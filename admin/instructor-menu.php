<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$table11 = $wpdb->prefix . 'event_instructor';

if ( isset( $_GET['add'] ) && $_GET['add'] == 1 ) {

	$sucessmsg = '';
	if ( isset( $_POST['createdriveruser'] ) ) {
		check_admin_referer( 'hostlinks_add_instructor' );
		$postcodename = sanitize_text_field( $_POST['first_name'] );
		$wpdb->insert(
			$table11,
			array( 'event_instructor_name' => $postcodename, 'event_instructor_status' => 1 ),
			array( '%s', '%d' )
		);
		$sucessmsg = '<div class="updated below-h2" id="message"><p>Instructor Sucessfully added. <a href="admin.php?page=istructor-menu">View Instructor</a></p></div>';
	}
	?>
<div class="wrap">
  <h2 id="add-new-user">Add New Instructor</h2>
  <?php echo $sucessmsg; ?>
  <form name="createdriver" method="post" action="" class="anewpostcode">
    <?php wp_nonce_field( 'hostlinks_add_instructor' ); ?>
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name Of the Instructor <span class="description">(required)</span></label></th>
        <td><input type="text" value="" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Add New Instructor" class="button button-primary" id="createdriveruser" name="createdriveruser">
    </p>
  </form>
</div>
	<?php

} elseif ( isset( $_GET['editu'] ) && $_GET['editu'] != '' ) {

	$userid       = intval( $_GET['editu'] );
	$sucessmsgnew = '';
	if ( isset( $_POST['updatethepcode'] ) ) {
		check_admin_referer( 'hostlinks_edit_instructor' );
		$postcodename = sanitize_text_field( $_POST['first_name'] );
		$wpdb->update(
			$table11,
			array( 'event_instructor_name' => $postcodename ),
			array( 'event_instructor_id'   => $userid ),
			array( '%s' ),
			array( '%d' )
		);
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Instructor Sucessfully Updated. <a href="admin.php?page=istructor-menu">View Instructor</a></p></div>';
	}
	$bokdetsx = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table11 WHERE `event_instructor_id` = %d", $userid ) );
	?>
<div class="wrap">
  <h2 id="add-new-user">Update Instructor</h2>
  <?php echo $sucessmsgnew; ?>
  <form name="createdriver" method="post" action="" class="updpocode">
    <?php wp_nonce_field( 'hostlinks_edit_instructor' ); ?>
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name Of the Instructor <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->event_instructor_name ?? '' ); ?>" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Update Instructor" class="button button-primary" id="updatethepcode" name="updatethepcode">
    </p>
  </form>
</div>
	<?php

} else {

	if ( isset( $_POST['deleteentire'] ) ) {
		check_admin_referer( 'hostlinks_manage_instructors' );
		$users = isset( $_POST['users'] ) ? (array) $_POST['users'] : array();
		foreach ( $users as $user ) {
			$wpdb->update( $table11, array( 'event_instructor_status' => 2 ), array( 'event_instructor_id' => intval( $user ) ), array( '%d' ), array( '%d' ) );
		}
	}
	$all_pending_bookings = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_instructor_status`='1'", ARRAY_A );
	$tot1                 = count( $all_pending_bookings );
	?>
<div id="wpbody">
  <div tabindex="0" id="wpbody-content">
    <div class="wrap">
      <h2>Instructors <a class="add-new-h2" href="admin.php?page=istructor-menu&add=1">Add New Instructor</a></h2>
      <ul class="subsubsubx">
        <li class="all"><a class="current">All <span class="count">(<?php echo $tot1; ?>)</span></a> |</li>
      </ul>
      <form method="post" action="" id="posts-filter">
        <?php wp_nonce_field( 'hostlinks_manage_instructors' ); ?>
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
              <th>Instructor Name</th>
            </tr>
          </thead>
          <tbody id="the-list">
            <?php foreach ( $all_pending_bookings as $alldriver ) { ?>
            <tr class="alternate">
              <th class="check-column"><input type="checkbox" value="<?php echo esc_attr( $alldriver['event_instructor_id'] ); ?>" class="administrator" name="users[]"></th>
              <td><strong><?php echo esc_html( $alldriver['event_instructor_name'] ); ?></strong>
                <div class="row-actions"><span class="edit"><a href="admin.php?page=istructor-menu&editu=<?php echo esc_attr( $alldriver['event_instructor_id'] ); ?>">Edit</a></span></div>
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
