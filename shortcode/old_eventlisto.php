<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;

$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

$currentYear = (int) wp_date('Y');

$selectedYear = ( isset( $_GET['syear'] ) && $_GET['syear'] !== '' )
	? (int) $_GET['syear']
	: $currentYear;

// ── Single JOIN query — replaces 3 per-row lookups (N+1 fix) ────────────────
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
$resulttotapplijobscnt = count( $all_pending_bookings );

// ── Pass 1: pre-calculate monthly totals ─────────────────────────────────────
$totals = array();
foreach ( $all_pending_bookings as $ev ) {
	$parts = explode( '-', $ev['eve_start'] );
	$yr    = $parts[0];
	$mo    = $parts[1];
	$type  = trim( $ev['event_type_name'] ?? '' );

	if ( ! isset( $totals[$yr][$mo] ) ) {
		$totals[$yr][$mo] = [ 'paid'=>0,'cnt'=>0,'wrpaid'=>0,'wrcnt'=>0,'mgpaid'=>0,'mgcnt'=>0 ];
	}

	$totals[$yr][$mo]['paid'] += (int) $ev['eve_paid'];
	$totals[$yr][$mo]['cnt']  += 1;

	if ( $type === 'Writing' ) {
		$totals[$yr][$mo]['wrpaid'] += (int) $ev['eve_paid'];
		$totals[$yr][$mo]['wrcnt']  += 1;
	} elseif ( $type === 'Management' ) {
		$totals[$yr][$mo]['mgpaid'] += (int) $ev['eve_paid'];
		$totals[$yr][$mo]['mgcnt']  += 1;
	}
}
// CSS is enqueued via class-assets.php (wp_enqueue_style) — no inline tags needed.
?>
<div class="alignwide">
	<div class="col-lg-12">
		<div class="card">
<div class="tablenav-pages" style="margin-bottom:10px;">
  <table border="0" cellspacing="0" cellpadding="30" class="listtable" width="100%"><tr>
    <td align="left">
      Filter By Year:
      <select id="hl-old-chooseyear" class="mosifyy">
        <?php
        $yearStart = 2022;
        $yearEnd   = $currentYear + 1;
        for ( $yr = $yearEnd; $yr >= $yearStart; $yr-- ) {
            $sel = ( $yr === $selectedYear ) ? 'selected' : '';
            echo "<option value=\"$yr\" $sel>$yr</option>";
        }
        ?>
      </select>
    </td>
  </tr></table>
</div>
<script>
document.getElementById('hl-old-chooseyear').addEventListener('change', function() {
    window.location.href = '<?php echo esc_js( get_permalink() ); ?>?syear=' + this.value;
});
</script>

		<div class="card-body">
<table class="castor" valign="top" cellspacing="0" cellpadding="8" border="0" bgcolor="ffffff" align="center">
<tr>
<td><span class="zoomyes"> color Key:zoom</span> <span class="management">mgmt</span></td>
<td></td><td></td><td></td>
<td><?php echo wp_date("m/d", strtotime( get_option('last_data_updation', '') ?: 'now' ) )?></td>
</tr>
</table>
<table valign="top" cellspacing="0" cellpadding="8" border="0" bgcolor="ffffff" align="center">
<tbody>
<?php
$yearss = 0;
$tt     = 1;
$today  = new DateTime();

