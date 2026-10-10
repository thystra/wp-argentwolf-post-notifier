<?php
/**
 * File: src/Content/EmailTemplateTestMailer.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use InvalidArgumentException;

/**
 * Submit an explicitly labeled test of the canonical rendered message.
 *
 * The only destination is the signed-in administrator's own account address.
 * This service does not look up subscribers or create campaign records.
 */
final class EmailTemplateTestMailer {
	/** Minimum interval between accepted test submissions for one user. */
	private const COOLDOWN_SECONDS = 60;

	/**
	 * Construct the opt-in administrator test sender.
	 *
	 * @param EmailTemplatePreview $preview   Canonical, read-only preview service.
	 * @param MailTransport        $transport Existing WordPress mail transport.
	 */
	public function __construct(
		private EmailTemplatePreview $preview,
		private MailTransport $transport
	) {
	}

	/**
	 * Send a test of saved or unsaved fields to the current administrator only.
	 *
	 * WordPress's mailer hook supplies the plain-text alternative to the HTML
	 * message. The hook is removed even on transport failures or exceptions.
	 *
	 * @param int        $post_id           Published post to use in the test.
	 * @param array|null $template_override Unsaved template fields, if supplied.
	 * @return DeliveryResult Whether the transport accepted the test message.
	 * @throws InvalidArgumentException When access, input or cooldown is invalid.
	 */
	public function send( int $post_id, ?array $template_override = null ): DeliveryResult {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new InvalidArgumentException( 'Test-email access is not permitted.' );
		}

		$user  = wp_get_current_user();
		$email = $user->user_email;
		if ( ! is_string( $email ) || ! is_email( $email ) ) {
			throw new InvalidArgumentException( 'An administrator account email is required.' );
		}

		$cooldown_key = 'awpn_test_email_' . get_current_user_id();
		if ( false !== get_transient( $cooldown_key ) ) {
			throw new InvalidArgumentException( 'A recent test email was already submitted.' );
		}

		$message = $this->preview->preview( $post_id, $template_override );
		$label   = __(
			'TEST EMAIL — subscriber links are examples only.',
			'argentwolf-post-notifier'
		);
		$html    = '<p><strong>' . esc_html( $label ) . '</strong></p>' . $message['html'];
		$text    = $label . "\n\n" . $message['text'];
		$subject = '[TEST] ' . $message['subject'];

		$add_alternative = static function ( $mailer ) use ( $text ): void {
			// PHPMailer uses the public camel-cased AltBody property for text alternatives.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->AltBody = $text;
		};
		add_action( 'phpmailer_init', $add_alternative );
		try {
			$result = $this->transport->send(
				new MailMessage(
					$email,
					$subject,
					$html,
					array( 'Content-Type: text/html; charset=UTF-8' )
				)
			);
		} finally {
			remove_action( 'phpmailer_init', $add_alternative );
		}

		if ( $result->is_submitted() ) {
			set_transient( $cooldown_key, 1, self::COOLDOWN_SECONDS );
		}
		return $result;
	}
}

// EOF: src/Content/EmailTemplateTestMailer.php.
