<?php
/**
 * Scanner settings fields.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Support\Settings;

/**
 * Declares the audit engine's configuration.
 */
final class ScannerFields {

	/**
	 * Field definitions for the `scanner` section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'          => 'scanner_enabled',
				'section'     => 'scanner',
				'type'        => 'toggle',
				'label'       => __( 'Run scheduled audits', 'sitecraft-accessibility' ),
				'description' => __( 'Turns the batched background audit on. Manual scans from the dashboard and WP-CLI still run when this is off, so you can audit on demand without a recurring cron job.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'scan_schedule',
				'section'     => 'scanner',
				'type'        => 'select',
				'label'       => __( 'Audit frequency', 'sitecraft-accessibility' ),
				'description' => __( 'How often a batch is dispatched. Each run processes one batch, not the whole site, so a large site needs several runs to complete a pass. Daily suits most sites; hourly only helps when content changes constantly.', 'sitecraft-accessibility' ),
				'default'     => 'daily',
				'choices'     => array(
					'hourly'     => __( 'Hourly', 'sitecraft-accessibility' ),
					'twicedaily' => __( 'Twice daily', 'sitecraft-accessibility' ),
					'daily'      => __( 'Daily', 'sitecraft-accessibility' ),
					'weekly'     => __( 'Weekly', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'scanner_enabled' => true ),
			),
			array(
				'id'          => 'scan_batch_size',
				'section'     => 'scanner',
				'type'        => 'number',
				'label'       => __( 'Posts per batch', 'sitecraft-accessibility' ),
				'description' => __( 'Parsing HTML with DOMDocument is memory-hungry. Twenty posts is safe on shared hosting; raise it on a dedicated server to finish a full pass sooner, lower it if cron runs are timing out.', 'sitecraft-accessibility' ),
				'default'     => 20,
				'min'         => 1,
				'max'         => 200,
				'step'        => 1,
			),
			array(
				'id'          => 'scan_max_runtime',
				'section'     => 'scanner',
				'type'        => 'number',
				'label'       => __( 'Batch time budget (seconds)', 'sitecraft-accessibility' ),
				'description' => __( 'A batch stops early and saves its cursor once this many seconds have elapsed, even if the batch is not finished. Keep it comfortably below your host\'s PHP max_execution_time so a slow post can never kill the run.', 'sitecraft-accessibility' ),
				'default'     => 20,
				'min'         => 5,
				'max'         => 120,
				'step'        => 1,
			),
			array(
				'id'          => 'scan_post_types',
				'section'     => 'scanner',
				'type'        => 'multicheck',
				'label'       => __( 'Content to audit', 'sitecraft-accessibility' ),
				'description' => __( 'Only public post types are offered, because private content is never seen by the visitors these criteria protect. Every selected type lengthens a full pass.', 'sitecraft-accessibility' ),
				'default'     => array( 'post', 'page' ),
				'choices'     => Settings::post_type_choices(),
			),
			array(
				'id'          => 'scan_include_drafts',
				'section'     => 'scanner',
				'type'        => 'toggle',
				'label'       => __( 'Include drafts and pending posts', 'sitecraft-accessibility' ),
				'description' => __( 'Catches problems before they are published, at the cost of a longer queue and issue rows for content that may never ship.', 'sitecraft-accessibility' ),
				'default'     => false,
			),
			array(
				'id'          => 'scan_on_save',
				'section'     => 'scanner',
				'type'        => 'toggle',
				'label'       => __( 'Re-audit a post when it is saved', 'sitecraft-accessibility' ),
				'description' => __( 'Keeps the issue list honest between scheduled passes. The single-post audit runs on shutdown, so it does not slow the editor\'s save request.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'scan_apply_content_filters',
				'section'     => 'scanner',
				'type'        => 'toggle',
				'label'       => __( 'Audit rendered output', 'sitecraft-accessibility' ),
				'description' => __( 'Runs content through <code>the_content</code> before parsing, so shortcodes, blocks and embeds are audited as visitors receive them. More accurate, but slower, and it executes other plugins\' filters during cron.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'scan_levels',
				'section'     => 'scanner',
				'type'        => 'multicheck',
				'label'       => __( 'Conformance levels', 'sitecraft-accessibility' ),
				'description' => __( 'Level A and AA together are what EN 301 549 and the ADA-related settlements ask for. AAA is included for teams that have chosen to go further; it is not a legal baseline.', 'sitecraft-accessibility' ),
				'default'     => array( 'A', 'AA' ),
				'choices'     => array(
					'A'   => __( 'Level A', 'sitecraft-accessibility' ),
					'AA'  => __( 'Level AA', 'sitecraft-accessibility' ),
					'AAA' => __( 'Level AAA', 'sitecraft-accessibility' ),
				),
			),
			array(
				'id'          => 'scan_disabled_rules',
				'section'     => 'scanner',
				'type'        => 'multicheck',
				'label'       => __( 'Rules to skip', 'sitecraft-accessibility' ),
				'description' => __( 'Mute a rule your build genuinely handles elsewhere. Muting a rule hides the finding, it does not fix the barrier, so record why you muted it where your team will see it.', 'sitecraft-accessibility' ),
				'default'     => array(),
				'choices'     => Settings::rule_choices(),
			),
			array(
				'id'          => 'contrast_ratio_normal',
				'section'     => 'scanner',
				'type'        => 'number',
				'label'       => __( 'Minimum contrast, normal text', 'sitecraft-accessibility' ),
				'description' => __( 'WCAG 2.1 SC 1.4.3 requires 4.5:1. Raise it to 7 to audit against the stricter SC 1.4.6 (AAA). Lowering it below 4.5 means you are no longer testing to any published standard.', 'sitecraft-accessibility' ),
				'default'     => 4.5,
				'min'         => 1,
				'max'         => 21,
				'step'        => 0.1,
			),
			array(
				'id'          => 'contrast_ratio_large',
				'section'     => 'scanner',
				'type'        => 'number',
				'label'       => __( 'Minimum contrast, large text', 'sitecraft-accessibility' ),
				'description' => __( 'Applies to text of at least 24px, or 18.66px when bold. WCAG allows 3:1 for that size; the AAA equivalent is 4.5:1.', 'sitecraft-accessibility' ),
				'default'     => 3.0,
				'min'         => 1,
				'max'         => 21,
				'step'        => 0.1,
			),
			array(
				'id'          => 'scan_context_length',
				'section'     => 'scanner',
				'type'        => 'number',
				'label'       => __( 'Stored snippet length', 'sitecraft-accessibility' ),
				'description' => __( 'How many characters of the offending markup are kept with each issue so you can recognise it. Longer snippets are easier to act on and make the issues table grow faster.', 'sitecraft-accessibility' ),
				'default'     => 512,
				'min'         => 64,
				'max'         => 2048,
				'step'        => 32,
			),
			array(
				'id'          => 'scan_excluded_ids',
				'section'     => 'scanner',
				'type'        => 'list',
				'label'       => __( 'Post IDs to never audit', 'sitecraft-accessibility' ),
				'description' => __( 'One ID per line. Useful for archived landing pages you cannot edit. Excluded content still exists for visitors, so treat this as a triage tool rather than a resolution.', 'sitecraft-accessibility' ),
				'default'     => array(),
				'rows'        => 4,
				'placeholder' => "128\n4096",
				'sanitize'    => array( Settings::class, 'sanitize_id_list' ),
			),
		);
	}
}
