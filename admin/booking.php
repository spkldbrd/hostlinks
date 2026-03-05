<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$timezone = new DateTimeZone( 'America/Los_Angeles' );
$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

if ( isset( $_GET['add'] ) && $_GET['add'] == 1 ) {

	$sucessmsg = '';
	if ( isset( $_POST['createdriveruser'] ) ) {
		$eve_location    = trim( $_POST['eve_location'] );
		$eve_paid        = trim( $_POST['eve_paid'] );
		$eve_free        = trim( $_POST['eve_free'] );
		$eve_tot_date    = trim( $_POST['evedate'] );
		$evedatearray    = explode( '-', $eve_tot_date );
		$eve_start       = date( 'Y-m-d', strtotime( trim( $evedatearray[0] ) ) );
		$eve_end         = date( 'Y-m-d', strtotime( trim( $evedatearray[1] ) ) );
		$eve_type        = trim( $_POST['eve_type'] );
		$eve_zoom        = trim( $_POST['eve_zoom'] );
		$eve_marketer    = trim( $_POST['eve_marketer'] );
		$eve_host_url    = trim( $_POST['eve_host_url'] );
		$eve_roster_url  = trim( $_POST['eve_roster_url'] );
		$eve_trainner_url = trim( $_POST['eve_trainner_url'] );
		$eve_sign_in_url = trim( $_POST['eve_sign_in_url'] );
		$eve_instructor  = trim( $_POST['eve_instructor'] );
		update_option( 'last_data_updation', wp_date( 'Y-m-d', null, $timezone ) );
		$wpdb->query( "INSERT INTO $table11 (`eve_location`,`eve_paid`,`eve_free`,`eve_start`,`eve_end`,`eve_type`,`eve_zoom`,`eve_marketer`,`eve_host_url`,`eve_roster_url`,`eve_trainner_url`,`eve_sign_in_url`,`eve_instructor`,`eve_tot_date`,`eve_status`)
			VALUES ('$eve_location','$eve_paid','$eve_free','$eve_start','$eve_end','$eve_type','$eve_zoom','$eve_marketer','$eve_host_url','$eve_roster_url','$eve_trainner_url','$eve_sign_in_url','$eve_instructor','$eve_tot_date','1')" );
		$sucessmsg = '<div class="updated below-h2" id="message"><p>Event Sucessfully added. <a href="admin.php?page=booking-menu">View Event</a></p></div>';
	}
	?>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
<div class="wrap">
  <h2 id="add-new-user">Add New Event</h2>
  <div id="ajax-response"></div>
  <p>Add new Event to this site.</p>
  <?php echo $sucessmsg; ?>
  <form name="createdriver" method="post" action="" class="anewpostcode">
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="eve_location">Location <span class="description">(required)</span></label></th>
        <td><input type="text" value="" id="eve_location" name="eve_location" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_paid">Paid <span class="description">(required)</span></label></th>
        <td><input type="number" value="0" id="eve_paid" name="eve_paid" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_free">Free <span class="description">(required)</span></label></th>
        <td><input type="number" value="0" id="eve_free" name="eve_free" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="evedate">Event Date <span class="description">(required)</span></label></th>
        <td><input type="text" name="evedate" class="sentinal inputfilder" value="" id="eventenddertot" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_type">Type <span class="description">(required)</span></label></th>
        <td>
          <select name="eve_type" id="eve_type" class="evetype" required>
            <option value="">Please Choose</option>
            <?php
            $all_pending_toter = $wpdb->get_results( "SELECT * FROM $table12 WHERE `event_type_status` = '1'", ARRAY_A );
            foreach ( $all_pending_toter as $alldriverx1 ) { ?>
              <option value="<?php echo $alldriverx1['event_type_id']; ?>"><?php echo $alldriverx1['event_type_name']; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_zoom">Zoom</label></th>
        <td><input type="checkbox" value="yes" id="eve_zoom" name="eve_zoom"></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_marketer">Marketer <span class="description">(required)</span></label></th>
        <td>
          <select name="eve_marketer" id="eve_marketer" class="evetype" required>
            <option value="">Please Choose</option>
            <?php
            $all_pending_toterx = $wpdb->get_results( "SELECT * FROM $table13 WHERE `event_marketer_status` = '1'", ARRAY_A );
            foreach ( $all_pending_toterx as $alldriverx2 ) { ?>
              <option value="<?php echo $alldriverx2['event_marketer_id']; ?>"><?php echo $alldriverx2['event_marketer_name']; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_host_url">HOST URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="#" id="eve_host_url" name="eve_host_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_roster_url">ROSTER URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="#" id="eve_roster_url" name="eve_roster_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_trainner_url">TRAINER URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="#" id="eve_trainner_url" name="eve_trainner_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_sign_in_url">Sign In URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="#" id="eve_sign_in_url" name="eve_sign_in_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_instructor">Instructor <span class="description">(required)</span></label></th>
        <td>
          <select name="eve_instructor" id="eve_instructor" class="evetype" required>
            <option value="">Please Choose</option>
            <?php
            $all_pending_toterxx = $wpdb->get_results( "SELECT * FROM $table14 WHERE `event_instructor_status` = '1'", ARRAY_A );
            foreach ( $all_pending_toterxx as $alldriverx3 ) { ?>
              <option value="<?php echo $alldriverx3['event_instructor_id']; ?>"><?php echo $alldriverx3['event_instructor_name']; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Add New Event" class="button button-primary" id="createdriveruser" name="createdriveruser">
    </p>
  </form>
</div>
<link rel="stylesheet" type="text/css" href="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/css/daterangepicker.css"/>
<script type="text/javascript" src="https://cdn.jsdelivr.net/jquery/latest/jquery.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.1/moment.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/js/daterangepicker.js"></script>
<script type="text/javascript">
jQuery(function() {
  jQuery('input[name="evedate"]').daterangepicker({
    timePicker: false,
    startDate: moment().startOf('hour'),
    endDate: moment().startOf('hour').add(32, 'hour'),
    locale: { format: 'YYYY/MM/DD' }
  });
});
</script>
	<?php

} elseif ( isset( $_GET['editu'] ) && $_GET['editu'] != '' ) {

	$userid = intval( $_GET['editu'] );
	$sucessmsgnew = '';
	if ( isset( $_POST['updatethepcode'] ) ) {
		$eve_location     = trim( $_POST['eve_location'] );
		$eve_paid         = trim( $_POST['eve_paid'] );
		$eve_free         = trim( $_POST['eve_free'] );
		$eve_tot_date     = trim( $_POST['evedate'] );
		$evedatearray     = explode( '-', $eve_tot_date );
		$eve_start        = date( 'Y-m-d H:i:s', strtotime( trim( $evedatearray[0] ) ) );
		$eve_end          = date( 'Y-m-d H:i:s', strtotime( trim( $evedatearray[1] ) ) );
		$eve_type         = trim( $_POST['eve_type'] );
		$eve_zoom         = trim( $_POST['eve_zoom'] );
		$eve_marketer     = trim( $_POST['eve_marketer'] );
		$eve_host_url     = trim( $_POST['eve_host_url'] );
		$eve_roster_url   = trim( $_POST['eve_roster_url'] );
		$eve_trainner_url = trim( $_POST['eve_trainner_url'] );
		$eve_sign_in_url  = trim( $_POST['eve_sign_in_url'] );
		$eve_instructor   = trim( $_POST['eve_instructor'] );
		update_option( 'last_data_updation', wp_date( 'Y-m-d', null, $timezone ) );
		$wpdb->query( "UPDATE $table11 SET `eve_location`='$eve_location',`eve_paid`='$eve_paid',`eve_free`='$eve_free',`eve_start`='$eve_start',`eve_end`='$eve_end',`eve_type`='$eve_type',`eve_zoom`='$eve_zoom',
			`eve_marketer`='$eve_marketer',`eve_host_url`='$eve_host_url',`eve_roster_url`='$eve_roster_url',`eve_trainner_url`='$eve_trainner_url',`eve_sign_in_url`='$eve_sign_in_url',
			`eve_instructor`='$eve_instructor',`eve_tot_date`='$eve_tot_date' WHERE `eve_id`=$userid" );
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Event Sucessfully Updated. <a href="admin.php?page=booking-menu">View Event</a></p></div>';
	}
	$bokdetsx = $wpdb->get_row( "SELECT * FROM $table11 WHERE `eve_id` = $userid" );
	?>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
<div class="wrap">
  <h2 id="add-new-user">Update Event</h2>
  <?php echo $sucessmsgnew; ?>
  <form name="createdriver" method="post" action="" class="updpocode">
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="eve_location">Location <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->eve_location ); ?>" id="eve_location" name="eve_location" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_paid">Paid <span class="description">(required)</span></label></th>
        <td><input type="number" value="<?php echo esc_attr( $bokdetsx->eve_paid ); ?>" id="eve_paid" name="eve_paid" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_free">Free <span class="description">(required)</span></label></th>
        <td><input type="number" value="<?php echo esc_attr( $bokdetsx->eve_free ); ?>" id="eve_free" name="eve_free" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="evedate">Eve Date <span class="description">(required)</span></label></th>
        <td><input type="text" name="evedate" class="sentinal inputfilder" value="<?php echo esc_attr( $bokdetsx->eve_tot_date ); ?>" id="eventenddertot" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_type">Type <span class="description">(required)</span></label></th>
        <td>
          <select name="eve_type" id="eve_type" class="evetype" required>
            <option value="">Please Choose</option>
            <?php
            $all_pending_toter = $wpdb->get_results( "SELECT * FROM $table12 WHERE `event_type_status` = '1'", ARRAY_A );
            foreach ( $all_pending_toter as $alldriverx1 ) { ?>
              <option value="<?php echo $alldriverx1['event_type_id']; ?>" <?php if ( $bokdetsx->eve_type == $alldriverx1['event_type_id'] ) echo 'selected'; ?>><?php echo $alldriverx1['event_type_name']; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_zoom">Zoom</label></th>
        <td><input type="checkbox" value="yes" id="eve_zoom" name="eve_zoom" <?php if ( $bokdetsx->eve_zoom == 'yes' ) echo 'checked'; ?>></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_marketer">Marketer <span class="description">(required)</span></label></th>
        <td>
          <select name="eve_marketer" id="eve_marketer" class="evetype" required>
            <option value="">Please Choose</option>
            <?php
            $all_pending_toterx = $wpdb->get_results( "SELECT * FROM $table13 WHERE `event_marketer_status` = '1'", ARRAY_A );
            foreach ( $all_pending_toterx as $alldriverx2 ) { ?>
              <option value="<?php echo $alldriverx2['event_marketer_id']; ?>" <?php if ( $bokdetsx->eve_marketer == $alldriverx2['event_marketer_id'] ) echo 'selected'; ?>><?php echo $alldriverx2['event_marketer_name']; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_host_url">HOST URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->eve_host_url ); ?>" id="eve_host_url" name="eve_host_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_roster_url">ROSTER URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->eve_roster_url ); ?>" id="eve_roster_url" name="eve_roster_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_trainner_url">TRAINER URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->eve_trainner_url ); ?>" id="eve_trainner_url" name="eve_trainner_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_sign_in_url">Sign In URL <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->eve_sign_in_url ); ?>" id="eve_sign_in_url" name="eve_sign_in_url" required></td>
      </tr>
      <tr class="form-field">
        <th><label for="eve_instructor">Instructor <span class="description">(required)</span></label></th>
        <td>
          <select name="eve_instructor" id="eve_instructor" class="evetype" required>
            <option value="">Please Choose</option>
            <?php
            $all_pending_toterxx = $wpdb->get_results( "SELECT * FROM $table14 WHERE `event_instructor_status` = '1'", ARRAY_A );
            foreach ( $all_pending_toterxx as $alldriverx3 ) { ?>
              <option value="<?php echo $alldriverx3['event_instructor_id']; ?>" <?php if ( $bokdetsx->eve_instructor == $alldriverx3['event_instructor_id'] ) echo 'selected'; ?>><?php echo $alldriverx3['event_instructor_name']; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Update Event" class="button button-primary" id="updatethepcode" name="updatethepcode">
    </p>
  </form>
</div>
<link rel="stylesheet" type="text/css" href="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/css/daterangepicker.css"/>
<script type="text/javascript" src="https://cdn.jsdelivr.net/jquery/latest/jquery.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.1/moment.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/js/daterangepicker.js"></script>
<script type="text/javascript">
jQuery(function() {
  jQuery('input[name="evedate"]').daterangepicker({
    timePicker: false,
    startDate: moment().startOf('hour'),
    endDate: moment().startOf('hour').add(32, 'hour'),
    locale: { format: 'YYYY/MM/DD' }
  });
});
</script>
	<?php

} else {

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
		if ( $_POST['actiondelete'] === 'delete' ) {
			$users = isset( $_POST['users'] ) ? (array) $_POST['users'] : array();
			foreach ( $users as $user ) {
				$wpdb->query( "UPDATE $table11 SET `eve_status` = '2' WHERE `eve_id` = " . intval( $user ) );
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
						$eve_location     = trim( $_POST['eve_location'][ $key ] );
						$eve_paid         = trim( $_POST['eve_paid'][ $key ] );
						$eve_free         = trim( $_POST['eve_free'][ $key ] );
						$eve_tot_date     = trim( $_POST['evedate'][ $key ] );
						$evedatearray     = explode( '-', $eve_tot_date );
						$eve_start        = date( 'Y-m-d', strtotime( trim( $evedatearray[0] ) ) );
						$eve_end          = date( 'Y-m-d', strtotime( trim( $evedatearray[1] ) ) );
						$eve_type         = trim( $_POST['eve_type'][ $key ] );
						$eve_zoom_array   = isset( $_POST['eve_zoom'] ) ? (array) $_POST['eve_zoom'] : array();
						$eve_zoom         = in_array( (string) $user, $eve_zoom_array ) ? 'yes' : '';
						$eve_marketer     = trim( $_POST['eve_marketer'][ $key ] );
						$eve_host_url     = trim( $_POST['eve_host_url'][ $key ] );
						$eve_roster_url   = trim( $_POST['eve_roster_url'][ $key ] );
						$eve_trainner_url = trim( $_POST['eve_trainner_url'][ $key ] );
						$eve_sign_in_url  = trim( $_POST['eve_sign_in_url'][ $key ] );
						$eve_instructor   = trim( $_POST['eve_instructor'][ $key ] );
						$wpdb->query( $wpdb->prepare(
							"UPDATE $table11 SET
								`eve_location`=%s, `eve_paid`=%d, `eve_free`=%d,
								`eve_start`=%s, `eve_end`=%s, `eve_type`=%d,
								`eve_zoom`=%s, `eve_marketer`=%d,
								`eve_host_url`=%s, `eve_roster_url`=%s,
								`eve_trainner_url`=%s, `eve_sign_in_url`=%s,
								`eve_instructor`=%d, `eve_tot_date`=%s
							WHERE `eve_id`=%d",
							$eve_location, $eve_paid, $eve_free,
							$eve_start, $eve_end, $eve_type,
							$eve_zoom, $eve_marketer,
							$eve_host_url, $eve_roster_url,
							$eve_trainner_url, $eve_sign_in_url,
							$eve_instructor, $eve_tot_date,
							$userid
						) );
					}
				}
				update_option( 'last_data_updation', wp_date( 'Y-m-d', null, $timezone ) );
				$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Event(s) successfully updated.</p></div>';
			}
		}
	}

	// ── Query events for the active filter ───────────────────────────────────
	$all_pending_bookings = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table11 WHERE `eve_status` = '1' AND `eve_start` LIKE %s ORDER BY `eve_start` ASC",
			$syear . '%'
		),
		ARRAY_A
	);
	$resulttotapplijobscnt = $wpdb->num_rows;
	$tot1                  = $resulttotapplijobscnt;
	?>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
<div id="wpbody">
<?php echo $sucessmsgnew; ?>
<div tabindex="0" id="wpbody-content" class="ddddd">
  <div class="wrap">
    <h2>Events <a class="add-new-h2" href="admin.php?page=booking-menu&add=1">Add New Event</a></h2>
    <ul class="subsubsubx">
      <li class="all"><a class="current">Showing <?php echo $tot1; ?> event(s) for <?php echo esc_html( date( 'F Y', mktime( 0, 0, 0, $filterMonth, 1, $filterYear ) ) ); ?></a></li>
    </ul>

    <?php /* ── Year / Month filter — uses JS redirect so it works inside a WP admin page callback ── */ ?>
    <div class="tablenav-pages" style="margin-bottom:10px;">
      <table border="0" cellspacing="0" cellpadding="0" class="listtable" width="100%"><tr>
        <td align="left">
          <select id="hl-chooseyear">
            <?php
            $yearStart = $currentYear - 4;
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
          <button type="button" id="hl-apply-filter" class="button action">Apply Filter</button>
          <a href="admin.php?page=booking-menu" class="button" style="margin-left:6px;">Reset</a>
        </td>
      </tr></table>
    </div>
    <script>
    document.getElementById('hl-apply-filter').addEventListener('click', function() {
        var yr  = document.getElementById('hl-chooseyear').value;
        var mo  = document.getElementById('hl-choosemonth').value;
        window.location.href = 'admin.php?page=booking-menu&syear=' + yr + '-' + mo;
    });
    </script>

    <form method="post" action="admin.php?page=booking-menu&syear=<?php echo esc_attr( $syear ); ?>" id="posts-filter">
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
            <th>HOST URL</th><th>ROSTER URL</th><th>TRAINER URL</th><th>Sign In URL</th><th>Instructor</th>
          </tr>
        </thead>
        <tbody id="the-list">
          <?php
          if ( $resulttotapplijobscnt > 0 ) {
              foreach ( $all_pending_bookings as $alldriver ) {
                  $all_pending_sex1 = $wpdb->get_row( "SELECT * FROM $table12 WHERE `event_type_id` = " . $alldriver['eve_type'], ARRAY_A );
                  $all_pending_sex2 = $wpdb->get_row( "SELECT * FROM $table13 WHERE `event_marketer_id` = " . $alldriver['eve_marketer'], ARRAY_A );
                  $all_pending_sex3 = $wpdb->get_row( "SELECT * FROM $table14 WHERE `event_instructor_id` = " . $alldriver['eve_instructor'], ARRAY_A );
                  $all_pending_toter   = $wpdb->get_results( "SELECT * FROM $table12 WHERE `event_type_status` = '1'", ARRAY_A );
                  $all_pending_toterx  = $wpdb->get_results( "SELECT * FROM $table13 WHERE `event_marketer_status` = '1'", ARRAY_A );
                  $all_pending_toterxx = $wpdb->get_results( "SELECT * FROM $table14 WHERE `event_instructor_status` = '1'", ARRAY_A );
                  ?>
          <tr class="alternate" id="user-<?php echo $alldriver['eve_id']; ?>">
            <th class="check-column"><input type="checkbox" value="<?php echo $alldriver['eve_id']; ?>" class="administrator splchkkr" name="users[]"></th>
            <input type="hidden" name="originalid[]" value="<?php echo $alldriver['eve_id']; ?>">
            <td><p class="hidder"><?php echo $alldriver['eve_location']; ?></p>
              <input type="text" value="<?php echo esc_attr( $alldriver['eve_location'] ); ?>" name="eve_location[]" required></td>
            <td><p class="hidder"><?php echo $alldriver['eve_paid']; ?></p>
              <input type="number" value="<?php echo esc_attr( $alldriver['eve_paid'] ); ?>" name="eve_paid[]" required style="width:50px;"></td>
            <td><p class="hidder"><?php echo $alldriver['eve_free']; ?></p>
              <input type="number" value="<?php echo esc_attr( $alldriver['eve_free'] ); ?>" name="eve_free[]" required style="width:50px;"></td>
            <td><p class="hidder"><?php echo $alldriver['eve_start']; ?></p>
              <input type="text" name="evedate[]" class="sentinal inputfilder eventenddertot" id="eventenddertot<?php echo $alldriver['eve_id']; ?>" value="<?php echo esc_attr( $alldriver['eve_tot_date'] ); ?>" required></td>
            <td>
              <select name="eve_type[]" class="evetype" required style="width:100px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toter as $alldriverx1 ) { ?>
                  <option value="<?php echo $alldriverx1['event_type_id']; ?>" <?php if ( $alldriverx1['event_type_name'] == $all_pending_sex1['event_type_name'] ) echo 'selected'; ?>><?php echo $alldriverx1['event_type_name']; ?></option>
                <?php } ?>
              </select>
              <?php foreach ( $all_pending_toter as $alldriverx1 ) { if ( $alldriverx1['event_type_name'] == $all_pending_sex1['event_type_name'] ) { ?><p class="hidder"><?php echo $alldriverx1['event_type_name']; ?></p><?php } } ?>
            </td>
            <td><input type="checkbox" class="splchkkr" value="<?php echo $alldriver['eve_id']; ?>" name="eve_zoom[]" <?php if ( $alldriver['eve_zoom'] == 'yes' ) echo 'checked'; ?>></td>
            <td>
              <select name="eve_marketer[]" class="evetype" required style="width:100px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toterx as $alldriverx2 ) { ?>
                  <option value="<?php echo $alldriverx2['event_marketer_id']; ?>" <?php if ( $alldriver['eve_marketer'] == $alldriverx2['event_marketer_id'] ) echo 'selected'; ?>><?php echo $alldriverx2['event_marketer_name']; ?></option>
                <?php } ?>
              </select>
              <?php foreach ( $all_pending_toterx as $alldriverx2 ) { if ( $alldriver['eve_marketer'] == $alldriverx2['event_marketer_id'] ) { ?><p class="hidder"><?php echo $alldriverx2['event_marketer_name']; ?></p><?php } } ?>
            </td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_host_url'] ); ?>" name="eve_host_url[]" required style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_roster_url'] ); ?>" name="eve_roster_url[]" required style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_trainner_url'] ); ?>" name="eve_trainner_url[]" required style="width:140px;"></td>
            <td><input type="text" value="<?php echo esc_attr( $alldriver['eve_sign_in_url'] ); ?>" name="eve_sign_in_url[]" required style="width:140px;"></td>
            <td>
              <select name="eve_instructor[]" class="evetype" required style="width:100px;">
                <option value="">Please Choose</option>
                <?php foreach ( $all_pending_toterxx as $alldriverx3 ) { ?>
                  <option value="<?php echo $alldriverx3['event_instructor_id']; ?>" <?php if ( $alldriver['eve_instructor'] == $alldriverx3['event_instructor_id'] ) echo 'selected'; ?>><?php echo $alldriverx3['event_instructor_name']; ?></option>
                <?php } ?>
              </select>
              <?php foreach ( $all_pending_toterxx as $alldriverx3 ) { if ( $alldriver['eve_instructor'] == $alldriverx3['event_instructor_id'] ) { ?><p class="hidder"><?php echo $alldriverx3['event_instructor_name']; ?></p><?php } } ?>
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
<link rel="stylesheet" type="text/css" href="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/css/daterangepicker.css"/>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.1/moment.min.js"></script>
<script src="<?php echo HOSTLINKS_PLUGIN_URL; ?>assets/js/daterangepicker.js"></script>
<script type="text/javascript">
jQuery(function() {
  jQuery('.eventenddertot').daterangepicker({
    timePicker: false,
    locale: { format: 'YYYY/MM/DD' }
  });
});
</script>
<script type="text/javascript">
jQuery(document).ready(function(){
  jQuery('#myTable').dataTable({
    "aoColumns": [null,null,null,null,{"sType":"date-uk"},null,null,null,null,null,null,null,null],
    "order": [[4,"asc"]],
    "bPaginate": true, "bLengthChange": true, "bFilter": true, "bSort": true,
    "bInfo": true, "bAutoWidth": true, "stateSave": true, "searching": true,
    "dom": 'lfrtip', "pageLength": 25, "lengthChange": true,
    "columnDefs": [{"targets":[0,2,3,5,6,7,8,9,10,11,12],"orderable":false}]
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
input[type="checkbox"]:checked::before,input[type="radio"]:checked::before{width:2rem!important;}
th.manage-column{padding-bottom:0px!important;padding-top:10px!important;vertical-align:middle!important;}
.updpocode,.anewpostcode{background-color:#e0e0e0;padding:20px;width:49%;}
.TFtable tr:nth-child(odd){background:#f9f9f9;}
.TFtable tr:nth-child(even){background:#ededed;}
</style>
	<?php
}


