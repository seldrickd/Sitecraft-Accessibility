<?php
/**
 * Admin notice queue.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues admin notices that survive the redirect after a form post.
 *
 * Settings screens redirect after saving to avoid resubmission on refresh, which
 * discards anything echoed during the POST. Notices are therefore parked in a
 * per-user transient and flushed on the next screen render.
 */
final class Notices {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const KEY = 'sitecraft_a11y_notices_';

	/**
	 * Adds a notice for the current user.
	 *
	 * @param string $message     Translated, plain-text message.
	 * @param string $type        One of success, error, warning or info.
	 * @param bool   $dismissible Whether the notice can be dismissed.
	 * @return void
	 */
	public static function add( string $message, string $type = 'success', bool $dismissible = true ): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$allowed = array( 'success', 'error', 'warning', 'info' );

		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'info';
		}

		$queue   = self::queue( $user_id );
		$queue[] = array(
			'message'     => $message,
			'type'        => $type,
			'dismissible' => $dismissible,
		);

		set_transient( self::KEY . $user_id, $queue, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Hooks the flusher into the admin notices action.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Prints and clears the queue for the current user.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$queue = self::queue( $user_id );

		if ( empty( $queue ) ) {
			return;
		}

		delete_transient( self::KEY . $user_id );

		foreach ( $queue as $notice ) {
			printf(
				'<div class="notice notice-%1$s%2$s"><p>%3$s</p></div>',
				esc_attr( (string) $notice['type'] ),
				! empty( $notice['dismissible'] ) ? ' is-dismissible' : '',
				esc_html( (string) $notice['message'] )
			);
		}
	}

	/**
	 * Reads the queue for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function queue( int $user_id ): array {
		$queue = get_transient( self::KEY . $user_id );

		return is_array( $queue ) ? $queue : array();
	}
}
