<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hostlinks_Event_Request
 *
 * Validation, normalization, and helper logic for event request intake.
 * No WordPress hooks — pure logic consumed by Hostlinks_Event_Request_Shortcode.
 */
class Hostlinks_Event_Request {

	// ── Status registry ───────────────────────────────────────────────────────
	const STATUS_NEW       = 'new';
	const STATUS_REVIEWED  = 'reviewed';
	const STATUS_CONVERTED = 'converted';
	const STATUS_ARCHIVED  = 'archived';

	const STATUSES = array(
		self::STATUS_NEW       => 'New',
		self::STATUS_REVIEWED  => 'Reviewed',
		self::STATUS_CONVERTED => 'Converted',
		self::STATUS_ARCHIVED  => 'Archived',
	);

	// ── US timezone options ───────────────────────────────────────────────────
	const TIMEZONES = array(
		'EST (Eastern Time)'  => 'EST (Eastern Time)',
		'CST (Central Time)'  => 'CST (Central Time)',
		'MST (Mountain Time)' => 'MST (Mountain Time)',
		'PST (Pacific Time)'  => 'PST (Pacific Time)',
		'AKST (Alaska Time)'  => 'AKST (Alaska Time)',
		'HST (Hawaii Time)'   => 'HST (Hawaii Time)',
	);

	// ── Validate ─────────────────────────────────────────────────────────────

	/**
	 * Validate the raw POST payload.
	 *
	 * @param array $raw  Raw $_POST data.
	 * @return array      Keyed errors: [ field_name => message ]. Empty = valid.
	 */
	public static function validate( array $raw ): array {
		$errors = array();

		// Marketer — now required
		if ( empty( trim( $raw['hl_marketer'] ?? '' ) ) ) {
			$errors['hl_marketer'] = 'Please select a marketer.';
		}

		// Timezone is optional — only required per-event when ZOOM is checked.

		// Event rows — at least one required
		$event_categories  = (array) ( $raw['hl_event_category']   ?? array() );
		$event_start_dates = (array) ( $raw['hl_event_start_date']  ?? array() );
		$event_end_dates   = (array) ( $raw['hl_event_end_date']    ?? array() );
		$event_trainers    = (array) ( $raw['hl_event_trainer']     ?? array() );

		$has_event = false;
		foreach ( $event_categories as $i => $cat ) {
			$cat = trim( $cat );
			if ( $cat === '' ) {
				continue;
			}
			$has_event = true;
			$start = trim( $event_start_dates[ $i ] ?? '' );
			$end   = trim( $event_end_dates[ $i ]   ?? '' );
			$trainer = trim( $event_trainers[ $i ]  ?? '' );

			if ( $start === '' ) {
				$errors[ 'hl_event_start_date_' . $i ] = 'Start date is required for event row ' . ( $i + 1 ) . '.';
			} elseif ( ! self::is_valid_date( $start ) ) {
				$errors[ 'hl_event_start_date_' . $i ] = 'Invalid start date on event row ' . ( $i + 1 ) . '.';
			}
			if ( $end === '' ) {
				$errors[ 'hl_event_end_date_' . $i ] = 'End date is required for event row ' . ( $i + 1 ) . '.';
			} elseif ( ! self::is_valid_date( $end ) ) {
				$errors[ 'hl_event_end_date_' . $i ] = 'Invalid end date on event row ' . ( $i + 1 ) . '.';
			}
			// Trainer defaults to TBA so it is always set.
		}

		if ( ! $has_event ) {
			$errors['hl_events'] = 'At least one event row is required.';
		}

		// City and state required only when an address line is provided
		if ( ! empty( trim( $raw['hl_street_address_1'] ?? '' ) ) ) {
			if ( empty( trim( $raw['hl_city']  ?? '' ) ) ) {
				$errors['hl_city']  = 'City is required when an address is provided.';
			}
			if ( empty( trim( $raw['hl_state'] ?? '' ) ) ) {
				$errors['hl_state'] = 'State is required when an address is provided.';
			}
		}

		// Max attendees — integer if provided
		$max_raw = trim( $raw['hl_max_attendees'] ?? '' );
		if ( $max_raw !== '' && ( ! ctype_digit( $max_raw ) || (int) $max_raw < 0 ) ) {
			$errors['hl_max_attendees'] = 'Max attendees must be a positive whole number.';
		}

		return $errors;
	}

	// ── Normalize ─────────────────────────────────────────────────────────────

