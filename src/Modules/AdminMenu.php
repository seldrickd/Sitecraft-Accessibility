<?php
/**
 * Admin menu, screen wiring and admin asset loading.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Admin\DashboardScreen;
use Sitecraft\Accessibility\Admin\IssuesScreen;
use Sitecraft\Accessibility\Admin\SettingsScreen;
use Sitecraft\Accessibility\Core\Admin\Notices;
use Sitecraft\Accessibility\Core\Admin\Screen;
use Sitecraft\Accessibility\Core\Container;
use Sitecraft\Accessibility\Core\Module;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Support\Settings;

/**
 * Puts this plugin's screens under the shared Sitecraft menu.
 *
 * The module holds the container rather than the settings schema so nothing is
 * resolved until a screen is actually drawn. That matters: the schema offers a
 * post-type choice list, and post types are not registered until `init`.
 */
final class AdminMenu implements Module {

	/**
	 * Menu slug shared by every plugin in the suite.
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'sitecraft';

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Screen instances keyed by menu slug.
	 *
	 * @var array<string, Screen>
	 */
	private array $screens = array();

	/**
	 * Page hook suffixes keyed by menu slug, filled in on `admin_menu`.
	 *
	 * @var array<string, string>
	 */
	private array $hooks = array();

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
		return 'admin-menu';
	}

	/**
	 * Binds the module to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Priority 9 for the parent and 10 for the children keeps ordering deterministic
		// no matter which suite plugin loads first.
		add_action( 'admin_menu', array( $this, 'register_parent_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'register_submenus' ), 10 );
		add_action( 'admin_init', array( $this, 'handle_submissions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Registered here rather than on the screen's own load hook: WordPress saves a
		// screen option early in the request that submits it, before any page hook for
		// the screen being returned to has fired.
		add_filter( 'set_screen_option_' . IssuesScreen::PER_PAGE_OPTION, array( $this, 'save_per_page_option' ), 10, 3 );

		Notices::register();
	}

	/**
	 * Registers the shared parent menu if no sibling plugin has already done so.
	 *
	 * @return void
	 */
	public function register_parent_menu(): void {
		global $admin_page_hooks;

		if ( isset( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
			return;
		}

		add_menu_page(
			__( 'Sitecraft', 'sitecraft-accessibility' ),
			__( 'Sitecraft', 'sitecraft-accessibility' ),
			$this->capability(),
			self::PARENT_SLUG,
			'__return_null',
			'dashicons-universal-access',
			58
		);
	}

	/**
	 * Registers one submenu per screen.
	 *
	 * @return void
	 */
	public function register_submenus(): void {
		$capability = $this->capability();

		$labels = array(
			'sitecraft-accessibility'          => __( 'Accessibility', 'sitecraft-accessibility' ),
			'sitecraft-accessibility-issues'   => __( 'Findings', 'sitecraft-accessibility' ),
			'sitecraft-accessibility-settings' => __( 'A11y settings', 'sitecraft-accessibility' ),
		);

		foreach ( $this->screens() as $slug => $screen ) {
			$hook = add_submenu_page(
				self::PARENT_SLUG,
				$screen->title(),
				$labels[ $slug ] ?? $screen->title(),
				$capability,
				$slug,
				array( $screen, 'render' )
			);

			// False when the current user cannot see the page; nothing to hang assets on.
			if ( ! is_string( $hook ) ) {
				continue;
			}

			$this->hooks[ $slug ] = $hook;

			// The findings screen declares a per-page screen option, which has to be
			// registered once WordPress has a current screen and before it renders.
			if ( $screen instanceof IssuesScreen ) {
				add_action( 'load-' . $hook, array( $screen, 'on_load' ) );
			}
		}
	}

	/**
	 * Stores the per-page preference for the findings table.
	 *
	 * Without a filter WordPress discards an unrecognised screen option, so this is
	 * what makes the control persist. The bounds stop a stored value from asking the
	 * database for an unbounded page.
	 *
	 * @param mixed  $status Value to store, or false to discard.
	 * @param string $option Option name being saved.
	 * @param mixed  $value  Submitted value.
	 * @return int
	 */
	public function save_per_page_option( $status, $option, $value ): int {
		unset( $status, $option );

		return max( 1, min( 500, (int) $value ) );
	}

	/**
	 * Gives every screen the chance to handle its own POST before output starts.
	 *
	 * @return void
	 */
	public function handle_submissions(): void {
		foreach ( $this->screens() as $screen ) {
			$screen->maybe_save();
		}
	}

	/**
	 * Loads the admin stylesheet and script on this plugin's screens only.
	 *
	 * @param string $hook_suffix Page hook of the screen being rendered.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'sitecraft-a11y-admin',
			SITECRAFT_A11Y_URL . 'assets/admin/admin.css',
			array(),
			SITECRAFT_A11Y_VERSION
		);

		wp_enqueue_script(
			'sitecraft-a11y-admin',
			SITECRAFT_A11Y_URL . 'assets/admin/admin.js',
			array(),
			SITECRAFT_A11Y_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// The colour picker is only worth its jQuery dependency on the screen that has one.
		if ( isset( $this->hooks['sitecraft-accessibility-settings'] ) && $hook_suffix === $this->hooks['sitecraft-accessibility-settings'] ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script(
				'wp-color-picker',
				'jQuery( function ( $ ) { $( ".sc-color-picker" ).wpColorPicker(); } );'
			);
		}
	}

	/**
	 * Builds the screen instances once per request.
	 *
	 * @return array<string, Screen>
	 */
	private function screens(): array {
		if ( ! empty( $this->screens ) ) {
			return $this->screens;
		}

		$settings = $this->settings();

		foreach ( array( new DashboardScreen( $settings ), new IssuesScreen( $settings ), new SettingsScreen( $settings ) ) as $screen ) {
			$this->screens[ $screen->slug() ] = $screen;
		}

		return $this->screens;
	}

	/**
	 * Resolves the settings schema.
	 *
	 * @return Registry
	 */
	private function settings(): Registry {
		return $this->container->get( 'settings' );
	}

	/**
	 * Capability guarding the menu.
	 *
	 * The parent menu uses the same capability as the submenus so lowering it in
	 * settings does not leave an administrator-only menu wrapped around pages an
	 * editor is allowed to open.
	 *
	 * @return string
	 */
	private function capability(): string {
		return Settings::capability();
	}
}
