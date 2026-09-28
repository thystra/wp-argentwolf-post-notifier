<?php
/**
 * File: src/Audit/AuditService.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Structured privacy-safe audit policy for list and suppression changes.
 */
final class AuditService {
	private const SUBJECT_LIST        = 'list';
	private const SUBJECT_SUPPRESSION = 'suppression';

	/**
	 * Construct the audit service.
	 *
	 * @param AuditRepository $repository Audit-event persistence.
	 */
	public function __construct( private AuditRepository $repository ) {
	}

	/**
	 * Record one named-list audit event.
	 *
	 * @param AuditEventType         $event_type   Stable list event type.
	 * @param int                    $list_id      Named-list row ID.
	 * @param string|null            $related_type Optional related entity type.
	 * @param int|null               $related_id   Optional related entity ID.
	 * @param DateTimeInterface|null $now          Optional testable current time.
	 * @return void
	 * @throws InvalidArgumentException When event or entity data is invalid.
	 */
	public function record_list_event(
		AuditEventType $event_type,
		int $list_id,
		?string $related_type = null,
		?int $related_id = null,
		?DateTimeInterface $now = null
	): void {
		if ( ! str_starts_with( $event_type->value, 'list_' ) ) {
			throw new InvalidArgumentException( 'Audit event is not a list event.' );
		}
		if ( $list_id < 1 ) {
			throw new InvalidArgumentException( 'List ID must be positive.' );
		}
		if ( null !== $related_type ) {
			$related_type = $this->validated_code( $related_type, 32 );
		}
		if ( null !== $related_id && $related_id < 1 ) {
			throw new InvalidArgumentException( 'Related entity ID must be positive.' );
		}

		$this->repository->record(
			$event_type,
			$this->actor_user_id(),
			self::SUBJECT_LIST,
			$list_id,
			$related_type,
			$related_id,
			null,
			null,
			null,
			$this->normalize_now( $now )
		);
	}

	/**
	 * Record one global-suppression audit event using only keyed email identity.
	 *
	 * @param AuditEventType         $event_type Stable suppression event type.
	 * @param string                 $email_hash Canonical keyed email hash.
	 * @param string|null            $reason     Stable suppression reason code.
	 * @param string                 $source     Stable suppression source code.
	 * @param DateTimeInterface|null $now        Optional testable current time.
	 * @return void
	 * @throws InvalidArgumentException When audit data is invalid.
	 */
	public function record_suppression_event(
		AuditEventType $event_type,
		string $email_hash,
		?string $reason,
		string $source,
		?DateTimeInterface $now = null
	): void {
		if ( ! str_starts_with( $event_type->value, 'suppression_' ) ) {
			throw new InvalidArgumentException( 'Audit event is not a suppression event.' );
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $email_hash ) ) {
			throw new InvalidArgumentException( 'Audit email hash is invalid.' );
		}

		$reason = null === $reason ? null : $this->validated_code( $reason, 64 );
		$source = $this->validated_code( $source, 64 );

		$this->repository->record(
			$event_type,
			$this->actor_user_id(),
			self::SUBJECT_SUPPRESSION,
			null,
			null,
			null,
			$email_hash,
			$reason,
			$source,
			$this->normalize_now( $now )
		);
	}

	/**
	 * Resolve the authenticated WordPress actor when one exists.
	 *
	 * @return int|null
	 */
	private function actor_user_id(): ?int {
		$actor = get_current_user_id();
		return $actor > 0 ? $actor : null;
	}

	/**
	 * Validate one stable audit code.
	 *
	 * @param string $code       Candidate code.
	 * @param int    $max_length Maximum code length.
	 * @return string
	 * @throws InvalidArgumentException When the code is unsafe for storage.
	 */
	private function validated_code( string $code, int $max_length ): string {
		$code    = strtolower( trim( $code ) );
		$pattern = sprintf( '/\A[a-z0-9_-]{1,%d}\z/D', $max_length );
		if ( 1 !== preg_match( $pattern, $code ) ) {
			throw new InvalidArgumentException( 'Audit code is invalid.' );
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

// EOF: src/Audit/AuditService.php.
