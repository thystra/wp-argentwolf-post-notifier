<?php
/**
 * File: src/Admin/NamedListAdminPage.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use InvalidArgumentException;

/**
 * Administrator-only named-list and typed-membership management.
 */
final class NamedListAdminPage implements Registerable {
	/**
	 * Initial alpha list-management capability.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * WordPress administration page slug.
	 */
	public const PAGE_SLUG = 'argentwolf-post-notifier-lists';

	/**
	 * Authenticated admin-post action for list creation.
	 */
	public const CREATE_ACTION = 'argentwolf_post_notifier_create_list';

	/**
	 * Authenticated admin-post action for list updates.
	 */
	public const UPDATE_ACTION = 'argentwolf_post_notifier_update_list';

	/**
	 * Authenticated admin-post action for list deletion.
	 */
	public const DELETE_ACTION = 'argentwolf_post_notifier_delete_list';

	/**
	 * Authenticated admin-post action for adding one typed member.
	 */
	public const ADD_ACTION = 'argentwolf_post_notifier_add_list_member';

	/**
	 * Authenticated admin-post action for removing one membership.
	 */
	public const REMOVE_ACTION = 'argentwolf_post_notifier_remove_list_member';

	/**
	 * Construct the named-list administration page.
	 *
	 * @param NamedListRepository $repository  Named-list persistence.
	 * @param EmailIdentity       $identity    Canonical email identity helper.
	 * @param SuppressionService  $suppression Global suppression policy.
	 */
	public function __construct(
		private NamedListRepository $repository,
		private EmailIdentity $identity,
		private SuppressionService $suppression
	) {
	}

	/**
	 * Register administration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action(
			'admin_post_' . self::CREATE_ACTION,
			array( $this, 'handle_create' )
		);
		add_action(
			'admin_post_' . self::UPDATE_ACTION,
			array( $this, 'handle_update' )
		);
		add_action(
			'admin_post_' . self::DELETE_ACTION,
			array( $this, 'handle_delete' )
		);
		add_action(
			'admin_post_' . self::ADD_ACTION,
			array( $this, 'handle_add_member' )
		);
		add_action(
			'admin_post_' . self::REMOVE_ACTION,
			array( $this, 'handle_remove_member' )
		);
	}

	/**
	 * Register the Lists submenu below the plugin subscriber screen.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SubscriberAdminPage::PAGE_SLUG,
			__( 'Notification Lists', 'argentwolf-post-notifier' ),
			__( 'Lists', 'argentwolf-post-notifier' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the list index or one list editor.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$list_id = absint( $this->request_text( $_GET, 'list_id' ) );
		echo '<div class="wrap">';
		if ( $list_id > 0 ) {
			$this->render_editor( $list_id );
		} else {
			$this->render_index();
		}
		echo '</div>';
	}

	/**
	 * Create a named list from an authorized POST.
	 *
	 * @return never
	 */
	public function handle_create(): never {
		$this->require_post();
		check_admin_referer( self::CREATE_ACTION );

		try {
			$list_id = $this->repository->create(
				$this->request_text( $_POST, 'name' ),
				$this->request_text( $_POST, 'description' ),
				get_current_user_id()
			);
		} catch ( InvalidArgumentException ) {
			$this->redirect_index( 'invalid_list' );
		}

		$this->redirect_editor( $list_id, 'created' );
	}

	/**
	 * Update a named list from an authorized POST.
	 *
	 * @return never
	 */
	public function handle_update(): never {
		$this->require_post();
		check_admin_referer( self::UPDATE_ACTION );
		$list_id = absint( $this->request_text( $_POST, 'list_id' ) );
		if ( null === $this->repository->find( $list_id ) ) {
			$this->die_with_status(
				__( 'The notification list could not be found.', 'argentwolf-post-notifier' ),
				404
			);
		}

		try {
			$this->repository->update(
				$list_id,
				$this->request_text( $_POST, 'name' ),
				$this->request_text( $_POST, 'description' )
			);
		} catch ( InvalidArgumentException ) {
			$this->redirect_editor( $list_id, 'invalid_list' );
		}

		$this->redirect_editor( $list_id, 'updated' );
	}

	/**
	 * Delete one named list from an authorized POST.
	 *
	 * @return never
	 */
	public function handle_delete(): never {
		$this->require_post();
		check_admin_referer( self::DELETE_ACTION );
		$list_id = absint( $this->request_text( $_POST, 'list_id' ) );
		if ( null === $this->repository->find( $list_id ) ) {
			$this->die_with_status(
				__( 'The notification list could not be found.', 'argentwolf-post-notifier' ),
				404
			);
		}

		$this->repository->delete( $list_id );
		$this->redirect_index( 'deleted' );
	}

