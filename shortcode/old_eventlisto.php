<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
if ( ! is_user_logged_in() ) {
	wp_redirect( home_url() );
	exit;
}

if ( isset( $_POST['applyfilter'] ) ) {
	$thispage    = get_permalink();
	$chooseyear  = $_POST['chooseyear'];
	wp_redirect( $thispage . '?syear=' . $chooseyear );
}

$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

$currentYear = (int) date('Y');

// Use syear from URL if provided and non-empty, otherwise default to current year
$selectedYear = ( isset( $_GET['syear'] ) && $_GET['syear'] !== '' )
	? (int) $_GET['syear']
	: $currentYear;

$all_pending_bookings = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM $table11 WHERE `eve_status` = '1' AND `eve_start` LIKE %s ORDER BY `eve_start` ASC",
		$selectedYear . '%'
	),
	ARRAY_A
);
$resulttotapplijobscnt = $wpdb->num_rows;
?>
<link href="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/css/bootstrap.min.css" id="bootstrap-style" rel="stylesheet" type="text/css" />
<link href="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/css/icons.min.css" rel="stylesheet" type="text/css" />
<link href="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/css/app.min.css" id="app-style" rel="stylesheet" type="text/css" />
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>

<div class="alignwide">
	<div class="col-lg-12">
		<div class="card">
<form method="post" action="" id="posts-filter">
  <div class="tablenav-pages">
    <table border="0" cellspacing="0" cellpadding="30" class="listtable" width="100%"><tr>
      <td align="left">
        Filter By Year:
        <select name="chooseyear" class="mosifyy">
          <option value="">Choose Year</option>
          <?php
          // Build year list dynamically: 4 years back through 1 year ahead
          $yearStart = $currentYear - 4;
          $yearEnd   = $currentYear + 1;
          for ( $yr = $yearEnd; $yr >= $yearStart; $yr-- ) {
              $sel = ( $yr === $selectedYear ) ? 'selected' : '';
              echo "<option value=\"$yr\" $sel>$yr</option>";
          }
          ?>
        </select>
        <input type="submit" value="Apply Filter" class="button action powerup" name="applyfilter">
      </td>
    </tr></table>
  </div>
</form>

		<div class="card-body">
<table class="castor" valign="top" cellspacing="0" cellpadding="8" border="0" bgcolor="ffffff" align="center">
<tr>
<td><span class="zoomyes"> color Key:zoom</span> <span class="management">mgmt</span></td>
<td></td><td></td><td></td>
<td><?php echo date("m/d", strtotime( get_option('last_data_updation', true) ) )?></td>
</tr>
</table>
<table valign="top" cellspacing="0" cellpadding="8" border="0" bgcolor="ffffff" align="center">
<tbody>
<?php
$yearss   = 0;
$tt       = 1;
$papaya   = 1;
$totsum   = array();
$toteve   = array();
$totsumwr = array();
$totevewr = array();
$totsummg = array();
$totevemg = array();

