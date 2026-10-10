<?php
/**
 * File: tests/Integration/EmailMoreSettingsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Content\EmailMoreSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Qualify persisted More-block selection and its preview/test-mail path.
 */
final class EmailMoreSettingsTest extends WP_UnitTestCase {
	/**
	 * Remove option mutations after every test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( EmailMoreSettings::OPTION );
		parent::tear_down();
	}

	/**
	 * Enabled remains the default, and both explicit states persist and reset.
	 *
	 * @return void
	 */
	public function test_default_save_and_reset(): void {
		$settings = new EmailMoreSettings();
		self::assertTrue( $settings->enabled() );
		$settings->save( '0' );
		self::assertFalse( $settings->enabled() );
		$settings->save( '1' );
		self::assertTrue( $settings->enabled() );
		$settings->save( '0' );
		$settings->restore_defaults();
		self::assertTrue( $settings->enabled() );
	}

	/**
	 * Malformed flags cannot silently change a saved opt-out of More handling.
	 *
	 * @return void
	 */
	public function test_rejects_invalid_flags_and_invalid_storage_falls_back(): void {
		$settings = new EmailMoreSettings();
		$settings->save( '0' );
		$invalid = array( 'false', 'yes', '', ' 1 ', '2', 1, 0, array( '1' ), null );
		foreach ( $invalid as $value ) {
			try {
				$settings->save( $value );
				self::fail( 'An invalid More-block preference was saved.' );
			} catch ( InvalidArgumentException ) {
				self::assertFalse( $settings->enabled() );
			}
		}
		update_option( EmailMoreSettings::OPTION, 'malformed' );
		self::assertTrue( $settings->enabled() );
	}

	/**
	 * The canonical preview selects More only when enabled, without mail.
	 *
	 * @return void
	 */
	public function test_canonical_preview_respects_more_preference(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => 'Manual summary',
				'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->'
					. '<!-- wp:more /-->'
					. '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );
		$settings = Plugin::instance()->container()->get( EmailMoreSettings::class );
		$preview  = Plugin::instance()->container()->get( EmailTemplatePreview::class );
		self::assertInstanceOf( EmailMoreSettings::class, $settings );
		self::assertInstanceOf( EmailTemplatePreview::class, $preview );

		$sent = 0;
		$campaigns = 0;
		$mail_filter = static function ( $result ) use ( &$sent ) {
			++$sent;
			return $result;
		};
		$campaign_action = static function () use ( &$campaigns ): void {
			++$campaigns;
		};
		add_filter( 'pre_wp_mail', $mail_filter );
		add_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $campaign_action );
		try {
			$default = $preview->preview( $post_id );
			$settings->save( '0' );
			$disabled = $preview->preview( $post_id );
			$settings->save( '1' );
			$enabled = $preview->preview( $post_id );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			remove_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $campaign_action );
		}
		self::assertSame( 'more', $default['source'] );
		self::assertStringContainsString( 'Before', $default['text'] );
		self::assertStringNotContainsString( 'After', $default['text'] );
		self::assertSame( 'manual_excerpt', $disabled['source'] );
		self::assertStringContainsString( 'Manual summary', $disabled['text'] );
		self::assertSame( 'more', $enabled['source'] );
		self::assertSame( 0, $sent );
		self::assertSame( 0, $campaigns );
	}

	/**
	 * With More disabled and no manual excerpt, content is generated normally.
	 *
	 * @return void
	 */
	public function test_disabled_more_falls_back_to_generated_excerpt(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>one two three</p><!-- /wp:paragraph -->'
					. '<!-- wp:more /-->'
					. '<!-- wp:paragraph --><p>four five six</p><!-- /wp:paragraph -->',
			)
		);
		$settings = Plugin::instance()->container()->get( EmailMoreSettings::class );
		$preview  = Plugin::instance()->container()->get( EmailTemplatePreview::class );
		$settings->save( '0' );
		$message = $preview->preview( $post_id );
		self::assertSame( 'generated_excerpt', $message['source'] );
		self::assertStringContainsString( 'four five six', $message['text'] );
	}

	/**
	 * Explicit Email Cutoff and full content still override the More preference.
	 *
	 * @return void
	 */
	public function test_full_and_email_cutoff_keep_their_precedence(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->'
					. '<!-- wp:more /-->'
					. '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->'
					. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
					. '<!-- wp:paragraph --><p>Third</p><!-- /wp:paragraph -->',
			)
		);
		$settings = Plugin::instance()->container()->get( EmailMoreSettings::class );
		$preview  = Plugin::instance()->container()->get( EmailTemplatePreview::class );
		$settings->save( '0' );
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );
		$cutoff = $preview->preview( $post_id );
		self::assertSame( 'email_cutoff', $cutoff['source'] );
		self::assertStringNotContainsString( 'Third', $cutoff['text'] );
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'full' );
		$full = $preview->preview( $post_id );
		self::assertSame( 'full', $full['source'] );
		self::assertStringContainsString( 'Third', $full['text'] );
	}

	/**
	 * Administrator controls use their own authenticated POST action.
	 *
	 * @return void
	 */
	public function test_admin_more_form_and_post_action_are_registered(): void {
		self::assertNotFalse(
			has_action( 'admin_post_' . NotificationTemplateSettingsPage::MORE_ACTION )
		);
		self::assertFalse(
			has_action( 'admin_post_nopriv_' . NotificationTemplateSettingsPage::MORE_ACTION )
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		ob_start();
		Plugin::instance()->container()->get( NotificationTemplateSettingsPage::class )->render();
		$html = ob_get_clean();
		self::assertStringContainsString( 'awpn_more_enabled', $html );
		self::assertStringContainsString( NotificationTemplateSettingsPage::MORE_ACTION, $html );
		self::assertStringContainsString( 'awpn_more_reset', $html );
	}
}

// EOF: tests/Integration/EmailMoreSettingsTest.php.
