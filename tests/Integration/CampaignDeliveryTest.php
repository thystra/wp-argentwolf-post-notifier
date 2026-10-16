<?php
/**
 * File: tests/Integration/CampaignDeliveryTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\DeliveryStatusPage;
use ArgentWolf\PostNotifier\Audit\AuditRepository;
use ArgentWolf\PostNotifier\Audit\AuditService;
use ArgentWolf\PostNotifier\Campaign\CampaignAudienceSnapshot;
use ArgentWolf\PostNotifier\Campaign\CampaignContentSnapshotStore;
use ArgentWolf\PostNotifier\Campaign\CampaignPreparation;
use ArgentWolf\PostNotifier\Campaign\CampaignQueueGate;
use ArgentWolf\PostNotifier\Campaign\CampaignRecipientRepository;
use ArgentWolf\PostNotifier\Campaign\CampaignRenderedBodyFinalizer;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Campaign\RecipientLinkFactory;
use ArgentWolf\PostNotifier\Campaign\RecipientMessageBuilder;
use ArgentWolf\PostNotifier\Campaign\RecipientToken;
use ArgentWolf\PostNotifier\Campaign\RecipientUnsubscribeController;
use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Delivery\DeliveryScheduler;
use ArgentWolf\PostNotifier\Delivery\QueueRepository;
use ArgentWolf\PostNotifier\Delivery\QueueWorker;
use ArgentWolf\PostNotifier\Delivery\RecipientEligibilityRecheck;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Privacy\PrivacyIntegration;
use ArgentWolf\PostNotifier\Privacy\PrivacyRepository;
use ArgentWolf\PostNotifier\Privacy\PrivacySettings;
use ArgentWolf\PostNotifier\Privacy\RetentionCleanup;
use ArgentWolf\PostNotifier\Database\DataCleanup;
use ArgentWolf\PostNotifier\Recipient\AudienceRequestBuilder;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use ArgentWolf\PostNotifier\Tracking\ClickController;
use ArgentWolf\PostNotifier\Tracking\ClickRepository;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderHealth;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Milestone 8-10: recipient finalization, audience snapshot, queue and worker.
 */
final class CampaignDeliveryTest extends WP_UnitTestCase {
	/**
	 * Verification status by user ID for the deterministic provider.
	 *
	 * @var array<int,VerificationStatus>
	 */
	private array $statuses = array();

	/**
	 * Whether the deterministic provider reports healthy.
	 *
	 * @var bool
	 */
	private bool $healthy = true;

	/**
	 * Recording transport.
	 *
	 * @var CampaignDeliveryRecordingTransport
	 */
	private CampaignDeliveryRecordingTransport $transport;

	/**
	 * Saved request method.
	 *
	 * @var string|null
	 */
	private ?string $request_method = null;

