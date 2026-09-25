<?php
/**
 * File: tests/Unit/SignupRateLimiterTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Subscriber\RateLimitStore;
use ArgentWolf\PostNotifier\Subscriber\SignupRateLimiter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SignupRateLimiterTest extends TestCase {
	public function test_email_limit_is_enforced_within_window(): void {
		$store   = new MemoryRateLimitStore();
		$limiter = $this->limiter( $store );
		$now     = $this->utc( '2026-09-25 14:00:00' );

		for ( $attempt = 1; $attempt <= SignupRateLimiter::EMAIL_LIMIT; ++$attempt ) {
			self::assertTrue(
				$limiter->allow( 'person@example.com', null, $now ),
				'Allowed email attempt should remain within the configured limit.'
			);
		}

		self::assertFalse( $limiter->allow( 'person@example.com', null, $now ) );
		self::assertTrue(
			$limiter->allow(
				'person@example.com',
				null,
				$now->modify( '+' . SignupRateLimiter::WINDOW_SECONDS . ' seconds' )
			)
		);
	}

	public function test_network_limit_is_shared_across_email_addresses(): void {
		$store   = new MemoryRateLimitStore();
		$limiter = $this->limiter( $store );
		$now     = $this->utc( '2026-09-25 14:00:00' );

		for ( $attempt = 1; $attempt <= SignupRateLimiter::NETWORK_LIMIT; ++$attempt ) {
			self::assertTrue(
				$limiter->allow(
					sprintf( 'person%d@example.com', $attempt ),
					'192.0.2.44',
					$now
				)
			);
		}

		self::assertFalse(
			$limiter->allow( 'another@example.com', '192.0.2.44', $now )
		);
	}

	public function test_store_receives_no_raw_email_or_network_address(): void {
		$store   = new MemoryRateLimitStore();
		$limiter = $this->limiter( $store );

		self::assertTrue(
			$limiter->allow(
				'Private.Person@Example.com',
				'2001:db8::1234',
				$this->utc( '2026-09-25 14:00:00' )
			)
		);

		self::assertCount( 2, $store->buckets );
		foreach ( $store->buckets as $bucket ) {
			self::assertStringNotContainsString( 'private.person@example.com', $bucket );
			self::assertStringNotContainsString( '2001:db8::1234', $bucket );
		}
	}

	public function test_invalid_network_address_is_not_persisted_as_a_bucket(): void {
		$store   = new MemoryRateLimitStore();
		$limiter = $this->limiter( $store );

		self::assertTrue(
			$limiter->allow(
				'person@example.com',
				'not-an-ip-address',
				$this->utc( '2026-09-25 14:00:00' )
			)
		);
		self::assertCount( 1, $store->buckets );
		self::assertStringStartsWith( 'email:', $store->buckets[0] );
	}

	private function limiter( RateLimitStore $store ): SignupRateLimiter {
		return new SignupRateLimiter(
			$store,
			new EmailIdentity( str_repeat( "\x31", 32 ) ),
			str_repeat( 'network-secret-', 3 )
		);
	}

	private function utc( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}

/**
 * Deterministic in-memory fixed-window store for limiter unit tests.
 */
final class MemoryRateLimitStore implements RateLimitStore {
	/** @var array<string> */
	public array $buckets = array();

	/** @var array<string,array{count:int,reset_at:int}> */
	private array $state = array();

	public function consume(
		string $bucket,
		int $limit,
		int $window_seconds,
		int $now
	): bool {
		$this->buckets[] = $bucket;
		$current         = $this->state[ $bucket ] ?? null;

		if ( null === $current || $current['reset_at'] <= $now ) {
			$this->state[ $bucket ] = array(
				'count'    => 1,
				'reset_at' => $now + $window_seconds,
			);
			return true;
		}

		if ( $current['count'] >= $limit ) {
			return false;
		}

		++$current['count'];
		$this->state[ $bucket ] = $current;
		return true;
	}
}

// EOF: tests/Unit/SignupRateLimiterTest.php.
