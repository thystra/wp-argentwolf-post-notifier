<?php
/**
 * Opt-in WordPress/SQL acceptance test for RC4 CSV-to-list consent lifecycle.
 *
 * Run ONLY on disposable local/staging WordPress with:
 * AWPN_RUN_ACCEPTANCE=1 wp eval-file tests/Integration/RC4ListCsvAcceptance.php
 *
 * Uses an in-memory mail transport: no actual invitation or campaign is sent.
 * Test data is deleted in finally, even after an assertion failure.
 *
 * @package ArgentWolf\PostNotifier
 */

use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportMailer;
use ArgentWolf\PostNotifier\Audit\AuditRepository;
use ArgentWolf\PostNotifier\Audit\AuditService;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImporter;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Recipient\AudienceResolutionRequest;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\WordPressConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\UnavailableVerificationProvider;

if (
	! defined( 'WP_CLI' ) || ! WP_CLI
	|| '1' !== getenv( 'AWPN_RUN_ACCEPTANCE' )
	|| ! in_array( wp_get_environment_type(), array( 'local', 'staging' ), true )
) {
	throw new RuntimeException(
		'Acceptance tests require an explicitly opted-in local/staging WP-CLI instance.'
	);
}

/**
 * Assert a test result.
 *
 * @param bool   $pass    Whether the condition passed.
 * @param string $message Assertion message without sensitive information.
 * @return void
 * @throws RuntimeException On failure.
 */
