<?php
/**
 * Block editor integration.
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
use Sitecraft\Accessibility\Modules\Remediation\LanguageAttribute;
use Sitecraft\Accessibility\Modules\Scanner\RuleRegistry;

/**
 * Puts the audit in the editor, where a problem costs a minute to fix.
 *
 * The same finding costs a support ticket and a content review six months later.
 * Everything the sidebar shows comes from the REST route, which runs the same PHP
 * rules as the scheduled audit, so the editor and the report can never disagree.
 */
final class Editor implements Module {

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	private const HANDLE = 'sitecraft-a11y-editor';

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
		return 'editor';
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

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the sidebar.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$settings = $this->settings();

		if ( ! $settings->is_enabled( 'editor_sidebar_enabled' ) ) {
			return;
		}

		// The sidebar is entirely REST driven, so without the routes it would render an
		// empty panel and an error. Better to not appear at all.
		if ( ! $settings->is_enabled( 'rest_enabled' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && '' !== (string) $screen->post_type ) {
			$audited = array_map( 'strval', (array) $settings->get( 'scan_post_types', array( 'post', 'page' ) ) );

			if ( ! in_array( (string) $screen->post_type, $audited, true ) ) {
				return;
			}
		}

		wp_register_script(
			self::HANDLE,
			SITECRAFT_A11Y_URL . 'assets/public/editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch' ),
			SITECRAFT_A11Y_VERSION,
			true
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.sitecraftA11yEditor = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);

		wp_enqueue_script( self::HANDLE );

		// Registered after enqueue so the JSON translations, if any, attach to a handle
		// WordPress has already accepted.
		wp_set_script_translations( self::HANDLE, 'sitecraft-accessibility', SITECRAFT_A11Y_PATH . 'languages' );

		wp_enqueue_style(
			self::HANDLE,
			SITECRAFT_A11Y_URL . 'assets/public/editor.css',
			array( 'wp-components' ),
			SITECRAFT_A11Y_VERSION
		);
	}

	/**
	 * Data the sidebar needs before its first request.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$settings = $this->settings();

		return array(
			// All three toggles matter: the override is registered as post meta by the
			// language fix, which only binds when remediation is on. Offering the field
			// without the meta behind it would show a control whose value never saves.
			'perPostLanguage' => $settings->is_enabled( 'remediation_enabled' )
				&& $settings->is_enabled( 'lang_attribute_enabled' )
				&& $settings->is_enabled( 'lang_per_post_enabled' ),
			'languageMetaKey' => LanguageAttribute::META_KEY,
			'settingsUrl'     => current_user_can( 'manage_options' )
				? add_query_arg(
					array(
						'page' => 'sitecraft-accessibility-settings',
						'tab'  => 'scanner',
					),
					admin_url( 'admin.php' )
				)
				: '',
			'rules'           => RuleRegistry::create( $settings )->describe(),
		);
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
