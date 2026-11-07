<?php
/**
 * Ring buffer of recent CVENT sync runs (last 24) for the Sync Log UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_CVENT_Sync_Log {

	const OPTION_KEY = 'hostlinks_cvent_sync_history';
	const MAX_ENTRIES = 24;

	/**
	 * Append a trimmed run summary. Skips dry-runs.
	 *
	 * @param array  $report Sync report from sync_all / sync_incremental / sync_one_or_family.
	 * @param array  $meta   Optional: source, mode, slot, api_calls, triggered_by.
	 */
	/**
	 * @return string|null New entry id, or null if skipped (dry-run).
	 */
	public static function record( array $report, array $meta = array() ): ?string {
		if ( ! empty( $report['dry_run'] ) ) {
			return null;
		}

		$events = self::trim_results( $report['results'] ?? array() );
		$id     = self::new_id();

		$entry = array(
			'id'                 => $id,
			'time'               => current_time( 'mysql' ),
			'source'             => sanitize_key( $meta['source'] ?? 'manual' ), // auto|manual|incremental|single
			'mode'               => sanitize_key( $meta['mode'] ?? ( $report['mode'] ?? 'full' ) ),
			'slot'               => isset( $meta['slot'] ) ? (string) $meta['slot'] : '',
			'triggered_by'      => (int) ( $meta['triggered_by'] ?? get_current_user_id() ),
			'api_calls'          => isset( $meta['api_calls'] ) ? (int) $meta['api_calls'] : null,
			'synced'             => (int) ( $report['synced'] ?? 0 ),
			'matched'            => (int) ( $report['matched'] ?? 0 ),
			'needs_review'       => (int) ( $report['needs_review'] ?? 0 ),
			'no_candidates'      => (int) ( $report['no_candidates'] ?? 0 ),
			'errors'             => (int) ( $report['errors'] ?? 0 ),
			'discovered_events'  => isset( $report['discovered_events'] ) ? (int) $report['discovered_events'] : null,
			'linked_events'      => isset( $report['linked_events'] ) ? (int) $report['linked_events'] : null,
			'attendee_rows'      => isset( $report['attendee_rows'] ) ? (int) $report['attendee_rows'] : null,
			'events'             => $events,
			'changed_count'      => self::count_changed( $events ),
		);

		$history = self::get_all();
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );
		update_option( self::OPTION_KEY, $history, false );
		return $id;
	}

	/**
	 * @return array<int,array>
	 */
	public static function get_all(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				return $entry;
			}
		}
		return null;
	}

	private static function new_id(): string {
		return substr( md5( uniqid( (string) microtime( true ), true ) ), 0, 12 );
	}

	/**
	 * @param array $results
	 * @return array<int,array>
	 */
	private static function trim_results( array $results ): array {
		$labels = self::event_labels( $results );
		$out    = array();

		foreach ( $results as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$eve_id = (int) ( $r['eve_id'] ?? 0 );
			$paid   = array_key_exists( 'paid', $r ) ? $r['paid'] : null;
			$free   = array_key_exists( 'free', $r ) ? $r['free'] : null;
			$hl_p   = array_key_exists( 'hl_paid', $r ) ? $r['hl_paid'] : null;
			$hl_f   = array_key_exists( 'hl_free', $r ) ? $r['hl_free'] : null;

			$dp = ( null !== $paid && null !== $hl_p ) ? ( (int) $paid - (int) $hl_p ) : null;
			$df = ( null !== $free && null !== $hl_f ) ? ( (int) $free - (int) $hl_f ) : null;

			$out[] = array(
				'eve_id'      => $eve_id,
				'label'       => $labels[ $eve_id ] ?? ( 'Event #' . $eve_id ),
				'action'      => (string) ( $r['action'] ?? '' ),
				'message'     => self::truncate( (string) ( $r['message'] ?? '' ), 200 ),
				'paid'        => null !== $paid ? (int) $paid : null,
				'free'        => null !== $free ? (int) $free : null,
				'hl_paid'     => null !== $hl_p ? (int) $hl_p : null,
				'hl_free'     => null !== $hl_f ? (int) $hl_f : null,
				'delta_paid'  => $dp,
				'delta_free'  => $df,
				'changed'     => ( null !== $dp && null !== $df ) ? ( $dp !== 0 || $df !== 0 ) : false,
				'cvent_id'    => (string) ( $r['cvent_id'] ?? '' ),
				'cvent_title' => self::truncate( (string) ( $r['cvent_title'] ?? '' ), 120 ),
			);
		}

		return $out;
	}

	/**
	 * @param array $results
	 * @return array<int,string>
	 */
	private static function event_labels( array $results ): array {
		$ids = array();
		foreach ( $results as $r ) {
			if ( is_array( $r ) && ! empty( $r['eve_id'] ) ) {
				$ids[] = (int) $r['eve_id'];
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;
		$tbl  = $wpdb->prefix . 'event_details_list';
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results(
			"SELECT eve_id, eve_location, cvent_event_title FROM `{$tbl}` WHERE eve_id IN ({$in})",
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$id    = (int) $row['eve_id'];
			$label = trim( (string) ( $row['eve_location'] ?? '' ) );
			if ( $label === '' ) {
				$label = trim( (string) ( $row['cvent_event_title'] ?? '' ) );
			}
			$map[ $id ] = $label !== '' ? $label : ( 'Event #' . $id );
		}
		return $map;
	}

	private static function count_changed( array $events ): int {
		$n = 0;
		foreach ( $events as $e ) {
			if ( ! empty( $e['changed'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function truncate( string $s, int $max ): string {
		$s = trim( $s );
		if ( strlen( $s ) <= $max ) {
			return $s;
		}
		return substr( $s, 0, $max - 1 ) . '…';
	}
}
