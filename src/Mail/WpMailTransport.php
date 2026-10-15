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
		$alternative = $message->alt_body();
		$attach      = null;
		if ( null !== $alternative ) {
			$attach = static function ( $mailer ) use ( $alternative ): void {
				if ( is_object( $mailer ) ) {
					// PHPMailer uses the public camel-cased AltBody property for text alternatives.
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$mailer->AltBody = $alternative;
				}
			};
			add_action( 'phpmailer_init', $attach );
		}

		try {
			$submitted = wp_mail(
				$message->recipient(),
				$message->subject(),
				$message->body(),
				$message->headers()
			);
		} finally {
			if ( null !== $attach ) {
				remove_action( 'phpmailer_init', $attach );
			}
		}

		return $submitted
			? DeliveryResult::submitted()
			: DeliveryResult::failed();
	}
}

// EOF: src/Mail/WpMailTransport.php.