	/**
	 * Isolate plugin tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$isolated = array(
			$tables->campaign_recipients(),
			$tables->campaigns(),
			$tables->suppressions(),
			$tables->clicks(),
		);
		foreach ( $isolated as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
		// phpcs:enable
		$this->transport      = new CampaignDeliveryRecordingTransport();
		$this->request_method = $_SERVER['REQUEST_METHOD'] ?? null;
	}

	/**
	 * Restore request globals and cron state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null === $this->request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}
		$_GET  = array();
		$_POST = array();
		DeliveryScheduler::unschedule_all();
		delete_option( PrivacySettings::OPTION );
		parent::tear_down();
	}

	public function test_container_registers_delivery_services(): void {
		$container = Plugin::instance()->container();
		foreach (
			array(
				CampaignPreparation::class,
				QueueWorker::class,
				DeliveryScheduler::class,
				RecipientUnsubscribeController::class,
			) as $id
		) {
			self::assertTrue( $container->has( $id ), $id );
		}
		self::assertNotFalse(
			has_action( 'admin_post_nopriv_' . RecipientLinkFactory::ONE_CLICK_ACTION )
		);
	}

	public function test_publication_freezes_audience_and_defers_without_sending(): void {
		$subscriber = $this->subscriber( 'reader@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$campaign   = $this->campaign_for( $post_id );

		self::assertSame( CampaignRepository::STATUS_BUILDING, $campaign['status'] );
		self::assertNotNull( $campaign['audience_snapshot_json'] );
		self::assertSame( '', $campaign['html_body'] );
		self::assertNotFalse(
			wp_next_scheduled( DeliveryScheduler::PREPARE_HOOK, array( (int) $campaign['id'] ) )
		);
		self::assertSame( array(), $this->transport->messages );
		self::assertSame( 0, $this->recipient_count( (int) $campaign['id'] ) );

		// Later audience edits cannot change the frozen campaign audience.
		update_post_meta(
			$post_id,
			PostNotificationMeta::AUDIENCE_CONFIG_KEY,
			array( 'included_subscriber_ids' => array( 999 ) )
		);
		self::assertSame(
			array( $subscriber ),
			( new CampaignAudienceSnapshot() )->read_config( (int) $campaign['id'] )['included_subscriber_ids']
		);
	}

	public function test_preparation_resolves_snapshot_and_counts_agree(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		( new RegisteredUserPreferenceRepository() )->set( $user_id, RegisteredUserPreference::Subscribed );
		$pending_user = self::factory()->user->create( array( 'user_email' => 'pending@example.com' ) );
		( new RegisteredUserPreferenceRepository() )->set( $pending_user, RegisteredUserPreference::Subscribed );
		$this->statuses = array(
			$user_id      => VerificationStatus::Verified,
			$pending_user => VerificationStatus::Pending,
		);
		$subscriber     = $this->subscriber( 'reader@example.com' );
		$shared         = $this->subscriber( 'member@example.com' );
		$suppressed     = $this->subscriber( 'blocked@example.com' );
		$this->suppression()->suppress(
			'blocked@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN
		);
		$post_id = $this->publish(
			array(
				'included_user_ids'       => array( $user_id, $pending_user ),
				'included_subscriber_ids' => array( $subscriber, $shared, $suppressed ),
			)
		);
		$id      = (int) $this->campaign_for( $post_id )['id'];

		$outcome = $this->preparation()->prepare( $id );
		self::assertSame( CampaignPreparation::RESULT_QUEUED, $outcome['result'] );

		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_QUEUED, $campaign['status'] );
		self::assertSame( 2, (int) $campaign['recipient_count'] );
		self::assertSame( 2, $this->recipient_count( $id ) );
		$skips = ( new CampaignAudienceSnapshot() )->skip_counts( $id );
		self::assertSame( 1, $skips['unverified'] ?? 0 );
		self::assertSame( 1, $skips['suppressed'] ?? 0 );
		self::assertSame( 1, $skips['duplicate'] ?? 0 );
		self::assertSame( array_sum( $skips ), (int) $campaign['skipped_count'] );
		self::assertStringNotContainsString( 'Unsubscribe', $campaign['html_body'] );

		// Idempotent replay.
		self::assertSame(
			CampaignPreparation::RESULT_SKIPPED,
			$this->preparation()->prepare( $id )['result']
		);
		self::assertSame( 2, $this->recipient_count( $id ) );
	}

	public function test_gate_fails_closed_for_unpublished_post_and_defers_unhealthy_provider(): void {
		$user_id = self::factory()->user->create();
		$post_id = $this->publish( array( 'included_user_ids' => array( $user_id ) ) );
		$id      = (int) $this->campaign_for( $post_id )['id'];

		$this->healthy = false;
		$outcome       = $this->preparation()->prepare( $id );
		self::assertSame( CampaignPreparation::RESULT_DEFERRED, $outcome['result'] );
		self::assertSame( CampaignQueueGate::VERIFICATION_UNHEALTHY, $outcome['code'] );
		self::assertSame( CampaignRepository::STATUS_BUILDING, ( new CampaignRepository() )->find( $id )['status'] );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		$this->healthy = true;
		$outcome       = $this->preparation()->prepare( $id );
		self::assertSame( CampaignPreparation::RESULT_FAILED, $outcome['result'] );
		self::assertSame( CampaignQueueGate::POST_NOT_PUBLISHED, $outcome['code'] );
		self::assertSame( 0, $this->recipient_count( $id ) );
	}

	public function test_unhealthy_provider_deferral_is_bounded_then_fails_closed(): void {
		$user_id       = self::factory()->user->create();
		$post_id       = $this->publish( array( 'included_user_ids' => array( $user_id ) ) );
		$id            = (int) $this->campaign_for( $post_id )['id'];
		$this->healthy = false;

		self::assertSame( CampaignPreparation::RESULT_DEFERRED, $this->preparation()->prepare( $id )['result'] );
		self::assertSame(
			CampaignQueueGate::VERIFICATION_UNHEALTHY,
			( new CampaignRepository() )->find( $id )['last_error_code']
		);

		$limit   = static fn (): int => 0;
		add_filter( 'argentwolf_post_notifier_max_preparation_deferral_seconds', $limit );
		$outcome = $this->preparation()->prepare( $id );
		remove_filter( 'argentwolf_post_notifier_max_preparation_deferral_seconds', $limit );

		self::assertSame( CampaignPreparation::RESULT_FAILED, $outcome['result'] );
		self::assertSame( CampaignQueueGate::VERIFICATION_UNHEALTHY, $outcome['code'] );
		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_FAILED, $campaign['status'] );
		self::assertSame( 0, $this->recipient_count( $id ) );

		// A recovered provider cannot revive the failed campaign.
		$this->healthy = true;
		self::assertSame( CampaignPreparation::RESULT_SKIPPED, $this->preparation()->prepare( $id )['result'] );
		self::assertSame( array(), $this->transport->messages );
	}

	public function test_zero_recipient_campaign_completes_with_counts(): void {
		$post_id = $this->publish( array() );
		$id      = (int) $this->campaign_for( $post_id )['id'];

		self::assertSame( CampaignPreparation::RESULT_COMPLETED, $this->preparation()->prepare( $id )['result'] );
		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_COMPLETED, $campaign['status'] );
		self::assertSame( 0, (int) $campaign['recipient_count'] );
	}

	public function test_worker_submits_one_message_per_recipient_with_local_footer_links(): void {
		$first   = $this->subscriber( 'one@example.com' );
		$second  = $this->subscriber( 'two@example.com' );
		$post_id = $this->publish(
			array( 'included_subscriber_ids' => array( $first, $second ) ),
			'<!-- wp:paragraph --><p>Lead.</p><!-- /wp:paragraph -->'
				. '<!-- wp:argentwolf-post-notifier/email-cutoff /-->'
				. '<!-- wp:paragraph --><p>Hidden tail.</p><!-- /wp:paragraph -->'
		);
		$id      = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$frozen = ( new CampaignRepository() )->find( $id );

		$totals = $this->worker()->run( 2 );
		self::assertSame( 2, $totals['submitted'] );
		self::assertCount( 2, $this->transport->messages );

		$recipients = array();
		$site       = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		foreach ( $this->transport->messages as $message ) {
			$recipients[] = $message->recipient();
			self::assertStringNotContainsString( ',', $message->recipient() );
			self::assertStringContainsString( 'Lead.', $message->body() );
			self::assertStringNotContainsString( 'Hidden tail.', $message->body() );
			self::assertStringNotContainsString( 'email-cutoff', $message->body() );
			self::assertStringContainsString( RecipientLinkFactory::UNSUBSCRIBE_ACTION, $message->body() );
			self::assertStringContainsString( RecipientLinkFactory::MANAGE_ACTION, (string) $message->alt_body() );
			self::assertContains( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $message->headers() );
			foreach ( $message->headers() as $header ) {
				self::assertDoesNotMatchRegularExpression( '/^(to|cc|bcc):/i', $header );
			}
			preg_match_all( '/href="([^"]+)"/', $message->body(), $links );
			foreach ( $links[1] as $link ) {
				self::assertSame( $site, wp_parse_url( html_entity_decode( $link ), PHP_URL_HOST ) );
			}
		}
		sort( $recipients );
		self::assertSame( array( 'one@example.com', 'two@example.com' ), $recipients );

		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_COMPLETED, $campaign['status'] );
		self::assertSame( 2, (int) $campaign['submitted_count'] );
		self::assertSame( $frozen['html_body'], $campaign['html_body'] );
		self::assertSame( $frozen['text_body'], $campaign['text_body'] );

		// Replay sends nothing more.
		self::assertSame( 0, $this->worker()->run( 2 )['claimed'] );
		self::assertCount( 2, $this->transport->messages );
	}

	public function test_transport_failures_retry_with_backoff_then_fail_terminally(): void {
		add_filter( 'argentwolf_post_notifier_queue_max_attempts', static fn () => 2 );
		$subscriber = $this->subscriber( 'flaky@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$this->transport->accept = false;

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		self::assertSame( 1, $this->worker()->run( 1, $now )['retried'] );
		// Backoff blocks an immediate re-claim.
		self::assertSame( 0, $this->worker()->run( 1, $now )['claimed'] );
		self::assertSame( 60, QueueWorker::backoff_seconds( 1 ) );
		self::assertSame( 120, QueueWorker::backoff_seconds( 2 ) );
		self::assertSame( QueueWorker::BACKOFF_MAX_SECONDS, QueueWorker::backoff_seconds( 50 ) );

		self::assertSame( 1, $this->worker()->run( 1, $now->modify( '+1 hour' ) )['failed'] );
		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_COMPLETED_WITH_ERRORS, $campaign['status'] );
		self::assertSame( 1, (int) $campaign['failed_count'] );
		self::assertCount( 2, $this->transport->messages );
		remove_all_filters( 'argentwolf_post_notifier_queue_max_attempts' );
	}

	public function test_concurrent_leases_are_exclusive_and_expired_leases_recover(): void {
		$subscriber = $this->subscriber( 'lease@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );

		$queue = new QueueRepository();
		$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$first = $queue->claim( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 10, 300, $now );
		self::assertCount( 1, $first );
		self::assertSame( array(), $queue->claim( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 10, 300, $now ) );

		// Crash: the first worker never finishes; its lease expires.
		$later  = $now->modify( '+10 minutes' );
		$second = $queue->claim( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 10, 300, $later );
		self::assertCount( 1, $second );
		self::assertSame( 2, (int) $second[0]['attempt_count'] );

		// The stale owner can no longer record a result.
		self::assertFalse(
			$queue->mark_submitted( (int) $first[0]['id'], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $later )
		);
		self::assertTrue(
			$queue->mark_submitted( (int) $second[0]['id'], 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $later )
		);
	}

	public function test_presend_recheck_skips_newly_suppressed_unverified_and_filtered(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		( new RegisteredUserPreferenceRepository() )->set( $user_id, RegisteredUserPreference::Subscribed );
		$this->statuses = array( $user_id => VerificationStatus::Verified );
		$blocked        = $this->subscriber( 'later-blocked@example.com' );
		$filtered       = $this->subscriber( 'filtered@example.com' );
		$post_id        = $this->publish(
			array(
				'included_user_ids'       => array( $user_id ),
				'included_subscriber_ids' => array( $blocked, $filtered ),
			)
		);
		$id             = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		self::assertSame( 3, $this->recipient_count( $id ) );

		// Eligibility changes after the audience snapshot.
		$this->statuses[ $user_id ] = VerificationStatus::Pending;
		$this->suppression()->suppress(
			'later-blocked@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN
		);
		$veto = static fn ( bool $ok, array $context ): bool => $ok && $context['subscriber_id'] !== $filtered;
		add_filter( 'argentwolf_post_notifier_recipient_eligible', $veto, 10, 2 );

		$totals = $this->worker()->run( 1 );
		remove_filter( 'argentwolf_post_notifier_recipient_eligible', $veto, 10 );
		self::assertSame( 3, $totals['skipped'] );
		self::assertSame( array(), $this->transport->messages );
		self::assertSame(
			array( 'filtered', 'suppressed', 'unverified' ),
			$this->skip_reasons( $id )
		);
		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_COMPLETED, $campaign['status'] );
		self::assertSame( 3, (int) $campaign['skipped_count'] );
	}

	public function test_delivery_status_page_is_admin_only_aggregate_and_runs_manually(): void {
		$blocked = $this->subscriber( 'skip-me@example.com' );
		$sent    = $this->subscriber( 'send-me@example.com' );
		$post_id = $this->publish( array( 'included_subscriber_ids' => array( $blocked, $sent ) ) );
		$id      = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$this->suppression()->suppress(
			'skip-me@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN
		);
		$worker = $this->worker();
		$page   = new DeliveryStatusPage(
			static fn (): object => new QueueRepository(),
			static fn (): object => $worker
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertStringContainsString(
			'permission',
			$this->capture_wp_die( static fn () => $page->render() )
		);
		self::assertStringContainsString(
			'permission',
			$this->capture_wp_die( static fn () => $page->handle_run() )
		);
		self::assertSame( array(), $this->transport->messages );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->capture_wp_die( static fn () => $page->handle_run() );
		self::assertSame( array(), $this->transport->messages, 'Missing nonce must not run the queue.' );

		$totals = $page->run_manual();
		self::assertSame( 1, $totals['submitted'] );
		self::assertSame( 1, $totals['skipped'] );

		$_GET = array(
			'awpn_ran'       => '1',
			'awpn_submitted' => '1<script>',
		);
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'suppressed', $html );
		self::assertStringContainsString( 'completed', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertStringContainsString( DeliveryStatusPage::RUN_ACTION, $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( 'example.com', $html );
	}

	public function test_recipient_links_unsubscribe_safely(): void {
		$subscriber = $this->subscriber( 'leaver@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$this->worker()->run( 1 );
		self::assertCount( 1, $this->transport->messages );
		preg_match( '/token=([a-f0-9]{64})/', $this->transport->messages[0]->body(), $match );
		$token      = $match[1];
		$controller = new RecipientUnsubscribeController( new CampaignRecipientRepository(), $this->suppression() );

		// GET is display-only.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'token' => $token );
		$html                      = $this->capture_wp_die( array( $controller, 'handle_get' ) );
		self::assertStringContainsString( 'Stop post notifications', $html );
		self::assertStringNotContainsString( 'leaver@example.com', $html );
		self::assertFalse( $this->suppression()->is_suppressed( 'leaver@example.com' ) );

		// One-click requires the exact RFC 8058 POST body.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->capture_wp_die( array( $controller, 'handle_one_click' ) );
		self::assertFalse( $this->suppression()->is_suppressed( 'leaver@example.com' ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'List-Unsubscribe' => 'One-Click' );
		$html                      = $this->capture_wp_die( array( $controller, 'handle_one_click' ) );
		self::assertStringContainsString( 'Subscription updated', $html );
		self::assertTrue( $this->suppression()->is_suppressed( 'leaver@example.com' ) );

		// Invalid and replayed tokens give generic results.
		self::assertFalse( $controller->unsubscribe( str_repeat( 'f', 64 ) ) );
		self::assertFalse( $controller->unsubscribe( 'not-a-token' ) );
		self::assertTrue( $controller->unsubscribe( $token ) );
		self::assertNull( RecipientToken::hash_plaintext( 'ABC' ) );
	}

	public function test_campaign_link_resubscribe_expiry_and_replay(): void {
		$subscriber = $this->subscriber( 'returner@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$this->worker()->run( 1 );
		preg_match( '/token=([a-f0-9]{64})/', $this->transport->messages[0]->body(), $match );
		$token      = $match[1];
		$controller = new RecipientUnsubscribeController( new CampaignRecipientRepository(), $this->suppression() );

		// Not suppressed: resubscribe is a no-op and the page offers only "stop".
		self::assertFalse( $controller->resubscribe( $token, $controller->current_binding( $token ) ) );
		self::assertTrue( $controller->unsubscribe( $token ) );
		self::assertTrue( $this->suppression()->is_suppressed( 'returner@example.com' ) );

		// GET (scanner) shows the resume form but changes nothing.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'token' => $token );
		$html                      = $this->capture_wp_die( array( $controller, 'handle_get' ) );
		self::assertStringContainsString( 'Resume post notifications', $html );
		self::assertStringContainsString( RecipientUnsubscribeController::INTENT_RESUBSCRIBE, $html );
		self::assertStringNotContainsString( 'returner@example.com', $html );
		self::assertTrue( $this->suppression()->is_suppressed( 'returner@example.com' ) );

		// The confirmation POST requires the nonce.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$field                     = RecipientUnsubscribeController::BINDING_FIELD;
		preg_match( '/name="' . $field . '" value="([a-f0-9]{64})"/', $html, $bound );
		self::assertSame( $controller->current_binding( $token ), $bound[1] );
		$_POST = array(
			'argentwolf_post_notifier_recipient_token'    => $token,
			'argentwolf_post_notifier_recipient_intent'   => RecipientUnsubscribeController::INTENT_RESUBSCRIBE,
			RecipientUnsubscribeController::BINDING_FIELD => $bound[1],
		);
		$this->capture_wp_die( array( $controller, 'handle_confirm' ) );
		self::assertTrue( $this->suppression()->is_suppressed( 'returner@example.com' ) );

		$_POST['argentwolf_post_notifier_recipient_nonce'] = wp_create_nonce(
			'argentwolf_post_notifier_recipient_unsubscribe'
		);
		$_REQUEST = $_POST;
		$html     = $this->capture_wp_die( array( $controller, 'handle_confirm' ) );
		self::assertStringContainsString( 'will resume', $html );
		self::assertFalse( $this->suppression()->is_suppressed( 'returner@example.com' ) );
		$_REQUEST = array();

		// Replay after resuming is a generic failure and changes nothing.
		self::assertFalse( $controller->resubscribe( $token, $controller->current_binding( $token ) ) );

		// An operator suppression can never be lifted from a campaign link.
		$this->suppression()->suppress(
			'returner@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN
		);
		self::assertFalse( $controller->resubscribe( $token, $controller->current_binding( $token ) ) );
		self::assertTrue( $this->suppression()->is_suppressed( 'returner@example.com' ) );
		$this->suppression()->resubscribe( 'returner@example.com', SuppressionService::SOURCE_SUBSCRIBER_ADMIN );

		// Resubscribe expires before unsubscribe does.
		self::assertTrue( $controller->unsubscribe( $token ) );
		$short = static fn (): int => -1;
		add_filter( 'argentwolf_post_notifier_recipient_resubscribe_ttl_seconds', $short );
		self::assertFalse( $controller->resubscribe( $token, $controller->current_binding( $token ) ) );
		self::assertTrue( $controller->unsubscribe( $token ) );
		remove_filter( 'argentwolf_post_notifier_recipient_resubscribe_ttl_seconds', $short );

		// Fully expired tokens are rejected for every intent.
		add_filter( 'argentwolf_post_notifier_recipient_token_ttl_seconds', $short );
		self::assertFalse( $controller->unsubscribe( $token ) );
		self::assertFalse( $controller->resubscribe( $token, $controller->current_binding( $token ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertStringContainsString(
			'invalid',
			$this->capture_wp_die( array( $controller, 'handle_get' ) )
		);
		remove_filter( 'argentwolf_post_notifier_recipient_token_ttl_seconds', $short );
	}

	public function test_resubscribe_replay_after_later_unsubscribe_and_double_submit_are_rejected(): void {
		$subscriber = $this->subscriber( 'replay@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$this->worker()->run( 1 );
		preg_match( '/token=([a-f0-9]{64})/', $this->transport->messages[0]->body(), $match );
		$token      = $match[1];
		$controller = new RecipientUnsubscribeController( new CampaignRecipientRepository(), $this->suppression() );

		// Normal flow: unsubscribe, then resubscribe with the page's binding.
		self::assertTrue( $controller->unsubscribe( $token ) );
		$first = $controller->current_binding( $token );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $first );
		self::assertFalse( $controller->resubscribe( $token, '' ), 'Missing binding is rejected.' );
		self::assertFalse( $controller->resubscribe( $token, str_repeat( 'a', 64 ) ), 'Forged binding is rejected.' );
		self::assertTrue( $controller->resubscribe( $token, $first ) );
		self::assertFalse( $this->suppression()->is_suppressed( 'replay@example.com' ) );

		// Double submit of the same form is rejected (the event was consumed).
		self::assertFalse( $controller->resubscribe( $token, $first ) );
		self::assertSame( '', $controller->current_binding( $token ) );

		// A later unsubscribe creates a new event; replaying the old form fails.
		self::assertTrue( $controller->unsubscribe( $token ) );
		self::assertTrue( $this->suppression()->is_suppressed( 'replay@example.com' ) );
		self::assertFalse( $controller->resubscribe( $token, $first ) );
		self::assertTrue( $this->suppression()->is_suppressed( 'replay@example.com' ) );
		self::assertNotSame( $first, $controller->current_binding( $token ) );

		// The delivered link keeps working for unsubscribe, and a binding taken
		// before a newer suppression update is stale.
		global $wpdb;
		$table = TableNames::from_database( $wpdb )->suppressions();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at_gmt = %s', $table, '2000-01-01 00:00:00' ) );
		// phpcs:enable
		$second = $controller->current_binding( $token );
		self::assertTrue( $controller->unsubscribe( $token ) );
		self::assertFalse( $controller->resubscribe( $token, $second ) );
		self::assertTrue( $this->suppression()->is_suppressed( 'replay@example.com' ) );

		// The current page binding still allows the legitimate resubscribe.
		self::assertTrue( $controller->resubscribe( $token, $controller->current_binding( $token ) ) );
		self::assertFalse( $this->suppression()->is_suppressed( 'replay@example.com' ) );
	}

	public function test_campaign_manage_links_reach_profile_and_standalone_settings(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member-links@example.com' ) );
		( new RegisteredUserPreferenceRepository() )->set( $user_id, RegisteredUserPreference::Subscribed );
		$this->statuses = array( $user_id => VerificationStatus::Verified );
		$subscriber     = $this->subscriber( 'standalone-links@example.com' );
		$post_id        = $this->publish(
			array(
				'included_user_ids'       => array( $user_id ),
				'included_subscriber_ids' => array( $subscriber ),
			)
		);
		$id             = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$this->worker()->run( 1 );
		self::assertCount( 2, $this->transport->messages );

		$tokens = array();
		foreach ( $this->transport->messages as $message ) {
			preg_match( '/token=([a-f0-9]{64})/', $message->body(), $match );
			$tokens[ $message->recipient() ] = $match[1];
			if ( 'member-links@example.com' === $message->recipient() ) {
				self::assertStringContainsString(
					'profile.php#' . \ArgentWolf\PostNotifier\Admin\UserNotificationPreferenceProfile::SECTION_ID,
					html_entity_decode( (string) $message->alt_body() . $message->body() )
				);
			}
		}

		$subscribers = new SubscriberService( new SubscriberRepository(), new EmailIdentity(), $this->suppression() );
		$controller  = new RecipientUnsubscribeController(
			new CampaignRecipientRepository(),
			$this->suppression(),
			$subscribers,
			new \ArgentWolf\PostNotifier\Subscriber\WordPressManageSubscriptionLinkFactory()
		);

		// Registered users are not offered standalone settings.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'token' => $tokens['member-links@example.com'] );
		$html                      = $this->capture_wp_die( array( $controller, 'handle_get' ) );
		self::assertStringNotContainsString( 'Open subscription settings', $html );
		self::assertNull( $controller->open_settings( $tokens['member-links@example.com'] ) );

		// Standalone subscribers get a scanner-safe settings button.
		$_GET = array( 'token' => $tokens['standalone-links@example.com'] );
		$html = $this->capture_wp_die( array( $controller, 'handle_get' ) );
		self::assertStringContainsString( 'Open subscription settings', $html );
		self::assertStringContainsString( RecipientUnsubscribeController::INTENT_MANAGE, $html );
		self::assertStringNotContainsString( 'standalone-links@example.com', $html );

		// The POST issues a fresh management bearer and links to the manage page.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_recipient_token'  => $tokens['standalone-links@example.com'],
			'argentwolf_post_notifier_recipient_intent' => RecipientUnsubscribeController::INTENT_MANAGE,
			'argentwolf_post_notifier_recipient_nonce'  => wp_create_nonce(
				'argentwolf_post_notifier_recipient_unsubscribe'
			),
		);
		$_REQUEST                  = $_POST;
		$html                      = $this->capture_wp_die( array( $controller, 'handle_confirm' ) );
		$_REQUEST                  = array();
		self::assertStringContainsString( 'Continue to subscription settings', $html );
		preg_match( '/href="([^"]+)"/', $html, $link );
		$url = html_entity_decode( $link[1] );
		self::assertStringContainsString(
			\ArgentWolf\PostNotifier\Subscriber\WordPressManageSubscriptionLinkFactory::GET_ACTION,
			$url
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$first = (string) $query['token'];
		$row   = $subscribers->managed_subscriber( $first );
		self::assertSame( $subscriber, (int) $row['id'] );

		// Opening settings again rotates: the earlier link stops working.
		$second_url = (string) $controller->open_settings( $tokens['standalone-links@example.com'] );
		parse_str( (string) wp_parse_url( $second_url, PHP_URL_QUERY ), $query );
		self::assertNull( $subscribers->managed_subscriber( $first ) );
		self::assertNotNull( $subscribers->managed_subscriber( (string) $query['token'] ) );

		// Invalid campaign tokens get the generic failure.
		self::assertNull( $controller->open_settings( str_repeat( 'f', 64 ) ) );
	}

	public function test_tracked_links_count_clicks_and_redirect_only_to_the_post(): void {
		$first   = $this->subscriber( 'click-one@example.com' );
		$second  = $this->subscriber( 'click-two@example.com' );
		$post_id = $this->publish( array( 'included_subscriber_ids' => array( $first, $second ) ) );
		$id      = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$privacy = new PrivacySettings();
		self::assertTrue( $privacy->click_tracking_enabled() );

		self::assertSame( 2, $this->worker( $privacy )->run( 2 )['submitted'] );
		$permalink = (string) get_permalink( $post_id );
		$tokens    = array();
		foreach ( $this->transport->messages as $message ) {
			self::assertStringNotContainsString( 'href="' . esc_url( $permalink ) . '"', $message->body() );
			self::assertStringNotContainsString( $permalink . "\n", (string) $message->alt_body() );
			self::assertSame( 1, preg_match( $this->click_pattern(), $message->body(), $html ) );
			self::assertSame( 1, preg_match( $this->click_pattern(), (string) $message->alt_body(), $text ) );
			self::assertSame( $html[1], $text[1] );
			self::assertStringNotContainsString( 'open', strtolower( implode( ' ', $message->headers() ) ) );
			self::assertStringNotContainsString( '<img', $message->body() );
			$tokens[] = $html[1];
		}
		self::assertNotSame( $tokens[0], $tokens[1] );

		$controller = new ClickController( new ClickRepository(), $privacy );
		self::assertSame( $permalink, $controller->resolve( $tokens[0] ) );
		self::assertSame( $permalink, $controller->resolve( $tokens[0] ) );
		self::assertSame( $permalink, $controller->resolve( $tokens[1] ) );
		self::assertSame( home_url( '/' ), $controller->resolve( str_repeat( 'a', 64 ) ) );
		self::assertSame( home_url( '/' ), $controller->resolve( 'https://evil.example/' ) );
		self::assertSame( home_url( '/' ), $controller->resolve( '' ) );

		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( 2, (int) $campaign['unique_click_count'] );
		self::assertSame( 3, (int) $campaign['total_click_count'] );

		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $tables->clicks() ) );
		$events  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables->clicks() ) );
		$counts  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT click_count FROM %i WHERE campaign_id = %d ORDER BY click_count',
				$tables->campaign_recipients(),
				$id
			)
		);
		// phpcs:enable
		self::assertSame( '', $wpdb->last_error );
		self::assertSame(
			array( 'id', 'campaign_recipient_id', 'clicked_at_gmt', 'destination_kind' ),
			$columns
		);
		self::assertSame( 3, $events );
		self::assertSame( array( '1', '2' ), $counts );

		// Expired tokens and direct-link mode still redirect but record nothing.
		$later = new DateTimeImmutable( '+2 years', new DateTimeZone( 'UTC' ) );
		self::assertSame( $permalink, $controller->resolve( $tokens[1], $later ) );
		$privacy->save( array( 'click_tracking' => '0' ) );
		self::assertSame( $permalink, $controller->resolve( $tokens[1] ) );
		self::assertSame( 3, (int) ( new CampaignRepository() )->find( $id )['total_click_count'] );

		// Dashboard rows expose aggregates only.
		$rows = DeliveryStatusPage::campaign_rows( ( new QueueRepository() )->recent_campaigns( 5 ) );
		self::assertSame( 'Delivery title', $rows[0][0] );
		self::assertSame( array( 2, 0, 0, 2, 3 ), array_slice( $rows[0], 4, 5 ) );
		self::assertStringNotContainsString( '@', wp_json_encode( $rows ) );

		// A post that is no longer public is never the redirect target.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		self::assertSame( home_url( '/' ), $controller->resolve( $tokens[0] ) );
	}

	public function test_direct_link_mode_issues_no_click_token(): void {
		( new PrivacySettings() )->save( array( 'click_tracking' => '0' ) );
		$subscriber = $this->subscriber( 'direct@example.com' );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );

		self::assertSame( 1, $this->worker( new PrivacySettings() )->run( 1 )['submitted'] );
		$message = $this->transport->messages[0];
		$direct  = 'href="' . esc_url( (string) get_permalink( $post_id ) ) . '"';
		self::assertStringContainsString( $direct, $message->body() );
		self::assertStringNotContainsString( RecipientLinkFactory::CLICK_ACTION, $message->body() );
		self::assertStringNotContainsString( RecipientLinkFactory::CLICK_ACTION, (string) $message->alt_body() );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$hashes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE campaign_id = %d AND click_token_hash IS NOT NULL',
				TableNames::from_database( $wpdb )->campaign_recipients(),
				$id
			)
		);
		// phpcs:enable
		self::assertSame( '', $wpdb->last_error );
		self::assertSame( 0, $hashes );
	}

	public function test_privacy_settings_sanitize_and_retention_cleanup_is_bounded(): void {
		self::assertSame(
			array(
				'click_tracking'           => true,
				'click_retention_days'     => 90,
				'recipient_retention_days' => 365,
			),
			PrivacySettings::sanitize( array() )
		);
		self::assertSame(
			array(
				'click_tracking'           => false,
				'click_retention_days'     => 1,
				'recipient_retention_days' => 3650,
			),
			PrivacySettings::sanitize(
				array(
					'click_tracking'           => 'yes',
					'click_retention_days'     => '0',
					'recipient_retention_days' => '99999',
				)
			)
		);

		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array( '2020-01-01 00:00:00', gmdate( 'Y-m-d H:i:s' ) ) as $at ) {
			$wpdb->insert(
				$tables->clicks(),
				array(
					'campaign_recipient_id' => 1,
					'clicked_at_gmt'        => $at,
					'destination_kind'      => 'post',
				)
			);
		}
		$job    = new RetentionCleanup( new DataCleanup(), new PrivacySettings() );
		$result = $job->run();
		$left   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables->clicks() ) );
		// phpcs:enable
		self::assertSame( 1, $result['clicks'] );
		self::assertSame( 1, $left );
		RetentionCleanup::schedule();
		self::assertNotFalse( wp_next_scheduled( RetentionCleanup::HOOK ) );
		RetentionCleanup::unschedule();
	}

	public function test_privacy_exporter_and_eraser_cover_subscriber_deliveries_and_suppression(): void {
		$email      = 'privacy@example.com';
		$subscriber = $this->subscriber( $email );
		$post_id    = $this->publish( array( 'included_subscriber_ids' => array( $subscriber ) ) );
		$id         = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );
		$privacy = new PrivacySettings();
		$this->worker( $privacy )->run( 1 );
		preg_match( $this->click_pattern(), $this->transport->messages[0]->body(), $match );
		( new ClickController( new ClickRepository(), $privacy ) )->resolve( $match[1] );
		$this->suppression()->suppress( $email, 'unsubscribed', 'test' );

		$integration = Plugin::instance()->container()->get( PrivacyIntegration::class );
		self::assertInstanceOf( PrivacyIntegration::class, $integration );
		self::assertArrayHasKey(
			PrivacyIntegration::EXPORTER_ID,
			apply_filters( 'wp_privacy_personal_data_exporters', array() )
		);
		self::assertArrayHasKey(
			PrivacyIntegration::ERASER_ID,
			apply_filters( 'wp_privacy_personal_data_erasers', array() )
		);

		$export = $integration->export( $email, 1 );
		self::assertTrue( $export['done'] );
		$groups = array_column( $export['data'], 'group_id' );
		self::assertContains( 'argentwolf-post-notifier-subscription', $groups );
		self::assertContains( 'argentwolf-post-notifier-deliveries', $groups );
		self::assertContains( 'argentwolf-post-notifier-suppression', $groups );
		self::assertStringContainsString( $email, wp_json_encode( $export ) );
		self::assertSame( array(), $integration->export( 'nobody@example.com', 1 )['data'] );

		$erase = $integration->erase( $email, 1 );
		self::assertTrue( $erase['done'] );
		self::assertTrue( $erase['items_removed'] );
		self::assertTrue( $erase['items_retained'] );
		self::assertNotEmpty( $erase['messages'] );

		$after = $integration->export( $email, 1 );
		self::assertStringNotContainsString( $email, wp_json_encode( $after ) );
		self::assertSame( array( 'argentwolf-post-notifier-suppression' ), array_column( $after['data'], 'group_id' ) );
		self::assertTrue( $this->suppression()->is_suppressed( $email ) );

		global $wpdb;
		$tables = TableNames::from_database( $wpdb );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT email_snapshot, email_hash, click_token_hash, personal_data_erased_at_gmt
				FROM %i WHERE campaign_id = %d',
				$tables->campaign_recipients(),
				$id
			),
			ARRAY_A
		);
		$clicks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables->clicks() ) );
		// phpcs:enable
		self::assertNull( $row['email_snapshot'] );
		self::assertNull( $row['email_hash'] );
		self::assertNull( $row['click_token_hash'] );
		self::assertNotNull( $row['personal_data_erased_at_gmt'] );
		self::assertSame( 0, $clicks );
		self::assertSame( 1, (int) ( new CampaignRepository() )->find( $id )['unique_click_count'] );
		self::assertNull( ( new SubscriberRepository() )->find_for_audience( $subscriber ) );
		self::assertStringContainsString( 'IP address', PrivacyIntegration::policy_text() );
	}

	public function test_privacy_exporter_and_eraser_cover_registered_user_preference(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		update_user_meta( $user_id, RegisteredUserPreferenceRepository::META_KEY, 'subscribed' );
		$integration = new PrivacyIntegration( new PrivacyRepository(), new EmailIdentity() );

		$export = $integration->export( 'member@example.com', 1 );
		self::assertContains( 'argentwolf-post-notifier-user-' . $user_id, array_column( $export['data'], 'item_id' ) );

		$erase = $integration->erase( 'member@example.com', 1 );
		self::assertTrue( $erase['items_removed'] );
		self::assertFalse( $erase['items_retained'] );
		self::assertSame( '', get_user_meta( $user_id, RegisteredUserPreferenceRepository::META_KEY, true ) );
		self::assertSame( array(), $integration->export( 'member@example.com', 1 )['data'] );
	}

	public function test_post_removal_stops_delivery_and_deleted_user_rows_are_redacted(): void {
		$one     = $this->subscriber( 'removed-one@example.com' );
		$two     = $this->subscriber( 'removed-two@example.com' );
		$post_id = $this->publish( array( 'included_subscriber_ids' => array( $one, $two ) ) );
		$id      = (int) $this->campaign_for( $post_id )['id'];
		$this->preparation()->prepare( $id );

		global $wpdb;
		$tables  = TableNames::from_database( $wpdb );
		$user_id = self::factory()->user->create();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET user_id = %d WHERE campaign_id = %d ORDER BY id LIMIT 1',
				$tables->campaign_recipients(),
				$user_id,
				$id
			)
		);
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
		$redacted = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, skip_reason, email_snapshot, user_id FROM %i
				WHERE campaign_id = %d AND personal_data_erased_at_gmt IS NOT NULL',
				$tables->campaign_recipients(),
				$id
			),
			ARRAY_A
		);
		// phpcs:enable
		self::assertSame( 'skipped', $redacted['status'] );
		self::assertSame( PrivacyRepository::SKIP_USER_DELETED, $redacted['skip_reason'] );
		self::assertNull( $redacted['email_snapshot'] );
		self::assertNull( $redacted['user_id'] );

		wp_trash_post( $post_id );
		$campaign = ( new CampaignRepository() )->find( $id );
		self::assertSame( CampaignRepository::STATUS_FAILED, $campaign['status'] );
		self::assertSame( PrivacyRepository::SKIP_POST_REMOVED, $campaign['last_error_code'] );
		self::assertSame( array( 'post_removed', 'user_deleted' ), $this->skip_reasons( $id ) );
		self::assertSame( 0, $this->worker()->run( 2 )['claimed'] );
		self::assertCount( 0, $this->transport->messages );
	}

	/**
	 * Regex capturing a click token from an HTML or text body.
	 *
	 * @return string
	 */
	private function click_pattern(): string {
		return '/' . RecipientLinkFactory::CLICK_ACTION . '(?:&#038;|&amp;|&)token=([a-f0-9]{64})/';
	}

