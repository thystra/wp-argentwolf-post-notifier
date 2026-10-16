<?php
/**
 * File: src/Subscriber/PublicSignupProcessor.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Privacy\ClickConsent;
use ArgentWolf\PostNotifier\Privacy\PrivacySettings;
use InvalidArgumentException;
use RuntimeException;

/**
 * Privacy-preserving public standalone-subscriber signup coordinator.
 *
 * This class contains the public-flow policy but does not register an HTTP
 * endpoint. Public controllers translate requests into PublicSignupRequest
 * values and render the one generic response.
 */
final class PublicSignupProcessor {
	/**
	 * Construct the processor.
	 *
	 * @param SubscriberService       $service      Subscriber domain service.
	 * @param SignupRateLimiter       $rate_limiter Local public-request limiter.
	 * @param ConfirmationLinkFactory $links        Local confirmation-link factory.
	 * @param ConfirmationMailer      $mailer       Confirmation-message sender.
	 * @param ClickConsent|null       $consent      Per-user click consent store.
	 * @param PrivacySettings|null    $privacy      Tracking settings.
	 */
	public function __construct(
		private SubscriberService $service,
		private SignupRateLimiter $rate_limiter,
		private ConfirmationLinkFactory $links,
		private ConfirmationMailer $mailer,
		private ?ClickConsent $consent = null,
		private ?PrivacySettings $privacy = null
	) {
	}

	/**
	 * Process one untrusted signup request without exposing internal state.
	 *
	 * Invalid input, bot honeypot hits, rate limiting, existing non-pending
	 * records, resend cooldown, and transport submission results all map to the
	 * same public response code.
	 *
	 * @param PublicSignupRequest $request Public request values.
	 * @return PublicSignupResponse
	 */
	public function process( PublicSignupRequest $request ): PublicSignupResponse {
		$response = new PublicSignupResponse();

		if ( '' !== trim( $request->honeypot() ) ) {
			return $response;
		}

		if (
			! $this->rate_limiter->allow(
				$request->email(),
				$request->remote_address()
			)
		) {
			return $response;
		}

		try {
			$result = $this->service->request_confirmation(
				$request->email(),
				$request->display_name(),
				$request->consent_text(),
				$request->signup_source(),
				$request->source_post_id()
			);
		} catch ( InvalidArgumentException ) {
			return $response;
		}

		$token = $result->confirmation_token();
		if ( null === $token ) {
			return $response;
		}

		$this->record_click_consent( $result->subscriber_id(), $request->click_consent() );

		$this->mailer->send(
			$result->normalized_email(),
			$this->links->create( $token )
		);

		return $response;
	}

	/**
	 * Record or clear click consent, only when a confirmation was issued.
	 *
	 * A fresh confirmation request without the box ticked clears any earlier
	 * consent on the pending row, so consent always matches the last form.
	 *
	 * @param int  $subscriber_id Pending subscriber ID.
	 * @param bool $ticked        Whether the optional box was ticked.
	 * @return void
	 */
	private function record_click_consent( int $subscriber_id, bool $ticked ): void {
		if ( null === $this->consent || $subscriber_id < 1 ) {
			return;
		}
		try {
			$enabled = null !== $this->privacy && $this->privacy->per_user_tracking_enabled();
			if ( $ticked && $enabled ) {
				$this->consent->grant_subscriber( $subscriber_id );
			} elseif ( null !== $this->consent->subscriber_record( $subscriber_id ) ) {
				$this->consent->withdraw_subscriber( $subscriber_id );
			}
		} catch ( RuntimeException ) {
			// Consent is optional; failing to record it must not block signup.
			return;
		}
	}
}

// EOF: src/Subscriber/PublicSignupProcessor.php.
