<?php
/**
 * Dependency-free project contract tests.
 *
 * File: tests/run.php
 */

$root     = dirname( __DIR__ );
$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

require_once $root . '/autoload.php';

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationToken;
use ArgentWolf\PostNotifier\Subscriber\ManageSubscriptionToken;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Support\Container;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use ArgentWolf\PostNotifier\Version;
use ArgentWolf\PostNotifier\Verification\ArgentWolfEmailVerificationProvider;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\VerificationProviderResolver;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;

$main   = file_get_contents( $root . '/argentwolf-post-notifier.php' );
$readme = file_get_contents( $root . '/readme.txt' );
$todo         = file_get_contents( $root . '/TODO.md' );
$architecture = file_get_contents( $root . '/ARCHITECTURE.md' );
$installer = file_get_contents( $root . '/bin/install-wp-tests.sh' );
$workflow_path = $root . '/.forgejo/workflows/ci.yml';
$workflow      = is_readable( $workflow_path )
	? file_get_contents( $workflow_path )
	: '';
$qualified_images_path = $root . '/ci/images/qualified-images.json';
$qualified_images      = is_readable( $qualified_images_path )
	? json_decode( (string) file_get_contents( $qualified_images_path ), true )
	: null;
$image_verifier_path = $root . '/ci/images/php/verify-image.sh';
$image_verifier      = is_readable( $image_verifier_path )
	? file_get_contents( $image_verifier_path )
	: '';
$build_script = file_get_contents( $root . '/build/build-plugin.sh' );
$package_manifest_script = file_get_contents( $root . '/tests/package-manifest.sh' );
$plugin_check_script = file_get_contents( $root . '/scripts/run-plugin-check.sh' );
$companion_installer = file_get_contents( $root . '/bin/install-verification-companion.sh' );
$package_manifest = json_decode( (string) file_get_contents( $root . '/package.json' ), true );
$package_lock = json_decode( (string) file_get_contents( $root . '/package-lock.json' ), true );
$schema_one_source = file_get_contents( $root . '/src/Database/Migrations/Schema1.php' );
$schema_one_digest_path = $root . '/tests/fixtures/schema-1.sha256';
$schema_one_digest      = is_readable( $schema_one_digest_path )
	? trim( (string) file_get_contents( $schema_one_digest_path ) )
	: '';
$activator_source = file_get_contents( $root . '/src/Lifecycle/Activator.php' );
$uninstall_source = file_get_contents( $root . '/uninstall.php' );
$cleanup_source = file_get_contents( $root . '/src/Database/DataCleanup.php' );
$pending_cleanup_source = file_get_contents(
	$root . '/src/Subscriber/PendingSubscriberCleanup.php'
);
$subscriber_admin_page_source = file_get_contents(
	$root . '/src/Admin/SubscriberAdminPage.php'
);
$subscriber_admin_repository_source = file_get_contents(
	$root . '/src/Admin/SubscriberAdminRepository.php'
);
$subscriber_csv_exporter_source = file_get_contents(
	$root . '/src/Admin/SubscriberCsvExporter.php'
);
$user_preference_profile_source = file_get_contents(
	$root . '/src/Admin/UserNotificationPreferenceProfile.php'
);
$registered_user_preference_source = file_get_contents(
	$root . '/src/Recipient/RegisteredUserPreference.php'
);
$registered_user_preference_repository_source = file_get_contents(
	$root . '/src/Recipient/RegisteredUserPreferenceRepository.php'
);
$suppression_repository_source = file_get_contents(
	$root . '/src/Suppression/SuppressionRepository.php'
);
$suppression_service_source = file_get_contents(
	$root . '/src/Suppression/SuppressionService.php'
);
$manage_controller_source = file_get_contents(
	$root . '/src/Subscriber/ManageSubscriptionController.php'
);
$subscriber_service_source = file_get_contents(
	$root . '/src/Subscriber/SubscriberService.php'
);
$deactivator_source = file_get_contents( $root . '/src/Lifecycle/Deactivator.php' );
$destructive_uninstaller_source = file_get_contents(
	$root . '/src/Database/DestructiveUninstaller.php'
);
$migrator_source = file_get_contents( $root . '/src/Database/SchemaMigrator.php' );

preg_match( '/^[\h]*\*[\h]+Version:[\h]*(\S+)[\h]*$/m', (string) $main, $header );
preg_match( '/^Stable tag:[\h]*(\S+)[\h]*$/m', (string) $readme, $stable );
preg_match( '/^[\h]*\*[\h]+Requires at least:[\h]*(\S+)[\h]*$/m', (string) $main, $wordpress );
preg_match( '/^[\h]*\*[\h]+Requires PHP:[\h]*(\S+)[\h]*$/m', (string) $main, $php );
preg_match( '/^Tested up to:[\h]*(\S+)[\h]*$/m', (string) $readme, $tested_up_to );

