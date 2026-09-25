<?php
/**
 * File: src/Plugin.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier;

use ArgentWolf\PostNotifier\Admin\VerificationProviderNotice;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Mail\WpMailTransport;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationMailer;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupProcessor;
use ArgentWolf\PostNotifier\Subscriber\RateLimitStore;
use ArgentWolf\PostNotifier\Subscriber\SignupRateLimiter;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\WordPressConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\WordPressRateLimitStore;
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
				SchemaMigrator::class,
				static fn (): SchemaMigrator => new SchemaMigrator()
			);
			$container->set(
				EmailIdentity::class,
				static fn (): EmailIdentity => new EmailIdentity()
			);
			$container->set(
				SubscriberRepository::class,
				static fn (): SubscriberRepository => new SubscriberRepository()
			);
			$container->set(
				SubscriberService::class,
				static function ( Container $services ): SubscriberService {
					$repository = $services->get( SubscriberRepository::class );
					$identity   = $services->get( EmailIdentity::class );
					if ( ! $repository instanceof SubscriberRepository ) {
						throw new LogicException( 'The subscriber repository is invalid.' );
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}

					return new SubscriberService( $repository, $identity );
				}
			);
			$container->set(
				RateLimitStore::class,
				static fn (): RateLimitStore => new WordPressRateLimitStore()
			);
			$container->set(
				SignupRateLimiter::class,
				static function ( Container $services ): SignupRateLimiter {
					$store    = $services->get( RateLimitStore::class );
					$identity = $services->get( EmailIdentity::class );
					if ( ! $store instanceof RateLimitStore ) {
						throw new LogicException( 'The rate-limit store is invalid.' );
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}

					return new SignupRateLimiter(
						$store,
						$identity,
						wp_salt( 'nonce' )
					);
				}
			);
			$container->set(
				MailTransport::class,
				static fn (): MailTransport => new WpMailTransport()
			);
			$container->set(
				ConfirmationLinkFactory::class,
				static fn (): ConfirmationLinkFactory =>
					new WordPressConfirmationLinkFactory()
			);
			$container->set(
				ConfirmationMailer::class,
				static function ( Container $services ): ConfirmationMailer {
					$transport = $services->get( MailTransport::class );
					if ( ! $transport instanceof MailTransport ) {
						throw new LogicException( 'The mail transport is invalid.' );
					}

					return new ConfirmationMailer(
						$transport,
						wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
					);
				}
			);
			$container->set(
				PublicSignupProcessor::class,
				static function ( Container $services ): PublicSignupProcessor {
					$subscriber_service = $services->get( SubscriberService::class );
					$rate_limiter       = $services->get( SignupRateLimiter::class );
					$links              = $services->get( ConfirmationLinkFactory::class );
					$mailer             = $services->get( ConfirmationMailer::class );

					if ( ! $subscriber_service instanceof SubscriberService ) {
						throw new LogicException( 'The subscriber service is invalid.' );
					}
					if ( ! $rate_limiter instanceof SignupRateLimiter ) {
						throw new LogicException( 'The signup rate limiter is invalid.' );
					}
					if ( ! $links instanceof ConfirmationLinkFactory ) {
						throw new LogicException( 'The confirmation link factory is invalid.' );
					}
					if ( ! $mailer instanceof ConfirmationMailer ) {
						throw new LogicException( 'The confirmation mailer is invalid.' );
					}

					return new PublicSignupProcessor(
						$subscriber_service,
						$rate_limiter,
						$links,
						$mailer
					);
				}
			);
			$container->set(
				UpgradeManager::class,
				static function ( Container $services ): UpgradeManager {
					$migrator = $services->get( SchemaMigrator::class );
					if ( ! $migrator instanceof SchemaMigrator ) {
						throw new LogicException(
							'The schema migrator is invalid.'
						);
					}

					return new UpgradeManager( $migrator );
				}
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
