<?php
/**
 * File: src/Content/EmailContentSelector.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

/**
 * Deterministically select source content without rendering or sending email.
 *
 * The returned block content is still untrusted source, not ready-to-send
 * HTML. The future renderer must sanitize it before constructing messages.
 */
final class EmailContentSelector {
	/** Default number of words in the generated excerpt. */
	public const DEFAULT_EXCERPT_WORDS = 55;

	/** Maximum caller-selected generated excerpt length. */
	public const MAX_EXCERPT_WORDS = 500;

	/** Core More block name. */
	private const MORE_BLOCK = 'core/more';

	/**
	 * Select a post's content using the Beta.3 precedence contract.
	 *
	 * `format=blocks` carries unrendered block markup. `format=text` carries
	 * plain text. Neither format is a finished email or a safe HTML template.
	 *
	 * @param string $post_content     Original post content.
	 * @param string $manual_excerpt   Original WordPress post excerpt.
	 * @param string $content_mode     site_default, excerpt, or full.
	 * @param bool   $more_enabled     Whether to honor core More markers.
	 * @param int    $excerpt_words    Generated excerpt word count.
	 * @return array{source:string,format:string,content:string}
	 */
	public function select(
		string $post_content,
		string $manual_excerpt = '',
		string $content_mode = 'site_default',
		bool $more_enabled = true,
		int $excerpt_words = self::DEFAULT_EXCERPT_WORDS
	): array {
		$blocks = parse_blocks( $post_content );

		if ( 'full' === $content_mode ) {
			return $this->block_selection( 'full', $blocks );
		}

		if ( $this->contains_marker( $blocks, EmailCutoffBlock::BLOCK_NAME ) ) {
			return $this->cutoff_selection( $blocks, EmailCutoffBlock::BLOCK_NAME, 'email_cutoff' );
		}

		if ( $more_enabled && $this->contains_marker( $blocks, self::MORE_BLOCK ) ) {
			return $this->cutoff_selection( $blocks, self::MORE_BLOCK, 'more' );
		}

		$manual_text = $this->plain_text( $manual_excerpt );
		if ( '' !== $manual_text ) {
			return array(
				'source'  => 'manual_excerpt',
				'format'  => 'text',
				'content' => $manual_text,
			);
		}

		$excerpt_words = max( 1, min( self::MAX_EXCERPT_WORDS, $excerpt_words ) );
		$text          = $this->collect_static_text( $blocks );

		return array(
			'source'  => 'generated_excerpt',
			'format'  => 'text',
			'content' => wp_trim_words( $text, $excerpt_words, '…' ),
		);
	}

	/**
	 * Return the selected serialized blocks after removing presentation markers.
	 *
	 * @param string           $source Selection source identifier.
	 * @param array<int,array> $blocks Parsed WordPress blocks.
	 * @return array{source:string,format:string,content:string}
	 */
	private function block_selection( string $source, array $blocks ): array {
		return array(
			'source'  => $source,
			'format'  => 'blocks',
			'content' => serialize_blocks( $this->without_markers( $blocks ) ),
		);
	}

	/**
	 * Choose the first matching marker, even when it is inside a nested block.
	 *
	 * For nested markers, return static plain text: truncating arbitrary inner
	 * block markup at that point could leave broken wrapping HTML. No dynamic
	 * block, shortcode, or embed callback is executed by the selector.
	 *
	 * @param array<int,array> $blocks Parsed WordPress blocks.
	 * @param string           $marker Target block name.
	 * @param string           $source Selection source identifier.
	 * @return array{source:string,format:string,content:string}
	 */
	private function cutoff_selection( array $blocks, string $marker, string $source ): array {
		$prefix = array();

		foreach ( $blocks as $block ) {
			if ( $marker === $block['blockName'] ) {
				return $this->block_selection( $source, $prefix );
			}

			if ( $this->contains_marker( $block['innerBlocks'] ?? array(), $marker ) ) {
				return array(
					'source'  => $source,
					'format'  => 'text',
					'content' => $this->collect_static_text( $blocks, $marker ),
				);
			}

			$prefix[] = $block;
		}

		return $this->block_selection( $source, $prefix );
	}