if ( $resulttotapplijobscnt > 0 ) {

	foreach ( $all_pending_bookings as $alldriver ) {

		// Cache timestamps once per row (used multiple times for date formatting)
		$ts_start = strtotime( $alldriver['eve_start'] );
		$ts_end   = strtotime( $alldriver['eve_end'] );

		$type_name       = trim( $alldriver['event_type_name']       ?? '' );
		$marketer_name   = $alldriver['event_marketer_name']   ?? '';
		$instructor_name = $alldriver['event_instructor_name'] ?? '';

		$dater = explode( '-', $alldriver['eve_start'] );
		$yr    = $dater[0];
		$mo    = $dater[1];

		// ── Month header row (printed once per new month) ────────────────
		if ( $yearss != $yr . $mo ) {
			$tt     = 1;
			$yearss = $yr . $mo;

			$t     = $totals[$yr][$mo];
			$avg   = $t['cnt']   > 0 ? round( $t['paid']   / $t['cnt'] )   : 0;
			$avgwr = $t['wrcnt'] > 0 ? round( $t['wrpaid'] / $t['wrcnt'] ) : 0;
			$avgmg = $t['mgcnt'] > 0 ? round( $t['mgpaid'] / $t['mgcnt'] ) : 0;
			?>
	<tr bgcolor="ffffe1">
		<td valign="bottom"><p><b><?php echo wp_date("F Y", $ts_start); ?></b><br/>
		<?php echo "{$t['paid']} / {$t['cnt']} / {$avg}"; ?></p></td>
		<td valign="bottom"><p>W&nbsp;<?php echo "{$t['wrpaid']} / {$t['wrcnt']} / {$avgwr}"; ?></p></td>
		<td valign="bottom"><p>M&nbsp;<?php echo "{$t['mgpaid']} / {$t['mgcnt']} / {$avgmg}"; ?></p></td>
		<td></td><td></td>
	</tr>
	<tr>
			<?php
		}

		// ── Compute per-row values once (shared by both cell branches) ────
		$fsarray = explode( '-', $alldriver['eve_start'] );
		$lsarray = explode( '-', $alldriver['eve_end'] );
		if ( $fsarray[0] == $lsarray[0] ) {
			if ( $fsarray[1] == $lsarray[1] ) {
				$date_range = wp_date( "F", $ts_start ) . '&nbsp;' . $fsarray[2] . '-' . $lsarray[2] . ',&nbsp;' . $fsarray[0];
			} else {
				$date_range = wp_date( "M", $ts_start ) . ',' . $fsarray[2] . '-' . wp_date( "M", $ts_end ) . ',' . $lsarray[2] . ',&nbsp;' . $fsarray[0];
			}
		} else {
			$date_range = wp_date( "M", $ts_start ) . ',' . $fsarray[2] . ',' . $fsarray[0] . '-' . wp_date( "M", $ts_end ) . ',' . $lsarray[2] . ',' . $lsarray[0];
		}
		$date2 = new DateTime( $alldriver['eve_start'] );
		$date3 = new DateTime( $alldriver['eve_end'] );
		if ( $today > $date2 ) {
			$days_label = ( $today > $date3 ) ? 'The Event is History' : 'Event Started';
		} else {
			$days_label = $today->diff( $date2 )->days . ' days to event';
		}

		// ── Event cell ───────────────────────────────────────────────────
		if ( $tt % 5 == 0 ) { ?>
<td>
<span class="zoom<?php echo trim( strtolower( $alldriver['eve_zoom'] ) );?>"><a href="<?php echo $alldriver['eve_host_url']; ?>" target="_blank"><?php echo $alldriver['eve_location']; ?></a> <?php echo $alldriver['eve_paid']; ?> + <?php echo $alldriver['eve_free']; ?> <?php echo esc_html( $marketer_name );?></span><br/>
<a href="<?php echo $alldriver['eve_roster_url']; ?>" target="_blank" class="rosterlink">Roster</a> | <a href="<?php echo $alldriver['eve_trainer_url']; ?>" target="_blank" class="trainerlink">TR</a> | <a href="<?php echo $alldriver['eve_sign_in_url']; ?>" target="_blank" class="signinurllink">SI</a><br/>
<?php echo $date_range; ?><br/>
<span class="<?php echo trim( strtolower( $type_name ) );?>">Instructor: <?php echo esc_html( $instructor_name );?></span><br/>
<?php echo $days_label; ?>
</td>
</tr><tr>
		<?php
		} else { ?>
<td>
<span class="zoom<?php echo trim( strtolower( $alldriver['eve_zoom'] ) );?>"><a href="<?php echo $alldriver['eve_host_url']; ?>" target="_blank"><?php echo $alldriver['eve_location']; ?></a> <?php echo $alldriver['eve_paid']; ?>+<?php echo $alldriver['eve_free']; ?> <?php echo esc_html( $marketer_name );?></span><br/>
<a href="<?php echo $alldriver['eve_roster_url']; ?>" target="_blank" class="rosterlink">Roster</a> | <a href="<?php echo $alldriver['eve_trainer_url']; ?>" target="_blank" class="trainerlink">TR</a> | <a href="<?php echo $alldriver['eve_sign_in_url']; ?>" target="_blank" class="signinurllink">SI</a><br/>
<?php echo $date_range; ?><br/>
<span class="<?php echo trim( strtolower( $type_name ) );?>">Instructor: <?php echo esc_html( $instructor_name );?></span><br/>
<?php echo $days_label; ?>
</td>
		<?php
		}
		$tt++;
	}
}
?>
</tr>
</tbody>
</table>
		</div>
	</div>
</div>
<style>
.mosifyy{padding:5px 10px;}
.button.action.powerup{padding:5px 15px;background-color:#5de3f9;border-radius:5px;box-shadow:2px 2px 2px #80808073;border:none!important;}
.card-body table{width:100%;}
td p{margin-bottom:0px;}
.castor{width:100%;}
.zoomyes{background-color:#f2e2e2;}
.management{background-color:#a4dfa4;padding-left:10px;padding-right:10px;}
tr{border-bottom-style:dashed;border-bottom-color:#b2b2b2;border-bottom-width:1px;}
.card{max-width:100%;}
.rosterlink,.trainerlink,.signinurllink{font-size:1.05em;}
</style>
