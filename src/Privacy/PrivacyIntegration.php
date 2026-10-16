<?php
/**
 * File: src/Privacy/PrivacyIntegration.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Privacy;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use Throwable;
use WP_Post;

/**
 * WordPress privacy API integration and content/user removal handling.
 *
 * - Personal data exporter and eraser (Tools > Export/Erase Personal Data).
 * - Suggested privacy-policy text.
 * - Deleted users: their recipient rows are redacted and list memberships removed.
 * - Posts leaving the published state: undelivered campaigns are stopped.
 */
final class PrivacyIntegration implements Registerable {

	public const EXPORTER_ID = 'argentwolf-post-notifier';
	public const ERASER_ID   = 'argentwolf-post-notifier';

	/**
	 * Construct the integration.
	 *
	 * @param PrivacyRepository $repository Privacy SQL.
	 * @param EmailIdentity     $identity   Keyed email hashing.
	 */
	public function __construct(
		private PrivacyRepository $repository,
		private EmailIdentity $identity
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
		add_action( 'deleted_user', array( $this, 'handle_deleted_user' ) );
		add_action( 'before_delete_post', array( $this, 'handle_post_removed' ) );
		add_action( 'transition_post_status', array( $this, 'handle_transition' ), 10, 3 );
	}

	/**
	 * Add the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( mixed $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$name      = __( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' );

		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => $name,
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( mixed $erasers ): array {
		$erasers                    = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export one page of personal data.
	 *
	 * @param string $email Requested address.
	 * @param int    $page  1-based page.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$hash = $this->identity->hash( $email );
		if ( null === $hash ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$page    = max( 1, $page );
		$user    = get_user_by( 'email', $email );
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		$data    = array();

		if ( 1 === $page ) {
			$data = array_merge( $data, $this->export_identity( $hash, $user_id ) );
		}

		$rows = $this->repository->recipient_page( $hash, $user_id, $page );
		// phpcs:disable Generic.Files.LineLength -- Label/value maps read best on one line.
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'argentwolf-post-notifier-deliveries',
				'group_label' => __( 'Post notification deliveries', 'argentwolf-post-notifier' ),
				'item_id'     => 'argentwolf-post-notifier-delivery-' . (int) $row['id'],
				'data'        => $this->pairs(
					array(
						__( 'Post', 'argentwolf-post-notifier' ) => get_the_title( (int) $row['post_id'] ),
						__( 'Email address', 'argentwolf-post-notifier' ) => $row['email_snapshot'],
						__( 'Name', 'argentwolf-post-notifier' ) => $row['display_name_snapshot'],
						__( 'Delivery status', 'argentwolf-post-notifier' ) => $row['status'],
						__( 'Skip reason', 'argentwolf-post-notifier' ) => $row['skip_reason'],
						__( 'Submitted (UTC)', 'argentwolf-post-notifier' ) => $row['submitted_at_gmt'],
						__( 'Tracked clicks', 'argentwolf-post-notifier' ) => $row['click_count'],
						__( 'First click (UTC)', 'argentwolf-post-notifier' ) => $row['first_clicked_at_gmt'],
						__( 'Last click (UTC)', 'argentwolf-post-notifier' ) => $row['last_clicked_at_gmt'],
					)
				),
			);
		}

		// phpcs:enable Generic.Files.LineLength

		return array(
			'data' => $data,
			'done' => count( $rows ) < PrivacyRepository::PAGE_SIZE,
		);
	}

	/**
	 * Erase one batch of personal data.
	 *
	 * The suppression entry keeps its keyed hash so the address is never
	 * mailed again; its readable copy is removed and the retention is reported.
	 *
	 * @param string $email Requested address.
	 * @param int    $page  1-based page (unused; each call processes the next batch).
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$hash   = $this->identity->hash( $email );
		if ( null === $hash ) {
			return $result;
		}
		$user    = get_user_by( 'email', $email );
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;

		try {
			$removed  = $this->repository->delete_subscriber( $hash ) > 0;
			$removed  = $this->repository->redact_audit( $hash ) > 0 || $removed;
			$redacted = $this->repository->redact_recipients(
				$hash,
				$user_id,
				PrivacyRepository::SKIP_ERASED
			);
			$removed  = $redacted > 0 || $removed;
			if ( $user_id > 0 ) {
				$removed = $this->repository->delete_user_memberships( $user_id ) > 0 || $removed;
				$meta    = RegisteredUserPreferenceRepository::META_KEY;
				$removed = delete_user_meta( $user_id, $meta ) || $removed;
			}
			$this->repository->redact_suppression( $hash );
			if ( null !== $this->repository->suppression( $hash ) ) {
				$result['items_retained'] = true;
				$result['messages'][]     = __(
					// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
					'An unsubscribe record was kept as a one-way hash so this address is never emailed again. Its readable copy was removed.',
					'argentwolf-post-notifier'
				);
			}
		} catch ( Throwable ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __(
				'Post notification data could not be fully erased; please try again.',
				'argentwolf-post-notifier'
			);
			return $result;
		}

		$result['items_removed'] = $removed;
		$result['done']          = $redacted < PrivacyRepository::PAGE_SIZE;

		return $result;
	}

	/**
	 * Suggest privacy-policy text.
	 *
	 * @return void
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' ),
			wp_kses_post( wpautop( self::policy_text() ) )
		);
	}

	/**
	 * Suggested policy text.
	 *
	 * @return string
	 */
	public static function policy_text(): string {
		// phpcs:disable Generic.Files.LineLength -- Preserve gettext source text.
		$paragraphs = array(
			array(
				__( 'When you subscribe to new-post notifications we store your email address, an optional name, your subscription status and the time and source of your signup.', 'argentwolf-post-notifier' ),
				__( 'We also keep the consent text you agreed to. If you have an account, your notification preference is stored with your profile.', 'argentwolf-post-notifier' ),
			),
			array(
				__( 'For each notification we keep a delivery record: your address, the delivery status and time.', 'argentwolf-post-notifier' ),
				__( 'If click counting is enabled, the post link in the email passes through this site so the use of the link can be counted.', 'argentwolf-post-notifier' ),
				__( 'Only the time of the click is stored, never your IP address, browser details or cookies. Email opens are never tracked.', 'argentwolf-post-notifier' ),
			),
			array(
				__( 'Click events and per-recipient delivery records are deleted or anonymized after the retention periods set by the site owner.', 'argentwolf-post-notifier' ),
				__( 'When you unsubscribe, a one-way hash of your address is kept so you are not emailed again.', 'argentwolf-post-notifier' ),
				__( 'You can ask the site owner to export or erase this data. No data is sent to the plugin author.', 'argentwolf-post-notifier' ),
			),
		);
		// phpcs:enable Generic.Files.LineLength
		$text = array();
		foreach ( $paragraphs as $sentences ) {
			$text[] = implode( ' ', $sentences );
		}

		return implode( "\n\n", $text );
	}

