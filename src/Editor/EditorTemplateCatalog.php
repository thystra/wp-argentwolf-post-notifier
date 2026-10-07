<?php
/**
 * File: src/Editor/EditorTemplateCatalog.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

use Throwable;

/**
 * Bounded editor-visible template-choice catalog.
 *
 * Beta.1 owns only template selection metadata. Milestone 8 may populate this
 * catalog with real templates without changing the post metadata contract.
 */
final class EditorTemplateCatalog {
	/**
	 * Filter used to supply custom editor-visible template choices.
	 */
	public const FILTER = 'argentwolf_post_notifier_editor_template_choices';

	/**
	 * Maximum custom choices exposed in the editor bootstrap.
	 */
	private const MAX_CHOICES = 200;

	/**
	 * Return normalized editor-visible template choices.
	 *
	 * Template ID zero is permanently reserved for the site default. Filtered
	 * custom choices must use positive integer IDs and nonempty labels.
	 *
	 * @return array<int,array{value:int,label:string}>
	 */
	public function choices(): array {
		$choices = array(
			array(
				'value' => 0,
				'label' => __( 'Site default', 'argentwolf-post-notifier' ),
			),
		);

		try {
			/**
			 * Filter editor-visible custom template choices.
			 *
			 * Milestone 8 can populate this catalog without changing Beta.1
			 * post metadata or the editor selection contract.
			 *
			 * @param array<int,array{id:int,label:string}> $choices Custom choices.
			 */
			$candidates = apply_filters( self::FILTER, array() );
		} catch ( Throwable ) {
			return $choices;
		}

		if ( ! is_array( $candidates ) ) {
			return $choices;
		}

		$custom = array();

		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$id    = $this->positive_id( $candidate['id'] ?? null );
			$label = $candidate['label'] ?? null;

			if ( $id < 1 || ! is_string( $label ) || isset( $custom[ $id ] ) ) {
				continue;
			}

			$label = sanitize_text_field( $label );
			if ( '' === $label ) {
				continue;
			}

			$custom[ $id ] = array(
				'value' => $id,
				'label' => $label,
			);

			if ( count( $custom ) >= self::MAX_CHOICES ) {
				break;
			}
		}

		uasort(
			$custom,
			static function ( array $left, array $right ): int {
				$label_order = strnatcasecmp( $left['label'], $right['label'] );

				return 0 !== $label_order
					? $label_order
					: $left['value'] <=> $right['value'];
			}
		);

		return array_merge( $choices, array_values( $custom ) );
	}

	/**
	 * Normalize a positive template identifier without arbitrary scalar coercion.
	 *
	 * @param mixed $value Candidate identifier.
	 * @return int
	 */
	private function positive_id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if (
			! is_string( $value )
			|| 1 !== preg_match( '/^[1-9][0-9]*$/D', $value )
		) {
			return 0;
		}

		$id = (int) $value;

		return $id > 0 ? $id : 0;
	}
}

// EOF: src/Editor/EditorTemplateCatalog.php.
