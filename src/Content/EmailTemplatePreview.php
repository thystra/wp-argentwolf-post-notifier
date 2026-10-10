<?php
/**
 * File: src/Content/EmailTemplatePreview.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use InvalidArgumentException;
use WP_Post;

/**
 * Compose a read-only administrator preview using the canonical composer.
 *
 * Preview footer destinations are deliberately synthetic local examples. No
 * subscriber is resolved, no bearer token is generated, and no message is sent.
 */
final class EmailTemplatePreview {
	/**
	 * Use the same composer as all future notification messages.
	 *
	 * @param EmailTemplateComposer $composer Canonical message composer.
	 */
	public function __construct( private EmailTemplateComposer $composer ) {
	}

	/**
	 * Render a published post for a user allowed to administer templates.
	 *
	 * @param int        $post_id           Published post ID.
	 * @param array|null $template_override Unsaved fields or null for stored settings.
	 * @return array{source:string,subject:string,html:string,text:string}
	 * @throws InvalidArgumentException If the post or access is invalid.
	 */
	public function preview( int $post_id, ?array $template_override = null ): array {
		if (
			! current_user_can( 'manage_options' ) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			throw new InvalidArgumentException( 'Preview access is not permitted.' );
		}

		$post = get_post( $post_id );
		if (
			! $post instanceof WP_Post ||
			'post' !== $post->post_type ||
			'publish' !== $post->post_status
		) {
			throw new InvalidArgumentException( 'A published post is required for preview.' );
		}

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			throw new InvalidArgumentException( 'A local post permalink is required.' );
		}

		$mode = ContentMode::sanitize(
			get_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, true )
		);

		return $this->composer->compose(
			$post->post_content,
			$post->post_title,
			$permalink,
			home_url( '/?awpn_preview_only=unsubscribe' ),
			home_url( '/?awpn_preview_only=manage' ),
			$post->post_excerpt,
			$mode,
			true,
			EmailContentSelector::DEFAULT_EXCERPT_WORDS,
			$template_override
		);
	}
}

// EOF: src/Content/EmailTemplatePreview.php.
