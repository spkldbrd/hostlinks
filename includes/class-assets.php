<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	public function enqueue_frontend() {
		global $post;
		// Only load on pages that contain a Hostlinks shortcode.
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! has_shortcode( $post->post_content, 'eventlisto' ) &&
		     ! has_shortcode( $post->post_content, 'oldeventlisto' ) ) {
			return;
		}
		wp_enqueue_style(
			'hostlinks-calendar',
			HOSTLINKS_PLUGIN_URL . 'assets/css/hostlinks-calendar.css',
			array(),
			HOSTLINKS_VERSION
		);
	}

	public function enqueue_admin( $hook ) {
		$hostlinks_pages = array(
			'toplevel_page_booking-menu',
			'toplevel_page_types-menu',
			'toplevel_page_marketer-menu',
			'toplevel_page_istructor-menu',
			'events_page_hostlinks-import-export',
		);

		if ( ! in_array( $hook, $hostlinks_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'hostlinks-daterangepicker',
			HOSTLINKS_PLUGIN_URL . 'assets/css/daterangepicker.css',
			array(),
			HOSTLINKS_VERSION
		);
		wp_enqueue_script(
			'hostlinks-moment',
			'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.1/moment.min.js',
			array( 'jquery' ),
			'2.22.1',
			true
		);
		wp_enqueue_script(
			'hostlinks-daterangepicker',
			HOSTLINKS_PLUGIN_URL . 'assets/js/daterangepicker.js',
			array( 'jquery', 'hostlinks-moment' ),
			HOSTLINKS_VERSION,
			true
		);
	}
}

new Hostlinks_Assets();
