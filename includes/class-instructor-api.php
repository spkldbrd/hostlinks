<?php
/**
 * Hostlinks Automation REST API.
 *
 * Namespace : hostlinks/v1
 * Base URL  : /wp-json/hostlinks/v1/
 *
 * Endpoints:
 *   POST /assign-instructor      — bulk-assign instructors to upcoming events
 *   POST /create-event-request   — insert a parsed email into the event queue
 *   GET  /upcoming-events        — list upcoming events with current instructor
 *   GET  /email-events           — upcoming events as email merge-field JSON
 *   GET  /instructors            — list all active instructors
 *
 * Auth: every request must include the header
 *   X-HL-Key: {value of option hostlinks_automation_api_key}
 *
 * Dry-run / test mode (no DB writes):
 *   Per-request : add "dry_run": true to the JSON body.
 *   Global      : enable "API Test Mode" in Settings → Automation API
 *                 (option hostlinks_api_test_mode = 1).
 *   When active, write endpoints return the payload they WOULD have written
 *   under the key "would_write" / "would_insert" instead of touching the DB.
 *
 * The API key is generated and managed under
 * Hostlinks → Settings → Automation API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Instructor_API {

	const NAMESPACE = 'hostlinks/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( static::class, 'register_routes' ) );
		// Admin-post handler: regenerate API key button.
		add_action( 'admin_post_hostlinks_regenerate_api_key', array( static::class, 'handle_regenerate_key' ) );
	}

	// ── Route registration ───────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/assign-instructor', array(
			'methods'             => 'POST',
			'callback'            => array( static::class, 'assign_instructor' ),
			'permission_callback' => array( static::class, 'check_api_key' ),
		) );

		register_rest_route( self::NAMESPACE, '/create-event-request', array(
			'methods'             => 'POST',
			'callback'            => array( static::class, 'create_event_request' ),
			'permission_callback' => array( static::class, 'check_api_key' ),
		) );

		register_rest_route( self::NAMESPACE, '/upcoming-events', array(
			'methods'             => 'GET',
			'callback'            => array( static::class, 'get_upcoming_events' ),
			'permission_callback' => array( static::class, 'check_api_key' ),
		) );

		register_rest_route( self::NAMESPACE, '/email-events', array(
			'methods'             => 'GET',
			'callback'            => array( static::class, 'get_email_events' ),
			'permission_callback' => array( static::class, 'check_api_key' ),
			'args'                => array(
				'id'              => array(
					'description' => 'Return a single event by Hostlinks eve_id.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'days'            => array(
					'description' => 'Only include events whose start date is within this many days.',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 1095,
				),
				'marketer'        => array(
					'description' => 'Filter by marketer ID or exact name.',
					'type'        => 'string',
				),
				'type'            => array(
					'description' => 'Filter by event type ID, name, or abbreviation.',
					'type'        => 'string',
				),
				'include_private' => array(
					'description' => 'When true, include hidden/private classes. Default false.',
					'type'        => 'boolean',
					'default'     => false,
				),
				'detail'          => array(
					'description' => 'summary (default) or full (adds venue ops, hotels, host contacts, shipping).',
					'type'        => 'string',
					'enum'        => array( 'summary', 'full' ),
					'default'     => 'summary',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/instructors', array(
			'methods'             => 'GET',
			'callback'            => array( static::class, 'get_instructors' ),
			'permission_callback' => array( static::class, 'check_api_key' ),
		) );
	}

	// ── Auth ─────────────────────────────────────────────────────────────────

	public static function check_api_key( WP_REST_Request $request ): bool|WP_Error {
		$stored = get_option( 'hostlinks_automation_api_key', '' );
		if ( ! $stored ) {
			return new WP_Error( 'api_disabled', 'Automation API key not configured.', array( 'status' => 503 ) );
		}
		$provided = trim( $request->get_header( 'X-HL-Key' ) ?? '' );
		if ( ! hash_equals( $stored, $provided ) ) {
			return new WP_Error( 'unauthorized', 'Invalid or missing X-HL-Key header.', array( 'status' => 401 ) );
		}
		return true;
	}

	// ── Dry-run detection ────────────────────────────────────────────────────

	/**
	 * Returns true when the current request should be treated as a dry run.
	 *
	 * Triggers:
	 *   1. Request body contains "dry_run": true  (per-request control).
	 *   2. WP option hostlinks_api_test_mode is truthy  (global toggle).
	 */
	private static function is_dry_run( array $body ): bool {
		if ( ! empty( $body['dry_run'] ) ) {
			return true;
		}
		return (bool) get_option( 'hostlinks_api_test_mode', 0 );
	}

	// ── POST /assign-instructor ──────────────────────────────────────────────

	public static function assign_instructor( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$edl  = $wpdb->prefix . 'event_details_list';
		$inst = $wpdb->prefix . 'event_instructor';

		$body        = $request->get_json_params();
		$dry_run     = self::is_dry_run( $body );
		$assignments = $body['assignments'] ?? null;

		// Accept a single assignment object as well as an array.
		if ( isset( $body['city'] ) && isset( $body['instructor'] ) && ! is_array( $assignments ) ) {
			$assignments = array( array( 'city' => $body['city'], 'instructor' => $body['instructor'] ) );
		}

		if ( ! is_array( $assignments ) || empty( $assignments ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Provide an "assignments" array or a single { city, instructor } object.' ),
				400
			);
		}

		// Pre-load all active instructors once.
		$all_instructors = $wpdb->get_results(
			"SELECT event_instructor_id AS id, event_instructor_name AS name
			 FROM `{$inst}`
			 WHERE event_instructor_status = 1",
			ARRAY_A
		);

		$today   = current_time( 'Y-m-d' );
		$results = array();
		$summary = array( 'total' => 0, 'updated' => 0, 'no_change' => 0, 'not_found' => 0, 'needs_review' => 0, 'dry_run' => $dry_run );

		foreach ( $assignments as $assignment ) {
			$input_city       = trim( $assignment['city']       ?? '' );
			$input_instructor = trim( $assignment['instructor'] ?? '' );

			if ( $input_city === '' || $input_instructor === '' ) {
				continue;
			}
			$summary['total']++;

			// ── Match instructor ──────────────────────────────────────────────
			$matched_instructor = self::match_instructor( $input_instructor, $all_instructors );

			if ( ! $matched_instructor ) {
				$results[]         = self::result( $input_city, $input_instructor, 'not_found_instructor',
					null, null, false, 'Instructor "' . $input_instructor . '" not found in the Hostlinks instructor list.' );
				$summary['not_found']++;
				continue;
			}

			// ── Match event ───────────────────────────────────────────────────
			[ $events, $fuzzy ] = self::match_events( $input_city, $today, $edl );

			if ( empty( $events ) ) {
				$results[]        = self::result( $input_city, $input_instructor, 'not_found_event',
					null, null, false, 'No upcoming event matched "' . $input_city . '".' );
				$summary['not_found']++;
				continue;
			}

			// Multiple matches with same start date = truly ambiguous.
			if ( count( $events ) > 1 && $events[0]['eve_start'] === $events[1]['eve_start'] ) {
				$locations = implode( ', ', array_column( $events, 'eve_location' ) );
				$results[] = self::result( $input_city, $input_instructor, 'ambiguous_event',
					null, null, $fuzzy, 'Multiple events on the same date matched: ' . $locations );
				$summary['needs_review']++;
				continue;
			}

			// Use the nearest upcoming event (already sorted by eve_start ASC).
			$event = $events[0];
			$eve_id = (int) $event['eve_id'];

			// No-change guard.
			if ( (int) $event['eve_instructor'] === (int) $matched_instructor['id'] ) {
				$results[]        = self::result( $input_city, $input_instructor, 'no_change',
					$event, $matched_instructor, $fuzzy, null );
				$summary['no_change']++;
				continue;
			}

			// ── Update (or dry-run preview) ───────────────────────────────────
			$warning = $fuzzy ? 'City matched by fuzzy search — please verify.' : null;

			if ( $dry_run ) {
				$result             = self::result( $input_city, $input_instructor, 'would_update',
					$event, $matched_instructor, $fuzzy, $warning );
				$result['dry_run']  = true;
				$result['would_write'] = array(
					'table'   => $edl,
					'where'   => array( 'eve_id' => $eve_id ),
					'set'     => array( 'eve_instructor' => (int) $matched_instructor['id'] ),
				);
				$results[] = $result;
				$summary['updated']++;
				continue;
			}

			$wpdb->update(
				$edl,
				array( 'eve_instructor' => (int) $matched_instructor['id'] ),
				array( 'eve_id'         => $eve_id ),
				array( '%d' ),
				array( '%d' )
			);

			// Bust Marketing Ops public cache so front-end reflects the change.
			if ( class_exists( 'HMO_REST', false ) && is_callable( array( 'HMO_REST', 'flush_public_events_cache' ) ) ) {
				HMO_REST::flush_public_events_cache();
			}

			$results[] = self::result( $input_city, $input_instructor, 'updated',
				$event, $matched_instructor, $fuzzy, $warning );
			$summary['updated']++;
		}

		$response = array( 'results' => $results, 'summary' => $summary );
		if ( $dry_run ) {
			$response['dry_run'] = true;
			$response['notice']  = 'DRY RUN — no database writes performed.';
		}
		return new WP_REST_Response( $response, 200 );
	}

	// ── POST /create-event-request ──────────────────────────────────────────

	/**
	 * Accept a pre-parsed (by n8n + AI) event email and insert it into the
	 * Hostlinks event request queue — exactly as if it were submitted through
	 * the front-end [hostlinks_event_request_form] shortcode.
	 *
	 * Expected JSON body:
	 * {
	 *   "events": [
	 *     { "category": "Management", "start_date": "2026-08-19",
	 *       "end_date": "2026-08-20", "trainer": "TBA", "is_zoom": false,
	 *       "timezone": "" }
	 *   ],
	 *   "marketer":         "Nikki",
	 *   "city":             "Billings",
	 *   "state":            "MT",
	 *   "zip_code":         "59101",
	 *   "street_address_1": "4810 Midland Road",
	 *   "street_address_2": "",
	 *   "location_name":    "Billings Police Department",
	 *   "host_name":        "",
	 *   "displayed_as":     "",
	 *   "special_instructions": "",
	 *   "max_attendees":    null,
	 *   "host_contacts": [
	 *     { "name":"Brad Mansur","title":"Administrative Sergeant",
	 *       "agency":"Billings Police Department",
	 *       "email":"mansurb@billingsmt.gov","phone":"406-247-8557",
	 *       "phone2":"" }
	 *   ],
	 *   "hotels": [
	 *     { "name":"Comfort Suites","address":"4908 Southgate Dr, Billings, MT 59101",
	 *       "phone":"406-969-2300","url":"" }
	 *   ],
	 *   "ship_name":      "Sgt. Brad Mansur",
	 *   "ship_address_1": "220 North 27th Street",
	 *   "ship_city":      "Billings",
	 *   "ship_state":     "MT",
	 *   "ship_zip":       "59101",
	 *   "ship_phone":     "406-247-8557",
	 *   "ship_notes":     "",
	 *   "source":         "email-forward"
	 * }
	 */
	public static function create_event_request( WP_REST_Request $request ): WP_REST_Response {
		$body    = $request->get_json_params();
		$dry_run = self::is_dry_run( $body );

		// ── Validate minimum requirements ─────────────────────────────────────
		$events = $body['events'] ?? array();
		if ( ! is_array( $events ) || empty( $events ) ) {
			return new WP_REST_Response(
				array( 'error' => '"events" array is required and must contain at least one entry.' ),
				400
			);
		}

		$valid_categories = array( 'Management', 'Writing', 'Subaward' );
		foreach ( $events as $i => $ev ) {
			$cat   = trim( $ev['category']   ?? '' );
			$start = trim( $ev['start_date'] ?? '' );
			$end   = trim( $ev['end_date']   ?? '' );
			if ( $cat === '' ) {
				return new WP_REST_Response( array( 'error' => "events[$i].category is required." ), 400 );
			}
			if ( ! in_array( $cat, $valid_categories, true ) ) {
				return new WP_REST_Response(
					array( 'error' => "events[$i].category must be one of: " . implode( ', ', $valid_categories ) . ". Got: \"$cat\"." ),
					400
				);
			}
			if ( ! self::is_valid_ymd( $start ) ) {
				return new WP_REST_Response( array( 'error' => "events[$i].start_date must be YYYY-MM-DD. Got: \"$start\"." ), 400 );
			}
			if ( ! self::is_valid_ymd( $end ) ) {
				return new WP_REST_Response( array( 'error' => "events[$i].end_date must be YYYY-MM-DD. Got: \"$end\"." ), 400 );
			}
		}

		// ── Normalise and sanitize shared fields ──────────────────────────────
		$now              = current_time( 'mysql' );
		$submission_group = wp_generate_uuid4();
		$city             = sanitize_text_field( $body['city']             ?? '' );
		$state            = sanitize_text_field( $body['state']            ?? '' );

		// Hotels
		$hotels = array();
		foreach ( (array) ( $body['hotels'] ?? array() ) as $h ) {
			$name = sanitize_text_field( $h['name'] ?? '' );
			if ( $name === '' ) continue;
			$hotels[] = array(
				'name'    => $name,
				'address' => sanitize_text_field( $h['address'] ?? '' ),
				'phone'   => sanitize_text_field( $h['phone']   ?? '' ),
				'url'     => esc_url_raw( trim( $h['url'] ?? '' ) ),
			);
		}

		// Host contacts
		$host_contacts = array();
		foreach ( (array) ( $body['host_contacts'] ?? array() ) as $c ) {
			$name = sanitize_text_field( $c['name'] ?? '' );
			if ( $name === '' ) continue;
			$host_contacts[] = array(
				'name'             => $name,
				'agency'           => sanitize_text_field( $c['agency']  ?? '' ),
				'title'            => sanitize_text_field( $c['title']   ?? '' ),
				'email'            => sanitize_email(      $c['email']   ?? '' ),
				'phone'            => Hostlinks_Event_Request::normalize_phone( sanitize_text_field( $c['phone']  ?? '' ) ),
				'phone2'           => Hostlinks_Event_Request::normalize_phone( sanitize_text_field( $c['phone2'] ?? '' ) ),
				'dnl_phone'        => false,
				'dnl_phone2'       => false,
				'include_in_email' => true,
				'cc_on_alerts'     => false,
			);
		}

		$has_shipping = ! empty( $body['ship_name'] ) || ! empty( $body['ship_address_1'] );

		$shared = array(
			'submission_group'    => $submission_group,
			'request_status'      => Hostlinks_Event_Request::STATUS_NEW,
			'submitted_at'        => $now,
			'updated_at'          => $now,
			'event_title'         => '',
			'hostlinks_title'     => '',
			'description'         => '',
			'custom_email_intro'  => sanitize_textarea_field( $body['custom_email_intro'] ?? '' ),
			'format'              => '',
			'timezone'            => sanitize_text_field( $body['timezone'] ?? '' ),
			'marketer'            => sanitize_text_field( $body['marketer'] ?? '' ),
			'host_name'           => sanitize_text_field( $body['host_name']           ?? '' ),
			'displayed_as'        => sanitize_text_field( $body['displayed_as']        ?? '' ),
			'location_name'       => sanitize_text_field( $body['location_name']       ?? '' ),
			'street_address_1'    => sanitize_text_field( $body['street_address_1']    ?? '' ),
			'street_address_2'    => sanitize_text_field( $body['street_address_2']    ?? '' ),
			'street_address_3'    => sanitize_text_field( $body['street_address_3']    ?? '' ),
			'city'                => $city,
			'state'               => $state,
			'zip_code'            => sanitize_text_field( $body['zip_code']            ?? '' ),
			'special_instructions'=> sanitize_textarea_field( $body['special_instructions'] ?? '' ),
			'parking_file_url'    => '',
			'max_attendees'       => is_numeric( $body['max_attendees'] ?? '' ) ? (int) $body['max_attendees'] : null,
			'special_message'     => sanitize_text_field( $body['source'] ?? 'api' ),
			'cc_emails'           => '[]',
			'start_time'          => '',
			'end_time'            => '',
			'hotels'              => wp_json_encode( $hotels ),
			'host_contacts'       => wp_json_encode( $host_contacts ),
			'ship_name'      => $has_shipping ? sanitize_text_field( $body['ship_name']      ?? '' ) : '',
			'ship_email'     => $has_shipping ? sanitize_email(      $body['ship_email']     ?? '' ) : '',
			'ship_phone'     => $has_shipping ? Hostlinks_Event_Request::normalize_phone( sanitize_text_field( $body['ship_phone'] ?? '' ) ) : '',
			'ship_address_1' => $has_shipping ? sanitize_text_field( $body['ship_address_1'] ?? '' ) : '',
			'ship_address_2' => $has_shipping ? sanitize_text_field( $body['ship_address_2'] ?? '' ) : '',
			'ship_address_3' => $has_shipping ? sanitize_text_field( $body['ship_address_3'] ?? '' ) : '',
			'ship_city'      => $has_shipping ? sanitize_text_field( $body['ship_city']      ?? '' ) : '',
			'ship_state'     => $has_shipping ? sanitize_text_field( $body['ship_state']     ?? '' ) : '',
			'ship_zip'       => $has_shipping ? sanitize_text_field( $body['ship_zip']       ?? '' ) : '',
			'ship_workbooks' => null,
			'ship_notes'     => $has_shipping ? sanitize_textarea_field( $body['ship_notes']  ?? '' ) : '',
		);

		// ── Build rows (and optionally insert) ────────────────────────────────
		$inserted_ids  = array();
		$preview_rows  = array();

		foreach ( $events as $ev ) {
			$cat     = sanitize_text_field( $ev['category']   ?? '' );
			$start   = sanitize_text_field( $ev['start_date'] ?? '' );
			$end     = sanitize_text_field( $ev['end_date']   ?? '' );
			$trainer = sanitize_text_field( $ev['trainer']    ?? 'TBA' );
			$is_zoom = ! empty( $ev['is_zoom'] );
			$row_tz  = $is_zoom ? sanitize_text_field( $ev['timezone'] ?? $shared['timezone'] ) : $shared['timezone'];

			$hl_title = Hostlinks_Event_Request::build_hostlinks_title( $city, $state, $cat, $start );

			$row = array_merge( $shared, array(
				'category'        => $cat,
				'trainer'         => $trainer ?: 'TBA',
				'start_date'      => $start,
				'end_date'        => $end,
				'format'          => $is_zoom ? 'virtual' : 'in-person',
				'timezone'        => $row_tz,
				'price'           => null,
				'hostlinks_title' => $hl_title,
				'event_title'     => $hl_title,
			) );

			if ( $dry_run ) {
				$preview_rows[] = $row;
				continue;
			}

			$id = Hostlinks_Event_Request_Storage::insert( $row );
			if ( $id ) {
				$inserted_ids[] = $id;
			}
		}

		// ── Dry-run response ──────────────────────────────────────────────────
		if ( $dry_run ) {
			return new WP_REST_Response( array(
				'dry_run'         => true,
				'notice'          => 'DRY RUN — no database writes performed.',
				'status'          => 'would_insert',
				'submission_group'=> $submission_group,
				'events_count'    => count( $preview_rows ),
				'would_insert'    => $preview_rows,
				'queue_url'       => admin_url( 'admin.php?page=hostlinks-event-requests' ),
			), 200 );
		}

		if ( empty( $inserted_ids ) ) {
			return new WP_REST_Response( array( 'error' => 'All inserts failed — check server logs.' ), 500 );
		}

		return new WP_REST_Response( array(
			'status'          => 'created',
			'submission_group'=> $submission_group,
			'request_ids'     => $inserted_ids,
			'events_created'  => count( $inserted_ids ),
			'queue_url'       => admin_url( 'admin.php?page=hostlinks-event-requests' ),
		), 201 );
	}

	/** Simple YYYY-MM-DD date sanity check. */
	private static function is_valid_ymd( string $date ): bool {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	// ── GET /upcoming-events ─────────────────────────────────────────────────

	public static function get_upcoming_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$edl  = $wpdb->prefix . 'event_details_list';
		$inst = $wpdb->prefix . 'event_instructor';
		$today = current_time( 'Y-m-d' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.eve_id, e.eve_location, e.eve_start, e.eve_end,
				        e.eve_instructor,
				        i.event_instructor_name AS instructor_name
				 FROM `{$edl}` e
				 LEFT JOIN `{$inst}` i ON i.event_instructor_id = e.eve_instructor
				 WHERE e.eve_status = 1
				   AND e.eve_end >= %s
				 ORDER BY e.eve_start ASC",
				$today
			),
			ARRAY_A
		);

		$data = array_map( function( $r ) {
			return array(
				'eve_id'          => (int) $r['eve_id'],
				'eve_location'    => $r['eve_location'],
				'eve_start'       => $r['eve_start'],
				'eve_end'         => $r['eve_end'],
				'instructor_id'   => (int) $r['eve_instructor'],
				'instructor_name' => $r['instructor_name'] ?: 'TBA',
			);
		}, $rows );

		return new WP_REST_Response( $data, 200 );
	}

	// ── GET /email-events ────────────────────────────────────────────────────

	/**
	 * Upcoming (or single) events shaped for email merge fields.
	 *
	 * Query params:
	 *   id              int     Single event by eve_id (bypasses date + private filters)
	 *   days            int     Only events starting within N days (default: all upcoming)
	 *   marketer        string  Marketer ID or exact name
	 *   type            string  Type ID, name, or abbreviation
	 *   include_private bool    Include hidden/private classes (default false)
	 *   detail          string  "summary" (default) or "full"
	 */
	public static function get_email_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$edl  = $wpdb->prefix . 'event_details_list';
		$inst = $wpdb->prefix . 'event_instructor';
		$mktr = $wpdb->prefix . 'event_marketer';
		$typ  = $wpdb->prefix . 'event_type';
		$today = current_time( 'Y-m-d' );

		$id              = (int) $request->get_param( 'id' );
		$days            = (int) $request->get_param( 'days' );
		$marketer        = trim( (string) ( $request->get_param( 'marketer' ) ?? '' ) );
		$type            = trim( (string) ( $request->get_param( 'type' ) ?? '' ) );
		$include_private = rest_sanitize_boolean( $request->get_param( 'include_private' ) );
		$detail          = strtolower( (string) ( $request->get_param( 'detail' ) ?? 'summary' ) );
		$full            = ( 'full' === $detail );

		$where = array( 'e.eve_status = 1' );
		$args  = array();

		if ( $id > 0 ) {
			$where[] = 'e.eve_id = %d';
			$args[]  = $id;
		} else {
			$where[] = 'e.eve_end >= %s';
			$args[]  = $today;
			if ( $days > 0 ) {
				$until   = ( new DateTime( $today, wp_timezone() ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
				$where[] = 'e.eve_start <= %s';
				$args[]  = $until;
			}
			if ( ! $include_private ) {
				$where[] = 'e.eve_public_hide = 0';
				$where[] = 'e.eve_location NOT LIKE %s';
				$args[]  = '%|PRIVATE%';
				$where[] = 'e.eve_location NOT LIKE %s';
				$args[]  = '%| PRIVATE%';
				$where[] = 'e.eve_location NOT LIKE %s';
				$args[]  = '%|private%';
				$where[] = "NOT ( e.eve_marketer > 0 AND LOWER( TRIM( COALESCE( m.event_marketer_name, '' ) ) ) = 'private' )";
			}
		}

		if ( $marketer !== '' ) {
			if ( ctype_digit( $marketer ) ) {
				$where[] = 'e.eve_marketer = %d';
				$args[]  = (int) $marketer;
			} else {
				$where[] = 'LOWER( TRIM( m.event_marketer_name ) ) = %s';
				$args[]  = strtolower( $marketer );
			}
		}

		if ( $type !== '' ) {
			if ( ctype_digit( $type ) ) {
				$where[] = 'e.eve_type = %d';
				$args[]  = (int) $type;
			} else {
				$where[] = '( LOWER( TRIM( t.event_type_name ) ) = %s OR LOWER( TRIM( t.event_type_abbr ) ) = %s )';
				$args[]  = strtolower( $type );
				$args[]  = strtolower( $type );
			}
		}

		$sql = "SELECT e.eve_id, e.eve_location, e.eve_start, e.eve_end, e.eve_tot_date,
		               e.eve_type, e.eve_zoom, e.eve_zoom_time, e.eve_marketer, e.eve_instructor,
		               e.eve_host_url, e.eve_roster_url, e.eve_trainer_url, e.eve_web_url, e.eve_email_url,
		               e.eve_paid, e.eve_free, e.eve_public_hide, e.cvent_event_id, e.cvent_event_title,
		               e.host_name, e.displayed_as, e.location_name,
		               e.street_address_1, e.street_address_2, e.street_address_3,
		               e.city, e.state, e.zip_code, e.custom_email_intro,
		               e.special_instructions, e.parking_file_url, e.max_attendees,
		               e.host_contacts, e.hotels,
		               e.ship_name, e.ship_email, e.ship_phone,
		               e.ship_address_1, e.ship_address_2, e.ship_address_3,
		               e.ship_city, e.ship_state, e.ship_zip, e.ship_workbooks, e.ship_notes,
		               t.event_type_name AS type_name, t.event_type_abbr AS type_abbr,
		               m.event_marketer_name AS marketer_name, m.marketer_email AS marketer_email,
		               i.event_instructor_name AS instructor_name
		        FROM `{$edl}` e
		        LEFT JOIN `{$typ}` t ON t.event_type_id = e.eve_type
		        LEFT JOIN `{$mktr}` m ON m.event_marketer_id = e.eve_marketer
		        LEFT JOIN `{$inst}` i ON i.event_instructor_id = e.eve_instructor
		        WHERE " . implode( ' AND ', $where ) . '
		        ORDER BY e.eve_start ASC';

		if ( empty( $args ) ) {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		}

		$events = array();
		foreach ( (array) $rows as $r ) {
			$events[] = self::format_email_event( $r, $full );
		}

		return new WP_REST_Response( array(
			'count'  => count( $events ),
			'events' => $events,
		), 200 );
	}

	/**
	 * Shape one event row as email merge fields.
	 *
	 * @param array $r    Joined event_details_list row.
	 * @param bool  $full Include ops/PII fields (contacts, hotels, shipping).
	 */
	private static function format_email_event( array $r, bool $full ): array {
		$start = (string) ( $r['eve_start'] ?? '' );
		$end   = (string) ( $r['eve_end'] ?? $start );
		$loc   = (string) ( $r['eve_location'] ?? '' );

		$city  = trim( (string) ( $r['city'] ?? '' ) );
		$state = trim( (string) ( $r['state'] ?? '' ) );
		if ( $city === '' || $state === '' ) {
			$loc_city = trim( (string) preg_replace( '/\|.*/', '', $loc ) );
			if ( preg_match( '/^(.+),\s*([A-Za-z]{2})\b/', $loc_city, $m ) ) {
				if ( $city === '' ) {
					$city = trim( $m[1] );
				}
				if ( $state === '' ) {
					$state = strtoupper( trim( $m[2] ) );
				}
			}
		}

		$out = array(
			'id'                  => (int) $r['eve_id'],
			'location'            => $loc,
			'city'                => $city,
			'state'               => $state,
			'start'               => $start,
			'end'                 => $end,
			'dates_display'       => self::format_dates_display( $start, $end ),
			'type'                => (string) ( $r['type_name'] ?? '' ),
			'type_abbr'           => (string) ( $r['type_abbr'] ?? '' ),
			'type_id'             => (int) ( $r['eve_type'] ?? 0 ),
			'marketer'            => (string) ( $r['marketer_name'] ?? '' ),
			'marketer_email'      => (string) ( $r['marketer_email'] ?? '' ),
			'instructor'          => $r['instructor_name'] ? (string) $r['instructor_name'] : 'TBA',
			'is_zoom'             => ( strtolower( trim( (string) ( $r['eve_zoom'] ?? '' ) ) ) === 'yes' ),
			'zoom_time'           => (string) ( $r['eve_zoom_time'] ?? '' ),
			'reg_url'             => (string) ( $r['eve_trainer_url'] ?? '' ),
			'web_url'             => (string) ( $r['eve_web_url'] ?? '' ),
			'host_url'            => (string) ( $r['eve_host_url'] ?? '' ),
			'email_url'           => (string) ( $r['eve_email_url'] ?? '' ),
			'roster_url'          => (string) ( $r['eve_roster_url'] ?? '' ),
			'venue_name'          => (string) ( $r['location_name'] ?? '' ),
			'venue_address'       => self::format_venue_address( $r ),
			'host_name'           => (string) ( $r['host_name'] ?? '' ),
			'displayed_as'        => (string) ( $r['displayed_as'] ?? '' ),
			'custom_email_intro'  => (string) ( $r['custom_email_intro'] ?? '' ),
			'paid'                => (int) ( $r['eve_paid'] ?? 0 ),
			'free'                => (int) ( $r['eve_free'] ?? 0 ),
			'is_private'          => self::event_is_private( $r ),
			'cvent_id'            => (string) ( $r['cvent_event_id'] ?? '' ),
			'cvent_title'         => (string) ( $r['cvent_event_title'] ?? '' ),
		);

		if ( $full ) {
			$out['max_attendees']        = isset( $r['max_attendees'] ) && $r['max_attendees'] !== '' && $r['max_attendees'] !== null
				? (int) $r['max_attendees'] : null;
			$out['special_instructions'] = (string) ( $r['special_instructions'] ?? '' );
			$out['parking_file_url']     = (string) ( $r['parking_file_url'] ?? '' );
			$out['host_contacts']        = self::decode_json_array( $r['host_contacts'] ?? '' );
			$out['hotels']               = self::decode_json_array( $r['hotels'] ?? '' );
			$out['shipping']             = array(
				'name'      => (string) ( $r['ship_name'] ?? '' ),
				'email'     => (string) ( $r['ship_email'] ?? '' ),
				'phone'     => (string) ( $r['ship_phone'] ?? '' ),
				'address_1' => (string) ( $r['ship_address_1'] ?? '' ),
				'address_2' => (string) ( $r['ship_address_2'] ?? '' ),
				'address_3' => (string) ( $r['ship_address_3'] ?? '' ),
				'city'      => (string) ( $r['ship_city'] ?? '' ),
				'state'     => (string) ( $r['ship_state'] ?? '' ),
				'zip'       => (string) ( $r['ship_zip'] ?? '' ),
				'workbooks' => isset( $r['ship_workbooks'] ) && $r['ship_workbooks'] !== '' && $r['ship_workbooks'] !== null
					? (int) $r['ship_workbooks'] : null,
				'notes'     => (string) ( $r['ship_notes'] ?? '' ),
			);
		}

		return $out;
	}

	/** Calendar-friendly date range, e.g. "December 2–3, 2027". */
	private static function format_dates_display( string $start, string $end ): string {
		if ( $start === '' ) {
			return '';
		}
		$tz = wp_timezone();
		$s  = DateTime::createFromFormat( 'Y-m-d', $start, $tz );
		if ( ! $s ) {
			return $start;
		}
		$e = $end !== '' ? DateTime::createFromFormat( 'Y-m-d', $end, $tz ) : $s;
		if ( ! $e ) {
			$e = $s;
		}
		if ( $s->format( 'Y-m-d' ) === $e->format( 'Y-m-d' ) ) {
			return $s->format( 'F j, Y' );
		}
		if ( $s->format( 'Y-m' ) === $e->format( 'Y-m' ) ) {
			return $s->format( 'F j' ) . '–' . $e->format( 'j, Y' );
		}
		if ( $s->format( 'Y' ) === $e->format( 'Y' ) ) {
			return $s->format( 'F j' ) . ' – ' . $e->format( 'F j, Y' );
		}
		return $s->format( 'F j, Y' ) . ' – ' . $e->format( 'F j, Y' );
	}

	/** Single-line venue address from street / city / state / zip columns. */
	private static function format_venue_address( array $r ): string {
		$parts = array_filter( array(
			trim( (string) ( $r['street_address_1'] ?? '' ) ),
			trim( (string) ( $r['street_address_2'] ?? '' ) ),
			trim( (string) ( $r['street_address_3'] ?? '' ) ),
		) );
		$city  = trim( (string) ( $r['city'] ?? '' ) );
		$state = trim( (string) ( $r['state'] ?? '' ) );
		$zip   = trim( (string) ( $r['zip_code'] ?? '' ) );
		$csz   = trim( $city . ( $city && $state ? ', ' : '' ) . $state . ( $zip !== '' ? ' ' . $zip : '' ) );
		if ( $csz !== '' ) {
			$parts[] = $csz;
		}
		return implode( ', ', $parts );
	}

	private static function event_is_private( array $r ): bool {
		if ( (int) ( $r['eve_public_hide'] ?? 0 ) === 1 ) {
			return true;
		}
		if ( preg_match( '/\|\s*private/i', (string) ( $r['eve_location'] ?? '' ) ) ) {
			return true;
		}
		return strtolower( trim( (string) ( $r['marketer_name'] ?? '' ) ) ) === 'private';
	}

	private static function decode_json_array( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// ── GET /instructors ─────────────────────────────────────────────────────

	public static function get_instructors( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$inst = $wpdb->prefix . 'event_instructor';

		$rows = $wpdb->get_results(
			"SELECT event_instructor_id AS id, event_instructor_name AS name
			 FROM `{$inst}`
			 WHERE event_instructor_status = 1
			 ORDER BY event_instructor_name ASC",
			ARRAY_A
		);

		$data = array_map( function( $r ) {
			return array( 'id' => (int) $r['id'], 'name' => $r['name'] );
		}, $rows );

		return new WP_REST_Response( $data, 200 );
	}

	// ── Matching helpers ─────────────────────────────────────────────────────

	/**
	 * Try to match upcoming events for a given city string.
	 * Returns [ array $rows, bool $fuzzy_matched ].
	 *
	 * Priority:
	 *   1. Exact substring match on full input.
	 *   2. Strip after comma (handles "San Diego, Private" → "San Diego").
	 *   3. SOUNDEX fuzzy match on the first word as a last resort.
	 */
	private static function match_events( string $city, string $today, string $table ): array {
		global $wpdb;

		$base_sql = "SELECT eve_id, eve_location, eve_start, eve_end, eve_instructor
		             FROM `{$table}`
		             WHERE eve_status = 1
		               AND eve_end >= %s
		               AND eve_location LIKE %s
		             ORDER BY eve_start ASC";

		// 1. Exact substring.
		$rows = $wpdb->get_results(
			$wpdb->prepare( $base_sql, $today, '%' . $wpdb->esc_like( $city ) . '%' ),
			ARRAY_A
		);
		if ( ! empty( $rows ) ) {
			return array( $rows, false );
		}

		// 2. Strip after comma.
		$stripped = trim( explode( ',', $city )[0] );
		if ( $stripped !== $city ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( $base_sql, $today, '%' . $wpdb->esc_like( $stripped ) . '%' ),
				ARRAY_A
			);
			if ( ! empty( $rows ) ) {
				return array( $rows, false );
			}
		}

		// 3. Fuzzy: pull all upcoming events and compare via similar_text().
		$all = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eve_id, eve_location, eve_start, eve_end, eve_instructor
				 FROM `{$table}`
				 WHERE eve_status = 1 AND eve_end >= %s
				 ORDER BY eve_start ASC",
				$today
			),
			ARRAY_A
		);

		$best_pct  = 0;
		$best_rows = array();
		$search    = strtolower( $stripped ?: $city );

		foreach ( $all as $row ) {
			// Compare against just the city portion of the stored location (before comma).
			$loc_city = strtolower( trim( explode( ',', $row['eve_location'] )[0] ) );
			similar_text( $search, $loc_city, $pct );
			if ( $pct >= 70 ) { // 70 % similarity threshold
				if ( $pct > $best_pct ) {
					$best_pct  = $pct;
					$best_rows = array( $row );
				} elseif ( $pct === $best_pct ) {
					$best_rows[] = $row;
				}
			}
		}

		return array( $best_rows, ! empty( $best_rows ) );
	}

	/**
	 * Case-insensitive name match against the pre-loaded instructor list.
	 * Returns the matched instructor array or null.
	 */
	private static function match_instructor( string $name, array $instructors ): ?array {
		$needle = strtolower( trim( $name ) );
		foreach ( $instructors as $inst ) {
			if ( strtolower( trim( $inst['name'] ) ) === $needle ) {
				return $inst;
			}
		}
		// Partial match fallback (e.g. "Terri" matches "Terri Smith").
		foreach ( $instructors as $inst ) {
			if ( str_contains( strtolower( $inst['name'] ), $needle ) ) {
				return $inst;
			}
		}
		return null;
	}

	/** Build a standardised result array. */
	private static function result(
		string $input_city,
		string $input_instructor,
		string $status,
		?array $event,
		?array $instructor,
		bool   $fuzzy,
		?string $warning
	): array {
		return array(
			'input_city'        => $input_city,
			'input_instructor'  => $input_instructor,
			'status'            => $status,
			'eve_id'            => $event     ? (int) $event['eve_id']    : null,
			'eve_location'      => $event     ? $event['eve_location']    : null,
			'eve_start'         => $event     ? $event['eve_start']       : null,
			'instructor_id'     => $instructor ? (int) $instructor['id']  : null,
			'instructor_name'   => $instructor ? $instructor['name']      : null,
			'fuzzy_match'       => $fuzzy,
			'warning'           => $warning,
		);
	}

	// ── Key regeneration (admin-post) ────────────────────────────────────────

	public static function handle_regenerate_key(): void {
		check_admin_referer( 'hostlinks_regenerate_api_key' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		$key = wp_generate_password( 40, false );
		update_option( 'hostlinks_automation_api_key', $key );
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'hostlinks-settings', 'tab' => 'automation-api', 'hl_key_regen' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
