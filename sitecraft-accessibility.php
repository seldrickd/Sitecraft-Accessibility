<?php
/**
 * Plugin Name:       Sitecraft Accessibility
 * Plugin URI:        https://github.com/sitecraft-suite/sitecraft-accessibility
 * Description:       Audits content against WCAG 2.1 AA and WCAG 2.2, fixes real markup problems server-side, and generates a publishable accessibility statement. Not an overlay.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Sitecraft
 * Author URI:        https://github.com/sitecraft-suite
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitecraft-accessibility
 * Domain Path:       /languages
 * Update URI:        https://github.com/sitecraft-suite/sitecraft-accessibility
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Cli\Commands;
use Sitecraft\Accessibility\Core\Plugin;
use Sitecraft\Accessibility\Core\Autoloader;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Core\Support\Migrations;
use Sitecraft\Accessibility\Modules\AdminMenu;
use Sitecraft\Accessibility\Modules\Editor;
use Sitecraft\Accessibility\Modules\PreferencePanel;
use Sitecraft\Accessibility\Modules\Remediation;
use Sitecraft\Accessibility\Modules\Scanner;
use Sitecraft\Accessibility\Modules\Statement;
use Sitecraft\Accessibility\Rest\Routes;
use Sitecraft\Accessibility\Support\Installer;
use Sitecraft\Accessibility\Support\Settings;

define( 'SITECRAFT_A11Y_VERSION', '1.0.0' );
define( 'SITECRAFT_A11Y_SLUG', 'sitecraft-accessibility' );
define( 'SITECRAFT_A11Y_MIN_PHP', '8.1' );
define( 'SITECRAFT_A11Y_MIN_WP', '6.5' );
define( 'SITECRAFT_A11Y_FILE', __FILE__ );
define( 'SITECRAFT_A11Y_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITECRAFT_A11Y_URL', plugin_dir_url( __FILE__ ) );

/**
 * Collects the runtime requirements this host does not meet.
 *
 * @return string[] Human readable failures; empty when the host is supported.
 */
function sitecraft_a11y_unmet_requirements(): array {
	$problems = array();

	if ( version_compare( PHP_VERSION, SITECRAFT_A11Y_MIN_PHP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: minimum PHP version, 2: PHP version running on this host */
			__( 'PHP %1$s or newer is required. This site is running PHP %2$s.', 'sitecraft-accessibility' ),
			SITECRAFT_A11Y_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';

	if ( version_compare( $wp_version, SITECRAFT_A11Y_MIN_WP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: minimum WordPress version, 2: WordPress version running on this site */
			__( 'WordPress %1$s or newer is required. This site is running WordPress %2$s.', 'sitecraft-accessibility' ),
			SITECRAFT_A11Y_MIN_WP,
			$wp_version
		);
	}

	return $problems;
}

/**
 * Prints the unsupported-host notice.
 *
 * @return void
 */
function sitecraft_a11y_requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$problems = sitecraft_a11y_unmet_requirements();

	if ( empty( $problems ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>';
	esc_html_e( 'Sitecraft Accessibility has been deactivated.', 'sitecraft-accessibility' );
	echo '</strong></p><ul style="list-style:disc;margin-left:1.5em">';

	foreach ( $problems as $problem ) {
		printf( '<li>%s</li>', esc_html( $problem ) );
	}

	echo '</ul></div>';
}

/**
 * Deactivates the plugin on an unsupported host.
 *
 * Failing loudly but safely is the point: a fatal error on activation locks an
 * administrator out of the very screen they need to fix the problem.
 *
 * @return void
 */
function sitecraft_a11y_self_deactivate(): void {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	deactivate_plugins( plugin_basename( SITECRAFT_A11Y_FILE ) );

	// Core prints "Plugin activated" from this flag, which would contradict the notice above.
	if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Clearing a core UI flag, not reading user input.
		unset( $_GET['activate'] );
	}
}

if ( ! empty( sitecraft_a11y_unmet_requirements() ) ) {
	add_action( 'admin_notices', 'sitecraft_a11y_requirements_notice' );
	add_action( 'admin_init', 'sitecraft_a11y_self_deactivate' );

	return;
}

require_once SITECRAFT_A11Y_PATH . 'src/Core/Autoloader.php';

( new Autoloader( 'Sitecraft\\Accessibility', __DIR__ . '/src' ) )->register();

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

/**
 * Loads the translation catalogue.
 *
 * @return void
 */
function sitecraft_a11y_load_textdomain(): void {
	load_plugin_textdomain(
		'sitecraft-accessibility',
		false,
		dirname( plugin_basename( SITECRAFT_A11Y_FILE ) ) . '/languages'
	);
}

/**
 * Builds the container, registers every module and boots them.
 *
 * Services are registered as factories rather than instances so a front-end
 * request never constructs the settings schema it does not read.
 *
 * @return void
 */
function sitecraft_a11y_bootstrap(): void {
	$plugin    = Plugin::instance( SITECRAFT_A11Y_FILE );
	$container = $plugin->container();

	$container->set(
		'settings',
		static function (): Registry {
			return Settings::create();
		}
	);

	$container->set(
		'migrations',
		static function (): Migrations {
			return Installer::migrations();
		}
	);

	// The audit module is both a module and a service: the REST routes, the editor
	// sidebar and WP-CLI all reach the analyser, the batch runner and the findings
	// store through it, and they must share one instance so the parsed rule set is
	// built once per request rather than once per consumer.
	$scanner = new Scanner( $container );

	$container->set(
		'scanner',
		static function () use ( $scanner ): Scanner {
			return $scanner;
		}
	);

	$plugin->add_module( $scanner );
	$plugin->add_module( new Remediation( $container ) );
	$plugin->add_module( new PreferencePanel( $container ) );
	$plugin->add_module( new Statement( $container ) );
	$plugin->add_module( new Editor( $container ) );
	$plugin->add_module( new Routes( $container ) );
	$plugin->add_module( new AdminMenu( $container ) );

	$plugin->register();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		Commands::register( $container );
	}
}

add_action( 'init', 'sitecraft_a11y_load_textdomain' );
add_action( 'plugins_loaded', 'sitecraft_a11y_bootstrap' );

// Activation hooks do not fire on update, so the schema runner has to be request driven.
add_action( 'admin_init', array( Installer::class, 'maybe_migrate' ), 5 );

// Registered outside the admin because WP-Cron fires on front-end requests.
add_action( Installer::PRUNE_EVENT, array( Installer::class, 'prune_issues' ) );
