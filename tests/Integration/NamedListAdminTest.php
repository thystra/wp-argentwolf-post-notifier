<?php
/**
 * File: tests/Integration/NamedListAdminTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NamedListAdminPage;
use ArgentWolf\PostNotifier\Admin\SubscriberAdminPage;
use ArgentWolf\PostNotifier\Admin\SubscriberAdminRepository;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

final class NamedListAdminTest extends WP_UnitTestCase {
	public function test_plugin_registers_authenticated_named_list_hooks(): void {
		$service = Plugin::instance()->container()->get( NamedListAdminPage::class );

		self::assertInstanceOf( NamedListAdminPage::class, $service );
		self::assertSame( 'manage_options', NamedListAdminPage::CAPABILITY );
		self::assertSame( SubscriberAdminPage::PAGE_SLUG, 'argentwolf-post-notifier' );
		self::assertNotFalse(
			has_action( 'admin_menu', array( $service, 'register_menu' ) )
		);
		foreach ( $this->actions() as $action => $method ) {
			self::assertNotFalse(
				has_action( 'admin_post_' . $action, array( $service, $method ) )
			);
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ) );
		}
	}

	public function test_repository_manages_typed_members_without_duplicate_rows(): void {
		$repository = $this->repository();
		$now        = $this->utc( '2026-09-26 14:00:00' );
		$user_id    = self::factory()->user->create(
			array(
				'user_email'   => 'member@example.com',
				'display_name' => 'Member User',
			)
		);
		$signup     = $this->subscriber_service()->request_confirmation(
			'subscriber@example.com',
			'Subscriber Person',
			'Consent',
			'block',
			null,
			$now
		);
		$list_id    = $repository->create( 'Editors', 'Editorial list', $user_id, $now );

		self::assertTrue(
			$repository->add_member(
				$list_id,
				NamedListMemberType::User,
				$user_id,
				$now
			)
		);
		self::assertFalse(
			$repository->add_member(
				$list_id,
				NamedListMemberType::User,
				$user_id,
				$now
			)
		);
		self::assertTrue(
			$repository->add_member(
				$list_id,
				NamedListMemberType::Subscriber,
				$signup->subscriber_id(),
				$now
			)
		);

		$list = $repository->find( $list_id );
		self::assertIsArray( $list );
		self::assertSame( 'Editors', $list['name'] );
		self::assertTrue(
			$repository->update( $list_id, 'Editors and Reviewers', 'Updated', $now )
		);

		$members = $repository->members( $list_id );
		self::assertCount( 2, $members );
		self::assertSame(
			array( 'user', 'subscriber' ),
			array_values( array_column( $members, 'member_type' ) )
		);
		self::assertSame(
			$user_id,
			$repository->resolve_member_id(
				NamedListMemberType::User,
				'member@example.com'
			)
		);
		self::assertSame(
			$signup->subscriber_id(),
			$repository->resolve_member_id(
				NamedListMemberType::Subscriber,
				'subscriber@example.com'
			)
		);

		$membership_id = (int) $members[0]['membership_id'];
		self::assertTrue( $repository->remove_member( $list_id, $membership_id ) );
		self::assertFalse( $repository->remove_member( $list_id, $membership_id ) );
		self::assertCount( 1, $repository->members( $list_id ) );
		self::assertTrue( $repository->delete( $list_id ) );
		self::assertNull( $repository->find( $list_id ) );
		self::assertSame( array(), $repository->members( $list_id ) );
	}

	public function test_same_email_can_have_distinct_typed_memberships(): void {
		$repository = $this->repository();
		$now        = $this->utc( '2026-09-26 15:00:00' );
		$user_id    = self::factory()->user->create(
			array( 'user_email' => 'shared@example.com' )
		);
		$signup     = $this->subscriber_service()->request_confirmation(
			'shared@example.com',
			null,
			'Consent',
			'block',
			null,
			$now
		);
		$list_id    = $repository->create( 'Shared identity', '', $user_id, $now );

		self::assertTrue(
			$repository->add_member( $list_id, NamedListMemberType::User, $user_id, $now )
		);
		self::assertTrue(
			$repository->add_member(
				$list_id,
				NamedListMemberType::Subscriber,
				$signup->subscriber_id(),
				$now
			)
		);

		$members = $repository->members( $list_id );
		self::assertCount( 2, $members );
		self::assertSame( 'shared@example.com', $members[0]['email'] );
		self::assertSame( 'shared@example.com', $members[1]['email'] );
		self::assertNotSame( $members[0]['member_key'], $members[1]['member_key'] );
	}

	public function test_list_membership_does_not_resubscribe_or_unsuppress(): void {
		global $wpdb;

		$repository = $this->repository();
		$identity   = new EmailIdentity();
		$suppression = new SuppressionService(
			new SuppressionRepository( $wpdb ),
			$identity
		);
		$service = new SubscriberService(
			new SubscriberRepository( $wpdb ),
			$identity,
			$suppression
		);
		$admin = new SubscriberAdminRepository( $wpdb );
		$now   = $this->utc( '2026-09-26 16:00:00' );
		$signup = $service->request_confirmation(
			'suppressed@example.com',
			null,
			'Consent',
			'block',
			null,
			$now
		);
		self::assertTrue(
			$service->confirm(
				(string) $signup->confirmation_token(),
				$now->modify( '+1 hour' )
			)
		);
		$suppression->suppress(
			'suppressed@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$now->modify( '+2 hours' )
		);
		self::assertTrue(
			$admin->suppress( $signup->subscriber_id(), $now->modify( '+2 hours' ) )
		);

		$list_id = $repository->create( 'Suppressed members', '', null, $now );
		self::assertTrue(
			$repository->add_member(
				$list_id,
				NamedListMemberType::Subscriber,
				$signup->subscriber_id(),
				$now
			)
		);
		self::assertTrue( $suppression->is_suppressed( 'suppressed@example.com' ) );

		$table = TableNames::from_database( $wpdb )->subscribers();
		$status = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE id = %d',
				$table,
				$signup->subscriber_id()
			)
		);
		self::assertSame( SubscriberStatus::Suppressed->value, $status );
	}

	/**
	 * Return authenticated admin-post actions and handler methods.
	 *
	 * @return array<string,string>
	 */
	private function actions(): array {
		return array(
			NamedListAdminPage::CREATE_ACTION => 'handle_create',
			NamedListAdminPage::UPDATE_ACTION => 'handle_update',
			NamedListAdminPage::DELETE_ACTION => 'handle_delete',
			NamedListAdminPage::ADD_ACTION    => 'handle_add_member',
			NamedListAdminPage::REMOVE_ACTION => 'handle_remove_member',
		);
	}

	private function repository(): NamedListRepository {
		global $wpdb;

		return new NamedListRepository( $wpdb );
	}

	private function subscriber_service(): SubscriberService {
		global $wpdb;

		return new SubscriberService(
			new SubscriberRepository( $wpdb ),
			new EmailIdentity()
		);
	}

	private function utc( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}

// EOF: tests/Integration/NamedListAdminTest.php.
