<?php
/**
 * Fix: minimum interactive target size.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG 2.2 SC 2.5.8 — 24x24 CSS pixels of target for every control.
 *
 * Small targets are hardest for people with tremor, for anyone using a device one
 * handed, and for touch generally. The criterion has an explicit exception for
 * links inside a sentence, because widening those would break the line box, so
 * this fix deliberately leaves inline prose links alone.
 */
final class TargetSize extends AbstractFix {

	/**
	 * Controls the rule applies to.
	 *
	 * @var string
	 */
	private const SELECTOR = 'button,[role="button"],input[type="button"],input[type="submit"],'
		. 'input[type="reset"],input[type="checkbox"],input[type="radio"],select,summary,'
		. ':where(nav,header,footer,.wp-block-buttons,.wp-block-social-links) a[href]';

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'target-size';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'target_size_enabled' );
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
	 * The sizing rules.
	 *
	 * Logical properties are used so the minimum still applies in a vertical writing
	 * mode, where `min-width` would constrain the wrong axis.
	 *
	 * @return string
	 */
	private function css(): string {
		$size = $this->pixels( 'target_size_px', 24, 24, 48 );

		return self::SELECTOR . '{'
			. 'min-block-size:' . $size . 'px;'
			. 'min-inline-size:' . $size . 'px;'
			. '}'
			. ':where(nav,header,footer) a[href]{'
			// Inline-block is needed for the minimum to take effect on an anchor, which
			// is otherwise laid out as inline content and ignores box sizing.
			. 'display:inline-block;'
			. '}';
	}
}
