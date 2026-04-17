<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'admin_head',            array( $this, 'print_admin_menu_css' ) );
	}

	/**
	 * Tighten the padding on the Hostlinks admin submenu items.
	 * Scoped to #toplevel_page_booking-menu so no other plugin's menu is affected.
	 * Printed inline (few bytes) and covers both the expanded and folded-flyout states.
	 */
	public function print_admin_menu_css() {
		?>
		<style id="hostlinks-admin-menu-css">
		#adminmenu #toplevel_page_booking-menu .wp-submenu > li > a,
		.folded #adminmenu #toplevel_page_booking-menu .wp-submenu > li > a {
			padding: 5px 2px 5px 12px;
		}
		</style>
		<?php
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

			$maps_key = get_option( 'hostlinks_google_maps_api_key', '' );
			if ( $maps_key ) {
				wp_enqueue_script(
					'google-maps-places',
					'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $maps_key ) . '&libraries=places&loading=async',
					array(),
					null,
					false // load in <head> so Places is ready before the inline form script
				);
			}
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
		// Load WordPress media library on the Roster settings tab.
		if ( is_admin()
			&& ( sanitize_key( $_GET['page'] ?? '' ) === 'hostlinks-settings' )
			&& ( sanitize_key( $_GET['tab']  ?? '' ) === 'roster' ) ) {
			wp_enqueue_media();
		}

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
