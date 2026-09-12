<?php
/**
 * Rule: skipped heading level.
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
 * WCAG 1.3.1 — a heading level jumps by more than one.
 *
 * Headings are the table of contents a screen reader user navigates by. Jumping
 * from h2 to h4 reads as a missing section: the listener cannot tell whether they
 * skipped past something or the author skipped a level for the font size.
 */
final class HeadingOrderSkip extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'heading-order-skip';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Heading level skipped', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '1.3.1';
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
		return __( 'Choose the heading level for the structure, not the size: a subsection of an h2 is an h3. If the h3 is too large for the design, style it in CSS rather than promoting the markup to h4.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds headings that descend more than one level at a time.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );

		if ( false === $nodes ) {
			return $findings;
		}

		$previous = 0;

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			$level = (int) substr( $node->tagName, 1 );

			// Only descending jumps break the outline. Climbing back from h4 to h2 closes
			// two sections at once, which is exactly how nested content ends.
			if ( $previous > 0 && $level > $previous + 1 ) {
				$findings[] = $this->finding( $node );
			}

			$previous = $level;
		}

		return $findings;
	}
}
