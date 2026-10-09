<?php
/**
 * File: src/Admin/NotificationTemplateSettingsPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use InvalidArgumentException;

/**
 * Administrator-only settings for safe text customization of email templates.
 */
final class NotificationTemplateSettingsPage implements Registerable {
	/** Settings screen slug. */
	public const PAGE_SLUG = 'argentwolf-post-notifier-templates';

	/** POST action for save and restore-defaults. */
	public const SAVE_ACTION = 'argentwolf_post_notifier_save_template_text';

	/** Required WordPress permission. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Construct the administration settings screen.
	 *
	 * @param EmailTemplateSettings $settings Validated option store.
	 */
	public function __construct( private EmailTemplateSettings $settings ) {
	}

	/**
	 * Register settings-menu and authenticated POST handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Place template customization under WordPress Settings.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Notification Email Templates', 'argentwolf-post-notifier' ),
			__( 'Post Notifier Templates', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render fields as plain text; no arbitrary HTML template editing.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();
		$settings = $this->settings->get();
		$labels   = array(
			'subject'     => __( 'Email subject', 'argentwolf-post-notifier' ),
			'heading'     => __( 'Message heading', 'argentwolf-post-notifier' ),
			'before'      => __( 'Text before article', 'argentwolf-post-notifier' ),
			'after'       => __( 'Text after article', 'argentwolf-post-notifier' ),
			'cta'         => __( 'Read-post link label', 'argentwolf-post-notifier' ),
			'footer_note' => __( 'Optional footer note', 'argentwolf-post-notifier' ),
		);
		echo '<div class="wrap"><h1>';
		echo esc_html__( 'Notification Email Templates', 'argentwolf-post-notifier' );
		echo '</h1>';
		// Read-only notice status: no state changes depend on the query string.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['awpn_status'] ) && is_string( $_GET['awpn_status'] )
			? sanitize_key( wp_unslash( $_GET['awpn_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( in_array( $status, array( 'saved', 'restored', 'invalid' ), true ) ) {
			$message = __( 'Template settings updated.', 'argentwolf-post-notifier' );
			if ( 'invalid' === $status ) {
				$message = __(
					'Template settings were rejected; no changes saved.',
					'argentwolf-post-notifier'
				);
			}
			$notice_class = 'invalid' === $status ? 'error' : 'success';
			echo '<div class="notice notice-' . esc_attr( $notice_class ) . '"><p>';
			echo esc_html( $message );
			echo '</p></div>';
		}
		echo '<p>';
		echo esc_html__(
			'Only plain text and {{site_name}} / {{post_title}} tokens are supported.',
			'argentwolf-post-notifier'
		);
		echo ' ';
		echo esc_html__(
			'The article body and required subscriber links cannot be removed.',
			'argentwolf-post-notifier'
		);
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $labels as $field => $label ) {
			echo '<tr><th scope="row"><label for="awpn_' . esc_attr( $field ) . '">';
			echo esc_html( $label );
			echo '</label></th><td>';
			$name = 'awpn_template[' . $field . ']';
			if ( in_array( $field, array( 'before', 'after', 'footer_note' ), true ) ) {
				echo '<textarea class="large-text" rows="3" id="awpn_' . esc_attr( $field );
				echo '" name="' . esc_attr( $name ) . '">';
				echo esc_textarea( $settings[ $field ] );
				echo '</textarea>';
			} else {
				echo '<input class="regular-text" type="text" id="awpn_';
				echo esc_attr( $field ) . '" name="' . esc_attr( $name ) . '" value="';
				echo esc_attr( $settings[ $field ] ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save templates', 'argentwolf-post-notifier' ) );
		echo '</form><form method="post" action="'
			. esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<input type="hidden" name="awpn_restore" value="1">';
		submit_button( __( 'Restore defaults', 'argentwolf-post-notifier' ), 'secondary' );
		echo '</form></div>';
	}

	/**
	 * Save or reset settings only with authorization and a valid nonce.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->require_capability();
		check_admin_referer( self::SAVE_ACTION );
		$status = 'saved';
		if ( isset( $_POST['awpn_restore'] ) ) {
			$this->settings->restore_defaults();
			$status = 'restored';
		} else {
			// Raw input is checked, then sanitized by EmailTemplateSettings::validate().
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$fields = isset( $_POST['awpn_template'] ) && is_array( $_POST['awpn_template'] )
				? wp_unslash( $_POST['awpn_template'] ) : array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			try {
				$this->settings->save( $fields );
			} catch ( InvalidArgumentException ) {
				$status = 'invalid';
			}
		}
		wp_safe_redirect(
			add_query_arg(
				'awpn_status',
				$status,
				admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Fail closed for users without administrator configuration access.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__(
					'You cannot manage notification templates.',
					'argentwolf-post-notifier'
				)
			);
		}
	}
}

// EOF: src/Admin/NotificationTemplateSettingsPage.php.
