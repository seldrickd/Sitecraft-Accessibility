<?php
/**
 * Rule: data table without header cells.
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
 * WCAG 1.3.1 — a table with rows but no header cells.
 *
 * Without `th` a screen reader cannot say "Price: 12.99" when the listener moves
 * to a cell; it can only say "12.99". In a table of any width that turns the data
 * into a sequence of unlabelled numbers.
 */
final class TableNoHeader extends AbstractRule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'table-no-header';
	}

	/**
	 * Rule title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Data table without header cells', 'sitecraft-accessibility' );
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
		return __( 'Mark the first row, the first column, or both, with th elements and add scope="col" or scope="row". For a table that is only holding a layout together, add role="presentation" instead so assistive technology ignores the grid.', 'sitecraft-accessibility' );
	}

	/**
	 * Finds tables with data rows but no header cells.
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

			$rows = $xpath->query( './/tr', $node );

			if ( false === $rows || 0 === $rows->length ) {
				continue;
			}

			$headers = $xpath->query( './/th | .//*[@scope] | .//td[@headers]', $node );

			if ( false !== $headers && $headers->length > 0 ) {
				continue;
			}

			$findings[] = $this->finding( $node );
		}

		return $findings;
	}
}
