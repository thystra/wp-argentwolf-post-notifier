<?php
/**
 * File: src/Version.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier;

/**
 * Central plugin and schema versions.
 */
final class Version {
	/**
	 * Plugin version.
	 */
	public const PLUGIN = '0.1.0-alpha.5';

	/**
	 * Database schema version.
	 *
	 * Schema one introduces the initial plugin-owned data tables.
	 */
	public const SCHEMA = '1';

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Version.php.
