<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$table11 = $wpdb->prefix . 'event_type';

if ( isset( $_GET['add'] ) && $_GET['add'] == 1 ) {

	$sucessmsg = '';
	if ( isset( $_POST['createdriveruser'] ) ) {
		$postcodename = trim( $_POST['first_name'] );
		$lat_long     = trim( $_POST['lat_long'] );
		$wpdb->query( "INSERT INTO $table11 (`event_type_name`,`event_type_color`) VALUES ('$postcodename','$lat_long')" );
		$sucessmsg = '<div class="updated below-h2" id="message"><p>Type Sucessfully added. <a href="admin.php?page=types-menu">View Type</a></p></div>';
	}
	?>
<div class="wrap">
  <h2 id="add-new-user">Add New Type</h2>
  <?php echo $sucessmsg; ?>
  <form name="createdriver" method="post" action="" class="anewpostcode">
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name Of the Type <span class="description">(required)</span></label></th>
        <td><input type="text" value="" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
      <tr class="form-field">
        <th><label for="lat_long">Type Color <span class="description">(required)</span></label></th>
        <td>
          <select name="lat_long">
            <option value="">Please Choose</option>
            <?php
            $arclr = array( 'bg-primary' => 'bg-primary', 'bg-secondary' => 'bg-secondary', 'bg-success' => 'bg-success', 'bg-danger' => 'bg-danger', 'bg-warning' => 'bg-warning', 'bg-info' => 'bg-info', 'bg-dark' => 'bg-dark' );
            foreach ( $arclr as $key => $value ) { ?>
              <option value="<?php echo $value; ?>"><?php echo $key; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Add New Type" class="button button-primary" id="createdriveruser" name="createdriveruser">
    </p>
  </form>
</div>
	<?php

} elseif ( isset( $_GET['editu'] ) && $_GET['editu'] != '' ) {

	$userid       = intval( $_GET['editu'] );
	$sucessmsgnew = '';
	if ( isset( $_POST['updatethepcode'] ) ) {
		$postcodename = trim( $_POST['first_name'] );
		$lat_long     = trim( $_POST['lat_long'] );
		$wpdb->query( "UPDATE $table11 SET `event_type_name`='$postcodename',`event_type_color`='$lat_long' WHERE `event_type_id`=$userid" );
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Type Sucessfully Updated. <a href="admin.php?page=types-menu">View Type</a></p></div>';
	}
	$bokdetsx = $wpdb->get_row( "SELECT * FROM $table11 WHERE `event_type_id`=$userid" );
	?>
<div class="wrap">
  <h2 id="add-new-user">Update Type</h2>
  <?php echo $sucessmsgnew; ?>
  <form name="createdriver" method="post" action="" class="updpocode">
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name Of the Type <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->event_type_name ?? '' ); ?>" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
      <tr class="form-field">
        <th><label for="lat_long">Type Color <span class="description">(required)</span></label></th>
        <td>
          <select name="lat_long">
            <option value="">Please Choose</option>
            <?php
            $arclr = array( 'bg-primary' => 'bg-primary', 'bg-secondary' => 'bg-secondary', 'bg-success' => 'bg-success', 'bg-danger' => 'bg-danger', 'bg-warning' => 'bg-warning', 'bg-info' => 'bg-info', 'bg-dark' => 'bg-dark' );
            foreach ( $arclr as $key => $value ) { ?>
              <option value="<?php echo $value; ?>" <?php if ( $bokdetsx->event_type_color == $value ) echo 'selected'; ?>><?php echo $key; ?></option>
            <?php } ?>
          </select>
        </td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Update Type" class="button button-primary" id="updatethepcode" name="updatethepcode">
    </p>
  </form>
</div>
	<?php

} else {

	if ( isset( $_POST['deleteentire'] ) ) {
		$users = $_POST['users'];
		if ( count( $users ) > 0 ) {
			foreach ( $users as $user ) {
				$wpdb->query( "UPDATE $table11 SET `event_type_status` = '2' WHERE `event_type_id`=" . intval( $user ) );
			}
		}
	}
	$all_pending_bookings = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_type_status`='1'", ARRAY_A );
	$tot1                 = $wpdb->num_rows;
	?>
<div id="wpbody">
  <div tabindex="0" id="wpbody-content" class="ddddd">
    <div class="wrap">
      <h2>Types <a class="add-new-h2" href="admin.php?page=types-menu&add=1">Add New Type</a></h2>
      <ul class="subsubsubx">
        <li class="all"><a class="current">All <span class="count">(<?php echo $tot1; ?>)</span></a> |</li>
      </ul>
      <form method="post" action="" id="posts-filter">
        <div class="alignleft actions bulkactions">
          <select id="bulk-action-selector-top" name="actiondelete">
            <option selected value="-1">Bulk Actions</option>
            <option value="delete">Delete</option>
          </select>
          <input type="submit" value="Apply" class="button action" id="doaction" name="deleteentire">
        </div>
        <br class="clear">
        <table class="wp-list-table widefat fixed users TFtable">
          <thead>
            <tr>
              <th class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>
              <th>Type Name</th>
              <th>Type Color</th>
            </tr>
          </thead>
          <tbody id="the-list">
            <?php foreach ( $all_pending_bookings as $alldriver ) { ?>
            <tr class="alternate">
              <th class="check-column"><input type="checkbox" value="<?php echo $alldriver['event_type_id']; ?>" class="administrator" name="users[]"></th>
              <td><strong><?php echo $alldriver['event_type_name']; ?></strong>
                <div class="row-actions"><span class="edit"><a href="admin.php?page=types-menu&editu=<?php echo $alldriver['event_type_id']; ?>">Edit</a></span></div>
              </td>
              <td><div class="struter <?php echo $alldriver['event_type_color']; ?>" style="width:50px;">&nbsp;</div></td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </form>
    </div>
  </div>
</div>
	<?php
}
?>
<script type="text/javascript">
function alphabetssonly(e) {
  var unicode = e.charCode ? e.charCode : e.keyCode;
  if (unicode != 32) {
    if (unicode != 8) {
      if ((unicode >= 65 && unicode <= 90) || (unicode >= 97 && unicode <= 122)) return true;
      else return false;
    }
  }
}
</script>
<style type="text/css">
th.manage-column{padding-bottom:0px!important;padding-top:10px!important;vertical-align:middle!important;}
.updpocode,.anewpostcode{background-color:#e0e0e0;padding:20px;width:49%;}
.TFtable tr:nth-child(odd){background:#f9f9f9;}
.TFtable tr:nth-child(even){background:#ededed;}
</style>


