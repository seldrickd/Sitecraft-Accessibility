<?php
/**
 * Module contract.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module is a self-contained feature that binds itself to WordPress hooks.
 *
 * Modules are constructed eagerly but must not touch the database or emit output
 * from their constructor. All hook registration belongs in `register()`, which the
 * bootstrap calls on `plugins_loaded`.
 */
interface Module {

	/**
	 * Stable identifier used for settings keys and feature toggles.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Binds the module to WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
