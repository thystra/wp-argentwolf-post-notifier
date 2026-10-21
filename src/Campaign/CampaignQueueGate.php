<?php
/**
 * File: src/Campaign/CampaignQueueGate.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use InvalidArgumentException;
use Throwable;

/**
 * Pre-queue eligibility gate for one building campaign.
 *
 * A campaign may move from `building` to `queued` only when its frozen
 * content is complete, inert, and able to carry same-site subscriber
 * controls, its post is still published, and its audience configuration is
 * frozen. Registered-user audiences additionally require a healthy
 * verification provider; that failure is retryable so a temporary provider
 * outage does not silently drop every registered recipient.
 */
final class CampaignQueueGate {
	public const OK                         = 'ok';
	public const NOT_BUILDING               = 'not_building';
	public const MISSING_INPUT_SNAPSHOT     = 'missing_input_snapshot';
	public const MISSING_RENDERED_BODY      = 'missing_rendered_body';
	public const UNRESOLVED_TEMPLATE_TOKEN  = 'unresolved_template_token';
	public const EDITOR_MARKER_IN_BODY      = 'editor_marker_in_body';
	public const POST_NOT_PUBLISHED         = 'post_not_published';
	public const MISSING_AUDIENCE_SNAPSHOT  = 'missing_audience_snapshot';
	public const NON_LOCAL_SUBSCRIBER_LINKS = 'non_local_subscriber_links';
	public const VERIFICATION_UNHEALTHY     = 'verification_provider_unhealthy';

	/**
	 * Construct the gate.
	 *
	 * @param EmailTemplateComposer $composer Canonical footer composer.
	 * @param VerificationProvider  $provider Registered-user verification provider.
	 */
	public function __construct(
		private EmailTemplateComposer $composer,
		private VerificationProvider $provider
	) {
	}

	/**
	 * Evaluate one campaign row.
	 *
	 * @param array<string,mixed>      $campaign Campaign row from CampaignRepository::find().
	 * @param array<string,mixed>|null $audience Frozen audience configuration.
	 * @return array{code:string,retryable:bool}
	 */
	public function check( array $campaign, ?array $audience ): array {
		if ( CampaignRepository::STATUS_BUILDING !== ( $campaign['status'] ?? null ) ) {
			return $this->result( self::NOT_BUILDING );
		}
		if ( ! is_string( $campaign['template_snapshot_json'] ?? null ) ) {
			return $this->result( self::MISSING_INPUT_SNAPSHOT );
		}
		$fields = array( 'subject', 'html_body', 'text_body' );
		foreach ( $fields as $field ) {
			if ( '' === trim( (string) ( $campaign[ $field ] ?? '' ) ) ) {
				return $this->result( self::MISSING_RENDERED_BODY );
			}
		}
		foreach ( $fields as $field ) {
			$value = (string) $campaign[ $field ];
			if ( 1 === preg_match( '/\{\{\s*[a-z_]+\s*\}\}/i', $value ) ) {
				return $this->result( self::UNRESOLVED_TEMPLATE_TOKEN );
			}
			if (
				str_contains( $value, 'wp:argentwolf-post-notifier/email-cutoff' )
				|| str_contains( $value, '<!--more' )
				|| str_contains( $value, '<!-- wp:' )
			) {
				return $this->result( self::EDITOR_MARKER_IN_BODY );
			}
		}

		$post_id = (int) ( $campaign['post_id'] ?? 0 );
		if ( $post_id < 1 || 'publish' !== get_post_status( $post_id ) ) {
			return $this->result( self::POST_NOT_PUBLISHED );
		}

		if ( null === $audience ) {
			return $this->result( self::MISSING_AUDIENCE_SNAPSHOT );
		}

		try {
			$probe = add_query_arg(
				array(
					'action' => RecipientLinkFactory::UNSUBSCRIBE_ACTION,
					'token'  => str_repeat( '0', 64 ),
				),
				admin_url( 'admin-post.php' )
			);
			$this->composer->recipient_footer( $probe, admin_url( 'profile.php' ) );
		} catch ( InvalidArgumentException ) {
			return $this->result( self::NON_LOCAL_SUBSCRIBER_LINKS );
		}

		if ( $this->may_include_users( $audience ) ) {
			try {
				$healthy = $this->provider->health()->is_healthy();
			} catch ( Throwable ) {
				$healthy = false;
			}
			if ( ! $healthy ) {
				return $this->result( self::VERIFICATION_UNHEALTHY, true );
			}
		}

		return $this->result( self::OK );
	}

	/**
	 * Whether a configuration can expand to registered users.
	 *
	 * Named lists may contain typed user members, so they count. The site-wide
	 * All Subscribers audience also includes opted-in registered users.
	 *
	 * @param array<string,mixed> $audience Audience configuration.
	 * @return bool
	 */
	private function may_include_users( array $audience ): bool {
		$registered_user_keys = array(
			'role_slugs',
			'named_list_ids',
			'included_user_ids',
			'include_all_subscribers',
		);
		foreach ( $registered_user_keys as $key ) {
			if ( ! empty( $audience[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build one gate result.
	 *
	 * @param string $code      Stable result code.
	 * @param bool   $retryable Whether the gate may pass later without new input.
	 * @return array{code:string,retryable:bool}
	 */
	private function result( string $code, bool $retryable = false ): array {
		return array(
			'code'      => $code,
			'retryable' => $retryable,
		);
	}
}

// EOF: src/Campaign/CampaignQueueGate.php.
