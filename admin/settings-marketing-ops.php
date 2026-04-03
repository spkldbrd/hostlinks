<?php
/**
 * Settings → Marketing Ops tab.
 *
 * Controls the optional "📋 Marketing Ops" button on the [eventlisto] calendar.
 * The button is shown when a published page containing [hmo_dashboard_selector]
 * is detected (or a manual URL override is set in General settings).
 *
 * Included from admin/settings.php with $hl_embedded = true.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

// ── Save ──────────────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hostlinks_save_mktops'] ) ) {
	check_admin_referer( 'hostlinks_mktops_settings' );

	$btn = sanitize_key( $_POST['hostlinks_mktops_btn'] ?? 'disabled' );
			if ( ! in_array( $btn, array( 'disabled', 'admin', 'admin_plus_mgr', 'all' ), true ) ) {
		$btn = 'disabled';
	}
	update_option( 'hostlinks_mktops_btn', $btn );
	$notice = '<div class="notice notice-success is-dismissible"><p>Marketing Ops settings saved.</p></div>';
}

// ── Reset prompt ──────────────────────────────────────────────────────────────
if ( isset( $_POST['hostlinks_reset_mktops_prompt'] ) ) {
	check_admin_referer( 'hostlinks_mktops_settings' );
	delete_option( 'hostlinks_mktops_prompt_dismissed' );
	$notice = '<div class="notice notice-success is-dismissible"><p>Detection prompt reset — it will appear again on the next admin page load if the Marketing Hub page is still published.</p></div>';
}

// ── Current values ────────────────────────────────────────────────────────────
$btn         = get_option( 'hostlinks_mktops_btn', 'disabled' );
$hub_url     = Hostlinks_Page_URLs::get_mktops_hub();
$dismissed   = get_option( 'hostlinks_mktops_prompt_dismissed' ) === '1';
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Marketing Ops Integration</h2>
<p>When enabled, a <strong>&#x1F4CB; Marketing Ops</strong> button appears in the <code>[eventlisto]</code> calendar navigation bar (between Reports and + Event), linking users to your Marketing Hub page.</p>

<!-- Detection status -------------------------------------------------------->
<h3 style="font-size:14px;margin:20px 0 8px;">Marketing Hub Page Detection</h3>
<table class="widefat striped" style="max-width:660px;margin-bottom:16px;">
	<tbody>
		<tr>
			<th style="width:200px;">Shortcode</th>
			<td><code>[hmo_dashboard_selector]</code></td>
		</tr>
		<tr>
			<th>Page detected</th>
			<td>
				<?php if ( $hub_url ) : ?>
					<span style="color:#00a32a;font-weight:600;">&#9679; Yes</span>
					&mdash; <a href="<?php echo esc_url( $hub_url ); ?>" target="_blank"><?php echo esc_html( $hub_url ); ?></a>
				<?php else : ?>
					<span style="color:#d63638;font-weight:600;">&#9679; Not found</span>
					&mdash; Create a published page and add <code>[hmo_dashboard_selector]</code> to it. The button will remain hidden until the page is detected.
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th>Admin notice</th>
			<td>
				<?php if ( $dismissed ) : ?>
					<span style="color:#888;">Dismissed</span>
				<?php elseif ( $hub_url ) : ?>
					<span style="color:#0a6cbc;">Will display on next admin page load</span>
				<?php else : ?>
					<span style="color:#888;">Will display when a Marketing Hub page is detected</span>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<!-- Main settings form ------------------------------------------------------>
<form method="post">
	<?php wp_nonce_field( 'hostlinks_mktops_settings' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hostlinks_mktops_btn">"&#x1F4CB; Marketing Ops" Button</label></th>
			<td>
				<select id="hostlinks_mktops_btn" name="hostlinks_mktops_btn">
					<option value="disabled"       <?php selected( $btn, 'disabled' ); ?>>Disabled</option>
					<option value="admin"          <?php selected( $btn, 'admin' ); ?>>WordPress Admins only</option>
					<option value="admin_plus_mgr" <?php selected( $btn, 'admin_plus_mgr' ); ?>>Admins &amp; Marketing Managers</option>
					<option value="all"            <?php selected( $btn, 'all' ); ?>>All Hostlinks users</option>
				</select>
				<p class="description">
					Controls who sees the <strong>&#x1F4CB; Marketing Ops</strong> button on the upcoming events calendar. The button only appears when a Marketing Hub page is detected.<br>
					<em>Admins &amp; Marketing Managers</em> requires the Marketing Ops plugin to be active; Marketing Managers are configured under Marketing Ops → Settings → User Access.
				</p>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hostlinks_save_mktops" class="button button-primary">Save Settings</button>
	</p>
</form>

<!-- Reset prompt notice ----------------------------------------------------->
<hr style="margin:24px 0;" />
<h3 style="font-size:14px;margin:0 0 8px;">Detection Notice</h3>
<p style="color:#666;">If you dismissed the detection prompt and want it to re-appear, click the button below.</p>
<form method="post">
	<?php wp_nonce_field( 'hostlinks_mktops_settings' ); ?>
	<button type="submit" name="hostlinks_reset_mktops_prompt" class="button button-secondary">Reset Detection Prompt</button>
</form>
