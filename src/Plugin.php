<?php
/**
 * File: src/Plugin.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier;

use ArgentWolf\PostNotifier\Admin\SubscriberAdminPage;
use ArgentWolf\PostNotifier\Admin\SubscriberAdminRepository;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvExporter;
use ArgentWolf\PostNotifier\Admin\UserNotificationPreferenceProfile;
use ArgentWolf\PostNotifier\Admin\VerificationProviderNotice;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\DataCleanup;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Mail\WpMailTransport;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationController;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationMailer;
use ArgentWolf\PostNotifier\Subscriber\PendingSubscriberCleanup;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupController;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupProcessor;
use ArgentWolf\PostNotifier\Subscriber\RateLimitStore;
use ArgentWolf\PostNotifier\Subscriber\SignupFormContext;
use ArgentWolf\PostNotifier\Subscriber\SignupRateLimiter;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscribeBlock;
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
				DataCleanup::class,
				static fn (): DataCleanup => new DataCleanup()
			);
			$container->set(
				SubscriberAdminRepository::class,
				static fn (): SubscriberAdminRepository => new SubscriberAdminRepository()
			);
			$container->set(
				RegisteredUserPreferenceRepository::class,
				static fn (): RegisteredUserPreferenceRepository =>
					new RegisteredUserPreferenceRepository()
			);
			$container->set(
				UserNotificationPreferenceProfile::class,
				static function ( Container $services ): UserNotificationPreferenceProfile {
					$preferences = $services->get( RegisteredUserPreferenceRepository::class );
					if ( ! $preferences instanceof RegisteredUserPreferenceRepository ) {
						throw new LogicException(
							'The registered-user preference repository is invalid.'
						);
					}

					return new UserNotificationPreferenceProfile( $preferences );
				}
			);
			$container->set(
				SubscriberCsvExporter::class,
				static function ( Container $services ): SubscriberCsvExporter {
					$repository = $services->get( SubscriberAdminRepository::class );
					if ( ! $repository instanceof SubscriberAdminRepository ) {
						throw new LogicException(
							'The subscriber administration repository is invalid.'
						);
					}

					return new SubscriberCsvExporter( $repository );
				}
			);
			$container->set(
				SubscriberAdminPage::class,
				static function ( Container $services ): SubscriberAdminPage {
					$repository = $services->get( SubscriberAdminRepository::class );
					$exporter   = $services->get( SubscriberCsvExporter::class );
					if ( ! $repository instanceof SubscriberAdminRepository ) {
						throw new LogicException(
							'The subscriber administration repository is invalid.'
						);
					}
					if ( ! $exporter instanceof SubscriberCsvExporter ) {
						throw new LogicException(
							'The subscriber CSV exporter is invalid.'
						);
					}

					return new SubscriberAdminPage( $repository, $exporter );
				}
			);
			$container->set(
				SubscriberRepository::class,
				static fn (): SubscriberRepository => new SubscriberRepository()
			);
			$container->set(
				PendingSubscriberCleanup::class,
				static function ( Container $services ): PendingSubscriberCleanup {
					$cleanup = $services->get( DataCleanup::class );
					if ( ! $cleanup instanceof DataCleanup ) {
						throw new LogicException( 'The data cleanup service is invalid.' );
					}

					return new PendingSubscriberCleanup( $cleanup );
				}
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
				ConfirmationController::class,
				static function ( Container $services ): ConfirmationController {
					$subscriber_service = $services->get( SubscriberService::class );
					if ( ! $subscriber_service instanceof SubscriberService ) {
						throw new LogicException( 'The subscriber service is invalid.' );
					}

					return new ConfirmationController( $subscriber_service );
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
				SignupFormContext::class,
				static fn (): SignupFormContext => new SignupFormContext( wp_salt( 'nonce' ) )
			);
			$container->set(
				PublicSignupController::class,
				static function ( Container $services ): PublicSignupController {
					$processor = $services->get( PublicSignupProcessor::class );
					$context   = $services->get( SignupFormContext::class );
					if ( ! $processor instanceof PublicSignupProcessor ) {
						throw new LogicException( 'The public signup processor is invalid.' );
					}
					if ( ! $context instanceof SignupFormContext ) {
						throw new LogicException( 'The signup form context is invalid.' );
					}

					return new PublicSignupController( $processor, $context );
				}
			);
			$container->set(
				SubscribeBlock::class,
				static function ( Container $services ): SubscribeBlock {
					$context = $services->get( SignupFormContext::class );
					if ( ! $context instanceof SignupFormContext ) {
						throw new LogicException( 'The signup form context is invalid.' );
					}

					return new SubscribeBlock( $context );
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

		$this->register_services(
			array(
				UpgradeManager::class,
				ConfirmationController::class,
				SubscriberAdminPage::class,
				UserNotificationPreferenceProfile::class,
				VerificationProviderNotice::class,
			)
		);

		add_action(
			'plugins_loaded',
			function (): void {
				$this->register_services(
					array(
						PendingSubscriberCleanup::class,
						PublicSignupController::class,
						SubscribeBlock::class,
					)
				);
			}
		);

		$this->registered = true;
	}

	/**
	 * Resolve and register a set of container services.
	 *
	 * @param array<int,string> $service_ids Service identifiers.
	 * @return void
	 *
	 * @throws LogicException When a configured service is not registerable.
	 */
	private function register_services( array $service_ids ): void {
		foreach ( $service_ids as $service_id ) {
			$service = $this->container->get( $service_id );

			if ( ! $service instanceof Registerable ) {
				throw new LogicException(
					'ArgentWolf Post Notifier service is not registerable.'
				);
			}

			$service->register();
		}
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
