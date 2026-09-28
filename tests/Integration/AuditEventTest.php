<?php
/**
 * File: tests/Integration/AuditEventTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Audit\AuditEventType;
use ArgentWolf\PostNotifier\Audit\AuditRepository;
use ArgentWolf\PostNotifier\Audit\AuditService;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\SchemaInspector;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use WP_UnitTestCase;

final class AuditEventTest extends WP_UnitTestCase {
	public function test_audit_table_is_privacy_conscious_and_indexed(): void {
		global $wpdb;

		$table     = TableNames::from_database( $wpdb )->audit_events();
		$inspector = new SchemaInspector( $wpdb );
		$columns   = $inspector->columns( $table );
		$indexes   = $inspector->indexes( $table );

		self::assertTrue( $inspector->table_exists( $table ) );
		self::assertArrayHasKey( 'email_hash', $columns );
		self::assertArrayNotHasKey( 'email', $columns );
		self::assertArrayNotHasKey( 'token', $columns );
		self::assertContains( 'event_type', $indexes );
		self::assertContains( 'actor_user_id', $indexes );
		self::assertContains( 'subject', $indexes );
		self::assertContains( 'email_hash', $indexes );
		self::assertContains( 'created_at_gmt', $indexes );
	}

	public function test_list_audit_events_store_only_structured_entity_identity(): void {
		global $wpdb;

		$repository = new AuditRepository( $wpdb );
		$audit      = new AuditService( $repository );
		$user_id    = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$audit->record_list_event( AuditEventType::ListCreated, 42 );
		$audit->record_list_event(
			AuditEventType::ListMemberAdded,
			42,
			'user',
			$user_id
		);

		$rows = $repository->recent( 2 );
		self::assertCount( 2, $rows );
		self::assertSame( 'list_member_added', $rows[0]['event_type'] );
		self::assertSame( (string) $user_id, (string) $rows[0]['actor_user_id'] );
		self::assertSame( 'list', $rows[0]['subject_type'] );
		self::assertSame( '42', (string) $rows[0]['subject_id'] );
		self::assertSame( 'user', $rows[0]['related_type'] );
		self::assertSame( (string) $user_id, (string) $rows[0]['related_id'] );
		self::assertNull( $rows[0]['email_hash'] );
	}

	public function test_suppression_set_and_removal_are_audited_by_hash_only(): void {
		global $wpdb;

		$identity   = new EmailIdentity();
		$audit_repo = new AuditRepository( $wpdb );
		$audit      = new AuditService( $audit_repo );
		$service    = new SuppressionService(
			new SuppressionRepository( $wpdb ),
			$identity,
			$audit
		);
		$email       = 'Audit.Person@example.com';
		$email_hash  = $identity->hash( $email );
		self::assertNotNull( $email_hash );

		$service->suppress(
			$email,
			SuppressionService::REASON_UNSUBSCRIBE,
			SuppressionService::SOURCE_SUBSCRIBER_MANAGE
		);
		self::assertTrue(
			$service->resubscribe(
				$email,
				SuppressionService::SOURCE_SUBSCRIBER_MANAGE
			)
		);

		$rows = $audit_repo->recent( 2 );
		self::assertCount( 2, $rows );
		self::assertSame( 'suppression_removed', $rows[0]['event_type'] );
		self::assertSame( $email_hash, $rows[0]['email_hash'] );
		self::assertSame( 'suppression_set', $rows[1]['event_type'] );
		self::assertSame( $email_hash, $rows[1]['email_hash'] );
		self::assertStringNotContainsString( 'audit.person', wp_json_encode( $rows ) );
	}
}

// EOF: tests/Integration/AuditEventTest.php.
