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

		self::send_notification( $this->inserted_ids, $records );
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

	/**
	 * Send (or re-send) the notification email for a submission.
	 *
	 * @param int[]  $ids      DB row IDs for this submission.
	 * @param array  $records  Fully-decoded row arrays (same shape as storage returns).
	 */
	public static function send_notification( array $ids, array $records ) {
		$to = get_option( 'hostlinks_event_request_notification_email', get_option( 'admin_email' ) );
		if ( empty( $to ) ) {
			return;
		}

		$first    = $records[0] ?? array();
		$n_events = count( $records );

		// ── Subject ──────────────────────────────────────────────────────────
		$all_virtual = ( $n_events > 0 ) && array_reduce(
			$records,
			fn( $carry, $r ) => $carry && ( ( $r['format'] ?? '' ) === 'virtual' ),
			true
		);

		if ( $all_virtual ) {
			$subject = 'New ZOOM events';
		} else {
			$city  = trim( $first['city']  ?? '' );
			$state = trim( $first['state'] ?? '' );
			$loc   = $city . ( $state ? ', ' . $state : '' );

			$date_str = self::_format_date_range(
				$first['start_date'] ?? '',
				$first['end_date']   ?? ''
			);

			// Resolve type abbreviation from the DB; fall back to category name.
			global $wpdb;
			$category = trim( $first['category'] ?? '' );
			$abbr     = '';
			if ( $category ) {
				$abbr = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT event_type_abbr FROM {$wpdb->prefix}event_type WHERE event_type_name = %s LIMIT 1",
					$category
				) );
			}
			if ( $abbr === '' ) {
				$abbr = $category;
			}

			$subject = 'New Workshop in: ' . $loc . ', ' . $date_str . ' ' . $abbr;
		}

		// ── Smart possessive for marketer ─────────────────────────────────────
		$marketer_raw  = trim( $first['marketer'] ?? '' );
		$non_person    = array( 'private', 'zoom', 'virtual', 'tbd' );
		$marketer_lc   = strtolower( $marketer_raw );
		$is_private    = ( $marketer_lc === 'private' );
		$is_non_person = in_array( $marketer_lc, $non_person, true );

		$L   = array();
		$sep = '----';

		// ── Intro line ───────────────────────────────────────────────────────
		if ( $marketer_raw !== '' && ! $is_private ) {
			$possessive = $is_non_person ? $marketer_raw . '.' : $marketer_raw . "'s.";
			$L[] = 'This class will be ' . $possessive;
			$L[] = '';
		}

		// ── Event type + date blocks ──────────────────────────────────────────
		foreach ( $records as $data ) {
			if ( ! empty( $data['category'] ) ) {
				$L[] = $data['category'];
			}
			$dr = self::_format_date_range( $data['start_date'] ?? '', $data['end_date'] ?? '' );
			if ( $dr ) {
				$L[] = $dr;
			}
			if ( ( $data['format'] ?? '' ) === 'virtual' ) {
				$L[] = 'Virtual (Zoom)';
				if ( ! empty( $data['timezone'] ) ) {
					$L[] = $data['timezone'];
				}
			}
			if ( ! empty( $data['trainer'] ) ) {
				$L[] = 'Trainer: ' . $data['trainer'];
			}
			$L[] = '';
		}

		// ── Venue ────────────────────────────────────────────────────────────
		$displayed_as = trim( $first['displayed_as'] ?? '' );
		$host_name    = trim( $first['host_name']    ?? '' );
		$venue_label  = $displayed_as ?: $host_name;

		if ( $venue_label ) {
			$L[] = $sep;
			$L[] = '';
			$L[] = $venue_label;
			if ( ! empty( $first['location_name'] ) ) {
				$L[] = $first['location_name'];
			}
			foreach ( array_filter( array(
				$first['street_address_1'] ?? '',
				$first['street_address_2'] ?? '',
				$first['street_address_3'] ?? '',
			) ) as $addr_line ) {
				$L[] = $addr_line;
			}
			$city_raw  = trim( $first['city']     ?? '' );
			$state_raw = trim( $first['state']    ?? '' );
			$zip_raw   = trim( $first['zip_code'] ?? '' );
			$csz       = $city_raw
				. ( $state_raw ? ' ' . $state_raw : '' )
				. ( $zip_raw   ? '   ' . $zip_raw : '' );
			if ( $csz ) {
				$L[] = $csz;
			}
			$L[] = '';
		}

		// ── Host contacts ─────────────────────────────────────────────────────
		$contacts_json = $first['host_contacts'] ?? '[]';
		$contacts      = is_array( $contacts_json )
			? $contacts_json
			: ( json_decode( $contacts_json, true ) ?: array() );

		foreach ( $contacts as $c ) {
			if ( ! empty( $c['name'] ) )   { $L[] = $c['name']; }
			if ( ! empty( $c['agency'] ) ) { $L[] = $c['agency']; }
			if ( ! empty( $c['title'] ) )  { $L[] = $c['title']; }
			if ( ! empty( $c['email'] ) )  { $L[] = $c['email']; }
			if ( ! empty( $c['phone'] ) ) {
				$L[] = $c['phone'] . ( ! empty( $c['dnl_phone'] ) ? ' [Do Not List]' : '' );
			}
			if ( ! empty( $c['phone2'] ) ) {
				$L[] = $c['phone2'] . ( ! empty( $c['dnl_phone2'] ) ? ' [Do Not List]' : '' );
			}
			$L[] = '';
		}

		// ── Hotels ───────────────────────────────────────────────────────────
		$hotels_json = $first['hotels'] ?? '[]';
		$hotels      = is_array( $hotels_json )
			? $hotels_json
			: ( json_decode( $hotels_json, true ) ?: array() );

		foreach ( $hotels as $h ) {
			if ( ! empty( $h['name'] ) )    { $L[] = $h['name']; }
			if ( ! empty( $h['address'] ) ) { $L[] = $h['address']; }
			if ( ! empty( $h['phone'] ) )   { $L[] = $h['phone']; }
			if ( ! empty( $h['url'] ) )     { $L[] = $h['url']; }
			$L[] = '';
		}

		// ── Shipping / Att: ───────────────────────────────────────────────────
		$has_shipping = ! empty( $first['ship_name'] )
			|| ! empty( $first['ship_address_1'] )
			|| ! empty( $first['ship_email'] );

		if ( $has_shipping ) {
			if ( ! empty( $first['ship_name'] ) ) {
				$L[] = 'Att: ' . $first['ship_name'];
			}
			foreach ( array_filter( array(
				$first['ship_address_1'] ?? '',
				$first['ship_address_2'] ?? '',
				$first['ship_address_3'] ?? '',
			) ) as $sa ) {
				$L[] = $sa;
			}
			$ship_csz = trim(
				( $first['ship_city']  ?? '' )
				. ( ! empty( $first['ship_state'] ) ? ' ' . $first['ship_state'] : '' )
				. ( ! empty( $first['ship_zip'] )   ? '   ' . $first['ship_zip'] : '' )
			);
			if ( $ship_csz ) {
				$L[] = $ship_csz;
			}
			if ( ! empty( $first['ship_phone'] ) ) { $L[] = $first['ship_phone']; }
			if ( ! empty( $first['ship_email'] ) ) { $L[] = $first['ship_email']; }
			if ( isset( $first['ship_workbooks'] ) && $first['ship_workbooks'] !== null && $first['ship_workbooks'] !== '' ) {
				$L[] = 'Workbooks: ' . $first['ship_workbooks'];
			}
			if ( ! empty( $first['ship_notes'] ) ) { $L[] = $first['ship_notes']; }
			$L[] = '';
		}

		// ── Additional details ────────────────────────────────────────────────
		$has_additional = ! empty( $first['max_attendees'] )
			|| ! empty( $first['special_instructions'] )
			|| ! empty( $first['custom_email_intro'] )
			|| ! empty( $first['parking_file_url'] );

		if ( $has_additional ) {
			$L[] = $sep;
			$L[] = '';
			if ( ! empty( $first['max_attendees'] ) ) {
				$L[] = 'Max Attendees: ' . $first['max_attendees'];
			}
			if ( ! empty( $first['custom_email_intro'] ) ) {
				foreach ( explode( "\n", $first['custom_email_intro'] ) as $ce_line ) {
					$L[] = $ce_line;
				}
			}
			if ( ! empty( $first['special_instructions'] ) ) {
				$L[] = 'Special Instructions:';
				foreach ( explode( "\n", $first['special_instructions'] ) as $si_line ) {
					$L[] = $si_line;
				}
			}
			if ( ! empty( $first['parking_file_url'] ) ) {
				$L[] = 'Parking / Directions: ' . $first['parking_file_url'];
			}
			$L[] = '';
		}

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

	/**
	 * Format a start/end YYYY-MM-DD pair into a human-readable range.
	 *
	 * 2026-08-19 / 2026-08-20  →  August 19-20, 2026
	 * 2026-07-31 / 2026-08-01  →  July 31 - August 1, 2026
	 * 2026-08-19 / 2026-08-19  →  August 19, 2026
	 */
	private static function _format_date_range( string $start_raw, string $end_raw ): string {
		if ( $start_raw === '' ) {
			return '';
		}
		$tz    = wp_timezone();
		$start = DateTime::createFromFormat( 'Y-m-d', $start_raw, $tz );
		if ( ! $start ) {
			return $start_raw;
		}
		if ( $end_raw === '' || $end_raw === $start_raw ) {
			return $start->format( 'F j, Y' );
		}
		$end = DateTime::createFromFormat( 'Y-m-d', $end_raw, $tz );
		if ( ! $end ) {
			return $start->format( 'F j, Y' );
		}
		if ( $start->format( 'Y' ) === $end->format( 'Y' ) ) {
			if ( $start->format( 'm' ) === $end->format( 'm' ) ) {
				// Same month and year: August 19-20, 2026
				return $start->format( 'F' ) . ' ' . $start->format( 'j' ) . '-' . $end->format( 'j' ) . ', ' . $start->format( 'Y' );
			}
			// Different month, same year: July 31 - August 1, 2026
			return $start->format( 'F j' ) . ' - ' . $end->format( 'F j' ) . ', ' . $start->format( 'Y' );
		}
		// Different years (rare): July 31, 2026 - January 2, 2027
		return $start->format( 'F j, Y' ) . ' - ' . $end->format( 'F j, Y' );
	}
}
