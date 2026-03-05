<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Shortcodes {

	public function __construct() {
		add_shortcode( 'eventlisto',    array( $this, 'render_eventlisto' ) );
		add_shortcode( 'oldeventlisto', array( $this, 'render_old_eventlisto' ) );
	}

	public function render_eventlisto() {
		global $wpdb, $post;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/initial_eventlisto.php';
		return ob_get_clean();
	}

	public function render_old_eventlisto() {
		global $wpdb, $post;
		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/old_eventlisto.php';
		return ob_get_clean();
	}
}

new Hostlinks_Shortcodes();
