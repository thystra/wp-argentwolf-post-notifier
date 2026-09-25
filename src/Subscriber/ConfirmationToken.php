<?php
/**
 * File: src/Subscriber/ConfirmationToken.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Cryptographically secure standalone-subscriber confirmation token.
 */
final class ConfirmationToken {
	/**
	 * Random bytes represented by one token.
	 */
	private const RANDOM_BYTES = 32;

	/**
	 * Construct a generated token.
	 *
	 * @param string $plaintext Plaintext bearer token returned only to the caller.
	 * @param string $hash      SHA-256 token hash safe for persistence.
	 */
	private function __construct(
		private string $plaintext,
		private string $hash
	) {
	}

	/**
	 * Generate a new secure token.
	 *
	 * @return self
	 */
	public static function generate(): self {
		$plaintext = bin2hex( random_bytes( self::RANDOM_BYTES ) );

		return new self( $plaintext, hash( 'sha256', $plaintext ) );
	}

	/**
	 * Hash a syntactically valid plaintext token received from a request.
	 *
	 * Invalid input is rejected before hashing so arbitrarily large public input
	 * never reaches a database lookup.
	 *
	 * @param string $plaintext Candidate bearer token.
	 * @return string|null
	 */
	public static function hash_plaintext( string $plaintext ): ?string {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $plaintext ) ) {
			return null;
		}

		return hash( 'sha256', $plaintext );
	}

	/**
	 * Return the plaintext token for the confirmation message.
	 *
	 * @return string
	 */
	public function plaintext(): string {
		return $this->plaintext;
	}

	/**
	 * Return the persistence-safe token hash.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}
}

// EOF: src/Subscriber/ConfirmationToken.php.