	/**
	 * Determine whether a block or any of its descendants is the target.
	 *
	 * @param array<int,array> $blocks Parsed WordPress blocks.
	 * @param string           $marker Target block name.
	 * @return bool
	 */
	private function contains_marker( array $blocks, string $marker ): bool {
		foreach ( $blocks as $block ) {
			if ( $marker === $block['blockName'] ) {
				return true;
			}

			if ( $this->contains_marker( $block['innerBlocks'] ?? array(), $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove invisible cutoff and More blocks without executing render callbacks.
	 *
	 * @param array<int,array> $blocks Parsed WordPress blocks.
	 * @return array<int,array>
	 */
	private function without_markers( array $blocks ): array {
		$clean = array();

		foreach ( $blocks as $block ) {
			if ( $this->is_presentation_marker( $block['blockName'] ) ) {
				continue;
			}

			$children = $block['innerBlocks'] ?? array();
			if ( ! empty( $children ) ) {
				$filtered_children = array();
				$filtered_parts    = array();
				$child_index       = 0;

				foreach ( $block['innerContent'] as $part ) {
					if ( null !== $part ) {
						$filtered_parts[] = $part;
						continue;
					}

					$child = $children[ $child_index++ ];
					if ( $this->is_presentation_marker( $child['blockName'] ) ) {
						continue;
					}

					$filtered_children[] = $this->without_markers( array( $child ) )[0];
					$filtered_parts[]    = null;
				}

				$block['innerBlocks']  = $filtered_children;
				$block['innerContent'] = $filtered_parts;
			}

			$clean[] = $block;
		}

		return $clean;
	}

	/**
	 * Identify markup-only markers that must not reach downstream rendering.
	 *
	 * @param string|null $block_name Parsed block name.
	 * @return bool
	 */
	private function is_presentation_marker( ?string $block_name ): bool {
		return in_array(
			$block_name,
			array( EmailCutoffBlock::BLOCK_NAME, self::MORE_BLOCK ),
			true
		);
	}

	/**
	 * Collect static text in parser order without executing dynamic content.
	 *
	 * @param array<int,array> $blocks Parsed WordPress blocks.
	 * @param string|null      $stop   Optional marker at which to stop.
	 * @return string
	 */
	private function collect_static_text( array $blocks, ?string $stop = null ): string {
		$parts = array();
		$this->collect_static_parts( $blocks, $parts, $stop );
		return $this->plain_text( implode( ' ', $parts ) );
	}

	/**
	 * Add static fragments before the marker in depth-first order.
	 *
	 * @param array<int,array>  $blocks Parsed WordPress blocks.
	 * @param array<int,string> $parts  Collected static fragments, by reference.
	 * @param string|null       $stop   Optional marker at which to stop.
	 * @return bool True when the stop marker was found.
	 */
	private function collect_static_parts( array $blocks, array &$parts, ?string $stop ): bool {
		foreach ( $blocks as $block ) {
			if ( null !== $stop && $stop === $block['blockName'] ) {
				return true;
			}

			if ( $this->is_presentation_marker( $block['blockName'] ) ) {
				continue;
			}

			$children    = $block['innerBlocks'] ?? array();
			$child_index = 0;
			foreach ( $block['innerContent'] as $part ) {
				if ( null !== $part ) {
					$parts[] = $part;
					continue;
				}

				$found = $this->collect_static_parts(
					array( $children[ $child_index++ ] ),
					$parts,
					$stop
				);
				if ( $found ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Strip markup and registered shortcodes without executing shortcode callbacks.
	 *
	 * @param string $content Untrusted static source text.
	 * @return string
	 */
	private function plain_text( string $content ): string {
		$text = strip_shortcodes( $content );
		$text = wp_strip_all_tags( $text, true );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
	}
}

// EOF: src/Content/EmailContentSelector.php.
