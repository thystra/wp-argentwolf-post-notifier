<?php
/**
 * File: src/Verification/VerificationProviderResolver.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

use Closure;
use Throwable;

/**
 * Resolve the default or filtered authoritative verification provider.
 */
final class VerificationProviderResolver {
	/**
	 * Provider filter.
	 *
	 * @var Closure
	 */
	private Closure $filter;

	/**
	 * Construct the resolver.
	 *
	 * @param VerificationProvider $default_provider Default provider.
	 * @param Closure|null         $filter           Provider filter.
	 */
	public function __construct(
		private VerificationProvider $default_provider,
		?Closure $filter = null
	) {
		$this->filter = $filter
			?? static function ( VerificationProvider $provider ): mixed {
				if ( ! function_exists( 'apply_filters' ) ) {
					return $provider;
				}

				return apply_filters(
					'argentwolf_post_notifier_verification_provider',
					$provider
				);
			};
	}

	/**
	 * Resolve a valid provider or return the fail-closed fallback.
	 *
	 * @return VerificationProvider
	 */
	public function resolve(): VerificationProvider {
		try {
			$provider = ( $this->filter )( $this->default_provider );
		} catch ( Throwable ) {
			return new UnavailableVerificationProvider(
				'provider_filter_failed',
				'The verification-provider extension point raised an error.'
			);
		}

		if ( $provider instanceof VerificationProvider ) {
			return $provider;
		}

		return new UnavailableVerificationProvider(
			'invalid_provider',
			'The verification-provider extension point returned an invalid provider.'
		);
	}
}

// EOF: src/Verification/VerificationProviderResolver.php.
