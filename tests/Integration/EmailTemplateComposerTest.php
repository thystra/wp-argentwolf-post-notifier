<?php
/**
 * File: tests/Integration/EmailTemplateComposerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Qualify inert default templates, allow-list and immutable subscriber links.
 */
final class EmailTemplateComposerTest extends WP_UnitTestCase {
	/**
	 * Both formats are composed through the registered canonical renderer.
	 *
	 * @return void
	 */
	public function test_composer_is_registered(): void {
		self::assertInstanceOf(
			EmailTemplateComposer::class,
			Plugin::instance()->container()->get( EmailTemplateComposer::class )
		);
	}

	/**
	 * A selected fragment becomes two formats with mandatory local links.
	 *
	 * @return void
	 */
	public function test_both_formats_preserve_cutoff_and_append_required_links(): void {
		$content = '<!-- wp:paragraph --><p>Before <strong>cutoff</strong></p><!-- /wp:paragraph -->'
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. '<!-- wp:paragraph --><p>SECRET</p><!-- /wp:paragraph -->';
		$links   = $this->links();
		$message = $this->composer()->compose(
			$content,
			'A & <b>B</b>',
			$links['post'],
			$links['unsubscribe'],
			$links['manage']
		);

		self::assertSame( 'email_cutoff', $message['source'] );
		self::assertSame( 'New post: A & B', $message['subject'] );
		self::assertStringContainsString( 'A &amp; B', $message['html'] );
		self::assertStringContainsString( 'Before', $message['html'] );
		self::assertStringContainsString( 'Before', $message['text'] );
		self::assertStringNotContainsString( 'SECRET', $message['html'] . $message['text'] );
		self::assertStringNotContainsString( 'email-cutoff', $message['html'] );
		self::assertStringContainsString( 'max-width:640px', $message['html'] );
		self::assertStringContainsString( esc_url( $links['post'] ), $message['html'] );

		foreach ( array( 'unsubscribe', 'manage' ) as $kind ) {
			self::assertSame( 1, substr_count( $message['html'], esc_url( $links[ $kind ] ) ) );
			self::assertSame( 1, substr_count( $message['text'], $links[ $kind ] ) );
		}
		self::assertStringNotContainsString( '{{', $message['html'] . $message['text'] );
	}

	/**
	 * Escape excerpts and never execute shortcode callbacks in a message.
	 *
	 * @return void
	 */
	public function test_excerpt_is_escaped_and_shortcodes_remain_inert(): void {
		$calls = 0;
		add_shortcode(
			'awpn_composer_test',
			static function () use ( &$calls ): string {
				++$calls;
				return 'EXECUTED';
			}
		);

		try {
			$links   = $this->links();
			$message = $this->composer()->compose(
				'[awpn_composer_test] <img src="https://remote.test/pixel">',
				'Test',
				$links['post'],
				$links['unsubscribe'],
				$links['manage'],
				'<em>Plain & escaped</em>'
			);
			self::assertSame( 'manual_excerpt', $message['source'] );
			self::assertSame( '<p>Plain &amp; escaped</p>', $this->fragment_html( $message['html'] ) );
			self::assertStringContainsString( 'Plain & escaped', $message['text'] );
			self::assertStringNotContainsString( '<em>Plain', $message['html'] );
			self::assertStringNotContainsString( 'pixel', $message['html'] . $message['text'] );
			self::assertSame( 0, $calls );
		} finally {
			remove_shortcode( 'awpn_composer_test' );
		}
	}

	/**
	 * Do not accept missing, external, credentialed, or dangerous footer URLs.
	 *
	 * @return void
	 */
	public function test_required_urls_are_fail_closed(): void {
		$links = $this->links();
		foreach ( array( '', 'https://outside.invalid/unsubscribe', 'javascript:alert(1)',
			'http://user:secret@example.org/path', '//outside.invalid/path',
		) as $invalid ) {
			try {
				$this->composer()->compose(
					'Body',
					'Title',
					$links['post'],
					$invalid,
					$links['manage']
				);
				self::fail( 'Invalid unsubscribe URL was accepted.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'A valid local notification URL is required.', $exception->getMessage() );
			}
		}
	}

	/**
	 * All existing content modes are still selected by the canonical renderer.
	 *
	 * @return void
	 */
	public function test_explicit_full_mode_uses_canonical_selection(): void {
		$links   = $this->links();
		$content = '<!-- wp:paragraph --><p>Full content</p><!-- /wp:paragraph -->';
		$message = $this->composer()->compose(
			$content,
			'Title',
			$links['post'],
			$links['unsubscribe'],
			$links['manage'],
			'',
			'full'
		);
		self::assertSame( 'full', $message['source'] );
		self::assertStringContainsString( 'Full content', $message['html'] );
	}

	/**
	 * Compose the default templates through the real inert renderer.
	 *
	 * @return EmailTemplateComposer
	 */
	private function composer(): EmailTemplateComposer {
		return new EmailTemplateComposer( new EmailContentRenderer( new EmailContentSelector() ) );
	}

	/**
	 * Use same-site links with query parameters to qualify HTML escaping.
	 *
	 * @return array{post:string,unsubscribe:string,manage:string}
	 */
	private function links(): array {
		return array(
			'post'        => home_url( '/article?x=1&y=2' ),
			'unsubscribe' => home_url( '/unsubscribe?key=abc&source=post' ),
			'manage'      => home_url( '/manage?key=xyz&source=post' ),
		);
	}

	/**
	 * Extract the safe fragment from the fixed default message wrapper.
	 *
	 * @param string $html Full message markup.
	 * @return string
	 */
	private function fragment_html( string $html ): string {
		preg_match( '~<div>(.*?)</div>~s', $html, $matches );
		return $matches[1] ?? '';
	}
}

// EOF: tests/Integration/EmailTemplateComposerTest.php.
