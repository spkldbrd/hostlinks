<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

// ── Save ──────────────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hl_save_request_settings'] ) ) {
	check_admin_referer( 'hl_event_request_settings' );

	$email       = sanitize_email( $_POST['hl_notification_email'] ?? '' );
	$prefix      = sanitize_text_field( $_POST['hl_subject_prefix'] ?? '[Event Request]' );
	$success     = sanitize_textarea_field( $_POST['hl_success_message'] ?? '' );
	$form_header = sanitize_text_field( $_POST['hl_form_header_text'] ?? '' );
	$add_btn     = sanitize_key( $_POST['hl_add_event_btn'] ?? 'disabled' );
	if ( ! in_array( $add_btn, array( 'disabled', 'admin', 'custom', 'all' ), true ) ) {
		$add_btn = 'disabled';
	}

	// Save the selected user IDs when mode is 'custom'.
	$custom_users = array();
	if ( $add_btn === 'custom' && ! empty( $_POST['hl_add_event_btn_users'] ) && is_array( $_POST['hl_add_event_btn_users'] ) ) {
		$custom_users = array_map( 'absint', $_POST['hl_add_event_btn_users'] );
		$custom_users = array_filter( $custom_users ); // remove zeros
	}

	update_option( 'hostlinks_event_request_notification_email', $email );
	update_option( 'hostlinks_event_request_email_subject_prefix', $prefix );
	update_option( 'hostlinks_event_request_success_message', $success );
	update_option( 'hostlinks_event_request_form_header', $form_header );
	update_option( 'hostlinks_add_event_btn', $add_btn );
	update_option( 'hostlinks_add_event_btn_users', $custom_users );

	$notice = '<div class="notice notice-success is-dismissible"><p>Event Request settings saved.</p></div>';
}

// ── Current values ────────────────────────────────────────────────────────────
$notif_email    = get_option( 'hostlinks_event_request_notification_email', get_option( 'admin_email' ) );
$subject_prefix = get_option( 'hostlinks_event_request_email_subject_prefix', '[Event Request]' );
$success_msg    = get_option( 'hostlinks_event_request_success_message', '' );
$form_header    = get_option( 'hostlinks_event_request_form_header', 'New Event Build Form' );
$add_btn        = get_option( 'hostlinks_add_event_btn', 'disabled' );
$custom_users   = get_option( 'hostlinks_add_event_btn_users', array() );
if ( ! is_array( $custom_users ) ) {
	$custom_users = array();
}

// Build user list for the picker (all WP users, any role).
$all_users = get_users( array(
	'fields'  => array( 'ID', 'display_name', 'user_email' ),
	'orderby' => 'display_name',
	'order'   => 'ASC',
	'number'  => 200,
) );
?>
<?php if ( empty( $hl_embedded ) ) : ?>
<div class="wrap">
<h1>Hostlinks — Event Request Settings</h1>
<?php endif; ?>
<?php echo $notice; ?>

