<?php
/**
 * File: src/Admin/SubscriberCsvImportPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use Throwable;
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
	 * Manual single-address invitation action.
	 */
	public const ADD_ACTION = 'argentwolf_post_notifier_add_subscriber';

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
	 * @param SubscriberCsvImporter    $importer Bounded double-opt-in importer.
	 * @param NamedListRepository|null $lists    Optional named lists for the manual form.
	 */
	public function __construct(
		private SubscriberCsvImporter $importer,
		private ?NamedListRepository $lists = null
	) {
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
		add_action(
			'admin_post_' . self::ADD_ACTION,
			array( $this, 'handle_add' )
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
		$this->render_add_notice();
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

		$this->render_add_form();

		echo '<h2>' . esc_html__( 'Import a CSV file', 'argentwolf-post-notifier' ) . '</h2>';
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
		$lists = $this->list_choices();
		if ( array() !== $lists ) {
			echo '<p><label for="arpn-import-lists"><strong>';
			echo esc_html__( 'Add imported contacts to named lists', 'argentwolf-post-notifier' );
			echo '</strong></label><br>';
			echo '<select id="arpn-import-lists" name="list_ids[]" multiple size="5">';
			foreach ( $lists as $id => $name ) {
				echo '<option value="' . esc_attr( (string) $id ) . '">';
				echo esc_html( $name ) . '</option>';
			}
			echo '</select></p><p class="description">';
			echo esc_html__(
				// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
				'Use Ctrl or Command to choose multiple lists. Membership remains pending until the email confirms global consent.',
				'argentwolf-post-notifier'
			);
			echo '</p>';
		}
		echo '<p><label><input type="checkbox" name="';
		echo esc_attr( self::APPROVAL_FIELD ) . '" value="1" required> ';
		echo esc_html__(
			'I approve sending double-opt-in confirmation invitations to this CSV.',
			'argentwolf-post-notifier'
		);
		echo '</label></p>';
		echo '<p class="description">';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Each email has one global consent status. Selected list memberships become eligible automatically upon confirmation.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		submit_button( __( 'Send confirmation invitations', 'argentwolf-post-notifier' ) );
		echo '</form></div>';
	}

	/**
	 * Render the manual single-address invitation form.
	 *
	 * @return void
	 */
	private function render_add_form(): void {
		echo '<h2>' . esc_html__( 'Add subscriber', 'argentwolf-post-notifier' ) . '</h2>';
		echo '<p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Sends the same confirmation invitation as CSV import. The address is subscribed only after its owner confirms.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD_ACTION ) . '">';
		wp_nonce_field( self::ADD_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="arpn-add-email">';
		echo esc_html__( 'Email address', 'argentwolf-post-notifier' );
		echo '</label></th><td><input id="arpn-add-email" class="regular-text" type="email"';
		echo ' name="email" maxlength="320" required autocomplete="off"></td></tr>';
		echo '<tr><th scope="row"><label for="arpn-add-name">';
		echo esc_html__( 'Name (optional)', 'argentwolf-post-notifier' );
		echo '</label></th><td><input id="arpn-add-name" class="regular-text" type="text"';
		echo ' name="display_name" maxlength="191"></td></tr>';
		$lists = $this->list_choices();
		if ( array() !== $lists ) {
			echo '<tr><th scope="row"><label for="arpn-add-list">';
			echo esc_html__( 'Add to list (optional)', 'argentwolf-post-notifier' );
			echo '</label></th><td><select id="arpn-add-list" name="list_id">';
			echo '<option value="0">';
			echo esc_html__( 'None', 'argentwolf-post-notifier' ) . '</option>';
			foreach ( $lists as $id => $name ) {
				echo '<option value="' . esc_attr( (string) $id ) . '">';
				echo esc_html( $name ) . '</option>';
			}
			echo '</select><p class="description">';
			echo esc_html__(
				'List membership is organizational and does not subscribe the address.',
				'argentwolf-post-notifier'
			);
			echo '</p></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Send confirmation invitation', 'argentwolf-post-notifier' ) );
		echo '</form>';
	}

	/**
	 * Handle the manual add form.
	 *
	 * @return never
	 */
	public function handle_add(): never {
		$this->require_capability();
		if ( 'POST' !== $this->request_method() ) {
			$this->redirect( array( 'arpn_add' => 'error' ) );
		}
		check_admin_referer( self::ADD_ACTION );

		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';
		$this->redirect(
			$this->add_subscriber(
				$email,
				$this->request_text( $_POST, 'display_name' ),
				absint( $this->request_text( $_POST, 'list_id' ) )
			)
		);
	}

	/**
	 * Invite one address for an already-authorized request.
	 *
	 * @param string $email   Sanitized address.
	 * @param string $name    Optional display name.
	 * @param int    $list_id Optional named list ID, 0 for none.
	 * @return array<string,string> Generic redirect query arguments.
	 */
	public function add_subscriber( string $email, string $name, int $list_id ): array {
		try {
			$result = $this->importer->invite( $email, $name );
		} catch ( Throwable ) {
			return array( 'arpn_add' => 'error' );
		}
		$args = array( 'arpn_add' => $result['outcome'] );
		if (
			$list_id > 0
			&& $result['subscriber_id'] > 0
			&& null !== $this->lists
			&& current_user_can( NamedListAdminPage::CAPABILITY )
		) {
			try {
				$this->lists->add_member(
					$list_id,
					NamedListMemberType::Subscriber,
					$result['subscriber_id']
				);
				$args['arpn_listed'] = '1';
			} catch ( Throwable ) {
				$args['arpn_listed'] = '0';
			}
		}

		return $args;
	}

	/**
	 * Lists the current user may assign, keyed by ID.
	 *
	 * @return array<int,string>
	 */
	private function list_choices(): array {
		if ( null === $this->lists || ! current_user_can( NamedListAdminPage::CAPABILITY ) ) {
			return array();
		}
		$choices = array();
		foreach ( $this->lists->all() as $list ) {
			$choices[ (int) $list['id'] ] = (string) $list['name'];
		}

		return $choices;
	}

	/**
	 * Render the manual-add result notice.
	 *
	 * @return void
	 */
	private function render_add_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only notice keys.
		$notice = $this->request_text( $_GET, 'arpn_add' );
		$listed = $this->request_text( $_GET, 'arpn_listed' );
		// phpcs:enable
		// phpcs:disable Generic.Files.LineLength -- Preserve gettext source text.
		$messages = array(
			SubscriberCsvImporter::INVITE_SENT        => __(
				'A confirmation invitation was sent. The address will be subscribed only after its owner confirms.',
				'argentwolf-post-notifier'
			),
			SubscriberCsvImporter::INVITE_NOT_SENT    => __(
				'No invitation was sent. The address may already be subscribed, recently invited, or unable to receive notifications.',
				'argentwolf-post-notifier'
			),
			SubscriberCsvImporter::INVITE_INVALID     => __( 'Enter a valid email address.', 'argentwolf-post-notifier' ),
			SubscriberCsvImporter::INVITE_MAIL_FAILED => __(
				'The invitation could not be handed to the mail system. Check your mail setup and try again.',
				'argentwolf-post-notifier'
			),
			'error'                                   => __(
				'The request could not be processed. Please try again.',
				'argentwolf-post-notifier'
			),
		);
		// phpcs:enable Generic.Files.LineLength
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		$class = SubscriberCsvImporter::INVITE_SENT === $notice
			? 'notice-success'
			: 'notice-warning';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
		echo esc_html( $messages[ $notice ] );
		if ( '1' === $listed ) {
			echo ' ';
			echo esc_html__(
				'The address was added to the selected list.',
				'argentwolf-post-notifier'
			);
		} elseif ( '0' === $listed ) {
			echo ' ';
			echo esc_html__(
				'The address could not be added to the selected list.',
				'argentwolf-post-notifier'
			);
		}
		echo '</p></div>';
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

		// Only list managers may assign memberships through subscriber CSV intake.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$raw_ids = wp_unslash( $_POST['list_ids'] ?? array() );
		if ( ! is_array( $raw_ids ) || count( $raw_ids ) > 25 ) {
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}
		if ( array() !== $raw_ids && ! current_user_can( NamedListAdminPage::CAPABILITY ) ) {
			$this->redirect( array( 'arpn_import' => 'error' ) );
		}
		$list_ids = array();
		foreach ( $raw_ids as $raw_id ) {
			if ( ! is_scalar( $raw_id ) || ! ctype_digit( (string) $raw_id ) ) {
				$this->redirect( array( 'arpn_import' => 'error' ) );
			}
			$list_ids[] = absint( $raw_id );
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
			$result = $this->importer->import( $stream, $list_ids );
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
				'listed'      => $result->memberships_added(),
				'batch'       => $result->batch_id(),
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Aggregate count.
			$listed = absint( $this->request_text( $_GET, 'listed' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only reference.
			$batch = sanitize_key( $this->request_text( $_GET, 'batch' ) );

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
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: %d: newly assigned named-list memberships. */
					__( '%d new list memberships recorded.', 'argentwolf-post-notifier' ),
					$listed
				)
			);
			if ( 1 === preg_match( '/\A[a-f0-9]{24}\z/D', $batch ) ) {
				echo ' ';
				echo esc_html(
					sprintf(
						/* translators: %s: opaque CSV import batch reference. */
						__( 'Import reference: %s', 'argentwolf-post-notifier' ),
						$batch
					)
				);
			}
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
