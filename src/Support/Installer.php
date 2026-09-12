<?php
/**
 * Activation, deactivation and schema ownership.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Support\Logger;
use Sitecraft\Accessibility\Core\Support\Migrations;

/**
 * Owns everything that outlives a single request: tables, options and cron events.
 *
 * Nothing else in the plugin issues DDL. Keeping the schema in one class means the
 * activation path, the migration path and `uninstall.php` cannot drift apart.
 */
final class Installer {

	/**
	 * Unprefixed name of the issues table.
	 *
	 * @var string
	 */
	public const ISSUES_TABLE = 'sitecraft_a11y_issues';

	/**
	 * Option holding the schema version this install has reached.
	 *
	 * @var string
	 */
	public const SCHEMA_OPTION = 'sitecraft_a11y_schema_version';

	/**
	 * Option holding the chunked-scan cursor and counters.
	 *
	 * @var string
	 */
	public const SCAN_STATE_OPTION = 'sitecraft_a11y_scan_state';

	/**
	 * Standalone mirror of the "keep data" preference, read by uninstall.php.
	 *
	 * @var string
	 */
	public const KEEP_DATA_OPTION = 'sitecraft_a11y_keep_data_on_uninstall';

	/**
	 * Cron hook that processes one batch of posts.
	 *
	 * @var string
	 */
	public const SCAN_EVENT = 'sitecraft_a11y_scan_batch';

	/**
	 * Cron hook that enforces issue retention.
	 *
	 * @var string
	 */
	public const PRUNE_EVENT = 'sitecraft_a11y_prune_issues';

	/**
	 * Transient used as a mutex so two cron runs cannot scan the same batch.
	 *
	 * @var string
	 */
	public const SCAN_LOCK = 'sitecraft_a11y_scan_lock';