function awpn_rc4_accept( bool $pass, string $message ): void {
	if ( ! $pass ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Return one CSV stream with email/name headers.
 *
 * @param string $email Test address.
 * @return resource
 * @throws RuntimeException On stream failure.
 */
function awpn_rc4_csv( string $email ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Ephemeral in-memory test stream.
	$stream = fopen( 'php://temp', 'w+' );
	if ( false === $stream ) {
		throw new RuntimeException( 'Could not open acceptance CSV stream.' );
	}
	fputcsv( $stream, array( 'email', 'name' ), ',', '"', '' );
	fputcsv( $stream, array( $email, 'Acceptance Test' ), ',', '"', '' );
	rewind( $stream );
	return $stream;
}

/**
 * Recover a local confirmation token from one captured test invitation.
 *
 * @param MailMessage $message Captured invitation.
 * @return string
 * @throws RuntimeException If the token was not present.
 */
function awpn_rc4_token( MailMessage $message ): string {
	if ( 1 !== preg_match( '/[?&]token=([a-f0-9]{64})/', $message->body(), $matches ) ) {
		throw new RuntimeException( 'Captured invitation has no confirmation token.' );
	}
	return $matches[1];
}

$database = $GLOBALS['wpdb'];
$tables   = TableNames::from_database( $database );
$identity = new EmailIdentity();
$lists    = new NamedListRepository( $database );
$subs     = new SubscriberRepository( $database );
$suppress = new SuppressionService( new SuppressionRepository( $database ), $identity );
$service  = new SubscriberService( $subs, $identity, $suppress );
$mail     = new class() implements MailTransport {
	/** @var array<int,MailMessage> Captured messages. */
	public array $messages = array();

	/**
	 * Capture one message without network/email delivery.
	 *
	 * @param MailMessage $message Candidate invitation.
	 * @return DeliveryResult
	 */
	public function send( MailMessage $message ): DeliveryResult {
		$this->messages[] = $message;
		return DeliveryResult::submitted();
	}
};
$importer = new SubscriberCsvImporter(
	$service,
	$identity,
	new WordPressConfirmationLinkFactory(),
	new SubscriberCsvImportMailer( $mail, 'Acceptance Test' ),
	$lists,
	new AuditService( new AuditRepository( $database ) )
);
$resolver = new AudienceResolver(
	$lists,
	new RegisteredUserPreferenceRepository(),
	new RegisteredUserEligibility( new UnavailableVerificationProvider() ),
	$subs,
	$suppress,
	$identity
);

$addresses = array();
$list_ids  = array();
try {
	$unique      = bin2hex( random_bytes( 8 ) );
	$email       = 'awpn-' . $unique . '@example.net';
	$addresses[] = $email;
	foreach ( array( 'A', 'B', 'C' ) as $suffix ) {
		$list_ids[] = $lists->create( 'AWPN acceptance ' . $unique . ' ' . $suffix, '', null );
	}
	// Import into A and B, then import same identity into C within cooldown.
	$stream = awpn_rc4_csv( $email );
	$first  = $importer->import( $stream, array( $list_ids[0], $list_ids[1] ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory test stream.
	fclose( $stream );
	awpn_rc4_accept( 1 === $first->submitted(), 'Initial invitation was not submitted.' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Disposable test-owned audit assertion.
	$provenance = $database->get_var(
		$database->prepare(
			'SELECT COUNT(*) FROM %i WHERE subject_type = %s AND subject_id = %d
			AND reason = %s AND source = %s',
			$tables->audit_events(),
			'list',
			$list_ids[0],
			$first->batch_id(),
			'csv_import'
		)
	);
	awpn_rc4_accept( 1 === (int) $provenance, 'CSV membership provenance was not recorded.' );
	$stream = awpn_rc4_csv( strtoupper( $email ) );
	$second = $importer->import( $stream, array( $list_ids[2] ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory test stream.
	fclose( $stream );
	awpn_rc4_accept( 0 === $second->submitted(), 'Duplicate invitation was issued.' );
	awpn_rc4_accept( 1 === count( $mail->messages ), 'There must be one initial invitation.' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Disposable test-owned registry assertion.
	$row = $database->get_row(
		$database->prepare(
			'SELECT id, status FROM %i WHERE email_hash = %s',
			$tables->subscribers(),
			$identity->hash( $email )
		),
		ARRAY_A
	);
	awpn_rc4_accept(
		is_array( $row ) && 'pending' === $row['status'],
		'Central consent must remain pending.'
	);
	foreach ( $list_ids as $list_id ) {
		awpn_rc4_accept(
			1 === count( $lists->members( $list_id ) ),
			'Pending membership was not saved.'
		);
	}

	// Removing B while pending must remain removed after confirmation.
	$member_b = $lists->members( $list_ids[1] )[0];
	$lists->remove_member( $list_ids[1], (int) $member_b['membership_id'] );
	$management_token = $service->confirm_with_management( awpn_rc4_token( $mail->messages[0] ) );
	awpn_rc4_accept( is_string( $management_token ), 'Central confirmation was rejected.' );
	awpn_rc4_accept(
		0 === count( $lists->members( $list_ids[1] ) ),
		'Removed pending membership was restored.'
	);

	$audience   = new AudienceResolutionRequest(
		array(),
		array( $list_ids[0], $list_ids[1], $list_ids[2] ),
		array(),
		array(),
		array(),
		array(),
		false,
		true
	);
	$recipients = array_filter(
		$resolver->resolve( $audience )->recipients(),
		static fn ( $recipient ) => $email === $recipient->email()
	);
	awpn_rc4_accept(
		1 === count( $recipients ),
		'Overlapping list and global audiences must deduplicate.'
	);
	$duplicated = $lists->duplicate( $list_ids[0], 0 );
	$list_ids[] = $duplicated;
	awpn_rc4_accept(
		1 === count( $lists->members( $duplicated ) ),
		'List duplication lost memberships.'
	);

	// A global unsubscribe suppresses every retained list assignment.
	awpn_rc4_accept(
		$service->unsubscribe_managed( $management_token ),
		'Global unsubscribe failed.'
	);
	$recipients = array_filter(
		$resolver->resolve( $audience )->recipients(),
		static fn ( $recipient ) => $email === $recipient->email()
	);
	awpn_rc4_accept( 0 === count( $recipients ), 'Unsubscribed recipient remains eligible.' );
	awpn_rc4_accept(
		1 === count( $lists->members( $duplicated ) ),
		'Unsubscribe erased membership history.'
	);
	echo "RC4_LIST_CSV_ACCEPTANCE=PASS\n";
} finally {
	// Delete only identities/lists generated within this invocation.
	foreach ( $list_ids as $list_id ) {
		$lists->delete( $list_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test-owned audit cleanup only.
		$database->delete(
			$tables->audit_events(),
			array(
				'subject_type' => 'list',
				'subject_id'   => $list_id,
			)
		);
	}
	foreach ( $addresses as $email ) {
		$hash = $identity->hash( $email );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Isolated acceptance-only cleanup.
		$database->delete( $tables->subscribers(), array( 'email_hash' => $hash ) );
		$database->delete( $tables->suppressions(), array( 'email_hash' => $hash ) );
		$database->delete( $tables->audit_events(), array( 'email_hash' => $hash ) );
		// phpcs:enable
	}
}
