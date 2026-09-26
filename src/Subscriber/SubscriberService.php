<?php
/**
 * File: src/Subscriber/SubscriberService.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Standalone-subscriber double-opt-in domain service.
 */
final class SubscriberService {
	/**
	 * Initial confirmation-token lifetime.
	 */
	public const CONFIRMATION_TTL = 'P1D';

	/**
	 * Minimum time between confirmation sends for one address.
	 */
	public const RESEND_COOLDOWN = 'PT15M';

	/**
	 * Global suppression policy.
	 *
	 * @var SuppressionService
	 */
	private SuppressionService $suppression;

	/**
	 * Construct the subscriber service.
	 *
	 * @param SubscriberRepository    $repository Subscriber persistence.
	 * @param EmailIdentity           $identity Canonical email identity helper.
	 * @param SuppressionService|null $suppression Optional suppression policy.
	 */
	public function __construct(
		private SubscriberRepository $repository,
		private EmailIdentity $identity,
		?SuppressionService $suppression = null
	) {
		$this->suppression = $suppression
			?? new SuppressionService( new SuppressionRepository(), $identity );
	}

	/**
	 * Create or refresh a pending standalone subscriber.
	 *
	 * This method intentionally exposes an internal result. A public controller
	 * must return a generic response instead of exposing row existence, status,
	 * cooldown state, or whether a confirmation message will be sent.
	 *
	 * @param string                 $email          Candidate email address.
	 * @param string|null            $display_name   Optional display name.
	 * @param string                 $consent_text   Consent text accepted by the requester.
	 * @param string                 $signup_source  Source identifier such as block.
	 * @param int|null               $source_post_id Optional source post ID.
	 * @param DateTimeInterface|null $now            Testable current time override.
	 * @return SignupResult
	 * @throws InvalidArgumentException When request data is invalid.
	 */
	public function request_confirmation(
		string $email,
		?string $display_name,
		string $consent_text,
		string $signup_source,
		?int $source_post_id = null,
		?DateTimeInterface $now = null
	): SignupResult {
		$normalized_email = $this->identity->normalize( $email );
		if ( null === $normalized_email ) {
			throw new InvalidArgumentException( 'A valid email address is required.' );
		}

		$email_hash = $this->identity->hash( $normalized_email );
		if ( null === $email_hash ) {
			throw new InvalidArgumentException( 'A valid email address is required.' );
		}

		if ( $this->suppression->is_suppressed( $normalized_email ) ) {
			return new SignupResult( 0, $normalized_email, null );
		}

		$consent_text = $this->normalize_text( $consent_text, 4000 );
		if ( '' === $consent_text ) {
			throw new InvalidArgumentException( 'Consent text is required.' );
		}

		$signup_source = strtolower( trim( $signup_source ) );
		if ( 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/D', $signup_source ) ) {
			throw new InvalidArgumentException( 'Signup source is invalid.' );
		}

		if ( null !== $source_post_id && $source_post_id < 1 ) {
			throw new InvalidArgumentException( 'Source post ID must be positive.' );
		}

		$display_name = null === $display_name
			? null
			: $this->normalize_text( $display_name, 191 );
		if ( '' === $display_name ) {
			$display_name = null;
		}

		$current = $this->normalize_now( $now );
		$token   = ConfirmationToken::generate();
		$result  = $this->repository->create_or_refresh_pending(
			$this->uuid(),
			$normalized_email,
			$email_hash,
			$display_name,
			$consent_text,
			$signup_source,
			$source_post_id,
			$token->hash(),
			$current->add( new DateInterval( self::CONFIRMATION_TTL ) ),
			$current->sub( new DateInterval( self::RESEND_COOLDOWN ) ),
			$current
		);

		return new SignupResult(
			$result['subscriber_id'],
			$normalized_email,
			$result['token_rotated'] ? $token->plaintext() : null
		);
	}

	/**
	 * Promote one pending subscriber through an unexpired confirmation token.
	 *
	 * Public routing must require an intentional POST before calling this method.
	 * A GET of the confirmation URL must never call confirm().
	 *
	 * @param string                 $plaintext_token Plaintext confirmation token.
	 * @param DateTimeInterface|null $now             Testable current time override.
	 * @return bool True only when one pending subscriber was promoted.
	 */
	public function confirm(
		string $plaintext_token,
		?DateTimeInterface $now = null
	): bool {
		return null !== $this->confirm_with_management( $plaintext_token, $now );
	}

