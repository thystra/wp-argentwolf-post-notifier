<?php
/**
 * File: tests/Integration/SubscriberCsvImportTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\SubscriberCsvImporter;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportMailer;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportPage;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Plugin;
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
			'I agree to receive email notifications when new posts are published.',
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

		$transport = new CsvImportRecordingMailTransport();
		$importer  = $this->importer( $transport );
		$service   = $this->service();
		$unsubscribed = $service->request_confirmation(
			'unsubscribed@example.com',
			null,
			'I agree to receive email notifications when new posts are published.',
			'block'
		);
		$subscribed = $service->request_confirmation(
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

		$csv = "email\nnew@example.com\nNEW@example.com\nnot-an-email\n"
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
