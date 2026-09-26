<?php
/**
 * File: src/Subscriber/ManageSubscriptionController.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;

/**
 * Public self-service standalone subscription management.
 *
 * GET requests are display-only. State changes require a valid management bearer,
 * an intentional POST, and a WordPress nonce.
 */
final class ManageSubscriptionController implements Registerable {
	public const POST_ACTION = 'argentwolf_post_notifier_manage_subscription_post';

	public const INTENT_UNSUBSCRIBE = 'unsubscribe';
	public const INTENT_RESUBSCRIBE = 'resubscribe';

	private const TOKEN_FIELD  = 'argentwolf_post_notifier_manage_token';
	private const INTENT_FIELD = 'argentwolf_post_notifier_manage_intent';
	private const NONCE_FIELD  = 'argentwolf_post_notifier_manage_nonce';
	private const NONCE_ACTION = 'argentwolf_post_notifier_manage_subscription';

	/**
	 * Construct the controller.
	 *
	 * @param SubscriberService  $service     Subscriber domain service.
	 * @param SuppressionService $suppression Global suppression policy.
	 */
	public function __construct(
		private SubscriberService $service,
		private SuppressionService $suppression
	) {
	}

	/**
	 * Register public management routes.
	 *
	 * @return void
	 */
	public function register(): void {
		$get_action = WordPressManageSubscriptionLinkFactory::GET_ACTION;

		add_action( 'admin_post_nopriv_' . $get_action, array( $this, 'handle_get' ) );
		add_action( 'admin_post_' . $get_action, array( $this, 'handle_get' ) );
		add_action(
			'admin_post_nopriv_' . self::POST_ACTION,
			array( $this, 'handle_post' )
		);
		add_action(
			'admin_post_' . self::POST_ACTION,
			array( $this, 'handle_post' )
		);
	}

	/**
	 * Render the display-only management page.
	 *
	 * @return void
	 */
	public function handle_get(): void {
		if ( 'GET' !== $this->request_method() ) {
			$this->render_result( false, 405 );
			return;
		}

		// Display-only GET. Reading the bearer token here does not mutate state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = $this->request_value( $_GET, 'token' );
		$row   = $this->service->managed_subscriber( $token );
		if ( null === $row ) {
			$this->render_result( false, 400 );
			return;
		}

		$status = SubscriberStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		$email  = (string) ( $row['email'] ?? '' );
		$source = $this->suppression->source_for( $email );

		$intent = null;
		if ( SubscriberStatus::Subscribed === $status && null === $source ) {
			$intent = self::INTENT_UNSUBSCRIBE;
		} elseif (
			SubscriberStatus::Unsubscribed === $status
			&& SuppressionService::SOURCE_SUBSCRIBER_MANAGE === $source
		) {
			$intent = self::INTENT_RESUBSCRIBE;
		}

		$html  = '<h1>';
		$html .= esc_html__( 'Manage post notifications', 'argentwolf-post-notifier' );
		$html .= '</h1>';
		$html .= '<p>';
		$html .= esc_html__(
			'Use this page to change this email subscription.',
			'argentwolf-post-notifier'
		);
		$html .= '</p>';

		if ( null === $intent ) {
			$html .= '<p>';
			$html .= esc_html__(
				'This subscription cannot be changed from this link.',
				'argentwolf-post-notifier'
			);
			$html .= '</p>';
			$this->render_page( $html, 200 );
			return;
		}

		$button = self::INTENT_UNSUBSCRIBE === $intent
			? __( 'Stop post notifications', 'argentwolf-post-notifier' )
			: __( 'Resume post notifications', 'argentwolf-post-notifier' );
		$nonce  = wp_create_nonce( self::NONCE_ACTION );
		$html  .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html  .= $this->hidden_input( 'action', self::POST_ACTION );
		$html  .= $this->hidden_input( self::TOKEN_FIELD, $token );
		$html  .= $this->hidden_input( self::INTENT_FIELD, $intent );
		$html  .= $this->hidden_input( self::NONCE_FIELD, $nonce );
		$html  .= '<p><button type="submit">' . esc_html( $button ) . '</button></p>';
		$html  .= '</form>';
		$html  .= '<p>';
		$html  .= esc_html__(
			'Nothing changes until you choose the button above.',
			'argentwolf-post-notifier'
		);
		$html  .= '</p>';

		$this->render_page( $html, 200 );
	}

	/**
	 * Process an intentional management POST.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		if ( 'POST' !== $this->request_method() ) {
			$this->render_result( false, 405 );
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$token  = $this->request_value( $_POST, self::TOKEN_FIELD );
		$intent = $this->request_value( $_POST, self::INTENT_FIELD );
		if ( null === ManageSubscriptionToken::hash_plaintext( $token ) ) {
			$this->render_result( false, 400 );
			return;
		}

		$changed = match ( $intent ) {
			self::INTENT_UNSUBSCRIBE => $this->service->unsubscribe_managed( $token ),
			self::INTENT_RESUBSCRIBE => $this->service->resubscribe_managed( $token ),
			default => false,
		};

		$this->render_result( $changed, $changed ? 200 : 400 );
	}

	/**
	 * Render a final state-change result.
	 *
	 * @param bool $changed Whether the requested change was applied.
	 * @param int  $response HTTP response code.
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
				'Your post notification preference has been updated.',
				'argentwolf-post-notifier'
			)
			: esc_html__(
				'This management link is invalid or cannot make that change.',
				'argentwolf-post-notifier'
			);
		$html .= '</p>';

		$this->render_page( $html, $response );
	}

	/**
	 * Render one private no-index management page and stop request processing.
	 *
	 * @param string $html Escaped page body HTML.
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
			wp_kses( $html, $this->allowed_page_html() ),
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
	 * @param string $name Field name.
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
	 * Read one request value as unslashed text.
	 *
	 * @param array<string,mixed> $source Request source array.
	 * @param string              $key Request key.
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
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		return strtoupper( $method );
	}

	/**
	 * Allowed HTML for public management pages.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function allowed_page_html(): array {
		return array(
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
		);
	}
}

// EOF: src/Subscriber/ManageSubscriptionController.php.
