<?php
/**
 * File: src/Admin/NotificationDefaultsPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Settings\NotificationDefaults;
use InvalidArgumentException;

/**
 * Administrator-only settings for global publication and default audience.
 */
final class NotificationDefaultsPage implements Registerable {
	/** Page slug. */
	public const PAGE_SLUG = 'argentwolf-post-notifier-defaults';

	/** POST action. */
	public const SAVE_ACTION = 'argentwolf_post_notifier_save_defaults';

	/**
	 * Construct with the shared canonical policy and list repository.
	 *
	 * @param NotificationDefaults $defaults Canonical site policy.
	 * @param NamedListRepository  $lists    Available named lists.
	 */
	public function __construct(
		private NotificationDefaults $defaults,
		private NamedListRepository $lists
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
	}

	/**
	 * Add settings below Post Notifier.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Notification Defaults', 'argentwolf-post-notifier' ),
			__( 'Settings', 'argentwolf-post-notifier' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the current site policy and named-list choices.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->authorize();
		$policy = $this->defaults->get();
		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Post notification defaults', 'argentwolf-post-notifier' );
		echo '</h1><p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'All Subscribers includes opted-in email subscribers and verified, opted-in WordPress users. A list selects candidates but never bypasses consent, verification, or suppression.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status.
		if ( isset( $_GET['awpn_defaults_saved'] ) ) {
			echo '<div class="notice notice-success"><p>';
			echo esc_html__( 'Notification defaults saved.', 'argentwolf-post-notifier' );
			echo '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status.
		if ( isset( $_GET['awpn_defaults_error'] ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Invalid settings or the selected list is unavailable.',
				'argentwolf-post-notifier'
			);
			echo '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="';
		echo esc_attr( self::SAVE_ACTION ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
		echo esc_html__( 'Notify on publication', 'argentwolf-post-notifier' );
		echo '</th><td><label><input type="checkbox" name="send" value="1" ';
		checked( true, $policy['send'] );
		echo '> ';
		echo esc_html__(
			'Send notifications for new posts unless the author overrides this.',
			'argentwolf-post-notifier'
		);
		echo '</label><p class="description">';
		echo esc_html__(
			'Off by default on upgraded sites. Changing this does not send old posts.',
			'argentwolf-post-notifier'
		);
		echo '</p></td></tr><tr><th scope="row"><label for="awpn-default-audience">';
		echo esc_html__( 'Default audience', 'argentwolf-post-notifier' );
		echo '</label></th><td><select id="awpn-default-audience" name="audience">';
		echo '<option value="all" ';
		selected( NotificationDefaults::AUDIENCE_ALL, $policy['audience'] );
		echo '>' . esc_html__( 'All Subscribers (automatic)', 'argentwolf-post-notifier' );
		echo '</option>';
		foreach ( $this->lists->all() as $list ) {
			$id = (int) $list['id'];
			echo '<option value="' . esc_attr( 'list:' . $id ) . '" ';
			selected(
				NotificationDefaults::AUDIENCE_LIST === $policy['audience']
					&& $id === $policy['list_id'],
				true
			);
			echo '>' . esc_html( (string) $list['name'] ) . '</option>';
		}
		echo '</select><p class="description">';
		echo esc_html__(
			'A custom audience selected on a post replaces this default audience.',
			'argentwolf-post-notifier'
		);
		echo '</p></td></tr></tbody></table>';
		submit_button( __( 'Save notification defaults', 'argentwolf-post-notifier' ) );
		echo '</form></div>';
	}

	/**
	 * Persist only explicitly approved values and known lists.
	 *
	 * @return never
	 */
	public function save(): never {
		$this->authorize();
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Invalid request method.', 'argentwolf-post-notifier' ) );
		}
		check_admin_referer( self::SAVE_ACTION );
		$raw        = isset( $_POST['audience'] ) && is_string( $_POST['audience'] )
			? sanitize_text_field( wp_unslash( $_POST['audience'] ) )
			: '';
		$send_value = isset( $_POST['send'] ) && is_string( $_POST['send'] )
			? sanitize_text_field( wp_unslash( $_POST['send'] ) )
			: '0';
		if ( ! in_array( $send_value, array( '0', '1' ), true ) ) {
			$this->redirect( false );
		}
		$audience = NotificationDefaults::AUDIENCE_ALL;
		$list_id  = 0;
		if ( 'all' !== $raw ) {
			if ( 1 !== preg_match( '/^list:([1-9][0-9]*)$/D', $raw, $matches ) ) {
				$this->redirect( false );
			}
			$audience = NotificationDefaults::AUDIENCE_LIST;
			$list_id  = (int) $matches[1];
		}
		try {
			$this->defaults->save(
				array(
					'send'     => '1' === $send_value,
					'audience' => $audience,
					'list_id'  => $list_id,
				),
				$this->lists
			);
		} catch ( InvalidArgumentException ) {
			$this->redirect( false );
		}
		$this->redirect( true );
	}

	/**
	 * Redirect after form submission.
	 *
	 * @param bool $saved Whether the policy was saved.
	 * @return never
	 */
	private function redirect( bool $saved ): never {
		wp_safe_redirect(
			add_query_arg(
				$saved ? 'awpn_defaults_saved' : 'awpn_defaults_error',
				'1',
				admin_url( 'admin.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Restrict all actions to site administrators.
	 *
	 * @return void
	 */
	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'argentwolf-post-notifier' ) );
		}
	}
}

// EOF: src/Admin/NotificationDefaultsPage.php.
