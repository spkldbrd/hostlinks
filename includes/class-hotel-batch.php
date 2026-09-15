<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch-fill event hotel JSON from a CSV (city / state / date match).
 */
class Hostlinks_Hotel_Batch {

	const TRANSIENT_PREFIX = 'hl_hotel_batch_';
	const TRANSIENT_TTL    = 1800;

	const SAMPLE_HEADERS = array(
		'City',
		'State',
		'Date',
		'Host Name',
		'Type',
		'County',
		'Hotel Name',
		'Address',
		'Phone',
		'URL',
	);

	/**
	 * @param array $file     $_FILES entry.
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

		$replace = ! empty( $preview['replace_existing'] );
		global $wpdb;
		$table   = $wpdb->prefix . 'event_details_list';
		$updated = 0;
		$skipped = array();

		foreach ( $preview['matches'] as $m ) {
			$eve_id = (int) ( $m['eve_id'] ?? 0 );
			$hotels = self::sanitize_hotels( $m['hotels'] ?? array() );
			if ( $eve_id < 1 || empty( $hotels ) ) {
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT eve_id, hotels, eve_location FROM `{$table}` WHERE eve_id = %d", $eve_id ),
				ARRAY_A
			);
			if ( ! $row ) {
				$skipped[] = array(
					'location' => $m['location'] ?? ( 'Event #' . $eve_id ),
					'reason'   => 'Event not found',
				);
				continue;
			}

			$existing = self::decode_hotels( $row['hotels'] ?? '' );
			if ( ! $replace && self::hotels_have_name( $existing ) ) {
				$skipped[] = array(
					'location' => $row['eve_location'] ?: ( $m['location'] ?? '' ),
					'reason'   => 'Already has hotel data',
				);
				continue;
			}

			$ok = $wpdb->update(
				$table,
				array( 'hotels' => wp_json_encode( $hotels ) ),
				array( 'eve_id' => $eve_id ),
				array( '%s' ),
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

		return array(
			'updated'   => $updated,
			'skipped'   => $skipped,
			'unmatched' => $preview['unmatched'] ?? array(),
		);
	}

	/**
	 * Stream a sample CSV and exit.
	 */
	public static function download_sample() {
		$filename = 'hostlinks-hotels-sample.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, self::SAMPLE_HEADERS );
		fputcsv(
			$out,
			array(
				'Sandy',
				'UT',
				'2026-12-08',
				'Sandy City Police Department',
				'',
				'',
				'Hampton Inn',
				'100 Main St, Sandy UT 84070',
				'801-555-0100',
				'https://example.com/hotel',
			)
		);
		fputcsv(
			$out,
			array(
				'Sandy',
				'UT',
				'2026-12-08',
				'Sandy City Police Department',
				'',
				'',
				'Holiday Inn Express',
				'200 Center St, Sandy UT 84070',
				'',
				'',
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
		} elseif ( strncmp( $raw, "\xFF\xFE", 2 ) === 0 ) {
			$raw = function_exists( 'mb_convert_encoding' )
				? mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' )
				: $raw;
		} elseif ( strncmp( $raw, "\xFE\xFF", 2 ) === 0 ) {
			$raw = function_exists( 'mb_convert_encoding' )
				? mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' )
				: $raw;
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
		$has_hotel = isset( $map['hotel_name'] );
		if ( ! $has_place || ! $has_date || ! $has_hotel ) {
			fclose( $fh );
			$found = implode( ', ', array_map( 'trim', $header ) );
			return new WP_Error(
				'bad_headers',
				'CSV needs City (or Location), Date, and Hotel Name columns. Found: ' . $found
			);
		}

		$out  = array();
		$line = 1;
		while ( ( $cols = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			$line++;
			if ( ! is_array( $cols ) ) {
				continue;
			}
			$row = array( 'line' => $line );
			foreach ( array( 'city', 'state', 'date', 'host', 'type', 'county', 'location', 'hotel_name', 'address', 'phone', 'url' ) as $role ) {
				$i           = $map[ $role ] ?? null;
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
			$prepared[] = self::prepare_event( $ev );
		}

		$by_event  = array();
		$unmatched = array();
		$skipped   = array();

		foreach ( $csv_rows as $row ) {
			$hotel_name = sanitize_text_field( $row['hotel_name'] ?? '' );
			if ( '' === $hotel_name ) {
				$unmatched[] = self::unmatched_row( $row, 'Hotel Name is empty' );
				continue;
			}

			$place = self::csv_place( $row );
			$date  = self::parse_date( $row['date'] ?? '' );
			if ( '' === $date ) {
				$unmatched[] = self::unmatched_row( $row, 'Date is missing or not a valid date' );
				continue;
			}
			if ( '' === $place['city'] ) {
				$unmatched[] = self::unmatched_row( $row, 'Need City or Location (City, ST)' );
				continue;
			}

			$found = array();
			foreach ( $prepared as $ev ) {
				if ( ! self::date_matches( $date, $ev['start'], $ev['end'] ) ) {
					continue;
				}
				if ( $place['state'] && $ev['state'] && $place['state'] !== $ev['state'] ) {
					continue;
				}
				if ( $ev['city'] && $place['city'] !== $ev['city'] ) {
					continue;
				}
				if ( ! $ev['city'] && $place['city'] ) {
					$loc = $ev['city_norm_loc'];
					if ( $loc && false === strpos( $loc, $place['city'] ) && false === strpos( $place['city'], $loc ) ) {
						continue;
					}
				}
				$found[ $ev['eve_id'] ] = $ev;
			}

			$type = trim( (string) ( $row['type'] ?? '' ) );
			if ( $type && count( $found ) > 1 ) {
				$typed = array();
				foreach ( $found as $id => $ev ) {
					if ( self::type_matches( $type, $ev ) ) {
						$typed[ $id ] = $ev;
					}
				}
				if ( ! empty( $typed ) ) {
					$found = $typed;
				}
			}

			$host = trim( (string) ( $row['host'] ?? '' ) );
			if ( $host && count( $found ) > 1 ) {
				$hosted = array();
				foreach ( $found as $id => $ev ) {
					if ( self::host_matches( $host, $ev['host_name'] ) ) {
						$hosted[ $id ] = $ev;
					}
				}
				if ( ! empty( $hosted ) ) {
					$found = $hosted;
				}
			}

			if ( empty( $found ) ) {
				$unmatched[] = self::unmatched_row( $row, 'No event with this city, state, and date' );
				continue;
			}

			$hotel = array(
				'name'    => $hotel_name,
				'address' => sanitize_text_field( $row['address'] ?? '' ),
				'phone'   => sanitize_text_field( $row['phone'] ?? '' ),
				'url'     => esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) ),
			);

			foreach ( $found as $id => $ev ) {
				if ( ! isset( $by_event[ $id ] ) ) {
					$existing = $ev['hotels'];
					$has      = self::hotels_have_name( $existing );
					$by_event[ $id ] = array(
						'eve_id'         => $id,
						'location'       => $ev['location'],
						'start'          => $ev['start'],
						'end'            => $ev['end'],
						'type'           => $ev['type_name'],
						'host_name'      => $ev['host_name'],
						'existing_count' => $has ? count( $existing ) : 0,
						'has_existing'   => $has,
						'hotels'         => array(),
						'csv_lines'      => array(),
					);
				}
				$by_event[ $id ]['hotels']      = self::merge_hotel( $by_event[ $id ]['hotels'], $hotel );
				$by_event[ $id ]['csv_lines'][] = (int) $row['line'];
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
					'reason'   => 'Already has hotel data (turn on Replace existing to overwrite)',
				);
				continue;
			}
			$matches[] = $m;
		}

		return array(
			'matches'           => $matches,
			'unmatched'         => $unmatched,
			'skipped'           => $skipped,
			'csv_rows'          => count( $csv_rows ),
			'events_scanned'    => count( $events ),
			'include_past'      => (bool) $include_past,
			'replace_existing'  => (bool) $replace_existing,
		);
	}

	/**
	 * @param bool $include_past
	 * @return array
	 */
	public static function candidate_events( $include_past = false ) {
		global $wpdb;
		$edl   = $wpdb->prefix . 'event_details_list';
		$types = $wpdb->prefix . 'event_type';
		$sql   = "SELECT e.eve_id, e.eve_location, e.eve_start, e.eve_end, e.host_name,
			e.city, e.state, e.hotels, t.event_type_name, t.event_type_abbr
			FROM `{$edl}` e
			LEFT JOIN `{$types}` t ON t.event_type_id = e.eve_type
			WHERE e.eve_status = 1";
		if ( ! $include_past ) {
			$sql .= $wpdb->prepare( ' AND e.eve_start >= %s', current_time( 'Y-m-d' ) );
		}
		$sql .= ' ORDER BY e.eve_start ASC, e.eve_location ASC';
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private static function prepare_event( $ev ) {
		$city  = trim( (string) ( $ev['city'] ?? '' ) );
		$state = trim( (string) ( $ev['state'] ?? '' ) );
		$base  = self::location_base( $ev['eve_location'] ?? '' );
		$parts = explode( ',', $base );
		if ( '' === $city ) {
			$city = trim( (string) ( $parts[0] ?? '' ) );
		}
		if ( '' === $state && isset( $parts[1] ) ) {
			$state = trim( $parts[1] );
		}
		return array(
			'eve_id'        => (int) $ev['eve_id'],
			'location'      => (string) ( $ev['eve_location'] ?? '' ),
			'start'         => (string) ( $ev['eve_start'] ?? '' ),
			'end'           => (string) ( $ev['eve_end'] ?? $ev['eve_start'] ?? '' ),
			'host_name'     => (string) ( $ev['host_name'] ?? '' ),
			'type_name'     => (string) ( $ev['event_type_name'] ?? '' ),
			'type_abbr'     => (string) ( $ev['event_type_abbr'] ?? '' ),
			'city'          => self::normalize_city( $city ),
			'state'         => Hostlinks_CVENT_Matcher::normalize_state( $state ),
			'city_norm_loc' => self::normalize_city( $base ),
			'hotels'        => self::decode_hotels( $ev['hotels'] ?? '' ),
		);
	}

	private static function csv_place( $row ) {
		$city  = trim( (string) ( $row['city'] ?? '' ) );
		$state = trim( (string) ( $row['state'] ?? '' ) );
		$loc   = trim( (string) ( $row['location'] ?? '' ) );
		if ( ( '' === $city || '' === $state ) && $loc ) {
			$base  = self::location_base( $loc );
			$parts = explode( ',', $base );
			if ( '' === $city ) {
				$city = trim( (string) ( $parts[0] ?? '' ) );
			}
			if ( '' === $state && isset( $parts[1] ) ) {
				$state = trim( $parts[1] );
			}
		}
		if ( '' === $city ) {
			$city = trim( (string) ( $row['county'] ?? '' ) );
			$city = preg_replace( '/\s+county$/i', '', $city );
		}
		return array(
			'city'  => self::normalize_city( $city ),
			'state' => Hostlinks_CVENT_Matcher::normalize_state( $state ),
		);
	}

	private static function date_matches( $csv_date, $start, $end ) {
		$start = substr( (string) $start, 0, 10 );
		$end   = substr( (string) $end, 0, 10 );
		if ( $csv_date === $start ) {
			return true;
		}
		if ( $end && $csv_date >= $start && $csv_date <= $end ) {
			return true;
		}
		return false;
	}

	private static function type_matches( $csv_type, $ev ) {
		$want = Hostlinks_CVENT_Matcher::normalize( $csv_type );
		if ( '' === $want ) {
			return true;
		}
		$name = Hostlinks_CVENT_Matcher::normalize( $ev['type_name'] ?? '' );
		$abbr = Hostlinks_CVENT_Matcher::normalize( $ev['type_abbr'] ?? '' );
		if ( $abbr && ( $want === $abbr || false !== strpos( $want, $abbr ) || false !== strpos( $abbr, $want ) ) ) {
			return true;
		}
		if ( $name && ( $want === $name || false !== strpos( $name, $want ) || false !== strpos( $want, $name ) ) ) {
			return true;
		}
		return false;
	}

	private static function host_matches( $csv_host, $event_host ) {
		$a = Hostlinks_CVENT_Matcher::normalize( $csv_host );
		$b = Hostlinks_CVENT_Matcher::normalize( $event_host );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		return $a === $b || false !== strpos( $b, $a ) || false !== strpos( $a, $b );
	}

	public static function parse_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		if ( preg_match( '/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $raw, $m ) ) {
			$y = (int) $m[3];
			if ( $y < 100 ) {
				$y += 2000;
			}
			return sprintf( '%04d-%02d-%02d', $y, (int) $m[1], (int) $m[2] );
		}
		$ts = strtotime( $raw );
		if ( $ts ) {
			return gmdate( 'Y-m-d', $ts );
		}
		return '';
	}

	private static function location_base( $location ) {
		$base = preg_replace( '/\s*\|.*$/s', '', (string) $location );
		$base = preg_replace( '/\s*–.*$/su', '', $base );
		$base = preg_replace( '/\s+-\s+.*$/s', '', $base );
		return trim( $base );
	}

	private static function normalize_city( $str ) {
		$s = Hostlinks_CVENT_Matcher::normalize( $str );
		$s = preg_replace( '/^street\s+/', 'st ', $s );
		$s = preg_replace( '/^saint\s+/', 'st ', $s );
		return $s;
	}

	private static function header_role( $label ) {
		$key = strtolower( trim( (string) $label ) );
		$key = trim( $key, "\"'" );
		$key = preg_replace( '/\s+/', ' ', $key );
		$aliases = array(
			'city'        => 'city',
			'event city'  => 'city',
			'location city' => 'city',
			'state'       => 'state',
			'st'          => 'state',
			'date'        => 'date',
			'start'       => 'date',
			'start date'  => 'date',
			'event date'  => 'date',
			'host'        => 'host',
			'host name'   => 'host',
			'type'        => 'type',
			'event type'  => 'type',
			'county'      => 'county',
			'location'    => 'location',
			'eve_location'=> 'location',
			'hotel'       => 'hotel_name',
			'hotel name'  => 'hotel_name',
			'hotel_name'  => 'hotel_name',
			'address'     => 'address',
			'hotel address' => 'address',
			'phone'       => 'phone',
			'hotel phone' => 'phone',
			'url'         => 'url',
			'hotel url'   => 'url',
			'website'     => 'url',
			'link'        => 'url',
		);
		return $aliases[ $key ] ?? '';
	}

	private static function row_is_empty( $row ) {
		foreach ( array( 'city', 'state', 'date', 'host', 'location', 'hotel_name', 'address' ) as $k ) {
			if ( trim( (string) ( $row[ $k ] ?? '' ) ) !== '' ) {
				return false;
			}
		}
		return true;
	}

	private static function unmatched_row( $row, $reason ) {
		return array(
			'line'       => (int) ( $row['line'] ?? 0 ),
			'city'       => $row['city'] ?? '',
			'state'      => $row['state'] ?? '',
			'date'       => $row['date'] ?? '',
			'hotel_name' => $row['hotel_name'] ?? '',
			'reason'     => $reason,
		);
	}

	public static function decode_hotels( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function hotels_have_name( $hotels ) {
		foreach ( (array) $hotels as $h ) {
			if ( trim( (string) ( $h['name'] ?? '' ) ) !== '' ) {
				return true;
			}
		}
		return false;
	}

	private static function merge_hotel( $list, $hotel ) {
		$key = Hostlinks_CVENT_Matcher::normalize( $hotel['name'] ?? '' );
		foreach ( $list as $i => $existing ) {
			if ( Hostlinks_CVENT_Matcher::normalize( $existing['name'] ?? '' ) === $key ) {
				$list[ $i ] = array_merge( $existing, array_filter( $hotel ) );
				$list[ $i ]['name'] = $hotel['name'];
				return $list;
			}
		}
		$list[] = $hotel;
		return $list;
	}

	private static function sanitize_hotels( $hotels ) {
		$out = array();
		foreach ( (array) $hotels as $h ) {
			$name = sanitize_text_field( $h['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'    => $name,
				'phone'   => sanitize_text_field( $h['phone'] ?? '' ),
				'address' => sanitize_text_field( $h['address'] ?? '' ),
				'url'     => esc_url_raw( trim( (string) ( $h['url'] ?? '' ) ) ),
			);
		}
		return $out;
	}
}
