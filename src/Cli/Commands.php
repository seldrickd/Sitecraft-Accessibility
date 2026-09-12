<?php
/**
 * WP-CLI commands.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Container;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Scanner;
use Sitecraft\Accessibility\Modules\Scanner\BatchRunner;
use Sitecraft\Accessibility\Modules\Scanner\Repository;
use Sitecraft\Accessibility\Support\Installer;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp sitecraft-a11y` - audit, report on and clear accessibility findings.
 *
 * The commands are thin. Everything they do is the same code the cron batch and
 * the REST routes call, so a finding produced on the command line is byte for byte
 * the finding produced by a scheduled run; a CLI path with its own slightly
 * different rule set would be worse than no CLI path at all.
 *
 * Authorisation here is shell access. WP-CLI runs with no logged-in user, so a
 * `current_user_can()` check would test the capabilities of nobody and pass or
 * fail for reasons unrelated to who is at the keyboard. The HTTP write paths that
 * do have a user each verify a nonce and a capability.
 */
final class Commands {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Registers the command namespace.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public static function register( Container $container ): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}

		WP_CLI::add_command(
			'sitecraft-a11y',
			new self( $container ),
			array(
				'shortdesc' => 'Audits content against WCAG 2.1 AA and WCAG 2.2 and reports what a person has to fix.',
			)
		);
	}

	/**
	 * Audits content and records what the rules find.
	 *
	 * Runs the same chunked pass the cron job runs, but synchronously: the command
	 * blocks until the queue is exhausted or --limit is reached. The cursor is shared
	 * with the scheduled run, so a command interrupted half way through is picked up
	 * by the next cron batch rather than starting again.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<types>]
	 * : Comma separated post types to audit, overriding the configured set for this
	 * run only. Unregistered types are rejected rather than silently skipped.
	 *
	 * [--limit=<n>]
	 * : Stop after auditing this many items. Defaults to the whole queue.
	 *
	 * [--reset]
	 * : Rewind the cursor so the pass starts from the first item instead of resuming.
	 *
	 * ## EXAMPLES
	 *
	 *     # Audit everything, resuming from wherever the last pass stopped.
	 *     $ wp sitecraft-a11y scan
	 *
	 *     # Audit the first 200 pages from the beginning.
	 *     $ wp sitecraft-a11y scan --post-type=page --limit=200 --reset
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function scan( array $args, array $assoc_args ): void {
		unset( $args );

		$runner = $this->runner();
		$types  = (string) Utils\get_flag_value( $assoc_args, 'post-type', '' );

		if ( '' !== trim( $types ) ) {
			$runner->only_post_types( $this->validate_post_types( $types ) );
		}

		$limit = max( 0, (int) Utils\get_flag_value( $assoc_args, 'limit', 0 ) );

		if ( (bool) Utils\get_flag_value( $assoc_args, 'reset', false ) ) {
			Installer::reset_scan_state( $runner->queue_total() );
			WP_CLI::log( 'Cursor rewound; this pass starts from the first item.' );
		}

		$queue = $runner->queue_total();
		$total = $limit > 0 ? min( $limit, $queue ) : $queue;

		if ( $total < 1 ) {
			WP_CLI::warning( 'Nothing to audit: no content matches the configured post types and statuses.' );

			return;
		}

		WP_CLI::log(
			sprintf(
				'Auditing up to %s across %s.',
				$this->quantity( $total, 'item', 'items' ),
				implode( ', ', $runner->post_types() )
			)
		);

		$progress = Utils\make_progress_bar( 'Auditing', $total );

		$totals = $runner->run_until_done(
			$limit,
			static function () use ( $progress ): void {
				$progress->tick();
			}
		);

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'Audited %s and recorded %s.',
				$this->quantity( (int) $totals['scanned'], 'item', 'items' ),
				$this->quantity( (int) $totals['findings'], 'finding', 'findings' )
			)
		);

		$this->log_severity_summary();
	}

	/**
	 * Lists recorded findings.
	 *
	 * Reads the stored results; it does not audit. Run `wp sitecraft-a11y scan`
	 * first if the table is empty or stale. Rows come back worst first.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<level>]
	 * : Only findings of this impact: critical, serious, moderate or minor.
	 *
	 * [--rule=<id>]
	 * : Only findings from this rule id, for example img-alt-missing.
	 *
	 * [--post=<id>]
	 * : Only findings recorded against this post ID.
	 *
	 * [--search=<text>]
	 * : Only findings whose stored markup, selector hint or rule id contains this text.
	 *
	 * [--limit=<n>]
	 * : Maximum rows to print. Pass 0 for every row.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma separated columns. Available: id, severity, rule_id, criterion, level,
	 * object_id, object_type, title, post_type, edit_url, selector_hint, context,
	 * detected_at.
	 *
	 * [--format=<format>]
	 * : Render format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # What is blocking someone outright.
	 *     $ wp sitecraft-a11y report --severity=critical
	 *
	 *     # Hand the whole backlog to another tool.
	 *     $ wp sitecraft-a11y report --limit=0 --format=json > findings.json
	 *
	 *     # Just the missing alt attributes, as a spreadsheet.
	 *     $ wp sitecraft-a11y report --rule=img-alt-missing --format=csv > alt.csv
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function report( array $args, array $assoc_args ): void {
		unset( $args );

		$format   = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$severity = (string) Utils\get_flag_value( $assoc_args, 'severity', '' );
		$rule     = (string) Utils\get_flag_value( $assoc_args, 'rule', '' );
		$parsed   = Reporter::parse_fields( (string) Utils\get_flag_value( $assoc_args, 'fields', '' ) );

		// Every flag is checked before a single row is read, so a typo in the third
		// argument fails immediately instead of after a long query.
		foreach ( array( Reporter::check_format( $format ), Reporter::check_severity( $severity ), Reporter::check_rule( $rule ), $parsed['error'] ) as $problem ) {
			if ( null !== $problem ) {
				WP_CLI::error( $problem );
			}
		}

		$items = $this->reporter()->collect(
			array(
				'severity'  => $severity,
				'rule'      => $rule,
				'search'    => (string) Utils\get_flag_value( $assoc_args, 'search', '' ),
				'object_id' => max( 0, (int) Utils\get_flag_value( $assoc_args, 'post', 0 ) ),
			),
			max( 0, (int) Utils\get_flag_value( $assoc_args, 'limit', 100 ) )
		);

		if ( empty( $items ) && 'table' === $format ) {
			WP_CLI::log( 'No findings match those filters.' );

			return;
		}

		Utils\format_items( $format, $items, $parsed['fields'] );
	}

	/**
	 * Deletes every recorded finding.
	 *
	 * Clears the results table and rewinds the cursor. It changes no content and
	 * fixes nothing: the next audit records the same findings again unless the
	 * underlying markup has been corrected.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp sitecraft-a11y clear --yes
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function clear( array $args, array $assoc_args ): void {
		unset( $args );

		$total = $this->repository()->total();

		if ( $total < 1 ) {
			WP_CLI::success( 'Nothing to clear; no findings are recorded.' );

			return;
		}

		WP_CLI::confirm(
			sprintf( 'Delete all %d recorded findings and rewind the audit cursor?', $total ),
			$assoc_args
		);

		$removed = Installer::clear_issues();

		WP_CLI::success( sprintf( 'Deleted %s.', $this->quantity( $removed, 'finding', 'findings' ) ) );
	}

	/**
	 * Turns `--post-type=a,b` into a validated list, or stops the command.
	 *
	 * @param string $types Comma separated post types.
	 * @return string[]
	 */
	private function validate_post_types( string $types ): array {
		$requested = array_filter( array_map( 'trim', explode( ',', $types ) ) );
		$valid     = array();
		$unknown   = array();

		foreach ( $requested as $type ) {
			if ( post_type_exists( $type ) ) {
				$valid[] = $type;
			} else {
				$unknown[] = $type;
			}
		}

		if ( ! empty( $unknown ) ) {
			WP_CLI::error(
				sprintf(
					'Unregistered post type(s): %s. Run `wp post-type list` to see what this site has.',
					implode( ', ', $unknown )
				)
			);
		}

		return $valid;
	}

	/**
	 * Prints the severity breakdown after a run.
	 *
	 * @return void
	 */
	private function log_severity_summary(): void {
		$parts = array();

		foreach ( $this->repository()->severity_counts() as $severity => $count ) {
			$parts[] = sprintf( '%s: %d', $severity, (int) $count );
		}

		WP_CLI::log( 'Open findings by severity - ' . implode( ', ', $parts ) . '.' );
		WP_CLI::log( 'Automated rules cover a minority of WCAG; a clean report is not a conformance claim.' );
	}

	/**
	 * Formats a count with the right noun.
	 *
	 * Command output is deliberately untranslated: WP-CLI runs in the operator's
	 * shell, where a stable, greppable English string is worth more than a localised
	 * one, and where the locale is often not even loaded.
	 *
	 * @param int    $count    Quantity.
	 * @param string $singular Singular noun.
	 * @param string $plural   Plural noun.
	 * @return string
	 */
	private function quantity( int $count, string $singular, string $plural ): string {
		return sprintf( '%d %s', $count, 1 === $count ? $singular : $plural );
	}

	/**
	 * A runner private to this command.
	 *
	 * Built rather than taken from the container so `--post-type` cannot narrow the
	 * instance any other caller in the process is holding.
	 *
	 * @return BatchRunner
	 */
	private function runner(): BatchRunner {
		return new BatchRunner( $this->settings(), $this->scanner()->analyzer(), $this->repository() );
	}

	/**
	 * Report assembler.
	 *
	 * @return Reporter
	 */
	private function reporter(): Reporter {
		return new Reporter( $this->repository() );
	}

	/**
	 * Findings store.
	 *
	 * @return Repository
	 */
	private function repository(): Repository {
		return $this->scanner()->repository();
	}

	/**
	 * Audit module.
	 *
	 * @return Scanner
	 */
	private function scanner(): Scanner {
		return $this->container->get( 'scanner' );
	}

	/**
	 * Settings schema.
	 *
	 * @return Registry
	 */
	private function settings(): Registry {
		return $this->container->get( 'settings' );
	}
}