	public function test_message_builder_rejects_unfrozen_content(): void {
		$builder = new RecipientMessageBuilder( $this->composer() );
		$this->expectException( \InvalidArgumentException::class );
		$builder->build(
			array(
				'uuid'      => wp_generate_uuid4(),
				'subject'   => '',
				'html_body' => '',
				'text_body' => '',
			),
			'person@example.com',
			wp_generate_uuid4(),
			( new RecipientLinkFactory() )->create(
				NamedListMemberType::Subscriber,
				RecipientToken::generate()->plaintext()
			)
		);
	}

	/**
	 * Publish a post opted into notification with an audience configuration.
	 *
	 * @param array<string,mixed> $audience Audience config.
	 * @param string              $content  Post content.
	 * @return int
	 */
	private function publish( array $audience, string $content = 'Body text.' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Delivery title',
				'post_content' => $content,
			)
		);
		update_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, 'send' );
		update_post_meta( $post_id, PostNotificationMeta::CONTENT_MODE_KEY, 'site_default' );
		update_post_meta( $post_id, PostNotificationMeta::AUDIENCE_CONFIG_KEY, $audience );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}

	/**
	 * Campaign row for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function campaign_for( int $post_id ): array {
		$row = ( new CampaignRepository() )->find_by_key(
			CampaignRepository::initial_key( get_current_blog_id(), $post_id )
		);
		self::assertNotNull( $row );

		return ( new CampaignRepository() )->find( (int) $row['id'] );
	}

	/**
	 * Count recipient rows.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int
	 */
	private function recipient_count( int $campaign_id ): int {
		return array_sum( ( new CampaignRecipientRepository() )->status_counts( $campaign_id ) );
	}

	/**
	 * Sorted skip reasons for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<int,string>
	 */
	private function skip_reasons( int $campaign_id ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT skip_reason FROM %i WHERE campaign_id = %d AND status = %s ORDER BY skip_reason',
				TableNames::from_database( $wpdb )->campaign_recipients(),
				$campaign_id,
				'skipped'
			)
		);
		// phpcs:enable

		return array_map( 'strval', $values );
	}

	/**
	 * Create one confirmed standalone subscriber.
	 *
	 * @param string $email Address.
	 * @return int
	 */
	private function subscriber( string $email ): int {
		$now     = new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) );
		$service = new SubscriberService( new SubscriberRepository(), new EmailIdentity(), $this->suppression() );
		$signup  = $service->request_confirmation( $email, null, 'Consent', 'delivery-test', null, $now );
		self::assertTrue( $service->confirm( (string) $signup->confirmation_token(), $now->modify( '+1 hour' ) ) );

		return $signup->subscriber_id();
	}

	/**
	 * Suppression service.
	 *
	 * @return SuppressionService
	 */
	private function suppression(): SuppressionService {
		return new SuppressionService( new SuppressionRepository(), new EmailIdentity() );
	}

	/**
	 * Canonical composer.
	 *
	 * @return EmailTemplateComposer
	 */
	private function composer(): EmailTemplateComposer {
		return new EmailTemplateComposer( new EmailContentRenderer( new EmailContentSelector() ) );
	}

	/**
	 * Deterministic provider bound to this test's state.
	 *
	 * @return VerificationProvider
	 */
	private function provider(): VerificationProvider {
		return new CampaignDeliveryVerificationProvider(
			fn ( int $user_id ): VerificationStatus => $this->statuses[ $user_id ] ?? VerificationStatus::Unknown,
			fn (): bool => $this->healthy
		);
	}

	/**
	 * Preparation service with deterministic verification.
	 *
	 * @return CampaignPreparation
	 */
	private function preparation(): CampaignPreparation {
		return new CampaignPreparation(
			new CampaignRepository(),
			new CampaignRenderedBodyFinalizer(),
			new CampaignQueueGate( $this->composer(), $this->provider() ),
			$this->audience(),
			new AuditService( new AuditRepository() )
		);
	}

	/**
	 * Audience snapshot with deterministic verification.
	 *
	 * @return CampaignAudienceSnapshot
	 */
	private function audience(): CampaignAudienceSnapshot {
		return new CampaignAudienceSnapshot(
			new AudienceResolver(
				new NamedListRepository(),
				new RegisteredUserPreferenceRepository(),
				new RegisteredUserEligibility( $this->provider() ),
				new SubscriberRepository(),
				$this->suppression(),
				new EmailIdentity()
			),
			new AudienceRequestBuilder()
		);
	}

	/**
	 * Worker with recording transport.
	 *
	 * @param PrivacySettings|null $privacy Optional tracking settings.
	 * @return QueueWorker
	 */
	private function worker( ?PrivacySettings $privacy = null ): QueueWorker {
		return new QueueWorker(
			new QueueRepository(),
			new CampaignRepository(),
			$this->audience(),
			new RecipientEligibilityRecheck(
				new RegisteredUserEligibility( $this->provider() ),
				new RegisteredUserPreferenceRepository(),
				new SubscriberRepository(),
				$this->suppression(),
				new EmailIdentity()
			),
			new RecipientMessageBuilder( $this->composer() ),
			new RecipientLinkFactory(),
			$this->transport,
			new AuditService( new AuditRepository() ),
			$privacy,
			null === $privacy ? null : new CampaignContentSnapshotStore()
		);
	}

	/**
	 * Capture a wp_die() page.
	 *
	 * @param callable $callback Handler.
	 * @return string
	 * @throws RuntimeException When the handler did not terminate.
	 */
	private function capture_wp_die( callable $callback ): string {
		$filter = static fn () => static function ( $message ): void {
			throw new CampaignDeliveryWpDie( is_string( $message ) ? $message : '' );
		};
		add_filter( 'wp_die_handler', $filter );
		try {
			$callback();
		} catch ( CampaignDeliveryWpDie $exception ) {
			return $exception->getMessage();
		} finally {
			remove_filter( 'wp_die_handler', $filter );
		}

		throw new RuntimeException( 'Controller did not terminate through wp_die().' );
	}
}

