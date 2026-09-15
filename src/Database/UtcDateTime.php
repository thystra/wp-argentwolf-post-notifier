<?php
/**
 * File: src/Database/UtcDateTime.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Canonical UTC conversion for values persisted in MySQL datetime columns.
 */
final class UtcDateTime {
	/**
	 * MySQL datetime format used by plugin-owned tables.
	 */
	public const MYSQL_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Format a point in time for UTC database persistence.
	 *
	 * @param DateTimeInterface $value Date/time value in any timezone.
	 * @return string
	 */
	public static function format( DateTimeInterface $value ): string {
		$utc = DateTimeImmutable::createFromInterface( $value )->setTimezone(
			new DateTimeZone( 'UTC' )
		);

		return $utc->format( self::MYSQL_FORMAT );
	}

	/**
	 * Return the current UTC time in the database format.
	 *
	 * @return string
	 */
	public static function now(): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->format( self::MYSQL_FORMAT );
	}

	/**
	 * Parse a persisted UTC database value strictly.
	 *
	 * @param string $value Database datetime value.
	 * @return DateTimeImmutable
	 * @throws InvalidArgumentException When the persisted value is malformed.
	 */
	public static function parse( string $value ): DateTimeImmutable {
		$utc    = new DateTimeZone( 'UTC' );
		$parsed = DateTimeImmutable::createFromFormat(
			'!' . self::MYSQL_FORMAT,
			$value,
			$utc
		);
		$errors = DateTimeImmutable::getLastErrors();

		if (
			false === $parsed
			|| (
				is_array( $errors )
				&& ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 )
			)
			|| $parsed->format( self::MYSQL_FORMAT ) !== $value
		) {
			throw new InvalidArgumentException( 'Invalid persisted UTC datetime value.' );
		}

		return $parsed;
	}

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Database/UtcDateTime.php.
