<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Save settings ─────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hostlinks_cvent_save'] ) ) {
	check_admin_referer( 'hostlinks_cvent_settings' );
	Hostlinks_CVENT_API::save_settings(
		$_POST['cvent_client_id']      ?? '',
		$_POST['cvent_client_secret']  ?? '',
		$_POST['cvent_account_number'] ?? ''
	);
	$notice = '<div class="notice notice-success is-dismissible"><p>CVENT settings saved.</p></div>';
}

// ── Test Connection ────────────────────────────────────────────────────────────
$test_result = null;
if ( isset( $_POST['hostlinks_cvent_test'] ) ) {
	check_admin_referer( 'hostlinks_cvent_settings' );
	// Save first so test uses latest-submitted credentials.
	Hostlinks_CVENT_API::save_settings(
		$_POST['cvent_client_id']      ?? '',
		$_POST['cvent_client_secret']  ?? '',
		$_POST['cvent_account_number'] ?? ''
	);
	// Force fresh token fetch.
	delete_transient( Hostlinks_CVENT_API::TOKEN_KEY );
	$token = Hostlinks_CVENT_API::get_token();
	if ( is_wp_error( $token ) ) {
		$test_result = array( 'ok' => false, 'msg' => $token->get_error_message() );
	} else {
		$test_result = array( 'ok' => true, 'msg' => 'Connection successful. Token received (prefix: ' . substr( $token, 0, 8 ) . '…).' );
	}
}

$s = Hostlinks_CVENT_API::get_settings();
?>
<div class="wrap">
	<h1>CVENT Settings</h1>
	<?php echo $notice; // already sanitized above ?>

	<?php if ( $test_result ) : ?>
		<div class="notice <?php echo $test_result['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $test_result['msg'] ); ?></p>
		</div>
	<?php endif; ?>

	<p>Enter your CVENT Developer Platform OAuth 2.0 credentials. These are stored in the WordPress database.</p>

	<form method="post">
		<?php wp_nonce_field( 'hostlinks_cvent_settings' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cvent_client_id">Client ID</label></th>
				<td>
					<input
						type="text"
						id="cvent_client_id"
						name="cvent_client_id"
						value="<?php echo esc_attr( $s['client_id'] ); ?>"
						class="regular-text"
						autocomplete="off"
					/>
					<p class="description">OAuth 2.0 Client ID from your CVENT Developer account.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvent_client_secret">Client Secret</label></th>
				<td>
					<input
						type="password"
						id="cvent_client_secret"
						name="cvent_client_secret"
						value="<?php echo esc_attr( $s['client_secret'] ); ?>"
						class="regular-text"
						autocomplete="new-password"
					/>
					<p class="description">OAuth 2.0 Client Secret. Stored securely in the database.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cvent_account_number">Account Number</label></th>
				<td>
					<input
						type="text"
						id="cvent_account_number"
						name="cvent_account_number"
						value="<?php echo esc_attr( $s['account_number'] ); ?>"
						class="regular-text"
						autocomplete="off"
					/>
					<p class="description">Your CVENT account number — sent as the <code>Cvent-Account-Number</code> header on every API request.</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="hostlinks_cvent_save" class="button button-primary">Save Settings</button>
			&nbsp;
			<button type="submit" name="hostlinks_cvent_test" class="button button-secondary">Test Connection</button>
		</p>
	</form>

	<hr />
	<h2>API Endpoints</h2>
	<table class="widefat striped" style="max-width:600px;">
		<tbody>
			<tr><td><strong>Token URL</strong></td><td><code><?php echo esc_html( Hostlinks_CVENT_API::TOKEN_URL ); ?></code></td></tr>
			<tr><td><strong>Base URL</strong></td><td><code><?php echo esc_html( Hostlinks_CVENT_API::BASE_URL ); ?></code></td></tr>
		</tbody>
	</table>

	<hr />
	<h2>API Call Budget (Free Tier)</h2>
	<table class="widefat striped" style="max-width:500px;">
		<thead><tr><th>Limit</th><th>Value</th></tr></thead>
		<tbody>
			<tr><td>Daily calls</td><td>1,000</td></tr>
			<tr><td>Per second</td><td>2</td></tr>
			<tr><td>Estimated calls / sync</td><td>~1 + number of active events</td></tr>
		</tbody>
	</table>
</div>
