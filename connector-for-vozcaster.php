<?php
/**
 * Plugin Name: Connector for VozCaster
 * Plugin URI:  https://vozcaster.com
 * Description: Connect your WordPress to the VozCaster Telegram bot — publish podcast episodes from voice notes directly into PowerPress.
 * Version:     1.6.1
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author:      Miguel Ángel Terrón Bote
 * Author URI:  https://potencia.pro
 * Text Domain: connector-for-vozcaster
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VPCONN_VERSION', '1.6.1' );
define( 'VPCONN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VPCONN_URL', plugin_dir_url( __FILE__ ) );
define( 'VPCONN_BASENAME', plugin_basename( __FILE__ ) );

require_once VPCONN_DIR . 'includes/class-auth.php';
require_once VPCONN_DIR . 'includes/class-media.php';
require_once VPCONN_DIR . 'includes/class-api.php';
require_once VPCONN_DIR . 'includes/class-settings.php';

register_activation_hook( __FILE__, 'vpconn_activate' );
register_deactivation_hook( __FILE__, 'vpconn_deactivate' );

function vpconn_activate(): void {
	VPConn_Settings::create_log_option();
}

function vpconn_deactivate(): void {
	// Data is not removed on deactivation; only on uninstall (uninstall.php).
}

/**
 * Runs one-off data migrations after the plugin files are updated.
 *
 * Plugin updates do not fire the activation hook, so schema-ish changes have to
 * be applied from a normal request, gated on the stored version.
 */
function vpconn_maybe_upgrade(): void {
	$stored = get_option( 'vpconn_version' );
	if ( VPCONN_VERSION === $stored ) {
		return;
	}

	// 1.5.15: the episode log no longer autoloads on every request.
	VPConn_Settings::migrate_log_autoload();

	update_option( 'vpconn_version', VPCONN_VERSION, false );
}

add_action( 'plugins_loaded', 'vpconn_init' );

function vpconn_init(): void {
	vpconn_maybe_upgrade();

	// Translations load automatically since WP 4.6 (Domain Path: /languages);
	// no manual load_plugin_textdomain() call is required.
	$auth     = new VPConn_Auth();
	$api      = new VPConn_API();
	$settings = new VPConn_Settings();

	$auth->register_hooks();
	$api->register_hooks();
	$settings->register_hooks();
}
