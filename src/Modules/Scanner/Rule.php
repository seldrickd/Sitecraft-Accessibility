<?php
/**
 * Contract for a single audit rule.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;
use DOMXPath;

/**
 * One WCAG success criterion, tested against one parsed document.
 *
 * A rule reports; it never repairs. Every method other than `evaluate()` is
 * metadata, which lets the admin screens, the REST payload and the CLI report
 * describe a finding without instantiating the document it came from.
 */
interface Rule {

	/**
	 * Stable machine identifier, matching the ids offered in the settings screen.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Translated, human readable rule name.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * WCAG success criterion number, for example `1.1.1`.
	 *
	 * @return string
	 */
	public function criterion(): string;

	/**
	 * Conformance level the criterion belongs to: `A`, `AA` or `AAA`.
	 *
	 * @return string
	 */
	public function level(): string;

	/**
	 * Impact on someone using the page: critical, serious, moderate or minor.
	 *
	 * @return string
	 */
	public function severity(): string;

	/**
	 * Translated remediation advice aimed at the person who owns the content.
	 *
	 * @return string
	 */
	public function how_to_fix(): string;

	/**
	 * Tests the document and returns zero or more findings.
	 *
	 * Each finding is an array with a `context` key holding the offending markup
	 * and a `selector_hint` key locating it. The caller truncates and decorates.
	 *
	 * @param DOMXPath  $xpath XPath bound to the document.
	 * @param DOMDocument $doc Parsed document.
	 * @return array<int, array{context:string, selector_hint:string}>
	 */
	public function evaluate( DOMXPath $xpath, DOMDocument $doc ): array;
}
