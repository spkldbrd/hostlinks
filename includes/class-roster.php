<?php
/**
 * Roster report — fetch, cache, and normalize CVENT registration data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Roster {

	const CACHE_PREFIX = 'hostlinks_roster_v2_';

	const SKIP_STATUSES = array(
		'Cancelled', 'Declined', 'Deleted', 'TestAttendee', 'Waitlisted',
		'cancelled', 'declined', 'deleted', 'testattendee', 'waitlisted',
	);

	/**
	 * Load order items (with expand=attendee) from cache or CVENT API.
	 *
	 * @return array{items:array,is_past:bool,from_cache:bool}|WP_Error
	 */
	public static function load_order_items( string $cvent_id, array $event_row, bool $force_refresh = false ) {
		$cvent_id    = Hostlinks_CVENT_API::sanitize_uuid( $cvent_id );
		$end_ts      = ! empty( $event_row['eve_end'] ) ? strtotime( $event_row['eve_end'] ) : 0;
		$is_past     = $end_ts > 0 && $end_ts < strtotime( 'today midnight' );
		$cache_ttl   = $is_past ? 0 : 24 * HOUR_IN_SECONDS;
		$cache_key   = self::CACHE_PREFIX . md5( $cvent_id );

		if ( $force_refresh ) {
			delete_transient( $cache_key );
		}

		$cached = get_transient( $cache_key );
		if ( $cached !== false && is_array( $cached ) ) {
			return array(
				'items'       => $cached,
				'is_past'     => $is_past,
				'from_cache'  => true,
			);
		}

		$items = Hostlinks_CVENT_API::get_roster_order_items( $cvent_id );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		set_transient( $cache_key, $items, $cache_ttl );

		return array(
			'items'      => $items,
			'is_past'    => $is_past,
			'from_cache' => false,
		);
	}

	/**
	 * Schedule the 5-day post-event roster finalize cron when appropriate.
	 */
	public static function maybe_schedule_finalize( string $cvent_id, int $eve_id, array $event_row, bool $is_past ): void {
		if ( ! $is_past ) {
			return;
		}
		$end_ts = ! empty( $event_row['eve_end'] ) ? strtotime( $event_row['eve_end'] ) : 0;
		if ( $end_ts <= 0 || $end_ts <= strtotime( '-5 days' ) ) {
			return;
		}
		$args = array( $cvent_id, $eve_id );
		if ( ! wp_next_scheduled( 'hostlinks_roster_finalize', $args ) ) {
			wp_schedule_single_event( $end_ts + ( 5 * DAY_IN_SECONDS ), 'hostlinks_roster_finalize', $args );
		}
	}

	/**
	 * Build display rows from raw order items.
	 *
	 * @param array $order_items  CVENT order items (expand=attendee).
	 * @param bool  $is_past      Whether the HL event has ended.
	 * @return array<int,array<string,string>>
	 */
	public static function build_rows( array $order_items, bool $is_past ): array {
		if ( empty( $order_items ) ) {
			return array();
		}

		$attendees_map = self::resolve_attendees_map( $order_items );
		$meta_by_id    = self::aggregate_order_meta( $order_items );
		$rows          = array();

		foreach ( $attendees_map as $uuid => $att ) {
			$status = $att['status'] ?? $att['attendeeStatus'] ?? '';
			if ( in_array( $status, self::SKIP_STATUSES, true ) ) {
				continue;
			}

			$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
			$meta    = $meta_by_id[ $uuid ] ?? array(
				'discounts'   => array(),
				'balance_due' => 0.0,
				'order_nums'  => array(),
			);

			$work_city  = trim( (string) ( $att['workCity'] ?? $contact['workCity'] ?? '' ) );
			$work_state = self::format_work_state( $att['workState'] ?? $contact['workState'] ?? '' );

			$rows[] = array(
				'last'         => $att['lastName']    ?? $contact['lastName']    ?? '',
				'first'        => $att['firstName']   ?? $contact['firstName']   ?? '',
				'company'      => $att['companyName'] ?? $contact['company']     ?? $contact['companyName'] ?? '',
				'title'        => $att['title']       ?? $contact['title']       ?? '',
				'email'        => $att['email']       ?? $contact['email']       ?? '',
				'phone'        => self::format_phone( $att['workPhone'] ?? $contact['workPhone'] ?? $att['phone'] ?? $contact['phone'] ?? '' ),
				'discounts'    => implode( ', ', $meta['discounts'] ),
				'balance_due'  => self::format_balance( $meta['balance_due'] ),
				'participant'  => $is_past ? self::participant_label( $att ) : '',
				'work_city'    => $work_city,
				'work_state'   => $work_state,
				'status'       => $status,
			);
		}

		usort( $rows, function( $a, $b ) {
			$c = strcasecmp( $a['last'], $b['last'] );
			return $c !== 0 ? $c : strcasecmp( $a['first'], $b['first'] );
		} );

		return $rows;
	}

	/**
	 * Resolve full attendee records keyed by UUID.
	 *
	 * @param array $order_items
	 * @return array<string,array>
	 */
	public static function resolve_attendees_map( array $order_items ): array {
		$map = array();

		foreach ( $order_items as $item ) {
			$att  = $item['attendee'] ?? array();
			$uuid = Hostlinks_CVENT_API::sanitize_uuid( (string) ( $att['id'] ?? $item['attendeeId'] ?? '' ) );
			if ( $uuid === '' ) {
				continue;
			}
			if ( ! empty( $att['firstName'] ) || ! empty( $att['lastName'] ) || ! empty( $att['contact'] ) ) {
				$map[ $uuid ] = $att;
			} elseif ( ! isset( $map[ $uuid ] ) ) {
				$map[ $uuid ] = $att;
			}
		}

		$sample = $order_items[0]['attendee'] ?? array();
		$expand_works = isset( $sample['firstName'] ) || isset( $sample['lastName'] ) || isset( $sample['contact'] );
		if ( $expand_works ) {
			return $map;
		}

		foreach ( array_keys( $map ) as $uuid ) {
			$att = Hostlinks_CVENT_API::get_attendee( $uuid );
			if ( is_wp_error( $att ) ) {
				continue;
			}
			if ( isset( $att['data'] ) && is_array( $att['data'] ) ) {
				$att = $att['data'];
			}
			$map[ $uuid ] = $att;
		}

		return $map;
	}

	/**
	 * Per-attendee discount codes, balance due, and order numbers from order items.
	 *
	 * @param array $order_items
	 * @return array<string,array{discounts:array,balance_due:float,order_nums:array}>
	 */
	private static function aggregate_order_meta( array $order_items ): array {
		$meta = array();

		foreach ( $order_items as $item ) {
			if ( ! ( $item['active'] ?? true ) ) {
				continue;
			}

			$uuid = Hostlinks_CVENT_API::sanitize_uuid(
				(string) ( $item['attendee']['id'] ?? $item['attendeeId'] ?? '' )
			);
			if ( $uuid === '' ) {
				continue;
			}

			if ( ! isset( $meta[ $uuid ] ) ) {
				$meta[ $uuid ] = array(
					'discounts'   => array(),
					'balance_due' => 0.0,
					'order_nums'  => array(),
				);
			}

			foreach ( self::extract_item_discounts( $item ) as $code ) {
				$meta[ $uuid ]['discounts'][ $code ] = $code;
			}

			$att_discounts = Hostlinks_CVENT_Sync::extract_discount_strings( $item['attendee'] ?? array() );
			foreach ( $att_discounts as $code ) {
				$meta[ $uuid ]['discounts'][ $code ] = $code;
			}

			$meta[ $uuid ]['balance_due'] += self::parse_money( $item['amountDue'] ?? $item['balanceDue'] ?? 0 );

			$order_num = trim( (string) ( $item['orderNumber'] ?? $item['orderId'] ?? '' ) );
			if ( $order_num !== '' ) {
				$meta[ $uuid ]['order_nums'][ $order_num ] = $order_num;
			}
		}

		return $meta;
	}

	/**
	 * @param array $item  Single CVENT order item.
	 * @return string[]
	 */
	public static function extract_item_discounts( array $item ): array {
		$strings = array();

		foreach ( array( 'discountCode', 'DiscountCode', 'discount_code', 'discountName', 'DiscountName' ) as $field ) {
			if ( ! empty( $item[ $field ] ) ) {
				$strings[] = (string) $item[ $field ];
			}
		}

		if ( ! empty( $item['discounts'] ) && is_array( $item['discounts'] ) ) {
			foreach ( $item['discounts'] as $d ) {
				foreach ( array( 'code', 'name', 'discountCode' ) as $field ) {
					if ( ! empty( $d[ $field ] ) ) {
						$strings[] = (string) $d[ $field ];
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $strings ) ) );
	}

	public static function format_phone( string $raw ): string {
		$digits = preg_replace( '/\D/', '', $raw );
		if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
			$digits = substr( $digits, 1 );
		}
		if ( strlen( $digits ) === 10 ) {
			return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}
		return $raw;
	}

	public static function format_work_state( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( preg_match( '/^[A-Za-z]{2}$/', $raw ) ) {
			return strtoupper( $raw );
		}
		if ( class_exists( 'Hostlinks_CVENT_Matcher', false ) ) {
			$norm = Hostlinks_CVENT_Matcher::normalize_state( $raw );
			if ( preg_match( '/^[a-z]{2}$/', $norm ) ) {
				return strtoupper( $norm );
			}
		}
		return $raw;
	}

	public static function participant_label( array $att ): string {
		$sources = array( $att );
		if ( ! empty( $att['contact'] ) && is_array( $att['contact'] ) ) {
			$sources[] = $att['contact'];
		}

		foreach ( $sources as $src ) {
			foreach ( array( 'participant', 'checkedIn', 'participantStatus', 'isParticipant' ) as $key ) {
				if ( ! array_key_exists( $key, $src ) ) {
					continue;
				}
				$val = $src[ $key ];
				if ( is_bool( $val ) ) {
					return $val ? 'Yes' : 'No';
				}
				if ( is_numeric( $val ) ) {
					return ( (int) $val ) ? 'Yes' : 'No';
				}
				$s = strtolower( trim( (string) $val ) );
				if ( in_array( $s, array( 'yes', 'true', '1', 'y' ), true ) ) {
					return 'Yes';
				}
				if ( in_array( $s, array( 'no', 'false', '0', 'n' ), true ) ) {
					return 'No';
				}
			}
		}

		return '';
	}

	private static function parse_money( $value ): float {
		if ( is_array( $value ) ) {
			$value = $value['value'] ?? $value['amount'] ?? 0;
		}
		if ( is_string( $value ) ) {
			$value = str_replace( array( '$', ',' ), '', $value );
		}
		return (float) $value;
	}

	public static function format_balance( float $amount ): string {
		if ( $amount <= 0 ) {
			return '';
		}
		return '$' . number_format( $amount, 2 );
	}

	/**
	 * Event header title parts for roster display.
	 */
	public static function build_title( array $event_row, int $eve_id, $wpdb ): string {
		$type_raw = strtolower( trim( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT event_type_name FROM `{$wpdb->prefix}event_type` WHERE event_type_id = %d",
			(int) ( $event_row['eve_type'] ?? 0 )
		) ) ) );
		$is_zoom = ( strtolower( trim( $event_row['eve_zoom'] ?? '' ) ) === 'yes' );

		if ( $is_zoom ) {
			$type_label = 'ZOOM';
		} elseif ( strpos( $type_raw, 'management' ) !== false ) {
			$type_label = 'Management';
		} elseif ( strpos( $type_raw, 'writing' ) !== false ) {
			$type_label = 'Writing';
		} else {
			$type_label = '';
		}

		$parts = array_filter( array(
			'Roster',
			$event_row['eve_location'] ?? 'Event #' . $eve_id,
			$type_label,
		) );

		return implode( ' – ', $parts );
	}
}
