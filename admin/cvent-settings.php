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

// ── Attendee Fetch Diagnostic ──────────────────────────────────────────────────
$diag_result = null;
if ( isset( $_POST['hostlinks_cvent_diag'] ) ) {
	check_admin_referer( 'hostlinks_cvent_settings' );
	$diag_id  = sanitize_text_field( trim( $_POST['diag_event_id'] ?? '' ) );
	$diag_id  = Hostlinks_CVENT_API::sanitize_uuid( $diag_id );

	if ( ! $diag_id ) {
		// Auto-pick: grab first event from list and use its ID.
		$list = Hostlinks_CVENT_API::list_active_events( 90 );
		if ( is_wp_error( $list ) ) {
			$diag_result = array( 'step' => 'list_events', 'error' => $list->get_error_message() );
		} elseif ( empty( $list['data'] ) ) {
			$diag_result = array( 'step' => 'list_events', 'error' => 'No events returned by API.' );
		} else {
			$first    = $list['data'][0];
			$diag_id  = Hostlinks_CVENT_API::sanitize_uuid( $first['id'] ?? '' );
			$diag_result = array( 'step' => 'auto_pick', 'event' => $first, 'raw_id' => $first['id'] ?? '', 'clean_id' => $diag_id );
		}
	}

	if ( $diag_id && ( ! isset( $diag_result['error'] ) ) ) {
		// Hex-dump of the ID to surface invisible characters.
		$hex = '';
		for ( $i = 0; $i < strlen( $diag_id ); $i++ ) {
			$hex .= sprintf( '%02X ', ord( $diag_id[ $i ] ) );
		}

		// Build the filter string and URL exactly as the API call would.
		$filter_str = 'eventId eq ' . $diag_id;
		$test_url   = Hostlinks_CVENT_API::BASE_URL . 'attendees/filter?' .
		              http_build_query( array( 'filter' => $filter_str, 'limit' => 5 ), '', '&', PHP_QUERY_RFC3986 );

		// Perform the actual attendee fetch.
		$attendees = Hostlinks_CVENT_Sync::fetch_attendees_for_event( $diag_id );

		$diag_result = array_merge( $diag_result ?? array(), array(
			'step'        => 'attendee_fetch',
			'clean_id'    => $diag_id,
			'hex_dump'    => trim( $hex ),
			'filter_str'  => $filter_str,
			'test_url'    => $test_url,
			'is_error'    => is_wp_error( $attendees ),
			'error_msg'   => is_wp_error( $attendees ) ? $attendees->get_error_message() : null,
			'count'       => is_wp_error( $attendees ) ? null : count( $attendees ),
			'first_few'   => is_wp_error( $attendees ) ? null : array_slice( $attendees, 0, 3 ),
		) );
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

	<hr />
	<h2>Attendee Fetch Diagnostic</h2>
	<p>Tests the <code>attendees/filter</code> endpoint directly. Leave the ID blank to auto-pick the first event from the API, or paste a specific CVENT event UUID to test it.</p>

	<form method="post">
		<?php wp_nonce_field( 'hostlinks_cvent_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="diag_event_id">CVENT Event UUID</label></th>
				<td>
					<input type="text" id="diag_event_id" name="diag_event_id"
						value="<?php echo esc_attr( $_POST['diag_event_id'] ?? '' ); ?>"
						class="regular-text" placeholder="Leave blank to auto-pick first event" />
				</td>
			</tr>
		</table>
		<p><button type="submit" name="hostlinks_cvent_diag" class="button button-secondary">Run Diagnostic</button></p>
	</form>

	<?php if ( $diag_result ) : ?>
	<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin-top:12px;border-radius:4px;">
		<h3 style="margin-top:0;">Diagnostic Result</h3>

		<?php if ( isset( $diag_result['error'] ) ) : ?>
			<p style="color:#d63638;"><strong>Error (<?php echo esc_html( $diag_result['step'] ); ?>):</strong>
			<?php echo esc_html( $diag_result['error'] ); ?></p>
		<?php else : ?>

			<?php if ( isset( $diag_result['event'] ) ) : ?>
			<p><strong>Auto-picked event:</strong> <?php echo esc_html( $diag_result['event']['title'] ?? '(no title)' ); ?></p>
			<p><strong>Raw ID from API:</strong> <code><?php echo esc_html( $diag_result['raw_id'] ?? '' ); ?></code></p>
			<?php endif; ?>

			<table class="widefat striped" style="margin-bottom:12px;">
				<tr>
					<th style="width:180px;">Clean UUID used</th>
					<td><code><?php echo esc_html( $diag_result['clean_id'] ?? '' ); ?></code></td>
				</tr>
				<tr>
					<th>Hex dump of UUID</th>
					<td><code style="word-break:break-all;font-size:11px;"><?php echo esc_html( $diag_result['hex_dump'] ?? '' ); ?></code>
					<br><small style="color:#888;">A clean UUID starts: <code>XX XX XX XX -</code> (no BOM = no leading EF BB BF)</small></td>
				</tr>
				<tr>
					<th>Filter string sent</th>
					<td><code><?php echo esc_html( $diag_result['filter_str'] ?? '' ); ?></code></td>
				</tr>
				<tr>
					<th>Full URL called</th>
					<td><code style="word-break:break-all;font-size:11px;"><?php echo esc_html( $diag_result['test_url'] ?? '' ); ?></code></td>
				</tr>
				<tr>
					<th>Result</th>
					<td>
					<?php if ( $diag_result['is_error'] ) : ?>
						<span style="color:#d63638;font-weight:600;">&#9888; FAILED</span> &mdash;
						<?php echo esc_html( $diag_result['error_msg'] ); ?>
					<?php else : ?>
						<span style="color:#0a6b00;font-weight:600;">&#10003; SUCCESS</span> &mdash;
						<?php echo (int) $diag_result['count']; ?> attendee(s) returned
					<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( ! $diag_result['is_error'] && ! empty( $diag_result['first_few'] ) ) : ?>
			<details>
				<summary style="cursor:pointer;">First <?php echo count( $diag_result['first_few'] ); ?> raw attendee record(s)</summary>
				<pre style="background:#f0f0f1;padding:10px;overflow:auto;font-size:11px;max-height:300px;"><?php
					echo esc_html( json_encode( $diag_result['first_few'], JSON_PRETTY_PRINT ) );
				?></pre>
			</details>
			<?php endif; ?>

		<?php endif; ?>
	</div>
	<?php endif; ?>

</div>
