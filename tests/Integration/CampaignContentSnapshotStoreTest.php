<?php
/**
 * File: tests/Integration/CampaignContentSnapshotStoreTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignContentSnapshotStore;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use InvalidArgumentException;
use UnexpectedValueException;
use WP_UnitTestCase;

/**
 * Qualify write-once campaign source snapshots without audience or mail work.
 */
final class CampaignContentSnapshotStoreTest extends WP_UnitTestCase {
	/**
	 * Remove plugin-owned campaign fixtures for independent tests.
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
	 * A snapshot is frozen once, even if a later caller supplies changed content.
	 *
	 * @return void
	 */
	public function test_first_writer_wins_and_rendered_message_remains_unset(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		$store   = new CampaignContentSnapshotStore();
		$source  = $this->source( $post_id );
		self::assertNull( $store->read( $id ) );
		self::assertTrue( $store->freeze( $id, $source ) );
		$changed                 = $source;
		$changed['post_content'] = 'New post text that must not replace the snapshot.';
		self::assertFalse( $store->freeze( $id, $changed ) );
		self::assertSame( $source, $store->read( $id ) );

		$campaign = ( new CampaignRepository() )->find_by_key(
			CampaignRepository::initial_key( get_current_blog_id(), $post_id )
		);
		self::assertNotNull( $campaign );
		self::assertSame( CampaignRepository::STATUS_BUILDING, $campaign['status'] );
		self::assertSame( '', $campaign['subject'] );
		self::assertSame( '', $campaign['html_body'] );
		self::assertSame( '', $campaign['text_body'] );
		$this->assert_no_recipient_rows();
	}

	/**
	 * The writer cannot attach another post's inputs or write after building.
	 *
	 * @return void
	 */
	public function test_wrong_post_and_nonbuilding_rows_cannot_be_frozen(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		$store   = new CampaignContentSnapshotStore();
		$source  = $this->source( $post_id );
		$source['post_id'] = $post_id + 1000;
		self::assertFalse( $store->freeze( $id, $source ) );
		self::assertNull( $store->read( $id ) );

		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables->campaigns(),
			array( 'status' => 'queued' ),
			array( 'id' => $id )
		);
		// phpcs:enable
		self::assertFalse( $store->freeze( $id, $this->source( $post_id ) ) );
		self::assertNull( $store->read( $id ) );
	}

	/**
	 * Never add an input snapshot to a row that already contains message text.
	 *
	 * @return void
	 */
	public function test_existing_rendered_content_blocks_input_freeze(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		$tables  = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables->campaigns(),
			array( 'subject' => 'Already rendered' ),
			array( 'id' => $id )
		);
		// phpcs:enable
		$store = new CampaignContentSnapshotStore();
		self::assertFalse( $store->freeze( $id, $this->source( $post_id ) ) );
		self::assertNull( $store->read( $id ) );
	}

	/**
	 * Reject unknown schema fields, off-site links and unsafe template text.
	 *
	 * @return void
	 */
	public function test_invalid_snapshot_is_rejected_without_write(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		$store   = new CampaignContentSnapshotStore();
		foreach ( array( 'unknown', 'url', 'template', 'words', 'external_id' ) as $case ) {
			$input = $this->source( $post_id );
			switch ( $case ) {
				case 'unknown':
					$input['extra'] = 'unapproved';
					break;
				case 'url':
					$input['post_url'] = 'https://example.invalid/offsite';
					break;
				case 'template':
					$input['template_fields']['subject'] = '{{unsupported}}';
					break;
				case 'words':
					$input['excerpt_words'] = 501;
					break;
				case 'external_id':
					$input['template_id'] = 17;
					break;
			}
			try {
				$store->freeze( $id, $input );
				self::fail( 'Malformed campaign snapshot should be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::assertNull( $store->read( $id ) );
			}
		}
		$this->assert_no_recipient_rows();
	}

	/**
	 * A broken persisted document must never silently turn into live post data.
	 *
	 * @return void
	 */
	public function test_corrupt_snapshot_json_fails_closed(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		$tables  = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables->campaigns(),
			array( 'template_snapshot_json' => '{broken json' ),
			array( 'id' => $id )
		);
		// phpcs:enable
		$store = new CampaignContentSnapshotStore();
		$this->expectException( UnexpectedValueException::class );
		$store->read( $id );
	}

	/**
	 * An empty non-NULL snapshot is corruption, not an uninitialized shell.
	 *
	 * @return void
	 */
	public function test_empty_stored_snapshot_fails_closed(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		$tables  = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables->campaigns(),
			array( 'template_snapshot_json' => '' ),
			array( 'id' => $id )
		);
		// phpcs:enable
		$this->expectException( UnexpectedValueException::class );
		( new CampaignContentSnapshotStore() )->read( $id );
	}

	/**
	 * Reserve a building campaign without involving publication hooks.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function reserve( int $post_id ): int {
		return ( new CampaignRepository() )->create_initial_building(
			get_current_blog_id(),
			$post_id,
			gmdate( 'Y-m-d H:i:s' ),
			ContentMode::Excerpt
		);
	}

	/**
	 * Produce a canonical recipient-neutral input document.
	 *
	 * @param int $post_id Source post.
	 * @return array<string,mixed>
	 */
	private function source( int $post_id ): array {
		return array(
			'version'         => CampaignContentSnapshotStore::VERSION,
			'site_id'         => get_current_blog_id(),
			'post_id'         => $post_id,
			'template_id'     => 0,
			'site_name'       => get_bloginfo( 'name' ),
			'post_title'      => 'Original test title',
			'post_content'    => '<!-- wp:paragraph --><p>Original text.</p><!-- /wp:paragraph -->',
			'post_excerpt'    => 'Optional summary',
			'post_url'        => get_permalink( $post_id ),
			'content_mode'    => ContentMode::Excerpt->value,
			'more_enabled'    => true,
			'excerpt_words'   => 55,
			'template_fields' => ( new EmailTemplateSettings() )->defaults(),
		);
	}

	/**
	 * Ensure a snapshot operation never enumerates or populates recipients.
	 *
	 * @return void
	 */
	private function assert_no_recipient_rows(): void {
		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables->campaign_recipients() )
		);
		// phpcs:enable
		self::assertSame( 0, (int) $count );
	}
}

// EOF: tests/Integration/CampaignContentSnapshotStoreTest.php.
