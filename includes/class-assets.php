<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'admin_head',            array( $this, 'print_admin_menu_css' ) );
		add_filter( 'admin_body_class',      array( $this, 'admin_body_class' ) );
	}

	public function admin_body_class( string $classes ): string {
		if ( self::is_hostlinks_admin_page() ) {
			$classes .= ' hl-admin';
		}
		return $classes;
	}

	/**
	 * Tighten the padding on the Hostlinks admin submenu items.
	 * Scoped to #toplevel_page_booking-menu so no other plugin's menu is affected.
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
		$has_cert_hub      = has_shortcode( $post->post_content, 'hostlinks_certificate_generator' );
		$has_event_request = has_shortcode( $post->post_content, 'hostlinks_event_request_form' );
		$has_public_list   = has_shortcode( $post->post_content, 'public_event_list' );

		if ( ! $has_calendar && ! $has_reports && ! $has_event_request && ! $has_public_list && ! $has_cert_hub ) {
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
			if ( ! $has_calendar && ! $has_reports && ! $has_event_request && ! $has_cert_hub ) {
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
		if ( self::is_hostlinks_admin_page() ) {
			wp_enqueue_style(
				'hostlinks-admin',
				HOSTLINKS_PLUGIN_URL . 'assets/css/hostlinks-admin.css',
				array(),
				HOSTLINKS_VERSION
			);
		}

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

		if ( in_array( $hook, $hostlinks_pages, true ) ) {
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

		$is_events_list = ( sanitize_key( $_GET['page'] ?? '' ) === 'booking-menu' )
			&& empty( $_GET['edit_event'] )
			&& empty( $_GET['add_event'] )
			&& empty( $_GET['add_cvent'] )
			&& empty( $_GET['add_request'] )
			&& empty( $_GET['copy_event'] );

		if ( $is_events_list ) {
			wp_enqueue_style(
				'hostlinks-datatables',
				'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css',
				array(),
				'1.13.8'
			);
			wp_enqueue_style(
				'hostlinks-events',
				HOSTLINKS_PLUGIN_URL . 'assets/css/hostlinks-events.css',
				array( 'hostlinks-admin', 'hostlinks-datatables' ),
				HOSTLINKS_VERSION
			);
			wp_enqueue_script(
				'hostlinks-datatables',
				'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
				array( 'jquery' ),
				'1.13.8',
				true
			);
			wp_enqueue_script(
				'hostlinks-events',
				HOSTLINKS_PLUGIN_URL . 'assets/js/hostlinks-events.js',
				array( 'jquery', 'hostlinks-daterangepicker', 'hostlinks-datatables' ),
				HOSTLINKS_VERSION,
				true
			);
			wp_localize_script(
				'hostlinks-events',
				'hlEvents',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'hostlinks_event_links' ),
				)
			);
		}
	}

	/**
	 * True on Hostlinks wp-admin screens (Events, CVENT, Settings, etc.).
	 */
	private static function is_hostlinks_admin_page(): bool {
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( $page === '' ) {
			return false;
		}
		if ( str_starts_with( $page, 'hostlinks-' ) || str_starts_with( $page, 'cvent-' ) ) {
			return true;
		}
		return in_array( $page, array( 'booking-menu', 'types-menu', 'marketer-menu', 'istructor-menu' ), true );
	}
}

new Hostlinks_Assets();
