<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes resolution of the three Hostlinks frontend page URLs.
 *
 * Priority (highest → lowest):
 *   1. Manual override stored in 'hostlinks_page_urls' option.
 *   2. Auto-detect: scan published pages for the shortcode tag (cached 24 h).
 *   3. Hard-coded default path (legacy fallback).
 *
 * Callers should always use the static getters; never hardcode home_url() paths
 * in shortcode files.
 */
class Hostlinks_Page_URLs {

	const OPTION_KEY = 'hostlinks_page_urls';

	// ── Public getters ─────────────────────────────────────────────────────────

	public static function get_upcoming() {
		return self::resolve( 'upcoming', 'eventlisto', '/' );
	}

	public static function get_past_events() {
		return self::resolve( 'past_events', 'oldeventlisto', '/old-event-list/' );
	}

	/**
	 * Returns the Reports page URL, or '' if not found and no default exists.
	 * An empty string means the Reports button should be hidden.
	 */
	public static function get_reports() {
		return self::resolve( 'reports', 'hostlinks_reports', '' );
	}

	/**
	 * Returns the Public Event List page URL, or '' if not found.
	 */
	public static function get_public_event_list() {
		return self::resolve( 'public_event_list', 'public_event_list', '' );
	}

	/**
	 * Returns the Roster front-end page URL, or '' if not found.
	 * Used to auto-populate eve_roster_url on new events.
	 */
	public static function get_roster() {
		return self::resolve( 'roster', 'hostlinks_roster', '' );
	}

	/**
	 * Returns the Event Request Form page URL, or '' if not found.
	 * Used to power the optional "+ Event" button on the upcoming events calendar.
	 */
	public static function get_event_request_form() {
		return self::resolve( 'event_request_form', 'hostlinks_event_request_form', '' );
	}

	// ── Settings helpers ───────────────────────────────────────────────────────

	public static function get_overrides() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), array(
			'upcoming'            => '',
			'past_events'         => '',
			'reports'             => '',
			'public_event_list'   => '',
			'roster'              => '',
			'event_request_form'  => '',
		) );
	}

	public static function save_overrides( $upcoming, $past_events, $reports, $public_event_list = '', $roster = '', $event_request_form = '' ) {
		update_option( self::OPTION_KEY, array(
			'upcoming'           => esc_url_raw( trim( $upcoming ) ),
			'past_events'        => esc_url_raw( trim( $past_events ) ),
			'reports'            => esc_url_raw( trim( $reports ) ),
			'public_event_list'  => esc_url_raw( trim( $public_event_list ) ),
			'roster'             => esc_url_raw( trim( $roster ) ),
			'event_request_form' => esc_url_raw( trim( $event_request_form ) ),
		) );
		self::clear_cache();
	}

	// ── Cache management ───────────────────────────────────────────────────────

	public static function clear_cache() {
		delete_transient( 'hostlinks_page_url_upcoming' );
		delete_transient( 'hostlinks_page_url_past_events' );
		delete_transient( 'hostlinks_page_url_reports' );
		delete_transient( 'hostlinks_page_url_public_event_list' );
		delete_transient( 'hostlinks_page_url_roster' );
		delete_transient( 'hostlinks_page_url_event_request_form' );
		// Remove the legacy transient used by Reports in earlier versions.
		delete_transient( 'hostlinks_reports_page_url' );
	}

	// ── Core resolver ──────────────────────────────────────────────────────────

	/**
	 * Resolve a page URL using the three-tier priority.
	 *
	 * @param string $key           One of 'upcoming', 'past_events', 'reports'.
	 * @param string $shortcode     Shortcode tag to search for in page content.
	 * @param string $default_path  home_url() relative path used as last resort.
	 *                              Pass '' to return '' (hide the button) when
	 *                              neither override nor auto-detect finds a page.
	 * @return string               Absolute URL, or '' if nothing found.
	 */
	private static function resolve( $key, $shortcode, $default_path ) {
		// 1. Manual override wins.
		$overrides = self::get_overrides();
		if ( ! empty( $overrides[ $key ] ) ) {
			return $overrides[ $key ];
		}

		// 2. Auto-detect from WordPress page content (transient-cached).
		$transient_key = 'hostlinks_page_url_' . $key;
		$cached        = get_transient( $transient_key );

		if ( $cached === false ) {
			// Not cached yet — run the DB scan.
			global $wpdb;
			$rid = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type   = 'page'
				   AND post_content LIKE %s
				 LIMIT 1",
				'%' . $wpdb->esc_like( '[' . $shortcode . ']' ) . '%'
			) );
			$cached = $rid ? (string) get_permalink( (int) $rid ) : '';
			// Cache the result for 24 hours.  Empty string is a valid cached
			// value (means "no page found"); false would trigger a re-scan.
			set_transient( $transient_key, $cached, DAY_IN_SECONDS );
		}

		if ( $cached ) {
			return $cached;
		}

		// 3. Hard-coded default fallback.
		return $default_path ? home_url( $default_path ) : '';
	}

	// ── Detection status (used by the settings UI) ─────────────────────────────

	/**
	 * Return an array with the resolved URL and its source for each key.
	 * Used to populate the settings page status table.
	 *
	 * @return array  [ key => [ 'url' => string, 'source' => 'override'|'auto'|'default'|'none' ] ]
	 */
	public static function detection_status() {
		$overrides = self::get_overrides();
		$map       = array(
			'upcoming'           => array( 'shortcode' => 'eventlisto',                    'default_path' => '/' ),
			'past_events'        => array( 'shortcode' => 'oldeventlisto',                 'default_path' => '/old-event-list/' ),
			'reports'            => array( 'shortcode' => 'hostlinks_reports',             'default_path' => '' ),
			'public_event_list'  => array( 'shortcode' => 'public_event_list',             'default_path' => '' ),
			'roster'             => array( 'shortcode' => 'hostlinks_roster',              'default_path' => '' ),
			'event_request_form' => array( 'shortcode' => 'hostlinks_event_request_form',  'default_path' => '' ),
		);

		$status = array();
		foreach ( $map as $key => $cfg ) {
			if ( ! empty( $overrides[ $key ] ) ) {
				$status[ $key ] = array( 'url' => $overrides[ $key ], 'source' => 'override' );
				continue;
			}

			$transient_key = 'hostlinks_page_url_' . $key;
			$cached        = get_transient( $transient_key );

			if ( $cached === false ) {
				// Force a scan now so the UI shows live data.
				global $wpdb;
				$rid    = $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_status = 'publish'
					   AND post_type   = 'page'
					   AND post_content LIKE %s
					 LIMIT 1",
					'%' . $wpdb->esc_like( '[' . $cfg['shortcode'] . ']' ) . '%'
				) );
				$cached = $rid ? (string) get_permalink( (int) $rid ) : '';
				set_transient( $transient_key, $cached, DAY_IN_SECONDS );
			}

			if ( $cached ) {
				$status[ $key ] = array( 'url' => $cached, 'source' => 'auto' );
			} elseif ( $cfg['default_path'] ) {
				$status[ $key ] = array( 'url' => home_url( $cfg['default_path'] ), 'source' => 'default' );
			} else {
				$status[ $key ] = array( 'url' => '', 'source' => 'none' );
			}
		}

		return $status;
	}
}
