<?php
/**
 * CVENT Sync Scheduler
 *
 * Manages a daily WordPress cron job that runs Hostlinks_CVENT_Sync::sync_all().
 * Settings are stored under the option key 'hostlinks_cvent_schedule'.
 *
 * Option shape:
 *   enabled  bool   Whether the daily sync is active.
 *   hour     int    Hour of day to run (0–23, site timezone).
 *   minute   int    Minute of hour (0–59). Default 0.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_CVENT_Scheduler {

	const HOOK        = 'hostlinks_cvent_daily_sync';
	const OPTION_KEY  = 'hostlinks_cvent_schedule';
	const LOG_KEY     = 'hostlinks_cvent_schedule_log';

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		// Re-evaluate schedule on every load in case settings changed.
		add_action( 'init', array( __CLASS__, 'maybe_reschedule' ) );
	}

	// -------------------------------------------------------------------------
	// Run the sync (cron callback)
	// -------------------------------------------------------------------------

	public static function run() {
		if ( ! class_exists( 'Hostlinks_CVENT_Sync' ) ) {
			return;
		}

		$result = Hostlinks_CVENT_Sync::sync_all( false ); // live run, not dry-run

		// Store a compact log of the last run.
		$log = array(
			'time'          => current_time( 'mysql' ),
			'synced'        => $result['synced']        ?? 0,
			'matched'       => $result['matched']       ?? 0,
			'needs_review'  => $result['needs_review']  ?? 0,
			'no_candidates' => $result['no_candidates'] ?? 0,
			'errors'        => $result['errors']        ?? 0,
			'total_events'  => count( $result['results'] ?? array() ),
		);
		update_option( self::LOG_KEY, $log, false );
	}

	// -------------------------------------------------------------------------
	// Schedule management
	// -------------------------------------------------------------------------

	/**
	 * Called on every page load to keep the scheduled event in sync with saved settings.
	 * Harmless no-op when nothing has changed.
	 */
	public static function maybe_reschedule() {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] ) {
			self::clear();
			return;
		}

		$next = wp_next_scheduled( self::HOOK );

		// Build the next run timestamp in the site timezone.
		$target_ts = self::next_run_timestamp( $settings['hour'], $settings['minute'] );

		if ( ! $next ) {
			// Not scheduled at all — set it.
			wp_schedule_event( $target_ts, 'daily', self::HOOK );
		} else {
			// Already scheduled: check whether the stored time drifts more than 5 minutes
			// from the desired time-of-day (handles hour/minute changes).
			$stored_offset  = (int) gmdate( 'H', $next ) * 60 + (int) gmdate( 'i', $next );
			$desired_offset = (int) $settings['hour'] * 60 + (int) $settings['minute'];

			// Convert to site timezone for the comparison.
			$tz              = wp_timezone();
			$dt              = new DateTime( '@' . $next );
			$dt->setTimezone( $tz );
			$stored_hm       = (int) $dt->format( 'H' ) * 60 + (int) $dt->format( 'i' );

			if ( abs( $stored_hm - $desired_offset ) > 5 ) {
				// Time-of-day changed — reschedule.
				self::clear();
				wp_schedule_event( $target_ts, 'daily', self::HOOK );
			}
		}
	}

	/** Remove any scheduled instance of the hook. */
	public static function clear() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * Return the Unix timestamp for the next occurrence of HH:MM in the site timezone.
	 * If that time has already passed today, schedules for tomorrow.
	 */
	private static function next_run_timestamp( $hour, $minute ) {
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );
		$run = clone $now;
		$run->setTime( (int) $hour, (int) $minute, 0 );

		if ( $run <= $now ) {
			$run->modify( '+1 day' );
		}

		return $run->getTimestamp();
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	public static function get_settings() {
		$defaults = array( 'enabled' => false, 'hour' => 2, 'minute' => 0 );
		$saved    = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	public static function save_settings( $enabled, $hour, $minute ) {
		update_option( self::OPTION_KEY, array(
			'enabled' => (bool) $enabled,
			'hour'    => max( 0, min( 23, (int) $hour ) ),
			'minute'  => max( 0, min( 59, (int) $minute ) ),
		) );
	}

	public static function get_last_log() {
		return get_option( self::LOG_KEY, null );
	}

	/** Human-readable next scheduled run in the site timezone. */
	public static function next_run_display() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( ! $ts ) {
			return null;
		}
		$tz = wp_timezone();
		$dt = new DateTime( '@' . $ts );
		$dt->setTimezone( $tz );
		return $dt->format( 'D, M j Y \a\t g:i a T' );
	}
}
