<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$timezone = new DateTimeZone( 'America/Los_Angeles' );
$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

// ── Full event form (add new, add from CVENT, or edit) ───────────────────────
if ( isset( $_GET['edit_event'] ) || isset( $_GET['add_event'] ) || isset( $_GET['add_cvent'] ) ) {
	require_once __DIR__ . '/edit-event.php';
	return;
}

// ── Determine active year-month filter (default: current month) ──────────
$currentYear  = (int) date('Y');
$currentMonth = date('m');
$defaultSyear = $currentYear . '-' . $currentMonth;

$syear = ( isset( $_GET['syear'] ) && $_GET['syear'] !== '' )
	? sanitize_text_field( $_GET['syear'] )
	: $defaultSyear;

$syearParts  = explode( '-', $syear );
$filterYear  = isset( $syearParts[0] ) ? (int) $syearParts[0] : $currentYear;
$filterMonth = isset( $syearParts[1] ) ? (int) $syearParts[1] : (int) $currentMonth;

// ── Bulk action processing (POST) ────────────────────────────────────────
$sucessmsgnew = '';
if ( isset( $_POST['deleteentire'] ) ) {
	check_admin_referer( 'hostlinks_manage_events' );

	if ( $_POST['actiondelete'] === 'delete' ) {
		$users = isset( $_POST['users'] ) ? (array) $_POST['users'] : array();
		foreach ( $users as $user ) {
			$wpdb->update( $table11, array( 'eve_status' => 2 ), array( 'eve_id' => intval( $user ) ), array( '%d' ), array( '%d' ) );
		}
		update_option( 'last_data_updation', wp_date( 'Y-m-d', null, $timezone ) );
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Event(s) deleted. <a href="admin.php?page=booking-menu&syear=' . esc_attr( $syear ) . '">Back to list</a></p></div>';
	}

	if ( $_POST['actiondelete'] === 'uppydate' ) {
		$users        = isset( $_POST['originalid'] ) ? (array) $_POST['originalid'] : array();
		$usersadvance = isset( $_POST['users'] )      ? (array) $_POST['users']      : array();
		if ( ! empty( $usersadvance ) ) {
			foreach ( $users as $key => $user ) {
				if ( in_array( $user, $usersadvance ) ) {
					$userid           = intval( $user );
					$eve_location     = sanitize_text_field( $_POST['eve_location'][ $key ] );
					$eve_paid         = intval( $_POST['eve_paid'][ $key ] );
					$eve_free         = intval( $_POST['eve_free'][ $key ] );
					$eve_tot_date     = sanitize_text_field( $_POST['evedate'][ $key ] );
					$_eveparts        = preg_split( '/ - /', $eve_tot_date, 2 );
					$_ts_start        = isset( $_eveparts[0] ) ? strtotime( trim( $_eveparts[0] ) ) : false;
					$_ts_end          = isset( $_eveparts[1] ) ? strtotime( trim( $_eveparts[1] ) ) : false;
					if ( $_ts_start && $_ts_end ) {
						$eve_start = date( 'Y-m-d', $_ts_start );
						$eve_end   = date( 'Y-m-d', $_ts_end );
					} else {
						// Unparseable date — preserve existing DB values rather than writing garbage.
						$_row         = $wpdb->get_row( $wpdb->prepare( "SELECT eve_start, eve_end, eve_tot_date FROM {$table11} WHERE eve_id = %d", $userid ), ARRAY_A );
						$eve_start    = $_row ? $_row['eve_start']    : '';
						$eve_end      = $_row ? $_row['eve_end']      : '';
						$eve_tot_date = $_row ? $_row['eve_tot_date'] : $eve_tot_date;
					}
					$eve_type         = intval( $_POST['eve_type'][ $key ] );
					$eve_zoom_array   = isset( $_POST['eve_zoom'] ) ? (array) $_POST['eve_zoom'] : array();
					$eve_zoom         = in_array( (string) $user, $eve_zoom_array ) ? 'yes' : '';
					$eve_marketer     = intval( $_POST['eve_marketer'][ $key ] );
					$eve_host_url     = esc_url_raw( trim( $_POST['eve_host_url'][ $key ] ) );
					$eve_roster_url   = esc_url_raw( trim( $_POST['eve_roster_url'][ $key ] ) );
					$eve_trainer_url  = esc_url_raw( trim( $_POST['eve_trainer_url'][ $key ] ) );
					$eve_web_url      = esc_url_raw( trim( $_POST['eve_web_url'][ $key ] ) );
					$eve_email_url    = esc_url_raw( trim( $_POST['eve_email_url'][ $key ] ?? '' ) );
					$eve_zoom_time    = sanitize_text_field( $_POST['eve_zoom_time'][ $key ] ?? '' );
					$hide_ids         = isset( $_POST['eve_public_hide_ids'] ) ? (array) $_POST['eve_public_hide_ids'] : array();
					$eve_public_hide  = in_array( (string) $user, $hide_ids ) ? 1 : 0;
					$eve_instructor   = intval( $_POST['eve_instructor'][ $key ] );
					// Auto-fill blank roster URL on update if roster page is configured.
					if ( ! $eve_roster_url ) {
						$roster_base = Hostlinks_Page_URLs::get_roster();
						if ( $roster_base ) {
							$eve_roster_url = rtrim( $roster_base, '/' ) . '/?eve_id=' . $userid;
						}
					}
					$wpdb->update(
						$table11,
						array(
							'eve_location'    => $eve_location,
							'eve_paid'        => $eve_paid,
							'eve_free'        => $eve_free,
							'eve_start'       => $eve_start,
							'eve_end'         => $eve_end,
							'eve_type'        => $eve_type,
							'eve_zoom'        => $eve_zoom,
							'eve_marketer'    => $eve_marketer,
							'eve_host_url'    => $eve_host_url,
							'eve_roster_url'  => $eve_roster_url,
							'eve_trainer_url' => $eve_trainer_url,
							'eve_web_url'     => $eve_web_url,
							'eve_email_url'   => $eve_email_url,
							'eve_zoom_time'   => $eve_zoom_time,
							'eve_public_hide' => $eve_public_hide,
							'eve_instructor'  => $eve_instructor,
							'eve_tot_date'    => $eve_tot_date,
						),
						array( 'eve_id' => $userid ),
						array( '%s','%d','%d','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s','%d','%d','%s' ),
						array( '%d' )
					);
				}
			}
			update_option( 'last_data_updation', wp_date( 'Y-m-d', null, $timezone ) );
			$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Event(s) successfully updated.</p></div>';
		}
	}
}

