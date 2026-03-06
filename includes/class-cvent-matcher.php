<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap matching: find the best CVENT event for a Hostlinks event.
 *
 * Scoring (max 135 points):
 *   +25  same start calendar day (UTC — CVENT stores UTC ISO timestamps)
 *   +25  date ranges overlap (HL start..end overlaps CVENT start..end)
 *   +40  normalized city matches
 *   +20  state/region matches
 *   +15  venue name contains match (normalized)
 *   +10  title token overlap > 60% of shorter string's tokens
 *
 * Auto-match: top score >= 90 AND at least 20 points ahead of second-best.
 * Otherwise: status = needs_review (admin picks manually).
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
	 * @param array $hl_event  Row from event_details_list (needs eve_start, eve_end, eve_location).
	 * @return array {
	 *   status       : 'auto'|'needs_review'|'no_candidates',
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
			$result   = self::score_candidate( $hl_event, $ce );
			$scored[] = array(
				'event'     => $ce,
				'score'     => $result['score'],
				'breakdown' => $result['breakdown'],
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

		if ( $best_score >= self::SCORE_AUTO_THRESHOLD && $gap >= self::SCORE_AUTO_GAP ) {
			$status = 'auto';
		} else {
			$status = 'needs_review';
		}

		return array(
			'status'     => $status,
			'best'       => $best['event'],
			'best_score' => $best_score,
			'candidates' => $scored,
		);
	}

	// -------------------------------------------------------------------------
	// Scoring
	// -------------------------------------------------------------------------

	/**
	 * Score a single CVENT event against a Hostlinks event (0–135).
	 *
	 * @param array $hl_event    Hostlinks event row.
	 * @param array $cvent_event CVENT event record.
	 * @return array { score: int, breakdown: array }
	 */
	public static function score_candidate( $hl_event, $cvent_event ) {
		$score     = 0;
		$breakdown = array(
			'dates_same_day' => 0,
			'dates_overlap'  => 0,
			'city'           => 0,
			'state'          => 0,
			'venue'          => 0,
			'title'          => 0,
			'hl_city'        => '',
			'hl_state'       => '',
			'cv_city'        => '',
			'cv_state'       => '',
			'hl_start'       => '',
			'cv_start'       => '',
			'title_overlap'  => 0.0,
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

		// ── Location scoring ─────────────────────────────────────────────────
		$hl_city  = '';
		$hl_state = '';
		$cv_city  = '';
		$cv_state = '';
		$cv_venue = '';

		// Hostlinks location is a free-text string, e.g. "Paso Robles, CA"
		if ( ! empty( $hl_event['eve_location'] ) ) {
			$parts    = explode( ',', $hl_event['eve_location'] );
			$hl_city  = self::normalize( trim( $parts[0] ?? '' ) );
			$hl_state = self::normalize( trim( $parts[1] ?? '' ) );
		}

		// CVENT venues[] array — prefer first venue.
		if ( ! empty( $cvent_event['venues'] ) && is_array( $cvent_event['venues'] ) ) {
			$v        = $cvent_event['venues'][0];
			$cv_city  = self::normalize( $v['city']       ?? '' );
			$cv_state = self::normalize( $v['regionCode'] ?? ( $v['region'] ?? '' ) );
			$cv_venue = self::normalize( $v['name']       ?? '' );
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

		// ── Title scoring ─────────────────────────────────────────────────────
		// Hostlinks has no native title; use location as best proxy.
		// CVENT titles often contain the city name, so we measure what fraction
		// of the shorter string's tokens appear in the longer string.
		$cv_title = self::normalize( $cvent_event['title'] ?? '' );
		$hl_title = self::normalize( $hl_event['eve_location'] ?? '' );

		if ( $cv_title && $hl_title ) {
			$overlap                    = self::token_overlap( $cv_title, $hl_title );
			$breakdown['title_overlap'] = round( $overlap, 2 );
			if ( $overlap >= 0.6 ) {
				$score += 10;
				$breakdown['title'] = 10;
			}
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
	// Normalisation helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize a location or title string for comparison.
	 * lowercase → abbreviations → remove punctuation → collapse whitespace.
	 *
	 * @param string $str
	 * @return string
	 */
	public static function normalize( $str ) {
		$str = strtolower( trim( $str ) );

		// Street-type abbreviations (expand short → long for consistency).
		$abbr = array(
			'/\bst\b/'    => 'street',
			'/\brd\b/'    => 'road',
			'/\bave\b/'   => 'avenue',
			'/\bblvd\b/'  => 'boulevard',
			'/\bste\b/'   => 'suite',
			'/\bdr\b/'    => 'drive',
			'/\bct\b/'    => 'court',
			'/\bln\b/'    => 'lane',
			'/\bhwy\b/'   => 'highway',
			// All 50 US states + DC: full name → postal abbreviation.
			// CVENT often returns region as the full state name.
			'/\balabama\b/'              => 'al',
			'/\balaska\b/'               => 'ak',
			'/\barizona\b/'              => 'az',
			'/\barkansas\b/'             => 'ar',
			'/\bcalifornia\b/'           => 'ca',
			'/\bcolorado\b/'             => 'co',
			'/\bconnecticut\b/'          => 'ct',
			'/\bdelaware\b/'             => 'de',
			'/\bdistrict of columbia\b/' => 'dc',
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
		$str = preg_replace( array_keys( $abbr ), array_values( $abbr ), $str );

		// Remove punctuation except spaces and letters/digits.
		$str = preg_replace( '/[^a-z0-9\s]/', '', $str );

		// Collapse whitespace.
		$str = preg_replace( '/\s+/', ' ', trim( $str ) );

		return $str;
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
