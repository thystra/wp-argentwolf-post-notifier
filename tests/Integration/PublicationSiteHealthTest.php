<?php
/**
 * File: tests/Integration/PublicationSiteHealthTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use ArgentWolf\PostNotifier\Health\PublicationSiteHealth;
use ArgentWolf\PostNotifier\Plugin;
use WP_UnitTestCase;

/**
 * Integration coverage for read-only publication Site Health checks.
 */
final class PublicationSiteHealthTest extends WP_UnitTestCase {
	/**
	 * Verify both namespaced direct tests are registered.
	 *
	 * @return void
	 */
	public function test_direct_site_health_checks_are_registered(): void {
		$health = Plugin::instance()->container()->get( PublicationSiteHealth::class );
		self::assertInstanceOf( PublicationSiteHealth::class, $health );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		self::assertArrayHasKey( 'argentwolf_post_notifier_overdue_scheduled_posts', $tests['direct'] );
		self::assertArrayHasKey( 'argentwolf_post_notifier_queue_wakeups', $tests['direct'] );
		self::assertSame(
			array( $health, 'test_overdue_scheduled_posts' ),
			$tests['direct']['argentwolf_post_notifier_overdue_scheduled_posts']['test']
		);
	}

	/**
	 * Ignore future schedules and overdue posts without explicit send intent.
	 *
	 * @return void
	 */
	public function test_no_warning_for_non_opted_in_or_not_overdue_posts(): void {
		$health = new PublicationSiteHealth();
		$this->create_scheduled_post( SendIntent::Send->value, 3600 );
		$this->create_scheduled_post( SendIntent::DoNotSend->value, -3600 );
		$this->create_scheduled_post( SendIntent::SiteDefault->value, -3600 );
		$this->create_scheduled_post( SendIntent::Send->value, -120 );

		$result = $health->test_overdue_scheduled_posts();
		self::assertSame( 'good', $result['status'] );
		self::assertSame( '', $result['actions'] );
	}

	/**
	 * Surface a missed WordPress scheduled post after the grace period.
	 *
	 * @return void
	 */
	public function test_overdue_opted_in_future_post_is_recommended_without_post_data(): void {
		$health  = new PublicationSiteHealth();
		$post_id = $this->create_scheduled_post( SendIntent::Send->value, -3600 );

		self::assertSame( 'future', get_post_status( $post_id ) );
		$result = $health->test_overdue_scheduled_posts();
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( 'argentwolf_post_notifier_overdue_scheduled_posts', $result['test'] );
		self::assertStringContainsString( 'post_status=future', $result['actions'] );
		self::assertStringNotContainsString( 'Top secret', $result['description'] );
		self::assertStringNotContainsString( 'user@example.invalid', $result['description'] );
	}

	/**
	 * Do not invent a queue cron event or misrepresent delivery health.
	 *
	 * @return void
	 */
	public function test_queue_wakeups_are_explicitly_not_applicable(): void {
		$result = ( new PublicationSiteHealth() )->test_queue_wakeups();

		self::assertSame( 'good', $result['status'] );
		self::assertSame( 'argentwolf_post_notifier_queue_wakeups', $result['test'] );
		self::assertStringContainsString( 'delivery queue and worker do not yet exist', $result['description'] );
		self::assertSame( '', $result['actions'] );
	}

	/**
	 * Simulate a missed cron event without actually publishing a post.
	 *
	 * @param string $intent  Stored send intent.
	 * @param int    $offset  Seconds relative to now.
	 * @return int
	 */
	private function create_scheduled_post( string $intent, int $offset ): int {
		global $wpdb;

		$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'future',
				'post_title'    => 'Top secret user@example.invalid',
				'post_date'     => $future,
				'post_date_gmt' => $future,
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, $intent );
		if ( $offset < 0 ) {
			// Writing the fixture directly avoids triggering core's publication transition.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => gmdate( 'Y-m-d H:i:s', time() + $offset ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + $offset ),
				),
				array( 'ID' => $post_id )
			);
			clean_post_cache( $post_id );
		}

		return $post_id;
	}
}

// EOF: tests/Integration/PublicationSiteHealthTest.php.