if ( $resulttotapplijobscnt > 0 ) {

	foreach ( $all_pending_bookings as $alldriver ) {
		$all_pending_sex1 = $wpdb->get_row( "SELECT * FROM $table12 WHERE `event_type_id` = " . $alldriver['eve_type'], ARRAY_A );
		$all_pending_sex2 = $wpdb->get_row( "SELECT * FROM $table13 WHERE `event_marketer_id` = " . $alldriver['eve_marketer'], ARRAY_A );
		$all_pending_sex3 = $wpdb->get_row( "SELECT * FROM $table14 WHERE `event_instructor_id` = " . $alldriver['eve_instructor'], ARRAY_A );

		$dater = explode( '-', $alldriver['eve_start'] );
		if ( $yearss != $dater[0] . '' . $dater[1] ) {
			$tt     = 1;
			$yearss = $dater[0] . '' . $dater[1];
			?>
	<tr bgcolor="ffffe1"><td><p><b><?php echo date("F Y", strtotime( $alldriver['eve_start'] ) )?></b>
	<br/><b id="sectionone<?php echo $papaya;?>"></b></p></td>
	<td><p><b id="wrsectionone<?php echo $papaya;?>"></b></p></td>
	<td><p><b id="mgsectionone<?php echo $papaya;?>"></b></p></td>
	<td></td><td></td></tr>
	<tr>
			<?php
			$papaya++;
		}

		$totsum[$dater[0]][$dater[1]] = ( $totsum[$dater[0]][$dater[1]] ?? 0 ) + $alldriver['eve_paid'];
		$toteve[$dater[0]][$dater[1]] = ( $toteve[$dater[0]][$dater[1]] ?? 0 ) + 1;

		if ( trim( $all_pending_sex1['event_type_name'] ) == 'Writing' ) {
			$totsumwr[$dater[0]][$dater[1]] = ( $totsumwr[$dater[0]][$dater[1]] ?? 0 ) + $alldriver['eve_paid'];
			$totevewr[$dater[0]][$dater[1]] = ( $totevewr[$dater[0]][$dater[1]] ?? 0 ) + 1;
		} elseif ( trim( $all_pending_sex1['event_type_name'] ) == 'Management' ) {
			$totsummg[$dater[0]][$dater[1]] = ( $totsummg[$dater[0]][$dater[1]] ?? 0 ) + $alldriver['eve_paid'];
			$totevemg[$dater[0]][$dater[1]] = ( $totevemg[$dater[0]][$dater[1]] ?? 0 ) + 1;
		}

		if ( $tt % 5 == 0 ) { ?>
<td>
<span class="zoom<?php echo trim( strtolower( $alldriver['eve_zoom'] ) );?>"><a href="<?php echo $alldriver['eve_host_url']; ?>" target="_blank"><?php echo $alldriver['eve_location']; ?></a> <?php echo $alldriver['eve_paid']; ?> + <?php echo $alldriver['eve_free']; ?> <?php echo $all_pending_sex2['event_marketer_name'];?></span><br/>
<a href="<?php echo $alldriver['eve_roster_url']; ?>" target="_blank">Roster</a> | <a href="<?php echo $alldriver['eve_trainner_url']; ?>" target="_blank">TR</a> | <a href="<?php echo $alldriver['eve_sign_in_url']; ?>" target="_blank">SI</a><br/>
<?php
			$fsarray = explode( '-', $alldriver['eve_start'] );
			$lsarray = explode( '-', $alldriver['eve_end'] );
			if ( $fsarray[0] == $lsarray[0] ) {
				if ( $fsarray[1] == $lsarray[1] ) {
					echo date( "F", strtotime( $alldriver['eve_start'] ) ); ?><?php echo '&nbsp;' . $fsarray[2] . '-' . $lsarray[2];
				} else {
					echo date( "M", strtotime( $alldriver['eve_start'] ) ); ?><?php echo ',' . $fsarray[2] . '-'; ?><?php echo date( "M", strtotime( $alldriver['eve_end'] ) ); ?><?php echo ',' . $lsarray[2];
				}
				echo ',&nbsp;' . $fsarray[0];
			} else {
				echo date( "M", strtotime( $alldriver['eve_start'] ) ); ?><?php echo ',' . $fsarray[2] . ',' . $fsarray[0] . '-'; ?><?php echo date( "M", strtotime( $alldriver['eve_end'] ) ); ?><?php echo ',' . $lsarray[2] . ',' . $lsarray[0];
			}
			?>
<br/>
<span class="<?php echo trim( strtolower( $all_pending_sex1['event_type_name'] ) );?>">Instructor: <?php echo $all_pending_sex3['event_instructor_name'];?></span><br/>
<?php
			$date1 = new DateTime();
			$date2 = new DateTime( $alldriver['eve_start'] );
			$date3 = new DateTime( $alldriver['eve_end'] );
			if ( $date1 > $date2 ) {
				echo ( $date1 > $date3 ) ? 'The Event is History' : 'Event Started';
			} else {
				echo $date1->diff($date2)->days . ' days to event';
			}
			?>
</td>
</tr><tr>
		<?php
		} else { ?>
<td>
<span class="zoom<?php echo trim( strtolower( $alldriver['eve_zoom'] ) );?>"><a href="<?php echo $alldriver['eve_host_url']; ?>" target="_blank"><?php echo $alldriver['eve_location']; ?></a> <?php echo $alldriver['eve_paid']; ?>+<?php echo $alldriver['eve_free']; ?> <?php echo $all_pending_sex2['event_marketer_name'];?></span><br/>
<a href="<?php echo $alldriver['eve_roster_url']; ?>" target="_blank" class="rosterlink">Roster</a> | <a href="<?php echo $alldriver['eve_trainner_url']; ?>" target="_blank" class="trainerlink">TR</a> | <a href="<?php echo $alldriver['eve_sign_in_url']; ?>" target="_blank" class="signinurllink">SI</a><br/>
<?php
			$fsarray = explode( '-', $alldriver['eve_start'] );
			$lsarray = explode( '-', $alldriver['eve_end'] );
			if ( $fsarray[0] == $lsarray[0] ) {
				if ( $fsarray[1] == $lsarray[1] ) {
					echo date( "F", strtotime( $alldriver['eve_start'] ) ); ?><?php echo '&nbsp;' . $fsarray[2] . '-' . $lsarray[2];
				} else {
					echo date( "M", strtotime( $alldriver['eve_start'] ) ); ?><?php echo ',' . $fsarray[2] . '-'; ?><?php echo date( "M", strtotime( $alldriver['eve_end'] ) ); ?><?php echo ',' . $lsarray[2];
				}
				echo ',&nbsp;' . $fsarray[0];
			} else {
				echo date( "M", strtotime( $alldriver['eve_start'] ) ); ?><?php echo ',' . $fsarray[2] . ',' . $fsarray[0] . '-'; ?><?php echo date( "M", strtotime( $alldriver['eve_end'] ) ); ?><?php echo ',' . $lsarray[2] . ',' . $lsarray[0];
			}
			?><br/>
<span class="<?php echo trim( strtolower( $all_pending_sex1['event_type_name'] ) );?>">Instructor: <?php echo $all_pending_sex3['event_instructor_name'];?></span><br/>
<?php
			$date1 = new DateTime();
			$date2 = new DateTime( $alldriver['eve_start'] );
			$date3 = new DateTime( $alldriver['eve_end'] );
			if ( $date1 > $date2 ) {
				echo ( $date1 > $date3 ) ? 'The Event is History' : 'Event Started';
			} else {
				$date11 = date_create( $alldriver['eve_start'] );
				$date21 = date_create( date('Y-m-d') );
				$diff   = date_diff( $date11, $date21 );
				echo $diff->format("%a days") . ' to event';
			}
			?>
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

<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/jquery/jquery.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/metismenu/metisMenu.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/simplebar/simplebar.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/node-waves/waves.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/waypoints/lib/jquery.waypoints.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/jquery.counterup/jquery.counterup.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/moment/min/moment.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/libs/jquery-ui-dist/jquery-ui.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/js/app.js"></script>
<?php
$ryy = 1;
foreach ( $totsum as $keyx => $totsumx ) {
	foreach ( $totsumx as $key => $totsumxx ) {
		$_evetot    = $toteve[$keyx][$key] ?? 0;
		$disblval   = ( $_evetot > 0 ) ? round( $totsumxx / $_evetot ) : 0;
		$_sumwr     = $totsumwr[$keyx][$key] ?? 0;
		$_evewr     = $totevewr[$keyx][$key] ?? 0;
		$disblvalon = ( $_sumwr != 0 && $_evewr != 0 ) ? round( $_sumwr / $_evewr ) : 0;
		$_summg     = $totsummg[$keyx][$key] ?? 0;
		$_evemg     = $totevemg[$keyx][$key] ?? 0;
		$disblvalto = ( $_summg != 0 && $_evemg != 0 ) ? round( $_summg / $_evemg ) : 0;
		?>
<script>
jQuery(document).ready(function(){
	jQuery('#sectionone<?php echo $ryy;?>').html('<?php echo $totsumxx;?> / <?php echo $_evetot; ?> / <?php echo $disblval;?>');
	jQuery('#wrsectionone<?php echo $ryy;?>').html('W &nbsp;<?php echo $_sumwr; ?> / <?php echo $_evewr; ?> / <?php echo $disblvalon;?>');
	jQuery('#mgsectionone<?php echo $ryy;?>').html('M &nbsp;<?php echo $_summg; ?> / <?php echo $_evemg; ?> / <?php echo $disblvalto;?>');
});
</script>
		<?php
		$ryy++;
	}
}
?>
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
</style>

