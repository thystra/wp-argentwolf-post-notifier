<?php
/**
 * File: tests/concurrent-queue-worker.php
 *
 * Isolated, real-WordPress entry point for the cross-process delivery-queue
 * lease test. Run only from tests/concurrent-queue.sh against a disposable
 * WordPress site: it creates subscribers, a post, a campaign and a scratch
 * mail-log table, and deletes them again in cleanup mode.
 *
 * Mail never leaves the process: `pre_wp_mail` records each submission in a
 * shared, non-transactional table so duplicates across processes are visible.
 *
 * @package ArgentWolf\PostNotifier\Tests
 */

use ArgentWolf\PostNotifier\Campaign\CampaignPreparation;
use ArgentWolf\PostNotifier\Campaign\CampaignRecipientRepository;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Delivery\QueueRepository;
use ArgentWolf\PostNotifier\Delivery\QueueWorker;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;

if ( PHP_SAPI !== 'cli' || count( $argv ) < 4 ) {
	fwrite( STDERR, "Usage: php concurrent-queue-worker.php MODE WP_ROOT RUN_DIR [ARG]\n" );
	exit( 2 );
}

$mode    = $argv[1];
$wp_root = rtrim( $argv[2], '/\\' );
$run_dir = rtrim( $argv[3], '/\\' );
$arg     = $argv[4] ?? '';

