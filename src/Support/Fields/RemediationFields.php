<?php
/**
 * Remediation settings fields.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares the server-side markup fixes.
 *
 * Every field here changes the HTML a visitor receives. None of them repaint an
 * inaccessible page from JavaScript, which is the distinction between a fix and
 * an overlay.
 */
final class RemediationFields {

	/**
	 * Field definitions for the `remediation` section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'          => 'remediation_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Apply server-side fixes', 'sitecraft-accessibility' ),
				'description' => __( 'Master switch for everything in this section. Turn it off to audit only, which is the right first step when you are evaluating the plugin on a production theme.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'skip_link_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Skip to content link', 'sitecraft-accessibility' ),
				'description' => __( 'Satisfies SC 2.4.1 by giving keyboard and screen-reader users a way past the navigation. Injected at <code>wp_body_open</code>, so a theme that does not call that hook cannot receive it.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'skip_link_target',
				'section'     => 'remediation',
				'type'        => 'text',
				'label'       => __( 'Skip link target', 'sitecraft-accessibility' ),
				'description' => __(
					'A CSS id selector on the element that begins your main content, for example <code>#content</code> or <code>#main</code>. If the target is missing from the page the link is not emitted, because a skip link that goes nowhere is worse than none.',
					'sitecraft-accessibility'
				),
				'default'     => '#content',
				'placeholder' => '#content',
				'depends_on'  => array( 'skip_link_enabled' => true ),
			),
			array(
				'id'          => 'skip_link_text',
				'section'     => 'remediation',
				'type'        => 'text',
				'label'       => __( 'Skip link text', 'sitecraft-accessibility' ),
				'description' => __( 'Read aloud by screen readers as the first item on the page. Keep it short and literal.', 'sitecraft-accessibility' ),
				'default'     => 'Skip to content',
				'depends_on'  => array( 'skip_link_enabled' => true ),
			),
			array(
				'id'          => 'landmarks_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Add missing landmark roles', 'sitecraft-accessibility' ),
				'description' => __( 'Adds an ARIA role only where the theme has neither the native element nor an existing role. Themes built on <code>&lt;header&gt;</code>, <code>&lt;main&gt;</code> and <code>&lt;footer&gt;</code> are left untouched: doubling up on landmarks makes navigation worse, not better.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'landmark_roles',
				'section'     => 'remediation',
				'type'        => 'multicheck',
				'label'       => __( 'Landmarks to supply', 'sitecraft-accessibility' ),
				'description' => __( 'A page must have exactly one banner, one main and one contentinfo. Navigation may repeat, and each repeat should carry its own accessible name.', 'sitecraft-accessibility' ),
				'default'     => array( 'banner', 'main', 'contentinfo', 'navigation' ),
				'choices'     => array(
					'banner'      => __( 'banner (site header)', 'sitecraft-accessibility' ),
					'main'        => __( 'main (primary content)', 'sitecraft-accessibility' ),
					'contentinfo' => __( 'contentinfo (site footer)', 'sitecraft-accessibility' ),
					'navigation'  => __( 'navigation (menus)', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'landmarks_enabled' => true ),
			),
			array(
				'id'          => 'focus_visible_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Restore visible focus', 'sitecraft-accessibility' ),
				'description' => __( 'Many themes remove the browser focus ring for aesthetics, which breaks SC 2.4.7 outright. This puts a ring back using <code>:focus-visible</code>, so it appears for keyboard users without following a mouse click.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'focus_outline_color',
				'section'     => 'remediation',
				'type'        => 'color',
				'label'       => __( 'Focus ring colour', 'sitecraft-accessibility' ),
				'description' => __( 'SC 1.4.11 asks for 3:1 against the adjacent background. The default is a strong blue that survives on both light and dark surfaces; check it against your own palette before changing it.', 'sitecraft-accessibility' ),
				'default'     => '#005fcc',
				'depends_on'  => array( 'focus_visible_enabled' => true ),
			),
			array(
				'id'          => 'focus_outline_width',
				'section'     => 'remediation',
				'type'        => 'number',
				'label'       => __( 'Focus ring width (px)', 'sitecraft-accessibility' ),
				'description' => __( 'WCAG 2.2 SC 2.4.13 treats a 2px perimeter as the minimum for the AAA focus-appearance criterion. Three is a comfortable default that stays visible on busy backgrounds.', 'sitecraft-accessibility' ),
				'default'     => 3,
				'min'         => 1,
				'max'         => 8,
				'step'        => 1,
				'depends_on'  => array( 'focus_visible_enabled' => true ),
			),
			array(
				'id'          => 'focus_outline_offset',
				'section'     => 'remediation',
				'type'        => 'number',
				'label'       => __( 'Focus ring offset (px)', 'sitecraft-accessibility' ),
				'description' => __( 'Space between the control and its ring. A small offset keeps the ring readable against a button of a similar colour; a large one can overlap neighbouring controls.', 'sitecraft-accessibility' ),
				'default'     => 2,
				'min'         => 0,
				'max'         => 8,
				'step'        => 1,
				'depends_on'  => array( 'focus_visible_enabled' => true ),
			),
			array(
				'id'          => 'lang_attribute_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Enforce the document language', 'sitecraft-accessibility' ),
				'description' => __( 'SC 3.1.1 requires a correct <code>lang</code> on the html element; without it a screen reader may pronounce your content with the wrong voice. This fills the attribute in from the site language when the theme omits it.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'lang_per_post_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Allow a per-post language override', 'sitecraft-accessibility' ),
				'description' => __( 'Adds a language field to the editor for sites that publish in more than one language without a translation plugin. Leave it off if a multilingual plugin already manages the attribute.', 'sitecraft-accessibility' ),
				'default'     => false,
				'depends_on'  => array( 'lang_attribute_enabled' => true ),
			),
			array(
				'id'          => 'reduced_motion_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Honour reduced-motion preferences', 'sitecraft-accessibility' ),
				'description' => __( 'Emits a <code>prefers-reduced-motion</code> block that disables animation, transitions and smooth scrolling for visitors who asked their operating system for less movement. Related to SC 2.3.3.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'target_size_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Enforce minimum target size', 'sitecraft-accessibility' ),
				'description' => __( 'WCAG 2.2 SC 2.5.8 asks for 24x24 CSS pixels of clickable area. Enforcing it globally can stretch dense toolbars and inline icon rows, so review your header and footer after enabling it.', 'sitecraft-accessibility' ),
				'default'     => false,
			),
			array(
				'id'          => 'target_size_px',
				'section'     => 'remediation',
				'type'        => 'number',
				'label'       => __( 'Minimum target size (px)', 'sitecraft-accessibility' ),
				'description' => __( 'Twenty-four is the AA threshold. Forty-four matches the stricter SC 2.5.5 (AAA) and the platform guidance for touch, but disturbs more layouts.', 'sitecraft-accessibility' ),
				'default'     => 24,
				'min'         => 24,
				'max'         => 48,
				'step'        => 1,
				'depends_on'  => array( 'target_size_enabled' => true ),
			),
			array(
				'id'          => 'image_alt_audit_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Record images rendered without alt text', 'sitecraft-accessibility' ),
				'description' => __( 'Logs front-end images that reach the browser with no alt attribute, including ones produced by themes and page builders that the content scan never sees. It records, it never invents alt text: a machine-written description is a new barrier, not a fix.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'decorative_alt_enabled',
				'section'     => 'remediation',
				'type'        => 'toggle',
				'label'       => __( 'Mark decorative images as decorative', 'sitecraft-accessibility' ),
				'description' => __( 'Adds <code>alt=""</code> to attachments a human has flagged as decorative in the media library. An empty alt is the correct markup for an image that carries no information: it tells assistive technology to skip the image rather than announce its filename.', 'sitecraft-accessibility' ),
				'default'     => false,
			),
		);
	}
}
