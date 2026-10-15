<?php
/**
 * File: tests/Integration/QueueLockRetryTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Delivery\QueueRepository;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

/**
 * Queue writes retry InnoDB lock conflicts and nothing else.
 */
final class QueueLockRetryTest extends WP_UnitTestCase {
	public function test_deadlock_victim_statement_is_retried(): void {
		$db   = $this->scripted( array( 'Deadlock found when trying to get lock', 'Lock wait timeout exceeded', 1 ) );
		$repo = new QueueRepository( $db );

		self::assertTrue( $repo->mark_skipped( 7, 'lease', 'filtered' ) );
		self::assertCount( 3, $db->seen );
		self::assertSame( $db->seen[0], $db->seen[2], 'The same statement is re-run.' );
	}

	public function test_other_errors_fail_immediately(): void {
		$db   = $this->scripted( array( 'Table does not exist', 1 ) );
		$repo = new QueueRepository( $db );

		try {
			$repo->mark_skipped( 7, 'lease', 'filtered' );
			self::fail( 'A non-lock error was retried or ignored.' );
		} catch ( RuntimeException ) {
			self::assertCount( 1, $db->seen );
		}
	}

	public function test_persistent_deadlock_gives_up_after_bounded_attempts(): void {
		$db   = $this->scripted( array_fill( 0, 10, 'Deadlock found when trying to get lock' ) );
		$repo = new QueueRepository( $db );

		try {
			$repo->mark_skipped( 7, 'lease', 'filtered' );
			self::fail( 'An endless deadlock did not surface.' );
		} catch ( RuntimeException ) {
			self::assertCount( 5, $db->seen );
		}
	}

	/**
	 * Database double whose query() follows a script of errors and results.
	 *
	 * @param array<int,string|int> $script Error message (fails) or affected rows.
	 * @return wpdb
	 */
	private function scripted( array $script ): wpdb {
		global $wpdb;

		return new class( $script, $wpdb ) extends wpdb {
			/**
			 * Queries received.
			 *
			 * @var array<int,string>
			 */
			public array $seen = array();

			/**
			 * Build the double around the real connection's prepare().
			 *
			 * @param array<int,string|int> $script Script.
			 * @param wpdb                  $real   Real connection.
			 */
			public function __construct( private array $script, private wpdb $real ) {
				$this->prefix = $real->prefix;
			}

			/**
			 * Delegate prepare() to the real connection.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Arguments.
			 * @return string|void
			 */
			public function prepare( $query, ...$args ) {
				return $this->real->prepare( $query, ...$args );
			}

			/**
			 * Follow the script.
			 *
			 * @param string $query Query.
			 * @return int|false
			 */
			public function query( $query ) {
				$this->seen[] = (string) $query;
				$step         = array_shift( $this->script );
				if ( is_string( $step ) ) {
					$this->last_error = $step;
					return false;
				}
				$this->last_error = '';
				return (int) $step;
			}
		};
	}
}

// EOF: tests/Integration/QueueLockRetryTest.php.
