<?php
/**
 * File: src/Tracking/ClickController.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Tracking;

use ArgentWolf\PostNotifier\Campaign\RecipientLinkFactory;
use ArgentWolf\PostNotifier\Campaign\RecipientToken;
use ArgentWolf\PostNotifier\Campaign\RecipientUnsubscribeController;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Privacy\PrivacySettings;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Click endpoint: record a tracked click and redirect to the post.
 *
 * The request carries only an opaque token. The destination is never read from
 * the request: it is always the current permalink of the campaign's post, or
 * the site home page when the token is unknown or the post is not public. This
 * makes open redirects impossible by construction.
 */
final class ClickController implements Registerable {

	/**
	 * Construct the controller.
	 *
	 * @param ClickRepository $clicks  Click persistence.
	 * @param PrivacySettings $privacy Tracking settings.
	 */
	public function __construct(
		private ClickRepository $clicks,
		private PrivacySettings $privacy
	) {
	}

	/**
	 * Register the logged-in and logged-out admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . RecipientLinkFactory::CLICK_ACTION, array( $this, 'handle' ) );
		add_action(
			'admin_post_nopriv_' . RecipientLinkFactory::CLICK_ACTION,
			array( $this, 'handle' )
		);
	}

	/**
	 * Handle one click request.
	 *
	 * @return never
	 */
	public function handle(): never {
		// The opaque bearer token is the only input; clicks carry no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) && is_string( $_GET['token'] )
			? sanitize_text_field( wp_unslash( $_GET['token'] ) )
			: '';
		// phpcs:enable

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer', true );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		wp_safe_redirect( $this->resolve( $token ), 302, 'ArgentWolf Post Notifier' );
		exit;
	}

	/**
	 * Record a click when allowed and return the safe destination.
	 *
	 * @param string                 $token Plaintext click token.
	 * @param DateTimeImmutable|null $now   Optional fixed clock (tests).
	 * @return string Local destination URL.
	 */
	public function resolve( string $token, ?DateTimeImmutable $now = null ): string {
		$home = home_url( '/' );
		$hash = RecipientToken::hash_plaintext( $token );
		if ( null === $hash ) {
			return $home;
		}
		$row = $this->clicks->find_by_click_hash( $hash );
		if ( null === $row ) {
			return $home;
		}

		$now ??= new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		if (
			$this->privacy->click_tracking_enabled()
			&& ClickRepository::is_clickable_status( $row['status'] )
			&& $this->within_ttl( $row['submitted_at_gmt'], $now )
		) {
			try {
				$this->clicks->record( $row['id'], $row['campaign_id'], $now );
			} catch ( Throwable ) {
				// Counting is best effort; the reader still reaches the post.
				return $this->destination( $row['post_id'], $home );
			}
		}

		return $this->destination( $row['post_id'], $home );
	}

	/**
	 * Current public permalink of the post, or the home page.
	 *
	 * @param int    $post_id Campaign post ID.
	 * @param string $home    Fallback URL.
	 * @return string
	 */
	private function destination( int $post_id, string $home ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return $home;
		}
		$permalink = get_permalink( $post );

		return is_string( $permalink ) && '' !== $permalink ? $permalink : $home;
	}

	/**
	 * Whether the message was submitted within the recipient-token lifetime.
	 *
	 * @param string            $submitted Submission time, UTC.
	 * @param DateTimeImmutable $now       Current time.
	 * @return bool
	 */
	private function within_ttl( string $submitted, DateTimeImmutable $now ): bool {
		$time = '' === $submitted ? false : strtotime( $submitted . ' UTC' );
		/** This filter is documented in src/Campaign/RecipientUnsubscribeController.php */
		$ttl = (int) apply_filters(
			'argentwolf_post_notifier_recipient_token_ttl_seconds',
			RecipientUnsubscribeController::TOKEN_TTL_SECONDS
		);

		return false !== $time && $ttl > 0 && $now->getTimestamp() - $time <= $ttl;
	}
}

// EOF: src/Tracking/ClickController.php.
