<?php
/**
 * Rule: positive tabindex.
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
 * WCAG 2.4.3 — `tabindex` greater than zero.
 *
 * A positive tabindex pulls the element to the front of the entire page's tab
 * order, ahead of the skip link and the navigation. One of them is a nuisance;
 * several of them produce a focus order nobody can predict, including the author.
 */
final class TabindexPositive extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'tabindex-positive';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Positive tabindex', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '2.4.3';
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
		return __( 'Remove the value, or set it to 0. Focus order should come from the order of the markup; if the tab order is wrong, move the element in the DOM rather than renumbering the page around it.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds elements pulled out of the natural focus order.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//*[@tabindex]' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$value = trim( $node->getAttribute( 'tabindex' ) );

			if ( 1 !== preg_match( '/^\+?\d+$/', $value ) ) {
				continue;
			}

			if ( (int) $value > 0 ) {
				$findings[] = $this->finding( $node );
			}
		}

		return $findings;
	}
}
