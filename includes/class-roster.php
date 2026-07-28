<?php
/**
 * Roster report — fetch, cache, and normalize CVENT registration data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Roster {

	const CACHE_PREFIX = 'hostlinks_roster_v2_';

	const ATTENDEES_CACHE_PREFIX = 'hostlinks_roster_att_v1_';

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
			delete_transient( self::ATTENDEES_CACHE_PREFIX . md5( $cvent_id ) );
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

	public static function build_rows( array $order_items, bool $is_past, string $cvent_id = '' ): array {
		if ( empty( $order_items ) ) {
			return array();
		}

		$cache_ttl     = $is_past ? 0 : 24 * HOUR_IN_SECONDS;
		$attendees_map = self::resolve_attendees_map( $order_items, $cvent_id, $cache_ttl );
		$meta_by_id    = self::aggregate_order_meta( $order_items );
		$rows          = array();

		foreach ( $attendees_map as $uuid => $att ) {
			$status = $att['status'] ?? $att['attendeeStatus'] ?? '';
			if ( in_array( $status, self::SKIP_STATUSES, true ) ) {
				continue;
			}

			$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
			$meta    = $meta_by_id[ $uuid ] ?? self::empty_order_meta();

			// Work city/state: CVENT returns these as top-level fields on the attendee record.
			$work_city  = trim( (string) ( $att['workCity']  ?? $contact['workCity']  ?? '' ) );
			$work_state = self::format_work_state( $att['workState'] ?? $contact['workState'] ?? '' );

			$rows[] = array(
				'last'              => $att['lastName']    ?? $contact['lastName']    ?? '',
				'first'             => $att['firstName']   ?? $contact['firstName']   ?? '',
				'company'           => $att['companyName'] ?? $contact['company']     ?? $contact['companyName'] ?? '',
				'title'             => $att['title']       ?? $contact['title']       ?? '',
				'email'             => $att['email']       ?? $contact['email']       ?? '',
				'work_phone'        => self::format_phone( $att['workPhone'] ?? $contact['workPhone'] ?? $att['phone'] ?? '' ),
				'mobile_phone'      => self::format_phone( $att['mobilePhone'] ?? $contact['mobilePhone'] ?? '' ),
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

	/**
	 * Resolve full attendee records keyed by UUID — mirrors the v2.11.2 approach that worked.
	 *
	 * Strategy:
	 *  1. Pull attendee stubs out of order items (expand=attendee may already include names).
	 *  2. If the first item's attendee already has firstName/lastName, use those directly.
	 *  3. Otherwise fall back to GET /attendees/{uuid} (no expand) which returns top-level
	 *     firstName, lastName, email, workPhone, workCity, workState etc.
	 */
	public static function resolve_attendees_map( array $order_items, string $cvent_id = '', int $cache_ttl = 86400 ): array {
		if ( $cvent_id !== '' ) {
			$att_key = self::ATTENDEES_CACHE_PREFIX . md5( $cvent_id );
			$cached  = get_transient( $att_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

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

		// Check if expand=attendee gave us real name data.
		$sample       = $order_items[0]['attendee'] ?? array();
		$expand_works = isset( $sample['firstName'] ) || isset( $sample['lastName'] ) || isset( $sample['contact'] );

		if ( ! $expand_works ) {
			// Fallback: fetch each attendee individually (no expand — top-level fields include names).
			foreach ( array_keys( $map ) as $uuid ) {
				$fetched = Hostlinks_CVENT_API::get_attendee( $uuid );
				if ( is_wp_error( $fetched ) ) {
					continue;
				}
				if ( isset( $fetched['data'] ) && is_array( $fetched['data'] ) ) {
					$fetched = $fetched['data'];
				}
				if ( is_array( $fetched ) ) {
					$map[ $uuid ] = $fetched;
				}
			}
		}

		if ( $cvent_id !== '' && ! empty( $map ) ) {
			set_transient( self::ATTENDEES_CACHE_PREFIX . md5( $cvent_id ), $map, $cache_ttl );
		}

		return $map;
	}

	/** Whether an attendee payload includes a usable name or email. */
	public static function attendee_has_identity( array $att ): bool {
		return ! empty( $att['firstName'] ) || ! empty( $att['lastName'] ) || ! empty( $att['email'] );
	}

	/**
	 * Work city/state — read top-level fields returned by GET /attendees/{uuid}.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function extract_work_location( array $att ): array {
		$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
		foreach ( array( $att, $contact ) as $src ) {
			$city  = trim( (string) ( $src['workCity']  ?? '' ) );
			$state = self::format_work_state( $src['workState'] ?? '' );
			if ( $city !== '' || $state !== '' ) {
				return array( $city, $state );
			}
		}
		return array( '', '' );
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
