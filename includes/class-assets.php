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

		$has_calendar      = has_shortcode( $post->post_content, 'eventlisto' ) ||
		                     has_shortcode( $post->post_content, 'oldeventlisto' );
		$has_reports       = has_shortcode( $post->post_content, 'hostlinks_reports' );
		$has_event_request = has_shortcode( $post->post_content, 'hostlinks_event_request_form' );
		$has_public_list   = has_shortcode( $post->post_content, 'public_event_list' );

		if ( ! $has_calendar && ! $has_reports && ! $has_event_request && ! $has_public_list ) {
			return;
		}

		if ( $has_public_list ) {
			wp_enqueue_style(
				'hostlinks-public-event-list',
				HOSTLINKS_PLUGIN_URL . 'assets/css/hostlinks-public-event-list.css',
				array(),
				HOSTLINKS_VERSION
			);
			// Public list is standalone — no other Hostlinks CSS needed on this page.
			if ( ! $has_calendar && ! $has_reports && ! $has_event_request ) {
				return;
			}
		}

		wp_enqueue_style(
			'hostlinks-calendar',
			HOSTLINKS_PLUGIN_URL . 'assets/css/hostlinks-calendar.css',
			array(),
			HOSTLINKS_VERSION
		);

		if ( $has_event_request ) {
			wp_enqueue_style(
				'hostlinks-event-request',
				HOSTLINKS_PLUGIN_URL . 'assets/css/hostlinks-event-request.css',
				array(),
				HOSTLINKS_VERSION
			);
		}

		if ( $has_reports ) {
			wp_enqueue_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
				array(),
				'4.4.3',
				false  // load in <head> so it's available before the shortcode's inline script
			);
		}
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
