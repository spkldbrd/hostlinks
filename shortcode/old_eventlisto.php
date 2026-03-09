<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb;

$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

$currentYear = (int) wp_date( 'Y' );

$selectedYear = ( isset( $_GET['syear'] ) && $_GET['syear'] !== '' )
	? (int) $_GET['syear']
	: $currentYear;

// ── Focus filter ──────────────────────────────────────────────────────────────
$focus_id = isset( $_GET['focus'] ) ? (int) $_GET['focus'] : 0;

// Load active marketers for the Focus dropdown.
$marketers = $wpdb->get_results(
	"SELECT event_marketer_id, event_marketer_name
	 FROM {$table13}
	 WHERE event_marketer_status = 1
	 ORDER BY event_marketer_name ASC"
);

// Resolve the focused marketer's display name (for the empty-state message).
$focus_name = '';
if ( $focus_id > 0 ) {
	foreach ( $marketers as $mk ) {
		if ( (int) $mk->event_marketer_id === $focus_id ) {
			$focus_name = $mk->event_marketer_name;
			break;
		}
	}
}

// ── Single JOIN query — replaces 3 per-row lookups (N+1 fix) ─────────────────
if ( $focus_id > 0 ) {
	$all_pending_bookings = $wpdb->get_results( $wpdb->prepare(
		"SELECT e.*,
		        t.event_type_name,
		        m.event_marketer_name,
		        i.event_instructor_name
		 FROM {$table11} e
		 LEFT JOIN {$table12} t ON e.eve_type        = t.event_type_id
		 LEFT JOIN {$table13} m ON e.eve_marketer    = m.event_marketer_id
		 LEFT JOIN {$table14} i ON e.eve_instructor  = i.event_instructor_id
		 WHERE e.eve_status = '1' AND e.eve_start LIKE %s AND e.eve_marketer = %d
		 ORDER BY e.eve_start ASC",
		$selectedYear . '%', $focus_id
	), ARRAY_A );
} else {
	$all_pending_bookings = $wpdb->get_results( $wpdb->prepare(
		"SELECT e.*,
		        t.event_type_name,
		        m.event_marketer_name,
		        i.event_instructor_name
		 FROM {$table11} e
		 LEFT JOIN {$table12} t ON e.eve_type        = t.event_type_id
		 LEFT JOIN {$table13} m ON e.eve_marketer    = m.event_marketer_id
		 LEFT JOIN {$table14} i ON e.eve_instructor  = i.event_instructor_id
		 WHERE e.eve_status = '1' AND e.eve_start LIKE %s
		 ORDER BY e.eve_start ASC",
		$selectedYear . '%'
	), ARRAY_A );
}

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
$today         = new DateTime();
$current_month = null;
$upcoming_url     = Hostlinks_Page_URLs::get_upcoming();
$page_url         = get_permalink();
$reports_page_url = Hostlinks_Page_URLs::get_reports();

$_upd_raw     = get_option( 'last_data_updation', '' );
$_upd_dt      = $_upd_raw ? DateTime::createFromFormat( 'Y-m-d', $_upd_raw ) : null;
$last_updated = $_upd_dt ? $_upd_dt->format( 'm/d' ) : ( new DateTime() )->format( 'm/d' );
?>
<div class="hostlinks-page">
<div class="hostlinks-container">

	<div class="hostlinks-actions">
		<a href="<?php echo esc_url( $upcoming_url ); ?>" class="hostlinks-btn">Upcoming Events</a>
		<a href="<?php echo esc_url( $page_url ); ?>" class="hostlinks-btn hostlinks-btn--active">Past Events</a>

		<select id="hl-old-chooseyear" class="hostlinks-year-filter" aria-label="Filter by year">
			<?php
			$yearStart = 2022;
			$yearEnd   = $currentYear + 1;
			for ( $y = $yearEnd; $y >= $yearStart; $y-- ) {
				$sel = ( $y === $selectedYear ) ? 'selected' : '';
				echo "<option value=\"{$y}\" {$sel}>{$y}</option>";
			}
			?>
		</select>

		<select id="hl-focus-marketer" class="hostlinks-year-filter" aria-label="Focus by marketer">
			<option value="0" <?php selected( $focus_id, 0 ); ?>>All Marketers</option>
			<?php foreach ( $marketers as $mk ) : ?>
			<option value="<?php echo (int) $mk->event_marketer_id; ?>" <?php selected( $focus_id, (int) $mk->event_marketer_id ); ?>>
				<?php echo esc_html( $mk->event_marketer_name ); ?>
			</option>
			<?php endforeach; ?>
		</select>

		<script>
		(function() {
			function hlOldNav() {
				var yr  = document.getElementById('hl-old-chooseyear').value;
				var fk  = document.getElementById('hl-focus-marketer').value;
				var url = '<?php echo esc_js( $page_url ); ?>?syear=' + yr;
				if ( fk && fk !== '0' ) { url += '&focus=' + fk; }
				window.location.href = url;
			}
			document.getElementById('hl-old-chooseyear').addEventListener('change', hlOldNav);
			document.getElementById('hl-focus-marketer').addEventListener('change', hlOldNav);
		})();
		</script>

		<?php if ( $reports_page_url ) : ?>
		<a href="<?php echo esc_url( $reports_page_url ); ?>" class="hostlinks-btn" style="margin-left:auto;">&#x1F4CA; Reports</a>
		<?php endif; ?>
		<span class="hostlinks-updated">Updated: <?php echo esc_html( $last_updated ); ?></span>
	</div>

<?php
$empty_msg = $focus_name
	? 'No events found for ' . esc_html( $focus_name ) . ' in ' . $selectedYear . '.'
	: 'No events found for ' . (int) $selectedYear . '.';
?>

<?php if ( empty( $all_pending_bookings ) ) : ?>
	<div class="hostlinks-empty"><?php echo $empty_msg; ?></div>
<?php else : ?>

<?php foreach ( $all_pending_bookings as $alldriver ) :

	$dt_start = new DateTime( $alldriver['eve_start'] );
	$dt_end   = new DateTime( $alldriver['eve_end'] );

	$type_name       = trim( $alldriver['event_type_name']       ?? '' );
	$marketer_name   = $alldriver['event_marketer_name']         ?? '';
	$instructor_name = $alldriver['event_instructor_name']       ?? '';

	$dater     = explode( '-', $alldriver['eve_start'] );
	$yr        = $dater[0];
	$mo        = $dater[1];
	$month_key = $yr . $mo;

	// ── Open new month group when month changes ──────────────────────────
	if ( $current_month !== $month_key ) {

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
				<div class="hostlinks-card-links">
					<a href="<?php echo esc_url( $alldriver['eve_roster_url'] ); ?>" class="hostlinks-roster-link" target="_blank">Roster</a>
					<?php if ( ! empty( $alldriver['eve_trainer_url'] ) ) : ?>
					&nbsp;|&nbsp;<a href="<?php echo esc_url( $alldriver['eve_trainer_url'] ); ?>" class="hostlinks-roster-link" target="_blank">Reg</a>
					<?php endif; ?>
					<?php if ( ! empty( $alldriver['eve_web_url'] ) ) : ?>
					&nbsp;|&nbsp;<a href="<?php echo esc_url( $alldriver['eve_web_url'] ); ?>" class="hostlinks-roster-link" target="_blank">SI</a>
					<?php endif; ?>
				</div>
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
