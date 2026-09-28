<?php
/**
 * File: src/Recipient/AudienceResolver.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;

/**
 * Resolve typed audience sources into one normalized recipient per email.
 *
 * This is the reusable Alpha.5 policy layer. Campaign snapshot persistence and
 * pre-send rechecks remain Milestone 9 responsibilities.
 */
final class AudienceResolver {
	/**
	 * Construct the audience resolver.
	 *
	 * @param NamedListRepository                $lists       Named-list persistence.
	 * @param RegisteredUserPreferenceRepository $preferences Registered-user preferences.
	 * @param RegisteredUserEligibility          $eligibility Registered-user verification.
	 * @param SubscriberRepository               $subscribers Standalone-subscriber persistence.
	 * @param SuppressionService                 $suppression Global suppression policy.
	 * @param EmailIdentity                      $identity    Canonical email identity.
	 */
	public function __construct(
		private NamedListRepository $lists,
		private RegisteredUserPreferenceRepository $preferences,
		private RegisteredUserEligibility $eligibility,
		private SubscriberRepository $subscribers,
		private SuppressionService $suppression,
		private EmailIdentity $identity
	) {
	}

	/**
	 * Resolve one audience request.
	 *
	 * @param AudienceResolutionRequest $request Resolution inputs.
	 * @return AudienceResolution
	 */
	public function resolve( AudienceResolutionRequest $request ): AudienceResolution {
		$contacts = array();
		$skips    = array();

		foreach ( $request->role_user_ids() as $user_id ) {
			$this->add_contact(
				$contacts,
				NamedListMemberType::User,
				$user_id,
				AudienceSource::Role
			);
		}

		foreach ( $request->named_list_ids() as $list_id ) {
			$this->add_list_contacts( $contacts, $list_id );
		}

		foreach ( $request->included_user_ids() as $user_id ) {
			$this->add_contact(
				$contacts,
				NamedListMemberType::User,
				$user_id,
				AudienceSource::Explicit
			);
		}

		foreach ( $request->included_subscriber_ids() as $subscriber_id ) {
			$this->add_contact(
				$contacts,
				NamedListMemberType::Subscriber,
				$subscriber_id,
				AudienceSource::Explicit
			);
		}

		$excluded = $this->excluded_keys( $request );
		foreach ( array_keys( $excluded ) as $key ) {
			if ( isset( $contacts[ $key ] ) ) {
				unset( $contacts[ $key ] );
				$this->increment_skip( $skips, 'excluded' );
			}
		}

		$groups = $this->group_contacts( $contacts, $skips );
		ksort( $groups, SORT_STRING );

		$recipients = array();
		foreach ( $groups as $email => $group ) {
			$recipient = $this->resolve_email_group( $email, $group, $request, $skips );
			if ( null !== $recipient ) {
				$recipients[] = $recipient;
			}
		}

		ksort( $skips, SORT_STRING );

		return new AudienceResolution( $recipients, $skips );
	}

	/**
	 * Add typed members from one named list.
	 *
	 * @param array<string,array<string,mixed>> $contacts Contact map.
	 * @param int                               $list_id  Named-list row ID.
	 * @return void
	 */
	private function add_list_contacts( array &$contacts, int $list_id ): void {
		foreach ( $this->lists->members( $list_id ) as $member ) {
			$type = NamedListMemberType::tryFrom( (string) ( $member['member_type'] ?? '' ) );
			if ( null === $type ) {
				continue;
			}

			$entity_id = NamedListMemberType::User === $type
				? (int) ( $member['user_id'] ?? 0 )
				: (int) ( $member['subscriber_id'] ?? 0 );
			if ( $entity_id < 1 ) {
				continue;
			}

			$this->add_contact( $contacts, $type, $entity_id, AudienceSource::NamedList );
		}
	}

	/**
	 * Add or merge one typed source contact.
	 *
	 * @param array<string,array<string,mixed>> $contacts  Contact map.
	 * @param NamedListMemberType               $type      Contact type.
	 * @param int                               $entity_id Entity ID.
	 * @param AudienceSource                    $source    Contributing source.
	 * @return void
	 */
	private function add_contact(
		array &$contacts,
		NamedListMemberType $type,
		int $entity_id,
		AudienceSource $source
	): void {
		if ( $entity_id < 1 ) {
			return;
		}

		$key = $type->member_key( $entity_id );
		if ( ! isset( $contacts[ $key ] ) ) {
			$contacts[ $key ] = array(
				'type'      => $type,
				'entity_id' => $entity_id,
				'sources'   => array(),
			);
		}

		$contacts[ $key ]['sources'][ $source->value ] = $source;
	}

