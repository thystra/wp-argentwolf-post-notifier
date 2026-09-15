<?php
/**
 * File: tests/Integration/DataCleanupTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\DataCleanup;
use ArgentWolf\PostNotifier\Database\TableNames;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WP_UnitTestCase;

final class DataCleanupTest extends WP_UnitTestCase {
	public function test_pending_subscriber_cleanup_is_bounded_and_status_safe(): void {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->subscribers();
		$old   = '2026-01-01 00:00:00';
		$new   = '2027-01-01 00:00:00';

		$ids = array(
			$this->insert_subscriber( 'pending', $old ),
			$this->insert_subscriber( 'pending', $old ),
			$this->insert_subscriber( 'pending', $old ),
			$this->insert_subscriber( 'pending', $new ),
			$this->insert_subscriber( 'subscribed', $old ),
		);

		$cleanup = new DataCleanup( $wpdb );
		$cutoff  = new DateTimeImmutable( '2026-06-01 00:00:00', new DateTimeZone( 'UTC' ) );

		self::assertSame( 2, $cleanup->delete_expired_pending_subscribers( $cutoff, 2 ) );
		self::assertSame( 1, $cleanup->delete_expired_pending_subscribers( $cutoff, 2 ) );
		self::assertSame( 0, $cleanup->delete_expired_pending_subscribers( $cutoff, 2 ) );

		$remaining = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id IN ( %d, %d, %d, %d, %d ) ORDER BY id ASC',
					$table,
					...$ids
				)
			)
		);

		self::assertSame( array( $ids[3], $ids[4] ), $remaining );
	}

	public function test_click_cleanup_is_bounded_by_cutoff(): void {
		global $wpdb;

		$tables      = TableNames::from_database( $wpdb );
		$recipient_id = $this->insert_campaign_recipient( '2026-01-01 00:00:00' );
		$clicks       = $tables->clicks();
		$old_ids      = array(
			$this->insert_click( $recipient_id, '2026-01-01 00:00:00' ),
			$this->insert_click( $recipient_id, '2026-01-02 00:00:00' ),
			$this->insert_click( $recipient_id, '2026-01-03 00:00:00' ),
		);
		$new_id       = $this->insert_click( $recipient_id, '2027-01-01 00:00:00' );
		$cleanup      = new DataCleanup( $wpdb );
		$cutoff       = new DateTimeImmutable( '2026-06-01 00:00:00', new DateTimeZone( 'UTC' ) );

		self::assertSame( 2, $cleanup->delete_click_events_before( $cutoff, 2 ) );
		self::assertSame( 1, $cleanup->delete_click_events_before( $cutoff, 2 ) );

		$remaining = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id IN ( %d, %d, %d, %d ) ORDER BY id ASC',
					$clicks,
					$old_ids[0],
					$old_ids[1],
					$old_ids[2],
					$new_id
				)
			)
		);

		self::assertSame( array( $new_id ), $remaining );
	}

	public function test_completed_recipient_redaction_removes_correlatable_identity_in_batches(): void {
		global $wpdb;

		$tables       = TableNames::from_database( $wpdb );
		$old_campaign = $this->insert_campaign( '2026-01-01 00:00:00' );
		$new_campaign = $this->insert_campaign( '2027-01-01 00:00:00' );
		$old_ids      = array(
			$this->insert_recipient_for_campaign( $old_campaign ),
			$this->insert_recipient_for_campaign( $old_campaign ),
		);
		$new_id       = $this->insert_recipient_for_campaign( $new_campaign );
		$cleanup      = new DataCleanup( $wpdb );
		$cutoff       = new DateTimeImmutable( '2026-06-01 00:00:00', new DateTimeZone( 'UTC' ) );

		self::assertSame( 1, $cleanup->redact_completed_campaign_recipients( $cutoff, 1 ) );
		self::assertSame( 1, $cleanup->redact_completed_campaign_recipients( $cutoff, 1 ) );
		self::assertSame( 0, $cleanup->redact_completed_campaign_recipients( $cutoff, 1 ) );

		foreach ( $old_ids as $old_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d',
					$tables->campaign_recipients(),
					$old_id
				),
				ARRAY_A
			);

			self::assertNull( $row['user_id'] );
			self::assertNull( $row['subscriber_id'] );
			self::assertNull( $row['email_snapshot'] );
			self::assertNull( $row['email_hash'] );
			self::assertNull( $row['unsubscribe_token_hash'] );
			self::assertNull( $row['click_token_hash'] );
			self::assertNull( $row['display_name_snapshot'] );
			self::assertNotEmpty( $row['personal_data_erased_at_gmt'] );
		}

		$new_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$tables->campaign_recipients(),
				$new_id
			),
			ARRAY_A
		);
		self::assertNotNull( $new_row['email_hash'] );
		self::assertNull( $new_row['personal_data_erased_at_gmt'] );
	}

	public function test_cleanup_rejects_an_unbounded_batch_size(): void {
		global $wpdb;

		$cleanup = new DataCleanup( $wpdb );
		$cutoff  = new DateTimeImmutable( '2026-06-01 00:00:00', new DateTimeZone( 'UTC' ) );

		$this->expectException( InvalidArgumentException::class );
		$cleanup->delete_click_events_before( $cutoff, DataCleanup::MAX_BATCH_SIZE + 1 );
	}

	private function insert_subscriber( string $status, string $expires_at ): int {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->subscribers();
		$uuid  = wp_generate_uuid4();
		$email = $uuid . '@example.test';
		$wpdb->insert(
			$table,
			array(
				'uuid'                        => $uuid,
				'email'                       => $email,
				'email_hash'                  => hash( 'sha256', $email ),
				'status'                      => $status,
				'confirmation_expires_at_gmt' => $expires_at,
				'created_at_gmt'              => '2026-01-01 00:00:00',
				'updated_at_gmt'              => '2026-01-01 00:00:00',
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function insert_campaign( string $completed_at ): int {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->campaigns();
		$uuid  = wp_generate_uuid4();
		$wpdb->insert(
			$table,
			array(
				'uuid'             => $uuid,
				'campaign_key'     => 'cleanup:' . $uuid,
				'campaign_kind'    => 'post',
				'post_id'          => 1,
				'status'           => 'completed',
				'subject'          => 'Subject',
				'html_body'        => '<p>Body</p>',
				'text_body'        => 'Body',
				'content_mode'     => 'snapshot',
				'created_at_gmt'   => '2026-01-01 00:00:00',
				'completed_at_gmt' => $completed_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function insert_recipient_for_campaign( int $campaign_id ): int {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->campaign_recipients();
		$uuid  = wp_generate_uuid4();
		$email = $uuid . '@example.test';
		$wpdb->insert(
			$table,
			array(
				'campaign_id'          => $campaign_id,
				'recipient_uuid'       => $uuid,
				'recipient_type'       => 'user',
				'user_id'              => 123,
				'email_snapshot'        => $email,
				'email_hash'             => hash( 'sha256', $email ),
				'unsubscribe_token_hash' => hash( 'sha256', 'unsubscribe:' . $uuid ),
				'click_token_hash'       => hash( 'sha256', 'click:' . $uuid ),
				'display_name_snapshot' => 'Example Person',
				'status'                => 'submitted',
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function insert_campaign_recipient( string $completed_at ): int {
		return $this->insert_recipient_for_campaign( $this->insert_campaign( $completed_at ) );
	}

	private function insert_click( int $recipient_id, string $clicked_at ): int {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->clicks();
		$wpdb->insert(
			$table,
			array(
				'campaign_recipient_id' => $recipient_id,
				'clicked_at_gmt'         => $clicked_at,
				'destination_kind'       => 'post',
			)
		);

		return (int) $wpdb->insert_id;
	}
}

// EOF: tests/Integration/DataCleanupTest.php.
