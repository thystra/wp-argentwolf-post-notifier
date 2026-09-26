<?php
/**
 * File: src/Admin/UserNotificationPreferenceProfile.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use WP_User;

/**
 * Self-service registered-user notification preference on the WordPress profile.
 */
final class UserNotificationPreferenceProfile implements Registerable {
	/**
	 * Profile form field name.
	 */
	public const FIELD_NAME = 'argentwolf_post_notifier_subscription_preference';

	/**
	 * Profile-form nonce field.
	 */
	public const NONCE_FIELD = 'argentwolf_post_notifier_preference_nonce';

	/**
	 * Profile-form nonce action prefix.
	 */
	public const NONCE_ACTION = 'argentwolf_post_notifier_save_preference';

	/**
	 * Construct the profile integration.
	 *
	 * @param RegisteredUserPreferenceRepository $preferences Preference persistence.
	 */
	public function __construct(
		private RegisteredUserPreferenceRepository $preferences
	) {
	}

	/**
	 * Register WordPress profile hooks.
	 *
	 * The preference is deliberately self-service. Editing another user's profile
	 * does not expose an administrator override that could silently reverse a
	 * user's explicit unsubscribe choice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
	}

	/**
	 * Render the current user's notification preference control.
	 *
	 * @param WP_User $user User whose own profile is being shown.
	 * @return void
	 */
	public function render( WP_User $user ): void {
		$user_id = (int) $user->ID;
		if ( get_current_user_id() !== $user_id ) {
			return;
		}

		$preference         = $this->preferences->get( $user_id );
		$site_default_value = RegisteredUserPreference::SiteDefault->value;
		$subscribed_value   = RegisteredUserPreference::Subscribed->value;
		$unsubscribed_value = RegisteredUserPreference::Unsubscribed->value;

		wp_nonce_field( $this->nonce_action( $user_id ), self::NONCE_FIELD );
		?>
		<h2><?php esc_html_e( 'Post notifications', 'argentwolf-post-notifier' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( self::FIELD_NAME ); ?>">
						<?php
						esc_html_e(
							'Email notification preference',
							'argentwolf-post-notifier'
						);
						?>
					</label>
				</th>
				<td>
					<select
						name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
						id="<?php echo esc_attr( self::FIELD_NAME ); ?>"
					>
						<option
							value="<?php echo esc_attr( $site_default_value ); ?>"
							<?php selected( $preference->value, $site_default_value ); ?>
						>
							<?php esc_html_e( 'Use site default', 'argentwolf-post-notifier' ); ?>
						</option>
						<option
							value="<?php echo esc_attr( $subscribed_value ); ?>"
							<?php selected( $preference->value, $subscribed_value ); ?>
						>
							<?php
							esc_html_e(
								'Receive post notifications',
								'argentwolf-post-notifier'
							);
							?>
						</option>
						<option
							value="<?php echo esc_attr( $unsubscribed_value ); ?>"
							<?php selected( $preference->value, $unsubscribed_value ); ?>
						>
							<?php
							esc_html_e(
								'Do not receive post notifications',
								'argentwolf-post-notifier'
							);
							?>
						</option>
					</select>
					<p class="description">
						<?php
						esc_html_e(
							'Email verification and global suppression still apply.',
							'argentwolf-post-notifier'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the current user's notification preference from the core profile form.
	 *
	 * WordPress verifies the profile-form nonce before firing
	 * `personal_options_update`. This handler additionally requires that the
	 * current user is saving their own profile and accepts only the defined enum
	 * values.
	 *
	 * @param int $user_id WordPress user ID being updated.
	 * @return void
	 */
	public function save( int $user_id ): void {
		if ( get_current_user_id() !== $user_id ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$nonce = sanitize_text_field(
			wp_unslash( (string) $_POST[ self::NONCE_FIELD ] )
		);
		if ( ! wp_verify_nonce( $nonce, $this->nonce_action( $user_id ) ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::FIELD_NAME ] ) ) {
			return;
		}

		$value      = sanitize_text_field(
			wp_unslash( (string) $_POST[ self::FIELD_NAME ] )
		);
		$preference = RegisteredUserPreference::tryFrom( $value );
		if ( null === $preference ) {
			return;
		}

		$this->preferences->set( $user_id, $preference );
	}

	/**
	 * Build a nonce action bound to one user's own profile.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private function nonce_action( int $user_id ): string {
		return self::NONCE_ACTION . ':' . $user_id;
	}
}

// EOF: src/Admin/UserNotificationPreferenceProfile.php.
