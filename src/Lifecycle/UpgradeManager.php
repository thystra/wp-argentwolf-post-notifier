<?php
/**
 * File: src/Lifecycle/UpgradeManager.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Lifecycle;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Version;

/**
 * Idempotent plugin-version and schema-version coordinator.
 */
final class UpgradeManager implements Registerable {
	/**
	 * Construct the upgrade coordinator.
	 *
	 * @param SchemaMigrator|null $schema_migrator Optional schema migrator.
	 */
	public function __construct( private ?SchemaMigrator $schema_migrator = null ) {
	}

	/**
	 * Register the upgrade check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Apply pending schema migrations, then record the current plugin version.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$installed_plugin = (string) get_option(
			'argentwolf_post_notifier_version',
			''
		);
		$installed_schema = (string) get_option(
			SchemaMigrator::SCHEMA_OPTION,
			'0'
		);

		if ( Version::SCHEMA !== $installed_schema ) {
			$migrator = $this->schema_migrator ?? new SchemaMigrator();
			$migrator->migrate();
		}

		if ( Version::PLUGIN !== $installed_plugin ) {
			update_option(
				'argentwolf_post_notifier_version',
				Version::PLUGIN,
				false
			);
		}
	}
}

// EOF: src/Lifecycle/UpgradeManager.php.
