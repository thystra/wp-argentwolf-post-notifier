<?php
/**
 * File: src/Mail/MailTransport.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Mail;

/**
 * Transport-neutral message submission contract.
 */
interface MailTransport {
	/**
	 * Submit one message.
	 *
	 * @param MailMessage $message Message to submit.
	 * @return DeliveryResult
	 */
	public function send( MailMessage $message ): DeliveryResult;
}

// EOF: src/Mail/MailTransport.php.
