<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$table11 = $wpdb->prefix . 'event_marketer';

if ( isset( $_GET['add'] ) && $_GET['add'] == 1 ) {

	$sucessmsg = '';
	if ( isset( $_POST['createdriveruser'] ) ) {
		$postcodename = trim( $_POST['first_name'] );
		$wpdb->query( "INSERT INTO $table11 (`event_marketer_name`) VALUES ('$postcodename')" );
		$sucessmsg = '<div class="updated below-h2" id="message"><p>Marketer Sucessfully added. <a href="admin.php?page=marketer-menu">View Marketer</a></p></div>';
	}
	?>
<div class="wrap">
  <h2 id="add-new-user">Add New Marketer</h2>
  <?php echo $sucessmsg; ?>
  <form name="createdriver" method="post" action="" class="anewpostcode">
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name Of the Marketer <span class="description">(required)</span></label></th>
        <td><input type="text" value="" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Add New Marketer" class="button button-primary" id="createdriveruser" name="createdriveruser">
    </p>
  </form>
</div>
	<?php

} elseif ( isset( $_GET['editu'] ) && $_GET['editu'] != '' ) {

	$userid       = intval( $_GET['editu'] );
	$sucessmsgnew = '';
	if ( isset( $_POST['updatethepcode'] ) ) {
		$postcodename = trim( $_POST['first_name'] );
		$wpdb->query( "UPDATE $table11 SET `event_marketer_name`='$postcodename' WHERE `event_marketer_id`=$userid" );
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Marketer Sucessfully Updated. <a href="admin.php?page=marketer-menu">View Marketer</a></p></div>';
	}
	$bokdetsx = $wpdb->get_row( "SELECT * FROM $table11 WHERE `event_marketer_id`=$userid" );
	?>
<div class="wrap">
  <h2 id="add-new-user">Update Marketer</h2>
  <?php echo $sucessmsgnew; ?>
  <form name="createdriver" method="post" action="" class="updpocode">
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name Of the Marketer <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->event_marketer_name ); ?>" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Update Marketer" class="button button-primary" id="updatethepcode" name="updatethepcode">
    </p>
  </form>
</div>
	<?php

} else {

	if ( isset( $_POST['deleteentire'] ) ) {
		$users = $_POST['users'];
		if ( count( $users ) > 0 ) {
			foreach ( $users as $user ) {
				$wpdb->query( "UPDATE $table11 SET `event_marketer_status` = '2' WHERE `event_marketer_id`=" . intval( $user ) );
			}
		}
	}
	$all_pending_bookings = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_marketer_status`='1'", ARRAY_A );
	$tot1                 = $wpdb->num_rows;
	?>
<div id="wpbody">
  <div tabindex="0" id="wpbody-content">
    <div class="wrap">
      <h2>Marketers <a class="add-new-h2" href="admin.php?page=marketer-menu&add=1">Add New Marketer</a></h2>
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
              <th>Marketer Name</th>
            </tr>
          </thead>
          <tbody id="the-list">
            <?php foreach ( $all_pending_bookings as $alldriver ) { ?>
            <tr class="alternate">
              <th class="check-column"><input type="checkbox" value="<?php echo $alldriver['event_marketer_id']; ?>" class="administrator" name="users[]"></th>
              <td><strong><?php echo $alldriver['event_marketer_name']; ?></strong>
                <div class="row-actions"><span class="edit"><a href="admin.php?page=marketer-menu&editu=<?php echo $alldriver['event_marketer_id']; ?>">Edit</a></span></div>
              </td>
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


