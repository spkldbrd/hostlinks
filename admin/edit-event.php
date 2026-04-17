<?php
/**
 * Unified event form: Add New, Add from CVENT, and Edit.
 * Accessed via:
 *   ?page=booking-menu&edit_event={id}   → Edit existing event
 *   ?page=booking-menu&add_event=1       → Add new event (blank form)
 *   ?page=booking-menu&add_cvent={uuid}  → Add from CVENT (pre-populated form)
 * Included from admin/booking.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

global $wpdb;

$list_url = admin_url( 'admin.php?page=booking-menu' );
$timezone = wp_timezone();
$table    = $wpdb->prefix . 'event_details_list';

// ── Mode detection ────────────────────────────────────────────────────────────
// Modes: edit, add, cvent, request
//   request: pre-fill the add form from a row in wp_hostlinks_event_requests.
if ( isset( $_GET['add_cvent'] ) ) {
	$mode   = 'cvent';
	$eve_id = 0;
} elseif ( isset( $_GET['add_request'] ) ) {
	$mode   = 'request';
	$eve_id = 0;
} elseif ( isset( $_GET['add_event'] ) ) {
	$mode   = 'add';
	$eve_id = 0;
} else {
	$mode   = 'edit';
	$eve_id = (int) ( $_GET['edit_event'] ?? 0 );
	if ( ! $eve_id ) {
		wp_safe_redirect( $list_url );
		exit;
	}
}

// Safe defaults for variables only populated in add/cvent modes.
$cvent_uuid_pre      = '';
$cvent_title_pre     = '';
$cvent_start_utc_pre = '';

// Request-mode globals populated during data load below. Kept here as safe
// defaults so later blocks (save handler, header, cancel URL) can reference
// them unconditionally.
$request_id     = (int) ( $_GET['add_request'] ?? 0 );
$request_row    = null;
$request_header = '';

// ── Load lookup tables ────────────────────────────────────────────────────────
$all_types       = $wpdb->get_results( "SELECT event_type_id AS id, event_type_name AS name FROM {$wpdb->prefix}event_type WHERE event_type_status = 1 ORDER BY name", ARRAY_A );
$all_marketers   = $wpdb->get_results( "SELECT event_marketer_id AS id, event_marketer_name AS name FROM {$wpdb->prefix}event_marketer WHERE event_marketer_status = 1 ORDER BY name", ARRAY_A );
$all_instructors = $wpdb->get_results( "SELECT event_instructor_id AS id, event_instructor_name AS name FROM {$wpdb->prefix}event_instructor WHERE event_instructor_status = 1 ORDER BY name", ARRAY_A );

// ── Helper: build the eve_tot_date display string ("YYYY/MM/DD - YYYY/MM/DD")
// Returns '' if either input is not a valid YYYY-MM-DD date. Rejecting garbage
// prevents strings like "foo - foo" from landing in the DB.
function _hl_build_tot_date( string $start, string $end ): string {
	$re = '/^\d{4}-\d{2}-\d{2}$/';
	if ( ! preg_match( $re, $start ) || ! preg_match( $re, $end ) ) {
		return '';
	}
	return str_replace( '-', '/', $start ) . ' - ' . str_replace( '-', '/', $end );
}

// ── Helper: normalize a phone number to XXX-XXX-XXXX ─────────────────────────
function _hl_edit_phone( string $raw ): string {
	$digits = preg_replace( '/\D/', '', $raw );
	if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
		$digits = substr( $digits, 1 );
	}
	if ( strlen( $digits ) === 10 ) {
		return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
	}
	return $raw;
}

// ── Notices ───────────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_GET['saved'] ) ) {
	$notice = '<div class="notice notice-success is-dismissible"><p>Event added successfully. You can continue editing it here.</p></div>';
}

// ── ADD handler (add + cvent + request modes) ───────────────────────────────
if ( $mode !== 'edit' && isset( $_POST['hl_add_event_submit'] ) ) {
	check_admin_referer( 'hostlinks_add_event_unified' );

	// Core event fields
	$location       = sanitize_text_field( $_POST['eve_location']    ?? '' );
	$eve_type       = (int) ( $_POST['eve_type']       ?? 0 );
	$eve_marketer   = (int) ( $_POST['eve_marketer']   ?? 0 );
	$eve_instructor = (int) ( $_POST['eve_instructor'] ?? 0 );
	$eve_zoom       = sanitize_text_field( $_POST['eve_zoom']      ?? '' );
	$eve_zoom_time  = sanitize_text_field( $_POST['eve_zoom_time'] ?? '' );
	$eve_paid       = (int) ( $_POST['eve_paid']  ?? 0 );
	$eve_free       = (int) ( $_POST['eve_free']  ?? 0 );
	$eve_public_hide = isset( $_POST['eve_public_hide'] ) ? 1 : 0;

	// Date fields
	$eve_start    = sanitize_text_field( $_POST['eve_start_date'] ?? '' );
	$eve_end      = sanitize_text_field( $_POST['eve_end_date']   ?? $eve_start );
	$eve_tot_date = _hl_build_tot_date( $eve_start, $eve_end );

	// Required-field guard — abort with an error notice rather than inserting incomplete data.
	// Placed here (after core + date fields) so we fail fast before doing expensive work
	// like JSON-encoding contact/hotel arrays below.
	$missing = array();
	if ( ! $location )       { $missing[] = 'Location'; }
	if ( ! $eve_type )       { $missing[] = 'Type'; }
	if ( ! $eve_marketer )   { $missing[] = 'Marketer'; }
	if ( ! $eve_instructor ) { $missing[] = 'Instructor'; }
	if ( ! $eve_start )      { $missing[] = 'Start Date'; }
	if ( ! empty( $missing ) ) {
		wp_die(
			'<p><strong>Cannot save event.</strong> The following required fields are missing: ' . esc_html( implode( ', ', $missing ) ) . '.</p>'
			. '<p><a href="javascript:history.back()">Go back</a></p>',
			'Missing Required Fields',
			array( 'response' => 400 )
		);
	}

	// URLs
	$eve_host_url    = esc_url_raw( trim( $_POST['eve_host_url']    ?? '' ) );
	$eve_roster_url  = esc_url_raw( trim( $_POST['eve_roster_url']  ?? '' ) );
	$eve_trainer_url = esc_url_raw( trim( $_POST['eve_trainer_url'] ?? '' ) );
	$eve_web_url     = esc_url_raw( trim( $_POST['eve_web_url']     ?? '' ) );
	$eve_email_url   = esc_url_raw( trim( $_POST['eve_email_url']   ?? '' ) );

	// Shipping
	$ship_workbooks_raw = trim( $_POST['ship_workbooks'] ?? '' );
	$has_shipping       = isset( $_POST['hl_add_shipping'] );

	// Host & Venue
	$host_name     = sanitize_text_field( $_POST['edit_host_name']        ?? '' );
	$displayed_as  = sanitize_text_field( $_POST['edit_displayed_as']     ?? '' );
	$location_name = sanitize_text_field( $_POST['edit_location_name']    ?? '' );
	$addr1         = sanitize_text_field( $_POST['edit_street_address_1'] ?? '' );
	$addr2         = sanitize_text_field( $_POST['edit_street_address_2'] ?? '' );
	$addr3         = sanitize_text_field( $_POST['edit_street_address_3'] ?? '' );
	$ev_city       = sanitize_text_field( $_POST['edit_city']             ?? '' );
	$ev_state      = sanitize_text_field( $_POST['edit_state']            ?? '' );
	$zip_code      = sanitize_text_field( $_POST['edit_zip_code']         ?? '' );

	// Additional Details
	$max_attendees_raw    = trim( $_POST['edit_max_attendees']       ?? '' );
	$special_instructions = sanitize_textarea_field( $_POST['edit_special_instructions'] ?? '' );
	$parking_file_url     = esc_url_raw( trim( $_POST['edit_parking_file_url'] ?? '' ) );
	$custom_email_intro   = sanitize_textarea_field( $_POST['edit_custom_email_intro']   ?? '' );

	// Host Contacts (repeatable → JSON)
	$host_contacts = array();
	foreach ( (array) ( $_POST['edit_contact_name'] ?? array() ) as $i => $cname ) {
		$cname = sanitize_text_field( $cname );
		if ( $cname === '' ) continue;
		$host_contacts[] = array(
			'name'             => $cname,
			'agency'           => sanitize_text_field( $_POST['edit_contact_agency'][$i]  ?? '' ),
			'title'            => sanitize_text_field( $_POST['edit_contact_title'][$i]   ?? '' ),
			'email'            => sanitize_email(      $_POST['edit_contact_email'][$i]   ?? '' ),
			'phone'            => _hl_edit_phone( sanitize_text_field( $_POST['edit_contact_phone'][$i]  ?? '' ) ),
			'phone2'           => _hl_edit_phone( sanitize_text_field( $_POST['edit_contact_phone2'][$i] ?? '' ) ),
			'cc_on_alerts'     => ! empty( $_POST['edit_contact_cc'][$i] ),
			'include_in_email' => ! empty( $_POST['edit_contact_include'][$i] ),
			'dnl_phone'        => ! empty( $_POST['edit_contact_dnl_phone'][$i] ),
			'dnl_phone2'       => ! empty( $_POST['edit_contact_dnl_phone2'][$i] ),
		);
	}

	// Hotels (repeatable → JSON)
	$hotels_arr = array();
	foreach ( (array) ( $_POST['edit_hotel_name'] ?? array() ) as $i => $hname ) {
		$hname = sanitize_text_field( $hname );
		if ( $hname === '' ) continue;
		$hotels_arr[] = array(
			'name'    => $hname,
			'phone'   => sanitize_text_field( $_POST['edit_hotel_phone'][$i]   ?? '' ),
			'address' => sanitize_text_field( $_POST['edit_hotel_address'][$i] ?? '' ),
			'url'     => esc_url_raw( trim( $_POST['edit_hotel_url'][$i] ?? '' ) ),
		);
	}

	// CVENT-specific fields (only populated in cvent mode)
	$insert_cvent_uuid      = '';
	$insert_cvent_title     = '';
	$insert_cvent_start_utc = null;
	if ( $mode === 'cvent' ) {
		// Use the same normalization as the cached CVENT list so the transient cleanup
		// below matches reliably even if the UUID contains a BOM or NBSP prefix.
		$insert_cvent_uuid      = Hostlinks_CVENT_API::sanitize_uuid( $_POST['cvent_uuid'] ?? '' );
		$insert_cvent_title     = sanitize_text_field( $_POST['cvent_title']     ?? '' );
		$start_utc_raw          = sanitize_text_field( $_POST['cvent_start_utc'] ?? '' );
		$insert_cvent_start_utc = $start_utc_raw ? gmdate( 'Y-m-d H:i:s', strtotime( $start_utc_raw ) ) : null;
	}

	$insert_data = array(
		// Core
		'eve_location'    => $location,
		'eve_type'        => $eve_type,
		'eve_marketer'    => $eve_marketer,
		'eve_instructor'  => $eve_instructor,
		'eve_zoom'        => $eve_zoom,
		'eve_zoom_time'   => $eve_zoom_time,
		'eve_paid'        => $eve_paid,
		'eve_free'        => $eve_free,
		'eve_public_hide' => $eve_public_hide,
		'eve_status'      => (int) ( $_POST['eve_status'] ?? 1 ),
		'eve_start'       => $eve_start,
		'eve_end'         => $eve_end,
		'eve_tot_date'    => $eve_tot_date,
		// URLs
		'eve_host_url'    => $eve_host_url,
		'eve_roster_url'  => $eve_roster_url,
		'eve_trainer_url' => $eve_trainer_url,
		'eve_web_url'     => $eve_web_url,
		'eve_email_url'   => $eve_email_url,
		// Shipping
		'ship_name'      => $has_shipping ? sanitize_text_field( $_POST['ship_name']      ?? '' ) : '',
		'ship_email'     => $has_shipping ? sanitize_email(      $_POST['ship_email']     ?? '' ) : '',
		'ship_phone'     => $has_shipping ? sanitize_text_field( $_POST['ship_phone']     ?? '' ) : '',
		'ship_address_1' => $has_shipping ? sanitize_text_field( $_POST['ship_address_1'] ?? '' ) : '',
		'ship_address_2' => $has_shipping ? sanitize_text_field( $_POST['ship_address_2'] ?? '' ) : '',
		'ship_address_3' => $has_shipping ? sanitize_text_field( $_POST['ship_address_3'] ?? '' ) : '',
		'ship_city'      => $has_shipping ? sanitize_text_field( $_POST['ship_city']      ?? '' ) : '',
		'ship_state'     => $has_shipping ? sanitize_text_field( $_POST['ship_state']     ?? '' ) : '',
		'ship_zip'       => $has_shipping ? sanitize_text_field( $_POST['ship_zip']       ?? '' ) : '',
		'ship_workbooks' => ( $has_shipping && $ship_workbooks_raw !== '' ) ? (int) $ship_workbooks_raw : null,
		'ship_notes'     => $has_shipping ? sanitize_textarea_field( $_POST['ship_notes'] ?? '' ) : '',
		// Host & Venue
		'host_name'            => $host_name,
		'displayed_as'         => $displayed_as,
		'location_name'        => $location_name,
		'street_address_1'     => $addr1,
		'street_address_2'     => $addr2,
		'street_address_3'     => $addr3,
		'city'                 => $ev_city,
		'state'                => $ev_state,
		'zip_code'             => $zip_code,
		// Additional
		'max_attendees'        => $max_attendees_raw !== '' ? (int) $max_attendees_raw : null,
		'special_instructions' => $special_instructions,
		'parking_file_url'     => $parking_file_url,
		'custom_email_intro'   => $custom_email_intro,
		// JSON blobs
		'host_contacts'        => wp_json_encode( $host_contacts ),
		'hotels'               => wp_json_encode( $hotels_arr ),
		// Timestamps
		'eve_created_at'       => current_time( 'mysql' ),
	);

	if ( $mode === 'cvent' ) {
		$insert_data['cvent_event_id']        = $insert_cvent_uuid;
		$insert_data['cvent_event_title']     = $insert_cvent_title;
		$insert_data['cvent_event_start_utc'] = $insert_cvent_start_utc;
		$insert_data['cvent_match_status']    = 'manual';
		$insert_data['cvent_last_synced']     = null;
	}

	$wpdb->insert( $table, $insert_data );
	$new_eve_id = (int) $wpdb->insert_id;

	// Auto-populate eve_roster_url if left blank.
	if ( ! $eve_roster_url && $new_eve_id ) {
		$roster_base = Hostlinks_Page_URLs::get_roster();
		if ( $roster_base ) {
			$auto_roster_url = rtrim( $roster_base, '/' ) . '/?eve_id=' . $new_eve_id;
			$wpdb->update( $table, array( 'eve_roster_url' => $auto_roster_url ), array( 'eve_id' => $new_eve_id ), array( '%s' ), array( '%d' ) );
		}
	}

	// Remove from CVENT transient cache so it disappears from the new-events list.
	if ( $mode === 'cvent' && $insert_cvent_uuid ) {
		$cached = get_transient( 'hostlinks_cvent_new_events' );
		if ( is_array( $cached ) ) {
			$cached = array_values( array_filter(
				$cached,
				function( $e ) use ( $insert_cvent_uuid ) {
					return Hostlinks_CVENT_API::sanitize_uuid( $e['id'] ?? '' ) !== $insert_cvent_uuid;
				}
			) );
			set_transient( 'hostlinks_cvent_new_events', $cached, HOUR_IN_SECONDS );
			update_option( 'hostlinks_cvent_new_count', count( $cached ) );
		}
	}

	if ( $new_eve_id ) {
		do_action( 'hostlinks_event_created', $new_eve_id, $eve_start );
	}

	// Request mode: mark the originating event request as converted so it
	// drops off the Pending queue and shows under Completed.
	if ( $mode === 'request' && $new_eve_id ) {
		$req_id_posted = (int) ( $_POST['hl_request_id'] ?? 0 );
		if ( $req_id_posted && class_exists( 'Hostlinks_Event_Request_Storage' ) ) {
			Hostlinks_Event_Request_Storage::update_status( $req_id_posted, 'converted' );
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=booking-menu&edit_event=' . $new_eve_id . '&saved=1' ) );
	exit;
}

// ── EDIT handler ──────────────────────────────────────────────────────────────
if ( $mode === 'edit' && isset( $_POST['hl_edit_full_event'] ) ) {
	check_admin_referer( 'hostlinks_edit_full_event_' . $eve_id );

	// Core event fields
	$location      = sanitize_text_field( $_POST['eve_location']    ?? '' );
	$eve_type      = (int) ( $_POST['eve_type']       ?? 0 );
	$eve_marketer  = (int) ( $_POST['eve_marketer']   ?? 0 );
	$eve_instructor= (int) ( $_POST['eve_instructor'] ?? 0 );
	$eve_zoom      = sanitize_text_field( $_POST['eve_zoom']      ?? '' );
	$eve_zoom_time = sanitize_text_field( $_POST['eve_zoom_time'] ?? '' );
	$eve_paid      = (int) ( $_POST['eve_paid']  ?? 0 );
	$eve_free      = (int) ( $_POST['eve_free']  ?? 0 );
	$eve_public_hide = isset( $_POST['eve_public_hide'] ) ? 1 : 0;
	$eve_status    = (int) ( $_POST['eve_status'] ?? 1 );

	// Date fields
	$eve_start    = sanitize_text_field( $_POST['eve_start_date'] ?? '' );
	$eve_end      = sanitize_text_field( $_POST['eve_end_date']   ?? $eve_start );
	$eve_tot_date = _hl_build_tot_date( $eve_start, $eve_end );

	// URLs
	$eve_host_url    = esc_url_raw( trim( $_POST['eve_host_url']    ?? '' ) );
	$eve_roster_url  = esc_url_raw( trim( $_POST['eve_roster_url']  ?? '' ) );
	$eve_trainer_url = esc_url_raw( trim( $_POST['eve_trainer_url'] ?? '' ) );
	$eve_web_url     = esc_url_raw( trim( $_POST['eve_web_url']     ?? '' ) );
	$eve_email_url   = esc_url_raw( trim( $_POST['eve_email_url']   ?? '' ) );

	// Shipping
	$ship_workbooks_raw = trim( $_POST['ship_workbooks'] ?? '' );
	$has_shipping       = isset( $_POST['hl_add_shipping'] );

	// ── Host & Venue ─────────────────────────────────────────────────────
	$host_name     = sanitize_text_field( $_POST['edit_host_name']        ?? '' );
	$displayed_as  = sanitize_text_field( $_POST['edit_displayed_as']     ?? '' );
	$location_name = sanitize_text_field( $_POST['edit_location_name']    ?? '' );
	$addr1         = sanitize_text_field( $_POST['edit_street_address_1'] ?? '' );
	$addr2         = sanitize_text_field( $_POST['edit_street_address_2'] ?? '' );
	$addr3         = sanitize_text_field( $_POST['edit_street_address_3'] ?? '' );
	$ev_city       = sanitize_text_field( $_POST['edit_city']             ?? '' );
	$ev_state      = sanitize_text_field( $_POST['edit_state']            ?? '' );
	$zip_code      = sanitize_text_field( $_POST['edit_zip_code']         ?? '' );

	// ── Additional Details ────────────────────────────────────────────────
	$max_attendees_raw    = trim( $_POST['edit_max_attendees']       ?? '' );
	$special_instructions = sanitize_textarea_field( $_POST['edit_special_instructions'] ?? '' );
	$parking_file_url     = esc_url_raw( trim( $_POST['edit_parking_file_url'] ?? '' ) );
	$custom_email_intro   = sanitize_textarea_field( $_POST['edit_custom_email_intro']   ?? '' );

	// ── Host Contacts (repeatable → JSON) ────────────────────────────────
	$host_contacts = array();
	foreach ( (array) ( $_POST['edit_contact_name'] ?? array() ) as $i => $cname ) {
		$cname = sanitize_text_field( $cname );
		if ( $cname === '' ) continue;
		$host_contacts[] = array(
			'name'             => $cname,
			'agency'           => sanitize_text_field( $_POST['edit_contact_agency'][$i]  ?? '' ),
			'title'            => sanitize_text_field( $_POST['edit_contact_title'][$i]   ?? '' ),
			'email'            => sanitize_email(      $_POST['edit_contact_email'][$i]   ?? '' ),
			'phone'            => _hl_edit_phone( sanitize_text_field( $_POST['edit_contact_phone'][$i]  ?? '' ) ),
			'phone2'           => _hl_edit_phone( sanitize_text_field( $_POST['edit_contact_phone2'][$i] ?? '' ) ),
			'cc_on_alerts'     => ! empty( $_POST['edit_contact_cc'][$i] ),
			'include_in_email' => ! empty( $_POST['edit_contact_include'][$i] ),
			'dnl_phone'        => ! empty( $_POST['edit_contact_dnl_phone'][$i] ),
			'dnl_phone2'       => ! empty( $_POST['edit_contact_dnl_phone2'][$i] ),
		);
	}

	// ── Hotels (repeatable → JSON) ────────────────────────────────────────
	$hotels_arr = array();
	foreach ( (array) ( $_POST['edit_hotel_name'] ?? array() ) as $i => $hname ) {
		$hname = sanitize_text_field( $hname );
		if ( $hname === '' ) continue;
		$hotels_arr[] = array(
			'name'    => $hname,
			'phone'   => sanitize_text_field( $_POST['edit_hotel_phone'][$i]   ?? '' ),
			'address' => sanitize_text_field( $_POST['edit_hotel_address'][$i] ?? '' ),
			'url'     => esc_url_raw( trim( $_POST['edit_hotel_url'][$i] ?? '' ) ),
		);
	}

	$update_data = array(
		// Core
		'eve_location'    => $location,
		'eve_type'        => $eve_type,
		'eve_marketer'    => $eve_marketer,
		'eve_instructor'  => $eve_instructor,
		'eve_zoom'        => $eve_zoom,
		'eve_zoom_time'   => $eve_zoom_time,
		'eve_paid'        => $eve_paid,
		'eve_free'        => $eve_free,
		'eve_public_hide' => $eve_public_hide,
		'eve_status'      => $eve_status,
		'eve_start'       => $eve_start,
		'eve_end'         => $eve_end,
		'eve_tot_date'    => $eve_tot_date,
		// URLs
		'eve_host_url'    => $eve_host_url,
		'eve_roster_url'  => $eve_roster_url,
		'eve_trainer_url' => $eve_trainer_url,
		'eve_web_url'     => $eve_web_url,
		'eve_email_url'   => $eve_email_url,
		// Shipping
		'ship_name'      => $has_shipping ? sanitize_text_field( $_POST['ship_name']      ?? '' ) : '',
		'ship_email'     => $has_shipping ? sanitize_email(      $_POST['ship_email']     ?? '' ) : '',
		'ship_phone'     => $has_shipping ? sanitize_text_field( $_POST['ship_phone']     ?? '' ) : '',
		'ship_address_1' => $has_shipping ? sanitize_text_field( $_POST['ship_address_1'] ?? '' ) : '',
		'ship_address_2' => $has_shipping ? sanitize_text_field( $_POST['ship_address_2'] ?? '' ) : '',
		'ship_address_3' => $has_shipping ? sanitize_text_field( $_POST['ship_address_3'] ?? '' ) : '',
		'ship_city'      => $has_shipping ? sanitize_text_field( $_POST['ship_city']      ?? '' ) : '',
		'ship_state'     => $has_shipping ? sanitize_text_field( $_POST['ship_state']     ?? '' ) : '',
		'ship_zip'       => $has_shipping ? sanitize_text_field( $_POST['ship_zip']       ?? '' ) : '',
		'ship_workbooks' => ( $has_shipping && $ship_workbooks_raw !== '' ) ? (int) $ship_workbooks_raw : null,
		'ship_notes'     => $has_shipping ? sanitize_textarea_field( $_POST['ship_notes'] ?? '' ) : '',
		// Host & Venue
		'host_name'            => $host_name,
		'displayed_as'         => $displayed_as,
		'location_name'        => $location_name,
		'street_address_1'     => $addr1,
		'street_address_2'     => $addr2,
		'street_address_3'     => $addr3,
		'city'                 => $ev_city,
		'state'                => $ev_state,
		'zip_code'             => $zip_code,
		// Additional
		'max_attendees'        => $max_attendees_raw !== '' ? (int) $max_attendees_raw : null,
		'special_instructions' => $special_instructions,
		'parking_file_url'     => $parking_file_url,
		'custom_email_intro'   => $custom_email_intro,
		// JSON blobs
		'host_contacts'        => wp_json_encode( $host_contacts ),
		'hotels'               => wp_json_encode( $hotels_arr ),
	);

	$wpdb->update( $table, $update_data, array( 'eve_id' => $eve_id ) );
	$notice = '<div class="notice notice-success is-dismissible"><p>Event updated successfully.</p></div>';
}

// ── Load or build the event data array ────────────────────────────────────────
if ( $mode === 'edit' ) {
	$ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE eve_id = %d", $eve_id ), ARRAY_A );
	if ( ! $ev ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>Event not found.</p></div></div>';
		return;
	}
} elseif ( $mode === 'request' ) {
	// Pull the event request row and map its fields onto the $ev shape
	// expected by the rest of the form. Best-effort name→ID lookup for
	// the three ID-based fields (type, marketer, instructor).
	if ( ! class_exists( 'Hostlinks_Event_Request_Storage' ) ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>Event Request module not available.</p></div></div>';
		return;
	}
	$request_row = Hostlinks_Event_Request_Storage::get_by_id( $request_id );
	if ( ! $request_row ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>Event request #' . esc_html( (string) $request_id ) . ' not found.</p></div></div>';
		return;
	}

	// Helper: case-insensitive lookup of a name in a lookup array → id (0 if no match).
	$_hl_lookup_id = function( $name, array $lookup ) {
		$needle = strtolower( trim( (string) $name ) );
		if ( $needle === '' ) return 0;
		foreach ( $lookup as $r ) {
			if ( strtolower( trim( (string) $r['name'] ) ) === $needle ) {
				return (int) $r['id'];
			}
		}
		return 0;
	};

	$req_type_id       = $_hl_lookup_id( $request_row['category'] ?? '', $all_types );
	$req_marketer_id   = $_hl_lookup_id( $request_row['marketer'] ?? '', $all_marketers );
	$req_instructor_id = $_hl_lookup_id( $request_row['trainer']  ?? '', $all_instructors );

	$request_header = trim( (string) ( $request_row['event_title'] ?? '' ) );

	$ev = array(
		'eve_id'          => 0,
		'eve_location'    => sanitize_text_field( ( $request_row['city'] ?? '' ) . ( ! empty( $request_row['state'] ) ? ', ' . $request_row['state'] : '' ) ),
		'eve_start'       => sanitize_text_field( $request_row['start_date'] ?? '' ),
		'eve_end'         => sanitize_text_field( $request_row['end_date']   ?? $request_row['start_date'] ?? '' ),
		'eve_type'        => $req_type_id,
		'eve_zoom'        => ( strtolower( (string) ( $request_row['format'] ?? '' ) ) === 'virtual' ) ? 'yes' : '',
		'eve_zoom_time'   => trim( (string) ( $request_row['start_time'] ?? '' ) ) . ( ! empty( $request_row['end_time'] ) ? '-' . $request_row['end_time'] : '' ),
		'eve_marketer'    => $req_marketer_id,
		'eve_instructor'  => $req_instructor_id,
		'eve_paid'        => 0,
		'eve_free'        => 0,
		'eve_status'      => 1,
		'eve_public_hide' => 0,
		'max_attendees'   => $request_row['max_attendees'] ?? '',
		'eve_host_url'    => '',
		'eve_roster_url'  => '',
		'eve_trainer_url' => '',
		'eve_web_url'     => '',
		'eve_email_url'   => '',
		// Host/venue — direct pass-through
		'host_name'       => (string) ( $request_row['host_name']         ?? '' ),
		'displayed_as'    => (string) ( $request_row['displayed_as']      ?? '' ),
		'location_name'   => (string) ( $request_row['location_name']     ?? '' ),
		'street_address_1'=> (string) ( $request_row['street_address_1']  ?? '' ),
		'street_address_2'=> (string) ( $request_row['street_address_2']  ?? '' ),
		'street_address_3'=> (string) ( $request_row['street_address_3']  ?? '' ),
		'city'            => (string) ( $request_row['city']              ?? '' ),
		'state'           => (string) ( $request_row['state']             ?? '' ),
		'zip_code'        => (string) ( $request_row['zip_code']          ?? '' ),
		'special_instructions' => (string) ( $request_row['special_instructions'] ?? '' ),
		'parking_file_url'    => (string) ( $request_row['parking_file_url']    ?? '' ),
		'custom_email_intro'  => (string) ( $request_row['custom_email_intro']  ?? '' ),
		// host_contacts / hotels: already JSON strings in the request table.
		'host_contacts'       => (string) ( $request_row['host_contacts'] ?? '' ),
		'hotels'              => (string) ( $request_row['hotels']        ?? '' ),
		// Shipping — direct pass-through
		'ship_name'      => (string) ( $request_row['ship_name']      ?? '' ),
		'ship_email'     => (string) ( $request_row['ship_email']     ?? '' ),
		'ship_phone'     => (string) ( $request_row['ship_phone']     ?? '' ),
		'ship_address_1' => (string) ( $request_row['ship_address_1'] ?? '' ),
		'ship_address_2' => (string) ( $request_row['ship_address_2'] ?? '' ),
		'ship_address_3' => (string) ( $request_row['ship_address_3'] ?? '' ),
		'ship_city'      => (string) ( $request_row['ship_city']      ?? '' ),
		'ship_state'     => (string) ( $request_row['ship_state']     ?? '' ),
		'ship_zip'       => (string) ( $request_row['ship_zip']       ?? '' ),
		'ship_workbooks' => $request_row['ship_workbooks'] ?? '',
		'ship_notes'     => (string) ( $request_row['ship_notes']     ?? '' ),
		'cvent_event_id'     => '',
		'cvent_event_title'  => '',
		'cvent_match_status' => '',
		'cvent_match_score'  => '',
		'cvent_last_synced'  => '',
	);
} else {
	// Add or CVENT mode: pre-populate from GET params or use blank defaults.
	$cvent_uuid_pre      = $mode === 'cvent' ? sanitize_text_field( $_GET['add_cvent']         ) : '';
	$cvent_title_pre     = sanitize_text_field( $_GET['cvent_title']     ?? '' );
	$cvent_start_utc_pre = sanitize_text_field( $_GET['cvent_start_utc'] ?? '' );

	$ev = array(
		'eve_id'          => 0,
		'eve_location'    => sanitize_text_field( $_GET['cvent_loc']   ?? '' ),
		'eve_start'       => sanitize_text_field( $_GET['cvent_start'] ?? '' ),
		'eve_end'         => sanitize_text_field( $_GET['cvent_end']   ?? '' ),
		'eve_type'        => (int) ( $_GET['cvent_type'] ?? 0 ),
		'eve_zoom'        => ( ! empty( $_GET['cvent_zoom'] ) && '1' === $_GET['cvent_zoom'] ) ? 'yes' : '',
		'eve_zoom_time'   => '',
		'eve_marketer'    => (int) ( $_GET['cvent_mkt']  ?? 0 ),
		'eve_instructor'  => (int) ( $_GET['cvent_inst'] ?? 0 ),
		'eve_paid'        => 0,
		'eve_free'        => 0,
		'eve_status'      => 1,
		'eve_public_hide' => 0,
		'max_attendees'   => '',
		'eve_host_url'    => '',
		'eve_roster_url'  => '',
		'eve_trainer_url' => esc_url_raw( sanitize_text_field( $_GET['cvent_reg_url'] ?? '' ) ),
		'eve_web_url'     => '',
		'eve_email_url'   => '',
		'host_name'       => '',
		'displayed_as'    => '',
		'location_name'   => '',
		'street_address_1'=> '',
		'street_address_2'=> '',
		'street_address_3'=> '',
		'city'            => '',
		'state'           => '',
		'zip_code'        => '',
		'special_instructions' => '',
		'parking_file_url'    => '',
		'custom_email_intro'  => '',
		'host_contacts'       => '',
		'hotels'              => '',
		'ship_name'      => '', 'ship_email'     => '', 'ship_phone'  => '',
		'ship_address_1' => '', 'ship_address_2' => '', 'ship_address_3' => '',
		'ship_city'      => '', 'ship_state'     => '', 'ship_zip'    => '',
		'ship_workbooks' => '', 'ship_notes'     => '',
		'cvent_event_id'     => $cvent_uuid_pre,
		'cvent_event_title'  => $cvent_title_pre,
		'cvent_match_status' => $mode === 'cvent' ? 'manual' : '',
		'cvent_match_score'  => '',
		'cvent_last_synced'  => '',
	);
}

// Resolved display names for the view header
$type_name     = '';
foreach ( $all_types as $t ) {
	if ( (int) $t['id'] === (int) $ev['eve_type'] ) { $type_name = $t['name']; break; }
}
$marketer_name = '';
foreach ( $all_marketers as $m ) {
	if ( (int) $m['id'] === (int) $ev['eve_marketer'] ) { $marketer_name = $m['name']; break; }
}

// Parse JSON blobs
$ev_contacts = array();
if ( ! empty( $ev['host_contacts'] ) ) {
	$decoded = json_decode( $ev['host_contacts'], true );
	if ( is_array( $decoded ) ) $ev_contacts = $decoded;
}
$ev_hotels = array();
if ( ! empty( $ev['hotels'] ) ) {
	$decoded = json_decode( $ev['hotels'], true );
	if ( is_array( $decoded ) ) $ev_hotels = $decoded;
}

$maps_api_key      = get_option( 'hostlinks_google_maps_api_key', '' );
$has_shipping_data = ! empty( $ev['ship_name'] ) || ! empty( $ev['ship_email'] ) || ! empty( $ev['ship_address_1'] ) || ! empty( $ev['ship_notes'] );

if ( $maps_api_key ) {
	wp_enqueue_script(
		'google-maps-places',
		'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $maps_api_key ) . '&libraries=places',
		array(),
		null,
		true
	);
}

$us_states = [ 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC' ];
?>
<style>
.hl-edit-section-title {
	font-size: 15px;
	margin: 24px 0 8px;
	padding-bottom: 4px;
	border-bottom: 2px solid #0da2e7;
}
.hl-edit-contact-row,
.hl-edit-hotel-row {
	background: #f9f9f9;
	border: 1px solid #e5e5e5;
	border-radius: 4px;
	padding: 12px 14px;
	margin-bottom: 10px;
	position: relative;
}
.hl-edit-contact-grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 10px 14px;
}
.hl-edit-hotel-grid {
	display: grid;
	grid-template-columns: 2fr 1fr 2fr 2fr;
	gap: 10px 14px;
}
.hl-edit-row-field label {
	display: block;
	font-size: 11px;
	font-weight: 600;
	color: #555;
	margin-bottom: 3px;
}
.hl-edit-row-field input[type="text"],
.hl-edit-row-field input[type="email"],
.hl-edit-row-field input[type="tel"],
.hl-edit-row-field input[type="url"] {
	width: 100%;
	box-sizing: border-box;
}
.hl-edit-contact-checks {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 20px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px dashed #ddd;
	font-size: 12px;
}
.hl-edit-contact-checks label {
	display: flex;
	align-items: center;
	gap: 5px;
	cursor: pointer;
}
.hl-edit-remove-row {
	position: absolute;
	top: 8px;
	right: 8px;
	background: #c0392b;
	color: #fff;
	border: none;
	border-radius: 3px;
	padding: 2px 8px;
	font-size: 11px;
	cursor: pointer;
	line-height: 1.6;
}
.hl-edit-remove-row:hover { background: #922b21; }
.hl-edit-add-row-btn {
	margin-top: 4px;
	font-size: 12px;
}
</style>

<div class="wrap">
<h1>
	<a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none;color:#50575e;font-size:14px;margin-right:8px;">&#8592; Event List</a>
	<?php
	if ( $mode === 'edit' ) {
		echo 'Edit Event #' . $eve_id;
		if ( $marketer_name ) {
			echo '<span style="font-size:14px;color:#50575e;font-weight:400;margin-left:8px;">— ' . esc_html( $marketer_name ) . '</span>';
		}
	} elseif ( $mode === 'cvent' ) {
		echo 'Add CVENT Event';
		if ( $cvent_title_pre ) {
			echo '<span style="font-size:14px;color:#50575e;font-weight:400;margin-left:8px;">— ' . esc_html( $cvent_title_pre ) . '</span>';
		}
	} elseif ( $mode === 'request' ) {
		echo 'Convert Event Request #' . (int) $request_id;
		if ( $request_header ) {
			echo '<span style="font-size:14px;color:#50575e;font-weight:400;margin-left:8px;">— ' . esc_html( $request_header ) . '</span>';
		}
	} else {
		echo 'Add New Event';
	}
	?>
</h1>

<?php echo $notice; ?>

<form method="post" id="hl-edit-event-form">
<?php if ( $mode === 'edit' ) : ?>
<?php wp_nonce_field( 'hostlinks_edit_full_event_' . $eve_id ); ?>
<?php else : ?>
<?php wp_nonce_field( 'hostlinks_add_event_unified' ); ?>
<?php if ( $mode === 'cvent' ) : ?>
<input type="hidden" name="cvent_uuid"      value="<?php echo esc_attr( $cvent_uuid_pre ); ?>">
<input type="hidden" name="cvent_title"     value="<?php echo esc_attr( $cvent_title_pre ); ?>">
<input type="hidden" name="cvent_start_utc" value="<?php echo esc_attr( $cvent_start_utc_pre ); ?>">
<?php endif; ?>
<?php if ( $mode === 'request' ) : ?>
<input type="hidden" name="hl_request_id" value="<?php echo esc_attr( (string) $request_id ); ?>">
<?php endif; ?>
<?php endif; ?>

<div style="max-width:1100px;">

<!-- ═══════════════════════════════════════════════════════════════════════════
     ROW 1: Core + CVENT (2 columns)
════════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

	<!-- ── LEFT: Event Info + URLs ──────────────────────────────────────── -->
	<div>

		<h2 class="hl-edit-section-title" style="margin-top:0;">Event Info</h2>
		<table class="form-table" style="margin-bottom:0;">
			<tr>
				<th style="width:140px;padding:8px 12px;">Location</th>
				<td style="padding:8px 12px;">
				<input type="text" name="eve_location" value="<?php echo esc_attr( $ev['eve_location'] ); ?>"
					class="regular-text" required autocomplete="off" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Type</th>
				<td style="padding:8px 12px;">
					<select name="eve_type" required>
						<option value="">— select —</option>
						<?php foreach ( $all_types as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) $ev['eve_type'], (int) $t['id'] ); ?>>
							<?php echo esc_html( $t['name'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Start Date</th>
				<td style="padding:8px 12px;">
					<input type="date" name="eve_start_date" value="<?php echo esc_attr( $ev['eve_start'] ?? '' ); ?>"
						class="regular-text hl-date-input" required />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">End Date</th>
				<td style="padding:8px 12px;">
					<input type="date" name="eve_end_date" value="<?php echo esc_attr( $ev['eve_end'] ?? '' ); ?>"
						class="regular-text hl-date-input" required />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Marketer</th>
				<td style="padding:8px 12px;">
					<select name="eve_marketer" required>
						<option value="">— select —</option>
						<?php foreach ( $all_marketers as $m ) : ?>
						<option value="<?php echo (int) $m['id']; ?>" <?php selected( (int) $ev['eve_marketer'], (int) $m['id'] ); ?>>
							<?php echo esc_html( $m['name'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Instructor</th>
				<td style="padding:8px 12px;">
					<select name="eve_instructor">
						<option value="0">— select —</option>
						<?php foreach ( $all_instructors as $i ) : ?>
						<option value="<?php echo (int) $i['id']; ?>" <?php selected( (int) $ev['eve_instructor'], (int) $i['id'] ); ?>>
							<?php echo esc_html( $i['name'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">ZOOM?</th>
				<td style="padding:8px 12px;">
					<label>
						<input type="checkbox" id="hl-edit-zoom" name="eve_zoom" value="yes"
							<?php checked( $ev['eve_zoom'] ?? '', 'yes' ); ?>>
						Yes — this is a virtual event
					</label>
				</td>
			</tr>
			<tr id="hl-edit-zoom-time-row" style="<?php echo ( ( $ev['eve_zoom'] ?? '' ) !== 'yes' ) ? 'display:none;' : ''; ?>">
				<th style="padding:8px 12px;">Zoom Time</th>
				<td style="padding:8px 12px;">
					<input type="text" name="eve_zoom_time" value="<?php echo esc_attr( $ev['eve_zoom_time'] ?? '' ); ?>"
						class="regular-text" placeholder="e.g. 9:30–4:30 EST" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Event Capacity</th>
				<td style="padding:8px 12px;">
					<input type="number" name="edit_max_attendees" min="1"
						value="<?php echo esc_attr( $ev['max_attendees'] ?? '' ); ?>"
						style="width:90px;" placeholder="Unlimited" />
					<small style="color:#888;margin-left:6px;">Leave blank for unlimited</small>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Paid / Free</th>
				<td style="padding:8px 12px;">
					<input type="number" name="eve_paid" value="<?php echo (int) $ev['eve_paid']; ?>"
						style="width:70px;" min="0" />
					<span style="margin:0 8px;color:#666;">paid</span>
					<input type="number" name="eve_free" value="<?php echo (int) $ev['eve_free']; ?>"
						style="width:70px;" min="0" />
					<span style="margin-left:8px;color:#666;">free</span>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Status</th>
				<td style="padding:8px 12px;">
					<select name="eve_status">
						<option value="1" <?php selected( (int) $ev['eve_status'], 1 ); ?>>Active</option>
						<option value="0" <?php selected( (int) $ev['eve_status'], 0 ); ?>>Inactive</option>
						<option value="2" <?php selected( (int) $ev['eve_status'], 2 ); ?>>Deleted</option>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Hide from Public</th>
				<td style="padding:8px 12px;">
					<label>
						<input type="checkbox" name="eve_public_hide" value="1"
							<?php checked( 1, (int) ( $ev['eve_public_hide'] ?? 0 ) ); ?>>
						Hide this event from the public-facing event list
					</label>
				</td>
			</tr>
		</table>

		<h2 class="hl-edit-section-title">URLs &amp; Links</h2>
		<table class="form-table" style="margin-bottom:0;">
			<tr>
				<th style="width:140px;padding:8px 12px;">Host URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_host_url" value="<?php echo esc_attr( $ev['eve_host_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Roster URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_roster_url" value="<?php echo esc_attr( $ev['eve_roster_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
					<br><small style="color:#888;">Leave blank to auto-populate with the default roster URL plus this event's ID.</small>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">REG URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_trainer_url" value="<?php echo esc_attr( $ev['eve_trainer_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
					<br><small style="color:#888;">Leave blank to auto-populate from CVENT when the next sync runs.</small>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Web / Sign-in URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_web_url" value="<?php echo esc_attr( $ev['eve_web_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
					<br><small style="color:#888;">Leave blank to auto-populate from the Marketing Ops event page when it's generated.</small>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Email URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_email_url" value="<?php echo esc_attr( $ev['eve_email_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
		</table>

	</div>

	<!-- ── RIGHT: CVENT + Shipping ──────────────────────────────────────── -->
	<div>

		<h2 class="hl-edit-section-title" style="margin-top:0;">CVENT Link</h2>
		<?php if ( $mode === 'cvent' ) : ?>
		<table style="width:100%;border-collapse:collapse;">
			<tr>
				<th style="width:140px;padding:8px 12px;background:#f0f6fc;border:1px solid #c3d4e8;font-weight:600;vertical-align:top;">CVENT Title</th>
				<td style="padding:8px 12px;border:1px solid #c3d4e8;font-size:12px;"><?php echo esc_html( $cvent_title_pre ); ?></td>
			</tr>
			<tr>
				<th style="padding:8px 12px;background:#f0f6fc;border:1px solid #c3d4e8;font-weight:600;vertical-align:top;">CVENT UUID</th>
				<td style="padding:8px 12px;border:1px solid #c3d4e8;font-family:monospace;font-size:11px;"><?php echo esc_html( $cvent_uuid_pre ); ?></td>
			</tr>
			<?php if ( $ev['eve_start'] ) : ?>
			<tr>
				<th style="padding:8px 12px;background:#f0f6fc;border:1px solid #c3d4e8;font-weight:600;vertical-align:top;">CVENT Start</th>
				<td style="padding:8px 12px;border:1px solid #c3d4e8;font-size:12px;"><?php echo esc_html( $ev['eve_start'] ); ?></td>
			</tr>
			<?php endif; ?>
		</table>
		<p style="margin:6px 0 0;font-size:11px;color:#2271b1;">Fields below are pre-filled from CVENT data — review before saving.</p>
		<?php elseif ( $mode === 'edit' ) : ?>
		<table style="width:100%;border-collapse:collapse;">
			<?php
			$cvent_rows = array(
				'CVENT Event ID'    => $ev['cvent_event_id']        ?? '',
				'CVENT Title'       => $ev['cvent_event_title']     ?? '',
				'Match Status'      => $ev['cvent_match_status']    ?? '',
				'Match Score'       => isset( $ev['cvent_match_score'] ) ? (string) $ev['cvent_match_score'] : '',
				'Last Synced'       => $ev['cvent_last_synced']     ?? '',
			);
			foreach ( $cvent_rows as $lbl => $val ) :
				if ( $val === '' ) continue;
			?>
			<tr>
				<th style="width:140px;padding:8px 12px;background:#f9f9f9;border:1px solid #e5e5e5;font-weight:600;vertical-align:top;"><?php echo esc_html( $lbl ); ?></th>
				<td style="padding:8px 12px;border:1px solid #e5e5e5;font-family:monospace;font-size:12px;"><?php echo esc_html( $val ); ?></td>
			</tr>
			<?php endforeach; ?>
			<?php if ( empty( $ev['cvent_event_id'] ) ) : ?>
			<tr><td colspan="2" style="padding:8px 12px;color:#888;font-style:italic;">No CVENT link — sync or manually add a CVENT ID on the Event List.</td></tr>
			<?php endif; ?>
		</table>
		<?php else : ?>
		<p style="color:#888;font-style:italic;margin:0;">No CVENT link — this event will be added manually.</p>
		<?php endif; ?>

		<!-- Shipping Details -->
		<h2 class="hl-edit-section-title">Shipping Details</h2>

		<p style="margin-bottom:10px;">
			<label style="font-weight:500;">
				<input type="checkbox" id="hl-edit-shipping-toggle" name="hl_add_shipping" value="1"
					<?php checked( $has_shipping_data ); ?>>
				Add / Edit Shipping Details
			</label>
		</p>

		<div id="hl-edit-shipping-fields" <?php if ( ! $has_shipping_data ) echo 'style="display:none;"'; ?>>
			<table class="form-table" style="margin-bottom:0;">
				<tr>
					<th style="width:140px;padding:8px 12px;">Name</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_name" value="<?php echo esc_attr( $ev['ship_name'] ?? '' ); ?>"
							class="regular-text" placeholder="Recipient name" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Email</th>
					<td style="padding:8px 12px;">
						<input type="email" name="ship_email" value="<?php echo esc_attr( $ev['ship_email'] ?? '' ); ?>"
							class="regular-text" placeholder="recipient@example.com" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Phone</th>
					<td style="padding:8px 12px;">
						<input type="tel" name="ship_phone" id="hl-ship-phone" value="<?php echo esc_attr( $ev['ship_phone'] ?? '' ); ?>"
							class="regular-text" placeholder="123-456-7890" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Address 1</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_address_1" id="hl-ship-addr1"
							value="<?php echo esc_attr( $ev['ship_address_1'] ?? '' ); ?>"
							class="large-text" placeholder="Street address" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Address 2</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_address_2" value="<?php echo esc_attr( $ev['ship_address_2'] ?? '' ); ?>"
							class="large-text" placeholder="Suite, floor, etc." />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Address 3</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_address_3" value="<?php echo esc_attr( $ev['ship_address_3'] ?? '' ); ?>"
							class="large-text" placeholder="Additional address info" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">City</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_city" id="hl-ship-city" value="<?php echo esc_attr( $ev['ship_city'] ?? '' ); ?>"
							class="regular-text" placeholder="City" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">State</th>
					<td style="padding:8px 12px;">
						<select name="ship_state" id="hl-ship-state">
							<option value="">— select —</option>
							<?php foreach ( $us_states as $abbr ) : ?>
							<option value="<?php echo esc_attr( $abbr ); ?>" <?php selected( $ev['ship_state'] ?? '', $abbr ); ?>>
								<?php echo esc_html( $abbr ); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">ZIP Code</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_zip" id="hl-ship-zip" value="<?php echo esc_attr( $ev['ship_zip'] ?? '' ); ?>"
							class="regular-text" placeholder="12345" maxlength="10" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;"># Workbooks</th>
					<td style="padding:8px 12px;">
						<input type="number" name="ship_workbooks" min="0" style="width:80px;"
							value="<?php echo esc_attr( $ev['ship_workbooks'] ?? '' ); ?>" placeholder="0" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Notes</th>
					<td style="padding:8px 12px;">
						<textarea name="ship_notes" rows="3" class="large-text"
							placeholder="Any other items to ship or special instructions."><?php
							echo esc_textarea( $ev['ship_notes'] ?? '' );
						?></textarea>
					</td>
				</tr>
			</table>
		</div>

	</div>
</div><!-- end 2-col grid -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     FULL-WIDTH SECTIONS (below the 2-col grid)
════════════════════════════════════════════════════════════════════════════ -->

<!-- ── Host & Venue ──────────────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Host &amp; Venue</h2>
<table class="form-table" style="margin-bottom:0;">
	<tr>
		<th style="width:180px;padding:8px 12px;">Host Name</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_host_name"
				value="<?php echo esc_attr( $ev['host_name'] ?? '' ); ?>"
				class="regular-text" placeholder="e.g. City of Phoenix" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Displayed As</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_displayed_as"
				value="<?php echo esc_attr( $ev['displayed_as'] ?? '' ); ?>"
				class="large-text" placeholder="Hosted by …" />
			<small style="color:#888;">Auto-filled from Host Name on the request form — edit if needed.</small>
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Location Name / Building</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_location_name"
				value="<?php echo esc_attr( $ev['location_name'] ?? '' ); ?>"
				class="large-text" placeholder="e.g. City Hall, Conference Center" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Address Line 1</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_street_address_1" id="hl-edit-venue-addr1"
				value="<?php echo esc_attr( $ev['street_address_1'] ?? '' ); ?>"
				class="large-text" placeholder="123 Main St" autocomplete="off" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Address Line 2</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_street_address_2"
				value="<?php echo esc_attr( $ev['street_address_2'] ?? '' ); ?>"
				class="large-text" placeholder="Suite, Floor, etc." />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Address Line 3</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_street_address_3"
				value="<?php echo esc_attr( $ev['street_address_3'] ?? '' ); ?>"
				class="large-text" placeholder="" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">City / State / ZIP</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_city" id="hl-edit-venue-city"
				value="<?php echo esc_attr( $ev['city'] ?? '' ); ?>"
				style="width:180px;" placeholder="City" />
			&nbsp;
			<select name="edit_state" id="hl-edit-venue-state">
				<option value="">— State —</option>
				<?php foreach ( $us_states as $abbr ) : ?>
				<option value="<?php echo esc_attr( $abbr ); ?>"
					<?php selected( $ev['state'] ?? '', $abbr ); ?>>
					<?php echo esc_html( $abbr ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			&nbsp;
			<input type="text" name="edit_zip_code" id="hl-edit-venue-zip"
				value="<?php echo esc_attr( $ev['zip_code'] ?? '' ); ?>"
				style="width:90px;" placeholder="ZIP" maxlength="10" />
		</td>
	</tr>
</table>

<!-- ── Additional Details ─────────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Additional Details</h2>
<table class="form-table" style="margin-bottom:0;">
	<tr>
		<th style="width:180px;padding:8px 12px;">Special Instructions / Parking</th>
		<td style="padding:8px 12px;">
			<textarea name="edit_special_instructions" rows="4" class="large-text"
				placeholder="Parking instructions, building access notes, etc."><?php
				echo esc_textarea( $ev['special_instructions'] ?? '' );
			?></textarea>
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Parking / Instructions File URL</th>
		<td style="padding:8px 12px;">
			<input type="url" name="edit_parking_file_url"
				value="<?php echo esc_attr( $ev['parking_file_url'] ?? '' ); ?>"
				class="large-text" placeholder="https://… (PDF or image URL)" />
			<small style="color:#888;display:block;margin-top:4px;">Link to a previously uploaded PDF or file.</small>
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Custom Email Intro</th>
		<td style="padding:8px 12px;">
			<textarea name="edit_custom_email_intro" rows="4" class="large-text"
				placeholder="Opening paragraph for registration / marketing emails."><?php
				echo esc_textarea( $ev['custom_email_intro'] ?? '' );
			?></textarea>
		</td>
	</tr>
</table>

<!-- ── Host Contacts ─────────────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Host Contacts</h2>

<div id="hl-edit-contact-rows">
<?php if ( empty( $ev_contacts ) ) : ?>
	<p id="hl-no-contacts-msg" style="color:#888;font-style:italic;margin:0 0 8px;">No host contacts saved. Click below to add one.</p>
<?php endif; ?>
<?php foreach ( $ev_contacts as $ci => $c ) :
	$ci_cc  = ! empty( $c['cc_on_alerts'] );
	$ci_inc = ! empty( $c['include_in_email'] );
	$ci_dnl = ! empty( $c['dnl_phone'] );
	$ci_dnl2= ! empty( $c['dnl_phone2'] );
?>
<div class="hl-edit-contact-row">
	<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,'hl-no-contacts-msg')">Remove</button>
	<div class="hl-edit-contact-grid">
		<div class="hl-edit-row-field">
			<label>Name</label>
			<input type="text" name="edit_contact_name[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="Name" />
		</div>
		<div class="hl-edit-row-field">
			<label>Agency</label>
			<input type="text" name="edit_contact_agency[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['agency'] ?? '' ); ?>" placeholder="Agency" />
		</div>
		<div class="hl-edit-row-field">
			<label>Title</label>
			<input type="text" name="edit_contact_title[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['title'] ?? '' ); ?>" placeholder="Title" />
		</div>
		<div class="hl-edit-row-field">
			<label>Email</label>
			<input type="email" name="edit_contact_email[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['email'] ?? '' ); ?>" placeholder="email@example.com" />
		</div>
		<div class="hl-edit-row-field">
			<label>Phone</label>
			<input type="tel" name="edit_contact_phone[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['phone'] ?? '' ); ?>" placeholder="123-456-7890"
				class="hl-phone-input" />
		</div>
		<div class="hl-edit-row-field">
			<label>Phone 2</label>
			<input type="tel" name="edit_contact_phone2[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['phone2'] ?? '' ); ?>" placeholder="123-456-7890"
				class="hl-phone-input" />
		</div>
	</div>
	<div class="hl-edit-contact-checks">
		<label>
			<input type="checkbox" name="edit_contact_cc[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_cc ); ?>>
			CC on Registration Alerts
		</label>
		<label>
			<input type="checkbox" name="edit_contact_include[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_inc ); ?>>
			Include in Email Template
		</label>
		<label>
			<input type="checkbox" name="edit_contact_dnl_phone[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_dnl ); ?>>
			Do Not List (Phone)
		</label>
		<label>
			<input type="checkbox" name="edit_contact_dnl_phone2[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_dnl2 ); ?>>
			Do Not List (Phone 2)
		</label>
	</div>
</div>
<?php endforeach; ?>
</div>

<button type="button" class="button hl-edit-add-row-btn" id="hl-add-contact-btn">+ Add Host Contact</button>

<!-- ── Hotel Recommendations ─────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Hotel Recommendations</h2>

<div id="hl-edit-hotel-rows">
<?php if ( empty( $ev_hotels ) ) : ?>
	<p id="hl-no-hotels-msg" style="color:#888;font-style:italic;margin:0 0 8px;">No hotels saved. Click below to add one.</p>
<?php endif; ?>
<?php foreach ( $ev_hotels as $hi => $h ) : ?>
<div class="hl-edit-hotel-row">
	<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,'hl-no-hotels-msg')">Remove</button>
	<div class="hl-edit-hotel-grid">
		<div class="hl-edit-row-field">
			<label>Hotel Name</label>
			<input type="text" name="edit_hotel_name[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['name'] ); ?>" placeholder="Hotel Name" />
		</div>
		<div class="hl-edit-row-field">
			<label>Phone</label>
			<input type="tel" name="edit_hotel_phone[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['phone'] ?? '' ); ?>" placeholder="123-456-7890"
				class="hl-phone-input" />
		</div>
		<div class="hl-edit-row-field">
			<label>Address</label>
			<input type="text" name="edit_hotel_address[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['address'] ?? '' ); ?>" placeholder="Address" />
		</div>
		<div class="hl-edit-row-field">
			<label>URL</label>
			<input type="url" name="edit_hotel_url[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['url'] ?? '' ); ?>" placeholder="https://" />
		</div>
	</div>
</div>
<?php endforeach; ?>
</div>

<button type="button" class="button hl-edit-add-row-btn" id="hl-add-hotel-btn">+ Add Hotel</button>

</div><!-- .max-width wrapper -->

<p class="submit" style="margin-top:28px;">
	<?php if ( $mode === 'edit' ) : ?>
	<button type="submit" name="hl_edit_full_event" class="button button-primary" style="font-size:14px;padding:6px 20px;">Save Changes</button>
	&nbsp;
	<a href="<?php echo esc_url( $list_url ); ?>" class="button button-secondary">Cancel</a>
	<?php else :
		if ( $mode === 'cvent' ) {
			$submit_label = 'Save to Hostlinks';
			$cancel_href  = admin_url( 'admin.php?page=cvent-new-events' );
		} elseif ( $mode === 'request' ) {
			$submit_label = 'Save to Hostlinks';
			$cancel_href  = admin_url( 'admin.php?page=hostlinks-event-requests' );
		} else {
			$submit_label = 'Add New Event';
			$cancel_href  = $list_url;
		}
	?>
	<button type="submit" name="hl_add_event_submit" class="button button-primary" style="font-size:14px;padding:6px 20px;">
		<?php echo esc_html( $submit_label ); ?>
	</button>
	&nbsp;
	<a href="<?php echo esc_url( $cancel_href ); ?>" class="button button-secondary">Cancel</a>
	<?php endif; ?>
</p>
</form>
</div><!-- .wrap -->

<script>
(function() {

	// ── Zoom time row toggle ─────────────────────────────────────────────
	var zoomChk     = document.getElementById('hl-edit-zoom');
	var zoomTimeRow = document.getElementById('hl-edit-zoom-time-row');
	if (zoomChk && zoomTimeRow) {
		zoomChk.addEventListener('change', function() {
			zoomTimeRow.style.display = this.checked ? '' : 'none';
		});
	}

	// ── Shipping section toggle ──────────────────────────────────────────
	var shipToggle = document.getElementById('hl-edit-shipping-toggle');
	var shipFields = document.getElementById('hl-edit-shipping-fields');
	if (shipToggle && shipFields) {
		shipToggle.addEventListener('change', function() {
			shipFields.style.display = this.checked ? '' : 'none';
		});
	}

	// ── Phone formatter (shipping + contact fields) ──────────────────────
	function formatPhone(input) {
		var digits = input.value.replace(/\D/g, '');
		if (digits.length === 11 && digits[0] === '1') digits = digits.slice(1);
		if (digits.length === 10) {
			input.value = digits.slice(0,3) + '-' + digits.slice(3,6) + '-' + digits.slice(6);
		}
	}

	var shipPhone = document.getElementById('hl-ship-phone');
	if (shipPhone) {
		shipPhone.addEventListener('blur', function() { formatPhone(this); });
	}

	document.addEventListener('blur', function(e) {
		if (e.target && e.target.classList.contains('hl-phone-input')) {
			formatPhone(e.target);
		}
	}, true);

	// ── Remove row helper ────────────────────────────────────────────────
	window.hlRemoveRow = function(btn, emptyMsgId) {
		var row = btn.closest('.hl-edit-contact-row, .hl-edit-hotel-row');
		if (!row) return;
		row.remove();
		var container = document.getElementById(
			emptyMsgId === 'hl-no-contacts-msg' ? 'hl-edit-contact-rows' : 'hl-edit-hotel-rows'
		);
		if (container) {
			var hasRows = container.querySelectorAll('.hl-edit-contact-row, .hl-edit-hotel-row').length > 0;
			var msg = document.getElementById(emptyMsgId);
			if (!msg) {
				msg = document.createElement('p');
				msg.id = emptyMsgId;
				msg.style.cssText = 'color:#888;font-style:italic;margin:0 0 8px;';
				msg.textContent = emptyMsgId === 'hl-no-contacts-msg'
					? 'No host contacts saved. Click below to add one.'
					: 'No hotels saved. Click below to add one.';
				if (!hasRows) container.appendChild(msg);
			} else {
				msg.style.display = hasRows ? 'none' : '';
			}
		}
	};

	// ── Repeatable: Add Contact ──────────────────────────────────────────
	var contactCounter = <?php echo count( $ev_contacts ); ?>;

	document.getElementById('hl-add-contact-btn').addEventListener('click', function() {
		var idx = contactCounter++;
		var msg = document.getElementById('hl-no-contacts-msg');
		if (msg) msg.style.display = 'none';

		var row = document.createElement('div');
		row.className = 'hl-edit-contact-row';
		row.innerHTML =
			'<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,\'hl-no-contacts-msg\')">Remove</button>' +
			'<div class="hl-edit-contact-grid">' +
				'<div class="hl-edit-row-field"><label>Name</label>' +
					'<input type="text" name="edit_contact_name[' + idx + ']" placeholder="Name" /></div>' +
				'<div class="hl-edit-row-field"><label>Agency</label>' +
					'<input type="text" name="edit_contact_agency[' + idx + ']" placeholder="Agency" /></div>' +
				'<div class="hl-edit-row-field"><label>Title</label>' +
					'<input type="text" name="edit_contact_title[' + idx + ']" placeholder="Title" /></div>' +
				'<div class="hl-edit-row-field"><label>Email</label>' +
					'<input type="email" name="edit_contact_email[' + idx + ']" placeholder="email@example.com" /></div>' +
				'<div class="hl-edit-row-field"><label>Phone</label>' +
					'<input type="tel" name="edit_contact_phone[' + idx + ']" placeholder="123-456-7890" class="hl-phone-input" /></div>' +
				'<div class="hl-edit-row-field"><label>Phone 2</label>' +
					'<input type="tel" name="edit_contact_phone2[' + idx + ']" placeholder="123-456-7890" class="hl-phone-input" /></div>' +
			'</div>' +
			'<div class="hl-edit-contact-checks">' +
				'<label><input type="checkbox" name="edit_contact_cc[' + idx + ']" value="1"> CC on Registration Alerts</label>' +
				'<label><input type="checkbox" name="edit_contact_include[' + idx + ']" value="1" checked> Include in Email Template</label>' +
				'<label><input type="checkbox" name="edit_contact_dnl_phone[' + idx + ']" value="1"> Do Not List (Phone)</label>' +
				'<label><input type="checkbox" name="edit_contact_dnl_phone2[' + idx + ']" value="1"> Do Not List (Phone 2)</label>' +
			'</div>';

		document.getElementById('hl-edit-contact-rows').appendChild(row);
	});

	// ── Repeatable: Add Hotel ────────────────────────────────────────────
	var hotelCounter = <?php echo count( $ev_hotels ); ?>;

	document.getElementById('hl-add-hotel-btn').addEventListener('click', function() {
		var idx = hotelCounter++;
		var msg = document.getElementById('hl-no-hotels-msg');
		if (msg) msg.style.display = 'none';

		var row = document.createElement('div');
		row.className = 'hl-edit-hotel-row';
		row.innerHTML =
			'<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,\'hl-no-hotels-msg\')">Remove</button>' +
			'<div class="hl-edit-hotel-grid">' +
				'<div class="hl-edit-row-field"><label>Hotel Name</label>' +
					'<input type="text" name="edit_hotel_name[' + idx + ']" placeholder="Hotel Name" /></div>' +
				'<div class="hl-edit-row-field"><label>Phone</label>' +
					'<input type="tel" name="edit_hotel_phone[' + idx + ']" placeholder="123-456-7890" class="hl-phone-input" /></div>' +
				'<div class="hl-edit-row-field"><label>Address</label>' +
					'<input type="text" name="edit_hotel_address[' + idx + ']" placeholder="Address" /></div>' +
				'<div class="hl-edit-row-field"><label>URL</label>' +
					'<input type="url" name="edit_hotel_url[' + idx + ']" placeholder="https://" /></div>' +
			'</div>';

		document.getElementById('hl-edit-hotel-rows').appendChild(row);
	});

<?php if ( $maps_api_key ) : ?>
	// ── Google Places autocomplete for shipping address ──────────────────
	function initShipAutocomplete() {
		if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
		var addr1 = document.getElementById('hl-ship-addr1');
		if (!addr1) return;
		var ac = new google.maps.places.Autocomplete(addr1, {
			types: ['address'],
			componentRestrictions: { country: 'us' },
			fields: ['address_components']
		});
		ac.addListener('place_changed', function() {
			var place = ac.getPlace();
			if (!place || !place.address_components) return;
			var streetNum = '', route = '', city = '', state = '', zip = '';
			place.address_components.forEach(function(c) {
				var t = c.types[0];
				if      (t === 'street_number')               streetNum = c.long_name;
				else if (t === 'route')                       route     = c.short_name;
				else if (t === 'locality')                    city      = c.long_name;
				else if (t === 'administrative_area_level_1') state     = c.short_name;
				else if (t === 'postal_code')                 zip       = c.long_name;
			});
			addr1.value = (streetNum + ' ' + route).trim();
			var cityEl  = document.getElementById('hl-ship-city');
			var stateEl = document.getElementById('hl-ship-state');
			var zipEl   = document.getElementById('hl-ship-zip');
			if (cityEl)  cityEl.value  = city;
			if (stateEl) stateEl.value = state;
			if (zipEl)   zipEl.value   = zip;
		});
	}

	// ── Google Places autocomplete for venue address ─────────────────────
	function initVenueAutocomplete() {
		if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
		var addr1 = document.getElementById('hl-edit-venue-addr1');
		if (!addr1) return;
		var ac = new google.maps.places.Autocomplete(addr1, {
			types: ['address'],
			componentRestrictions: { country: 'us' },
			fields: ['address_components']
		});
		ac.addListener('place_changed', function() {
			var place = ac.getPlace();
			if (!place || !place.address_components) return;
			var streetNum = '', route = '', city = '', state = '', zip = '';
			place.address_components.forEach(function(c) {
				var t = c.types[0];
				if      (t === 'street_number')               streetNum = c.long_name;
				else if (t === 'route')                       route     = c.short_name;
				else if (t === 'locality')                    city      = c.long_name;
				else if (t === 'administrative_area_level_1') state     = c.short_name;
				else if (t === 'postal_code')                 zip       = c.long_name;
			});
			addr1.value = (streetNum + ' ' + route).trim();
			var cityEl  = document.getElementById('hl-edit-venue-city');
			var stateEl = document.getElementById('hl-edit-venue-state');
			var zipEl   = document.getElementById('hl-edit-venue-zip');
			if (cityEl)  cityEl.value  = city;
			if (stateEl) stateEl.value = state;
			if (zipEl)   zipEl.value   = zip;
		});
	}

	if (typeof google !== 'undefined' && google.maps && google.maps.places) {
		initShipAutocomplete();
		initVenueAutocomplete();
	} else {
		window.addEventListener('load', function() {
			initShipAutocomplete();
			initVenueAutocomplete();
		});
	}
<?php endif; ?>

	// ── Auto end-date from Type + Start Date ─────────────────────────────
	var hlTypeSelect  = document.querySelector('select[name="eve_type"]');
	var hlStartInput  = document.querySelector('input[name="eve_start_date"]');
	var hlEndInput    = document.querySelector('input[name="eve_end_date"]');

	function hlAutoEndDate() {
		if (!hlTypeSelect || !hlStartInput || !hlEndInput) return;
		var typeName = (hlTypeSelect.options[hlTypeSelect.selectedIndex]
			? hlTypeSelect.options[hlTypeSelect.selectedIndex].text
			: '').toLowerCase().trim();
		var start = hlStartInput.value;
		if (!start || !typeName) return;

		var d = new Date(start + 'T00:00:00');
		if (isNaN(d.getTime())) return;

		if (/sub[-\s]?awards?/i.test(typeName)) {
			// Subaward → same day (end = start)
		} else if (typeName.indexOf('management') !== -1 || typeName.indexOf('writing') !== -1) {
			// Management / Writing → +1 day
			d.setDate(d.getDate() + 1);
		} else {
			return; // Other types — don't auto-set end date
		}

		var y   = d.getFullYear();
		var mo  = String(d.getMonth() + 1).padStart(2, '0');
		var day = String(d.getDate()).padStart(2, '0');
		hlEndInput.value = y + '-' + mo + '-' + day;
	}

	if (hlTypeSelect)  hlTypeSelect.addEventListener('change', hlAutoEndDate);
	if (hlStartInput) hlStartInput.addEventListener('change', hlAutoEndDate);

	// ── Open native date picker when clicking anywhere in the field ──────
	// Default browser behavior only opens the picker when clicking the small
	// calendar icon. showPicker() (Chromium/Safari/Edge) opens it on any click.
	// Bound to 'click' only (not 'focus') so keyboard users can still Tab in
	// and type/arrow-edit without the picker popping up unexpectedly.
	document.querySelectorAll('input.hl-date-input').forEach(function(inp) {
		inp.addEventListener('click', function() {
			if (typeof inp.showPicker === 'function') {
				try { inp.showPicker(); } catch (e) {}
			}
		});
	});

})();
</script>
