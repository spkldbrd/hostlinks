<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Import_Export {

	public function __construct() {
		add_action( 'admin_post_hostlinks_export_json', array( $this, 'handle_export_json' ) );
		add_action( 'admin_post_hostlinks_export_csv',  array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_hostlinks_import',      array( $this, 'handle_import' ) );
	}

	// ─── Export ────────────────────────────────────────────────────────────────

	public function handle_export_json() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
		check_admin_referer( 'hostlinks_export' );

		global $wpdb;
		$data = array(
			'exported_at'      => current_time('mysql'),
			// Events: only active (eve_status=1) — soft-deleted events should not migrate.
			'event_details'    => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_details_list ORDER BY eve_start ASC", ARRAY_A ),
			// Reference tables: export ALL records including inactive so that historical
			// events referencing inactive marketers/instructors/types keep valid foreign keys.
			'event_types'      => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_type ORDER BY event_type_id ASC", ARRAY_A ),
			'event_marketers'  => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_marketer ORDER BY event_marketer_id ASC", ARRAY_A ),
			'event_instructors'=> $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_instructor ORDER BY event_instructor_id ASC", ARRAY_A ),
		);

		$filename = 'hostlinks-export-' . date('Y-m-d') . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
		check_admin_referer( 'hostlinks_export' );

		global $wpdb;
		$events   = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_details_list WHERE eve_status = 1 ORDER BY eve_start ASC", ARRAY_A );
		$filename = 'hostlinks-events-' . date('Y-m-d') . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		if ( ! empty( $events ) ) {
			fputcsv( $out, array_keys( $events[0] ) );
			foreach ( $events as $row ) {
				fputcsv( $out, $row );
			}
		}
		fclose( $out );
		exit;
	}

	// ─── Import ────────────────────────────────────────────────────────────────

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
		check_admin_referer( 'hostlinks_import' );

		$redirect = admin_url( 'admin.php?page=hostlinks-import-export' );

		if ( empty( $_FILES['hostlinks_import_file']['tmp_name'] ) ) {
			wp_redirect( add_query_arg( 'hl_msg', 'no_file', $redirect ) );
			exit;
		}

		$file     = $_FILES['hostlinks_import_file'];
		$ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$tmp      = $file['tmp_name'];

		if ( $ext === 'json' ) {
			$this->import_json( $tmp, $redirect );
		} elseif ( $ext === 'csv' ) {
			$this->import_csv( $tmp, $redirect );
		} else {
			wp_redirect( add_query_arg( 'hl_msg', 'bad_type', $redirect ) );
			exit;
		}
	}

	private function import_json( $tmp, $redirect ) {
		global $wpdb;
		$raw  = file_get_contents( $tmp );
		$data = json_decode( $raw, true );

		if ( ! $data || ! is_array( $data ) ) {
			wp_redirect( add_query_arg( 'hl_msg', 'bad_json', $redirect ) );
			exit;
		}

		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();

		// ── Helper: record a failed insert ────────────────────────────────────
		$record_error = function( $context ) use ( $wpdb, &$failed, &$errors ) {
			$failed++;
			if ( count( $errors ) < 3 && $wpdb->last_error ) {
				$errors[] = "[{$context}] " . $wpdb->last_error;
			}
		};

		// ── Event types ───────────────────────────────────────────────────────
		// Use INSERT IGNORE with the original primary key so that events
		// imported later still reference the correct type ID.
		if ( ! empty( $data['event_types'] ) ) {
			foreach ( $data['event_types'] as $row ) {
				$id     = (int) ( $row['event_type_id'] ?? 0 );
				$name   = $row['event_type_name'] ?? '';
				$color  = $row['event_type_color'] ?? '';
				$status = (int) ( $row['event_type_status'] ?? 1 );

				// Check by ID first; fall back to name for exports from older versions.
				if ( $id ) {
					$exists_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}event_type WHERE event_type_id = %d", $id
					) );
					if ( $exists_id ) { $skipped++; continue; }
				} else {
					$exists_name = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}event_type WHERE event_type_name = %s", $name
					) );
					if ( $exists_name ) { $skipped++; continue; }
				}

				$sql = $id
					? $wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}event_type (event_type_id, event_type_name, event_type_color, event_type_status) VALUES (%d, %s, %s, %d)",
						$id, $name, $color, $status
					)
					: $wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}event_type (event_type_name, event_type_color, event_type_status) VALUES (%s, %s, %d)",
						$name, $color, $status
					);

				$result = $wpdb->query( $sql );
				if ( false === $result ) {
					$record_error( 'event_type:' . $name );
				} else {
					$imported++;
				}
			}
		}

		// ── Marketers ─────────────────────────────────────────────────────────
		if ( ! empty( $data['event_marketers'] ) ) {
			foreach ( $data['event_marketers'] as $row ) {
				$id     = (int) ( $row['event_marketer_id'] ?? 0 );
				$name   = $row['event_marketer_name'] ?? '';
				$status = (int) ( $row['event_marketer_status'] ?? 1 );

				if ( $id ) {
					$exists_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}event_marketer WHERE event_marketer_id = %d", $id
					) );
					if ( $exists_id ) { $skipped++; continue; }
				} else {
					$exists_name = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}event_marketer WHERE event_marketer_name = %s", $name
					) );
					if ( $exists_name ) { $skipped++; continue; }
				}

				$sql = $id
					? $wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}event_marketer (event_marketer_id, event_marketer_name, event_marketer_status) VALUES (%d, %s, %d)",
						$id, $name, $status
					)
					: $wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}event_marketer (event_marketer_name, event_marketer_status) VALUES (%s, %d)",
						$name, $status
					);

				$result = $wpdb->query( $sql );
				if ( false === $result ) {
					$record_error( 'marketer:' . $name );
				} else {
					$imported++;
				}
			}
		}

		// ── Instructors ───────────────────────────────────────────────────────
		if ( ! empty( $data['event_instructors'] ) ) {
			foreach ( $data['event_instructors'] as $row ) {
				$id     = (int) ( $row['event_instructor_id'] ?? 0 );
				$name   = $row['event_instructor_name'] ?? '';
				$status = (int) ( $row['event_instructor_status'] ?? 1 );

				if ( $id ) {
					$exists_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}event_instructor WHERE event_instructor_id = %d", $id
					) );
					if ( $exists_id ) { $skipped++; continue; }
				} else {
					$exists_name = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}event_instructor WHERE event_instructor_name = %s", $name
					) );
					if ( $exists_name ) { $skipped++; continue; }
				}

				$sql = $id
					? $wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}event_instructor (event_instructor_id, event_instructor_name, event_instructor_status) VALUES (%d, %s, %d)",
						$id, $name, $status
					)
					: $wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}event_instructor (event_instructor_name, event_instructor_status) VALUES (%s, %d)",
						$name, $status
					);

				$result = $wpdb->query( $sql );
				if ( false === $result ) {
					$record_error( 'instructor:' . $name );
				} else {
					$imported++;
				}
			}
		}

		// ── Events ────────────────────────────────────────────────────────────
		// Deduplicate by eve_start + eve_location (same logic as before).
		if ( ! empty( $data['event_details'] ) ) {
			foreach ( $data['event_details'] as $row ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}event_details_list WHERE eve_start = %s AND eve_location = %s",
					$row['eve_start'], $row['eve_location']
				) );
				if ( $exists ) {
					$skipped++;
					continue;
				}

				unset( $row['eve_id'] ); // let auto-increment assign a new ID
				$result = $wpdb->insert( $wpdb->prefix . 'event_details_list', $row );
				if ( false === $result ) {
					$record_error( 'event:' . ( $row['eve_location'] ?? '?' ) . ' ' . ( $row['eve_start'] ?? '' ) );
				} else {
					$imported++;
				}
			}
		}

		$args = array(
			'hl_msg'      => 'imported',
			'hl_imported' => $imported,
			'hl_skipped'  => $skipped,
			'hl_failed'   => $failed,
		);
		if ( $errors ) {
			$args['hl_error'] = urlencode( implode( ' | ', $errors ) );
		}
		wp_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	private function import_csv( $tmp, $redirect ) {
		global $wpdb;
		$handle = fopen( $tmp, 'r' );
		if ( ! $handle ) {
			wp_redirect( add_query_arg( 'hl_msg', 'bad_csv', $redirect ) );
			exit;
		}

		$headers  = fgetcsv( $handle );
		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();

		while ( ( $line = fgetcsv( $handle ) ) !== false ) {
			if ( count( $line ) !== count( $headers ) ) { continue; }
			$row    = array_combine( $headers, $line );
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}event_details_list WHERE eve_start = %s AND eve_location = %s",
				$row['eve_start'], $row['eve_location']
			) );
			if ( $exists ) {
				$skipped++;
				continue;
			}
			unset( $row['eve_id'] );
			$result = $wpdb->insert( $wpdb->prefix . 'event_details_list', $row );
			if ( false === $result ) {
				$failed++;
				if ( count( $errors ) < 3 && $wpdb->last_error ) {
					$errors[] = '[event:' . ( $row['eve_location'] ?? '?' ) . '] ' . $wpdb->last_error;
				}
			} else {
				$imported++;
			}
		}
		fclose( $handle );

		$args = array(
			'hl_msg'      => 'imported',
			'hl_imported' => $imported,
			'hl_skipped'  => $skipped,
			'hl_failed'   => $failed,
		);
		if ( $errors ) {
			$args['hl_error'] = urlencode( implode( ' | ', $errors ) );
		}
		wp_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}
}

new Hostlinks_Import_Export();