$assert( Version::PLUGIN === ( $header[1] ?? null ), 'Plugin header and Version::PLUGIN must match.' );
$assert( Version::PLUGIN === ( $stable[1] ?? null ), 'Plugin version and readme Stable Tag must match.' );
$assert( '7.0' === ( $wordpress[1] ?? null ), 'Requires at least must be WordPress 7.0.' );
$assert( '8.4' === ( $php[1] ?? null ), 'Requires PHP must be 8.4.' );
$assert( '7.1' === ( $tested_up_to[1] ?? null ), 'readme Tested up to must be WordPress 7.1.' );
$assert(
	str_contains(
		(string) $todo,
		"## Milestone 2 — Verification-provider contract\n\nTarget: `0.1.0-alpha.2`"
	),
	'Alpha.2 must remain the verification-provider contract milestone.'
);
$assert(
	str_contains(
		(string) $todo,
		"## Milestone 3 — Database schema and migrations\n\nTarget: `0.1.0-alpha.3`"
	),
	'Database schema work must remain outside the alpha.2 release boundary.'
);
$assert(
	str_contains(
		(string) $architecture,
		'The `0.1.0-alpha.2` implementation boundary ends at this provider contract'
	),
	'Architecture must state the alpha.2 implementation boundary explicitly.'
);
$assert(
	str_contains(
		(string) $todo,
		'Alpha versions are development milestones.'
	),
	'TODO must distinguish alpha checkpoints from public prerelease publication.'
);
$agents = file_get_contents( $root . '/AGENTS.md' );
$assert(
	str_contains(
		(string) $agents,
		'Completing an alpha milestone does **not** by itself authorize a Forgejo or'
	),
	'AGENTS must preserve the alpha-development/RC release lifecycle.'
);
$assert(
	str_contains(
		(string) $todo,
		"## Milestone 3 — Database schema and migrations\n\nTarget: `0.1.0-alpha.3`"
	),
	'Alpha.3 must remain the database schema and migrations milestone.'
);
$assert(
	str_contains(
		(string) $todo,
		"## Milestone 4 — Standalone subscribers and mailing-list block\n\nTarget: `0.1.0-alpha.4`"
	),
	'Alpha.4 must remain the standalone subscriber and mailing-list milestone.'
);
$assert(
	str_contains(
		(string) $todo,
		"## Milestone 5 — User preferences, named lists, and suppression\n\nTarget: `0.1.0-alpha.5`"
	),
	'Alpha.5 must remain the user preferences, named lists, and suppression milestone.'
);
$database_files = array(
	'src/Database/DataCleanup.php',
	'src/Database/DestructiveUninstaller.php',
	'src/Database/EmailIdentity.php',
	'src/Database/Migration.php',
	'src/Database/MigrationLock.php',
	'src/Database/Migrations/Schema1.php',
	'src/Database/SchemaInspector.php',
	'src/Database/SchemaMigrator.php',
	'src/Database/TableNames.php',
	'src/Database/UtcDateTime.php',
);
foreach ( $database_files as $database_file ) {
	$assert(
		is_readable( $root . '/' . $database_file ),
		sprintf( 'Database foundation file must exist: %s.', $database_file )
	);
}
$subscriber_files = array(
	'src/Admin/SubscriberAdminPage.php',
	'src/Admin/SubscriberAdminRepository.php',
	'src/Admin/SubscriberCsvExporter.php',
	'src/Mail/DeliveryResult.php',
	'src/Mail/MailMessage.php',
	'src/Mail/MailTransport.php',
	'src/Mail/WpMailTransport.php',
	'src/Subscriber/ConfirmationController.php',
	'src/Subscriber/ConfirmationLinkFactory.php',
	'src/Subscriber/ConfirmationMailer.php',
	'src/Subscriber/ConfirmationToken.php',
	'src/Subscriber/ManageSubscriptionController.php',
	'src/Subscriber/ManageSubscriptionLinkFactory.php',
	'src/Subscriber/ManageSubscriptionToken.php',
	'src/Subscriber/PublicSignupController.php',
	'src/Subscriber/PublicSignupProcessor.php',
	'src/Subscriber/PublicSignupRequest.php',
	'src/Subscriber/PublicSignupResponse.php',
	'src/Subscriber/RateLimitStore.php',
	'src/Subscriber/SignupFormContext.php',
	'src/Subscriber/SignupRateLimiter.php',
	'src/Subscriber/SignupResult.php',
	'src/Subscriber/SubscriberRepository.php',
	'src/Subscriber/SubscriberService.php',
	'src/Subscriber/SubscriberStatus.php',
	'src/Subscriber/SubscribeBlock.php',
	'src/Subscriber/WordPressConfirmationLinkFactory.php',
	'src/Subscriber/WordPressManageSubscriptionLinkFactory.php',
	'src/Subscriber/WordPressRateLimitStore.php',
	'src/Suppression/SuppressionRepository.php',
	'src/Suppression/SuppressionService.php',
);
foreach ( $subscriber_files as $subscriber_file ) {
	$assert(
		is_readable( $root . '/' . $subscriber_file ),
		sprintf( 'Subscriber foundation file must exist: %s.', $subscriber_file )
	);
}
$table_names = new TableNames( 'wp_' );
$assert( 7 === count( $table_names->all() ), 'Schema one must own exactly seven tables.' );
foreach ( $table_names->all() as $table_name ) {
	$assert(
		str_starts_with( $table_name, 'wp_argentwolf_pn_' ),
		'Table names must use the canonical argentwolf_pn_ prefix.'
	);
}
$assert(
	str_contains( (string) $schema_one_source, 'UNIQUE KEY campaign_key (campaign_key)' )
		&& str_contains( (string) $schema_one_source, 'UNIQUE KEY campaign_email (campaign_id,email_hash)' )
		&& str_contains( (string) $schema_one_source, 'UNIQUE KEY email_hash (email_hash)' )
		&& str_contains( (string) $schema_one_source, 'UNIQUE KEY confirmation_token_hash (confirmation_token_hash)' )
		&& str_contains( (string) $schema_one_source, 'UNIQUE KEY manage_token_hash (manage_token_hash)' )
		&& str_contains( (string) $schema_one_source, 'UNIQUE KEY unsubscribe_token_hash (unsubscribe_token_hash)' )
		&& str_contains( (string) $schema_one_source, 'UNIQUE KEY click_token_hash (click_token_hash)' ),
	'Schema one must retain campaign, campaign-recipient, and email uniqueness constraints.'
);
$assert(
	str_contains( (string) $schema_one_source, 'email_snapshot varchar(320) DEFAULT NULL' )
		&& str_contains( (string) $schema_one_source, 'email_hash char(64) DEFAULT NULL' )
		&& str_contains( (string) $schema_one_source, 'personal_data_erased_at_gmt datetime DEFAULT NULL' )
		&& str_contains( (string) $schema_one_source, 'KEY completed_at_gmt (completed_at_gmt)' ),
	'Schema one must support bounded recipient privacy redaction.'
);
$assert(
	1 === preg_match( '/^[a-f0-9]{64}$/', $schema_one_digest )
		&& hash( 'sha256', (string) $schema_one_source ) === $schema_one_digest,
	'Schema one is frozen upgrade history; create a new numbered migration instead of changing Schema1.'
);
$identity = new EmailIdentity( str_repeat( "\x31", 32 ) );
$assert(
	'person@example.com' === $identity->normalize( ' Person@Example.COM ' ),
	'Email normalization must be deterministic and case-normalized.'
);
$assert(
	$identity->hash( 'Person@Example.COM' ) === $identity->hash( 'person@example.com' ),
	'Email hashing must operate on the canonical normalized identity.'
);
$confirmation_token = ConfirmationToken::generate();
$assert(
	1 === preg_match( '/\A[a-f0-9]{64}\z/D', $confirmation_token->plaintext() )
		&& ConfirmationToken::hash_plaintext( $confirmation_token->plaintext() )
			=== $confirmation_token->hash()
		&& $confirmation_token->plaintext() !== $confirmation_token->hash(),
	'Confirmation tokens must be bearer values with a separate persistence hash.'
);
$assert(
	SubscriberStatus::Subscribed->is_notification_eligible()
		&& ! SubscriberStatus::Pending->is_notification_eligible()
		&& ! SubscriberStatus::Unsubscribed->accepts_confirmation_refresh()
		&& ! SubscriberStatus::Suppressed->accepts_confirmation_refresh(),
	'Subscriber lifecycle rules must fail closed outside subscribed/pending states.'
);
$rate_limiter_source = file_get_contents( $root . '/src/Subscriber/SignupRateLimiter.php' );
$signup_processor_source = file_get_contents( $root . '/src/Subscriber/PublicSignupProcessor.php' );
$mail_transport_source = file_get_contents( $root . '/src/Mail/WpMailTransport.php' );
$confirmation_controller_source = file_get_contents( $root . '/src/Subscriber/ConfirmationController.php' );
$public_signup_controller_source = file_get_contents( $root . '/src/Subscriber/PublicSignupController.php' );
$signup_form_context_source = file_get_contents( $root . '/src/Subscriber/SignupFormContext.php' );
$subscribe_block_source = file_get_contents( $root . '/src/Subscriber/SubscribeBlock.php' );
$subscribe_editor_source = file_get_contents( $root . '/assets/runtime/subscribe-editor.js' );
$subscribe_block_metadata = json_decode(
	(string) file_get_contents( $root . '/blocks/subscribe/block.json' ),
	true
);
$assert(
	false !== $rate_limiter_source
		&& str_contains( $rate_limiter_source, 'hash_hmac( \'sha256\', $packed' )
		&& ! str_contains( $rate_limiter_source, 'HTTP_USER_AGENT' ),
	'Public signup rate limits must key network indicators without retaining user-agent data.'
);
$assert(
	false !== $signup_processor_source
		&& str_contains( $signup_processor_source, 'new PublicSignupResponse()' )
		&& str_contains( $signup_processor_source, 'InvalidArgumentException' ),
	'Public signup coordination must preserve one generic response across expected request outcomes.'
);
$assert(
	false !== $mail_transport_source
		&& str_contains( $mail_transport_source, 'DeliveryResult::submitted()' )
		&& ! str_contains( $mail_transport_source, 'delivered' ),
	'Mail transport success must be modeled as submitted rather than delivered.'
);
$assert(
	false !== $confirmation_controller_source
		&& str_contains( $confirmation_controller_source, '\'admin_post_nopriv_\' . $get_action' )
		&& str_contains( $confirmation_controller_source, '\'admin_post_nopriv_\' . $post_action' )
		&& str_contains( $confirmation_controller_source, '\'POST\' !== $this->request_method()' ),
	'Confirmation routing must separate display-only GET from intentional POST confirmation.'
);
$assert(
	false !== $subscribe_block_source
		&& str_contains( $subscribe_block_source, "'argentwolf-post-notifier/subscribe'" )
		&& str_contains( $subscribe_block_source, 'register_block_type' )
		&& str_contains( $subscribe_block_source, 'PublicSignupController::POST_ACTION' ),
	'Subscribe block runtime must use the canonical dynamic block and public action.'
);
$assert(
	false !== $public_signup_controller_source
		&& str_contains( $public_signup_controller_source, 'wp_verify_nonce' )
		&& str_contains( $public_signup_controller_source, 'wp_validate_redirect' )
		&& str_contains( $public_signup_controller_source, 'PublicSignupRequest' )
		&& str_contains( $public_signup_controller_source, 'RESULT_RECEIVED' ),
	'Public signup POST routing must verify intent, constrain redirects, and use generic results.'
);
$assert(
	false !== $signup_form_context_source
		&& str_contains( $signup_form_context_source, 'hash_hmac' )
		&& str_contains( $signup_form_context_source, 'bin2hex' )
		&& str_contains( $signup_form_context_source, 'hex2bin' ),
	'Public signup render context must be locally signed without reversible shared secrets.'
);
$assert(
	false !== $subscribe_editor_source
		&& str_contains( $subscribe_editor_source, "registerBlockType( 'argentwolf-post-notifier/subscribe'" )
		&& str_contains( $subscribe_editor_source, 'consentText' )
		&& str_contains( $subscribe_editor_source, 'buttonLabel' ),
	'Subscribe editor asset must register the canonical configurable block.'
);
$assert(
	is_array( $subscribe_block_metadata )
		&& 'argentwolf-post-notifier/subscribe' === ( $subscribe_block_metadata['name'] ?? null )
		&& 3 === ( $subscribe_block_metadata['apiVersion'] ?? null )
		&& 'argentwolf-post-notifier-subscribe-editor'
			=== ( $subscribe_block_metadata['editorScript'] ?? null ),
	'Subscribe block metadata must declare the canonical dynamic block and editor handle.'
);
$assert(
	false !== $build_script
		&& str_contains( $build_script, "'assets/runtime'" )
		&& str_contains( $build_script, 'assets/runtime' )
		&& str_contains( $build_script, 'blocks/subscribe/block.json' ),
	'Distribution build must include the human-readable runtime block asset.'
);
$utc_probe = new DateTimeImmutable(
	'2026-09-15 08:30:00',
	new DateTimeZone( 'America/New_York' )
);
$assert(
	'2026-09-15 12:30:00' === UtcDateTime::format( $utc_probe ),
	'Database datetime persistence must use canonical UTC conversion.'
);
$assert(
	str_contains( (string) $activator_source, '( new SchemaMigrator() )->migrate();' ),
	'Activation must run versioned database migrations before recording the plugin version.'
);
$assert(
	str_contains( (string) $uninstall_source, 'DestructiveUninstaller::is_enabled()' )
		&& str_contains( (string) $uninstall_source, 'new DestructiveUninstaller()' )
		&& str_contains( (string) $destructive_uninstaller_source, 'TableNames::from_database' )
		&& str_contains( (string) $destructive_uninstaller_source, 'EmailIdentity::HASH_KEY_OPTION' ),
	'Destructive uninstall must be explicit, centralized, and remove all plugin-owned state.'
);
$assert(
	str_contains( (string) $cleanup_source, 'MAX_BATCH_SIZE = 500' )
		&& str_contains( (string) $cleanup_source, 'delete_expired_pending_subscribers' )
		&& str_contains( (string) $cleanup_source, 'delete_click_events_before' )
		&& str_contains( (string) $cleanup_source, 'redact_completed_campaign_recipients' ),
	'Data cleanup primitives must remain explicitly bounded and cover planned retention classes.'
);
$assert(
	str_contains( (string) $pending_cleanup_source, "EXPIRED_RETENTION = 'P7D'" )
		&& str_contains( (string) $pending_cleanup_source, 'BATCH_SIZE = 250' )
		&& str_contains( (string) $pending_cleanup_source, 'wp_next_scheduled' )
		&& str_contains( (string) $pending_cleanup_source, 'wp_schedule_event' )
		&& str_contains( (string) $pending_cleanup_source, 'delete_expired_pending_subscribers' )
		&& str_contains( (string) $activator_source, 'PendingSubscriberCleanup::schedule();' )
		&& str_contains( (string) $deactivator_source, 'PendingSubscriberCleanup::unschedule();' ),
	'Pending subscriber cleanup must use a daily limited schedule and clear it on deactivation.'
);
$assert(
	str_contains( (string) $subscriber_admin_page_source, "CAPABILITY = 'manage_options'" )
		&& str_contains( (string) $subscriber_admin_page_source, "add_action( 'admin_menu'" )
		&& str_contains( (string) $subscriber_admin_page_source, 'check_admin_referer' )
		&& str_contains( (string) $subscriber_admin_page_source, 'wp_safe_redirect' )
		&& str_contains( (string) $subscriber_admin_page_source, 'SOURCE_SUBSCRIBER_ADMIN' )
		&& str_contains( (string) $subscriber_admin_repository_source, 'email_for_id' ),
	'Subscriber administration must be authorized and create canonical global suppression.'
);
$assert(
	str_contains( (string) $subscriber_admin_repository_source, 'SubscriberStatus::Suppressed->value' )
		&& str_contains( (string) $subscriber_admin_repository_source, 'confirmation_token_hash = NULL' )
		&& str_contains( (string) $subscriber_admin_repository_source, 'manage_token_hash = NULL' ),
	'Manual subscriber suppression must clear reusable bearer hashes.'
);
$assert(
	str_contains( (string) $subscriber_csv_exporter_source, "private const BATCH_SIZE = 500" )
		&& str_contains( (string) $subscriber_csv_exporter_source, 'safe_cell' )
		&& ! str_contains( (string) $subscriber_csv_exporter_source, 'confirmation_token_hash' )
		&& ! str_contains( (string) $subscriber_csv_exporter_source, 'manage_token_hash' ),
	'Subscriber CSV export must use limited batches, neutralize formula cells, and omit bearer hashes.'
);
$assert(
	RegisteredUserPreference::SiteDefault->value === 'site_default'
		&& RegisteredUserPreference::Subscribed->value === 'subscribed'
		&& RegisteredUserPreference::Unsubscribed->value === 'unsubscribed'
		&& RegisteredUserPreference::SiteDefault
			=== RegisteredUserPreference::from_stored_value( 'unexpected' ),
	'Registered-user preference states must be typed and malformed metadata must use site_default.'
);
$assert(
	RegisteredUserPreferenceRepository::META_KEY
		=== '_argentwolf_post_notifier_subscription_preference'
		&& false !== $registered_user_preference_repository_source
		&& str_contains( $registered_user_preference_repository_source, 'get_user_meta' )
		&& str_contains( $registered_user_preference_repository_source, 'update_user_meta' ),
	'Registered-user preference persistence must use the canonical WordPress user-meta key.'
);
$assert(
	false !== $registered_user_preference_source
		&& false !== $user_preference_profile_source
		&& str_contains( $user_preference_profile_source, "add_action( 'show_user_profile'" )
		&& str_contains( $user_preference_profile_source, "add_action( 'personal_options_update'" )
		&& ! str_contains( $user_preference_profile_source, "add_action( 'edit_user_profile_update'" )
		&& str_contains( $user_preference_profile_source, 'wp_verify_nonce' )
		&& str_contains( $user_preference_profile_source, "current_user_can( 'edit_user', \$user_id )" ),
	'Registered-user profile preference must be self-service, authorized, and use only defined states.'
);
$manage_token = ManageSubscriptionToken::generate();
$assert(
	64 === strlen( $manage_token->plaintext() )
		&& hash( 'sha256', $manage_token->plaintext() )
			=== $manage_token->hash()
		&& null === ManageSubscriptionToken::hash_plaintext( 'invalid' ),
	'Management bearer tokens must be random 256-bit values stored only by hash.'
);
$assert(
	false !== $suppression_repository_source
		&& false !== $suppression_service_source
		&& str_contains( $suppression_repository_source, 'ON DUPLICATE KEY UPDATE' )
		&& str_contains( $suppression_service_source, 'SOURCE_SUBSCRIBER_ADMIN' )
		&& str_contains( $suppression_service_source, 'delete_if_source' ),
	'Global suppression must use the canonical table and source-limited resubscribe.'
);
$assert(
	false !== $manage_controller_source
		&& str_contains( $manage_controller_source, 'check_admin_referer' )
		&& str_contains( $manage_controller_source, 'Referrer-Policy: no-referrer' )
		&& str_contains( $manage_controller_source, "'GET' !== \$this->request_method()" )
		&& str_contains( $manage_controller_source, "'POST' !== \$this->request_method()" ),
	'Self-service management must keep GET display-only and require intentional POST.'
);
$assert(
	false !== $subscriber_service_source
		&& str_contains( $subscriber_service_source, 'is_suppressed' )
		&& str_contains( $subscriber_service_source, 'confirm_with_management' )
		&& str_contains( $subscriber_service_source, 'unsubscribe_managed' )
		&& str_contains( $subscriber_service_source, 'resubscribe_managed' ),
	'Standalone signup, confirmation, unsubscribe, and resubscribe must share suppression policy.'
);
$assert(
	SuppressionService::SOURCE_SUBSCRIBER_MANAGE === 'subscriber_manage'
		&& SuppressionService::SOURCE_SUBSCRIBER_ADMIN === 'subscriber_admin',
	'Suppression sources must remain stable for source-limited resubscription.'
);
$assert(
	str_contains( (string) $migrator_source, 'migration->verify()' )
		&& str_contains( (string) $migrator_source, 'current === $target' ),
	'Migration coordination must verify and repair recoverable current-schema drift.'
);
$editor_source = file_get_contents( $root . '/assets/src/editor.js' );
$assert(
	! str_contains( (string) $editor_source, 'SCAFFOLD_VERSION' ),
	'Editor scaffold must not maintain a duplicate plugin-version constant.'
);
$assert(
	str_contains(
		(string) $main,
		'Requires Plugins: argentwolf-email-verification'
	),
	'The approved companion must be declared through Requires Plugins.'
);
$assert(
	str_contains(
		(string) $main,
		'Plugin URI: https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier'
	),
	'Plugin URI must point to the authoritative Forgejo repository.'
);

