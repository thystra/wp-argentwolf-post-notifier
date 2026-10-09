<?php
/**
 * File: src/Content/EmailTemplateComposer.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use InvalidArgumentException;
use LogicException;

/**
 * Compose inert notification messages from the canonical fragment renderer.
 *
 * This service only creates strings. It neither resolves an audience nor
 * persists campaigns, generates links, or transmits email. Templates are fixed
 * here until separately validated administrator template settings are added.
 */
final class EmailTemplateComposer {
	/**
	 * Only these placeholders may appear in the fixed message body templates.
	 *
	 * @var array<int,string>
	 */
	public const BODY_TOKENS = array( 'site_name', 'post_title', 'post_url', 'content' );

	/**
	 * Use one canonical renderer for the HTML and plain-text representations.
	 *
	 * @param EmailContentRenderer $renderer Canonical inert fragment renderer.
	 */
	public function __construct( private EmailContentRenderer $renderer ) {
	}

	/**
	 * Render a ready-to-preview pair without performing any side effects.
	 *
	 * All links are supplied by a trusted caller and must point to this site.
	 * The unsubscribe and management footer is mandatory, not a user template.
	 *
	 * @param string $post_content    Original saved post content.
	 * @param string $post_title      Post title, never interpreted as markup.
	 * @param string $post_url        Public post permalink on this site.
	 * @param string $unsubscribe_url Existing local unsubscribe URL for preview.
	 * @param string $manage_url      Existing local management URL for preview.
	 * @param string $manual_excerpt  Saved manual excerpt.
	 * @param string $content_mode    Selection mode.
	 * @param bool   $more_enabled    Whether to honor the More marker.
	 * @param int    $excerpt_words   Generated excerpt word count.
	 * @return array{source:string,subject:string,html:string,text:string}
	 */
	public function compose(
		string $post_content,
		string $post_title,
		string $post_url,
		string $unsubscribe_url,
		string $manage_url,
		string $manual_excerpt = '',
		string $content_mode = 'site_default',
		bool $more_enabled = true,
		int $excerpt_words = EmailContentSelector::DEFAULT_EXCERPT_WORDS
	): array {
		// Reject malformed or off-site URLs before evaluating any post content.
		$post_url        = $this->require_site_url( $post_url );
		$unsubscribe_url = $this->require_site_url( $unsubscribe_url );
		$manage_url      = $this->require_site_url( $manage_url );

		$fragments = $this->renderer->render(
			$post_content,
			$manual_excerpt,
			$content_mode,
			$more_enabled,
			$excerpt_words
		);
		$title     = sanitize_text_field( wp_strip_all_tags( $post_title ) );
		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );

		$html_values = array(
			'site_name'  => esc_html( $site_name ),
			'post_title' => esc_html( $title ),
			'post_url'   => esc_url( $post_url ),
			'content'    => $fragments['html'],
		);
		$text_values = array(
			'site_name'  => $site_name,
			'post_title' => $title,
			'post_url'   => $post_url,
			'content'    => $fragments['text'],
		);

		// The footer is outside both templates and cannot be removed by them.
		$html  = $this->replace_body_tokens( $this->html_body_template(), $html_values );
		$html .= $this->mandatory_html_footer( $unsubscribe_url, $manage_url );
		$text  = $this->replace_body_tokens( $this->text_body_template(), $text_values );
		$text .= $this->mandatory_text_footer( $unsubscribe_url, $manage_url );

