<?php
/**
 * Rule: image with no alt attribute.
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
 * WCAG 1.1.1 — an image with no `alt` attribute at all.
 *
 * The distinction that matters: `alt=""` is a decision (this image is decorative,
 * skip it) while a missing attribute is an omission, and screen readers fall back
 * to announcing the file name. That is why an empty alt is not reported here.
 */
final class ImageAltMissing extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'img-alt-missing';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Image with no alt attribute', 'sitecraft-accessibility' );
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
		return 'critical';
	}

	/**
	 * Remediation advice.
	 *
	 * @return string
	 */
	public function how_to_fix(): string {
		return __( 'Describe what the image conveys in its alt attribute. If it conveys nothing - a divider, a background flourish, an icon next to text that already says the same thing - give it alt="" so assistive technology skips it.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds images with no alt attribute.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//img[not(@alt)]' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			// An image already removed from the accessibility tree cannot be announced,
			// so its missing alt is not the barrier a reader would meet.
			if ( $this->is_hidden( $node ) ) {
				continue;
			}

			$findings[] = $this->finding( $node );
		}

		return $findings;
	}
}
