<?php
/**
 * Advanced settings fields.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares integration surfaces, retention and data-lifecycle settings.
 */
final class AdvancedFields {

	/**
	 * Field definitions for the `advanced` section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'          => 'capability',
				'section'     => 'advanced',
				'type'        => 'text',
				'label'       => __( 'Required capability', 'sitecraft-accessibility' ),
				'description' => __( 'Capability a user needs to open these screens and start a scan. Lower it deliberately - for example to <code>edit_pages</code> so an editor can triage findings - and never to a capability every subscriber holds.', 'sitecraft-accessibility' ),
				'default'     => 'manage_options',
				'placeholder' => 'manage_options',
			),
			array(
				'id'          => 'rest_enabled',
				'section'     => 'advanced',
				'type'        => 'toggle',
				'label'       => __( 'Expose the REST endpoints', 'sitecraft-accessibility' ),
				'description' => __( 'Serves the routes under <code>sitecraft/v1/a11y</code>. Every route has its own capability check, so turning this off is defence in depth rather than the only guard. The editor sidebar depends on it.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'editor_sidebar_enabled',
				'section'     => 'advanced',
				'type'        => 'toggle',
				'label'       => __( 'Show the block editor sidebar', 'sitecraft-accessibility' ),
				'description' => __( 'Runs the same rules against the post being edited and lists what it finds before publish. Catching a missing alt attribute in the editor costs a minute; catching it in an audit six months later costs a support ticket.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'issue_retention_days',
				'section'     => 'advanced',
				'type'        => 'number',
				'label'       => __( 'Keep resolved issues for (days)', 'sitecraft-accessibility' ),
				'description' => __( 'A daily job removes issue rows older than this. Zero keeps everything, which is useful evidence of remediation history but grows the table on a large, frequently scanned site.', 'sitecraft-accessibility' ),
				'default'     => 90,
				'min'         => 0,
				'max'         => 3650,
				'step'        => 1,
			),
			array(
				'id'          => 'debug_logging',
				'section'     => 'advanced',
				'type'        => 'toggle',
				'label'       => __( 'Verbose logging', 'sitecraft-accessibility' ),
				'description' => __( 'Writes per-batch audit timings and capped-document notices to the WordPress debug log. Rules that throw are always recorded; this adds the routine diagnostics on top. Nothing is written unless <code>WP_DEBUG</code> is also on, so this cannot quietly fill a production disk.', 'sitecraft-accessibility' ),
				'default'     => false,
			),
			array(
				'id'          => 'keep_data_on_uninstall',
				'section'     => 'advanced',
				'type'        => 'toggle',
				'label'       => __( 'Keep data when the plugin is deleted', 'sitecraft-accessibility' ),
				'description' => __( 'On: deleting the plugin leaves your settings, issue history and per-post metadata untouched, so a reinstall picks up where you left off. Off: deleting the plugin removes all of it permanently, which is what a clean removal or a GDPR erasure request needs.', 'sitecraft-accessibility' ),
				'default'     => false,
			),
		);
	}
}
