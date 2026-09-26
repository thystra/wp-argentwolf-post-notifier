<?php
/**
 * File: tests/Integration/SubscriberAdminTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\SubscriberAdminPage;
use ArgentWolf\PostNotifier\Admin\SubscriberAdminRepository;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvExporter;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

final class SubscriberAdminTest extends WP_UnitTestCase {
	public function test_plugin_registers_authenticated_subscriber_administration_hooks(): void {
		$service = Plugin::instance()->container()->get( SubscriberAdminPage::class );

		self::assertInstanceOf( SubscriberAdminPage::class, $service );
		self::assertSame( 'manage_options', SubscriberAdminPage::CAPABILITY );
		self::assertNotFalse(
			has_action( 'admin_menu', array( $service, 'register_menu' ) )
		);
		self::assertNotFalse(
			has_action(
				'admin_post_' . SubscriberAdminPage::SUPPRESS_ACTION,
				array( $service, 'handle_suppression' )
			)
		);
		self::assertNotFalse(
			has_action(
				'admin_post_' . SubscriberAdminPage::EXPORT_ACTION,
				array( $service, 'handle_export' )
			)
		);
		self::assertFalse(
			has_action( 'admin_post_nopriv_' . SubscriberAdminPage::SUPPRESS_ACTION )
		);
		self::assertFalse(
			has_action( 'admin_post_nopriv_' . SubscriberAdminPage::EXPORT_ACTION )
		);
	}

	public function test_repository_filters_and_searches_subscribers(): void {
		$service = $this->subscriber_service();
		$admin   = $this->admin_repository();
		$start   = $this->utc( '2026-09-26 10:00:00' );

		$alice = $service->request_confirmation(
			'alice@example.com',
			'Alice Example',
			'Alice consent',
			'block',
			11,
			$start
		);
		$bob   = $service->request_confirmation(
			'bob@example.com',
			'Bob Example',
			'Bob consent',
			'block',
			12,
			$start
		);
		self::assertTrue(
			$service->confirm(
				(string) $bob->confirmation_token(),
				$start->modify( '+1 hour' )
			)
		);

		$all = $admin->page( null, 'example.com', 1, 50 );
		self::assertSame( 2, $all['total'] );
		self::assertCount( 2, $all['rows'] );

		$subscribed = $admin->page( SubscriberStatus::Subscribed, 'Bob', 1, 50 );
		self::assertSame( 1, $subscribed['total'] );
		self::assertCount( 1, $subscribed['rows'] );
		self::assertSame( $bob->subscriber_id(), (int) $subscribed['rows'][0]['id'] );
		self::assertSame( 'bob@example.com', $subscribed['rows'][0]['email'] );

		$pending = $admin->page( SubscriberStatus::Pending, 'Alice', 1, 50 );
		self::assertSame( 1, $pending['total'] );
		self::assertSame( $alice->subscriber_id(), (int) $pending['rows'][0]['id'] );
	}

	public function test_manual_suppression_is_one_way_and_clears_bearer_hashes(): void {
		global $wpdb;

		$service = $this->subscriber_service();
		$admin   = $this->admin_repository();
		$start   = $this->utc( '2026-09-26 10:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$start
		);

		self::assertTrue(
			$admin->suppress(
				$signup->subscriber_id(),
				$start->modify( '+2 hours' )
			)
		);
		self::assertFalse(
			$admin->suppress(
				$signup->subscriber_id(),
				$start->modify( '+3 hours' )
			)
		);

		$table = TableNames::from_database( $wpdb )->subscribers();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, confirmation_token_hash, confirmation_expires_at_gmt,
					manage_token_hash, updated_at_gmt
				FROM %i WHERE id = %d',
				$table,
				$signup->subscriber_id()
			),
			ARRAY_A
		);

		self::assertIsArray( $row );
		self::assertSame( SubscriberStatus::Suppressed->value, $row['status'] );
		self::assertNull( $row['confirmation_token_hash'] );
		self::assertNull( $row['confirmation_expires_at_gmt'] );
		self::assertNull( $row['manage_token_hash'] );
		self::assertSame( '2026-09-26 12:00:00', $row['updated_at_gmt'] );
	}

	public function test_csv_export_obeys_filters_and_neutralizes_formula_cells(): void {
		$service  = $this->subscriber_service();
		$admin    = $this->admin_repository();
		$exporter = new SubscriberCsvExporter( $admin );
		$start    = $this->utc( '2026-09-26 10:00:00' );
		$signup   = $service->request_confirmation(
			'formula@example.com',
			'=SUM(1,1)',
			'Consent snapshot',
			'block',
			33,
			$start
		);
		$token_hash = hash( 'sha256', (string) $signup->confirmation_token() );

		$csv = implode(
			'',
			iterator_to_array(
				$exporter->lines( SubscriberStatus::Pending, 'formula@example.com' )
			)
		);
		$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );

		self::assertIsArray( $lines );
		self::assertCount( 2, $lines );
		$header = str_getcsv( $lines[0], ',', '"', '' );
		$row    = str_getcsv( $lines[1], ',', '"', '' );

		self::assertSame( 'email', $header[0] );
		self::assertSame( 'consent_text_snapshot', $header[6] );
		self::assertSame( 'formula@example.com', $row[0] );
		self::assertSame( "'=SUM(1,1)", $row[1] );
		self::assertSame( SubscriberStatus::Pending->value, $row[2] );
		self::assertSame( 'Consent snapshot', $row[6] );
		self::assertSame( 'block', $row[7] );
		self::assertSame( '33', $row[8] );
		self::assertStringNotContainsString( 'confirmation_token_hash', $csv );
		self::assertStringNotContainsString( $token_hash, $csv );
	}

	private function admin_repository(): SubscriberAdminRepository {
		global $wpdb;

		return new SubscriberAdminRepository( $wpdb );
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

// EOF: tests/Integration/SubscriberAdminTest.php.
