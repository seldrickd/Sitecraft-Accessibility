<?php
/**
 * PSR-4 style autoloader.
 *
 * Runtime dependency free: the suite ships without a Composer vendor directory so
 * it installs on any host, including those that forbid shell access.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a namespace prefix onto a base directory and lazily requires class files.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader, with trailing separator.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Absolute base directory for the prefix, with trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $prefix   Namespace prefix, for example `Acme\Plugin`.
	 * @param string $base_dir Directory that maps to the prefix.
	 */
	public function __construct( string $prefix, string $base_dir ) {
		$this->prefix   = rtrim( $prefix, '\\' ) . '\\';
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Registers the autoloader with the SPL stack.
	 *
	 * @return void
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Resolves a fully qualified class name to a file and requires it.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public function load( string $class_name ): void {
		if ( 0 !== strncmp( $this->prefix, $class_name, strlen( $this->prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $this->prefix ) );

		// Guard against path traversal from a malformed class name.
		if ( false !== strpos( $relative, '..' ) ) {
			return;
		}

		$path = $this->base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
