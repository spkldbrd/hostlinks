<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CVENT sync orchestration.
 *
 * Flow for each Hostlinks event:
 *   1. If cvent_event_id is stored → verify it still exists (GET /ea/events/{id}).
 *      On 404, clear the stored ID and fall through to bootstrap.
 *   2. If no ID → bootstrap_match() to find the best CVENT candidate.
 *      Auto-match (score >= 90, gap >= 20) or flag needs_review.
 *   3. If status is 'auto' or 'manual' → fetch attendees, filter cancelled/test,
 *      extract discount strings, count PAID/FREE, update DB.
 *
 * Attendee endpoint:
 *   GET /ea/attendees/filter?filter=eventId eq '{id}'&limit=200
 *
 * PAID/FREE rule:
 *   extract_discount_strings() → if any string contains /free/i → FREE, else PAID.
 */
class Hostlinks_CVENT_Sync {

	/**
	 * Registration statuses treated as valid (case-insensitive comparison).
	 * Records whose status is NOT in this list are excluded from counts.
	 * Adjust if CVENT returns different status strings in your account.
	 */
	private static $valid_statuses = array(
		'registered',
		'confirmed',
		'attending',
		'checked in',
		'checkedin',
		'active',
	);

	// -------------------------------------------------------------------------
	// Public: find new CVENT events not yet in Hostlinks
	// -------------------------------------------------------------------------

