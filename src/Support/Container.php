<?php
/**
 * File: src/Support/Container.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Support;

use Closure;
use RuntimeException;

/**
 * Minimal lazy service container for plugin-owned services.
 */
final class Container {
	/**
	 * Service factories.
	 *
	 * @var array<string, Closure(self): object>
	 */
	private array $factories = array();

	/**
	 * Resolved services.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Register a service factory.
	 *
	 * @param string  $service_id Service identifier.
	 * @param Closure $factory    Service factory.
	 * @return void
	 */
	public function set( string $service_id, Closure $factory ): void {
		$this->factories[ $service_id ] = $factory;
		unset( $this->instances[ $service_id ] );
	}

	/**
	 * Determine whether the service is registered.
	 *
	 * @param string $service_id Service identifier.
	 * @return bool
	 */
	public function has( string $service_id ): bool {
		return isset( $this->factories[ $service_id ] )
			|| isset( $this->instances[ $service_id ] );
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $service_id Service identifier.
	 * @return object
	 *
	 * @throws RuntimeException When the service is not registered.
	 */
	public function get( string $service_id ): object {
		if ( isset( $this->instances[ $service_id ] ) ) {
			return $this->instances[ $service_id ];
		}

		if ( ! isset( $this->factories[ $service_id ] ) ) {
			throw new RuntimeException(
				'ArgentWolf Post Notifier service is not registered.'
			);
		}

		$this->instances[ $service_id ] = ( $this->factories[ $service_id ] )( $this );

		return $this->instances[ $service_id ];
	}
}

// EOF: src/Support/Container.php.
