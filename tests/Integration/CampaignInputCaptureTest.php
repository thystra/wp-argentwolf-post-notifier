<?php
/**
 * File: tests/Integration/CampaignInputCaptureTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignContentSnapshotStore;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Campaign\PublicationObserver;
use ArgentWolf\PostNotifier\Content\EmailExcerptSettings;
use ArgentWolf\PostNotifier\Content\EmailMoreSettings;
use ArgentWolf\PostNotifier\Content\EmailNamedTemplateLibrary;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use ArgentWolf\PostNotifier\Plugin;
use WP_Post;
use WP_UnitTestCase;

/**
 * Publication-time snapshot capture without campaign rendering or delivery.
 */
final class CampaignInputCaptureTest extends WP_UnitTestCase {
	/**
	 * Clear plugin-owned campaign fixtures before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables->campaign_recipients() ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables->campaigns() ) );
		// phpcs:enable
	}

	/**
	 * First publication freezes the exact saved inputs, not preview links.
	 *
	 * @return void
	 */
	public function test_publication_captures_site_defaults_and_leaves_building_shell(): void {
		$settings          = new EmailTemplateSettings();
		$fields            = $settings->defaults();
		$fields['subject'] = 'Site: {{post_title}}';
		$settings->save( $fields );
		( new EmailExcerptSettings() )->save( 72 );
		( new EmailMoreSettings() )->save( '0' );

		$post_id = $this->draft();
		$this->publish( $post_id );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		$stored = ( new CampaignContentSnapshotStore() )->read( (int) $row['id'] );
		self::assertNotNull( $stored );
		self::assertSame( CampaignContentSnapshotStore::VERSION, $stored['version'] );
		self::assertSame( get_current_blog_id(), $stored['site_id'] );
		self::assertSame( $post_id, $stored['post_id'] );
		self::assertSame( 0, $stored['template_id'] );
		self::assertSame( 'First post title', $stored['post_title'] );
		self::assertSame( 'Immutable post body', $stored['post_content'] );
		self::assertSame( 'Manual summary', $stored['post_excerpt'] );
		self::assertSame( get_permalink( $post_id ), $stored['post_url'] );
		self::assertSame( ContentMode::Full->value, $stored['content_mode'] );
		self::assertFalse( $stored['more_enabled'] );
		self::assertSame( 72, $stored['excerpt_words'] );
		self::assertSame( $fields, $stored['template_fields'] );
		$this->assert_building_and_no_delivery( $row );
	}

	/**
	 * Named inputs survive changes and deletion of the underlying template.
	 *
	 * @return void
	 */
	public function test_named_template_is_frozen_when_published(): void {
		$templates         = new EmailTemplateSettings();
		$fields            = $templates->defaults();
		$fields['subject'] = 'Named: {{post_title}}';
		$named             = new EmailNamedTemplateLibrary( $templates );
		$id                = $named->create( 'Newsletter variant', $fields );

		$post_id = $this->draft();
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, $id );
		$this->publish( $post_id );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		$store  = new CampaignContentSnapshotStore();
		$frozen = $store->read( (int) $row['id'] );
		self::assertNotNull( $frozen );
		self::assertSame( $id, $frozen['template_id'] );
		self::assertSame( $fields, $frozen['template_fields'] );

