<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side POST of the email-events snapshot to EmailCraft ingest.
 *
 * EmailCraft cannot GET Hostlinks from its VPS; Hostlinks pushes instead.
 */
class Hostlinks_EmailCraft_Push {

	const OPTION_SETTINGS = 'hostlinks_emailcraft_ingest';
	const OPTION_STATUS   = 'hostlinks_emailcraft_status';
	const OPTION_LAST_TS  = 'hostlinks_emailcraft_last_push_ts';
	const OPTION_BACKOFF  = 'hostlinks_emailcraft_backoff_until';
	const HOOK_PUSH       = 'hostlinks_emailcraft_push';
	const HOOK_HOURLY     = 'hostlinks_emailcraft_push_hourly';
	const DEFAULT_URL     = 'https://emailcraft.grantwritingusa.com/api/events/hostlinks-ingest';
	const DAYS            = 180;
	const MAX_EVENTS      = 500;
	const MIN_INTERVAL    = 20;

	private static $scheduled_this_request = false;
	private static $force_now              = false;

	public static function init() {
		add_action( self::HOOK_PUSH, array( __CLASS__, 'push' ) );
		add_action( self::HOOK_HOURLY, array( __CLASS__, 'push' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_hourly' ) );
		add_action( 'hostlinks_event_created', array( __CLASS__, 'schedule' ), 20, 0 );
		add_action( 'hostlinks_event_updated', array( __CLASS__, 'schedule' ), 20, 0 );
		add_action( 'admin_post_hostlinks_emailcraft_push_now', array( __CLASS__, 'handle_push_now' ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK_PUSH );
		wp_clear_scheduled_hook( self::HOOK_HOURLY );
	}

	public static function ensure_hourly() {
		if ( ! wp_next_scheduled( self::HOOK_HOURLY ) ) {
			wp_schedule_event( time() + 120, 'hourly', self::HOOK_HOURLY );
		}
	}

	/**
	 * Queue a push on shutdown, coalescing many saves in one request.
	 * Respects EmailCraft rate limits (not more than a few POSTs per minute).
	 */
	public static function schedule() {
		if ( self::$scheduled_this_request ) {
			return;
		}
		if ( ! self::is_configured() ) {
			return;
		}
		self::$scheduled_this_request = true;
		add_action( 'shutdown', array( __CLASS__, 'push_debounced' ), 100 );
	}

	public static function push_debounced() {
		$backoff = (int) get_option( self::OPTION_BACKOFF, 0 );
		if ( $backoff > time() ) {
			self::ensure_delayed( $backoff );
			return;
		}
		$last = (int) get_option( self::OPTION_LAST_TS, 0 );
		$wait = self::MIN_INTERVAL - ( time() - $last );
		if ( $last > 0 && $wait > 0 ) {
			self::ensure_delayed( time() + $wait );
			return;
		}
		self::push();
	}

	/**
	 * @return true|WP_Error
	 */
	public static function push() {
		$s = self::get_settings();
		if ( '' === $s['url'] || '' === $s['key'] ) {
			return new WP_Error( 'not_configured', 'EmailCraft ingest URL or key is not set.' );
		}

		$backoff = (int) get_option( self::OPTION_BACKOFF, 0 );
		if ( $backoff > time() && ! self::$force_now ) {
			self::ensure_delayed( $backoff );
			return new WP_Error( 'backoff', 'EmailCraft ingest is backing off until ' . gmdate( 'c', $backoff ) . '.' );
		}

		$last = (int) get_option( self::OPTION_LAST_TS, 0 );
		if ( $last > 0 && ( time() - $last ) < self::MIN_INTERVAL && ! self::$force_now ) {
			self::ensure_delayed( $last + self::MIN_INTERVAL );
			return new WP_Error( 'rate', 'Skipped — last ingest was too recent.' );
		}

		$events = Hostlinks_Instructor_API::query_email_events(
			array(
				'days'            => self::DAYS,
				'include_private' => false,
				'detail'          => 'summary',
				'limit'           => self::MAX_EVENTS,
			)
		);

		if ( empty( $events ) ) {
			self::store_status(
				array(
					'ok'      => false,
					'skipped' => true,
					'message' => 'No upcoming public events in the next ' . self::DAYS . ' days — ingest not called (EmailCraft keeps the previous snapshot).',
					'at'      => gmdate( 'c' ),
				)
			);
			return new WP_Error( 'empty', 'No events to push; EmailCraft snapshot left unchanged.' );
		}

		foreach ( $events as &$ev ) {
			if ( isset( $ev['id'] ) ) {
				$ev['id'] = (string) $ev['id'];
			}
		}
		unset( $ev );

		$payload = array(
			'days'   => self::DAYS,
			'events' => $events,
		);

		update_option( self::OPTION_LAST_TS, time(), false );

		$response = wp_remote_post(
			$s['url'],
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'             => 'application/json',
					'Accept'                   => 'application/json',
					'X-EmailCraft-Ingest-Key'  => $s['key'],
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::set_backoff( 5 * MINUTE_IN_SECONDS );
			self::store_status(
				array(
					'ok'      => false,
					'http'    => 0,
					'message' => $response->get_error_message(),
					'at'      => gmdate( 'c' ),
				)
			);
			error_log( 'Hostlinks EmailCraft ingest network error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( 200 === $code && ! empty( $body['ok'] ) ) {
			delete_option( self::OPTION_BACKOFF );
			self::store_status(
				array(
					'ok'        => true,
					'http'      => 200,
					'count'     => isset( $body['count'] ) ? (int) $body['count'] : count( $events ),
					'updatedAt' => (string) ( $body['updatedAt'] ?? '' ),
					'source'    => (string) ( $body['source'] ?? 'plugin-push' ),
					'at'        => gmdate( 'c' ),
				)
			);
			return true;
		}

		$message = self::message_for_status( $code, $body );
		if ( 401 === $code || 503 === $code ) {
			self::set_backoff( HOUR_IN_SECONDS );
		} elseif ( 429 === $code ) {
			self::set_backoff( 5 * MINUTE_IN_SECONDS );
		} elseif ( $code >= 500 ) {
			self::set_backoff( 5 * MINUTE_IN_SECONDS );
		}

		self::store_status(
			array(
				'ok'      => false,
				'http'    => $code,
				'message' => $message,
				'at'      => gmdate( 'c' ),
			)
		);
		error_log( 'Hostlinks EmailCraft ingest HTTP ' . $code . ': ' . $message );
		return new WP_Error( 'ingest_failed', $message, array( 'status' => $code ) );
	}

	public static function handle_push_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'hostlinks_emailcraft_push_now' );
		delete_option( self::OPTION_BACKOFF );
		delete_option( self::OPTION_LAST_TS );
		self::$force_now = true;
		$result          = self::push();
		$redirect = admin_url( 'admin.php?page=hostlinks-settings&tab=automation-api' );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'hl_ec_push', 'err', $redirect );
		} else {
			$redirect = add_query_arg( 'hl_ec_push', 'ok', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array(
			'url' => esc_url_raw( $saved['url'] ?? self::DEFAULT_URL ) ?: self::DEFAULT_URL,
			'key' => (string) ( $saved['key'] ?? '' ),
		);
	}

	public static function save_settings( $url, $key, $keep_existing_key = false ) {
		$current = self::get_settings();
		$url     = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			$url = self::DEFAULT_URL;
		}
		$key = trim( (string) $key );
		if ( $keep_existing_key || '' === $key ) {
			$key = $current['key'];
		}
		update_option(
			self::OPTION_SETTINGS,
			array(
				'url' => $url,
				'key' => $key,
			),
			false
		);
		delete_option( self::OPTION_BACKOFF );
	}

	public static function get_status() {
		$st = get_option( self::OPTION_STATUS, array() );
		return is_array( $st ) ? $st : array();
	}

	public static function is_configured() {
		$s = self::get_settings();
		return $s['url'] !== '' && $s['key'] !== '';
	}

	private static function ensure_delayed( $timestamp ) {
		$next = wp_next_scheduled( self::HOOK_PUSH );
		if ( $next && $next <= $timestamp + 2 ) {
			return;
		}
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK_PUSH );
		}
		wp_schedule_single_event( max( time() + 5, (int) $timestamp ), self::HOOK_PUSH );
	}

	private static function set_backoff( $seconds ) {
		update_option( self::OPTION_BACKOFF, time() + (int) $seconds, false );
	}

	private static function store_status( array $status ) {
		update_option( self::OPTION_STATUS, $status, false );
	}

	private static function message_for_status( $code, array $body ) {
		if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
			return $body['error'];
		}
		if ( ! empty( $body['message'] ) && is_string( $body['message'] ) ) {
			return $body['message'];
		}
		$map = array(
			401 => 'Invalid ingest key — paste the current key from EmailCraft Admin → Email → Hostlinks.',
			503 => 'Ingest not configured — generate a key in EmailCraft admin first.',
			400 => 'EmailCraft rejected the payload (no valid events or too many events).',
			429 => 'Too many ingest requests — backing off.',
		);
		return $map[ $code ] ?? ( 'EmailCraft ingest failed (HTTP ' . $code . ').' );
	}
}
