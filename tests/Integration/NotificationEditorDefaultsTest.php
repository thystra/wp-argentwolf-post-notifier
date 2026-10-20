<?php
/**
 * File: tests/Integration/NotificationEditorDefaultsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Settings\NotificationDefaults;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Per-post native editor selections must match the canonical send policy.
 */
final class NotificationEditorDefaultsTest extends WP_UnitTestCase {
	/**
	 * Preserve the previous site default option for other tests.
	 *
	 * @var mixed
	 */
	private mixed $previous = null;

	/**
	 * Save the site default before tests modify it.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->previous = get_option( NotificationDefaults::OPTION, null );
	}

	/**
	 * Restore site notification defaults.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null === $this->previous ) {
			delete_option( NotificationDefaults::OPTION );
		} else {
			update_option( NotificationDefaults::OPTION, $this->previous, false );
		}
		parent::tear_down();
	}

	/**
	 * Older post configurations remain backward-compatible.
	 *
	 * @return void
	 */
	public function test_old_audiences_default_to_no_explicit_all_override(): void {
		$config = PostNotificationMeta::sanitize_audience_config(
			array( 'named_list_ids' => array( 4 ) )
		);
		self::assertFalse( $config['include_all_subscribers'] );
		self::assertSame( array( 4 ), $config['named_list_ids'] );
	}

	/**
	 * An explicit All override must preserve exclusions and override even an
	 * unavailable named-list default (without silently using that list).
	 *
	 * @return void
	 */
	public function test_explicit_all_overrides_named_list_default_and_preserves_exclusions(): void {
		update_option(
			NotificationDefaults::OPTION,
			array(
				'send'     => true,
				'audience' => NotificationDefaults::AUDIENCE_LIST,
				'list_id'  => 999999999,
			),
			false
		);
		$config                            = PostNotificationMeta::default_audience_config();
		$config['include_all_subscribers'] = true;
		$config['included_subscriber_ids'] = array( 13 );
		$config['excluded_user_ids']       = array( 42 );

		$effective = ( new NotificationDefaults() )->resolve_audience(
			$config,
			new NamedListRepository()
		);
		self::assertTrue( $effective['include_all_subscribers'] );
		self::assertSame( array(), $effective['included_subscriber_ids'] );
		self::assertSame( array(), $effective['named_list_ids'] );
		self::assertSame( array( 42 ), $effective['excluded_user_ids'] );
	}

	/**
	 * The default named-list policy still fails closed when there is no override.
	 *
	 * @return void
	 */
	public function test_missing_default_list_still_fails_closed_without_override(): void {
		update_option(
			NotificationDefaults::OPTION,
			array(
				'send'     => true,
				'audience' => NotificationDefaults::AUDIENCE_LIST,
				'list_id'  => 999999999,
			),
			false
		);
		$this->expectException( RuntimeException::class );
		( new NotificationDefaults() )->resolve_audience(
			PostNotificationMeta::default_audience_config(),
			new NamedListRepository()
		);
	}

	/**
	 * The REST contract protects the explicit all flag as a strict boolean.
	 *
	 * @return void
	 */
	public function test_explicit_all_flag_is_boolean_and_declared_in_rest_schema(): void {
		$schema = PostNotificationMeta::audience_rest_schema();
		self::assertSame(
			array( 'type' => 'boolean' ),
			$schema['properties']['include_all_subscribers']
		);
		self::assertFalse(
			PostNotificationMeta::sanitize_audience_config(
				array( 'include_all_subscribers' => 'true' )
			)['include_all_subscribers']
		);
		self::assertTrue(
			PostNotificationMeta::sanitize_audience_config(
				array( 'include_all_subscribers' => true )
			)['include_all_subscribers']
		);
	}
}

// EOF: tests/Integration/NotificationEditorDefaultsTest.php.
