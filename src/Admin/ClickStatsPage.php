<?php
/**
 * File: src/Admin/ClickStatsPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Audit\AuditEventType;
use ArgentWolf\PostNotifier\Audit\AuditService;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Tracking\ClickAttributionReport;
use Throwable;

/**
 * Admin-only per-recipient click view and CSV export.
 *
 * Gated by the dedicated view_post_notification_stats capability. Every view
 * and every export is written to the audit log (actor and campaign only).
 */
final class ClickStatsPage implements Registerable {

	public const PAGE_SLUG     = 'argentwolf-post-notifier-click-stats';
	public const EXPORT_ACTION = 'argentwolf_post_notifier_export_click_stats';
	public const CAPABILITY    = Capabilities::VIEW_STATS;

	/**
	 * Construct the page.
	 *
	 * @param ClickAttributionReport $report Attributed click read model.
	 * @param AuditService           $audit  Audit log.
	 */
	public function __construct(
		private ClickAttributionReport $report,
		private AuditService $audit
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Per-recipient clicks', 'argentwolf-post-notifier' ),
			__( 'Recipient clicks', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Rows for display, recording the access in the audit log.
	 *
	 * @param int $campaign_id Campaign filter or 0.
	 * @return array<int,array<string,mixed>>
	 */
	public function view_rows( int $campaign_id ): array {
		$this->audit_access( AuditEventType::ClickStatsViewed, $campaign_id );

		return $this->report->rows( $campaign_id );
	}

	/**
	 * CSV document for export, recording the access in the audit log.
	 *
	 * @param int $campaign_id Campaign filter or 0.
	 * @return string
	 */
	public function export_csv( int $campaign_id ): string {
		$this->audit_access( AuditEventType::ClickStatsExported, $campaign_id );

		$csv = self::csv_line(
			array( 'campaign_id', 'post', 'email', 'clicked_at_utc', 'likely_scanner' )
		);
		foreach ( $this->report->rows( $campaign_id ) as $row ) {
			$csv .= self::csv_line(
				array(
					(string) $row['campaign_id'],
					get_the_title( $row['post_id'] ),
					$row['email'],
					$row['clicked_at_gmt'],
					$row['likely_scanner'] ? '1' : '0',
				)
			);
		}

		return $csv;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();
		$campaign_id = $this->requested_campaign();
		$rows        = $this->view_rows( $campaign_id );
		$export      = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::EXPORT_ACTION,
					'campaign' => $campaign_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
		$labels      = array(
			__( 'Post', 'argentwolf-post-notifier' ),
			__( 'Recipient', 'argentwolf-post-notifier' ),
			__( 'Clicked (UTC)', 'argentwolf-post-notifier' ),
			__( 'Likely scanner', 'argentwolf-post-notifier' ),
		);
		$yes         = __( 'Yes', 'argentwolf-post-notifier' );
		$no          = __( 'No', 'argentwolf-post-notifier' );

		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Per-recipient clicks', 'argentwolf-post-notifier' ) . '</h1><p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Only recipients who gave separate click-tracking consent appear here, and only within the attribution retention period. Views and exports are recorded in the audit log.',
			'argentwolf-post-notifier'
		) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $export ) . '">';
		echo esc_html__( 'Export CSV', 'argentwolf-post-notifier' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $labels as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="4">';
			echo esc_html__( 'No attributed clicks.', 'argentwolf-post-notifier' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( get_the_title( $row['post_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . esc_html( $row['clicked_at_gmt'] ) . '</td>';
			echo '<td>' . esc_html( $row['likely_scanner'] ? $yes : $no ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Stream the CSV export.
	 *
	 * @return never
	 */
	public function handle_export(): never {
		$this->require_capability();
		check_admin_referer( self::EXPORT_ACTION );
		$csv = $this->export_csv( $this->requested_campaign() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		$filename = 'argentwolf-post-notifier-clicks-' . gmdate( 'Ymd-His' ) . '.csv';
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// CSV is raw download content, not HTML output.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $csv;
		exit;
	}

	/**
	 * Stop unless the current user may view per-recipient statistics.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_die(
			esc_html__(
				'Sorry, you are not allowed to access this page.',
				'argentwolf-post-notifier'
			),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Requested campaign filter.
	 *
	 * @return int
	 */
	private function requested_campaign(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
	}

	/**
	 * Record an access; auditing failures never expose data silently.
	 *
	 * @param AuditEventType $type        Event type.
	 * @param int            $campaign_id Campaign filter.
	 * @return void
	 */
	private function audit_access( AuditEventType $type, int $campaign_id ): void {
		try {
			$this->audit->record_click_stats_access( $type, $campaign_id );
		} catch ( Throwable ) {
			wp_die(
				esc_html__(
					'The access could not be recorded, so the data is not shown.',
					'argentwolf-post-notifier'
				),
				'',
				array( 'response' => 500 )
			);
		}
	}

	/**
	 * One RFC-4180 CSV line with spreadsheet-formula neutralization.
	 *
	 * @param array<int,string> $cells Cells.
	 * @return string
	 */
	private static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $text ) {
			if ( 1 === preg_match( '/\A[\x00-\x20]*[=+\-@]/D', $text ) ) {
				$text = "'" . $text;
			}
			$out[] = false === strpbrk( $text, ",\"\r\n" )
				? $text
				: '"' . str_replace( '"', '""', $text ) . '"';
		}

		return implode( ',', $out ) . "\r\n";
	}
}

// EOF: src/Admin/ClickStatsPage.php.