/**
 * Captured wp_die() termination.
 */
final class CampaignDeliveryWpDie extends RuntimeException {
}

/**
 * Recording transport; never sends mail.
 */
final class CampaignDeliveryRecordingTransport implements MailTransport {
	/**
	 * Submitted messages.
	 *
	 * @var array<int,MailMessage>
	 */
	public array $messages = array();

	/**
	 * Whether submissions succeed.
	 *
	 * @var bool
	 */
	public bool $accept = true;

	/**
	 * Record one message.
	 *
	 * @param MailMessage $message Message.
	 * @return DeliveryResult
	 */
	public function send( MailMessage $message ): DeliveryResult {
		$this->messages[] = $message;

		return $this->accept ? DeliveryResult::submitted() : DeliveryResult::failed();
	}
}

/**
 * Closure-driven verification provider for delivery tests.
 */
final class CampaignDeliveryVerificationProvider implements VerificationProvider {
	/**
	 * Construct the provider.
	 *
	 * @param \Closure $status  Status callback.
	 * @param \Closure $healthy Health callback.
	 */
	public function __construct( private \Closure $status, private \Closure $healthy ) {
	}

	public function is_available(): bool {
		return true;
	}

	public function status_for_user( int $user_id ): VerificationStatus {
		return ( $this->status )( $user_id );
	}

	public function description(): string {
		return 'Delivery test provider';
	}

	public function health(): VerificationProviderHealth {
		$healthy = ( $this->healthy )();

		return new VerificationProviderHealth(
			'delivery-test',
			$this->description(),
			$healthy,
			$healthy,
			'1.0.0',
			$healthy ? 'healthy' : 'unavailable',
			'Test provider.'
		);
	}
}

// EOF: tests/Integration/CampaignDeliveryTest.php.
