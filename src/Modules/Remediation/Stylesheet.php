<?php
/**
 * Collector for the CSS the remediation fixes emit.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gathers every fix's CSS into one inline stylesheet.
 *
 * The whole payload is well under a kilobyte, so a separate file would cost a
 * round trip to save nothing. Registering a source-less handle and attaching the
 * rules to it keeps the output inside the enqueue system, where a theme can
 * dequeue it, rather than echoing a stray style tag into `wp_head`.
 */
final class Stylesheet {

	/**
	 * Handle used for the inline stylesheet.
	 *
	 * @var string
	 */
	public const HANDLE = 'sitecraft-a11y-remediation';

	/**
	 * CSS blocks keyed by the fix that contributed them.
	 *
	 * @var array<string, string>
	 */
	private array $blocks = array();

	/**
	 * Whether the enqueue hook has been bound.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Adds a block of CSS.
	 *
	 * @param string $id  Contributing fix id, so a later call can replace its block.
	 * @param string $css CSS to emit.
	 * @return void
	 */
	public function add( string $id, string $css ): void {
		$css = trim( $css );

		if ( '' === $css ) {
			return;
		}

		$this->blocks[ $id ] = $css;

		$this->register();
	}

	/**
	 * Binds the enqueue hook once.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Emits the collected CSS.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( empty( $this->blocks ) ) {
			return;
		}

		// A registered style with no source is the supported way to own an inline block:
		// it gains a handle, a dependency graph and a version like any other stylesheet.
		wp_register_style( self::HANDLE, false, array(), SITECRAFT_A11Y_VERSION );
		wp_enqueue_style( self::HANDLE );

		$css = implode( "\n", $this->blocks );

		/**
		 * Filters the remediation CSS before it is printed.
		 *
		 * @param string                $css    Complete stylesheet.
		 * @param array<string, string> $blocks Blocks keyed by contributing fix.
		 */
		$css = (string) apply_filters( 'sitecraft_a11y_remediation_css', $css, $this->blocks );

		wp_add_inline_style( self::HANDLE, $css );
	}

	/**
	 * The collected CSS, for tests and diagnostics.
	 *
	 * @return string
	 */
	public function css(): string {
		return implode( "\n", $this->blocks );
	}
}
