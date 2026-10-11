<?php
/**
 * File: src/Campaign/CampaignInputCapture.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Content\EmailExcerptSettings;
use ArgentWolf\PostNotifier\Content\EmailMoreSettings;
use ArgentWolf\PostNotifier\Content\EmailNamedTemplateLibrary;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use InvalidArgumentException;
use WP_Post;

/**
 * Freeze recipient-neutral inputs at the completed first-publication save.
 *
 * Snapshotting must not compose subscriber links, resolve an audience, or
 * advance the campaign beyond building state.
 */
final class CampaignInputCapture {
	/**
	 * Freeze current saved post and template inputs, or verify an existing freeze.
	 *
	 * A repeated first-publication observation may resolve the same campaign;
	 * when a valid snapshot already exists, it wins without being regenerated.
	 *
	 * @param int         $campaign_id Reserved initial campaign ID.
	 * @param WP_Post     $post        Post from the completed WordPress save.
	 * @param ContentMode $mode        Mode used for the campaign reservation.
	 * @return bool Whether the campaign now has a valid immutable snapshot.
	 * @throws InvalidArgumentException On unsupported selections or bad input.
	 */
	public function capture( int $campaign_id, WP_Post $post, ContentMode $mode ): bool {
		$store = new CampaignContentSnapshotStore();
		if ( null !== $store->read( $campaign_id ) ) {
			return true;
		}

		$templates   = new EmailTemplateSettings();
		$template_id = $this->selected_template_id(
			get_post_meta( $post->ID, PostNotificationMeta::TEMPLATE_ID_KEY, true )
		);
		$fields      = $templates->get();

		if ( 0 !== $template_id ) {
			$named = ( new EmailNamedTemplateLibrary( $templates ) )->find( $template_id );
			if ( null === $named ) {
				throw new InvalidArgumentException( 'Selected named template is unavailable.' );
			}
			$fields = $named['settings'];
		}

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			throw new InvalidArgumentException( 'A local post permalink is required.' );
		}

		$input = array(
			'version'         => CampaignContentSnapshotStore::VERSION,
			'site_id'         => get_current_blog_id(),
			'post_id'         => (int) $post->ID,
			'template_id'     => $template_id,
			'site_name'       => sanitize_text_field( get_bloginfo( 'name' ) ),
			'post_title'      => (string) $post->post_title,
			'post_content'    => (string) $post->post_content,
			'post_excerpt'    => (string) $post->post_excerpt,
			'post_url'        => $permalink,
			'content_mode'    => $mode->value,
			'more_enabled'    => ( new EmailMoreSettings() )->enabled(),
			'excerpt_words'   => ( new EmailExcerptSettings() )->get(),
			'template_fields' => $fields,
		);

		if ( $store->freeze( $campaign_id, $input ) ) {
			return true;
		}

		// Another observer may have won the compare-and-set. Never overwrite it.
		return null !== $store->read( $campaign_id );
	}

	/**
	 * Only zero or an owned named-template ID may enter a campaign snapshot.
	 *
	 * @param mixed $raw Saved post metadata.
	 * @return int Selected ID.
	 * @throws InvalidArgumentException On malformed or external-only IDs.
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

// EOF: src/Campaign/CampaignInputCapture.php.
