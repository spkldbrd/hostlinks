<?php
/**
 * Plugin Name: Hostlinks
 * Plugin URI:  https://digitalsolution.com
 * Description: Event management tool for tracking hosted events, marketers, instructors, and types.
 * Version:     2.3.0
 * Author:      Digital Solution
 * Author URI:  https://digitalsolution.com
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Temporary debug handler: captures the full call stack when preg_replace() receives
// null inside kses.php so we can identify the exact source of the PHP 8.1 deprecation.
// REMOVE this block once the source is identified.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
		if ( strpos( $errstr, 'preg_replace' ) !== false && strpos( $errfile, 'kses.php' ) !== false ) {
			$trace = array_map( function( $f ) {
				return ( $f['file'] ?? '?' ) . ':' . ( $f['line'] ?? '?' ) . ' ' . ( $f['function'] ?? '' );
			}, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) );
			error_log( '=== HOSTLINKS KSES NULL TRACE ===' );
			error_log( implode( "\n  ", $trace ) );
			error_log( '=================================' );
		}
		return false; // continue normal PHP error handling
	}, E_DEPRECATED | E_USER_DEPRECATED );
}

define( 'HOSTLINKS_VERSION',    '2.3.0' );
define( 'HOSTLINKS_DB_VERSION', '1.1' );
define( 'HOSTLINKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOSTLINKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'HOSTLINKS_GITHUB_USER', 'spkldbrd' );
define( 'HOSTLINKS_GITHUB_REPO', 'hostlinks' );

require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-db.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-activation.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-assets.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-admin-menus.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-import-export.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-updater.php';

// Activation: create DB tables + detect theme conflict in one hook
register_activation_hook( __FILE__, array( 'Hostlinks_Activation', 'on_activate' ) );

new Hostlinks_Activation();

Hostlinks_Updater::init( __FILE__, HOSTLINKS_GITHUB_USER, HOSTLINKS_GITHUB_REPO );

// Run schema upgrades on every load — safe because dbDelta only alters when needed
add_action( 'plugins_loaded', array( 'Hostlinks_DB', 'maybe_upgrade' ) );
