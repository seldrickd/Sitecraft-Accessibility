<?php
/**
 * Debug logger.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes diagnostics to the WordPress debug log, and only when debugging is on.
 *
 * Silent in production by default: a plugin that writes to the log on every
 * request is a plugin that fills a disk.
 */
final class Logger {

	/**
	 * Prefix prepended to every line.
	 *
	 * @var string
	 */
	private const PREFIX = '[sitecraft-accessibility]';

	/**
	 * Logs an informational message.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Optional structured context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::write( 'INFO', $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Optional structured context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::write( 'WARNING', $message, $context );
	}

	/**
	 * Logs an error.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Optional structured context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'ERROR', $message, $context );
	}

	/**
	 * Writes a line to the debug log when WP_DEBUG_LOG is active.
	 *
	 * @param string               $level   Severity label.
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Optional structured context.
	 * @return void
	 */
	private static function write( string $level, string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$line = sprintf( '%s %s: %s', self::PREFIX, $level, $message );

		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );

			if ( is_string( $encoded ) ) {
				$line .= ' ' . $encoded;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG.
		error_log( $line );
	}
}