	/**
	 * Find CVENT events that are not yet in Hostlinks and not dismissed by admin.
	 *
	 * Fetches all active CVENT events (60-day lookback + future), then excludes:
	 *   - Events already linked via cvent_event_id in event_details_list.
	 *   - Events the admin has permanently ignored.
	 *
	 * Results are cached in a 1-hour transient. The option
	 * 'hostlinks_cvent_new_count' is kept in sync for badge/notice display.
	 *
	 * @param bool $force  If true, bypass the transient cache and re-fetch.
	 * @return array|WP_Error  Flat array of CVENT event objects, or WP_Error on failure.
	 */
	public static function find_new_events( $force = false ) {
		$transient_key = 'hostlinks_cvent_new_events';

		if ( ! $force ) {
			$cached = get_transient( $transient_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// 1 API call — same request used by the regular sync.
		$response = Hostlinks_CVENT_API::list_active_events( 60 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// list_active_events returns the full paged wrapper; data is in ['data'].
		$all_events = isset( $response['data'] ) ? $response['data'] : $response;
		if ( ! is_array( $all_events ) ) {
			$all_events = array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'event_details_list';

		// One query to get all already-linked CVENT UUIDs.
		$linked_raw = $wpdb->get_col(
			"SELECT DISTINCT cvent_event_id FROM `{$table}`
			 WHERE cvent_event_id IS NOT NULL AND cvent_event_id != ''"
		);
		$linked_set = array_flip( array_map( 'trim', $linked_raw ) );

		// Admin-dismissed UUIDs.
		$ignored     = (array) get_option( 'hostlinks_cvent_ignored_events', array() );
		$ignored_set = array_flip( $ignored );

		$new_events = array();
		foreach ( $all_events as $ev ) {
			$uuid = Hostlinks_CVENT_API::sanitize_uuid( $ev['id'] ?? '' );
			if ( ! $uuid ) {
				continue;
			}
			if ( isset( $linked_set[ $uuid ] ) || isset( $ignored_set[ $uuid ] ) ) {
				continue;
			}
			$new_events[] = $ev;
		}

		set_transient( $transient_key, $new_events, HOUR_IN_SECONDS );
		update_option( 'hostlinks_cvent_new_count', count( $new_events ) );
		update_option( 'hostlinks_cvent_last_new_fetch', current_time( 'mysql' ) );

		return $new_events;
	}

	// -------------------------------------------------------------------------
	// Public: sync one event
	// -------------------------------------------------------------------------

	/**
	 * Sync a single Hostlinks event against CVENT.
	 *
	 * @param int  $eve_id   Row ID in event_details_list.
	 * @param bool $dry_run  If true, run full logic but write nothing to the DB.
	 * @return array {
	 *   eve_id            : int,
	 *   dry_run           : bool,
	 *   action            : 'synced'|'matched'|'needs_review'|'no_candidates'|'skipped'|'error',
	 *   message           : string,
	 *   paid              : int|null,
	 *   free              : int|null,
	 *   cvent_title       : string|null,
	 *   cvent_id          : string|null,
	 *   score             : int|null,
	 *   // dry-run extras:
	 *   candidates        : array|null   scored match candidates
	 *   attendees_preview : array|null   first 10 valid attendees with discount info
	 *   filtered_out      : int|null     attendees removed by status filter
	 * }
	 */
	public static function sync_one( $eve_id, $dry_run = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'event_details_list';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE eve_id = %d AND eve_status = 1", $eve_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return self::result( $eve_id, 'error', 'Event not found or inactive.', dry_run: $dry_run );
		}

		// Enrich row with type name for scorer (eve_zoom is already present from SELECT *).
		$type_name = $wpdb->get_var( $wpdb->prepare(
			"SELECT event_type_name FROM `{$wpdb->prefix}event_type` WHERE event_type_id = %d",
			(int) $row['eve_type']
		) );
		$row['eve_type_name'] = strtolower( trim( $type_name ?? '' ) );

		// Skip PRIVATE-marketer events — they are not listed in CVENT.
		$marketer_name = $wpdb->get_var( $wpdb->prepare(
			"SELECT event_marketer_name FROM `{$wpdb->prefix}event_marketer` WHERE event_marketer_id = %d",
			(int) ( $row['eve_marketer'] ?? 0 )
		) );
		if ( strtoupper( trim( $marketer_name ?? '' ) ) === 'PRIVATE' ) {
			return self::result( $eve_id, 'no_candidates', 'Skipped — Private event (not in CVENT).', dry_run: $dry_run );
		}

		// Skip events whose location contains "| private" (with or without space,
		// any case). These are private/internal events stored with a pipe modifier
		// in HL (e.g. "GM MT Zoom | Private") and are not listed in CVENT.
		if ( preg_match( '/\|\s*private\b/i', $row['eve_location'] ?? '' ) ) {
			return self::result( $eve_id, 'no_candidates', 'Skipped — Private event (location contains "| Private").', dry_run: $dry_run );
		}

		// Always capture current HL counts for comparison display in every result.
		$hl_paid = (int) ( $row['eve_paid'] ?? 0 );
		$hl_free = (int) ( $row['eve_free'] ?? 0 );

		$stored_id = Hostlinks_CVENT_API::sanitize_uuid( $row['cvent_event_id'] ?? '' );
		$status    = $row['cvent_match_status'] ?? 'unlinked';

		// ── Step 1: verify stored CVENT ID ───────────────────────────────────
		if ( $stored_id && in_array( $status, array( 'auto', 'manual' ), true ) ) {
			$check = Hostlinks_CVENT_API::get_event( $stored_id );
			if ( is_wp_error( $check ) && strpos( $check->get_error_message(), 'HTTP 404' ) !== false ) {
				// Stored event gone — clear mapping and re-bootstrap.
				if ( ! $dry_run ) {
					self::clear_cvent_mapping( $eve_id );
				}
				$stored_id = '';
				$status    = 'unlinked';
			} elseif ( is_wp_error( $check ) ) {
				return self::result( $eve_id, 'error', $check->get_error_message(), hl_paid: $hl_paid, hl_free: $hl_free, dry_run: $dry_run );
			} else {
				// Verify staleness hash hasn't changed drastically.
				$new_hash = Hostlinks_CVENT_Matcher::staleness_hash( $check );
				if ( $new_hash !== ( $row['cvent_staleness_hash'] ?? '' ) ) {
					if ( ! $dry_run ) {
						$wpdb->update(
							$table,
							array( 'cvent_match_status' => 'needs_review', 'cvent_staleness_hash' => $new_hash ),
							array( 'eve_id' => $eve_id ),
							array( '%s', '%s' ),
							array( '%d' )
						);
					}
					$status = 'needs_review';
				}
			}
		}

		// ── Step 2: bootstrap if no confirmed ID ─────────────────────────────
		if ( ! $stored_id || ! in_array( $status, array( 'auto', 'manual' ), true ) ) {
			$match = Hostlinks_CVENT_Matcher::bootstrap_match( $row );

			if ( 'error' === $match['status'] ) {
				return self::result( $eve_id, 'error', $match['error'], hl_paid: $hl_paid, hl_free: $hl_free, dry_run: $dry_run );
			}

			if ( 'no_candidates' === $match['status'] ) {
				return self::result( $eve_id, 'no_candidates', 'No CVENT events found in date window.', hl_paid: $hl_paid, hl_free: $hl_free, dry_run: $dry_run );
			}

			$best  = $match['best'];
			$score = $match['best_score'];
			$hash  = Hostlinks_CVENT_Matcher::staleness_hash( $best );

			if ( ! $dry_run ) {
				$wpdb->update(
					$table,
					array(
						'cvent_event_id'        => $best['id'],
						'cvent_event_title'     => $best['title'] ?? '',
						'cvent_event_start_utc' => isset( $best['start'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $best['start'] ) ) : null,
						'cvent_match_score'     => $score,
						'cvent_match_status'    => $match['status'],
						'cvent_staleness_hash'  => $hash,
					),
					array( 'eve_id' => $eve_id ),
					array( '%s', '%s', '%s', '%d', '%s', '%s' ),
					array( '%d' )
				);
			}

			if ( 'needs_review' === $match['status'] ) {
				$r = self::result(
					$eve_id,
					'needs_review',
					sprintf( 'Best candidate "%s" scored %d — needs manual review.', $best['title'] ?? '(no title)', $score ),
					null, null, $best['title'] ?? '', $best['id'], $score,
					dry_run: $dry_run,
					hl_paid: $hl_paid,
					hl_free: $hl_free
				);
				$r['candidates'] = $match['candidates'];
				return $r;
			}

		$stored_id = Hostlinks_CVENT_API::sanitize_uuid( $best['id'] );
		$status    = 'auto';

			// In dry-run, also preview the attendee counts for the auto-matched event.
			if ( $dry_run ) {
				$count_preview = self::do_count_sync( $eve_id, $stored_id, $row, true );
				// Surface attendee-fetch errors rather than silently showing "no CVENT count".
				$count_error = ( ( $count_preview['action'] ?? '' ) === 'error' ) ? $count_preview['message'] : null;
				$r = self::result( $eve_id, 'matched',
					sprintf( 'DRY RUN — would auto-match to "%s" (score %d).', $best['title'] ?? '', $score ),
					$count_preview['paid'] ?? null, $count_preview['free'] ?? null,
					$best['title'] ?? '', $stored_id, $score, dry_run: true
				);
				$r['candidates']        = $match['candidates'];
				$r['attendees_preview'] = $count_preview['attendees_preview'] ?? null;
				$r['filtered_out']      = $count_preview['filtered_out'] ?? null;
				$r['total_fetched']     = $count_preview['total_fetched'] ?? null;
				$r['count_fetch_error'] = $count_error;
				$r['hl_paid']           = (int) ( $row['eve_paid'] ?? 0 );
				$r['hl_free']           = (int) ( $row['eve_free'] ?? 0 );
				return $r;
			}

			return self::result( $eve_id, 'matched', sprintf( 'Auto-matched to "%s" (score %d). Run sync again to update counts.', $best['title'] ?? '', $score ), null, null, $best['title'] ?? '', $stored_id, $score, hl_paid: $hl_paid, hl_free: $hl_free );
		}

		// ── Step 3: fetch attendees and count PAID/FREE ───────────────────────
		return self::do_count_sync( $eve_id, $stored_id, $row, $dry_run );
	}

	// -------------------------------------------------------------------------
	// Public: sync all active events
	// -------------------------------------------------------------------------

	/**
	 * Sync all active Hostlinks events.
	 *
	 * @param bool $dry_run  If true, run full logic but write nothing to the DB.
	 * @return array {
	 *   results      : array of sync_one() result arrays,
	 *   dry_run      : bool,
	 *   synced       : int,
	 *   matched      : int,
	 *   needs_review : int,
	 *   no_candidates: int,
	 *   errors       : int,
	 * }
	 */
	/**
	 * @param bool $dry_run  If true, no DB writes are performed.
	 * @param int  $limit    When > 0, restrict to the next $limit upcoming events
	 *                       (eve_start >= today, ordered earliest first). Intended
	 *                       for dry-run test runs to minimise API call usage.
	 */
	public static function sync_all( $dry_run = false, $limit = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'event_details_list';

		$mktr = $wpdb->prefix . 'event_marketer';

		if ( $limit > 0 ) {
			// Limit mode: only upcoming events (starting today or later), nearest first.
			// Excludes PRIVATE-marketer events and events whose location contains "| Private".
			$today = gmdate( 'Y-m-d' );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT edl.eve_id FROM `{$table}` edl
					 LEFT JOIN `{$mktr}` m ON m.event_marketer_id = edl.eve_marketer
					 WHERE edl.eve_status = 1
					   AND edl.eve_start >= %s
					   AND (m.event_marketer_name IS NULL OR UPPER(m.event_marketer_name) != 'PRIVATE')
					   AND UPPER(edl.eve_location) NOT LIKE '%|PRIVATE%'
					   AND UPPER(edl.eve_location) NOT LIKE '%| PRIVATE%'
					 ORDER BY edl.eve_start ASC
					 LIMIT %d",
					$today,
					$limit
				),
				ARRAY_A
			);
		} else {
			// Normal mode: all events ending within the last 60 days or in the future.
			// Excludes PRIVATE-marketer events and events whose location contains "| Private".
			$cutoff = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT edl.eve_id FROM `{$table}` edl
					 LEFT JOIN `{$mktr}` m ON m.event_marketer_id = edl.eve_marketer
					 WHERE edl.eve_status = 1
					   AND edl.eve_end >= %s
					   AND (m.event_marketer_name IS NULL OR UPPER(m.event_marketer_name) != 'PRIVATE')
					   AND UPPER(edl.eve_location) NOT LIKE '%|PRIVATE%'
					   AND UPPER(edl.eve_location) NOT LIKE '%| PRIVATE%'",
					$cutoff
				),
				ARRAY_A
			);
		}

