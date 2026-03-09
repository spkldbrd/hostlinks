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

	// ── Format values ─────────────────────────────────────────────────────────
	const FORMAT_IN_PERSON = 'in-person';
	const FORMAT_VIRTUAL   = 'virtual';

	// ── US timezone options ───────────────────────────────────────────────────
	const TIMEZONES = array(
		'EST (Eastern Time)'      => 'EST (Eastern Time)',
		'CST (Central Time)'      => 'CST (Central Time)',
		'MST (Mountain Time)'     => 'MST (Mountain Time)',
		'PST (Pacific Time)'      => 'PST (Pacific Time)',
		'AKST (Alaska Time)'      => 'AKST (Alaska Time)',
		'HST (Hawaii Time)'       => 'HST (Hawaii Time)',
		'America/New_York'        => 'America/New_York',
		'America/Chicago'         => 'America/Chicago',
		'America/Denver'          => 'America/Denver',
		'America/Los_Angeles'     => 'America/Los_Angeles',
	);

	// ── Validate ─────────────────────────────────────────────────────────────

	/**
	 * Validate the raw POST payload.
	 *
	 * @param array $raw  Raw $_POST data.
	 * @return array      Keyed errors: [ field_name => message ]. Empty = valid.
	 */
	public static function validate( array $raw ) {
		$errors = array();
		$format = sanitize_text_field( $raw['hl_format'] ?? '' );

		// Event Details
		if ( empty( trim( $raw['hl_event_title'] ?? '' ) ) ) {
			$errors['hl_event_title'] = 'Event title is required.';
		}
		if ( empty( trim( $raw['hl_category'] ?? '' ) ) ) {
			$errors['hl_category'] = 'Category is required.';
		}
		if ( ! in_array( $format, array( self::FORMAT_IN_PERSON, self::FORMAT_VIRTUAL ), true ) ) {
			$errors['hl_format'] = 'Please select a format.';
		}
		if ( empty( trim( $raw['hl_timezone'] ?? '' ) ) ) {
			$errors['hl_timezone'] = 'Timezone is required.';
		}
		if ( empty( trim( $raw['hl_trainer'] ?? '' ) ) ) {
			$errors['hl_trainer'] = 'Trainer is required.';
		}

		// Dates & Times
		if ( empty( trim( $raw['hl_start_date'] ?? '' ) ) ) {
			$errors['hl_start_date'] = 'Start date is required.';
		} elseif ( ! self::is_valid_date( $raw['hl_start_date'] ) ) {
			$errors['hl_start_date'] = 'Invalid start date.';
		}
		if ( empty( trim( $raw['hl_end_date'] ?? '' ) ) ) {
			$errors['hl_end_date'] = 'End date is required.';
		} elseif ( ! self::is_valid_date( $raw['hl_end_date'] ) ) {
			$errors['hl_end_date'] = 'Invalid end date.';
		}
		if ( empty( trim( $raw['hl_start_time'] ?? '' ) ) ) {
			$errors['hl_start_time'] = 'Start time is required.';
		}
		if ( empty( trim( $raw['hl_end_time'] ?? '' ) ) ) {
			$errors['hl_end_time'] = 'End time is required.';
		}

		// Venue — only required for in-person events
		if ( $format === self::FORMAT_IN_PERSON ) {
			if ( empty( trim( $raw['hl_street_address_1'] ?? '' ) ) ) {
				$errors['hl_street_address_1'] = 'Street address is required for in-person events.';
			}
			if ( empty( trim( $raw['hl_city'] ?? '' ) ) ) {
				$errors['hl_city'] = 'City is required for in-person events.';
			}
			if ( empty( trim( $raw['hl_state'] ?? '' ) ) ) {
				$errors['hl_state'] = 'State is required for in-person events.';
			}
		}

		// Price — numeric if provided
		$price_raw = trim( $raw['hl_price'] ?? '' );
		if ( $price_raw !== '' && ! is_numeric( $price_raw ) ) {
			$errors['hl_price'] = 'Price must be a number.';
		}

		// Max attendees — integer if provided
		$max_raw = trim( $raw['hl_max_attendees'] ?? '' );
		if ( $max_raw !== '' && ( ! ctype_digit( $max_raw ) || (int) $max_raw < 0 ) ) {
			$errors['hl_max_attendees'] = 'Max attendees must be a positive whole number.';
		}

		// CC emails — validate each non-empty address
		foreach ( (array) ( $raw['hl_cc_email'] ?? array() ) as $i => $email ) {
			$email = trim( $email );
			if ( $email !== '' && ! is_email( $email ) ) {
				$errors[ 'hl_cc_email_' . $i ] = 'Invalid email address: ' . esc_html( $email );
			}
		}

		return $errors;
	}

	// ── Normalize ─────────────────────────────────────────────────────────────

	/**
	 * Sanitize and reshape the raw POST data into a clean insert-ready array.
	 * Must only be called after validate() returns an empty error array.
	 *
	 * @param array $raw  Raw $_POST data.
	 * @return array      Clean data ready for Hostlinks_Event_Request_Storage::insert().
	 */
	public static function normalize( array $raw ) {
		$format = sanitize_text_field( $raw['hl_format'] );
		$city   = sanitize_text_field( $raw['hl_city']   ?? '' );
		$state  = sanitize_text_field( $raw['hl_state']  ?? '' );

		// Build the cc_emails JSON array — strip empties.
		$cc_emails = array_values( array_filter(
			array_map( 'sanitize_email', (array) ( $raw['hl_cc_email'] ?? array() ) )
		) );

		// Hotels repeatable group
		$hotels = array();
		$hotel_names = (array) ( $raw['hl_hotel_name'] ?? array() );
		foreach ( $hotel_names as $i => $name ) {
			$name = sanitize_text_field( $name );
			if ( $name === '' ) {
				continue;
			}
			$hotels[] = array(
				'name'    => $name,
				'phone'   => sanitize_text_field( $raw['hl_hotel_phone']   [ $i ] ?? '' ),
				'address' => sanitize_text_field( $raw['hl_hotel_address'] [ $i ] ?? '' ),
				'url'     => esc_url_raw( trim( $raw['hl_hotel_url'][ $i ] ?? '' ) ),
			);
		}

		// Host contacts repeatable group
		$host_contacts = array();
		$contact_names = (array) ( $raw['hl_contact_name'] ?? array() );
		foreach ( $contact_names as $i => $cname ) {
			$cname = sanitize_text_field( $cname );
			if ( $cname === '' ) {
				continue;
			}
			$host_contacts[] = array(
				'name'          => $cname,
				'agency'        => sanitize_text_field( $raw['hl_contact_agency']  [ $i ] ?? '' ),
				'title'         => sanitize_text_field( $raw['hl_contact_title']   [ $i ] ?? '' ),
				'email'         => sanitize_email(      $raw['hl_contact_email']   [ $i ] ?? '' ),
				'phone'         => sanitize_text_field( $raw['hl_contact_phone']   [ $i ] ?? '' ),
				'phone2'        => sanitize_text_field( $raw['hl_contact_phone2']  [ $i ] ?? '' ),
				'dnl_phone'     => ! empty( $raw['hl_contact_dnl_phone']  [ $i ] ),
				'dnl_phone2'    => ! empty( $raw['hl_contact_dnl_phone2'] [ $i ] ),
				'publish'       => ! empty( $raw['hl_contact_publish']    [ $i ] ),
			);
		}

		$price_raw = trim( $raw['hl_price'] ?? '' );
		$max_raw   = trim( $raw['hl_max_attendees'] ?? '' );

		$event_title = sanitize_text_field( $raw['hl_event_title'] );

		return array(
			'request_status'  => self::STATUS_NEW,
			'submitted_at'    => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
			'event_title'     => $event_title,
			'hostlinks_title' => self::build_hostlinks_title( $raw ),
			'description'     => sanitize_textarea_field( $raw['hl_description'] ?? '' ),
			'category'        => sanitize_text_field( $raw['hl_category'] ),
			'format'          => $format,
			'timezone'        => sanitize_text_field( $raw['hl_timezone'] ),
			'marketer'        => sanitize_text_field( $raw['hl_marketer']  ?? '' ),
			'trainer'         => sanitize_text_field( $raw['hl_trainer'] ),
			'start_date'      => sanitize_text_field( $raw['hl_start_date'] ),
			'end_date'        => sanitize_text_field( $raw['hl_end_date'] ),
			'start_time'      => sanitize_text_field( $raw['hl_start_time'] ),
			'end_time'        => sanitize_text_field( $raw['hl_end_time'] ),
			'host_name'       => sanitize_text_field( $raw['hl_host_name']       ?? '' ),
			'location_name'   => sanitize_text_field( $raw['hl_location_name']   ?? '' ),
			'street_address_1'=> sanitize_text_field( $raw['hl_street_address_1']?? '' ),
			'street_address_2'=> sanitize_text_field( $raw['hl_street_address_2']?? '' ),
			'city'            => $city,
			'state'           => $state,
			'zip_code'        => sanitize_text_field( $raw['hl_zip_code'] ?? '' ),
			'price'           => $price_raw !== '' ? (float) $price_raw : null,
			'max_attendees'   => $max_raw   !== '' ? (int)   $max_raw   : null,
			'special_message' => sanitize_textarea_field( $raw['hl_special_message'] ?? '' ),
			'cc_emails'       => wp_json_encode( $cc_emails ),
			'hotels'          => wp_json_encode( $hotels ),
			'host_contacts'   => wp_json_encode( $host_contacts ),
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Auto-generate an internal Hostlinks-style title.
	 * Format: "City, ST - Category (Format) Start"  or  "Zoom - Category Start"
	 *
	 * @param array $raw
	 * @return string
	 */
	public static function build_hostlinks_title( array $raw ) {
		$format    = sanitize_text_field( $raw['hl_format']     ?? '' );
		$city      = sanitize_text_field( $raw['hl_city']       ?? '' );
		$state     = sanitize_text_field( $raw['hl_state']      ?? '' );
		$category  = sanitize_text_field( $raw['hl_category']   ?? '' );
		$start     = sanitize_text_field( $raw['hl_start_date'] ?? '' );

		$location = ( $format === self::FORMAT_VIRTUAL )
			? 'Zoom'
			: trim( $city . ( $state ? ', ' . $state : '' ) );

		$date_part = $start ? date( 'M j, Y', strtotime( $start ) ) : '';

		$parts = array_filter( array( $location, $category, $date_part ) );
		return implode( ' - ', $parts );
	}

	/** Simple Y-m-d date sanity check. */
	private static function is_valid_date( string $date ): bool {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
