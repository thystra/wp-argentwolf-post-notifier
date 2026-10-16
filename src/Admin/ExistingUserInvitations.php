<?php
/**
 * File: src/Admin/ExistingUserInvitations.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use Throwable;
use WP_User;
use WP_User_Query;

/**
 * One-time offer to invite existing users with the double opt-in flow.
 *
 * Existing accounts are never auto-enrolled. After activation an admin may
 * send each non-administrator user the same confirmation invitation used by
 * the CSV import. Sending runs in small cron batches, skips suppressed and
 * already-subscribed people, and can run only once per site.
 */
final class ExistingUserInvitations implements Registerable {

	public const OPTION         = 'argentwolf_post_notifier_user_invitations';
	public const HOOK           = 'argentwolf_post_notifier_user_invitation_batch';
	public const PAGE_SLUG      = 'argentwolf-post-notifier-invite-users';
	public const START_ACTION   = 'argentwolf_post_notifier_invite_users';
	public const DISMISS_ACTION = 'argentwolf_post_notifier_dismiss_user_invitations';
	public const RESET_ACTION   = 'argentwolf_post_notifier_reset_user_invitations';
	public const CAPABILITY     = Capabilities::MANAGE_SUBSCRIBERS;
	public const SOURCE         = 'user_invite';
	public const BATCH_SIZE     = 25;
	public const BATCH_DELAY    = 60;

	public const STATUS_OFFERED = 'offered';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';

	/**
	 * Construct the service.
	 *
	 * @param SubscriberCsvImporter              $importer    Invitation sender.
	 * @param RegisteredUserPreferenceRepository $preferences Registered-user preferences.
	 * @param NamedListRepository|null           $lists       Optional list store.
	 */
	public function __construct(
		private SubscriberCsvImporter $importer,
		private RegisteredUserPreferenceRepository $preferences,
		private ?NamedListRepository $lists = null
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
	}

	/**
	 * Record the offer on first activation only; never resets a previous run.
	 *
	 * @return void
	 */
	public static function offer(): void {
		add_option( self::OPTION, self::initial_state( self::STATUS_OFFERED ), '', false );
	}

	/**
	 * Clear a scheduled batch (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Current state.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		return $this->heal( $this->stored_state() );
	}

	/**
	 * Stored state without self-healing (used inside the cron batch, where the
	 * running event has already been unscheduled by WP-Cron).
	 *
	 * @return array<string,mixed>
	 */
	private function stored_state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = self::initial_state( '' );
		foreach ( $state as $key => $default ) {
			if ( isset( $stored[ $key ] ) ) {
				$state[ $key ] = is_bool( $default ) ? (bool) $stored[ $key ]
					: ( is_int( $default ) ? (int) $stored[ $key ] : (string) $stored[ $key ] );
			}
		}

