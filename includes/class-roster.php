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

	/** Optional column CSS suffixes (hidden until toggled). */
	const OPTIONAL_COLS = array(
		'participant',
		'email',
		'status',
		'work_phone',
		'mobile_phone',
		'amount_ordered',
		'amount_paid',
		'discounts_applied',
		'balance_due',
		'payment_type',
		'work_city',
		'work_state',
		'discount_code',
	);

	/** Registrant-details preset (matches CVENT optional columns). */
	const DETAILS_PRESET = array(
		'participant', 'email', 'status', 'work_phone', 'mobile_phone',
		'amount_ordered', 'amount_paid', 'discounts_applied', 'balance_due',
		'payment_type', 'work_city', 'work_state', 'discount_code',
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
				'items'      => $cached,
				'is_past'    => $is_past,
				'from_cache' => true,
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

	public static function build_rows( array $order_items, bool $is_past ): array {
		if ( empty( $order_items ) ) {
			return array();
		}

		$attendees_map = self::resolve_attendees_map( $order_items );
		$meta_by_id    = self::aggregate_order_meta( $order_items );
		$rows          = array();

		foreach ( $attendees_map as $uuid => $att ) {
			$status = $att['status'] ?? $att['inviteeStatus'] ?? $att['attendeeStatus'] ?? '';
			if ( in_array( $status, self::SKIP_STATUSES, true ) ) {
				continue;
			}

			$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
			$meta    = $meta_by_id[ $uuid ] ?? self::empty_order_meta();

			list( $work_city, $work_state ) = self::extract_work_location( $att );

			$rows[] = array(
				'last'              => $att['lastName']    ?? $contact['lastName']    ?? '',
				'first'             => $att['firstName']   ?? $contact['firstName']   ?? '',
				'company'           => $att['companyName'] ?? $contact['company']     ?? $contact['companyName'] ?? '',
				'title'             => $att['title']       ?? $contact['title']       ?? '',
				'email'             => $att['email']       ?? $contact['email']       ?? '',
				'work_phone'        => self::format_phone( self::extract_phone( $att, 'work' ) ),
				'mobile_phone'      => self::format_phone( self::extract_phone( $att, 'mobile' ) ),
				'status'            => $status,
				'participant'       => $is_past ? self::participant_label( $att ) : '',
				'amount_ordered'    => self::format_money( $meta['amount_ordered'], true ),
				'amount_paid'       => self::format_money( $meta['amount_paid'], true ),
				'discounts_applied' => self::format_money( $meta['discount_applied'], true ),
				'balance_due'       => self::format_money( $meta['balance_due'], false ),
				'payment_type'      => implode( ', ', $meta['payment_types'] ),
				'discount_code'     => implode( ', ', $meta['discounts'] ),
				'work_city'         => $work_city,
				'work_state'        => $work_state,
			);
		}

		usort( $rows, function( $a, $b ) {
			$c = strcasecmp( $a['last'], $b['last'] );
			return $c !== 0 ? $c : strcasecmp( $a['first'], $b['first'] );
		} );

		return $rows;
	}

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

		$sample       = $order_items[0]['attendee'] ?? array();
		$expand_works = isset( $sample['firstName'] ) || isset( $sample['lastName'] ) || isset( $sample['contact'] );

		if ( ! $expand_works ) {
			foreach ( array_keys( $map ) as $uuid ) {
				$att = Hostlinks_CVENT_API::get_attendee( $uuid, 'contact' );
				if ( is_wp_error( $att ) ) {
					continue;
				}
				if ( isset( $att['data'] ) && is_array( $att['data'] ) ) {
					$att = $att['data'];
				}
				$map[ $uuid ] = self::merge_attendee_contact( $map[ $uuid ], $att );
			}
			self::enrich_work_location_from_contacts( $map );
			return $map;
		}

		// Expand worked for names but often omits contact.workAddress — enrich individually when needed.
		foreach ( array_keys( $map ) as $uuid ) {
			if ( ! self::needs_contact_enrich( $map[ $uuid ] ) ) {
				continue;
			}
			$att = Hostlinks_CVENT_API::get_attendee( $uuid, 'contact' );
			if ( is_wp_error( $att ) ) {
				continue;
			}
			if ( isset( $att['data'] ) && is_array( $att['data'] ) ) {
				$att = $att['data'];
			}
			$map[ $uuid ] = self::merge_attendee_contact( $map[ $uuid ], $att );
		}

		self::enrich_work_location_from_contacts( $map );

		return $map;
	}

	/**
	 * Fetch full contact records when work city/state are still missing.
	 *
	 * @param array<string,array> $map Attendee map keyed by UUID (modified in place).
	 */
	private static function enrich_work_location_from_contacts( array &$map ): void {
		static $contact_cache = array();

		foreach ( $map as $uuid => $att ) {
			list( $city, $state ) = self::extract_work_location( $att );
			if ( $city !== '' || $state !== '' ) {
				continue;
			}

			$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
			$cid     = Hostlinks_CVENT_API::sanitize_uuid( (string) ( $contact['id'] ?? $att['contactId'] ?? '' ) );
			if ( $cid === '' ) {
				continue;
			}

			if ( ! isset( $contact_cache[ $cid ] ) ) {
				$fetched = Hostlinks_CVENT_API::get_contact( $cid );
				if ( is_wp_error( $fetched ) ) {
					$contact_cache[ $cid ] = null;
					continue;
				}
				if ( isset( $fetched['data'] ) && is_array( $fetched['data'] ) ) {
					$fetched = $fetched['data'];
				}
				$contact_cache[ $cid ] = is_array( $fetched ) ? $fetched : null;
			}

			if ( empty( $contact_cache[ $cid ] ) ) {
				continue;
			}

			$map[ $uuid ] = self::merge_attendee_contact( $att, array( 'contact' => $contact_cache[ $cid ] ) );
		}
	}

	/**
	 * Merge a fetched attendee/contact payload into an existing attendee row.
	 */
	private static function merge_attendee_contact( array $existing, array $fetched ): array {
		$merged = array_merge( $existing, $fetched );
		$exist_contact = is_array( $existing['contact'] ?? null ) ? $existing['contact'] : array();
		$fetch_contact = is_array( $fetched['contact'] ?? null ) ? $fetched['contact'] : array();
		if ( ! empty( $fetch_contact ) ) {
			$merged['contact'] = array_merge( $exist_contact, $fetch_contact );
		}
		return $merged;
	}

	private static function needs_contact_enrich( array $att ): bool {
		list( $city, $state ) = self::extract_work_location( $att );
		if ( $city !== '' || $state !== '' ) {
			return false;
		}
		$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
		return empty( $contact['workAddress'] ) && empty( $contact['workCity'] );
	}

	/**
	 * Work city/state from contact.workAddress (CVENT REST standard).
	 *
	 * @return array{0:string,1:string} city, state (2-letter when possible)
	 */
	public static function extract_work_location( array $att ): array {
		$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();

		foreach ( array( $att, $contact ) as $src ) {
			if ( empty( $src['workAddress'] ) ) {
				continue;
			}
			$first = self::first_address_entry( $src['workAddress'] );
			$city  = trim( (string) ( $first['city'] ?? $first['locality'] ?? '' ) );
			$state = trim( (string) ( $first['regionCode'] ?? $first['region'] ?? $first['stateCode'] ?? '' ) );
			if ( $city !== '' || $state !== '' ) {
				return array( $city, self::format_work_state( $state ) );
			}
		}

		foreach ( array( $att, $contact ) as $src ) {
			$city  = trim( (string) ( $src['workCity'] ?? '' ) );
			$state = trim( (string) ( $src['workState'] ?? $src['workStateCode'] ?? '' ) );
			if ( $city !== '' || $state !== '' ) {
				return array( $city, self::format_work_state( $state ) );
			}
		}

		list( $ans_city, $ans_state ) = self::extract_work_location_from_answers( $att );
		if ( $ans_city !== '' || $ans_state !== '' ) {
			return array( $ans_city, self::format_work_state( $ans_state ) );
		}

		return array( '', '' );
	}

	/** @param mixed $address CVENT workAddress object or array of objects. */
	private static function first_address_entry( $address ): array {
		if ( ! is_array( $address ) ) {
			return array();
		}
		if ( isset( $address[0] ) && is_array( $address[0] ) ) {
			return $address[0];
		}
		if ( isset( $address['city'] ) || isset( $address['region'] ) || isset( $address['regionCode'] ) ) {
			return $address;
		}
		return array();
	}

	/**
	 * Some events store work city/state as registration answers instead of contact fields.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function extract_work_location_from_answers( array $att ): array {
		$answers = $att['answers'] ?? array();
		if ( ! is_array( $answers ) ) {
			return array( '', '' );
		}

		$city  = '';
		$state = '';

		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			$q_text = strtolower( trim( (string) (
				$answer['question']['name']
				?? $answer['question']['text']
				?? $answer['questionText']
				?? ''
			) ) );
			if ( $q_text === '' ) {
				continue;
			}

			$value = self::answer_value( $answer );
			if ( $value === '' ) {
				continue;
			}

			if ( $city === '' && ( false !== strpos( $q_text, 'work city' ) || $q_text === 'city' ) ) {
				$city = $value;
			}
			if ( $state === '' && (
				false !== strpos( $q_text, 'work state' )
				|| false !== strpos( $q_text, 'state/province' )
				|| $q_text === 'state'
			) ) {
				$state = $value;
			}
		}

		return array( $city, $state );
	}

	private static function answer_value( array $answer ): string {
		if ( ! empty( $answer['value'] ) ) {
			if ( is_array( $answer['value'] ) ) {
				return trim( implode( ', ', array_filter( array_map( 'strval', $answer['value'] ) ) ) );
			}
			return trim( (string) $answer['value'] );
		}
		if ( ! empty( $answer['values'] ) && is_array( $answer['values'] ) ) {
			return trim( implode( ', ', array_filter( array_map( 'strval', $answer['values'] ) ) ) );
		}
		return '';
	}

	private static function extract_phone( array $att, string $type ): string {
		$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
		$key     = ( $type === 'mobile' ) ? 'mobilePhone' : 'workPhone';

		foreach ( array( $att, $contact ) as $src ) {
			if ( ! empty( $src[ $key ] ) ) {
				return (string) $src[ $key ];
			}
		}

		if ( $type === 'work' && ! empty( $att['phone'] ) ) {
			return (string) $att['phone'];
		}

		return '';
	}

	private static function empty_order_meta(): array {
		return array(
			'discounts'       => array(),
			'balance_due'     => 0.0,
			'amount_ordered'  => 0.0,
			'amount_paid'     => 0.0,
			'discount_applied'=> 0.0,
			'payment_types'   => array(),
			'order_nums'      => array(),
		);
	}

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
				$meta[ $uuid ] = self::empty_order_meta();
			}

			foreach ( self::extract_item_discounts( $item ) as $code ) {
				$meta[ $uuid ]['discounts'][ $code ] = $code;
			}

			$att_discounts = Hostlinks_CVENT_Sync::extract_discount_strings( $item['attendee'] ?? array() );
			foreach ( $att_discounts as $code ) {
				$meta[ $uuid ]['discounts'][ $code ] = $code;
			}

			$meta[ $uuid ]['amount_ordered']   += self::parse_money( $item['amount'] ?? $item['amountOrdered'] ?? $item['orderAmount'] ?? 0 );
			$meta[ $uuid ]['amount_paid']      += self::parse_money( $item['amountPaid'] ?? 0 );
			$meta[ $uuid ]['discount_applied'] += self::parse_money( $item['discountAmount'] ?? $item['discountsApplied'] ?? 0 );
			$meta[ $uuid ]['balance_due']      += self::parse_money( $item['amountDue'] ?? $item['balanceDue'] ?? 0 );

			foreach ( array( 'paymentType', 'paymentTypes', 'paymentMethod' ) as $pt_key ) {
				if ( empty( $item[ $pt_key ] ) ) {
					continue;
				}
				$val = $item[ $pt_key ];
				if ( is_array( $val ) ) {
					foreach ( $val as $pt ) {
						$label = is_string( $pt ) ? $pt : ( $pt['type'] ?? $pt['name'] ?? '' );
						if ( $label !== '' ) {
							$meta[ $uuid ]['payment_types'][ $label ] = $label;
						}
					}
				} else {
					$meta[ $uuid ]['payment_types'][ (string) $val ] = (string) $val;
				}
			}

			$order_num = trim( (string) ( $item['orderNumber'] ?? $item['orderId'] ?? '' ) );
			if ( $order_num !== '' ) {
				$meta[ $uuid ]['order_nums'][ $order_num ] = $order_num;
			}
		}

		return $meta;
	}

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

	/** @param bool $show_zero Show 0.00 (CVENT-style amount columns). */
	public static function format_money( float $amount, bool $show_zero = false ): string {
		if ( $amount <= 0 && ! $show_zero ) {
			return '';
		}
		return number_format( $amount, 2 );
	}

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

	/**
	 * Render roster table HTML (sign-in sheet base + optional detail columns).
	 *
	 * @param string $prefix CSS prefix: hl-fe or hl
	 */
	public static function render_table( array $rows, bool $is_past, string $prefix = 'hl-fe' ): string {
		if ( empty( $rows ) ) {
			return '<p style="color:#888;padding:20px 0;">No registered attendees found for this event.</p>';
		}

		$col = function( string $slug ) use ( $prefix ): string {
			return $prefix . '-col-' . str_replace( '_', '-', $slug );
		};

		ob_start();
		?>
		<table class="<?php echo esc_attr( $prefix ); ?>-roster-table">
			<thead>
				<tr>
					<th>#</th>
					<?php if ( $is_past ) : ?>
					<th class="<?php echo esc_attr( $col( 'participant' ) ); ?>">Participant</th>
					<?php endif; ?>
					<th>Last Name</th>
					<th>First Name</th>
					<th class="<?php echo esc_attr( $col( 'email' ) ); ?>">Email Address</th>
					<th>Company / Agency</th>
					<th>Title</th>
					<th class="<?php echo esc_attr( $col( 'status' ) ); ?>">Invitee Status</th>
					<th class="<?php echo esc_attr( $col( 'work_phone' ) ); ?>">Work Phone</th>
					<th class="<?php echo esc_attr( $col( 'mobile_phone' ) ); ?>">Mobile Phone</th>
					<th class="<?php echo esc_attr( $col( 'amount_ordered' ) ); ?>">Amount Ordered</th>
					<th class="<?php echo esc_attr( $col( 'amount_paid' ) ); ?>">Amount Paid</th>
					<th class="<?php echo esc_attr( $col( 'discounts_applied' ) ); ?>">Discounts Applied</th>
					<th class="<?php echo esc_attr( $col( 'balance_due' ) ); ?>">Amount Due</th>
					<th class="<?php echo esc_attr( $col( 'payment_type' ) ); ?>">Payment Type</th>
					<th class="<?php echo esc_attr( $col( 'work_city' ) ); ?>">Work City</th>
					<th class="<?php echo esc_attr( $col( 'work_state' ) ); ?>">Work State</th>
					<th class="<?php echo esc_attr( $col( 'discount_code' ) ); ?>">Discount Code</th>
					<th class="<?php echo esc_attr( $prefix ); ?>-sign-in">Sign In</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $i => $row ) : ?>
				<tr>
					<td class="<?php echo esc_attr( $prefix ); ?>-num"><?php echo (int) ( $i + 1 ); ?></td>
					<?php if ( $is_past ) : ?>
					<td class="<?php echo esc_attr( $col( 'participant' ) ); ?>"><?php echo esc_html( $row['participant'] ); ?></td>
					<?php endif; ?>
					<td><?php echo esc_html( $row['last'] ); ?></td>
					<td><?php echo esc_html( $row['first'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'email' ) ); ?>"><?php echo esc_html( $row['email'] ); ?></td>
					<td><?php echo esc_html( $row['company'] ); ?></td>
					<td><?php echo esc_html( $row['title'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'status' ) ); ?>"><?php echo esc_html( $row['status'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'work_phone' ) ); ?>"><?php echo esc_html( $row['work_phone'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'mobile_phone' ) ); ?>"><?php echo esc_html( $row['mobile_phone'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'amount_ordered' ) ); ?>"><?php echo esc_html( $row['amount_ordered'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'amount_paid' ) ); ?>"><?php echo esc_html( $row['amount_paid'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'discounts_applied' ) ); ?>"><?php echo esc_html( $row['discounts_applied'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'balance_due' ) ); ?>"><?php echo esc_html( $row['balance_due'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'payment_type' ) ); ?>"><?php echo esc_html( $row['payment_type'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'work_city' ) ); ?>"><?php echo esc_html( $row['work_city'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'work_state' ) ); ?>"><?php echo esc_html( $row['work_state'] ); ?></td>
					<td class="<?php echo esc_attr( $col( 'discount_code' ) ); ?>"><?php echo esc_html( $row['discount_code'] ); ?></td>
					<td class="<?php echo esc_attr( $prefix ); ?>-sign-in">&nbsp;</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo self::render_totals( $rows, $prefix ); ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Financial summary cards (matches CVENT registrant-details footer).
	 */
	public static function render_totals( array $rows, string $prefix = 'hl-fe' ): string {
		$keys = array(
			'amount_ordered'    => 'Amount Ordered',
			'amount_paid'       => 'Amount Paid',
			'discounts_applied' => 'Discounts Applied',
			'balance_due'       => 'Amount Due',
		);

		$stats = array();
		foreach ( $keys as $key => $label ) {
			$vals = array();
			foreach ( $rows as $row ) {
				$raw = trim( (string) ( $row[ $key ] ?? '' ) );
				if ( $raw === '' ) {
					continue;
				}
				$vals[] = (float) str_replace( ',', '', $raw );
			}
			if ( empty( $vals ) ) {
				continue;
			}
			$stats[ $key ] = array(
				'label'   => $label,
				'average' => array_sum( $vals ) / count( $vals ),
				'maximum' => max( $vals ),
				'minimum' => min( $vals ),
				'sum'     => array_sum( $vals ),
			);
		}

		if ( empty( $stats ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $prefix ); ?>-roster-totals">
			<?php foreach ( $stats as $slug => $stat ) :
				$col = $prefix . '-col-' . str_replace( '_', '-', $slug );
			?>
			<div class="<?php echo esc_attr( $prefix ); ?>-roster-total-card <?php echo esc_attr( $col ); ?>">
				<strong><?php echo esc_html( $stat['label'] ); ?></strong>
				<span>Avg <?php echo esc_html( number_format( $stat['average'], 2 ) ); ?></span>
				<span>Max <?php echo esc_html( number_format( $stat['maximum'], 2 ) ); ?></span>
				<span>Min <?php echo esc_html( number_format( $stat['minimum'], 2 ) ); ?></span>
				<span>Sum <?php echo esc_html( number_format( $stat['sum'], 2 ) ); ?></span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function optional_col_css( string $prefix = 'hl-fe' ): string {
		$selectors = array();
		foreach ( self::OPTIONAL_COLS as $slug ) {
			$selectors[] = '.' . $prefix . '-col-' . str_replace( '_', '-', $slug );
		}
		return implode( ', ', $selectors ) . ' { display:none; }';
	}

	public static function optional_col_visible_css( string $prefix = 'hl-fe' ): string {
		$rules = array();
		foreach ( self::OPTIONAL_COLS as $slug ) {
			$cls = $prefix . '-col-' . str_replace( '_', '-', $slug );
			$rules[] = '.' . $cls . '.' . $prefix . '-col-visible';
		}
		return implode( ', ', $rules ) . ' { display:table-cell !important; }';
	}
}