	/**
	 * Build explicitly excluded typed-contact keys.
	 *
	 * @param AudienceResolutionRequest $request Resolution inputs.
	 * @return array<string,bool>
	 */
	private function excluded_keys( AudienceResolutionRequest $request ): array {
		$excluded = array();

		foreach ( $request->excluded_user_ids() as $user_id ) {
			$excluded[ NamedListMemberType::User->member_key( $user_id ) ] = true;
		}
		foreach ( $request->excluded_subscriber_ids() as $subscriber_id ) {
			$key              = NamedListMemberType::Subscriber->member_key( $subscriber_id );
			$excluded[ $key ] = true;
		}

		return $excluded;
	}

	/**
	 * Resolve source contacts and group them by normalized email.
	 *
	 * @param array<string,array<string,mixed>> $contacts Contact map.
	 * @param array<string,int>                 $skips    Aggregate skip counts.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function group_contacts( array $contacts, array &$skips ): array {
		$groups = array();

		ksort( $contacts, SORT_STRING );
		foreach ( $contacts as $contact ) {
			$resolved = $this->resolve_contact( $contact );
			if ( null === $resolved ) {
				$this->increment_skip( $skips, 'deleted' );
				continue;
			}

			$email = (string) $resolved['email'];
			if ( '' === trim( $email ) ) {
				$this->increment_skip( $skips, 'no_email' );
				continue;
			}

			$normalized = $this->identity->normalize( $email );
			if ( null === $normalized ) {
				$this->increment_skip( $skips, 'invalid_email' );
				continue;
			}

			$resolved['email']       = $normalized;
			$groups[ $normalized ][] = $resolved;
		}

		return $groups;
	}

	/**
	 * Resolve one typed source contact.
	 *
	 * @param array<string,mixed> $contact Contact descriptor.
	 * @return array<string,mixed>|null
	 */
	private function resolve_contact( array $contact ): ?array {
		$type      = $contact['type'] ?? null;
		$entity_id = (int) ( $contact['entity_id'] ?? 0 );
		$sources   = $contact['sources'] ?? array();
		if ( ! $type instanceof NamedListMemberType || $entity_id < 1 ) {
			return null;
		}

		if ( NamedListMemberType::User === $type ) {
			$user = get_userdata( $entity_id );
			if ( false === $user ) {
				return null;
			}

			return array(
				'type'         => $type,
				'entity_id'    => $entity_id,
				'email'        => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
				'sources'      => $sources,
			);
		}

		$subscriber = $this->subscribers->find_for_audience( $entity_id );
		if ( null === $subscriber ) {
			return null;
		}

		return array(
			'type'              => $type,
			'entity_id'         => $entity_id,
			'email'             => (string) ( $subscriber['email'] ?? '' ),
			'display_name'      => (string) ( $subscriber['display_name'] ?? '' ),
			'subscriber_status' => (string) ( $subscriber['status'] ?? '' ),
			'sources'           => $sources,
		);
	}

	/**
	 * Resolve one normalized email group to at most one recipient.
	 *
	 * @param string                         $email   Normalized email.
	 * @param array<int,array<string,mixed>> $group   Source contacts sharing email.
	 * @param AudienceResolutionRequest      $request Resolution inputs.
	 * @param array<string,int>              $skips   Aggregate skip counts.
	 * @return ResolvedAudienceRecipient|null
	 */
	private function resolve_email_group(
		string $email,
		array $group,
		AudienceResolutionRequest $request,
		array &$skips
	): ?ResolvedAudienceRecipient {
		$eligible_users       = array();
		$eligible_subscribers = array();
		$ineligible_reasons   = array();

		foreach ( $group as $contact ) {
			$reason = $this->ineligible_reason( $contact, $request );
			if ( null !== $reason ) {
				$ineligible_reasons[] = $reason;
				continue;
			}

			$type = $contact['type'];
			if ( NamedListMemberType::User === $type ) {
				$eligible_users[] = $contact;
			} else {
				$eligible_subscribers[] = $contact;
			}
		}

		if ( $this->suppression->is_suppressed( $email ) ) {
			$this->increment_skip( $skips, 'suppressed' );
			return null;
		}

		if ( array() === $eligible_users && array() === $eligible_subscribers ) {
			$this->increment_skip(
				$skips,
				$this->preferred_skip_reason( $ineligible_reasons )
			);
			return null;
		}

		usort( $eligible_users, array( $this, 'compare_entity_id' ) );
		usort( $eligible_subscribers, array( $this, 'compare_entity_id' ) );

		$user       = $eligible_users[0] ?? null;
		$subscriber = $eligible_subscribers[0] ?? null;
		$primary    = null !== $user ? $user : $subscriber;
		if ( null === $primary ) {
			return null;
		}

		$eligible_count = count( $eligible_users ) + count( $eligible_subscribers );
		if ( $eligible_count > 1 ) {
			$skips['duplicate'] = ( $skips['duplicate'] ?? 0 ) + $eligible_count - 1;
		}

		$sources = $this->merged_sources( $eligible_users, $eligible_subscribers );
		$hash    = $this->identity->hash( $email );
		if ( null === $hash ) {
			$this->increment_skip( $skips, 'invalid_email' );
			return null;
		}

		$display_name = trim( (string) ( $primary['display_name'] ?? '' ) );
		if ( '' === $display_name && null !== $subscriber ) {
			$display_name = trim( (string) ( $subscriber['display_name'] ?? '' ) );
		}

		return new ResolvedAudienceRecipient(
			$email,
			$hash,
			$display_name,
			$primary['type'],
			null === $user ? null : (int) $user['entity_id'],
			null === $subscriber ? null : (int) $subscriber['entity_id'],
			$sources
		);
	}

