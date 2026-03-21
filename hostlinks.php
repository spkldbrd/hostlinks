<?php
/**
 * Plugin Name: Hostlinks
 * Plugin URI:  https://digitalsolution.com
 * Description: Event management tool for tracking hosted events, marketers, instructors, and types.
 * Version:     2.5.93
 * Author:      Digital Solution
 * Author URI:  https://digitalsolution.com
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HOSTLINKS_VERSION',    '2.5.93' );
define( 'HOSTLINKS_DB_VERSION', '1.9' );
define( 'HOSTLINKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOSTLINKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'HOSTLINKS_GITHUB_USER', 'spkldbrd' );
define( 'HOSTLINKS_GITHUB_REPO', 'hostlinks' );

require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-db.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-page-urls.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-access.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-activation.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-assets.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-admin-menus.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-event-request.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-event-request-storage.php';
require_once HOSTLINKS_PLUGIN_DIR . 'includes/class-event-request-shortcode.php';
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

// Front-end access control (registers AJAX search hook)
Hostlinks_Access::init();

// Event request intake shortcode + submission handler
new Hostlinks_Event_Request_Shortcode();

// CVENT daily sync scheduler
Hostlinks_CVENT_Scheduler::init();

// Roster finalize cron: re-fetch and permanently cache attendees 5 days after event end.
add_action( 'hostlinks_roster_finalize', function( $cvent_id, $eve_id ) {
	$cache_key     = 'hostlinks_roster_' . md5( $cvent_id );
	delete_transient( $cache_key );
	$attendees_raw = Hostlinks_CVENT_API::get_roster_attendees( $cvent_id );
	if ( ! is_wp_error( $attendees_raw ) ) {
		set_transient( $cache_key, $attendees_raw, 0 ); // 0 = permanent
	}
}, 10, 2 );
