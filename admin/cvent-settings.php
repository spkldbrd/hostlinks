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

// ── Order Items / Attendee Diagnostic ─────────────────────────────────────────
// Tests the event-scoped endpoints confirmed correct after v2.4.16 diagnosis:
//   GET /ea/events/{UUID}/orders/items  (primary — discount codes + attendee IDs)
//   GET /ea/events/{UUID}/attendees     (fallback — status only, no discounts)
//   GET /ea/events/{UUID}/orders        (bonus — show order-level structure)
// Previous paths that all failed:
//   /attendees?filter=eventId…          → HTTP 400 "Unsupported filter field eventId"
//   /attendees/filter?filter=eventId…   → HTTP 400 "Not a valid UUID"
//   /orders/items?filter=eventId…       → HTTP 404 "Unrecognized request URL"
$diag_result = null;
if ( isset( $_POST['hostlinks_cvent_diag'] ) ) {
	check_admin_referer( 'hostlinks_cvent_settings' );
	$diag_id = sanitize_text_field( trim( $_POST['diag_event_id'] ?? '' ) );
	$diag_id = Hostlinks_CVENT_API::sanitize_uuid( $diag_id );

	if ( ! $diag_id ) {
		$list = Hostlinks_CVENT_API::list_active_events( 90 );
		if ( is_wp_error( $list ) ) {
			$diag_result = array( 'step' => 'list_events', 'error' => $list->get_error_message() );
		} elseif ( empty( $list['data'] ) ) {
			$diag_result = array( 'step' => 'list_events', 'error' => 'No events returned by API.' );
		} else {
			$first   = $list['data'][0];
			$diag_id = Hostlinks_CVENT_API::sanitize_uuid( $first['id'] ?? '' );
			$diag_result = array( 'step' => 'auto_pick', 'event' => $first, 'raw_id' => $first['id'] ?? '', 'clean_id' => $diag_id );
		}
	}

	if ( $diag_id && ( ! isset( $diag_result['error'] ) ) ) {
		$hex = '';
		for ( $i = 0; $i < strlen( $diag_id ); $i++ ) {
			$hex .= sprintf( '%02X ', ord( $diag_id[ $i ] ) );
		}

		// Force a fresh token so the new scope takes effect immediately.
		delete_transient( Hostlinks_CVENT_API::TOKEN_KEY );
		delete_transient( Hostlinks_CVENT_API::TOKEN_KEY . '_meta' );
		Hostlinks_CVENT_API::get_token(); // Prime the cache + meta.
		$token_meta = Hostlinks_CVENT_API::get_token_meta();

		// Endpoints to probe (3 records each, 1 API call each).
		$endpoints_to_test = array(
			// Primary path for counts + discounts.
			'events/{UUID}/orders/items'        => 'events/' . $diag_id . '/orders/items',
			// Fallback: status-only attendee count.
			'events/{UUID}/attendees'            => 'events/' . $diag_id . '/attendees',
			// Orders parent object (scope check).
			'events/{UUID}/orders'               => 'events/' . $diag_id . '/orders',
			// Flat attendees with no filter — verifies attendees:read scope at all.
			'attendees (no filter, scope check)' => 'attendees',
		);

		$endpoint_results = array();
		foreach ( $endpoints_to_test as $label => $ep ) {
			$params = array( 'limit' => 3 );
			$res    = Hostlinks_CVENT_API::request( $ep, $params );
			$endpoint_results[ $label ] = array(
				'url'    => Hostlinks_CVENT_API::BASE_URL . $ep . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
				'ok'     => ! is_wp_error( $res ),
				'error'  => is_wp_error( $res ) ? $res->get_error_message() : null,
				'count'  => is_wp_error( $res ) ? null : count( $res['data'] ?? array() ),
				'sample' => is_wp_error( $res ) ? null : array_slice( $res['data'] ?? array(), 0, 1 ),
			);
		}

		// Full order-items fetch using current production code path.
		$order_items = Hostlinks_CVENT_API::get_order_items( $diag_id );

		$diag_result = array_merge( $diag_result ?? array(), array(
			'step'             => 'order_items_fetch',
			'clean_id'         => $diag_id,
			'hex_dump'         => trim( $hex ),
			'token_meta'       => $token_meta,
			'requested_scope'  => Hostlinks_CVENT_API::REQUESTED_SCOPE,
			'primary_url'      => Hostlinks_CVENT_API::BASE_URL . 'events/' . $diag_id . '/orders/items?' .
			                      http_build_query( array( 'limit' => 5 ), '', '&', PHP_QUERY_RFC3986 ),
			'endpoint_results' => $endpoint_results,
			'is_error'         => is_wp_error( $order_items ),
			'error_msg'        => is_wp_error( $order_items ) ? $order_items->get_error_message() : null,
			'count'            => is_wp_error( $order_items ) ? null : count( $order_items ),
			'first_few'        => is_wp_error( $order_items ) ? null : array_slice( $order_items, 0, 3 ),
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
	<h2>Order Items / Attendee Diagnostic</h2>
	<p>Tests the <strong>event-scoped endpoints</strong> now used in v2.4.17. Leave the UUID blank to auto-pick the first event from the API, or paste a specific CVENT event UUID.</p>
	<p style="color:#666;font-size:12px;">Previous flat-collection paths all failed: <code>/attendees?filter=eventId…</code> → 400 "Unsupported filter field", <code>/attendees/filter</code> → 400 "Not a valid UUID", <code>/orders/items?filter=eventId…</code> → 404. Now using event-scoped paths.<br>
	If you see <strong>HTTP 403</strong> on orders endpoints, the scope <code>event/orders:read</code> is missing — either the token server isn't granting it (check the "Scope granted" row) or it isn't enabled on your CVENT app in the Developer Portal.</p>

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
					<th style="width:220px;">Clean UUID used</th>
					<td><code><?php echo esc_html( $diag_result['clean_id'] ?? '' ); ?></code></td>
				</tr>
				<tr>
					<th>Hex dump of UUID</th>
					<td><code style="word-break:break-all;font-size:11px;"><?php echo esc_html( $diag_result['hex_dump'] ?? '' ); ?></code>
					<br><small style="color:#888;">A clean UUID starts: <code>63 XX XX XX</code> ('c') — no BOM = no leading <code>EF BB BF</code></small></td>
				</tr>
				<tr style="background:#fffbe6;">
					<th>Scope <em>requested</em> (sent to token endpoint)</th>
					<td><code><?php echo esc_html( $diag_result['requested_scope'] ?? '' ); ?></code>
					<br><small style="color:#888;">These scopes must also be enabled on your CVENT app in the Developer Portal.</small></td>
				</tr>
				<tr style="background:#fffbe6;">
					<th>Scope <em>granted</em> (returned by CVENT token server)</th>
					<td>
					<?php
					$meta = $diag_result['token_meta'] ?? null;
					if ( $meta ) :
						$granted = esc_html( $meta['scope'] );
						$req     = $diag_result['requested_scope'] ?? '';
						// Highlight if granted scope differs from requested.
						$match   = ( trim( $meta['scope'] ) === trim( $req ) );
					?>
						<code><?php echo $granted; ?></code>
						<?php if ( ! $match ) : ?>
						<br><span style="color:#d63638;font-weight:600;">&#9888; Mismatch — server did not grant all requested scopes. Check your CVENT app permissions in the Developer Portal.</span>
						<?php else : ?>
						<br><span style="color:#0a6b00;">&#10003; Matches requested scope.</span>
						<?php endif; ?>
					<?php else : ?>
						<span style="color:#888;">(Token fetch failed or metadata not available — run Test Connection first.)</span>
					<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>Primary URL (order items)</th>
					<td><code style="word-break:break-all;font-size:11px;"><?php echo esc_html( $diag_result['primary_url'] ?? '' ); ?></code></td>
				</tr>
				<tr>
					<th>Full order-items fetch result</th>
					<td>
					<?php if ( $diag_result['is_error'] ) : ?>
						<span style="color:#d63638;font-weight:600;">&#9888; FAILED</span> &mdash;
						<?php echo esc_html( $diag_result['error_msg'] ); ?>
					<?php else : ?>
						<span style="color:#0a6b00;font-weight:600;">&#10003; SUCCESS</span> &mdash;
						<?php echo (int) $diag_result['count']; ?> order item(s) returned (all pages)
					<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $diag_result['endpoint_results'] ) ) : ?>
			<h4>Endpoint probe results (3 records each, 1 API call each)</h4>
			<table class="widefat striped" style="margin-bottom:12px;">
				<thead><tr><th>Endpoint</th><th>Result</th><th>URL sent</th></tr></thead>
				<tbody>
				<?php foreach ( $diag_result['endpoint_results'] as $label => $er ) : ?>
					<tr style="<?php echo $er['ok'] ? 'background:#e6f4ea;' : ''; ?>">
						<td><code><?php echo esc_html( $label ); ?></code></td>
						<td>
							<?php if ( $er['ok'] ) : ?>
								<strong style="color:#0a6b00;">&#10003; OK</strong> — <?php echo (int) $er['count']; ?> record(s)
							<?php else : ?>
								<span style="color:#d63638;">&#9888;</span> <?php echo esc_html( $er['error'] ); ?>
							<?php endif; ?>
						</td>
						<td><code style="font-size:10px;word-break:break-all;"><?php echo esc_html( $er['url'] ); ?></code></td>
					</tr>
					<?php if ( $er['ok'] && ! empty( $er['sample'] ) ) : ?>
					<tr>
						<td colspan="3" style="padding:4px 12px;">
							<details><summary style="font-size:11px;cursor:pointer;">Sample record keys</summary>
							<pre style="font-size:10px;background:#f0f0f1;padding:6px;max-height:150px;overflow:auto;"><?php
								echo esc_html( json_encode( array_keys( $er['sample'][0] ), JSON_PRETTY_PRINT ) );
							?></pre></details>
						</td>
					</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( ! $diag_result['is_error'] && ! empty( $diag_result['first_few'] ) ) : ?>
			<details>
				<summary style="cursor:pointer;">First <?php echo count( $diag_result['first_few'] ); ?> raw order item record(s)</summary>
				<pre style="background:#f0f0f1;padding:10px;overflow:auto;font-size:11px;max-height:300px;"><?php
					echo esc_html( json_encode( $diag_result['first_few'], JSON_PRETTY_PRINT ) );
				?></pre>
			</details>
			<?php endif; ?>

		<?php endif; ?>
	</div>
	<?php endif; ?>

</div>
