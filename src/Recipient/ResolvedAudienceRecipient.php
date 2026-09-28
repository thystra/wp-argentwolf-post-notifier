<?php
/**
 * File: src/Recipient/ResolvedAudienceRecipient.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

use InvalidArgumentException;

/**
 * One normalized, deduplicated recipient selected by audience policy.
 */
final readonly class ResolvedAudienceRecipient {
	/**
	 * Construct a resolved recipient.
	 *
	 * @param string                    $email         Normalized destination email.
	 * @param string                    $email_hash    Canonical keyed email hash.
	 * @param string                    $display_name  Preferred display-name snapshot.
	 * @param NamedListMemberType       $primary_type  Deterministic primary identity type.
	 * @param int|null                  $user_id       Eligible linked WordPress user ID.
	 * @param int|null                  $subscriber_id Eligible linked subscriber row ID.
	 * @param array<int,AudienceSource> $sources       Sources contributing eligible contacts.
	 * @throws InvalidArgumentException When identity data is incomplete.
	 */
	public function __construct(
		private string $email,
		private string $email_hash,
		private string $display_name,
		private NamedListMemberType $primary_type,
		private ?int $user_id,
		private ?int $subscriber_id,
		private array $sources
	) {
		if ( '' === $email || '' === $email_hash ) {
			throw new InvalidArgumentException( 'Resolved recipient email identity is required.' );
		}
		if ( null === $user_id && null === $subscriber_id ) {
			throw new InvalidArgumentException(
				'Resolved recipient must retain an eligible identity.'
			);
		}
	}

	/**
	 * Return normalized email.
	 *
	 * @return string
	 */
	public function email(): string {
		return $this->email;
	}

	/**
	 * Return canonical keyed email hash.
	 *
	 * @return string
	 */
	public function email_hash(): string {
		return $this->email_hash;
	}

	/**
	 * Return preferred display name.
	 *
	 * @return string
	 */
	public function display_name(): string {
		return $this->display_name;
	}

	/**
	 * Return deterministic primary identity type.
	 *
	 * @return NamedListMemberType
	 */
	public function primary_type(): NamedListMemberType {
		return $this->primary_type;
	}

	/**
	 * Return eligible linked WordPress user ID.
	 *
	 * @return int|null
	 */
	public function user_id(): ?int {
		return $this->user_id;
	}

	/**
	 * Return eligible linked standalone-subscriber ID.
	 *
	 * @return int|null
	 */
	public function subscriber_id(): ?int {
		return $this->subscriber_id;
	}

	/**
	 * Return sources contributing eligible contacts.
	 *
	 * @return array<int,AudienceSource>
	 */
	public function sources(): array {
		return $this->sources;
	}
}

// EOF: src/Recipient/ResolvedAudienceRecipient.php.
