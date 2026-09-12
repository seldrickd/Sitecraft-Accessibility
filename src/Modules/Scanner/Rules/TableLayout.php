<?php
/**
 * Rule: layout table without a presentation role.
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
 * WCAG 1.3.1 — a multi-column table with no headers and no presentation role.
 *
 * Either it is data missing its headers or it is a layout grid that has not said
 * so. Both are worth a human decision, because a screen reader announces "table,
 * four columns, nine rows" and then navigates it as data either way.
 */
final class TableLayout extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'table-layout';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Table used for layout without a presentation role', 'sitecraft-accessibility' );
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
		return __( 'Decide what the table is. If it holds data, add th cells with a scope. If it exists only to arrange content, add role="presentation" - and plan to replace it with CSS grid or flexbox, which reflow on a narrow screen where a table cannot.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds unmarked multi-column tables.
	 *
	 * @param DOMXPath    $xpath XPath bound to the document.
	 * @param DOMDocument $doc   Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array {
		$findings = array();
		$nodes    = $xpath->query( '//table' );

		if ( false === $nodes ) {
			return $findings;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			$role = strtolower( trim( $node->getAttribute( 'role' ) ) );

			if ( 'presentation' === $role || 'none' === $role ) {
				continue;
			}

			$headers = $xpath->query( './/th | .//*[@scope]', $node );

			if ( false !== $headers && $headers->length > 0 ) {
				continue;
			}

			if ( $this->widest_row( $xpath, $node ) > 1 ) {
				$findings[] = $this->finding( $node );
			}
		}

		return $findings;
	}

	/**
	 * Number of cells in the widest row of a table.
	 *
	 * @param DOMXPath   $xpath XPath bound to the document.
	 * @param DOMElement $table Table element.
	 * @return int
	 */
	private function widest_row( DOMXPath $xpath, DOMElement $table ): int {
		$rows = $xpath->query( './/tr', $table );

		if ( false === $rows ) {
			return 0;
		}

		$widest = 0;

		foreach ( $rows as $row ) {
			if ( ! $row instanceof DOMElement ) {
				continue;
			}

			$cells = $xpath->query( './td | ./th', $row );
			$width = false === $cells ? 0 : $cells->length;

			$widest = max( $widest, $width );
		}

		return $widest;
	}
}
