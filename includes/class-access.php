<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hostlinks front-end access control.
 *
 * Three access modes per shortcode:
 *   public           - everyone (including logged-out visitors)
 *   logged_in        - any logged-in WordPress user
 *   approved_viewers - only administrators + specifically approved user IDs
 *
 * Administrators (manage_options) always pass regardless of mode.
 * Defaults to 'approved_viewers' for all shortcodes until explicitly set.
 */
class Hostlinks_Access {

	// ── Shortcode registry ────────────────────────────────────────────────────
	// Add future Hostlinks shortcodes here to make them available on the
	// User Access settings page automatically.
	const SHORTCODES = array(
		'eventlisto'                    => 'Upcoming Events',
		'oldeventlisto'                 => 'Past Events',
		'hostlinks_reports'             => 'Reports',
		'hostlinks_roster'              => 'Roster',
		'hostlinks_event_request_form'  => 'Event Request Form',
	);

	const MODES = array( 'public', 'logged_in', 'approved_viewers' );

	// ── wp_options keys ───────────────────────────────────────────────────────
	const OPT_MODES   = 'hostlinks_shortcode_access_modes';
	const OPT_VIEWERS = 'hostlinks_approved_viewers';
	const OPT_MESSAGE = 'hostlinks_denial_message';

	const DEFAULT_MESSAGE = "Uh Oh, it looks like you've landed on a page that isn't available to you. Please reach out to your site admin if you think you've reached this message by mistake.";

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init() {
		add_action( 'wp_ajax_hostlinks_search_users',        array( __CLASS__, 'ajax_search_users' ) );
		add_action( 'wp_ajax_nopriv_hostlinks_search_users', array( __CLASS__, 'ajax_search_users_denied' ) );
	}

	// ── Access checks ─────────────────────────────────────────────────────────

	/**
	 * Return the resolved access mode for a shortcode key.
	 * Falls back to 'approved_viewers' if not configured.
	 *
	 * @param string $key  One of the keys in self::SHORTCODES.
	 * @return string
	 */
	public static function get_shortcode_access_mode( $key ) {
		$modes = get_option( self::OPT_MODES, array() );
		$mode  = $modes[ $key ] ?? 'approved_viewers';
		return in_array( $mode, self::MODES, true ) ? $mode : 'approved_viewers';
	}

	/**
	 * Main gate: can the current user view a shortcode?
	 *
	 * @param string $key  Shortcode key (e.g. 'eventlisto').
	 * @return bool
	 */
	public static function can_view_shortcode( $key ) {
		// Administrators always pass.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$mode = self::get_shortcode_access_mode( $key );

		switch ( $mode ) {
			case 'public':
				return true;

			case 'logged_in':
				return is_user_logged_in();

			case 'approved_viewers':
				return is_user_logged_in() && self::current_user_is_approved_viewer();

			default:
				return false;
		}
	}

	/**
	 * Is the current user in the approved viewers list?
	 *
	 * @return bool
	 */
	public static function current_user_is_approved_viewer() {
		$uid      = get_current_user_id();
		$approved = self::get_approved_viewers();
		return $uid > 0 && in_array( $uid, $approved, true );
	}

	// ── Option getters / setters ──────────────────────────────────────────────

	/**
	 * Return the approved viewer user IDs as an array of integers.
	 *
	 * @return int[]
	 */
	public static function get_approved_viewers() {
		$raw = get_option( self::OPT_VIEWERS, array() );
		return array_map( 'intval', (array) $raw );
	}

	/**
	 * Save an array of user IDs as approved viewers.
	 * Deduplicates, casts to int, and removes zeros.
	 *
	 * @param array $ids
	 */
	public static function save_approved_viewers( array $ids ) {
		$clean = array_values( array_unique( array_filter(
			array_map( 'intval', $ids ),
			function( $id ) { return $id > 0; }
		) ) );
		update_option( self::OPT_VIEWERS, $clean );
	}

	/**
	 * Save per-shortcode access modes.
	 * Validates each mode against the allowed list.
	 *
	 * @param array $modes  [ shortcode_key => mode_string ]
	 */
	public static function save_access_modes( array $modes ) {
		$clean = array();
		foreach ( array_keys( self::SHORTCODES ) as $key ) {
			$m           = $modes[ $key ] ?? 'approved_viewers';
			$clean[$key] = in_array( $m, self::MODES, true ) ? $m : 'approved_viewers';
		}
		update_option( self::OPT_MODES, $clean );
	}

	/**
	 * Return the denial message text.
	 *
	 * @return string
	 */
	public static function get_denial_message() {
		$msg = get_option( self::OPT_MESSAGE, '' );
		return $msg !== '' ? $msg : self::DEFAULT_MESSAGE;
	}

	/**
	 * Return the denial message wrapped in the styled container div.
	 * The hostlinks-calendar.css provides the visual treatment.
	 *
	 * @return string  HTML safe to return from a shortcode callback.
	 */
	public static function get_denial_message_html() {
		return '<div class="hostlinks-access-denied"><p>'
			. wp_kses_post( self::get_denial_message() )
			. '</p></div>';
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Search WordPress users by name or email (admin-only AJAX).
	 * Expects: GET/POST 'q' — the search string.
	 * Returns: JSON array of { id, name, email }.
	 */
	public static function ajax_search_users() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		check_ajax_referer( 'hostlinks_user_access' );

		$q = sanitize_text_field( $_REQUEST['q'] ?? '' );
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array() );
		}

		$users = new WP_User_Query( array(
			'search'         => '*' . $q . '*',
			'search_columns' => array( 'display_name', 'user_email', 'user_login' ),
			'number'         => 10,
			'fields'         => array( 'ID', 'display_name', 'user_email' ),
		) );

		$results = array();
		foreach ( $users->get_results() as $u ) {
			$results[] = array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'email' => $u->user_email,
			);
		}
		wp_send_json_success( $results );
	}

	/** Non-admin AJAX call — always denied. */
	public static function ajax_search_users_denied() {
		wp_send_json_error( 'Unauthorized', 403 );
	}
}
