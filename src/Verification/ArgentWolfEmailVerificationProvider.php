<?php
/**
 * File: src/Verification/ArgentWolfEmailVerificationProvider.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

use Closure;
use ReflectionFunction;
use Throwable;

/**
 * Adapter for the released ArgentWolf Email Verification public API.
 */
final class ArgentWolfEmailVerificationProvider implements VerificationProvider {
	/**
	 * Minimum released companion API version.
	 */
	public const MINIMUM_VERSION = '0.3.4';

	/**
	 * Canonical companion status function.
	 */
	private const STATUS_FUNCTION =
		'argentwolf_email_verification_get_user_verification_status';

	/**
	 * Canonical companion Boolean function.
	 */
	private const BOOLEAN_FUNCTION =
		'argentwolf_email_verification_is_user_verified';

	/**
	 * Status resolver.
	 *
	 * @var Closure
	 */
	private Closure $status_resolver;

	/**
	 * API availability resolver.
	 *
	 * @var Closure
	 */
	private Closure $availability_resolver;

	/**
	 * Version resolver.
	 *
	 * @var Closure
	 */
	private Closure $version_resolver;

	/**
	 * Construct the companion adapter.
	 *
	 * Optional resolvers keep the contract independently unit-testable.
	 *
	 * @param Closure|null $status_resolver       Status resolver.
	 * @param Closure|null $availability_resolver Availability resolver.
	 * @param Closure|null $version_resolver      Version resolver.
	 */
	public function __construct(
		?Closure $status_resolver = null,
		?Closure $availability_resolver = null,
		?Closure $version_resolver = null
	) {
		$this->status_resolver       = $status_resolver
			?? static function ( int $user_id ): mixed {
				$function = self::STATUS_FUNCTION;

				return $function( $user_id );
			};
		$this->availability_resolver = $availability_resolver
			?? static fn (): bool => function_exists( self::STATUS_FUNCTION )
				&& function_exists( self::BOOLEAN_FUNCTION );
		$this->version_resolver      = $version_resolver
			?? static fn (): ?string => self::detect_version();
	}

	/**
	 * Determine whether both canonical public functions are present.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return (bool) ( $this->availability_resolver )();
	}

	/**
	 * Return the authoritative companion status.
	 *
	 * Unknown is returned for missing, obsolete, malformed, or failing APIs.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return VerificationStatus
	 */
	public function status_for_user( int $user_id ): VerificationStatus {
		if ( $user_id <= 0 || ! $this->health()->is_healthy() ) {
			return VerificationStatus::Unknown;
		}

		try {
			return VerificationStatus::from_provider_value(
				( $this->status_resolver )( $user_id )
			);
		} catch ( Throwable ) {
			return VerificationStatus::Unknown;
		}
	}

	/**
	 * Return a human-readable provider description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'ArgentWolf Email Verification';
	}

	/**
	 * Return provider availability and compatibility details.
	 *
	 * @return VerificationProviderHealth
	 */
	public function health(): VerificationProviderHealth {
		if ( ! $this->is_available() ) {
			return new VerificationProviderHealth(
				'argentwolf-email-verification',
				$this->description(),
				false,
				false,
				null,
				'missing_api',
				'ArgentWolf Email Verification 0.3.4 or later is not active.'
			);
		}

		$version = ( $this->version_resolver )();
		if ( null === $version || '' === $version ) {
			return new VerificationProviderHealth(
				'argentwolf-email-verification',
				$this->description(),
				true,
				false,
				null,
				'unknown_version',
				'The verification provider version could not be determined.'
			);
		}

		if ( version_compare( $version, self::MINIMUM_VERSION, '<' ) ) {
			return new VerificationProviderHealth(
				'argentwolf-email-verification',
				$this->description(),
				true,
				false,
				$version,
				'obsolete_api',
				'ArgentWolf Email Verification must be upgraded to version 0.3.4 or later.'
			);
		}

		return new VerificationProviderHealth(
			'argentwolf-email-verification',
			$this->description(),
			true,
			true,
			$version,
			'healthy',
			'The authoritative verification provider is available.'
		);
	}

	/**
	 * Detect the plugin version from the file that defines the status function.
	 *
	 * @return string|null
	 */
	private static function detect_version(): ?string {
		if (
			! function_exists( self::STATUS_FUNCTION )
			|| ! function_exists( 'get_file_data' )
		) {
			return null;
		}

		try {
			$reflection = new ReflectionFunction( self::STATUS_FUNCTION );
			$file_name  = $reflection->getFileName();
		} catch ( Throwable ) {
			return null;
		}

		if ( false === $file_name || ! is_readable( $file_name ) ) {
			return null;
		}

		$data    = get_file_data(
			$file_name,
			array(
				'version' => 'Version',
			),
			'plugin'
		);
		$version = isset( $data['version'] ) ? trim( (string) $data['version'] ) : '';

		return '' === $version ? null : $version;
	}
}

// EOF: src/Verification/ArgentWolfEmailVerificationProvider.php.
