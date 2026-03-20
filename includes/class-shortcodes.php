<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Shortcodes {

	public function __construct() {
		add_shortcode( 'eventlisto',          array( $this, 'render_eventlisto' ) );
		add_shortcode( 'oldeventlisto',       array( $this, 'render_old_eventlisto' ) );
		add_shortcode( 'hostlinks_reports',   array( $this, 'render_reports' ) );
		add_shortcode( 'public_event_list',   array( $this, 'render_public_event_list' ) );
		add_shortcode( 'hostlinks_roster',    array( $this, 'render_roster' ) );
		add_action( 'wp_ajax_hostlinks_get_roster',        array( $this, 'ajax_get_roster' ) );
		add_action( 'wp_ajax_nopriv_hostlinks_get_roster', array( $this, 'ajax_get_roster' ) );
	}

	public function render_eventlisto() {
		if ( ! Hostlinks_Access::can_view_shortcode( 'eventlisto' ) ) {
			return Hostlinks_Access::get_denial_message_html();
		}
		global $wpdb, $post;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/initial_eventlisto.php';
		return ob_get_clean();
	}

	public function render_old_eventlisto() {
		if ( ! Hostlinks_Access::can_view_shortcode( 'oldeventlisto' ) ) {
			return Hostlinks_Access::get_denial_message_html();
		}
		global $wpdb, $post;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/old_eventlisto.php';
		return ob_get_clean();
	}

	public function render_reports() {
		if ( ! Hostlinks_Access::can_view_shortcode( 'hostlinks_reports' ) ) {
			return Hostlinks_Access::get_denial_message_html();
		}
		global $wpdb, $post;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/reports.php';
		return ob_get_clean();
	}

	public function render_public_event_list( $atts ) {
		// Always public — no access control check.
		include_once HOSTLINKS_PLUGIN_DIR . 'shortcode/public-event-list.php';
		return hostlinks_public_event_list_shortcode( $atts );
	}

	public function render_roster() {
		if ( ! Hostlinks_Access::can_view_shortcode( 'hostlinks_roster' ) ) {
			return Hostlinks_Access::get_denial_message_html();
		}
		global $wpdb;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/roster.php';
		return ob_get_clean();
	}

	public function ajax_get_roster() {
		// Verify nonce.
		if ( empty( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'hostlinks_roster_fetch' ) ) {
			wp_send_json_error( 'Invalid request.' );
		}
		// Check access.
		if ( ! Hostlinks_Access::can_view_shortcode( 'hostlinks_roster' ) ) {
			wp_send_json_error( 'Access denied.' );
		}

		$eve_id = isset( $_GET['eve_id'] ) ? (int) $_GET['eve_id'] : 0;
		if ( ! $eve_id ) {
			wp_send_json_error( 'No event specified.' );
		}

		global $wpdb;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/roster-content.php';
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}
}

new Hostlinks_Shortcodes();
