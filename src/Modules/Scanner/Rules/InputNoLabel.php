<?php
/**
 * Rule: form control without a label.
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
 * WCAG 3.3.2 — a form control nothing names.
 *
 * A placeholder is not a label: it disappears the moment someone types, it is not
 * reliably announced, and its contrast is usually well below the threshold. This
 * rule deliberately does not accept one as a substitute.
 */
final class InputNoLabel extends AbstractRule {

	/**
	 * Input types that carry their own name or take no user input.
	 *
	 * @var string[]
	 */
	private const EXEMPT_TYPES = array( 'hidden', 'submit', 'button', 'reset', 'image' );

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'input-no-label';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Form control without a label', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '3.3.2';
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
		return __( 'Add a visible label element whose for attribute matches the control id. Where the design has no room for one, aria-label is acceptable - but a visible label also helps people using speech input, who have to say the label out loud to reach the field.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds unlabelled controls.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//input | //select | //textarea' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			if ( 'input' === strtolower( $node->tagName ) ) {
				$type = strtolower( trim( $node->getAttribute( 'type' ) ) );

				if ( in_array( $type, self::EXEMPT_TYPES, true ) ) {
					continue;
				}
			}

			if ( $this->has_name( $xpath, $node ) ) {
				continue;
			}

			$findings[] = $this->finding( $node );
		}

		return $findings;
	}

	/**
	 * Whether anything names the control.
	 *
	 * @param DOMXPath   $xpath   XPath bound to the document.
	 * @param DOMElement $control Control element.
	 * @return bool
	 */
	private function has_name( DOMXPath $xpath, DOMElement $control ): bool {
		if ( '' !== trim( $control->getAttribute( 'aria-label' ) ) ) {
			return true;
		}

		$labelledby = trim( $control->getAttribute( 'aria-labelledby' ) );

		if ( '' !== $labelledby ) {
			foreach ( preg_split( '/\s+/', $labelledby ) ?: array() as $reference ) {
				if ( null !== $this->find_by_id( $control, (string) $reference ) ) {
					return true;
				}
			}
		}

		// title is a poor label but it does produce an accessible name, so a control
		// carrying one is not silent and does not belong in this report.
		if ( '' !== trim( $control->getAttribute( 'title' ) ) ) {
			return true;
		}

		$id = trim( $control->getAttribute( 'id' ) );

		if ( '' !== $id ) {
			$labels = $xpath->query( sprintf( '//label[@for="%s"]', $this->escape( $id ) ) );

			if ( false !== $labels && $labels->length > 0 ) {
				return true;
			}
		}

		// A wrapping label associates implicitly, with no id needed on either element.
		$ancestor = $control->parentNode;

		while ( $ancestor instanceof DOMElement ) {
			if ( 'label' === strtolower( $ancestor->tagName ) ) {
				return '' !== $this->text( $ancestor );
			}

			$ancestor = $ancestor->parentNode;
		}

		return false;
	}

	/**
	 * Makes a value safe to embed in a double-quoted XPath literal.
	 *
	 * Ids come from post content, so an unescaped quote would break the expression
	 * and silently drop the query rather than raising anything visible.
	 *
	 * @param string $value Raw attribute value.
	 * @return string
	 */
	private function escape( string $value ): string {
		return str_replace( '"', '', $value );
	}
}
