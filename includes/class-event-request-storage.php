<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hostlinks_Event_Request_Storage
 *
 * Handles all database operations for the event request intake table.
 */
class Hostlinks_Event_Request_Storage {

	/** @return string  Full table name including WP prefix. */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hostlinks_event_requests';
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Insert a new event request.
	 *
	 * @param array $data  Clean, normalized row from Hostlinks_Event_Request::normalize().
	 * @return int|false   Inserted row ID on success, false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$result = $wpdb->insert( self::table(), $data );
		return $result !== false ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update the status of a request.
	 *
	 * @param int    $id      Row ID.
	 * @param string $status  One of Hostlinks_Event_Request::STATUSES keys.
	 * @return bool
	 */
	public static function update_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! array_key_exists( $status, Hostlinks_Event_Request::STATUSES ) ) {
			return false;
		}
		$result = $wpdb->update(
			self::table(),
			array(
				'request_status' => $status,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $result !== false;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch a paginated list of event requests.
	 *
	 * @param string $status_filter  '' = all, or a specific status key.
	 * @param int    $per_page
	 * @param int    $page           1-based page number.
	 * @return array { rows: array, total: int }
	 */
	public static function get_list( string $status_filter = '', int $per_page = 25, int $page = 1 ): array {
		global $wpdb;
		$table  = self::table();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		if ( $status_filter !== '' && array_key_exists( $status_filter, Hostlinks_Event_Request::STATUSES ) ) {
			$where = $wpdb->prepare( 'WHERE request_status = %s', $status_filter );
		} else {
			$where = '';
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, request_status, submitted_at, event_title, category, format, city, state, start_date, end_date, trainer, marketer
				 FROM {$table} {$where}
				 ORDER BY submitted_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'rows'  => $rows  ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Fetch a single event request by ID with all fields.
	 *
	 * @param int $id
	 * @return array|null  Associative row array, or null if not found.
	 */
	public static function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		// Decode JSON columns for convenience.
		$row['cc_emails']    = json_decode( $row['cc_emails'],    true ) ?: array();
		$row['hotels']       = json_decode( $row['hotels'],       true ) ?: array();
		$row['host_contacts']= json_decode( $row['host_contacts'],true ) ?: array();
		return $row;
	}

	/**
	 * Count requests by status. Returns array [ status => count ].
	 *
	 * @return array
	 */
	public static function count_by_status(): array {
		global $wpdb;
		$results = $wpdb->get_results(
			'SELECT request_status, COUNT(*) AS cnt FROM ' . self::table() . ' GROUP BY request_status',
			ARRAY_A
		);
		$counts = array();
		foreach ( (array) $results as $r ) {
			$counts[ $r['request_status'] ] = (int) $r['cnt'];
		}
		return $counts;
	}
}
