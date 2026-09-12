<?php
/**
 * Fix: visible keyboard focus.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG 2.4.7 — puts back the focus ring themes remove.
 *
 * `outline: none` on `:focus` is one of the most common accessibility defects in
 * commercial themes: it is added to stop a ring appearing on mouse click, and it
 * removes the only cue a keyboard user has about where they are. `:focus-visible`
 * solves the original complaint properly, so the ring can be restored without the
 * side effect the theme was trying to avoid.
 */
final class FocusVisibility extends AbstractFix {

	/**
	 * Elements that must show a focus ring.
	 *
	 * @var string
	 */
	private const SELECTOR = 'a[href]:focus-visible,area[href]:focus-visible,button:focus-visible,'
		. 'input:focus-visible,select:focus-visible,textarea:focus-visible,summary:focus-visible,'
		. '[tabindex]:focus-visible,[contenteditable="true"]:focus-visible,'
		. '.wp-block-navigation-item__content:focus-visible';

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'focus-visibility';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'focus_visible_enabled' );
	}

	/**
	 * Binds the fix.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->stylesheet->add( $this->id(), $this->css() );
	}

	/**
	 * The focus ring rules.
	 *
	 * `!important` is used knowingly. The rules exist precisely because another
	 * stylesheet has already set `outline:none`, usually with higher specificity, and
	 * a fix that loses the cascade fixes nothing.
	 *
	 * @return string
	 */
	private function css(): string {
		$color  = $this->color( 'focus_outline_color', '#005fcc' );
		$width  = $this->pixels( 'focus_outline_width', 3, 1, 8 );
		$offset = $this->pixels( 'focus_outline_offset', 2, 0, 8 );

		return self::SELECTOR . '{'
			. 'outline:' . $width . 'px solid ' . $color . ' !important;'
			. 'outline-offset:' . $offset . 'px !important;'
			// A second ring in the opposite tone keeps the indicator visible when the
			// configured colour happens to match the surface behind the control.
			. 'box-shadow:0 0 0 ' . ( $width + $offset ) . 'px rgba(255,255,255,.85) !important;'
			. '}';
	}
}
