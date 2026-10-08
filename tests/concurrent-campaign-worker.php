<?php
/**
 * File: tests/concurrent-campaign-worker.php
 *
 * Isolated, real-WordPress worker for the cross-process campaign race test.
 * Run only from tests/concurrent-campaigns.sh against a disposable WordPress site.
 *
 * @package ArgentWolf\PostNotifier\Tests
 */

use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Campaign\PublicationObserver;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;

if ( PHP_SAPI !== 'cli' || count( $argv ) < 4 ) {
	fwrite( STDERR, "Usage: php concurrent-campaign-worker.php MODE WP_ROOT RUN_DIR [WORKER_ID]\n" );
	exit( 2 );
}

$mode       = $argv[1];
$wp_root    = rtrim( $argv[2], '/\\' );
$run_dir    = rtrim( $argv[3], '/\\' );
$worker_id  = $argv[4] ?? '';
$wordpress  = $wp_root . '/wp-load.php';

if ( ! is_readable( $wordpress ) || ! is_dir( $run_dir ) ) {
	fwrite( STDERR, "WordPress bootstrap or test coordination directory unavailable.\n" );
	exit( 2 );
}

// Load the exact installed and active plugin; never load source files directly.
require $wordpress;

/**
 * Fail an isolated test worker without depending on WordPress test factories.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Failure message.
 * @return void
 */
function argentwolf_post_notifier_test_concurrent_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Read the committed fixture post identifier shared by the workers.
 *
 * @param string $directory Coordination directory.
 * @return int Post identifier.
 */
function argentwolf_post_notifier_test_concurrent_post_id( string $directory ): int {
	$path = $directory . '/post-id';
	$id   = is_readable( $path ) ? (int) file_get_contents( $path ) : 0;
	argentwolf_post_notifier_test_concurrent_assert( $id > 0, 'Fixture post ID unavailable.' );
	return $id;
}

/**
 * Wait for a coordinator signal with a bounded deadline.
 *
 * @param string $path    Signal filename.
 * @param string $purpose Purpose for a failure message.
 * @return void
 */
function argentwolf_post_notifier_test_concurrent_wait( string $path, string $purpose ): void {
	$deadline = microtime( true ) + 45;
	while ( ! file_exists( $path ) ) {
		if ( microtime( true ) >= $deadline ) {
			throw new RuntimeException( 'Timed out waiting for ' . $purpose . '.' );
		}
		usleep( 25000 );
	}
}

/**
 * Exercise the actual publication observer with a published post and draft prior state.
 *
 * The fixture was published before its explicit send intent was assigned so the
 * only observers capable of reserving its initial campaign are these workers.
 *
 * @param int $post_id Fixture post ID.
 * @return void
 */
function argentwolf_post_notifier_test_concurrent_observe( int $post_id ): void {
	$post = get_post( $post_id );
	argentwolf_post_notifier_test_concurrent_assert( $post instanceof WP_Post, 'Published fixture is missing.' );
	argentwolf_post_notifier_test_concurrent_assert( 'publish' === $post->post_status, 'Fixture is not published.' );

	$prior              = clone $post;
	$prior->post_status = 'draft';

	// The observer intentionally swallows persistence exceptions after publication;
	// turn its privacy-safe diagnostic action into a hard test failure here.
	add_action(
		'argentwolf_post_notifier_campaign_creation_failed',
		static function ( int $failed_post_id, string $reason ) use ( $post_id ): void {
			if ( $failed_post_id === $post_id ) {
				throw new RuntimeException( 'Publication observer failed: ' . $reason );
			}
		},
		10,
		2
	);

	$observer = new PublicationObserver( new CampaignRepository() );
	$observer->observe_publication( $post_id, $post, true, $prior );
}

