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
	 * Management-link factory.
	 *
	 * @var ManageSubscriptionLinkFactory
	 */
	private ManageSubscriptionLinkFactory $manage_links;

	/**
	 * Construct the confirmation controller.
	 *
	 * @param SubscriberService                  $service      Subscriber domain service.
	 * @param ManageSubscriptionLinkFactory|null $manage_links Management links.
	 */
	public function __construct(
		private SubscriberService $service,
		?ManageSubscriptionLinkFactory $manage_links = null
	) {
		$this->manage_links = $manage_links ?? new WordPressManageSubscriptionLinkFactory();
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
			$this->render_result_page( null, 405 );
			return;
		}

		// Display-only GET: reading the bearer token here does not change state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = $this->request_value( $_GET, 'token' );
		if ( null === ConfirmationToken::hash_plaintext( $token ) ) {
			$this->render_result_page( null, 400 );
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$message = sprintf(
			/* translators: %s: site name. */
			esc_html__(
				'Confirm that you want to receive post notifications from %s.',
				'argentwolf-post-notifier'
			),
			esc_html( $site_name )
		);
		$html  = '<h1>';
		$html .= esc_html__( 'Confirm your subscription', 'argentwolf-post-notifier' );
		$html .= '</h1>';
		$html .= '<p>' . $message . '</p>';
		$html .= '<form method="post" action="' . esc_url( $action_url ) . '">';
		$html .= sprintf(
			'<input type="hidden" name="action" value="%s">',
			esc_attr( self::POST_ACTION )
		);
		$html .= sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( self::TOKEN_FIELD ),
			esc_attr( $token )
		);
		$html .= sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( self::NONCE_FIELD ),
			esc_attr( $nonce )
		);
		$html .= '<p><button type="submit">';
		$html .= esc_html__( 'Confirm subscription', 'argentwolf-post-notifier' );
		$html .= '</button></p>';
		$html .= '</form>';
		$html .= '<p>';
		$html .= esc_html__(
			'Nothing changes until you choose the confirmation button.',
			'argentwolf-post-notifier'
		);
		$html .= '</p>';

		$this->render_page( $html, 200 );
	}

	/**
	 * Process an intentional confirmation POST.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		if ( 'POST' !== $this->request_method() ) {
			$this->render_result_page( null, 405 );
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$token = $this->request_value( $_POST, self::TOKEN_FIELD );
		if ( null === ConfirmationToken::hash_plaintext( $token ) ) {
			$this->render_result_page( null, 400 );
			return;
		}

		$manage_token = $this->service->confirm_with_management( $token );
		$this->render_result_page( $manage_token, 200 );
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
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		return strtoupper( $method );
	}

	/**
	 * Render the final confirmation result page.
	 *
	 * @param string|null $manage_token Plaintext management token on success.
	 * @param int         $response     HTTP response code.
	 * @return void
	 */
	private function render_result_page(
		?string $manage_token,
		int $response
	): void {
		if ( null !== $manage_token ) {
			$manage_url = $this->manage_links->create( $manage_token );
			$html       = '<h1>';
			$html      .= esc_html__(
				'Subscription confirmed',
				'argentwolf-post-notifier'
			);
			$html      .= '</h1>';
			$html      .= '<p>';
			$html      .= esc_html__(
				'You are now subscribed to post notifications.',
				'argentwolf-post-notifier'
			);
			$html      .= '</p>';
			$html      .= '<p><a href="' . esc_url( $manage_url ) . '">';
			$html      .= esc_html__(
				'Manage this subscription',
				'argentwolf-post-notifier'
			);
			$html      .= '</a></p>';
		} else {
			$html  = '<h1>';
			$html .= esc_html__(
				'Unable to confirm subscription',
				'argentwolf-post-notifier'
			);
			$html .= '</h1>';
			$html .= '<p>';
			$html .= esc_html__(
				'This confirmation link is invalid, expired, or already used. ',
				'argentwolf-post-notifier'
			);
			$html .= esc_html__(
				'Request a new confirmation message from the site.',
				'argentwolf-post-notifier'
			);
			$html .= '</p>';
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
			wp_kses( $html, $this->allowed_page_html() ),
			esc_html__( 'Subscription confirmation', 'argentwolf-post-notifier' ),
			array(
				'response'  => absint( $response ),
				'back_link' => false,
			)
		);
	}

	/**
	 * Return the HTML elements allowed on the standalone confirmation page.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function allowed_page_html(): array {
		return array(
			'h1'     => array(),
			'p'      => array(),
			'a'      => array(
				'href' => true,
			),
			'form'   => array(
				'method' => true,
				'action' => true,
			),
			'input'  => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
			),
			'button' => array(
				'type' => true,
			),
		);
	}
}

// EOF: src/Subscriber/ConfirmationController.php.
