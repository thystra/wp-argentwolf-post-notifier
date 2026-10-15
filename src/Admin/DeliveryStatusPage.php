<?php
/**
 * File: src/Admin/DeliveryStatusPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Delivery\QueueRepository;
use ArgentWolf\PostNotifier\Delivery\QueueWorker;
use Closure;

/**
 * Administrator-only delivery queue status, skip statistics and manual run.
 *
 * Shows aggregate counts only; no recipient address, token or name is ever
 * read or rendered here.
 */
final class DeliveryStatusPage implements Registerable {
	/**
	 * WordPress administration page slug.
	 */
	public const PAGE_SLUG = 'argentwolf-post-notifier-delivery';

	/**
	 * Authenticated admin-post action for a manual bounded queue run.
	 */
	public const RUN_ACTION = 'argentwolf_post_notifier_run_queue';

	/**
	 * Capability required to view the screen and trigger a manual run.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Claims processed by one manual run.
	 */
	public const MANUAL_BATCHES = 4;

	/**
	 * Construct the page.
	 *
	 * @param Closure $queue  Returns QueueRepository.
	 * @param Closure $worker Returns QueueWorker.
	 */
	public function __construct( private Closure $queue, private Closure $worker ) {
	}

	/**
	 * Register administration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'handle_run' ) );
	}

	/**
	 * Register the Delivery submenu below the subscriber screen.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Delivery Queue', 'argentwolf-post-notifier' ),
			__( 'Delivery', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render aggregate status, skip statistics and the manual-run form.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();
		$queue = ( $this->queue )();
		if ( ! $queue instanceof QueueRepository ) {
			return;
		}

		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Delivery queue', 'argentwolf-post-notifier' );
		echo '</h1>';
		$this->render_notice();

		echo '<h2>' . esc_html__( 'Campaigns', 'argentwolf-post-notifier' ) . '</h2>';
		$campaign_rows = array();
		foreach ( $queue->aggregate_status() as $status => $row ) {
			$campaign_rows[] = array(
				$status,
				$row['campaigns'],
				$row['recipients'],
				$row['submitted'],
				$row['failed'],
				$row['skipped'],
			);
		}
		$this->render_table(
			array(
				__( 'Status', 'argentwolf-post-notifier' ),
				__( 'Campaigns', 'argentwolf-post-notifier' ),
				__( 'Recipients', 'argentwolf-post-notifier' ),
				__( 'Submitted', 'argentwolf-post-notifier' ),
				__( 'Failed', 'argentwolf-post-notifier' ),
				__( 'Skipped', 'argentwolf-post-notifier' ),
			),
			$campaign_rows
		);

		echo '<h2>' . esc_html__( 'Recipient rows', 'argentwolf-post-notifier' ) . '</h2>';
		$this->render_pairs( $queue->recipient_status_counts() );

		echo '<h2>' . esc_html__( 'Skip reasons', 'argentwolf-post-notifier' ) . '</h2>';
		$this->render_pairs( $queue->skip_reason_counts() );

		$next = $queue->next_attempt_at();
		echo '<p>';
		echo esc_html(
			null === $next
				? __( 'No queued recipient is waiting.', 'argentwolf-post-notifier' )
				: sprintf(
					/* translators: %s: UTC timestamp. */
					__( 'Next queued attempt (UTC): %s', 'argentwolf-post-notifier' ),
					$next
				)
		);
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '">';
		wp_nonce_field( self::RUN_ACTION );
		submit_button( __( 'Run queue now', 'argentwolf-post-notifier' ), 'secondary' );
		echo '</form></div>';
	}

	/**
	 * Handle the manual-run request.
	 *
	 * @return never
	 */
	public function handle_run(): never {
		$this->require_capability();
		check_admin_referer( self::RUN_ACTION );
		$totals = $this->run_manual();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'awpn_ran'       => '1',
					'awpn_submitted' => $totals['submitted'] ?? 0,
					'awpn_failed'    => $totals['failed'] ?? 0,
					'awpn_retried'   => $totals['retried'] ?? 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Run a bounded number of claims after the caller has been authorized.
	 *
	 * @return array<string,int> Worker totals.
	 */
	public function run_manual(): array {
		$worker = ( $this->worker )();
		if ( ! $worker instanceof QueueWorker ) {
			return array();
		}

		return $worker->run( self::MANUAL_BATCHES );
	}

	/**
	 * Render the manual-run result notice from validated integer arguments.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['awpn_ran'] ) ) {
			return;
		}
		$submitted = absint( wp_unslash( $_GET['awpn_submitted'] ?? 0 ) );
		$failed    = absint( wp_unslash( $_GET['awpn_failed'] ?? 0 ) );
		$retried   = absint( wp_unslash( $_GET['awpn_retried'] ?? 0 ) );
		// phpcs:enable
		echo '<div class="notice notice-info"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: submitted, 2: failed, 3: retried counts. */
				__(
					'Manual run finished: %1$d submitted, %2$d failed, %3$d scheduled for retry.',
					'argentwolf-post-notifier'
				),
				$submitted,
				$failed,
				$retried
			)
		);
		echo '</p></div>';
	}

	/**
	 * Render a two-column label/count table.
	 *
	 * @param array<string,int> $pairs Label => count.
	 * @return void
	 */
	private function render_pairs( array $pairs ): void {
		$rows = array();
		foreach ( $pairs as $label => $count ) {
			$rows[] = array( (string) $label, $count );
		}
		$this->render_table(
			array(
				__( 'Value', 'argentwolf-post-notifier' ),
				__( 'Rows', 'argentwolf-post-notifier' ),
			),
			$rows
		);
	}

	/**
	 * Render an escaped table.
	 *
	 * @param array<int,string>           $headers Column headers.
	 * @param array<int,array<int,mixed>> $rows    Cell values.
	 * @return void
	 */
	private function render_table( array $headers, array $rows ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="' . esc_attr( (string) count( $headers ) ) . '">';
			echo esc_html__( 'None.', 'argentwolf-post-notifier' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $cell ) {
				echo '<td>' . esc_html( (string) $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Stop unless the current user may operate the delivery queue.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_die(
			esc_html__(
				'You do not have permission to operate the delivery queue.',
				'argentwolf-post-notifier'
			),
			esc_html__( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' ),
			array( 'response' => 403 )
		);
	}
}

// EOF: src/Admin/DeliveryStatusPage.php.
