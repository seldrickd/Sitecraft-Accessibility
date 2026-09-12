<?php
/**
 * Fix: missing landmark roles.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the landmark roles a theme left out, and only those.
 *
 * Landmarks are how a screen reader user jumps straight to the navigation or the
 * article. A page with none forces linear reading; a page with two mains is worse
 * than one with none, because the shortcut now lies. Every injection below is
 * therefore preceded by a check that the role is genuinely absent.
 *
 * The document-level roles need the finished HTML, which only exists after the
 * theme has run, so this is the one fix that buffers output. The pass is a small
 * number of string searches over a string PHP already holds in memory - no DOM is
 * built and no markup is reformatted.
 */
final class Landmarks extends AbstractFix {

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'landmarks';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'landmarks_enabled' ) && ! empty( $this->roles() );
	}

	/**
	 * Binds the fix.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( in_array( 'navigation', $this->roles(), true ) ) {
			// Menus are reachable through a real filter, so they never need the buffer.
			add_filter( 'wp_nav_menu_args', array( $this, 'ensure_nav_element' ) );
		}

		if ( array() === array_intersect( array( 'banner', 'main', 'contentinfo' ), $this->roles() ) ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
	}

	/**
	 * Landmark roles the site asked for.
	 *
	 * @return string[]
	 */
	private function roles(): array {
		return array_map( 'strval', (array) $this->settings->get( 'landmark_roles', array() ) );
	}

	/**
	 * Wraps a menu in a `nav` element and gives it an accessible name.
	 *
	 * `wp_nav_menu` defaults to a `div` container, which carries no role at all. A
	 * page with several menus also needs each one named, or a screen reader announces
	 * three identical "navigation" landmarks.
	 *
	 * @param array<string, mixed> $args Menu arguments.
	 * @return array<string, mixed>
	 */
	public function ensure_nav_element( $args ): array {
		if ( ! is_array( $args ) ) {
			return array();
		}

		// A theme that passed container => false wants no wrapper; respect that rather
		// than forcing markup into a layout that was built without it.
		if ( isset( $args['container'] ) && false === $args['container'] ) {
			return $args;
		}

		if ( empty( $args['container'] ) || 'div' === $args['container'] ) {
			$args['container'] = 'nav';
		}

		if ( 'nav' === $args['container'] && empty( $args['container_aria_label'] ) ) {
			$label = '';

			if ( ! empty( $args['theme_location'] ) ) {
				$locations = get_registered_nav_menus();
				$label     = (string) ( $locations[ $args['theme_location'] ] ?? '' );
			}

			if ( '' === $label && ! empty( $args['menu'] ) && is_string( $args['menu'] ) ) {
				$label = $args['menu'];
			}

			if ( '' !== $label ) {
				$args['container_aria_label'] = $label;
			}
		}

		return $args;
	}

	/**
	 * Starts buffering the front-end response.
	 *
	 * @return void
	 */
	public function start_buffer(): void {
		if ( is_admin() || is_feed() || is_robots() || is_embed() ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		/**
		 * Filters whether the landmark buffer runs for this request.
		 *
		 * @param bool $enabled Whether to buffer the response.
		 */
		if ( ! apply_filters( 'sitecraft_a11y_buffer_output', true ) ) {
			return;
		}

		ob_start( array( $this, 'add_landmarks' ) );
	}

	/**
	 * Adds the missing roles to a finished HTML document.
	 *
	 * @param string $html Buffered response body.
	 * @return string
	 */
	public function add_landmarks( $html ): string {
		$html = (string) $html;

		// Anything that is not a whole document - a JSON response, a partial from a
		// plugin that started its own buffer - is passed through untouched.
		if ( false === stripos( $html, '<body' ) || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$roles = $this->roles();

		if ( in_array( 'banner', $roles, true ) ) {
			$html = $this->add_banner( $html );
		}

		if ( in_array( 'main', $roles, true ) ) {
			$html = $this->add_main( $html );
		}

		if ( in_array( 'contentinfo', $roles, true ) ) {
			$html = $this->add_contentinfo( $html );
		}

		return $html;
	}

	/**
	 * Adds `role="banner"` to the site header when nothing else claims it.
	 *
	 * The first `header` element in a document is the site header unless an article
	 * comes first, in which case it is that article's own header and carries no
	 * banner role at all. That single check removes the common false positive.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function add_banner( string $html ): string {
		if ( $this->has_role( $html, 'banner' ) ) {
			return $html;
		}

		$header  = stripos( $html, '<header' );
		$article = stripos( $html, '<article' );

		if ( false === $header || ( false !== $article && $article < $header ) ) {
			return $html;
		}

		return $this->inject_attribute( $html, $header, 'role="banner"' );
	}

	/**
	 * Adds `role="main"` to the element the skip link points at.
	 *
	 * Targeting the configured id rather than guessing at a wrapper means the main
	 * landmark and the skip link destination are the same element, which is what a
	 * keyboard user expects when the two features are used together.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function add_main( string $html ): string {
		if ( $this->has_role( $html, 'main' ) || false !== stripos( $html, '<main' ) ) {
			return $html;
		}

		$target = trim( (string) $this->settings->get( 'skip_link_target', '#content' ) );
		$id     = ltrim( $target, '#' );

		if ( '' === $id || 1 !== preg_match( '/^[A-Za-z][\w:.-]*$/', $id ) ) {
			return $html;
		}

		$pattern = '/<(div|section)\b([^>]*\bid=["\']' . preg_quote( $id, '/' ) . '["\'][^>]*)>/i';

		$result = preg_replace( $pattern, '<$1$2 role="main">', $html, 1 );

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Adds `role="contentinfo"` to the site footer.
	 *
	 * The *last* footer in the document is the site footer; earlier ones belong to
	 * articles and comments, which must not be announced as page-level content info.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function add_contentinfo( string $html ): string {
		if ( $this->has_role( $html, 'contentinfo' ) ) {
			return $html;
		}

		$position = strripos( $html, '<footer' );

		if ( false === $position ) {
			return $html;
		}

		return $this->inject_attribute( $html, $position, 'role="contentinfo"' );
	}

	/**
	 * Whether a role is already present anywhere in the document.
	 *
	 * @param string $html Document.
	 * @param string $role Role name.
	 * @return bool
	 */
	private function has_role( string $html, string $role ): bool {
		return 1 === preg_match( '/\brole=["\'][^"\']*\b' . preg_quote( $role, '/' ) . '\b/i', $html );
	}

	/**
	 * Inserts an attribute into the tag that starts at the given offset.
	 *
	 * @param string $html      Document.
	 * @param int    $offset    Offset of the opening angle bracket.
	 * @param string $attribute Attribute to insert, already quoted.
	 * @return string
	 */
	private function inject_attribute( string $html, int $offset, string $attribute ): string {
		$close = strpos( $html, '>', $offset );

		if ( false === $close ) {
			return $html;
		}

		$tag = substr( $html, $offset, $close - $offset );

		// A malformed or unexpectedly long tag is left alone rather than rewritten.
		if ( strlen( $tag ) > 2000 ) {
			return $html;
		}

		// Respect an XHTML-style self-closing slash by inserting ahead of it.
		$insert_at = ( '/' === substr( $html, $close - 1, 1 ) ) ? $close - 1 : $close;

		return substr( $html, 0, $insert_at ) . ' ' . $attribute . ' ' . substr( $html, $insert_at );
	}
}
