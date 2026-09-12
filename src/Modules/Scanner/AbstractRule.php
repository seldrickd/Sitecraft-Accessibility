<?php
/**
 * Shared behaviour for audit rules.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMElement;
use DOMNode;

/**
 * Serialisation, naming and accessible-name helpers every rule needs.
 *
 * Keeping these here means a rule class is only ever its XPath expression and the
 * condition that makes a node a finding, which is the part worth reviewing.
 */
abstract class AbstractRule implements Rule {

	/**
	 * Number of ancestor levels included in a selector hint.
	 *
	 * Enough to recognise the element in a template, short enough to stay readable
	 * in a table cell.
	 *
	 * @var int
	 */
	private const SELECTOR_DEPTH = 4;

	/**
	 * Level A unless a subclass says otherwise.
	 *
	 * @return string
	 */
	public function level(): string {
		return 'A';
	}

	/**
	 * Builds a finding row for an element.
	 *
	 * @param DOMNode     $node Offending node.
	 * @param string|null $hint Optional selector hint override.
	 * @return array{context:string, selector_hint:string}
	 */
	protected function finding( DOMNode $node, ?string $hint = null ): array {
		return array(
			'context'       => $this->markup( $node ),
			'selector_hint' => null !== $hint ? $hint : $this->selector( $node ),
		);
	}

	/**
	 * Serialises a node back to HTML.
	 *
	 * @param DOMNode $node Node to serialise.
	 * @return string
	 */
	protected function markup( DOMNode $node ): string {
		$owner = $node->ownerDocument;

		if ( null === $owner ) {
			return '';
		}

		$html = $owner->saveHTML( $node );

		if ( ! is_string( $html ) ) {
			return '';
		}

		// Collapse the whitespace a pretty-printed template leaves behind so the
		// stored snippet spends its character budget on markup, not indentation.
		$html = preg_replace( '/\s+/u', ' ', $html );

		return trim( (string) $html );
	}

	/**
	 * Builds a short CSS-like path that locates the node in a template.
	 *
	 * Stops at the first ancestor carrying an id, because an id is already unique
	 * and everything above it is noise.
	 *
	 * @param DOMNode $node Node to describe.
	 * @return string
	 */
	protected function selector( DOMNode $node ): string {
		$parts   = array();
		$current = $node instanceof DOMElement ? $node : $node->parentNode;
		$depth   = 0;

		while ( $current instanceof DOMElement && $depth < self::SELECTOR_DEPTH ) {
			$segment = strtolower( $current->tagName );
			$id      = trim( $current->getAttribute( 'id' ) );

			if ( '' !== $id ) {
				array_unshift( $parts, $segment . '#' . $id );
				break;
			}

			$class = trim( $current->getAttribute( 'class' ) );

			if ( '' !== $class ) {
				$classes = preg_split( '/\s+/', $class );

				if ( is_array( $classes ) && isset( $classes[0] ) ) {
					$segment .= '.' . $classes[0];
				}
			}

			$position = $this->position( $current );

			if ( $position > 1 ) {
				$segment .= ':nth-of-type(' . $position . ')';
			}

			array_unshift( $parts, $segment );

			$current = $current->parentNode;
			++$depth;
		}

		// The parser wraps every fragment in html > body, which tells a reader nothing.
		$parts = array_values(
			array_filter(
				$parts,
				static function ( string $part ): bool {
					return 'html' !== $part && 'body' !== $part;
				}
			)
		);

		return implode( ' > ', $parts );
	}

	/**
	 * One-based index of an element among siblings sharing its tag name.
	 *
	 * @param DOMElement $element Element to locate.
	 * @return int
	 */
	protected function position( DOMElement $element ): int {
		$index   = 1;
		$sibling = $element->previousSibling;

		while ( null !== $sibling ) {
			if ( $sibling instanceof DOMElement && $sibling->tagName === $element->tagName ) {
				++$index;
			}

			$sibling = $sibling->previousSibling;
		}

		return $index;
	}

