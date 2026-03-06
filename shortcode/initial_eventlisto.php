<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb;

$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

$start_date = wp_date( 'Y-m-01' );

// ── Single JOIN query — replaces 3 per-row lookups (N+1 fix) ─────────────────
$all_pending_bookings = $wpdb->get_results( $wpdb->prepare(
	"SELECT e.*,
	        t.event_type_name,
	        m.event_marketer_name,
	        i.event_instructor_name
	 FROM {$table11} e
	 LEFT JOIN {$table12} t ON e.eve_type        = t.event_type_id
	 LEFT JOIN {$table13} m ON e.eve_marketer    = m.event_marketer_id
	 LEFT JOIN {$table14} i ON e.eve_instructor  = i.event_instructor_id
	 WHERE e.eve_status = '1' AND e.eve_start >= %s
	 ORDER BY e.eve_start ASC",
	$start_date
), ARRAY_A );

// ── Pass 1: pre-calculate monthly totals ─────────────────────────────────────
$totals = array();

foreach ( $all_pending_bookings as $ev ) {
	$parts = explode( '-', $ev['eve_start'] );
	$yr    = $parts[0];
	$mo    = $parts[1];
	$type  = trim( $ev['event_type_name'] ?? '' );

	if ( ! isset( $totals[ $yr ][ $mo ] ) ) {
		$totals[ $yr ][ $mo ] = array( 'paid' => 0, 'cnt' => 0, 'wrpaid' => 0, 'wrcnt' => 0, 'mgpaid' => 0, 'mgcnt' => 0 );
	}

	$totals[ $yr ][ $mo ]['paid'] += (int) $ev['eve_paid'];
	$totals[ $yr ][ $mo ]['cnt']  += 1;

	if ( $type === 'Writing' ) {
		$totals[ $yr ][ $mo ]['wrpaid'] += (int) $ev['eve_paid'];
		$totals[ $yr ][ $mo ]['wrcnt']  += 1;
	} elseif ( $type === 'Management' ) {
		$totals[ $yr ][ $mo ]['mgpaid'] += (int) $ev['eve_paid'];
		$totals[ $yr ][ $mo ]['mgcnt']  += 1;
	}
}

// ── Pass 2: render ─────────────────────────────────────────────────────────
$today        = new DateTime();
$current_month = null;
$last_updated  = wp_date( 'm/d', strtotime( get_option( 'last_data_updation', '' ) ?: 'now' ) );

// Build past-events page URL dynamically; fall back to slug.
$past_events_url = home_url( '/old-event-list/' );
$upcoming_url    = home_url( '/' );
?>
<div class="hostlinks-page">
<div class="hostlinks-container">

	<div class="hostlinks-actions">
		<a href="<?php echo esc_url( $upcoming_url ); ?>" class="hostlinks-btn hostlinks-btn--active">Upcoming Events</a>
		<a href="<?php echo esc_url( $past_events_url ); ?>" class="hostlinks-btn">Past Events</a>
		<span class="hostlinks-updated">Updated: <?php echo esc_html( $last_updated ); ?></span>
	</div>

<?php if ( empty( $all_pending_bookings ) ) : ?>
	<div class="hostlinks-empty">No upcoming events found.</div>
<?php else : ?>

