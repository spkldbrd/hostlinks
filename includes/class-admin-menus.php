<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Admin_Menus {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
	}

	public function register_menus() {
		// ── HOSTLINKS (top-level parent) ──────────────────────────────────────
		add_menu_page(
			'Hostlinks',
			'Hostlinks',
			'manage_options',
			'booking-menu',
			array( $this, 'page_events' ),
			HOSTLINKS_PLUGIN_URL . 'assets/images/booking.png'
		);

		// The first submenu entry replaces the auto-generated duplicate of the parent
		add_submenu_page( 'booking-menu', 'Events',        'Events',        'manage_options', 'booking-menu',             array( $this, 'page_events' ) );
		add_submenu_page( 'booking-menu', 'Add New Event', 'Add New Event', 'manage_options', 'admin.php?page=booking-menu&add=1' );
		add_submenu_page( 'booking-menu', 'Type Settings', 'Type Settings', 'manage_options', 'types-menu',               array( $this, 'page_types' ) );
		add_submenu_page( 'booking-menu', 'Add New Type',  'Add New Type',  'manage_options', 'admin.php?page=types-menu&add=1' );
		add_submenu_page( 'booking-menu', 'Marketers',     'Marketers',     'manage_options', 'marketer-menu',            array( $this, 'page_marketer' ) );
		add_submenu_page( 'booking-menu', 'Add Marketer',  'Add Marketer',  'manage_options', 'admin.php?page=marketer-menu&add=1' );
		add_submenu_page( 'booking-menu', 'Instructors',   'Instructors',   'manage_options', 'istructor-menu',           array( $this, 'page_instructor' ) );
		add_submenu_page( 'booking-menu', 'Add Instructor','Add Instructor','manage_options', 'admin.php?page=istructor-menu&add=1' );
		add_submenu_page( 'booking-menu', 'Import / Export','Import / Export','manage_options','hostlinks-import-export', array( $this, 'page_import_export' ) );
		add_submenu_page( 'booking-menu', 'CVENT Sync',    'CVENT Sync',    'manage_options','cvent-sync',              array( $this, 'page_cvent_sync' ) );
		add_submenu_page( 'booking-menu', 'CVENT Settings','CVENT Settings','manage_options','cvent-settings',          array( $this, 'page_cvent_settings' ) );
		add_submenu_page( 'booking-menu', 'Plugin Info',   'Plugin Info',   'manage_options','hostlinks-plugin-info',   array( $this, 'page_plugin_info' ) );
	}

	public function page_events() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/booking.php';
	}

	public function page_types() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/type-menu.php';
	}

	public function page_marketer() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/marketer-menu.php';
	}

	public function page_instructor() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/instructor-menu.php';
	}

	public function page_import_export() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/import-export.php';
	}

	public function page_cvent_sync() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/cvent-sync.php';
	}

	public function page_cvent_settings() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/cvent-settings.php';
	}

	public function page_plugin_info() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/plugin-info.php';
	}
}

new Hostlinks_Admin_Menus();
