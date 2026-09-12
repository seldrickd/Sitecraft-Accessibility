<?php
/**
 * HTML fragment parsing.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;

/**
 * Turns post content into a DOM the rules can query.
 *
 * Post content is never valid XHTML and frequently is not even well-formed HTML,
 * so libxml's error stream is silenced for the duration of the parse and restored
 * afterwards. Leaving `libxml_use_internal_errors( true )` set would swallow XML
 * errors for every other plugin that runs later in the request.
 */
final class Document {

	/**
	 * Parses an HTML fragment.
	 *
	 * @param string $html Raw HTML, typically post content after `the_content`.
	 * @return DOMDocument|null Null when there is nothing parseable.
	 */
	public static function load( string $html ): ?DOMDocument {
		$html = trim( $html );

		if ( '' === $html ) {
			return null;
		}

		// Strip anything the audit must not execute or reason about. Script and style
		// bodies are not content, and their contents routinely contain angle brackets
		// that turn a valid document into an unparseable one.
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );

		$previous = libxml_use_internal_errors( true );

		$document = new DOMDocument( '1.0', 'UTF-8' );

		// Without an explicit encoding hint libxml assumes ISO-8859-1 and mangles every
		// multibyte character, which would corrupt the snippet stored with each finding.
		$wrapped = '<?xml encoding="utf-8" ?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			. $html
			. '</body></html>';

		$loaded = $document->loadHTML(
			$wrapped,
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_NOENT
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		return $document;
	}

	/**
	 * Renders a post's content the way a visitor receives it.
	 *
	 * @param string $content       Raw post content.
	 * @param bool   $apply_filters Whether to run `the_content`.
	 * @return string
	 */
	public static function prepare( string $content, bool $apply_filters ): string {
		if ( ! $apply_filters ) {
			return $content;
		}

		// Blocks, shortcodes and embeds only produce their real markup here, and the
		// real markup is what the criteria apply to. The cost is that third-party
		// filters execute during a cron batch, which is why this is a setting.
		return (string) apply_filters( 'the_content', $content );
	}
}
