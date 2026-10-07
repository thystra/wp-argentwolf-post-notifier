<?php
/**
 * File: tests/Integration/EditorAssetsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Editor\EditorAssets;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use WP_UnitTestCase;

/**
 * Integration coverage for capability-gated block-editor assets.
 */
final class EditorAssetsTest extends WP_UnitTestCase {
	/**
	 * Administrator used for authorized editor assertions.
	 *
	 * @var int
	 */
	private int $administrator_id = 0;

	/**
	 * Prepare an authorized post-editor screen.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Capabilities::install( true );
		$this->administrator_id = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->administrator_id );
		set_current_screen( 'post' );
		wp_dequeue_script( EditorAssets::SCRIPT_HANDLE );
	}

	/**
	 * Restore global editor state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_dequeue_script( EditorAssets::SCRIPT_HANDLE );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The plugin service must register on the editor-only enqueue hook.
	 *
	 * @return void
	 */
	public function test_service_registers_block_editor_enqueue_hook(): void {
		$service = Plugin::instance()->container()->get( EditorAssets::class );

		self::assertInstanceOf( EditorAssets::class, $service );
		self::assertNotFalse(
			has_action(
				'enqueue_block_editor_assets',
				array( $service, 'enqueue_assets' )
			)
		);
	}

	/**
	 * Authorized post editors receive the runtime sidebar and canonical settings.
	 *
	 * @return void
	 */
	public function test_authorized_post_editor_enqueues_sidebar_runtime(): void {
		$container = Plugin::instance()->container();
		$lists     = $container->get( NamedListRepository::class );
		$service   = $container->get( EditorAssets::class );
		self::assertInstanceOf( NamedListRepository::class, $lists );
		self::assertInstanceOf( EditorAssets::class, $service );

		$list_id = $lists->create(
			'Editorial digest',
			'Internal description that must not reach the editor bootstrap.',
			$this->administrator_id
		);
		self::assertGreaterThan( 0, $list_id );

		$service->enqueue_assets();

		self::assertTrue( wp_script_is( EditorAssets::SCRIPT_HANDLE, 'enqueued' ) );
		$dependency = wp_scripts()->registered[ EditorAssets::SCRIPT_HANDLE ] ?? null;
		self::assertNotNull( $dependency );
		self::assertContains( 'wp-editor', $dependency->deps );
		self::assertContains( 'wp-data', $dependency->deps );
		self::assertContains( 'wp-plugins', $dependency->deps );

		$before = wp_scripts()->get_data( EditorAssets::SCRIPT_HANDLE, 'before' );
		self::assertIsArray( $before );
		$inline = implode( "\n", $before );
		self::assertStringContainsString( PostNotificationMeta::SEND_INTENT_KEY, $inline );
		self::assertStringContainsString( PostNotificationMeta::AUDIENCE_CONFIG_KEY, $inline );
		self::assertStringContainsString( PostNotificationMeta::CONTENT_MODE_KEY, $inline );
		self::assertStringContainsString( PostNotificationMeta::CTA_TEXT_KEY, $inline );
		self::assertStringContainsString( 'Editorial digest', $inline );
		self::assertStringContainsString( '"value":"administrator"', $inline );
		self::assertStringNotContainsString( 'Internal description', $inline );
		self::assertStringNotContainsString( 'member_count', $inline );
	}

	/**
	 * Send authorization may expose list names without granting list administration.
	 *
	 * @return void
	 */
	public function test_delegated_sender_receives_named_list_choices_without_management(): void {
		$container = Plugin::instance()->container();
		$lists     = $container->get( NamedListRepository::class );
		$service   = $container->get( EditorAssets::class );
		self::assertInstanceOf( NamedListRepository::class, $lists );
		self::assertInstanceOf( EditorAssets::class, $service );

		$list_id = $lists->create(
			'Sender-visible list',
			'Hidden detail',
			$this->administrator_id
		);
		self::assertGreaterThan( 0, $list_id );

		$editor_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		$editor    = get_userdata( $editor_id );
		self::assertInstanceOf( \WP_User::class, $editor );
		$editor->add_cap( Capabilities::SEND_NOTIFICATIONS );
		wp_set_current_user( $editor_id );

		self::assertTrue( current_user_can( Capabilities::SEND_NOTIFICATIONS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_LISTS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_SUBSCRIBERS ) );

		$service->enqueue_assets();
		self::assertTrue( wp_script_is( EditorAssets::SCRIPT_HANDLE, 'enqueued' ) );

		$before = wp_scripts()->get_data( EditorAssets::SCRIPT_HANDLE, 'before' );
		self::assertIsArray( $before );
		$inline = implode( "\n", $before );
		self::assertStringContainsString( 'Sender-visible list', $inline );
		self::assertStringNotContainsString( 'Hidden detail', $inline );
	}

	/**
	 * Editors without send authorization must not receive the sidebar asset.
	 *
	 * @return void
	 */
	public function test_editor_without_send_capability_does_not_enqueue_sidebar(): void {
		$editor_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $editor_id );

		$service = Plugin::instance()->container()->get( EditorAssets::class );
		self::assertInstanceOf( EditorAssets::class, $service );
		$service->enqueue_assets();

		self::assertFalse( wp_script_is( EditorAssets::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Authorized senders must not receive the post sidebar on other post types.
	 *
	 * @return void
	 */
	public function test_non_post_screen_does_not_enqueue_sidebar(): void {
		set_current_screen( 'page' );

		$service = Plugin::instance()->container()->get( EditorAssets::class );
		self::assertInstanceOf( EditorAssets::class, $service );
		$service->enqueue_assets();

		self::assertFalse( wp_script_is( EditorAssets::SCRIPT_HANDLE, 'enqueued' ) );
	}
}

// EOF: tests/Integration/EditorAssetsTest.php.
