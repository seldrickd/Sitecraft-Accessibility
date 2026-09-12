<?php
/**
 * Minimal service container.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use RuntimeException;

/**
 * A deliberately small lazy service locator.
 *
 * Services are registered as factory closures and instantiated on first use, so a
 * request that never touches the admin screens never builds the admin services.
 */
final class Container {

	/**
	 * Registered factory closures, keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved service instances, keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Registers a factory for a service id.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Receives the container, returns the service.
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Whether a service id is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolves a service, building it on first request.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws RuntimeException When the service id was never registered.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException(
				sprintf( 'Unknown service "%s".', $id )
			);
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}

	/**
	 * Returns every registered service id.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->factories );
	}
}
