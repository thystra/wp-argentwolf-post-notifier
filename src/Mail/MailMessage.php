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
	 * @param string|null   $alt_body  Optional plain-text alternative for HTML bodies.
	 * @throws InvalidArgumentException When recipient, subject, or body is empty.
	 */
	public function __construct(
		private string $recipient,
		private string $subject,
		private string $body,
		private array $headers = array(),
		private ?string $alt_body = null
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
		if ( null !== $this->alt_body && '' === trim( $this->alt_body ) ) {
			throw new InvalidArgumentException( 'Mail plain-text alternative must not be empty.' );
		}
		if ( 1 === preg_match( '/[\r\n,;]/', $this->recipient ) ) {
			// One message has exactly one primary recipient; never a shared list.
			throw new InvalidArgumentException( 'Mail recipient must be a single address.' );
		}
		foreach ( $this->headers as $header ) {
			if ( ! is_string( $header ) || 1 === preg_match( '/[\r\n]/', $header ) ) {
				throw new InvalidArgumentException( 'Mail headers must be single-line strings.' );
			}
			if ( 1 === preg_match( '/\A\s*(to|cc|bcc)\s*:/i', $header ) ) {
				throw new InvalidArgumentException( 'Shared recipient headers are not permitted.' );
			}
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

	/**
	 * Optional plain-text alternative body.
	 *
	 * @return string|null
	 */
	public function alt_body(): ?string {
		return $this->alt_body;
	}
}

// EOF: src/Mail/MailMessage.php.
