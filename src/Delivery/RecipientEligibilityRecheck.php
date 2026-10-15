<?php
/**
 * File: src/Delivery/RecipientEligibilityRecheck.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Delivery;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;

/**
 * Recheck hard ineligibility immediately before one recipient is submitted.
 *
 * Suppression always wins. Otherwise the row stays deliverable if any
 * identity retained in the snapshot is still eligible: a registered user
 * must still exist with the same normalized email, be verified by a healthy
 * provider, and not be unsubscribed; a standalone subscriber must still be
 * `subscribed` with the same normalized email. Extensions may veto through
 * `argentwolf_post_notifier_recipient_eligible` but can never make an
 * ineligible recipient eligible.
 */
final class RecipientEligibilityRecheck {
	/**
	 * Construct the recheck policy.
	 *
	 * @param RegisteredUserEligibility          $eligibility Verification policy.
	 * @param RegisteredUserPreferenceRepository $preferences User preferences.
	 * @param SubscriberRepository               $subscribers Subscriber persistence.
	 * @param SuppressionService                 $suppression Global suppression.
	 * @param EmailIdentity                      $identity    Canonical email identity.
	 */
	public function __construct(
		private RegisteredUserEligibility $eligibility,
		private RegisteredUserPreferenceRepository $preferences,
		private SubscriberRepository $subscribers,
		private SuppressionService $suppression,
		private EmailIdentity $identity
	) {
	}

	/**
	 * Return null when deliverable, otherwise a stable skip reason.
	 *
	 * @param array<string,mixed> $row Claimed recipient row.
	 * @return string|null
	 */
	public function skip_reason( array $row ): ?string {
		$email = (string) ( $row['email_snapshot'] ?? '' );
		$hash  = (string) ( $row['email_hash'] ?? '' );
		if ( '' === $email || '' === $hash || $this->identity->hash( $email ) !== $hash ) {
			return 'invalid_email';
		}
		if ( $this->suppression->is_suppressed( $email ) ) {
			return 'suppressed';
		}

		$reasons       = array();
		$user_id       = (int) ( $row['user_id'] ?? 0 );
		$subscriber_id = (int) ( $row['subscriber_id'] ?? 0 );
		$eligible      = false;

		if ( $user_id > 0 ) {
			$reason = $this->user_reason( $user_id, $hash );
			if ( null === $reason ) {
				$eligible = true;
			} else {
				$reasons[] = $reason;
			}
		}
		if ( ! $eligible && $subscriber_id > 0 ) {
			$reason = $this->subscriber_reason( $subscriber_id, $hash );
			if ( null === $reason ) {
				$eligible = true;
			} else {
				$reasons[] = $reason;
			}
		}
		if ( ! $eligible ) {
			return $reasons[0] ?? 'deleted';
		}

		/**
		 * Filter whether one already-eligible campaign recipient may be submitted.
		 *
		 * Returning false skips the recipient with reason `filtered`. A filter
		 * cannot override suppression, verification, or confirmation checks.
		 * The context intentionally excludes the email address.
		 *
		 * @param bool                $eligible Always true when the filter runs.
		 * @param array<string,mixed> $context  campaign_id, recipient_type, user_id, subscriber_id.
		 */
		$filtered = apply_filters(
			'argentwolf_post_notifier_recipient_eligible',
			true,
			array(
				'campaign_id'    => (int) ( $row['campaign_id'] ?? 0 ),
				'recipient_type' => (string) ( $row['recipient_type'] ?? '' ),
				'user_id'        => $user_id > 0 ? $user_id : null,
				'subscriber_id'  => $subscriber_id > 0 ? $subscriber_id : null,
			)
		);

		return true === $filtered ? null : 'filtered';
	}

	/**
	 * Registered-user hard eligibility.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $hash    Snapshot email hash.
	 * @return string|null
	 */
	private function user_reason( int $user_id, string $hash ): ?string {
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return 'deleted';
		}
		if ( $this->identity->hash( (string) $user->user_email ) !== $hash ) {
			return 'email_changed';
		}
		$verification = $this->eligibility->skip_reason_for_user( $user_id );
		if ( null !== $verification ) {
			return $verification;
		}
		if ( RegisteredUserPreference::Unsubscribed === $this->preferences->get( $user_id ) ) {
			return 'unsubscribed';
		}

		return null;
	}

	/**
	 * Standalone-subscriber hard eligibility.
	 *
	 * @param int    $subscriber_id Subscriber row ID.
	 * @param string $hash          Snapshot email hash.
	 * @return string|null
	 */
	private function subscriber_reason( int $subscriber_id, string $hash ): ?string {
		$row = $this->subscribers->find_for_audience( $subscriber_id );
		if ( null === $row ) {
			return 'deleted';
		}
		if ( (string) ( $row['email_hash'] ?? '' ) !== $hash ) {
			return 'email_changed';
		}
		$status = SubscriberStatus::tryFrom( (string) ( $row['status'] ?? '' ) );

		return match ( $status ) {
			SubscriberStatus::Subscribed => null,
			SubscriberStatus::Pending    => 'pending_subscription',
			default                      => 'unsubscribed',
		};
	}
}

// EOF: src/Delivery/RecipientEligibilityRecheck.php.