try {
	global $wpdb;
	argentwolf_post_notifier_test_concurrent_assert( $wpdb instanceof wpdb, 'WordPress database unavailable.' );

	$campaigns = new CampaignRepository( $wpdb );
	$tables    = TableNames::from_database( $wpdb );

	switch ( $mode ) {
		case 'seed':
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'ArgentWolf Post Notifier concurrent campaign fixture',
					'post_content' => 'Concurrency test only. No notification delivery.',
				),
				true
			);
			argentwolf_post_notifier_test_concurrent_assert( is_int( $post_id ) && $post_id > 0, 'Fixture creation failed.' );

			// Deliberately set send intent after initial publication (no campaign yet).
			update_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, SendIntent::Send->value );
			update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, ContentMode::Full->value );
			$key = CampaignRepository::initial_key( get_current_blog_id(), $post_id );
			argentwolf_post_notifier_test_concurrent_assert( null === $campaigns->find_by_key( $key ), 'Fixture already has a campaign.' );
			argentwolf_post_notifier_test_concurrent_assert(
				false !== file_put_contents( $run_dir . '/post-id', (string) $post_id ),
				'Could not write fixture identifier.'
			);
			break;

		case 'holder':
			$post_id = argentwolf_post_notifier_test_concurrent_post_id( $run_dir );
			$key     = CampaignRepository::initial_key( get_current_blog_id(), $post_id );
			argentwolf_post_notifier_test_concurrent_assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not begin transaction.' );
			argentwolf_post_notifier_test_concurrent_observe( $post_id );
			$row = $campaigns->find_by_key( $key );
			argentwolf_post_notifier_test_concurrent_assert( null !== $row, 'Holding transaction has no campaign row.' );
			argentwolf_post_notifier_test_concurrent_assert(
				false !== file_put_contents( $run_dir . '/holder-ready', (string) $row['id'] ),
				'Could not report held reservation.'
			);
			// The row and its unique key remain uncommitted while contenders arrive.
			argentwolf_post_notifier_test_concurrent_wait( $run_dir . '/commit', 'transaction release' );
			argentwolf_post_notifier_test_concurrent_assert( false !== $wpdb->query( 'COMMIT' ), 'Could not commit reservation.' );
			argentwolf_post_notifier_test_concurrent_assert(
				false !== file_put_contents( $run_dir . '/result-holder', (string) $row['id'] ),
				'Could not report committed reservation.'
			);
			break;

		case 'contender':
			argentwolf_post_notifier_test_concurrent_assert( preg_match( '/^[1-9][0-9]*$/', $worker_id ) === 1, 'Invalid worker ID.' );
			$post_id = argentwolf_post_notifier_test_concurrent_post_id( $run_dir );
			$key     = CampaignRepository::initial_key( get_current_blog_id(), $post_id );
			argentwolf_post_notifier_test_concurrent_wait( $run_dir . '/holder-ready', 'held reservation' );
			argentwolf_post_notifier_test_concurrent_assert(
				false !== file_put_contents( $run_dir . '/attempt-' . $worker_id, '1' ),
				'Could not report contention attempt.'
			);
			argentwolf_post_notifier_test_concurrent_observe( $post_id );
			$row = $campaigns->find_by_key( $key );
			argentwolf_post_notifier_test_concurrent_assert( null !== $row, 'Contender did not resolve a campaign.' );
			argentwolf_post_notifier_test_concurrent_assert(
				false !== file_put_contents( $run_dir . '/result-' . $worker_id, (string) $row['id'] ),
				'Could not report contender result.'
			);
			break;

		case 'verify':
			argentwolf_post_notifier_test_concurrent_assert( preg_match( '/^[1-9][0-9]*$/', $worker_id ) === 1, 'Invalid worker count.' );
			$post_id  = argentwolf_post_notifier_test_concurrent_post_id( $run_dir );
			$key      = CampaignRepository::initial_key( get_current_blog_id(), $post_id );
			$expected = (int) file_get_contents( $run_dir . '/result-holder' );
			$row      = $campaigns->find_by_key( $key );
			argentwolf_post_notifier_test_concurrent_assert( $expected > 0 && null !== $row, 'Committed campaign is missing.' );
			argentwolf_post_notifier_test_concurrent_assert( (int) $row['id'] === $expected, 'Campaign ID changed.' );
			argentwolf_post_notifier_test_concurrent_assert( 'building' === $row['status'], 'Campaign build state was altered.' );
			argentwolf_post_notifier_test_concurrent_assert( 'post' === $row['campaign_kind'], 'Campaign kind was altered.' );
			argentwolf_post_notifier_test_concurrent_assert( ContentMode::Full->value === $row['content_mode'], 'Content mode was altered.' );
			argentwolf_post_notifier_test_concurrent_assert(
				'' === $row['subject'] && '' === $row['html_body'] && '' === $row['text_body'],
				'Content was populated before build qualification.'
			);

			for ( $i = 1; $i <= (int) $worker_id; ++$i ) {
				$result_path = $run_dir . '/result-' . $i;
				argentwolf_post_notifier_test_concurrent_assert( is_readable( $result_path ), 'Contender result missing.' );
				argentwolf_post_notifier_test_concurrent_assert(
				(int) file_get_contents( $result_path ) === $expected,
				'Concurrent contender did not resolve the original campaign.'
				);
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_key = %s', $tables->campaigns(), $key )
			);
			$post_campaigns = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE post_id = %d AND campaign_kind = %s',
					$tables->campaigns(),
					$post_id,
					CampaignRepository::KIND_POST
				)
			);
			$recipients = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id = %d', $tables->campaign_recipients(), $expected )
			);
			// phpcs:enable
			argentwolf_post_notifier_test_concurrent_assert( 1 === $count, 'Concurrent attempts created duplicate campaign keys.' );
			argentwolf_post_notifier_test_concurrent_assert( 1 === $post_campaigns, 'Concurrent attempts created multiple post campaigns.' );
			argentwolf_post_notifier_test_concurrent_assert( 0 === $recipients, 'Reservation unexpectedly created recipients.' );
			printf( "CONCURRENT_CAMPAIGN_RESERVATION=PASS contenders=%d unique_rows=%d recipients=%d\n", (int) $worker_id, $count, $recipients );
			break;

		case 'cleanup':
			if ( is_readable( $run_dir . '/post-id' ) ) {
				$post_id = argentwolf_post_notifier_test_concurrent_post_id( $run_dir );
				$key     = CampaignRepository::initial_key( get_current_blog_id(), $post_id );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE campaign_key = %s', $tables->campaigns(), $key ) );
				// phpcs:enable
				wp_delete_post( $post_id, true );
			}
			break;

		default:
			throw new RuntimeException( 'Unknown concurrency test mode.' );
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Concurrent campaign qualification failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

// EOF: tests/concurrent-campaign-worker.php.
