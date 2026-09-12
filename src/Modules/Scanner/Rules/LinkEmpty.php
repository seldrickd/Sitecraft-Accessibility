<?php
/**
 * Rule: link with no accessible name.
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
 * WCAG 2.4.4 — a link a screen reader can only announce as "link".
 *
 * Almost always an icon link: a social media glyph, a card wrapping an image whose
 * alt is empty, or a "read more" arrow. The link works perfectly with a mouse and
 * is unusable from a links list.
 */
final class LinkEmpty extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'link-empty';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Link with no accessible name', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '2.4.4';
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
		return __( 'Give the link visible text, or - when the design calls for an icon alone - an aria-label saying where it goes. If the link wraps an image, the image alt becomes the link name, so describe the destination there rather than the picture.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds anchors with no computable name.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//a[@href]' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			// An in-page anchor target written as <a id="section"></a> is markup, not a link.
			$href = trim( $node->getAttribute( 'href' ) );

			if ( '' === $href ) {
				continue;
			}

			if ( '' === $this->accessible_name( $node ) ) {
				$findings[] = $this->finding( $node );
			}
		}

		return $findings;
	}
}