		return array(
			'source'  => $fragments['source'],
			'subject' => sprintf(
				/* translators: %s: Title of the newly published post. */
				__( 'New post: %s', 'argentwolf-post-notifier' ),
				$title
			),
			'html'    => $html,
			'text'    => $text,
		);
	}

	/**
	 * Accept only absolute HTTP(S) URLs on the current WordPress origin.
	 *
	 * @param string $url Caller-supplied link.
	 * @return string
	 * @throws InvalidArgumentException If the URL is not on the current site.
	 */
	private function require_site_url( string $url ): string {
		if ( '' === $url || preg_match( '/[\x00-\x20\\\\]/', $url ) ) {
			throw new InvalidArgumentException( 'A valid local notification URL is required.' );
		}
		$url_parts  = wp_parse_url( $url );
		$site_parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $url_parts ) || ! is_array( $site_parts ) ) {
			throw new InvalidArgumentException( 'A valid local notification URL is required.' );
		}
		$scheme = strtolower( $url_parts['scheme'] ?? '' );
		$host   = strtolower( $url_parts['host'] ?? '' );
		if (
			! in_array( $scheme, array( 'http', 'https' ), true ) ||
			strtolower( $site_parts['scheme'] ?? '' ) !== $scheme ||
			'' === $host ||
			strtolower( $site_parts['host'] ?? '' ) !== $host ||
			( $url_parts['port'] ?? null ) !== ( $site_parts['port'] ?? null ) ||
			isset( $url_parts['user'] ) ||
			isset( $url_parts['pass'] )
		) {
			throw new InvalidArgumentException( 'A valid local notification URL is required.' );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Responsive-width default email body; no images or remote assets.
	 *
	 * @return string
	 */
	private function html_body_template(): string {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
			. '<tr><td align="center" style="padding:20px">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
			. 'width="100%" style="max-width:640px">'
			. '<tr><td><p>{{site_name}}</p><h2>{{post_title}}</h2>'
			. '<div>{{content}}</div><p><a href="{{post_url}}">'
			. esc_html__( 'Read the post', 'argentwolf-post-notifier' )
			. '</a></p></td></tr></table></td></tr></table>';
	}

	/**
	 * Plain-text body corresponding to the default HTML content.
	 *
	 * @return string
	 */
	private function text_body_template(): string {
		return "{{site_name}}\n{{post_title}}\n\n{{content}}\n\n{{post_url}}\n";
	}

	/**
	 * Replace only the fixed allow-listed token names.
	 *
	 * @param string               $template Trusted built-in template.
	 * @param array<string,string> $values   Escaped, context-appropriate values.
	 * @return string
	 * @throws LogicException If the fixed template uses an unsupported token.
	 */
	private function replace_body_tokens( string $template, array $values ): string {
		preg_match_all( '/{{([a-z_]+)}}/', $template, $matches );
		foreach ( $matches[1] as $token ) {
			if ( ! in_array( $token, self::BODY_TOKENS, true ) || ! isset( $values[ $token ] ) ) {
				throw new LogicException( 'Invalid built-in email template token.' );
			}
		}
		$replacements = array();
		foreach ( self::BODY_TOKENS as $token ) {
			$replacements[ '{{' . $token . '}}' ] = $values[ $token ];
		}
		return strtr( $template, $replacements );
	}

	/**
	 * Append immutable clickable subscriber controls to the HTML body.
	 *
	 * @param string $unsubscribe_url Verified local unsubscribe link.
	 * @param string $manage_url      Verified local management link.
	 * @return string
	 */
	private function mandatory_html_footer( string $unsubscribe_url, string $manage_url ): string {
		return '<p style="text-align:center;font-size:12px">'
			. '<a href="' . esc_url( $unsubscribe_url ) . '">'
			. esc_html__( 'Unsubscribe', 'argentwolf-post-notifier' ) . '</a> | '
			. '<a href="' . esc_url( $manage_url ) . '">'
			. esc_html__( 'Manage notifications', 'argentwolf-post-notifier' )
			. '</a></p>';
	}

	/**
	 * Append matching subscriber controls to the plain-text body.
	 *
	 * @param string $unsubscribe_url Verified local unsubscribe link.
	 * @param string $manage_url      Verified local management link.
	 * @return string
	 */
	private function mandatory_text_footer( string $unsubscribe_url, string $manage_url ): string {
		return "\n" . __( 'Unsubscribe', 'argentwolf-post-notifier' ) . ': '
			. $unsubscribe_url . "\n"
			. __( 'Manage notifications', 'argentwolf-post-notifier' ) . ': '
			. $manage_url . "\n";
	}
}

// EOF: src/Content/EmailTemplateComposer.php.
