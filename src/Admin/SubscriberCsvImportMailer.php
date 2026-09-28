<?php
/**
 * File: src/Admin/SubscriberCsvImportMailer.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;

/**
 * Compose confirmation invitations for administrator CSV intake.
 */
final class SubscriberCsvImportMailer {
	/**
	 * Construct the CSV-intake confirmation mailer.
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
	 * Submit one CSV-intake double-opt-in invitation.
	 *
	 * @param string $email            Normalized recipient email.
	 * @param string $confirmation_url Local intentional-confirmation URL.
	 * @return DeliveryResult
	 */
	public function send( string $email, string $confirmation_url ): DeliveryResult {
		$site_name = '' === trim( $this->site_name )
			? __( 'this site', 'argentwolf-post-notifier' )
			: $this->site_name;
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( 'Invitation to receive post notifications from %s', 'argentwolf-post-notifier' ),
			$site_name
		);
		$body = sprintf(
			/* translators: %s: site name. */
			__(
				'An administrator for %s invited this email address to receive notifications.',
				'argentwolf-post-notifier'
			),
			$site_name
		);
		$body .= "\n\n";
		$body .= __(
			'You are not subscribed unless you choose to confirm at this link:',
			'argentwolf-post-notifier'
		);
		$body .= "\n" . $confirmation_url . "\n\n";
		$body .= __(
			'If you do not want these notifications, ignore this message.',
			'argentwolf-post-notifier'
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

// EOF: src/Admin/SubscriberCsvImportMailer.php.
