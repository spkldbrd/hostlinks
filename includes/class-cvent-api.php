<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CVENT REST API client (api-platform.cvent.com/ea).
 *
 * Call budget strategy (free tier: 1,000 calls/day):
 *   - 1 call  : GET /ea/events           (list)
 *   - 1+ calls: GET /ea/events/{id}/attendees  (paginated at 200/page)
 *   - No /orders, /transactions, or /fee-items calls needed.
 *
 * PAID/FREE rule:
 *   If any discount name on an attendee contains 'free' (case-insensitive) → FREE.
 *   Otherwise → PAID.
 */
class Hostlinks_CVENT_API {

	const TOKEN_URL    = 'https://api-platform.cvent.com/ea/oauth2/token';
	const BASE_URL     = 'https://api-platform.cvent.com/ea/';
	const TOKEN_KEY    = 'hostlinks_cvent_token';
	const SETTINGS_KEY = 'hostlinks_cvent_settings';
	const MAX_RETRIES  = 3;

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Return stored credentials with safe defaults.
	 *
	 * @return array{client_id:string, client_secret:string, account_number:string}
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::SETTINGS_KEY, array() ),
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'account_number' => '',
			)
		);
	}

	/**
	 * Persist credentials.
	 */
	public static function save_settings( $client_id, $client_secret, $account_number ) {
		update_option(
			self::SETTINGS_KEY,
			array(
				'client_id'      => sanitize_text_field( $client_id ),
				'client_secret'  => sanitize_text_field( $client_secret ),
				'account_number' => sanitize_text_field( $account_number ),
			)
		);
		// Invalidate any cached token so next request re-authenticates.
		delete_transient( self::TOKEN_KEY );
	}

	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------

	/**
	 * Fetch (or return cached) Bearer token.
	 * Token is cached for expires_in − 60 seconds (≈55 min for a 1hr token).
	 *
	 * @return string|WP_Error
	 */
	public static function get_token() {
		$cached = get_transient( self::TOKEN_KEY );
		if ( $cached ) {
			return $cached;
		}

		$s = self::get_settings();
		if ( empty( $s['client_id'] ) || empty( $s['client_secret'] ) ) {
			return new WP_Error( 'cvent_no_credentials', 'CVENT Client ID or Client Secret is not configured.' );
		}

		$credentials = base64_encode( $s['client_id'] . ':' . $s['client_secret'] );

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . $credentials,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : 'HTTP ' . $code;
			return new WP_Error( 'cvent_token_error', 'Token request failed: ' . $msg, $body );
		}

		$ttl = isset( $body['expires_in'] ) ? max( (int) $body['expires_in'] - 60, 60 ) : 3540;
		set_transient( self::TOKEN_KEY, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	// -------------------------------------------------------------------------
	// HTTP layer
	// -------------------------------------------------------------------------

	/**
	 * Execute a GET request against the CVENT EA REST API.
	 * Retries with exponential backoff on 429 / 503.
	 *
	 * @param string $endpoint  Path relative to BASE_URL (e.g. 'events').
	 * @param array  $params    Query-string parameters.
	 * @param int    $attempt   Internal retry counter.
	 * @return array|WP_Error   Decoded JSON body or WP_Error.
	 */
	public static function request( $endpoint, $params = array(), $attempt = 0 ) {
		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$s   = self::get_settings();
		$url = self::BASE_URL . ltrim( $endpoint, '/' );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization'        => 'Bearer ' . $token,
					'Accept'               => 'application/json',
					'Cvent-Account-Number' => $s['account_number'],
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// Exponential backoff for rate-limit and transient server errors.
		if ( in_array( $code, array( 429, 503 ), true ) && $attempt < self::MAX_RETRIES ) {
			$delay = min( (int) pow( 2, $attempt ) * 2 + wp_rand( 0, 1 ), 16 );
			sleep( $delay );
			return self::request( $endpoint, $params, $attempt + 1 );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error(
				'cvent_api_error',
				sprintf( 'CVENT API error (HTTP %d): %s', $code, $detail ),
				$body
			);
		}

		return $body;
	}

	// -------------------------------------------------------------------------
	// API methods
	// -------------------------------------------------------------------------

	/**
	 * List events that are upcoming or ended within the last $days_back days.
	 * CVENT's EA API accepts ISO-8601 date filters via query params.
	 * We request events ending on or after the cutoff date.
	 *
	 * @param int $days_back  How many days into the past to include.
	 * @return array|WP_Error Raw API response (has 'data' key).
	 */
	public static function list_active_events( $days_back = 10 ) {
		$cutoff = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( "-{$days_back} days" ) );

		// CVENT EA filter syntax (OData-style).
		// Retrieve events whose end date is on or after the cutoff.
		return self::request(
			'events',
			array(
				'filter' => "end ge '" . $cutoff . "'",
			)
		);
	}

	/**
	 * Retrieve full detail for a single event.
	 *
	 * @param string $event_id CVENT event UUID.
	 * @return array|WP_Error
	 */
	public static function get_event( $event_id ) {
		return self::request( 'events/' . $event_id );
	}

	/**
	 * Retrieve ALL registrations for an event, handling token-based pagination.
	 * Uses page size of 200 to minimise call count (free tier: 1,000 calls/day).
	 *
	 * CVENT Developer Platform uses /registrations not /attendees.
	 *
	 * @param string $event_id CVENT event UUID.
	 * @return array|WP_Error  Flat array of registration records.
	 */
	public static function get_attendees( $event_id ) {
		$all       = array();
		$next      = null;
		$page      = 0;
		$max_pages = 20; // guard rail

		do {
			$params = array( 'limit' => 200 );
			if ( $next ) {
				$params['token'] = $next;
			}

			$result = self::request( 'events/' . $event_id . '/registrations', $params );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$data = isset( $result['data'] ) ? $result['data'] : array();
			$all  = array_merge( $all, $data );
			$next = isset( $result['paging']['nextToken'] ) ? $result['paging']['nextToken'] : null;
			$page++;
		} while ( $next && $page < $max_pages );

		return $all;
	}

	// -------------------------------------------------------------------------
	// PAID / FREE logic
	// -------------------------------------------------------------------------

	/**
	 * Determine if an attendee is FREE based on their discount codes.
	 * Rule: any discount whose name contains 'free' (case-insensitive) = FREE.
	 *
	 * @param array $attendee Attendee record from the API.
	 * @return bool
	 */
	public static function is_free_attendee( $attendee ) {
		if ( empty( $attendee['discounts'] ) || ! is_array( $attendee['discounts'] ) ) {
			return false;
		}
		foreach ( $attendee['discounts'] as $discount ) {
			$name = strtolower( isset( $discount['name'] ) ? $discount['name'] : '' );
			if ( false !== strpos( $name, 'free' ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Test / discovery pull
	// -------------------------------------------------------------------------

	/**
	 * Pull the first upcoming (or recently-ended) event and count paid/free attendees.
	 * Uses only 2 API calls for a single-page attendee list.
	 *
	 * @return array|WP_Error {
	 *   event         : full event record,
	 *   paid_count    : int,
	 *   free_count    : int,
	 *   total_count   : int,
	 *   attendees_raw : first 5 attendee records (for display),
	 *   api_calls_used: int
	 * }
	 */
	public static function test_pull() {
		$calls = 0;

		// 1. List events.
		$events_result = self::list_active_events( 10 );
		$calls++;
		if ( is_wp_error( $events_result ) ) {
			return $events_result;
		}

		$events = isset( $events_result['data'] ) ? $events_result['data'] : array();
		if ( empty( $events ) ) {
			return new WP_Error( 'cvent_no_events', 'No upcoming or recently-ended events found in CVENT.' );
		}

		// Take the first event from the list.
		$event_id = $events[0]['id'];

		// 2. Full event detail.
		$event = self::get_event( $event_id );
		$calls++;
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		// 3. All attendees (paginated — each page = 1 call).
		$all       = array();
		$next      = null;
		$page      = 0;
		$max_pages = 20;

		do {
			$params = array( 'limit' => 200 );
			if ( $next ) {
				$params['token'] = $next;
			}
			$result = self::request( 'events/' . $event_id . '/registrations', $params );
			$calls++;
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$data  = isset( $result['data'] ) ? $result['data'] : array();
			$all   = array_merge( $all, $data );
			$next  = isset( $result['paging']['nextToken'] ) ? $result['paging']['nextToken'] : null;
			$page++;
		} while ( $next && $page < $max_pages );

		// 4. Tally paid / free by discount code.
		$paid = 0;
		$free = 0;
		foreach ( $all as $attendee ) {
			if ( self::is_free_attendee( $attendee ) ) {
				$free++;
			} else {
				$paid++;
			}
		}

		return array(
			'event'          => $event,
			'paid_count'     => $paid,
			'free_count'     => $free,
			'total_count'    => count( $all ),
			'attendees_raw'  => array_slice( $all, 0, 5 ),
			'api_calls_used' => $calls,
		);
	}
}
