<?php
/**
 * File: src/Content/EmailCutoffBlock.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use WP_Block;

/**
 * Editor-only email cutoff marker with deliberately empty public rendering.
 */
final class EmailCutoffBlock implements Registerable {
	/** Canonical block name. */
	public const BLOCK_NAME = 'argentwolf-post-notifier/email-cutoff';

	/** Human-readable editor script handle. */
	public const EDITOR_SCRIPT = 'argentwolf-post-notifier-email-cutoff-editor';

	/**
	 * Register the block on WordPress init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the block and its editor-only interface.
	 *
	 * @return void
	 */
	public function register_block(): void {
		wp_register_script(
			self::EDITOR_SCRIPT,
			ARGENTWOLF_POST_NOTIFIER_URL . 'assets/runtime/email-cutoff-editor.js',
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n' ),
			ARGENTWOLF_POST_NOTIFIER_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_SCRIPT, 'argentwolf-post-notifier' );

		register_block_type(
			ARGENTWOLF_POST_NOTIFIER_DIR . 'blocks/email-cutoff',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Never expose the marker or any unexpected saved content on the public site.
	 *
	 * @param array<string,mixed> $attributes Marker attributes (unused).
	 * @param string              $content    Saved inner content (discarded).
	 * @param WP_Block|null       $block      Block instance (unused).
	 * @return string
	 */
	public function render(
		array $attributes = array(),
		string $content = '',
		?WP_Block $block = null
	): string {
		return '';
	}
}

// EOF: src/Content/EmailCutoffBlock.php.
