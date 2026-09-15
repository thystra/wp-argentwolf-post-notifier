<?php
/**
 * File: src/Database/Migration.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

/**
 * Versioned database migration contract.
 */
interface Migration {
	/**
	 * Apply the migration.
	 *
	 * @return void
	 */
	public function up(): void;

	/**
	 * Verify the migration's resulting schema without advancing state.
	 *
	 * @return void
	 */
	public function verify(): void;
}

// EOF: src/Database/Migration.php.