// ── Pre-fetch lookup tables once (used for dropdowns in every row) ────────
$all_pending_toter   = $wpdb->get_results( "SELECT * FROM $table12 WHERE `event_type_status` = '1'", ARRAY_A );
$all_pending_toterx  = $wpdb->get_results( "SELECT * FROM $table13 WHERE `event_marketer_status` = '1'", ARRAY_A );
$all_pending_toterxx = $wpdb->get_results( "SELECT * FROM $table14 WHERE `event_instructor_status` = '1'", ARRAY_A );

// ── Single JOIN query — eliminates N+1 per-row lookups ───────────────────
$all_pending_bookings = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT e.*,
		        t.event_type_name,
		        m.event_marketer_name,
		        i.event_instructor_name
		 FROM   {$table11} e
		 LEFT JOIN {$table12} t ON e.eve_type       = t.event_type_id
		 LEFT JOIN {$table13} m ON e.eve_marketer   = m.event_marketer_id
		 LEFT JOIN {$table14} i ON e.eve_instructor = i.event_instructor_id
		 WHERE  e.eve_status = '1' AND e.eve_start LIKE %s
		 ORDER BY e.eve_start ASC",
		$syear . '%'
	),
	ARRAY_A
);
$tot1 = count( $all_pending_bookings );
?>
<div id="wpbody">
<?php echo $sucessmsgnew; ?>
<div tabindex="0" id="wpbody-content" class="ddddd">
  <div class="wrap">
    <h2>Events <a class="add-new-h2" href="admin.php?page=booking-menu&add_event=1">Add New Event</a></h2>
    <ul class="subsubsubx">
      <li class="all"><a class="current">Showing <?php echo $tot1; ?> event(s) for <?php echo esc_html( date( 'F Y', mktime( 0, 0, 0, $filterMonth, 1, $filterYear ) ) ); ?></a></li>
    </ul>

    <?php /* ── Year / Month filter — uses JS redirect so it works inside a WP admin page callback ── */ ?>
    <div class="tablenav-pages" style="margin-bottom:10px;">
      <table border="0" cellspacing="0" cellpadding="0" class="listtable" width="100%"><tr>
        <td align="left">
          <select id="hl-chooseyear">
            <?php
            $yearStart = 2022;
            $yearEnd   = $currentYear + 1;
            for ( $yr = $yearEnd; $yr >= $yearStart; $yr-- ) {
                $sel = ( $yr === $filterYear ) ? 'selected' : '';
                echo "<option value=\"$yr\" $sel>$yr</option>";
            }
            ?>
          </select>
          <select id="hl-choosemonth">
            <?php
            $monthNames = array( 1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
                                 7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec' );
            for ( $m = 1; $m <= 12; $m++ ) {
                $mv  = str_pad( $m, 2, '0', STR_PAD_LEFT );
                $sel = ( $m === $filterMonth ) ? 'selected' : '';
                echo "<option value=\"$mv\" $sel>" . $monthNames[$m] . " ($mv)</option>";
            }
            ?>
          </select>
        </td>
      </tr></table>
    </div>
    <script>
    function hlApplyFilter() {
        var yr = document.getElementById('hl-chooseyear').value;
        var mo = document.getElementById('hl-choosemonth').value;
        window.location.href = 'admin.php?page=booking-menu&syear=' + yr + '-' + mo;
    }
    document.getElementById('hl-chooseyear').addEventListener('change', hlApplyFilter);
    document.getElementById('hl-choosemonth').addEventListener('change', hlApplyFilter);
    </script>

    <form method="post" action="admin.php?page=booking-menu&syear=<?php echo esc_attr( $syear ); ?>" id="posts-filter">
      <?php wp_nonce_field( 'hostlinks_manage_events' ); ?>
      <div class="tablenav-pages">
        <table border="0" cellspacing="0" cellpadding="0" class="listtable" width="100%"><tr>
          <td align="left">
            <div class="alignleft actions bulkactions">
              <select id="bulk-action-selector-top" name="actiondelete">
                <option class="hide-if-no-js" value="uppydate">Update</option>
                <option class="hide-if-no-js" value="delete">Delete</option>
              </select>
              <input type="submit" value="Apply" class="button action" id="doaction" name="deleteentire">
            </div>
          </td>
        </tr></table>
      </div>
      <input type="hidden" value="list" name="mode">
      <br class="clear">
      <table class="wp-list-table widefat table table-bordered table-striped dataTable" id="myTable">
        <thead>
          <tr>
            <th class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>
            <th>Location</th><th>Paid</th><th>Free</th><th>Date</th>
            <th>Type</th><th>Zoom</th><th>Marketer</th>
            <th style="width:40px;"></th><th>HOST URL</th><th>ROSTER URL</th><th>REG URL</th><th>WEB URL</th><th>EMAIL URL</th><th>ZOOM TIME</th><th>HIDE PUBLIC</th><th>Instructor</th>
          </tr>
        </thead>
        <tbody id="the-list">
          <?php
          if ( $tot1 > 0 ) {
              foreach ( $all_pending_bookings as $alldriver ) {
                  ?>
          <tr class="alternate" id="user-<?php echo esc_attr( $alldriver['eve_id'] ); ?>">
            <th class="check-column"><input type="checkbox" value="<?php echo esc_attr( $alldriver['eve_id'] ); ?>" class="administrator splchkkr" name="users[]"></th>
            <input type="hidden" name="originalid[]" value="<?php echo esc_attr( $alldriver['eve_id'] ); ?>">
            <td>
              <p class="hidder"><?php echo esc_html( $alldriver['eve_location'] ?? '' ); ?></p>
              <input type="text" value="<?php echo esc_attr( $alldriver['eve_location'] ?? '' ); ?>" name="eve_location[]" required autocomplete="off">
            </td>
            <td><p class="hidder"><?php echo esc_html( $alldriver['eve_paid'] ?? '' ); ?></p>
              <input type="number" value="<?php echo esc_attr( $alldriver['eve_paid'] ?? '' ); ?>" name="eve_paid[]" required style="width:50px;"></td>
            <td><p class="hidder"><?php echo esc_html( $alldriver['eve_free'] ?? '' ); ?></p>
              <input type="number" value="<?php echo esc_attr( $alldriver['eve_free'] ?? '' ); ?>" name="eve_free[]" required style="width:50px;"></td>
            <td><p class="hidder"><?php echo esc_html( $alldriver['eve_start'] ?? '' ); ?></p>
              <input type="text" name="evedate[]" class="sentinal inputfilder eventenddertot" id="eventenddertot<?php echo esc_attr( $alldriver['eve_id'] ); ?>" value="<?php echo esc_attr( $alldriver['eve_tot_date'] ?? '' ); ?>" required autocomplete="off"></td>
            <td>
              <select name="eve_type[]" class="evetype" required style="width:100px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toter as $alldriverx1 ) { ?>
                  <option value="<?php echo esc_attr( $alldriverx1['event_type_id'] ); ?>" <?php if ( $alldriverx1['event_type_id'] == $alldriver['eve_type'] ) echo 'selected'; ?>><?php echo esc_html( $alldriverx1['event_type_name'] ); ?></option>
                <?php } ?>
              </select>
              <p class="hidder"><?php echo esc_html( $alldriver['event_type_name'] ?? '' ); ?></p>
            </td>
            <td><input type="checkbox" class="splchkkr" value="<?php echo esc_attr( $alldriver['eve_id'] ); ?>" name="eve_zoom[]" <?php if ( ( $alldriver['eve_zoom'] ?? '' ) == 'yes' ) echo 'checked'; ?>></td>
            <td>
              <select name="eve_marketer[]" class="evetype" required style="width:100px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toterx as $alldriverx2 ) { ?>
                  <option value="<?php echo esc_attr( $alldriverx2['event_marketer_id'] ); ?>" <?php if ( $alldriver['eve_marketer'] == $alldriverx2['event_marketer_id'] ) echo 'selected'; ?>><?php echo esc_html( $alldriverx2['event_marketer_name'] ); ?></option>
                <?php } ?>
              </select>
              <p class="hidder"><?php echo esc_html( $alldriver['event_marketer_name'] ?? '' ); ?></p>
            </td>
            <td style="text-align:center;white-space:nowrap;">
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=booking-menu&edit_event=' . (int) $alldriver['eve_id'] ) ); ?>"
                title="Full Edit" class="button button-small" style="padding:2px 8px;font-size:11px;">&#9998; Edit</a>
            </td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_host_url'] ?? '' ); ?>" name="eve_host_url[]" style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_roster_url'] ?? '' ); ?>" name="eve_roster_url[]" style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_trainer_url'] ?? '' ); ?>" name="eve_trainer_url[]" style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_web_url'] ?? '' ); ?>" name="eve_web_url[]" style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_email_url'] ?? '' ); ?>" name="eve_email_url[]" style="width:140px;" placeholder="https://"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_zoom_time'] ?? '' ); ?>" name="eve_zoom_time[]" placeholder="e.g. 9:30-4:30 EST" style="width:110px;"></td>
            <td style="text-align:center;"><input type="checkbox" name="eve_public_hide_ids[]" value="<?php echo esc_attr( $alldriver['eve_id'] ); ?>" <?php checked( 1, intval( $alldriver['eve_public_hide'] ?? 0 ) ); ?>></td>
            <td>
              <select name="eve_instructor[]" class="evetype" required style="width:100px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toterxx as $alldriverx3 ) { ?>
                  <option value="<?php echo esc_attr( $alldriverx3['event_instructor_id'] ); ?>" <?php if ( $alldriver['eve_instructor'] == $alldriverx3['event_instructor_id'] ) echo 'selected'; ?>><?php echo esc_html( $alldriverx3['event_instructor_name'] ); ?></option>
                <?php } ?>
              </select>
              <p class="hidder"><?php echo esc_html( $alldriver['event_instructor_name'] ?? '' ); ?></p>
            </td>
          </tr>
                  <?php
              }
          } else { ?>
          <tr><td colspan="13"><h3>Sorry! Nothing Found</h3></td></tr>
          <?php } ?>
        </tbody>
      </table>
    </form>
  </div>
