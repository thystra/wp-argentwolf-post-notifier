<?php
/**
 * File: tests/Integration/PendingSubscriberCleanupTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\DataCleanup;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\PendingSubscriberCleanup;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

final class PendingSubscriberCleanupTest extends WP_UnitTestCase {
	public function tear_down(): void {
		PendingSubscriberCleanup::unschedule();
		parent::tear_down();
	}

	public function test_plugin_registers_cleanup_callback(): void {
		$cleanup = Plugin::instance()->container()->get( PendingSubscriberCleanup::class );

		self::assertInstanceOf( PendingSubscriberCleanup::class, $cleanup );
		self::assertNotFalse(
			has_action( PendingSubscriberCleanup::HOOK, array( $cleanup, 'run' ) )
		);
	}

	public function test_schedule_is_daily_and_idempotent(): void {
		PendingSubscriberCleanup::unschedule();
		PendingSubscriberCleanup::schedule();

		$first = wp_get_scheduled_event( PendingSubscriberCleanup::HOOK );
		self::assertNotFalse( $first );
		self::assertSame( PendingSubscriberCleanup::RECURRENCE, $first->schedule );

		PendingSubscriberCleanup::schedule();
		$second = wp_get_scheduled_event( PendingSubscriberCleanup::HOOK );

		self::assertNotFalse( $second );
		self::assertSame( $first->timestamp, $second->timestamp );
	}

	public function test_run_deletes_only_pending_records_past_retention_grace(): void {
		global $wpdb;

		$old_pending = $this->insert_subscriber( 'pending', '2026-09-18 11:59:59' );
		$boundary    = $this->insert_subscriber( 'pending', '2026-09-19 12:00:00' );
		$recent      = $this->insert_subscriber( 'pending', '2026-09-25 12:00:00' );
		$subscribed  = $this->insert_subscriber( 'subscribed', '2026-09-01 12:00:00' );
		$cleanup     = new PendingSubscriberCleanup( new DataCleanup( $wpdb ) );
		$now         = new DateTimeImmutable( '2026-09-26 12:00:00', new DateTimeZone( 'UTC' ) );

		self::assertSame( 1, $cleanup->run( $now ) );

		$table     = TableNames::from_database( $wpdb )->subscribers();
		$remaining = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id IN ( %d, %d, %d, %d ) ORDER BY id ASC',
					$table,
					$old_pending,
					$boundary,
					$recent,
					$subscribed
				)
			)
		);

		self::assertSame( array( $boundary, $recent, $subscribed ), $remaining );
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
				'created_at_gmt'              => '2026-09-01 00:00:00',
				'updated_at_gmt'              => '2026-09-01 00:00:00',
			)
		);

		return (int) $wpdb->insert_id;
	}
}

// EOF: tests/Integration/PendingSubscriberCleanupTest.php.
