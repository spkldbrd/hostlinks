<?php
/**
 * Hostlinks Automation REST API.
 *
 * Namespace : hostlinks/v1
 * Base URL  : /wp-json/hostlinks/v1/
 *
 * Endpoints:
 *   POST /assign-instructor   — bulk-assign instructors to upcoming events
 *   GET  /upcoming-events     — list upcoming events with current instructor
 *   GET  /instructors         — list all active instructors
 *
 * Auth: every request must include the header
 *   X-HL-Key: {value of option hostlinks_automation_api_key}
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

		register_rest_route( self::NAMESPACE, '/upcoming-events', array(
			'methods'             => 'GET',
			'callback'            => array( static::class, 'get_upcoming_events' ),
			'permission_callback' => array( static::class, 'check_api_key' ),
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

	// ── POST /assign-instructor ──────────────────────────────────────────────

	public static function assign_instructor( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$edl  = $wpdb->prefix . 'event_details_list';
		$inst = $wpdb->prefix . 'event_instructor';

		$body        = $request->get_json_params();
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
		$summary = array( 'total' => 0, 'updated' => 0, 'no_change' => 0, 'not_found' => 0, 'needs_review' => 0 );

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

			// ── Update ────────────────────────────────────────────────────────
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

			$warning = $fuzzy ? 'City matched by fuzzy search — please verify.' : null;
			$results[] = self::result( $input_city, $input_instructor, 'updated',
				$event, $matched_instructor, $fuzzy, $warning );
			$summary['updated']++;
		}

		return new WP_REST_Response( array( 'results' => $results, 'summary' => $summary ), 200 );
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
