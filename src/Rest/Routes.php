<?php
/**
 * REST API surface.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Container;
use Sitecraft\Accessibility\Core\Module;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Scanner\Analyzer;
use Sitecraft\Accessibility\Modules\Scanner\Issue;
use Sitecraft\Accessibility\Modules\Scanner\Repository;
use Sitecraft\Accessibility\Support\Installer;
use Sitecraft\Accessibility\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Routes under `sitecraft/v1`.
 *
 * Three rules hold for every route here. Each has a permission callback that
 * checks a real capability - `__return_true` is never used, not even for the read
 * route, because a site's accessibility debt is internal information. Each
 * argument is declared with a validator and a sanitizer, so a malformed request is
 * rejected by the schema rather than by code further in. And nothing here writes
 * synchronously: a scan request queues work, it does not hold the connection open
 * while fifty thousand posts are parsed.
 */
final class Routes implements Module {

	/**
	 * REST namespace shared across the suite.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'sitecraft/v1';

	/**
	 * Route prefix for this plugin.
	 *
	 * @var string
	 */
	private const BASE = 'a11y';

	/**
	 * Largest content payload accepted by the analyse route, in bytes.
	 *
	 * Generous for a post, small enough that the endpoint cannot be used to make the
	 * site parse megabytes of markup on demand.
	 *
	 * @var int
	 */
	private const MAX_CONTENT_BYTES = 512000;

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
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rest';
	}

	/**
	 * Binds the module to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares every route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! $this->settings()->is_enabled( 'rest_enabled' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'rules' => array(
							'description'       => __( 'Include the per-rule breakdown.', 'sitecraft-accessibility' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'queue_scan' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'reset' => array(
							'description'       => __( 'Rewind the cursor so the run starts from the beginning.', 'sitecraft-accessibility' ),
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/analyse',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'analyse' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'content' => array(
							'description'       => __( 'HTML to audit.', 'sitecraft-accessibility' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_content' ),
							// No sanitizer strips tags here on purpose: the markup *is* the
							// subject of the audit, and the response never echoes it into a
							// page. Everything the callback returns is escaped by the client.
							'sanitize_callback' => static function ( $value ): string {
								return (string) $value;
							},
						),
						'post_id' => array(
							'description'       => __( 'Post the content belongs to, used for the capability check.', 'sitecraft-accessibility' ),
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Read permission: anyone who can write content can see its findings.
	 *
	 * @return true|WP_Error
	 */
	public function can_read() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'sitecraft_a11y_forbidden',
			__( 'You are not allowed to read accessibility findings.', 'sitecraft-accessibility' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Write permission, honouring the configurable capability.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( current_user_can( Settings::capability() ) ) {
			return true;
		}

		return new WP_Error(
			'sitecraft_a11y_forbidden',
			__( 'You are not allowed to run an accessibility audit.', 'sitecraft-accessibility' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Rejects payloads that are not plausible post content.
	 *
	 * @param mixed $value Submitted value.
	 * @return true|WP_Error
	 */
	public function validate_content( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error(
				'sitecraft_a11y_invalid_content',
				__( 'Content must be a string.', 'sitecraft-accessibility' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $value ) > self::MAX_CONTENT_BYTES ) {
			return new WP_Error(
				'sitecraft_a11y_content_too_large',
				sprintf(
					/* translators: %s: maximum size in kilobytes */
					__( 'Content is larger than the %s KB the audit endpoint accepts.', 'sitecraft-accessibility' ),
					number_format_i18n( self::MAX_CONTENT_BYTES / 1024 )
				),
				array( 'status' => 413 )
			);
		}

		return true;
	}

	/**
	 * Counts by severity, and optionally by rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		$repository = $this->repository();
		$state      = Installer::scan_state();

		$payload = array(
			'total'      => $repository->total(),
			'objects'    => $repository->object_count(),
			'severities' => $repository->severity_counts(),
			'scan'       => array(
				'scanned'     => $state['scanned'],
				'queue_total' => $state['queue_total'],
				'started_at'  => $state['started_at'] > 0 ? gmdate( 'c', $state['started_at'] ) : null,
				'finished_at' => $state['finished_at'] > 0 ? gmdate( 'c', $state['finished_at'] ) : null,
				'running'     => false !== get_transient( Installer::SCAN_LOCK ),
			),
		);

		if ( (bool) $request->get_param( 'rules' ) ) {
			$payload['rules'] = array_map(
				static function ( array $row ): array {
					return array(
						'rule_id'   => (string) $row['rule_id'],
						'criterion' => (string) $row['criterion'],
						'level'     => (string) $row['level'],
						'severity'  => (string) $row['severity'],
						'total'     => (int) $row['total'],
					);
				},
				$repository->rule_counts()
			);
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Queues a full audit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function queue_scan( WP_REST_Request $request ) {
		if ( false !== get_transient( Installer::SCAN_LOCK ) ) {
			return new WP_Error(
				'sitecraft_a11y_scan_running',
				__( 'An audit is already running. Wait for the current batch to finish.', 'sitecraft-accessibility' ),
				array( 'status' => 409 )
			);
		}

		if ( (bool) $request->get_param( 'reset' ) ) {
			$runner = $this->container->get( 'scanner' )->runner();

			Installer::reset_scan_state( $runner->queue_total() );
		}

		$scheduled = wp_schedule_single_event( time() + 5, Installer::SCAN_EVENT );

		if ( false === $scheduled ) {
			return new WP_Error(
				'sitecraft_a11y_schedule_failed',
				__( 'The audit could not be queued. Check that WP-Cron is not disabled on this site.', 'sitecraft-accessibility' ),
				array( 'status' => 500 )
			);
		}

		// Nudges cron so the first batch starts now rather than on the next visitor.
		spawn_cron();

		$state = Installer::scan_state();

		return new WP_REST_Response(
			array(
				'queued'      => true,
				'queue_total' => $state['queue_total'],
			),
			202
		);
	}

	/**
	 * Audits a fragment of content without storing anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyse( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		// A caller naming a post must be allowed to edit that post; the generic
		// `edit_posts` check in the permission callback is not enough on its own.
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'sitecraft_a11y_forbidden',
				__( 'You are not allowed to audit this post.', 'sitecraft-accessibility' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$analyzer = $this->analyzer();
		$issues   = $analyzer->analyse( (string) $request->get_param( 'content' ) );

		$payload = array(
			'count'  => count( $issues ),
			'issues' => array_map(
				static function ( Issue $issue ): array {
					return $issue->to_array();
				},
				$issues
			),
		);

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Rule engine.
	 *
	 * @return Analyzer
	 */
	private function analyzer(): Analyzer {
		return $this->container->get( 'scanner' )->analyzer();
	}

	/**
	 * Findings store.
	 *
	 * @return Repository
	 */
	private function repository(): Repository {
		return $this->container->get( 'scanner' )->repository();
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
