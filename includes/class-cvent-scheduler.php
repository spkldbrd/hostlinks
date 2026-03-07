<?php
/**
 * CVENT Sync Scheduler
 *
 * Manages a WordPress cron job that runs Hostlinks_CVENT_Sync::sync_all().
 *
 * Uses wp_schedule_single_event() rather than a repeating interval so that:
 *   - Day-of-week filtering is respected (skip weekends, etc.).
 *   - A random ±offset is applied to each day's scheduled time, making the
 *     run pattern appear less robotic.
 *
 * After each run, the next single event is scheduled immediately with a fresh
 * random offset. maybe_reschedule() runs on every page load to self-heal if
 * the cron is missing or settings have changed.
 *
 * Option shape ('hostlinks_cvent_schedule'):
 *   enabled    bool     Whether the scheduler is active.
 *   hour       int      Base hour (0–23, site timezone). Default 9.
 *   minute     int      Base minute (0–59). Default 0.
 *   days       int[]    Days of week to run: 0=Sun,1=Mon,…,6=Sat. Default [1-5].
 *   offset_max int      Max random ± offset in minutes. Default 45.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_CVENT_Scheduler {

	const HOOK       = 'hostlinks_cvent_daily_sync';
	const OPTION_KEY = 'hostlinks_cvent_schedule';
	const LOG_KEY    = 'hostlinks_cvent_schedule_log';
	const HASH_KEY   = 'hostlinks_cvent_schedule_hash';

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_action( self::HOOK,  array( __CLASS__, 'run' ) );
		// Restrict self-healing to admin page loads — wp_next_scheduled() runs a
		// DB query, so firing on every frontend request is unnecessary overhead.
		add_action( 'admin_init', array( __CLASS__, 'maybe_reschedule' ) );
	}

	// -------------------------------------------------------------------------
	// Run the sync (cron callback)
	// -------------------------------------------------------------------------

	public static function run() {
		if ( ! class_exists( 'Hostlinks_CVENT_Sync' ) ) {
			return;
		}

		// Record that the cron hook fired — written before sync so even a
		// hung/errored sync leaves a breadcrumb on the settings page.
		update_option( 'hostlinks_cvent_last_auto_run', current_time( 'mysql' ), false );

		$result = Hostlinks_CVENT_Sync::sync_all( false ); // live run

		// Update the frontend "last data updated" date shown in the calendar view.
		if ( ( $result['synced'] ?? 0 ) > 0 ) {
			update_option( 'last_data_updation', current_time( 'Y-m-d' ) );
		}

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

		// Schedule the next single event with a fresh random offset.
		$settings = self::get_settings();
		if ( $settings['enabled'] ) {
			$next_ts = self::next_run_timestamp( $settings );
			if ( $next_ts ) {
				wp_schedule_single_event( $next_ts, self::HOOK );
				update_option( self::HASH_KEY, md5( serialize( $settings ) ) );
				// Record the next scheduled time so the settings page can display
				// it even if wp_next_scheduled() returns an unexpected value.
				update_option( 'hostlinks_cvent_next_scheduled_run', gmdate( 'Y-m-d H:i:s', $next_ts ), false );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Schedule management
	// -------------------------------------------------------------------------

	/**
	 * Called on every page load. Self-heals the schedule if:
	 *   - Schedule is disabled → clears any queued event.
	 *   - Settings changed     → clears old event, queues new one.
	 *   - No event is queued   → queues one.
	 *   - Queued time is past  → reschedules (missed cron).
	 */
	public static function maybe_reschedule() {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] ) {
			self::clear();
			delete_option( self::HASH_KEY );
			return;
		}

		$settings_hash = md5( serialize( $settings ) );
		$stored_hash   = get_option( self::HASH_KEY, '' );
		$next          = wp_next_scheduled( self::HOOK );

		$needs = false;
		if ( ! $next ) {
			$needs = true; // nothing queued
		} elseif ( $settings_hash !== $stored_hash ) {
			$needs = true; // settings changed
		} elseif ( $next < time() ) {
			$needs = true; // missed schedule
		}

		if ( $needs ) {
			self::clear();
			$next_ts = self::next_run_timestamp( $settings );
			if ( $next_ts ) {
				wp_schedule_single_event( $next_ts, self::HOOK );
				update_option( self::HASH_KEY, $settings_hash );
				update_option( 'hostlinks_cvent_next_scheduled_run', gmdate( 'Y-m-d H:i:s', $next_ts ), false );
			}
		}
	}

	/** Remove any queued instance of the hook. */
	public static function clear() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * Calculate the Unix timestamp for the next valid run.
	 *
	 * Finds the next day (from now, looking up to 8 days ahead) that is in the
	 * configured days-of-week list, sets the base time, then applies a random
	 * ±offset_max minute jitter.
	 *
	 * @param array $settings  Result of get_settings().
	 * @return int|null  Unix timestamp, or null if no valid day found.
	 */
	private static function next_run_timestamp( $settings ) {
		$days       = (array) ( $settings['days']       ?? array( 1, 2, 3, 4, 5 ) );
		$hour       = (int)   ( $settings['hour']       ?? 9 );
		$minute     = (int)   ( $settings['minute']     ?? 0 );
		$offset_max = (int)   ( $settings['offset_max'] ?? 45 );

		if ( empty( $days ) ) {
			return null;
		}

		$tz            = wp_timezone();
		$now           = new DateTime( 'now', $tz );
		$random_offset = ( $offset_max > 0 ) ? rand( -$offset_max, $offset_max ) : 0;

		// Look forward up to 8 days.
		for ( $i = 0; $i <= 8; $i++ ) {
			$candidate = clone $now;
			if ( $i > 0 ) {
				$candidate->modify( "+{$i} day" );
			}

			// PHP date('w'): 0 = Sunday, 1 = Monday, …, 6 = Saturday.
			$dow = (int) $candidate->format( 'w' );
			if ( ! in_array( $dow, $days, true ) ) {
				continue;
			}

			$candidate->setTime( $hour, $minute, 0 );

			// Apply random offset.
			if ( $random_offset !== 0 ) {
				$abs = abs( $random_offset );
				$candidate->modify( ( $random_offset > 0 ? '+' : '-' ) . "{$abs} minutes" );
			}

			if ( $candidate > $now ) {
				return $candidate->getTimestamp();
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	public static function get_settings() {
		$defaults = array(
			'enabled'    => false,
			'hour'       => 9,
			'minute'     => 0,
			'days'       => array( 1, 2, 3, 4, 5 ), // Mon–Fri
			'offset_max' => 45,
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	public static function save_settings( $enabled, $hour, $minute, $days = array(), $offset_max = 45 ) {
		$days = array_values( array_unique( array_filter(
			array_map( 'intval', (array) $days ),
			function( $d ) { return $d >= 0 && $d <= 6; }
		) ) );
		sort( $days );

		update_option( self::OPTION_KEY, array(
			'enabled'    => (bool) $enabled,
			'hour'       => max( 0, min( 23, (int) $hour ) ),
			'minute'     => max( 0, min( 59, (int) $minute ) ),
			'days'       => $days ?: array( 1, 2, 3, 4, 5 ),
			'offset_max' => max( 0, min( 120, (int) $offset_max ) ),
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