	/**
	 * Fully prefixed name of the issues table.
	 *
	 * @return string
	 */
	public static function issues_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::ISSUES_TABLE;
	}

	/**
	 * Runs on activation.
	 *
	 * `create_tables()` always produces the current schema, so a fresh install can
	 * jump straight to the target version instead of replaying historical steps.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		self::schedule_events();

		update_option( self::SCHEMA_OPTION, self::migrations()->target_version(), false );

		Logger::info( 'Activated.', array( 'version' => SITECRAFT_A11Y_VERSION ) );
	}

	/**
	 * Runs on deactivation.
	 *
	 * Deliberately destructive to nothing: a deactivation is usually a diagnostic
	 * step, and losing a site's audit history to one is unforgivable. Data removal
	 * belongs in uninstall.php, behind the "keep data" preference.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::clear_events();

		// A lock left behind by an interrupted batch would block the next activation.
		delete_transient( self::SCAN_LOCK );

		Logger::info( 'Deactivated; scheduled events cleared, stored issues retained.' );
	}

	/**
	 * Migration runner for this plugin's schema.
	 *
	 * @return Migrations
	 */
	public static function migrations(): Migrations {
		return new Migrations(
			self::SCHEMA_OPTION,
			array(
				1 => array( self::class, 'create_tables' ),
			)
		);
	}

	/**
	 * Applies any pending schema steps.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		self::migrations()->run();
	}

	/**
	 * Creates or updates the issues table.
	 *
	 * `detected_at` has no default because the zero date is rejected on hosts running
	 * MySQL in strict mode; every writer supplies a real timestamp.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::issues_table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(32) NOT NULL DEFAULT 'post',
			rule_id varchar(64) NOT NULL DEFAULT '',
			severity varchar(16) NOT NULL DEFAULT 'moderate',
			criterion varchar(16) NOT NULL DEFAULT '',
			level varchar(4) NOT NULL DEFAULT 'A',
			context text NOT NULL,
			selector_hint varchar(255) NOT NULL DEFAULT '',
			detected_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object_id (object_id),
			KEY rule_id (rule_id),
			KEY severity (severity),
			KEY detected_at (detected_at),
			KEY object_rule (object_id,rule_id)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Writes default options without overwriting an existing configuration.
	 *
	 * @return void
	 */
	public static function seed_options(): void {
		$settings = Settings::create();

		// add_option() is a no-op when the option exists, which is what a reactivation wants.
		add_option( Settings::OPTION_NAME, $settings->defaults() );

		// Mirrored as a standalone option so uninstall.php never has to understand the schema.
		add_option( self::KEEP_DATA_OPTION, (bool) $settings->get( 'keep_data_on_uninstall', false ), '', false );

		add_option( self::SCAN_STATE_OPTION, self::default_scan_state(), '', false );
	}

	/**
	 * Shape of the chunked-scan cursor.
	 *
	 * @return array<string, int>
	 */
	public static function default_scan_state(): array {
		return array(
			'cursor'      => 0,
			'scanned'     => 0,
			'queue_total' => 0,
			'started_at'  => 0,
			'finished_at' => 0,
		);
	}

	/**
	 * Current scan cursor, with missing keys filled from the default shape.
	 *
	 * @return array<string, int>
	 */
	public static function scan_state(): array {
		$state = get_option( self::SCAN_STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_map( 'intval', array_merge( self::default_scan_state(), $state ) );
	}

	/**
	 * Rewinds the scan cursor so the next batch starts from the beginning.
	 *
	 * @param int $queue_total Number of objects the run expects to visit.
	 * @return void
	 */
	public static function reset_scan_state( int $queue_total = 0 ): void {
		$state                = self::default_scan_state();
		$state['queue_total'] = max( 0, $queue_total );
		$state['started_at']  = time();

		update_option( self::SCAN_STATE_OPTION, $state, false );
	}

	/**
	 * (Re)schedules the plugin's cron events from the current settings.
	 *
	 * Called on activation and after every settings save, so changing the cadence in
	 * the UI takes effect without a deactivate/activate cycle.
	 *
	 * @return void
	 */
	public static function schedule_events(): void {
		self::clear_events();

		$settings = Settings::create();

		/**
		 * Filters the recurrence used for the batched scan.
		 *
		 * @param string $schedule One of the registered cron schedules, or `disabled`.
		 */
		$schedule = (string) apply_filters(
			'sitecraft_a11y_scan_schedule',
			(string) $settings->get( 'scan_schedule', 'daily' )
		);

		$recurrences = wp_get_schedules();

		if ( $settings->is_enabled( 'scanner_enabled' ) && isset( $recurrences[ $schedule ] ) ) {
			// Offset the first run so activation does not compete with the request that caused it.
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), $schedule, self::SCAN_EVENT );
		}

		if ( (int) $settings->get( 'issue_retention_days', 0 ) > 0 ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_EVENT );
		}
	}

	/**
	 * Unschedules every event this plugin owns.
	 *
	 * @return void
	 */
	public static function clear_events(): void {
		wp_clear_scheduled_hook( self::SCAN_EVENT );
		wp_clear_scheduled_hook( self::PRUNE_EVENT );
	}

	/**
	 * Deletes issues older than the configured retention window.
	 *
	 * @return int Number of rows removed.
	 */
	public static function prune_issues(): int {
		global $wpdb;

		$days = (int) Settings::create()->get( 'issue_retention_days', 0 );

		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::issues_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance write against the plugin's own table.
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE detected_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix and a class constant.
				$cutoff
			)
		);

		if ( $removed > 0 ) {
			Logger::info(
				'Pruned expired issues.',
				array(
					'removed' => $removed,
					'cutoff'  => $cutoff,
				)
			);
		}

		return $removed;
	}

	/**
	 * Empties the issues table.
	 *
	 * @return int Number of rows removed.
	 */
	public static function clear_issues(): int {
		global $wpdb;

		$table = self::issues_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete on the plugin's own table; no prepare needed without variables.
		$removed = (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix and a class constant.

		update_option( self::SCAN_STATE_OPTION, self::default_scan_state(), false );

		return $removed;
	}
}