<form method="post">
	<?php wp_nonce_field( 'hl_event_request_settings' ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hl_add_event_btn">"+ Event" Button</label></th>
			<td>
				<select id="hl_add_event_btn" name="hl_add_event_btn">
					<option value="disabled" <?php selected( $add_btn, 'disabled' ); ?>>Disabled</option>
					<option value="admin"    <?php selected( $add_btn, 'admin' ); ?>>Admin only</option>
					<option value="custom"   <?php selected( $add_btn, 'custom' ); ?>>Admin + selected users</option>
					<option value="all"      <?php selected( $add_btn, 'all' ); ?>>All Hostlinks users</option>
				</select>
				<p class="description">When enabled, a <strong>+ Event</strong> button appears to the right of the Reports button on the Upcoming Events calendar and links to the Event Request Form page.<br>
				<em>Admin only</em> = visible to <code>manage_options</code> users. <em>Admin + selected users</em> = admins plus any users you pick below. <em>All Hostlinks users</em> = visible to anyone with approved viewer access.<br>
				The destination page is auto-detected from the page containing <code>[hostlinks_event_request_form]</code> (configure under <strong>Settings → General → Page Link Settings</strong>).</p>
			</td>
		</tr>
		<tr id="hl_custom_users_row" style="<?php echo $add_btn === 'custom' ? '' : 'display:none;'; ?>">
			<th scope="row"><label for="hl_add_event_btn_users">Allowed Users</label></th>
			<td>
				<?php if ( $all_users ) : ?>
				<div style="position:relative;max-width:400px;">
					<input type="text" id="hl_user_search" placeholder="&#128269; Search users…"
						style="width:100%;margin-bottom:6px;padding:5px 8px;box-sizing:border-box;border:1px solid #8c8f94;border-radius:3px;" />
					<select id="hl_add_event_btn_users" name="hl_add_event_btn_users[]"
						multiple size="8"
						style="width:100%;font-size:13px;border:1px solid #8c8f94;border-radius:3px;">
						<?php foreach ( $all_users as $u ) : ?>
						<option value="<?php echo esc_attr( $u->ID ); ?>"
							<?php echo in_array( (int) $u->ID, array_map( 'intval', $custom_users ), true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<p class="description" style="max-width:400px;">
					Hold <kbd>Ctrl</kbd> (Windows) or <kbd>⌘ Cmd</kbd> (Mac) to select multiple users.<br>
					Admins (<code>manage_options</code>) always see the button regardless of this list.
				</p>
				<?php else : ?>
				<p class="description">No WordPress users found.</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl_form_header_text">Form Header Text</label></th>
			<td>
				<input type="text" id="hl_form_header_text" name="hl_form_header_text"
					value="<?php echo esc_attr( $form_header ); ?>"
					class="regular-text" placeholder="New Event Build Form" />
				<p class="description">Shown as the small heading at the top-left of the <code>[hostlinks_event_request_form]</code> page. Default: <em>New Event Build Form</em>.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl_notification_email">Notification Email</label></th>
			<td>
				<input type="email" id="hl_notification_email" name="hl_notification_email"
					value="<?php echo esc_attr( $notif_email ); ?>"
					class="regular-text" />
				<p class="description">New event request submissions will be sent to this address. Defaults to the site admin email.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl_subject_prefix">Email Subject Prefix</label></th>
			<td>
				<input type="text" id="hl_subject_prefix" name="hl_subject_prefix"
					value="<?php echo esc_attr( $subject_prefix ); ?>"
					class="regular-text" />
				<p class="description">Prepended to the event title in notification email subjects. Example: <code>[Event Request] My Event Title — #42</code></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl_success_message">Success Message</label></th>
			<td>
				<textarea id="hl_success_message" name="hl_success_message" rows="4"
					class="large-text"><?php echo esc_textarea( $success_msg ); ?></textarea>
				<p class="description">Shown to the visitor after a successful submission. Leave blank for the default message which includes the request ID.</p>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" name="hl_save_request_settings" class="button button-primary">Save Settings</button>
	</p>
</form>
<script>
(function () {
	var sel    = document.getElementById('hl_add_event_btn');
	var row    = document.getElementById('hl_custom_users_row');
	var search = document.getElementById('hl_user_search');
	var list   = document.getElementById('hl_add_event_btn_users');

	function toggleRow() {
		if ( row ) row.style.display = ( sel.value === 'custom' ) ? '' : 'none';
	}
	if ( sel ) sel.addEventListener('change', toggleRow);

	// Live search filter for the user list.
	if ( search && list ) {
		search.addEventListener('input', function () {
			var q = this.value.toLowerCase();
			Array.prototype.forEach.call(list.options, function (opt) {
				opt.style.display = opt.text.toLowerCase().indexOf(q) > -1 ? '' : 'none';
			});
		});
	}
})();
</script>

<hr />

<h2>Shortcode</h2>
<p>Place the following shortcode on any page to display the event request intake form:</p>
<p><code>[hostlinks_event_request_form]</code></p>
<p>The form saves submissions to a separate <strong>Event Requests</strong> table and does <em>not</em> publish events immediately. Review and manage submissions under <strong>Hostlinks &rarr; Event Requests</strong>.</p>
<?php if ( empty( $hl_embedded ) ) : ?></div><?php endif; ?>
