<?php
/**
 * File: tests/Integration/EmailContentRendererTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Plugin;
use WP_UnitTestCase;

/**
 * Verify selection and inert email-fragment rendering on real WordPress.
 */
final class EmailContentRendererTest extends WP_UnitTestCase {
	/**
	 * The renderer uses the same selector as future preview and composition.
	 *
	 * @return void
	 */
	public function test_renderer_is_available_from_container(): void {
		self::assertInstanceOf(
			EmailContentRenderer::class,
			Plugin::instance()->container()->get( EmailContentRenderer::class )
		);
	}

	/**
	 * Both formats must honor the Email Cutoff precedence.
	 *
	 * @return void
	 */
	public function test_cutoff_returns_matching_html_and_text_without_markers(): void {
		$post = $this->paragraph( '<strong>Alpha</strong>' )
			. '<!-- wp:more /-->'
			. $this->paragraph( 'Beta' )
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. $this->paragraph( 'SECRET' );
		$result = $this->renderer()->render( $post, 'Manual excerpt' );

		self::assertSame( 'email_cutoff', $result['source'] );
		self::assertStringContainsString( '<strong>Alpha</strong>', $result['html'] );
		self::assertStringContainsString( 'Beta', $result['html'] );
		self::assertStringContainsString( 'Alpha', $result['text'] );
		self::assertStringContainsString( 'Beta', $result['text'] );
		self::assertStringNotContainsString( 'SECRET', $result['html'] . $result['text'] );
		self::assertStringNotContainsString( 'wp:', $result['html'] );
	}

	/**
	 * Full content skips markers but does not truncate after them.
	 *
	 * @return void
	 */
	public function test_explicit_full_mode_preserves_content_after_cutoff(): void {
		$post   = $this->paragraph( 'First' )
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. $this->paragraph( 'Last' );
		$result = $this->renderer()->render( $post, '', 'full' );

		self::assertSame( 'full', $result['source'] );
		self::assertStringContainsString( 'Last', $result['html'] );
		self::assertStringNotContainsString( 'email-cutoff', $result['html'] );
	}

	/**
	 * Manual and generated plain-text selections become escaped fragments.
	 *
	 * @return void
	 */
	public function test_plain_text_selections_are_html_escaped(): void {
		$result = $this->renderer()->render( $this->paragraph( 'Whole' ), '<b>A & B</b>' );
		self::assertSame( 'manual_excerpt', $result['source'] );
		self::assertSame( 'A & B', $result['text'] );
		self::assertSame( '<p>A &amp; B</p>', $result['html'] );

		$generated = $this->renderer()->render(
			$this->paragraph( 'one two three four' ),
			'',
			'site_default',
			true,
			2
		);
		self::assertSame( 'generated_excerpt', $generated['source'] );
		self::assertSame( 'one two…', $generated['text'] );
	}

	/**
	 * Never invoke dynamic blocks, registered shortcodes or embeds.
	 *
	 * @return void
	 */
	public function test_dynamic_and_unsupported_blocks_are_omitted_without_execution(): void {
		$calls = 0;
		add_shortcode(
			'awpn_renderer_test',
			static function () use ( &$calls ): string {
				++$calls;
				return 'EXECUTED';
			}
		);

		try {
			$post = $this->paragraph( 'Before [awpn_renderer_test] after' )
				. '<!-- wp:shortcode -->[awpn_renderer_test]<!-- /wp:shortcode -->'
				. '<!-- wp:embed {"url":"https://example.org"} -->'
				. '<figure>REMOTE</figure><!-- /wp:embed -->'
				. '<!-- wp:latest-posts /-->'
				. '<!-- wp:some-plugin/custom --><p>PLUGIN_SECRET</p>'
				. '<!-- /wp:some-plugin/custom -->'
				. '<!-- wp:image --><figure><img src="https://remote.test/pixel.gif">'
				. '</figure><!-- /wp:image -->'
				. $this->paragraph( 'End' );
			$result = $this->renderer()->render( $post, '', 'full' );
			self::assertSame( 0, $calls );
			self::assertStringNotContainsString( 'EXECUTED', $result['html'] );
			self::assertStringNotContainsString( 'awpn_renderer_test', $result['html'] );
			self::assertStringNotContainsString( 'REMOTE', $result['html'] );
			self::assertStringNotContainsString( 'PLUGIN_SECRET', $result['html'] );
			self::assertStringNotContainsString( 'pixel.gif', $result['html'] );
			self::assertStringContainsString( 'End', $result['text'] );
		} finally {
			remove_shortcode( 'awpn_renderer_test' );
		}
	}

	/**
	 * Remove executable markup, unsafe destinations, and remote images.
	 *
	 * @return void
	 */
	public function test_markup_sanitization_keeps_only_inert_elements(): void {
		$post = $this->paragraph(
			'<a href="javascript:alert(1)" onclick="bad()">No</a>'
			. '<script>SECRET_SCRIPT()</script>'
			. '<img src="https://remote.test/track.gif" alt="tracker">'
			. '<a href="/about">About</a>'
		);
		$result = $this->renderer()->render( $post, '', 'full' );
		$url    = home_url( '/about' );

		self::assertStringNotContainsString( 'javascript:', $result['html'] );
		self::assertStringNotContainsString( 'onclick', $result['html'] );
		self::assertStringNotContainsString( 'SECRET_SCRIPT', $result['html'] );
		self::assertStringNotContainsString( '<img', $result['html'] );
		self::assertStringContainsString( $url, $result['html'] );
		self::assertStringContainsString( $url, $result['text'] );
	}

	/**
	 * The nested cutoff selector intentionally fails to plain text.
	 *
	 * @return void
	 */
	public function test_nested_cutoff_produces_escaped_plain_text(): void {
		$post = $this->paragraph( 'Outside' )
			. '<!-- wp:group --><div class="wp-block-group">'
			. $this->paragraph( 'Inside' )
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. $this->paragraph( 'Secret' )
			. '</div><!-- /wp:group -->';
		$result = $this->renderer()->render( $post );
		self::assertSame( 'email_cutoff', $result['source'] );
		self::assertSame( 'Outside Inside', $result['text'] );
		self::assertStringNotContainsString( 'Secret', $result['html'] );
	}

	/**
	 * Build the renderer with its canonical selector.
	 *
	 * @return EmailContentRenderer
	 */
	private function renderer(): EmailContentRenderer {
		return new EmailContentRenderer( new EmailContentSelector() );
	}

	/**
	 * Build a trusted static paragraph fixture.
	 *
	 * @param string $inner_html Paragraph content.
	 * @return string
	 */
	private function paragraph( string $inner_html ): string {
		return '<!-- wp:paragraph --><p>' . $inner_html . '</p><!-- /wp:paragraph -->';
	}
}

// EOF: tests/Integration/EmailContentRendererTest.php.
