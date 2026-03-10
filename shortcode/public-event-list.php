<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [public_event_list] shortcode.
 *
 * Always public — no login or access-control check.
 * Renders a two-column upcoming workshop list matching the layout in
 * the Grant Writing USA public event page.
 */
function hostlinks_public_event_list_shortcode( $atts ) {
	global $wpdb;

	// ── Settings ──────────────────────────────────────────────────────────────
	$left_heading      = get_option( 'hostlinks_pel_left_heading',      'Grant Writing Workshops' );
	$left_heading_tag  = hostlinks_pel_safe_tag( get_option( 'hostlinks_pel_left_heading_tag', 'h2' ) );
	$left_desc         = get_option( 'hostlinks_pel_left_desc',         '' );
	$left_desc_tag     = hostlinks_pel_safe_tag( get_option( 'hostlinks_pel_left_desc_tag', 'p' ) );
	$left_types        = array_map( 'intval', (array) get_option( 'hostlinks_pel_left_types',  array() ) );

	$right_heading     = get_option( 'hostlinks_pel_right_heading',     'Grant Management Workshops' );
	$right_heading_tag = hostlinks_pel_safe_tag( get_option( 'hostlinks_pel_right_heading_tag', 'h2' ) );
	$right_desc        = get_option( 'hostlinks_pel_right_desc',        '' );
	$right_desc_tag    = hostlinks_pel_safe_tag( get_option( 'hostlinks_pel_right_desc_tag', 'p' ) );
	$right_types       = array_map( 'intval', (array) get_option( 'hostlinks_pel_right_types', array() ) );

	$sub_types         = array_map( 'intval', (array) get_option( 'hostlinks_pel_subaward_types', array() ) );

	$zoom_east         = get_option( 'hostlinks_pel_zoom_time_east',   '9:30-4:30 EST' );
	$zoom_west         = get_option( 'hostlinks_pel_zoom_time_west',   '8:00-3:00 PST' );
	$zoom_default      = get_option( 'hostlinks_pel_zoom_time_default','9:30-4:30 EST' );

	// ── Query ─────────────────────────────────────────────────────────────────
	$today = current_time( 'Y-m-d' );

	$events = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT e.*, t.event_type_name
			 FROM {$wpdb->prefix}event_details_list e
			 LEFT JOIN {$wpdb->prefix}event_type t ON t.event_type_id = e.eve_type
			 WHERE e.eve_status = 1
			   AND e.eve_public_hide = 0
			   AND e.eve_start >= %s
			   AND e.eve_location NOT LIKE %s
			   AND e.eve_location NOT LIKE %s
			   AND e.eve_location NOT LIKE %s
			 ORDER BY e.eve_start ASC",
			$today,
			'%|PRIVATE%',
			'%| PRIVATE%',
			'%|private%'
		),
		ARRAY_A
	);

	if ( empty( $events ) ) {
		return '<p class="hpl-no-events">No upcoming events at this time.</p>';
	}

	// ── Split into columns ────────────────────────────────────────────────────
	$left_events  = array();
	$right_events = array();
	foreach ( $events as $ev ) {
		$type_id = intval( $ev['eve_type'] );
		if ( in_array( $type_id, $left_types, true ) ) {
			$left_events[] = $ev;
		} elseif ( in_array( $type_id, $right_types, true ) ) {
			$right_events[] = $ev;
		}
	}

	// ── Render ────────────────────────────────────────────────────────────────
	$zoom_logo_url = HOSTLINKS_PLUGIN_URL . 'assets/images/zoom-logo.png';

	ob_start();
	?>
	<div class="hpl-wrapper">

		<!-- Left column -->
		<div class="hpl-column">
			<?php if ( $left_heading ) : ?>
			<<?php echo $left_heading_tag; ?> class="hpl-col-heading"><?php echo esc_html( $left_heading ); ?></<?php echo $left_heading_tag; ?>>
			<?php endif; ?>
			<?php if ( $left_desc ) : ?>
			<<?php echo $left_desc_tag; ?> class="hpl-col-desc"><?php echo wp_kses_post( $left_desc ); ?></<?php echo $left_desc_tag; ?>>
			<?php endif; ?>

			<ul class="hpl-event-list">
			<?php foreach ( $left_events as $ev ) : ?>
				<li class="hpl-event-item">
					<?php echo hostlinks_pel_render_event( $ev, $sub_types, $zoom_east, $zoom_west, $zoom_default, $zoom_logo_url ); ?>
				</li>
			<?php endforeach; ?>
			<?php if ( empty( $left_events ) ) : ?>
				<li class="hpl-no-events">No upcoming events at this time.</li>
			<?php endif; ?>
			</ul>
		</div>

		<!-- Right column -->
		<div class="hpl-column">
			<?php if ( $right_heading ) : ?>
			<<?php echo $right_heading_tag; ?> class="hpl-col-heading"><?php echo esc_html( $right_heading ); ?></<?php echo $right_heading_tag; ?>>
			<?php endif; ?>
			<?php if ( $right_desc ) : ?>
			<<?php echo $right_desc_tag; ?> class="hpl-col-desc"><?php echo wp_kses_post( $right_desc ); ?></<?php echo $right_desc_tag; ?>>
			<?php endif; ?>

			<ul class="hpl-event-list">
			<?php foreach ( $right_events as $ev ) : ?>
				<li class="hpl-event-item">
					<?php echo hostlinks_pel_render_event( $ev, $sub_types, $zoom_east, $zoom_west, $zoom_default, $zoom_logo_url ); ?>
				</li>
			<?php endforeach; ?>
			<?php if ( empty( $right_events ) ) : ?>
				<li class="hpl-no-events">No upcoming events at this time.</li>
			<?php endif; ?>
			</ul>
		</div>

	</div><!-- .hpl-wrapper -->
	<?php
	return ob_get_clean();
}

