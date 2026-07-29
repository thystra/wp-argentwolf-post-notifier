<?php
/**
 * File: src/Contracts/Registerable.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Contracts;

/**
 * A service that attaches its WordPress hooks.
 */
interface Registerable {
	/**
	 * Register the service with WordPress.
	 *
	 * @return void
	 */
	public function register(): void;
}

// EOF: src/Contracts/Registerable.php.
