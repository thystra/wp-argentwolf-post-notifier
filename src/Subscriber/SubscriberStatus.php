<?php
/**
 * File: src/Subscriber/SubscriberStatus.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Lifecycle state for a standalone subscriber record.
 */
enum SubscriberStatus: string {
	case Pending      = 'pending';
	case Subscribed   = 'subscribed';
	case Unsubscribed = 'unsubscribed';
	case Suppressed   = 'suppressed';

	/**
	 * Determine whether this state is eligible for post notifications.
	 *
	 * @return bool
	 */
	public function is_notification_eligible(): bool {
		return self::Subscribed === $this;
	}

	/**
	 * Determine whether a public signup may refresh confirmation state.
	 *
	 * @return bool
	 */
	public function accepts_confirmation_refresh(): bool {
		return self::Pending === $this;
	}
}

// EOF: src/Subscriber/SubscriberStatus.php.
