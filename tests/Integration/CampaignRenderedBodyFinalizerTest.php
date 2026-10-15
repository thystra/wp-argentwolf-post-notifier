<?php
/**
 * File: tests/Integration/CampaignRenderedBodyFinalizerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignContentSnapshotStore;
use ArgentWolf\PostNotifier\Campaign\CampaignRenderedBodyFinalizer;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use UnexpectedValueException;
use WP_UnitTestCase;

/**
 * Freeze recipient-neutral campaign bodies without recipients or mail activity.
 */
final class CampaignRenderedBodyFinalizerTest extends WP_UnitTestCase {
	/**
	 * Isolate plugin campaign fixtures.
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
	 * Frozen content is identical to the canonical composer except for the footer.
	 *
	 * @return void
	 */
	public function test_first_write_uses_frozen_source_and_never_adds_preview_links(): void {
		$post_id                             = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id                                  = $this->reserve( $post_id );
		$input                               = $this->input( $post_id );
		$input['site_name']                  = 'Old site name';
		$input['post_content']               = '<!-- wp:paragraph --><p>Frozen article.</p><!-- /wp:paragraph -->';
		$input['template_fields']['subject'] = 'Frozen: {{site_name}} / {{post_title}}';
		$input['template_fields']['before']  = 'Original introduction';
		$store                               = new CampaignContentSnapshotStore();
		self::assertTrue( $store->freeze( $id, $input ) );

		$composer  = new EmailTemplateComposer(
			new EmailContentRenderer( new EmailContentSelector() )
		);
		$expected  = $composer->compose_campaign_body( $input );
		$finalizer = new CampaignRenderedBodyFinalizer();
		self::assertTrue( $finalizer->finalize( $id ) );
		$row = $this->campaign( $post_id );
		self::assertNotNull( $row );
		self::assertSame( $expected['subject'], $row['subject'] );
		self::assertSame( $expected['html'], $row['html_body'] );
		self::assertSame( $expected['text'], $row['text_body'] );
		self::assertSame( 'Frozen: Old site name / Frozen title', $row['subject'] );
		self::assertSame( CampaignRepository::STATUS_BUILDING, $row['status'] );
		self::assertStringNotContainsString( 'awpn_preview_only', $row['html_body'] );
		self::assertStringNotContainsString( 'Unsubscribe', $row['html_body'] );
		self::assertStringNotContainsString( 'Manage notifications', $row['text_body'] );
		self::assertSame( $input, $store->read( $id ) );
		$this->assert_no_recipients();

		// Modifying everything live cannot rewrite either frozen message body.
		update_option( 'blogname', 'New site name' );
		$settings           = new EmailTemplateSettings();
		$changed            = $settings->defaults();
		$changed['subject'] = 'Changed subject';
		$settings->save( $changed );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited title',
			)
		);
		self::assertFalse( $finalizer->finalize( $id ) );
		self::assertSame( $row['subject'], $this->campaign( $post_id )['subject'] );
		self::assertSame( $row['html_body'], $this->campaign( $post_id )['html_body'] );
		self::assertSame( $row['text_body'], $this->campaign( $post_id )['text_body'] );
		$this->assert_no_recipients();
	}

	/**
	 * Preview keeps its mandatory footer, but the new path must omit it.
	 *
	 * @return void
	 */
	public function test_existing_preview_composer_still_includes_real_footer_arguments(): void {
		$composer = new EmailTemplateComposer(
			new EmailContentRenderer( new EmailContentSelector() )
		);
		$post     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$input    = $this->input( $post );
		$preview  = $composer->compose(
			$input['post_content'],
			$input['post_title'],
			$input['post_url'],
			home_url( '/?test_unsubscribe=1' ),
			home_url( '/?test_manage=1' ),
			$input['post_excerpt'],
			$input['content_mode'],
			$input['more_enabled'],
			$input['excerpt_words'],
			$input['template_fields']
		);
		$body     = $composer->compose_campaign_body( $input );
		self::assertStringContainsString( 'test_unsubscribe=1', $preview['html'] );
		self::assertStringContainsString( 'test_manage=1', $preview['text'] );
		self::assertStringNotContainsString( 'test_unsubscribe=1', $body['html'] );
		self::assertStringNotContainsString( 'test_manage=1', $body['text'] );
		self::assertSame( $preview['subject'], $body['subject'] );
	}

	/**
	 * Absent or corrupted source must never fall back to edited live content.
	 *
	 * @return void
	 */
	public function test_missing_input_returns_false_and_corruption_fails_closed(): void {
		global $wpdb;
		$post_id   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id        = $this->reserve( $post_id );
		$finalizer = new CampaignRenderedBodyFinalizer();
		self::assertFalse( $finalizer->finalize( $id ) );
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables->campaigns(),
			array( 'template_snapshot_json' => '{broken' ),
			array( 'id' => $id )
		);
		// phpcs:enable
		$this->expectException( UnexpectedValueException::class );
		$finalizer->finalize( $id );
	}

	/**
	 * A non-building or partially rendered campaign cannot be overwritten.
	 *
	 * @return void
	 */
	public function test_nonbuilding_and_existing_bodies_are_not_overwritten(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id      = $this->reserve( $post_id );
		self::assertTrue(
			( new CampaignContentSnapshotStore() )->freeze( $id, $this->input( $post_id ) )
		);
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $tables->campaigns(), array( 'status' => 'queued' ), array( 'id' => $id ) );
		// phpcs:enable
		$finalizer = new CampaignRenderedBodyFinalizer();
		self::assertFalse( $finalizer->finalize( $id ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables->campaigns(),
			array(
				'status'  => 'building',
				'subject' => 'Existing subject',
			),
			array( 'id' => $id )
		);
		// phpcs:enable
		self::assertFalse( $finalizer->finalize( $id ) );
		$row = $this->campaign( $post_id );
		self::assertSame( 'Existing subject', $row['subject'] );
		self::assertSame( '', $row['html_body'] );
		self::assertSame( '', $row['text_body'] );
		$this->assert_no_recipients();
	}

	/**
	 * Reserve a draft-post shell without a publication hook.
	 *
	 * @param int $post_id Source post ID.
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
	 * Build a valid, recipient-neutral snapshot for a draft post.
	 *
	 * @param int $post_id Source post ID.
	 * @return array<string,mixed>
	 */
	private function input( int $post_id ): array {
		return array(
			'version'         => CampaignContentSnapshotStore::VERSION,
			'site_id'         => get_current_blog_id(),
			'post_id'         => $post_id,
			'template_id'     => 0,
			'site_name'       => get_bloginfo( 'name' ),
			'post_title'      => 'Frozen title',
			'post_content'    => 'Frozen content.',
			'post_excerpt'    => 'Frozen manual excerpt.',
			'post_url'        => get_permalink( $post_id ),
			'content_mode'    => ContentMode::Excerpt->value,
			'more_enabled'    => true,
			'excerpt_words'   => 55,
			'template_fields' => ( new EmailTemplateSettings() )->defaults(),
		);
	}

	/**
	 * Lookup campaign state for a post.
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
	 * Verify no recipients were populated by body finalization.
	 *
	 * @return void
	 */
	private function assert_no_recipients(): void {
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

// EOF: tests/Integration/CampaignRenderedBodyFinalizerTest.php.
