<?php
/**
 * File: src/Recipient/NamedListMemberType.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

use InvalidArgumentException;

/**
 * Persisted member types supported by named lists.
 */
enum NamedListMemberType: string {
	case User       = 'user';
	case Subscriber = 'subscriber';

	/**
	 * Build the canonical persisted membership key.
	 *
	 * @param int $entity_id WordPress user or subscriber row ID.
	 * @return string
	 * @throws InvalidArgumentException When the entity ID is invalid.
	 */
	public function member_key( int $entity_id ): string {
		if ( $entity_id < 1 ) {
			throw new InvalidArgumentException( 'Named-list member ID must be positive.' );
		}

		return $this->value . ':' . $entity_id;
	}
}

// EOF: src/Recipient/NamedListMemberType.php.
