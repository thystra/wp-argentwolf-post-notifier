<?php
/**
 * File: tests/Integration/SubscriberCsvImportTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\ExistingUserInvitations;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImporter;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportMailer;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportPage;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use InvalidArgumentException;
use WP_UnitTestCase;

final class SubscriberCsvImportTest extends WP_UnitTestCase {
	public function test_plugin_container_exposes_csv_import_page(): void {
		$page = Plugin::instance()->container()->get( SubscriberCsvImportPage::class );

		self::assertInstanceOf( SubscriberCsvImportPage::class, $page );
	}

	public function test_fresh_csv_row_remains_pending_and_sends_import_invitation(): void {
		$transport = new CsvImportRecordingMailTransport();
		$importer  = $this->importer( $transport );
		$result    = $importer->import(
			$this->csv_stream( "email,name\nperson@example.com,Example Person\n" )
		);

		self::assertSame( 1, $result->rows_total() );
		self::assertSame( 1, $result->submitted() );
		self::assertSame( 0, $result->skipped() );
		self::assertCount( 1, $transport->messages );
		self::assertStringContainsString(
			'invited this email address',
			$transport->messages[0]->body()
		);
		self::assertStringContainsString(
			'You are not subscribed',
			$transport->messages[0]->body()
		);
		self::assertStringNotContainsString( 'You requested', $transport->messages[0]->body() );

		$row = $this->subscriber( 'person@example.com' );
		self::assertIsArray( $row );
		self::assertSame( SubscriberStatus::Pending->value, $row['status'] );
		self::assertSame( SubscriberCsvImporter::SIGNUP_SOURCE, $row['signup_source'] );
		self::assertSame(
			'I consent to site-wide post notifications, including administrator-assigned lists.',
			$row['consent_text_snapshot']
		);
		self::assertNotEmpty( $row['confirmation_token_hash'] );
		self::assertNull( $row['confirmed_at_gmt'] );
	}

	public function test_imported_status_column_cannot_create_subscribed_row(): void {
		$transport = new CsvImportRecordingMailTransport();
		$importer  = $this->importer( $transport );
		$csv       = "email,display_name,status\nperson@example.com,Person,subscribed\n";

		$result = $importer->import( $this->csv_stream( $csv ) );
		$row    = $this->subscriber( 'person@example.com' );

		self::assertSame( 1, $result->submitted() );
		self::assertIsArray( $row );
		self::assertSame( SubscriberStatus::Pending->value, $row['status'] );
	}

	public function test_duplicate_invalid_existing_and_suppressed_rows_do_not_reactivate(): void {
		global $wpdb;

		$transport    = new CsvImportRecordingMailTransport();
		$importer     = $this->importer( $transport );
		$service      = $this->service();
		$unsubscribed = $service->request_confirmation(
			'unsubscribed@example.com',
			null,
			'I agree to receive email notifications when new posts are published.',
			'block'
		);
		$subscribed   = $service->request_confirmation(
			'subscribed@example.com',
			null,
			'I agree to receive email notifications when new posts are published.',
			'block'
		);
		self::assertNotNull( $unsubscribed->confirmation_token() );
		self::assertNotNull( $subscribed->confirmation_token() );

		$table = TableNames::from_database( $wpdb )->subscribers();
		$wpdb->update(
			$table,
			array( 'status' => SubscriberStatus::Unsubscribed->value ),
			array( 'email' => 'unsubscribed@example.com' ),
			array( '%s' ),
			array( '%s' )
		);
		$wpdb->update(
			$table,
			array( 'status' => SubscriberStatus::Subscribed->value ),
			array( 'email' => 'subscribed@example.com' ),
			array( '%s' ),
			array( '%s' )
		);

		$suppression = new SuppressionService(
			new SuppressionRepository( $wpdb ),
			new EmailIdentity()
		);
		$suppression->suppress(
			'suppressed@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN
		);

		$csv    = "email\nnew@example.com\nNEW@example.com\nnot-an-email\n"
			. "unsubscribed@example.com\nsubscribed@example.com\nsuppressed@example.com\n";
		$result = $importer->import( $this->csv_stream( $csv ) );

		self::assertSame( 6, $result->rows_total() );
		self::assertSame( 1, $result->submitted() );
		self::assertSame( 3, $result->skipped() );
		self::assertSame( 1, $result->invalid() );
		self::assertSame( 1, $result->duplicates() );
		self::assertSame( 0, $result->mail_failures() );
		self::assertCount( 1, $transport->messages );
		self::assertSame(
			SubscriberStatus::Unsubscribed->value,
			$this->subscriber( 'unsubscribed@example.com' )['status']
		);
		self::assertSame(
			SubscriberStatus::Subscribed->value,
			$this->subscriber( 'subscribed@example.com' )['status']
		);
		self::assertNull( $this->subscriber( 'suppressed@example.com' ) );
	}

	public function test_row_limit_rejects_before_any_mail_or_subscriber_write(): void {
		$transport = new CsvImportRecordingMailTransport();
		$importer  = $this->importer( $transport );
		$csv       = "email\n";
		for ( $i = 0; $i <= SubscriberCsvImporter::MAX_ROWS; ++$i ) {
			$csv .= sprintf( "person-%d@example.com\n", $i );
		}

		try {
			$importer->import( $this->csv_stream( $csv ) );
			self::fail( 'Expected the CSV row limit to reject the import.' );
		} catch ( InvalidArgumentException ) {
			self::assertCount( 0, $transport->messages );
			self::assertNull( $this->subscriber( 'person-0@example.com' ) );
		}
	}

	public function test_mail_submission_failure_is_counted_while_row_stays_pending(): void {
		$transport = new CsvImportRecordingMailTransport( false );
		$importer  = $this->importer( $transport );
		$result    = $importer->import(
			$this->csv_stream( "email\nfailed@example.com\n" )
		);

		self::assertSame( 0, $result->submitted() );
		self::assertSame( 1, $result->mail_failures() );
		self::assertSame(
			SubscriberStatus::Pending->value,
			$this->subscriber( 'failed@example.com' )['status']
		);
	}

	public function test_manual_add_sends_double_opt_in_invitation_and_joins_list(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$transport = new CsvImportRecordingMailTransport();
		$lists     = new NamedListRepository();
		$list_id   = $lists->create( 'Manual', '' );
		$page      = new SubscriberCsvImportPage( $this->importer( $transport ), $lists );

		self::assertSame(
			array(
				'arpn_add'    => SubscriberCsvImporter::INVITE_SENT,
				'arpn_listed' => '1',
			),
			$page->add_subscriber( ' Manual@Example.com ', 'Manual Person', $list_id )
		);
		self::assertCount( 1, $transport->messages );
		self::assertSame( 'manual@example.com', $transport->messages[0]->recipient() );
		self::assertStringContainsString( 'https://example.test/confirm/', $transport->messages[0]->body() );
		$row = $this->subscriber( 'manual@example.com' );
		self::assertSame( SubscriberStatus::Pending->value, $row['status'] );
		self::assertNull( $row['confirmed_at_gmt'] );
		self::assertSame( SubscriberCsvImporter::MANUAL_SOURCE, $row['signup_source'] );
		self::assertCount( 1, $lists->members( $list_id ) );

		// Resend cooldown: an immediate repeat sends nothing and says so generically.
		self::assertSame(
			array( 'arpn_add' => SubscriberCsvImporter::INVITE_NOT_SENT ),
			$page->add_subscriber( 'manual@example.com', '', 0 )
		);
		self::assertCount( 1, $transport->messages );
	}

	public function test_manual_add_rejects_invalid_and_suppressed_addresses_generically(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$transport = new CsvImportRecordingMailTransport();
		$lists     = new NamedListRepository();
		$list_id   = $lists->create( 'Blocked', '' );
		$page      = new SubscriberCsvImportPage( $this->importer( $transport ), $lists );
		global $wpdb;
		( new SuppressionService( new SuppressionRepository( $wpdb ), new EmailIdentity() ) )
			->suppress( 'blocked@example.com', 'unsubscribed', 'test' );

		self::assertSame(
			array( 'arpn_add' => SubscriberCsvImporter::INVITE_INVALID ),
			$page->add_subscriber( 'not-an-email', '', $list_id )
		);
		self::assertSame(
			array( 'arpn_add' => SubscriberCsvImporter::INVITE_NOT_SENT ),
			$page->add_subscriber( 'blocked@example.com', '', $list_id )
		);
		self::assertSame( array(), $transport->messages );
		self::assertNull( $this->subscriber( 'blocked@example.com' ) );
		self::assertSame( array(), $lists->members( $list_id ) );

		$failing = new SubscriberCsvImportPage(
			$this->importer( new CsvImportRecordingMailTransport( false ) ),
			$lists
		);
		self::assertSame(
			array( 'arpn_add' => SubscriberCsvImporter::INVITE_MAIL_FAILED ),
			$failing->add_subscriber( 'mailfail@example.com', '', 0 )
		);
		self::assertSame( SubscriberStatus::Pending->value, $this->subscriber( 'mailfail@example.com' )['status'] );
	}

	public function test_manual_add_is_registered_admin_only_and_list_requires_list_capability(): void {
		$page = Plugin::instance()->container()->get( SubscriberCsvImportPage::class );
		$hook = 'admin_post_' . SubscriberCsvImportPage::ADD_ACTION;
		self::assertNotFalse( has_action( $hook, array( $page, 'handle_add' ) ) );
		self::assertFalse( has_action( 'admin_post_nopriv_' . SubscriberCsvImportPage::ADD_ACTION ) );

		$role = 'arpn_subscribers_only';
		add_role(
			$role,
			'Subscribers only',
			array(
				'read'                                 => true,
				'manage_post_notification_subscribers' => true,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$transport = new CsvImportRecordingMailTransport();
		$lists     = new NamedListRepository();
		$list_id   = $lists->create( 'Restricted', '' );
		$result    = ( new SubscriberCsvImportPage( $this->importer( $transport ), $lists ) )
			->add_subscriber( 'nolist@example.com', '', $list_id );
		remove_role( $role );

		self::assertSame( array( 'arpn_add' => SubscriberCsvImporter::INVITE_SENT ), $result );
		self::assertSame( array(), $lists->members( $list_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$handler = static function (): void {
			throw new \RuntimeException( 'denied' );
		};
		add_filter( 'wp_die_handler', static fn () => $handler );
		$denied = '';
		try {
			$page->handle_add();
		} catch ( \RuntimeException $exception ) {
			$denied = $exception->getMessage();
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
		self::assertSame( 'denied', $denied );
	}

	public function test_existing_users_get_a_one_time_batched_double_opt_in_invitation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( ExistingUserInvitations::OPTION );
		$transport   = new CsvImportRecordingMailTransport();
		$lists       = new NamedListRepository();
		$list_id     = $lists->create( 'Members', '' );
		$preferences = new RegisteredUserPreferenceRepository();
		$invites     = new ExistingUserInvitations( $this->importer( $transport ), $preferences, $lists );

		$plain = array();
		for ( $i = 0; $i < ExistingUserInvitations::BATCH_SIZE + 1; ++$i ) {
			$plain[] = self::factory()->user->create( array( 'user_email' => "member{$i}@example.com" ) );
		}
		$subscribed = self::factory()->user->create( array( 'user_email' => 'already@example.com' ) );
		$preferences->set( $subscribed, RegisteredUserPreference::Subscribed );
		self::factory()->user->create( array( 'user_email' => 'suppressed-user@example.com' ) );
		( new SuppressionService( new SuppressionRepository(), new EmailIdentity() ) )
			->suppress( 'suppressed-user@example.com', 'unsubscribed', 'test' );
		$existing = count( get_users( array( 'role__not_in' => array( 'administrator' ) ) ) );

		// Activation offers once and never resets a finished run.
		ExistingUserInvitations::offer();
		self::assertSame( ExistingUserInvitations::STATUS_OFFERED, $invites->state()['status'] );
		ob_start();
		$invites->render_notice();
		self::assertStringContainsString( ExistingUserInvitations::PAGE_SLUG, (string) ob_get_clean() );

		self::assertTrue( $invites->start( $list_id ) );
		self::assertFalse( $invites->start( $list_id ), 'The run cannot be started twice.' );
		self::assertSame( $existing, $invites->state()['total'] );
		self::assertNotFalse( wp_next_scheduled( ExistingUserInvitations::HOOK ) );
		self::assertCount( 0, $transport->messages, 'Nothing is sent synchronously.' );

		wp_clear_scheduled_hook( ExistingUserInvitations::HOOK );
		$first = $invites->run_batch();
		self::assertSame( ExistingUserInvitations::BATCH_SIZE, $first['processed'] );
		self::assertSame( ExistingUserInvitations::STATUS_RUNNING, $first['status'] );
		self::assertNotFalse( wp_next_scheduled( ExistingUserInvitations::HOOK ), 'The next batch is scheduled.' );
		wp_clear_scheduled_hook( ExistingUserInvitations::HOOK );
		while ( ExistingUserInvitations::STATUS_RUNNING === $invites->run_batch()['status'] ) {
			wp_clear_scheduled_hook( ExistingUserInvitations::HOOK );
		}

		$state = $invites->state();
		self::assertSame( ExistingUserInvitations::STATUS_DONE, $state['status'] );
		self::assertSame( $existing, $state['processed'] );
		self::assertSame( $existing - 2, $state['sent'] );
		self::assertSame( 2, $state['skipped'] );
		$recipients = array_map( static fn ( MailMessage $m ): string => $m->recipient(), $transport->messages );
		self::assertNotContains( 'already@example.com', $recipients );
		self::assertNotContains( 'suppressed-user@example.com', $recipients );
		self::assertContains( 'member0@example.com', $recipients );
		self::assertSame( SubscriberStatus::Pending->value, $this->subscriber( 'member0@example.com' )['status'] );
		self::assertCount( $existing - 2, $lists->members( $list_id ) );

		// Done: no notice, no restart, and activation does not re-offer.
		ExistingUserInvitations::offer();
		self::assertSame( ExistingUserInvitations::STATUS_DONE, $invites->state()['status'] );
		self::assertFalse( $invites->start() );
		ob_start();
		$invites->render_notice();
		self::assertSame( '', (string) ob_get_clean() );
		unset( $plain );
	}

	public function test_existing_user_invitation_notice_is_dismissible(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( ExistingUserInvitations::OPTION );
		ExistingUserInvitations::offer();
		$invites = new ExistingUserInvitations(
			$this->importer( new CsvImportRecordingMailTransport() ),
			new RegisteredUserPreferenceRepository()
		);
		$invites->dismiss();
		ob_start();
		$invites->render_notice();
		self::assertSame( '', (string) ob_get_clean() );
		self::assertSame( ExistingUserInvitations::STATUS_OFFERED, $invites->state()['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		delete_option( ExistingUserInvitations::OPTION );
		ExistingUserInvitations::offer();
		ob_start();
		$invites->render_notice();
		self::assertSame( '', (string) ob_get_clean(), 'Only subscriber managers see the offer.' );
	}

	public function test_upgraded_site_without_stored_offer_shows_the_start_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// rc.1 -> rc.2 by file replacement: the activation hook never ran.
		delete_option( ExistingUserInvitations::OPTION );
		$invites = $this->invitations();

		self::assertSame( ExistingUserInvitations::STATUS_OFFERED, $invites->state()['status'] );
		ob_start();
		$invites->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Send invitations', $html );
		self::assertStringContainsString( 'would be invited', $html );
		self::assertStringNotContainsString( 'Invitations are being sent', $html );

		// The upgrade routine now records the offer as well.
		delete_option( ExistingUserInvitations::OPTION );
		update_option( 'argentwolf_post_notifier_version', '0.1.0-rc.1', false );
		( new UpgradeManager() )->maybe_upgrade();
		$stored = get_option( ExistingUserInvitations::OPTION );
		self::assertSame( ExistingUserInvitations::STATUS_OFFERED, $stored['status'] );
	}

	public function test_stuck_states_self_heal_without_breaking_the_once_only_rule(): void {
		$invites = $this->invitations();
		wp_clear_scheduled_hook( ExistingUserInvitations::HOOK );
		$stuck = array(
			array( 'status' => 'running' ),
			array(
				'status' => 'running',
				'total'  => 0,
			),
			array(
				'status'    => 'running',
				'total'     => 5,
				'processed' => 2,
				'skipped'   => 2,
			),
			array( 'status' => 'bogus' ),
		);
		foreach ( $stuck as $stored ) {
			update_option( ExistingUserInvitations::OPTION, $stored, false );
			self::assertSame(
				ExistingUserInvitations::STATUS_OFFERED,
				$invites->state()['status'],
				(string) wp_json_encode( $stored )
			);
		}

		// Invitations already went out: keep running and reschedule, never re-offer.
		update_option(
			ExistingUserInvitations::OPTION,
			array(
				'status'       => 'running',
				'total'        => 5,
				'processed'    => 3,
				'sent'         => 3,
				'last_user_id' => 9,
			),
			false
		);
		self::assertSame( ExistingUserInvitations::STATUS_RUNNING, $invites->state()['status'] );
		self::assertNotFalse( wp_next_scheduled( ExistingUserInvitations::HOOK ) );
		self::assertFalse( $invites->can_reset() );
		self::assertFalse( $invites->reset() );
		wp_clear_scheduled_hook( ExistingUserInvitations::HOOK );
	}

	public function test_zero_user_run_completes_immediately_and_can_be_reset(): void {
		delete_option( ExistingUserInvitations::OPTION );
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
			( new \WP_User( (int) $id ) )->set_role( 'administrator' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$invites = $this->invitations();
		self::assertSame( 0, $invites->audience_count() );

		self::assertTrue( $invites->start() );
		self::assertSame( ExistingUserInvitations::STATUS_DONE, $invites->state()['status'] );
		self::assertFalse( wp_next_scheduled( ExistingUserInvitations::HOOK ) );
		ob_start();
		$invites->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'no non-administrator users to invite', $html );
		self::assertStringContainsString( ExistingUserInvitations::RESET_ACTION, $html );

		self::assertTrue( $invites->reset() );
		self::assertSame( ExistingUserInvitations::STATUS_OFFERED, $invites->state()['status'] );
	}

	public function test_audience_counts_custom_role_and_role_less_members_and_is_not_cached(): void {
		$invites = $this->invitations();
		$before  = $invites->audience_count();
		add_role( 'awpn_member', 'Member', array( 'read' => true ) );
		self::factory()->user->create( array( 'role' => 'awpn_member' ) );
		$roleless = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		( new \WP_User( $roleless ) )->set_role( '' );
		self::factory()->user->create( array( 'role' => 'administrator' ) );

		// A persistent object cache must not serve a stale audience.
		wp_cache_set( 'awpn_probe', 'primed', 'users' );
		self::assertSame( $before + 2, $invites->audience_count() );
		remove_role( 'awpn_member' );
	}

	/**
	 * Invitation service with a recording transport.
	 *
	 * @return ExistingUserInvitations
	 */
	private function invitations(): ExistingUserInvitations {
		return new ExistingUserInvitations(
			$this->importer( new CsvImportRecordingMailTransport() ),
			new RegisteredUserPreferenceRepository()
		);
	}

	private function importer( CsvImportRecordingMailTransport $transport ): SubscriberCsvImporter {
		return new SubscriberCsvImporter(
			$this->service(),
			new EmailIdentity(),
			new CsvImportStaticConfirmationLinkFactory(),
			new SubscriberCsvImportMailer( $transport, 'Example Site' )
		);
	}

	private function service(): SubscriberService {
		global $wpdb;

		$identity = new EmailIdentity();
		return new SubscriberService(
			new SubscriberRepository( $wpdb ),
			$identity,
			new SuppressionService( new SuppressionRepository( $wpdb ), $identity )
		);
	}

	/**
	 * Open an in-memory CSV stream.
	 *
	 * @param string $csv CSV contents.
	 * @return resource
	 */
	private function csv_stream( string $csv ) {
		$stream = fopen( 'php://temp', 'w+b' );
		self::assertIsResource( $stream );
		fwrite( $stream, $csv );
		rewind( $stream );
		return $stream;
	}

	/**
	 * Find one subscriber row by email.
	 *
	 * @param string $email Email address.
	 * @return array<string,mixed>|null
	 */
	private function subscriber( string $email ): ?array {
		global $wpdb;

		$identity   = new EmailIdentity();
		$email_hash = $identity->hash( $email );
		self::assertNotNull( $email_hash );
		$table = TableNames::from_database( $wpdb )->subscribers();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email_hash = %s',
				$table,
				$email_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}

final class CsvImportRecordingMailTransport implements MailTransport {
	/** @var array<MailMessage> */
	public array $messages = array();

	public function __construct( private bool $submitted = true ) {
	}

	public function send( MailMessage $message ): DeliveryResult {
		$this->messages[] = $message;
		return $this->submitted
			? DeliveryResult::submitted()
			: DeliveryResult::failed();
	}
}

final class CsvImportStaticConfirmationLinkFactory implements ConfirmationLinkFactory {
	public function create( string $plaintext_token ): string {
		return 'https://example.test/confirm/' . rawurlencode( $plaintext_token );
	}
}

// EOF: tests/Integration/SubscriberCsvImportTest.php.
