<?php
/**
 * File: tests/Integration/EmailContentSelectorTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Plugin;
use WP_UnitTestCase;

/**
 * Integration contract for deterministic content selection before rendering.
 */
final class EmailContentSelectorTest extends WP_UnitTestCase {
	/**
	 * Selector is available for the future canonical message composer.
	 *
	 * @return void
	 */
	public function test_selector_is_available_from_the_service_container(): void {
		self::assertInstanceOf(
			EmailContentSelector::class,
			Plugin::instance()->container()->get( EmailContentSelector::class )
		);
	}

	/**
	 * Full mode takes precedence and strips invisible presentation markers.
	 *
	 * @return void
	 */
	public function test_full_mode_ignores_both_cutoff_markers(): void {
		$selector = new EmailContentSelector();
		$content  = $this->paragraph( 'Before' )
			. '<!-- wp:more /-->'
			. $this->paragraph( 'Between' )
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. $this->paragraph( 'After' );
		$result   = $selector->select( $content, 'Manual', 'full' );

		self::assertSame( 'full', $result['source'] );
		self::assertSame( 'blocks', $result['format'] );
		self::assertStringContainsString( 'After', $result['content'] );
		self::assertStringNotContainsString( 'email-cutoff', $result['content'] );
		self::assertStringNotContainsString( 'wp:more', $result['content'] );
	}

	/**
	 * The Email Cutoff marker outranks the More block and manual excerpt.
	 *
	 * @return void
	 */
	public function test_email_cutoff_takes_precedence_over_more_and_manual(): void {
		$selector = new EmailContentSelector();
		$content  = $this->paragraph( 'First' )
			. '<!-- wp:more /-->'
			. $this->paragraph( 'Second' )
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. $this->paragraph( 'Third' );
		$result   = $selector->select( $content, 'Manual text' );

		self::assertSame( 'email_cutoff', $result['source'] );
		self::assertSame( 'blocks', $result['format'] );
		self::assertStringContainsString( 'First', $result['content'] );
		self::assertStringContainsString( 'Second', $result['content'] );
		self::assertStringNotContainsString( 'Third', $result['content'] );
		self::assertStringNotContainsString( 'wp:more', $result['content'] );
	}

	/**
	 * More is optional and cannot outrank an explicit Email Cutoff block.
	 *
	 * @return void
	 */
	public function test_more_is_selected_only_when_enabled(): void {
		$selector = new EmailContentSelector();
		$content  = $this->paragraph( 'First' )
			. '<!-- wp:more /-->'
			. $this->paragraph( 'Second' );

		$enabled = $selector->select( $content, 'Manual', 'excerpt', true );
		self::assertSame( 'more', $enabled['source'] );
		self::assertStringContainsString( 'First', $enabled['content'] );
		self::assertStringNotContainsString( 'Second', $enabled['content'] );

		$disabled = $selector->select( $content, 'Manual', 'excerpt', false );
		self::assertSame( 'manual_excerpt', $disabled['source'] );
		self::assertSame( 'Manual', $disabled['content'] );
	}

	/**
	 * A manual excerpt is plain text and precedes generated content.
	 *
	 * @return void
	 */
	public function test_manual_excerpt_is_not_executed_or_rendered(): void {
		$selector = new EmailContentSelector();
		$result   = $selector->select( $this->paragraph( 'Full post' ), '<b>Manual</b> text' );

		self::assertSame( 'manual_excerpt', $result['source'] );
		self::assertSame( 'text', $result['format'] );
		self::assertSame( 'Manual text', $result['content'] );
	}

	/**
	 * Generated excerpt length is caller configurable and bounded.
	 *
	 * @return void
	 */
	public function test_generated_excerpt_is_length_bounded(): void {
		$selector = new EmailContentSelector();
		$content  = $this->paragraph( 'one two three four five' );
		$result   = $selector->select( $content, '', 'site_default', true, 3 );

		self::assertSame( 'generated_excerpt', $result['source'] );
		self::assertSame( 'text', $result['format'] );
		self::assertSame( 'one two three…', $result['content'] );
		self::assertSame(
			5,
			str_word_count( $selector->select( $content, '', 'excerpt', true, 9999 )['content'] )
		);
	}

	/**
	 * Nested cutoffs safely yield static text, not broken partial block HTML.
	 *
	 * @return void
	 */
	public function test_nested_cutoff_produces_safe_plain_text(): void {
		$selector = new EmailContentSelector();
		$content  = $this->paragraph( 'Outside' )
			. '<!-- wp:group --><div class="wp-block-group">'
			. $this->paragraph( 'Inside' )
			. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
			. $this->paragraph( 'Secret' )
			. '</div><!-- /wp:group -->'
			. $this->paragraph( 'After' );
		$result   = $selector->select( $content, 'Manual' );

		self::assertSame( 'email_cutoff', $result['source'] );
		self::assertSame( 'text', $result['format'] );
		self::assertSame( 'Outside Inside', $result['content'] );
		self::assertStringNotContainsString( 'Secret', $result['content'] );
	}

	/**
	 * Neither dynamic rendering nor shortcode handlers can run at selection.
	 *
	 * @return void
	 */
	public function test_generated_excerpt_does_not_execute_shortcode(): void {
		$selector = new EmailContentSelector();
		$calls    = 0;
		add_shortcode(
			'awpn_selector_test',
			static function () use ( &$calls ): string {
				++$calls;
				return 'EXECUTED';
			}
		);

		try {
			$content = $this->paragraph( 'Hello [awpn_selector_test] world' );
			$result  = $selector->select( $content );
			self::assertSame( 0, $calls );
			self::assertStringNotContainsString( 'EXECUTED', $result['content'] );
			self::assertSame( 'Hello world', $result['content'] );
		} finally {
			remove_shortcode( 'awpn_selector_test' );
		}
	}

	/**
	 * Construct one simple block from trusted test words.
	 *
	 * @param string $content Text used by the fixture.
	 * @return string
	 */
	private function paragraph( string $content ): string {
		return '<!-- wp:paragraph --><p>' . $content . '</p><!-- /wp:paragraph -->';
	}
}

// EOF: tests/Integration/EmailContentSelectorTest.php.
