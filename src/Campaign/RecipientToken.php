<?php
/**
 * File: src/Campaign/RecipientToken.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

/**
 * Opaque per-recipient campaign bearer token for unsubscribe and management links.
 *
 * Only the SHA-256 hash is persisted in the campaign-recipient row. The
 * plaintext exists only while one message is being composed for transport.
 */
final class RecipientToken {
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
	 * Plaintext for the single message that must disclose it.
	 *
	 * @return string
	 */
	public function plaintext(): string {
		return $this->plaintext;
	}

	/**
	 * Hash persisted in `unsubscribe_token_hash`.
	 *
	 * @return string
	 */
	public function hash(): string {
		return hash( 'sha256', $this->plaintext );
	}

	/**
	 * Validate and hash a candidate plaintext token.
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

// EOF: src/Campaign/RecipientToken.php.
