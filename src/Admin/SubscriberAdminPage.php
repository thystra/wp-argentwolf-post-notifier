<?php
/**
 * File: src/Admin/SubscriberAdminPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;

/**
 * Administrator-only standalone subscriber list, suppression, and CSV export.
 */
final class SubscriberAdminPage implements Registerable {
	/**
	 * Initial alpha administration capability.
	 *
	 * A dedicated subscriber-management capability is introduced by a later
	 * capability-mapping milestone. Alpha.4 remains administrator-only.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * WordPress administration page slug.
	 */
	public const PAGE_SLUG = 'argentwolf-post-notifier';

	/**
	 * Authenticated admin-post action for suppression.
	 */
	public const SUPPRESS_ACTION = 'argentwolf_post_notifier_suppress_subscriber';

	/**
	 * Authenticated admin-post action for CSV export.
	 */
	public const EXPORT_ACTION = 'argentwolf_post_notifier_export_subscribers';

	/**
	 * Rows displayed per administration page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Construct the subscriber administration page.
	 *
	 * @param SubscriberAdminRepository $repository Administration persistence.
	 * @param SubscriberCsvExporter     $exporter   CSV exporter.
	 */
	public function __construct(
		private SubscriberAdminRepository $repository,
		private SubscriberCsvExporter $exporter
	) {
	}

	/**
	 * Register menu and authenticated administration actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action(
			'admin_post_' . self::SUPPRESS_ACTION,
			array( $this, 'handle_suppression' )
		);
		add_action(
			'admin_post_' . self::EXPORT_ACTION,
			array( $this, 'handle_export' )
		);
	}

	/**
	 * Register the Post Notifier administration page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'ArgentWolf Post Notifier Subscribers', 'argentwolf-post-notifier' ),
			__( 'Post Notifier', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-email-alt',
			58
		);
	}

	/**
	 * Render the subscriber administration screen.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters.
		$status = $this->requested_status();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters.
		$search = $this->request_text( $_GET, 's' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page   = max( 1, absint( $this->request_text( $_GET, 'paged' ) ) );
		$result = $this->repository->page( $status, $search, $page, self::PER_PAGE );
		$total  = $result['total'];
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		if ( $page > $pages ) {
			$page   = $pages;
			$result = $this->repository->page( $status, $search, $page, self::PER_PAGE );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Subscribers', 'argentwolf-post-notifier' ) . '</h1>';
		$this->render_notice();
		$this->render_filters( $status, $search );
		$this->render_table( $result['rows'] );
		$this->render_pagination( $status, $search, $page, $pages, $total );
		echo '</div>';
	}

	/**
	 * Handle an administrator's explicit subscriber suppression POST.
	 *
	 * @return never
	 */
	public function handle_suppression(): never {
		$this->require_capability();
		if ( 'POST' !== $this->request_method() ) {
			$this->die_with_status(
				__( 'Subscriber suppression requires a POST request.', 'argentwolf-post-notifier' ),
				405
			);
		}

		check_admin_referer( self::SUPPRESS_ACTION );

		$subscriber_id = absint( $this->request_text( $_POST, 'subscriber_id' ) );
		if ( $subscriber_id < 1 ) {
			$this->die_with_status(
				__( 'The subscriber could not be identified.', 'argentwolf-post-notifier' ),
				400
			);
		}

		$this->repository->suppress( $subscriber_id );

		wp_safe_redirect(
			add_query_arg(
				'arpn_notice',
				'suppressed',
				$this->list_url( null, '', 1 )
			)
		);
		exit;
	}

