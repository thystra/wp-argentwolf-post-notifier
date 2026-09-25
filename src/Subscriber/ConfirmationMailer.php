<?php
/**
 * File: src/Subscriber/ConfirmationMailer.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;

/**
 * Composes and submits standalone-subscriber confirmation messages.
 */
final class ConfirmationMailer {
	/**
	 * Construct the confirmation mailer.
	 *
	 * @param MailTransport $transport Mail transport.
	 * @param string        $site_name Public site name used in the message.
	 */
	public function __construct(
		private MailTransport $transport,
		private string $site_name
	) {
	}

	/**
	 * Submit one confirmation message.
	 *
	 * @param string $email            Normalized recipient email.
	 * @param string $confirmation_url Local confirmation URL.
	 * @return DeliveryResult
	 */
	public function send( string $email, string $confirmation_url ): DeliveryResult {
		$site_name = '' === trim( $this->site_name )
			? __( 'this site', 'argentwolf-post-notifier' )
			: $this->site_name;
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( 'Confirm your subscription to %s', 'argentwolf-post-notifier' ),
			$site_name
		);
		$body = sprintf(
			/* translators: 1: site name, 2: local confirmation URL. */
			__(
				'You requested email notifications from %1$s.

Open this link and confirm the subscription on the site:
%2$s

If you did not request this, you can ignore this message.',
				'argentwolf-post-notifier'
			),
			$site_name,
			$confirmation_url
		);

		return $this->transport->send(
			new MailMessage(
				$email,
				$subject,
				$body,
				array( 'Content-Type: text/plain; charset=UTF-8' )
			)
		);
	}
}

// EOF: src/Subscriber/ConfirmationMailer.php.
