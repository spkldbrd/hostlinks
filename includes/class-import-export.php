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
			'event_details'    => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_details_list  WHERE eve_status = 1 ORDER BY eve_start ASC", ARRAY_A ),
			'event_types'      => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_type          WHERE event_type_status = 1", ARRAY_A ),
			'event_marketers'  => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_marketer      WHERE event_marketer_status = 1", ARRAY_A ),
			'event_instructors'=> $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}event_instructor    WHERE event_instructor_status = 1", ARRAY_A ),
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

		// Import event types
		if ( ! empty( $data['event_types'] ) ) {
			foreach ( $data['event_types'] as $row ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}event_type WHERE event_type_name = %s",
					$row['event_type_name']
				) );
				if ( ! $exists ) {
					$wpdb->insert( $wpdb->prefix . 'event_type', array(
						'event_type_name'   => $row['event_type_name'],
						'event_type_color'  => $row['event_type_color'] ?? '',
						'event_type_status' => 1,
					) );
					$imported++;
				} else {
					$skipped++;
				}
			}
		}

		// Import marketers
		if ( ! empty( $data['event_marketers'] ) ) {
			foreach ( $data['event_marketers'] as $row ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}event_marketer WHERE event_marketer_name = %s",
					$row['event_marketer_name']
				) );
				if ( ! $exists ) {
					$wpdb->insert( $wpdb->prefix . 'event_marketer', array(
						'event_marketer_name'   => $row['event_marketer_name'],
						'event_marketer_status' => 1,
					) );
					$imported++;
				} else {
					$skipped++;
				}
			}
		}

		// Import instructors
		if ( ! empty( $data['event_instructors'] ) ) {
			foreach ( $data['event_instructors'] as $row ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}event_instructor WHERE event_instructor_name = %s",
					$row['event_instructor_name']
				) );
				if ( ! $exists ) {
					$wpdb->insert( $wpdb->prefix . 'event_instructor', array(
						'event_instructor_name'   => $row['event_instructor_name'],
						'event_instructor_status' => 1,
					) );
					$imported++;
				} else {
					$skipped++;
				}
			}
		}

		// Import events (deduplicate by eve_start + eve_location)
		if ( ! empty( $data['event_details'] ) ) {
			foreach ( $data['event_details'] as $row ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}event_details_list WHERE eve_start = %s AND eve_location = %s",
					$row['eve_start'], $row['eve_location']
				) );
				if ( ! $exists ) {
					unset( $row['eve_id'] );
					$row['eve_status'] = 1;
					$wpdb->insert( $wpdb->prefix . 'event_details_list', $row );
					$imported++;
				} else {
					$skipped++;
				}
			}
		}

		wp_redirect( add_query_arg( array( 'hl_msg' => 'imported', 'hl_imported' => $imported, 'hl_skipped' => $skipped ), $redirect ) );
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

		while ( ( $line = fgetcsv( $handle ) ) !== false ) {
			if ( count( $line ) !== count( $headers ) ) { continue; }
			$row = array_combine( $headers, $line );
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}event_details_list WHERE eve_start = %s AND eve_location = %s",
				$row['eve_start'], $row['eve_location']
			) );
			if ( ! $exists ) {
				unset( $row['eve_id'] );
				$row['eve_status'] = 1;
				$wpdb->insert( $wpdb->prefix . 'event_details_list', $row );
				$imported++;
			} else {
				$skipped++;
			}
		}
		fclose( $handle );

		wp_redirect( add_query_arg( array( 'hl_msg' => 'imported', 'hl_imported' => $imported, 'hl_skipped' => $skipped ), $redirect ) );
		exit;
	}
}

new Hostlinks_Import_Export();


