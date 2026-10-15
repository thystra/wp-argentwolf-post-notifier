<?php
/**
 * File: tests/Integration/EmailNamedTemplateBindingTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Content\EmailNamedTemplateLibrary;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplateTestMailer;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Bind persisted editor template IDs to canonical preview and test-mail content.
 */
final class EmailNamedTemplateBindingTest extends WP_UnitTestCase {
	/**
	 * Prevent options and user cooldowns leaking across integration cases.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( EmailNamedTemplateLibrary::OPTION );
		delete_option( EmailTemplateSettings::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Reset the site-level fixtures for the next integration test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_transient( 'awpn_test_email_' . get_current_user_id() );
		delete_option( EmailNamedTemplateLibrary::OPTION );
		delete_option( EmailTemplateSettings::OPTION );
		parent::tear_down();
	}

	/**
	 * Named fields win over site defaults and unrelated unsaved site-default edits.
	 *
	 * @return void
	 */
	public function test_selected_named_template_controls_canonical_preview(): void {
		$defaults        = ( new EmailTemplateSettings() )->defaults();
		$site            = $defaults;
		$site['subject'] = 'Default {{post_title}}';
		( new EmailTemplateSettings() )->save( $site );

		$named            = $defaults;
		$named['subject'] = 'Named {{post_title}}';
		$named['heading'] = 'Named heading';
		$id               = $this->library()->create( 'First variant', $named );
		$post_id          = $this->post_id();
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, $id );
		$unsaved            = $site;
		$unsaved['subject'] = 'Unsaved {{post_title}}';

		$preview = $this->preview();
		$saved   = $preview->preview( $post_id );
		$live    = $preview->preview( $post_id, $unsaved );
		self::assertSame( 'Named Binding Test', $saved['subject'] );
		self::assertSame( $saved, $live );
		self::assertStringContainsString( 'Named heading', $saved['html'] );
		self::assertStringContainsString( 'Named heading', $saved['text'] );
		self::assertSame( $site, ( new EmailTemplateSettings() )->get() );

		$named['subject'] = 'Updated {{post_title}}';
		$this->library()->update( $id, 'First variant', $named );
		self::assertSame( 'Updated Binding Test', $preview->preview( $post_id )['subject'] );
	}

	/**
	 * Site-default selection still supports both saved and unsaved template text.
	 *
	 * @return void
	 */
	public function test_site_default_retains_unsaved_override(): void {
		$post_id           = $this->post_id();
		$fields            = ( new EmailTemplateSettings() )->defaults();
		$fields['subject'] = 'Draft {{post_title}}';
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, 0 );
		self::assertSame(
			'Draft Binding Test',
			$this->preview()->preview( $post_id, $fields )['subject']
		);
		self::assertSame(
			'New post: Binding Test',
			$this->preview()->preview( $post_id )['subject']
		);
	}

	/**
	 * Removed or externally selected templates cannot silently use site defaults.
	 *
	 * @return void
	 */
	public function test_deleted_external_and_malformed_ids_fail_closed(): void {
		$post_id = $this->post_id();
		$id      = $this->library()->create( 'Temporary', ( new EmailTemplateSettings() )->defaults() );
		$this->library()->delete( $id );
		foreach ( array( $id, 17, -3 ) as $invalid ) {
			update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, $invalid );
			try {
				$this->preview()->preview( $post_id );
				self::fail( 'An unavailable template was rendered.' );
			} catch ( InvalidArgumentException ) {
				self::assertNotSame( 0, $invalid );
			}
		}
		// A malformed raw value can also be injected by a metadata integration.
		$malformed = static function ( $value, $object_id, $meta_key ) use ( $post_id ) {
			if ( $post_id === $object_id && PostNotificationMeta::TEMPLATE_ID_KEY === $meta_key ) {
				return 'not-a-number';
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $malformed, 10, 3 );
		try {
			$this->expectException( InvalidArgumentException::class );
			$this->preview()->preview( $post_id );
		} finally {
			remove_filter( 'get_post_metadata', $malformed );
		}
	}

	/**
	 * Corrupted libraries fail closed, including when drafts are submitted.
	 *
	 * @return void
	 */
	public function test_corrupt_library_and_invalid_unsaved_text_are_rejected(): void {
		$post_id = $this->post_id();
		$id      = $this->library()->create( 'Persisted', ( new EmailTemplateSettings() )->defaults() );
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, $id );
		$bad            = ( new EmailTemplateSettings() )->defaults();
		$bad['subject'] = "Unsafe\r\nBcc: other@example.com";
		try {
			$this->preview()->preview( $post_id, $bad );
			self::fail( 'An invalid draft was accepted.' );
		} catch ( InvalidArgumentException ) {
			self::assertNotNull( $this->library()->find( $id ) );
		}
		update_option( EmailNamedTemplateLibrary::OPTION, array( 'damaged' => true ), false );
		$this->expectException( InvalidArgumentException::class );
		$this->preview()->preview( $post_id );
	}

	/**
	 * Test emails use named text and reject missing IDs before transport.
	 *
	 * @return void
	 */
	public function test_named_test_mail_uses_canonical_preview_and_does_not_reserve(): void {
		$post_id           = $this->post_id();
		$fields            = ( new EmailTemplateSettings() )->defaults();
		$fields['subject'] = 'Named mail {{post_title}}';
		$id                = $this->library()->create( 'Mail variant', $fields );
		update_post_meta( $post_id, PostNotificationMeta::TEMPLATE_ID_KEY, $id );
		$transport   = $this->transport();
		$sender      = new EmailTemplateTestMailer( $this->preview(), $transport );
		$reserved    = 0;
		$on_campaign = static function () use ( &$reserved ): void {
			++$reserved;
		};
		add_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $on_campaign );
		try {
			self::assertTrue( $sender->send( $post_id )->is_submitted() );
			self::assertCount( 1, $transport->messages );
			self::assertSame( '[TEST] Named mail Binding Test', $transport->messages[0]->subject() );
			self::assertSame( 0, $reserved );
			delete_transient( 'awpn_test_email_' . get_current_user_id() );
			$this->library()->delete( $id );
			try {
				$sender->send( $post_id );
				self::fail( 'A deleted named template was mailed.' );
			} catch ( InvalidArgumentException ) {
				self::assertCount( 1, $transport->messages );
			}
		} finally {
			remove_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $on_campaign );
		}
	}

	/**
	 * Return the plugin's production preview.
	 *
	 * @return EmailTemplatePreview
	 */
	private function preview(): EmailTemplatePreview {
		return Plugin::instance()->container()->get( EmailTemplatePreview::class );
	}

	/**
	 * Build the canonical named-template store.
	 *
	 * @return EmailNamedTemplateLibrary
	 */
	private function library(): EmailNamedTemplateLibrary {
		return Plugin::instance()->container()->get( EmailNamedTemplateLibrary::class );
	}

	/**
	 * Create a published post with deterministic subject substitutions.
	 *
	 * @return int
	 */
	private function post_id(): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Binding Test',
				'post_content' => '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Return a transport that captures only explicitly requested test messages.
	 *
	 * @return MailTransport
	 */
	private function transport(): MailTransport {
		return new class() implements MailTransport {
			/** @var MailMessage[] Captured submissions. */
			public array $messages = array();

			/**
			 * Capture without a real outbound delivery.
			 *
			 * @param MailMessage $message Message.
			 * @return DeliveryResult
			 */
			public function send( MailMessage $message ): DeliveryResult {
				$this->messages[] = $message;
				return DeliveryResult::submitted();
			}
		};
	}
}

// EOF: tests/Integration/EmailNamedTemplateBindingTest.php.
