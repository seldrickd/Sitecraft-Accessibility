<?php
/**
 * Rule: alt text that is only a filename.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;
use DOMElement;
use DOMXPath;
use Sitecraft\Accessibility\Modules\Scanner\AbstractRule;

/**
 * WCAG 1.1.1 — alt text that is the file name rather than a description.
 *
 * This is what a media library produces when nobody types anything: WordPress
 * seeds the alt field from the upload name, so "IMG_2043" and "hero-banner-2-final"
 * reach production looking filled in. It is a text alternative that carries none
 * of the information the image carries.
 */
final class ImageAltFilename extends AbstractRule {

	/**
	 * File extensions that mark alt text as a leaked file name.
	 *
	 * @var string
	 */
	private const EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic';

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'img-alt-filename';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Alt text is only a filename', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '1.1.1';
	}

	/**
	 * Impact.
	 *
	 * @return string
	 */
	public function severity(): string {
		return 'serious';
	}

	/**
	 * Remediation advice.
	 *
	 * @return string
	 */
	public function how_to_fix(): string {
		return __( 'Replace the file name with a sentence describing what the image shows or does in this context. The same photograph needs different alt text on a product page and in a news story.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds alt attributes that are file names or slugs.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//img[@alt]' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$alt = trim( $node->getAttribute( 'alt' ) );

			if ( '' === $alt || $this->is_hidden( $node ) ) {
				continue;
			}

			if ( $this->looks_like_filename( $alt ) || $this->matches_source( $alt, $node->getAttribute( 'src' ) ) ) {
				$findings[] = $this->finding( $node );
			}
		}

		return $findings;
	}

	/**
	 * Whether the alt text reads as a file name rather than prose.
	 *
	 * @param string $alt Alt attribute value.
	 * @return bool
	 */
	private function looks_like_filename( string $alt ): bool {
		if ( 1 === preg_match( '/^[\w\-. ()]+\.(' . self::EXTENSIONS . ')$/i', $alt ) ) {
			return true;
		}

		// Camera and phone exports: IMG_2043, DSC00123, PXL_20240118_101500, Screenshot 2024-01-18.
		if ( 1 === preg_match( '/^(img|dsc|dscn|pxl|mvimg|photo|image|screen ?shot|untitled)[\s_-]*[0-9][\w\s.-]*$/i', $alt ) ) {
			return true;
		}

		// A slug with no spaces and at least two separators is a file stem, not a sentence.
		if ( false === strpos( $alt, ' ' ) && 1 === preg_match( '/^[a-z0-9]+([_-][a-z0-9]+){2,}$/i', $alt ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the alt text is simply the source file's own name.
	 *
	 * @param string $alt Alt attribute value.
	 * @param string $src Image source.
	 * @return bool
	 */
	private function matches_source( string $alt, string $src ): bool {
		$src = trim( $src );

		if ( '' === $src ) {
			return false;
		}

		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$name = basename( $path );

		if ( '' === $name ) {
			return false;
		}

		$stem       = (string) preg_replace( '/\.[a-z0-9]+$/i', '', $name );
		$normalised = strtolower( (string) preg_replace( '/[\s_-]+/', '', $alt ) );

		return '' !== $stem && strtolower( (string) preg_replace( '/[\s_-]+/', '', $stem ) ) === $normalised;
	}
}