</div>

<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/dt/jszip-2.5.0/dt-1.12.1/af-2.4.0/b-2.2.3/b-colvis-2.2.3/b-html5-2.2.3/b-print-2.2.3/cr-1.5.6/date-1.1.2/fc-4.1.0/fh-3.2.4/kt-2.7.0/r-2.3.0/rg-1.2.0/rr-1.2.8/sc-2.0.7/sb-1.3.4/sp-2.0.2/sl-1.4.0/sr-1.1.1/datatables.min.css"/>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/pdfmake.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/vfs_fonts.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/v/dt/jszip-2.5.0/dt-1.12.1/af-2.4.0/b-2.2.3/b-colvis-2.2.3/b-html5-2.2.3/b-print-2.2.3/cr-1.5.6/date-1.1.2/fc-4.1.0/fh-3.2.4/kt-2.7.0/r-2.3.0/rg-1.2.0/rr-1.2.8/sc-2.0.7/sb-1.3.4/sp-2.0.2/sl-1.4.0/sr-1.1.1/datatables.min.js"></script>
<script type="text/javascript">
jQuery(function() {
  jQuery('.eventenddertot').each(function() {
    var $el  = jQuery(this);
    var val  = $el.val();
    var opts = { timePicker: false, locale: { format: 'YYYY/MM/DD' } };
    var p    = val ? val.split(' - ') : [];
    if ( p.length === 2 && p[0].trim() && p[1].trim() ) {
      opts.startDate = p[0].trim();
      opts.endDate   = p[1].trim();
    }
    $el.daterangepicker(opts);
  });
});
</script>
<script type="text/javascript">
jQuery(document).ready(function(){
  jQuery('#myTable').dataTable({
    "aoColumns": [null,null,null,null,{"sType":"date-uk"},null,null,null,null,null,null,null,null,null,null,null,null],
    "order": [[4,"asc"]],
    "bPaginate": true, "bLengthChange": true, "bFilter": true, "bSort": true,
    "bInfo": true, "bAutoWidth": true, "stateSave": true, "searching": true,
    "dom": 'lfrtip', "pageLength": 25, "lengthChange": true,
    "columnDefs": [{"targets":[0,2,3,5,6,7,8,9,10,11,12,13,14,15,16],"orderable":false}]
  });
  jQuery.extend(jQuery.fn.dataTableExt.oSort, {
    "date-uk-pre": function(a) { var d=a.split('/'); return (d[2]+d[1]+d[0])*1; },
    "date-uk-asc":  function(a,b) { return ((a<b)?-1:((a>b)?1:0)); },
    "date-uk-desc": function(a,b) { return ((a<b)?1:((a>b)?-1:0)); }
  });
});
</script>
<style type="text/css">
.hidder{display:none;}
.splchkkr,.administrator,#cb-select-all-1{width:17px!important;height:17px!important;}
th.manage-column{padding-bottom:0px!important;padding-top:10px!important;vertical-align:middle!important;}
.updpocode,.anewpostcode{background-color:#e0e0e0;padding:20px;width:49%;}
.TFtable tr:nth-child(odd){background:#f9f9f9;}
.TFtable tr:nth-child(even){background:#ededed;}
</style>
