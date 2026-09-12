<?php
/**
 * Audit engine module.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Container;
use Sitecraft\Accessibility\Core\Module;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Scanner\Analyzer;
use Sitecraft\Accessibility\Modules\Scanner\BatchRunner;
use Sitecraft\Accessibility\Modules\Scanner\Repository;
use Sitecraft\Accessibility\Support\Installer;
use WP_Post;

/**
 * Wires the audit engine to WordPress.
 *
 * The batch hook is registered whether or not scheduled auditing is enabled: the
 * dashboard button and the CLI both dispatch the same event, and a site that has
 * turned off the recurring schedule still expects "Audit now" to work.
 */
final class Scanner implements Module {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Post ids to re-audit once the current request has finished.
	 *
	 * @var int[]
	 */
	private array $deferred = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'scanner';
	}

	/**
	 * Binds the module to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Adds a longer interval, never a shorter one.

		add_action( Installer::SCAN_EVENT, array( $this, 'run_batch' ) );

		// Findings that outlive their content are misleading, so cleanup is not optional
		// and is registered regardless of whether auditing on save is switched on.
		add_action( 'deleted_post', array( $this, 'forget_post' ) );
		add_action( 'wp_trash_post', array( $this, 'forget_post' ) );

		add_action( 'save_post', array( $this, 'queue_post' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'run_deferred' ) );
	}

	/**
	 * Adds the weekly recurrence offered in the settings screen.
	 *
	 * WordPress ships hourly, twicedaily and daily only.
	 *
	 * @param array<string, array<string, mixed>> $schedules Registered schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedule( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly', 'sitecraft-accessibility' ),
			);
		}

		return $schedules;
	}

	/**
	 * Runs one audit batch.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		$this->runner()->run_batch();
	}

	/**
	 * Queues a post for re-audit after the response has been sent.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post object.
	 * @param bool         $update  Whether this was an update rather than an insert.
	 * @return void
	 */
	public function queue_post( $post_id, $post = null, $update = false ): void {
		unset( $update );

		if ( ! $this->settings()->is_enabled( 'scan_on_save' ) ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->runner()->post_types(), true ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, $this->runner()->post_statuses(), true ) ) {
			// A post leaving the audited set should not keep its old findings.
			$this->repository()->delete_for_object( $post_id );

			return;
		}

		$this->deferred[ $post_id ] = $post_id;
	}

	/**
	 * Audits everything queued during this request.
	 *
	 * Deferring to `shutdown` keeps the parse off the editor's save round trip, which
	 * is the difference between a save that feels instant and one that does not.
	 *
	 * @return void
	 */
	public function run_deferred(): void {
		if ( empty( $this->deferred ) ) {
			return;
		}

		$queue          = $this->deferred;
		$this->deferred = array();

		$runner = $this->runner();

		foreach ( $queue as $post_id ) {
			$runner->scan_post( (int) $post_id );
		}
	}

	/**
	 * Drops every finding recorded for a post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function forget_post( $post_id ): void {
		$this->repository()->delete_for_object( (int) $post_id );
	}

	/**
	 * Batch runner for the current settings.
	 *
	 * @return BatchRunner
	 */
	public function runner(): BatchRunner {
		if ( ! $this->container->has( 'scanner.runner' ) ) {
			$this->container->set(
				'scanner.runner',
				function (): BatchRunner {
					return new BatchRunner( $this->settings(), $this->analyzer(), $this->repository() );
				}
			);
		}

		return $this->container->get( 'scanner.runner' );
	}

	/**
	 * Rule engine for the current settings.
	 *
	 * @return Analyzer
	 */
	public function analyzer(): Analyzer {
		if ( ! $this->container->has( 'scanner.analyzer' ) ) {
			$this->container->set(
				'scanner.analyzer',
				function (): Analyzer {
					return Analyzer::create( $this->settings() );
				}
			);
		}

		return $this->container->get( 'scanner.analyzer' );
	}

	/**
	 * Findings store.
	 *
	 * @return Repository
	 */
	public function repository(): Repository {
		if ( ! $this->container->has( 'scanner.repository' ) ) {
			$this->container->set(
				'scanner.repository',
				static function (): Repository {
					return new Repository();
				}
			);
		}

		return $this->container->get( 'scanner.repository' );
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
