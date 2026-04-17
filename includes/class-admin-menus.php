<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Admin_Menus {

	public function __construct() {
		add_action( 'admin_menu',    array( $this, 'register_menus' ) );
		add_action( 'admin_menu',    array( $this, 'reorder_menus' ), 9999 );
		add_action( 'admin_notices', array( $this, 'new_events_notice' ) );
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
		add_submenu_page( 'booking-menu', 'Events',          'Events',          'manage_options', 'booking-menu',              array( $this, 'page_events' ) );
		add_submenu_page( 'booking-menu', 'Add New Event',   'Add New Event',   'manage_options', 'admin.php?page=booking-menu&add_event=1' );
		add_submenu_page( 'booking-menu', 'Marketers',       'Marketers',       'manage_options', 'marketer-menu',             array( $this, 'page_marketer' ) );
		add_submenu_page( 'booking-menu', 'Instructors',     'Instructors',     'manage_options', 'istructor-menu',            array( $this, 'page_instructor' ) );
		add_submenu_page( 'booking-menu', 'CVENT Sync',      'CVENT Sync',      'manage_options', 'cvent-sync',                array( $this, 'page_cvent_sync' ) );

		// New CVENT Events — show a badge count when new events are waiting.
		$new_count  = (int) get_option( 'hostlinks_cvent_new_count', 0 );
		$new_label  = 'New CVENT Events';
		if ( $new_count > 0 ) {
			$new_label .= ' <span class="awaiting-mod update-plugins" style="margin-left:4px;">'
				. esc_html( $new_count ) . '</span>';
		}
		add_submenu_page( 'booking-menu', 'New CVENT Events', $new_label,      'manage_options', 'cvent-new-events',          array( $this, 'page_cvent_new_events' ) );

		// New Event Queue — sits right after New CVENT Events. Slug preserved
		// as hostlinks-event-requests for URL backward compatibility.
		global $wpdb;
		$req_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}hostlinks_event_requests WHERE request_status = %s",
			'new'
		) );
		$req_label = 'New Event Queue';
		if ( $req_count > 0 ) {
			$req_label .= ' <span class="awaiting-mod update-plugins" style="margin-left:4px;">'
				. esc_html( $req_count ) . '</span>';
		}
		add_submenu_page( 'booking-menu', 'New Event Queue', $req_label, 'manage_options', 'hostlinks-event-requests', array( $this, 'page_event_requests' ) );

		// Settings just above Plugin Info
		add_submenu_page( 'booking-menu', 'Settings',        'Settings',        'manage_options', 'hostlinks-settings',        array( $this, 'page_settings' ) );
		add_submenu_page( 'booking-menu', 'Plugin Info',     'Plugin Info',     'manage_options', 'hostlinks-plugin-info',     array( $this, 'page_plugin_info' ) );

		// Hidden pages — registered with null parent so they are NEVER shown in the menu
		// but remain accessible via direct URL (e.g. when included from the Settings tabs).
		add_submenu_page( null, 'Type Settings',          'Type Settings',          'manage_options', 'types-menu',                       array( $this, 'page_types' ) );
		add_submenu_page( null, 'Import / Export',        'Import / Export',        'manage_options', 'hostlinks-import-export',          array( $this, 'page_import_export' ) );
		add_submenu_page( null, 'CVENT Settings',         'CVENT Settings',         'manage_options', 'cvent-settings',                   array( $this, 'page_cvent_settings' ) );
		add_submenu_page( null, 'Event Request Settings', 'Event Request Settings', 'manage_options', 'hostlinks-event-request-settings', array( $this, 'page_event_request_settings' ) );
		add_submenu_page( null, 'User Access',            'User Access',            'manage_options', 'hostlinks-user-access',            array( $this, 'page_user_access' ) );
		add_submenu_page( null, 'Event Roster',           'Event Roster',           'manage_options', 'hostlinks-roster',                 array( $this, 'page_roster' ) );
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

	public function page_event_requests() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/event-requests.php';
	}

	public function page_event_request_settings() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/event-request-settings.php';
	}

	public function page_user_access() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/user-access.php';
	}

	public function page_settings() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/settings.php';
	}

	public function page_plugin_info() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/plugin-info.php';
	}

	public function page_cvent_new_events() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/cvent-new-events.php';
	}

	public function page_roster() {
		include HOSTLINKS_PLUGIN_DIR . 'admin/roster.php';
	}

	/**
	 * Force Settings and Plugin Info to always be the last two items in the
	 * Hostlinks submenu, regardless of registration order quirks.
	 * Runs at priority 9999 so it fires after all other plugins and our own
	 * register_menus() have finished.
	 */
	public function reorder_menus() {
		global $submenu;
		if ( empty( $submenu['booking-menu'] ) ) {
			return;
		}

		$pinned_slugs = array( 'hostlinks-settings', 'hostlinks-plugin-info' );
		$pinned       = array();

		foreach ( $submenu['booking-menu'] as $key => $item ) {
			if ( in_array( $item[2], $pinned_slugs, true ) ) {
				$pinned[ $item[2] ] = $item;
				unset( $submenu['booking-menu'][ $key ] );
			}
		}

		// Re-index remaining items
		$submenu['booking-menu'] = array_values( $submenu['booking-menu'] );

		// Append in desired order: Settings → Plugin Info
		foreach ( $pinned_slugs as $slug ) {
			if ( isset( $pinned[ $slug ] ) ) {
				$submenu['booking-menu'][] = $pinned[ $slug ];
			}
		}
	}

	/**
	 * Show an admin notice on all admin pages when new CVENT events are detected.
	 * The notice links to the New CVENT Events review page.
	 */
	public function new_events_notice() {
		$count = (int) get_option( 'hostlinks_cvent_new_count', 0 );
		if ( $count <= 0 ) {
			return;
		}
		// Only show on Hostlinks admin pages (and the dashboard) to avoid noise.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		// Suppress on the New CVENT Events page itself (already has results there).
		if ( false !== strpos( $screen->id, 'cvent-new-events' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=cvent-new-events' );
		printf(
			'<div class="notice notice-warning"><p><strong>Hostlinks:</strong> %s <a href="%s">Review →</a></p></div>',
			sprintf(
				esc_html(
					_n(
						'%d new CVENT event detected that is not in Hostlinks.',
						'%d new CVENT events detected that are not in Hostlinks.',
						$count,
						'hostlinks'
					)
				),
				$count
			),
			esc_url( $url )
		);
	}
}

new Hostlinks_Admin_Menus();
