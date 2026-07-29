<?php
/**
 * Lightweight source/package autoloader.
 *
 * Composer's generated autoloader is preferred whenever it is available.
 * This fallback keeps the reviewed distribution independent of development
 * dependencies because the initial plugin has no third-party runtime package.
 *
 * File: autoload.php
 *
 * @package ArgentWolf\PostNotifier
 */

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ArgentWolf\\PostNotifier\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// EOF: autoload.php.