	/**
	 * Sanitize and reshape the raw POST data into an array of insert-ready records,
	 * one per event row submitted. All records share the same submission_group UUID
	 * and venue / host / hotel / contact data.
	 *
	 * Must only be called after validate() returns an empty error array.
	 *
	 * @param array       $raw              Raw $_POST data.
	 * @param string      $submission_group UUID shared across all records in this submit.
	 * @param string|null $parking_file_url URL of the uploaded parking PDF (or null).
	 * @return array[]    Array of complete row arrays ready for Storage::insert().
	 */
	public static function normalize( array $raw, string $submission_group, ?string $parking_file_url = null ): array {
		$now = current_time( 'mysql' );

		// ── Shared (venue / host / hotel / contact) fields ─────────────────
		$city  = sanitize_text_field( $raw['hl_city']  ?? '' );
		$state = sanitize_text_field( $raw['hl_state'] ?? '' );

		// Hotels repeatable group
		$hotels = array();
		foreach ( (array) ( $raw['hl_hotel_name'] ?? array() ) as $i => $name ) {
			$name = sanitize_text_field( $name );
			if ( $name === '' ) continue;
			$hotels[] = array(
				'name'    => $name,
				'phone'   => sanitize_text_field( $raw['hl_hotel_phone']   [ $i ] ?? '' ),
				'address' => sanitize_text_field( $raw['hl_hotel_address'] [ $i ] ?? '' ),
				'url'     => esc_url_raw( trim( $raw['hl_hotel_url'][ $i ] ?? '' ) ),
			);
		}

		// Host contacts repeatable group (includes cc_on_alerts flag)
		$host_contacts = array();
		foreach ( (array) ( $raw['hl_contact_name'] ?? array() ) as $i => $cname ) {
			$cname = sanitize_text_field( $cname );
			if ( $cname === '' ) continue;
			$host_contacts[] = array(
				'name'          => $cname,
				'agency'        => sanitize_text_field( $raw['hl_contact_agency']  [ $i ] ?? '' ),
				'title'         => sanitize_text_field( $raw['hl_contact_title']   [ $i ] ?? '' ),
				'email'         => sanitize_email(      $raw['hl_contact_email']   [ $i ] ?? '' ),
				'phone'         => sanitize_text_field( $raw['hl_contact_phone']   [ $i ] ?? '' ),
				'phone2'        => sanitize_text_field( $raw['hl_contact_phone2']  [ $i ] ?? '' ),
				'dnl_phone'        => ! empty( $raw['hl_contact_dnl_phone']        [ $i ] ),
				'dnl_phone2'       => ! empty( $raw['hl_contact_dnl_phone2']       [ $i ] ),
				'include_in_email' => ! empty( $raw['hl_contact_include_email']    [ $i ] ),
				'cc_on_alerts'     => ! empty( $raw['hl_contact_cc']               [ $i ] ),
			);
		}

		$max_raw = trim( $raw['hl_max_attendees'] ?? '' );

		$shared = array(
			'submission_group'    => $submission_group,
			'request_status'      => self::STATUS_NEW,
			'submitted_at'        => $now,
			'updated_at'          => $now,
			'event_title'         => '',
			'hostlinks_title'     => '',
			'description'         => '',
			'custom_email_intro'  => sanitize_textarea_field( $raw['hl_custom_email_intro'] ?? '' ),
			'format'              => '',
			'timezone'            => sanitize_text_field( $raw['hl_timezone'] ?? '' ),
			'marketer'            => sanitize_text_field( $raw['hl_marketer'] ?? '' ),
			'host_name'           => sanitize_text_field( $raw['hl_host_name']        ?? '' ),
			'displayed_as'        => sanitize_text_field( $raw['hl_displayed_as']     ?? '' ),
			'location_name'       => sanitize_text_field( $raw['hl_location_name']    ?? '' ),
			'street_address_1'    => sanitize_text_field( $raw['hl_street_address_1'] ?? '' ),
			'street_address_2'    => sanitize_text_field( $raw['hl_street_address_2'] ?? '' ),
			'street_address_3'    => sanitize_text_field( $raw['hl_street_address_3'] ?? '' ),
			'city'                => $city,
			'state'               => $state,
			'zip_code'            => sanitize_text_field( $raw['hl_zip_code'] ?? '' ),
			'special_instructions'=> sanitize_textarea_field( $raw['hl_special_instructions'] ?? '' ),
			'parking_file_url'    => $parking_file_url ?? '',
			'max_attendees'       => $max_raw !== '' ? (int) $max_raw : null,
			'special_message'     => '',
			'cc_emails'           => '[]',
			'start_time'          => '',
			'end_time'            => '',
			'hotels'              => wp_json_encode( $hotels ),
			'host_contacts'       => wp_json_encode( $host_contacts ),
		);

		// ── Per-event-row records ───────────────────────────────────────────
		$records           = array();
		$event_categories  = (array) ( $raw['hl_event_category']  ?? array() );
		$event_start_dates = (array) ( $raw['hl_event_start_date'] ?? array() );
		$event_end_dates   = (array) ( $raw['hl_event_end_date']   ?? array() );
		$event_trainers    = (array) ( $raw['hl_event_trainer']    ?? array() );
		$event_zooms       = (array) ( $raw['hl_event_zoom']       ?? array() );
		$event_timezones   = (array) ( $raw['hl_event_timezone']   ?? array() );
		$shared_timezone   = $shared['timezone'];

		foreach ( $event_categories as $i => $cat ) {
			$cat = sanitize_text_field( $cat );
			if ( $cat === '' ) continue;

			$start   = sanitize_text_field( $event_start_dates[ $i ] ?? '' );
			$end     = sanitize_text_field( $event_end_dates[ $i ]   ?? '' );
			$trainer = sanitize_text_field( $event_trainers[ $i ]    ?? '' );
			$is_zoom = ! empty( $event_zooms[ $i ] );
			$row_tz  = $is_zoom ? sanitize_text_field( $event_timezones[ $i ] ?? '' ) : $shared_timezone;

			$hl_title = self::build_hostlinks_title( $city, $state, $cat, $start );

			$records[] = array_merge( $shared, array(
				'category'        => $cat,
				'trainer'         => $trainer,
				'start_date'      => $start,
				'end_date'        => $end,
				'format'          => $is_zoom ? 'virtual' : 'in-person',
				'timezone'        => $row_tz,
				'price'           => null,
				'hostlinks_title' => $hl_title,
				'event_title'     => $hl_title,
			) );
		}

		return $records;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Auto-generate an internal Hostlinks-style title.
	 * Format: "City, ST - Category - Start Date"
	 */
	public static function build_hostlinks_title( string $city, string $state, string $category, string $start ): string {
		$location  = trim( $city . ( $state ? ', ' . $state : '' ) );
		$date_part = $start ? date( 'M j, Y', strtotime( $start ) ) : '';
		$parts     = array_filter( array( $location, $category, $date_part ) );
		return implode( ' - ', $parts );
	}

	/** Simple Y-m-d date sanity check. */
	private static function is_valid_date( string $date ): bool {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
