<?php
/**
 * File: src/Subscriber/PublicSignupController.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Contracts\Registerable;

/**
 * Public no-JavaScript signup submission endpoint.
 */
final class PublicSignupController implements Registerable {
	/** Public signup POST action. */
	public const POST_ACTION = 'argentwolf_post_notifier_subscribe';

	/** Query variable used for generic post-redirect-get status. */
	public const RESULT_QUERY = 'argentwolf_post_notifier_signup';

	/** Generic accepted-request result. */
	public const RESULT_RECEIVED = 'request_received';

	/** Generic request-shape failure result. */
	public const RESULT_ERROR = 'request_error';

	/** Stable status-region anchor. */
	public const STATUS_ID = 'argentwolf-post-notifier-subscribe-status';

	/** Form nonce action. */
	public const NONCE_ACTION = 'argentwolf_post_notifier_subscribe';

	/** Form nonce field. */
	public const NONCE_FIELD = 'argentwolf_post_notifier_subscribe_nonce';

	/** Signed render-context field. */
	public const CONTEXT_FIELD = 'argentwolf_post_notifier_context';

	/** Render-context signature field. */
	public const CONTEXT_SIGNATURE_FIELD = 'argentwolf_post_notifier_context_signature';

	/** Return URL field. */
	public const RETURN_FIELD = 'argentwolf_post_notifier_return';

	/** Email field. */
	public const EMAIL_FIELD = 'argentwolf_post_notifier_email';

	/** Optional display-name field. */
	public const NAME_FIELD = 'argentwolf_post_notifier_name';

	/** Consent checkbox field. */
	public const CONSENT_FIELD = 'argentwolf_post_notifier_consent';

	/** Honeypot field. */
	public const HONEYPOT_FIELD = 'argentwolf_post_notifier_website';

	/**
	 * Construct the public signup endpoint.
	 *
	 * @param PublicSignupProcessor $processor Privacy-preserving signup coordinator.
	 * @param SignupFormContext      $context   Signed rendered-form context.
	 */
	public function __construct(
		private PublicSignupProcessor $processor,
		private SignupFormContext $context
	) {
	}

	/**
	 * Register public form actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::POST_ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle_submission' ) );
	}

	/**
	 * Process a public form POST and redirect back to one generic result state.
	 *
	 * @return void
	 */
	public function handle_submission(): void {
		$return_url = $this->posted_return_url();
		if ( 'POST' !== $this->request_method() ) {
			$this->redirect_with_result( $return_url, self::RESULT_ERROR );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce being verified.
		$nonce = $this->request_value( $_POST, self::NONCE_FIELD );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_result( $return_url, self::RESULT_ERROR );
			return;
		}

		$context   = $this->request_value( $_POST, self::CONTEXT_FIELD );
		$signature = $this->request_value( $_POST, self::CONTEXT_SIGNATURE_FIELD );
		$rendered  = $this->context->verify( $context, $signature );
		$consent   = $this->request_value( $_POST, self::CONSENT_FIELD );
		if ( null === $rendered || '1' !== $consent ) {
			$this->redirect_with_result( $return_url, self::RESULT_ERROR );
			return;
		}

		$email        = $this->request_value( $_POST, self::EMAIL_FIELD );
		$display_name = $this->request_value( $_POST, self::NAME_FIELD );
		$honeypot     = $this->request_value( $_POST, self::HONEYPOT_FIELD );

		$this->processor->process(
			new PublicSignupRequest(
				$email,
				'' === $display_name ? null : $display_name,
				$rendered['consent_text'],
				'block',
				$rendered['source_post_id'],
				$honeypot,
				$this->remote_address()
			)
		);

		$this->redirect_with_result( $return_url, self::RESULT_RECEIVED );
	}

	/**
	 * Read one request value as unslashed text.
	 *
	 * @param array<string,mixed> $source Request source array.
	 * @param string              $key    Request key.
	 * @return string
	 */
	private function request_value( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Return a same-site redirect target from untrusted POST input.
	 *
	 * @return string
	 */
	private function posted_return_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Local redirect only.
		$value = $_POST[ self::RETURN_FIELD ] ?? '';
		if ( ! is_string( $value ) ) {
			return home_url( '/' );
		}

		$candidate = esc_url_raw( wp_unslash( $value ) );
		return wp_validate_redirect( $candidate, home_url( '/' ) );
	}

	/**
	 * Return the current HTTP request method.
	 *
	 * @return string
	 */
	private function request_method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		return strtoupper( $method );
	}

	/**
	 * Return the request network address without persisting it.
	 *
	 * @return string|null
	 */
	private function remote_address(): ?string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return '' === $address ? null : $address;
	}

	/**
	 * Redirect to a local generic form result.
	 *
	 * @param string $return_url Validated local return URL.
	 * @param string $result     Generic result code.
	 * @return void
	 */
	private function redirect_with_result( string $return_url, string $result ): void {
		$location = add_query_arg( self::RESULT_QUERY, $result, $return_url );
		$location .= '#' . self::STATUS_ID;

		wp_safe_redirect( $location, 303, 'ArgentWolf Post Notifier' );
	}
}

// EOF: src/Subscriber/PublicSignupController.php.
