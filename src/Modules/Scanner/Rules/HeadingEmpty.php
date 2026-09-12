<?php
/**
 * Rule: heading with no text.
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
 * WCAG 1.3.1 — a heading element containing nothing to announce.
 *
 * Usually a spacer: an author presses Enter inside a heading block, or a builder
 * emits an empty h2 as a divider. The heading still appears in the outline, so a
 * listener navigating by headings lands on silence.
 */
final class HeadingEmpty extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'heading-empty';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Empty heading', 'sitecraft-accessibility' );
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
		return 'serious';
	}

	/**
	 * Remediation advice.
	 *
	 * @return string
	 */
	public function how_to_fix(): string {
		return __( 'Give the heading text, or delete it. If it exists to create vertical space, remove it and add margin in CSS - an empty heading is announced as a heading and leads nowhere.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds headings with no accessible name.
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

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			if ( '' === $this->accessible_name( $node ) ) {
				$findings[] = $this->finding( $node );
			}
		}

		return $findings;
	}
}
