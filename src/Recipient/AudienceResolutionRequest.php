<?php
/**
 * File: src/Recipient/AudienceResolutionRequest.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

/**
 * Immutable inputs for the Alpha.5 audience-policy layer.
 *
 * Role expansion remains the caller's responsibility. This request receives the
 * resulting user IDs, named-list IDs, and explicit typed include/exclude IDs.
 */
final class AudienceResolutionRequest {
	/**
	 * Users contributed by role expansion.
	 *
	 * @var array<int,int>
	 */
	private readonly array $role_user_ids;

	/**
	 * Named lists to expand.
	 *
	 * @var array<int,int>
	 */
	private readonly array $named_list_ids;

	/**
	 * Explicitly included WordPress users.
	 *
	 * @var array<int,int>
	 */
	private readonly array $included_user_ids;

	/**
	 * Explicitly included standalone subscribers.
	 *
	 * @var array<int,int>
	 */
	private readonly array $included_subscriber_ids;

	/**
	 * Explicitly excluded WordPress users.
	 *
	 * @var array<int,int>
	 */
	private readonly array $excluded_user_ids;

	/**
	 * Explicitly excluded standalone subscribers.
	 *
	 * @var array<int,int>
	 */
	private readonly array $excluded_subscriber_ids;

	/**
	 * Whether site_default users are opted in for this resolution.
	 *
	 * @var bool
	 */
	private readonly bool $site_default_user_subscribed;

	/**
	 * Construct one request.
	 *
	 * @param array<int,int> $role_user_ids                Users contributed by roles.
	 * @param array<int,int> $named_list_ids               Named lists to expand.
	 * @param array<int,int> $included_user_ids            Explicitly included users.
	 * @param array<int,int> $included_subscriber_ids      Explicitly included subscribers.
	 * @param array<int,int> $excluded_user_ids            Explicitly excluded users.
	 * @param array<int,int> $excluded_subscriber_ids      Explicitly excluded subscribers.
	 * @param bool           $site_default_user_subscribed Whether site_default users opt in.
	 */
	public function __construct(
		array $role_user_ids = array(),
		array $named_list_ids = array(),
		array $included_user_ids = array(),
		array $included_subscriber_ids = array(),
		array $excluded_user_ids = array(),
		array $excluded_subscriber_ids = array(),
		bool $site_default_user_subscribed = false
	) {
		$this->role_user_ids                = self::normalize_ids( $role_user_ids );
		$this->named_list_ids               = self::normalize_ids( $named_list_ids );
		$this->included_user_ids            = self::normalize_ids( $included_user_ids );
		$this->included_subscriber_ids      = self::normalize_ids( $included_subscriber_ids );
		$this->excluded_user_ids            = self::normalize_ids( $excluded_user_ids );
		$this->excluded_subscriber_ids      = self::normalize_ids( $excluded_subscriber_ids );
		$this->site_default_user_subscribed = $site_default_user_subscribed;
	}

	/**
	 * Return role-expanded user IDs.
	 *
	 * @return array<int,int>
	 */
	public function role_user_ids(): array {
		return $this->role_user_ids;
	}

	/**
	 * Return named-list IDs.
	 *
	 * @return array<int,int>
	 */
	public function named_list_ids(): array {
		return $this->named_list_ids;
	}

	/**
	 * Return explicitly included user IDs.
	 *
	 * @return array<int,int>
	 */
	public function included_user_ids(): array {
		return $this->included_user_ids;
	}

	/**
	 * Return explicitly included standalone-subscriber IDs.
	 *
	 * @return array<int,int>
	 */
	public function included_subscriber_ids(): array {
		return $this->included_subscriber_ids;
	}

	/**
	 * Return explicitly excluded user IDs.
	 *
	 * @return array<int,int>
	 */
	public function excluded_user_ids(): array {
		return $this->excluded_user_ids;
	}

	/**
	 * Return explicitly excluded standalone-subscriber IDs.
	 *
	 * @return array<int,int>
	 */
	public function excluded_subscriber_ids(): array {
		return $this->excluded_subscriber_ids;
	}

	/**
	 * Determine whether site_default registered users are opted in for this request.
	 *
	 * @return bool
	 */
	public function site_default_user_subscribed(): bool {
		return $this->site_default_user_subscribed;
	}

	/**
	 * Normalize positive integer IDs into a deterministic unique list.
	 *
	 * @param array<int,mixed> $ids Candidate IDs.
	 * @return array<int,int>
	 */
	private static function normalize_ids( array $ids ): array {
		$normalized = array();

		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				continue;
			}

			$normalized[ $id ] = $id;
		}

		ksort( $normalized, SORT_NUMERIC );

		return array_values( $normalized );
	}
}

// EOF: src/Recipient/AudienceResolutionRequest.php.
