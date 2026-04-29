<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$timezone = new DateTimeZone( 'America/Los_Angeles' );
$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

// ── Full event form (add new, add from CVENT, add from request, or edit) ────
if ( isset( $_GET['edit_event'] ) || isset( $_GET['add_event'] ) || isset( $_GET['add_cvent'] ) || isset( $_GET['add_request'] ) ) {
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
// $filterMonth is 0 when showing the whole year (syear=YYYY with no -MM suffix).
$filterMonth = isset( $syearParts[1] ) ? (int) $syearParts[1] : 0;
$allMonths   = ( $filterMonth === 0 );

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
// NOTE: we deliberately DO NOT reference t.event_type_abbr here. That column
// arrived in DB v2.4, and if the migration has not yet run on a given site
// (stale option, silently-failed ALTER, etc.), referencing it would kill the
// whole SELECT and render the event list as empty. The dropdown gets the
// abbreviation from $all_pending_toter (SELECT * below, with ?? '' fallback),
// so omitting it from the JOIN costs nothing.
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

// Pre-fetch average daily registration rates for all displayed events in one query.
$_hl_avg_regs = array();
if ( $tot1 > 0 ) {
	$_hl_eve_ids  = array_map( fn( $r ) => (int) $r['eve_id'], $all_pending_bookings );
	$_hl_avg_regs = Hostlinks_DB::get_avg_daily_regs_bulk( $_hl_eve_ids );
}
?>
<div id="wpbody">
<?php echo $sucessmsgnew; ?>
<div tabindex="0" id="wpbody-content" class="ddddd">
  <div class="wrap">
    <h2>Events <a class="add-new-h2" href="admin.php?page=booking-menu&add_event=1">Add New Event</a></h2>
    <ul class="subsubsubx">
      <li class="all"><a class="current">Showing <?php echo $tot1; ?> event(s) for <?php
          echo esc_html( $allMonths
              ? ( 'All of ' . $filterYear )
              : date( 'F Y', mktime( 0, 0, 0, $filterMonth, 1, $filterYear ) )
          );
      ?></a></li>
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
            <option value="all" <?php echo $allMonths ? 'selected' : ''; ?>>All months</option>
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
        var yr  = document.getElementById('hl-chooseyear').value;
        var mo  = document.getElementById('hl-choosemonth').value;
        var url = 'admin.php?page=booking-menu&syear=' + yr;
        if (mo !== 'all') { url += '-' + mo; }
        window.location.href = url;
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
            <th style="width:40px;"></th>
            <th>Location</th><th>Paid</th><th>Free</th><th style="width:90px;">Date</th>
            <th style="width:60px;">Type</th><th>Zoom</th><th>Marketer</th>
            <th>HOST URL</th><th>ROSTER URL</th><th>REG URL</th><th>WEB URL</th><th>EMAIL URL</th><th>ZOOM TIME</th><th>HIDE PUBLIC</th><th>Instructor</th><th style="width:52px;" title="Average daily registrations tracked since first CVENT sync">Avg/day</th>
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
            <td class="hl-edit-cell">
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=booking-menu&edit_event=' . (int) $alldriver['eve_id'] ) ); ?>"
                title="Edit event" aria-label="Edit event #<?php echo (int) $alldriver['eve_id']; ?>"
                class="hl-edit-icon">
                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
              </a>
            </td>
            <td>
              <p class="hidder"><?php echo esc_html( $alldriver['eve_location'] ?? '' ); ?></p>
              <input type="text" value="<?php echo esc_attr( $alldriver['eve_location'] ?? '' ); ?>" name="eve_location[]" required autocomplete="off">
            </td>
            <td><p class="hidder"><?php echo esc_html( $alldriver['eve_paid'] ?? '' ); ?></p>
              <input type="number" value="<?php echo esc_attr( $alldriver['eve_paid'] ?? '' ); ?>" name="eve_paid[]" required style="width:50px;"></td>
            <td><p class="hidder"><?php echo esc_html( $alldriver['eve_free'] ?? '' ); ?></p>
              <input type="number" value="<?php echo esc_attr( $alldriver['eve_free'] ?? '' ); ?>" name="eve_free[]" required style="width:50px;"></td>
            <td>
              <p class="hidder"><?php echo esc_html( $alldriver['eve_start'] ?? '' ); ?></p>
              <?php
              // Compact display: "MM/DD-MM/DD" (same-day collapses to "MM/DD").
              // The real <input> below stays in the DOM and holds the full
              // YYYY/MM/DD - YYYY/MM/DD string that daterangepicker & the
              // POST handler expect — this span is purely visual.
              $_start_ts = ! empty( $alldriver['eve_start'] ) ? strtotime( $alldriver['eve_start'] ) : false;
              $_end_ts   = ! empty( $alldriver['eve_end'] )   ? strtotime( $alldriver['eve_end'] )   : false;
              $_compact  = '';
              if ( $_start_ts ) {
                  $_compact = date( 'm/d', $_start_ts );
                  if ( $_end_ts && $_end_ts !== $_start_ts ) {
                      $_compact .= '-' . date( 'm/d', $_end_ts );
                  }
              }
              ?>
              <span class="hl-date-wrap">
                <span class="hl-compact-date" title="<?php echo esc_attr( $alldriver['eve_tot_date'] ?? '' ); ?>"><?php echo esc_html( $_compact !== '' ? $_compact : '—' ); ?></span>
                <input type="text" name="evedate[]" class="sentinal inputfilder eventenddertot hl-date-hidden" id="eventenddertot<?php echo esc_attr( $alldriver['eve_id'] ); ?>" value="<?php echo esc_attr( $alldriver['eve_tot_date'] ?? '' ); ?>" required autocomplete="off">
              </span>
            </td>
            <td>
              <select name="eve_type[]" class="evetype hl-type-compact" required>
                <option value="">—</option>
                <?php foreach ( $all_pending_toter as $alldriverx1 ) {
                    $_opt_abbr  = $alldriverx1['event_type_abbr'] ?? '';
                    $_opt_label = $_opt_abbr !== '' ? $_opt_abbr : $alldriverx1['event_type_name'];
                    $_opt_title = $alldriverx1['event_type_name'];
                ?>
                  <option value="<?php echo esc_attr( $alldriverx1['event_type_id'] ); ?>" title="<?php echo esc_attr( $_opt_title ); ?>" <?php if ( $alldriverx1['event_type_id'] == $alldriver['eve_type'] ) echo 'selected'; ?>><?php echo esc_html( $_opt_label ); ?></option>
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
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_host_url'] ?? '' ); ?>" name="eve_host_url[]" style="width:98px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_roster_url'] ?? '' ); ?>" name="eve_roster_url[]" style="width:98px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_trainer_url'] ?? '' ); ?>" name="eve_trainer_url[]" style="width:98px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_web_url'] ?? '' ); ?>" name="eve_web_url[]" style="width:98px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_email_url'] ?? '' ); ?>" name="eve_email_url[]" style="width:98px;" placeholder="https://"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_zoom_time'] ?? '' ); ?>" name="eve_zoom_time[]" placeholder="e.g. 9:30-4:30 EST" style="width:110px;"></td>
            <td style="text-align:center;"><input type="checkbox" name="eve_public_hide_ids[]" value="<?php echo esc_attr( $alldriver['eve_id'] ); ?>" <?php checked( 1, intval( $alldriver['eve_public_hide'] ?? 0 ) ); ?>></td>
            <td>
              <select name="eve_instructor[]" class="evetype" required style="width:80px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toterxx as $alldriverx3 ) { ?>
                  <option value="<?php echo esc_attr( $alldriverx3['event_instructor_id'] ); ?>" <?php if ( $alldriver['eve_instructor'] == $alldriverx3['event_instructor_id'] ) echo 'selected'; ?>><?php echo esc_html( $alldriverx3['event_instructor_name'] ); ?></option>
                <?php } ?>
              </select>
              <p class="hidder"><?php echo esc_html( $alldriver['event_instructor_name'] ?? '' ); ?></p>
            </td>
            <td style="text-align:center;font-size:12px;color:#555;" title="Avg daily registrations since first CVENT sync"><?php
              $_avg = $_hl_avg_regs[ (int) $alldriver['eve_id'] ] ?? null;
              echo $_avg !== null ? esc_html( number_format( $_avg, 1 ) ) : '<span style="color:#bbb;">—</span>';
            ?></td>
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
  // Format a moment-ish date-string to "MM/DD" for the compact display.
  function _hlCompactMD(d) {
    if (!d || typeof d.format !== 'function') return '';
    return d.format('MM/DD');
  }
  function _hlRefreshCompact($input) {
    var $row  = $input.closest('tr');
    var $span = $row.find('span.hl-compact-date');
    if (!$span.length) return;
    var dp = $input.data('daterangepicker');
    if (!dp) return;
    var s = dp.startDate, e = dp.endDate;
    var out = _hlCompactMD(s);
    if (e && e.format('YYYY-MM-DD') !== s.format('YYYY-MM-DD')) {
      out += '-' + _hlCompactMD(e);
    }
    $span.text(out || '—').attr('title', s.format('YYYY/MM/DD') + ' - ' + e.format('YYYY/MM/DD'));
    // Keep the real hidden input's value in the picker's output format so
    // the POST handler still parses it via preg_split('/ - /', ...).
    $input.val( s.format('YYYY/MM/DD') + ' - ' + e.format('YYYY/MM/DD') );
  }

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
    $el.on('apply.daterangepicker', function() { _hlRefreshCompact( jQuery(this) ); });
  });

  // Clicking the compact span opens the associated picker.
  jQuery(document).on('click', 'span.hl-compact-date', function() {
    var $input = jQuery(this).siblings('input.eventenddertot');
    var dp     = $input.data('daterangepicker');
    if (dp) { dp.show(); }
  });
});
</script>
<script type="text/javascript">
jQuery(document).ready(function(){
  jQuery('#myTable').dataTable({
    "aoColumns": [null,null,null,null,null,{"sType":"date-uk"},null,null,null,null,null,null,null,null,null,null,null,null],
    "order": [[5,"asc"]],
    "bPaginate": true, "bLengthChange": true, "bFilter": true, "bSort": true,
    "bInfo": true, "bAutoWidth": true, "stateSave": true, "searching": true,
    "dom": 'lfrtip', "pageLength": 25, "lengthChange": true,
    "columnDefs": [{"targets":[0,1,3,4,6,7,8,9,10,11,12,13,14,15,16,17],"orderable":false}],
    // Keep sort/pagination state persisted across loads, but always start
    // with an empty search box. Clears both the saved value and any
    // per-column search state before DataTables applies it on init.
    "stateLoadParams": function(settings, data) {
      if (data && data.search) { data.search.search = ""; }
      if (data && Array.isArray(data.columns)) {
        for (var i = 0; i < data.columns.length; i++) {
          if (data.columns[i] && data.columns[i].search) {
            data.columns[i].search.search = "";
          }
        }
      }
    }
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

/* Event list: compact date column.
   The wrap is a positioned inline box. The compact span sits on top as the
   visible label. The real daterangepicker-bound input is overlaid at the same
   spot with opacity:0 so the popup still anchors correctly to the input's
   on-screen rect, but it's invisible and pointer-events pass through to the
   span underneath (so the span click handler fires). */
.hl-date-wrap{position:relative;display:inline-block;}
.hl-date-hidden{position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;pointer-events:none;border:0;padding:0;margin:0;}
.hl-compact-date{cursor:pointer;display:inline-block;padding:3px 6px;border:1px dashed #bbb;border-radius:3px;font-family:monospace;font-size:12px;white-space:nowrap;background:#fff;}
.hl-compact-date:hover{background:#eef5fb;border-color:#72aee6;}

/* Event list: narrower Type dropdown when abbreviations are used. */
select.hl-type-compact{width:64px;padding-left:4px;padding-right:18px;}

/* Event list: Edit icon column — centered square icon-button using the
   native WordPress dashicons. Flex centering guarantees vertical alignment
   with the row's form-control content regardless of row height. */
.hl-edit-cell{text-align:center;vertical-align:middle;padding:4px 6px;width:32px;}
.hl-edit-icon{
	display:inline-flex;
	align-items:center;
	justify-content:center;
	width:26px;
	height:26px;
	border-radius:4px;
	color:#2271b1;
	background:transparent;
	text-decoration:none;
	transition:background-color .15s ease, color .15s ease, box-shadow .15s ease;
	box-sizing:border-box;
}
.hl-edit-icon:hover, .hl-edit-icon:focus{
	background:#2271b1;
	color:#fff;
	box-shadow:0 0 0 1px #135e96;
	outline:none;
}
.hl-edit-icon .dashicons{
	font-size:16px;
	width:16px;
	height:16px;
	line-height:1;
}
</style>
