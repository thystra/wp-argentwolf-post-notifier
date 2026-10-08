<?php
/**
 * File: tests/Integration/CampaignDiagnosticsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignDiagnostics;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use ArgentWolf\PostNotifier\Plugin;
use WP_UnitTestCase;

/**
 * Qualify the privacy boundary of publication reservation diagnostics.
 */
final class CampaignDiagnosticsTest extends WP_UnitTestCase {
	public function test_default_failure_action_is_registered_and_sanitized(): void {
		$diagnostics = Plugin::instance()->container()->get( CampaignDiagnostics::class );
		self::assertInstanceOf( CampaignDiagnostics::class, $diagnostics );
		self::assertSame(
			10,
			has_action(
				'argentwolf_post_notifier_campaign_creation_failed',
				array( $diagnostics, 'log_failure' )
			)
		);

		$lines = array();
		$test  = new CampaignDiagnostics(
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);
		$test->log_failure( 15, 'email=secret@example.invalid' );
		self::assertSame(
			array( CampaignDiagnostics::failure_line( get_current_blog_id(), 15, 'unknown_error' ) ),
			$lines
		);
		$test->log_failure( 0, 'persistence_error' );
		self::assertCount( 1, $lines );

		// Ordinary successful resolution is silent unless explicitly opted in.
		if ( ! defined( 'ARGENTWOLF_POST_NOTIFIER_DEBUG_CAMPAIGNS' ) ) {
			$test->log_resolved( 15, 9 );
			self::assertCount( 1, $lines );
		}
	}

	public function test_reservation_hook_reports_only_campaign_ids_after_real_publication(): void {
		$events = array();
		$record = static function ( int $post_id, int $campaign_id ) use ( &$events ): void {
			$events[] = array( $post_id, $campaign_id );
		};
		add_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $record, 20, 2 );

		try {
			$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
			update_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, SendIntent::Send->value );
			self::assertSame( array(), $events );

			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
			self::assertCount( 1, $events );
			self::assertSame( $post_id, $events[0][0] );
			self::assertGreaterThan( 0, $events[0][1] );

			$campaign = ( new CampaignRepository() )->find_by_key(
				CampaignRepository::initial_key( get_current_blog_id(), $post_id )
			);
			self::assertIsArray( $campaign );
			self::assertSame( (int) $campaign['id'], $events[0][1] );

			wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Published edit' ) );
			self::assertCount( 1, $events );
		} finally {
			remove_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $record, 20 );
		}
	}
}

// EOF: tests/Integration/CampaignDiagnosticsTest.php.
