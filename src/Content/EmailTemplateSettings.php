<?php
/**
 * File: src/Content/EmailTemplateSettings.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use InvalidArgumentException;

/**
 * Validate and store text-only notification template customizations.
 *
 * No user-supplied HTML, PHP, URL, or subscriber footer is configurable here.
 */
final class EmailTemplateSettings {
	/** WordPress option for this site's template text. */
	public const OPTION = 'argentwolf_post_notifier_template_text';

	/**
	 * The only placeholders supported in operator-supplied text.
	 *
	 * @var array<int,string>
	 */
	public const TEXT_TOKENS = array( 'site_name', 'post_title' );

	/**
	 * Return localizable built-in defaults.
	 *
	 * @return array<string,string>
	 */
	public function defaults(): array {
		return array(
			'subject'     => __( 'New post: {{post_title}}', 'argentwolf-post-notifier' ),
			'heading'     => '{{post_title}}',
			'before'      => '',
			'after'       => '',
			'cta'         => __( 'Read the post', 'argentwolf-post-notifier' ),
			'footer_note' => '',
		);
	}

	/**
	 * Read only a completely valid stored configuration; otherwise use defaults.
	 *
	 * @return array<string,string>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, false );
		if ( ! is_array( $stored ) ) {
			return $this->defaults();
		}
		try {
			return $this->validate( $stored );
		} catch ( InvalidArgumentException ) {
			return $this->defaults();
		}
	}

	/**
	 * Save an entire validated configuration, never partial unchecked fields.
	 *
	 * @param array<string,mixed> $fields Operator-provided settings.
	 * @return void
	 * @throws InvalidArgumentException If a field or placeholder is unsafe.
	 */
	public function save( array $fields ): void {
		update_option( self::OPTION, $this->validate( $fields ), false );
	}

	/**
	 * Revert to built-in localized defaults.
	 *
	 * @return void
	 */
	public function restore_defaults(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Normalize permitted plain-text fields and reject unknown tokens.
	 *
	 * @param array<string,mixed> $fields Untrusted option or form values.
	 * @return array<string,string>
	 * @throws InvalidArgumentException If a field or placeholder is unsafe.
	 */
	public function validate( array $fields ): array {
		$limits = array(
			'subject'     => 200,
			'heading'     => 180,
			'before'      => 800,
			'after'       => 800,
			'cta'         => 100,
			'footer_note' => 250,
		);
		$result = array();
		if ( array_diff( array_keys( $fields ), array_keys( $limits ) ) ) {
			throw new InvalidArgumentException( 'Unsupported notification template field.' );
		}
		foreach ( $limits as $field => $length ) {
			if ( ! isset( $fields[ $field ] ) || ! is_string( $fields[ $field ] ) ) {
				throw new InvalidArgumentException( 'A notification template field is missing.' );
			}
			$raw = $fields[ $field ];
			if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw ) ) {
				throw new InvalidArgumentException( 'Invalid notification template text.' );
			}
			if ( 'subject' === $field && preg_match( '/[\r\n]/', $raw ) ) {
				throw new InvalidArgumentException( 'Email subject must be one line.' );
			}
			$value = in_array( $field, array( 'before', 'after', 'footer_note' ), true )
				? sanitize_textarea_field( $raw )
				: sanitize_text_field( $raw );
			if ( strlen( $value ) > $length ) {
				throw new InvalidArgumentException( 'Notification template text is too long.' );
			}
			// A fixed allowlist prevents accidental unresolved or executable markup.
			preg_match_all( '/{{([^{}]+)}}/', $value, $matches );
			foreach ( $matches[1] as $token ) {
				if ( ! in_array( $token, self::TEXT_TOKENS, true ) ) {
					throw new InvalidArgumentException(
						'Unsupported notification template token.'
					);
				}
			}
			$without_tokens = preg_replace( '/{{(?:site_name|post_title)}}/', '', $value );
			if ( str_contains( $without_tokens, '{{' ) || str_contains( $without_tokens, '}}' ) ) {
				throw new InvalidArgumentException( 'Malformed notification template token.' );
			}
			if ( in_array( $field, array( 'subject', 'heading', 'cta' ), true ) && '' === $value ) {
				throw new InvalidArgumentException(
					'Required notification template text is empty.'
				);
			}
			$result[ $field ] = $value;
		}
		return $result;
	}
}

// EOF: src/Content/EmailTemplateSettings.php.