	/**
	 * Normalised text content of a node.
	 *
	 * @param DOMNode $node Node to read.
	 * @return string
	 */
	protected function text( DOMNode $node ): string {
		$text = preg_replace( '/\s+/u', ' ', (string) $node->textContent );

		// Zero-width characters are a common way to defeat an "empty link" test while
		// leaving the link just as unusable, so they are stripped before comparison.
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Best-effort accessible name for an element.
	 *
	 * Implements the parts of accname that are decidable from static markup:
	 * `aria-labelledby`, `aria-label`, descendant text, descendant image `alt`,
	 * then `title`. Anything requiring computed style or live DOM is out of scope
	 * for a server-side audit and is deliberately not guessed at.
	 *
	 * @param DOMElement $element Element to name.
	 * @return string
	 */
	protected function accessible_name( DOMElement $element ): string {
		$labelledby = trim( $element->getAttribute( 'aria-labelledby' ) );

		if ( '' !== $labelledby && null !== $element->ownerDocument ) {
			$names = array();

			foreach ( preg_split( '/\s+/', $labelledby ) ?: array() as $reference ) {
				$target = $this->find_by_id( $element, (string) $reference );

				if ( $target instanceof DOMElement ) {
					$names[] = $this->text( $target );
				}
			}

			$joined = trim( implode( ' ', array_filter( $names ) ) );

			if ( '' !== $joined ) {
				return $joined;
			}
		}

		$label = trim( $element->getAttribute( 'aria-label' ) );

		if ( '' !== $label ) {
			return $label;
		}

		$text = $this->text( $element );

		if ( '' !== $text ) {
			return $text;
		}

		foreach ( $element->getElementsByTagName( 'img' ) as $image ) {
			if ( $image instanceof DOMElement && '' !== trim( $image->getAttribute( 'alt' ) ) ) {
				return trim( $image->getAttribute( 'alt' ) );
			}
		}

		foreach ( $element->getElementsByTagName( 'svg' ) as $svg ) {
			if ( $svg instanceof DOMElement && '' !== trim( $svg->getAttribute( 'aria-label' ) ) ) {
				return trim( $svg->getAttribute( 'aria-label' ) );
			}
		}

		return trim( $element->getAttribute( 'title' ) );
	}

	/**
	 * Resolves an id reference within the same document.
	 *
	 * `DOMDocument::getElementById()` only sees ids libxml has registered, which does
	 * not happen for every fragment we parse, so a linear fallback keeps
	 * `aria-labelledby` resolution honest instead of silently reporting a false
	 * "no accessible name" finding.
	 *
	 * @param DOMElement $context   Element whose document is searched.
	 * @param string     $reference Id to resolve.
	 * @return DOMElement|null
	 */
	protected function find_by_id( DOMElement $context, string $reference ): ?DOMElement {
		$document = $context->ownerDocument;

		if ( null === $document || '' === $reference ) {
			return null;
		}

		$direct = $document->getElementById( $reference );

		if ( $direct instanceof DOMElement ) {
			return $direct;
		}

		foreach ( $document->getElementsByTagName( '*' ) as $candidate ) {
			if ( $candidate instanceof DOMElement && $candidate->getAttribute( 'id' ) === $reference ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Whether the element is hidden from assistive technology.
	 *
	 * An element the accessibility tree never sees cannot present a barrier, so
	 * reporting one is a false positive that trains people to ignore the report.
	 *
	 * @param DOMElement $element Element to test.
	 * @return bool
	 */
	protected function is_hidden( DOMElement $element ): bool {
		$current = $element;

		while ( $current instanceof DOMElement ) {
			if ( 'true' === strtolower( trim( $current->getAttribute( 'aria-hidden' ) ) ) ) {
				return true;
			}

			if ( $current->hasAttribute( 'hidden' ) ) {
				return true;
			}

			$style = strtolower( (string) preg_replace( '/\s+/', '', $current->getAttribute( 'style' ) ) );

			if ( false !== strpos( $style, 'display:none' ) || false !== strpos( $style, 'visibility:hidden' ) ) {
				return true;
			}

			$current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
		}

		return false;
	}
}