$assert(
	! str_contains( (string) $installer, 'main "$@" || true' ),
	'WordPress test installation failures must not be suppressed.'
);
$assert(
	str_contains( (string) $installer, 'main "$@"' ),
	'WordPress test installer must return its real child-process status.'
);
$assert(
	is_readable( $workflow_path ),
	'Forgejo CI workflow must exist.'
);
$assert(
	str_contains( (string) $workflow, 'runs-on: forgejo-workstation' ),
	'Forgejo CI must target the forgejo-workstation runner label.'
);
$assert(
	str_contains(
		(string) $workflow,
		'test -r "${WP_TESTS_DIR}/includes/functions.php"'
	),
	'CI must verify the installed WordPress test-library path.'
);
$assert(
	is_array( $qualified_images )
		&& 1 === ( $qualified_images['schemaVersion'] ?? null ),
	'Qualified CI image metadata must exist and use schema version 1.'
);
$qualified_php_images = array(
	'php84' => array(
		'minor' => '8.4',
		'digest' => 'sha256:22dd9b45874452a5da42870b41f33b80d9af6c58c2f0aaa37f800619fc275e95',
	),
	'php85' => array(
		'minor' => '8.5',
		'digest' => 'sha256:f0b19c7643297e618f50f02853a64305f87988ad0f03bcbb7b2489450c435ace',
	),
);
foreach ( $qualified_php_images as $image_key => $expected_image ) {
	$image = $qualified_images['images'][ $image_key ] ?? array();
	$assert(
		$expected_image['minor'] === ( $image['phpMinor'] ?? null ),
		sprintf( 'Qualified %s metadata must record the expected PHP minor.', $image_key )
	);
	$assert(
		$expected_image['digest'] === ( $image['indexDigest'] ?? null ),
		sprintf( 'Qualified %s metadata must record the reviewed OCI index digest.', $image_key )
	);
	$assert(
		isset( $image['reference'] )
			&& str_ends_with( (string) $image['reference'], '@' . $expected_image['digest'] ),
		sprintf( 'Qualified %s reference must pin its reviewed OCI index digest.', $image_key )
	);
	$assert(
		isset( $image['reference'] )
			&& str_contains( (string) $workflow, (string) $image['reference'] ),
		sprintf( 'Forgejo CI must consume the qualified %s image by digest.', $image_key )
	);
}
$assert(
	! str_contains( (string) $workflow, 'setup-php@' )
		&& ! str_contains( (string) $workflow, 'shivammathur/setup-php' ),
	'Forgejo CI must use qualified PHP images instead of provisioning PHP at runtime.'
);
$assert(
	str_contains( (string) $workflow, 'argentwolf-verify-php-ci-image' ),
	'Forgejo PHP jobs must verify the selected shared CI image before running project tests.'
);
$assert(
	str_contains( (string) $image_verifier, 'php composer node git curl svn rsync unzip zip' ),
	'Shared PHP CI image verification must require the Forgejo action and WordPress test tools.'
);
$assert(
	! str_contains( (string) $workflow, 'apt-get install --yes subversion' ),
	'Forgejo WordPress CI must use Subversion from the qualified shared image.'
);
$assert(
	str_contains( (string) $workflow, "- '7.0.6'" )
		&& str_contains( (string) $workflow, "- '7.1.2'" ),
	'WordPress integration CI must cover the maintained minimum branch and current stable release.'
);
$assert(
	str_contains( (string) $workflow, "- '0.3.4'" )
		&& str_contains( (string) $workflow, "- '1.0.2'" ),
	'WordPress integration CI must cover the minimum and current companion releases.'
);
$assert(
	str_contains(
		(string) $workflow,
		'ARGENTWOLF_EMAIL_VERIFICATION_EXPECTED_VERSION: ${{ matrix.verification }}'
	),
	'WordPress integration CI must pass the selected companion version to integration tests.'
);
$assert(
	str_contains( (string) $workflow, "PLUGIN_CHECK_VERSION: '2.1.0'" )
		&& str_contains(
			(string) $workflow,
			"PLUGIN_CHECK_SHA256: '6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4'"
		),
	'Forgejo package CI must pin Plugin Check 2.1.0 by SHA-256.'
);
$assert(
	str_contains( (string) $workflow, "WORDPRESS_PACKAGE_VERSION: '7.1.2'" )
		&& str_contains( (string) $workflow, 'INSTALLED_PACKAGE_BYTE_IDENTITY=PASS' ),
	'Package CI must install the exact candidate ZIP on the current WordPress release and verify byte identity.'
);
$assert(
	str_contains( (string) $workflow, "WP_CLI_VERSION: '2.12.0'" )
		&& str_contains( (string) $workflow, 'WP_CLI_SHA512:' ),
	'Package CI must pin and verify WP-CLI when using the shared PHP image.'
);
$assert(
	str_contains( (string) $plugin_check_script, "run_check 'new-static' 'no' 'new'" )
		&& str_contains( (string) $plugin_check_script, "run_check 'new-runtime' 'yes' 'new'" )
		&& str_contains( (string) $plugin_check_script, "run_check 'update-runtime' 'yes' 'update'" )
		&& str_contains( (string) $plugin_check_script, '--format=strict-table' ),
	'Plugin Check helper must enforce static/new, runtime/new, and runtime/update strict gates.'
);
$assert(
	str_contains( (string) $workflow, 'POST_NOTIFIER_WP_DEBUG_GATE=PASS' )
		&& str_contains( (string) $workflow, '/wp-content/plugins/${PLUGIN_SLUG}/' ),
	'Package CI must fail on notifier-specific WP_DEBUG findings.'
);
$assert(
	! str_contains( (string) $workflow, '7.0.2' ),
	'CI must not remain pinned to obsolete WordPress 7.0.2.'
);
$assert(
	str_contains(
		(string) $companion_installer,
		'https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-email-verification'
	),
	'Companion integration fixtures must use the authoritative Forgejo project.'
);
$assert(
	! str_contains(
		(string) $installer,
		'mkdir -p "${tests_dir}" "${core_dir}"'
	),
	'WordPress core export destination must not be pre-created.'
);
$required_test_constants = array(
	'WP_TESTS_DOMAIN',
	'WP_TESTS_EMAIL',
	'WP_TESTS_TITLE',
	'WP_PHP_BINARY',
);
foreach ( $required_test_constants as $test_constant ) {
	$assert(
		str_contains(
			(string) $installer,
			"define( '{$test_constant}'"
		),
		sprintf( 'Installer must define %s.', $test_constant )
	);
}
$composer_manifest = json_decode(
	(string) file_get_contents( $root . '/composer.json' ),
	true
);
$unit_phpunit_config = file_get_contents(
	$root . '/phpunit.xml.dist'
);
$integration_phpunit_config = file_get_contents(
	$root . '/phpunit.integration.xml.dist'
);
$assert(
	'^9.6.16' === (
		$composer_manifest['require-dev']['phpunit/phpunit'] ?? null
	),
	'Supported WordPress integration tests must use PHPUnit 9.6.'
);
$assert(
	str_contains(
		(string) $unit_phpunit_config,
		'schema.phpunit.de/9.6/phpunit.xsd'
	),
	'Unit-test configuration must target the PHPUnit 9.6 schema.'
);
$assert(
	str_contains(
		(string) $integration_phpunit_config,
		'schema.phpunit.de/9.6/phpunit.xsd'
	),
	'Integration configuration must target the PHPUnit 9.6 schema.'
);
$assert(
	! str_contains(
		(string) $unit_phpunit_config,
		'cacheDirectory='
	),
	'PHPUnit 11-only cacheDirectory must not remain configured.'
);
$assert(
	'>=8.4' === (
		$composer_manifest['require']['php'] ?? null
	),
	'Composer runtime requirement must be PHP 8.4 or newer.'
);
$assert(
	'8.4.0' === (
		$composer_manifest['config']['platform']['php'] ?? null
	),
	'Composer dependency resolution must target the PHP 8.4 floor.'
);
$assert(
	Version::PLUGIN === ( $package_manifest['version'] ?? null ),
	'package.json version must match Version::PLUGIN.'
);
$assert(
	Version::PLUGIN === ( $package_lock['version'] ?? null )
		&& Version::PLUGIN === ( $package_lock['packages']['']['version'] ?? null ),
	'package-lock.json root versions must match Version::PLUGIN.'
);
$assert(
	'bash build/build-plugin.sh' === ( $composer_manifest['scripts']['build'] ?? null ),
	'Composer build must derive the package version instead of hard-coding a release number.'
);
$assert(
	! str_contains( (string) $build_script, 'main "$@" || true' )
		&& str_contains( (string) $build_script, 'main "$@"' ),
	'Package build failures must propagate to callers.'
);
$assert(
	! str_contains( (string) $package_manifest_script, 'main "$@" || true' )
		&& str_contains( (string) $package_manifest_script, 'main "$@"' ),
	'Package manifest failures must propagate to callers.'
);
$assert(
	str_contains( (string) $build_script, 'SOURCE_DATE_EPOCH' )
		&& str_contains( (string) $build_script, 'TZ=UTC' ),
	'Package builds must normalize archive timestamps from a reproducible source epoch.'
);
$assert(
	'https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier' === (
		$composer_manifest['support']['source'] ?? null
	),
	'Composer source metadata must point to the authoritative Forgejo repository.'
);
$verification_files = array(
	'src/Verification/VerificationProvider.php',
	'src/Verification/VerificationStatus.php',
	'src/Verification/VerificationProviderHealth.php',
	'src/Verification/ArgentWolfEmailVerificationProvider.php',
	'src/Verification/VerificationProviderResolver.php',
	'src/Verification/RegisteredUserEligibility.php',
);
foreach ( $verification_files as $verification_file ) {
	$assert(
		is_readable( $root . '/' . $verification_file ),
		sprintf( 'Verification contract file must exist: %s.', $verification_file )
	);
}
$provider = new ArgentWolfEmailVerificationProvider(
	static fn ( int $user_id ): string => 10 === $user_id
		? 'verified'
		: 'pending',
	static fn (): bool => true,
	static fn (): string => '0.3.4'
);
$assert( $provider->health()->is_healthy(), 'Released companion contract must be healthy.' );
$policy = new RegisteredUserEligibility( $provider );
$assert( $policy->is_eligible( 10 ), 'Verified users must be eligible.' );
$assert( ! $policy->is_eligible( 11 ), 'Pending users must be ineligible.' );
$assert(
	'unverified' === $policy->skip_reason_for_user( 11 ),
	'Pending users must have the unverified skip reason.'
);
$missing_provider = new ArgentWolfEmailVerificationProvider(
	static fn (): string => 'verified',
	static fn (): bool => false,
	static fn (): ?string => null
);
$missing_policy = new RegisteredUserEligibility( $missing_provider );
$assert(
	! $missing_policy->is_eligible( 10 ),
	'Missing providers must fail closed.'
);
$assert(
	'verification_unknown' === $missing_policy->skip_reason_for_user( 10 ),
	'Missing providers must use the verification_unknown skip reason.'
);
$resolver = new VerificationProviderResolver(
	$missing_provider,
	static fn () => 'invalid'
);
$assert(
	'verification_unknown' === (
		new RegisteredUserEligibility( $resolver->resolve() )
	)->skip_reason_for_user( 10 ),
	'Invalid alternate providers must fail closed.'
);
$verification_source = '';
foreach ( glob( $root . '/src/Verification/*.php' ) ?: array() as $source_file ) {
	$verification_source .= (string) file_get_contents( $source_file );
}
$assert(
	! str_contains( $verification_source, 'wp_mail(' ),
	'Mail transport success must never be treated as verification proof.'
);
$container = new Container();
$created   = 0;
$container->set(
	'test',
	static function () use ( &$created ): object {
		++$created;
		return new stdClass();
	}
);
$assert( $container->has( 'test' ), 'Container must report registered services.' );
$assert( $container->get( 'test' ) === $container->get( 'test' ), 'Services must be shared.' );
$assert( 1 === $created, 'Container factory must run once.' );

$GLOBALS['argentwolf_post_notifier_test_actions'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		$GLOBALS['argentwolf_post_notifier_test_actions'][] = array( $hook, $callback, $priority );
	}
}

$manager = new UpgradeManager();
$manager->register();

$assert(
	'plugins_loaded' === ( $GLOBALS['argentwolf_post_notifier_test_actions'][0][0] ?? null ),
	'UpgradeManager must register on plugins_loaded.'
);

$assert( Plugin::instance() === Plugin::instance(), 'Plugin::instance() must return one instance.' );

if ( array() !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "All dependency-free project tests passed.\n" );

// EOF: tests/run.php
