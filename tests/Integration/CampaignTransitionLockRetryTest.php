<?php
/**
 * File: tests/Integration/CampaignTransitionLockRetryTest.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

/**
 * Regression for Forgejo CI run 148: under MySQL 8 a concurrent worker's
 * queued-to-sending transition was chosen as an InnoDB deadlock victim and the
 * worker crashed with "Campaign status transition failed.".
 */
final class CampaignTransitionLockRetryTest extends WP_UnitTestCase {

	public function test_deadlocked_transition_is_retried_and_succeeds(): void {
		$db       = $this->failing_database( 'Deadlock found when trying to get lock', 2 );
		$campaign = $this->queued_campaign();

		$changed = ( new CampaignRepository( $db ) )->transition(
			$campaign,
			array( CampaignRepository::STATUS_QUEUED ),
			CampaignRepository::STATUS_SENDING,
			'started_at_gmt'
		);

		self::assertTrue( $changed );
		self::assertSame( 0, $db->failures_left );
		self::assertSame(
			CampaignRepository::STATUS_SENDING,
			( new CampaignRepository() )->find( $campaign )['status']
		);
	}

	public function test_losing_the_race_after_a_deadlock_is_success_not_an_exception(): void {
		$campaign = $this->queued_campaign();
		// Another worker already moved the campaign on.
		( new CampaignRepository() )->transition(
			$campaign,
			array( CampaignRepository::STATUS_QUEUED ),
			CampaignRepository::STATUS_SENDING,
			'started_at_gmt'
		);
		$db = $this->failing_database( 'Lock wait timeout exceeded; try restarting transaction', 1 );

		$changed = ( new CampaignRepository( $db ) )->transition(
			$campaign,
			array( CampaignRepository::STATUS_QUEUED ),
			CampaignRepository::STATUS_SENDING,
			'started_at_gmt'
		);

		self::assertFalse( $changed, 'Zero affected rows: the other worker won.' );
		self::assertSame(
			CampaignRepository::STATUS_SENDING,
			( new CampaignRepository() )->find( $campaign )['status']
		);
	}

	public function test_non_transient_errors_and_persistent_deadlocks_still_throw(): void {
		$campaign = $this->queued_campaign();
		foreach ( array( array( 'Table is read only', 1 ), array( 'Deadlock found', 99 ) ) as $case ) {
			$db = $this->failing_database( $case[0], $case[1] );
			try {
				( new CampaignRepository( $db ) )->transition(
					$campaign,
					array( CampaignRepository::STATUS_QUEUED ),
					CampaignRepository::STATUS_SENDING
				);
				self::fail( 'Expected a RuntimeException for: ' . $case[0] );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'Campaign status transition failed.', $exception->getMessage() );
			}
		}
	}

	/**
	 * Create a queued campaign.
	 *
	 * @return int
	 */
	private function queued_campaign(): int {
		$repository = new CampaignRepository();
		$id         = $repository->create_initial_building(
			get_current_blog_id(),
			self::factory()->post->create(),
			'2026-01-01 00:00:00',
			ContentMode::Excerpt
		);
		self::assertTrue(
			$repository->transition(
				$id,
				array( CampaignRepository::STATUS_BUILDING ),
				CampaignRepository::STATUS_QUEUED
			)
		);

		return $id;
	}

	/**
	 * A wpdb sharing the test connection whose campaign UPDATEs fail N times.
	 *
	 * @param string $error    Simulated MySQL error.
	 * @param int    $failures Number of failing UPDATE attempts.
	 * @return wpdb&object{failures_left:int}
	 */
	private function failing_database( string $error, int $failures ): wpdb {
		global $wpdb;
		$db = new class() extends wpdb {
			/**
			 * Remaining simulated failures.
			 *
			 * @var int
			 */
			public int $failures_left = 0;

			/**
			 * Simulated error text.
			 *
			 * @var string
			 */
			public string $simulated_error = '';

			/**
			 * Skip connecting; state is copied from the real connection.
			 */
			public function __construct() {
			}

			/**
			 * Fail campaign UPDATEs while failures remain.
			 *
			 * @param string $query SQL.
			 * @return int|bool
			 */
			public function query( $query ) {
				if ( $this->failures_left > 0 && str_starts_with( ltrim( $query ), 'UPDATE' ) ) {
					--$this->failures_left;
					$this->last_error = $this->simulated_error;
					return false;
				}

				return parent::query( $query );
			}
		};
		// Share the real connection, including wpdb's non-public state.
		$state = \Closure::bind( fn (): array => get_object_vars( $this ), $wpdb, wpdb::class )();
		\Closure::bind(
			function ( array $vars ): void {
				foreach ( $vars as $name => $value ) {
					$this->$name = $value;
				}
			},
			$db,
			wpdb::class
		)( $state );
		$db->failures_left   = $failures;
		$db->simulated_error = $error;

		return $db;
	}
}

// EOF: tests/Integration/CampaignTransitionLockRetryTest.php.
