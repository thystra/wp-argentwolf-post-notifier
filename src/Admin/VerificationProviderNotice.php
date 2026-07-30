<?php
/**
 * File: src/Admin/VerificationProviderNotice.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use Closure;

/**
 * Warn administrators when registered-user verification is unavailable.
 */
final class VerificationProviderNotice implements Registerable {
	/**
	 * Provider resolver.
	 *
	 * @var Closure
	 */
	private Closure $provider_resolver;

	/**
	 * Construct the notice service.
	 *
	 * Resolution is deferred until notice rendering so all plugins have had an
	 * opportunity to register the alternate-provider filter.
	 *
	 * @param Closure $provider_resolver Provider resolver.
	 */
	public function __construct( Closure $provider_resolver ) {
		$this->provider_resolver = $provider_resolver;
	}

	/**
	 * Register the administrator notice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render a provider warning for administrators.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$provider = ( $this->provider_resolver )();
		if ( ! $provider instanceof VerificationProvider ) {
			return;
		}

		$health = $provider->health();
		if ( $health->is_healthy() ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: provider description, 2: provider health message. */
			__(
				'Registered-user delivery is disabled: %1$s. %2$s',
				'argentwolf-post-notifier'
			),
			$health->description(),
			$health->message()
		);

		echo '<div class="notice notice-warning"><p>';
		echo esc_html( $message );
		echo '</p></div>';
	}
}

// EOF: src/Admin/VerificationProviderNotice.php.
