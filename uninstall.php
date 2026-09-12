<?php
/**
 * Removes everything Sitecraft Accessibility stored, unless asked to keep it.
 *
 * Runs with the plugin unloaded, so nothing here may depend on the autoloader or
 * on any class in `src/`. Every name is written out literally and must be kept in
 * step with `Sitecraft\Accessibility\Support\Installer`.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Uninstall is triggered from an authenticated admin request; treating it as a
// privileged write and checking the capability again costs nothing.
if ( ! current_user_can( 'activate_plugins' ) ) {
	exit;
}

/**
 * Options written by the plugin.
 *
 * @return string[]
 */
function sitecraft_a11y_uninstall_options(): array {
	return array(
		'sitecraft_a11y_settings',
		'sitecraft_a11y_keep_data_on_uninstall',
		'sitecraft_a11y_schema_version',
		'sitecraft_a11y_scan_state',
	);
}

/**
 * Post and term meta keys written by the plugin.
 *
 * @return string[]
 */
function sitecraft_a11y_uninstall_post_meta_keys(): array {
	return array(
		'_sitecraft_a11y_lang',
		'_sitecraft_a11y_decorative',
		'_sitecraft_a11y_issue_count',
		'_sitecraft_a11y_scanned_at',
	);
}

/**
 * User meta keys written by the plugin.
 *
 * Only the findings screen's rows-per-page preference: queued admin notices live
 * in transients, which the generated-name sweep at the end of the run removes.
 *
 * @return string[]
 */
function sitecraft_a11y_uninstall_user_meta_keys(): array {
	return array(
		'sitecraft_a11y_issues_per_page',
	);
}

/**
 * Whether this site asked for its data to survive deletion.
 *
 * @return bool
 */
function sitecraft_a11y_uninstall_keeps_data(): bool {
	$keep = (bool) get_option( 'sitecraft_a11y_keep_data_on_uninstall', false );

	if ( ! $keep ) {
		// Fall back to the schema value in case the standalone mirror was never written,
		// for example on an install that was activated and deleted without a settings save.
		$settings = get_option( 'sitecraft_a11y_settings', array() );

		if ( is_array( $settings ) && ! empty( $settings['keep_data_on_uninstall'] ) ) {
			$keep = true;
		}
	}

	/**
	 * Filters whether plugin data survives uninstall.
	 *
	 * @param bool $keep Whether to keep the data.
	 */
	return (bool) apply_filters( 'sitecraft_a11y_uninstall_keep_data', $keep );
}

/**
 * Deletes every trace of the plugin from the current site.
 *
 * @return void
 */
function sitecraft_a11y_uninstall_site(): void {
	global $wpdb;

	// Cron events are cleared even when data is kept: a scheduled hook with no
	// listener is dead weight in the options table.
	wp_clear_scheduled_hook( 'sitecraft_a11y_scan_batch' );
	wp_clear_scheduled_hook( 'sitecraft_a11y_prune_issues' );
	delete_transient( 'sitecraft_a11y_scan_lock' );

	if ( sitecraft_a11y_uninstall_keeps_data() ) {
		return;
	}

	$table = $wpdb->prefix . 'sitecraft_a11y_issues';

	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on the plugin's own table; the only interpolation is $wpdb->prefix plus a literal.

	foreach ( sitecraft_a11y_uninstall_options() as $option ) {
		delete_option( $option );
	}

	foreach ( sitecraft_a11y_uninstall_post_meta_keys() as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	foreach ( sitecraft_a11y_uninstall_user_meta_keys() as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// Transients are options with generated names, so they cannot be listed literally.
	$like = $wpdb->esc_like( '_transient_sitecraft_a11y_' ) . '%';
	$slow = $wpdb->esc_like( '_transient_timeout_sitecraft_a11y_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup of generated option names.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like,
			$slow
		)
	);

	wp_cache_flush();
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		sitecraft_a11y_uninstall_site();
		restore_current_blog();
	}
} else {
	sitecraft_a11y_uninstall_site();
}
