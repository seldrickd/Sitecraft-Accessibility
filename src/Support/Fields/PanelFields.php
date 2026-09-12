<?php
/**
 * Preference panel settings fields.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares the optional front-end reading preference panel.
 *
 * Off by default and documented as a convenience. It adjusts presentation for a
 * visitor who chooses to use it; it does not repair markup, and it must never be
 * described to visitors as making the site conformant.
 */
final class PanelFields {

	/**
	 * Field definitions for the `panel` section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'          => 'panel_enabled',
				'section'     => 'panel',
				'type'        => 'toggle',
				'label'       => __( 'Show the reading preference panel', 'sitecraft-accessibility' ),
				'description' => __( 'Off by default and deliberately so. Assistive-technology users already have their own tooling, and a panel that fights it helps nobody. Enable it only if your audience has asked for in-page reading controls.', 'sitecraft-accessibility' ),
				'default'     => false,
			),
			array(
				'id'          => 'panel_position',
				'section'     => 'panel',
				'type'        => 'select',
				'label'       => __( 'Panel position', 'sitecraft-accessibility' ),
				'description' => __( 'The toggle is fixed to this corner. Check it does not cover a cookie banner, a chat widget or your own sticky controls; overlapping fixed elements are a common keyboard trap.', 'sitecraft-accessibility' ),
				'default'     => 'bottom-right',
				'choices'     => array(
					'bottom-right' => __( 'Bottom right', 'sitecraft-accessibility' ),
					'bottom-left'  => __( 'Bottom left', 'sitecraft-accessibility' ),
					'top-right'    => __( 'Top right', 'sitecraft-accessibility' ),
					'top-left'     => __( 'Top left', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_button_label',
				'section'     => 'panel',
				'type'        => 'text',
				'label'       => __( 'Toggle button label', 'sitecraft-accessibility' ),
				'description' => __( 'The accessible name of the button that opens the panel. Say what it does, not what it complies with.', 'sitecraft-accessibility' ),
				'default'     => 'Reading preferences',
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_heading',
				'section'     => 'panel',
				'type'        => 'text',
				'label'       => __( 'Panel heading', 'sitecraft-accessibility' ),
				'description' => __( 'Used as the panel\'s accessible name through <code>aria-labelledby</code>, so it is announced when focus enters the panel.', 'sitecraft-accessibility' ),
				'default'     => 'Reading preferences',
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_features',
				'section'     => 'panel',
				'type'        => 'multicheck',
				'label'       => __( 'Available preferences', 'sitecraft-accessibility' ),
				'description' => __( 'Offer only what your design can absorb. Every option is applied through a data attribute on the html element and styled in one stylesheet, so a preference your theme cannot honour is better removed here than left broken.', 'sitecraft-accessibility' ),
				'default'     => array( 'text_size', 'line_spacing', 'underline_links', 'high_contrast', 'pause_animations', 'reading_mask', 'dyslexia_font' ),
				'choices'     => array(
					'text_size'        => __( 'Larger text', 'sitecraft-accessibility' ),
					'line_spacing'     => __( 'Increased line spacing', 'sitecraft-accessibility' ),
					'underline_links'  => __( 'Underline all links', 'sitecraft-accessibility' ),
					'high_contrast'    => __( 'High contrast theme', 'sitecraft-accessibility' ),
					'greyscale'        => __( 'Greyscale', 'sitecraft-accessibility' ),
					'pause_animations' => __( 'Pause animations', 'sitecraft-accessibility' ),
					'reading_mask'     => __( 'Reading mask', 'sitecraft-accessibility' ),
					'dyslexia_font'    => __( 'Dyslexia-friendly font', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_text_sizes',
				'section'     => 'panel',
				'type'        => 'multicheck',
				'label'       => __( 'Text size steps', 'sitecraft-accessibility' ),
				'description' => __( 'SC 1.4.4 requires content to survive 200% zoom, which is why 200 is offered. Fewer, larger steps are easier to operate than a long list.', 'sitecraft-accessibility' ),
				'default'     => array( '100', '125', '150', '200' ),
				'choices'     => array(
					'100' => __( '100% (default)', 'sitecraft-accessibility' ),
					'125' => __( '125%', 'sitecraft-accessibility' ),
					'150' => __( '150%', 'sitecraft-accessibility' ),
					'200' => __( '200%', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_disclaimer',
				'section'     => 'panel',
				'type'        => 'textarea',
				'label'       => __( 'Panel footnote', 'sitecraft-accessibility' ),
				'description' => __( 'Shown at the bottom of the panel. Being straight with visitors about what the panel is - and is not - is both honest and safer than implying a conformance claim you cannot support.', 'sitecraft-accessibility' ),
				'default'     => 'These settings change how this site looks in your browser. They are a convenience, not a substitute for the accessibility work behind the page, and they do not affect your own assistive software.',
				'rows'        => 3,
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_storage_key',
				'section'     => 'panel',
				'type'        => 'text',
				'label'       => __( 'Local storage key', 'sitecraft-accessibility' ),
				'description' => __( 'One namespaced key holds every preference in the visitor\'s browser. Nothing is sent to the server and no cookie is set, so the panel needs no consent banner. Change the key to invalidate stored preferences after a redesign.', 'sitecraft-accessibility' ),
				'default'     => 'sitecraftA11yPrefs',
				'depends_on'  => array( 'panel_enabled' => true ),
			),
			array(
				'id'          => 'panel_z_index',
				'section'     => 'panel',
				'type'        => 'number',
				'label'       => __( 'Stacking order', 'sitecraft-accessibility' ),
				'description' => __( 'Raise this if a sticky header or chat widget covers the toggle. Lower it if the toggle covers something a visitor needs more.', 'sitecraft-accessibility' ),
				'default'     => 99000,
				'min'         => 1,
				'max'         => 2147483000,
				'step'        => 1000,
				'depends_on'  => array( 'panel_enabled' => true ),
			),
		);
	}
}
