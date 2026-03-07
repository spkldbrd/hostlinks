<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Save schedule settings ────────────────────────────────────────────────────
$schedule_notice = '';
if ( isset( $_POST['hostlinks_cvent_schedule_save'] ) ) {
	check_admin_referer( 'hostlinks_cvent_settings' );
	$sched_days = array_map( 'intval', (array) ( $_POST['cvent_schedule_days'] ?? array() ) );
	Hostlinks_CVENT_Scheduler::save_settings(
		! empty( $_POST['cvent_schedule_enabled'] ),
		$_POST['cvent_schedule_hour']       ?? 9,
		$_POST['cvent_schedule_minute']     ?? 0,
		$sched_days,
		$_POST['cvent_schedule_offset_max'] ?? 45
	);
	// Immediately apply the new schedule.
	Hostlinks_CVENT_Scheduler::maybe_reschedule();
	$schedule_notice = '<div class="notice notice-success is-dismissible"><p>Schedule settings saved.</p></div>';
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

		// Reg URL field probe — fetch full event object and find all URL-related keys.
		$event_obj       = Hostlinks_CVENT_API::get_event( $diag_id );
		$reg_url_fields  = array();
		$reg_url_found   = '';
		if ( ! is_wp_error( $event_obj ) && is_array( $event_obj ) ) {
			foreach ( $event_obj as $k => $v ) {
				if ( preg_match( '/url|link|registr/i', $k ) ) {
					$reg_url_fields[ $k ] = is_string( $v ) ? $v : '(' . gettype( $v ) . ')';
				}
			}
			// Replicate the exact extraction logic used in sync_one().
			$reg_url_found = $event_obj['registrationUrl'] ?? $event_obj['publicRegistrationUrl'] ?? $event_obj['websiteLink'] ?? '';
		}

		// Weblinks probe — fetch /weblinks endpoint as fallback source for Reg URL.
		$weblinks_raw    = Hostlinks_CVENT_API::get_event_weblinks( $diag_id );
		$weblinks_data   = is_wp_error( $weblinks_raw ) ? array() : $weblinks_raw;
		$weblinks_error  = is_wp_error( $weblinks_raw ) ? $weblinks_raw->get_error_message() : null;
		// Replicate resolve_reg_url() fallback logic for display.
		$weblinks_reg_url = '';
		if ( ! $reg_url_found && ! empty( $weblinks_data ) ) {
			foreach ( $weblinks_data as $wl ) {
				$label = strtolower( ( $wl['name'] ?? '' ) . ' ' . ( $wl['type'] ?? '' ) );
				if ( false !== strpos( $label, 'registr' ) ) {
					$weblinks_reg_url = $wl['url'] ?? '';
					break;
				}
			}
		}

		// Full order-items fetch using current production code path.
		$order_items = Hostlinks_CVENT_API::get_order_items( $diag_id );

		// Group by active=true (counted) vs active=false (skipped).
		// The API has no 'status' field — 'active' is the cancellation signal.
		$active_count   = 0;
		$inactive_count = 0;
		$counted_records = array();
		$skipped_records = array();
		if ( ! is_wp_error( $order_items ) ) {
			foreach ( $order_items as $item ) {
				if ( $item['active'] ?? true ) {
					$active_count++;
					$counted_records[] = $item;
				} else {
					$inactive_count++;
					$skipped_records[] = $item;
				}
			}
		}

		$diag_result = array_merge( $diag_result ?? array(), array(
			'step'             => 'order_items_fetch',
			'clean_id'         => $diag_id,
			'hex_dump'         => trim( $hex ),
			'token_meta'       => $token_meta,
			'requested_scope'  => Hostlinks_CVENT_API::REQUESTED_SCOPE,
			'primary_url'      => Hostlinks_CVENT_API::BASE_URL . 'events/' . $diag_id . '/orders/items?' .
			                      http_build_query( array( 'limit' => 5 ), '', '&', PHP_QUERY_RFC3986 ),
			'endpoint_results' => $endpoint_results,
			'reg_url_fields'   => $reg_url_fields,
			'reg_url_found'    => $reg_url_found,
			'event_obj_error'  => is_wp_error( $event_obj ) ? $event_obj->get_error_message() : null,
			'weblinks_data'    => $weblinks_data,
			'weblinks_error'   => $weblinks_error,
			'weblinks_reg_url' => $weblinks_reg_url,
			'is_error'         => is_wp_error( $order_items ),
			'error_msg'        => is_wp_error( $order_items ) ? $order_items->get_error_message() : null,
			'count'            => is_wp_error( $order_items ) ? null : count( $order_items ),
			'active_count'     => $active_count,
			'inactive_count'   => $inactive_count,
			'counted_records'  => $counted_records,
			'skipped_records'  => $skipped_records,
			'all_items'        => is_wp_error( $order_items ) ? null : $order_items,
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
	<h2>Daily Sync Schedule</h2>
	<?php echo $schedule_notice; ?>
	<?php
	$sched          = Hostlinks_CVENT_Scheduler::get_settings();
	$last_log       = Hostlinks_CVENT_Scheduler::get_last_log();
	$next_run       = Hostlinks_CVENT_Scheduler::next_run_display();
	$tz_label       = wp_timezone_string();
	$last_auto_run  = get_option( 'hostlinks_cvent_last_auto_run', '' );
	$next_sched_raw = get_option( 'hostlinks_cvent_next_scheduled_run', '' );

	// Format stored UTC next-run time in site timezone for display.
	$next_sched_display = '';
	if ( $next_sched_raw ) {
		try {
			$dt = new DateTime( $next_sched_raw, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( wp_timezone() );
			$next_sched_display = $dt->format( 'D, M j Y \a\t g:i a T' );
		} catch ( Exception $e ) {
			$next_sched_display = $next_sched_raw;
		}
	}
	?>
	<p>Automatically run a full CVENT sync on selected days at a set time with a random offset to make the schedule appear natural. Only events from the last 60 days forward are processed. Dry-run mode is <strong>not</strong> used — results are written live.</p>

	<table class="widefat striped" style="max-width:500px;margin-bottom:12px;">
		<thead><tr><th colspan="2">Scheduler Status</th></tr></thead>
		<tbody>
			<tr>
				<td><strong>Last cron fire</strong></td>
				<td>
					<?php if ( $last_auto_run ) : ?>
						<?php echo esc_html( $last_auto_run ); ?> (site time)
					<?php else : ?>
						<em style="color:#888;">Never fired</em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Last sync completed</strong></td>
				<td>
					<?php if ( $last_log ) : ?>
						<?php echo esc_html( $last_log['time'] ); ?> (site time)
						— <?php echo (int) $last_log['synced']; ?> synced,
						<?php echo (int) $last_log['errors']; ?> errors,
						<?php echo (int) $last_log['needs_review']; ?> need review
					<?php else : ?>
						<em style="color:#888;">No sync log yet</em>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Next scheduled run</strong></td>
				<td>
					<?php if ( $next_run ) : ?>
						<?php echo esc_html( $next_run ); ?>
					<?php elseif ( $next_sched_display ) : ?>
						<?php echo esc_html( $next_sched_display ); ?> <em style="color:#888;">(stored)</em>
					<?php elseif ( $sched['enabled'] ) : ?>
						<span style="color:#d63638;">&#9888; No cron event queued — save settings to requeue.</span>
					<?php else : ?>
						<em style="color:#888;">Scheduler disabled</em>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( $last_log ) : ?>
	<details style="margin-bottom:12px;">
		<summary style="cursor:pointer;font-weight:600;">Last Auto-Sync Result detail</summary>
		<table class="widefat striped" style="max-width:500px;margin-top:8px;">
			<tbody>
				<tr><td><strong>Events processed</strong></td><td><?php echo (int) $last_log['total_events']; ?></td></tr>
				<tr><td><strong>Synced (counts written)</strong></td><td><?php echo (int) $last_log['synced']; ?></td></tr>
				<tr><td><strong>Auto-matched (no count yet)</strong></td><td><?php echo (int) $last_log['matched']; ?></td></tr>
				<tr><td><strong>Needs review</strong></td><td><?php echo (int) $last_log['needs_review']; ?></td></tr>
				<tr><td><strong>No candidates</strong></td><td><?php echo (int) $last_log['no_candidates']; ?></td></tr>
				<tr><td><strong>Errors</strong></td><td><?php echo (int) $last_log['errors']; ?></td></tr>
			</tbody>
		</table>
	</details>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'hostlinks_cvent_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable auto-sync</th>
				<td>
					<label>
						<input type="checkbox" name="cvent_schedule_enabled" value="1"
							<?php checked( $sched['enabled'] ); ?> />
						Run CVENT sync automatically on selected days
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>Run on days</label></th>
				<td>
					<?php
					$day_labels = array( 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' );
					$sched_days = isset( $sched['days'] ) ? (array) $sched['days'] : array( 1, 2, 3, 4, 5 );
					foreach ( $day_labels as $d => $label ) :
					?>
					<label style="margin-right:14px;display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
						<input type="checkbox" name="cvent_schedule_days[]" value="<?php echo $d; ?>"
							<?php checked( in_array( $d, $sched_days, true ) ); ?> />
						<?php echo $label; ?>
					</label>
					<?php endforeach; ?>
					<p class="description" style="margin-top:6px;">Default: Mon – Fri (weekdays only).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>Base time</label></th>
				<td>
					<select name="cvent_schedule_hour" style="width:80px;">
					<?php for ( $h = 0; $h < 24; $h++ ) : ?>
						<option value="<?php echo $h; ?>" <?php selected( (int) $sched['hour'], $h ); ?>>
							<?php echo str_pad( $h, 2, '0', STR_PAD_LEFT ); ?>
						</option>
					<?php endfor; ?>
					</select>
					:
					<select name="cvent_schedule_minute" style="width:80px;">
					<?php foreach ( array( 0, 15, 30, 45 ) as $m ) : ?>
						<option value="<?php echo $m; ?>" <?php selected( (int) $sched['minute'], $m ); ?>>
							<?php echo str_pad( $m, 2, '0', STR_PAD_LEFT ); ?>
						</option>
					<?php endforeach; ?>
					</select>
					<p class="description">Site timezone: <strong><?php echo esc_html( $tz_label ); ?></strong>. Default: 9:00 AM.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>Random offset</label></th>
				<td>
					<select name="cvent_schedule_offset_max" style="width:130px;">
					<?php foreach ( array( 0, 15, 30, 45, 60 ) as $o ) : ?>
						<option value="<?php echo $o; ?>" <?php selected( (int) ( $sched['offset_max'] ?? 45 ), $o ); ?>>
							± <?php echo $o; ?> minutes
						</option>
					<?php endforeach; ?>
					</select>
					<p class="description">A random offset is added or subtracted from the base time on each run, making the schedule look like a human triggered it. Default: ±45 min.</p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" name="hostlinks_cvent_schedule_save" class="button button-primary">Save Schedule</button>
			<?php if ( $sched['enabled'] && $next_run ) : ?>
			&nbsp;
			<span style="line-height:30px;color:#555;">Next run: <?php echo esc_html( $next_run ); ?></span>
			<?php endif; ?>
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
						$granted_raw = $meta['scope'] ?? '';
						$req         = $diag_result['requested_scope'] ?? '';
						$not_returned = ( $granted_raw === '(not returned by server)' || $granted_raw === '' );
					?>
						<code><?php echo esc_html( $granted_raw ?: '(not returned by server)' ); ?></code>
						<?php if ( $not_returned ) : ?>
						<br><small style="color:#888;">CVENT commonly omits the <code>scope</code> field from the token response — this is normal. Verify access by checking the endpoint probe results below.</small>
						<?php elseif ( trim( $granted_raw ) === trim( $req ) ) : ?>
						<br><span style="color:#0a6b00;">&#10003; Matches requested scope.</span>
						<?php else : ?>
						<br><span style="color:#d63638;font-weight:600;">&#9888; Mismatch — server granted different scopes. Check your CVENT app permissions in the Developer Portal.</span>
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

		<?php // ── Reg URL field probe ──────────────────────────────────────────────── ?>
		<h4>Reg URL field probe (from <code>GET /ea/events/{UUID}</code>)</h4>
		<?php if ( ! empty( $diag_result['event_obj_error'] ) ) : ?>
			<p style="color:#d63638;"><strong>get_event() failed:</strong> <?php echo esc_html( $diag_result['event_obj_error'] ); ?></p>
		<?php elseif ( empty( $diag_result['reg_url_fields'] ) ) : ?>
			<p style="color:#888;">No keys containing <code>url</code>, <code>link</code>, or <code>registr</code> were found in the event object.</p>
		<?php else : ?>
			<p style="font-size:12px;color:#555;">These are every key in the CVENT event object whose name contains <code>url</code>, <code>link</code>, or <code>registr</code>. The plugin tries <code>registrationUrl</code> → <code>publicRegistrationUrl</code> → <code>websiteLink</code> in that order.</p>
			<table class="widefat striped" style="max-width:700px;margin-bottom:8px;">
				<thead><tr><th style="width:220px;">Field name</th><th>Value</th><th>Plugin uses?</th></tr></thead>
				<tbody>
				<?php
				$plugin_tries = array( 'registrationUrl', 'publicRegistrationUrl', 'websiteLink' );
				foreach ( $diag_result['reg_url_fields'] as $field_key => $field_val ) :
					$is_tried  = in_array( $field_key, $plugin_tries, true );
					$has_value = ( $field_val && $field_val !== '(NULL)' && $field_val !== '(array)' );
				?>
					<tr style="<?php echo ( $is_tried && $has_value ) ? 'background:#e6f4ea;' : ( $is_tried ? 'background:#fffbe6;' : '' ); ?>">
						<td><code><?php echo esc_html( $field_key ); ?></code></td>
						<td style="word-break:break-all;"><?php echo $has_value ? '<code style="font-size:11px;">' . esc_html( $field_val ) . '</code>' : '<span style="color:#999;">empty / null</span>'; ?></td>
						<td><?php echo $is_tried ? '<span style="color:#0a6b00;">&#10003; yes</span>' : '<span style="color:#999;">no</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php if ( $diag_result['reg_url_found'] ) : ?>
			<p><strong style="color:#0a6b00;">&#10003; Reg URL the plugin would use (from event object):</strong> <code><?php echo esc_html( $diag_result['reg_url_found'] ); ?></code></p>
		<?php else : ?>
			<p><strong style="color:#888;">&#9888; Event object returned empty URL.</strong> None of the three tried fields (<code>registrationUrl</code>, <code>publicRegistrationUrl</code>, <code>websiteLink</code>) contained a value. The plugin will automatically try the <strong>Weblinks endpoint</strong> as a fallback &mdash; see probe below.</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php // ── Weblinks probe ──────────────────────────────────────────────────── ?>
	<h4>Weblinks probe (<code>GET /ea/events/{UUID}/weblinks</code>)</h4>
	<?php if ( $diag_result['weblinks_error'] ) : ?>
		<p style="color:#d63638;"><strong>Weblinks fetch failed:</strong> <?php echo esc_html( $diag_result['weblinks_error'] ); ?></p>
	<?php elseif ( empty( $diag_result['weblinks_data'] ) ) : ?>
		<p style="color:#888;">No weblinks returned for this event (empty array). This may mean the event has no registration page configured in CVENT yet.</p>
	<?php else : ?>
		<p style="font-size:12px;color:#555;">The plugin uses the first weblink whose <code>name</code> or <code>type</code> contains <code>registr</code> (case-insensitive) as the fallback Reg URL when the event object fields are blank.</p>
		<table class="widefat striped" style="max-width:750px;margin-bottom:8px;">
			<thead><tr><th style="width:180px;">name</th><th style="width:130px;">type</th><th>url</th><th style="width:80px;">Plugin uses?</th></tr></thead>
			<tbody>
			<?php
			foreach ( $diag_result['weblinks_data'] as $wl ) :
				$wl_name  = $wl['name'] ?? '';
				$wl_type  = $wl['type'] ?? '';
				$wl_url   = $wl['url']  ?? '';
				$is_reg   = ( false !== stripos( $wl_name . ' ' . $wl_type, 'registr' ) );
			?>
				<tr style="<?php echo $is_reg ? 'background:#e6f4ea;' : ''; ?>">
					<td><?php echo esc_html( $wl_name ); ?></td>
					<td><code style="font-size:11px;"><?php echo esc_html( $wl_type ); ?></code></td>
					<td style="word-break:break-all;"><code style="font-size:11px;"><?php echo esc_html( $wl_url ); ?></code></td>
					<td><?php echo $is_reg ? '<span style="color:#0a6b00;">&#10003; yes</span>' : '<span style="color:#999;">no</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $diag_result['weblinks_reg_url'] ) : ?>
			<p><strong style="color:#0a6b00;">&#10003; Fallback Reg URL the plugin would use:</strong> <code><?php echo esc_html( $diag_result['weblinks_reg_url'] ); ?></code></p>
		<?php elseif ( ! $diag_result['reg_url_found'] ) : ?>
			<p><strong style="color:#d63638;">&#9888; No registration weblink found.</strong> No weblink entry had a name/type containing <code>registr</code>. The Reg URL field will remain blank after sync until a registration link is published in CVENT.</p>
		<?php else : ?>
			<p style="color:#888;">(Weblinks fallback not needed — Reg URL already found in event object above.)</p>
		<?php endif; ?>
	<?php endif; ?>

		<?php if ( ! $diag_result['is_error'] ) : ?>
		<h4>Active/inactive breakdown (all <?php echo (int) $diag_result['count']; ?> order items)</h4>
			<p style="font-size:12px;color:#555;">The API returns no <code>status</code> field. Cancellation is indicated by <code>"active": false</code>.</p>
			<table class="widefat striped" style="max-width:500px;margin-bottom:12px;">
				<thead><tr><th>Field value</th><th>Count</th><th>Plugin action</th></tr></thead>
				<tbody>
					<tr style="background:#f0fff4;">
						<td><code>active: true</code></td>
						<td><?php echo (int) ( $diag_result['active_count'] ?? 0 ); ?></td>
						<td><span style="color:#0a6b00;">&#10003; Counted</span></td>
					</tr>
					<tr style="background:#fff0f0;">
						<td><code>active: false</code></td>
						<td><?php echo (int) ( $diag_result['inactive_count'] ?? 0 ); ?></td>
						<td><span style="color:#d63638;">&#10007; Skipped (cancelled/voided)</span></td>
					</tr>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( ! $diag_result['is_error'] && ! empty( $diag_result['skipped_records'] ) ) : ?>
			<details>
				<summary style="cursor:pointer;font-weight:600;color:#d63638;">
					Skipped records (<?php echo count( $diag_result['skipped_records'] ); ?>) — these are NOT counted
				</summary>
				<pre style="background:#fff0f0;padding:10px;overflow:auto;font-size:11px;max-height:300px;"><?php
					echo esc_html( json_encode( $diag_result['skipped_records'], JSON_PRETTY_PRINT ) );
				?></pre>
			</details>
			<?php endif; ?>

			<?php if ( ! $diag_result['is_error'] && ! empty( $diag_result['all_items'] ) ) : ?>
			<details>
				<summary style="cursor:pointer;">All <?php echo count( $diag_result['all_items'] ); ?> raw order item records (key fields)</summary>
				<table class="widefat striped" style="margin-top:6px;font-size:11px;">
					<thead><tr><th>#</th><th>attendeeId</th><th>active</th><th>discount name / code</th><th>amountPaid</th><th>amountDue</th></tr></thead>
					<tbody>
					<?php foreach ( $diag_result['all_items'] as $i => $item ) :
						$is_active = $item['active'] ?? true;
						// Pull discount label: prefer code, fall back to name.
						$disc_label = '—';
						if ( ! empty( $item['discounts'] ) ) {
							$parts = array();
							foreach ( $item['discounts'] as $d ) {
								$parts[] = ( ! empty( $d['code'] ) ? $d['code'] : ( $d['name'] ?? '' ) );
							}
							$disc_label = implode( ', ', array_filter( $parts ) ) ?: '—';
						}
					?>
						<tr style="<?php echo ! $is_active ? 'background:#fff0f0;' : ''; ?>">
							<td><?php echo $i + 1; ?></td>
							<td><code style="font-size:10px;"><?php echo esc_html( substr( $item['attendee']['id'] ?? '—', 0, 8 ) . '…' ); ?></code></td>
							<td>
								<?php if ( $is_active ) : ?>
									<span style="color:#0a6b00;">&#10003; true</span>
								<?php else : ?>
									<span style="color:#d63638;">&#10007; false</span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $disc_label ); ?></code></td>
							<td><?php echo esc_html( $item['amountPaid'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $item['amountDue'] ?? '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<details style="margin-top:8px;"><summary style="font-size:11px;cursor:pointer;">Full JSON of all records</summary>
				<pre style="background:#f0f0f1;padding:10px;overflow:auto;font-size:10px;max-height:400px;"><?php
					echo esc_html( json_encode( $diag_result['all_items'], JSON_PRETTY_PRINT ) );
				?></pre></details>
			</details>
			<?php endif; ?>

		<?php endif; ?>
	</div>
	<?php endif; ?>

</div>
