<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Shortcodes {

	public function __construct() {
		add_shortcode( 'eventlisto',        array( $this, 'render_eventlisto' ) );
		add_shortcode( 'oldeventlisto',     array( $this, 'render_old_eventlisto' ) );
		add_shortcode( 'hostlinks_reports', array( $this, 'render_reports' ) );
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
}

new Hostlinks_Shortcodes();
