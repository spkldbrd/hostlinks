<?php
/**
 * CVENT Sync Scheduler
 *
 * Manages a WordPress cron job that runs full or incremental CVENT syncs.
 *
 * Uses wp_schedule_single_event() rather than a repeating interval so that:
 *   - Day-of-week filtering is respected (skip weekends, etc.).
 *   - A random ±offset is applied to each run, making the schedule appear
 *     less robotic.
 *   - Up to 5 base times per day are supported — after each run, the next
 *     base slot is scheduled.
 *   - Each slot is mode full|incremental (full = sync_all; incremental =
 *     date-window attendees → sync only touched linked events).
 *
 * Option shape ('hostlinks_cvent_schedule'):
 *   enabled    bool
 *   times      array    Up to 5 {hour, minute, mode} slots (site timezone).
 *   hour/minute int     Legacy single time (migrated into times[]).
 *   days       int[]    Days of week to run: 0=Sun…6=Sat. Default [1-5].
 *   offset_max int      Max random ± offset in minutes. Default 45.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_CVENT_Scheduler {

	const HOOK          = 'hostlinks_cvent_daily_sync';
	const OPTION_KEY    = 'hostlinks_cvent_schedule';
	const LOG_KEY       = 'hostlinks_cvent_schedule_log';
	const HASH_KEY      = 'hostlinks_cvent_schedule_hash';
	const WATERMARK_KEY = 'hostlinks_cvent_incr_watermark';
	const MAX_TIMES     = 5;
	const API_DAILY_LIMIT = 1000;
	const INCR_MAX_LOOKBACK_HOURS = 48;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_reschedule' ) );
	}

	// -------------------------------------------------------------------------
	// Run the sync (cron callback)
	// -------------------------------------------------------------------------

	/**
	 * @param string $slot Slot key that was scheduled, e.g. "10:00" (from cron args).
	 */
	public static function run( $slot = '' ) {
		if ( ! class_exists( 'Hostlinks_CVENT_Sync' ) ) {
			return;
		}

		update_option( 'hostlinks_cvent_last_auto_run', current_time( 'mysql' ), false );

		$settings   = self::get_settings();
		$slot_key   = is_string( $slot ) ? $slot : '';
		$mode       = self::mode_for_slot( $settings, $slot_key );
		$before_utc = gmdate( 'Y-m-d\TH:i:s\Z' );

		$calls_before = class_exists( 'Hostlinks_CVENT_API', false )
			? Hostlinks_CVENT_API::get_call_count_today()
			: 0;

		if ( $mode === 'incremental' ) {
			$after_utc = self::incremental_after_utc( $before_utc );
			$result    = Hostlinks_CVENT_Sync::sync_incremental( $after_utc, $before_utc, false );
		} else {
			$result = Hostlinks_CVENT_Sync::sync_all( false );
			$result['mode'] = 'full';
		}

		$calls_after = class_exists( 'Hostlinks_CVENT_API', false )
			? Hostlinks_CVENT_API::get_call_count_today()
			: $calls_before;

		if ( class_exists( 'Hostlinks_Toolbar', false ) ) {
			Hostlinks_Toolbar::touch_last_updated( $mode === 'incremental' ? 'incremental' : 'full' );
		} elseif ( $mode !== 'incremental' ) {
			update_option( 'last_data_updation', current_time( 'mysql' ) );
		}

		// Advance watermark after both modes so the next incremental window is tight.
		self::set_watermark( $before_utc );

		$api_calls = max( 0, $calls_after - $calls_before );
		$log       = array(
			'time'               => current_time( 'mysql' ),
			'mode'               => $mode,
			'slot'               => $slot_key,
			'synced'             => $result['synced']        ?? 0,
			'matched'            => $result['matched']       ?? 0,
			'needs_review'       => $result['needs_review']  ?? 0,
			'no_candidates'      => $result['no_candidates'] ?? 0,
			'errors'             => $result['errors']        ?? 0,
			'total_events'       => count( $result['results'] ?? array() ),
			'api_calls'          => $api_calls,
			'discovered_events'  => $result['discovered_events'] ?? null,
			'linked_events'      => $result['linked_events'] ?? null,
			'skipped_unlinked'   => $result['skipped_unlinked'] ?? null,
			'attendee_rows'      => $result['attendee_rows'] ?? null,
		);
		update_option( self::LOG_KEY, $log, false );

		if ( class_exists( 'Hostlinks_CVENT_Sync_Log', false ) ) {
			Hostlinks_CVENT_Sync_Log::record(
				$result,
				array(
					'source'       => 'auto',
					'mode'         => $mode,
					'slot'         => $slot_key,
					'api_calls'    => $api_calls,
					'triggered_by' => 0,
				)
			);
		}

		if ( $settings['enabled'] ) {
			$after_base = self::floor_after_completed_run( $slot_key, $settings );
			$planned    = self::plan_next_run( $settings, $after_base );
			if ( $planned ) {
				self::schedule_event( $planned['timestamp'], $planned['slot'] );
				update_option( self::HASH_KEY, md5( serialize( $settings ) ) );
			}
		}
	}

	/**
	 * UTC after bound for incremental: watermark, else now−24h, capped at 48h lookback.
	 */
	public static function incremental_after_utc( string $before_utc ): string {
		$wm = self::get_watermark();
		if ( is_string( $wm ) && preg_match( '/^\d{4}-\d{2}-\d{2}T/', $wm ) ) {
			$after = $wm;
		} else {
			$after = gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS );
		}

		$min_after = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( self::INCR_MAX_LOOKBACK_HOURS * HOUR_IN_SECONDS ) );
		if ( strcmp( $after, $min_after ) < 0 ) {
			$after = $min_after;
		}
		if ( strcmp( $after, $before_utc ) > 0 ) {
			$after = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );
		}
		return $after;
	}

	public static function get_watermark(): string {
		return (string) get_option( self::WATERMARK_KEY, '' );
	}

	public static function set_watermark( string $utc_iso ): void {
		update_option( self::WATERMARK_KEY, $utc_iso, false );
	}

	// -------------------------------------------------------------------------
	// Schedule management
	// -------------------------------------------------------------------------

	public static function maybe_reschedule() {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] ) {
			self::clear();
			delete_option( self::HASH_KEY );
			return;
		}

		$settings_hash = md5( serialize( $settings ) );
		$stored_hash   = get_option( self::HASH_KEY, '' );
		$next          = self::next_scheduled_ts();

		$needs = false;
		if ( ! $next ) {
			$needs = true;
		} elseif ( $settings_hash !== $stored_hash ) {
			$needs = true;
		} elseif ( $next < time() ) {
			$needs = true;
		}

		if ( $needs ) {
			self::clear();
			$planned = self::plan_next_run( $settings, new DateTime( 'now', wp_timezone() ) );
			if ( $planned ) {
				self::schedule_event( $planned['timestamp'], $planned['slot'] );
				update_option( self::HASH_KEY, $settings_hash );
			}
		}
	}

	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	private static function schedule_event( int $timestamp, string $slot ): void {
		wp_schedule_single_event( $timestamp, self::HOOK, array( $slot ) );
		update_option( 'hostlinks_cvent_next_scheduled_run', gmdate( 'Y-m-d H:i:s', $timestamp ), false );
	}

	private static function next_scheduled_ts(): int {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return 0;
		}
		foreach ( $crons as $ts => $hooks ) {
			if ( isset( $hooks[ self::HOOK ] ) ) {
				return (int) $ts;
			}
		}
		return 0;
	}

	private static function base_datetime_from_slot( string $slot ): ?DateTime {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $slot, $m ) ) {
			return null;
		}
		$dt = new DateTime( 'now', wp_timezone() );
		$dt->setTime( (int) $m[1], (int) $m[2], 0 );
		return $dt;
	}

	private static function floor_after_completed_run( string $slot, array $settings ): DateTime {
		$from_slot = self::base_datetime_from_slot( $slot );
		if ( $from_slot instanceof DateTime ) {
			return $from_slot;
		}
		$floor = new DateTime( 'now', wp_timezone() );
		$om    = (int) ( $settings['offset_max'] ?? 45 );
		if ( $om > 0 ) {
			$floor->modify( "+{$om} minutes" );
		}
		return $floor;
	}

	/**
	 * @param array         $settings
	 * @param DateTime|null $after_base
	 * @return array{timestamp:int,slot:string}|null
	 */
	private static function plan_next_run( array $settings, $after_base = null ) {
		$days       = (array) ( $settings['days'] ?? array( 1, 2, 3, 4, 5 ) );
		$times      = self::normalize_times( $settings );
		$offset_max = (int) ( $settings['offset_max'] ?? 45 );

		if ( empty( $days ) || empty( $times ) ) {
			return null;
		}

		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );

		if ( $after_base instanceof DateTime ) {
			$floor = clone $after_base;
			$floor->setTimezone( $tz );
		} else {
			$floor = clone $now;
		}

		$best_base = null;
		$best_slot = null;

		for ( $i = 0; $i <= 8; $i++ ) {
			$day = clone $now;
			if ( $i > 0 ) {
				$day->modify( "+{$i} day" );
			}
			$day->setTime( 0, 0, 0 );

			$dow = (int) $day->format( 'w' );
			if ( ! in_array( $dow, $days, true ) ) {
				continue;
			}

			foreach ( $times as $slot ) {
				$base = clone $day;
				$base->setTime( (int) $slot['hour'], (int) $slot['minute'], 0 );
				$slot_key = sprintf( '%02d:%02d', (int) $slot['hour'], (int) $slot['minute'] );

				if ( $base <= $floor ) {
					continue;
				}

				if ( null === $best_base || $base < $best_base ) {
					$best_base = $base;
					$best_slot = $slot_key;
				}
			}
		}

		if ( ! $best_base || ! $best_slot ) {
			return null;
		}

		$candidate = clone $best_base;
		if ( $offset_max > 0 ) {
			$offset = wp_rand( -$offset_max, $offset_max );
			if ( $offset !== 0 ) {
				$abs = abs( $offset );
				$candidate->modify( ( $offset > 0 ? '+' : '-' ) . "{$abs} minutes" );
			}
		}

		if ( $candidate <= $now ) {
			$candidate = clone $now;
			$candidate->modify( '+1 minute' );
		}

		return array(
			'timestamp' => $candidate->getTimestamp(),
			'slot'      => $best_slot,
		);
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	/**
	 * Recommended daytime schedule: 10 full, then noon/2/4/6 incremental.
	 *
	 * @return array<int,array{hour:int,minute:int,mode:string}>
	 */
	public static function recommended_times(): array {
		return array(
			array( 'hour' => 10, 'minute' => 0, 'mode' => 'full' ),
			array( 'hour' => 12, 'minute' => 0, 'mode' => 'incremental' ),
			array( 'hour' => 14, 'minute' => 0, 'mode' => 'incremental' ),
			array( 'hour' => 16, 'minute' => 0, 'mode' => 'incremental' ),
			array( 'hour' => 18, 'minute' => 0, 'mode' => 'incremental' ),
		);
	}

	/**
	 * @param array $settings
	 * @return array<int,array{hour:int,minute:int,mode:string}>
	 */
	public static function normalize_times( array $settings ): array {
		$raw = $settings['times'] ?? null;
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = array(
				array(
					'hour'   => (int) ( $settings['hour'] ?? 9 ),
					'minute' => (int) ( $settings['minute'] ?? 0 ),
					'mode'   => 'full',
				),
			);
		}

		$out = array();
		foreach ( $raw as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$h    = max( 0, min( 23, (int) ( $t['hour'] ?? 0 ) ) );
			$m    = max( 0, min( 59, (int) ( $t['minute'] ?? 0 ) ) );
			$mode = ( ( $t['mode'] ?? '' ) === 'full' ) ? 'full' : 'incremental';
			$key  = sprintf( '%02d:%02d', $h, $m );
			$out[ $key ] = array(
				'hour'   => $h,
				'minute' => $m,
				'mode'   => $mode,
			);
			if ( count( $out ) >= self::MAX_TIMES ) {
				break;
			}
		}

		if ( empty( $out ) ) {
			$out['10:00'] = array( 'hour' => 10, 'minute' => 0, 'mode' => 'full' );
		}

		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Resolve mode for a scheduled slot key (e.g. "14:00").
	 */
	public static function mode_for_slot( array $settings, string $slot_key ): string {
		$times = self::normalize_times( $settings );
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $slot_key, $m ) ) {
			$want = sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
			foreach ( $times as $t ) {
				$key = sprintf( '%02d:%02d', (int) $t['hour'], (int) $t['minute'] );
				if ( $key === $want ) {
					return ( $t['mode'] ?? '' ) === 'full' ? 'full' : 'incremental';
				}
			}
		}
		// Legacy cron with no slot args — treat as full for safety.
		return 'full';
	}

	public static function get_settings() {
		$defaults = array(
			'enabled'    => false,
			'times'      => array( array( 'hour' => 10, 'minute' => 0, 'mode' => 'full' ) ),
			'hour'       => 10,
			'minute'     => 0,
			'days'       => array( 1, 2, 3, 4, 5 ),
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
	 * @param array $times
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

	public static function next_run_display() {
		$ts = self::next_scheduled_ts();
		if ( ! $ts ) {
			return null;
		}
		$tz = wp_timezone();
		$dt = new DateTime( '@' . $ts );
		$dt->setTimezone( $tz );
		return $dt->format( 'D, M j Y \a\t g:i a T' );
	}

	/**
	 * Estimate API usage for mixed full + incremental schedules.
	 */
	public static function api_usage_estimate(): array {
		$settings = self::get_settings();
		$times    = self::normalize_times( $settings );
		$n_full   = 0;
		$n_incr   = 0;
		if ( $settings['enabled'] ) {
			foreach ( $times as $t ) {
				if ( ( $t['mode'] ?? '' ) === 'full' ) {
					$n_full++;
				} else {
					$n_incr++;
				}
			}
		}
		$times_per_day = $n_full + $n_incr;
		$calls_today   = class_exists( 'Hostlinks_CVENT_API', false )
			? Hostlinks_CVENT_API::get_call_count_today()
			: 0;
		$log = self::get_last_log();

		$full_cost = null;
		$incr_cost = null;
		if ( is_array( $log ) && isset( $log['api_calls'] ) && (int) $log['api_calls'] > 0 ) {
			if ( ( $log['mode'] ?? '' ) === 'incremental' ) {
				$incr_cost = (int) $log['api_calls'];
			} else {
				$full_cost = (int) $log['api_calls'];
			}
		}

		$event_count = 0;
		if ( null === $full_cost ) {
			global $wpdb;
			$tbl    = $wpdb->prefix . 'event_details_list';
			$cutoff = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
			$event_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$tbl}` WHERE eve_status = 1 AND eve_end >= %s",
					$cutoff
				)
			);
			$full_cost = max( 10, 2 + ( $event_count * 2 ) );
		}
		if ( null === $incr_cost ) {
			$linked = ( is_array( $log ) && isset( $log['linked_events'] ) )
				? (int) $log['linked_events']
				: 15;
			$incr_cost = max( 3, 2 + $linked );
		}

		$estimated_daily = ( $n_full * $full_cost ) + ( $n_incr * $incr_cost );
		$remaining       = max( 0, self::API_DAILY_LIMIT - $calls_today );
		$blended         = $times_per_day > 0 ? (int) round( $estimated_daily / $times_per_day ) : $full_cost;

		$measured = ( isset( $log['api_calls'] ) && (int) $log['api_calls'] > 0 )
			? (int) $log['api_calls']
			: null;

		return array(
			'calls_today'         => $calls_today,
			'daily_limit'         => self::API_DAILY_LIMIT,
			'last_sync_calls'     => $measured,
			'times_per_day'       => $times_per_day,
			'full_slots'          => $n_full,
			'incremental_slots'   => $n_incr,
			'estimated_per_full'  => $full_cost,
			'estimated_per_incr'  => $incr_cost,
			'estimated_per_sync'  => $blended,
			'estimated_daily'     => $estimated_daily,
			'remaining_today'     => $remaining,
			'over_limit'          => $estimated_daily > self::API_DAILY_LIMIT,
			'syncable_events'     => $event_count,
		);
	}
}