		$results = array();
		$counts  = array( 'synced' => 0, 'matched' => 0, 'needs_review' => 0, 'no_candidates' => 0, 'errors' => 0 );

		foreach ( $rows as $r ) {
			$res       = self::sync_one( (int) $r['eve_id'], $dry_run );
			$results[] = $res;
			$action    = $res['action'] ?? 'error';
			if ( isset( $counts[ $action ] ) ) {
				$counts[ $action ]++;
			} else {
				$counts['errors']++;
			}
		}

		return array_merge( array( 'results' => $results, 'dry_run' => $dry_run ), $counts );
	}

	// -------------------------------------------------------------------------
	// Public: save a manual link
	// -------------------------------------------------------------------------

	/**
	 * Save a manually chosen CVENT event ID for a Hostlinks event.
	 *
	 * @param int    $eve_id     Hostlinks event ID.
	 * @param string $cvent_id   CVENT event UUID.
	 * @return true|WP_Error
	 */
	public static function save_manual_link( $eve_id, $cvent_id ) {
		global $wpdb;

		$cvent_event = Hostlinks_CVENT_API::get_event( $cvent_id );
		if ( is_wp_error( $cvent_event ) ) {
			return $cvent_event;
		}

		$hash = Hostlinks_CVENT_Matcher::staleness_hash( $cvent_event );
		$table = $wpdb->prefix . 'event_details_list';

		$wpdb->update(
			$table,
			array(
				'cvent_event_id'        => $cvent_id,
				'cvent_event_title'     => $cvent_event['title'] ?? '',
				'cvent_event_start_utc' => isset( $cvent_event['start'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $cvent_event['start'] ) ) : null,
				'cvent_match_score'     => null,
				'cvent_match_status'    => 'manual',
				'cvent_staleness_hash'  => $hash,
			),
			array( 'eve_id' => $eve_id ),
			array( '%s', '%s', '%s', null, '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	// -------------------------------------------------------------------------
	// Public: unlink
	// -------------------------------------------------------------------------

	/**
	 * Clear the CVENT mapping for a Hostlinks event.
	 *
	 * @param int $eve_id
	 */
	public static function clear_cvent_mapping( $eve_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'event_details_list';
		$wpdb->update(
			$table,
			array(
				'cvent_event_id'        => null,
				'cvent_event_title'     => null,
				'cvent_event_start_utc' => null,
				'cvent_match_score'     => null,
				'cvent_match_status'    => 'unlinked',
				'cvent_last_synced'     => null,
				'cvent_staleness_hash'  => null,
			),
			array( 'eve_id' => $eve_id ),
			array( null, null, null, null, '%s', null, null ),
			array( '%d' )
		);
	}

	// -------------------------------------------------------------------------
	// Attendee fetching and counting
	// -------------------------------------------------------------------------

	/**
	 * Fetch all attendees for a CVENT event, then count PAID/FREE.
	 *
	 * @param int    $eve_id
	 * @param string $cvent_id
	 * @param array  $row
	 * @param bool   $dry_run  If true, skip DB write and include attendee preview in return value.
	 * @return array
	 */
	private static function do_count_sync( $eve_id, $cvent_id, $row, $dry_run = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'event_details_list';

		// ── Step A: order items via event-scoped path ─────────────────────────
		// GET /ea/events/{UUID}/orders/items — primary source for count + discounts.
		// Flat /orders/items?filter=... returns 404. /attendees?filter=eventId=...
		// returns 400 "Unsupported filter field eventId".
		$order_items = Hostlinks_CVENT_API::get_order_items( $cvent_id );
		$orders_ok   = ! is_wp_error( $order_items );

		if ( $orders_ok ) {
		// ── Count from order items ────────────────────────────────────────
		// The API response has no 'status' field. Cancellation is indicated
		// by the boolean 'active' field: active=false means cancelled/voided.
		$seen            = array(); // attendeeId → 'free'|'paid'
		$preview         = array();
		$cancelled_count = 0; // items explicitly marked active=false

		foreach ( $order_items as $item ) {
			// Skip inactive (cancelled/voided) order items.
			if ( ! ( $item['active'] ?? true ) ) {
				$cancelled_count++;
				continue;
			}

				$att_id = $item['attendeeId']
					?? ( $item['attendee']['id'] ?? ( $item['contactId'] ?? null ) );
				if ( ! $att_id || isset( $seen[ $att_id ] ) ) {
					continue;
				}

				// Collect discount strings from every possible field location.
				$discount_strings = array();
				foreach ( array( 'discountCode', 'DiscountCode', 'discount_code', 'discountName', 'DiscountName' ) as $f ) {
					if ( ! empty( $item[ $f ] ) ) {
						$discount_strings[] = (string) $item[ $f ];
					}
				}
				if ( ! empty( $item['discounts'] ) && is_array( $item['discounts'] ) ) {
					foreach ( $item['discounts'] as $d ) {
						foreach ( array( 'code', 'name', 'discountCode' ) as $f ) {
							if ( ! empty( $d[ $f ] ) ) {
								$discount_strings[] = (string) $d[ $f ];
							}
						}
					}
				}
				$discount_strings = array_unique( $discount_strings );

				$is_free = false;
				foreach ( $discount_strings as $ds ) {
					if ( preg_match( '/free/i', $ds ) ) {
						$is_free = true;
						break;
					}
				}

				$seen[ $att_id ] = $is_free ? 'free' : 'paid';

			if ( $dry_run && count( $preview ) < 10 ) {
				$preview[] = array(
					'id'               => $att_id,
					'active'           => $item['active'] ?? true,
					'discount_strings' => $discount_strings,
					'counted_as'       => $is_free ? 'FREE' : 'PAID',
					'source'           => 'order_items',
				);
			}
			}

		$free         = count( array_filter( $seen, function( $v ) { return $v === 'free'; } ) );
		$paid         = count( $seen ) - $free;
		$total        = count( $seen );
		// $filtered_out reflects only genuinely cancelled items (active=false).
		// Duplicate order lines for the same attendee (e.g. ticket + add-on)
		// are silently deduplicated via $seen and are NOT counted as filtered.
		$filtered_out = $cancelled_count;
		$source_note  = '';

		} else {
			// ── No fallback available ─────────────────────────────────────────
			// events/{UUID}/attendees returns 404 (not a valid CVENT path).
			// Flat /attendees?filter=eventId returns 400 "Unsupported filter field".
			// Surface the original order-items error so the user can act on it.
			return self::result( $eve_id, 'error',
				'Order items fetch failed: ' . $order_items->get_error_message(),
				hl_paid: (int) ( $row['eve_paid'] ?? 0 ),
				hl_free: (int) ( $row['eve_free'] ?? 0 ),
				dry_run: $dry_run
			);
		}

	if ( ! $dry_run ) {
		$wpdb->update(
			$table,
			array(
				'cvent_prev_paid'   => (int) ( $row['eve_paid'] ?? 0 ),
				'cvent_prev_free'   => (int) ( $row['eve_free'] ?? 0 ),
				'eve_paid'          => $paid,
				'eve_free'          => $free,
				'cvent_last_synced' => current_time( 'mysql' ),
			),
			array( 'eve_id' => $eve_id ),
			array( '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

		$msg = $dry_run
			? sprintf( 'DRY RUN — would write: %d paid, %d free (%d unique attendees, %d items skipped%s).', $paid, $free, $total, $filtered_out, $source_note )
			: sprintf( 'Synced: %d paid, %d free (%d total%s).', $paid, $free, $total, $source_note );

		$r = self::result(
			$eve_id,
			'synced',
			$msg,
			$paid,
			$free,
			$row['cvent_event_title'] ?? '',
			$cvent_id,
			$row['cvent_match_score'] ?? null,
			dry_run: $dry_run,
			hl_paid: (int) ( $row['eve_paid'] ?? 0 ),
			hl_free: (int) ( $row['eve_free'] ?? 0 )
		);

		if ( $dry_run ) {
			$r['attendees_preview'] = $preview;
			$r['filtered_out']      = $filtered_out;
			$r['total_fetched']     = $orders_ok ? count( $order_items ) : 0;
			$r['orders_ok']         = $orders_ok;
		}

		return $r;
	}

	/**
	 * Fetch all attendees for a CVENT event (paginated).
	 * Endpoint: GET /ea/attendees/filter?filter=eventId eq '{id}'&limit=200
	 *
	 * @param string $cvent_event_id
	 * @return array|WP_Error
	 */
	/**
	 * Thin wrapper — consolidates to the API class which handles pagination,
	 * UUID sanitisation, and endpoint selection.
	 */
	public static function fetch_attendees_for_event( $cvent_event_id ) {
		return Hostlinks_CVENT_API::get_attendees( $cvent_event_id );
	}

	/**
	 * Remove attendees whose registration status indicates they shouldn't be counted.
	 * Excludes: Cancelled, Declined, Deleted, Test, Waitlisted.
	 * Includes: Registered, Confirmed, Attending, Checked In, Active.
	 *
	 * @param array $attendees
	 * @return array Filtered attendees.
	 */
	public static function filter_valid_attendees( $attendees ) {
		return array_values( array_filter( $attendees, function( $att ) {
			// If no status field present, include by default (conservative).
			if ( ! isset( $att['status'] ) ) {
				return true;
			}
			$s = strtolower( trim( $att['status'] ) );
			// Explicit exclusions.
			$excluded = array( 'cancelled', 'canceled', 'declined', 'deleted', 'test', 'waitlisted', 'waitlist' );
			if ( in_array( $s, $excluded, true ) ) {
				return false;
			}
			// If we have an allowlist match, include it.
			foreach ( self::$valid_statuses as $valid ) {
				if ( false !== strpos( $s, $valid ) ) {
					return true;
				}
			}
			// Unknown status: include (conservative — log-visible in sync output).
			return true;
		} ) );
	}

	/**
	 * Extract all discount-related strings from an attendee record.
	 * Checks multiple possible field locations to be tolerant of API shape variation.
	 *
	 * Priority order:
	 *   1. discounts[] → each item's 'name' field
	 *   2. discount.name  (single-object form)
	 *   3. pricing.discountCode / pricing.discountName
	 *   4. orderItems[].discounts[].name
	 *
	 * @param array $attendee
	 * @return string[] Flat array of discount name/code strings.
	 */
	public static function extract_discount_strings( $attendee ) {
		$strings = array();

		// 1. discounts[] array.
		if ( ! empty( $attendee['discounts'] ) && is_array( $attendee['discounts'] ) ) {
			foreach ( $attendee['discounts'] as $d ) {
				if ( ! empty( $d['name'] ) )        $strings[] = $d['name'];
				if ( ! empty( $d['code'] ) )        $strings[] = $d['code'];
				if ( ! empty( $d['discountCode'] ) ) $strings[] = $d['discountCode'];
			}
		}

		// 2. Single discount object.
		if ( ! empty( $attendee['discount'] ) && is_array( $attendee['discount'] ) ) {
			if ( ! empty( $attendee['discount']['name'] ) ) $strings[] = $attendee['discount']['name'];
			if ( ! empty( $attendee['discount']['code'] ) ) $strings[] = $attendee['discount']['code'];
		}

		// 3. pricing.discountCode / pricing.discountName.
		if ( ! empty( $attendee['pricing'] ) && is_array( $attendee['pricing'] ) ) {
			if ( ! empty( $attendee['pricing']['discountCode'] ) ) $strings[] = $attendee['pricing']['discountCode'];
			if ( ! empty( $attendee['pricing']['discountName'] ) ) $strings[] = $attendee['pricing']['discountName'];
		}

		// 4. orderItems[].discounts[].name.
		if ( ! empty( $attendee['orderItems'] ) && is_array( $attendee['orderItems'] ) ) {
			foreach ( $attendee['orderItems'] as $item ) {
				if ( ! empty( $item['discounts'] ) && is_array( $item['discounts'] ) ) {
					foreach ( $item['discounts'] as $d ) {
						if ( ! empty( $d['name'] ) ) $strings[] = $d['name'];
					}
				}
			}
		}

		return array_unique( array_filter( $strings ) );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	private static function result( $eve_id, $action, $message, $paid = null, $free = null, $cvent_title = null, $cvent_id = null, $score = null, bool $dry_run = false, $hl_paid = null, $hl_free = null ) {
		return array(
			'eve_id'            => $eve_id,
			'dry_run'           => $dry_run,
			'action'            => $action,
			'message'           => $message,
			'paid'              => $paid,
			'free'              => $free,
			'hl_paid'           => $hl_paid,  // current HL stored paid count (for comparison)
			'hl_free'           => $hl_free,  // current HL stored free count (for comparison)
			'cvent_title'       => $cvent_title,
			'cvent_id'          => $cvent_id,
			'score'             => $score,
			'candidates'        => null,
			'attendees_preview' => null,
			'filtered_out'      => null,
			'total_fetched'     => null,
		);
	}
}
