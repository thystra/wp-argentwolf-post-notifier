<?php
/**
 * File: src/Content/EmailContentRenderer.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use WP_HTML_Tag_Processor;

/**
 * Compose safe HTML and plain-text fragments from a single content selection.
 *
 * Rendering is deliberately inert: no render_block(), do_shortcode(), embeds,
 * theme filters, remote images, or block callbacks. These fragments are not
 * complete email messages; templates and mandatory links are added later.
 */
final class EmailContentRenderer {
	/**
	 * Create the renderer with the canonical selector.
	 *
	 * @param EmailContentSelector $selector Canonical content source selector.
	 */
	public function __construct( private EmailContentSelector $selector ) {
	}

	/**
	 * Select and safely render source content in both output formats.
	 *
	 * @param string $post_content   Original post content.
	 * @param string $manual_excerpt Original post excerpt.
	 * @param string $content_mode   Site default, excerpt, or full.
	 * @param bool   $more_enabled   Whether to honor core More markers.
	 * @param int    $excerpt_words  Requested generated excerpt length.
	 * @return array{source:string,html:string,text:string}
	 */
	public function render(
		string $post_content,
		string $manual_excerpt = '',
		string $content_mode = 'site_default',
		bool $more_enabled = true,
		int $excerpt_words = EmailContentSelector::DEFAULT_EXCERPT_WORDS
	): array {
		$selection = $this->selector->select(
			$post_content,
			$manual_excerpt,
			$content_mode,
			$more_enabled,
			$excerpt_words
		);

		if ( 'text' === $selection['format'] ) {
			$text = $this->normalize_text( $selection['content'] );
			return array(
				'source' => $selection['source'],
				'html'   => '' === $text ? '' : '<p>' . esc_html( $text ) . '</p>',
				'text'   => $text,
			);
		}

		$raw_html = $this->render_blocks( parse_blocks( $selection['content'] ) );
		$html     = $this->sanitize_html( $raw_html );

		return array(
			'source' => $selection['source'],
			'html'   => $html,
			'text'   => $this->html_to_text( $html ),
		);
	}

	/**
	 * Render only known-static block markup, preserving safe nested structure.
	 *
	 * @param array<int,array> $blocks Parsed WordPress blocks.
	 * @return string
	 */
	private function render_blocks( array $blocks ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				// Classic content has no block callback and is sanitized below.
				$html .= $block['innerHTML'] ?? '';
				continue;
			}

			// Allow only static core presentation blocks; discard every other block.
			if ( ! in_array( $name, $this->static_blocks(), true ) ) {
				continue;
			}

			if ( in_array( $name, $this->transparent_blocks(), true ) ) {
				$html .= $this->render_blocks( $block['innerBlocks'] ?? array() );
				continue;
			}

			$children    = $block['innerBlocks'] ?? array();
			$child_index = 0;
			foreach ( $block['innerContent'] ?? array() as $part ) {
				if ( null !== $part ) {
					$html .= $part;
				} elseif ( isset( $children[ $child_index ] ) ) {
					$html .= $this->render_blocks( array( $children[ $child_index ] ) );
					++$child_index;
				}
			}
		}
		return $html;
	}

	/**
	 * Known static core content and layout blocks, with no render callbacks.
	 *
	 * @return array<int,string>
	 */
	private function static_blocks(): array {
		return array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/pullquote',
			'core/separator',
			'core/preformatted',
			'core/verse',
			'core/group',
			'core/columns',
			'core/column',
		);
	}

	/**
	 * Layout blocks whose wrappers do not belong in an HTML email fragment.
	 *
	 * @return array<int,string>
	 */
	private function transparent_blocks(): array {
		return array( 'core/group', 'core/columns', 'core/column' );
	}

	/**
	 * Restrict markup, protocols and attributes to inert email-safe fragments.
	 *
	 * @param string $html Static block markup.
	 * @return string
	 */
	private function sanitize_html( string $html ): string {
		// Remove unsafe element bodies rather than leaving their text behind.
		$html = preg_replace(
			'~<(script|style|iframe|object|svg|form|template)\b[^>]*>.*?</\1\s*>~is',
			'',
			$html
		) ?? '';
		$html = strip_shortcodes( $html );
		$html = preg_replace( '~\[([a-z][a-z0-9_-]*)[^\]]*\].*?\[/\1\]~is', '', $html ) ?? '';
		$html = preg_replace( '~\[/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?/?\]~i', '', $html ) ?? '';

		$allowed = array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'b'          => array(),
			'i'          => array(),
			'u'          => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'hr'         => array(),
			'a'          => array(
				'href'  => true,
				'title' => true,
			),
		);
		$html    = wp_kses( $html, $allowed, array( 'http', 'https', 'mailto' ) );

		// A relative link has no meaningful destination inside an email client.
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'A' ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}
			if ( str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' ) ) {
				$tags->set_attribute( 'href', home_url( $href ) );
			} elseif ( ! str_starts_with( $href, '#' ) &&
				! str_starts_with( $href, '//' ) &&
				! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $href ) ) {
				$tags->set_attribute( 'href', home_url( '/' . ltrim( $href, '/' ) ) );
			}
		}
		return trim( $tags->get_updated_html() );
	}

	/**
	 * Derive text from exactly the sanitized markup used for the HTML fragment.
	 *
	 * @param string $html Sanitized HTML.
	 * @return string
	 */
	private function html_to_text( string $html ): string {
		$html = preg_replace_callback(
			'~<a\s+[^>]*href=("|\')(.*?)\1[^>]*>(.*?)</a>~is',
			static function ( array $matches ): string {
				$label = html_entity_decode(
					wp_strip_all_tags( $matches[3] ),
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				);
				$url   = html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return trim( $label ) === $url ? $url : trim( $label ) . ' (' . $url . ')';
			},
			$html
		) ?? '';
		$html = preg_replace( '~<li(?:\s[^>]*)?>~i', '- ', $html ) ?? '';
		$html = preg_replace(
			'~<br\s*/?>|</(?:p|h[1-6]|li|ul|ol|blockquote|pre)>~i',
			"\n",
			$html
		) ?? '';
		$html = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return $this->normalize_text( $html );
	}

	/**
	 * Normalize line endings without collapsing paragraph boundaries.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private function normalize_text( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[\t ]+/u', ' ', $text ) ?? '';
		$text = preg_replace( '/\n[\t ]+|[\t ]+\n/u', "\n", $text ) ?? '';
		return trim( preg_replace( '/\n{3,}/', "\n\n", $text ) ?? '' );
	}
}

// EOF: src/Content/EmailContentRenderer.php.
