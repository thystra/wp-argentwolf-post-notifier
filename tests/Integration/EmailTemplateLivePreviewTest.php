<?php
/**
 * File: tests/Integration/EmailTemplateLivePreviewTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Confirm unsaved template previews are validated, private, and non-persistent.
 */
final class EmailTemplateLivePreviewTest extends WP_UnitTestCase {
	/**
	 * Clear site customization left by the test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( EmailTemplateSettings::OPTION );
		parent::tear_down();
	}

	/**
	 * Unsaved edits must appear in both formats but never touch stored options.
	 *
	 * @return void
	 */
	public function test_preview_unsaved_fields_without_side_effects(): void {
		$this->administrator();
		$settings = new EmailTemplateSettings();
		$saved = $settings->defaults();
		$saved['subject'] = 'Stored subject';
		$settings->save( $saved );
		$unsaved = $saved;
		$unsaved['subject'] = 'Unsaved {{post_title}}';
		$unsaved['heading'] = 'Edited {{post_title}}';
		$unsaved['before'] = 'Fresh editor copy';
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title' => 'Example',
				'post_content' => '<!-- wp:paragraph --><p>Visible</p><!-- /wp:paragraph -->'
					. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
					. '<!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );

		$sent = 0;
		$reserved = 0;
		$mail_filter = static function ( $result ) use ( &$sent ) {
			++$sent;
			return $result;
		};
		$campaign_action = static function () use ( &$reserved ): void {
			++$reserved;
		};
		add_filter( 'pre_wp_mail', $mail_filter );
		add_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $campaign_action );
		try {
			$preview = $this->service()->preview( $post_id, $unsaved );
			$normal = $this->service()->preview( $post_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			remove_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $campaign_action );
		}
		self::assertSame( 'Unsaved Example', $preview['subject'] );
		self::assertSame( 'Stored subject', $normal['subject'] );
		self::assertStringContainsString( 'Edited Example', $preview['html'] );
		self::assertStringContainsString( 'Fresh editor copy', $preview['text'] );
		self::assertSame( 'email_cutoff', $preview['source'] );
		self::assertStringNotContainsString( 'Hidden', $preview['html'] . $preview['text'] );
		self::assertStringContainsString( 'awpn_preview_only=manage', $preview['text'] );
		self::assertStringContainsString( 'awpn_preview_only=unsubscribe', $preview['html'] );
		self::assertSame( $saved, $settings->get() );
		self::assertSame( 0, $sent );
		self::assertSame( 0, $reserved );
	}

	/**
	 * Unsafe or incomplete form data must not change saved text.
	 *
	 * @return void
	 */
	public function test_invalid_unsaved_fields_are_rejected(): void {
		$this->administrator();
		$settings = new EmailTemplateSettings();
		$saved = $settings->defaults();
		$settings->save( $saved );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$invalid = array(
			array( 'subject' => "Unsafe\r\nBcc: example@example.com" ),
			array( 'before' => '{{php}}' ),
			array( 'cta' => '' ),
			array( 'foreign' => 'not allowed' ),
		);
		foreach ( $invalid as $change ) {
			try {
				$this->service()->preview( $post_id, array_merge( $saved, $change ) );
				self::fail( 'Invalid unsaved text was rendered.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( $saved, $settings->get() );
			}
		}
	}

	/**
	 * An unsaved override cannot bypass normal post or user authorization.
	 *
	 * @return void
	 */
	public function test_override_cannot_bypass_preview_permissions(): void {
		$this->administrator();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		try {
			$this->service()->preview( $post_id, ( new EmailTemplateSettings() )->defaults() );
			self::fail( 'Draft post was previewed.' );
		} catch ( InvalidArgumentException ) {
				// Expected: drafts are ineligible regardless of supplied template fields.
		}
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( InvalidArgumentException::class );
		$this->service()->preview( $published, ( new EmailTemplateSettings() )->defaults() );
	}

	/**
	 * The AJAX handler is private and its browser script is page-scoped.
	 *
	 * @return void
	 */
	public function test_ajax_hook_assets_and_form_are_scoped(): void {
		$this->administrator();
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$page = Plugin::instance()->container()->get( NotificationTemplateSettingsPage::class );
		self::assertNotFalse( has_action( 'wp_ajax_' . NotificationTemplateSettingsPage::LIVE_PREVIEW_ACTION ) );
		self::assertFalse( has_action( 'wp_ajax_nopriv_' . NotificationTemplateSettingsPage::LIVE_PREVIEW_ACTION ) );
		$page->enqueue_preview_assets( 'dashboard' );
		self::assertFalse( wp_script_is( NotificationTemplateSettingsPage::LIVE_PREVIEW_SCRIPT, 'enqueued' ) );
		$page->enqueue_preview_assets( 'settings_page_' . NotificationTemplateSettingsPage::PAGE_SLUG );
		self::assertTrue( wp_script_is( NotificationTemplateSettingsPage::LIVE_PREVIEW_SCRIPT, 'enqueued' ) );
		ob_start();
		$page->render();
		$html = ob_get_clean();
		self::assertStringContainsString( 'id="awpn_template_settings_form"', $html );
		self::assertStringContainsString( 'id="awpn_preview_unsaved"', $html );
		self::assertStringContainsString( 'id="awpn_live_preview" hidden', $html );
	}

	/**
	 * Obtain the registered preview service.
	 *
	 * @return EmailTemplatePreview
	 */
	private function service(): EmailTemplatePreview {
		return Plugin::instance()->container()->get( EmailTemplatePreview::class );
	}

	/**
	 * Sign in as an administrator before each preview request.
	 *
	 * @return void
	 */
	private function administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}
}

// EOF: tests/Integration/EmailTemplateLivePreviewTest.php.
