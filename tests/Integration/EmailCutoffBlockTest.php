<?php
/**
 * File: tests/Integration/EmailCutoffBlockTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Content\EmailCutoffBlock;
use ArgentWolf\PostNotifier\Plugin;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Integration contract for the public-invisible email cutoff marker.
 */
final class EmailCutoffBlockTest extends WP_UnitTestCase {
	/**
	 * The block is registered from metadata with an editor-only script.
	 *
	 * @return void
	 */
	public function test_plugin_registers_cutoff_block(): void {
		$service = Plugin::instance()->container()->get( EmailCutoffBlock::class );
		$block   = WP_Block_Type_Registry::get_instance()->get_registered(
			EmailCutoffBlock::BLOCK_NAME
		);

		self::assertInstanceOf( EmailCutoffBlock::class, $service );
		self::assertNotNull( $block );
		self::assertTrue( $block->is_dynamic() );
		self::assertContains( EmailCutoffBlock::EDITOR_SCRIPT, $block->editor_script_handles );
		self::assertTrue( wp_script_is( EmailCutoffBlock::EDITOR_SCRIPT, 'registered' ) );
		self::assertFalse( $block->supports['multiple'] );
	}

	/**
	 * Rendering must discard marker output even if unexpected inner content exists.
	 *
	 * @return void
	 */
	public function test_cutoff_is_invisible_in_public_block_rendering(): void {
		$service = Plugin::instance()->container()->get( EmailCutoffBlock::class );
		self::assertInstanceOf( EmailCutoffBlock::class, $service );
		self::assertSame( '', $service->render() );
		self::assertSame( '', $service->render( array(), 'SECRET_MARKER_CONTENT' ) );

		$before = '<!-- wp:paragraph --><p>Visible before</p><!-- /wp:paragraph -->';
		$marker = '<!-- wp:argentwolf-post-notifier/email-cutoff /-->';
		$after  = '<!-- wp:paragraph --><p>Visible after</p><!-- /wp:paragraph -->';
		$output = do_blocks( $before . $marker . $after );

		self::assertStringContainsString( 'Visible before', $output );
		self::assertStringContainsString( 'Visible after', $output );
		self::assertStringNotContainsString( 'email-cutoff', $output );
		self::assertSame( '', do_blocks( $marker ) );
	}
}

// EOF: tests/Integration/EmailCutoffBlockTest.php
