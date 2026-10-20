<?php
/**
 * File: src/Settings/NotificationDefaults.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Settings;

use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fail-closed site-wide send and audience defaults.
 *
 * All Subscribers is a dynamic audience, never a stored list or second consent
 * record. A named-list default references the existing typed-membership table.
 */
final class NotificationDefaults {
	/** Non-autoloaded site option. */
	public const OPTION = 'argentwolf_post_notifier_notification_defaults';

	/** All eligible opted-in addresses across the site's supported identity types. */
	public const AUDIENCE_ALL = 'all';

	/** One administrator-managed list. */
	public const AUDIENCE_LIST = 'list';

	/**
	 * Return a safe effective configuration. Missing options preserve Off.
	 *
	 * Corrupt options never enable sending or broaden the audience.
	 *
	 * @return array{send:bool,audience:string,list_id:int}
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, false );
		if ( false === $stored ) {
			return $this->defaults();
		}

		try {
			return $this->validate( $stored );
		} catch ( InvalidArgumentException ) {
			return array(
				'send'     => false,
				'audience' => 'invalid',
				'list_id'  => 0,
			);
		}
	}

	/**
	 * Return backward-compatible defaults for installations without the option.
	 *
	 * @return array{send:bool,audience:string,list_id:int}
	 */
	public function defaults(): array {
		return array(
			'send'     => false,
			'audience' => self::AUDIENCE_ALL,
			'list_id'  => 0,
		);
	}

	/**
	 * Strictly validate all fields without coercing truthy values.
	 *
	 * @param mixed $input Candidate site policy.
	 * @return array{send:bool,audience:string,list_id:int}
	 * @throws InvalidArgumentException On invalid fields or inconsistent values.
	 */
	public function validate( mixed $input ): array {
		if (
			! is_array( $input )
			|| 3 !== count( $input )
			|| ! array_key_exists( 'send', $input )
			|| ! array_key_exists( 'audience', $input )
			|| ! array_key_exists( 'list_id', $input )
		) {
			throw new InvalidArgumentException( 'Invalid site notification policy.' );
		}

		$send = $input['send'];
		if ( ! is_bool( $send ) ) {
			throw new InvalidArgumentException( 'Invalid site notification send default.' );
		}
		$audience = $input['audience'];
		$id       = $input['list_id'];
		if ( ! is_string( $audience ) || ! is_int( $id ) ) {
			throw new InvalidArgumentException( 'Invalid site notification audience default.' );
		}
		if (
			( self::AUDIENCE_ALL === $audience && 0 !== $id )
			|| ( self::AUDIENCE_LIST === $audience && 1 > $id )
			|| ! in_array( $audience, array( self::AUDIENCE_ALL, self::AUDIENCE_LIST ), true )
		) {
			throw new InvalidArgumentException( 'Invalid site notification audience default.' );
		}

		return array(
			'send'     => $send,
			'audience' => $audience,
			'list_id'  => $id,
		);
	}

	/**
	 * Save an approved policy. A missing list must never broaden to All.
	 *
	 * @param mixed               $input Candidate policy.
	 * @param NamedListRepository $lists Named list repository.
	 * @return void
	 * @throws InvalidArgumentException On invalid input or a nonexistent list.
	 */
	public function save( mixed $input, NamedListRepository $lists ): void {
		$config = $this->validate( $input );
		if (
			self::AUDIENCE_LIST === $config['audience']
			&& null === $lists->find( $config['list_id'] )
		) {
			throw new InvalidArgumentException( 'Default notification list does not exist.' );
		}
		update_option( self::OPTION, $config, false );
	}

	/**
	 * Resolve empty post audience targets using this site's saved audience.
	 *
	 * Exclusions are not positive targets: an author can exclude individual
	 * members from All Subscribers without replacing the default audience.
	 *
	 * @param array<string,mixed> $config Sanitized post audience configuration.
	 * @param NamedListRepository $lists  Named list repository.
	 * @return array<string,mixed> Effective configuration with a dynamic source flag.
	 * @throws RuntimeException When the configured default list is unavailable.
	 */
	public function resolve_audience( array $config, NamedListRepository $lists ): array {
		if ( true === ( $config['include_all_subscribers'] ?? false ) ) {
			// Explicit All Subscribers overrides a named-list default. Preserve exclusions.
			foreach (
				array(
					'role_slugs',
					'named_list_ids',
					'included_user_ids',
					'included_subscriber_ids',
				)
				as $key
			) {
				$config[ $key ] = array();
			}
			return $config;
		}
		$config['include_all_subscribers'] = false;
		foreach (
			array( 'role_slugs', 'named_list_ids', 'included_user_ids', 'included_subscriber_ids' )
			as $key
		) {
			if ( ! empty( $config[ $key ] ) ) {
				return $config;
			}
		}

		$policy = $this->get();
		if ( self::AUDIENCE_ALL === $policy['audience'] ) {
			$config['include_all_subscribers'] = true;
			return $config;
		}
		if (
			self::AUDIENCE_LIST !== $policy['audience']
			|| null === $lists->find( $policy['list_id'] )
		) {
			throw new RuntimeException( 'Default notification audience is unavailable.' );
		}
		$config['named_list_ids'] = array( $policy['list_id'] );
		return $config;
	}
}

// EOF: src/Settings/NotificationDefaults.php.
