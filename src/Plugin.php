<?php
/**
 * File: src/Plugin.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Support\Container;
use LogicException;

/**
 * Plugin bootstrap and service registration.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Construct the plugin.
	 *
	 * @param Container $container Service container.
	 */
	private function __construct( private Container $container ) {
	}

	/**
	 * Return the plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			$container = new Container();

			$container->set(
				UpgradeManager::class,
				static fn (): UpgradeManager => new UpgradeManager()
			);

			self::$instance = new self( $container );
		}

		return self::$instance;
	}

	/**
	 * Register plugin services.
	 *
	 * @return void
	 *
	 * @throws LogicException When a configured service is not registerable.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$services = array(
			UpgradeManager::class,
		);

		foreach ( $services as $service_id ) {
			$service = $this->container->get( $service_id );

			if ( ! $service instanceof Registerable ) {
				throw new LogicException(
					'ArgentWolf Post Notifier service is not registerable.'
				);
			}

			$service->register();
		}

		$this->registered = true;
	}

	/**
	 * Return the service container for internal integration tests.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}
}

// EOF: src/Plugin.php.
