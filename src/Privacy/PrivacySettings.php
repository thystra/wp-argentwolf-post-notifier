<?php
/**
 * File: src/Privacy/PrivacySettings.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Privacy;

/**
 * Reads and sanitizes the privacy/tracking option.
 *
 * Click tracking is on by default and can be switched to direct-link mode, in
 * which messages link straight to the post permalink and no click token is
 * issued. Open tracking does not exist in any mode.
 */
final class PrivacySettings {

	public const OPTION = 'argentwolf_post_notifier_privacy';

	public const DEFAULT_CLICK_RETENTION_DAYS     = 90;
	public const DEFAULT_ATTRIBUTION_DAYS         = 30;
	public const DEFAULT_SCANNER_WINDOW_SECONDS   = 30;
	public const MAX_SCANNER_WINDOW_SECONDS       = 3600;
	public const DEFAULT_RECIPIENT_RETENTION_DAYS = 365;
	public const MIN_RETENTION_DAYS               = 1;
	public const MIN_RECIPIENT_RETENTION_DAYS     = 30;
	public const MAX_RETENTION_DAYS               = 3650;

	/**
	 * Sanitized settings.
	 *
	 * @return array{click_tracking:bool,click_retention_days:int,recipient_retention_days:int,per_user_tracking:bool,attribution_retention_days:int,scanner_window_seconds:int}
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Whether new messages should carry tracked links.
	 *
	 * @return bool
	 */
	public function click_tracking_enabled(): bool {
		return $this->get()['click_tracking'];
	}

	/**
	 * Whether consented recipients may have clicks attributed to them.
	 *
	 * Requires aggregate click tracking to be on as well.
	 *
	 * @return bool
	 */
	public function per_user_tracking_enabled(): bool {
		$settings = $this->get();

		return $settings['click_tracking'] && $settings['per_user_tracking'];
	}

	/**
	 * Persist sanitized settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array{click_tracking:bool,click_retention_days:int,recipient_retention_days:int,per_user_tracking:bool,attribution_retention_days:int,scanner_window_seconds:int}
	 */
	public function save( array $input ): array {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Sanitize raw settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array{click_tracking:bool,click_retention_days:int,recipient_retention_days:int,per_user_tracking:bool,attribution_retention_days:int,scanner_window_seconds:int}
	 */
	public static function sanitize( array $input ): array {
		$tracking = $input['click_tracking'] ?? true;
		$per_user = $input['per_user_tracking'] ?? false;
		$window   = $input['scanner_window_seconds'] ?? null;
		$numeric  = is_int( $window )
			|| ( is_string( $window ) && 1 === preg_match( '/\A\d{1,5}\z/D', $window ) );
		$window   = $numeric
			? max( 0, min( self::MAX_SCANNER_WINDOW_SECONDS, (int) $window ) )
			: self::DEFAULT_SCANNER_WINDOW_SECONDS;

		return array(
			'click_tracking'             => in_array( $tracking, array( true, '1', 1 ), true ),
			'click_retention_days'       => self::days(
				$input['click_retention_days'] ?? null,
				self::DEFAULT_CLICK_RETENTION_DAYS,
				self::MIN_RETENTION_DAYS
			),
			'recipient_retention_days'   => self::days(
				$input['recipient_retention_days'] ?? null,
				self::DEFAULT_RECIPIENT_RETENTION_DAYS,
				self::MIN_RECIPIENT_RETENTION_DAYS
			),
			'per_user_tracking'          => in_array( $per_user, array( true, '1', 1 ), true ),
			'attribution_retention_days' => self::days(
				$input['attribution_retention_days'] ?? null,
				self::DEFAULT_ATTRIBUTION_DAYS,
				self::MIN_RETENTION_DAYS
			),
			'scanner_window_seconds'     => $window,
		);
	}

	/**
	 * Clamp a day count.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Default when missing or non-numeric.
	 * @param int   $min      Lower bound.
	 * @return int
	 */
	private static function days( mixed $value, int $fallback, int $min ): int {
		if ( is_int( $value ) ) {
			$days = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/\A\d{1,5}\z/D', $value ) ) {
			$days = (int) $value;
		} else {
			return $fallback;
		}

		return max( $min, min( self::MAX_RETENTION_DAYS, $days ) );
	}
}

// EOF: src/Privacy/PrivacySettings.php.
