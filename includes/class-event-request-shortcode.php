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

	/** Holds validation errors across request lifecycle. @var array */
	private array $errors = array();

	/** Holds the last submission values so the form can be re-populated. @var array */
	private array $old = array();

	/** ID of a successfully inserted request (used for success message). @var int|null */
	private ?int $inserted_id = null;

	public function __construct() {
		add_shortcode( 'hostlinks_event_request_form', array( $this, 'render' ) );
		// Process form on init so we can set $this->errors / $this->inserted_id
		// before the shortcode renders.
		add_action( 'init', array( $this, 'handle_submission' ) );
	}

	// ── Submission handler ────────────────────────────────────────────────────

	public function handle_submission() {
		if ( empty( $_POST['hl_event_request_submit'] ) ) {
			return;
		}
		check_admin_referer( 'hl_event_request_form' );

		// Honeypot — reject silently if filled by a bot.
		if ( ! empty( $_POST['hl_hp_field'] ) ) {
			return;
		}

		$this->old    = $_POST;
		$this->errors = Hostlinks_Event_Request::validate( $_POST );

		if ( ! empty( $this->errors ) ) {
			return; // Shortcode re-renders the form with errors.
		}

		$data = Hostlinks_Event_Request::normalize( $_POST );
		$id   = Hostlinks_Event_Request_Storage::insert( $data );

		if ( $id ) {
			$this->inserted_id = $id;
			$this->send_notification( $id, $data );
			$this->old = array(); // Clear retained values on success.
		} else {
			$this->errors['_db'] = 'There was a problem saving your request. Please try again.';
		}
	}

	// ── Shortcode render ──────────────────────────────────────────────────────

	public function render(): string {
		// Success state — show configured message, not the form.
		if ( $this->inserted_id ) {
			$msg = get_option( 'hostlinks_event_request_success_message', '' );
			if ( $msg === '' ) {
				$msg = 'Thank you! Your event request (ID #' . $this->inserted_id . ') has been submitted. We will be in touch soon.';
			}
			return '<div class="hl-request-success"><p>' . wp_kses_post( $msg ) . '</p></div>';
		}

		// Collect data needed by the form template.
		$errors = $this->errors;
		$old    = $this->old;

		// Fetch active marketers and instructors for dropdowns.
		global $wpdb;
		$marketers   = $wpdb->get_results( "SELECT event_marketer_id AS id, event_marketer_name AS name FROM {$wpdb->prefix}event_marketer WHERE event_marketer_status = 1 ORDER BY name", ARRAY_A );
		$instructors = $wpdb->get_results( "SELECT event_instructor_id AS id, event_instructor_name AS name FROM {$wpdb->prefix}event_instructor WHERE event_instructor_status = 1 ORDER BY name", ARRAY_A );
		$categories  = $wpdb->get_results( "SELECT event_type_id AS id, event_type_name AS name FROM {$wpdb->prefix}event_type WHERE event_type_status = 1 ORDER BY name", ARRAY_A );

		ob_start();
		include HOSTLINKS_PLUGIN_DIR . 'shortcode/event-request-form.php';
		return ob_get_clean();
	}

	// ── Notification email ────────────────────────────────────────────────────

	private function send_notification( int $id, array $data ) {
		$to = get_option( 'hostlinks_event_request_notification_email', get_option( 'admin_email' ) );
		if ( empty( $to ) ) {
			return;
		}

		$prefix  = get_option( 'hostlinks_event_request_email_subject_prefix', '[Event Request]' );
		$subject = trim( $prefix ) . ' ' . $data['event_title'] . ' — #' . $id;

		$admin_url = admin_url( 'admin.php?page=hostlinks-event-requests&id=' . $id );

		$cc_emails    = json_decode( $data['cc_emails'],    true ) ?: array();
		$cc_list      = implode( ', ', $cc_emails );

		$lines   = array();
		$lines[] = 'A new event request has been submitted.';
		$lines[] = '';
		$lines[] = 'Request ID : #' . $id;
		$lines[] = 'Title      : ' . $data['event_title'];
		$lines[] = 'Category   : ' . $data['category'];
		$lines[] = 'Format     : ' . $data['format'];
		$lines[] = 'Timezone   : ' . $data['timezone'];
		$lines[] = 'Trainer    : ' . $data['trainer'];
		$lines[] = 'Marketer   : ' . ( $data['marketer'] ?: '—' );
		$lines[] = 'Dates      : ' . $data['start_date'] . ' – ' . $data['end_date'];
		$lines[] = 'Times      : ' . $data['start_time'] . ' – ' . $data['end_time'];
		$lines[] = 'City/State : ' . trim( $data['city'] . ' ' . $data['state'] );
		if ( $cc_list ) {
			$lines[] = 'CC Emails  : ' . $cc_list;
		}
		$lines[] = '';
		$lines[] = 'Review: ' . $admin_url;

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
