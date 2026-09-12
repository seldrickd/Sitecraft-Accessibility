<?php
/**
 * Rule: duplicate id attributes.
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
 * WCAG 4.1.1 — the same id used more than once.
 *
 * Everything that points at an element by id resolves to the first match only:
 * `label[for]`, `aria-labelledby`, `aria-describedby`, `aria-controls` and skip
 * links. A duplicated id silently detaches the second control from its label.
 */
final class DuplicateId extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'duplicate-id';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Duplicate id attribute', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '4.1.1';
	}

	/**
	 * Impact.
	 *
	 * @return string
	 */
	public function severity(): string {
		return 'moderate';
	}

	/**
	 * Remediation advice.
	 *
	 * @return string
	 */
	public function how_to_fix(): string {
		return __( 'Make each id unique on the page. Repeated ids usually come from a block or template part rendered twice; give the template a counter or use wp_unique_id() so every instance gets its own.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds ids that appear more than once.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//*[@id]' );

		if ( false === $nodes ) {
			return $findings;
		}

		$seen = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$id = trim( $node->getAttribute( 'id' ) );

			if ( '' === $id ) {
				continue;
			}

			if ( isset( $seen[ $id ] ) ) {
				// Only the repeat is reported: the first occurrence is the one every
				// reference already resolves to, so it is not the element to change.
				$findings[] = $this->finding( $node, $this->selector( $node ) . ' [id="' . $id . '"]' );
				continue;
			}

			$seen[ $id ] = true;
		}

		return $findings;
	}
}
