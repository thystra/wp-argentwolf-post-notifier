<?php
/**
 * File: tests/Integration/EmailLayoutSettingsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Campaign\CampaignContentSnapshotStore;
use ArgentWolf\PostNotifier\Campaign\CampaignInputCapture;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailLayoutSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Safe layout and theme customization (Milestone 8).
 */
final class EmailLayoutSettingsTest extends WP_UnitTestCase {
	public function tear_down(): void {
		delete_option( EmailLayoutSettings::OPTION );
		parent::tear_down();
	}

	/**
	 * Unsafe values that must never be accepted.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public function unsafe_values(): array {
		return array(
			'css injection colour' => array( 'accent_color', 'red;background:url(x)' ),
			'named colour'         => array( 'text_color', 'red' ),
			'short hex'            => array( 'background_color', '#fff' ),
			'quote breakout'       => array( 'content_background', '#ffffff"><script>' ),
			'unknown font'         => array( 'font_family', 'Comic Sans' ),
			'css font'             => array( 'font_family', 'serif;color:red' ),
			'narrow width'         => array( 'content_width', 479 ),
			'wide width'           => array( 'content_width', '801' ),
			'width expression'     => array( 'content_width', '640px' ),
			'unknown cta'          => array( 'cta_style', 'blink' ),
		);
	}

	/**
	 * @dataProvider unsafe_values
	 *
	 * @param string $key   Field.
	 * @param mixed  $value Unsafe value.
	 */
	public function test_unsafe_values_are_rejected( string $key, mixed $value ): void {
		$layout         = ( new EmailLayoutSettings() )->defaults();
		$layout[ $key ] = $value;
		$this->expectException( InvalidArgumentException::class );
		( new EmailLayoutSettings() )->validate( $layout );
	}

	public function test_unknown_or_missing_fields_are_rejected(): void {
		$settings      = new EmailLayoutSettings();
		$layout        = $settings->defaults();
		$layout['css'] = 'body{}';
		$missing       = $settings->defaults();
		unset( $missing['cta_style'] );
		foreach ( array( $layout, $missing, 'string' ) as $candidate ) {
			try {
				$settings->validate( $candidate );
				self::fail( 'Invalid layout was accepted.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_saved_layout_drives_preview_markup_and_corrupt_option_falls_back(): void {
		$settings = new EmailLayoutSettings();
		$layout   = array(
			'background_color'   => '#ABCDEF',
			'content_background' => '#101010',
			'text_color'         => '#fafafa',
			'accent_color'       => '#ff0066',
			'font_family'        => 'serif',
			'content_width'      => '720',
			'cta_style'          => 'button',
		);
		$settings->save( $layout );
		self::assertSame( '#abcdef', $settings->get()['background_color'] );
		self::assertSame( 720, $settings->get()['content_width'] );

		$message = $this->composer()->compose(
			'Body.',
			'Title',
			home_url( '/post/' ),
			home_url( '/unsubscribe/' ),
			home_url( '/manage/' )
		);
		self::assertStringContainsString( 'max-width:720px', $message['html'] );
		self::assertStringContainsString( 'background-color:#abcdef', $message['html'] );
		self::assertStringContainsString( 'Georgia', $message['html'] );
		self::assertStringContainsString( 'background-color:#ff0066', $message['html'] );
		self::assertStringNotContainsString( '<script', $message['html'] );

		update_option( EmailLayoutSettings::OPTION, array( 'accent_color' => 'x' ) );
		self::assertSame( $settings->defaults(), $settings->get() );
		$settings->restore_defaults();
		self::assertFalse( get_option( EmailLayoutSettings::OPTION ) );
	}

	public function test_capture_freezes_layout_and_legacy_snapshot_uses_defaults(): void {
		( new EmailLayoutSettings() )->save(
			array_merge( ( new EmailLayoutSettings() )->defaults(), array( 'content_width' => 500 ) )
		);
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$id      = ( new CampaignRepository() )->create_initial_building(
			get_current_blog_id(),
			$post_id,
			gmdate( 'Y-m-d H:i:s' ),
			ContentMode::Full
		);
		self::assertTrue( ( new CampaignInputCapture() )->capture( $id, get_post( $post_id ), ContentMode::Full ) );
		$input = ( new CampaignContentSnapshotStore() )->read( $id );
		self::assertSame( 500, $input['layout']['content_width'] );

		// Later layout edits cannot change the frozen campaign rendering.
		( new EmailLayoutSettings() )->restore_defaults();
		self::assertStringContainsString(
			'max-width:500px',
			$this->composer()->compose_campaign_body( $input )['html']
		);

		// A snapshot captured before layout support remains valid.
		unset( $input['layout'] );
		self::assertStringContainsString(
			'max-width:640px',
			$this->composer()->compose_campaign_body( $input )['html']
		);
		self::assertSame( ( new EmailTemplateSettings() )->defaults(), $input['template_fields'] );
	}

	public function test_layout_save_requires_capability_and_nonce(): void {
		$page  = \ArgentWolf\PostNotifier\Plugin::instance()->container()->get(
			\ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage::class
		);
		$_POST = array( 'awpn_layout' => array( 'accent_color' => '#000000' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			$page->handle_layout_save();
			self::fail( 'Editor reached layout save.' );
		} catch ( \WPDieException ) {
			self::assertFalse( get_option( EmailLayoutSettings::OPTION ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		try {
			$page->handle_layout_save();
			self::fail( 'Missing nonce reached layout save.' );
		} catch ( \WPDieException ) {
			self::assertFalse( get_option( EmailLayoutSettings::OPTION ) );
		}
		$_POST = array();
	}

	/**
	 * Canonical composer.
	 *
	 * @return EmailTemplateComposer
	 */
	private function composer(): EmailTemplateComposer {
		return new EmailTemplateComposer( new EmailContentRenderer( new EmailContentSelector() ) );
	}
}

// EOF: tests/Integration/EmailLayoutSettingsTest.php.
