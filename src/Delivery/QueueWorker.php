<?php
/**
 * File: src/Delivery/QueueWorker.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Delivery;

use ArgentWolf\PostNotifier\Audit\AuditEventType;
use ArgentWolf\PostNotifier\Audit\AuditService;
use ArgentWolf\PostNotifier\Campaign\CampaignAudienceSnapshot;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Campaign\RecipientLinkFactory;
use ArgentWolf\PostNotifier\Campaign\RecipientMessageBuilder;
use ArgentWolf\PostNotifier\Campaign\RecipientToken;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded, lease-based campaign delivery worker.
 *
 * One run processes at most `$max_batches` claims of a filterable batch
 * size. Every claimed row is rechecked for hard ineligibility, receives a
 * fresh per-attempt recipient token, and is submitted as one message with
 * exactly one recipient. Transport failures retry with bounded exponential
 * backoff until the maximum attempt count, then fail terminally. Rows whose
 * worker died are recovered once their lease expires. A successful transport
 * call is recorded as `submitted`, never as delivered.
 */
final class QueueWorker {
	public const DEFAULT_BATCH_SIZE    = 25;
	public const DEFAULT_MAX_ATTEMPTS  = 5;
	public const DEFAULT_LEASE_SECONDS = 300;
	public const BACKOFF_BASE_SECONDS  = 60;
	public const BACKOFF_MAX_SECONDS   = 21600;

	/**
	 * Per-run cache of campaign rows.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private array $campaign_cache = array();

	/**
	 * Construct the worker.
	 *
	 * @param QueueRepository             $queue     Lease-based queue persistence.
	 * @param CampaignRepository          $campaigns Campaign persistence.
	 * @param CampaignAudienceSnapshot    $audience  Frozen audience snapshot reader.
	 * @param RecipientEligibilityRecheck $recheck   Pre-send hard-eligibility policy.
	 * @param RecipientMessageBuilder     $messages  Per-recipient message builder.
	 * @param RecipientLinkFactory        $links     Recipient link factory.
	 * @param MailTransport               $transport Transport.
	 * @param AuditService|null           $audit     Optional campaign audit ledger.
	 */
	public function __construct(
		private QueueRepository $queue,
		private CampaignRepository $campaigns,
		private CampaignAudienceSnapshot $audience,
		private RecipientEligibilityRecheck $recheck,
		private RecipientMessageBuilder $messages,
		private RecipientLinkFactory $links,
		private MailTransport $transport,
		private ?AuditService $audit = null
	) {
	}

	/**
	 * Run up to $max_batches claims.
	 *
	 * @param int                    $max_batches Upper bound on claims this run.
	 * @param DateTimeImmutable|null $now         Optional fixed clock (tests).
	 * @return array{claimed:int,submitted:int,retried:int,failed:int,skipped:int,lost:int}
	 */
	public function run( int $max_batches = 1, ?DateTimeImmutable $now = null ): array {
		$totals  = array(
			'claimed'   => 0,
			'submitted' => 0,
			'retried'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'lost'      => 0,
		);
		$max     = $this->max_attempts();
		$touched = array();

		$batches = max( 1, $max_batches );
		$size    = $this->batch_size();
		$seconds = $this->lease_seconds();
		for ( $batch = 0; $batch < $batches; ++$batch ) {
			$clock     = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$recovered = $this->queue->fail_exhausted_expired_leases( $max, $clock );
			foreach ( $recovered as $campaign_id ) {
				$touched[ $campaign_id ] = true;
			}

			$lease = wp_generate_uuid4();
			$rows  = $this->queue->claim( $lease, $size, $seconds, $clock );
			if ( array() === $rows ) {
				break;
			}
			$totals['claimed'] += count( $rows );

			foreach ( $rows as $row ) {
				$campaign_id             = (int) $row['campaign_id'];
				$touched[ $campaign_id ] = true;
				$this->campaigns->transition(
					$campaign_id,
					array( CampaignRepository::STATUS_QUEUED ),
					CampaignRepository::STATUS_SENDING,
					'started_at_gmt',
					null,
					$clock
				);
				$outcome = $this->process( $row, $lease, $max, $clock );
				++$totals[ $outcome ];
			}
		}

		$clock = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		foreach ( array_keys( $touched ) as $campaign_id ) {
			$status = $this->queue->refresh_campaign(
				$campaign_id,
				array_sum( $this->audience->skip_counts( $campaign_id ) ),
				$clock
			);
			if ( null !== $status && null !== $this->audit ) {
				$this->audit->record_campaign_event(
					AuditEventType::CampaignCompleted,
					$campaign_id,
					$status
				);
			}
		}
		$this->campaign_cache = array();

		return $totals;
	}

