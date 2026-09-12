<?php
/**
 * Contract for a server-side remediation.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One independently switchable markup fix.
 *
 * A fix changes the HTML the server sends. Nothing in this namespace repaints a
 * rendered page from JavaScript, which is the line between remediation and an
 * overlay: what a fix emits is what a search engine, a screen reader and a
 * `view-source` all see.
 */
interface Fix {

	/**
	 * Stable identifier, used in logs and the module's public API.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether the site has switched this fix on.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Binds the fix to WordPress.
	 *
	 * Only called when {@see Fix::is_enabled()} returns true, so an implementation
	 * never has to guard its own hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