<?php foreach ( $all_pending_bookings as $alldriver ) :

	$dt_start = new DateTime( $alldriver['eve_start'] );
	$dt_end   = new DateTime( $alldriver['eve_end'] );

	$type_name       = trim( $alldriver['event_type_name']       ?? '' );
	$marketer_name   = $alldriver['event_marketer_name']         ?? '';
	$instructor_name = $alldriver['event_instructor_name']       ?? '';

	$dater = explode( '-', $alldriver['eve_start'] );
	$yr    = $dater[0];
	$mo    = $dater[1];
	$month_key = $yr . $mo;

	// ── Open new month group when month changes ──────────────────────────
	if ( $current_month !== $month_key ) {

		// Close previous month grid + group.
		if ( $current_month !== null ) {
			echo '</div></div>'; // .hostlinks-grid + .hostlinks-month-group
		}

		$t     = $totals[ $yr ][ $mo ];
		$avg   = $t['cnt']   > 0 ? round( $t['paid']   / $t['cnt'] )   : 0;
		$avgwr = $t['wrcnt'] > 0 ? round( $t['wrpaid'] / $t['wrcnt'] ) : 0;
		$avgmg = $t['mgcnt'] > 0 ? round( $t['mgpaid'] / $t['mgcnt'] ) : 0;
		?>
	<div class="hostlinks-month-group">
		<div class="hostlinks-month-header">
			<h2><?php echo esc_html( $dt_start->format( 'F Y' ) ); ?></h2>
			<div class="hostlinks-month-stats">
				<span><?php echo esc_html( "{$t['paid']} / {$t['cnt']} / {$avg}" ); ?></span>
				<span>W&nbsp;<?php echo esc_html( "{$t['wrpaid']} / {$t['wrcnt']} / {$avgwr}" ); ?></span>
				<span>M&nbsp;<?php echo esc_html( "{$t['mgpaid']} / {$t['mgcnt']} / {$avgmg}" ); ?></span>
			</div>
		</div>
		<div class="hostlinks-grid">
		<?php
		$current_month = $month_key;
	}

	// ── Date range string ─────────────────────────────────────────────────
	$fsarray = explode( '-', $alldriver['eve_start'] );
	$lsarray = explode( '-', $alldriver['eve_end'] );
	if ( $fsarray[0] === $lsarray[0] ) {
		if ( $fsarray[1] === $lsarray[1] ) {
			$date_range = $dt_start->format( 'F' ) . '&nbsp;' . $fsarray[2] . '–' . $lsarray[2] . ",&nbsp;" . $fsarray[0];
		} else {
			$date_range = $dt_start->format( 'M' ) . '&nbsp;' . $fsarray[2] . '–' . $dt_end->format( 'M' ) . '&nbsp;' . $lsarray[2] . ",&nbsp;" . $fsarray[0];
		}
	} else {
		$date_range = $dt_start->format( 'M' ) . '&nbsp;' . $fsarray[2] . ",&nbsp;" . $fsarray[0] . '–' . $dt_end->format( 'M' ) . '&nbsp;' . $lsarray[2] . ",&nbsp;" . $lsarray[0];
	}

	// ── Days-to-event label ───────────────────────────────────────────────
	if ( $today > $dt_start ) {
		$days_label = ( $today > $dt_end ) ? 'The Event is History' : 'Event Started';
	} else {
		$days_label = ( $today->diff( $dt_start )->days + 1 ) . ' days to event';
	}

	// ── CSS modifier classes ──────────────────────────────────────────────
	$is_zoom       = ! empty( $alldriver['eve_zoom'] ) && strtolower( trim( $alldriver['eve_zoom'] ) ) === 'yes';
	$is_management = ( $type_name === 'Management' );

	$title_class      = 'hostlinks-card-title' . ( $is_zoom ? ' hostlinks-card-title--virtual' : '' );
	$instructor_class = 'hostlinks-card-instructor' . ( $is_management ? ' hostlinks-card-instructor--management' : '' );
	?>
		<div class="hostlinks-card">
			<div class="hostlinks-card-inner">
				<div class="hostlinks-card-top">
					<span class="hostlinks-reg-count"><?php echo (int) $alldriver['eve_paid']; ?>+<?php echo (int) $alldriver['eve_free']; ?></span>
					<span class="hostlinks-marketer"><?php echo esc_html( $marketer_name ); ?></span>
				</div>
				<a href="<?php echo esc_url( $alldriver['eve_host_url'] ); ?>" class="<?php echo esc_attr( $title_class ); ?>" target="_blank"><?php echo esc_html( $alldriver['eve_location'] ); ?></a>
				<a href="<?php echo esc_url( $alldriver['eve_roster_url'] ); ?>" class="hostlinks-roster-link" target="_blank">Roster</a>
				<div class="hostlinks-card-date"><?php echo $date_range; ?></div>
				<div class="<?php echo esc_attr( $instructor_class ); ?>">Instructor: <?php echo esc_html( $instructor_name ); ?></div>
				<div class="hostlinks-card-countdown"><?php echo esc_html( $days_label ); ?></div>
			</div>
		</div>

<?php endforeach; ?>

<?php if ( $current_month !== null ) : ?>
		</div><!-- .hostlinks-grid -->
	</div><!-- .hostlinks-month-group -->
<?php endif; ?>

<?php endif; ?>

</div><!-- .hostlinks-container -->
</div><!-- .hostlinks-page -->
