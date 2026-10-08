<?php
/**
 * File: src/Campaign/PublicationObserver.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use RuntimeException;

/**
 * Observe completed WordPress saves and reserve initial campaign state.
 */
final class PublicationObserver implements Registerable {
	/**
	 * Supported post type for the Beta.2 publication lifecycle.
	 */
	private const POST_TYPE = 'post';

	/**
	 * Small future-time tolerance for clock skew around publication.
	 */
	private const CLOCK_SKEW_SECONDS = 60;

	/**
	 * Construct the publication observer.
	 *
	 * @param CampaignRepository $campaigns Campaign persistence.
	 */
	public function __construct( private CampaignRepository $campaigns ) {
	}

	/**
	 * Register the completed-save observer.
	 *
	 * `wp_after_insert_post` is used instead of an earlier transition hook so
	 * REST/editor metadata has completed persistence before notification intent
	 * is evaluated.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'wp_after_insert_post',
			array( $this, 'observe_publication' ),
			10,
			4
		);
	}

	/**
	 * Reserve one initial campaign only for an actual first publication event.
	 *
	 * @param int           $post_id     Saved post ID.
	 * @param \WP_Post      $post        Post after the completed save.
	 * @param bool          $update      Whether an existing post was updated.
	 * @param \WP_Post|null $post_before Post state before the save, when available.
	 * @return void
	 */
	public function observe_publication(
		int $post_id,
		\WP_Post $post,
		bool $update,
		?\WP_Post $post_before
	): void {
		unset( $update );

		if (
			$post_id < 1
			|| $post_id !== (int) $post->ID
			|| self::POST_TYPE !== $post->post_type
			|| 'publish' !== $post->post_status
			|| 'publish' !== get_post_status( $post_id )
		) {
			return;
		}

		if ( null !== $post_before && 'publish' === $post_before->post_status ) {
			return;
		}

		if (
			false !== wp_is_post_revision( $post_id )
			|| false !== wp_is_post_autosave( $post_id )
		) {
			return;
		}

		$send_intent = SendIntent::sanitize(
			get_post_meta(
				$post_id,
				PostNotificationMeta::SEND_INTENT_KEY,
				true
			)
		);

		/*
		 * Site-default send policy does not exist yet. Fail closed until a later
		 * site policy explicitly resolves that neutral state to Send.
		 */
		if ( SendIntent::Send->value !== $send_intent ) {
			return;
		}

		$published_at = get_post_datetime( $post, 'date', 'gmt' );
		if (
			false === $published_at
			|| $published_at->getTimestamp() > time() + self::CLOCK_SKEW_SECONDS
		) {
			return;
		}

		$content_mode = ContentMode::from(
			ContentMode::sanitize(
				get_post_meta(
					$post_id,
					PostNotificationMeta::CONTENT_MODE_KEY,
					true
				)
			)
		);

		try {
			$this->campaigns->create_initial_building(
				get_current_blog_id(),
				$post_id,
				(string) $post->post_modified_gmt,
				$content_mode
			);
		} catch ( RuntimeException ) {
			/*
			 * Publication is already committed by WordPress. Do not turn a
			 * notification-persistence failure into a misleading failed publish
			 * response. Milestone 7 diagnostics can observe this privacy-safe
			 * failure action without exposing message or recipient data.
			 */
			do_action(
				'argentwolf_post_notifier_campaign_creation_failed',
				$post_id,
				'persistence_error'
			);
		}
	}
}

// EOF: src/Campaign/PublicationObserver.php.
