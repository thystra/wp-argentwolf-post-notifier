<?php
/**
 * File: tests/Integration/PublicationObserverTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Campaign\PublicationObserver;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use ArgentWolf\PostNotifier\Plugin;
use WP_UnitTestCase;

/**
 * Integration coverage for actual-publication campaign reservation.
 */
final class PublicationObserverTest extends WP_UnitTestCase {
	/**
	 * Clear plugin-owned campaign state before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$tables = TableNames::from_database( $wpdb );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$tables->campaign_recipients()
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i',
				$tables->campaigns()
			)
		);
		// phpcs:enable
	}

	/**
	 * Confirm the completed-save publication observer is registered.
	 *
	 * @return void
	 */
	public function test_publication_observer_is_registered(): void {
		$observer = Plugin::instance()->container()->get( PublicationObserver::class );

		self::assertInstanceOf( PublicationObserver::class, $observer );
		self::assertSame(
			10,
			has_action(
				'wp_after_insert_post',
				array( $observer, 'observe_publication' )
			)
		);
	}

	/**
	 * Confirm an immediate first publish creates one building campaign shell.
	 *
	 * @return void
	 */
	public function test_immediate_publish_creates_one_initial_building_campaign(): void {
		$post_id = $this->create_configured_draft();

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			)
		);

		$row = $this->campaign_for_post( $post_id );
		self::assertNotNull( $row );
		self::assertSame(
			CampaignRepository::initial_key( get_current_blog_id(), $post_id ),
			$row['campaign_key']
		);
		self::assertSame( CampaignRepository::KIND_POST, $row['campaign_kind'] );
		self::assertSame( CampaignRepository::STATUS_BUILDING, $row['status'] );
		self::assertSame( ContentMode::Full->value, $row['content_mode'] );
		self::assertSame( '', $row['subject'] );
		self::assertSame( '', $row['html_body'] );
		self::assertSame( '', $row['text_body'] );
		self::assertSame( 0, $this->campaign_recipient_count( (int) $row['id'] ) );
	}

	/**
	 * Confirm scheduling stores editorial state only until core actually publishes.
	 *
	 * @return void
	 */
	public function test_scheduled_post_creates_campaign_only_when_core_publishes(): void {
		global $wpdb;

		$post_id    = $this->create_configured_draft();
		$future_gmt = gmdate(
			'Y-m-d H:i:s',
			current_datetime()->getTimestamp() + DAY_IN_SECONDS
		);

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'            => $post_id,
					'post_status'   => 'future',
					'post_date'     => get_date_from_gmt( $future_gmt ),
					'post_date_gmt' => $future_gmt,
					'edit_date'     => true,
				)
			)
		);
		self::assertSame( 'future', get_post_status( $post_id ) );
		self::assertNull( $this->campaign_for_post( $post_id ) );

		$due_gmt = gmdate(
			'Y-m-d H:i:s',
			current_datetime()->getTimestamp() - MINUTE_IN_SECONDS
		);

		// Move the already-scheduled fixture to a due timestamp without firing
		// another application save; core's scheduled-publication path is tested next.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => get_date_from_gmt( $due_gmt ),
				'post_date_gmt' => $due_gmt,
			),
			array( 'ID' => $post_id )
		);
		// phpcs:enable
		clean_post_cache( $post_id );

		check_and_publish_future_post( $post_id );

		self::assertSame( 'publish', get_post_status( $post_id ) );
		self::assertNotNull( $this->campaign_for_post( $post_id ) );
	}

	/**
	 * Confirm neutral and explicit do-not-send intent both fail closed.
	 *
	 * @return void
	 */
	public function test_non_send_intents_create_no_campaign(): void {
		$site_default_post = self::factory()->post->create(
			array( 'post_status' => 'draft' )
		);
		self::assertSame(
			$site_default_post,
			wp_update_post(
				array(
					'ID'          => $site_default_post,
					'post_status' => 'publish',
				)
			)
		);

		$do_not_send_post = $this->create_configured_draft(
			SendIntent::DoNotSend
		);
		self::assertSame(
			$do_not_send_post,
			wp_update_post(
				array(
					'ID'          => $do_not_send_post,
					'post_status' => 'publish',
				)
			)
		);

		self::assertNull( $this->campaign_for_post( $site_default_post ) );
		self::assertNull( $this->campaign_for_post( $do_not_send_post ) );
	}

	/**
	 * Confirm ordinary published edits and republishing cannot create another initial campaign.
	 *
	 * @return void
	 */
	public function test_updates_and_republishing_do_not_duplicate_initial_campaign(): void {
		$post_id = $this->create_configured_draft();

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			)
		);
		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => 'Published post updated',
				)
			)
		);
		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			)
		);
		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			)
		);

		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );
	}

	/**
	 * Confirm hook re-entry is harmless because the database key is authoritative.
	 *
	 * @return void
	 */
	public function test_duplicate_publication_observation_creates_one_campaign(): void {
		$post_id = $this->create_configured_draft();

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			)
		);

		$post = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );

		$post_before              = clone $post;
		$post_before->post_status = 'draft';

		$observer = Plugin::instance()->container()->get( PublicationObserver::class );
		self::assertInstanceOf( PublicationObserver::class, $observer );

		$observer->observe_publication( $post_id, $post, true, $post_before );
		$observer->observe_publication( $post_id, $post, true, $post_before );

		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );
	}

	/**
	 * Confirm a publish state with a future GMT timestamp is rejected defensively.
	 *
	 * @return void
	 */
	public function test_future_publication_timestamp_fails_closed(): void {
		global $wpdb;

		$post_id    = $this->create_configured_draft();
		$future_gmt = gmdate(
			'Y-m-d H:i:s',
			current_datetime()->getTimestamp() + DAY_IN_SECONDS
		);

		// Build an impossible/stale state defensively: published status with a
		// publication timestamp still materially in the future.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_status'   => 'publish',
				'post_date'     => get_date_from_gmt( $future_gmt ),
				'post_date_gmt' => $future_gmt,
			),
			array( 'ID' => $post_id )
		);
		// phpcs:enable
		clean_post_cache( $post_id );

		$post = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );

		$post_before              = clone $post;
		$post_before->post_status = 'draft';

		$observer = Plugin::instance()->container()->get( PublicationObserver::class );
		self::assertInstanceOf( PublicationObserver::class, $observer );
		$observer->observe_publication( $post_id, $post, true, $post_before );

		self::assertNull( $this->campaign_for_post( $post_id ) );
	}

	/**
	 * Create one draft with representative notification metadata.
	 *
	 * @param SendIntent $send_intent Stored notification intent.
	 * @return int
	 */
	private function create_configured_draft(
		SendIntent $send_intent = SendIntent::Send
	): int {
		$post_id = self::factory()->post->create(
			array( 'post_status' => 'draft' )
		);

		update_post_meta(
			$post_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			$send_intent->value
		);
		update_post_meta(
			$post_id,
			PostNotificationMeta::CONTENT_MODE_KEY,
			ContentMode::Full->value
		);

		return $post_id;
	}

	/**
	 * Return the one campaign row for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	private function campaign_for_post( int $post_id ): ?array {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->campaigns();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE post_id = %d LIMIT 1',
				$table,
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count campaign rows for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function campaign_count_for_post( int $post_id ): int {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->campaigns();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_id = %d',
				$table,
				$post_id
			)
		);
		// phpcs:enable

		return (int) $count;
	}

	/**
	 * Count recipients for one campaign.
	 *
	 * @param int $campaign_id Campaign row ID.
	 * @return int
	 */
	private function campaign_recipient_count( int $campaign_id ): int {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->campaign_recipients();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE campaign_id = %d',
				$table,
				$campaign_id
			)
		);
		// phpcs:enable

		return (int) $count;
	}
}

// EOF: tests/Integration/PublicationObserverTest.php.
