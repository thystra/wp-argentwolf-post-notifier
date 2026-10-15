<?php
/**
 * File: src/Campaign/CampaignPreparation.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Audit\AuditEventType;
use ArgentWolf\PostNotifier\Audit\AuditService;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use wpdb;

/**
 * Asynchronous building-to-queued preparation of one campaign.
 *
 * Runs outside the publish request (WP-Cron, watchdog, or WP-CLI). Steps:
 * freeze recipient-neutral bodies, evaluate the pre-queue eligibility gate,
 * then resolve and snapshot the frozen audience and atomically queue it. A
 * per-campaign advisory lock serializes concurrent invocations; every step is
 * itself idempotent so a crash at any point can be resumed.
 */
final class CampaignPreparation {
	public const RESULT_QUEUED    = 'queued';
	public const RESULT_COMPLETED = 'completed';
	public const RESULT_LOCKED    = 'locked';
	public const RESULT_MISSING   = 'missing';
	public const RESULT_SKIPPED   = 'not_building';
	public const RESULT_DEFERRED  = 'deferred';
	public const RESULT_FAILED    = 'failed';

	/**
	 * Default longest wait for a retryable preparation condition (seconds).
	 */
	public const MAX_DEFERRAL_SECONDS = 86400;

	/**
	 * Construct the preparation service.
	 *
	 * @param CampaignRepository            $campaigns Campaign persistence.
	 * @param CampaignRenderedBodyFinalizer $finalizer Recipient-neutral body finalizer.
	 * @param CampaignQueueGate             $gate      Pre-queue eligibility gate.
	 * @param CampaignAudienceSnapshot      $audience  Audience snapshot/materializer.
	 * @param AuditService|null             $audit     Optional audit ledger.
	 * @param wpdb|null                     $database  Optional database connection for locking.
	 * @throws LogicException When the database is unavailable.
	 */
	public function __construct(
		private CampaignRepository $campaigns,
		private CampaignRenderedBodyFinalizer $finalizer,
		private CampaignQueueGate $gate,
		private CampaignAudienceSnapshot $audience,
		private ?AuditService $audit = null,
		private ?wpdb $database = null
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb instanceof wpdb ? $wpdb : null;
		}
		if ( null === $this->database ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}
	}

	/**
	 * Prepare one campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{result:string,code:string}
	 */
	public function prepare( int $campaign_id ): array {
		if ( $campaign_id < 1 ) {
			return $this->result( self::RESULT_MISSING, 'invalid_id' );
		}
		$lock = $this->database->prefix . 'argentwolf_pn_prepare_' . $campaign_id;
		if ( ! $this->acquire( $lock ) ) {
			return $this->result( self::RESULT_LOCKED, 'locked' );
		}

		try {
			return $this->prepare_locked( $campaign_id );
		} finally {
			$this->release( $lock );
		}
	}

	/**
	 * Preparation body while the per-campaign lock is held.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array{result:string,code:string}
	 */
	private function prepare_locked( int $campaign_id ): array {
		$row = $this->campaigns->find( $campaign_id );
		if ( null === $row ) {
			return $this->result( self::RESULT_MISSING, 'missing' );
		}
		if ( CampaignRepository::STATUS_BUILDING !== $row['status'] ) {
			return $this->result( self::RESULT_SKIPPED, (string) $row['status'] );
		}

		try {
			$this->finalizer->finalize( $campaign_id );
		} catch ( InvalidArgumentException | UnexpectedValueException | RuntimeException ) {
			return $this->fail( $campaign_id, 'render_failed' );
		}

		$row = $this->campaigns->find( $campaign_id );
		if ( null === $row ) {
			return $this->result( self::RESULT_MISSING, 'missing' );
		}
		$gate = $this->gate->check( $row, $this->audience->read_config( $campaign_id ) );
		if ( CampaignQueueGate::OK !== $gate['code'] ) {
			if ( $gate['retryable'] ) {
				return $this->defer( $campaign_id, $row, $gate['code'] );
			}
			return $this->fail( $campaign_id, $gate['code'] );
		}

		try {
			$outcome = $this->audience->materialize( $campaign_id );
		} catch ( RuntimeException ) {
			return $this->defer( $campaign_id, $row, 'audience_persistence_error' );
		}
		if ( null === $outcome ) {
			return $this->fail( $campaign_id, CampaignQueueGate::MISSING_AUDIENCE_SNAPSHOT );
		}

		$result = CampaignRepository::STATUS_COMPLETED === $outcome['status']
			? self::RESULT_COMPLETED
			: self::RESULT_QUEUED;
		if ( $outcome['queued'] ) {
			$this->record( AuditEventType::CampaignQueued, $campaign_id, $outcome['status'] );
		}

		return $this->result( $result, $outcome['status'] );
	}

	/**
	 * Defer a retryable condition, or fail closed once the deferral window ends.
	 *
	 * The window is measured from campaign creation so it is bounded no matter
	 * how often cron, the watchdog or an operator retries preparation.
	 *
	 * @param int                 $campaign_id Campaign ID.
	 * @param array<string,mixed> $row         Campaign row.
	 * @param string              $code        Stable retryable code.
	 * @return array{result:string,code:string}
	 */
	private function defer( int $campaign_id, array $row, string $code ): array {
		/**
		 * Filter the longest time a campaign may wait in building for a retryable condition.
		 *
		 * @param int $seconds Default one day.
		 */
		$limit   = (int) apply_filters(
			'argentwolf_post_notifier_max_preparation_deferral_seconds',
			self::MAX_DEFERRAL_SECONDS
		);
		$created = strtotime( (string) ( $row['created_at_gmt'] ?? '' ) . ' UTC' );
		if ( false === $created || time() - $created >= max( 0, $limit ) ) {
			return $this->fail( $campaign_id, $code );
		}
		$this->campaigns->note_building_error( $campaign_id, $code );

		return $this->result( self::RESULT_DEFERRED, $code );
	}

	/**
	 * Fail a building campaign closed with a stable code.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $code        Stable code.
	 * @return array{result:string,code:string}
	 */
	private function fail( int $campaign_id, string $code ): array {
		$changed = $this->campaigns->transition(
			$campaign_id,
			array( CampaignRepository::STATUS_BUILDING ),
			CampaignRepository::STATUS_FAILED,
			'completed_at_gmt',
			$code
		);
		if ( $changed ) {
			$this->record( AuditEventType::CampaignFailed, $campaign_id, $code );
		}

		return $this->result( self::RESULT_FAILED, $code );
	}

	/**
	 * Record one audit event without letting audit failure block preparation.
	 *
	 * @param AuditEventType $type        Event type.
	 * @param int            $campaign_id Campaign ID.
	 * @param string         $reason      Stable reason.
	 * @return void
	 */
	private function record( AuditEventType $type, int $campaign_id, string $reason ): void {
		if ( null === $this->audit ) {
			return;
		}
		try {
			$this->audit->record_campaign_event( $type, $campaign_id, $reason );
		} catch ( Throwable ) {
			return;
		}
	}

	/**
	 * Acquire a non-blocking advisory lock.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	private function acquire( string $name ): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $this->database->get_var(
			$this->database->prepare( 'SELECT GET_LOCK( %s, 0 )', substr( $name, 0, 64 ) )
		);
		// phpcs:enable

		return '1' === (string) $value;
	}

	/**
	 * Release an advisory lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private function release( string $name ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->database->get_var(
			$this->database->prepare( 'SELECT RELEASE_LOCK( %s )', substr( $name, 0, 64 ) )
		);
		// phpcs:enable
	}

	/**
	 * Build one result.
	 *
	 * @param string $result Result class.
	 * @param string $code   Stable code.
	 * @return array{result:string,code:string}
	 */
	private function result( string $result, string $code ): array {
		return array(
			'result' => $result,
			'code'   => $code,
		);
	}
}

// EOF: src/Campaign/CampaignPreparation.php.
