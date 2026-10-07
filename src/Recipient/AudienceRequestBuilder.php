<?php
/**
 * File: src/Recipient/AudienceRequestBuilder.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

/**
 * Build reusable audience-resolution requests from canonical editor configuration.
 */
final class AudienceRequestBuilder {
	/**
	 * Build one request from canonical audience configuration.
	 *
	 * @param array<string,mixed> $config                       Audience configuration.
	 * @param bool                $site_default_user_subscribed Whether site-default users opt in.
	 * @return AudienceResolutionRequest
	 */
	public function from_config(
		array $config,
		bool $site_default_user_subscribed = false
	): AudienceResolutionRequest {
		return new AudienceResolutionRequest(
			role_user_ids: $this->role_user_ids( $config['role_slugs'] ?? array() ),
			named_list_ids: $this->positive_ids( $config['named_list_ids'] ?? array() ),
			included_user_ids: $this->positive_ids(
				$config['included_user_ids'] ?? array()
			),
			included_subscriber_ids: $this->positive_ids(
				$config['included_subscriber_ids'] ?? array()
			),
			excluded_user_ids: $this->positive_ids(
				$config['excluded_user_ids'] ?? array()
			),
			excluded_subscriber_ids: $this->positive_ids(
				$config['excluded_subscriber_ids'] ?? array()
			),
			site_default_user_subscribed: $site_default_user_subscribed
		);
	}

	/**
	 * Expand selected WordPress roles into deterministic user IDs.
	 *
	 * Unknown role slugs are ignored rather than broadening the query.
	 *
	 * @param mixed $values Candidate role slugs.
	 * @return array<int,int>
	 */
	private function role_user_ids( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$known_roles = wp_roles()->roles;
		$roles       = array();

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$slug = sanitize_key( $value );
			if ( '' === $slug || ! isset( $known_roles[ $slug ] ) ) {
				continue;
			}

			$roles[ $slug ] = $slug;
		}

		if ( array() === $roles ) {
			return array();
		}

		ksort( $roles, SORT_STRING );
		$user_ids = get_users(
			array(
				'role__in' => array_values( $roles ),
				'fields'   => 'ID',
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'number'   => -1,
			)
		);

		$normalized = array();
		foreach ( $user_ids as $user_id ) {
			$id = absint( $user_id );
			if ( $id > 0 ) {
				$normalized[ $id ] = $id;
			}
		}

		ksort( $normalized, SORT_NUMERIC );

		return array_values( $normalized );
	}

	/**
	 * Normalize positive integer IDs without accepting arbitrary scalar coercion.
	 *
	 * @param mixed $values Candidate IDs.
	 * @return array<int,int>
	 */
	private function positive_ids( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$ids = array();
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$id = $value;
			} elseif (
				is_string( $value )
				&& 1 === preg_match( '/^[1-9][0-9]*$/D', $value )
			) {
				$id = (int) $value;
			} else {
				continue;
			}

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		ksort( $ids, SORT_NUMERIC );

		return array_values( $ids );
	}
}

// EOF: src/Recipient/AudienceRequestBuilder.php.
