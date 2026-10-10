<?php
/**
 * File: tests/Integration/EmailNamedTemplateLibraryTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationTemplateSettingsPage;
use ArgentWolf\PostNotifier\Content\EmailNamedTemplateLibrary;
use ArgentWolf\PostNotifier\Content\EmailTemplateSettings;
use ArgentWolf\PostNotifier\Editor\EditorTemplateCatalog;
use ArgentWolf\PostNotifier\Plugin;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * Qualify named template catalog persistence without mail or campaigns.
 */
final class EmailNamedTemplateLibraryTest extends WP_UnitTestCase {
	/**
	 * Isolate each test's shared site-wide catalog.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( EmailNamedTemplateLibrary::OPTION );
	}

	/**
	 * Leave the global catalog clear for subsequent integration tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( EmailNamedTemplateLibrary::OPTION );
		parent::tear_down();
	}

	/**
	 * Create, update and delete preserve stable, non-recycled identifiers.
	 *
	 * @return void
	 */
	public function test_stable_catalog_ids_and_editor_choices(): void {
		$library = $this->library();
		$first   = $library->create( 'Morning digest', $this->fields() );
		self::assertSame( EmailNamedTemplateLibrary::FIRST_ID, $first );
		self::assertSame( 'Morning digest', $library->find( $first )['name'] );

		$modified            = $this->fields();
		$modified['subject'] = 'From {{site_name}}';
		$library->update( $first, 'Afternoon digest', $modified );
		self::assertSame( 'From {{site_name}}', $library->find( $first )['settings']['subject'] );

		$catalog = Plugin::instance()->container()->get( EditorTemplateCatalog::class );
		self::assertInstanceOf( EditorTemplateCatalog::class, $catalog );
		$choices = $catalog->choices();
		self::assertSame( 0, $choices[0]['value'] );
		self::assertContains(
			array( 'value' => $first, 'label' => 'Afternoon digest' ),
			$choices
		);

		$library->delete( $first );
		self::assertNull( $library->find( $first ) );
		$second = $library->create( 'Second template', $this->fields() );
		self::assertSame( $first + 1, $second );
		self::assertNotContains(
			array( 'value' => $first, 'label' => 'Afternoon digest' ),
			$catalog->choices()
		);
	}

	/**
	 * Invalid fields and an unknown ID cannot partially change a stored entry.
	 *
	 * @return void
	 */
	public function test_invalid_inputs_fail_without_mutation(): void {
		$library = $this->library();
		$id      = $library->create( 'Original', $this->fields() );
		$before  = get_option( EmailNamedTemplateLibrary::OPTION );
		$cases   = array(
			array( 'name' => '', 'fields' => $this->fields() ),
			array( 'name' => 'Bad {{token}}', 'fields' => $this->fields() ),
			array( 'name' => str_repeat( 'n', 81 ), 'fields' => $this->fields() ),
		);
		$unknown_token            = $this->fields();
		$unknown_token['subject'] = '{{wp_eval}}';
		$cases[]                  = array( 'name' => 'Unsafe token', 'fields' => $unknown_token );
		foreach ( $cases as $case ) {
			try {
				$library->update( $id, $case['name'], $case['fields'] );
				self::fail( 'Invalid named template was accepted.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( $before, get_option( EmailNamedTemplateLibrary::OPTION ) );
			}
		}
		$this->expectException( InvalidArgumentException::class );
		$library->delete( $id + 500 );
	}

	/**
	 * A corrupted persisted catalog is never exposed or overwritten.
	 *
	 * @return void
	 */
	public function test_corrupt_option_fails_closed(): void {
		$library = $this->library();
		update_option(
			EmailNamedTemplateLibrary::OPTION,
			array(
				'next_id' => EmailNamedTemplateLibrary::FIRST_ID,
				'items'   => array(
					EmailNamedTemplateLibrary::FIRST_ID => array( 'id' => 17 ),
				),
			),
			false
		);
		self::assertSame( array(), $library->all() );
		$this->expectException( InvalidArgumentException::class );
		$library->create( 'Must fail', $this->fields() );
	}

	/**
	 * External editor extensions cannot impersonate plugin-owned template IDs.
	 *
	 * @return void
	 */
	public function test_reserved_ids_are_not_overridden_by_filter(): void {
		$library = $this->library();
		$id      = $library->create( 'Trusted name', $this->fields() );
		$filter  = static function ( array $candidates ) use ( $id ): array {
			$candidates[] = array( 'id' => $id, 'label' => 'Impersonated' );
			$candidates[] = array( 'id' => 17, 'label' => 'External choice' );
			return $candidates;
		};
		add_filter( EditorTemplateCatalog::FILTER, $filter );
		try {
			$choices = Plugin::instance()->container()->get( EditorTemplateCatalog::class )->choices();
		} finally {
			remove_filter( EditorTemplateCatalog::FILTER, $filter );
		}
		self::assertContains( array( 'value' => $id, 'label' => 'Trusted name' ), $choices );
		self::assertContains( array( 'value' => 17, 'label' => 'External choice' ), $choices );
		self::assertNotContains( array( 'value' => $id, 'label' => 'Impersonated' ), $choices );
	}

	/**
	 * A bounded option never expands beyond the documented limit.
	 *
	 * @return void
	 */
	public function test_capacity_limit_is_enforced(): void {
		$library = $this->library();
		for ( $index = 0; $index < EmailNamedTemplateLibrary::MAX_TEMPLATES; ++$index ) {
			$library->create( 'Named ' . $index, $this->fields() );
		}
		self::assertCount( EmailNamedTemplateLibrary::MAX_TEMPLATES, $library->all() );
		$this->expectException( InvalidArgumentException::class );
		$library->create( 'Beyond capacity', $this->fields() );
	}

	/**
	 * Forms require administrator permissions and an authenticated POST action.
	 *
	 * @return void
	 */
	public function test_named_admin_form_and_action(): void {
		self::assertNotFalse(
			has_action( 'admin_post_' . NotificationTemplateSettingsPage::NAMED_ACTION )
		);
		self::assertFalse(
			has_action( 'admin_post_nopriv_' . NotificationTemplateSettingsPage::NAMED_ACTION )
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->library()->create( 'Form entry', $this->fields() );
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		ob_start();
		Plugin::instance()->container()->get( NotificationTemplateSettingsPage::class )->render();
		$html = ob_get_clean();
		self::assertStringContainsString( NotificationTemplateSettingsPage::NAMED_ACTION, $html );
		self::assertStringContainsString( 'awpn_named_text[subject]', $html );
		self::assertStringContainsString( 'Form entry', $html );
		self::assertStringContainsString( 'awpn_named_delete', $html );
	}

	/**
	 * Retrieve the canonical container-backed library.
	 *
	 * @return EmailNamedTemplateLibrary
	 */
	private function library(): EmailNamedTemplateLibrary {
		$library = Plugin::instance()->container()->get( EmailNamedTemplateLibrary::class );
		self::assertInstanceOf( EmailNamedTemplateLibrary::class, $library );
		return $library;
	}

	/**
	 * Obtain fully valid canonical fields for each fixture.
	 *
	 * @return array<string,string>
	 */
	private function fields(): array {
		return ( new EmailTemplateSettings() )->defaults();
	}
}

// EOF: tests/Integration/EmailNamedTemplateLibraryTest.php.
