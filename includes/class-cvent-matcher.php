<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap matching: find the best CVENT event for a Hostlinks event.
 *
 * Scoring (max 305 points):
 *   +25  same start calendar day (UTC — CVENT stores UTC ISO timestamps)
 *   +25  date ranges overlap (HL start..end overlaps CVENT start..end)
 *   +40  normalized city matches (from CVENT venue data)
 *   +20  state/region matches (from CVENT venue data)
 *   +15  venue name contains HL city (from CVENT venue data)
 *   +10  title token overlap > 60% of shorter string's tokens
 *   +65  CVENT title location prefix matches HL location base
 *         e.g. "Wellston, MO - Grant Writing USA" prefix "Wellston, MO"
 *         matched against HL eve_location "Wellston, MO" (modifiers stripped)
 *   +35  event type matches (Writing / Management extracted from CVENT title
 *         vs eve_type_name from Hostlinks event_type table)
 *   +30  both are zoom events (CVENT title contains "zoom", HL eve_zoom = 1)
 *   +15  subaward match: "SUB" in HL eve_location AND "subaward" in CVENT title
 *   +10  zoom region side match: timezone abbreviation in CVENT zoom title
 *         (EST/Eastern → east, PST/Pacific → west, CST/Central → central,
 *          MST/Mountain → mountain) matches the region prefix of HL eve_location
 *   +15  cancelled match: "cancel" in both CVENT title AND HL eve_location
 *
 * Location base stripping (hl_location_base):
 *   Strips "| modifier" suffixes, em-dash (–) suffixes, and " - STATUS" suffixes
 *   (e.g. "- CANCELED", "- CANCELLED", "- TO BE RESCH") before city/state
 *   extraction and title-location comparison. Uses space-hyphen-space so
 *   hyphenated city names like "Winston-Salem" are not affected.
 *
 * Auto-match: top score >= 90 AND at least 20 points ahead of second-best.
 * Otherwise: status = needs_review (admin picks manually).
 *
 * Zoom auto-match path:     overlap(25) + type(35) + zoom(30) = 90.
 * City auto-match path:     overlap(25) + title_location(65) = 90.
 * Zoom subaward path:       sameday(25) + overlap(25) + zoom(30) + subaward(15) + region(10) = 105.
 * Cancelled event path:     sameday(25) + overlap(25) + title_loc(65) + type(35) + cancelled(15) = 165.
 */
class Hostlinks_CVENT_Matcher {

	const SCORE_AUTO_THRESHOLD = 90;
	const SCORE_AUTO_GAP       = 20;

	// -------------------------------------------------------------------------
	// Public entry point
	// -------------------------------------------------------------------------

