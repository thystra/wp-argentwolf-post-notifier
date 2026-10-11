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
	 * Persisted named variants for a selected editor template ID.
	 *
	 * @var EmailNamedTemplateLibrary
	 */
	private EmailNamedTemplateLibrary $named;

	/**
	 * Use the same composer as all future notification messages.
	 *
	 * @param EmailTemplateComposer          $composer Canonical message composer.
	 * @param EmailExcerptSettings           $excerpts Validated generated excerpt length.
	 * @param EmailMoreSettings              $more     Site-wide core More-block preference.
	 * @param EmailNamedTemplateLibrary|null $named    Optional named-template resolver.
	 */
	public function __construct(
		private EmailTemplateComposer $composer,
		private EmailExcerptSettings $excerpts,
		private EmailMoreSettings $more,
		?EmailNamedTemplateLibrary $named = null
	) {
		$this->named = $named ?? new EmailNamedTemplateLibrary( new EmailTemplateSettings() );
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

		$template_id = $this->selected_template_id(
			get_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, true )
		);
		if ( 0 !== $template_id ) {
			// Even unused site-default drafts must not evade live-preview validation.
			if ( null !== $template_override ) {
				( new EmailTemplateSettings() )->validate( $template_override );
			}
			$selected = $this->named->find( $template_id );
			if ( null === $selected ) {
				throw new InvalidArgumentException( 'The selected named template is unavailable.' );
			}
			$template_override = $selected['settings'];
		}

		return $this->composer->compose(
			$post->post_content,
			$post->post_title,
			$permalink,
			home_url( '/?awpn_preview_only=unsubscribe' ),
			home_url( '/?awpn_preview_only=manage' ),
			$post->post_excerpt,
			$mode,
			$this->more->enabled(),
			$this->excerpts->get(),
			$template_override
		);
	}

	/**
	 * Accept only the site-default ID or an ID in the owned named namespace.
	 *
	 * Externally filtered editor choices have no rendering contract yet.
	 * Malformed and missing selections never become a site-default email.
	 *
	 * @param mixed $raw Persisted post metadata value.
	 * @return int Validated template ID.
	 * @throws InvalidArgumentException On malformed or unsupported selections.
	 */
	private function selected_template_id( mixed $raw ): int {
		if ( '' === $raw || '0' === $raw || 0 === $raw ) {
			return 0;
		}
		if ( is_int( $raw ) ) {
			$id = $raw;
		} elseif (
			is_string( $raw )
			&& 1 === preg_match( '/^[1-9][0-9]*$/D', $raw )
		) {
			$id = (int) $raw;
			if ( (string) $id !== $raw ) {
				throw new InvalidArgumentException( 'Invalid selected template ID.' );
			}
		} else {
			throw new InvalidArgumentException( 'Invalid selected template ID.' );
		}
		if (
			$id < EmailNamedTemplateLibrary::FIRST_ID
			|| $id > EmailNamedTemplateLibrary::LAST_ID
		) {
			throw new InvalidArgumentException( 'Unsupported selected template ID.' );
		}
		return $id;
	}
}

// EOF: src/Content/EmailTemplatePreview.php.
