<?php
/**
 * CVENT Sync Scheduler
 *
 * Manages a WordPress cron job that runs Hostlinks_CVENT_Sync::sync_all().
 *
 * Uses wp_schedule_single_event() rather than a repeating interval so that:
 *   - Day-of-week filtering is respected (skip weekends, etc.).
 *   - A random ±offset is applied to each run, making the schedule appear
 *     less robotic.
 *   - Up to 3 base times per day are supported.
 *
 * After each run, the next single event is scheduled immediately with a fresh
 * random offset. maybe_reschedule() runs on admin_init to self-heal if the
 * cron is missing or settings have changed.
 *
 * Option shape ('hostlinks_cvent_schedule'):
 *   enabled    bool     Whether the scheduler is active.
 *   times      array    Up to 3 {hour, minute} slots (site timezone).
 *   hour/minute int     Legacy single time (migrated into times[]).
 *   days       int[]    Days of week to run: 0=Sun…6=Sat. Default [1-5].
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
	const MAX_TIMES  = 3;
	const API_DAILY_LIMIT = 1000;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
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

		$calls_before = class_exists( 'Hostlinks_CVENT_API', false )
			? Hostlinks_CVENT_API::get_call_count_today()
			: 0;

		$result = Hostlinks_CVENT_Sync::sync_all( false ); // live run

		$calls_after = class_exists( 'Hostlinks_CVENT_API', false )
			? Hostlinks_CVENT_API::get_call_count_today()
			: $calls_before;

		// Stamp toolbar "Updated:" after every completed auto sync (date + time).
		if ( class_exists( 'Hostlinks_Toolbar', false ) ) {
			Hostlinks_Toolbar::touch_last_updated();
		} else {
			update_option( 'last_data_updation', current_time( 'mysql' ) );
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
			'api_calls'     => max( 0, $calls_after - $calls_before ),
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
	 * Called on admin_init. Self-heals the schedule if:
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
	 * Calculate the Unix timestamp for the next valid run among configured times.
	 *
	 * @param array $settings Result of get_settings().
	 * @return int|null Unix timestamp, or null if no valid day found.
	 */
	private static function next_run_timestamp( $settings ) {
		$days       = (array) ( $settings['days'] ?? array( 1, 2, 3, 4, 5 ) );
		$times      = self::normalize_times( $settings );
		$offset_max = (int) ( $settings['offset_max'] ?? 45 );

		if ( empty( $days ) || empty( $times ) ) {
			return null;
		}

		$tz            = wp_timezone();
		$now           = new DateTime( 'now', $tz );
		$random_offset = ( $offset_max > 0 ) ? wp_rand( -$offset_max, $offset_max ) : 0;
		$best          = null;

		// Look forward up to 8 days.
		for ( $i = 0; $i <= 8; $i++ ) {
			$day = clone $now;
			if ( $i > 0 ) {
				$day->modify( "+{$i} day" );
			}

			// PHP date('w'): 0 = Sunday … 6 = Saturday.
			$dow = (int) $day->format( 'w' );
			if ( ! in_array( $dow, $days, true ) ) {
				continue;
			}

			foreach ( $times as $slot ) {
				$candidate = clone $day;
				$candidate->setTime( (int) $slot['hour'], (int) $slot['minute'], 0 );

				if ( $random_offset !== 0 ) {
					$abs = abs( $random_offset );
					$candidate->modify( ( $random_offset > 0 ? '+' : '-' ) . "{$abs} minutes" );
				}

				if ( $candidate > $now && ( null === $best || $candidate < $best ) ) {
					$best = $candidate;
				}
			}
		}

		return $best ? $best->getTimestamp() : null;
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize up to MAX_TIMES unique hour/minute slots (migrate legacy hour/minute).
	 *
	 * @param array $settings
	 * @return array<int,array{hour:int,minute:int}>
	 */
	public static function normalize_times( array $settings ): array {
		$raw = $settings['times'] ?? null;
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = array(
				array(
					'hour'   => (int) ( $settings['hour'] ?? 9 ),
					'minute' => (int) ( $settings['minute'] ?? 0 ),
				),
			);
		}

		$out = array();
		foreach ( $raw as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$h   = max( 0, min( 23, (int) ( $t['hour'] ?? 0 ) ) );
			$m   = max( 0, min( 59, (int) ( $t['minute'] ?? 0 ) ) );
			$key = sprintf( '%02d:%02d', $h, $m );
			$out[ $key ] = array(
				'hour'   => $h,
				'minute' => $m,
			);
			if ( count( $out ) >= self::MAX_TIMES ) {
				break;
			}
		}

		if ( empty( $out ) ) {
			$out['09:00'] = array( 'hour' => 9, 'minute' => 0 );
		}

		ksort( $out );
		return array_values( $out );
	}

	public static function get_settings() {
		$defaults = array(
			'enabled'    => false,
			'times'      => array( array( 'hour' => 9, 'minute' => 0 ) ),
			'hour'       => 9, // legacy
			'minute'     => 0, // legacy
			'days'       => array( 1, 2, 3, 4, 5 ), // Mon–Fri
			'offset_max' => 45,
		);
		$saved              = get_option( self::OPTION_KEY, array() );
		$merged             = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		$merged['times']    = self::normalize_times( $merged );
		$merged['hour']     = $merged['times'][0]['hour'];
		$merged['minute']   = $merged['times'][0]['minute'];
		return $merged;
	}

	/**
	 * @param bool  $enabled
	 * @param array $times      Up to 3 {hour, minute} entries (or legacy hour/minute via save_settings_legacy).
	 * @param array $days
	 * @param int   $offset_max
	 */
	public static function save_settings( $enabled, $times, $days = array(), $offset_max = 45 ) {
		$days = array_values( array_unique( array_filter(
			array_map( 'intval', (array) $days ),
			function( $d ) {
				return $d >= 0 && $d <= 6;
			}
		) ) );
		sort( $days );

		$normalized = self::normalize_times( array( 'times' => (array) $times ) );

		update_option(
			self::OPTION_KEY,
			array(
				'enabled'    => (bool) $enabled,
				'times'      => $normalized,
				'hour'       => $normalized[0]['hour'],
				'minute'     => $normalized[0]['minute'],
				'days'       => $days ?: array( 1, 2, 3, 4, 5 ),
				'offset_max' => max( 0, min( 120, (int) $offset_max ) ),
			)
		);
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

	/**
	 * Estimate API usage for the configured schedule.
	 *
	 * @return array{
	 *   calls_today:int,
	 *   daily_limit:int,
	 *   last_sync_calls:int|null,
	 *   times_per_day:int,
	 *   estimated_per_sync:int,
	 *   estimated_daily:int,
	 *   remaining_today:int,
	 *   over_limit:bool
	 * }
	 */
	public static function api_usage_estimate(): array {
		$settings      = self::get_settings();
		$times         = self::normalize_times( $settings );
		$times_per_day = $settings['enabled'] ? count( $times ) : 0;
		$calls_today   = class_exists( 'Hostlinks_CVENT_API', false )
			? Hostlinks_CVENT_API::get_call_count_today()
			: 0;
		$log           = self::get_last_log();
		$last_calls    = ( isset( $log['api_calls'] ) && (int) $log['api_calls'] > 0 )
			? (int) $log['api_calls']
			: null;

		// Fallback estimate when we have not measured a sync yet: ~2 calls/event + overhead.
		$event_count = 0;
		if ( null === $last_calls ) {
			global $wpdb;
			$tbl    = $wpdb->prefix . 'event_details_list';
			$cutoff = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
			$event_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$tbl}` WHERE eve_status = 1 AND eve_end >= %s",
					$cutoff
				)
			);
			$last_calls = max( 10, 2 + ( $event_count * 2 ) );
		}

		$estimated_daily = $times_per_day * $last_calls;
		$remaining       = max( 0, self::API_DAILY_LIMIT - $calls_today );

		$measured = ( isset( $log['api_calls'] ) && (int) $log['api_calls'] > 0 )
			? (int) $log['api_calls']
			: null;

		return array(
			'calls_today'         => $calls_today,
			'daily_limit'         => self::API_DAILY_LIMIT,
			'last_sync_calls'     => $measured,
			'times_per_day'       => $times_per_day,
			'estimated_per_sync'  => $last_calls,
			'estimated_daily'     => $estimated_daily,
			'remaining_today'     => $remaining,
			'over_limit'          => $estimated_daily > self::API_DAILY_LIMIT,
			'syncable_events'     => $event_count,
		);
	}
}
