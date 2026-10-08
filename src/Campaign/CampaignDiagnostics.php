<?php
/**
 * File: src/Campaign/CampaignDiagnostics.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use Closure;

/**
 * Emit low-cardinality publication reservation diagnostics without recipient data.
 */
final class CampaignDiagnostics implements Registerable {
	/**
	 * Log writer, injectable for tests.
	 *
	 * @var Closure(string):void
	 */
	private Closure $writer;

	/**
	 * Configure a fixed-format PHP log writer, or substitute one for tests.
	 *
	 * @param callable(string):void|null $writer Optional test log writer.
	 */
	public function __construct( ?callable $writer = null ) {
		$this->writer = null === $writer
			? static function ( string $line ): void {
				// Fixed-format, identifier-only diagnostics: no request or exception data.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $line );
			}
			: Closure::fromCallable( $writer );
	}

	/**
	 * Register the failure and optional successful-resolution diagnostics hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'argentwolf_post_notifier_campaign_creation_failed',
			array( $this, 'log_failure' ),
			10,
			2
		);
		add_action(
			'argentwolf_post_notifier_campaign_reservation_resolved',
			array( $this, 'log_resolved' ),
			10,
			2
		);
	}

	/**
	 * Log an unexpected campaign-persistence failure; never interpolate details.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Untrusted failure reason from the public hook.
	 * @return void
	 */
	public function log_failure( int $post_id, string $reason ): void {
		$site_id = get_current_blog_id();
		if ( $site_id < 1 || $post_id < 1 ) {
			return;
		}

		( $this->writer )( self::failure_line( $site_id, $post_id, $reason ) );
	}

	/**
	 * Log successful resolution only if explicitly enabled by the operator.
	 *
	 * Resolving an existing campaign is not necessarily a new row creation.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $campaign_id Campaign ID, created or already reserved.
	 * @return void
	 */
	public function log_resolved( int $post_id, int $campaign_id ): void {
		if (
			! defined( 'ARGENTWOLF_POST_NOTIFIER_DEBUG_CAMPAIGNS' )
			|| true !== constant( 'ARGENTWOLF_POST_NOTIFIER_DEBUG_CAMPAIGNS' )
		) {
			return;
		}

		$site_id = get_current_blog_id();
		if ( $site_id < 1 || $post_id < 1 || $campaign_id < 1 ) {
			return;
		}

		( $this->writer )( self::resolved_line( $site_id, $post_id, $campaign_id ) );
	}

	/**
	 * Format a stable log event with integer IDs and a closed reason vocabulary.
	 *
	 * @param int    $site_id Site/blog ID.
	 * @param int    $post_id Post ID.
	 * @param string $reason  Reason; only one known value is permitted.
	 * @return string
	 */
	public static function failure_line( int $site_id, int $post_id, string $reason ): string {
		$code = 'persistence_error' === $reason ? 'persistence_error' : 'unknown_error';

		return sprintf(
			'[ArgentWolf Post Notifier] event=campaign_reservation_failed '
				. 'site_id=%d post_id=%d code=%s',
			$site_id,
			$post_id,
			$code
		);
	}

	/**
	 * Format one successful reservation resolution for opt-in debug logs.
	 *
	 * @param int $site_id     Site/blog ID.
	 * @param int $post_id     Post ID.
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	public static function resolved_line( int $site_id, int $post_id, int $campaign_id ): string {
		return sprintf(
			'[ArgentWolf Post Notifier] event=campaign_reservation_resolved '
				. 'site_id=%d post_id=%d campaign_id=%d status=building',
			$site_id,
			$post_id,
			$campaign_id
		);
	}
}

// EOF: src/Campaign/CampaignDiagnostics.php.
