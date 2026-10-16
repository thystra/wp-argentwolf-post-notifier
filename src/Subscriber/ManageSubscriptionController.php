<?php
/**
 * File: src/Subscriber/ManageSubscriptionController.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Privacy\ClickConsent;
use ArgentWolf\PostNotifier\Privacy\PrivacySettings;
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

	public const INTENT_CLICK_CONSENT  = 'click_consent_grant';
	public const INTENT_CLICK_WITHDRAW = 'click_consent_withdraw';

	private const TOKEN_FIELD  = 'argentwolf_post_notifier_manage_token';
	private const INTENT_FIELD = 'argentwolf_post_notifier_manage_intent';
	private const NONCE_FIELD  = 'argentwolf_post_notifier_manage_nonce';
	private const NONCE_ACTION = 'argentwolf_post_notifier_manage_subscription';

	/**
	 * Construct the controller.
	 *
	 * @param SubscriberService    $service     Subscriber domain service.
	 * @param SuppressionService   $suppression Global suppression policy.
	 * @param ClickConsent|null    $consent     Per-user click consent store.
	 * @param PrivacySettings|null $privacy     Tracking settings.
	 */
	public function __construct(
		private SubscriberService $service,
		private SuppressionService $suppression,
		private ?ClickConsent $consent = null,
		private ?PrivacySettings $privacy = null
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

		$html  = '<h1>';
		$html .= esc_html__( 'Manage post notifications', 'argentwolf-post-notifier' );
		$html .= '</h1>';
		$html .= '<p>';
		$html .= esc_html__(
			'Use this page to change this email subscription.',
			'argentwolf-post-notifier'
		);
		$html .= '</p>';
		$html .= $this->management_form( $token, $row );

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

		$consent_intents = array( self::INTENT_CLICK_CONSENT, self::INTENT_CLICK_WITHDRAW );
		if ( in_array( $intent, $consent_intents, true ) ) {
			$this->handle_consent( $token, $intent );
			return;
		}

		$outcome = $this->service->apply_managed( $token, $intent );
		if ( ! $outcome['changed'] ) {
			$this->render_result( false, 400 );
			return;
		}

		// The presented bearer was rotated; offer the next action on the new one.
		$html  = '<h1>';
		$html .= esc_html__( 'Subscription updated', 'argentwolf-post-notifier' ) . '</h1>';
		$html .= '<p>' . esc_html__(
			'Your post notification preference has been updated.',
			'argentwolf-post-notifier'
		) . '</p>';
		$next  = null === $outcome['token']
			? null
			: $this->service->managed_subscriber( $outcome['token'] );
		if ( null !== $next ) {
			$html .= $this->management_form( (string) $outcome['token'], $next );
		}
		$this->render_page( $html, 200 );
	}

	/**
	 * Grant or withdraw per-user click consent; the bearer is not rotated.
	 *
	 * @param string $token  Plaintext management token.
	 * @param string $intent Consent intent.
	 * @return void
	 */
	private function handle_consent( string $token, string $intent ): void {
		$row = null === $this->consent ? null : $this->service->managed_subscriber( $token );
		$id  = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
		if ( null === $this->consent || $id < 1 ) {
			$this->render_result( false, 400 );
			return;
		}
		if ( self::INTENT_CLICK_WITHDRAW === $intent ) {
			$this->consent->withdraw_subscriber( $id );
		} elseif ( $this->per_user_enabled() ) {
			$this->consent->grant_subscriber( $id );
		} else {
			$this->render_result( false, 400 );
			return;
		}

		$html  = '<h1>' . esc_html__( 'Subscription updated', 'argentwolf-post-notifier' );
		$html .= '</h1>';
		$html .= '<p>' . esc_html(
			self::INTENT_CLICK_WITHDRAW === $intent
				? __( 'Your clicks are no longer linked to you.', 'argentwolf-post-notifier' )
				: __( 'Your click-tracking consent has been recorded.', 'argentwolf-post-notifier' )
		) . '</p>';
		$html .= $this->management_form( $token, $row );
		$this->render_page( $html, 200 );
	}

	/**
	 * Click-consent grant/withdraw form, or an empty string.
	 *
	 * @param string              $token Current plaintext management token.
	 * @param array<string,mixed> $row   Subscriber row.
	 * @return string Escaped HTML.
	 */
	private function consent_form( string $token, array $row ): string {
		$id = (int) ( $row['id'] ?? 0 );
		if ( null === $this->consent || $id < 1 ) {
			return '';
		}
		$consented = null !== $this->consent->subscriber_record( $id );
		if ( ! $consented && ! $this->per_user_enabled() ) {
			return '';
		}
		$html  = '<h2>' . esc_html__( 'Click tracking', 'argentwolf-post-notifier' ) . '</h2>';
		$html .= '<p>' . esc_html(
			$consented
				? __(
					'You agreed that clicks on notification links are linked to your address.',
					'argentwolf-post-notifier'
				)
				: ClickConsent::wording()
		) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= $this->hidden_input( 'action', self::POST_ACTION );
		$html .= $this->hidden_input( self::TOKEN_FIELD, $token );
		$html .= $this->hidden_input(
			self::INTENT_FIELD,
			$consented ? self::INTENT_CLICK_WITHDRAW : self::INTENT_CLICK_CONSENT
		);
		$html .= $this->hidden_input( self::NONCE_FIELD, wp_create_nonce( self::NONCE_ACTION ) );
		$html .= '<p><button type="submit">' . esc_html(
			$consented
				? __( 'Withdraw click-tracking consent', 'argentwolf-post-notifier' )
				: __( 'I agree to click tracking', 'argentwolf-post-notifier' )
		) . '</button></p></form>';

		return $html;
	}

	/**
	 * Whether per-user click tracking is currently enabled.
	 *
	 * @return bool
	 */
	private function per_user_enabled(): bool {
		return null !== $this->privacy && $this->privacy->per_user_tracking_enabled();
	}

	/**
	 * Build the intent form for one current management bearer.
	 *
	 * @param string              $token Current plaintext management token.
	 * @param array<string,mixed> $row   Subscriber row.
	 * @return string Escaped HTML.
	 */
	private function management_form( string $token, array $row ): string {
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

		if ( null === $intent ) {
			return '<p>' . esc_html__(
				'This subscription cannot be changed from this link.',
				'argentwolf-post-notifier'
			) . '</p>' . $this->consent_form( $token, $row );
		}
		$html = '';

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
		$html  .= $this->consent_form( $token, $row );

		return $html;
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
			'h2'     => array(),
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
