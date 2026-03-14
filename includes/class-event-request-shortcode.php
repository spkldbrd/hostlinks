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

		$first   = $records[0] ?? array();
		$prefix  = get_option( 'hostlinks_event_request_email_subject_prefix', '[Event Request]' );
		$location = trim( ( $first['city'] ?? '' ) . ( ! empty( $first['state'] ) ? ', ' . $first['state'] : '' ) );
		$subject  = trim( $prefix ) . ' ' . $location . ' — ' . count( $records ) . ' event(s) submitted';

		$lines   = array();
		$lines[] = 'A new event request has been submitted.';
		$lines[] = '';
		$lines[] = 'Marketer   : ' . ( $first['marketer'] ?? '—' );
		$lines[] = 'Timezone   : ' . ( $first['timezone'] ?? '—' );
		$lines[] = 'Location   : ' . $location;
		$lines[] = 'Host       : ' . ( $first['host_name'] ?? '' );
		$lines[] = '';
		$lines[] = 'Events in this submission:';

		foreach ( $records as $idx => $data ) {
			$rid = $ids[ $idx ] ?? '?';
			$lines[] = sprintf(
				'  #%s  %s  %s – %s  Trainer: %s',
				$rid,
				$data['category'],
				$data['start_date'],
				$data['end_date'],
				$data['trainer']
			);
		}

		$lines[] = '';
		if ( ! empty( $ids ) ) {
			$lines[] = 'Review first: ' . admin_url( 'admin.php?page=hostlinks-event-requests&id=' . $ids[0] );
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
