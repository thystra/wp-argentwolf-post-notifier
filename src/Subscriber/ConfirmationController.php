<?php
/**
 * File: src/Subscriber/ConfirmationController.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Contracts\Registerable;

/**
 * Public double-opt-in confirmation route.
 *
 * The emailed URL is a GET-only display route. State changes are isolated to a
 * separate nonce-protected POST action so ordinary link previews and scanners
 * cannot promote a pending subscriber merely by fetching the emailed URL.
 */
final class ConfirmationController implements Registerable {
	/**
	 * POST action used by the confirmation form.
	 */
	public const POST_ACTION = 'argentwolf_post_notifier_confirm_post';

	/**
	 * Confirmation token field name.
	 */
	private const TOKEN_FIELD = 'argentwolf_post_notifier_token';

	/**
	 * Confirmation nonce field name.
	 */
	private const NONCE_FIELD = 'argentwolf_post_notifier_nonce';

	/**
	 * Confirmation nonce action.
	 */
	private const NONCE_ACTION = 'argentwolf_post_notifier_confirm';

	/**
	 * Construct the confirmation controller.
	 *
	 * @param SubscriberService $service Subscriber domain service.
	 */
	public function __construct( private SubscriberService $service ) {
	}

	/**
	 * Register public confirmation actions.
	 *
	 * @return void
	 */
	public function register(): void {
		$get_action  = WordPressConfirmationLinkFactory::GET_ACTION;
		$post_action = self::POST_ACTION;

		add_action( 'admin_post_nopriv_' . $get_action, array( $this, 'handle_get' ) );
		add_action( 'admin_post_' . $get_action, array( $this, 'handle_get' ) );
		add_action( 'admin_post_nopriv_' . $post_action, array( $this, 'handle_post' ) );
		add_action( 'admin_post_' . $post_action, array( $this, 'handle_post' ) );
	}

	/**
	 * Render the display-only confirmation page.
	 *
	 * This method never calls SubscriberService::confirm().
	 *
	 * @return void
	 */
	public function handle_get(): void {
		if ( 'GET' !== $this->request_method() ) {
			$this->render_result_page( false, 405 );
			return;
		}

		// Display-only GET: reading the bearer token here does not change state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = $this->request_value( $_GET, 'token' );
		if ( null === ConfirmationToken::hash_plaintext( $token ) ) {
			$this->render_result_page( false, 400 );
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$message = sprintf(
			/* translators: %s: site name. */
			esc_html__( 'Confirm that you want to receive post notifications from %s.', 'argentwolf-post-notifier' ),
			esc_html( $site_name )
		);
		$html    = '<h1>' . esc_html__( 'Confirm your subscription', 'argentwolf-post-notifier' ) . '</h1>';
		$html   .= '<p>' . $message . '</p>';
		$html   .= '<form method="post" action="' . esc_url( $action_url ) . '">';
		$html   .= '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '">';
		$html   .= '<input type="hidden" name="' . esc_attr( self::TOKEN_FIELD ) . '" value="' . esc_attr( $token ) . '">';
		$html   .= '<input type="hidden" name="' . esc_attr( self::NONCE_FIELD ) . '" value="' . esc_attr( $nonce ) . '">';
		$html   .= '<p><button type="submit">';
		$html   .= esc_html__( 'Confirm subscription', 'argentwolf-post-notifier' );
		$html   .= '</button></p>';
		$html   .= '</form>';
		$html   .= '<p>';
		$html   .= esc_html__(
			'Nothing changes until you choose the confirmation button.',
			'argentwolf-post-notifier'
		);
		$html   .= '</p>';

		$this->render_page( $html, 200 );
	}

	/**
	 * Process an intentional confirmation POST.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		if ( 'POST' !== $this->request_method() ) {
			$this->render_result_page( false, 405 );
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$token = $this->request_value( $_POST, self::TOKEN_FIELD );
		if ( null === ConfirmationToken::hash_plaintext( $token ) ) {
			$this->render_result_page( false, 400 );
			return;
		}

		$this->render_result_page( $this->service->confirm( $token ), 200 );
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
	 * Return the current HTTP request method.
	 *
	 * @return string
	 */
	private function request_method(): string {
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		return is_string( $method ) ? strtoupper( sanitize_text_field( wp_unslash( $method ) ) ) : '';
	}


	/**
	 * Render the final confirmation result page.
	 *
	 * @param bool $confirmed Whether a pending subscriber was promoted.
	 * @param int  $response  HTTP response code.
	 * @return void
	 */
	private function render_result_page( bool $confirmed, int $response ): void {
		if ( $confirmed ) {
			$html  = '<h1>' . esc_html__( 'Subscription confirmed', 'argentwolf-post-notifier' ) . '</h1>';
			$html .= '<p>' . esc_html__( 'You are now subscribed to post notifications.', 'argentwolf-post-notifier' ) . '</p>';
		} else {
			$html  = '<h1>' . esc_html__( 'Unable to confirm subscription', 'argentwolf-post-notifier' ) . '</h1>';
			$message = esc_html__(
				'This confirmation link is invalid, expired, or already used. Request a new confirmation message from the site.',
				'argentwolf-post-notifier'
			);
			$html   .= '<p>' . $message . '</p>';
		}

		$this->render_page( $html, $response );
	}

	/**
	 * Render one private no-index confirmation page and stop request processing.
	 *
	 * @param string $html     Escaped page body HTML.
	 * @param int    $response HTTP response code.
	 * @return void
	 */
	private function render_page( string $html, int $response ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
			header( 'Referrer-Policy: no-referrer', true );
		}

		wp_die(
			// The HTML is assembled only from escaped plugin-owned strings and values.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$html,
			esc_html__( 'Subscription confirmation', 'argentwolf-post-notifier' ),
			array(
				'response'  => $response,
				'back_link' => false,
			)
		);
	}
}

// EOF: src/Subscriber/ConfirmationController.php.
