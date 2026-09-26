<?php
/**
 * File: src/Suppression/SuppressionService.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Suppression;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Canonical global-suppression policy for all recipient sources.
 */
final class SuppressionService {
	public const REASON_UNSUBSCRIBE = 'unsubscribe';
	public const REASON_MANUAL      = 'manual';

	public const SOURCE_SUBSCRIBER_MANAGE = 'subscriber_manage';
	public const SOURCE_SUBSCRIBER_ADMIN  = 'subscriber_admin';

	/**
	 * Construct the suppression policy service.
	 *
	 * @param SuppressionRepository $repository Suppression persistence.
	 * @param EmailIdentity         $identity   Canonical email identity helper.
	 */
	public function __construct(
		private SuppressionRepository $repository,
		private EmailIdentity $identity
	) {
	}

	/**
	 * Determine whether one normalized identity is globally suppressed.
	 *
	 * @param string $email Candidate email address.
	 * @return bool
	 */
	public function is_suppressed( string $email ): bool {
		$email_hash = $this->required_hash( $email );

		return null !== $this->repository->find_by_hash( $email_hash );
	}

	/**
	 * Return the current suppression source for one email, when present.
	 *
	 * @param string $email Candidate email address.
	 * @return string|null
	 */
	public function source_for( string $email ): ?string {
		$email_hash = $this->required_hash( $email );
		$row        = $this->repository->find_by_hash( $email_hash );

		return null === $row ? null : (string) ( $row['source'] ?? '' );
	}

	/**
	 * Suppress one normalized email globally.
	 *
	 * User-owned suppression sources cannot overwrite an administrator-created
	 * suppression. Administrative suppression remains authoritative until an
	 * explicit operator workflow changes it.
	 *
	 * @param string                 $email  Candidate email address.
	 * @param string                 $reason Stable suppression reason code.
	 * @param string                 $source Stable suppression source code.
	 * @param DateTimeInterface|null $now    Optional testable current time.
	 * @return void
	 */
	public function suppress(
		string $email,
		string $reason,
		string $source,
		?DateTimeInterface $now = null
	): void {
		$normalized = $this->required_email( $email );
		$email_hash = $this->required_hash( $normalized );
		$reason     = $this->validated_code( $reason );
		$source     = $this->validated_code( $source );
		$current    = $this->normalize_now( $now );

		if ( self::SOURCE_SUBSCRIBER_ADMIN === $source ) {
			$this->repository->upsert(
				$email_hash,
				$normalized,
				$reason,
				$source,
				$current
			);
			return;
		}

		$this->repository->upsert_if_absent_or_source(
			$email_hash,
			$normalized,
			$reason,
			$source,
			$current
		);
	}

	/**
	 * Remove only a suppression created by the same verified self-service source.
	 *
	 * @param string $email           Candidate email address.
	 * @param string $expected_source Source allowed to remove its own suppression.
	 * @return bool True when one suppression was removed.
	 */
	public function resubscribe( string $email, string $expected_source ): bool {
		$email_hash = $this->required_hash( $email );

		return $this->repository->delete_if_source(
			$email_hash,
			$this->validated_code( $expected_source )
		);
	}

	/**
	 * Determine whether a verified source may remove the current suppression.
	 *
	 * @param string $email           Candidate email address.
	 * @param string $expected_source Source allowed to remove its own suppression.
	 * @return bool
	 */
	public function can_resubscribe( string $email, string $expected_source ): bool {
		return $this->validated_code( $expected_source ) === $this->source_for( $email );
	}

	/**
	 * Return one required normalized email address.
	 *
	 * @param string $email Candidate email address.
	 * @return string
	 * @throws InvalidArgumentException When the address is invalid.
	 */
	private function required_email( string $email ): string {
		$normalized = $this->identity->normalize( $email );
		if ( null === $normalized ) {
			throw new InvalidArgumentException( 'A valid email address is required.' );
		}

		return $normalized;
	}

	/**
	 * Return one required canonical keyed email hash.
	 *
	 * @param string $email Candidate email address.
	 * @return string
	 * @throws InvalidArgumentException When the address is invalid.
	 */
	private function required_hash( string $email ): string {
		$email_hash = $this->identity->hash( $this->required_email( $email ) );
		if ( null === $email_hash ) {
			throw new InvalidArgumentException( 'A valid email address is required.' );
		}

		return $email_hash;
	}

	/**
	 * Validate a persisted reason/source code.
	 *
	 * @param string $code Candidate code.
	 * @return string
	 * @throws InvalidArgumentException When the code is not safe for storage.
	 */
	private function validated_code( string $code ): string {
		$code = strtolower( trim( $code ) );
		if ( 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/D', $code ) ) {
			throw new InvalidArgumentException( 'Suppression code is invalid.' );
		}

		return $code;
	}

	/**
	 * Return a UTC immutable current-time value.
	 *
	 * @param DateTimeInterface|null $now Optional current-time override.
	 * @return DateTimeImmutable
	 */
	private function normalize_now( ?DateTimeInterface $now ): DateTimeImmutable {
		$current = null === $now
			? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: DateTimeImmutable::createFromInterface( $now );

		return $current->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}

// EOF: src/Suppression/SuppressionService.php.