		$named->delete( $id );
		$templates->restore_defaults();
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'Edited after publication',
				'post_content' => 'Changed content',
			)
		);
		self::assertSame( $frozen, $store->read( (int) $row['id'] ) );

		// Re-entry after named-template deletion must honor the frozen document.
		$post = get_post( $post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		$before              = clone $post;
		$before->post_status = 'draft';
		$observer            = Plugin::instance()->container()->get( PublicationObserver::class );
		self::assertInstanceOf( PublicationObserver::class, $observer );
		$observer->observe_publication( $post_id, $post, true, $before );
		self::assertSame( $frozen, $store->read( (int) $row['id'] ) );
		$this->assert_building_and_no_delivery( $row );
	}

	/**
	 * Duplicate observation cannot rewrite a valid first-writer snapshot.
	 *
	 * @return void
	 */
	public function test_duplicate_observation_keeps_original_snapshot(): void {
		$post_id = $this->draft();
		$this->publish( $post_id );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		$store  = new CampaignContentSnapshotStore();
		$frozen = $store->read( (int) $row['id'] );
		self::assertNotNull( $frozen );
		$post = get_post( $post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		$post->post_content  = 'Uncommitted changed body';
		$before              = clone $post;
		$before->post_status = 'draft';
		$observer            = Plugin::instance()->container()->get( PublicationObserver::class );
		self::assertInstanceOf( PublicationObserver::class, $observer );
		$observer->observe_publication( $post_id, $post, true, $before );
		self::assertSame( $frozen, $store->read( (int) $row['id'] ) );
	}

	/**
	 * Scheduled publication captures only when core actually transitions to publish.
	 *
	 * @return void
	 */
	public function test_scheduled_publication_captures_when_due(): void {
		global $wpdb;
		$post_id    = $this->draft();
		$future_gmt = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
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
		self::assertNull( $this->campaign( $post_id ) );

		$due_gmt = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		// Move fixture clock without another editorial post-save event.
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
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		self::assertNotNull(
			( new CampaignContentSnapshotStore() )->read( (int) $row['id'] )
		);
		$this->assert_building_and_no_delivery( $row );
	}

	/**
	 * Core direct publication runs the same completed-save capture path.
	 *
	 * @return void
	 */
	public function test_core_publish_snapshots_inputs(): void {
		$post_id = $this->draft();
		wp_publish_post( $post_id );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		self::assertNotNull(
			( new CampaignContentSnapshotStore() )->read( (int) $row['id'] )
		);
		$this->assert_building_and_no_delivery( $row );
	}

	/**
	 * A deleted named ID must not silently substitute site-default content.
	 *
	 * @return void
	 */
	public function test_deleted_template_leaves_safe_building_shell(): void {
		$templates = new EmailTemplateSettings();
		$named     = new EmailNamedTemplateLibrary( $templates );
		$id        = $named->create( 'To be deleted', $templates->defaults() );
		$named->delete( $id );
		$post_id = $this->draft();
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, $id );
		$reasons = array();
		add_action(
			'argentwolf_post_notifier_campaign_snapshot_failed',
			static function ( int $observed_post, string $reason ) use ( &$reasons ): void {
				$reasons[] = array( $observed_post, $reason );
			},
			10,
			2
		);
		$this->publish( $post_id );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		self::assertNull(
			( new CampaignContentSnapshotStore() )->read( (int) $row['id'] )
		);
		self::assertSame( array( array( $post_id, 'capture_error' ) ), $reasons );
		$this->assert_building_and_no_delivery( $row );
	}

	/**
	 * Unsupported external-only editor IDs cannot produce default snapshots.
	 *
	 * @return void
	 */
	public function test_external_template_id_fails_closed(): void {
		$post_id = $this->draft();
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, 17 );
		$this->publish( $post_id );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		self::assertNull(
			( new CampaignContentSnapshotStore() )->read( (int) $row['id'] )
		);
		$this->assert_building_and_no_delivery( $row );
	}

	/**
	 * Non-sending publication does not reserve or snapshot a campaign.
	 *
	 * @return void
	 */
	public function test_do_not_send_intent_keeps_campaign_absent(): void {
		$post_id = $this->draft();
		update_post_meta(
			$post_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			SendIntent::DoNotSend->value
		);
		$this->publish( $post_id );
		self::assertNull( $this->campaign( $post_id ) );
	}

	/**
	 * Prepare an explicit-Send draft with saved text and selection policy.
	 *
	 * @return int Draft post ID.
	 */
	private function draft(): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'First post title',
				'post_content' => 'Immutable post body',
				'post_excerpt' => 'Manual summary',
			)
		);
		update_post_meta(
			$post_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			SendIntent::Send->value
		);
		update_post_meta(
			$post_id,
			PostNotificationMeta::CONTENT_MODE_KEY,
			ContentMode::Full->value
		);
		return $post_id;
	}

	/**
	 * Publish a draft and ensure WordPress accepts the save.
	 *
	 * @param int $post_id Draft post ID.
	 * @return void
	 */
	private function publish( int $post_id ): void {
		self::assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			)
		);
	}

	/**
	 * Resolve the initial campaign using its idempotency key.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	private function campaign( int $post_id ): ?array {
		return ( new CampaignRepository() )->find_by_key(
			CampaignRepository::initial_key( get_current_blog_id(), $post_id )
		);
	}

	/**
	 * Ensure snapshot capture never creates deliverable content or recipients.
	 *
	 * @param array<string,mixed> $row Campaign state.
	 * @return void
	 */
	private function assert_building_and_no_delivery( array $row ): void {
		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		self::assertSame( CampaignRepository::STATUS_BUILDING, $row['status'] );
		self::assertSame( '', $row['subject'] );
		self::assertSame( '', $row['html_body'] );
		self::assertSame( '', $row['text_body'] );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE campaign_id = %d',
				$tables->campaign_recipients(),
				(int) $row['id']
			)
		);
		// phpcs:enable
		self::assertSame( 0, (int) $count );
	}
}

// EOF: tests/Integration/CampaignInputCaptureTest.php.
