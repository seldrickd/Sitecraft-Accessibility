<?php
/**
 * Chunked site audit.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Core\Support\Logger;
use Sitecraft\Accessibility\Support\Installer;

/**
 * Walks the site a batch at a time, remembering where it stopped.
 *
 * The cursor is the highest post id already audited rather than an offset, so a
 * post published mid-pass cannot shift the window and cause the run to skip or
 * repeat content. A time budget ends a batch early on a slow host; the next cron
 * run picks up from the same cursor.
 */
final class BatchRunner {

	/**
	 * Settings schema.
	 *
	 * @var Registry
	 */
	private Registry $settings;

	/**
	 * Rule engine.
	 *
	 * @var Analyzer
	 */
	private Analyzer $analyzer;

	/**
	 * Findings store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Post types this runner has been narrowed to, or null to use the settings.
	 *
	 * @var string[]|null
	 */
	private ?array $post_type_override = null;

	/**
	 * Constructor.
	 *
	 * @param Registry        $settings   Settings schema.
	 * @param Analyzer|null   $analyzer   Rule engine; built from settings when omitted.
	 * @param Repository|null $repository Findings store.
	 */
	public function __construct( Registry $settings, ?Analyzer $analyzer = null, ?Repository $repository = null ) {
		$this->settings   = $settings;
		$this->analyzer   = $analyzer instanceof Analyzer ? $analyzer : Analyzer::create( $settings );
		$this->repository = $repository instanceof Repository ? $repository : new Repository();
	}

