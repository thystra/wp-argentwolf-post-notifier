<?php
/**
 * File: src/Plugin.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier;

use ArgentWolf\PostNotifier\Admin\VerificationProviderNotice;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Support\Container;
use ArgentWolf\PostNotifier\Verification\ArgentWolfEmailVerificationProvider;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderResolver;
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
			$container->set(
				ArgentWolfEmailVerificationProvider::class,
				static fn (): ArgentWolfEmailVerificationProvider =>
					new ArgentWolfEmailVerificationProvider()
			);
			$container->set(
				VerificationProvider::class,
				static function ( Container $services ): VerificationProvider {
					$default_provider = $services->get(
						ArgentWolfEmailVerificationProvider::class
					);
					if ( ! $default_provider instanceof VerificationProvider ) {
						throw new LogicException(
							'The default verification provider is invalid.'
						);
					}

					return ( new VerificationProviderResolver( $default_provider ) )->resolve();
				}
			);
			$container->set(
				RegisteredUserEligibility::class,
				static function ( Container $services ): RegisteredUserEligibility {
					$provider = $services->get( VerificationProvider::class );
					if ( ! $provider instanceof VerificationProvider ) {
						throw new LogicException(
							'The verification provider is invalid.'
						);
					}

					return new RegisteredUserEligibility( $provider );
				}
			);
			$container->set(
				VerificationProviderNotice::class,
				static fn ( Container $services ): VerificationProviderNotice =>
					new VerificationProviderNotice(
						static fn (): object => $services->get(
							VerificationProvider::class
						)
					)
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
			VerificationProviderNotice::class,
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
