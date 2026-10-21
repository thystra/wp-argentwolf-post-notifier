<?php
/**
 * Isolated RC4 consent/provider-gate regression checks (no WordPress database).
 *
 * Run: php tests/Smoke/RC4ConsentGateSmoke.php
 *
 * The tests exercise production policy helpers and real preference decoding.
 * Full WordPress/database integration qualification is still required.
 *
 * @package ArgentWolf\PostNotifier
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/../../' );
require_once ABSPATH . 'autoload.php';

use ArgentWolf\PostNotifier\Campaign\CampaignQueueGate;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Delivery\RecipientEligibilityRecheck;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderHealth;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;

/** @var string $awpn_smoke_preference */
$awpn_smoke_preference = RegisteredUserPreference::Subscribed->value;

/**
 * WordPress user stub for this process only.
 *
 * @param int $user_id User ID.
 * @return object|false
 */
function get_userdata( int $user_id ) {
	return 7 === $user_id ? (object) array( 'user_email' => 'recipient@example.org' ) : false;
}

/**
 * WordPress user-meta stub for this process only.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param bool   $single Single-value request.
 * @return string
 * @throws RuntimeException When the test unexpectedly reads another key.
 */
function get_user_meta( int $user_id, string $key, bool $single = false ): string {
	global $awpn_smoke_preference;
	if (
		7 !== $user_id
		|| RegisteredUserPreferenceRepository::META_KEY !== $key
		|| ! $single
	) {
		throw new RuntimeException( 'Unexpected user-meta lookup in isolated test.' );
	}

	return $awpn_smoke_preference;
}

/**
 * Assert one isolated condition.
 *
 * @param bool   $valid   Whether the check passed.
 * @param string $message Failure message.
 * @return void
 * @throws RuntimeException When the condition fails.
 */
function awpn_smoke_assert( bool $valid, string $message ): void {
	if ( ! $valid ) {
		throw new RuntimeException( $message );
	}
}

$provider = new class() implements VerificationProvider {
	public function is_available(): bool {
		return true;
	}

	public function status_for_user( int $user_id ): VerificationStatus {
		return 7 === $user_id
			? VerificationStatus::Verified
			: VerificationStatus::Unknown;
	}

	public function description(): string {
		return 'RC4 isolated test';
	}

	public function health(): VerificationProviderHealth {
		return new VerificationProviderHealth(
			'rc4-smoke',
			'RC4 isolated test',
			true,
			true,
			'1.0.2',
			'ok',
			'Healthy'
		);
	}
};

$service    = ( new ReflectionClass( RecipientEligibilityRecheck::class ) )
	->newInstanceWithoutConstructor();
$reflection = new ReflectionClass( $service );
$reflection->getProperty( 'eligibility' )->setValue(
	$service,
	new RegisteredUserEligibility( $provider )
);
$reflection->getProperty( 'preferences' )->setValue(
	$service,
	new RegisteredUserPreferenceRepository()
);
$identity = new EmailIdentity( str_repeat( 'x', 32 ) );
$reflection->getProperty( 'identity' )->setValue( $service, $identity );
$user_reason = $reflection->getMethod( 'user_reason' );
$hash        = $identity->hash( 'recipient@example.org' );
awpn_smoke_assert( is_string( $hash ), 'Test email must hash.' );

foreach (
	array(
		RegisteredUserPreference::Subscribed->value   => null,
		RegisteredUserPreference::Unsubscribed->value => 'unsubscribed',
		RegisteredUserPreference::SiteDefault->value  => 'unsubscribed',
		'invalid-meta'                                => 'unsubscribed',
	) as $preference => $expected
) {
	$awpn_smoke_preference = $preference;
	$actual                = $user_reason->invoke( $service, 7, $hash );
	awpn_smoke_assert(
		$expected === $actual,
		sprintf(
			'Preference %s expected %s, got %s.',
			$preference,
			$expected ?? 'eligible',
			$actual ?? 'eligible'
		)
	);
}

echo "RC4_CONSENT_SMOKE=PASS\n";

// Exercise the production audience-classification policy without needing a
// database, WordPress hooks, or a verification-provider service on the host.
$gate              = ( new ReflectionClass( CampaignQueueGate::class ) )
	->newInstanceWithoutConstructor();
$may_include_users = ( new ReflectionClass( $gate ) )->getMethod( 'may_include_users' );
foreach (
	array(
		array( array(), false ),
		array( array( 'included_subscriber_ids' => array( 2 ) ), false ),
		array( array( 'role_slugs' => array( 'editor' ) ), true ),
		array( array( 'named_list_ids' => array( 4 ) ), true ),
		array( array( 'included_user_ids' => array( 7 ) ), true ),
		array( array( 'include_all_subscribers' => true ), true ),
		array( array( 'include_all_subscribers' => false ), false ),
	) as $case
) {
	$actual = $may_include_users->invoke( $gate, $case[0] );
	awpn_smoke_assert(
		$case[1] === $actual,
		'Audience gate misclassified for: ' . implode( ',', array_keys( $case[0] ) )
	);
}

echo "RC4_PROVIDER_GATE_SMOKE=PASS\n";
