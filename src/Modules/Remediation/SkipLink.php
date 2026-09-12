<?php
/**
 * Fix: skip to content link.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG 2.4.1 — a bypass link as the first focusable element on the page.
 *
 * A keyboard user landing on a page with a forty-item menu has to press Tab forty
 * times before reaching the article. The skip link makes that one keystroke.
 */
final class SkipLink extends AbstractFix {

	/**
	 * Class applied to the link and targeted by the emitted CSS.
	 *
	 * @var string
	 */
	private const CLASS_NAME = 'sc-a11y-skip-link';

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'skip-link';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'skip_link_enabled' ) && '' !== $this->target();
	}

	/**
	 * Binds the fix.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_body_open', array( $this, 'render' ), 1 );

		$this->stylesheet->add( $this->id(), $this->css() );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_guard' ), 20 );
	}

	/**
	 * Prints the link.
	 *
	 * @return void
	 */
	public function render(): void {
		$text = trim( (string) $this->settings->get( 'skip_link_text', 'Skip to content' ) );

		if ( '' === $text ) {
			$text = __( 'Skip to content', 'sitecraft-accessibility' );
		}

		printf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			esc_attr( self::CLASS_NAME ),
			esc_attr( $this->target() ),
			esc_html( $text )
		);
	}

	/**
	 * Registers the behaviour that makes the link actually move focus.
	 *
	 * Two problems are solved here and neither is cosmetic. Browsers do not move
	 * keyboard focus to a fragment target that is not itself focusable, so without
	 * `tabindex="-1"` the link scrolls the page and leaves focus in the header. And a
	 * skip link whose target does not exist in this theme is worse than no skip link,
	 * so it removes itself rather than sending someone nowhere.
	 *
	 * @return void
	 */
	public function enqueue_guard(): void {
		$handle = 'sitecraft-a11y-skip-link';

		wp_register_script( $handle, false, array(), SITECRAFT_A11Y_VERSION, true );
		wp_enqueue_script( $handle );

		$script = <<<'JS'
( function () {
	var link = document.querySelector( '.sc-a11y-skip-link' );

	if ( ! link ) {
		return;
	}

	var href = link.getAttribute( 'href' ) || '';
	var target = null;

	if ( href.charAt( 0 ) === '#' && href.length > 1 ) {
		try {
			target = document.getElementById( href.slice( 1 ) );
		} catch ( error ) {
			target = null;
		}
	}

	if ( ! target ) {
		if ( link.parentNode ) {
			link.parentNode.removeChild( link );
		}

		return;
	}

	link.addEventListener( 'click', function () {
		if ( ! target.hasAttribute( 'tabindex' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}

		target.focus();
	} );
}() );
JS;

		wp_add_inline_script( $handle, $script );
	}

	/**
	 * The configured target, normalised to a fragment.
	 *
	 * @return string Empty when the setting is not a usable id selector.
	 */
	private function target(): string {
		$target = trim( (string) $this->settings->get( 'skip_link_target', '#content' ) );

		if ( '' === $target ) {
			return '';
		}

		if ( '#' !== $target[0] ) {
			$target = '#' . $target;
		}

		// Only an id selector can be a link fragment; a class or a descendant selector
		// would produce an href the browser silently ignores.
		if ( 1 !== preg_match( '/^#[A-Za-z][\w:.-]*$/', $target ) ) {
			return '';
		}

		return $target;
	}

	/**
	 * CSS that hides the link until it takes focus.
	 *
	 * Positioning it off-screen rather than using `display:none` is deliberate:
	 * a hidden element is removed from the focus order, which would defeat the point.
	 *
	 * @return string
	 */
	private function css(): string {
		return '.' . self::CLASS_NAME . '{position:absolute;left:-9999px;top:0;z-index:100000;'
			. 'display:block;padding:.75rem 1.25rem;background:#fff;color:#111;'
			. 'font:600 1rem/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;'
			. 'text-decoration:underline;border:2px solid currentColor;border-radius:0 0 4px 0}'
			. '.' . self::CLASS_NAME . ':focus{left:0}';
	}
}