	/**
	 * Add one typed member from an authorized POST.
	 *
	 * @return never
	 */
	public function handle_add_member(): never {
		$this->require_post();
		check_admin_referer( self::ADD_ACTION );
		$list_id = absint( $this->request_text( $_POST, 'list_id' ) );
		$list    = $this->repository->find( $list_id );
		if ( null === $list ) {
			$this->die_with_status(
				__( 'The notification list could not be found.', 'argentwolf-post-notifier' ),
				404
			);
		}

		$type  = NamedListMemberType::tryFrom(
			$this->request_text( $_POST, 'member_type' )
		);
		$email = $this->identity->normalize(
			$this->request_text( $_POST, 'email' )
		);
		if ( null === $type || null === $email ) {
			$this->redirect_editor( $list_id, 'invalid_member' );
		}

		$entity_id = $this->repository->resolve_member_id( $type, $email );
		if ( null === $entity_id ) {
			$this->redirect_editor( $list_id, 'member_not_found' );
		}

		try {
			$inserted = $this->repository->add_member( $list_id, $type, $entity_id );
		} catch ( InvalidArgumentException ) {
			$this->redirect_editor( $list_id, 'invalid_member' );
		}

		$this->redirect_editor( $list_id, $inserted ? 'member_added' : 'member_exists' );
	}

	/**
	 * Remove one membership from an authorized POST.
	 *
	 * @return never
	 */
	public function handle_remove_member(): never {
		$this->require_post();
		check_admin_referer( self::REMOVE_ACTION );
		$list_id       = absint( $this->request_text( $_POST, 'list_id' ) );
		$membership_id = absint( $this->request_text( $_POST, 'membership_id' ) );
		if ( null === $this->repository->find( $list_id ) ) {
			$this->die_with_status(
				__( 'The notification list could not be found.', 'argentwolf-post-notifier' ),
				404
			);
		}

		$removed = $this->repository->remove_member( $list_id, $membership_id );
		$this->redirect_editor( $list_id, $removed ? 'member_removed' : 'member_missing' );
	}

