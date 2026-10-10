<?php
/**
 * File: src/Admin/NotificationTemplateSettingsPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Content\EmailExcerptSettings;
use ArgentWolf\PostNotifier\Content\EmailMoreSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Content\EmailTemplateTestMailer;
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

	/** Independent POST action for generated excerpt length. */
	public const EXCERPT_ACTION = 'argentwolf_post_notifier_save_excerpt_words';

	/** Independent POST action for More-block selection. */
	public const MORE_ACTION = 'argentwolf_post_notifier_save_more_setting';

	/** Read-only administrator preview action. */
	public const PREVIEW_ACTION = 'argentwolf_post_notifier_preview_template';

	/** Read-only AJAX action for unsaved settings. */
	public const LIVE_PREVIEW_ACTION = 'argentwolf_post_notifier_live_template_preview';

	/** Administrator-only AJAX action for an actual test-email submission. */
	public const TEST_SEND_ACTION = 'argentwolf_post_notifier_send_template_test';

	/** Browser script handle. */
	public const LIVE_PREVIEW_SCRIPT = 'argentwolf-post-notifier-live-template-preview';

	/** Required WordPress permission. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Construct the administration settings screen.
	 *
	 * @param EmailTemplateSettings   $settings Validated option store.
	 * @param EmailTemplatePreview    $preview  Read-only message composer.
	 * @param EmailTemplateTestMailer $mailer   Restricted test-email sender.
	 * @param EmailExcerptSettings    $excerpts Generated excerpt word count.
	 * @param EmailMoreSettings       $more     Site-wide More-block preference.
	 */
	public function __construct(
		private EmailTemplateSettings $settings,
		private EmailTemplatePreview $preview,
		private EmailTemplateTestMailer $mailer,
		private EmailExcerptSettings $excerpts,
		private EmailMoreSettings $more
	) {
	}

	/**
	 * Register settings-menu and authenticated POST handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::EXCERPT_ACTION, array( $this, 'handle_excerpt_save' ) );
		add_action( 'admin_post_' . self::MORE_ACTION, array( $this, 'handle_more_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
		add_action( 'wp_ajax_' . self::LIVE_PREVIEW_ACTION, array( $this, 'handle_live_preview' ) );
		add_action( 'wp_ajax_' . self::TEST_SEND_ACTION, array( $this, 'handle_test_send' ) );
	}

	/**
	 * Load the interactive preview only on this settings page.
	 *
	 * @param string $hook WordPress administration page hook.
	 * @return void
	 */
	public function enqueue_preview_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script(
			self::LIVE_PREVIEW_SCRIPT,
			ARGENTWOLF_POST_NOTIFIER_URL . 'assets/runtime/template-live-preview.js',
			array(),
			ARGENTWOLF_POST_NOTIFIER_VERSION,
			true
		);
		wp_localize_script(
			self::LIVE_PREVIEW_SCRIPT,
			'awpnTemplatePreview',
			array(
				'url'        => admin_url( 'admin-ajax.php' ),
				'action'     => self::LIVE_PREVIEW_ACTION,
				'nonce'      => wp_create_nonce( self::LIVE_PREVIEW_ACTION ),
				'testAction' => self::TEST_SEND_ACTION,
				'testNonce'  => wp_create_nonce( self::TEST_SEND_ACTION ),
				'strings'    => array(
					'select'   => __(
						'Select a published post first.',
						'argentwolf-post-notifier'
					),
					'loading'  => __( 'Rendering unsaved preview…', 'argentwolf-post-notifier' ),
					'failed'   => __(
						'Preview could not be rendered.',
						'argentwolf-post-notifier'
					),
					'subject'  => __( 'Subject:', 'argentwolf-post-notifier' ),
					'source'   => __( 'Content source:', 'argentwolf-post-notifier' ),
					'html'     => __( 'HTML preview (unsaved)', 'argentwolf-post-notifier' ),
					'frame'    => __(
						'Unsaved notification HTML preview',
						'argentwolf-post-notifier'
					),
					'text'     => __( 'Plain-text preview (unsaved)', 'argentwolf-post-notifier' ),
					'complete' => __(
						'Unsaved preview only. Nothing saved or sent.',
						'argentwolf-post-notifier'
					),
				),
			)
		);
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
		if ( in_array(
			$status,
			array(
				'saved',
				'restored',
				'invalid',
				'excerpt_saved',
				'excerpt_restored',
				'excerpt_invalid',
				'more_saved',
				'more_restored',
				'more_invalid',
			),
			true
		) ) {
			$message = __( 'Template settings updated.', 'argentwolf-post-notifier' );
			if ( 'invalid' === $status ) {
				$message = __(
					'Template settings were rejected; no changes saved.',
					'argentwolf-post-notifier'
				);
			}
			if ( 'excerpt_saved' === $status || 'excerpt_restored' === $status ) {
				$message = __( 'Generated excerpt length updated.', 'argentwolf-post-notifier' );
			} elseif ( 'excerpt_invalid' === $status ) {
				$message = __(
					'Invalid excerpt length; no changes saved.',
					'argentwolf-post-notifier'
				);
			} elseif ( 'more_saved' === $status || 'more_restored' === $status ) {
				$message = __( 'More-block preference updated.', 'argentwolf-post-notifier' );
			} elseif ( 'more_invalid' === $status ) {
				$message = __(
					'Invalid More-block preference; no changes saved.',
					'argentwolf-post-notifier'
				);
			}
			$notice_class = in_array(
				$status,
				array( 'invalid', 'excerpt_invalid', 'more_invalid' ),
				true
			)
				? 'error' : 'success';
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
		echo '</p><form id="awpn_template_settings_form" method="post" action="';
		echo esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
		echo '</form>';
		$this->render_excerpt_settings();
		$this->render_more_settings();
		$this->render_preview();
		echo '</div>';
	}

	/**
	 * Render a separate form for the generated-excerpt length.
	 *
	 * @return void
	 */
	private function render_excerpt_settings(): void {
		echo '<hr><h2>';
		echo esc_html__( 'Generated excerpt length', 'argentwolf-post-notifier' );
		echo '</h2><p>';
		echo esc_html__(
			'Applies only when neither a cutoff, More marker, nor manual excerpt is used.',
			'argentwolf-post-notifier'
		);
		echo '</p><form method="post" action="';
		echo esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXCERPT_ACTION ) . '">';
		wp_nonce_field( self::EXCERPT_ACTION );
		echo '<label for="awpn_excerpt_words">';
		echo esc_html__( 'Maximum words', 'argentwolf-post-notifier' );
		echo '</label> <input type="number" id="awpn_excerpt_words" name="awpn_excerpt_words"';
		echo ' min="1" max="500" step="1" required value="';
		echo esc_attr( (string) $this->excerpts->get() ) . '">';
		submit_button( __( 'Save excerpt length', 'argentwolf-post-notifier' ) );
		echo '<button class="button" type="submit" name="awpn_excerpt_reset"';
		echo ' value="1" formnovalidate>';
		echo esc_html__( 'Restore 55-word default', 'argentwolf-post-notifier' );
		echo '</button></form>';
	}

	/**
	 * Render the separate, persisted WordPress More-block preference.
	 *
	 * @return void
	 */
	private function render_more_settings(): void {
		echo '<hr><h2>';
		echo esc_html__( 'WordPress More block', 'argentwolf-post-notifier' );
		echo '</h2><p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength.TooLong -- Preserve gettext source text.
			'When enabled, a More block can limit notification content unless an Email Cutoff takes precedence.',
			'argentwolf-post-notifier'
		);
		echo '</p><form method="post" action="';
		echo esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::MORE_ACTION ) . '">';
		wp_nonce_field( self::MORE_ACTION );
		echo '<label for="awpn_more_enabled">';
		echo esc_html__( 'Honor More blocks', 'argentwolf-post-notifier' );
		echo '</label> <select id="awpn_more_enabled" name="awpn_more_enabled">';
		echo '<option value="1" ' . selected( $this->more->enabled(), true, false ) . '>';
		echo esc_html__( 'Enabled (default)', 'argentwolf-post-notifier' );
		echo '</option><option value="0" ';
		echo selected( $this->more->enabled(), false, false ) . '>';
		echo esc_html__( 'Disabled', 'argentwolf-post-notifier' );
		echo '</option></select>';
		submit_button( __( 'Save More preference', 'argentwolf-post-notifier' ) );
		echo '<button class="button" type="submit" name="awpn_more_reset"';
		echo ' value="1" formnovalidate>';
		echo esc_html__( 'Restore enabled default', 'argentwolf-post-notifier' );
		echo '</button></form>';
	}

	/**
	 * Preview the saved template and one published post without sending mail.
	 *
	 * @return void
	 */
	private function render_preview(): void {
		// Read-only input; a verified nonce is required before any preview runs.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['awpn_preview_post'] ) ? absint( $_GET['awpn_preview_post'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 0 !== $post_id ) {
			check_admin_referer( self::PREVIEW_ACTION, 'awpn_preview_nonce' );
		}

		$posts = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'posts_per_page'         => 20,
				'orderby'                => 'date',
				'order'                  => 'DESC',
			)
		);

		echo '<hr><h2>';
		echo esc_html__( 'Preview notification email', 'argentwolf-post-notifier' );
		echo '</h2><p>';
		echo esc_html__(
			'Saved settings are used. Links are examples only; no subscriber tokens are created.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		wp_nonce_field( self::PREVIEW_ACTION, 'awpn_preview_nonce' );
		echo '<label for="awpn_preview_post">';
		echo esc_html__( 'Published post', 'argentwolf-post-notifier' );
		echo '</label> <select id="awpn_preview_post" name="awpn_preview_post" required>';
		echo '<option value="">';
		echo esc_html__( 'Select a post', 'argentwolf-post-notifier' );
		echo '</option>';
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $post->ID ) . '" ';
			echo selected( $post_id, $post->ID, false ) . '>';
			echo esc_html( get_the_title( $post ) );
			echo '</option>';
		}
		echo '</select> ';
		submit_button(
			__( 'Preview email', 'argentwolf-post-notifier' ),
			'secondary',
			'submit',
			false
		);
		echo '<button type="button" class="button button-secondary" id="awpn_preview_unsaved">';
		echo esc_html__( 'Preview unsaved edits', 'argentwolf-post-notifier' );
		echo '</button> ';
		echo '<button type="button" class="button button-secondary" id="awpn_send_test_email">';
		echo esc_html__( 'Send test email to my account', 'argentwolf-post-notifier' );
		echo '</button>';
		echo '<p id="awpn_preview_feedback" role="status" aria-live="polite"></p>';
		echo '<div id="awpn_live_preview" hidden></div>';
		echo '</form>';

		if ( 0 === $post_id ) {
			return;
		}

		try {
			$message = $this->preview->preview( $post_id );
		} catch ( InvalidArgumentException ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'This post is unavailable for preview.', 'argentwolf-post-notifier' );
			echo '</p></div>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Subject:', 'argentwolf-post-notifier' ) . '</strong> ';
		echo esc_html( $message['subject'] ) . '</p>';
		echo '<p><strong>';
		echo esc_html__( 'Content source:', 'argentwolf-post-notifier' );
		echo '</strong> ';
		echo esc_html( $message['source'] ) . '</p>';
		echo '<p>';
		echo esc_html__(
			// phpcs:ignore Generic.Files.LineLength.TooLong -- Preserve the gettext source string.
			'Preview only: subscriber links are nonfunctional examples. Nothing was sent or queued.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		echo '<h3>' . esc_html__( 'HTML preview', 'argentwolf-post-notifier' ) . '</h3>';
		echo '<iframe title="';
		echo esc_attr__( 'Notification HTML preview', 'argentwolf-post-notifier' );
		echo '" sandbox="" referrerpolicy="no-referrer" style="width:100%;height:420px;';
		echo 'border:1px solid #8c8f94;pointer-events:none" srcdoc="';
		echo esc_attr( $message['html'] ) . '"></iframe>';
		echo '<h3>' . esc_html__( 'Plain-text preview', 'argentwolf-post-notifier' ) . '</h3>';
		echo '<textarea class="large-text code" rows="14" readonly>';
		echo esc_textarea( $message['text'] ) . '</textarea>';
	}

	/**
	 * Return a preview of unsaved text without persisting or sending anything.
	 *
	 * @return void
	 */
	public function handle_live_preview(): void {
		check_ajax_referer( self::LIVE_PREVIEW_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Preview is not permitted.', 'argentwolf-post-notifier' ) ),
				403
			);
			return;
		}

		$post_id = isset( $_POST['post_id'] ) && is_string( $_POST['post_id'] )
			? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		// Preserve raw text so validation rejects unsafe headers and placeholders.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fields = isset( $_POST['awpn_template'] ) && is_array( $_POST['awpn_template'] )
			? wp_unslash( $_POST['awpn_template'] ) : null;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 0 === $post_id || ! is_array( $fields ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Select a post and complete all fields.',
						'argentwolf-post-notifier'
					),
				),
				400
			);
			return;
		}
		try {
			$message = $this->preview->preview( $post_id, $fields );
		} catch ( InvalidArgumentException ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Invalid preview settings or post.',
						'argentwolf-post-notifier'
					),
				),
				400
			);
			return;
		}
		wp_send_json_success( $message );
	}

	/**
	 * Submit exactly one explicitly labeled test to the administrator's address.
	 *
	 * @return void
	 */
	public function handle_test_send(): void {
		check_ajax_referer( self::TEST_SEND_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Test email is not permitted.', 'argentwolf-post-notifier' ),
				),
				403
			);
			return;
		}

		$post_id = isset( $_POST['post_id'] ) && is_string( $_POST['post_id'] )
			? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		// Raw values are validated before any sanitization can conceal bad input.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fields = isset( $_POST['awpn_template'] ) && is_array( $_POST['awpn_template'] )
			? wp_unslash( $_POST['awpn_template'] ) : null;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 0 === $post_id || ! is_array( $fields ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'A post and valid template fields are required.',
						'argentwolf-post-notifier'
					),
				),
				400
			);
			return;
		}
		try {
			$result = $this->mailer->send( $post_id, $fields );
		} catch ( InvalidArgumentException ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Test-email request was rejected.',
						'argentwolf-post-notifier'
					),
				),
				400
			);
			return;
		}
		if ( ! $result->is_submitted() ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Mail transport did not accept the test.',
						'argentwolf-post-notifier'
					),
				),
				502
			);
			return;
		}
		wp_send_json_success( array( 'submitted' => true ) );
	}

	/**
	 * Save/reset only the separately validated generated-excerpt length.
	 *
	 * @return void
	 */
	public function handle_excerpt_save(): void {
		$this->require_capability();
		check_admin_referer( self::EXCERPT_ACTION );
		$status = 'excerpt_saved';
		if ( isset( $_POST['awpn_excerpt_reset'] ) ) {
			$this->excerpts->restore_defaults();
			$status = 'excerpt_restored';
		} else {
			// Raw value is strictly validated before conversion to an integer.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = null;
			if (
				isset( $_POST['awpn_excerpt_words'] )
				&& is_string( $_POST['awpn_excerpt_words'] )
			) {
				$value = wp_unslash( $_POST['awpn_excerpt_words'] );
			}
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			try {
				$this->excerpts->save( $value );
			} catch ( InvalidArgumentException ) {
				$status = 'excerpt_invalid';
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
	 * Save or reset only the separately validated More-block preference.
	 *
	 * @return void
	 */
	public function handle_more_save(): void {
		$this->require_capability();
		check_admin_referer( self::MORE_ACTION );
		$status = 'more_saved';
		if ( isset( $_POST['awpn_more_reset'] ) ) {
			$this->more->restore_defaults();
			$status = 'more_restored';
		} else {
			// Preserve the raw value so invalid flags cannot silently turn into false.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = null;
			if (
				isset( $_POST['awpn_more_enabled'] )
				&& is_string( $_POST['awpn_more_enabled'] )
			) {
				$value = wp_unslash( $_POST['awpn_more_enabled'] );
			}
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			try {
				$this->more->save( $value );
			} catch ( InvalidArgumentException ) {
				$status = 'more_invalid';
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
