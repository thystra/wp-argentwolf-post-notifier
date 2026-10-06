<?php
/**
 * File: src/Admin/SubscriberCsvImportPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Subscriber-management capability-gated CSV double-opt-in intake page.
 */
final class SubscriberCsvImportPage implements Registerable {
	/**
	 * WordPress administration page slug.
	 */
	public const PAGE_SLUG = 'argentwolf-post-notifier-import';

	/**
	 * Authenticated admin-post action for CSV intake.
	 */
	public const IMPORT_ACTION = 'argentwolf_post_notifier_import_subscribers';

	/**
	 * CSV upload field name.
	 */
	private const FILE_FIELD = 'argentwolf_post_notifier_csv';

	/**
	 * Explicit operator-approval field name.
	 */
	private const APPROVAL_FIELD = 'argentwolf_post_notifier_import_approved';

	/**
	 * Maximum accepted upload size in bytes.
	 */
	private const MAX_UPLOAD_BYTES = 524288;

	/**
	 * Construct the CSV intake administration page.
	 *
	 * @param SubscriberCsvImporter $importer Bounded double-opt-in importer.
	 */
	public function __construct( private SubscriberCsvImporter $importer ) {
	}

	/**
	 * Register administration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action(
			'admin_post_' . self::IMPORT_ACTION,
			array( $this, 'handle_import' )
		);
	}

	/**
	 * Register the Import submenu below the subscriber screen.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Import Subscribers', 'argentwolf-post-notifier' ),
			__( 'Import', 'argentwolf-post-notifier' ),
			SubscriberAdminPage::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the bounded CSV intake form.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();

		echo '<div class="wrap">';
		echo '<h1>';
		echo esc_html__( 'Import subscriber invitations', 'argentwolf-post-notifier' );
		echo '</h1>';
		$this->render_notice();
		echo '<p>';
		echo esc_html__(
			'CSV intake sends confirmation invitations; it never directly subscribes a contact.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		echo '<p>';
		echo esc_html__(
			'Use an email header and optionally name or display_name. Other columns are ignored.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		echo '<p>';
		echo esc_html(
			sprintf(
				/* translators: %d: maximum number of data rows. */
				__(
					'Each import is limited to %d nonblank data rows.',
					'argentwolf-post-notifier'
				),
				SubscriberCsvImporter::MAX_ROWS
			)
		);
		echo '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="';
		echo esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="';
		echo esc_attr( self::IMPORT_ACTION ) . '">';
		wp_nonce_field( self::IMPORT_ACTION );
		echo '<p><label for="arpn-subscriber-csv"><strong>';
		echo esc_html__( 'CSV file', 'argentwolf-post-notifier' );
		echo '</strong></label><br>';
		echo '<input id="arpn-subscriber-csv" type="file" name="';
		echo esc_attr( self::FILE_FIELD ) . '" accept=".csv,text/csv" required></p>';
		echo '<p><label><input type="checkbox" name="';
		echo esc_attr( self::APPROVAL_FIELD ) . '" value="1" required> ';
		echo esc_html__(
			'I approve sending double-opt-in confirmation invitations to this CSV.',
			'argentwolf-post-notifier'
		);
		echo '</label></p>';
		echo '<p class="description">';
		echo esc_html__(
			'No address becomes subscribed unless its recipient intentionally confirms.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		submit_button( __( 'Send confirmation invitations', 'argentwolf-post-notifier' ) );
		echo '</form></div>';
	}

	/**
	 * Process one explicitly approved CSV intake request.
	 *
	 * @return never
	 */
	public function handle_import(): never {
		$this->require_capability();
		if ( 'POST' !== $this->request_method() ) {
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}

		check_admin_referer( self::IMPORT_ACTION );
		if ( '1' !== $this->request_text( $_POST, self::APPROVAL_FIELD ) ) {
			$this->redirect( array( 'arpn_import' => 'approval_required' ) );
		}

		$file = $this->uploaded_file();
		if ( null === $file ) {
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}
		if (
			UPLOAD_ERR_OK !== $file['error']
			|| $file['size'] < 1
			|| $file['size'] > self::MAX_UPLOAD_BYTES
			|| 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) )
			|| ! is_uploaded_file( $file['tmp_name'] )
		) {
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}

		// PHP's upload temp file must be read directly; it is never persisted.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream = fopen( $file['tmp_name'], 'rb' );
		if ( false === $stream ) {
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}

		try {
			$result = $this->importer->import( $stream );
		} catch ( InvalidArgumentException | RuntimeException ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $stream );
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream );

		$this->redirect(
			array(
				'arpn_import' => 'complete',
				'rows'        => $result->rows_total(),
				'submitted'   => $result->submitted(),
				'skipped'     => $result->skipped(),
				'invalid'     => $result->invalid(),
				'duplicates'  => $result->duplicates(),
				'failed'      => $result->mail_failures(),
			)
		);
	}

	/**
	 * Render a generic or aggregate import notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
		$notice = $this->request_text( $_GET, 'arpn_import' );
		if ( 'complete' === $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$rows = absint( $this->request_text( $_GET, 'rows' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$submitted = absint( $this->request_text( $_GET, 'submitted' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$skipped = absint( $this->request_text( $_GET, 'skipped' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$invalid = absint( $this->request_text( $_GET, 'invalid' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$duplicates = absint( $this->request_text( $_GET, 'duplicates' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$failed = absint( $this->request_text( $_GET, 'failed' ) );

			$message = sprintf(
				/* translators: 1: rows; 2: sent; 3: skipped; 4: invalid; 5: dupes; 6: failed. */
				__(
					'%1$d rows; %2$d sent; %3$d skipped; %4$d invalid; %5$d dupes; %6$d failed.',
					'argentwolf-post-notifier'
				),
				$rows,
				$submitted,
				$skipped,
				$invalid,
				$duplicates,
				$failed
			);
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html( $message );
			echo '</p></div>';
			return;
		}

		if ( 'approval_required' === $notice ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Explicit approval is required before confirmation invitations are sent.',
				'argentwolf-post-notifier'
			);
			echo '</p></div>';
			return;
		}

		if ( 'error' === $notice ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'The CSV could not be processed. Check its format and row limit, then try again.',
				'argentwolf-post-notifier'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Read and normalize the uploaded-file metadata needed for intake.
	 *
	 * @return array{name:string,tmp_name:string,size:int,error:int}|null
	 */
	private function uploaded_file(): ?array {
		// PHP upload metadata is validated and sanitized field-by-field below.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies the import nonce.
		$file = $_FILES[ self::FILE_FIELD ] ?? null;
		// phpcs:enable
		if ( ! is_array( $file ) ) {
			return null;
		}

		return array(
			'name'     => sanitize_file_name(
				wp_unslash( is_string( $file['name'] ?? null ) ? $file['name'] : '' )
			),
			'tmp_name' => sanitize_text_field(
				wp_unslash( is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '' )
			),
			'size'     => absint( wp_unslash( $file['size'] ?? 0 ) ),
			'error'    => absint( wp_unslash( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ),
		);
	}

	/**
	 * Require the current subscriber-management capability.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( current_user_can( SubscriberAdminPage::CAPABILITY ) ) {
			return;
		}

		wp_die(
			esc_html__(
				'You do not have permission to import subscriber invitations.',
				'argentwolf-post-notifier'
			),
			esc_html__( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' ),
			array( 'response' => 403 )
		);
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
	 * Redirect back to the import screen with aggregate/generic state only.
	 *
	 * @param array<string,int|string> $args Query arguments.
	 * @return never
	 */
	private function redirect( array $args ): never {
		$args['page'] = self::PAGE_SLUG;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

// EOF: src/Admin/SubscriberCsvImportPage.php.
