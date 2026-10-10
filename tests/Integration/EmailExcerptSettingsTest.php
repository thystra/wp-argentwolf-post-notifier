<?php
/**
 * File: tests/Integration/EmailExcerptSettingsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailExcerptSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Qualify persisted excerpt limits without changing campaign or mail behavior.
 */
final class EmailExcerptSettingsTest extends WP_UnitTestCase {
	/**
	 * Restore isolation between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( EmailExcerptSettings::OPTION );
		parent::tear_down();
	}

	/**
	 * Default, persisted and reset values use the same bounded selector contract.
	 *
	 * @return void
	 */
	public function test_default_save_and_restore(): void {
		$settings = new EmailExcerptSettings();
		self::assertSame( EmailContentSelector::DEFAULT_EXCERPT_WORDS, $settings->get() );
		$settings->save( '3' );
		self::assertSame( 3, $settings->get() );
		$settings->restore_defaults();
		self::assertSame( EmailContentSelector::DEFAULT_EXCERPT_WORDS, $settings->get() );
	}

	/**
	 * Invalid input is rejected without changing the previously stored value.
	 *
	 * @return void
	 */
	public function test_strict_validation_and_invalid_option_fallback(): void {
		$settings = new EmailExcerptSettings();
		$settings->save( 21 );
		$invalid = array( '0', '501', '-2', '3.5', '1e2', '', ' 4 ', array( 5 ), null );
		foreach ( $invalid as $value ) {
			try {
				$settings->save( $value );
				self::fail( 'An invalid excerpt word count was saved.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( 21, $settings->get() );
			}
		}
		$settings->save( '500' );
		self::assertSame( EmailContentSelector::MAX_EXCERPT_WORDS, $settings->get() );
		update_option( EmailExcerptSettings::OPTION, 'not an integer' );
		self::assertSame( EmailContentSelector::DEFAULT_EXCERPT_WORDS, $settings->get() );
	}

	/**
	 * Saved previews and test emails use a single configured generated length.
	 *
	 * @return void
	 */
	public function test_saved_preview_uses_excerpt_limit(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Excerpt Preview',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph -->'
					. '<p>one two three four five six</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );
		$settings = Plugin::instance()->container()->get( EmailExcerptSettings::class );
		self::assertInstanceOf( EmailExcerptSettings::class, $settings );
		$settings->save( '3' );
		$preview = Plugin::instance()->container()->get( EmailTemplatePreview::class );
		$message = $preview->preview( $post_id );
		self::assertSame( 'generated_excerpt', $message['source'] );
		self::assertStringContainsString( 'one two three…', $message['text'] );
		self::assertStringNotContainsString( 'four five six', $message['text'] );

		// An explicit marker remains higher priority than the word limit.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>before marker text</p><!-- /wp:paragraph -->'
					. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
					. '<!-- wp:paragraph --><p>after marker text</p><!-- /wp:paragraph -->',
			)
		);
		$message = $preview->preview( $post_id );
		self::assertSame( 'email_cutoff', $message['source'] );
		self::assertStringContainsString( 'before marker text', $message['text'] );
		self::assertStringNotContainsString( 'after marker text', $message['text'] );
	}

	/**
	 * Settings controls are administrator-only and use a separate POST nonce.
	 *
	 * @return void
	 */
	public function test_admin_action_and_form_are_registered(): void {
		$services = Plugin::instance()->container();
		self::assertNotFalse(
			has_action( 'admin_post_' . NotificationTemplateSettingsPage::EXCERPT_ACTION )
		);
		self::assertFalse(
			has_action( 'admin_post_nopriv_' . NotificationTemplateSettingsPage::EXCERPT_ACTION )
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		ob_start();
		$services->get( NotificationTemplateSettingsPage::class )->render();
		$html = ob_get_clean();
		self::assertStringContainsString( 'awpn_excerpt_words', $html );
		self::assertStringContainsString( NotificationTemplateSettingsPage::EXCERPT_ACTION, $html );
		self::assertStringContainsString( 'max="500"', $html );
	}
}

// EOF: tests/Integration/EmailExcerptSettingsTest.php.
