<?php
/**
 * File: src/Campaign/RecipientUnsubscribeController.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use InvalidArgumentException;

/**
 * Recipient-token unsubscribe and management endpoints for campaign email.
 *
 * - GET of the visible unsubscribe or manage link is display-only and renders
 *   an explicit confirmation form (scanner-safe).
 * - POST of that form, with a WordPress nonce, applies global suppression.
 * - POST of the RFC 8058 one-click endpoint with the exact body
 *   `List-Unsubscribe=One-Click` applies global suppression without a nonce.
 *
 * Responses are generic and never reveal the address, recipient type, or
 * whether a token was valid beyond success/failure. No redirect target is
 * accepted.
 */
final class RecipientUnsubscribeController implements Registerable {
	public const CONFIRM_ACTION = 'argentwolf_post_notifier_recipient_unsubscribe_post';

	private const TOKEN_FIELD  = 'argentwolf_post_notifier_recipient_token';
	private const NONCE_FIELD  = 'argentwolf_post_notifier_recipient_nonce';
	private const NONCE_ACTION = 'argentwolf_post_notifier_recipient_unsubscribe';

	/**
	 * Construct the controller.
	 *
	 * @param CampaignRecipientRepository $recipients  Recipient token lookup.
	 * @param SuppressionService          $suppression Global suppression.
	 */
	public function __construct(
		private CampaignRecipientRepository $recipients,
		private SuppressionService $suppression
	) {
	}

	/**
	 * Register admin-post routes for logged-in and anonymous visitors.
	 *
	 * @return void
	 */
	public function register(): void {
		$routes = array(
			RecipientLinkFactory::UNSUBSCRIBE_ACTION => 'handle_get',
			RecipientLinkFactory::MANAGE_ACTION      => 'handle_get',
			self::CONFIRM_ACTION                     => 'handle_confirm',
			RecipientLinkFactory::ONE_CLICK_ACTION   => 'handle_one_click',
		);
		foreach ( $routes as $action => $method ) {
			add_action( 'admin_post_nopriv_' . $action, array( $this, $method ) );
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Display-only confirmation page.
	 *
	 * @return void
	 */
	public function handle_get(): void {
		if ( 'GET' !== $this->request_method() ) {
			$this->render_result( false, 405 );
			return;
		}
		// Display-only GET. Reading the bearer token does not mutate state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = $this->request_value( $_GET, 'token' );
		$row   = $this->lookup( $token );
		if ( null === $row ) {
			$this->render_result( false, 400 );
			return;
		}

		$html  = '<h1>';
		$html .= esc_html__( 'Manage post notifications', 'argentwolf-post-notifier' );
		$html .= '</h1>';
		$html .= '<p>' . esc_html__(
			'You can stop all post notification email sent to this address by this site.',
			'argentwolf-post-notifier'
		) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= $this->hidden_input( 'action', self::CONFIRM_ACTION );
		$html .= $this->hidden_input( self::TOKEN_FIELD, $token );
		$html .= $this->hidden_input( self::NONCE_FIELD, wp_create_nonce( self::NONCE_ACTION ) );
		$html .= '<p><button type="submit">'
			. esc_html__( 'Stop post notifications', 'argentwolf-post-notifier' )
			. '</button></p></form>';
		$html .= '<p>' . esc_html__(
			'Nothing changes until you choose the button above.',
			'argentwolf-post-notifier'
		) . '</p>';

		$this->render_page( $html, 200 );
	}

	/**
	 * Intentional, nonce-protected confirmation POST.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		if ( 'POST' !== $this->request_method() ) {
			$this->render_result( false, 405 );
			return;
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$token = $this->request_value( $_POST, self::TOKEN_FIELD );
		$this->render_result( $this->unsubscribe( $token ), 200 );
	}

	/**
	 * RFC 8058 one-click unsubscribe POST.
	 *
	 * @return void
	 */
	public function handle_one_click(): void {
		// RFC 8058 requests carry no nonce; the opaque bearer token authorizes.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$body  = $this->request_value( $_POST, 'List-Unsubscribe' );
		$token = $this->request_value( $_GET, 'token' );
		// phpcs:enable
		if ( 'POST' !== $this->request_method() || 'One-Click' !== $body ) {
			$this->render_result( false, 405 );
			return;
		}

		$changed = $this->unsubscribe( $token );
		$this->render_result( $changed, $changed ? 200 : 400 );
	}

	/**
	 * Apply global suppression for one valid token.
	 *
	 * Repeated requests are idempotent and still report success.
	 *
	 * @param string $token Plaintext token.
	 * @return bool
	 */
	public function unsubscribe( string $token ): bool {
		$row = $this->lookup( $token );
		if ( null === $row ) {
			return false;
		}
		try {
			$this->suppression->suppress(
				(string) $row['email_snapshot'],
				SuppressionService::REASON_UNSUBSCRIBE,
				SuppressionService::SOURCE_CAMPAIGN_LINK
			);
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve a token to a recipient row.
	 *
	 * @param string $token Plaintext token.
	 * @return array<string,mixed>|null
	 */
	private function lookup( string $token ): ?array {
		$hash = RecipientToken::hash_plaintext( $token );
		if ( null === $hash ) {
			return null;
		}
		$row = $this->recipients->find_by_token_hash( $hash );
		if ( null === $row || '' === (string) ( $row['email_snapshot'] ?? '' ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Render a generic final result.
	 *
	 * @param bool $changed  Whether the change was applied.
	 * @param int  $response HTTP status.
	 * @return void
	 */
	private function render_result( bool $changed, int $response ): void {
		$html  = '<h1>';
		$html .= $changed
			? esc_html__( 'Subscription updated', 'argentwolf-post-notifier' )
			: esc_html__( 'Unable to update subscription', 'argentwolf-post-notifier' );
		$html .= '</h1><p>';
		$html .= $changed
			? esc_html__(
				'This address will no longer receive post notifications.',
				'argentwolf-post-notifier'
			)
			: esc_html__(
				'This link is invalid or cannot make that change.',
				'argentwolf-post-notifier'
			);
		$html .= '</p>';

		$this->render_page( $html, $response );
	}

	/**
	 * Render one no-index page and stop processing.
	 *
	 * @param string $html     Escaped body HTML.
	 * @param int    $response HTTP status.
	 * @return void
	 */
	private function render_page( string $html, int $response ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
			header( 'Referrer-Policy: no-referrer', true );
		}

		wp_die(
			wp_kses(
				$html,
				array(
					'h1'     => array(),
					'p'      => array(),
					'form'   => array(
						'method' => true,
						'action' => true,
					),
					'input'  => array(
						'type'  => true,
						'name'  => true,
						'value' => true,
					),
					'button' => array( 'type' => true ),
				)
			),
			esc_html__( 'Manage subscription', 'argentwolf-post-notifier' ),
			array(
				'response'  => absint( $response ),
				'back_link' => false,
			)
		);
	}

	/**
	 * Render one escaped hidden input.
	 *
	 * @param string $name  Field name.
	 * @param string $value Field value.
	 * @return string
	 */
	private function hidden_input( string $name, string $value ): string {
		return sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * Read one unslashed request value.
	 *
	 * @param array<string,mixed> $source Request array.
	 * @param string              $key    Key.
	 * @return string
	 */
	private function request_value( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';

		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	/**
	 * Current HTTP method.
	 *
	 * @return string
	 */
	private function request_method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}
}

// EOF: src/Campaign/RecipientUnsubscribeController.php.
