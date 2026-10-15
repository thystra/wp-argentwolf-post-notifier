<?php
/**
 * File: src/Plugin.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier;

use ArgentWolf\PostNotifier\Admin\DeliveryStatusPage;
use ArgentWolf\PostNotifier\Admin\NamedListAdminPage;
use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Admin\SubscriberAdminPage;
use ArgentWolf\PostNotifier\Admin\SubscriberAdminRepository;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvExporter;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImporter;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportMailer;
use ArgentWolf\PostNotifier\Admin\SubscriberCsvImportPage;
use ArgentWolf\PostNotifier\Admin\UserNotificationPreferenceProfile;
use ArgentWolf\PostNotifier\Admin\VerificationProviderNotice;
use ArgentWolf\PostNotifier\Audit\AuditRepository;
use ArgentWolf\PostNotifier\Audit\AuditService;
use ArgentWolf\PostNotifier\Campaign\CampaignAudienceSnapshot;
use ArgentWolf\PostNotifier\Campaign\CampaignPreparation;
use ArgentWolf\PostNotifier\Campaign\CampaignQueueGate;
use ArgentWolf\PostNotifier\Campaign\CampaignRecipientRepository;
use ArgentWolf\PostNotifier\Campaign\CampaignRenderedBodyFinalizer;
use ArgentWolf\PostNotifier\Campaign\RecipientLinkFactory;
use ArgentWolf\PostNotifier\Campaign\RecipientMessageBuilder;
use ArgentWolf\PostNotifier\Campaign\RecipientUnsubscribeController;
use ArgentWolf\PostNotifier\Campaign\CampaignDiagnostics;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Campaign\PublicationObserver;
use ArgentWolf\PostNotifier\Content\EmailCutoffBlock;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailExcerptSettings;
use ArgentWolf\PostNotifier\Content\EmailMoreSettings;
use ArgentWolf\PostNotifier\Content\EmailNamedTemplateLibrary;
use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Content\EmailTemplatePreview;
use ArgentWolf\PostNotifier\Content\EmailTemplateTestMailer;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\DataCleanup;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Delivery\DeliveryScheduler;
use ArgentWolf\PostNotifier\Delivery\QueueCommand;
use ArgentWolf\PostNotifier\Delivery\QueueRepository;
use ArgentWolf\PostNotifier\Delivery\QueueWorker;
use ArgentWolf\PostNotifier\Delivery\RecipientEligibilityRecheck;
use ArgentWolf\PostNotifier\Editor\EditorAssets;
use ArgentWolf\PostNotifier\Editor\EditorAudienceEstimate;
use ArgentWolf\PostNotifier\Editor\EditorContactLookup;
use ArgentWolf\PostNotifier\Editor\EditorTemplateCatalog;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Health\PublicationSiteHealth;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Mail\WpMailTransport;
use ArgentWolf\PostNotifier\Recipient\AudienceRequestBuilder;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationController;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationMailer;
use ArgentWolf\PostNotifier\Subscriber\ManageSubscriptionController;
use ArgentWolf\PostNotifier\Subscriber\ManageSubscriptionLinkFactory;
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
use ArgentWolf\PostNotifier\Subscriber\WordPressManageSubscriptionLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\WordPressRateLimitStore;
use ArgentWolf\PostNotifier\Support\Container;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
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
				CampaignRepository::class,
				static fn (): CampaignRepository => new CampaignRepository()
			);
			$container->set(
				PublicationSiteHealth::class,
				static fn (): PublicationSiteHealth => new PublicationSiteHealth()
			);
			$container->set(
				EmailContentSelector::class,
				static fn (): EmailContentSelector => new EmailContentSelector()
			);
			$container->set(
				EmailContentRenderer::class,
				static function ( Container $services ): EmailContentRenderer {
					$selector = $services->get( EmailContentSelector::class );
					if ( ! $selector instanceof EmailContentSelector ) {
						throw new LogicException( 'The content selector is invalid.' );
					}
					return new EmailContentRenderer( $selector );
				}
			);
			$container->set(
				EmailTemplateSettings::class,
				static fn (): EmailTemplateSettings => new EmailTemplateSettings()
			);
			$container->set(
				EmailNamedTemplateLibrary::class,
				static function ( Container $services ): EmailNamedTemplateLibrary {
					$settings = $services->get( EmailTemplateSettings::class );
					if ( ! $settings instanceof EmailTemplateSettings ) {
						throw new LogicException( 'The template settings validator is invalid.' );
					}
					return new EmailNamedTemplateLibrary( $settings );
				}
			);
			$container->set(
				EmailExcerptSettings::class,
				static fn (): EmailExcerptSettings => new EmailExcerptSettings()
			);
			$container->set(
				EmailMoreSettings::class,
				static fn (): EmailMoreSettings => new EmailMoreSettings()
			);
			$container->set(
				EmailTemplateComposer::class,
				static function ( Container $services ): EmailTemplateComposer {
					$renderer = $services->get( EmailContentRenderer::class );
					if ( ! $renderer instanceof EmailContentRenderer ) {
						throw new LogicException( 'The email fragment renderer is invalid.' );
					}
					$settings = $services->get( EmailTemplateSettings::class );
					if ( ! $settings instanceof EmailTemplateSettings ) {
						throw new LogicException( 'The email template settings are invalid.' );
					}
					return new EmailTemplateComposer( $renderer, $settings );
				}
			);
			$container->set(
				EmailTemplatePreview::class,
				static function ( Container $services ): EmailTemplatePreview {
					$composer = $services->get( EmailTemplateComposer::class );
					if ( ! $composer instanceof EmailTemplateComposer ) {
						throw new LogicException( 'The email template composer is invalid.' );
					}
					$excerpt_settings = $services->get( EmailExcerptSettings::class );
					if ( ! $excerpt_settings instanceof EmailExcerptSettings ) {
						throw new LogicException( 'The excerpt settings are invalid.' );
					}
					$more_settings = $services->get( EmailMoreSettings::class );
					if ( ! $more_settings instanceof EmailMoreSettings ) {
						throw new LogicException( 'The More-block settings are invalid.' );
					}
					return new EmailTemplatePreview( $composer, $excerpt_settings, $more_settings );
				}
			);
			$container->set(
				EmailTemplateTestMailer::class,
				static function ( Container $services ): EmailTemplateTestMailer {
					$preview   = $services->get( EmailTemplatePreview::class );
					$transport = $services->get( MailTransport::class );
					if ( ! $preview instanceof EmailTemplatePreview ) {
						throw new LogicException( 'The template preview service is invalid.' );
					}
					if ( ! $transport instanceof MailTransport ) {
						throw new LogicException( 'The mail transport is invalid.' );
					}
					return new EmailTemplateTestMailer( $preview, $transport );
				}
			);
			$container->set(
				NotificationTemplateSettingsPage::class,
				static function ( Container $services ): NotificationTemplateSettingsPage {
					$settings = $services->get( EmailTemplateSettings::class );
					if ( ! $settings instanceof EmailTemplateSettings ) {
						throw new LogicException( 'The email template settings are invalid.' );
					}
					$preview = $services->get( EmailTemplatePreview::class );
					if ( ! $preview instanceof EmailTemplatePreview ) {
						throw new LogicException( 'The template preview service is invalid.' );
					}
					$mailer = $services->get( EmailTemplateTestMailer::class );
					if ( ! $mailer instanceof EmailTemplateTestMailer ) {
						throw new LogicException( 'The test-mail service is invalid.' );
					}
					$excerpt_settings = $services->get( EmailExcerptSettings::class );
					if ( ! $excerpt_settings instanceof EmailExcerptSettings ) {
						throw new LogicException( 'The excerpt settings are invalid.' );
					}
					$more_settings = $services->get( EmailMoreSettings::class );
					if ( ! $more_settings instanceof EmailMoreSettings ) {
						throw new LogicException( 'The More-block settings are invalid.' );
					}
					$named = $services->get( EmailNamedTemplateLibrary::class );
					if ( ! $named instanceof EmailNamedTemplateLibrary ) {
						throw new LogicException( 'The named template library is invalid.' );
					}
					return new NotificationTemplateSettingsPage(
						$settings,
						$preview,
						$mailer,
						$excerpt_settings,
						$more_settings,
						$named
					);
				}
			);
			$container->set(
				CampaignDiagnostics::class,
				static fn (): CampaignDiagnostics => new CampaignDiagnostics()
			);
			$container->set(
				PublicationObserver::class,
				static function ( Container $services ): PublicationObserver {
					$campaigns = $services->get( CampaignRepository::class );
					if ( ! $campaigns instanceof CampaignRepository ) {
						throw new LogicException( 'The campaign repository is invalid.' );
					}

					return new PublicationObserver( $campaigns );
				}
			);
			$container->set(
				EmailIdentity::class,
				static fn (): EmailIdentity => new EmailIdentity()
			);
			$container->set(
				AuditRepository::class,
				static fn (): AuditRepository => new AuditRepository()
			);
			$container->set(
				AuditService::class,
				static function ( Container $services ): AuditService {
					$repository = $services->get( AuditRepository::class );
					if ( ! $repository instanceof AuditRepository ) {
						throw new LogicException( 'The audit repository is invalid.' );
					}

					return new AuditService( $repository );
				}
			);
			$container->set(
				SuppressionRepository::class,
				static fn (): SuppressionRepository => new SuppressionRepository()
			);
			$container->set(
				SuppressionService::class,
				static function ( Container $services ): SuppressionService {
					$repository = $services->get( SuppressionRepository::class );
					$identity   = $services->get( EmailIdentity::class );
					$audit      = $services->get( AuditService::class );
					if ( ! $repository instanceof SuppressionRepository ) {
						throw new LogicException(
							'The suppression repository is invalid.'
						);
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}
					if ( ! $audit instanceof AuditService ) {
						throw new LogicException( 'The audit service is invalid.' );
					}

					return new SuppressionService( $repository, $identity, $audit );
				}
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
				NamedListRepository::class,
				static fn (): NamedListRepository => new NamedListRepository()
			);
			$container->set(
				RegisteredUserPreferenceRepository::class,
				static fn (): RegisteredUserPreferenceRepository =>
					new RegisteredUserPreferenceRepository()
			);
			$container->set(
				AudienceRequestBuilder::class,
				static fn (): AudienceRequestBuilder => new AudienceRequestBuilder()
			);
			$container->set(
				AudienceResolver::class,
				static function ( Container $services ): AudienceResolver {
					$lists       = $services->get( NamedListRepository::class );
					$preferences = $services->get( RegisteredUserPreferenceRepository::class );
					$eligibility = $services->get( RegisteredUserEligibility::class );
					$subscribers = $services->get( SubscriberRepository::class );
					$suppression = $services->get( SuppressionService::class );
					$identity    = $services->get( EmailIdentity::class );

					if ( ! $lists instanceof NamedListRepository ) {
						throw new LogicException( 'The named-list repository is invalid.' );
					}
					if ( ! $preferences instanceof RegisteredUserPreferenceRepository ) {
						throw new LogicException(
							'The registered-user preference repository is invalid.'
						);
					}
					if ( ! $eligibility instanceof RegisteredUserEligibility ) {
						throw new LogicException(
							'The registered-user eligibility policy is invalid.'
						);
					}
					if ( ! $subscribers instanceof SubscriberRepository ) {
						throw new LogicException( 'The subscriber repository is invalid.' );
					}
					if ( ! $suppression instanceof SuppressionService ) {
						throw new LogicException( 'The suppression service is invalid.' );
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}

					return new AudienceResolver(
						$lists,
						$preferences,
						$eligibility,
						$subscribers,
						$suppression,
						$identity
					);
				}
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
				NamedListAdminPage::class,
				static function ( Container $services ): NamedListAdminPage {
					$repository  = $services->get( NamedListRepository::class );
					$identity    = $services->get( EmailIdentity::class );
					$suppression = $services->get( SuppressionService::class );
					$audit       = $services->get( AuditService::class );
					if ( ! $repository instanceof NamedListRepository ) {
						throw new LogicException(
							'The named-list repository is invalid.'
						);
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}
					if ( ! $suppression instanceof SuppressionService ) {
						throw new LogicException(
							'The suppression service is invalid.'
						);
					}
					if ( ! $audit instanceof AuditService ) {
						throw new LogicException( 'The audit service is invalid.' );
					}

					return new NamedListAdminPage(
						$repository,
						$identity,
						$suppression,
						$audit
					);
				}
			);
			$container->set(
				SubscriberAdminPage::class,
				static function ( Container $services ): SubscriberAdminPage {
					$repository  = $services->get( SubscriberAdminRepository::class );
					$exporter    = $services->get( SubscriberCsvExporter::class );
					$suppression = $services->get( SuppressionService::class );
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
					if ( ! $suppression instanceof SuppressionService ) {
						throw new LogicException(
							'The suppression service is invalid.'
						);
					}

					return new SubscriberAdminPage(
						$repository,
						$exporter,
						$suppression
					);
				}
			);
			$container->set(
				SubscriberCsvImportPage::class,
				static function ( Container $services ): SubscriberCsvImportPage {
					$subscriber_service = $services->get( SubscriberService::class );
					$identity           = $services->get( EmailIdentity::class );
					$links              = $services->get( ConfirmationLinkFactory::class );
					$transport          = $services->get( MailTransport::class );
					if ( ! $subscriber_service instanceof SubscriberService ) {
						throw new LogicException( 'The subscriber service is invalid.' );
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}
					if ( ! $links instanceof ConfirmationLinkFactory ) {
						throw new LogicException(
							'The confirmation link factory is invalid.'
						);
					}
					if ( ! $transport instanceof MailTransport ) {
						throw new LogicException( 'The mail transport is invalid.' );
					}

					return new SubscriberCsvImportPage(
						new SubscriberCsvImporter(
							$subscriber_service,
							$identity,
							$links,
							new SubscriberCsvImportMailer(
								$transport,
								wp_specialchars_decode(
									get_bloginfo( 'name' ),
									ENT_QUOTES
								)
							)
						)
					);
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
					$repository  = $services->get( SubscriberRepository::class );
					$identity    = $services->get( EmailIdentity::class );
					$suppression = $services->get( SuppressionService::class );
					if ( ! $repository instanceof SubscriberRepository ) {
						throw new LogicException( 'The subscriber repository is invalid.' );
					}
					if ( ! $identity instanceof EmailIdentity ) {
						throw new LogicException( 'The email identity service is invalid.' );
					}
					if ( ! $suppression instanceof SuppressionService ) {
						throw new LogicException(
							'The suppression service is invalid.'
						);
					}

					return new SubscriberService(
						$repository,
						$identity,
						$suppression
					);
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
				ManageSubscriptionLinkFactory::class,
				static fn (): ManageSubscriptionLinkFactory =>
					new WordPressManageSubscriptionLinkFactory()
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
					$manage_links       = $services->get(
						ManageSubscriptionLinkFactory::class
					);
					if ( ! $subscriber_service instanceof SubscriberService ) {
						throw new LogicException( 'The subscriber service is invalid.' );
					}
					if ( ! $manage_links instanceof ManageSubscriptionLinkFactory ) {
						throw new LogicException(
							'The management link factory is invalid.'
						);
					}

					return new ConfirmationController(
						$subscriber_service,
						$manage_links
					);
				}
			);
			$container->set(
				ManageSubscriptionController::class,
				static function ( Container $services ): ManageSubscriptionController {
					$subscriber_service = $services->get( SubscriberService::class );
					$suppression        = $services->get( SuppressionService::class );
					if ( ! $subscriber_service instanceof SubscriberService ) {
						throw new LogicException( 'The subscriber service is invalid.' );
					}
					if ( ! $suppression instanceof SuppressionService ) {
						throw new LogicException(
							'The suppression service is invalid.'
						);
					}

					return new ManageSubscriptionController(
						$subscriber_service,
						$suppression
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
				EmailCutoffBlock::class,
				static fn (): EmailCutoffBlock => new EmailCutoffBlock()
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
				EditorContactLookup::class,
				static fn (): EditorContactLookup => new EditorContactLookup()
			);
			$container->set(
				EditorAudienceEstimate::class,
				static function ( Container $services ): EditorAudienceEstimate {
					$requests = $services->get( AudienceRequestBuilder::class );
					if ( ! $requests instanceof AudienceRequestBuilder ) {
						throw new LogicException(
							'The audience request builder is invalid.'
						);
					}

					return new EditorAudienceEstimate(
						$requests,
						static fn (): object => $services->get( AudienceResolver::class )
					);
				}
			);
			$container->set(
				EditorTemplateCatalog::class,
				static function ( Container $services ): EditorTemplateCatalog {
					$named = $services->get( EmailNamedTemplateLibrary::class );
					if ( ! $named instanceof EmailNamedTemplateLibrary ) {
						throw new LogicException( 'The named template library is invalid.' );
					}
					return new EditorTemplateCatalog( $named );
				}
			);
			$container->set(
				EditorAssets::class,
				static function ( Container $services ): EditorAssets {
					$lists     = $services->get( NamedListRepository::class );
					$templates = $services->get( EditorTemplateCatalog::class );
					if ( ! $lists instanceof NamedListRepository ) {
						throw new LogicException( 'The named-list repository is invalid.' );
					}
					if ( ! $templates instanceof EditorTemplateCatalog ) {
						throw new LogicException( 'The editor template catalog is invalid.' );
					}

					return new EditorAssets(
						$lists,
						$templates,
						static fn (): object => $services->get( VerificationProvider::class )
					);
				}
			);
			$container->set(
				PostNotificationMeta::class,
				static fn (): PostNotificationMeta => new PostNotificationMeta()
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

			self::register_delivery_services( $container );

			self::$instance = new self( $container );
		}

		return self::$instance;
	}

	/**
	 * Register Beta.3 campaign preparation and delivery services.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private static function register_delivery_services( Container $container ): void {
		$container->set(
			CampaignRecipientRepository::class,
			static fn (): CampaignRecipientRepository => new CampaignRecipientRepository()
		);
		$container->set(
			RecipientLinkFactory::class,
			static fn (): RecipientLinkFactory => new RecipientLinkFactory()
		);
		$container->set(
			QueueRepository::class,
			static fn (): QueueRepository => new QueueRepository()
		);
		$container->set(
			CampaignAudienceSnapshot::class,
			static fn ( Container $services ): CampaignAudienceSnapshot =>
				new CampaignAudienceSnapshot(
					self::typed( $services, AudienceResolver::class ),
					self::typed( $services, AudienceRequestBuilder::class ),
					self::typed( $services, CampaignRecipientRepository::class )
				)
		);
		$container->set(
			CampaignQueueGate::class,
			static fn ( Container $services ): CampaignQueueGate => new CampaignQueueGate(
				self::typed( $services, EmailTemplateComposer::class ),
				self::typed( $services, VerificationProvider::class )
			)
		);
		$container->set(
			CampaignPreparation::class,
			static fn ( Container $services ): CampaignPreparation => new CampaignPreparation(
				self::typed( $services, CampaignRepository::class ),
				new CampaignRenderedBodyFinalizer(),
				self::typed( $services, CampaignQueueGate::class ),
				self::typed( $services, CampaignAudienceSnapshot::class ),
				self::typed( $services, AuditService::class )
			)
		);
		$container->set(
			RecipientEligibilityRecheck::class,
			static fn ( Container $services ): RecipientEligibilityRecheck =>
				new RecipientEligibilityRecheck(
					self::typed( $services, RegisteredUserEligibility::class ),
					self::typed( $services, RegisteredUserPreferenceRepository::class ),
					self::typed( $services, SubscriberRepository::class ),
					self::typed( $services, SuppressionService::class ),
					self::typed( $services, EmailIdentity::class )
				)
		);
		$container->set(
			QueueWorker::class,
			static fn ( Container $services ): QueueWorker => new QueueWorker(
				self::typed( $services, QueueRepository::class ),
				self::typed( $services, CampaignRepository::class ),
				self::typed( $services, CampaignAudienceSnapshot::class ),
				self::typed( $services, RecipientEligibilityRecheck::class ),
				new RecipientMessageBuilder(
					self::typed( $services, EmailTemplateComposer::class )
				),
				self::typed( $services, RecipientLinkFactory::class ),
				self::typed( $services, MailTransport::class ),
				self::typed( $services, AuditService::class )
			)
		);
		$container->set(
			DeliveryScheduler::class,
			static fn ( Container $services ): DeliveryScheduler => new DeliveryScheduler(
				static fn (): object => $services->get( CampaignPreparation::class ),
				static fn (): object => $services->get( QueueWorker::class ),
				static fn (): object => $services->get( QueueRepository::class ),
				static fn (): object => $services->get( CampaignRepository::class )
			)
		);
		$container->set(
			DeliveryStatusPage::class,
			static fn ( Container $services ): DeliveryStatusPage => new DeliveryStatusPage(
				static fn (): object => $services->get( QueueRepository::class ),
				static fn (): object => $services->get( QueueWorker::class )
			)
		);
		$container->set(
			QueueCommand::class,
			static fn ( Container $services ): QueueCommand => new QueueCommand(
				static fn (): object => $services->get( QueueWorker::class ),
				static fn (): object => $services->get( QueueRepository::class ),
				static fn (): object => $services->get( CampaignPreparation::class )
			)
		);
		$container->set(
			RecipientUnsubscribeController::class,
			static fn ( Container $services ): RecipientUnsubscribeController =>
				new RecipientUnsubscribeController(
					self::typed( $services, CampaignRecipientRepository::class ),
					self::typed( $services, SuppressionService::class ),
					self::typed( $services, SubscriberService::class ),
					new WordPressManageSubscriptionLinkFactory()
				)
		);
	}

	/**
	 * Resolve one container service and assert its type.
	 *
	 * @param Container $services Container.
	 * @param string    $id       Service class name.
	 * @return object
	 * @throws LogicException When the service has an unexpected type.
	 */
	private static function typed( Container $services, string $id ): object {
		$service = $services->get( $id );
		if ( ! $service instanceof $id ) {
			throw new LogicException( 'ArgentWolf Post Notifier service has an invalid type.' );
		}

		return $service;
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
				PostNotificationMeta::class,
				CampaignDiagnostics::class,
				PublicationSiteHealth::class,
				PublicationObserver::class,
				EditorAudienceEstimate::class,
				EditorContactLookup::class,
				EditorAssets::class,
				ConfirmationController::class,
				ManageSubscriptionController::class,
				RecipientUnsubscribeController::class,
				DeliveryScheduler::class,
				SubscriberAdminPage::class,
				NotificationTemplateSettingsPage::class,
				SubscriberCsvImportPage::class,
				DeliveryStatusPage::class,
				NamedListAdminPage::class,
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
						EmailCutoffBlock::class,
					)
				);
			}
		);

		$command = $this->container->get( QueueCommand::class );
		if ( $command instanceof QueueCommand ) {
			QueueCommand::register_with_cli( $command );
		}

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
