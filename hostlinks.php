<?php
/**
 * Plugin Name: Hostlinks
 * Plugin URI:  https://digitalsolution.com
 * Description: Event management tool for tracking hosted events, marketers, instructors, and types.
 * Version:     2.4.26
 * Author:      Digital Solution
 * Author URI:  https://digitalsolution.com
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HOSTLINKS_VERSION',    '2.4.26' );
define( 'HOSTLINKS_DB_VERSION', '1.2' );
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
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-cvent-api.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-cvent-matcher.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-cvent-sync.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-cvent-scheduler.php';

// Activation: create DB tables + detect theme conflict in one hook
register_activation_hook( __FILE__, array( 'Hostlinks_Activation', 'on_activate' ) );

new Hostlinks_Activation();

Hostlinks_Updater::init( __FILE__, HOSTLINKS_GITHUB_USER, HOSTLINKS_GITHUB_REPO );

// Run schema upgrades on every load — safe because dbDelta only alters when needed
add_action( 'plugins_loaded', array( 'Hostlinks_DB', 'maybe_upgrade' ) );

// CVENT daily sync scheduler
Hostlinks_CVENT_Scheduler::init();
