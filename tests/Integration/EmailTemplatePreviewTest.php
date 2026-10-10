<?php
/**
 * File: tests/Integration/EmailTemplatePreviewTest.php
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
 * Qualify the administrator preview without recipient or campaign side effects.
 */
final class EmailTemplatePreviewTest extends WP_UnitTestCase {
	/**
	 * Preview uses the existing registered composer and settings page.
	 *
	 * @return void
	 */
	public function test_preview_is_registered(): void {
		$container = Plugin::instance()->container();
		self::assertInstanceOf(
			EmailTemplatePreview::class,
			$container->get( EmailTemplatePreview::class )
		);
		self::assertInstanceOf(
			NotificationTemplateSettingsPage::class,
			$container->get( NotificationTemplateSettingsPage::class )
		);
	}

	/**
	 * Preview must preserve selection and mandatory example links but not send.
	 *
	 * @return void
	 */
	public function test_published_post_preview_is_inert_and_uses_saved_settings(): void {
		$this->administrator();
		$settings = new EmailTemplateSettings();
		$fields   = $settings->defaults();
		$fields['heading'] = 'Preview: {{post_title}}';
		$settings->save( $fields );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Example',
				'post_content' => '<!-- wp:paragraph --><p>Visible</p><!-- /wp:paragraph -->'
					. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
					. '<!-- wp:paragraph --><p>HIDDEN</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );
		$sent  = 0;
		$hooks = 0;
		$mail_filter = static function ( $result ) use ( &$sent ) {
			++$sent;
			return $result;
		};
		$campaign_action = static function () use ( &$hooks ): void {
			++$hooks;
		};
		add_filter( 'pre_wp_mail', $mail_filter );
		add_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $campaign_action );
		try {
			$message = $this->service()->preview( $post_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			remove_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $campaign_action );
			$settings->restore_defaults();
		}
		self::assertSame( 0, $sent );
		self::assertSame( 0, $hooks );
		self::assertSame( 'email_cutoff', $message['source'] );
		self::assertStringContainsString( 'Preview: Example', $message['html'] );
		self::assertStringContainsString( 'Visible', $message['html'] );
		self::assertStringNotContainsString( 'HIDDEN', $message['html'] . $message['text'] );
		self::assertStringContainsString( 'awpn_preview_only=unsubscribe', $message['html'] );
		self::assertStringContainsString( 'awpn_preview_only=manage', $message['text'] );
		self::assertStringNotContainsString( 'token=', $message['html'] . $message['text'] );
	}

	/**
	 * Preview respects explicit full-content editor metadata.
	 *
	 * @return void
	 */
	public function test_preview_honors_full_content_mode(): void {
		$this->administrator();
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->'
					. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
					. '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'full' );
		$message = $this->service()->preview( $post_id );
		self::assertSame( 'full', $message['source'] );
		self::assertStringContainsString( 'Second', $message['html'] );
	}

	/**
	 * Unprivileged users and unpublished or other post types cannot preview.
	 *
	 * @return void
	 */
	public function test_preview_denies_ineligible_post_or_user(): void {
		$this->administrator();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$rejected = 0;
		foreach ( array( $draft, $page, 987654 ) as $post_id ) {
			try {
				$this->service()->preview( $post_id );
				self::fail( 'Ineligible post was previewed.' );
			} catch ( InvalidArgumentException ) {
				++$rejected;
			}
		}
		self::assertSame( 3, $rejected );
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->expectException( InvalidArgumentException::class );
		$this->service()->preview( $published );
	}

	/**
	 * The settings page contains a read-only preview form and sandboxed pane.
	 *
	 * @return void
	 */
	public function test_settings_preview_page_sandboxes_html(): void {
		$this->administrator();
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$old_get = $_GET;
		$_GET = array(
			'awpn_preview_post'  => (string) $post_id,
			'awpn_preview_nonce' => wp_create_nonce( NotificationTemplateSettingsPage::PREVIEW_ACTION ),
		);
		try {
			ob_start();
			Plugin::instance()->container()->get( NotificationTemplateSettingsPage::class )->render();
			$html = ob_get_clean();
		} finally {
			$_GET = $old_get;
		}
		self::assertStringContainsString( 'sandbox=""', $html );
		self::assertStringContainsString( 'pointer-events:none', $html );
		self::assertStringContainsString( 'readonly', $html );
		self::assertStringContainsString( 'Nothing was sent or queued.', $html );
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
	 * Become a user with administrative preview access.
	 *
	 * @return void
	 */
	private function administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}
}

// EOF: tests/Integration/EmailTemplatePreviewTest.php.
