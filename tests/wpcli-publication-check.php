<?php
/**
 * File: tests/wpcli-publication-check.php
 *
 * Assertions and fixture setup used by wpcli-publication.sh on a disposable
 * WordPress installation with the exact distribution package activated.
 * Invoked exclusively through `wp eval-file` (not shipped in the package).
 *
 * @package ArgentWolf\PostNotifier\Tests
 */

use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Editor\ContentMode;

/**
 * Abort the WP-CLI qualification on a failed assertion.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message   Description without private recipient information.
 * @return void
 * @throws RuntimeException When the condition is false.
 */
function argentwolf_post_notifier_wpcli_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$check    = 'argentwolf_post_notifier_wpcli_assert';
$mode     = getenv( 'AWPN_CLI_TEST_MODE' );
$post_id  = (int) getenv( 'AWPN_CLI_TEST_POST_ID' );
$expected = (int) getenv( 'AWPN_CLI_TEST_EXPECT_ID' );

$check( PHP_SAPI === 'cli', 'WP-CLI PHP runtime required.' );
$check( is_string( $mode ) && '' !== $mode, 'Test mode is required.' );
$check( $post_id > 0, 'Test post ID is required.' );
$check( class_exists( CampaignRepository::class ), 'Installed notifier is not active.' );

/** @var wpdb $wpdb */
global $wpdb;
$campaigns = new CampaignRepository( $wpdb );
$tables    = TableNames::from_database( $wpdb );
$key       = CampaignRepository::initial_key( get_current_blog_id(), $post_id );

if ( 'backdate' === $mode ) {
	$post = get_post( $post_id );
	$check( $post instanceof WP_Post, 'Scheduled fixture is missing.' );
	$check( 'future' === $post->post_status, 'Fixture must still be scheduled.' );
	$check(
		false !== wp_next_scheduled( 'publish_future_post', array( $post_id ) ),
		'WordPress did not schedule the publication cron event.'
	);

	// Backdate the DB fixture without triggering a WordPress save or observer.
	// The previously scheduled core cron event remains available for WP-CLI run.
	$gmt = gmdate( 'Y-m-d H:i:s', time() - 120 );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->update(
		$wpdb->posts,
		array(
			'post_date'     => get_date_from_gmt( $gmt ),
			'post_date_gmt' => $gmt,
		),
		array( 'ID' => $post_id )
	);
	// phpcs:enable
	$check( 1 === $updated, 'Could not backdate scheduled fixture.' );
	clean_post_cache( $post_id );
	return;
}

if ( 'cleanup' === $mode ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	$removed = $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE campaign_key = %s', $tables->campaigns(), $key )
	);
	// phpcs:enable
	$check( false !== $removed, 'Failed to clean up campaign fixture.' );
	return;
}

// Avoid the post-state API as the sole source of truth: prove database uniqueness
// and that no deliverable recipients were materialized by this lifecycle path.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
$count = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE post_id = %d AND campaign_kind = %s',
		$tables->campaigns(),
		$post_id,
		CampaignRepository::KIND_POST
	)
);
// phpcs:enable

if ( 'none' === $mode ) {
	$check( 0 === $count, 'Prepublication CLI command created a campaign.' );
	return;
}

$check( 'one' === $mode, 'Unknown WP-CLI qualification mode.' );
$check( 1 === $count, 'CLI publication did not preserve exactly one campaign.' );
$row = $campaigns->find_by_key( $key );
$check( is_array( $row ), 'Initial campaign idempotency key was not reserved.' );
$id = (int) $row['id'];
$check( $id > 0, 'Campaign ID is invalid.' );
$check( 0 === $expected || $id === $expected, 'Republish/update changed initial campaign ID.' );
$check( CampaignRepository::STATUS_BUILDING === $row['status'], 'Campaign status was changed before build.' );
$check( ContentMode::Full->value === $row['content_mode'], 'Unexpected content mode.' );
$check(
	'' === $row['subject'] && '' === $row['html_body'] && '' === $row['text_body'],
	'CLI publication prematurely populated message bodies.'
);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
$recipients = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id = %d', $tables->campaign_recipients(), $id )
);
// phpcs:enable
$check( 0 === $recipients, 'CLI publication prematurely materialized recipients.' );
echo (int) $id;

// EOF: tests/wpcli-publication-check.php.
