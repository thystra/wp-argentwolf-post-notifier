<?php
/**
 * File: src/Database/EmailIdentity.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use RuntimeException;

/**
 * Canonical email normalization and keyed deterministic hashing.
 */
final class EmailIdentity {
	/**
	 * Persistent keyed-hash secret option.
	 */
	public const HASH_KEY_OPTION = 'argentwolf_post_notifier_email_hash_key';

	/**
	 * Construct an email identity helper.
	 *
	 * @param string|null $hash_key Optional raw 32-byte key for isolated tests.
	 */
	public function __construct( private ?string $hash_key = null ) {
	}

	/**
	 * Normalize and validate an email address.
	 *
	 * @param string $email Candidate email address.
	 * @return string|null
	 */
	public function normalize( string $email ): ?string {
		$normalized = strtolower( trim( $email ) );

		if ( '' === $normalized ) {
			return null;
		}

		if ( function_exists( 'is_email' ) ) {
			$validated = is_email( $normalized );
			return false === $validated ? null : strtolower( $validated );
		}

		return false === filter_var( $normalized, FILTER_VALIDATE_EMAIL )
			? null
			: $normalized;
	}

	/**
	 * Return the deterministic keyed hash for a valid normalized address.
	 *
	 * @param string $email Candidate email address.
	 * @return string|null
	 */
	public function hash( string $email ): ?string {
		$normalized = $this->normalize( $email );
		if ( null === $normalized ) {
			return null;
		}

		return hash_hmac( 'sha256', $normalized, $this->key() );
	}

	/**
	 * Create the persistent keyed-hash secret if it does not exist.
	 *
	 * Concurrent activation is safe because add_option() is atomic for a unique
	 * option name. A losing process reads the key created by the winner.
	 *
	 * @return void
	 * @throws RuntimeException When the stored hash key cannot be initialized or validated.
	 */
	public static function ensure_hash_key(): void {
		$existing = get_option( self::HASH_KEY_OPTION, '' );
		if ( is_string( $existing ) && '' !== $existing ) {
			self::validate_hash_key();
			return;
		}

		// Base64 stores the binary key safely in a text WordPress option.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = base64_encode( random_bytes( 32 ) );
		if ( add_option( self::HASH_KEY_OPTION, $encoded, '', false ) ) {
			return;
		}

		$existing = get_option( self::HASH_KEY_OPTION, '' );
		if ( ! is_string( $existing ) || '' === $existing ) {
			throw new RuntimeException( 'Email hash key could not be initialized.' );
		}

		self::decode_key( $existing );
	}

	/**
	 * Validate the persisted keyed-hash secret without changing it.
	 *
	 * @return void
	 * @throws RuntimeException When the persisted hash key is missing or malformed.
	 */
	public static function validate_hash_key(): void {
		$encoded = get_option( self::HASH_KEY_OPTION, '' );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			throw new RuntimeException( 'Email hash key is unavailable.' );
		}

		self::decode_key( $encoded );
	}

	/**
	 * Resolve the raw hash key.
	 *
	 * @return string
	 * @throws RuntimeException When the configured hash key is unavailable or invalid.
	 */
	private function key(): string {
		if ( null !== $this->hash_key ) {
			if ( 32 !== strlen( $this->hash_key ) ) {
				throw new RuntimeException( 'Email hash key must contain 32 bytes.' );
			}
			return $this->hash_key;
		}

		$encoded = get_option( self::HASH_KEY_OPTION, '' );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			throw new RuntimeException( 'Email hash key is unavailable.' );
		}

		return self::decode_key( $encoded );
	}

	/**
	 * Decode and validate the persisted hash key.
	 *
	 * @param string $encoded Base64-encoded key.
	 * @return string
	 * @throws RuntimeException When the persisted hash key is malformed.
	 */
	private static function decode_key( string $encoded ): string {
		// Base64 reverses the text-safe storage encoding applied during creation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$key = base64_decode( $encoded, true );
		if ( false === $key || 32 !== strlen( $key ) ) {
			throw new RuntimeException( 'Stored email hash key is invalid.' );
		}

		return $key;
	}
}

// EOF: src/Database/EmailIdentity.php.