	/**
	 * Handle an authorized CSV export request.
	 *
	 * @return never
	 */
	public function handle_export(): never {
		$this->require_capability();
		check_admin_referer( self::EXPORT_ACTION );

		$status   = $this->requested_status();
		$search   = $this->request_text( $_GET, 's' );
		$filename = 'argentwolf-post-notifier-subscribers-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		foreach ( $this->exporter->lines( $status, $search ) as $line ) {
			// CSV is intentionally raw download content, not HTML output.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $line;
		}
		exit;
	}

	/**
	 * Render search/status controls and the current-filter CSV export link.
	 *
	 * @param SubscriberStatus|null $status Current status filter.
	 * @param string                $search Current search text.
	 * @return void
	 */
	private function render_filters( ?SubscriberStatus $status, string $search ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<label class="screen-reader-text" for="arpn-subscriber-status">';
		echo esc_html__( 'Filter subscribers by status', 'argentwolf-post-notifier' );
		echo '</label>';
		echo '<select id="arpn-subscriber-status" name="status">';
		echo '<option value="">';
		echo esc_html__( 'All statuses', 'argentwolf-post-notifier' );
		echo '</option>';
		foreach ( SubscriberStatus::cases() as $choice ) {
			echo '<option value="' . esc_attr( $choice->value ) . '"';
			if ( $choice === $status ) {
				echo ' selected="selected"';
			}
			echo '>' . esc_html( ucfirst( $choice->value ) ) . '</option>';
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="arpn-subscriber-search">';
		echo esc_html__( 'Search subscriber email or name', 'argentwolf-post-notifier' );
		echo '</label>';
		echo '<input id="arpn-subscriber-search" type="search" name="s" value="';
		echo esc_attr( $search ) . '" placeholder="';
		echo esc_attr__( 'Email or name', 'argentwolf-post-notifier' ) . '"> ';
		submit_button( __( 'Filter', 'argentwolf-post-notifier' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url( $this->export_url( $status, $search ) ) . '">';
		echo esc_html__( 'Export CSV', 'argentwolf-post-notifier' );
		echo '</a>';
		echo '</form>';
	}

	/**
	 * Render the subscriber table.
	 *
	 * @param array<int,array<string,mixed>> $rows Subscriber rows.
	 * @return void
	 */
	private function render_table( array $rows ): void {
		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		$headings = array(
			__( 'Email', 'argentwolf-post-notifier' ),
			__( 'Name', 'argentwolf-post-notifier' ),
			__( 'Status', 'argentwolf-post-notifier' ),
			__( 'Created', 'argentwolf-post-notifier' ),
			__( 'Confirmed', 'argentwolf-post-notifier' ),
			__( 'Source', 'argentwolf-post-notifier' ),
			__( 'Consent', 'argentwolf-post-notifier' ),
			__( 'Actions', 'argentwolf-post-notifier' ),
		);
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="8">';
			echo esc_html__(
				'No subscribers match the current filters.',
				'argentwolf-post-notifier'
			);
			echo '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$this->render_row( $row );
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Render one subscriber table row.
	 *
	 * @param array<string,mixed> $row Subscriber row.
	 * @return void
	 */
	private function render_row( array $row ): void {
		$subscriber_id = (int) ( $row['id'] ?? 0 );
		$status        = (string) ( $row['status'] ?? '' );
		$source        = (string) ( $row['signup_source'] ?? '' );
		$source_id     = (int) ( $row['source_post_id'] ?? 0 );
		$consent       = (string) ( $row['consent_text_snapshot'] ?? '' );

		echo '<tr>';
		echo '<td>' . esc_html( (string) ( $row['email'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $row['display_name'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( ucfirst( $status ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $row['created_at_gmt'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $row['confirmed_at_gmt'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( $source );
		if ( $source_id > 0 ) {
			echo '<br><span class="description">#' . esc_html( (string) $source_id ) . '</span>';
		}
		echo '</td>';
		echo '<td>';
		if ( '' !== $consent ) {
			echo '<details><summary>';
			echo esc_html__( 'View consent', 'argentwolf-post-notifier' );
			echo '</summary><p>' . esc_html( $consent ) . '</p></details>';
		}
		echo '</td>';
		echo '<td>';
		if ( SubscriberStatus::Suppressed->value === $status ) {
			echo esc_html__( 'Suppressed', 'argentwolf-post-notifier' );
		} else {
			$this->render_suppress_form( $subscriber_id );
		}
		echo '</td></tr>';
	}

	/**
	 * Render one explicit POST suppression form.
	 *
	 * @param int $subscriber_id Subscriber row ID.
	 * @return void
	 */
	private function render_suppress_form( int $subscriber_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="';
		echo esc_attr( self::SUPPRESS_ACTION ) . '">';
		echo '<input type="hidden" name="subscriber_id" value="';
		echo esc_attr( (string) $subscriber_id ) . '">';
		wp_nonce_field( self::SUPPRESS_ACTION );
		submit_button(
			__( 'Suppress', 'argentwolf-post-notifier' ),
			'small',
			'',
			false,
			array(
				'onclick' => "return confirm('" . esc_js(
					__( 'Suppress this subscriber?', 'argentwolf-post-notifier' )
				) . "');",
			)
		);
		echo '</form>';
	}

	/**
	 * Render simple previous/next pagination.
	 *
	 * @param SubscriberStatus|null $status Current status filter.
	 * @param string                $search Current search text.
	 * @param int                   $page   Current page.
	 * @param int                   $pages  Total pages.
	 * @param int                   $total  Total matching rows.
	 * @return void
	 */
	private function render_pagination(
		?SubscriberStatus $status,
		string $search,
		int $page,
		int $pages,
		int $total
	): void {
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo '<span class="displaying-num">';
		echo esc_html(
			sprintf(
				/* translators: %d: number of matching subscriber records. */
				_n( '%d subscriber', '%d subscribers', $total, 'argentwolf-post-notifier' ),
				$total
			)
		);
		echo '</span> ';

		if ( $page > 1 ) {
			echo '<a class="button" href="';
			echo esc_url( $this->list_url( $status, $search, $page - 1 ) ) . '">';
			echo esc_html__( 'Previous', 'argentwolf-post-notifier' ) . '</a> ';
		}

		echo '<span class="paging-input">';
		echo esc_html(
			sprintf(
				/* translators: 1: current page number, 2: total pages. */
				__( 'Page %1$d of %2$d', 'argentwolf-post-notifier' ),
				$page,
				$pages
			)
		);
		echo '</span>';

		if ( $page < $pages ) {
			echo ' <a class="button" href="';
			echo esc_url( $this->list_url( $status, $search, $page + 1 ) ) . '">';
			echo esc_html__( 'Next', 'argentwolf-post-notifier' ) . '</a>';
		}

		echo '</div></div>';
	}

	/**
	 * Render one post-action notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice code.
		if ( 'suppressed' !== $this->request_text( $_GET, 'arpn_notice' ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html__( 'Subscriber suppressed.', 'argentwolf-post-notifier' );
		echo '</p></div>';
	}

	/**
	 * Build a subscriber-list URL that preserves the requested filters.
	 *
	 * @param SubscriberStatus|null $status Status filter.
	 * @param string                $search Search text.
	 * @param int                   $page   One-based page.
	 * @return string
	 */
	private function list_url( ?SubscriberStatus $status, string $search, int $page ): string {
		$args = array(
			'page'  => self::PAGE_SLUG,
			'paged' => max( 1, $page ),
		);
		if ( null !== $status ) {
			$args['status'] = $status->value;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Build a nonce-protected export URL for the current filters.
	 *
	 * @param SubscriberStatus|null $status Status filter.
	 * @param string                $search Search text.
	 * @return string
	 */
	private function export_url( ?SubscriberStatus $status, string $search ): string {
		$args = array(
			'action'   => self::EXPORT_ACTION,
			'_wpnonce' => wp_create_nonce( self::EXPORT_ACTION ),
		);
		if ( null !== $status ) {
			$args['status'] = $status->value;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Resolve the optional requested status filter.
	 *
	 * @return SubscriberStatus|null
	 */
	private function requested_status(): ?SubscriberStatus {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only/export filter.
		$value = $this->request_text( $_GET, 'status' );
		return '' === $value ? null : SubscriberStatus::tryFrom( $value );
	}

	/**
	 * Read one scalar request field through WordPress unslashing/sanitization.
	 *
	 * @param array<string,mixed> $source Request source.
	 * @param string              $key    Field key.
	 * @return string
	 */
	private function request_text( array $source, string $key ): string {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $source[ $key ] ) );
	}

	/**
	 * Resolve the incoming request method.
	 *
	 * @return string
	 */
	private function request_method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		return strtoupper( $method );
	}

	/**
	 * Require alpha.4's administrator-only management capability.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->die_with_status(
			__( 'You do not have permission to manage subscribers.', 'argentwolf-post-notifier' ),
			403
		);
	}

	/**
	 * End an invalid administration request with an HTTP status.
	 *
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	private function die_with_status( string $message, int $status ): never {
		wp_die(
			esc_html( $message ),
			esc_html__( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' ),
			array( 'response' => absint( $status ) )
		);
	}
}

// EOF: src/Admin/SubscriberAdminPage.php.
