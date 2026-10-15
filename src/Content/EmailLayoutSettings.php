<?php
/**
 * File: src/Content/EmailLayoutSettings.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use InvalidArgumentException;

/**
 * Safe, closed-schema layout and theme options for notification email.
 *
 * Administrators choose only from validated primitives: six-digit hex
 * colours, a fixed font-stack enum, a bounded content width, and a fixed CTA
 * style enum. No CSS, HTML, URL, or template text is accepted, so layout can
 * never inject markup, remote assets, or executable code.
 */
final class EmailLayoutSettings {
	public const OPTION = 'argentwolf_post_notifier_email_layout';

	public const MIN_WIDTH = 480;
	public const MAX_WIDTH = 800;

	/**
	 * Fixed font stacks keyed by the stored enum value.
	 *
	 * @var array<string,string>
	 */
	public const FONTS = array(
		'system'    => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif',
		'serif'     => 'Georgia,"Times New Roman",Times,serif',
		'monospace' => 'Menlo,Consolas,"Liberation Mono",monospace',
	);

	public const CTA_STYLES = array( 'link', 'button' );

	private const COLOR_KEYS = array(
		'background_color',
		'content_background',
		'text_color',
		'accent_color',
	);

	/**
	 * Default layout.
	 *
	 * @return array{background_color:string,content_background:string,text_color:string,accent_color:string,font_family:string,content_width:int,cta_style:string}
	 */
	public function defaults(): array {
		return array(
			'background_color'   => '#f4f4f4',
			'content_background' => '#ffffff',
			'text_color'         => '#1e1e1e',
			'accent_color'       => '#2271b1',
			'font_family'        => 'system',
			'content_width'      => 640,
			'cta_style'          => 'link',
		);
	}

	/**
	 * Saved layout, falling back to defaults when stored data is invalid.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, null );
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
	 * Validate a complete layout into canonical stable-order form.
	 *
	 * Form posts deliver widths as strings; both int and digit strings are
	 * accepted. Colours are lower-cased.
	 *
	 * @param mixed $input Candidate layout.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When any field is missing or unsafe.
	 */
	public function validate( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'Layout must be an array.' );
		}
		$expected = array_keys( $this->defaults() );
		$actual   = array_keys( $input );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new InvalidArgumentException( 'Layout fields are incomplete or unknown.' );
		}

		$layout = array();
		foreach ( self::COLOR_KEYS as $key ) {
			$value = $input[ $key ];
			if ( ! is_string( $value ) || 1 !== preg_match( '/\A#[0-9a-fA-F]{6}\z/D', $value ) ) {
				throw new InvalidArgumentException( 'Layout colour is invalid.' );
			}
			$layout[ $key ] = strtolower( $value );
		}

		$font = $input['font_family'];
		if ( ! is_string( $font ) || ! isset( self::FONTS[ $font ] ) ) {
			throw new InvalidArgumentException( 'Layout font is invalid.' );
		}
		$layout['font_family'] = $font;

		$width = $input['content_width'];
		if ( is_string( $width ) && 1 === preg_match( '/\A[0-9]{3}\z/D', $width ) ) {
			$width = (int) $width;
		}
		if ( ! is_int( $width ) || $width < self::MIN_WIDTH || $width > self::MAX_WIDTH ) {
			throw new InvalidArgumentException( 'Layout width is out of range.' );
		}
		$layout['content_width'] = $width;

		$cta = $input['cta_style'];
		if ( ! is_string( $cta ) || ! in_array( $cta, self::CTA_STYLES, true ) ) {
			throw new InvalidArgumentException( 'Layout CTA style is invalid.' );
		}
		$layout['cta_style'] = $cta;

		return $layout;
	}

	/**
	 * Save one validated layout.
	 *
	 * @param mixed $input Candidate layout.
	 * @return void
	 * @throws InvalidArgumentException When invalid.
	 */
	public function save( mixed $input ): void {
		update_option( self::OPTION, $this->validate( $input ), false );
	}

	/**
	 * Restore default layout.
	 *
	 * @return void
	 */
	public function restore_defaults(): void {
		delete_option( self::OPTION );
	}
}

// EOF: src/Content/EmailLayoutSettings.php.
