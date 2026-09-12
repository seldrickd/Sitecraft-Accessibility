<?php
/**
 * Rule: insufficient contrast in inline styles.
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
use Sitecraft\Accessibility\Modules\Scanner\Contrast;

/**
 * WCAG 1.4.3 — text whose inline colours fall below the required ratio.
 *
 * Scope is deliberately narrow. A server-side audit has no cascade and no computed
 * style, so this rule only judges pairs it can resolve entirely from inline `style`
 * attributes on the element and its ancestors. That is exactly the case an editor
 * produces when someone recolours a paragraph by hand, which is where most content
 * contrast failures come from - and it never guesses at a theme's stylesheet, so it
 * does not manufacture findings a developer cannot reproduce.
 */
final class ColorContrastInline extends AbstractRule {

	/**
	 * How far up the tree an inherited background is looked for.
	 *
	 * @var int
	 */
	private const ANCESTOR_DEPTH = 12;

	/**
	 * Required ratio for normal-size text.
	 *
	 * @var float
	 */
	private float $normal_ratio;

	/**
	 * Required ratio for large text.
	 *
	 * @var float
	 */
	private float $large_ratio;

	/**
	 * Constructor.
	 *
	 * @param float $normal_ratio Threshold for normal text.
	 * @param float $large_ratio  Threshold for large text.
	 */
	public function __construct( float $normal_ratio = 4.5, float $large_ratio = 3.0 ) {
		$this->normal_ratio = $normal_ratio;
		$this->large_ratio  = $large_ratio;
	}

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'color-contrast-inline';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Insufficient contrast in inline styles', 'sitecraft-accessibility' );
	}

	/**
	 * Success criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return '1.4.3';
	}

	/**
	 * Conformance level.
	 *
	 * @return string
	 */
	public function level(): string {
		return 'AA';
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
		return __( 'Darken the text or lighten the background until the ratio reaches 4.5:1, or 3:1 for text of at least 24px (18.66px when bold). Colours chosen in the editor bypass your design system entirely, so the durable fix is usually to remove the inline colour and let the theme palette apply.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds inline colour pairs below the configured thresholds.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//*[@style]' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			$declarations = Contrast::parse_declarations( $node->getAttribute( 'style' ) );

			if ( ! isset( $declarations['color'] ) ) {
				continue;
			}

			// Only elements that actually render text can fail a text-contrast criterion.
			if ( '' === $this->own_text( $node ) ) {
				continue;
			}

			$foreground = Contrast::parse_color( $declarations['color'] );

			if ( null === $foreground ) {
				continue;
			}

			$background = $this->inherited_background( $node );

			if ( null === $background ) {
				continue;
			}

			$ratio    = Contrast::ratio( $foreground, $background );
			$size     = $this->font_size( $node );
			$is_bold  = $this->is_bold( $node );
			$required = Contrast::is_large_text( $size, $is_bold ) ? $this->large_ratio : $this->normal_ratio;

			if ( $ratio + 0.005 >= $required ) {
				continue;
			}

			$findings[] = $this->finding(
				$node,
				sprintf(
					/* translators: 1: selector, 2: measured contrast ratio, 3: required contrast ratio */
					__( '%1$s - measured %2$s:1, needs %3$s:1', 'sitecraft-accessibility' ),
					$this->selector( $node ),
					number_format_i18n( round( $ratio, 2 ), 2 ),
					number_format_i18n( $required, 1 )
				)
			);
		}

		return $findings;
	}

	/**
	 * Text belonging directly to this element rather than to its children.
	 *
	 * A wrapper that only colours its descendants is not the element rendering the
	 * text, and reporting it would blame the wrong node.
	 *
	 * @param DOMElement $element Element to inspect.
	 * @return string
	 */
	private function own_text( DOMElement $element ): string {
		$text = '';

		foreach ( $element->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->textContent;
			}
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Nearest background colour declared on the element or an ancestor.
	 *
	 * @param DOMElement $element Element to start from.
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function inherited_background( DOMElement $element ): ?array {
		$current = $element;
		$depth   = 0;

		while ( $current instanceof DOMElement && $depth < self::ANCESTOR_DEPTH ) {
			$declarations = Contrast::parse_declarations( $current->getAttribute( 'style' ) );

			foreach ( array( 'background-color', 'background' ) as $property ) {
				if ( ! isset( $declarations[ $property ] ) ) {
					continue;
				}

				// The background shorthand can carry an image or a gradient, in which case
				// no single colour is behind the text and the pair is not decidable.
				if ( 'background' === $property && 1 === preg_match( '/(url|gradient)\s*\(/i', $declarations[ $property ] ) ) {
					return null;
				}

				$colour = Contrast::parse_color( $declarations[ $property ] );

				if ( null !== $colour ) {
					return $colour;
				}
			}

			$current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
			++$depth;
		}

		return null;
	}

	/**
	 * Effective font size in CSS pixels, resolved through inline styles only.
	 *
	 * @param DOMElement $element Element to inspect.
	 * @return float
	 */
	private function font_size( DOMElement $element ): float {
		$chain   = array();
		$current = $element;
		$depth   = 0;

		while ( $current instanceof DOMElement && $depth < self::ANCESTOR_DEPTH ) {
			$declarations = Contrast::parse_declarations( $current->getAttribute( 'style' ) );

			if ( isset( $declarations['font-size'] ) ) {
				$chain[] = $declarations['font-size'];
			}

			$current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
			++$depth;
		}

		// Resolve outermost first so a relative unit has something to be relative to.
		$size = 16.0;

		foreach ( array_reverse( $chain ) as $declaration ) {
			$resolved = Contrast::length_to_px( (string) $declaration, $size );

			if ( null !== $resolved && $resolved > 0 ) {
				$size = $resolved;
			}
		}

		// Heading elements are large by default even with no inline size, and treating
		// an h1 as 16px would apply the stricter threshold to text that qualifies as large.
		if ( array() === $chain ) {
			$defaults = array(
				'h1' => 32.0,
				'h2' => 24.0,
				'h3' => 18.72,
			);

			$tag = strtolower( $element->tagName );

			if ( isset( $defaults[ $tag ] ) ) {
				return $defaults[ $tag ];
			}
		}

		return $size;
	}

	/**
	 * Whether the element renders in a bold weight.
	 *
	 * @param DOMElement $element Element to inspect.
	 * @return bool
	 */
	private function is_bold( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );

		if ( in_array( $tag, array( 'b', 'strong', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return true;
		}

		$current = $element;
		$depth   = 0;

		while ( $current instanceof DOMElement && $depth < self::ANCESTOR_DEPTH ) {
			$declarations = Contrast::parse_declarations( $current->getAttribute( 'style' ) );

			if ( isset( $declarations['font-weight'] ) ) {
				$weight = strtolower( trim( $declarations['font-weight'] ) );

				if ( 'bold' === $weight || 'bolder' === $weight ) {
					return true;
				}

				if ( is_numeric( $weight ) ) {
					return (int) $weight >= 700;
				}

				return false;
			}

			$current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
			++$depth;
		}

		return false;
	}
}
