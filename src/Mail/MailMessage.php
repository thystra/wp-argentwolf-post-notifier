<?php
/**
 * File: src/Mail/MailMessage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Mail;

use InvalidArgumentException;

/**
 * Immutable message submitted through a plugin mail transport.
 */
final class MailMessage {
	/**
	 * Construct a mail message.
	 *
	 * @param string        $recipient Recipient email address.
	 * @param string        $subject   Message subject.
	 * @param string        $body      Message body.
	 * @param array<string> $headers   Additional mail headers.
	 */
	public function __construct(
		private string $recipient,
		private string $subject,
		private string $body,
		private array $headers = array()
	) {
		$this->recipient = trim( $this->recipient );
		$this->subject   = trim( $this->subject );

		if ( '' === $this->recipient ) {
			throw new InvalidArgumentException( 'Mail recipient is required.' );
		}
		if ( '' === $this->subject ) {
			throw new InvalidArgumentException( 'Mail subject is required.' );
		}
		if ( '' === trim( $this->body ) ) {
			throw new InvalidArgumentException( 'Mail body is required.' );
		}
	}

	/**
	 * Recipient email address.
	 *
	 * @return string
	 */
	public function recipient(): string {
		return $this->recipient;
	}

	/**
	 * Message subject.
	 *
	 * @return string
	 */
	public function subject(): string {
		return $this->subject;
	}

	/**
	 * Message body.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Additional transport headers.
	 *
	 * @return array<string>
	 */
	public function headers(): array {
		return $this->headers;
	}
}

// EOF: src/Mail/MailMessage.php.
