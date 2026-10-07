<?php
/**
 * File: src/Editor/EditorAssets.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use WP_Screen;

/**
 * Enqueue the post-notification block-editor sidebar for authorized senders.
 */
final class EditorAssets implements Registerable {
	/**
	 * Runtime editor asset handle.
	 */
	public const SCRIPT_HANDLE = 'argentwolf-post-notifier-editor';

	/**
	 * Runtime editor asset path relative to the plugin root.
	 */
	public const RUNTIME_SCRIPT = 'assets/runtime/post-editor.js';

	/**
	 * Construct the editor asset coordinator.
	 *
	 * @param NamedListRepository $named_lists Named-list read repository.
	 */
	public function __construct( private NamedListRepository $named_lists ) {
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'enqueue_block_editor_assets',
			array( $this, 'enqueue_assets' )
		);
	}

	/**
	 * Enqueue the sidebar only for authorized post block-editor requests.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ARGENTWOLF_POST_NOTIFIER_URL . self::RUNTIME_SCRIPT,
			array(
				'wp-components',
				'wp-data',
				'wp-editor',
				'wp-element',
				'wp-i18n',
				'wp-plugins',
			),
			ARGENTWOLF_POST_NOTIFIER_VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'argentwolf-post-notifier' );

		$settings = wp_json_encode(
			$this->settings(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $settings ) {
			return;
		}

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.argentWolfPostNotifierEditor = ' . $settings . ';',
			'before'
		);
	}

	/**
	 * Determine whether the current request is an authorized post editor.
	 *
	 * The block-editor hook itself distinguishes Gutenberg from the classic editor.
	 * Screen checks keep the asset off pages and other post types.
	 *
	 * @return bool
	 */
	private function should_enqueue(): bool {
		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen ) {
			return false;
		}

		return 'post' === $screen->base
			&& 'post' === $screen->post_type
			&& current_user_can( Capabilities::SEND_NOTIFICATIONS );
	}

	/**
	 * Return non-sensitive editor contract values consumed by the runtime asset.
	 *
	 * Meta names and enum values originate in PHP so the JavaScript runtime does
	 * not maintain a second copy of the persistence contract.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		return array(
			'postType' => 'post',
			'metaKeys' => array(
				'sendIntent'     => PostNotificationMeta::SEND_INTENT_KEY,
				'audienceConfig' => PostNotificationMeta::AUDIENCE_CONFIG_KEY,
				'contentMode'    => PostNotificationMeta::CONTENT_MODE_KEY,
				'ctaText'        => PostNotificationMeta::CTA_TEXT_KEY,
			),
			'values'   => array(
				'sendIntent'  => array(
					'siteDefault' => SendIntent::SiteDefault->value,
					'send'        => SendIntent::Send->value,
					'doNotSend'   => SendIntent::DoNotSend->value,
				),
				'contentMode' => array(
					'siteDefault' => ContentMode::SiteDefault->value,
					'excerpt'     => ContentMode::Excerpt->value,
					'full'        => ContentMode::Full->value,
				),
			),
			'audience' => array(
				'roles' => $this->role_choices(),
				'lists' => $this->named_list_choices(),
			),
		);
	}

	/**
	 * Return selectable WordPress roles without exposing user records.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function role_choices(): array {
		$choices = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			if ( ! is_string( $slug ) || ! is_array( $role ) ) {
				continue;
			}

			$value = sanitize_key( $slug );
			if ( '' === $value ) {
				continue;
			}

			$name      = $role['name'] ?? $value;
			$label     = is_string( $name ) ? translate_user_role( $name ) : $value;
			$choices[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		usort(
			$choices,
			static function ( array $left, array $right ): int {
				$label_order = strnatcasecmp( $left['label'], $right['label'] );

				return 0 !== $label_order
					? $label_order
					: strcmp( $left['value'], $right['value'] );
			}
		);

		return $choices;
	}

	/**
	 * Return named-list identifiers and labels only.
	 *
	 * List descriptions, member counts, and member data stay out of the editor
	 * bootstrap contract so send authorization does not imply contact visibility.
	 *
	 * @return array<int,array{value:int,label:string}>
	 */
	private function named_list_choices(): array {
		$choices = array();

		foreach ( $this->named_lists->all() as $row ) {
			$id   = absint( $row['id'] ?? 0 );
			$name = $row['name'] ?? '';
			if ( $id < 1 || ! is_string( $name ) ) {
				continue;
			}

			$label = sanitize_text_field( $name );
			if ( '' === $label ) {
				continue;
			}

			$choices[] = array(
				'value' => $id,
				'label' => $label,
			);
		}

		return $choices;
	}
}

// EOF: src/Editor/EditorAssets.php.
