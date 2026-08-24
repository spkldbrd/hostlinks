<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch-replace future-event Web URLs from a Long URL / Short URL CSV.
 */
class Hostlinks_Short_URL_Batch {

	const TRANSIENT_PREFIX = 'hl_short_url_batch_';
	const TRANSIENT_TTL    = 1800;

	/**
	 * Parse an uploaded CSV and return a preview payload (also stored in a transient).
	 *
	 * @param array $file  $_FILES['...'] entry.
	 * @return array|WP_Error
	 */
	public static function preview_from_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No CSV file was uploaded.' );
		}
		if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', 'The file could not be uploaded. Please try again.' );
		}

		$ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( $ext && 'csv' !== $ext ) {
			return new WP_Error( 'bad_type', 'Please upload a .csv file with Long URL and Short URL columns.' );
		}

		$rows = self::parse_csv( $file['tmp_name'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$preview = self::match_rows( $rows );
		$token   = wp_generate_password( 20, false, false );
		set_transient( self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token, $preview, self::TRANSIENT_TTL );
		$preview['token'] = $token;
		return $preview;
	}

	/**
	 * Apply a previously previewed batch.
	 *
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
			$short  = esc_url_raw( trim( (string) ( $m['short'] ?? '' ) ) );
			if ( $eve_id < 1 || '' === $short ) {
				continue;
			}

			$current = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT eve_web_url FROM `{$table}` WHERE eve_id = %d",
				$eve_id
			) );

			if ( trim( (string) $current ) === $short ) {
				$skipped[] = array(
					'eve_id'   => $eve_id,
					'location' => $m['location'] ?? '',
					'reason'   => 'Already set to this short URL',
				);
				continue;
			}

			$ok = $wpdb->update(
				$table,
				array( 'eve_web_url' => $short ),
				array( 'eve_id' => $eve_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				$skipped[] = array(
					'eve_id'   => $eve_id,
					'location' => $m['location'] ?? '',
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
	 * @param string $path  Absolute path to the CSV on disk.
	 * @return array|WP_Error  List of [ 'long' => string, 'short' => string ].
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
			return new WP_Error( 'bad_csv', 'Could not read a header row. Expected columns Long URL and Short URL.' );
		}

		$long_i  = null;
		$short_i = null;
		foreach ( $header as $i => $label ) {
			$key = self::normalize_header( $label );
			if ( in_array( $key, array( 'long url', 'long_url', 'longurl', 'original url', 'destination url' ), true ) ) {
				$long_i = (int) $i;
			} elseif ( in_array( $key, array( 'short url', 'short_url', 'shorturl', 'short link', 'bitly' ), true ) ) {
				$short_i = (int) $i;
			}
		}

		if ( null === $long_i || null === $short_i ) {
			fclose( $fh );
			$found = implode( ', ', array_map( 'trim', $header ) );
			return new WP_Error(
				'bad_headers',
				'CSV must have columns named Long URL and Short URL. Found: ' . $found
			);
		}

		$out  = array();
		$line = 1;
		while ( ( $cols = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			$line++;
			if ( ! is_array( $cols ) ) {
				continue;
			}
			$long  = trim( (string) ( $cols[ $long_i ] ?? '' ) );
			$short = trim( (string) ( $cols[ $short_i ] ?? '' ) );
			if ( '' === $long && '' === $short ) {
				continue;
			}
			$out[] = array(
				'long'  => $long,
				'short' => $short,
				'line'  => $line,
			);
		}
		fclose( $fh );

		if ( empty( $out ) ) {
			return new WP_Error( 'bad_csv', 'The CSV has headers but no data rows.' );
		}

		return $out;
	}

	/**
	 * Match CSV rows to future events by Web URL.
	 *
	 * @param array $csv_rows
	 * @return array
	 */
	public static function match_rows( $csv_rows ) {
		$events = self::future_events();
		$by_key = array();
		foreach ( $events as $ev ) {
			$web = trim( (string) ( $ev['eve_web_url'] ?? '' ) );
			if ( '' === $web ) {
				continue;
			}
			foreach ( self::url_keys( $web ) as $key ) {
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $by_key[ $key ] ) ) {
					$by_key[ $key ] = array();
				}
				$by_key[ $key ][ (int) $ev['eve_id'] ] = $ev;
			}
		}

		$matches     = array();
		$matched_ids = array();
		$unmatched   = array();
		$already     = array();

		foreach ( $csv_rows as $row ) {
			$long  = trim( (string) $row['long'] );
			$short = trim( (string) $row['short'] );
			$line  = (int) ( $row['line'] ?? 0 );

			if ( '' === $long ) {
				$unmatched[] = array(
					'line'   => $line,
					'long'   => $long,
					'short'  => $short,
					'reason' => 'Long URL is empty',
				);
				continue;
			}
			if ( '' === $short ) {
				$unmatched[] = array(
					'line'   => $line,
					'long'   => $long,
					'short'  => $short,
					'reason' => 'Short URL is empty',
				);
				continue;
			}

			$short_clean = esc_url_raw( $short );
			if ( '' === $short_clean ) {
				$unmatched[] = array(
					'line'   => $line,
					'long'   => $long,
					'short'  => $short,
					'reason' => 'Short URL is not a valid URL',
				);
				continue;
			}

			$found = array();
			foreach ( self::url_keys( $long ) as $key ) {
				if ( isset( $by_key[ $key ] ) ) {
					foreach ( $by_key[ $key ] as $eve_id => $ev ) {
						$found[ $eve_id ] = $ev;
					}
				}
			}

			if ( empty( $found ) ) {
				$unmatched[] = array(
					'line'   => $line,
					'long'   => $long,
					'short'  => $short_clean,
					'reason' => 'No future event with this Web URL',
				);
				continue;
			}

			foreach ( $found as $eve_id => $ev ) {
				if ( isset( $matched_ids[ $eve_id ] ) ) {
					continue;
				}
				$current = trim( (string) ( $ev['eve_web_url'] ?? '' ) );
				if ( $current === $short_clean ) {
					$already[] = array(
						'eve_id'   => $eve_id,
						'location' => $ev['eve_location'] ?? '',
						'start'    => $ev['eve_start'] ?? '',
						'type'     => $ev['event_type_name'] ?? '',
						'current'  => $current,
						'short'    => $short_clean,
					);
					$matched_ids[ $eve_id ] = true;
					continue;
				}
				$matches[] = array(
					'eve_id'   => $eve_id,
					'location' => $ev['eve_location'] ?? '',
					'start'    => $ev['eve_start'] ?? '',
					'type'     => $ev['event_type_name'] ?? '',
					'current'  => $current,
					'long'     => $long,
					'short'    => $short_clean,
				);
				$matched_ids[ $eve_id ] = true;
			}
		}

		return array(
			'matches'         => $matches,
			'unmatched'       => $unmatched,
			'already'         => $already,
			'csv_rows'        => count( $csv_rows ),
			'future_events'   => count( $events ),
			'future_with_web' => count( array_filter( $events, function( $ev ) {
				return trim( (string) ( $ev['eve_web_url'] ?? '' ) ) !== '';
			} ) ),
		);
	}

	/**
	 * Active events starting today or later.
	 *
	 * @return array
	 */
	public static function future_events() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$edl   = $wpdb->prefix . 'event_details_list';
		$types = $wpdb->prefix . 'event_type';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.eve_id, e.eve_location, e.eve_start, e.eve_end, e.eve_web_url, t.event_type_name
				 FROM `{$edl}` e
				 LEFT JOIN `{$types}` t ON t.event_type_id = e.eve_type
				 WHERE e.eve_status = 1 AND e.eve_start >= %s
				 ORDER BY e.eve_start ASC, e.eve_location ASC",
				$today
			),
			ARRAY_A
		);
	}

	/**
	 * Keys used to compare a stored Web URL with a CSV Long URL.
	 * Includes a path-only form so UTM query strings still match.
	 *
	 * @param string $url
	 * @return string[]
	 */
	public static function url_keys( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return array();
		}
		$keys   = array();
		$keys[] = strtolower( rtrim( $url, '/' ) );
		$keys[] = self::normalize_url( $url );
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Host (no www) + path, lowercased, no scheme/query/fragment, no trailing slash.
	 *
	 * @param string $url
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			$stripped = preg_replace( '/[?#].*$/', '', $url );
			return strtolower( rtrim( (string) $stripped, '/' ) );
		}
		$host = strtolower( $parts['host'] );
		$host = preg_replace( '/^www\./', '', $host );
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		return $host . $path;
	}

	private static function normalize_header( $label ) {
		$label = strtolower( trim( (string) $label ) );
		$label = trim( $label, "\"'" );
		$label = preg_replace( '/\s+/', ' ', $label );
		return $label;
	}
}
