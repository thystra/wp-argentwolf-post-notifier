<?php
/**
 * File: src/Subscriber/SubscribeBlock.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use WP_Block;

/**
 * Dynamic public subscribe block renderer and editor registration.
 */
final class SubscribeBlock implements Registerable {
	/** Canonical block name. */
	public const BLOCK_NAME = 'argentwolf-post-notifier/subscribe';

	/** Editor asset handle. */
	public const EDITOR_SCRIPT = 'argentwolf-post-notifier-subscribe-editor';

	/**
	 * Construct the public block.
	 *
	 * @param SignupFormContext $context Signed rendered-form context.
	 */
	public function __construct( private SignupFormContext $context ) {
	}

	/**
	 * Register the dynamic block.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the block metadata and its human-readable editor script.
	 *
	 * @return void
	 */
	public function register_block(): void {
		wp_register_script(
			self::EDITOR_SCRIPT,
			ARGENTWOLF_POST_NOTIFIER_URL . 'assets/runtime/subscribe-editor.js',
			array(
				'wp-block-editor',
				'wp-blocks',
				'wp-components',
				'wp-element',
				'wp-i18n',
			),
			ARGENTWOLF_POST_NOTIFIER_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_SCRIPT, 'argentwolf-post-notifier' );

		register_block_type(
			ARGENTWOLF_POST_NOTIFIER_DIR . 'blocks/subscribe',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Render the public no-JavaScript subscription form.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Saved block content, unused for dynamic output.
	 * @param WP_Block|null       $block      Block instance and context.
	 * @return string
	 */
	public function render(
		array $attributes,
		string $content = '',
		?WP_Block $block = null
	): string {
		unset( $content );

		$values         = $this->presentation_values( $attributes );
		$source_post_id = $this->source_post_id( $block );
		$return_url     = $this->return_url( $source_post_id );
		$context        = $this->context->create( $values['consent_text'], $source_post_id );
		$form_id        = wp_unique_id( 'argentwolf-post-notifier-subscribe-' );
		$email_id       = $form_id . '-email';
		$name_id        = $form_id . '-name';
		$consent_id     = $form_id . '-consent';

		$html = '<div class="wp-block-argentwolf-post-notifier-subscribe">';
		$html .= $this->render_status();
		$html .= '<h3>' . esc_html( $values['heading'] ) . '</h3>';
		$html .= '<p>' . esc_html( $values['description'] ) . '</p>';
		$html .= sprintf(
			'<form id="%1$s" method="post" action="%2$s">',
			esc_attr( $form_id ),
			esc_url( admin_url( 'admin-post.php' ) )
		);
		$html .= sprintf(
			'<input type="hidden" name="action" value="%s">',
			esc_attr( PublicSignupController::POST_ACTION )
		);
		$html .= wp_nonce_field(
			PublicSignupController::NONCE_ACTION,
			PublicSignupController::NONCE_FIELD,
			false,
			false
		);
		$html .= sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( PublicSignupController::CONTEXT_FIELD ),
			esc_attr( $context['context'] )
		);
		$html .= sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( PublicSignupController::CONTEXT_SIGNATURE_FIELD ),
			esc_attr( $context['signature'] )
		);
		$html .= sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( PublicSignupController::RETURN_FIELD ),
			esc_url( $return_url )
		);
		$html .= sprintf(
			'<p><label for="%1$s">%2$s</label><br>',
			esc_attr( $email_id ),
			esc_html__( 'Email address', 'argentwolf-post-notifier' )
		);
		$html .= sprintf(
			'<input id="%1$s" name="%2$s" type="email" autocomplete="email" required></p>',
			esc_attr( $email_id ),
			esc_attr( PublicSignupController::EMAIL_FIELD )
		);
		$html .= sprintf(
			'<p><label for="%1$s">%2$s</label><br>',
			esc_attr( $name_id ),
			esc_html__( 'Name (optional)', 'argentwolf-post-notifier' )
		);
		$html .= sprintf(
			'<input id="%1$s" name="%2$s" type="text" autocomplete="name"></p>',
			esc_attr( $name_id ),
			esc_attr( PublicSignupController::NAME_FIELD )
		);
		$html .= sprintf(
			'<p><label for="%1$s"><input id="%1$s" name="%2$s" '
				. 'type="checkbox" value="1" required> %3$s</label></p>',
			esc_attr( $consent_id ),
			esc_attr( PublicSignupController::CONSENT_FIELD ),
			esc_html( $values['consent_text'] )
		);
		$html .= '<div aria-hidden="true" '
			. 'style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">';
		$html .= sprintf(
			'<input name="%s" type="text" tabindex="-1" autocomplete="off">',
			esc_attr( PublicSignupController::HONEYPOT_FIELD )
		);
		$html .= '</div>';
		$html .= sprintf(
			'<p><button class="wp-element-button" type="submit">%s</button></p>',
			esc_html( $values['button_label'] )
		);
		$html .= '</form></div>';

