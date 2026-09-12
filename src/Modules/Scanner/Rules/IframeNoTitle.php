<?php
/**
 * Rule: iframe without a title.
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
 * WCAG 4.1.2 — an embedded frame with no accessible name.
 *
 * Screen readers list frames the way they list links. An untitled frame appears as
 * "frame" and the listener has to enter it to find out whether it is the video
 * they wanted or a third-party advertisement.
 */
final class IframeNoTitle extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'iframe-no-title';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Iframe without a title attribute', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '4.1.2';
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
		return __( 'Add a title describing what the frame contains, for example title="Assembly instructions, video". WordPress embeds inherit the provider\'s markup, so a filter on oembed output is usually the durable fix rather than editing each post.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds frames with no name.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//iframe' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			$title = trim( $node->getAttribute( 'title' ) );
			$label = trim( $node->getAttribute( 'aria-label' ) );

			if ( '' !== $title || '' !== $label || '' !== trim( $node->getAttribute( 'aria-labelledby' ) ) ) {
				continue;
			}

			$findings[] = $this->finding( $node );
		}

		return $findings;
	}
}
