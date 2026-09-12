<?php
/**
 * Fix: honour reduced-motion preferences.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Respects the operating-system "reduce motion" setting.
 *
 * Parallax, auto-advancing carousels and long transitions trigger nausea and
 * migraine in people with vestibular disorders. The visitor has already told their
 * device they do not want movement; this passes that answer on to a theme that
 * never asked.
 */
final class ReducedMotion extends AbstractFix {

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'reduced-motion';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'reduced_motion_enabled' );
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
	 * The reduced-motion block.
	 *
	 * Animations are shortened to a hair rather than removed, because a script that
	 * waits for `animationend` before revealing content would hang forever if the
	 * animation never ran.
	 *
	 * @return string
	 */
	private function css(): string {
		return '@media (prefers-reduced-motion: reduce){'
			. '*,*::before,*::after{'
			. 'animation-duration:.01ms !important;'
			. 'animation-iteration-count:1 !important;'
			. 'transition-duration:.01ms !important;'
			. 'scroll-behavior:auto !important;'
			. '}'
			. 'html:focus-within{scroll-behavior:auto !important}'
			. '[data-sc-a11y-motion="pause"] *{animation-play-state:paused !important}'
			. '}';
	}
}
