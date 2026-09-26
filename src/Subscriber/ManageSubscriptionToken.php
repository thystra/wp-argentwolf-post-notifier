<?php
/**
 * File: src/Subscriber/ManageSubscriptionToken.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Opaque bearer token for standalone subscription management.
 */
final class ManageSubscriptionToken {
	/**
	 * Construct a token from validated plaintext.
	 *
	 * @param string $plaintext Raw token value.
	 */
	private function __construct( private string $plaintext ) {
	}

	/**
	 * Generate a cryptographically random 256-bit token.
	 *
	 * @return self
	 */
	public static function generate(): self {
		return new self( bin2hex( random_bytes( 32 ) ) );
	}

	/**
	 * Return plaintext for the one request that must disclose it.
	 *
	 * @return string
	 */
	public function plaintext(): string {
		return $this->plaintext;
	}

	/**
	 * Return the SHA-256 hash stored in the database.
	 *
	 * @return string
	 */
	public function hash(): string {
		return hash( 'sha256', $this->plaintext );
	}

	/**
	 * Validate and hash one plaintext management token.
	 *
	 * @param string $plaintext Candidate bearer token.
	 * @return string|null
	 */
	public static function hash_plaintext( string $plaintext ): ?string {
		$plaintext = trim( $plaintext );
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $plaintext ) ) {
			return null;
		}

		return hash( 'sha256', $plaintext );
	}
}

// EOF: src/Subscriber/ManageSubscriptionToken.php.