/**
 * Render a single event row.
 */
function hostlinks_pel_render_event( $ev, $sub_types, $zoom_east, $zoom_west, $zoom_default, $zoom_logo_url ) {
	$is_zoom     = ( $ev['eve_zoom'] === 'yes' );
	$type_id     = intval( $ev['eve_type'] );
	$is_subaward = in_array( $type_id, $sub_types, true );

	// ── Build title ───────────────────────────────────────────────────────────
	if ( $is_zoom ) {
		$title = $is_subaward ? 'Managing Subawards ZOOM WEBINAR' : 'ZOOM WEBINAR';
	} else {
		$location = hostlinks_pel_extract_city_state( $ev['eve_location'] );
		$title    = $is_subaward ? 'Managing Subawards ' . $location : $location;
	}

	// ── Build date string ─────────────────────────────────────────────────────
	$date_str = hostlinks_pel_format_date_range( $ev['eve_start'], $ev['eve_end'] );

	// ── Build ZOOM time string ────────────────────────────────────────────────
	$time_str = '';
	if ( $is_zoom ) {
		if ( ! empty( $ev['eve_zoom_time'] ) ) {
			$time_str = $ev['eve_zoom_time'];
		} else {
			$haystack = strtolower( ( $ev['eve_location'] ?? '' ) . ' ' . ( $ev['cvent_event_title'] ?? '' ) );
			if ( preg_match( '/\b(est|east|eastern)\b/', $haystack ) ) {
				$time_str = $zoom_east;
			} elseif ( preg_match( '/\b(pst|west|western|pacific)\b/', $haystack ) ) {
				$time_str = $zoom_west;
			} else {
				$time_str = $zoom_default;
			}
		}
	}

	// ── Build details line ────────────────────────────────────────────────────
	$web_url = esc_url( $ev['eve_web_url'] ?? '' );

	// ── Output ────────────────────────────────────────────────────────────────
	$out  = '<div class="hpl-event-title">';
	$out .= '<strong>' . esc_html( $title ) . '</strong>';
	$out .= '&nbsp;&nbsp;' . esc_html( $date_str );
	if ( $time_str ) {
		$out .= ' <span class="hpl-zoom-time">| ' . esc_html( $time_str ) . '</span>';
	}
	$out .= '</div>';

	$out .= '<div class="hpl-event-details-row">';
	$out .= 'Click for event ';
	if ( $web_url ) {
		$out .= '<a href="' . $web_url . '" class="hpl-details-link">details</a>';
	} else {
		$out .= '<span class="hpl-details-link hpl-details-pending">details</span>';
	}
	if ( $is_zoom ) {
		$out .= ' <img src="' . esc_url( $zoom_logo_url ) . '" alt="Zoom" class="hpl-zoom-logo">';
	}
	$out .= '</div>';

	return $out;
}

/**
 * Extract "City, ST" from the start of an eve_location string.
 * Handles: "Everett, WA", "Everett, WA - Grant Writing USA", "Hernando/Desoto, MS", etc.
 */
function hostlinks_pel_extract_city_state( $location ) {
	$location = trim( $location );
	// Match "City, ST" at the start — city may contain spaces, slashes, hyphens, periods
	if ( preg_match( '/^([A-Za-z][A-Za-z0-9\s\/\-\.]+,\s*[A-Z]{2})\b/u', $location, $m ) ) {
		return trim( $m[1] );
	}
	return $location;
}

/**
 * Format a date range as "March 9-10, 2026" or "March 31-April 1, 2026".
 */
function hostlinks_pel_format_date_range( $start, $end ) {
	if ( empty( $start ) ) {
		return '';
	}
	$s = date_create( $start );
	$e = date_create( $end );

	if ( ! $s ) {
		return '';
	}

	$start_month = $s->format( 'F' );
	$start_day   = (int) $s->format( 'j' );
	$start_year  = $s->format( 'Y' );

	if ( ! $e || $start === $end ) {
		return $start_month . ' ' . $start_day . ', ' . $start_year;
	}

	$end_month = $e->format( 'F' );
	$end_day   = (int) $e->format( 'j' );
	$end_year  = $e->format( 'Y' );

	if ( $start_month === $end_month && $start_year === $end_year ) {
		return $start_month . ' ' . $start_day . '-' . $end_day . ', ' . $start_year;
	}

	return $start_month . ' ' . $start_day . '-' . $end_month . ' ' . $end_day . ', ' . $start_year;
}

/**
 * Allow only safe HTML tag names for configurable heading/desc tags.
 */
function hostlinks_pel_safe_tag( $tag ) {
	$allowed = array( 'h2', 'h3', 'h4', 'h5', 'p' );
	return in_array( $tag, $allowed, true ) ? $tag : 'h2';
}
