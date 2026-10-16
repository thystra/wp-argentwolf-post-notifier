<?php
/**
 * File: src/Admin/PrivacySettingsPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\DestructiveUninstaller;
use ArgentWolf\PostNotifier\Privacy\PrivacySettings;

/**
 * Privacy, click-tracking, retention and uninstall settings screen.
 */
final class PrivacySettingsPage implements Registerable {

	public const PAGE_SLUG   = 'argentwolf-post-notifier-privacy';
	public const SAVE_ACTION = 'argentwolf_post_notifier_save_privacy';
	public const CAPABILITY  = 'manage_options';

	/**
	 * Construct the page.
	 *
	 * @param PrivacySettings $settings Settings store.
	 */
	public function __construct( private PrivacySettings $settings ) {
	}

	/**
	 * Register administration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Register the submenu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Privacy and Tracking', 'argentwolf-post-notifier' ),
			__( 'Privacy', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the settings form.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();
		$values    = $this->settings->get();
		$uninstall = (bool) get_option( DestructiveUninstaller::DELETE_DATA_OPTION, false );

		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Privacy and tracking', 'argentwolf-post-notifier' );
		echo '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['awpn_saved'] ) ) {
			echo '<div class="notice notice-success"><p>';
			echo esc_html__( 'Settings saved.', 'argentwolf-post-notifier' );
			echo '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Click tracking', 'argentwolf-post-notifier' );
		echo '</th><td><label><input type="checkbox" name="click_tracking" value="1"';
		checked( $values['click_tracking'] );
		echo '> ' . esc_html__(
			'Count clicks on the post link (no IP address, user agent or cookie is stored).',
			'argentwolf-post-notifier'
		) . '</label><p class="description">';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'When unchecked, messages link directly to the post and no click is recorded. Opens are never tracked.',
			'argentwolf-post-notifier'
		);
		echo '</p></td></tr>';

		$this->number_row(
			'click_retention_days',
			__( 'Keep click events (days)', 'argentwolf-post-notifier' ),
			$values['click_retention_days'],
			PrivacySettings::MIN_RETENTION_DAYS
		);
		$this->number_row(
			'recipient_retention_days',
			__(
				'Keep per-recipient delivery records (days after a campaign completes)',
				'argentwolf-post-notifier'
			),
			$values['recipient_retention_days'],
			PrivacySettings::MIN_RECIPIENT_RETENTION_DAYS
		);

		echo '<tr><th scope="row">';
		echo esc_html__( 'Per-recipient click tracking', 'argentwolf-post-notifier' );
		echo '</th><td><label><input type="checkbox" name="per_user_tracking" value="1"';
		checked( $values['per_user_tracking'] );
		echo '> ' . esc_html__(
			'Link clicks to individual recipients who give separate, explicit consent.',
			'argentwolf-post-notifier'
		) . '</label><p class="description">';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Off by default. Requires click tracking. Recipients without consent are only counted in totals. Update your privacy policy before enabling.',
			'argentwolf-post-notifier'
		);
		echo '</p></td></tr>';
		$this->number_row(
			'attribution_retention_days',
			__( 'Keep per-recipient click attribution (days)', 'argentwolf-post-notifier' ),
			$values['attribution_retention_days'],
			PrivacySettings::MIN_RETENTION_DAYS
		);
		$this->number_row(
			'scanner_window_seconds',
			__(
				'Flag clicks within this many seconds of delivery as likely scanner',
				'argentwolf-post-notifier'
			),
			$values['scanner_window_seconds'],
			0,
			PrivacySettings::MAX_SCANNER_WINDOW_SECONDS
		);

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'argentwolf-post-notifier' );
		echo '</th><td><label><input type="checkbox" name="delete_data_on_uninstall" value="1"';
		checked( $uninstall );
		echo '> ' . esc_html__(
			'Delete all plugin tables, options and user preferences when the plugin is deleted.',
			'argentwolf-post-notifier'
		) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Save settings.
	 *
	 * @return never
	 */
	public function handle_save(): never {
		$this->require_capability();
		check_admin_referer( self::SAVE_ACTION );
		$this->save( wp_unslash( $_POST ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'awpn_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Persist an already-authorized submission.
	 *
	 * @param array<string,mixed> $input Unslashed request fields.
	 * @return void
	 */
	public function save( array $input ): void {
		$clicks      = $input['click_retention_days'] ?? null;
		$recipients  = $input['recipient_retention_days'] ?? null;
		$attribution = $input['attribution_retention_days'] ?? null;
		$window      = $input['scanner_window_seconds'] ?? null;
		$this->settings->save(
			array(
				'per_user_tracking'          => isset( $input['per_user_tracking'] ) ? '1' : '0',
				'attribution_retention_days' => is_string( $attribution )
					? sanitize_text_field( $attribution )
					: null,
				'scanner_window_seconds'     => is_string( $window )
					? sanitize_text_field( $window )
					: null,
				'click_tracking'             => isset( $input['click_tracking'] ) ? '1' : '0',
				'click_retention_days'       => is_string( $clicks )
					? sanitize_text_field( $clicks )
					: null,
				'recipient_retention_days'   => is_string( $recipients )
					? sanitize_text_field( $recipients )
					: null,
			)
		);
		update_option(
			DestructiveUninstaller::DELETE_DATA_OPTION,
			isset( $input['delete_data_on_uninstall'] ),
			false
		);
	}

	/**
	 * Render one numeric row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @return void
	 */
	private function number_row(
		string $name,
		string $label,
		int $value,
		int $min,
		int $max = PrivacySettings::MAX_RETENTION_DAYS
	): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label );
		echo '</label></th><td><input type="number" class="small-text" id="' . esc_attr( $name );
		echo '" name="' . esc_attr( $name ) . '" min="' . esc_attr( (string) $min ) . '" max="';
		echo esc_attr( (string) $max ) . '" value="';
		echo esc_attr( (string) $value ) . '"></td></tr>';
	}

	/**
	 * Stop unless the current user may manage privacy settings.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_die(
			esc_html__(
				'You do not have permission to manage these settings.',
				'argentwolf-post-notifier'
			),
			esc_html__( 'ArgentWolf Post Notifier', 'argentwolf-post-notifier' ),
			array( 'response' => 403 )
		);
	}
}

// EOF: src/Admin/PrivacySettingsPage.php.
