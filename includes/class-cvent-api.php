<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CVENT REST API client (api-platform.cvent.com/ea).
 *
 * Call budget strategy (free tier: 1,000 calls/day):
 *   - 1 call  : GET /ea/events           (list)
 *   - 1+ calls: GET /ea/attendees/filter?filter=eventId eq '{id}'  (paginated at 200/page)
 *   - No /orders, /transactions, or /fee-items calls needed.
 *
 * PAID/FREE rule:
 *   If any discount name on an attendee contains 'free' (case-insensitive) → FREE.
 *   Otherwise → PAID.
 */
class Hostlinks_CVENT_API {

	const TOKEN_URL    = 'https://api-platform.cvent.com/ea/oauth2/token';
	const BASE_URL     = 'https://api-platform.cvent.com/ea/';
	const TOKEN_KEY    = 'hostlinks_cvent_token_v2'; // v2: includes attendees scope
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
				'body'    => http_build_query( array(
					'grant_type' => 'client_credentials',
					'scope'      => 'event/events:read event/attendees:read',
				) ),
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
			// Use RFC3986 (%20 for spaces) — add_query_arg uses urlencode (+) which
			// some OData parsers reject when decoding filter strings.
			$url .= '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		}
		$debug_url = $url; // preserve for error messages

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

		// Increment daily call counter (resets at midnight UTC).
		self::increment_call_counter();

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error(
				'cvent_api_error',
				sprintf( 'CVENT API error (HTTP %d): %s | URL: %s', $code, $detail, $debug_url ),
				$body
			);
		}

		return $body;
	}

	// -------------------------------------------------------------------------
	// Daily call counter
	// -------------------------------------------------------------------------

	/**
	 * Increment the daily API call counter.
	 * Keyed by UTC date so it resets automatically at midnight UTC.
	 * Transient TTL is 25 hours to survive the full day with a small buffer.
	 */
	private static function increment_call_counter() {
		$key     = 'hostlinks_cvent_calls_' . gmdate( 'Y-m-d' );
		$current = (int) get_transient( $key );
		set_transient( $key, $current + 1, 25 * HOUR_IN_SECONDS );
	}

	/**
	 * Get today's API call count (UTC day).
	 *
	 * @return int
	 */
	public static function get_call_count_today() {
		return (int) get_transient( 'hostlinks_cvent_calls_' . gmdate( 'Y-m-d' ) );
	}

	/**
	 * Reset today's call counter (useful for testing).
	 */
	public static function reset_call_counter() {
		delete_transient( 'hostlinks_cvent_calls_' . gmdate( 'Y-m-d' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip BOM, non-breaking space, and whitespace from a CVENT UUID.
	 * CVENT sometimes prefixes strings with a UTF-8 BOM (\xEF\xBB\xBF).
	 *
	 * @param string $id
	 * @return string Clean UUID string.
	 */
	public static function sanitize_uuid( $id ) {
		return trim( ltrim( (string) $id, "\xEF\xBB\xBF\xC2\xA0" ) );
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
		return self::request( 'events/' . self::sanitize_uuid( $event_id ) );
	}

	/**
	 * Retrieve ALL attendees for a CVENT event, handling token-based pagination.
	 * Uses the confirmed endpoint: GET /ea/attendees/filter?filter=eventId eq {id}
	 * Uses page size of 200 to minimise call count (free tier: 1,000 calls/day).
	 *
	 * Required scope: event/attendees:read (set on the CVENT app, not in the token request).
	 *
	 * @param string $event_id CVENT event UUID.
	 * @return array|WP_Error  Flat array of attendee records.
	 */
	/**
	 * Retrieve ALL attendees for a CVENT event, handling token-based pagination.
	 *
	 * Endpoint: GET /ea/attendees?filter=eventId eq '{id}'&limit=200
	 * (/attendees/filter does not exist — /attendees is the collection resource)
	 *
	 * Required scope: event/attendees:read
	 *
	 * @param string $event_id CVENT event UUID.
	 * @return array|WP_Error  Flat array of attendee records.
	 */
	public static function get_attendees( $event_id ) {
		$event_id  = self::sanitize_uuid( $event_id );
		$all       = array();
		$next      = null;
		$page      = 0;
		$max_pages = 20;

		do {
			$params = array(
				'filter' => "eventId eq '" . $event_id . "'",
				'limit'  => 200,
			);
			if ( $next ) {
				$params['token'] = $next;
			}

			// Endpoint is /ea/attendees (collection), NOT /ea/attendees/filter.
			$result = self::request( 'attendees', $params );
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

	/**
	 * Retrieve ALL order items for a CVENT event (paginated).
	 * Order items carry the discount code used during registration — the correct
	 * source for PAID/FREE classification (discount codes are NOT on the attendee record).
	 *
	 * Endpoint: GET /ea/orders/items?filter=eventId eq '{id}'&limit=200
	 *
	 * Each item in the response is expected to contain at least:
	 *   attendeeId, discountCode, discountName, status
	 *
	 * @param string $event_id CVENT event UUID.
	 * @return array|WP_Error  Flat array of order-item records.
	 */
	public static function get_order_items( $event_id ) {
		$event_id  = self::sanitize_uuid( $event_id );
		$all       = array();
		$next      = null;
		$page      = 0;
		$max_pages = 20;

		do {
			$params = array(
				'filter' => "eventId eq '" . $event_id . "'",
				'limit'  => 200,
			);
			if ( $next ) {
				$params['token'] = $next;
			}

			$result = self::request( 'orders/items', $params );
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

	/**
	 * Search CVENT events within a start-date window (for bootstrap matching).
	 * Used by the matcher to find candidates for a given Hostlinks event.
	 *
	 * @param string $start_min ISO 8601 UTC datetime (e.g. 2026-03-04T00:00:00Z).
	 * @param string $start_max ISO 8601 UTC datetime.
	 * @return array|WP_Error Raw API response (has 'data' key with event records).
	 */
	public static function search_events( $start_min, $start_max ) {
		return self::request(
			'events',
			array(
				'filter' => "start ge '" . $start_min . "' and start le '" . $start_max . "'",
			)
		);
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

		// 3. All attendees via confirmed endpoint (paginated — each page = 1 call).
		$all       = array();
		$next      = null;
		$page      = 0;
		$max_pages = 20;

		do {
			$params = array(
				'filter' => "eventId eq '" . $event_id . "'",
				'limit'  => 200,
			);
			if ( $next ) {
				$params['token'] = $next;
			}
			$result = self::request( 'attendees/filter', $params );
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
