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

			$subject = 'New Event Build Request: ' . $loc;
		}

		// ── Smart possessive for marketer ─────────────────────────────────────
		$marketer_raw  = trim( $first['marketer'] ?? '' );
		$non_person    = array( 'private', 'zoom', 'virtual', 'tbd' );
		$marketer_lc   = strtolower( $marketer_raw );
		$is_private    = ( $marketer_lc === 'private' );
		$is_non_person = in_array( $marketer_lc, $non_person, true );

		// ── HTML helpers ──────────────────────────────────────────────────────
		$esc = function( $s ) {
			return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		};
		$shdr = function( $label ) {
			return '<p style="margin:20px 0 2px 0;"><strong><u>' . $label . '</u></strong></p>';
		};

		$html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.8;color:#222;">' . "\n";

		// ── Intro line ───────────────────────────────────────────────────────
		if ( $marketer_raw !== '' && ! $is_private ) {
			$possessive = $is_non_person ? $marketer_raw . '.' : $marketer_raw . "'s.";
			$html .= '<p>This class will be ' . $esc( $possessive ) . '</p>' . "\n";
		}

		// Maps short category names to full workshop display names.
		$type_display = array(
			'management' => 'Grant Management Workshop',
			'writing'    => 'Grant Writing Workshop',
			'subaward'   => 'Subaward Workshop',
		);

		// ── Event type + date blocks ──────────────────────────────────────────
		foreach ( $records as $data ) {
			$html .= '<p>';
			if ( ! empty( $data['category'] ) ) {
				$cat_key      = strtolower( trim( $data['category'] ) );
				$cat_label    = $type_display[ $cat_key ] ?? $data['category'];
				$html .= $esc( $cat_label ) . '<br>';
			}
			$dr = self::_format_date_range( $data['start_date'] ?? '', $data['end_date'] ?? '' );
			if ( $dr ) {
				$html .= $esc( $dr ) . '<br>';
			}
			if ( ( $data['format'] ?? '' ) === 'virtual' ) {
				$html .= 'Virtual (Zoom)';
				if ( ! empty( $data['timezone'] ) ) {
					$html .= '<br>' . $esc( $data['timezone'] );
				}
				$html .= '<br>';
			}
			if ( ! empty( $data['trainer'] ) ) {
				$html .= 'Trainer: ' . $esc( $data['trainer'] ) . '<br>';
			}
			$html .= '</p>' . "\n";
		}

		// ── HOST INFO ─────────────────────────────────────────────────────────
		$displayed_as  = trim( $first['displayed_as']  ?? '' );
		$host_name     = trim( $first['host_name']     ?? '' );
		$location_name = trim( $first['location_name'] ?? '' );
		$addr_parts    = array_filter( array(
			$first['street_address_1'] ?? '',
			$first['street_address_2'] ?? '',
			$first['street_address_3'] ?? '',
		) );
		$city_raw  = trim( $first['city']     ?? '' );
		$state_raw = trim( $first['state']    ?? '' );
		$zip_raw   = trim( $first['zip_code'] ?? '' );
		$csz       = $city_raw
			. ( $state_raw ? ' ' . $state_raw : '' )
			. ( $zip_raw   ? '   ' . $zip_raw : '' );

		$venue_label = $displayed_as ?: $host_name;
		$has_venue   = $venue_label || $location_name || $addr_parts || $csz;
		if ( $has_venue ) {
			$html .= $shdr( 'HOST INFO' );
			$html .= '<p>';
			if ( $venue_label ) {
				$html .= $esc( $venue_label ) . '<br>';
			}
			if ( $location_name ) {
				$html .= $esc( $location_name ) . '<br>';
			}
			foreach ( $addr_parts as $addr_line ) {
				$html .= $esc( $addr_line ) . '<br>';
			}
			if ( $csz ) {
				$html .= $esc( $csz );
			}
			$html .= '</p>' . "\n";
		}

		// ── HOST CONTACTS ─────────────────────────────────────────────────────
		$contacts_json = $first['host_contacts'] ?? '[]';
		$contacts      = is_array( $contacts_json )
			? $contacts_json
			: ( json_decode( $contacts_json, true ) ?: array() );

		if ( ! empty( $contacts ) ) {
			$html .= $shdr( 'HOST CONTACTS' );
			foreach ( $contacts as $c ) {
				$html .= '<p>';
				if ( ! empty( $c['name'] ) )   { $html .= $esc( $c['name'] )   . '<br>'; }
				if ( ! empty( $c['agency'] ) ) { $html .= $esc( $c['agency'] ) . '<br>'; }
				if ( ! empty( $c['title'] ) )  { $html .= $esc( $c['title'] )  . '<br>'; }
				if ( ! empty( $c['email'] ) )  { $html .= $esc( $c['email'] )  . '<br>'; }
				if ( ! empty( $c['phone'] ) ) {
					$html .= $esc( $c['phone'] ) . ( ! empty( $c['dnl_phone'] ) ? ' [Do Not List]' : '' ) . '<br>';
				}
				if ( ! empty( $c['phone2'] ) ) {
					$html .= $esc( $c['phone2'] ) . ( ! empty( $c['dnl_phone2'] ) ? ' [Do Not List]' : '' ) . '<br>';
				}
				$html .= '</p>' . "\n";
			}
		}

		// ── HOTELS ───────────────────────────────────────────────────────────
		$hotels_json = $first['hotels'] ?? '[]';
		$hotels      = is_array( $hotels_json )
			? $hotels_json
			: ( json_decode( $hotels_json, true ) ?: array() );

		if ( ! empty( $hotels ) ) {
			$html .= $shdr( 'HOTELS' );
			foreach ( $hotels as $hotel ) {
				$html .= '<p>';
				if ( ! empty( $hotel['name'] ) )    { $html .= $esc( $hotel['name'] )    . '<br>'; }
				if ( ! empty( $hotel['address'] ) ) { $html .= $esc( $hotel['address'] ) . '<br>'; }
				if ( ! empty( $hotel['phone'] ) )   { $html .= $esc( $hotel['phone'] )   . '<br>'; }
				if ( ! empty( $hotel['url'] ) )     { $html .= $esc( $hotel['url'] )     . '<br>'; }
				$html .= '</p>' . "\n";
			}
		}

		// ── SHIPPING INFO ─────────────────────────────────────────────────────
		$has_shipping = ! empty( $first['ship_name'] )
			|| ! empty( $first['ship_address_1'] )
			|| ! empty( $first['ship_email'] );

		if ( $has_shipping ) {
			$html .= $shdr( 'SHIPPING INFO' );
			$html .= '<p>';
			if ( ! empty( $first['ship_name'] ) ) {
				$html .= 'Att: ' . $esc( $first['ship_name'] ) . '<br>';
			}
			foreach ( array_filter( array(
				$first['ship_address_1'] ?? '',
				$first['ship_address_2'] ?? '',
				$first['ship_address_3'] ?? '',
			) ) as $sa ) {
				$html .= $esc( $sa ) . '<br>';
			}
			$ship_csz = trim(
				( $first['ship_city']  ?? '' )
				. ( ! empty( $first['ship_state'] ) ? ' ' . $first['ship_state'] : '' )
				. ( ! empty( $first['ship_zip'] )   ? '   ' . $first['ship_zip'] : '' )
			);
			if ( $ship_csz ) { $html .= $esc( $ship_csz ) . '<br>'; }
			if ( ! empty( $first['ship_phone'] ) ) { $html .= $esc( $first['ship_phone'] ) . '<br>'; }
			if ( ! empty( $first['ship_email'] ) ) { $html .= $esc( $first['ship_email'] ) . '<br>'; }
			if ( isset( $first['ship_workbooks'] ) && $first['ship_workbooks'] !== null && $first['ship_workbooks'] !== '' ) {
				$html .= 'Workbooks: ' . $esc( $first['ship_workbooks'] ) . '<br>';
			}
			if ( ! empty( $first['ship_notes'] ) ) { $html .= $esc( $first['ship_notes'] ) . '<br>'; }
			$html .= '</p>' . "\n";
		}

		// ── SPECIAL INSTRUCTION ───────────────────────────────────────────────
		$has_additional = ! empty( $first['max_attendees'] )
			|| ! empty( $first['special_instructions'] )
			|| ! empty( $first['custom_email_intro'] )
			|| ! empty( $first['parking_file_url'] );

		if ( $has_additional ) {
			$html .= $shdr( 'SPECIAL INSTRUCTION' );
			$html .= '<p>';
			if ( ! empty( $first['max_attendees'] ) ) {
				$html .= 'Max Attendees: ' . $esc( $first['max_attendees'] ) . '<br>';
			}
			if ( ! empty( $first['custom_email_intro'] ) ) {
				$html .= nl2br( $esc( $first['custom_email_intro'] ) ) . '<br>';
			}
			if ( ! empty( $first['special_instructions'] ) ) {
				$html .= nl2br( $esc( $first['special_instructions'] ) ) . '<br>';
			}
			if ( ! empty( $first['parking_file_url'] ) ) {
				$html .= 'Parking / Directions: ' . $esc( $first['parking_file_url'] ) . '<br>';
			}
			$html .= '</p>' . "\n";
		}

		$html .= '</div>';

		// ── Cc: header ───────────────────────────────────────────────────────
		$headers     = array( 'Content-Type: text/html; charset=UTF-8' );
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

		wp_mail( $to, $subject, $html, $headers );
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