	/**
	 * Redact recipient rows of a deleted user.
	 *
	 * @param mixed $user_id Deleted user ID.
	 * @return void
	 */
	public function handle_deleted_user( mixed $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}
		try {
			$this->repository->delete_user_memberships( $user_id );
			for ( $i = 0; $i < 50; ++$i ) {
				$redacted = $this->repository->redact_recipients(
					'',
					$user_id,
					PrivacyRepository::SKIP_USER_DELETED
				);
				if ( $redacted < PrivacyRepository::PAGE_SIZE ) {
					break;
				}
			}
		} catch ( Throwable ) {
			// Remaining rows are covered by the recheck and the retention job.
			return;
		}
	}

	/**
	 * Stop delivery when a post is deleted.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public function handle_post_removed( mixed $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return;
		}
		try {
			$this->repository->stop_post_campaigns( $post_id );
		} catch ( Throwable ) {
			return;
		}
	}

	/**
	 * Stop delivery when a published post is unpublished or trashed.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public function handle_transition( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( 'publish' !== $old_status || 'publish' === $new_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$this->handle_post_removed( $post->ID );
	}

	/**
	 * Identity-level export items.
	 *
	 * @param string $hash    Keyed email hash.
	 * @param int    $user_id User ID or 0.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_identity( string $hash, int $user_id ): array {
		// phpcs:disable Generic.Files.LineLength -- Label/value maps read best on one line.
		$items      = array();
		$subscriber = $this->repository->subscriber( $hash );
		if ( null !== $subscriber ) {
			$items[] = array(
				'group_id'    => 'argentwolf-post-notifier-subscription',
				'group_label' => __( 'Post notification subscription', 'argentwolf-post-notifier' ),
				'item_id'     => 'argentwolf-post-notifier-subscriber-' . (int) $subscriber['id'],
				'data'        => $this->pairs(
					array(
						__( 'Email address', 'argentwolf-post-notifier' ) => $subscriber['email'],
						__( 'Name', 'argentwolf-post-notifier' ) => $subscriber['display_name'],
						__( 'Status', 'argentwolf-post-notifier' ) => $subscriber['status'],
						__( 'Signed up (UTC)', 'argentwolf-post-notifier' ) => $subscriber['created_at_gmt'],
						__( 'Confirmed (UTC)', 'argentwolf-post-notifier' ) => $subscriber['confirmed_at_gmt'],
						__( 'Unsubscribed (UTC)', 'argentwolf-post-notifier' ) => $subscriber['unsubscribed_at_gmt'],
						__( 'Signup source', 'argentwolf-post-notifier' ) => $subscriber['signup_source'],
						__( 'Consent text', 'argentwolf-post-notifier' ) => $subscriber['consent_text_snapshot'],
					)
				),
			);
		}
		if ( $user_id > 0 ) {
			$preference = get_user_meta( $user_id, RegisteredUserPreferenceRepository::META_KEY, true );
			if ( is_string( $preference ) && '' !== $preference ) {
				$items[] = array(
					'group_id'    => 'argentwolf-post-notifier-subscription',
					'group_label' => __( 'Post notification subscription', 'argentwolf-post-notifier' ),
					'item_id'     => 'argentwolf-post-notifier-user-' . $user_id,
					'data'        => $this->pairs(
						array( __( 'Account notification preference', 'argentwolf-post-notifier' ) => $preference )
					),
				);
			}
		}
		$suppression = $this->repository->suppression( $hash );
		if ( null !== $suppression ) {
			$items[] = array(
				'group_id'    => 'argentwolf-post-notifier-suppression',
				'group_label' => __( 'Post notification suppression', 'argentwolf-post-notifier' ),
				'item_id'     => 'argentwolf-post-notifier-suppression-' . (int) $suppression['id'],
				'data'        => $this->pairs(
					array(
						__( 'Email address', 'argentwolf-post-notifier' ) => $suppression['email_snapshot_or_redacted'],
						__( 'Reason', 'argentwolf-post-notifier' ) => $suppression['reason'],
						__( 'Source', 'argentwolf-post-notifier' ) => $suppression['source'],
						__( 'Recorded (UTC)', 'argentwolf-post-notifier' ) => $suppression['created_at_gmt'],
					)
				),
			);
		}
		// phpcs:enable Generic.Files.LineLength

		return $items;
	}

	/**
	 * Convert label => value pairs, dropping empty values.
	 *
	 * @param array<string,mixed> $values Values.
	 * @return array<int,array{name:string,value:string}>
	 */
	private function pairs( array $values ): array {
		$pairs = array();
		foreach ( $values as $name => $value ) {
			if ( null === $value || '' === (string) $value ) {
				continue;
			}
			$pairs[] = array(
				'name'  => (string) $name,
				'value' => (string) $value,
			);
		}

		return $pairs;
	}
}

// EOF: src/Privacy/PrivacyIntegration.php.
