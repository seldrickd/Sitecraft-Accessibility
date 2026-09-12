<?php
/**
 * Rule: generic link text.
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
 * WCAG 2.4.4 — link text that says nothing about the destination.
 *
 * Screen reader users navigate by pulling up a list of every link on the page.
 * Twelve entries reading "read more" is a list of twelve identical choices.
 */
final class LinkGenericText extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'link-generic-text';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Generic link text', 'sitecraft-accessibility' );
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
		return 'moderate';
	}

	/**
	 * Remediation advice.
	 *
	 * @return string
	 */
	public function how_to_fix(): string {
		return __( 'Make the link text name its destination: "Read the 2025 annual report" rather than "read more". Where the design requires a short label, keep the label visible and put the full wording in aria-label so both audiences are served.', 'sitecraft-accessibility' );
	}

	/**
	 * Phrases that carry no destination information.
	 *
	 * Translated so a non-English site can supply its own equivalents; the English
	 * list is the fallback for the many sites that never translate it.
	 *
	 * @return string[]
	 */
	private function generic_phrases(): array {
		$phrases = array(
			'click here',
			'click',
			'here',
			'read more',
			'more',
			'learn more',
			'find out more',
			'see more',
			'more info',
			'more information',
			'details',
			'link',
			'this link',
			'this page',
			'continue',
			'continue reading',
			'go',
			'download',
			'view',
			'?',
			'>',
			'>>',
		);

		/**
		 * Filters the phrases treated as uninformative link text.
		 *
		 * @param string[] $phrases Lowercase phrases, punctuation already stripped.
		 */
		return array_map( 'strval', (array) apply_filters( 'sitecraft_a11y_generic_link_phrases', $phrases ) );
	}

	/**
	 * Finds links whose whole accessible name is a generic phrase.
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

		$phrases = $this->generic_phrases();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			$name = $this->accessible_name( $node );

			if ( '' === $name ) {
				// Reported by link-empty; two findings for one anchor is noise.
				continue;
			}

			$lowered = strtolower( $name );
			$trimmed = trim( (string) preg_replace( '/[\s\p{P}]+$/u', '', $lowered ) );

			// Both forms are compared because trailing punctuation is usually decoration
			// ("read more...") but sometimes is the entire link text ("»").
			if ( in_array( $lowered, $phrases, true ) || ( '' !== $trimmed && in_array( $trimmed, $phrases, true ) ) ) {
				$findings[] = $this->finding( $node );
			}
		}

		return $findings;
	}
}
