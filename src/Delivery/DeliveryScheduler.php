<?php
/**
 * File: src/Delivery/DeliveryScheduler.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Delivery;

use ArgentWolf\PostNotifier\Campaign\CampaignPreparation;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * WP-Cron wake-ups for campaign preparation and the delivery queue.
 *
 * WP-Cron only wakes work; the plugin-owned recipient table is the queue.
 * Production sites should also run `wp argentwolf-post-notifier queue run`
 * from system cron so delivery does not depend solely on site traffic.
 */
final class DeliveryScheduler implements Registerable {
	public const PREPARE_HOOK  = 'argentwolf_post_notifier_prepare_campaign';
	public const QUEUE_HOOK    = 'argentwolf_post_notifier_process_queue';
	public const WATCHDOG_HOOK = 'argentwolf_post_notifier_delivery_watchdog';

	/** Batches per cron wake-up; WP-CLI may request more. */
	public const CRON_BATCHES = 4;

	/** Retry a deferred preparation after this many seconds. */
	private const DEFER_SECONDS = 900;

	/** Watchdog recovers building campaigns older than this. */
	private const STALE_SECONDS = 600;

	/**
	 * Construct the scheduler with lazy service factories.
	 *
	 * @param Closure $preparation Returns CampaignPreparation.
	 * @param Closure $worker      Returns QueueWorker.
	 * @param Closure $queue       Returns QueueRepository.
	 * @param Closure $campaigns   Returns CampaignRepository.
	 */
	public function __construct(
		private Closure $preparation,
		private Closure $worker,
		private Closure $queue,
		private Closure $campaigns
	) {
	}

	/**
	 * Register cron callbacks and ensure the hourly watchdog exists.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::PREPARE_HOOK, array( $this, 'handle_prepare' ) );
		add_action( self::QUEUE_HOOK, array( $this, 'handle_queue' ) );
		add_action( self::WATCHDOG_HOOK, array( $this, 'handle_watchdog' ) );
		add_action( 'init', array( self::class, 'ensure_watchdog' ) );
	}

	/**
	 * Schedule asynchronous preparation for one campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $delay       Delay in seconds.
	 * @return void
	 */
	public static function schedule_prepare( int $campaign_id, int $delay = 0 ): void {
		$args = array( $campaign_id );
		if ( false === wp_next_scheduled( self::PREPARE_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::PREPARE_HOOK, $args );
		}
	}

	/**
	 * Wake the queue worker at a given time (default now).
	 *
	 * @param int|null $timestamp Unix timestamp.
	 * @return void
	 */
	public static function wake( ?int $timestamp = null ): void {
		$when = $timestamp ?? time();
		$next = wp_next_scheduled( self::QUEUE_HOOK );
		if ( false === $next || $next > $when ) {
			if ( false !== $next ) {
				wp_unschedule_event( $next, self::QUEUE_HOOK );
			}
			wp_schedule_single_event( $when, self::QUEUE_HOOK );
		}
	}

	/**
	 * Ensure the hourly watchdog event exists.
	 *
	 * @return void
	 */
	public static function ensure_watchdog(): void {
		if ( false === wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCHDOG_HOOK );
		}
	}

	/**
	 * Remove every delivery cron event (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		wp_clear_scheduled_hook( self::QUEUE_HOOK );
		wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
		wp_unschedule_hook( self::PREPARE_HOOK );
	}

	/**
	 * Cron callback: prepare one campaign.
	 *
	 * @param mixed $campaign_id Campaign ID.
	 * @return void
	 */
	public function handle_prepare( mixed $campaign_id ): void {
		$id      = absint( $campaign_id );
		$outcome = $this->preparation()->prepare( $id );
		$this->after_prepare( $id, $outcome['result'] );
	}

	/**
	 * Cron callback: run a bounded number of queue batches.
	 *
	 * @return void
	 * @throws LogicException When the worker factory returns an invalid service.
	 */
	public function handle_queue(): void {
		$worker = ( $this->worker )();
		if ( ! $worker instanceof QueueWorker ) {
			throw new LogicException( 'The queue worker is invalid.' );
		}
		$totals = $worker->run( self::CRON_BATCHES );
		$this->reschedule( $totals['claimed'] > 0 );
	}

	/**
	 * Hourly crash recovery: resume stale preparation and expired leases.
	 *
	 * @return void
	 */
	public function handle_watchdog(): void {
		$campaigns = ( $this->campaigns )();
		if ( $campaigns instanceof CampaignRepository ) {
			$cutoff = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
				->sub( new DateInterval( 'PT' . self::STALE_SECONDS . 'S' ) );
			foreach ( $campaigns->stale_building_ids( $cutoff ) as $id ) {
				$outcome = $this->preparation()->prepare( $id );
				$this->after_prepare( $id, $outcome['result'] );
			}
		}
		self::wake();
	}

	/**
	 * Follow-up scheduling after one preparation attempt.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $result      Preparation result.
	 * @return void
	 */
	private function after_prepare( int $campaign_id, string $result ): void {
		if ( CampaignPreparation::RESULT_QUEUED === $result ) {
			self::wake();
		} elseif (
			CampaignPreparation::RESULT_DEFERRED === $result
			|| CampaignPreparation::RESULT_LOCKED === $result
		) {
			self::schedule_prepare( $campaign_id, self::DEFER_SECONDS );
		}
	}

	/**
	 * Schedule the next wake-up when work remains.
	 *
	 * @param bool $did_work Whether the last run claimed anything.
	 * @return void
	 */
	private function reschedule( bool $did_work ): void {
		if ( $did_work ) {
			self::wake( time() + 5 );
			return;
		}
		$queue = ( $this->queue )();
		if ( ! $queue instanceof QueueRepository ) {
			return;
		}
		$next = $queue->next_attempt_at();
		if ( null !== $next ) {
			$timestamp = strtotime( $next . ' UTC' );
			self::wake( false === $timestamp ? time() + 60 : max( time(), $timestamp ) );
		}
	}

	/**
	 * Resolve the preparation service.
	 *
	 * @return CampaignPreparation
	 * @throws LogicException When the factory returns an invalid service.
	 */
	private function preparation(): CampaignPreparation {
		$service = ( $this->preparation )();
		if ( ! $service instanceof CampaignPreparation ) {
			throw new LogicException( 'The campaign preparation service is invalid.' );
		}

		return $service;
	}
}

// EOF: src/Delivery/DeliveryScheduler.php.