	/**
	 * Return one source-contact ineligibility reason.
	 *
	 * @param array<string,mixed>       $contact Contact descriptor.
	 * @param AudienceResolutionRequest $request Resolution inputs.
	 * @return string|null
	 */
	private function ineligible_reason(
		array $contact,
		AudienceResolutionRequest $request
	): ?string {
		$type      = $contact['type'];
		$entity_id = (int) $contact['entity_id'];

		if ( NamedListMemberType::User === $type ) {
			$preference = $this->preferences->get( $entity_id );
			if ( RegisteredUserPreference::Unsubscribed === $preference ) {
				return 'unsubscribed';
			}
			if (
				RegisteredUserPreference::SiteDefault === $preference
				&& ! $request->site_default_user_subscribed()
			) {
				return 'unsubscribed';
			}

			return $this->eligibility->skip_reason_for_user( $entity_id );
		}

		$status = SubscriberStatus::tryFrom(
			(string) ( $contact['subscriber_status'] ?? '' )
		);
		if ( null === $status ) {
			return 'deleted';
		}

		return match ( $status ) {
			SubscriberStatus::Subscribed   => null,
			SubscriberStatus::Pending      => 'pending_subscription',
			SubscriberStatus::Unsubscribed => 'unsubscribed',
			SubscriberStatus::Suppressed   => 'suppressed',
		};
	}

	/**
	 * Choose one stable reason when every source for an email is ineligible.
	 *
	 * @param array<int,string> $reasons Source-level reasons.
	 * @return string
	 */
	private function preferred_skip_reason( array $reasons ): string {
		$priority = array(
			'suppressed',
			'verification_unknown',
			'unverified',
			'unsubscribed',
			'pending_subscription',
			'deleted',
		);

		foreach ( $priority as $reason ) {
			if ( in_array( $reason, $reasons, true ) ) {
				return $reason;
			}
		}

		return 'deleted';
	}

	/**
	 * Merge contributing sources in stable enum-value order.
	 *
	 * @param array<int,array<string,mixed>> $users       Eligible users.
	 * @param array<int,array<string,mixed>> $subscribers Eligible subscribers.
	 * @return array<int,AudienceSource>
	 */
	private function merged_sources( array $users, array $subscribers ): array {
		$sources = array();
		foreach ( array_merge( $users, $subscribers ) as $contact ) {
			foreach ( $contact['sources'] as $source ) {
				if ( $source instanceof AudienceSource ) {
					$sources[ $source->value ] = $source;
				}
			}
		}

		ksort( $sources, SORT_STRING );

		return array_values( $sources );
	}

	/**
	 * Compare source contacts by entity ID.
	 *
	 * @param array<string,mixed> $left  Left contact.
	 * @param array<string,mixed> $right Right contact.
	 * @return int
	 */
	private function compare_entity_id( array $left, array $right ): int {
		return (int) $left['entity_id'] <=> (int) $right['entity_id'];
	}

	/**
	 * Increment one stable aggregate skip reason.
	 *
	 * @param array<string,int> $skips  Aggregate skip counts.
	 * @param string            $reason Stable reason.
	 * @return void
	 */
	private function increment_skip( array &$skips, string $reason ): void {
		$skips[ $reason ] = ( $skips[ $reason ] ?? 0 ) + 1;
	}
}

// EOF: src/Recipient/AudienceResolver.php.
