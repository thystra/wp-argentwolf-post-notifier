<?php
/**
 * File: src/Content/EmailNamedTemplateLibrary.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use InvalidArgumentException;

/**
 * Bounded, site-wide reusable notification template catalog.
 *
 * IDs are stable and never recycled. The dedicated range avoids existing
 * externally supplied template IDs in the editor's original filter contract.
 * Only text settings already accepted by the canonical composer are stored.
 */
final class EmailNamedTemplateLibrary {
	/** Persist the entire library as one non-autoloaded WordPress option. */
	public const OPTION = 'argentwolf_post_notifier_named_templates';

	/** First plugin-managed template ID (zero remains the site default). */
	public const FIRST_ID = 1000000000;

	/** Last available plugin-managed template ID. */
	public const LAST_ID = 1000009999;

	/** Bound option size and the administrative form catalog. */
	public const MAX_TEMPLATES = 20;

	/**
	 * Use the exact canonical text validator, without an alternate token language.
	 *
	 * @param EmailTemplateSettings $settings Canonical text settings validator.
	 */
	public function __construct( private EmailTemplateSettings $settings ) {
	}

	/**
	 * Return valid named templates in ascending immutable-ID order.
	 *
	 * Invalid stored options are not exposed to the editor.
	 *
	 * @return array<int,array{id:int,name:string,settings:array<string,string>}>
	 */
	public function all(): array {
		try {
			return array_values( $this->state()['items'] );
		} catch ( InvalidArgumentException ) {
			return array();
		}
	}

	/**
	 * Find one persisted named template without falling back to site defaults.
	 *
	 * @param int $id Immutable ID.
	 * @return array{id:int,name:string,settings:array<string,string>}|null
	 */
	public function find( int $id ): ?array {
		foreach ( $this->all() as $item ) {
			if ( $id === $item['id'] ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Create a template with an immutable, never-reused identifier.
	 *
	 * @param string              $name   Administrator-supplied label.
	 * @param array<string,mixed> $fields Canonical plain-text template fields.
	 * @return int New template ID.
	 * @throws InvalidArgumentException On invalid input, corruption or capacity.
	 */
	public function create( string $name, array $fields ): int {
		$state = $this->state();
		if (
			count( $state['items'] ) >= self::MAX_TEMPLATES
			|| $state['next_id'] > self::LAST_ID
		) {
			throw new InvalidArgumentException( 'The named template library is full.' );
		}
		$id                    = $state['next_id'];
		$state['next_id']      = $id + 1;
		$state['items'][ $id ] = $this->item( $id, $name, $fields );
		$this->store( $state );
		return $id;
	}

	/**
	 * Update an existing template without changing its ID.
	 *
	 * @param int                 $id     Persisted ID.
	 * @param string              $name   Administrator-supplied label.
	 * @param array<string,mixed> $fields Canonical plain-text template fields.
	 * @return void
	 * @throws InvalidArgumentException On invalid input or unknown ID.
	 */
	public function update( int $id, string $name, array $fields ): void {
		$state = $this->state();
		if ( ! isset( $state['items'][ $id ] ) ) {
			throw new InvalidArgumentException( 'The named template does not exist.' );
		}
		$state['items'][ $id ] = $this->item( $id, $name, $fields );
		$this->store( $state );
	}

	/**
	 * Remove a template but never release its ID for reuse.
	 *
	 * @param int $id Persisted ID.
	 * @return void
	 * @throws InvalidArgumentException On an unknown ID or corrupted option.
	 */
	public function delete( int $id ): void {
		$state = $this->state();
		if ( ! isset( $state['items'][ $id ] ) ) {
			throw new InvalidArgumentException( 'The named template does not exist.' );
		}
		unset( $state['items'][ $id ] );
		$this->store( $state );
	}

	/**
	 * Strictly normalize a name and the same six canonical text fields.
	 *
	 * @param int                 $id     Stable ID.
	 * @param string              $name   Untrusted label.
	 * @param array<string,mixed> $fields Untrusted fields.
	 * @return array{id:int,name:string,settings:array<string,string>}
	 * @throws InvalidArgumentException On invalid label or template field data.
	 */
	private function item( int $id, string $name, array $fields ): array {
		if ( preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			throw new InvalidArgumentException( 'Invalid named template label.' );
		}
		$name = sanitize_text_field( $name );
		if (
			'' === trim( $name )
			|| strlen( $name ) > 80
			|| str_contains( $name, '{{' )
			|| str_contains( $name, '}}' )
		) {
			throw new InvalidArgumentException( 'Invalid named template label.' );
		}
		return array(
			'id'       => $id,
			'name'     => $name,
			'settings' => $this->settings->validate( $fields ),
		);
	}

	/**
	 * Read a complete, self-consistent option or fail closed.
	 *
	 * @return array<string,mixed> Validated high-water mark and named entries.
	 * @throws InvalidArgumentException On corrupted persisted library state.
	 */
	private function state(): array {
		$stored = get_option( self::OPTION, false );
		if ( false === $stored ) {
			return array(
				'next_id' => self::FIRST_ID,
				'items'   => array(),
			);
		}
		if (
			! is_array( $stored )
			|| ! isset( $stored['next_id'], $stored['items'] )
			|| 2 !== count( $stored )
			|| ! is_int( $stored['next_id'] )
			|| $stored['next_id'] < self::FIRST_ID
			|| $stored['next_id'] > self::LAST_ID + 1
			|| ! is_array( $stored['items'] )
			|| count( $stored['items'] ) > self::MAX_TEMPLATES
		) {
			throw new InvalidArgumentException( 'Invalid stored named template library.' );
		}
		$items = array();
		foreach ( $stored['items'] as $id => $item ) {
			if (
				! is_int( $id )
				|| $id < self::FIRST_ID
				|| $id >= $stored['next_id']
				|| ! is_array( $item )
				|| ! isset( $item['id'], $item['name'], $item['settings'] )
				|| 3 !== count( $item )
				|| $item['id'] !== $id
				|| ! is_string( $item['name'] )
				|| ! is_array( $item['settings'] )
			) {
				throw new InvalidArgumentException( 'Invalid stored named template entry.' );
			}
			$checked = $this->item( $id, $item['name'], $item['settings'] );
			if ( $checked !== $item ) {
				throw new InvalidArgumentException( 'Malformed named template entry.' );
			}
			$items[ $id ] = $checked;
		}
		ksort( $items, SORT_NUMERIC );
		return array(
			'next_id' => $stored['next_id'],
			'items'   => $items,
		);
	}

	/**
	 * Persist the complete validated state without WordPress autoload.
	 *
	 * @param array{next_id:int,items:array<int,mixed>} $state Checked state.
	 * @return void
	 */
	private function store( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}

// EOF: src/Content/EmailNamedTemplateLibrary.php.
