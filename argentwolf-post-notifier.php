<?php
/**
 * Plugin Name: ArgentWolf Post Notifier
 * Plugin URI: https://github.com/thystra/wp-argentwolf-post-notifier
 * Description: Scaffold for verified post notifications sent after posts are published.
 * Version: 0.1.0-alpha.2
 * Requires at least: 7.0
 * Requires PHP: 8.4
 * Author: Alan Johnson
 * Author URI: https://www.wolfandraven.blog/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: argentwolf-post-notifier
 * Domain Path: /languages
 *
 * File: argentwolf-post-notifier.php
 *
 * @package ArgentWolf\PostNotifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
	add_action( 'admin_notices', 'argentwolf_post_notifier_php_requirement_notice' );
	return;
}

global $wp_version;

if ( isset( $wp_version ) && version_compare( (string) $wp_version, '7.0', '<' ) ) {
	add_action( 'admin_notices', 'argentwolf_post_notifier_wordpress_requirement_notice' );
	return;
}

$argentwolf_post_notifier_composer = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $argentwolf_post_notifier_composer ) ) {
	require_once $argentwolf_post_notifier_composer;
} else {
	require_once __DIR__ . '/autoload.php';
}

use ArgentWolf\PostNotifier\Lifecycle\Activator;
use ArgentWolf\PostNotifier\Lifecycle\Deactivator;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Version;

define( 'ARGENTWOLF_POST_NOTIFIER_VERSION', Version::PLUGIN );
define( 'ARGENTWOLF_POST_NOTIFIER_SCHEMA_VERSION', Version::SCHEMA );
define( 'ARGENTWOLF_POST_NOTIFIER_FILE', __FILE__ );
define( 'ARGENTWOLF_POST_NOTIFIER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARGENTWOLF_POST_NOTIFIER_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

Plugin::instance()->register();

/**
 * Display the minimum-PHP requirement notice.
 *
 * @return void
 */
function argentwolf_post_notifier_php_requirement_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__(
		'ArgentWolf Post Notifier requires PHP 8.4 or newer.',
		'argentwolf-post-notifier'
	);
	echo '</p></div>';
}

/**
 * Display the minimum-WordPress requirement notice.
 *
 * @return void
 */
function argentwolf_post_notifier_wordpress_requirement_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__(
		'ArgentWolf Post Notifier requires WordPress 7.0 or newer.',
		'argentwolf-post-notifier'
	);
	echo '</p></div>';
}

// EOF: argentwolf-post-notifier.php.
