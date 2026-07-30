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
	public const PLUGIN = '0.1.0-alpha.2';

	/**
	 * Database schema version.
	 *
	 * Schema zero means that no custom tables have been introduced.
	 */
	public const SCHEMA = '0';

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Version.php.
