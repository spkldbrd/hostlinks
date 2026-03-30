<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$table11 = $wpdb->prefix . 'event_marketer';

// ── Add new marketer ─────────────────────────────────────────────────────────
if ( isset( $_GET['add'] ) && $_GET['add'] == 1 ) {

	$sucessmsg = '';
	if ( isset( $_POST['createdriveruser'] ) ) {
		check_admin_referer( 'hostlinks_add_marketer' );
		$postcodename = sanitize_text_field( $_POST['first_name'] );
		$wpdb->insert(
			$table11,
			array(
				'event_marketer_name'   => $postcodename,
				'event_marketer_status' => 1,
				'marketer_full_name'    => sanitize_text_field( $_POST['marketer_full_name'] ?? '' ),
				'marketer_company'      => sanitize_text_field( $_POST['marketer_company']   ?? '' ),
				'marketer_phone'        => sanitize_text_field( $_POST['marketer_phone']     ?? '' ),
				'marketer_email'        => sanitize_email(      $_POST['marketer_email']     ?? '' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		$sucessmsg = '<div class="updated below-h2" id="message"><p>Marketer successfully added. <a href="admin.php?page=marketer-menu">View Marketers</a></p></div>';
	}
	?>
<div class="wrap">
  <h2 id="add-new-user">Add New Marketer</h2>
  <?php echo $sucessmsg; ?>
  <form name="createdriver" method="post" action="" class="anewpostcode">
    <?php wp_nonce_field( 'hostlinks_add_marketer' ); ?>
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Short Name <span class="description">(required)</span></label></th>
        <td>
          <input type="text" value="" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)">
          <p class="description">Single word used in dropdowns and displays (e.g. "Maddux").</p>
        </td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_full_name">Full Name</label></th>
        <td><input type="text" value="" id="marketer_full_name" name="marketer_full_name" style="width:260px;" placeholder="e.g. Maddux Ballenger"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_company">Company</label></th>
        <td><input type="text" value="" id="marketer_company" name="marketer_company" style="width:260px;" placeholder="e.g. Grant Writing USA"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_phone">Phone</label></th>
        <td><input type="text" value="" id="marketer_phone" name="marketer_phone" style="width:180px;" placeholder="e.g. 702.677.0402"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_email">Email</label></th>
        <td><input type="email" value="" id="marketer_email" name="marketer_email" style="width:260px;" placeholder="e.g. maddux@grantwritingusa.net"></td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Add New Marketer" class="button button-primary" id="createdriveruser" name="createdriveruser">
    </p>
  </form>
</div>
	<?php

// ── Edit marketer ────────────────────────────────────────────────────────────
} elseif ( isset( $_GET['editu'] ) && $_GET['editu'] != '' ) {

	$userid       = intval( $_GET['editu'] );
	$sucessmsgnew = '';
	if ( isset( $_POST['updatethepcode'] ) ) {
		check_admin_referer( 'hostlinks_edit_marketer' );
		$postcodename  = sanitize_text_field( $_POST['first_name'] );
		$poststatus    = isset( $_POST['marketer_status'] ) ? 1 : 0;
		$wpdb->update(
			$table11,
			array(
				'event_marketer_name'   => $postcodename,
				'event_marketer_status' => $poststatus,
				'marketer_full_name'    => sanitize_text_field( $_POST['marketer_full_name'] ?? '' ),
				'marketer_company'      => sanitize_text_field( $_POST['marketer_company']   ?? '' ),
				'marketer_phone'        => sanitize_text_field( $_POST['marketer_phone']     ?? '' ),
				'marketer_email'        => sanitize_email(      $_POST['marketer_email']     ?? '' ),
			),
			array( 'event_marketer_id' => $userid ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Marketer successfully updated. <a href="admin.php?page=marketer-menu">View Marketers</a></p></div>';
	}
	$bokdetsx = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table11 WHERE `event_marketer_id` = %d", $userid ) );
	?>
<div class="wrap">
  <h2 id="add-new-user">Update Marketer</h2>
  <?php echo $sucessmsgnew; ?>
  <form name="createdriver" method="post" action="" class="updpocode">
    <?php wp_nonce_field( 'hostlinks_edit_marketer' ); ?>
    <table class="form-table"><tbody>
      <tr class="form-field">
        <th><label for="first_name">Short Name <span class="description">(required)</span></label></th>
        <td>
          <input type="text" value="<?php echo esc_attr( $bokdetsx->event_marketer_name ?? '' ); ?>" id="first_name" name="first_name" required onkeypress="return alphabetssonly(event)">
          <p class="description">Single word used in dropdowns and displays.</p>
        </td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_full_name">Full Name</label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->marketer_full_name ?? '' ); ?>" id="marketer_full_name" name="marketer_full_name" style="width:260px;" placeholder="e.g. Maddux Ballenger"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_company">Company</label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->marketer_company ?? '' ); ?>" id="marketer_company" name="marketer_company" style="width:260px;" placeholder="e.g. Grant Writing USA"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_phone">Phone</label></th>
        <td><input type="text" value="<?php echo esc_attr( $bokdetsx->marketer_phone ?? '' ); ?>" id="marketer_phone" name="marketer_phone" style="width:180px;" placeholder="e.g. 702.677.0402"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_email">Email</label></th>
        <td><input type="email" value="<?php echo esc_attr( $bokdetsx->marketer_email ?? '' ); ?>" id="marketer_email" name="marketer_email" style="width:260px;" placeholder="e.g. maddux@grantwritingusa.net"></td>
      </tr>
      <tr class="form-field">
        <th><label for="marketer_status">Status</label></th>
        <td>
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="marketer_status" name="marketer_status" value="1"
              <?php checked( (int) ( $bokdetsx->event_marketer_status ?? 1 ), 1 ); ?>>
            Active &nbsp;<span class="description">(uncheck to make this marketer inactive — they will no longer appear in dropdowns but past events will still show their name)</span>
          </label>
        </td>
      </tr>
    </tbody></table>
    <p class="submit">
      <input type="submit" value="Update Marketer" class="button button-primary" id="updatethepcode" name="updatethepcode">
    </p>
  </form>
</div>
	<?php

// ── Marketer list ────────────────────────────────────────────────────────────
} else {

	// Handle bulk actions
	if ( isset( $_POST['do_bulk'] ) ) {
		check_admin_referer( 'hostlinks_manage_marketers' );
		$uids   = isset( $_POST['users'] ) ? array_map( 'intval', (array) $_POST['users'] ) : array();
		$action = sanitize_key( $_POST['actiondelete'] ?? '' );

		if ( ! empty( $uids ) ) {
			if ( $action === 'deactivate' ) {
				foreach ( $uids as $uid ) {
					$wpdb->update( $table11, array( 'event_marketer_status' => 0 ), array( 'event_marketer_id' => $uid ), array( '%d' ), array( '%d' ) );
				}
				$bulk_msg = count( $uids ) . ' marketer(s) set to Inactive.';
			} elseif ( $action === 'activate' ) {
				foreach ( $uids as $uid ) {
					$wpdb->update( $table11, array( 'event_marketer_status' => 1 ), array( 'event_marketer_id' => $uid ), array( '%d' ), array( '%d' ) );
				}
				$bulk_msg = count( $uids ) . ' marketer(s) set to Active.';
			}
		}
	}

	// Handle row-level quick toggle
	if ( isset( $_POST['do_toggle'] ) ) {
		check_admin_referer( 'hostlinks_manage_marketers' );
		$toggle_uid    = intval( $_POST['toggle_uid'] ?? 0 );
		$toggle_status = intval( $_POST['toggle_status'] ?? 1 );
		if ( $toggle_uid > 0 && in_array( $toggle_status, array( 0, 1 ), true ) ) {
			$wpdb->update( $table11, array( 'event_marketer_status' => $toggle_status ), array( 'event_marketer_id' => $toggle_uid ), array( '%d' ), array( '%d' ) );
		}
	}

	$all_active   = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_marketer_status` = 1 ORDER BY event_marketer_name ASC", ARRAY_A );
	$all_inactive = $wpdb->get_results( "SELECT * FROM $table11 WHERE `event_marketer_status` = 0 ORDER BY event_marketer_name ASC", ARRAY_A );
	$tot_active   = count( $all_active );
	$tot_inactive = count( $all_inactive );
	?>
<div id="wpbody">
  <div tabindex="0" id="wpbody-content">
    <div class="wrap">
      <h2>Marketers <a class="add-new-h2" href="admin.php?page=marketer-menu&add=1">Add New Marketer</a></h2>

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
        <?php wp_nonce_field( 'hostlinks_manage_marketers' ); ?>
        <div class="alignleft actions bulkactions" style="margin-bottom:8px;">
          <select name="actiondelete">
            <option value="-1">Bulk Actions</option>
            <option value="deactivate">Set Inactive</option>
            <option value="activate">Set Active</option>
          </select>
          <input type="submit" value="Apply" class="button action" name="do_bulk">
        </div>
        <br class="clear">

        <?php /* ── Active marketers ── */ ?>
        <table class="wp-list-table widefat fixed users TFtable">
          <thead>
            <tr>
              <th class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>
              <th>Marketer</th>
              <th>Contact Details</th>
              <th style="width:80px;">Status</th>
            </tr>
          </thead>
          <tbody id="the-list">
            <?php if ( empty( $all_active ) ) : ?>
            <tr><td colspan="3"><em>No active marketers.</em></td></tr>
            <?php endif; ?>
            <?php foreach ( $all_active as $mk ) : ?>
            <tr>
              <th class="check-column"><input type="checkbox" value="<?php echo esc_attr( $mk['event_marketer_id'] ); ?>" class="administrator" name="users[]"></th>
              <td>
                <strong><?php echo esc_html( $mk['event_marketer_name'] ); ?></strong>
                <div class="row-actions">
                  <span class="edit"><a href="admin.php?page=marketer-menu&editu=<?php echo esc_attr( $mk['event_marketer_id'] ); ?>">Edit</a></span>
                  &nbsp;|&nbsp;
                  <span class="deactivate">
                    <form method="post" action="" style="display:inline;">
                      <?php wp_nonce_field( 'hostlinks_manage_marketers' ); ?>
                      <input type="hidden" name="toggle_uid" value="<?php echo esc_attr( $mk['event_marketer_id'] ); ?>">
                      <input type="hidden" name="toggle_status" value="0">
                      <button type="submit" name="do_toggle" class="button-link" style="color:#a00;">Set Inactive</button>
                    </form>
                  </span>
                </div>
              </td>
              <td style="font-size:12px;color:#444;line-height:1.7;">
                <?php if ( ! empty( $mk['marketer_full_name'] ) ) echo esc_html( $mk['marketer_full_name'] ) . '<br>'; ?>
                <?php if ( ! empty( $mk['marketer_company'] ) )   echo esc_html( $mk['marketer_company'] )   . '<br>'; ?>
                <?php if ( ! empty( $mk['marketer_phone'] ) )     echo esc_html( $mk['marketer_phone'] )     . '<br>'; ?>
                <?php if ( ! empty( $mk['marketer_email'] ) )     echo '<a href="mailto:' . esc_attr( $mk['marketer_email'] ) . '">' . esc_html( $mk['marketer_email'] ) . '</a>'; ?>
              </td>
              <td><span style="color:#1a8a1a;font-weight:600;">&#10003; Active</span></td>
            </tr>
            <?php endforeach; ?>

            <?php if ( ! empty( $all_inactive ) ) : ?>
            <tr>
              <td colspan="4" style="background:#f5f5f5;padding:6px 10px;font-weight:600;color:#666;border-top:2px solid #ddd;">
                Inactive Marketers &mdash; not shown in any dropdown; past event names are preserved
              </td>
            </tr>
            <?php foreach ( $all_inactive as $mk ) : ?>
            <tr style="opacity:0.65;">
              <th class="check-column"><input type="checkbox" value="<?php echo esc_attr( $mk['event_marketer_id'] ); ?>" class="administrator" name="users[]"></th>
              <td>
                <strong><?php echo esc_html( $mk['event_marketer_name'] ); ?></strong>
                <div class="row-actions">
                  <span class="edit"><a href="admin.php?page=marketer-menu&editu=<?php echo esc_attr( $mk['event_marketer_id'] ); ?>">Edit</a></span>
                  &nbsp;|&nbsp;
                  <span class="activate">
                    <form method="post" action="" style="display:inline;">
                      <?php wp_nonce_field( 'hostlinks_manage_marketers' ); ?>
                      <input type="hidden" name="toggle_uid" value="<?php echo esc_attr( $mk['event_marketer_id'] ); ?>">
                      <input type="hidden" name="toggle_status" value="1">
                      <button type="submit" name="do_toggle" class="button-link" style="color:#1a8a1a;">Reactivate</button>
                    </form>
                  </span>
                </div>
              </td>
              <td style="font-size:12px;color:#444;line-height:1.7;">
                <?php if ( ! empty( $mk['marketer_full_name'] ) ) echo esc_html( $mk['marketer_full_name'] ) . '<br>'; ?>
                <?php if ( ! empty( $mk['marketer_company'] ) )   echo esc_html( $mk['marketer_company'] )   . '<br>'; ?>
                <?php if ( ! empty( $mk['marketer_phone'] ) )     echo esc_html( $mk['marketer_phone'] )     . '<br>'; ?>
                <?php if ( ! empty( $mk['marketer_email'] ) )     echo '<a href="mailto:' . esc_attr( $mk['marketer_email'] ) . '">' . esc_html( $mk['marketer_email'] ) . '</a>'; ?>
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
// Select-all checkbox
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
