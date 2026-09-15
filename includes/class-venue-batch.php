<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch-fill Host &amp; Venue fields on events from a CSV (city / state / date match).
 */
class Hostlinks_Venue_Batch {

	const TRANSIENT_PREFIX = 'hl_venue_batch_';
	const TRANSIENT_TTL    = 1800;

	public static function init() {
		add_action( 'admin_post_hostlinks_venue_sample', array( __CLASS__, 'handle_sample_download' ) );
	}

	public static function handle_sample_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'hostlinks_venue_sample' );
		self::download_sample();
	}

	const SAMPLE_HEADERS = array(
		'City',
		'State',
		'Date',
		'Host Name',
		'Displayed As',
		'Location Name',
		'Address Line 1',
		'Address Line 2',
		'Address Line 3',
		'ZIP',
		'Special Instructions',
		'Parking File URL',
		'Type',
	);

	/**
	 * @param array $file $_FILES entry.
	 * @param bool  $include_past
	 * @param bool  $replace_existing
	 * @return array|WP_Error
	 */
	public static function preview_from_upload( $file, $include_past = false, $replace_existing = false ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No CSV file was uploaded.' );
		}
		if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', 'The file could not be uploaded. Please try again.' );
		}

		$ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( $ext && 'csv' !== $ext ) {
			return new WP_Error( 'bad_type', 'Please upload a .csv file.' );
		}

		$rows = self::parse_csv( $file['tmp_name'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$preview = self::match_rows( $rows, (bool) $include_past, (bool) $replace_existing );
		$token   = wp_generate_password( 20, false, false );
		set_transient( self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token, $preview, self::TRANSIENT_TTL );
		$preview['token'] = $token;
		return $preview;
	}

	/**
	 * @param string $token
	 * @return array|WP_Error
	 */
	public static function apply( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'expired', 'That preview expired. Upload the CSV again.' );
		}

		$key     = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
		$preview = get_transient( $key );
		if ( ! is_array( $preview ) || empty( $preview['matches'] ) ) {
			return new WP_Error( 'expired', 'That preview expired or had no matches. Upload the CSV again.' );
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'event_details_list';
		$updated = 0;
		$skipped = array();

		foreach ( $preview['matches'] as $m ) {
			$eve_id = (int) ( $m['eve_id'] ?? 0 );
			$fields = (array) ( $m['fields'] ?? array() );
			if ( $eve_id < 1 || empty( $fields ) ) {
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE eve_id = %d", $eve_id ),
				ARRAY_A
			);
			if ( ! $row ) {
				$skipped[] = array(
					'location' => $m['location'] ?? ( 'Event #' . $eve_id ),
					'reason'   => 'Event not found',
				);
				continue;
			}

			$ok = $wpdb->update(
				$table,
				$fields,
				array( 'eve_id' => $eve_id ),
				self::update_formats( $fields ),
				array( '%d' )
			);
			if ( false === $ok ) {
				$skipped[] = array(
					'location' => $row['eve_location'] ?: ( $m['location'] ?? '' ),
					'reason'   => 'Database update failed',
				);
				continue;
			}
			$updated++;
		}

		delete_transient( $key );

		if ( $updated > 0 ) {
			do_action( 'hostlinks_event_updated' );
		}

		return array(
			'updated'   => $updated,
			'skipped'   => $skipped,
			'unmatched' => $preview['unmatched'] ?? array(),
		);
	}

	public static function download_sample() {
		$filename = 'hostlinks-venue-sample.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, self::SAMPLE_HEADERS );
		fputcsv(
			$out,
			array(
				'Arlington',
				'TX',
				'2027-07-15',
				'North Central Texas Council of Governments Criminal Justice Program',
				'Hosted by North Central Texas Council of Governments Criminal Justice Program',
				'Pitstick Conference Room',
				'616 Six Flags Drive',
				'',
				'',
				'76011',
				'',
				'',
				'Grant Management',
			)
		);
		fclose( $out );
		exit;
	}

	/**
	 * @param string $path
	 * @return array|WP_Error
	 */
	public static function parse_csv( $path ) {
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'bad_csv', 'The CSV file is empty or could not be read.' );
		}

		if ( strncmp( $raw, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$raw = substr( $raw, 3 );
		}

		$fh = fopen( 'php://temp', 'r+' );
		if ( ! $fh ) {
			return new WP_Error( 'bad_csv', 'Could not open the CSV for reading.' );
		}
		fwrite( $fh, $raw );
		rewind( $fh );

		$delim  = ',';
		$header = fgetcsv( $fh, 0, ',' );
		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			rewind( $fh );
			$header = fgetcsv( $fh, 0, ';' );
			$delim  = ';';
		}
		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			fclose( $fh );
			return new WP_Error( 'bad_csv', 'Could not read a header row.' );
		}

		$map = array();
		foreach ( $header as $i => $label ) {
			$role = self::header_role( $label );
			if ( $role && ! isset( $map[ $role ] ) ) {
				$map[ $role ] = (int) $i;
			}
		}

		$has_place = isset( $map['city'] ) || isset( $map['location'] );
		$has_date  = isset( $map['date'] );
		if ( ! $has_place || ! $has_date ) {
			fclose( $fh );
			$found = implode( ', ', array_map( 'trim', $header ) );
			return new WP_Error(
				'bad_headers',
				'CSV needs City (or Location), Date, and at least one Host/Venue column. Found: ' . $found
			);
		}

		$roles = array(
			'city', 'state', 'date', 'host', 'type', 'county', 'location',
			'displayed_as', 'location_name', 'addr1', 'addr2', 'addr3', 'zip',
			'special_instructions', 'parking_file_url',
		);

		$out  = array();
		$line = 1;
		while ( ( $cols = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			$line++;
			if ( ! is_array( $cols ) ) {
				continue;
			}
			$row = array( 'line' => $line );
			foreach ( $roles as $role ) {
				$i            = $map[ $role ] ?? null;
				$row[ $role ] = ( null === $i ) ? '' : trim( (string) ( $cols[ $i ] ?? '' ) );
			}
			if ( self::row_is_empty( $row ) ) {
				continue;
			}
			$out[] = $row;
		}
		fclose( $fh );

		if ( empty( $out ) ) {
			return new WP_Error( 'bad_csv', 'The CSV has headers but no data rows.' );
		}

		return $out;
	}

	/**
	 * @param array $csv_rows
	 * @param bool  $include_past
	 * @param bool  $replace_existing
	 * @return array
	 */
	public static function match_rows( $csv_rows, $include_past = false, $replace_existing = false ) {
		$events   = self::candidate_events( $include_past );
		$prepared = array();
		foreach ( $events as $ev ) {
			$prepared[] = Hostlinks_Hotel_Batch::prepare_event( $ev );
		}

		$by_event  = array();
		$unmatched = array();
		$skipped   = array();

		foreach ( $csv_rows as $row ) {
			$fields = self::venue_fields_from_row( $row );
			if ( empty( $fields ) ) {
				$unmatched[] = self::unmatched_row( $row, 'No Host/Venue data on this row (need at least one of Displayed As, Host Name, Location Name, address, etc.)' );
				continue;
			}

			$match_row = array(
				'city'     => $row['city'] ?? '',
				'state'    => $row['state'] ?? '',
				'date'     => $row['date'] ?? '',
				'host'     => $row['host'] ?? '',
				'type'     => $row['type'] ?? '',
				'county'   => $row['county'] ?? '',
				'location' => $row['location'] ?? '',
			);
			if ( '' === trim( (string) ( $match_row['host'] ?? '' ) ) ) {
				$match_row['host'] = $fields['host_name'] ?? '';
			}

			$match = Hostlinks_Hotel_Batch::find_matching_events_for_csv_row( $match_row, $prepared );
			if ( ! empty( $match['error'] ) ) {
				$unmatched[] = self::unmatched_row( $row, $match['error'] );
				continue;
			}

			foreach ( $match['found'] as $id => $ev ) {
				if ( ! isset( $by_event[ $id ] ) ) {
					$db = self::get_event_venue_row( $id );
					$by_event[ $id ] = array(
						'eve_id'       => $id,
						'location'     => $ev['location'],
						'start'        => $ev['start'],
						'type'         => $ev['type_name'],
						'host_name'    => $ev['host_name'],
						'has_existing' => self::event_has_venue( $db ),
						'fields'       => array(),
						'csv_lines'    => array(),
						'warnings'     => array(),
						'summary'      => '',
					);
				}
				$by_event[ $id ]['fields']    = array_merge( $by_event[ $id ]['fields'], $fields );
				$by_event[ $id ]['csv_lines'][] = (int) $row['line'];
				$by_event[ $id ]['warnings']  = array_values( array_unique( array_merge(
					$by_event[ $id ]['warnings'],
					$match['notes']
				) ) );
				$by_event[ $id ]['summary']   = self::fields_summary( $by_event[ $id ]['fields'] );
			}
		}

		$matches = array();
		foreach ( $by_event as $m ) {
			if ( $m['has_existing'] && ! $replace_existing ) {
				$skipped[] = array(
					'eve_id'   => $m['eve_id'],
					'location' => $m['location'],
					'start'    => $m['start'],
					'type'     => $m['type'],
					'reason'   => 'Already has host/venue data (turn on Replace existing to overwrite)',
				);
				continue;
			}
			$matches[] = $m;
		}

		$quality = Hostlinks_Hotel_Batch::quality_report( $csv_rows, $matches, $unmatched, $skipped );

		return array(
			'matches'          => $matches,
			'unmatched'        => $unmatched,
			'skipped'          => $skipped,
			'csv_rows'         => count( $csv_rows ),
			'events_scanned'   => count( $events ),
			'include_past'     => (bool) $include_past,
			'replace_existing' => (bool) $replace_existing,
			'quality'          => $quality,
		);
	}

	public static function candidate_events( $include_past = false ) {
		global $wpdb;
		$edl   = $wpdb->prefix . 'event_details_list';
		$types = $wpdb->prefix . 'event_type';
		$sql   = "SELECT e.eve_id, e.eve_location, e.eve_start, e.eve_end, e.host_name, e.city, e.state,
			e.displayed_as, e.location_name, e.street_address_1, e.street_address_2, e.street_address_3,
			e.zip_code, e.special_instructions, e.parking_file_url,
			t.event_type_name, t.event_type_abbr
			FROM `{$edl}` e
			LEFT JOIN `{$types}` t ON t.event_type_id = e.eve_type
			WHERE e.eve_status = 1";
		if ( ! $include_past ) {
			$sql .= $wpdb->prepare( ' AND e.eve_start >= %s', current_time( 'Y-m-d' ) );
		}
		$sql .= ' ORDER BY e.eve_start ASC, e.eve_location ASC';
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private static function get_event_venue_row( int $eve_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT host_name, displayed_as, location_name, street_address_1, street_address_2,
					street_address_3, zip_code, special_instructions, parking_file_url
				 FROM {$wpdb->prefix}event_details_list WHERE eve_id = %d",
				$eve_id
			),
			ARRAY_A
		);
	}

	private static function event_has_venue( $row ) {
		if ( ! is_array( $row ) ) {
			return false;
		}
		foreach ( array(
			'displayed_as', 'host_name', 'location_name', 'street_address_1',
			'street_address_2', 'street_address_3', 'special_instructions', 'parking_file_url',
		) as $key ) {
			if ( trim( (string) ( $row[ $key ] ?? '' ) ) !== '' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array $row Parsed CSV row.
	 * @return array<string, string> DB column => value (non-empty cells only).
	 */
	private static function venue_fields_from_row( array $row ) {
		$map = array(
			'host'                 => 'host_name',
			'displayed_as'         => 'displayed_as',
			'location_name'        => 'location_name',
			'addr1'                => 'street_address_1',
			'addr2'                => 'street_address_2',
			'addr3'                => 'street_address_3',
			'zip'                  => 'zip_code',
			'special_instructions' => 'special_instructions',
			'parking_file_url'     => 'parking_file_url',
		);

		$out = array();
		foreach ( $map as $csv_key => $db_col ) {
			$val = trim( (string) ( $row[ $csv_key ] ?? '' ) );
			if ( '' === $val ) {
				continue;
			}
			if ( 'parking_file_url' === $db_col ) {
				$out[ $db_col ] = esc_url_raw( $val );
			} elseif ( 'special_instructions' === $db_col ) {
				$out[ $db_col ] = sanitize_textarea_field( $val );
			} else {
				$out[ $db_col ] = sanitize_text_field( $val );
			}
		}

		$city  = trim( (string) ( $row['city'] ?? '' ) );
		$state = trim( (string) ( $row['state'] ?? '' ) );
		if ( $city !== '' ) {
			$out['city'] = sanitize_text_field( $city );
		}
		if ( $state !== '' ) {
			$db_state = self::state_for_database( $state );
			if ( $db_state !== '' ) {
				$out['state'] = $db_state;
			}
		}

		if ( empty( $out['displayed_as'] ) && ! empty( $out['host_name'] ) ) {
			// Leave displayed_as for editors; optional auto-fill not applied so CSV can set explicitly.
		}

		return $out;
	}

	/**
	 * Hostlinks stores state as uppercase USPS abbreviations (Edit Event dropdown).
	 */
	private static function state_for_database( string $raw ): string {
		$abbr = strtoupper( Hostlinks_CVENT_Matcher::normalize_state( $raw ) );
		if ( strlen( $abbr ) === 2 && ctype_alpha( $abbr ) ) {
			return $abbr;
		}
		return '';
	}

	private static function fields_summary( array $fields ) {
		$bits = array();
		if ( ! empty( $fields['displayed_as'] ) ) {
			$bits[] = $fields['displayed_as'];
		} elseif ( ! empty( $fields['host_name'] ) ) {
			$bits[] = $fields['host_name'];
		}
		if ( ! empty( $fields['location_name'] ) ) {
			$bits[] = $fields['location_name'];
		}
		if ( ! empty( $fields['street_address_1'] ) ) {
			$bits[] = $fields['street_address_1'];
		}
		return implode( ' · ', array_slice( $bits, 0, 3 ) );
	}

	/**
	 * @param array<string, string> $fields
	 * @return string[]
	 */
	private static function update_formats( array $fields ) {
		$formats = array();
		foreach ( array_keys( $fields ) as $col ) {
			$formats[] = '%s';
		}
		return $formats;
	}

	private static function header_role( $label ) {
		$key = strtolower( trim( (string) $label ) );
		$key = trim( $key, "\"'" );
		$key = preg_replace( '/\s+/', ' ', $key );
		$aliases = array(
			'city'                    => 'city',
			'event city'              => 'city',
			'state'                   => 'state',
			'st'                      => 'state',
			'date'                    => 'date',
			'start date'              => 'date',
			'event date'              => 'date',
			'host'                    => 'host',
			'host name'               => 'host',
			'type'                    => 'type',
			'event type'              => 'type',
			'county'                  => 'county',
			'location'                => 'location',
			'displayed as'            => 'displayed_as',
			'displayed_as'            => 'displayed_as',
			'location name'           => 'location_name',
			'location name / building'=> 'location_name',
			'building'                => 'location_name',
			'address line 1'          => 'addr1',
			'address 1'               => 'addr1',
			'address line 2'          => 'addr2',
			'address line 3'          => 'addr3',
			'zip'                     => 'zip',
			'zip code'                => 'zip',
			'special instructions'    => 'special_instructions',
			'special instructions / parking' => 'special_instructions',
			'parking'                 => 'special_instructions',
			'parking file url'        => 'parking_file_url',
			'parking / instructions file url' => 'parking_file_url',
			'instructions file url'   => 'parking_file_url',
		);
		return $aliases[ $key ] ?? '';
	}

	private static function row_is_empty( array $row ) {
		foreach ( array(
			'city', 'state', 'date', 'host', 'location', 'displayed_as', 'location_name',
			'addr1', 'addr2', 'addr3', 'zip', 'special_instructions', 'parking_file_url',
		) as $k ) {
			if ( trim( (string) ( $row[ $k ] ?? '' ) ) !== '' ) {
				return false;
			}
		}
		return true;
	}

	private static function unmatched_row( $row, $reason ) {
		return array(
			'line'         => (int) ( $row['line'] ?? 0 ),
			'city'         => $row['city'] ?? '',
			'state'        => $row['state'] ?? '',
			'date'         => $row['date'] ?? '',
			'displayed_as' => $row['displayed_as'] ?? ( $row['host'] ?? '' ),
			'reason'       => $reason,
		);
	}
}
