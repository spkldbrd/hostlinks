<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$table11 = $wpdb->prefix . 'event_instructor';

// ── Add new instructor ───────────────────────────────────────────────────────
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
		$sucessmsg = '<div class="updated below-h2" id="message"><p>Instructor successfully added. <a href="admin.php?page=istructor-menu">View Instructors</a></p></div>';
	}
	?>
<div class="wrap">
  <h2 id="add-new-user">Add New Instructor</h2>
  <?php echo $sucessmsg; ?>
  <form name="createdriver" method="post" action="" class="anewpostcode">
    <?php wp_nonce_field( 'hostlinks_add_instructor' ); ?>
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Name of the Instructor <span class="description">(required)</span></label></th>
        <td><input type="text" value="" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Add New Instructor" class="button button-primary" id="createdriveruser" name="createdriveruser">
    </p>
  </form>
</div>
	<?php

// ── Edit instructor ──────────────────────────────────────────────────────────
} elseif ( isset( $_GET['editu'] ) && $_GET['editu'] != '' ) {

	$userid       = intval( $_GET['editu'] );
	$sucessmsgnew = '';
	if ( isset( $_POST['updatethepcode'] ) ) {
		check_admin_referer( 'hostlinks_edit_instructor' );
		$postcodename  = sanitize_text_field( $_POST['first_name'] );
		$poststatus    = isset( $_POST['instructor_status'] ) ? 1 : 0;
		$wpdb->update(
			$table11,
			array(
				'event_instructor_name'   => $postcodename,
				'event_instructor_status' => $poststatus,
			),
			array( 'event_instructor_id' => $userid ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Instructor successfully updated. <a href="admin.php?page=istructor-menu">View Instructors</a></p></div>';
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
        <th><label for="first_name">Name of the Instructor <span class="description">(required)</span></label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->event_instructor_name ?? '' ); ?>" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)"></td>
      </tr>
      <tr class="form-field">
        <th><label for="instructor_status">Status</label></th>
        <td>
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="instructor_status" name="instructor_status" value="1"
              <?php checked( (int) ( $bokdetsx->event_instructor_status ?? 1 ), 1 ); ?>>
            Active &nbsp;<span class="description">(uncheck to make this instructor inactive — they will no longer appear in dropdowns but past events will still show their name)</span>
          </label>
        </td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Update Instructor" class="button button-primary" id="updatethepcode" name="updatethepcode">
    </p>
  </form>
</div>
	<?php

// ── Instructor list ──────────────────────────────────────────────────────────
} else {

	// Handle bulk actions
	if ( isset( $_POST['do_bulk'] ) ) {
		check_admin_referer( 'hostlinks_manage_instructors' );
		$uids   = isset( $_POST['users'] ) ? array_map( 'intval', (array) $_POST['users'] ) : array();
		$action = sanitize_key( $_POST['actiondelete'] ?? '' );

		if ( ! empty( $uids ) ) {
			if ( $action === 'deactivate' ) {
				foreach ( $uids as $uid ) {
					$wpdb->update( $table11, array( 'event_instructor_status' => 0 ), array( 'event_instructor_id' => $uid ), array( '%d' ), array( '%d' ) );
				}
				$bulk_msg = count( $uids ) . ' instructor(s) set to Inactive.';
			} elseif ( $action === 'activate' ) {
				foreach ( $uids as $uid ) {
					$wpdb->update( $table11, array( 'event_instructor_status' => 1 ), array( 'event_instructor_id' => $uid ), array( '%d' ), array( '%d' ) );
				}
				$bulk_msg = count( $uids ) . ' instructor(s) set to Active.';
			}
		}
	}

	// Handle row-level quick toggle
	if ( isset( $_POST['do_toggle'] ) ) {
		check_admin_referer( 'hostlinks_manage_instructors' );
		$toggle_uid    = intval( $_POST['toggle_uid'] ?? 0 );
		$toggle_status = intval( $_POST['toggle_status'] ?? 1 );
		if ( $toggle_uid > 0 && in_array( $toggle_status, array( 0, 1 ), true ) ) {
			$wpdb->update( $table11, array( 'event_instructor_status' => $toggle_status ), array( 'event_instructor_id' => $toggle_uid ), array( '%d' ), array( '%d' ) );
		}
	}

	$all_active   = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_instructor_status` = 1 ORDER BY event_instructor_name ASC", ARRAY_A );
	$all_inactive = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_instructor_status` = 0 ORDER BY event_instructor_name ASC", ARRAY_A );
	$tot_active   = count( $all_active );
	$tot_inactive = count( $all_inactive );
	?>
<div id="wpbody">
  <div tabindex="0" id="wpbody-content">
    <div class="wrap">
      <h2>Instructors <a class="add-new-h2" href="admin.php?page=istructor-menu&add=1">Add New Instructor</a></h2>

      <?php if ( ! empty( $bulk_msg ) ) : ?>
      <div class="updated below-h2" id="message"><p><?php echo esc_html( $bulk_msg ); ?></p></div>
      <?php endif; ?>

      <ul class="subsubsubx" style="margin-bottom:12px;">
        <li class="all"><a class="current">Active <span class="count">(<?php echo $tot_active; ?>)</span></a></li>
        <?php if ( $tot_inactive > 0 ) : ?>
        <li> | <a style="color:#a00;">Inactive <span class="count">(<?php echo $tot_inactive; ?>)</span></a></li>
        <?php endif; ?>
      </ul>

      <form method="post" action="" id="posts-filter">
        <?php wp_nonce_field( 'hostlinks_manage_instructors' ); ?>
        <div class="alignleft actions bulkactions" style="margin-bottom:8px;">
          <select name="actiondelete">
            <option value="-1">Bulk Actions</option>
            <option value="deactivate">Set Inactive</option>
            <option value="activate">Set Active</option>
          </select>
          <input type="submit" value="Apply" class="button action" name="do_bulk">
        </div>
        <br class="clear">

        <table class="wp-list-table widefat fixed users TFtable">
          <thead>
            <tr>
              <th class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>
              <th>Instructor Name</th>
              <th style="width:80px;">Status</th>
            </tr>
          </thead>
          <tbody id="the-list">
            <?php if ( empty( $all_active ) ) : ?>
            <tr><td colspan="3"><em>No active instructors.</em></td></tr>
            <?php endif; ?>
            <?php foreach ( $all_active as $inst ) : ?>
            <tr>
              <th class="check-column"><input type="checkbox" value="<?php echo esc_attr( $inst['event_instructor_id'] ); ?>" class="administrator" name="users[]"></th>
              <td>
                <strong><?php echo esc_html( $inst['event_instructor_name'] ); ?></strong>
                <div class="row-actions">
                  <span class="edit"><a href="admin.php?page=istructor-menu&editu=<?php echo esc_attr( $inst['event_instructor_id'] ); ?>">Edit</a></span>
                  &nbsp;|&nbsp;
                  <span class="deactivate">
                    <form method="post" action="" style="display:inline;">
                      <?php wp_nonce_field( 'hostlinks_manage_instructors' ); ?>
                      <input type="hidden" name="toggle_uid" value="<?php echo esc_attr( $inst['event_instructor_id'] ); ?>">
                      <input type="hidden" name="toggle_status" value="0">
                      <button type="submit" name="do_toggle" class="button-link" style="color:#a00;">Set Inactive</button>
                    </form>
                  </span>
                </div>
              </td>
              <td><span style="color:#1a8a1a;font-weight:600;">&#10003; Active</span></td>
            </tr>
            <?php endforeach; ?>

            <?php if ( ! empty( $all_inactive ) ) : ?>
            <tr>
              <td colspan="3" style="background:#f5f5f5;padding:6px 10px;font-weight:600;color:#666;border-top:2px solid #ddd;">
                Inactive Instructors &mdash; not shown in any dropdown; past event names are preserved
              </td>
            </tr>
            <?php foreach ( $all_inactive as $inst ) : ?>
            <tr style="opacity:0.65;">
              <th class="check-column"><input type="checkbox" value="<?php echo esc_attr( $inst['event_instructor_id'] ); ?>" class="administrator" name="users[]"></th>
              <td>
                <strong><?php echo esc_html( $inst['event_instructor_name'] ); ?></strong>
                <div class="row-actions">
                  <span class="edit"><a href="admin.php?page=istructor-menu&editu=<?php echo esc_attr( $inst['event_instructor_id'] ); ?>">Edit</a></span>
                  &nbsp;|&nbsp;
                  <span class="activate">
                    <form method="post" action="" style="display:inline;">
                      <?php wp_nonce_field( 'hostlinks_manage_instructors' ); ?>
                      <input type="hidden" name="toggle_uid" value="<?php echo esc_attr( $inst['event_instructor_id'] ); ?>">
                      <input type="hidden" name="toggle_status" value="1">
                      <button type="submit" name="do_toggle" class="button-link" style="color:#1a8a1a;">Reactivate</button>
                    </form>
                  </span>
                </div>
              </td>
              <td><span style="color:#a00;">&#8212; Inactive</span></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
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
document.addEventListener('DOMContentLoaded', function() {
  var cb = document.getElementById('cb-select-all-1');
  if ( cb ) {
    cb.addEventListener('change', function() {
      document.querySelectorAll('input[name="users[]"]').forEach(function(el) {
        el.checked = cb.checked;
      });
    });
  }
});
</script>
<style type="text/css">
th.manage-column{padding-bottom:0px!important;padding-top:10px!important;vertical-align:middle!important;}
.updpocode,.anewpostcode{background-color:#e0e0e0;padding:20px;width:49%;}
.TFtable tr:nth-child(odd){background:#f9f9f9;}
.TFtable tr:nth-child(even){background:#ededed;}
.TFtable .button-link{background:none;border:none;padding:0;cursor:pointer;font-size:13px;}
</style>
