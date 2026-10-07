<?php
/**
 * File: src/Editor/EditorAudienceEstimate.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Recipient\AudienceRequestBuilder;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use Closure;
use LogicException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Aggregate-only resolved audience estimate for authorized post editors.
 */
final class EditorAudienceEstimate implements Registerable {
	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'argentwolf-post-notifier/v1';

	/**
	 * Audience-estimate route.
	 */
	public const ROUTE = '/editor/audience-estimate';

	/**
	 * Stable aggregate skip reasons exposed to the editor.
	 *
	 * @var array<int,string>
	 */
	private const SKIP_REASONS = array(
		'excluded',
		'suppressed',
		'verification_unknown',
		'unverified',
		'unsubscribed',
		'pending_subscription',
		'deleted',
		'no_email',
		'invalid_email',
		'duplicate',
	);

	/**
	 * Construct the editor estimate service.
	 *
	 * Resolver construction is deferred until an estimate is requested so
	 * alternate verification-provider filters registered by later-loading
	 * plugins are available before audience policy is resolved.
	 *
	 * @param AudienceRequestBuilder $requests          Audience request builder.
	 * @param Closure                $resolver_provider Deferred audience-resolver provider.
	 */
	public function __construct(
		private AudienceRequestBuilder $requests,
		private Closure $resolver_provider
	) {
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the aggregate-only audience estimate endpoint.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'estimate' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'post_id'  => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'audience' => array_merge(
						PostNotificationMeta::audience_rest_schema(),
						array( 'required' => true )
					),
				),
			)
		);
	}

	/**
	 * Authorize one estimate against the target post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ): true|WP_Error {
		if ( ! current_user_can( Capabilities::SEND_NOTIFICATIONS ) ) {
			return new WP_Error(
				'argentwolf_post_notifier_audience_estimate_forbidden',
				__(
					'You are not allowed to estimate notification audiences.',
					'argentwolf-post-notifier'
				),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( null === $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'argentwolf_post_notifier_audience_estimate_post',
				__( 'The notification post could not be found.', 'argentwolf-post-notifier' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'argentwolf_post_notifier_audience_estimate_post_forbidden',
				__( 'You are not allowed to edit this post.', 'argentwolf-post-notifier' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Resolve current editor audience configuration to aggregate counts only.
	 *
	 * Registered users with the neutral site-default preference remain fail-closed
	 * until a later site policy explicitly supplies an opt-in default.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 * @throws LogicException When the deferred audience resolver is invalid.
	 */
	public function estimate( WP_REST_Request $request ): WP_REST_Response {
		$resolver = ( $this->resolver_provider )();
		if ( ! $resolver instanceof AudienceResolver ) {
			throw new LogicException( 'The audience resolver is invalid.' );
		}

		$submitted  = $request->get_param( 'audience' );
		$config     = PostNotificationMeta::sanitize_audience_config( $submitted );
		$resolution = $resolver->resolve(
			$this->requests->from_config( $config, false )
		);

		$skipped = array();
		foreach ( self::SKIP_REASONS as $reason ) {
			$skipped[ $reason ] = $resolution->skipped( $reason );
		}

		$response = new WP_REST_Response(
			array(
				'eligible' => count( $resolution->recipients() ),
				'skipped'  => $skipped,
			),
			200
		);
		$response->header(
			'Cache-Control',
			'no-store, no-cache, must-revalidate, max-age=0'
		);

		return $response;
	}
}

// EOF: src/Editor/EditorAudienceEstimate.php.
