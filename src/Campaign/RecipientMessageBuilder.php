<?php
/**
 * File: src/Campaign/RecipientMessageBuilder.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use InvalidArgumentException;

/**
 * Turn one frozen recipient-neutral campaign body into one deliverable message.
 *
 * The frozen campaign columns are read, never written. The mandatory
 * subscriber-control footer is appended with recipient-specific local URLs,
 * and standard one-click unsubscribe headers are attached. Exactly one
 * primary recipient is addressed; there is no shared To/CC/BCC path.
 */
final class RecipientMessageBuilder {
	/**
	 * Construct the builder.
	 *
	 * @param EmailTemplateComposer $composer Canonical composer providing the footer.
	 */
	public function __construct( private EmailTemplateComposer $composer ) {
	}

	/**
	 * Build one message.
	 *
	 * Links are array{unsubscribe:string,manage:string,one_click:string} plus an
	 * optional click URL. When the click URL is present, every link to the frozen
	 * post URL (campaign key post_url) is replaced by the opaque click endpoint.
	 * Other links in the content are never rewritten, so the click endpoint can
	 * only ever lead to the server-chosen post destination.
	 *
	 * @param array<string,mixed>  $campaign       Campaign row with frozen bodies.
	 * @param string               $email          Snapshot recipient address.
	 * @param string               $recipient_uuid Recipient correlation UUID.
	 * @param array<string,string> $links          Recipient links.
	 * @return MailMessage
	 * @throws InvalidArgumentException When frozen content or links are unusable.
	 */
	public function build(
		array $campaign,
		string $email,
		string $recipient_uuid,
		array $links
	): MailMessage {
		$subject = (string) ( $campaign['subject'] ?? '' );
		$html    = (string) ( $campaign['html_body'] ?? '' );
		$text    = (string) ( $campaign['text_body'] ?? '' );
		$uuid    = (string) ( $campaign['uuid'] ?? '' );
		if ( '' === trim( $subject ) || '' === trim( $html ) || '' === trim( $text ) ) {
			throw new InvalidArgumentException( 'Campaign content has not been frozen.' );
		}
		if ( 1 === preg_match( '/[\r\n]/', $subject ) ) {
			throw new InvalidArgumentException( 'Campaign subject is invalid.' );
		}
		foreach ( array( $uuid, $recipient_uuid ) as $id ) {
			if ( 1 !== preg_match( '/\A[a-f0-9-]{36}\z/D', $id ) ) {
				throw new InvalidArgumentException( 'Correlation identifier is invalid.' );
			}
		}
		if ( ! is_email( $email ) ) {
			throw new InvalidArgumentException( 'Recipient address is invalid.' );
		}

		if ( isset( $links['click'] ) ) {
			list( $html, $text ) = $this->track_post_links(
				$html,
				$text,
				(string) ( $campaign['post_url'] ?? '' ),
				$links['click']
			);
		}

		$footer = $this->composer->recipient_footer( $links['unsubscribe'], $links['manage'] );
		if ( ! $this->is_local_https_or_http( $links['one_click'] ) ) {
			throw new InvalidArgumentException( 'One-click unsubscribe URL must be local.' );
		}

		return new MailMessage(
			$email,
			$subject,
			$html . $footer['html'],
			array(
				'Content-Type: text/html; charset=UTF-8',
				'List-Unsubscribe: <' . esc_url_raw( $links['one_click'] ) . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
				'X-ArgentWolf-PN-Campaign: ' . $uuid,
				'X-ArgentWolf-PN-Recipient: ' . $recipient_uuid,
			),
			$text . $footer['text']
		);
	}

	/**
	 * Replace frozen post-URL links with the click endpoint.
	 *
	 * @param string $html     Frozen HTML body.
	 * @param string $text     Frozen text body.
	 * @param string $post_url Frozen post URL captured with the campaign.
	 * @param string $click    Local click endpoint URL.
	 * @return array{0:string,1:string}
	 * @throws InvalidArgumentException When the click URL is not local.
	 */
	private function track_post_links(
		string $html,
		string $text,
		string $post_url,
		string $click
	): array {
		if ( ! $this->is_local_https_or_http( $click ) ) {
			throw new InvalidArgumentException( 'Click URL must be local.' );
		}
		if ( '' === $post_url || ! $this->is_local_https_or_http( $post_url ) ) {
			return array( $html, $text );
		}

		$html = str_replace(
			'href="' . esc_url( $post_url ) . '"',
			'href="' . esc_url( $click ) . '"',
			$html
		);
		$text = (string) preg_replace(
			'/(?<=\A|\s)' . preg_quote( $post_url, '/' ) . '(?=\s|\z)/u',
			str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), esc_url_raw( $click ) ),
			$text
		);

		return array( $html, $text );
	}

	/**
	 * Require an absolute same-origin URL for the List-Unsubscribe header.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_local_https_or_http( string $url ): bool {
		if ( '' === $url || 1 === preg_match( '/[\x00-\x20<>\\\\]/', $url ) ) {
			return false;
		}
		$url_parts  = wp_parse_url( $url );
		$site_parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $url_parts ) || ! is_array( $site_parts ) ) {
			return false;
		}

		$scheme = strtolower( $url_parts['scheme'] ?? '' );

		return in_array( $scheme, array( 'http', 'https' ), true )
			&& strtolower( $site_parts['scheme'] ?? '' ) === $scheme
			&& strtolower( $url_parts['host'] ?? '' ) === strtolower( $site_parts['host'] ?? '' )
			&& ( $url_parts['port'] ?? null ) === ( $site_parts['port'] ?? null )
			&& ! isset( $url_parts['user'] )
			&& ! isset( $url_parts['pass'] );
	}
}

// EOF: src/Campaign/RecipientMessageBuilder.php.
