<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hostlinks_Event_Request_Shortcode
 *
 * Registers [hostlinks_event_request_form], handles POST submission,
 * runs validation, saves to DB, and sends the notification email.
 */
class Hostlinks_Event_Request_Shortcode {

	/** @var array Validation errors keyed by field name. */
	private array $errors = array();

	/** @var array Previous POST values for sticky form re-population. */
	private array $old = array();

	/** @var int[] IDs of successfully inserted requests. */
	private array $inserted_ids = array();

	public function __construct() {
		add_shortcode( 'hostlinks_event_request_form', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'handle_submission' ) );
	}

	// ── Submission handler ────────────────────────────────────────────────────

	public function handle_submission() {
		if ( empty( $_POST['hl_event_request_submit'] ) ) {
			return;
		}
		check_admin_referer( 'hl_event_request_form' );

		// Honeypot.
		if ( ! empty( $_POST['hl_hp_field'] ) ) {
			return;
		}

		$this->old    = $_POST;
		$this->errors = Hostlinks_Event_Request::validate( $_POST );

		if ( ! empty( $this->errors ) ) {
			return;
		}

		// Handle parking PDF upload if present.
		$parking_url = null;
		if ( ! empty( $_FILES['hl_parking_file']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$upload = wp_handle_upload(
				$_FILES['hl_parking_file'],
				array(
					'test_form' => false,
					'mimes'     => array( 'pdf' => 'application/pdf' ),
				)
			);
			if ( isset( $upload['url'] ) ) {
				$parking_url = $upload['url'];
			} elseif ( isset( $upload['error'] ) ) {
				$this->errors['hl_parking_file'] = 'File upload failed: ' . $upload['error'];
				return;
			}
		}

		// Generate one UUID for all records in this submission.
		$submission_group = wp_generate_uuid4();

		$records = Hostlinks_Event_Request::normalize( $_POST, $submission_group, $parking_url );

		foreach ( $records as $data ) {
			$id = Hostlinks_Event_Request_Storage::insert( $data );
			if ( $id ) {
				$this->inserted_ids[] = $id;
			}
		}

		if ( empty( $this->inserted_ids ) ) {
			$this->errors['_db'] = 'There was a problem saving your request. Please try again.';
			return;
		}

		$this->send_notification( $this->inserted_ids, $records );
		$this->old = array();
	}

	// ── Shortcode render ──────────────────────────────────────────────────────

	public function render(): string {
		if ( ! Hostlinks_Access::can_view_shortcode( 'hostlinks_event_request_form' ) ) {
			return Hostlinks_Access::get_denial_message_html();
		}

		if ( ! empty( $this->inserted_ids ) ) {
			$msg = get_option( 'hostlinks_event_request_success_message', '' );
			$count = count( $this->inserted_ids );
			if ( $msg === '' ) {
				$msg = 'Thank you! Your event request' . ( $count > 1 ? 's (' . $count . ' events)' : '' ) .
					' (ID #' . implode( ', #', $this->inserted_ids ) . ') ' .
					'ha' . ( $count > 1 ? 've' : 's' ) . ' been submitted. We will be in touch soon.';
			}
			$form_url      = esc_url( get_permalink() );
			$hostlinks_url = esc_url( Hostlinks_Page_URLs::get_upcoming() );
			return '<div class="hl-request-success">'
				. '<p>' . wp_kses_post( $msg ) . '</p>'
				. '<div class="hl-success-actions">'
				. '<a href="' . $form_url . '" class="hl-success-btn hl-success-btn--secondary">&#8592; Return to Form</a>'
				. '<a href="' . $hostlinks_url . '" class="hl-success-btn hl-success-btn--primary">Return to Hostlinks</a>'
				. '</div>'
				. '</div>';
		}

		$errors = $this->errors;
		$old    = $this->old;

		global $wpdb;
		$marketers   = $wpdb->get_results( "SELECT event_marketer_id AS id, event_marketer_name AS name FROM {$wpdb->prefix}event_marketer WHERE event_marketer_status = 1 ORDER BY name", ARRAY_A );
		$instructors = $wpdb->get_results( "SELECT event_instructor_id AS id, event_instructor_name AS name FROM {$wpdb->prefix}event_instructor WHERE event_instructor_status = 1 ORDER BY name", ARRAY_A );
		$categories  = $wpdb->get_results( "SELECT event_type_id AS id, event_type_name AS name FROM {$wpdb->prefix}event_type WHERE event_type_status = 1 ORDER BY name", ARRAY_A );

		$maps_api_key  = get_option( 'hostlinks_google_maps_api_key', '' );
		$form_header   = get_option( 'hostlinks_event_request_form_header', 'New Event Build Form' );

		// Notification recipients — surfaced as a user-editable CC list above
		// the submit button so the submitter can uncheck / add extras.
		$notif_email   = get_option( 'hostlinks_event_request_notification_email', get_option( 'admin_email' ) );
		$cc_defaults   = get_option( 'hostlinks_event_request_cc_recipients', array() );
		if ( ! is_array( $cc_defaults ) ) {
			$cc_defaults = array();
		}

		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/event-request-form.php';
		return ob_get_clean();
	}

	// ── Notification email ────────────────────────────────────────────────────

	private function send_notification( array $ids, array $records ) {
		$to = get_option( 'hostlinks_event_request_notification_email', get_option( 'admin_email' ) );
		if ( empty( $to ) ) {
			return;
		}

		$first  = $records[0] ?? array();
		$prefix = get_option( 'hostlinks_event_request_email_subject_prefix', '[Event Request]' );

		$city     = $first['city']  ?? '';
		$state    = $first['state'] ?? '';
		$location = trim( $city . ( $state ? ', ' . $state : '' ) );
		$n_events = count( $records );
		$subject  = trim( $prefix ) . ' ' . $location . ' — ' . $n_events . ' event' . ( $n_events > 1 ? 's' : '' ) . ' submitted';

		$sep  = str_repeat( '-', 60 );
		$sep2 = str_repeat( '=', 60 );
		$L    = array(); // output lines

		// ── Header ──────────────────────────────────────────────────────────
		$L[] = 'New Event Request Submission';
		$L[] = 'Submitted: ' . ( ! empty( $first['submitted_at'] ) ? date( 'M j, Y g:i A', strtotime( $first['submitted_at'] ) ) : date( 'M j, Y' ) );
		$L[] = $sep2;

		// ── Section 1: Events ────────────────────────────────────────────────
		$L[] = '';
		$L[] = 'EVENTS (' . $n_events . ')';
		$L[] = $sep;

		foreach ( $records as $idx => $data ) {
			$rid    = $ids[ $idx ] ?? '?';
			$is_v   = ( $data['format'] ?? '' ) === 'virtual';
			$L[] = '';
			$L[] = '  Event #' . $rid;
			$L[] = '  Type      : ' . ( $data['category']   ?? '—' );
			$L[] = '  Start     : ' . ( $data['start_date'] ?? '—' );
			$L[] = '  End       : ' . ( $data['end_date']   ?? '—' );
			$L[] = '  Trainer   : ' . ( $data['trainer']    ?? '—' );
			$L[] = '  Format    : ' . ( $is_v ? 'Virtual (Zoom)' : 'In-Person' );
			$L[] = '  Timezone  : ' . ( $data['timezone']   ?? '—' );
		}

		// ── Section 2: Marketer / Trainer ────────────────────────────────────
		$L[] = '';
		$L[] = $sep;
		$L[] = 'MARKETER & TRAINER';
		$L[] = $sep;
		$L[] = '  Marketer  : ' . ( $first['marketer'] ?? '—' );
		$L[] = '  Trainer   : ' . ( $first['trainer']  ?? '—' );

		// ── Section 3: Host & Venue ───────────────────────────────────────────
		$L[] = '';
		$L[] = $sep;
		$L[] = 'HOST & VENUE';
		$L[] = $sep;
		$L[] = '  Host Name       : ' . ( $first['host_name']      ?? '' );
		$L[] = '  Displayed As    : ' . ( $first['displayed_as']   ?? '' );
		$L[] = '  Location / Room : ' . ( $first['location_name']  ?? '' );
		$addr_parts = array_filter( array(
			$first['street_address_1'] ?? '',
			$first['street_address_2'] ?? '',
			$first['street_address_3'] ?? '',
		) );
		if ( $addr_parts ) {
			foreach ( $addr_parts as $ap ) {
				$L[] = '  Address         : ' . $ap;
			}
		}
		$L[] = '  City, State, ZIP: ' . trim( $location . ( ! empty( $first['zip_code'] ) ? ' ' . $first['zip_code'] : '' ) );

		// ── Section 4: Host Contacts ─────────────────────────────────────────
		$contacts_json = $first['host_contacts'] ?? '[]';
		$contacts      = is_array( $contacts_json )
			? $contacts_json
			: ( json_decode( $contacts_json, true ) ?: array() );

		if ( ! empty( $contacts ) ) {
			$L[] = '';
			$L[] = $sep;
			$L[] = 'HOST CONTACTS';
			$L[] = $sep;
			foreach ( $contacts as $ci => $c ) {
				$L[] = '';
				$L[] = '  Contact ' . ( $ci + 1 );
				if ( ! empty( $c['name'] ) )    { $L[] = '    Name    : ' . $c['name']; }
				if ( ! empty( $c['agency'] ) )  { $L[] = '    Agency  : ' . $c['agency']; }
				if ( ! empty( $c['title'] ) )   { $L[] = '    Title   : ' . $c['title']; }
				if ( ! empty( $c['email'] ) )   { $L[] = '    Email   : ' . $c['email']; }
				if ( ! empty( $c['phone'] ) )   { $L[] = '    Phone   : ' . $c['phone'] . ( ! empty( $c['dnl_phone'] ) ? ' [Do Not List]' : '' ); }
				if ( ! empty( $c['phone2'] ) )  { $L[] = '    Phone 2 : ' . $c['phone2'] . ( ! empty( $c['dnl_phone2'] ) ? ' [Do Not List]' : '' ); }
			}
		}

		// ── Section 5: Hotels ────────────────────────────────────────────────
		$hotels_json = $first['hotels'] ?? '[]';
		$hotels      = is_array( $hotels_json )
			? $hotels_json
			: ( json_decode( $hotels_json, true ) ?: array() );

		if ( ! empty( $hotels ) ) {
			$L[] = '';
			$L[] = $sep;
			$L[] = 'HOTEL RECOMMENDATIONS';
			$L[] = $sep;
			foreach ( $hotels as $hi => $h ) {
				$L[] = '';
				$L[] = '  Hotel ' . ( $hi + 1 ) . ': ' . ( $h['name'] ?? '' );
				if ( ! empty( $h['phone'] ) )   { $L[] = '    Phone   : ' . $h['phone']; }
				if ( ! empty( $h['address'] ) ) { $L[] = '    Address : ' . $h['address']; }
				if ( ! empty( $h['url'] ) )     { $L[] = '    URL     : ' . $h['url']; }
			}
		}

		// ── Section 6: Additional Details ────────────────────────────────────
		$has_additional = ! empty( $first['max_attendees'] )
			|| ! empty( $first['special_instructions'] )
			|| ! empty( $first['custom_email_intro'] )
			|| ! empty( $first['parking_file_url'] );

		if ( $has_additional ) {
			$L[] = '';
			$L[] = $sep;
			$L[] = 'ADDITIONAL DETAILS';
			$L[] = $sep;
			if ( ! empty( $first['max_attendees'] ) )      { $L[] = '  Max Attendees       : ' . $first['max_attendees']; }
			if ( ! empty( $first['special_instructions'] ) ) {
				$L[] = '  Special Instructions:';
				foreach ( explode( "\n", $first['special_instructions'] ) as $si_line ) {
					$L[] = '    ' . $si_line;
				}
			}
			if ( ! empty( $first['custom_email_intro'] ) ) {
				$L[] = '  Custom Email Intro  :';
				foreach ( explode( "\n", $first['custom_email_intro'] ) as $ce_line ) {
					$L[] = '    ' . $ce_line;
				}
			}
			if ( ! empty( $first['parking_file_url'] ) ) { $L[] = '  Parking / Directions: ' . $first['parking_file_url']; }
		}

		// ── Section 7: Shipping ──────────────────────────────────────────────
		$has_shipping = ! empty( $first['ship_name'] )
			|| ! empty( $first['ship_address_1'] )
			|| ! empty( $first['ship_email'] );

		if ( $has_shipping ) {
			$L[] = '';
			$L[] = $sep;
			$L[] = 'SHIPPING DETAILS';
			$L[] = $sep;
			if ( ! empty( $first['ship_name'] ) )      { $L[] = '  Name      : ' . $first['ship_name']; }
			if ( ! empty( $first['ship_email'] ) )     { $L[] = '  Email     : ' . $first['ship_email']; }
			if ( ! empty( $first['ship_phone'] ) )     { $L[] = '  Phone     : ' . $first['ship_phone']; }
			$ship_addr = array_filter( array(
				$first['ship_address_1'] ?? '',
				$first['ship_address_2'] ?? '',
				$first['ship_address_3'] ?? '',
			) );
			foreach ( $ship_addr as $sa ) { $L[] = '  Address   : ' . $sa; }
			$ship_csz = trim(
				( $first['ship_city'] ?? '' )
				. ( ! empty( $first['ship_state'] ) ? ', ' . $first['ship_state'] : '' )
				. ( ! empty( $first['ship_zip'] )   ? ' ' . $first['ship_zip']   : '' )
			);
			if ( $ship_csz ) { $L[] = '  City/State: ' . $ship_csz; }
			if ( isset( $first['ship_workbooks'] ) && $first['ship_workbooks'] !== null && $first['ship_workbooks'] !== '' ) {
				$L[] = '  Workbooks : ' . $first['ship_workbooks'];
			}
			if ( ! empty( $first['ship_notes'] ) )    { $L[] = '  Notes     : ' . $first['ship_notes']; }
		}

		// ── Footer ───────────────────────────────────────────────────────────
		$L[] = '';
		$L[] = $sep2;
		if ( ! empty( $ids ) ) {
			$L[] = 'Review in admin: ' . admin_url( 'admin.php?page=hostlinks-event-requests&id=' . $ids[0] );
		}
		$L[] = 'Add to Hostlinks: ' . admin_url( 'admin.php?page=hostlinks-event-requests' );

		// ── Cc: header ───────────────────────────────────────────────────────
		$headers     = array();
		$cc_list     = array();
		$cc_raw_json = $first['cc_emails'] ?? '';
		if ( is_string( $cc_raw_json ) && $cc_raw_json !== '' ) {
			$decoded = json_decode( $cc_raw_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $e ) {
					$e = sanitize_email( (string) $e );
					if ( $e && is_email( $e ) && $e !== $to ) {
						$cc_list[] = $e;
					}
				}
			}
		}
		if ( ! empty( $cc_list ) ) {
			$headers[] = 'Cc: ' . implode( ', ', $cc_list );
		}

		wp_mail( $to, $subject, implode( "\n", $L ), $headers );
	}
}
