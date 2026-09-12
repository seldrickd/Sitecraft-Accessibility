<?php
/**
 * Rule: media that autoplays unmuted.
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
 * WCAG 1.4.2 — audio or video that starts playing with sound.
 *
 * Sound that starts on its own competes directly with a screen reader's speech.
 * The criterion allows it only when it stops within three seconds or a control to
 * silence it comes first in the focus order, neither of which is decidable from
 * markup - so any unmuted autoplay is surfaced for a human to judge.
 */
final class MediaAutoplay extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'media-autoplay';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Media autoplays with sound', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '1.4.2';
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
		return __( 'Remove autoplay, or add the muted attribute so a background video stays silent. If the audio is the point, let the visitor start it - and give the player a pause control that can be reached before anything else on the page.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds unmuted autoplaying media.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//video[@autoplay] | //audio[@autoplay]' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			if ( $node->hasAttribute( 'muted' ) ) {
				continue;
			}

			$findings[] = $this->finding( $node );
		}

		return $findings;
	}
}