	/**
	 * Process one claimed row.
	 *
	 * @param array<string,mixed> $row   Claimed row.
	 * @param string              $lease Owning lease token.
	 * @param int                 $max   Max attempts.
	 * @param DateTimeImmutable   $now   Current time.
	 * @return string One of submitted|retried|failed|skipped|lost.
	 * @throws InvalidArgumentException Internally, caught as a build failure.
	 */
	private function process(
		array $row,
		string $lease,
		int $max,
		DateTimeImmutable $now
	): string {
		$row_id      = (int) $row['id'];
		$campaign_id = (int) $row['campaign_id'];
		$uuid        = (string) $row['recipient_uuid'];

		$reason = $this->recheck->skip_reason( $row );
		if ( null !== $reason ) {
			$done = $this->queue->mark_skipped( $row_id, $lease, $reason );
			return $this->report( $campaign_id, $uuid, $done ? 'skipped' : 'lost', $reason );
		}

		$campaign = $this->campaign( $campaign_id );
		$type     = NamedListMemberType::tryFrom( (string) $row['recipient_type'] );
		$token    = RecipientToken::generate();
		try {
			if ( null === $campaign || null === $type ) {
				throw new InvalidArgumentException( 'Campaign or recipient unavailable.' );
			}
			$message = $this->messages->build(
				$campaign,
				(string) $row['email_snapshot'],
				$uuid,
				$this->links->create( $type, $token->plaintext() )
			);
		} catch ( Throwable ) {
			$done = $this->queue->mark_failed( $row_id, $lease, 'message_build_failed', $now );
			$this->audit_failure( $campaign_id, $row_id, 'message_build_failed' );
			$code = 'message_build_failed';
			return $this->report( $campaign_id, $uuid, $done ? 'failed' : 'lost', $code );
		}

		if ( ! $this->queue->set_token_hash( $row_id, $lease, $token->hash() ) ) {
			return $this->report( $campaign_id, $uuid, 'lost', 'lease_lost' );
		}

		try {
			$submitted = $this->transport->send( $message )->is_submitted();
		} catch ( Throwable ) {
			$submitted = false;
		}

		if ( $submitted ) {
			$done = $this->queue->mark_submitted( $row_id, $lease, $now );
			return $this->report( $campaign_id, $uuid, $done ? 'submitted' : 'lost', null );
		}

		$attempts = (int) $row['attempt_count'];
		if ( $attempts >= $max ) {
			$done = $this->queue->mark_failed( $row_id, $lease, 'transport_rejected', $now );
			$this->audit_failure( $campaign_id, $row_id, 'transport_rejected' );
			$code = 'transport_rejected';
			return $this->report( $campaign_id, $uuid, $done ? 'failed' : 'lost', $code );
		}

		$next = $now->add( new DateInterval( 'PT' . self::backoff_seconds( $attempts ) . 'S' ) );
		$done = $this->queue->mark_retry( $row_id, $lease, 'transport_rejected', $next );
		$code = 'transport_rejected';
		return $this->report( $campaign_id, $uuid, $done ? 'retried' : 'lost', $code );
	}

