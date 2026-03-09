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

	$email   = sanitize_email( $_POST['hl_notification_email'] ?? '' );
	$prefix  = sanitize_text_field( $_POST['hl_subject_prefix'] ?? '[Event Request]' );
	$success = sanitize_textarea_field( $_POST['hl_success_message'] ?? '' );

	update_option( 'hostlinks_event_request_notification_email', $email );
	update_option( 'hostlinks_event_request_email_subject_prefix', $prefix );
	update_option( 'hostlinks_event_request_success_message', $success );

	$notice = '<div class="notice notice-success is-dismissible"><p>Event Request settings saved.</p></div>';
}

// ── Current values ────────────────────────────────────────────────────────────
$notif_email    = get_option( 'hostlinks_event_request_notification_email', get_option( 'admin_email' ) );
$subject_prefix = get_option( 'hostlinks_event_request_email_subject_prefix', '[Event Request]' );
$success_msg    = get_option( 'hostlinks_event_request_success_message', '' );
?>
<div class="wrap">
<h1>Hostlinks — Event Request Settings</h1>
<?php echo $notice; ?>

<form method="post">
	<?php wp_nonce_field( 'hl_event_request_settings' ); ?>

	<table class="form-table" role="presentation">
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

<hr />

<h2>Shortcode</h2>
<p>Place the following shortcode on any page to display the event request intake form:</p>
<p><code>[hostlinks_event_request_form]</code></p>
<p>The form saves submissions to a separate <strong>Event Requests</strong> table and does <em>not</em> publish events immediately. Review and manage submissions under <strong>Hostlinks &rarr; Event Requests</strong>.</p>
</div>
