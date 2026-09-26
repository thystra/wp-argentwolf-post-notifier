<?php
/**
 * File: src/Subscriber/SignupFormContext.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Authenticated server-rendered consent/source context for public signup forms.
 */
final class SignupFormContext {
	/**
	 * Construct the context signer.
	 *
	 * @param string $signing_key Local signing key.
	 */
	public function __construct( private string $signing_key ) {
	}

	/**
	 * Create one signed public-form context.
	 *
	 * @param string   $consent_text   Consent text shown beside the checkbox.
	 * @param int|null $source_post_id Source post ID.
	 * @return array{context:string,signature:string}
	 */
	public function create( string $consent_text, ?int $source_post_id ): array {
		$json    = wp_json_encode(
			array(
				'consent_text'   => $consent_text,
				'source_post_id' => $source_post_id,
			)
		);
		$context = is_string( $json ) ? bin2hex( $json ) : '';

		return array(
			'context'   => $context,
			'signature' => $this->sign( $context ),
		);
	}

	/**
	 * Verify and decode signed public-form context.
	 *
	 * @param string $context   Encoded context.
	 * @param string $signature Submitted signature.
	 * @return array{consent_text:string,source_post_id:int|null}|null
	 */
	public function verify( string $context, string $signature ): ?array {
		if ( '' === $context || ! hash_equals( $this->sign( $context ), $signature ) ) {
			return null;
		}

		if ( 0 !== strlen( $context ) % 2 || 1 !== preg_match( '/\A[a-f0-9]+\z/D', $context ) ) {
			return null;
		}

		$json = hex2bin( $context );
		if ( ! is_string( $json ) ) {
			return null;
		}

		$value = json_decode( $json, true );
		if ( ! is_array( $value ) || ! isset( $value['consent_text'] ) ) {
			return null;
		}
		if ( ! is_string( $value['consent_text'] ) || '' === trim( $value['consent_text'] ) ) {
			return null;
		}

		$source_post_id = $value['source_post_id'] ?? null;
		if ( null !== $source_post_id && ! is_int( $source_post_id ) ) {
			return null;
		}
		if ( is_int( $source_post_id ) && $source_post_id < 1 ) {
			return null;
		}

		return array(
			'consent_text'   => sanitize_text_field( $value['consent_text'] ),
			'source_post_id' => $source_post_id,
		);
	}

	/**
	 * Sign one encoded context value.
	 *
	 * @param string $context Encoded context.
	 * @return string
	 */
	private function sign( string $context ): string {
		return hash_hmac( 'sha256', $context, $this->signing_key );
	}
}

// EOF: src/Subscriber/SignupFormContext.php.
