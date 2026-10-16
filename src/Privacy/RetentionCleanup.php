<?php
/**
 * File: src/Privacy/RetentionCleanup.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Privacy;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\DataCleanup;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Daily bounded retention: old click events and old per-recipient identities.
 *
 * Each run processes at most MAX_BATCHES batches per kind, so a large backlog
 * is worked off over several days instead of one long request. Rows stay
 * eligible until processed, which makes the job resumable after any failure.
 */
final class RetentionCleanup implements Registerable {

	public const HOOK        = 'argentwolf_post_notifier_retention_cleanup';
	public const BATCH_SIZE  = 500;
	public const MAX_BATCHES = 10;

	/**
	 * Construct the job.
	 *
	 * @param DataCleanup     $cleanup  Bounded cleanup primitives.
	 * @param PrivacySettings $settings Retention settings.
	 */
	public function __construct(
		private DataCleanup $cleanup,
		private PrivacySettings $settings
	) {
	}

	/**
	 * Register the cron handler and schedule.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedule the daily event once.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run one bounded retention pass.
	 *
	 * @param DateTimeInterface|null $now Optional fixed clock (tests).
	 * @return array{clicks:int,recipients:int}
	 */
	public function run( ?DateTimeInterface $now = null ): array {
		$utc      = new DateTimeZone( 'UTC' );
		$current  = null === $now
			? new DateTimeImmutable( 'now', $utc )
			: DateTimeImmutable::createFromInterface( $now )->setTimezone( $utc );
		$settings = $this->settings->get();

		$click_cutoff     = $current->sub(
			new DateInterval( 'P' . $settings['click_retention_days'] . 'D' )
		);
		$recipient_cutoff = $current->sub(
			new DateInterval( 'P' . $settings['recipient_retention_days'] . 'D' )
		);

		$result = array(
			'clicks'     => 0,
			'recipients' => 0,
		);
		for ( $batch = 0; $batch < self::MAX_BATCHES; ++$batch ) {
			$deleted           = $this->cleanup->delete_click_events_before(
				$click_cutoff,
				self::BATCH_SIZE
			);
			$result['clicks'] += $deleted;
			if ( $deleted < self::BATCH_SIZE ) {
				break;
			}
		}
		for ( $batch = 0; $batch < self::MAX_BATCHES; ++$batch ) {
			$redacted              = $this->cleanup->redact_completed_campaign_recipients(
				$recipient_cutoff,
				self::BATCH_SIZE
			);
			$result['recipients'] += $redacted;
			if ( $redacted < self::BATCH_SIZE ) {
				break;
			}
		}

		return $result;
	}
}

// EOF: src/Privacy/RetentionCleanup.php.
