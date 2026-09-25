<?php
/**
 * File: src/Subscriber/PublicSignupProcessor.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use InvalidArgumentException;

/**
 * Privacy-preserving public standalone-subscriber signup coordinator.
 *
 * This class contains the public-flow policy but does not register an HTTP
 * endpoint. The later block/controller tranche will translate requests into
 * PublicSignupRequest values and render the one generic response.
 */
final class PublicSignupProcessor {
	/**
	 * Construct the processor.
	 *
	 * @param SubscriberService       $service      Subscriber domain service.
	 * @param SignupRateLimiter       $rate_limiter Local public-request limiter.
	 * @param ConfirmationLinkFactory $links        Local confirmation-link factory.
	 * @param ConfirmationMailer      $mailer       Confirmation-message sender.
	 */
	public function __construct(
		private SubscriberService $service,
		private SignupRateLimiter $rate_limiter,
		private ConfirmationLinkFactory $links,
		private ConfirmationMailer $mailer
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

		$this->mailer->send(
			$result->normalized_email(),
			$this->links->create( $token )
		);

		return $response;
	}
}

// EOF: src/Subscriber/PublicSignupProcessor.php.
