<?php
/**
 * Base admin screen.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Core\Settings\Renderer;

/**
 * Shared chrome and, more importantly, shared guard rails for admin screens.
 *
 * Capability check, nonce verification and redirect-after-post are implemented
 * once here. A subclass that forgets to write a security check still gets one,
 * which is the point: the safe path is the default path.
 */
abstract class Screen {

	/**
	 * Settings schema backing this screen.
	 *
	 * @var Registry
	 */
	protected Registry $settings;

	/**
	 * Form renderer.
	 *
	 * @var Renderer
	 */
	protected Renderer $renderer;

	/**
	 * Capability required to view and save this screen.
	 *
	 * @var string
	 */
	protected string $capability = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Registry $settings Settings schema.
	 */
	public function __construct( Registry $settings ) {
		$this->settings = $settings;
		$this->renderer = new Renderer( $settings );
	}

	/**
	 * Menu slug for this screen.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Translated page title.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * Renders the body of the screen, inside the standard wrapper.
	 *
	 * @return void
	 */
	abstract protected function render_body(): void;

	/**
	 * Tabs for this screen as slug => label. Empty disables the tab bar.
	 *
	 * @return array<string, string>
	 */
	protected function tabs(): array {
		return array();
	}

	/**
	 * Returns the active tab slug.
	 *
	 * @return string
	 */
	protected function current_tab(): string {
		$tabs = $this->tabs();

		if ( empty( $tabs ) ) {
			return '';
		}

		$default = (string) array_key_first( $tabs );

		// Read-only navigation state; nonce verification belongs on writes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $default;

		return array_key_exists( $requested, $tabs ) ? $requested : $default;
	}

	/**
	 * Nonce action string for this screen's save handler.
	 *
	 * @return string
	 */
	protected function nonce_action(): string {
		return $this->slug() . '_save';
	}

	/**
	 * Handles a settings POST, if one is present and valid.
	 *
	 * Called from `admin_init` so the redirect happens before output starts.
	 *
	 * @return void
	 */
	public function maybe_save(): void {
		if ( ! isset( $_POST[ $this->nonce_action() ] ) ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change these settings.', 'sitecraft-accessibility' ),
				403
			);
		}

		check_admin_referer( $this->nonce_action(), $this->nonce_action() );

		$option = $this->settings->option_name();
		$raw    = isset( $_POST[ $option ] ) ? wp_unslash( $_POST[ $option ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the schema below.

		$this->settings->save( is_array( $raw ) ? $raw : array() );

		$this->after_save();

		Notices::add( __( 'Settings saved.', 'sitecraft-accessibility' ), 'success' );

		wp_safe_redirect( $this->redirect_url() );
		exit;
	}

	/**
	 * Hook for subclasses to react to a successful save.
	 *
	 * @return void
	 */
	protected function after_save(): void {
	}

	/**
	 * URL to return to after saving.
	 *
	 * @return string
	 */
	protected function redirect_url(): string {
		$args = array( 'page' => $this->slug() );
		$tab  = $this->current_tab();

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the screen with its standard wrapper and guard rail.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'sitecraft-accessibility' ),
				403
			);
		}

		echo '<div class="wrap sc-wrap">';
		printf( '<h1 class="sc-title">%s</h1>', esc_html( $this->title() ) );

		$this->render_tabs();

		echo '<div class="sc-panel">';
		$this->render_body();
		echo '</div></div>';
	}

	/**
	 * Renders the tab bar as an accessible navigation landmark.
	 *
	 * @return void
	 */
	protected function render_tabs(): void {
		$tabs = $this->tabs();

		if ( count( $tabs ) < 2 ) {
			return;
		}

		$current = $this->current_tab();

		printf(
			'<nav class="nav-tab-wrapper sc-tabs" aria-label="%s">',
			esc_attr__( 'Settings sections', 'sitecraft-accessibility' )
		);

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => $this->slug(),
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);

			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $url ),
				$slug === $current ? ' nav-tab-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Opens a settings form, including its nonce.
	 *
	 * @return void
	 */
	protected function open_form(): void {
		printf(
			'<form method="post" action="%s" class="sc-form">',
			esc_url( $this->redirect_url() )
		);

		wp_nonce_field( $this->nonce_action(), $this->nonce_action() );
	}

	/**
	 * Closes a settings form and prints the submit button.
	 *
	 * @return void
	 */
	protected function close_form(): void {
		submit_button( __( 'Save changes', 'sitecraft-accessibility' ) );
		echo '</form>';
	}
}