	/**
	 * Bounded exponential backoff after the given completed attempt number.
	 *
	 * @param int $attempt Attempt number that just failed (1-based).
	 * @return int Seconds.
	 */
	public static function backoff_seconds( int $attempt ): int {
		$exponent = max( 0, min( 20, $attempt - 1 ) );

		$seconds = self::BACKOFF_BASE_SECONDS * ( 2 ** $exponent );

		return (int) min( self::BACKOFF_MAX_SECONDS, $seconds );
	}

	/**
	 * Fire the privacy-safe delivery-result action.
	 *
	 * @param int         $campaign_id Campaign ID.
	 * @param string      $uuid        Recipient correlation UUID.
	 * @param string      $outcome     Outcome code.
	 * @param string|null $reason      Optional stable reason.
	 * @return string Outcome.
	 */
	private function report(
		int $campaign_id,
		string $uuid,
		string $outcome,
		?string $reason
	): string {
		/**
		 * Fires after one queue row is processed.
		 *
		 * Never receives an email address, token, or message content.
		 *
		 * @param int         $campaign_id    Campaign ID.
		 * @param string      $recipient_uuid Recipient correlation UUID.
		 * @param string      $outcome        submitted|retried|failed|skipped|lost.
		 * @param string|null $reason         Stable reason code.
		 */
		do_action(
			'argentwolf_post_notifier_delivery_result',
			$campaign_id,
			$uuid,
			$outcome,
			$reason
		);

		return $outcome;
	}

	/**
	 * Audit one terminal recipient failure.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $row_id      Recipient row ID.
	 * @param string $reason      Stable reason.
	 * @return void
	 */
	private function audit_failure( int $campaign_id, int $row_id, string $reason ): void {
		if ( null === $this->audit ) {
			return;
		}
		try {
			$this->audit->record_campaign_event(
				AuditEventType::RecipientFailed,
				$campaign_id,
				$reason,
				$row_id
			);
		} catch ( Throwable ) {
			// Auditing must not block delivery bookkeeping.
			return;
		}
	}

	/**
	 * Cached campaign row.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string,mixed>|null
	 */
	private function campaign( int $campaign_id ): ?array {
		if ( ! array_key_exists( $campaign_id, $this->campaign_cache ) ) {
			$this->campaign_cache[ $campaign_id ] = $this->campaigns->find( $campaign_id );
		}

		return $this->campaign_cache[ $campaign_id ];
	}

	/**
	 * Filterable batch size, bounded to 1-200.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		/**
		 * Filter the number of recipients claimed per worker batch.
		 *
		 * @param int $size Default 25.
		 */
		$size = (int) apply_filters(
			'argentwolf_post_notifier_queue_batch_size',
			self::DEFAULT_BATCH_SIZE
		);

		return max( 1, min( 200, $size ) );
	}

	/**
	 * Filterable maximum attempts, bounded to 1-10.
	 *
	 * @return int
	 */
	private function max_attempts(): int {
		/**
		 * Filter the maximum submission attempts per recipient.
		 *
		 * @param int $attempts Default 5.
		 */
		$attempts = (int) apply_filters(
			'argentwolf_post_notifier_queue_max_attempts',
			self::DEFAULT_MAX_ATTEMPTS
		);

		return max( 1, min( 10, $attempts ) );
	}

	/**
	 * Filterable lease length, bounded to 30-3600 seconds.
	 *
	 * @return int
	 */
	private function lease_seconds(): int {
		/**
		 * Filter the recipient lease length in seconds.
		 *
		 * @param int $seconds Default 300.
		 */
		$seconds = (int) apply_filters(
			'argentwolf_post_notifier_queue_lease_seconds',
			self::DEFAULT_LEASE_SECONDS
		);

		return max( 30, min( 3600, $seconds ) );
	}
}

// EOF: src/Delivery/QueueWorker.php.