	/**
	 * Narrows this runner to a specific set of post types for the rest of its life.
	 *
	 * Exists for `wp sitecraft-a11y scan --post-type=…`, which has to audit a subset
	 * without touching the stored configuration: a CLI flag that rewrote the site's
	 * settings would change what the next cron run does, which is not what anyone
	 * typing a one-off command expects. Unregistered types are dropped, and an empty
	 * result restores the configured set rather than auditing nothing.
	 *
	 * @param string[] $types Post type names.
	 * @return string[] The types actually applied.
	 */
	public function only_post_types( array $types ): array {
		$types = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $types ),
					static function ( string $type ): bool {
						return '' !== $type && post_type_exists( $type );
					}
				)
			)
		);

		$this->post_type_override = empty( $types ) ? null : $types;

		return $this->post_types();
	}

	/**
	 * Post types the audit covers.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		if ( null !== $this->post_type_override ) {
			return $this->post_type_override;
		}

		$types = array_values( array_filter( array_map( 'strval', (array) $this->settings->get( 'scan_post_types', array( 'post', 'page' ) ) ) ) );

		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}

		return $types;
	}

	/**
	 * Post statuses the audit covers.
	 *
	 * @return string[]
	 */
	public function post_statuses(): array {
		$statuses = array( 'publish' );

		if ( $this->settings->is_enabled( 'scan_include_drafts' ) ) {
			$statuses[] = 'draft';
			$statuses[] = 'pending';
			$statuses[] = 'future';
		}

		return $statuses;
	}

	/**
	 * Number of objects a full pass will visit.
	 *
	 * @return int
	 */
	public function queue_total(): int {
		global $wpdb;

		$types    = $this->post_types();
		$statuses = $this->post_statuses();

		$type_placeholders   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting the audit queue; a cached count would report a stale total on the dashboard.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a core table name; placeholders are generated from array counts.
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders})",
				array_merge( $types, $statuses )
			)
		);
	}

	/**
	 * Fetches the next slice of ids after the cursor.
	 *
	 * @param int $cursor Highest id already audited.
	 * @param int $limit  Maximum ids to return.
	 * @return int[]
	 */
	public function next_ids( int $cursor, int $limit ): array {
		global $wpdb;

		$types    = $this->post_types();
		$statuses = $this->post_statuses();

		$type_placeholders   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$parameters = array_merge( $types, $statuses, array( $cursor, max( 1, $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor walk over the posts table; caching a moving window would defeat the cursor.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a core table name; placeholders are generated from array counts.
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ({$type_placeholders})
				AND post_status IN ({$status_placeholders})
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$parameters
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Post ids the site has asked never to audit.
	 *
	 * @return int[]
	 */
	private function excluded_ids(): array {
		return array_map( 'absint', (array) $this->settings->get( 'scan_excluded_ids', array() ) );
	}

	/**
	 * Audits one post and records its findings.
	 *
	 * @param int $post_id Post to audit.
	 * @return int Findings recorded.
	 */
	public function scan_post( int $post_id ): int {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return 0;
		}

		if ( in_array( (int) $post->ID, $this->excluded_ids(), true ) ) {
			$this->repository->delete_for_object( (int) $post->ID );

			return 0;
		}

		$issues = $this->analyzer->analyse_post( $post );

		$this->repository->replace_for_object( (int) $post->ID, 'post', $issues );

		update_post_meta( (int) $post->ID, '_sitecraft_a11y_issue_count', count( $issues ) );
		update_post_meta( (int) $post->ID, '_sitecraft_a11y_scanned_at', time() );

		/**
		 * Fires after a single object has been audited and its findings stored.
		 *
		 * @param int     $post_id Post that was audited.
		 * @param Issue[] $issues  Findings recorded.
		 */
		do_action( 'sitecraft_a11y_post_scanned', (int) $post->ID, $issues );

		return count( $issues );
	}

	/**
	 * Runs one batch and advances the cursor.
	 *
	 * @return array{scanned:int, findings:int, complete:bool, skipped:bool}
	 */
	public function run_batch(): array {
		$result = array(
			'scanned'  => 0,
			'findings' => 0,
			'complete' => false,
			'skipped'  => false,
		);

		// Two overlapping cron runs would audit the same slice twice and race on the
		// cursor. The lock is short so a fatal error cannot wedge the queue for long.
		if ( false !== get_transient( Installer::SCAN_LOCK ) ) {
			$result['skipped'] = true;

			return $result;
		}

		set_transient( Installer::SCAN_LOCK, time(), 10 * MINUTE_IN_SECONDS );

		try {
			$state = Installer::scan_state();

			if ( $state['queue_total'] < 1 ) {
				$state['queue_total'] = $this->queue_total();
			}

			if ( $state['started_at'] < 1 ) {
				$state['started_at'] = time();
			}

			$batch_size = max( 1, min( 200, (int) $this->settings->get( 'scan_batch_size', 20 ) ) );
			$budget     = max( 5, min( 120, (int) $this->settings->get( 'scan_max_runtime', 20 ) ) );
			$started    = microtime( true );
			$deadline   = $started + $budget;

			$ids = $this->next_ids( (int) $state['cursor'], $batch_size );

			foreach ( $ids as $post_id ) {
				$result['findings'] += $this->scan_post( $post_id );
				++$result['scanned'];

				$state['cursor']  = $post_id;
				$state['scanned'] = (int) $state['scanned'] + 1;

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}

			// An empty slice means the cursor has passed the last matching id.
			if ( empty( $ids ) ) {
				$state['finished_at'] = time();
				$state['cursor']      = 0;
				$result['complete']   = true;
			}

			update_option( Installer::SCAN_STATE_OPTION, $state, false );

			if ( ! $result['complete'] ) {
				// Keep the pass moving without waiting for the next recurring run; a large
				// site would otherwise take one batch per day to work through its queue.
				wp_schedule_single_event( time() + 60, Installer::SCAN_EVENT );
			}

			// Timings are the one thing worth logging on every run and the one thing
			// nobody wants in a production log by default, so they sit behind the
			// verbose-logging setting rather than behind WP_DEBUG alone.
			if ( $this->settings->is_enabled( 'debug_logging' ) ) {
				Logger::info(
					'Audit batch finished.',
					array(
						'scanned'  => $result['scanned'],
						'findings' => $result['findings'],
						'cursor'   => $state['cursor'],
						'complete' => $result['complete'],
						'seconds'  => round( microtime( true ) - $started, 3 ),
					)
				);
			}
		} finally {
			delete_transient( Installer::SCAN_LOCK );
		}

		return $result;
	}

	/**
	 * Runs batches until the queue is exhausted or a limit is reached.
	 *
	 * Used by WP-CLI, where blocking is expected and cron is not involved.
	 *
	 * @param int           $limit    Maximum objects to audit; 0 for the whole queue.
	 * @param callable|null $progress Receives the post id after each object.
	 * @return array{scanned:int, findings:int}
	 */
	public function run_until_done( int $limit = 0, ?callable $progress = null ): array {
		$totals = array(
			'scanned'  => 0,
			'findings' => 0,
		);

		$state      = Installer::scan_state();
		$cursor     = (int) $state['cursor'];
		$batch_size = max( 1, min( 200, (int) $this->settings->get( 'scan_batch_size', 20 ) ) );

		while ( true ) {
			$remaining = $limit > 0 ? $limit - $totals['scanned'] : $batch_size;

			if ( $remaining < 1 ) {
				break;
			}

			$ids = $this->next_ids( $cursor, min( $batch_size, $remaining ) );

			if ( empty( $ids ) ) {
				$state['finished_at'] = time();
				$cursor               = 0;
				break;
			}

			foreach ( $ids as $post_id ) {
				$totals['findings'] += $this->scan_post( $post_id );
				++$totals['scanned'];

				$cursor = $post_id;

				if ( null !== $progress ) {
					call_user_func( $progress, $post_id );
				}
			}
		}

		$state['cursor']  = $cursor;
		$state['scanned'] = (int) $state['scanned'] + $totals['scanned'];

		if ( $state['queue_total'] < 1 ) {
			$state['queue_total'] = $this->queue_total();
		}

		update_option( Installer::SCAN_STATE_OPTION, $state, false );

		return $totals;
	}
}