	/**
	 * Render the list index and create-list form.
	 *
	 * @return void
	 */
	private function render_index(): void {
		echo '<h1>' . esc_html__( 'Notification Lists', 'argentwolf-post-notifier' ) . '</h1>';
		$this->render_notice();
		echo '<p>';
		echo esc_html__(
			'Lists organize recipients without changing verification, preferences, or suppression.',
			'argentwolf-post-notifier'
		);
		echo '</p>';

		echo '<h2>' . esc_html__( 'Create list', 'argentwolf-post-notifier' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CREATE_ACTION ) . '">';
		wp_nonce_field( self::CREATE_ACTION );
		$this->render_list_fields( '', '' );
		submit_button( __( 'Create list', 'argentwolf-post-notifier' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Existing lists', 'argentwolf-post-notifier' ) . '</h2>';
		$lists = $this->repository->all();
		if ( array() === $lists ) {
			echo '<p>';
			echo esc_html__( 'No lists have been created yet.', 'argentwolf-post-notifier' );
			echo '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Members', 'argentwolf-post-notifier' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $lists as $list ) {
			$url = add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'list_id' => (int) $list['id'],
				),
				admin_url( 'admin.php' )
			);
			echo '<tr><td><a href="' . esc_url( $url ) . '">';
			echo esc_html( (string) $list['name'] );
			echo '</a></td><td>' . esc_html( (string) ( $list['description'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $list['member_count'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render one list editor and typed membership controls.
	 *
	 * @param int $list_id List row ID.
	 * @return void
	 */
	private function render_editor( int $list_id ): void {
		$list = $this->repository->find( $list_id );
		if ( null === $list ) {
			wp_die(
				esc_html__(
					'The notification list could not be found.',
					'argentwolf-post-notifier'
				),
				'',
				array( 'response' => 404 )
			);
		}

		echo '<h1>' . esc_html__( 'Edit Notification List', 'argentwolf-post-notifier' ) . '</h1>';
		$this->render_notice();
		echo '<p><a href="' . esc_url( $this->index_url() ) . '">';
		echo esc_html__( 'Back to lists', 'argentwolf-post-notifier' );
		echo '</a></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::UPDATE_ACTION ) . '">';
		echo '<input type="hidden" name="list_id" value="' . esc_attr( (string) $list_id ) . '">';
		wp_nonce_field( self::UPDATE_ACTION );
		$this->render_list_fields(
			(string) $list['name'],
			(string) ( $list['description'] ?? '' )
		);
		submit_button( __( 'Save list', 'argentwolf-post-notifier' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE_ACTION ) . '">';
		echo '<input type="hidden" name="list_id" value="' . esc_attr( (string) $list_id ) . '">';
		wp_nonce_field( self::DELETE_ACTION );
		submit_button( __( 'Delete list', 'argentwolf-post-notifier' ), 'delete', 'submit', false );
		echo ' <span class="description">';
		echo esc_html__(
			'Deleting a list removes only its memberships; contacts and subscriptions remain.',
			'argentwolf-post-notifier'
		);
		echo '</span></form>';

		echo '<hr><h2>' . esc_html__( 'Add member', 'argentwolf-post-notifier' ) . '</h2>';
		echo '<p>';
		echo esc_html__(
			'Adding a member does not subscribe, resubscribe, or unsuppress the address.',
			'argentwolf-post-notifier'
		);
		echo '</p>';
		$this->render_add_member_form( $list_id );
		$this->render_members( $list_id );
	}

	/**
	 * Render shared list name and description fields.
	 *
	 * @param string $name        Current list name.
	 * @param string $description Current description.
	 * @return void
	 */
	private function render_list_fields( string $name, string $description ): void {
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="arpn-list-name">';
		echo esc_html__( 'Name', 'argentwolf-post-notifier' );
		echo '</label></th><td><input class="regular-text" id="arpn-list-name" name="name"';
		echo ' type="text" maxlength="191" required value="' . esc_attr( $name ) . '"></td>';
		echo '</tr><tr><th scope="row"><label for="arpn-list-description">';
		echo esc_html__( 'Description', 'argentwolf-post-notifier' );
		echo '</label></th><td><textarea class="large-text" id="arpn-list-description"';
		echo ' name="description" rows="3">' . esc_textarea( $description ) . '</textarea></td>';
		echo '</tr></tbody></table>';
	}

	/**
	 * Render the typed member-add form.
	 *
	 * @param int $list_id List row ID.
	 * @return void
	 */
	private function render_add_member_form( int $list_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD_ACTION ) . '">';
		echo '<input type="hidden" name="list_id" value="' . esc_attr( (string) $list_id ) . '">';
		wp_nonce_field( self::ADD_ACTION );
		echo '<label for="arpn-list-member-type">';
		echo esc_html__( 'Member type', 'argentwolf-post-notifier' );
		echo '</label> <select id="arpn-list-member-type" name="member_type">';
		echo '<option value="' . esc_attr( NamedListMemberType::User->value ) . '">';
		echo esc_html__( 'WordPress user', 'argentwolf-post-notifier' );
		echo '</option><option value="' . esc_attr( NamedListMemberType::Subscriber->value ) . '">';
		echo esc_html__( 'Standalone subscriber', 'argentwolf-post-notifier' );
		echo '</option></select> ';
		echo '<label for="arpn-list-member-email">';
		echo esc_html__( 'Email', 'argentwolf-post-notifier' );
		echo '</label> <input id="arpn-list-member-email" name="email" type="email" required> ';
		submit_button(
			__( 'Add member', 'argentwolf-post-notifier' ),
			'secondary',
			'submit',
			false
		);
		echo '</form>';
	}

	/**
	 * Render current typed list memberships.
	 *
	 * @param int $list_id List row ID.
	 * @return void
	 */
	private function render_members( int $list_id ): void {
		$members = $this->repository->members( $list_id );
		echo '<h2>' . esc_html__( 'Members', 'argentwolf-post-notifier' ) . '</h2>';
		if ( array() === $members ) {
			echo '<p>';
			echo esc_html__( 'This list has no members.', 'argentwolf-post-notifier' );
			echo '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Type', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Suppressed', 'argentwolf-post-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'argentwolf-post-notifier' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $members as $member ) {
			$this->render_member_row( $list_id, $member );
		}
		echo '</tbody></table>';
	}

	/**
	 * Render one typed membership row.
	 *
	 * @param int                 $list_id List row ID.
	 * @param array<string,mixed> $member  Membership row.
	 * @return void
	 */
	private function render_member_row( int $list_id, array $member ): void {
		$type       = NamedListMemberType::tryFrom( (string) $member['member_type'] );
		$email      = (string) ( $member['email'] ?? '' );
		$state      = NamedListMemberType::Subscriber === $type
			? (string) ( $member['subscriber_status'] ?? '' )
			: __( 'WordPress user', 'argentwolf-post-notifier' );
		$suppressed = '' !== $email && $this->suppression->is_suppressed( $email );

		echo '<tr><td>';
		echo esc_html( null === $type ? '' : $type->value );
		echo '</td><td>' . esc_html( $email ) . '</td>';
		echo '<td>' . esc_html( (string) ( $member['display_name'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( $state ) . '</td><td>';
		echo $suppressed
			? esc_html__( 'Yes', 'argentwolf-post-notifier' )
			: esc_html__( 'No', 'argentwolf-post-notifier' );
		echo '</td><td>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REMOVE_ACTION ) . '">';
		echo '<input type="hidden" name="list_id" value="' . esc_attr( (string) $list_id ) . '">';
		echo '<input type="hidden" name="membership_id" value="';
		echo esc_attr( (string) (int) $member['membership_id'] ) . '">';
		wp_nonce_field( self::REMOVE_ACTION );
		submit_button( __( 'Remove', 'argentwolf-post-notifier' ), 'small', 'submit', false );
		echo '</form></td></tr>';
	}

	/**
	 * Render an operation result notice from the redirect query string.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect-only notice key.
		$notice   = sanitize_key( $this->request_text( $_GET, 'arpn_notice' ) );
		$messages = array(
			'created'          => __( 'Notification list created.', 'argentwolf-post-notifier' ),
			'updated'          => __( 'Notification list updated.', 'argentwolf-post-notifier' ),
			'deleted'          => __( 'Notification list deleted.', 'argentwolf-post-notifier' ),
			'invalid_list'     => __( 'Enter a valid list name.', 'argentwolf-post-notifier' ),
			'member_added'     => __( 'List member added.', 'argentwolf-post-notifier' ),
			'member_exists'    => __(
				'That member is already on this list.',
				'argentwolf-post-notifier'
			),
			'member_removed'   => __( 'List member removed.', 'argentwolf-post-notifier' ),
			'member_missing'   => __(
				'That list membership no longer exists.',
				'argentwolf-post-notifier'
			),
			'invalid_member'   => __(
				'Enter a valid member type and email.',
				'argentwolf-post-notifier'
			),
			'member_not_found' => __( 'No matching member was found.', 'argentwolf-post-notifier' ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$class = str_starts_with( $notice, 'invalid_' ) || 'member_not_found' === $notice
			? 'notice notice-error'
			: 'notice notice-success is-dismissible';
		echo '<div class="' . esc_attr( $class ) . '"><p>';
		echo esc_html( $messages[ $notice ] );
		echo '</p></div>';
	}

	/**
	 * Require an administrator POST request.
	 *
	 * @return void
	 */
	private function require_post(): void {
		$this->require_capability();
		if ( 'POST' !== $this->request_method() ) {
			$this->die_with_status(
				__( 'This list action requires a POST request.', 'argentwolf-post-notifier' ),
				405
			);
		}
	}

	/**
	 * Require list-management authorization.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			$this->die_with_status(
				__(
					'You are not allowed to manage notification lists.',
					'argentwolf-post-notifier'
				),
				403
			);
		}
	}

	/**
	 * Redirect to the list index with a result notice.
	 *
	 * @param string $notice Notice key.
	 * @return never
	 */
	private function redirect_index( string $notice ): never {
		wp_safe_redirect( add_query_arg( 'arpn_notice', $notice, $this->index_url() ) );
		exit;
	}

	/**
	 * Redirect to one list editor with a result notice.
	 *
	 * @param int    $list_id List row ID.
	 * @param string $notice  Notice key.
	 * @return never
	 */
	private function redirect_editor( int $list_id, string $notice ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'list_id'     => $list_id,
					'arpn_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Return the list-index URL.
	 *
	 * @return string
	 */
	private function index_url(): string {
		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Read one scalar request value with WordPress unslashing.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @param string              $key     Request field.
	 * @return string
	 */
	private function request_text( array $request, string $key ): string {
		$value = $request[ $key ] ?? '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Return the normalized request method.
	 *
	 * @return string
	 */
	private function request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		return strtoupper( $method );
	}

	/**
	 * Terminate an invalid administration request with an HTTP status.
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	private function die_with_status( string $message, int $status ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTTP status metadata.
		wp_die( esc_html( $message ), '', array( 'response' => $status ) );
	}
}

// EOF: src/Admin/NamedListAdminPage.php.