		return $html;
	}

	/**
	 * Return the block attribute defaults used for defensive rendering.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function attribute_schema(): array {
		return array(
			'heading'      => array(
				'type'    => 'string',
				'default' => 'Get new post notifications by email',
			),
			'description'  => array(
				'type'    => 'string',
				'default' => 'Enter your email address and confirm the message we send you.',
			),
			'consentText'  => array(
				'type'    => 'string',
				'default' => 'I agree to receive email notifications when new posts are published.',
			),
			'buttonLabel'  => array(
				'type'    => 'string',
				'default' => 'Subscribe',
			),
		);
	}

	/**
	 * Normalize configurable presentation text.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array{heading:string,description:string,consent_text:string,button_label:string}
	 */
	private function presentation_values( array $attributes ): array {
		$schema = self::attribute_schema();

		$heading      = $this->attribute_text(
			$attributes,
			'heading',
			(string) $schema['heading']['default']
		);
		$description  = $this->attribute_text(
			$attributes,
			'description',
			(string) $schema['description']['default']
		);
		$consent_text = $this->attribute_text(
			$attributes,
			'consentText',
			(string) $schema['consentText']['default']
		);
		$button_label = $this->attribute_text(
			$attributes,
			'buttonLabel',
			(string) $schema['buttonLabel']['default']
		);

		return array(
			'heading'      => $heading,
			'description'  => $description,
			'consent_text' => $consent_text,
			'button_label' => $button_label,
		);
	}

	/**
	 * Return one sanitized non-empty block text attribute.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $key        Attribute key.
	 * @param string              $fallback   Default value.
	 * @return string
	 */
	private function attribute_text( array $attributes, string $key, string $fallback ): string {
		$value = $attributes[ $key ] ?? $fallback;
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = sanitize_text_field( $value );
		return '' === $value ? $fallback : $value;
	}

	/**
	 * Resolve the public source post without trusting submitted input.
	 *
	 * @param WP_Block|null $block Block instance.
	 * @return int|null
	 */
	private function source_post_id( ?WP_Block $block ): ?int {
		$context_id = null === $block ? 0 : absint( $block->context['postId'] ?? 0 );
		$post_id    = $context_id > 0 ? $context_id : absint( get_the_ID() );

		return $post_id > 0 ? $post_id : null;
	}

	/**
	 * Return a local URL for post-redirect-get navigation.
	 *
	 * @param int|null $source_post_id Source post ID.
	 * @return string
	 */
	private function return_url( ?int $source_post_id ): string {
		if ( null !== $source_post_id ) {
			$permalink = get_permalink( $source_post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}

	/**
	 * Render a generic accessible status region after redirect.
	 *
	 * @return string
	 */
	private function render_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only result code.
		$result = $this->request_value( $_GET, PublicSignupController::RESULT_QUERY );
		if ( PublicSignupController::RESULT_RECEIVED === $result ) {
			$message = esc_html__(
				'If this address can be subscribed, check your email for a confirmation message.',
				'argentwolf-post-notifier'
			);
		} elseif ( PublicSignupController::RESULT_ERROR === $result ) {
			$message = esc_html__(
				'We could not submit that request. Reload the page and try again.',
				'argentwolf-post-notifier'
			);
		} else {
			return '';
		}

		return sprintf(
			'<div id="%1$s" role="status" aria-live="polite" tabindex="-1"><p>%2$s</p></div>',
			esc_attr( PublicSignupController::STATUS_ID ),
			$message
		);
	}

	/**
	 * Read one request value as unslashed text.
	 *
	 * @param array<string,mixed> $source Request source array.
	 * @param string              $key    Request key.
	 * @return string
	 */
	private function request_value( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}
}

// EOF: src/Subscriber/SubscribeBlock.php.