	/**
	 * Confirm one subscriber and issue the initial management bearer token.
	 *
	 * The plaintext management token is returned only for the successful
	 * confirmation response so it can be presented to the subscriber. Only its
	 * SHA-256 hash is persisted.
	 *
	 * @param string                 $plaintext_token Plaintext confirmation token.
	 * @param DateTimeInterface|null $now             Testable current time override.
	 * @return string|null Plaintext management token on successful confirmation.
	 */
	public function confirm_with_management(
		string $plaintext_token,
		?DateTimeInterface $now = null
	): ?string {
		$token_hash = ConfirmationToken::hash_plaintext( $plaintext_token );
		if ( null === $token_hash ) {
			return null;
		}

		$manage_token = ManageSubscriptionToken::generate();
		$confirmed    = $this->repository->confirm_pending(
			$token_hash,
			$manage_token->hash(),
			$this->normalize_now( $now )
		);

		return $confirmed ? $manage_token->plaintext() : null;
	}

	/**
	 * Resolve one management bearer to its current standalone subscriber.
	 *
	 * @param string $plaintext_token Plaintext management token.
	 * @return array<string,mixed>|null
	 */
	public function managed_subscriber( string $plaintext_token ): ?array {
		$token_hash = ManageSubscriptionToken::hash_plaintext( $plaintext_token );
		if ( null === $token_hash ) {
			return null;
		}

		return $this->repository->find_by_manage_token_hash( $token_hash );
	}

	/**
	 * Globally unsubscribe one management-authorized standalone subscriber.
	 *
	 * Suppression is written before the subscriber row changes so a database
	 * failure cannot leave the address eligible through another source.
	 *
	 * @param string                 $plaintext_token Plaintext management token.
	 * @param DateTimeInterface|null $now             Testable current time override.
	 * @return bool True when the request was authorized and applied.
	 */
	public function unsubscribe_managed(
		string $plaintext_token,
		?DateTimeInterface $now = null
	): bool {
		$row = $this->managed_subscriber( $plaintext_token );
		if ( null === $row ) {
			return false;
		}

		$email = (string) ( $row['email'] ?? '' );
		$this->suppression->suppress(
			$email,
			SuppressionService::REASON_UNSUBSCRIBE,
			SuppressionService::SOURCE_SUBSCRIBER_MANAGE,
			$now
		);

		$token_hash = ManageSubscriptionToken::hash_plaintext( $plaintext_token );
		if ( null === $token_hash ) {
			return false;
		}

		return $this->repository->unsubscribe_by_manage_token(
			$token_hash,
			$this->normalize_now( $now )
		);
	}

	/**
	 * Resubscribe one management-authorized standalone subscriber.
	 *
	 * Only a suppression created by this management source can be removed. An
	 * operator-created suppression therefore cannot be bypassed by an old link.
	 *
	 * @param string                 $plaintext_token Plaintext management token.
	 * @param DateTimeInterface|null $now             Testable current time override.
	 * @return bool True when the verified resubscribe completed.
	 */
	public function resubscribe_managed(
		string $plaintext_token,
		?DateTimeInterface $now = null
	): bool {
		$row = $this->managed_subscriber( $plaintext_token );
		if ( null === $row ) {
			return false;
		}

		$email = (string) ( $row['email'] ?? '' );
		if (
			! $this->suppression->can_resubscribe(
				$email,
				SuppressionService::SOURCE_SUBSCRIBER_MANAGE
			)
		) {
			return false;
		}

		$token_hash = ManageSubscriptionToken::hash_plaintext( $plaintext_token );
		if ( null === $token_hash ) {
			return false;
		}

		if (
			! $this->repository->resubscribe_by_manage_token(
				$token_hash,
				$this->normalize_now( $now )
			)
		) {
			return false;
		}

		return $this->suppression->resubscribe(
			$email,
			SuppressionService::SOURCE_SUBSCRIBER_MANAGE
		);
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

	/**
	 * Normalize bounded text without retaining markup.
	 *
	 * @param string $value      Candidate text.
	 * @param int    $max_length Maximum characters retained.
	 * @return string
	 */
	private function normalize_text( string $value, int $max_length ): string {
		$value = trim( wp_strip_all_tags( $value, true ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length );
		}

		return substr( $value, 0, $max_length );
	}

	/**
	 * Generate a UUID using WordPress's canonical helper.
	 *
	 * @return string
	 */
	private function uuid(): string {
		return wp_generate_uuid4();
	}
}

// EOF: src/Subscriber/SubscriberService.php.
