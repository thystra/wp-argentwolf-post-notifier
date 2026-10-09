<?php
/**
 * File: tests/Integration/EmailTemplateSettingsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Test validated template settings and canonical HTML/plain-text composition.
 */
final class EmailTemplateSettingsTest extends WP_UnitTestCase {
	/**
	 * Clear custom settings after each isolated test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( EmailTemplateSettings::OPTION );
		parent::tear_down();
	}

	/**
	 * The settings and admin service are registered in the plugin container.
	 *
	 * @return void
	 */
	public function test_template_services_registered(): void {
		$services = Plugin::instance()->container();
		self::assertInstanceOf( EmailTemplateSettings::class, $services->get( EmailTemplateSettings::class ) );
		self::assertInstanceOf(
			NotificationTemplateSettingsPage::class,
			$services->get( NotificationTemplateSettingsPage::class )
		);
		self::assertNotFalse( has_action( 'admin_post_' . NotificationTemplateSettingsPage::SAVE_ACTION ) );
	}

	/**
	 * Custom text stays escaped and subscriber links remain mandatory.
	 *
	 * @return void
	 */
	public function test_custom_text_renders_in_both_formats_with_immutable_links(): void {
		$settings = new EmailTemplateSettings();
		$fields   = $settings->defaults();
		$fields['subject']     = '{{site_name}}: {{post_title}}';
		$fields['heading']     = 'Update: {{post_title}}';
		$fields['before']      = 'From {{site_name}}: <b>hello</b> & welcome';
		$fields['after']       = 'Thank you for reading';
		$fields['cta']         = 'Open article';
		$fields['footer_note'] = 'Questions? Contact the editor.';
		$settings->save( $fields );

		$composer = new EmailTemplateComposer(
			new EmailContentRenderer( new EmailContentSelector() ),
			$settings
		);
		$unsubscribe = home_url( '/unsubscribe?token=existing' );
		$manage      = home_url( '/manage?token=existing' );
		$result      = $composer->compose(
			'<!-- wp:paragraph --><p>Article content</p><!-- /wp:paragraph -->',
			'A & B',
			home_url( '/article' ),
			$unsubscribe,
			$manage
		);
		self::assertStringContainsString( 'Update: A &amp; B', $result['html'] );
		self::assertStringContainsString( 'Open article', $result['html'] );
		self::assertStringContainsString( 'hello &amp; welcome', $result['html'] );
		self::assertStringNotContainsString( '<b>hello</b>', $result['html'] );
		self::assertStringContainsString( 'Questions? Contact the editor.', $result['text'] );
		self::assertStringContainsString( 'Thank you for reading', $result['text'] );
		self::assertSame( get_bloginfo( 'name' ) . ': A & B', $result['subject'] );
		foreach ( array( $unsubscribe, $manage ) as $url ) {
			self::assertSame( 1, substr_count( $result['html'], esc_url( $url ) ) );
			self::assertSame( 1, substr_count( $result['text'], $url ) );
		}
	}

	/**
	 * Unknown tokens, headers, oversized fields, and arbitrary option keys fail.
	 *
	 * @return void
	 */
	public function test_invalid_configuration_cannot_replace_valid_settings(): void {
		$settings = new EmailTemplateSettings();
		$original = $settings->defaults();
		$settings->save( $original );
		foreach (
			array(
				array( 'subject' => "Hi\r\nBcc: abuse@example.test" ),
				array( 'heading' => '{{php}}' ),
				array( 'before' => '{{content}}' ),
				array( 'cta' => str_repeat( 'x', 110 ) ),
				array( 'extra' => 'unsupported' ),
			) as $changes
		) {
			try {
				$settings->save( array_merge( $original, $changes ) );
				self::fail( 'Unsafe template setting was accepted.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( $original, $settings->get() );
			}
		}
	}

	/**
	 * Bad stored options fall back safely; restoring defaults removes overrides.
	 *
	 * @return void
	 */
	public function test_corrupt_options_fall_back_and_defaults_restore(): void {
		$settings = new EmailTemplateSettings();
		update_option( EmailTemplateSettings::OPTION, array( 'subject' => '{{unknown}}' ) );
		self::assertSame( $settings->defaults(), $settings->get() );
		$changed = $settings->defaults();
		$changed['cta'] = 'Visit site';
		$settings->save( $changed );
		self::assertSame( 'Visit site', $settings->get()['cta'] );
		$settings->restore_defaults();
		self::assertFalse( get_option( EmailTemplateSettings::OPTION, false ) );
		self::assertSame( $settings->defaults(), $settings->get() );
	}

	/**
	 * The settings UI is restricted to WordPress administrators.
	 *
	 * @return void
	 */
	public function test_template_admin_capability_is_not_a_subscriber_permission(): void {
		$subscriber_manager = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_manager );
		self::assertFalse( current_user_can( NotificationTemplateSettingsPage::CAPABILITY ) );
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		self::assertTrue( current_user_can( NotificationTemplateSettingsPage::CAPABILITY ) );
	}
}

// EOF: tests/Integration/EmailTemplateSettingsTest.php.
