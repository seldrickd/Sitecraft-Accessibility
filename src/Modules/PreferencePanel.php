<?php
/**
 * Optional reading preference panel.
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

/**
 * A reader-convenience panel, off by default and honest about what it is.
 *
 * It changes presentation for a visitor who asks it to. It does not repair markup,
 * it does not affect anyone's screen reader, and it is not a conformance mechanism -
 * the panel says so in its own footnote. Shipping it at all is only defensible
 * because it is optional, disclosed, and itself built to the criteria: real
 * buttons, real state, keyboard operable, and no interference with assistive
 * technology the visitor already runs.
 */
final class PreferencePanel implements Module {

	/**
	 * DOM id of the panel region.
	 *
	 * @var string
	 */
	private const PANEL_ID = 'sc-a11y-panel';

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
		return 'preference-panel';
	}

	/**
	 * Binds the module to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
	}

	/**
	 * Whether the panel should appear on this request.
	 *
	 * @return bool
	 */
	private function is_active(): bool {
		if ( ! $this->settings()->is_enabled( 'panel_enabled' ) ) {
			return false;
		}

		if ( is_feed() || is_embed() || is_robots() ) {
			return false;
		}

		/**
		 * Filters whether the preference panel renders on this request.
		 *
		 * @param bool $active Whether to render the panel.
		 */
		return (bool) apply_filters( 'sitecraft_a11y_panel_active', true );
	}

	/**
	 * Loads the panel's assets.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		wp_enqueue_style(
			'sitecraft-a11y-panel',
			SITECRAFT_A11Y_URL . 'assets/public/panel.css',
			array(),
			SITECRAFT_A11Y_VERSION
		);

		wp_register_script(
			'sitecraft-a11y-panel',
			SITECRAFT_A11Y_URL . 'assets/public/panel.js',
			array(),
			SITECRAFT_A11Y_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'sitecraft-a11y-panel',
			'window.sitecraftA11yPanel = ' . wp_json_encode( $this->script_config() ) . ';',
			'before'
		);

		wp_enqueue_script( 'sitecraft-a11y-panel' );

		// The stacking order is the one thing a site owner has to tune per theme, so it
		// is a custom property rather than a value baked into the stylesheet.
		wp_add_inline_style(
			'sitecraft-a11y-panel',
			':root{--sc-a11y-panel-z:' . $this->z_index() . '}'
		);
	}

	/**
	 * Configuration handed to the front-end script.
	 *
	 * @return array<string, mixed>
	 */
	private function script_config(): array {
		return array(
			'storageKey' => $this->storage_key(),
			'features'   => $this->features(),
			'textSizes'  => $this->text_sizes(),
			'labels'     => array(
				'opened' => __( 'Reading preferences opened.', 'sitecraft-accessibility' ),
				'closed' => __( 'Reading preferences closed.', 'sitecraft-accessibility' ),
				'reset'  => __( 'Reading preferences reset to their defaults.', 'sitecraft-accessibility' ),
			),
		);
	}

	/**
	 * Renders the panel.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		$features = $this->features();

		if ( empty( $features ) ) {
			return;
		}

		$settings = $this->settings();
		$heading  = (string) $settings->get( 'panel_heading', 'Reading preferences' );
		$label    = (string) $settings->get( 'panel_button_label', 'Reading preferences' );
		$position = (string) $settings->get( 'panel_position', 'bottom-right' );

		printf(
			'<div class="sc-a11y-panel-root" data-position="%s">',
			esc_attr( $position )
		);

		printf(
			'<button type="button" class="sc-a11y-panel-toggle" aria-expanded="false" aria-controls="%1$s">%2$s<span class="sc-a11y-panel-toggle-text">%3$s</span></button>',
			esc_attr( self::PANEL_ID ),
			$this->toggle_icon(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup from a private method, no dynamic values.
			esc_html( $label )
		);

		printf(
			'<div class="sc-a11y-panel" id="%1$s" role="dialog" aria-modal="false" aria-labelledby="%1$s-heading" hidden>',
			esc_attr( self::PANEL_ID )
		);

		printf(
			'<div class="sc-a11y-panel-header"><h2 id="%1$s-heading" class="sc-a11y-panel-heading">%2$s</h2><button type="button" class="sc-a11y-panel-close" aria-label="%3$s">&times;</button></div>',
			esc_attr( self::PANEL_ID ),
			esc_html( $heading ),
			esc_attr__( 'Close reading preferences', 'sitecraft-accessibility' )
		);

		echo '<div class="sc-a11y-panel-body">';

		if ( in_array( 'text_size', $features, true ) ) {
			$this->render_text_sizes();
		}

		$this->render_toggles( $features );

		echo '</div>';

		$this->render_footer();

		echo '</div>';

		// The live region is a sibling of the panel, not a child of it. Inside the
		// panel it would be hidden the moment the panel closed, and an announcement
		// placed in a `hidden` subtree is never read - which would have silently lost
		// exactly the message a non-sighted visitor needs most, the one confirming the
		// panel has closed. It stays empty in the markup so nothing is read on load.
		echo '<p class="sc-a11y-panel-status" role="status" aria-live="polite"></p>';

		echo '</div>';
	}

	/**
	 * Renders the text size buttons.
	 *
	 * A button group with `aria-pressed` rather than a select: the options are few,
	 * the effect is immediate, and a radio group would imply a form to submit.
	 *
	 * @return void
	 */
	private function render_text_sizes(): void {
		$sizes = $this->text_sizes();

		if ( count( $sizes ) < 2 ) {
			return;
		}

		printf(
			'<div role="group" aria-labelledby="%1$s-text-size" class="sc-a11y-panel-group"><span class="sc-a11y-panel-group-label" id="%1$s-text-size">%2$s</span><div class="sc-a11y-panel-sizes">',
			esc_attr( self::PANEL_ID ),
			esc_html__( 'Text size', 'sitecraft-accessibility' )
		);

		foreach ( $sizes as $size ) {
			printf(
				'<button type="button" class="sc-a11y-size" data-sc-size="%1$s" aria-pressed="%2$s">%3$s</button>',
				esc_attr( (string) $size ),
				100 === (int) $size ? 'true' : 'false',
				esc_html(
					sprintf(
						/* translators: %s: text size as a percentage */
						__( '%s%%', 'sitecraft-accessibility' ),
						number_format_i18n( (int) $size )
					)
				)
			);
		}

		echo '</div></div>';
	}

	/**
	 * Renders the checkbox preferences.
	 *
	 * @param string[] $features Enabled feature keys.
	 * @return void
	 */
	private function render_toggles( array $features ): void {
		$labels = $this->feature_labels();

		echo '<ul class="sc-a11y-panel-list">';

		foreach ( $features as $feature ) {
			if ( 'text_size' === $feature || ! isset( $labels[ $feature ] ) ) {
				continue;
			}

			$input_id = self::PANEL_ID . '-' . str_replace( '_', '-', $feature );

			printf(
				'<li class="sc-a11y-panel-item"><input type="checkbox" id="%1$s" class="sc-a11y-pref" data-sc-pref="%2$s" /><label for="%1$s">%3$s</label></li>',
				esc_attr( $input_id ),
				esc_attr( $feature ),
				esc_html( $labels[ $feature ] )
			);
		}

		echo '</ul>';
	}

	/**
	 * Renders the reset control and the disclaimer.
	 *
	 * @return void
	 */
	private function render_footer(): void {
		$note = trim( (string) $this->settings()->get( 'panel_disclaimer', '' ) );

		echo '<div class="sc-a11y-panel-footer">';

		printf(
			'<button type="button" class="sc-a11y-panel-reset">%s</button>',
			esc_html__( 'Reset preferences', 'sitecraft-accessibility' )
		);

		if ( '' !== $note ) {
			printf( '<p class="sc-a11y-panel-note">%s</p>', esc_html( $note ) );
		}

		echo '</div>';
	}

	/**
	 * The toggle button's icon.
	 *
	 * Marked `aria-hidden` because the button already has a text label; announcing
	 * the graphic as well would say the same thing twice.
	 *
	 * @return string
	 */
	private function toggle_icon(): string {
		return '<svg class="sc-a11y-panel-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
			. '<circle cx="12" cy="4.2" r="2.2" fill="currentColor"></circle>'
			. '<path fill="currentColor" d="M20 7.5H4a1 1 0 0 0 0 2h4.6v3.1L6.8 19a1 1 0 0 0 1.9.6l1.9-5.3h2.8l1.9 5.3a1 1 0 0 0 1.9-.6l-1.8-6.4V9.5H20a1 1 0 0 0 0-2Z"></path>'
			. '</svg>';
	}

	/**
	 * Enabled feature keys, in the order the panel lists them.
	 *
	 * @return string[]
	 */
	private function features(): array {
		$allowed  = array_keys( $this->feature_labels() );
		$selected = array_map( 'strval', (array) $this->settings()->get( 'panel_features', array() ) );

		return array_values( array_intersect( $allowed, $selected ) );
	}

	/**
	 * Translated labels for every supported preference.
	 *
	 * @return array<string, string>
	 */
	private function feature_labels(): array {
		return array(
			'text_size'        => __( 'Larger text', 'sitecraft-accessibility' ),
			'line_spacing'     => __( 'More space between lines', 'sitecraft-accessibility' ),
			'underline_links'  => __( 'Underline every link', 'sitecraft-accessibility' ),
			'high_contrast'    => __( 'High contrast', 'sitecraft-accessibility' ),
			'greyscale'        => __( 'Remove colour', 'sitecraft-accessibility' ),
			'pause_animations' => __( 'Pause animations', 'sitecraft-accessibility' ),
			'reading_mask'     => __( 'Reading mask', 'sitecraft-accessibility' ),
			'dyslexia_font'    => __( 'Dyslexia-friendly font', 'sitecraft-accessibility' ),
		);
	}

	/**
	 * Text size steps, always including 100 so a visitor can get back.
	 *
	 * @return int[]
	 */
	private function text_sizes(): array {
		$sizes = array_map( 'absint', (array) $this->settings()->get( 'panel_text_sizes', array( 100, 125, 150, 200 ) ) );
		$sizes = array_values( array_unique( array_filter( $sizes ) ) );

		if ( ! in_array( 100, $sizes, true ) ) {
			array_unshift( $sizes, 100 );
		}

		sort( $sizes );

		return $sizes;
	}

	/**
	 * Local storage key.
	 *
	 * @return string
	 */
	private function storage_key(): string {
		$key = trim( (string) $this->settings()->get( 'panel_storage_key', 'sitecraftA11yPrefs' ) );

		return '' === $key ? 'sitecraftA11yPrefs' : $key;
	}

	/**
	 * Stacking order for the panel.
	 *
	 * @return int
	 */
	private function z_index(): int {
		$value = (int) $this->settings()->get( 'panel_z_index', 99000 );

		return max( 1, min( 2147483000, $value ) );
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