	/**
	 * Find the best-matching CVENT event for a Hostlinks event row.
	 *
	 * @param array $hl_event  Row from event_details_list (needs eve_start, eve_end,
	 *                         eve_location, eve_zoom, eve_type_name).
	 * @return array {
	 *   status       : 'auto'|'needs_review'|'no_candidates'|'error',
	 *   best         : CVENT event record or null,
	 *   best_score   : int,
	 *   candidates   : array of {event, score, breakdown},
	 * }
	 */
	public static function bootstrap_match( $hl_event ) {
		// Widen the search window by 1 day each side to absorb timezone drift.
		// Anchor date-only DB strings to UTC noon before arithmetic to prevent
		// server-local-midnight → UTC conversion shifting the date by 1 day.
		$start_min = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $hl_event['eve_start'] . 'T12:00:00Z -1 day' ) );
		$start_max = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( ( $hl_event['eve_end'] ?: $hl_event['eve_start'] ) . 'T12:00:00Z +1 day' ) );

		$result = Hostlinks_CVENT_API::search_events( $start_min, $start_max );
		if ( is_wp_error( $result ) ) {
			return array(
				'status'     => 'error',
				'error'      => $result->get_error_message(),
				'best'       => null,
				'best_score' => 0,
				'candidates' => array(),
			);
		}

		$cvent_events = isset( $result['data'] ) ? $result['data'] : array();
		if ( empty( $cvent_events ) ) {
			return array(
				'status'     => 'no_candidates',
				'best'       => null,
				'best_score' => 0,
				'candidates' => array(),
			);
		}

		// Score every candidate.
		$scored = array();
		foreach ( $cvent_events as $ce ) {
			$res      = self::score_candidate( $hl_event, $ce );
			$scored[] = array(
				'event'     => $ce,
				'score'     => $res['score'],
				'breakdown' => $res['breakdown'],
			);
		}

		// Sort highest score first.
		usort( $scored, function( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		$best       = $scored[0];
		$second     = isset( $scored[1] ) ? $scored[1] : null;
		$best_score = $best['score'];
		$gap        = $second ? ( $best_score - $second['score'] ) : 999;

	// Geographic mismatch guard — in-person events only (Zoom events are exempt
	// because they have no city/state in CVENT venue data).
	// Geo is confirmed by ANY of: city match, state match, or title_location
	// match (CVENT title starts with "City, ST - ..." matching HL location).
	// The title_location check handles events where CVENT returns no venues[]
	// data — the title prefix is just as reliable as the venue fields.
	$hl_is_zoom  = ! empty( $hl_event['eve_zoom'] );
	$geo_blocked = false;
	if ( ! $hl_is_zoom ) {
		$best_breakdown = $best['breakdown'] ?? array();
		$geo_confirmed  = ( $best_breakdown['city']           ?? 0 ) > 0
		               || ( $best_breakdown['state']          ?? 0 ) > 0
		               || ( $best_breakdown['title_location'] ?? 0 ) > 0;
		if ( ! $geo_confirmed ) {
			$geo_blocked = true;
		}
	}

	$can_auto = $best_score >= self::SCORE_AUTO_THRESHOLD && $gap >= self::SCORE_AUTO_GAP && ! $geo_blocked;
	$status   = $can_auto ? 'auto' : 'needs_review';

	return array(
		'status'      => $status,
		'best'        => $best['event'],
		'best_score'  => $best_score,
		'candidates'  => $scored,
		'geo_blocked' => $geo_blocked,
	);
	}

	// -------------------------------------------------------------------------
	// Scoring
	// -------------------------------------------------------------------------

	/**
	 * Score a single CVENT event against a Hostlinks event (0–265).
	 *
	 * @param array $hl_event    Hostlinks event row (may include eve_type_name, eve_zoom).
	 * @param array $cvent_event CVENT event record.
	 * @return array { score: int, breakdown: array }
	 */
	public static function score_candidate( $hl_event, $cvent_event ) {
		$score     = 0;
		$breakdown = array(
			'dates_same_day'   => 0,
			'dates_overlap'    => 0,
			'city'             => 0,
			'state'            => 0,
			'venue'            => 0,
			'title'            => 0,
			'title_location'   => 0,
			'type_match'       => 0,
			'zoom_match'       => 0,
			'subaward_match'   => 0,
			'zoom_region_match'=> 0,
			'cancelled_match'  => 0,
			'hl_city'          => '',
			'hl_state'         => '',
			'cv_city'          => '',
			'cv_state'         => '',
			'hl_start'         => '',
			'cv_start'         => '',
			'title_overlap'    => 0.0,
			'hl_loc_base'      => '',
			'cv_title_loc'     => '',
			'cv_type'          => '',
			'hl_type'          => '',
			'cv_is_zoom'       => false,
			'cv_is_cancelled'  => false,
			'hl_is_cancelled'  => false,
			'hl_is_zoom'       => false,
			'cv_zoom_region'   => '',
			'hl_zoom_region'   => '',
		);

		// ── Date scoring ─────────────────────────────────────────────────────
		// Hostlinks stores DATE only strings (Y-m-d).
		// CVENT timestamps are UTC ISO strings; extract calendar date in UTC.
		$hl_start = $hl_event['eve_start'] ?? '';
		$hl_end   = $hl_event['eve_end']   ?? $hl_start;

		$cv_start_raw = $cvent_event['start'] ?? '';
		$cv_end_raw   = $cvent_event['end']   ?? $cv_start_raw;

		$cv_start = $cv_start_raw ? gmdate( 'Y-m-d', strtotime( $cv_start_raw ) ) : '';
		$cv_end   = $cv_end_raw   ? gmdate( 'Y-m-d', strtotime( $cv_end_raw ) )   : '';

		$breakdown['hl_start'] = $hl_start;
		$breakdown['cv_start'] = $cv_start;

		// +25 if start dates are the same calendar day.
		if ( $hl_start && $cv_start && $hl_start === $cv_start ) {
			$score += 25;
			$breakdown['dates_same_day'] = 25;
		}

		// +25 if the date ranges overlap at all.
		if ( $hl_start && $hl_end && $cv_start && $cv_end ) {
			if ( $hl_start <= $cv_end && $cv_start <= $hl_end ) {
				$score += 25;
				$breakdown['dates_overlap'] = 25;
			}
		}

		// ── Location scoring (venue fields) ──────────────────────────────────
		$hl_city  = '';
		$hl_state = '';
		$cv_city  = '';
		$cv_state = '';
		$cv_venue = '';

		// Hostlinks location is a free-text string, e.g. "Paso Robles, CA".
		// Apply hl_location_base() first so that status suffixes like "- CANCELED"
		// and pipe-delimited modifiers are stripped before city/state extraction.
		// Without this, "OK - CANCELED" would fail the normalize_state() lookup.
		if ( ! empty( $hl_event['eve_location'] ) ) {
			$loc_base = self::hl_location_base( $hl_event['eve_location'] );
			$parts    = explode( ',', $loc_base );
			$hl_city  = self::normalize( trim( $parts[0] ?? '' ) );
			$hl_state = self::normalize_state( trim( $parts[1] ?? '' ) );
		}

		// CVENT venues[] array — prefer first venue.
		if ( ! empty( $cvent_event['venues'] ) && is_array( $cvent_event['venues'] ) ) {
			$v        = $cvent_event['venues'][0];
			$cv_city  = self::normalize( $v['city'] ?? '' );
			$cv_state = self::normalize_state( $v['regionCode'] ?? ( $v['region'] ?? '' ) );
			$cv_venue = self::normalize( $v['name'] ?? '' );
		}

		$breakdown['hl_city']  = $hl_city;
		$breakdown['hl_state'] = $hl_state;
		$breakdown['cv_city']  = $cv_city;
		$breakdown['cv_state'] = $cv_state;

		// +40 city match.
		if ( $hl_city && $cv_city && $hl_city === $cv_city ) {
			$score += 40;
			$breakdown['city'] = 40;
		}

		// +20 state/region match.
		if ( $hl_state && $cv_state && $hl_state === $cv_state ) {
			$score += 20;
			$breakdown['state'] = 20;
		}

		// +15 venue name contains HL city (or vice-versa) — looser match.
		if ( $cv_venue && $hl_city ) {
			if ( false !== strpos( $cv_venue, $hl_city ) || false !== strpos( $hl_city, $cv_venue ) ) {
				$score += 15;
				$breakdown['venue'] = 15;
			}
		}

		// ── Title token overlap ───────────────────────────────────────────────
		// Hostlinks has no native title; use location as best proxy.
		// Measures what fraction of the shorter string's tokens appear in the longer.
		$cv_title_norm = self::normalize( $cvent_event['title'] ?? '' );
		$hl_title_norm = self::normalize( $hl_event['eve_location'] ?? '' );

		if ( $cv_title_norm && $hl_title_norm ) {
			$overlap                    = self::token_overlap( $cv_title_norm, $hl_title_norm );
			$breakdown['title_overlap'] = round( $overlap, 2 );
			if ( $overlap >= 0.6 ) {
				$score += 10;
				$breakdown['title'] = 10;
			}
		}

		// ── Title location prefix match (+65) ────────────────────────────────
		// CVENT titles follow "{City}, {State} - Grant Writing/Management USA".
		// The prefix before " - " matches the HL location base (modifiers stripped).
		// Uses plain normalize() so "Washington, DC" stays "washington dc" —
		// a more specific string than the state-abbreviated form.
		$hl_loc_base  = self::normalize( self::hl_location_base( $hl_event['eve_location'] ?? '' ) );
		$cv_title_loc = self::normalize( self::cv_title_location( $cvent_event['title'] ?? '' ) );

		$breakdown['hl_loc_base']  = $hl_loc_base;
		$breakdown['cv_title_loc'] = $cv_title_loc;

		if ( $hl_loc_base && $cv_title_loc && $hl_loc_base === $cv_title_loc ) {
			$score += 65;
			$breakdown['title_location'] = 65;
		}

		// ── Type match (+35) ─────────────────────────────────────────────────
		// Extract type keyword from CVENT title: "writing" or "management".
		// Subawards events in CVENT are labelled "Management" — HL Subawards
		// events have management type + "SUB" in location, so they match naturally.
		$cv_title_lower = strtolower( $cvent_event['title'] ?? '' );
		$cv_type        = '';
		if ( false !== strpos( $cv_title_lower, 'writing' ) ) {
			$cv_type = 'writing';
		} elseif ( false !== strpos( $cv_title_lower, 'management' ) ) {
			$cv_type = 'management';
		}

		$hl_type = strtolower( trim( $hl_event['eve_type_name'] ?? '' ) );

		$breakdown['cv_type'] = $cv_type;
		$breakdown['hl_type'] = $hl_type;

		if ( $cv_type && $hl_type && $cv_type === $hl_type ) {
			$score += 35;
			$breakdown['type_match'] = 35;
		}

		// ── Zoom match (+30) ─────────────────────────────────────────────────
		// CVENT zoom events always contain "zoom" in their title.
		// Hostlinks zoom flag: eve_zoom stores "yes" when checked (not "1"),
		// so !empty() is the correct check — no integer cast needed.
		$cv_is_zoom = false !== strpos( $cv_title_lower, 'zoom' );
		$hl_is_zoom = ! empty( $hl_event['eve_zoom'] );

		$breakdown['cv_is_zoom'] = $cv_is_zoom;
		$breakdown['hl_is_zoom'] = $hl_is_zoom;

		if ( $cv_is_zoom && $hl_is_zoom ) {
			$score += 30;
			$breakdown['zoom_match'] = 30;
		}

		// ── Subaward match (+15) ─────────────────────────────────────────────
		// HL subaward events have "SUB" in eve_location (e.g. "East Management SUB").
		// CVENT subaward events contain "subaward" in their title
		// (e.g. "Managing Subawards Training - EST Live Zoom Webinar").
		$hl_is_sub  = false !== stripos( $hl_event['eve_location'] ?? '', 'sub' );
		$cv_is_sub  = false !== stripos( $cvent_event['title'] ?? '', 'subaward' );

		if ( $hl_is_sub && $cv_is_sub ) {
			$score += 15;
			$breakdown['subaward_match'] = 15;
		}

		// ── Zoom region side match (+10) ─────────────────────────────────────
		// CVENT zoom titles include a timezone side abbreviation
		// (e.g. "EST Live Zoom Webinar" or "PST Live Zoom Webinar").
		// HL zoom event locations carry the matching region prefix
		// (e.g. "East Management SUB", "West Writing").
		// Map both to a canonical region keyword and compare.
		$cv_zoom_region = '';
		$hl_zoom_region = '';

		if ( $cv_is_zoom ) {
			if ( preg_match( '/\best\b|\beastern\b/i', $cvent_event['title'] ?? '' ) ) {
				$cv_zoom_region = 'east';
			} elseif ( preg_match( '/\bpst\b|\bpacific\b/i', $cvent_event['title'] ?? '' ) ) {
				$cv_zoom_region = 'west';
			} elseif ( preg_match( '/\bcst\b|\bcentral\b/i', $cvent_event['title'] ?? '' ) ) {
				$cv_zoom_region = 'central';
			} elseif ( preg_match( '/\bmst\b|\bmountain\b/i', $cvent_event['title'] ?? '' ) ) {
				$cv_zoom_region = 'mountain';
			}
		}

		if ( $hl_is_zoom ) {
			$hl_loc_lower = strtolower( $hl_event['eve_location'] ?? '' );
			if ( str_starts_with( $hl_loc_lower, 'east' ) ) {
				$hl_zoom_region = 'east';
			} elseif ( str_starts_with( $hl_loc_lower, 'west' ) ) {
				$hl_zoom_region = 'west';
			} elseif ( str_starts_with( $hl_loc_lower, 'central' ) ) {
				$hl_zoom_region = 'central';
			} elseif ( str_starts_with( $hl_loc_lower, 'mountain' ) ) {
				$hl_zoom_region = 'mountain';
			}
		}

		$breakdown['cv_zoom_region'] = $cv_zoom_region;
		$breakdown['hl_zoom_region'] = $hl_zoom_region;

	if ( $cv_zoom_region && $hl_zoom_region && $cv_zoom_region === $hl_zoom_region ) {
		$score += 10;
		$breakdown['zoom_region_match'] = 10;
	}

	// ── Cancelled / weather match (+15) ──────────────────────────────────────
	// CVENT appends or prepends cancellation notes to event titles
	// (e.g. "Oklahoma City, OK - Grant Writing USA - CANCELED DUE TO WEATHER").
	// HL often stores the same event as "Oklahoma City, OK - CANCELED".
	// When both sides contain a cancellation marker and the date/location already
	// score well, this boost tips marginal candidates over the auto-match threshold.
	$cv_is_cancelled = (bool) preg_match( '/\bcancel/i', $cvent_event['title'] ?? '' );
	$hl_is_cancelled = (bool) preg_match( '/\bcancel/i', $hl_event['eve_location'] ?? '' );

	$breakdown['cv_is_cancelled'] = $cv_is_cancelled;
	$breakdown['hl_is_cancelled'] = $hl_is_cancelled;

	if ( $cv_is_cancelled && $hl_is_cancelled ) {
		$score += 15;
		$breakdown['cancelled_match'] = 15;
	}

	return array(
		'score'     => $score,
		'breakdown' => $breakdown,
	);
}

	// -------------------------------------------------------------------------
	// Staleness hash
	// -------------------------------------------------------------------------

	/**
	 * Compute a staleness hash for a CVENT event record.
	 * If this hash changes on a future sync, the mapping may be stale.
	 *
	 * @param array $cvent_event CVENT event record.
	 * @return string sha1 hex string.
	 */
	public static function staleness_hash( $cvent_event ) {
		$city  = '';
		if ( ! empty( $cvent_event['venues'][0]['city'] ) ) {
			$city = self::normalize( $cvent_event['venues'][0]['city'] );
		}
		$start = isset( $cvent_event['start'] ) ? gmdate( 'Y-m-d', strtotime( $cvent_event['start'] ) ) : '';
		$title = self::normalize( $cvent_event['title'] ?? '' );
		return sha1( $start . '|' . $city . '|' . $title );
	}

	// -------------------------------------------------------------------------
	// Location helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip HL location modifier suffixes before comparison.
	 * Removes "| SUB", "| CANCELED", "– TO BE RESCHEDULED", etc.
	 *
	 * Examples:
	 *   "Montgomery, AL | SUB"          → "Montgomery, AL"
	 *   "Oklahoma City, OK|SUB"         → "Oklahoma City, OK"
	 *   "Cleveland, OH – TO BE RESCH."  → "Cleveland, OH"
	 *   "Washington, DC"                → "Washington, DC"
	 *
	 * @param string $location
	 * @return string
	 */
	private static function hl_location_base( $location ) {
		$base = preg_replace( '/\s*\|.*$/s',  '', $location ); // strip | and everything after
		$base = preg_replace( '/\s*–.*$/su',  '', $base );     // strip em-dash (–) suffix
		// Strip " - CANCELED", " - CANCELLED", " - TO BE RESCH", etc.
		// Uses space-hyphen-space so "Winston-Salem, NC" is not affected.
		$base = preg_replace( '/\s+-\s+.*$/s', '', $base );
		return trim( $base );
	}

	/**
	 * Extract the "City, State" location prefix from a CVENT event title.
	 * CVENT titles follow "{City}, {State} - Grant Writing/Management USA".
	 * Returns everything before the first " - " separator.
	 * Returns '' if no separator found (e.g. zoom-only titles).
	 *
	 * @param string $title
	 * @return string
	 */
	/**
	 * Public wrapper so other classes (e.g. cvent-new-events page) can parse
	 * the city/state prefix out of a CVENT event title.
	 *
	 * @param string $title  CVENT event title, e.g. "San Antonio, TX - Grant Writing USA"
	 * @return string        Parsed prefix, e.g. "San Antonio, TX", or '' if no match.
	 */
	public static function title_location_from_cvent( $title ) {
		return self::cv_title_location( $title );
	}

	private static function cv_title_location( $title ) {
		// Strip BOM and leading whitespace that sometimes appears in CVENT exports.
		$title = ltrim( $title, "\xEF\xBB\xBF \t" );
		// Strip leading "CANCELED:" / "CANCELLED:" prefix that CVENT sometimes
		// prepends to cancelled event titles (e.g. "CANCELED: City, ST - Title").
		// The more common pattern is a suffix (handled in hl_location_base), but
		// we handle the prefix here defensively.
		$title = preg_replace( '/^CANCEL(?:L)?ED\s*:\s*/i', '', $title );
		// Use \s*-\s+ so we handle both "City, ST - Title" and "City, ST- Title"
		// (some CVENT events omit the space before the dash).
		// Requiring at least one space AFTER the dash prevents hyphenated city
		// names like "Winston-Salem" from being split at the wrong position.
		if ( preg_match( '/^(.+?)\s*-\s+.+$/s', $title, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Normalisation helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize a city, venue, or title string for comparison.
	 * lowercase → street abbreviations → remove punctuation → collapse whitespace.
	 * Does NOT replace state full names — use normalize_state() for state fields.
	 *
	 * @param string $str
	 * @return string
	 */
	public static function normalize( $str ) {
		$str = strtolower( trim( $str ) );

		// Street-type abbreviations only.
		$abbr = array(
			'/\bst\b/'   => 'street',
			'/\brd\b/'   => 'road',
			'/\bave\b/'  => 'avenue',
			'/\bblvd\b/' => 'boulevard',
			'/\bste\b/'  => 'suite',
			'/\bdr\b/'   => 'drive',
			'/\bct\b/'   => 'court',
			'/\bln\b/'   => 'lane',
			'/\bhwy\b/'  => 'highway',
		);
		$str = preg_replace( array_keys( $abbr ), array_values( $abbr ), $str );

		// Remove punctuation except spaces and letters/digits.
		$str = preg_replace( '/[^a-z0-9\s]/', '', $str );

		// Collapse whitespace.
		$str = preg_replace( '/\s+/', ' ', trim( $str ) );

		return $str;
	}

	/**
	 * Normalize a US state or region string to its 2-letter postal abbreviation.
	 * Calls normalize() first, then applies the full state-name → abbreviation map.
	 * Use ONLY for state/region fields, never for city names or full strings.
	 *
	 * @param string $str  e.g. "District of Columbia", "DC", "Pennsylvania", "PA"
	 * @return string      e.g. "dc", "dc", "pa", "pa"
	 */
	public static function normalize_state( $str ) {
		$str = self::normalize( $str );

		// All 50 US states + DC: full name → postal abbreviation.
		// CVENT often returns region as the full state name.
		// Multi-word states are listed before their single-word components
		// to prevent partial matches (e.g. "west virginia" before "virginia").
		$abbr = array(
			'/\bdistrict of columbia\b/' => 'dc',
			'/\balabama\b/'              => 'al',
			'/\balaska\b/'               => 'ak',
			'/\barizona\b/'              => 'az',
			'/\barkansas\b/'             => 'ar',
			'/\bcalifornia\b/'           => 'ca',
			'/\bcolorado\b/'             => 'co',
			'/\bconnecticut\b/'          => 'ct',
			'/\bdelaware\b/'             => 'de',
			'/\bflorida\b/'              => 'fl',
			'/\bgeorgia\b/'              => 'ga',
			'/\bhawaii\b/'               => 'hi',
			'/\bidaho\b/'                => 'id',
			'/\billinois\b/'             => 'il',
			'/\bindiana\b/'              => 'in',
			'/\biowa\b/'                 => 'ia',
			'/\bkansas\b/'               => 'ks',
			'/\bkentucky\b/'             => 'ky',
			'/\blouisiana\b/'            => 'la',
			'/\bmaine\b/'                => 'me',
			'/\bmaryland\b/'             => 'md',
			'/\bmassachusetts\b/'        => 'ma',
			'/\bmichigan\b/'             => 'mi',
			'/\bminnesota\b/'            => 'mn',
			'/\bmississippi\b/'          => 'ms',
			'/\bmissouri\b/'             => 'mo',
			'/\bmontana\b/'              => 'mt',
			'/\bnebraska\b/'             => 'ne',
			'/\bnevada\b/'               => 'nv',
			'/\bnew hampshire\b/'        => 'nh',
			'/\bnew jersey\b/'           => 'nj',
			'/\bnew mexico\b/'           => 'nm',
			'/\bnew york\b/'             => 'ny',
			'/\bnorth carolina\b/'       => 'nc',
			'/\bnorth dakota\b/'         => 'nd',
			'/\bohio\b/'                 => 'oh',
			'/\boklahoma\b/'             => 'ok',
			'/\boregon\b/'               => 'or',
			'/\bpennsylvania\b/'         => 'pa',
			'/\brhode island\b/'         => 'ri',
			'/\bsouth carolina\b/'       => 'sc',
			'/\bsouth dakota\b/'         => 'sd',
			'/\btennessee\b/'            => 'tn',
			'/\btexas\b/'                => 'tx',
			'/\butah\b/'                 => 'ut',
			'/\bvermont\b/'              => 'vt',
			'/\bwest virginia\b/'        => 'wv', // must precede virginia
			'/\bvirginia\b/'             => 'va',
			'/\bwashington\b/'           => 'wa',
			'/\bwisconsin\b/'            => 'wi',
			'/\bwyoming\b/'              => 'wy',
		);

		return trim( preg_replace( array_keys( $abbr ), array_values( $abbr ), $str ) );
	}

	/**
	 * Token overlap ratio between two normalized strings (0.0–1.0).
	 * Measures what fraction of the *shorter* string's tokens appear in the
	 * longer string, so a location like "Washington DC" (2 tokens) correctly
	 * scores 1.0 against a title like "Washington DC - Grant Management USA"
	 * (5 tokens) rather than being penalised for the title's extra words.
	 *
	 * @param string $a
	 * @param string $b
	 * @return float
	 */
	public static function token_overlap( $a, $b ) {
		$ta = array_filter( explode( ' ', $a ) );
		$tb = array_filter( explode( ' ', $b ) );
		if ( empty( $ta ) || empty( $tb ) ) {
			return 0.0;
		}
		$shared = count( array_intersect( $ta, $tb ) );
		$min    = min( count( $ta ), count( $tb ) );
		return $shared / $min;
	}
}
