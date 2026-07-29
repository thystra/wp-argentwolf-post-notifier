<?php
/**
 * File: tests/bootstrap-unit.php
 *
 * @package ArgentWolf\PostNotifier\Tests
 */

$composer = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( is_readable( $composer ) ) {
	require_once $composer;
} else {
	require_once dirname( __DIR__ ) . '/autoload.php';
}

// EOF: tests/bootstrap-unit.php
