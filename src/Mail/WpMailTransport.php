<?php
/**
 * File: src/Mail/WpMailTransport.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Mail;

/**
 * WordPress wp_mail() transport.
 */
final class WpMailTransport implements MailTransport {
	/**
	 * Submit one message through WordPress.
	 *
	 * A true wp_mail() result means WordPress accepted the request for
	 * processing. It does not prove delivery to an inbox.
	 *
	 * @param MailMessage $message Message to submit.
	 * @return DeliveryResult
	 */
	public function send( MailMessage $message ): DeliveryResult {
		$submitted = wp_mail(
			$message->recipient(),
			$message->subject(),
			$message->body(),
			$message->headers()
		);

		return $submitted
			? DeliveryResult::submitted()
			: DeliveryResult::failed();
	}
}

// EOF: src/Mail/WpMailTransport.php.