if ( ! is_readable( $wp_root . '/wp-load.php' ) || ! is_dir( $run_dir ) ) {
	fwrite( STDERR, "WordPress bootstrap or test coordination directory unavailable.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'post-notifier-ci.invalid';
// Only the processes started by the harness may run the queue.
define( 'DISABLE_WP_CRON', true );
require $wp_root . '/wp-load.php';

/**
 * Fail the qualification unless a condition holds.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message without recipient information.
 * @return void
 * @throws RuntimeException When the condition is false.
 */
function argentwolf_post_notifier_test_queue_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Read one integer written by an earlier phase.
 *
 * @param string $path Coordination file.
 * @return int
 */
function argentwolf_post_notifier_test_queue_int( string $path ): int {
	return is_readable( $path ) ? (int) file_get_contents( $path ) : 0;
}

/**
 * Wait for a coordinator signal with a bounded deadline.
 *
 * @param string $path Signal filename.
 * @return void
 * @throws RuntimeException When the deadline passes.
 */
function argentwolf_post_notifier_test_queue_wait( string $path ): void {
	$deadline = microtime( true ) + 45;
	while ( ! file_exists( $path ) ) {
		if ( microtime( true ) >= $deadline ) {
			throw new RuntimeException( 'Timed out waiting for the start signal.' );
		}
		usleep( 10000 );
	}
}

/**
 * Record each wp_mail() call in the shared log instead of sending it.
 *
 * @param string $log    Mail-log table.
 * @param string $worker Worker label.
 * @return void
 */
function argentwolf_post_notifier_test_queue_capture( string $log, string $worker ): void {
	add_filter(
		'pre_wp_mail',
		static function ( $short_circuit, array $atts ) use ( $log, $worker ) {
			global $wpdb;
			unset( $short_circuit );
			$uuid = '';
			foreach ( (array) ( $atts['headers'] ?? array() ) as $header ) {
				if ( 1 === preg_match( '/^X-ArgentWolf-PN-Recipient:\s*(\S+)/i', (string) $header, $m ) ) {
					$uuid = $m[1];
				}
			}
			// Widen the race window between claim and completion.
			usleep( 4000 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$log,
				array(
					'recipient_uuid' => $uuid,
					'worker'         => $worker,
				),
				array( '%s', '%s' )
			);
			return true;
		},
		10,
		2
	);
}

try {
	global $wpdb;
	$check = 'argentwolf_post_notifier_test_queue_assert';
	$check( $wpdb instanceof wpdb, 'WordPress database unavailable.' );

	$tables     = TableNames::from_database( $wpdb );
	$recipients = $tables->campaign_recipients();
	$log        = $wpdb->prefix . 'awpn_test_mail_log';
	$services   = Plugin::instance()->container();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
	switch ( $mode ) {
		case 'seed':
			$count = (int) $arg;
			$check( $count > 0 && $count <= 500, 'Recipient count must be 1-500.' );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $log ) );
			$wpdb->query(
				$wpdb->prepare(
					'CREATE TABLE %i (
						id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						recipient_uuid char(36) NOT NULL,
						worker varchar(32) NOT NULL,
						PRIMARY KEY  (id),
						KEY recipient_uuid (recipient_uuid)
					) ENGINE=InnoDB ' . $wpdb->get_charset_collate(),
					$log
				)
			);
			$check( '' === $wpdb->last_error, 'Mail-log table could not be created.' );

			$subscribers = $services->get( SubscriberService::class );
			$check( $subscribers instanceof SubscriberService, 'Subscriber service unavailable.' );
			$run_tag = substr( md5( $run_dir ), 0, 10 );
			$ids     = array();
			for ( $i = 1; $i <= $count; ++$i ) {
				$signup = $subscribers->request_confirmation(
					sprintf( 'queue-%s-%03d@example.invalid', $run_tag, $i ),
					null,
					'Concurrency test fixture consent.',
					'concurrency-test'
				);
				$check(
					$subscribers->confirm( (string) $signup->confirmation_token() ),
					'Fixture subscriber could not be confirmed.'
				);
				$ids[] = $signup->subscriber_id();
			}
			file_put_contents( $run_dir . '/subscriber-ids', implode( ',', $ids ) );

			$post_id = wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'ArgentWolf Post Notifier queue concurrency fixture',
					'post_content' => 'Concurrency test only. Mail is captured, never sent.',
				),
				true
			);
			$check( is_int( $post_id ) && $post_id > 0, 'Fixture post creation failed.' );
			file_put_contents( $run_dir . '/post-id', (string) $post_id );
			$audience                            = PostNotificationMeta::default_audience_config();
			$audience['included_subscriber_ids'] = $ids;
			update_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, 'send' );
			update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );
			update_post_meta( $post_id, PostNotificationMeta::AUDIENCE_CONFIG_KEY, $audience );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			$campaign = ( new CampaignRepository( $wpdb ) )->find_by_key(
				CampaignRepository::initial_key( get_current_blog_id(), $post_id )
			);
			$check( null !== $campaign, 'Publication did not reserve a campaign.' );
			$campaign_id = (int) $campaign['id'];
			file_put_contents( $run_dir . '/campaign-id', (string) $campaign_id );
			$preparation = $services->get( CampaignPreparation::class );
			$check( $preparation instanceof CampaignPreparation, 'Preparation unavailable.' );
			$outcome = $preparation->prepare( $campaign_id );
			$check(
				CampaignPreparation::RESULT_QUEUED === $outcome['result'],
				'Campaign was not queued: ' . $outcome['code']
			);
			$queued = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND status = %s',
					$recipients,
					$campaign_id,
					CampaignRecipientRepository::STATUS_QUEUED
				)
			);
			$check( $count === $queued, 'Queued recipient rows do not match the fixture.' );
			break;

		case 'dier':
			// Claim one batch, finish part of it, then die with the rest leased.
			$batch = max( 2, (int) $arg );
			argentwolf_post_notifier_test_queue_capture( $log, 'dier' );
			$queue = new QueueRepository( $wpdb );
			$lease = wp_generate_uuid4();
			$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$rows  = $queue->claim( $lease, $batch, 300, $now );
			$check( count( $rows ) === $batch, 'Dying worker could not claim its batch.' );
			$finished = array_slice( $rows, 0, 2 );
			foreach ( $finished as $row ) {
				$hash = hash( 'sha256', wp_generate_uuid4() );
				$check( $queue->set_token_hash( (int) $row['id'], $lease, $hash ), 'Dier lost its lease early.' );
				wp_mail(
					'fixture@example.invalid',
					'fixture',
					'fixture',
					array( 'X-ArgentWolf-PN-Recipient: ' . $row['recipient_uuid'] )
				);
				$check( $queue->mark_submitted( (int) $row['id'], $lease, $now ), 'Dier could not submit.' );
			}
			$abandoned = array_map(
				static fn ( array $row ): int => (int) $row['id'],
				array_slice( $rows, 2 )
			);
			file_put_contents( $run_dir . '/dier-lease', $lease );
			file_put_contents( $run_dir . '/dier-abandoned', implode( ',', $abandoned ) );
			// The process "crashes" here. Simulate its lease running out.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET lease_expires_at_gmt = %s WHERE lease_token = %s',
					$recipients,
					gmdate( 'Y-m-d H:i:s', time() - 1 ),
					$lease
				)
			);
			break;

		case 'worker':
			$check( 1 === preg_match( '/^[1-9][0-9]*$/', $arg ), 'Invalid worker ID.' );
			argentwolf_post_notifier_test_queue_capture( $log, 'worker-' . $arg );
			add_filter( 'argentwolf_post_notifier_queue_batch_size', static fn (): int => 5 );
			$worker = $services->get( QueueWorker::class );
			$check( $worker instanceof QueueWorker, 'Queue worker unavailable.' );
			file_put_contents( $run_dir . '/ready-' . $arg, '1' );
			argentwolf_post_notifier_test_queue_wait( $run_dir . '/go' );
			$totals = $worker->run( 200 );
			file_put_contents( $run_dir . '/totals-' . $arg, (string) wp_json_encode( $totals ) );
			break;

		case 'zombie':
			// The dead worker wakes up and tries to finish with its stale lease.
			$queue = new QueueRepository( $wpdb );
			$lease = (string) file_get_contents( $run_dir . '/dier-lease' );
			$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			foreach ( explode( ',', (string) file_get_contents( $run_dir . '/dier-abandoned' ) ) as $id ) {
				$row_id = (int) $id;
				$hash   = str_repeat( 'a', 64 );
				$check( ! $queue->set_token_hash( $row_id, $lease, $hash ), 'Stale lease rewrote a token.' );
				$check( ! $queue->mark_submitted( $row_id, $lease, $now ), 'Stale lease marked a row submitted.' );
				$check( ! $queue->mark_failed( $row_id, $lease, 'zombie', $now ), 'Stale lease failed a row.' );
				$check( ! $queue->mark_skipped( $row_id, $lease, 'zombie' ), 'Stale lease skipped a row.' );
			}
			break;

		case 'verify':
			$workers     = (int) $arg;
			$count       = count( explode( ',', (string) file_get_contents( $run_dir . '/subscriber-ids' ) ) );
			$campaign_id = argentwolf_post_notifier_test_queue_int( $run_dir . '/campaign-id' );
			$campaign    = ( new CampaignRepository( $wpdb ) )->find( $campaign_id );
			$check( null !== $campaign, 'Campaign disappeared.' );

			$sent      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $log ) );
			$distinct  = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(DISTINCT recipient_uuid) FROM %i', $log )
			);
			$unmatched = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i l LEFT JOIN %i r
						ON r.recipient_uuid = l.recipient_uuid AND r.campaign_id = %d
					WHERE r.id IS NULL',
					$log,
					$recipients,
					$campaign_id
				)
			);
			$check( '' === $wpdb->last_error, 'A verification query failed.' );
			$check( $count === $sent, sprintf( 'Expected %d submissions, saw %d.', $count, $sent ) );
			$check( $count === $distinct, sprintf( 'Duplicate or missing recipients: %d distinct.', $distinct ) );
			$check( 0 === $unmatched, 'A submission did not match a campaign recipient row.' );

			$states = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT status, COUNT(*) AS n, SUM(lease_token IS NOT NULL) AS leased,
						SUM(unsubscribe_token_hash IS NULL) AS no_token
					FROM %i WHERE campaign_id = %d GROUP BY status',
					$recipients,
					$campaign_id
				),
				ARRAY_A
			);
			$check( '' === $wpdb->last_error && is_array( $states ), 'Recipient state query failed.' );
			$check( 1 === count( $states ), 'Recipient rows are not all in one final state.' );
			$check( 'submitted' === $states[0]['status'], 'Recipient rows are not all submitted.' );
			$check( $count === (int) $states[0]['n'], 'Recipient row count changed.' );
			$check( 0 === (int) $states[0]['leased'], 'A submitted row still holds a lease.' );
			$check( 0 === (int) $states[0]['no_token'], 'A submitted row has no recipient token.' );
			$check( CampaignRepository::STATUS_COMPLETED === $campaign['status'], 'Campaign did not complete.' );
			$check( $count === (int) $campaign['submitted_count'], 'Campaign submitted_count is wrong.' );
			$check( 0 === (int) $campaign['failed_count'], 'Campaign failed_count is not zero.' );

			// Takeover: abandoned rows were sent once, by a live worker, on attempt two.
			$abandoned = array_map(
				'intval',
				explode( ',', (string) file_get_contents( $run_dir . '/dier-abandoned' ) )
			);
			foreach ( $abandoned as $row_id ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT recipient_uuid, attempt_count FROM %i WHERE id = %d',
						$recipients,
						$row_id
					),
					ARRAY_A
				);
				$check( 2 === (int) $row['attempt_count'], 'Abandoned row was not retaken exactly once.' );
				$by = $wpdb->get_col(
					$wpdb->prepare( 'SELECT worker FROM %i WHERE recipient_uuid = %s', $log, $row['recipient_uuid'] )
				);
				$check(
					1 === count( $by ) && str_starts_with( $by[0], 'worker-' ),
					'Abandoned row was not taken over.'
				);
			}
			$by_dier = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE worker = %s', $log, 'dier' )
			);
			$check( 2 === $by_dier, 'The dying worker\'s finished rows were resent or lost.' );

			$claimed = 0;
			$lost    = 0;
			$active  = 0;
			for ( $i = 1; $i <= $workers; ++$i ) {
				$totals = json_decode( (string) file_get_contents( $run_dir . '/totals-' . $i ), true );
				$check( is_array( $totals ), 'Worker totals missing.' );
				$claimed += (int) $totals['claimed'];
				$lost    += (int) $totals['lost'];
				$active  += (int) $totals['claimed'] > 0 ? 1 : 0;
				$check(
					0 === (int) $totals['failed'] && 0 === (int) $totals['retried'],
					'A worker failed or retried.'
				);
			}
			$check( 0 === $lost, 'A live worker lost a lease (lease-mismatch write).' );
			$check( $count - 2 === $claimed, sprintf( 'Live workers claimed %d rows.', $claimed ) );
			$check( $active >= 2, 'Fewer than two workers claimed rows; no real contention.' );
			printf(
				"CONCURRENT_QUEUE_LEASES=PASS workers=%d active=%d recipients=%d submitted=%d takeover=%d lost=0\n",
				(int) $workers,
				(int) $active,
				(int) $count,
				(int) $sent,
				count( $abandoned )
			);
			break;

		case 'cleanup':
			$campaign_id = argentwolf_post_notifier_test_queue_int( $run_dir . '/campaign-id' );
			if ( $campaign_id > 0 ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE campaign_id = %d', $recipients, $campaign_id ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $tables->campaigns(), $campaign_id ) );
			}
			$post_id = argentwolf_post_notifier_test_queue_int( $run_dir . '/post-id' );
			if ( $post_id > 0 ) {
				wp_delete_post( $post_id, true );
			}
			if ( is_readable( $run_dir . '/subscriber-ids' ) ) {
				$raw = (string) file_get_contents( $run_dir . '/subscriber-ids' );
				$ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );
				foreach ( $ids as $id ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $tables->subscribers(), $id ) );
				}
			}
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $log ) );
			break;

		default:
			throw new RuntimeException( 'Unknown queue concurrency test mode.' );
	}
	// phpcs:enable
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Concurrent queue qualification failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

// EOF: tests/concurrent-queue-worker.php.
