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
	 * Confirm every non-published save path stays outside campaign creation.
	 *
	 * @return void
	 */
	public function test_non_published_status_and_revision_saves_create_no_campaign(): void {
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			$post_id = $this->create_configured_draft();

			self::assertSame(
				$post_id,
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => $status,
						'post_title'  => 'Non-published status ' . $status,
					)
				)
			);
			self::assertSame( $status, get_post_status( $post_id ) );
			self::assertNull( $this->campaign_for_post( $post_id ) );
		}

		$auto_draft_id = self::factory()->post->create(
			array( 'post_status' => 'auto-draft' )
		);
		update_post_meta(
			$auto_draft_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			SendIntent::Send->value
		);
		update_post_meta(
			$auto_draft_id,
			PostNotificationMeta::CONTENT_MODE_KEY,
			ContentMode::Full->value
		);
		self::assertSame(
			$auto_draft_id,
			wp_update_post(
				array(
					'ID'          => $auto_draft_id,
					'post_status' => 'auto-draft',
					'post_title'  => 'Auto draft updated',
				)
			)
		);
		self::assertSame( 'auto-draft', get_post_status( $auto_draft_id ) );
		self::assertNull( $this->campaign_for_post( $auto_draft_id ) );

		$trash_id = $this->create_configured_draft();
		self::assertInstanceOf( \WP_Post::class, wp_trash_post( $trash_id ) );
		self::assertSame( 'trash', get_post_status( $trash_id ) );
		self::assertNull( $this->campaign_for_post( $trash_id ) );

		$revision_parent = $this->create_configured_draft();
		$revision_id     = wp_insert_post(
			array(
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $revision_parent,
				'post_title'   => 'Notification revision fixture',
				'post_content' => 'Revision content',
			)
		);
		self::assertGreaterThan( 0, $revision_id );
		self::assertSame( 'revision', get_post_type( $revision_id ) );
		self::assertNull( $this->campaign_for_post( $revision_parent ) );
		self::assertNull( $this->campaign_for_post( $revision_id ) );
	}

	/**
	 * Confirm edits and schedule-date changes remain editorial state only.
	 *
	 * @return void
	 */
	public function test_scheduled_edits_and_date_changes_create_no_campaign(): void {
		$post_id      = $this->create_configured_draft();
		$original_gmt = $this->schedule_post(
			$post_id,
			time() + DAY_IN_SECONDS
		);

		self::assertNull( $this->campaign_for_post( $post_id ) );

		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => 'Scheduled post edited',
				)
			)
		);
		self::assertSame( 'future', get_post_status( $post_id ) );
		self::assertNull( $this->campaign_for_post( $post_id ) );

		$changed_gmt = $this->schedule_post(
			$post_id,
			time() + ( 2 * DAY_IN_SECONDS )
		);
		self::assertNotSame( $original_gmt, $changed_gmt );

		$post = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );
		self::assertSame( $changed_gmt, $post->post_date_gmt );
		self::assertNull( $this->campaign_for_post( $post_id ) );
	}

	/**
	 * Confirm the core due-time path creates one initial campaign.
	 *
	 * @return void
	 */
	public function test_on_time_future_publication_creates_one_campaign(): void {
		$post_id = $this->create_configured_draft();
		$this->schedule_post( $post_id, time() + DAY_IN_SECONDS );
		$this->set_post_date_directly( $post_id, time() );

		check_and_publish_future_post( $post_id );

		self::assertSame( 'publish', get_post_status( $post_id ) );
		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );
	}

	/**
	 * Confirm manually publishing a scheduled post early creates the campaign then.
	 *
	 * @return void
	 */
	public function test_manual_early_publish_creates_one_campaign(): void {
		$post_id = $this->create_configured_draft();
		$this->schedule_post( $post_id, time() + DAY_IN_SECONDS );
		self::assertNull( $this->campaign_for_post( $post_id ) );

		$now_gmt = gmdate( 'Y-m-d H:i:s' );
		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'            => $post_id,
					'post_status'   => 'publish',
					'post_date'     => get_date_from_gmt( $now_gmt ),
					'post_date_gmt' => $now_gmt,
					'edit_date'     => true,
				)
			)
		);

		self::assertSame( 'publish', get_post_status( $post_id ) );
		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );
	}

	/**
	 * Confirm the direct core publication function follows the same observer path.
	 *
	 * @return void
	 */
	public function test_direct_core_publish_path_creates_one_campaign(): void {
		$post_id = $this->create_configured_draft();

		wp_publish_post( $post_id );

		self::assertSame( 'publish', get_post_status( $post_id ) );
		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );
	}

	/**
	 * Confirm independent repository instances resolve the same initial row.
	 *
	 * This is not the separate true-concurrency qualification; it proves the
	 * database idempotency primitive is shared across repository instances.
	 *
	 * @return void
	 */
	public function test_repository_instances_resolve_the_same_initial_campaign(): void {
		global $wpdb;

		$post_id = $this->create_configured_draft();
		$post    = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );

		$first  = new CampaignRepository( $wpdb );
		$second = new CampaignRepository( $wpdb );

		$first_id = $first->create_initial_building(
			get_current_blog_id(),
			$post_id,
			(string) $post->post_modified_gmt,
			ContentMode::Full
		);
		$second_id = $second->create_initial_building(
			get_current_blog_id(),
			$post_id,
			(string) $post->post_modified_gmt,
			ContentMode::Full
		);

		self::assertGreaterThan( 0, $first_id );
		self::assertSame( $first_id, $second_id );
		self::assertSame( 1, $this->campaign_count_for_post( $post_id ) );
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
	 * Schedule one post at an explicit UTC timestamp.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $timestamp Unix timestamp.
	 * @return string Scheduled GMT datetime.
	 */
	private function schedule_post( int $post_id, int $timestamp ): string {
		$future_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );

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

		return $future_gmt;
	}

	/**
	 * Move a scheduled fixture to an exact publication time without firing save hooks.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $timestamp Unix timestamp.
	 * @return string Stored GMT datetime.
	 */
	private function set_post_date_directly( int $post_id, int $timestamp ): string {
		global $wpdb;

		$gmt = gmdate( 'Y-m-d H:i:s', $timestamp );

		// Test fixture setup intentionally bypasses save hooks.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
			),
			array( 'ID' => $post_id )
		);
		// phpcs:enable

		self::assertNotFalse( $result );
		clean_post_cache( $post_id );

		return $gmt;
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
