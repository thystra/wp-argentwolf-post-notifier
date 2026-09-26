<?php
/**
 * File: src/Recipient/RegisteredUserPreferenceRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

use InvalidArgumentException;

/**
 * WordPress user-meta persistence for notification preference.
 */
final class RegisteredUserPreferenceRepository {
	/**
	 * Canonical WordPress user-meta key.
	 */
	public const META_KEY = '_argentwolf_post_notifier_subscription_preference';

	/**
	 * Read one registered user's notification preference.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return RegisteredUserPreference
	 * @throws InvalidArgumentException When the user ID is invalid.
	 */
	public function get( int $user_id ): RegisteredUserPreference {
		$this->assert_user_id( $user_id );

		return RegisteredUserPreference::from_stored_value(
			get_user_meta( $user_id, self::META_KEY, true )
		);
	}

	/**
	 * Persist one registered user's notification preference.
	 *
	 * @param int                      $user_id    WordPress user ID.
	 * @param RegisteredUserPreference $preference Notification preference.
	 * @return void
	 * @throws InvalidArgumentException When the user ID is invalid.
	 */
	public function set(
		int $user_id,
		RegisteredUserPreference $preference
	): void {
		$this->assert_user_id( $user_id );
		update_user_meta( $user_id, self::META_KEY, $preference->value );
	}

	/**
	 * Validate a WordPress user ID before touching metadata.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 * @throws InvalidArgumentException When the user ID is invalid.
	 */
	private function assert_user_id( int $user_id ): void {
		if ( $user_id < 1 ) {
			throw new InvalidArgumentException( 'WordPress user ID must be positive.' );
		}
	}
}

// EOF: src/Recipient/RegisteredUserPreferenceRepository.php.
