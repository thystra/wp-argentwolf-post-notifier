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
	public const PLUGIN = '0.1.0-beta.3';

	/**
	 * Database schema version.
	 *
	 * Schema two adds the privacy-conscious administrative audit-event ledger
	 * while preserving frozen schema one as immutable upgrade history.
	 */
	public const SCHEMA = '2';

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Version.php.
