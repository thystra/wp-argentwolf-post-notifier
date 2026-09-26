<?php
/**
 * File: src/Subscriber/PendingSubscriberCleanup.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\DataCleanup;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Scheduled cleanup for stale pending standalone subscribers.
 */
final class PendingSubscriberCleanup implements Registerable {
	/**
	 * WordPress cron hook.
	 */
	public const HOOK = 'argentwolf_post_notifier_cleanup_pending_subscribers';

	/**
	 * WordPress recurrence identifier.
	 */
	public const RECURRENCE = 'daily';

	/**
	 * Keep expired pending records for seven days before deletion.
	 */
	public const EXPIRED_RETENTION = 'P7D';

	/**
	 * Maximum stale pending records removed by one scheduled run.
	 */
	public const BATCH_SIZE = 250;

	/**
	 * One-hour scheduling interval used to choose a stable first-run boundary.
	 */
	private const FIRST_RUN_INTERVAL = 3600;

	/**
	 * Construct the pending-subscriber cleanup service.
	 *
	 * @param DataCleanup $cleanup Database cleanup primitives.
	 */
	public function __construct( private DataCleanup $cleanup ) {
	}

	/**
	 * Register cron callbacks and self-heal a missing schedule on init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Ensure the daily cleanup event exists without duplicating it.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$now       = time();
		$first_run = ( intdiv( $now, self::FIRST_RUN_INTERVAL ) + 1 )
			* self::FIRST_RUN_INTERVAL;

		wp_schedule_event( $first_run, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Remove the scheduled cleanup event without deleting subscriber data.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete one limited batch of pending records that have been expired long enough.
	 *
	 * @param DateTimeInterface|null $now Optional current-time override for tests.
	 * @return int Number of pending subscriber records deleted.
	 */
	public function run( ?DateTimeInterface $now = null ): int {
		$current = null === $now
			? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: DateTimeImmutable::createFromInterface( $now )->setTimezone(
				new DateTimeZone( 'UTC' )
			);
		$cutoff  = $current->sub( new DateInterval( self::EXPIRED_RETENTION ) );

		return $this->cleanup->delete_expired_pending_subscribers(
			$cutoff,
			self::BATCH_SIZE
		);
	}
}

// EOF: src/Subscriber/PendingSubscriberCleanup.php.
