<?php
/**
 * File: src/Health/PublicationSiteHealth.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Health;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use WP_Query;

/**
 * Report publication scheduling health without activating delivery infrastructure.
 */
final class PublicationSiteHealth implements Registerable {
	/**
	 * Ignore brief WP-Cron delays around a scheduled publication.
	 */
	private const OVERDUE_GRACE_SECONDS = 15 * 60;

	/**
	 * Register read-only Site Health tests.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Add the two Beta.2 direct tests without replacing core checks.
	 *
	 * @param array<string,mixed> $tests Existing Site Health tests.
	 * @return array<string,mixed>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['argentwolf_post_notifier_overdue_scheduled_posts'] = array(
			'label' => __(
				'ArgentWolf Post Notifier scheduled publications',
				'argentwolf-post-notifier'
			),
			'test'  => array( $this, 'test_overdue_scheduled_posts' ),
		);
		$tests['direct']['argentwolf_post_notifier_queue_wakeups']           = array(
			'label' => __( 'ArgentWolf Post Notifier queue wake-ups', 'argentwolf-post-notifier' ),
			'test'  => array( $this, 'test_queue_wakeups' ),
		);

		return $tests;
	}

	/**
	 * Flag an explicitly opted-in future post whose publication is overdue.
	 *
	 * This queries at most one ID. No post title, address, content, or recipient
	 * is included in the Site Health result or a diagnostic log.
	 *
	 * @return array<string,mixed>
	 */
	public function test_overdue_scheduled_posts(): array {
		$overdue     = $this->has_overdue_scheduled_post();
		$description = $overdue
			? __(
				'An opted-in notification post is at least 15 minutes overdue.',
				'argentwolf-post-notifier'
			) . ' ' . __(
				// phpcs:ignore Generic.Files.LineLength.TooLong -- Preserve a translatable literal.
				'Check scheduled events and the site cron runner. Notifications require actual publication.',
				'argentwolf-post-notifier'
			)
			: __(
				'No explicitly opted-in scheduled post is more than 15 minutes overdue.',
				'argentwolf-post-notifier'
			) . ' ' . __(
				'This does not verify delivery or queue worker health.',
				'argentwolf-post-notifier'
			);
		$label  = $overdue
			? __(
				'An opted-in notification post is overdue for publication',
				'argentwolf-post-notifier'
			)
			: __(
				'No overdue opted-in notification posts were found',
				'argentwolf-post-notifier'
			);
		$result = $this->result(
			'overdue_scheduled_posts',
			$overdue ? 'recommended' : 'good',
			$label,
			$description
		);

		if ( $overdue ) {
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'edit.php?post_status=future&post_type=post' ) ),
				esc_html__( 'Review scheduled posts', 'argentwolf-post-notifier' )
			);
		}

		return $result;
	}

	/**
	 * Explain why a queue wake-up cannot yet be checked in Beta.2.
	 *
	 * The queue worker and its cron hook do not exist in this release. Looking
	 * for an invented hook would otherwise cause a permanent false warning.
	 *
	 * @return array<string,mixed>
	 */
	public function test_queue_wakeups(): array {
		return $this->result(
			'queue_wakeups',
			'good',
			__(
				'Campaign queue wake-ups are not active in Beta.2',
				'argentwolf-post-notifier'
			),
			__(
				// phpcs:ignore Generic.Files.LineLength.TooLong -- Preserve a translatable literal.
				'This version reserves campaign shells only; the delivery queue and worker do not yet exist.',
				'argentwolf-post-notifier'
			) . ' ' . __(
				// phpcs:ignore Generic.Files.LineLength.TooLong -- Preserve a translatable literal.
				'This is not a delivery-health check. Worker monitoring will be added with queue delivery.',
				'argentwolf-post-notifier'
			)
		);
	}

	/**
	 * Find whether an explicitly opted-in scheduled post missed its date.
	 *
	 * @return bool
	 */
	private function has_overdue_scheduled_post(): bool {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::OVERDUE_GRACE_SECONDS );
		$query  = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'future',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				// Explicit send intent is stored in postmeta; restricting results to one ID
				// does not eliminate scan cost on large unindexed postmeta tables.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'               => PostNotificationMeta::SEND_INTENT_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'             => SendIntent::Send->value,
				'date_query'             => array(
					array(
						'column'    => 'post_date_gmt',
						'before'    => $cutoff,
						'inclusive' => true,
					),
				),
			)
		);

		return ! empty( $query->posts );
	}

	/**
	 * Build a WordPress Site Health result using only fixed operator text.
	 *
	 * @param string $test        Unique result suffix.
	 * @param string $status      Site Health status.
	 * @param string $label       Public status summary.
	 * @param string $description Fixed explanatory text.
	 * @return array<string,mixed>
	 */
	private function result(
		string $test,
		string $status,
		string $label,
		string $description
	): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'argentwolf-post-notifier' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'argentwolf_post_notifier_' . $test,
		);
	}
}

// EOF: src/Health/PublicationSiteHealth.php.
