<?php
/**
 * Plugin Name: Hostlinks
 * Plugin URI:  https://github.com/spkldbrd/hostlinks
 * Description: Event management tool for tracking hosted events, marketers, instructors, and types.
 * Version:     2.1.3
 * Author:      Digital Solution
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HOSTLINKS_VERSION',    '2.1.3' );
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

// Stored in a global so the Plugin Info admin page can call fetch/get methods
$GLOBALS['hostlinks_updater'] = new Hostlinks_Updater( __FILE__, HOSTLINKS_GITHUB_USER, HOSTLINKS_GITHUB_REPO );
