<?php
/**
 * File: tests/Integration/EmailTemplateTestMailerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplateTestMailer;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Test submissions are authorized and cannot become campaign deliveries.
 */
final class EmailTemplateTestMailerTest extends WP_UnitTestCase {
	/**
	 * The registered sender uses the canonical preview and transport service.
	 *
	 * @return void
	 */
	public function test_service_is_registered(): void {
		$services = Plugin::instance()->container();
		self::assertInstanceOf(
			EmailTemplateTestMailer::class,
			$services->get( EmailTemplateTestMailer::class )
		);
		self::assertNotFalse(
			has_action( 'wp_ajax_' . NotificationTemplateSettingsPage::TEST_SEND_ACTION )
		);
		self::assertFalse(
			has_action( 'wp_ajax_nopriv_' . NotificationTemplateSettingsPage::TEST_SEND_ACTION )
		);
	}

	/**
	 * One test goes only to the signed-in admin and retains both formats.
	 *
	 * @return void
	 */
	public function test_submission_is_labeled_and_isolated(): void {
		$user_id = $this->administrator();
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Sample Post',
				'post_content' => '<!-- wp:paragraph --><p>First paragraph</p><!-- /wp:paragraph -->'
					. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
					. '<!-- wp:paragraph --><p>DO NOT INCLUDE</p><!-- /wp:paragraph -->',
			)
		);
		$settings = new EmailTemplateSettings();
		$before   = $settings->get();
		$override = $before;
		$override['subject'] = 'Unsaved {{post_title}}';
		$transport = $this->transport( true );
		$reserved = 0;
		$reservation = static function () use ( &$reserved ): void {
			++$reserved;
		};
		add_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $reservation );
		try {
			$result = $this->sender( $transport )->send( $post_id, $override );
		} finally {
			remove_action( 'argentwolf_post_notifier_campaign_reservation_resolved', $reservation );
			delete_transient( 'awpn_test_email_' . $user_id );
		}
		self::assertTrue( $result->is_submitted() );
		self::assertCount( 1, $transport->messages );
		$mail = $transport->messages[0];
		self::assertSame( get_userdata( $user_id )->user_email, $mail->recipient() );
		self::assertSame( '[TEST] Unsaved Sample Post', $mail->subject() );
		self::assertContains( 'Content-Type: text/html; charset=UTF-8', $mail->headers() );
		self::assertStringContainsString( 'subscriber links are examples only', $mail->body() );
		self::assertStringContainsString( 'First paragraph', $mail->body() );
		self::assertStringContainsString( 'First paragraph', $transport->alternate );
		self::assertStringContainsString( 'awpn_preview_only=manage', $transport->alternate );
		self::assertStringNotContainsString( 'DO NOT INCLUDE', $mail->body() );
		self::assertStringNotContainsString( 'DO NOT INCLUDE', $transport->alternate );
		self::assertSame( $before, $settings->get() );
		self::assertSame( 0, $reserved );

		$unrelated = (object) array( 'AltBody' => 'unrelated' );
		do_action( 'phpmailer_init', $unrelated );
		self::assertSame( 'unrelated', $unrelated->AltBody );
	}

	/**
	 * Repeated submissions are limited and failed transport attempts can retry.
	 *
	 * @return void
	 */
	public function test_cooldown_and_failure(): void {
		$user_id = $this->administrator();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$failed = $this->transport( false );
		$sent = $this->transport( true );
		try {
			self::assertFalse( $this->sender( $failed )->send( $post_id )->is_submitted() );
			self::assertTrue( $this->sender( $sent )->send( $post_id )->is_submitted() );
			try {
				$this->sender( $sent )->send( $post_id );
				self::fail( 'Cooldown allowed duplicate test send.' );
			} catch ( InvalidArgumentException ) {
				self::assertCount( 1, $sent->messages );
			}
		} finally {
			delete_transient( 'awpn_test_email_' . $user_id );
		}
	}

	/**
	 * Deny unknown or draft posts and any non-admin, without calling transport.
	 *
	 * @return void
	 */
	public function test_permissions_and_invalid_inputs_reject_without_send(): void {
		$this->administrator();
		$transport = $this->transport( true );
		$sender = $this->sender( $transport );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$bad_settings = ( new EmailTemplateSettings() )->defaults();
		$bad_settings['subject'] = "Bad\r\nBcc: sample@example.com";
		$invalid = 0;
		foreach ( array( array( $draft, null ), array( $published, $bad_settings ) ) as $fixture ) {
			try {
				$sender->send( $fixture[0], $fixture[1] );
				self::fail( 'Invalid test-email request succeeded.' );
			} catch ( InvalidArgumentException ) {
				++$invalid;
			}
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		try {
			$sender->send( $published );
			self::fail( 'Subscriber could trigger test email.' );
		} catch ( InvalidArgumentException ) {
			++$invalid;
		}
		self::assertSame( 3, $invalid );
		self::assertCount( 0, $transport->messages );
	}

	/**
	 * The settings screen offers a test button but no public mail action.
	 *
	 * @return void
	 */
	public function test_settings_page_exposes_test_action(): void {
		$this->administrator();
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$page = Plugin::instance()->container()->get( NotificationTemplateSettingsPage::class );
		ob_start();
		$page->render();
		$html = ob_get_clean();
		self::assertStringContainsString( 'id="awpn_send_test_email"', $html );
		self::assertStringContainsString( 'Send test email to my account', $html );
	}

	/**
	 * Build an in-process stub that emulates the mailer alternative-body hook.
	 *
	 * @param bool $submitted Whether submission succeeds.
	 * @return MailTransport
	 */
	private function transport( bool $submitted ): MailTransport {
		return new class( $submitted ) implements MailTransport {
			/** @var MailMessage[] Captured messages. */
			public array $messages = array();
			/** @var string Last captured plain-text alternative. */
			public string $alternate = '';

			/** Set up a captured transport. */
			public function __construct( private bool $submitted ) {
			}

			/** Submit and capture a message. */
			public function send( MailMessage $message ): DeliveryResult {
				$this->messages[] = $message;
				$mailer = (object) array( 'AltBody' => '' );
				do_action( 'phpmailer_init', $mailer );
				$this->alternate = $mailer->AltBody;
				return $this->submitted ? DeliveryResult::submitted() : DeliveryResult::failed();
			}
		};
	}

	/**
	 * Construct with the real canonical preview and a controlled transport.
	 *
	 * @param MailTransport $transport Capturing transport.
	 * @return EmailTemplateTestMailer
	 */
	private function sender( MailTransport $transport ): EmailTemplateTestMailer {
		$preview = Plugin::instance()->container()->get( EmailTemplatePreview::class );
		return new EmailTemplateTestMailer( $preview, $transport );
	}

	/**
	 * Sign in as a user who can administer and edit posts.
	 *
	 * @return int
	 */
	private function administrator(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}
}

// EOF: tests/Integration/EmailTemplateTestMailerTest.php.
