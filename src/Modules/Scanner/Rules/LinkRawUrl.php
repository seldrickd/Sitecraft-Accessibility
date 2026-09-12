<?php
/**
 * Rule: link text that is a bare URL.
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
 * WCAG 2.4.4 — link text that is the raw address.
 *
 * A screen reader reads a pasted URL character by character, punctuation included.
 * A long tracking-parameter URL can take the better part of a minute to announce
 * and tells the listener nothing they could not have inferred from a title.
 */
final class LinkRawUrl extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'link-raw-url';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Link text is a bare URL', 'sitecraft-accessibility' );
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
		return 'minor';
	}

	/**
	 * Remediation advice.
	 *
	 * @return string
	 */
	public function how_to_fix(): string {
		return __( 'Replace the address with the title of the page it points to. Keep the visible URL only when the address itself is the information, such as in printed documentation.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds anchors whose text is an address.
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

			$text = $this->text( $node );

			if ( '' === $text ) {
				continue;
			}

			// A short domain read aloud is tolerable; the criterion bites once the
			// address carries a path, a query string or a tracking tail.
			if ( 1 !== preg_match( '#^(https?://|www\.)\S+$#i', $text ) ) {
				continue;
			}

			if ( strlen( $text ) < 30 && 1 !== preg_match( '#[/?=&%]#', substr( $text, 8 ) ) ) {
				continue;
			}

			$findings[] = $this->finding( $node );
		}

		return $findings;
	}
}