		return $state;
	}

	/**
	 * Repair a missing, unknown or stuck state.
	 *
	 * - Missing or unknown status (for example a site upgraded from 0.1.0-rc.1,
	 *   where the activation hook that records the offer never ran): offered.
	 * - Running with nothing to do, or with no batch scheduled and nothing sent:
	 *   back to offered, so an admin must start it explicitly.
	 * - Running with invitations already sent but no batch scheduled (cron was
	 *   cleared): the next batch is rescheduled; the once-only rule holds.
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return array<string,mixed>
	 */
	private function heal( array $state ): array {
		$known = array( self::STATUS_OFFERED, self::STATUS_RUNNING, self::STATUS_DONE );
		if ( ! in_array( $state['status'], $known, true ) ) {
			$healed              = self::initial_state( self::STATUS_OFFERED );
			$healed['dismissed'] = $state['dismissed'];
			update_option( self::OPTION, $healed, false );

			return $healed;
		}
		if ( self::STATUS_RUNNING !== $state['status'] ) {
			return $state;
		}
		$scheduled = false !== wp_next_scheduled( self::HOOK );
		if ( $state['sent'] < 1 && ( $state['total'] < 1 || ! $scheduled ) ) {
			$healed              = self::initial_state( self::STATUS_OFFERED );
			$healed['dismissed'] = $state['dismissed'];
			update_option( self::OPTION, $healed, false );

			return $healed;
		}
		if ( ! $scheduled ) {
			wp_schedule_single_event( time(), self::HOOK );
		}

		return $state;
	}

	/**
	 * Number of users an invitation run would process now.
	 *
	 * @return int
	 */
	public function audience_count(): int {
		return count( $this->user_ids( 0, 0 ) );
	}

	/**
	 * Whether the run may be reset: only when no invitation was ever sent.
	 *
	 * @return bool
	 */
	public function can_reset(): bool {
		$state = $this->state();

		return self::STATUS_OFFERED !== $state['status'] && $state['sent'] < 1;
	}

	/**
	 * Reset an unsent run back to offered.
	 *
	 * @return bool False when invitations were already sent (once-only rule).
	 */
	public function reset(): bool {
		if ( ! $this->can_reset() ) {
			return false;
		}
		self::unschedule();
		update_option( self::OPTION, self::initial_state( self::STATUS_OFFERED ), false );

		return true;
	}

	/**
	 * Start the one-time run.
	 *
	 * @param int $list_id Optional list for invited subscribers (0 for none).
	 * @return bool False when already started or finished.
	 */
	public function start( int $list_id = 0 ): bool {
		$state = $this->state();
		if ( self::STATUS_OFFERED !== $state['status'] ) {
			return false;
		}
		$known = null !== $this->lists && null !== $this->lists->find( $list_id );
		if ( $list_id > 0 && ! $known ) {
			$list_id = 0;
		}
		$state['list_id'] = $list_id;
		$state['total']   = $this->audience_count();
		if ( $state['total'] < 1 ) {
			// Nothing to send: finish immediately instead of a 0-of-0 "running" run.
			$state['status'] = self::STATUS_DONE;
			update_option( self::OPTION, $state, false );

			return true;
		}
		$state['status'] = self::STATUS_RUNNING;
		update_option( self::OPTION, $state, false );
		wp_schedule_single_event( time(), self::HOOK );

		return true;
	}

	/**
	 * Process one bounded batch and schedule the next one.
	 *
	 * @return array{status:string,processed:int}
	 */
	public function run_batch(): array {
		$state = $this->stored_state();
		if ( self::STATUS_RUNNING !== $state['status'] ) {
			return array(
				'status'    => $state['status'],
				'processed' => 0,
			);
		}

		$ids = $this->user_ids( $state['last_user_id'], self::BATCH_SIZE );
		foreach ( $ids as $user_id ) {
			$outcome = $this->invite_user( $user_id, $state['list_id'] );
			++$state[ $outcome ];
			++$state['processed'];
			$state['last_user_id'] = $user_id;
			// Persist progress per user so a crash never re-invites someone.
			update_option( self::OPTION, $state, false );
		}

		if ( count( $ids ) < self::BATCH_SIZE ) {
			$state['status'] = self::STATUS_DONE;
			update_option( self::OPTION, $state, false );
		} elseif ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::BATCH_DELAY, self::HOOK );
		}

		return array(
			'status'    => $state['status'],
			'processed' => count( $ids ),
		);
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Invite existing users', 'argentwolf-post-notifier' ),
			__( 'Invite users', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Dismissible notice while the offer is open.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		$state = $this->state();
		if (
			self::STATUS_OFFERED !== $state['status']
			|| $state['dismissed']
			|| ! current_user_can( self::CAPABILITY )
		) {
			return;
		}
		$dismiss = wp_nonce_url(
			add_query_arg( 'action', self::DISMISS_ACTION, admin_url( 'admin-post.php' ) ),
			self::DISMISS_ACTION
		);
		echo '<div class="notice notice-info"><p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Post Notifier does not subscribe existing users automatically. You can send them a one-time invitation to confirm a subscription.',
			'argentwolf-post-notifier'
		);
		echo '</p><p><a class="button button-primary" href="' . esc_url( $this->page_url() ) . '">';
		echo esc_html__( 'Review invitation', 'argentwolf-post-notifier' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $dismiss ) . '">';
		echo esc_html__( 'Dismiss', 'argentwolf-post-notifier' ) . '</a></p></div>';
	}

	/**
	 * Render the invitation screen with progress.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();
		$state = $this->state();
		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Invite existing users', 'argentwolf-post-notifier' ) . '</h1><p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Each non-administrator user receives the same confirmation email as a CSV import. Nobody is subscribed until they confirm. Unsubscribed (suppressed) and already-subscribed users are skipped. Emails are sent in small batches by WP-Cron. This can be done once.',
			'argentwolf-post-notifier'
		) . '</p>';

		if ( self::STATUS_OFFERED === $state['status'] ) {
			$this->render_start_form();
		} else {
			$this->render_progress( $state );
		}
		echo '</div>';
	}

	/**
	 * Handle the start form.
	 *
	 * @return never
	 */
	public function handle_start(): never {
		$this->require_capability();
		check_admin_referer( self::START_ACTION );
		$list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
		if ( ! current_user_can( NamedListAdminPage::CAPABILITY ) ) {
			$list_id = 0;
		}
		$this->start( $list_id );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Handle the notice dismissal.
	 *
	 * @return never
	 */
	public function handle_dismiss(): never {
		$this->require_capability();
		check_admin_referer( self::DISMISS_ACTION );
		$this->dismiss();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Hide the notice; the screen stays available.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		$state              = $this->state();
		$state['dismissed'] = true;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Invite one user and classify the outcome.
	 *
	 * @param int $user_id User ID.
	 * @param int $list_id Optional list ID.
	 * @return string State counter key: sent, skipped or failed.
	 */
	private function invite_user( int $user_id, int $list_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return 'skipped';
		}
		if ( RegisteredUserPreference::Subscribed === $this->preferences->get( $user_id ) ) {
			return 'skipped';
		}
		try {
			$result = $this->importer->invite(
				$user->user_email,
				'' === $user->display_name ? null : $user->display_name,
				self::SOURCE
			);
		} catch ( Throwable ) {
			return 'failed';
		}
		if ( SubscriberCsvImporter::INVITE_SENT !== $result['outcome'] ) {
			return SubscriberCsvImporter::INVITE_MAIL_FAILED === $result['outcome']
				? 'failed'
				: 'skipped';
		}
		if ( $list_id > 0 && $result['subscriber_id'] > 0 && null !== $this->lists ) {
			try {
				$this->lists->add_member(
					$list_id,
					NamedListMemberType::Subscriber,
					$result['subscriber_id']
				);
			} catch ( Throwable ) {
				// List membership is organizational; the invitation was still sent.
				return 'sent';
			}
		}

		return 'sent';
	}

	/**
	 * Non-administrator user IDs after a cursor.
	 *
	 * @param int $after Last processed user ID.
	 * @param int $limit Maximum IDs, or 0 for all.
	 * @return array<int,int>
	 */
	private function user_ids( int $after, int $limit ): array {
		// Fetch every member of this site by ID, then exclude administrators by
		// their actual roles. No role meta LIKE filter is used, so custom roles,
		// role-less members created by membership plugins and alternate
		// capability storage are all counted. Results are never cached.
		$query = new WP_User_Query(
			array(
				'fields'      => 'ID',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'number'      => -1,
				'blog_id'     => get_current_blog_id(),
				'count_total' => false,
			)
		);
		$ids   = array();
		foreach ( (array) $query->get_results() as $id ) {
			$id = (int) $id;
			if ( $id <= $after ) {
				continue;
			}
			$user = get_userdata( $id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
				$ids[] = $id;
			}
		}

		return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
	}

	/**
	 * Start form with optional list.
	 *
	 * @return void
	 */
	private function render_start_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::START_ACTION ) . '">';
		wp_nonce_field( self::START_ACTION );
		if ( null !== $this->lists && current_user_can( NamedListAdminPage::CAPABILITY ) ) {
			$lists = $this->lists->all();
			if ( array() !== $lists ) {
				echo '<p><label for="arpn-invite-list">';
				echo esc_html__(
					'Add invited users to list (optional)',
					'argentwolf-post-notifier'
				);
				echo '</label> <select id="arpn-invite-list" name="list_id"><option value="0">';
				echo esc_html__( 'None', 'argentwolf-post-notifier' ) . '</option>';
				foreach ( $lists as $list ) {
					echo '<option value="' . esc_attr( (string) $list['id'] ) . '">';
					echo esc_html( (string) $list['name'] ) . '</option>';
				}
				echo '</select></p>';
			}
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of users. */
				_n(
					'%d non-administrator user would be invited.',
					'%d non-administrator users would be invited.',
					$this->audience_count(),
					'argentwolf-post-notifier'
				),
				$this->audience_count()
			)
		) . '</p>';
		submit_button( __( 'Send invitations', 'argentwolf-post-notifier' ) );
		echo '</form>';
	}

	/**
	 * Progress summary.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return void
	 */
	private function render_progress( array $state ): void {
		if ( self::STATUS_DONE === $state['status'] && $state['total'] < 1 ) {
			echo '<p><strong>' . esc_html__(
				'There were no non-administrator users to invite, so nothing was sent.',
				'argentwolf-post-notifier'
			) . '</strong></p>';
			$this->render_reset_form();
			return;
		}
		echo '<p><strong>';
		echo esc_html(
			self::STATUS_DONE === $state['status']
				? __( 'Invitations finished.', 'argentwolf-post-notifier' )
				: __( 'Invitations are being sent.', 'argentwolf-post-notifier' )
		);
		echo '</strong></p><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: processed, 2: total, 3: invited, 4: skipped, 5: failed. */
				__(
					'Processed %1$d of %2$d users: %3$d invited, %4$d skipped, %5$d failed.',
					'argentwolf-post-notifier'
				),
				(int) $state['processed'],
				(int) $state['total'],
				(int) $state['sent'],
				(int) $state['skipped'],
				(int) $state['failed']
			)
		);
		echo '</p>';
		$this->render_reset_form();
	}

	/**
	 * Reset button, shown only while no invitation has been sent.
	 *
	 * @return void
	 */
	private function render_reset_form(): void {
		if ( ! $this->can_reset() ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RESET_ACTION ) . '">';
		wp_nonce_field( self::RESET_ACTION );
		submit_button( __( 'Reset and start again', 'argentwolf-post-notifier' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Handle the reset form.
	 *
	 * @return never
	 */
	public function handle_reset(): never {
		$this->require_capability();
		check_admin_referer( self::RESET_ACTION );
		$this->reset();
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Stop unless the current user may manage subscribers.
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
	 * Fresh state.
	 *
	 * @param string $status Initial status.
	 * @return array<string,mixed>
	 */
	private static function initial_state( string $status ): array {
		return array(
			'status'       => $status,
			'dismissed'    => false,
			'list_id'      => 0,
			'last_user_id' => 0,
			'total'        => 0,
			'processed'    => 0,
			'sent'         => 0,
			'skipped'      => 0,
			'failed'       => 0,
		);
	}
}

// EOF: src/Admin/ExistingUserInvitations.php.
