<?php
/**
 * Versioned schema migrations.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs numbered schema steps once each and records how far the install has come.
 *
 * Activation hooks do not fire on plugin *updates*, so relying on them alone
 * leaves upgraded sites on an old schema. This runner is invoked on every admin
 * request and is a no-op once the stored version matches the target.
 */
final class Migrations {

	/**
	 * Option holding the schema version this install has reached.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Migration callables keyed by integer version, ascending.
	 *
	 * @var array<int, callable>
	 */
	private array $steps;

	/**
	 * Constructor.
	 *
	 * @param string                $option Option name for the stored version.
	 * @param array<int, callable>  $steps  Migration callables keyed by version.
	 */
	public function __construct( string $option, array $steps ) {
		$this->option = $option;

		ksort( $steps, SORT_NUMERIC );
		$this->steps = $steps;
	}

	/**
	 * Highest version defined by the registered steps.
	 *
	 * @return int
	 */
	public function target_version(): int {
		if ( empty( $this->steps ) ) {
			return 0;
		}

		return (int) max( array_keys( $this->steps ) );
	}

	/**
	 * Version this install has already reached.
	 *
	 * @return int
	 */
	public function current_version(): int {
		return (int) get_option( $this->option, 0 );
	}

	/**
	 * Runs any pending steps in order.
	 *
	 * A step that throws stops the run and leaves the version pointing at the last
	 * step that completed, so the next request retries from the failure point
	 * rather than skipping past it.
	 *
	 * @return int Version reached after the run.
	 */
	public function run(): int {
		$current = $this->current_version();
		$target  = $this->target_version();

		if ( $current >= $target ) {
			return $current;
		}

		foreach ( $this->steps as $version => $step ) {
			if ( $version <= $current ) {
				continue;
			}

			try {
				call_user_func( $step );
			} catch ( \Throwable $error ) {
				Logger::error(
					sprintf( 'Migration to version %d failed: %s', $version, $error->getMessage() )
				);

				break;
			}

			$current = (int) $version;
			update_option( $this->option, $current, false );
		}

		return $current;
	}
}
