<?php
/**
 * Settings → Automation API tab.
 *
 * Manages labeled, scoped API keys used by n8n (or any HTTP client) to call
 * the Hostlinks REST API endpoints under /wp-json/hostlinks/v1/.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$notice    = '';
$base_url  = rest_url( 'hostlinks/v1' );
$test_mode = (bool) get_option( 'hostlinks_api_test_mode', 0 );
$catalog   = Hostlinks_Instructor_API::scope_catalog();

// ── Key CRUD ────────────────────────────────────────────────────────────────
if ( isset( $_POST['hl_api_key_action'] ) ) {
	check_admin_referer( 'hostlinks_api_keys' );
	$action = sanitize_text_field( (string) $_POST['hl_api_key_action'] );
	$key_id = sanitize_text_field( (string) ( $_POST['key_id'] ?? '' ) );
	$label  = sanitize_text_field( (string) ( $_POST['key_label'] ?? '' ) );
	$scopes = isset( $_POST['key_scopes'] ) ? (array) $_POST['key_scopes'] : array();

	if ( 'create' === $action ) {
		$result = Hostlinks_Instructor_API::create_key( $label, $scopes );
		$notice = is_wp_error( $result )
			? '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>API key <strong>' . esc_html( $result['label'] ) . '</strong> created. Copy it now into the email tool or n8n.</p></div>';
	} elseif ( 'update' === $action && $key_id ) {
		$result = Hostlinks_Instructor_API::update_key( $key_id, $label, $scopes );
		$notice = is_wp_error( $result )
			? '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>API key updated.</p></div>';
	} elseif ( 'regenerate' === $action && $key_id ) {
		$result = Hostlinks_Instructor_API::regenerate_key( $key_id );
		$notice = is_wp_error( $result )
			? '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'
			: '<div class="notice notice-warning is-dismissible"><p>Secret regenerated. Update the key in any tool still using the old value.</p></div>';
	} elseif ( 'delete' === $action && $key_id ) {
		$result = Hostlinks_Instructor_API::delete_key( $key_id );
		$notice = is_wp_error( $result )
			? '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>API key deleted.</p></div>';
	}
}

// ── Handle global test-mode toggle save ───────────────────────────────────
if ( isset( $_POST['hostlinks_save_api_test_mode'] ) ) {
	check_admin_referer( 'hostlinks_api_test_mode' );
	$new_mode = ! empty( $_POST['hostlinks_api_test_mode'] ) ? 1 : 0;
	update_option( 'hostlinks_api_test_mode', $new_mode );
	$test_mode = (bool) $new_mode;
	$notice    = $new_mode
		? '<div class="notice notice-warning is-dismissible"><p><strong>API Test Mode ON</strong> — all write endpoints will preview their payload without touching the database.</p></div>'
		: '<div class="notice notice-success is-dismissible"><p>API Test Mode disabled — write endpoints are live again.</p></div>';
}

if ( isset( $_GET['hl_key_regen'] ) && $notice === '' ) {
	$notice = '<div class="notice notice-warning is-dismissible"><p>API key regenerated. Update it in n8n and any other automation clients before your next run.</p></div>';
}

$api_keys = Hostlinks_Instructor_API::get_keys();
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Automation API</h2>
<p>These REST endpoints let external automation tools (n8n, Make, Zapier, email platforms, etc.) read and update Hostlinks event data without touching the WordPress admin. Every request must include a secret in the <code>X-HL-Key</code> HTTP header. Create a separate labeled key per tool, and grant only the endpoints that tool needs.</p>
<p style="color:#555;max-width:900px;">Works with <strong>WP Force Login</strong> and other “logged-in users only” REST restrictions: a valid <code>X-HL-Key</code> on <code>/wp-json/hostlinks/v1/*</code> is accepted before those plugins block the request. Endpoint scope and invalid keys are still enforced per route.</p>

<?php /* ── API Keys ───────────────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:24px 0 8px;">API Keys</h3>
<p style="color:#555;max-width:900px;margin-top:0;">Header name: <code>X-HL-Key</code>. The existing single key (if you had one) is migrated as <em>Legacy (all endpoints)</em> so current n8n workflows keep working.</p>

<?php if ( empty( $api_keys ) ) : ?>
	<p style="color:#d63638;"><em>No keys yet — create one below before calling the API.</em></p>
<?php endif; ?>

<?php foreach ( $api_keys as $k ) :
	$kid    = (string) ( $k['id'] ?? '' );
	$klabel = (string) ( $k['label'] ?? 'Untitled' );
	$ksec   = (string) ( $k['key'] ?? '' );
	$ksc    = (array) ( $k['scopes'] ?? array() );
	$created = ! empty( $k['created_at'] ) ? $k['created_at'] : '—';
	$used    = ! empty( $k['last_used_at'] ) ? $k['last_used_at'] : 'Never';
	$uid     = 'hl-key-' . esc_attr( $kid );
	?>
	<form method="post" style="max-width:900px;margin:0 0 16px;border:1px solid #c3c4c7;background:#fff;padding:14px 16px;">
		<?php wp_nonce_field( 'hostlinks_api_keys' ); ?>
		<input type="hidden" name="key_id" value="<?php echo esc_attr( $kid ); ?>">
		<div style="display:flex;flex-wrap:wrap;gap:12px 24px;align-items:flex-start;">
			<div style="flex:1;min-width:220px;">
				<label style="display:block;font-weight:600;margin-bottom:4px;">Label</label>
				<input type="text" name="key_label" value="<?php echo esc_attr( $klabel ); ?>"
				       class="regular-text" style="width:100%;max-width:320px;" placeholder="e.g. Email platform">
				<p style="margin:8px 0 4px;font-size:12px;color:#666;">Secret</p>
				<code id="<?php echo $uid; ?>" style="font-size:12px;background:#f0f0f1;padding:4px 8px;border-radius:3px;user-select:all;word-break:break-all;"><?php echo esc_html( $ksec ); ?></code>
				<button type="button" class="button button-small" style="margin-left:6px;"
				        onclick="navigator.clipboard.writeText(document.getElementById('<?php echo $uid; ?>').textContent).then(()=>{this.textContent='Copied!';}).catch(()=>{})">Copy</button>
				<p style="margin:8px 0 0;font-size:12px;color:#666;">Created <?php echo esc_html( $created ); ?> · Last used <?php echo esc_html( $used ); ?></p>
			</div>
			<div style="flex:1;min-width:260px;">
				<label style="display:block;font-weight:600;margin-bottom:6px;">Allowed endpoints</label>
				<?php foreach ( $catalog as $slug => $scope_label ) :
					$checked = in_array( '*', $ksc, true ) || in_array( $slug, $ksc, true );
					?>
					<label style="display:flex;align-items:flex-start;gap:6px;margin:0 0 4px;font-size:13px;cursor:pointer;">
						<input type="checkbox" name="key_scopes[]" value="<?php echo esc_attr( $slug ); ?>"
						       <?php checked( $checked ); ?>
						       <?php echo ( '*' === $slug ) ? 'data-hl-all="1"' : 'data-hl-scope="1"'; ?>>
						<span><?php echo esc_html( $scope_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<p style="margin:12px 0 0;">
			<button type="submit" name="hl_api_key_action" value="update" class="button button-secondary">Save label &amp; access</button>
			<button type="submit" name="hl_api_key_action" value="regenerate" class="button"
			        onclick="return confirm('This replaces the secret immediately. Any tool using the old value will stop working until you update it. Continue?');">Regenerate secret</button>
			<button type="submit" name="hl_api_key_action" value="delete" class="button"
			        style="color:#b32d2e;"
			        onclick="return confirm('Delete this API key? Tools using it will lose access immediately.');">Delete</button>
		</p>
	</form>
<?php endforeach; ?>

<form method="post" style="max-width:900px;margin:0 0 8px;border:1px dashed #c3c4c7;background:#f6f7f7;padding:14px 16px;">
	<?php wp_nonce_field( 'hostlinks_api_keys' ); ?>
	<h4 style="margin:0 0 8px;">Create a new key</h4>
	<p style="margin:0 0 10px;color:#555;font-size:13px;">Give it a label that matches the tool (e.g. “Email platform” or “n8n trainer assignment”) and tick only the endpoints that tool should call.</p>
	<p>
		<label style="font-weight:600;display:block;margin-bottom:4px;">Label</label>
		<input type="text" name="key_label" class="regular-text" placeholder="e.g. Email platform" required>
	</p>
	<p style="margin:0 0 6px;font-weight:600;">Allowed endpoints</p>
	<?php foreach ( $catalog as $slug => $scope_label ) : ?>
		<label style="display:flex;align-items:flex-start;gap:6px;margin:0 0 4px;font-size:13px;cursor:pointer;">
			<input type="checkbox" name="key_scopes[]" value="<?php echo esc_attr( $slug ); ?>"
			       <?php echo ( 'email-events' === $slug ) ? 'checked' : ''; ?>
			       <?php echo ( '*' === $slug ) ? 'data-hl-all="1"' : 'data-hl-scope="1"'; ?>>
			<span><?php echo esc_html( $scope_label ); ?></span>
		</label>
	<?php endforeach; ?>
	<p style="margin:12px 0 0;">
		<button type="submit" name="hl_api_key_action" value="create" class="button button-primary">Create key</button>
	</p>
</form>
<script>
(function(){
	document.querySelectorAll('form').forEach(function(form){
		var all = form.querySelector('input[data-hl-all="1"]');
		if (!all) return;
		var scopes = form.querySelectorAll('input[data-hl-scope="1"]');
		function sync(){
			scopes.forEach(function(cb){ cb.disabled = all.checked; if (all.checked) cb.checked = true; });
		}
		all.addEventListener('change', sync);
		sync();
	});
})();
</script>

<?php /* ── Global Test Mode toggle ─────────────────────────────────────── */ ?>
<hr style="margin:28px 0;" />

<h3 style="font-size:14px;margin:0 0 8px;">
	<?php if ( $test_mode ) : ?>
		<span style="display:inline-block;background:#d63638;color:#fff;font-size:11px;font-weight:700;padding:2px 7px;border-radius:3px;vertical-align:middle;margin-right:6px;letter-spacing:.04em;">TEST MODE ON</span>
	<?php endif; ?>
	API Test Mode (Global Dry Run)
</h3>
<p style="color:#555;max-width:700px;">
	When enabled, <strong>all write endpoints</strong> (<code>/assign-instructor</code>, <code>/create-event-request</code>) will return the payload they <em>would</em> write instead of touching the database — regardless of what the caller sends. Use this as a safety switch while building or debugging automation workflows.
	You can also trigger a single dry run per-request by including <code>"dry_run": true</code> in the JSON body without enabling this global toggle.
</p>

<form method="post">
	<?php wp_nonce_field( 'hostlinks_api_test_mode' ); ?>
	<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;font-weight:600;">
		<input type="checkbox" name="hostlinks_api_test_mode" value="1"
		       style="width:18px;height:18px;accent-color:#d63638;"
		       <?php checked( $test_mode ); ?>>
		Enable global API Test Mode (dry run for all write endpoints)
	</label>
	<p style="margin:10px 0 0;">
		<input type="hidden" name="hostlinks_save_api_test_mode" value="1">
		<button type="submit" class="button button-secondary">Save Test Mode Setting</button>
		<?php if ( $test_mode ) : ?>
			<span style="color:#d63638;font-size:12px;margin-left:10px;line-height:30px;font-weight:600;">&#x26A0; Currently active — no API writes will reach the database.</span>
		<?php endif; ?>
	</p>
</form>

<hr style="margin:28px 0;" />

<?php /* ── Endpoint Reference ──────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:0 0 8px;">Endpoint Reference</h3>
<p style="color:#555;margin-bottom:1rem;">Base URL: <code><?php echo esc_html( $base_url ); ?></code></p>

<table class="widefat striped" style="max-width:900px;margin-bottom:24px;">
	<thead>
		<tr>
			<th style="width:80px;">Method</th>
			<th style="width:240px;">Path</th>
			<th>Purpose</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><code>POST</code></td>
			<td><code>/assign-instructor</code></td>
			<td>Bulk-assign instructors to upcoming events by city name. Returns per-assignment status and a summary.</td>
		</tr>
		<tr>
			<td><code>POST</code></td>
			<td><code>/create-event-request</code></td>
			<td>Insert a pre-parsed host-info email into the New Event Queue. Accepts structured JSON (produced by n8n + AI) and creates one queue row per event. See Workflow B below.</td>
		</tr>
		<tr>
			<td><code>GET</code></td>
			<td><code>/upcoming-events</code></td>
			<td>List all upcoming events with their current instructor assignment. Useful for pre-flight checks and verification.</td>
		</tr>
		<tr>
			<td><code>GET</code></td>
			<td><code>/email-events</code></td>
			<td>Upcoming classes as merge-field JSON for email tools: location, dates, registration URL, marketer, instructor, type, and related links. See the section below.</td>
		</tr>
		<tr>
			<td><code>GET</code></td>
			<td><code>/instructors</code></td>
			<td>List all active instructors (id + name). Use to validate instructor names before posting assignments.</td>
		</tr>
	</tbody>
</table>

<?php /* ── GET /email-events detail ────────────────────────────────────────── */ ?>
<h4 style="margin:0 0 6px;">GET /email-events</h4>
<p style="color:#555;margin-bottom:8px;">Read-only feed of upcoming classes for email platforms and merge variables. Private / hidden classes are excluded by default. Host-contact, hotel, and shipping fields are omitted unless you pass <code>detail=full</code>.</p>

<table class="widefat striped" style="max-width:900px;margin-bottom:8px;">
	<tbody>
		<tr>
			<th style="width:160px;">URL</th>
			<td><code><?php echo esc_html( rest_url( 'hostlinks/v1/email-events' ) ); ?></code></td>
		</tr>
		<tr>
			<th>Request headers</th>
			<td><code>X-HL-Key: {key with email-events access}</code></td>
		</tr>
		<tr>
			<th>Query parameters</th>
			<td>
				<code>days</code> — only events starting within N days (omit for all upcoming)<br>
				<code>marketer</code> — marketer ID or exact name (e.g. <code>Nikki</code>)<br>
				<code>type</code> — type ID, full name, or abbreviation (e.g. <code>Management</code>)<br>
				<code>include_private=1</code> — include hidden and private-marketer classes<br>
				<code>id=1234</code> — single event by Hostlinks ID (bypasses date and private filters)<br>
				<code>detail=full</code> — add venue ops fields: host contacts, hotels, shipping, special instructions
			</td>
		</tr>
		<tr>
			<th>Example URLs</th>
			<td>
				<code>/email-events</code> — all upcoming public classes<br>
				<code>/email-events?days=90</code> — next 90 days<br>
				<code>/email-events?marketer=Nikki&amp;type=Writing</code> — Nikki’s writing classes<br>
				<code>/email-events?id=1234&amp;detail=full</code> — one event with full ops detail
			</td>
		</tr>
		<tr>
			<th>Response</th>
			<td>
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;">{
  "count": 1,
  "events": [
    {
      "id": 1234,
      "location": "Anaheim, CA",
      "city": "Anaheim",
      "state": "CA",
      "start": "2027-12-02",
      "end": "2027-12-03",
      "dates_display": "December 2–3, 2027",
      "type": "Grant Management USA",
      "type_abbr": "GM",
      "type_id": 2,
      "marketer": "Nikki",
      "marketer_email": "nikki@example.com",
      "instructor": "Ericka",
      "is_zoom": false,
      "zoom_time": "",
      "reg_url": "https://web.cvent.com/event/.../register",
      "web_url": "https://grantwritingusa.com/...",
      "host_url": "https://...",
      "email_url": "https://...",
      "roster_url": "https://...",
      "venue_name": "Anaheim Convention Center",
      "venue_address": "800 W Katella Ave, Anaheim, CA 92802",
      "host_name": "",
      "displayed_as": "",
      "custom_email_intro": "",
      "paid": 28,
      "free": 2,
      "is_private": false,
      "cvent_id": "97b0d6ae-...",
      "cvent_title": "Anaheim, CA - Grant Management USA"
    }
  ]
}</pre>
			</td>
		</tr>
		<tr>
			<th>Merge-field map</th>
			<td>
				Use these JSON keys as variables in your email tool:<br>
				<code>{{location}}</code> · <code>{{city}}</code> · <code>{{state}}</code> ·
				<code>{{start}}</code> · <code>{{end}}</code> · <code>{{dates_display}}</code> ·
				<code>{{type}}</code> · <code>{{marketer}}</code> · <code>{{instructor}}</code> ·
				<code>{{reg_url}}</code> · <code>{{web_url}}</code> · <code>{{email_url}}</code> ·
				<code>{{venue_name}}</code> · <code>{{venue_address}}</code> ·
				<code>{{zoom_time}}</code> · <code>{{custom_email_intro}}</code>
			</td>
		</tr>
		<tr>
			<th>Privacy</th>
			<td>
				Default list excludes: Hide from Public, location flagged <code>| PRIVATE</code>, and marketer named “Private”.<br>
				<code>detail=summary</code> (default) never returns host contacts, hotels, or shipping addresses.
			</td>
		</tr>
	</tbody>
</table>

<?php /* ── POST /assign-instructor detail ──────────────────────────────────── */ ?>
<h4 style="margin:0 0 6px;">POST /assign-instructor</h4>
<p style="color:#555;margin-bottom:8px;">Send a batch of city→instructor pairs. The API matches each city against upcoming events and updates the instructor field.</p>

<table class="widefat striped" style="max-width:900px;margin-bottom:8px;">
	<tbody>
		<tr>
			<th style="width:160px;">Request headers</th>
			<td><code>Content-Type: application/json</code><br><code>X-HL-Key: {your-secret-key}</code></td>
		</tr>
		<tr>
			<th>Request body</th>
			<td>
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;">{
  "assignments": [
    { "city": "Casper",      "instructor": "Sudie"  },
    { "city": "Crownsville", "instructor": "Ericka" },
    { "city": "Mays Landing","instructor": "Meredith"}
  ]
}</pre>
			</td>
		</tr>
		<tr>
			<th>Response</th>
			<td>
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;">{
  "results": [
    {
      "input_city":       "Casper",
      "input_instructor": "Sudie",
      "status":           "updated",
      "eve_id":           42,
      "eve_location":     "Casper, WY",
      "eve_start":        "2026-07-07",
      "instructor_id":    3,
      "instructor_name":  "Sudie",
      "fuzzy_match":      false,
      "warning":          null
    }
  ],
  "summary": {
    "total":       3,
    "updated":     2,
    "no_change":   0,
    "not_found":   1,
    "needs_review":0
  }
}</pre>
			</td>
		</tr>
		<tr>
			<th>Dry run</th>
			<td>Add <code>"dry_run": true</code> to the body to preview without writing. Per-item status will be <code>would_update</code> instead of <code>updated</code>. The response also includes a top-level <code>"dry_run": true</code> flag and <code>"notice"</code> string.</td>
		</tr>
		<tr>
			<th>Status values</th>
			<td>
				<code>updated</code> — matched and saved<br>
				<code>would_update</code> — dry run: would have updated<br>
				<code>no_change</code> — event already had this instructor<br>
				<code>not_found_event</code> — no upcoming event matched the city<br>
				<code>not_found_instructor</code> — instructor name not in the active list<br>
				<code>ambiguous_event</code> — multiple events on the same date matched
			</td>
		</tr>
		<tr>
			<th>Matching logic</th>
			<td>
				Cities are matched in order:<br>
				1. Exact substring — <code>eve_location LIKE '%Casper%'</code><br>
				2. Strip after comma — <code>"San Diego, Private"</code> → tries <code>"San Diego"</code><br>
				3. Fuzzy similarity (≥ 70 %) — catches typos like "Freno" → "Fresno". Flagged with <code>"fuzzy_match": true</code> and a warning.<br><br>
				If multiple upcoming events match, the nearest by start date is chosen. If two events share the same start date, the result is <code>ambiguous_event</code>.
			</td>
		</tr>
	</tbody>
</table>

<?php /* ── POST /create-event-request detail ──────────────────────────────── */ ?>
<h4 style="margin:24px 0 6px;">POST /create-event-request</h4>
<p style="color:#555;margin-bottom:8px;">Inserts a pre-parsed event email into the <strong>New Event Queue</strong> — exactly as if it were submitted through the front-end request form. Designed to be called by n8n after an AI node extracts the structured data from the raw email text.</p>

<table class="widefat striped" style="max-width:900px;margin-bottom:8px;">
	<tbody>
		<tr>
			<th style="width:160px;">Request headers</th>
			<td><code>Content-Type: application/json</code><br><code>X-HL-Key: {your-secret-key}</code></td>
		</tr>
		<tr>
			<th>Request body</th>
			<td>
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;">{
  "events": [
    {
      "category":   "Management",
      "start_date": "2026-08-19",
      "end_date":   "2026-08-20",
      "trainer":    "TBA",
      "is_zoom":    false,
      "timezone":   ""
    }
  ],
  "marketer":          "Nikki",
  "city":              "Billings",
  "state":             "MT",
  "zip_code":          "59101",
  "street_address_1":  "4810 Midland Road",
  "street_address_2":  "",
  "location_name":     "Billings Police Department",
  "host_name":         "",
  "displayed_as":      "",
  "special_instructions": "",
  "max_attendees":     null,
  "host_contacts": [
    {
      "name":   "Brad Mansur",
      "title":  "Administrative Sergeant",
      "agency": "Billings Police Department",
      "email":  "mansurb@billingsmt.gov",
      "phone":  "406-247-8557",
      "phone2": ""
    }
  ],
  "hotels": [
    {
      "name":    "Comfort Suites",
      "address": "4908 Southgate Dr, Billings, MT 59101",
      "phone":   "406-969-2300",
      "url":     ""
    },
    {
      "name":    "Double Tree by Hilton",
      "address": "27 N 27th St, Billings, MT 59101",
      "phone":   "406-252-7400",
      "url":     ""
    }
  ],
  "ship_name":      "Sgt. Brad Mansur",
  "ship_address_1": "220 North 27th Street",
  "ship_city":      "Billings",
  "ship_state":     "MT",
  "ship_zip":       "59101",
  "ship_phone":     "406-247-8557",
  "ship_notes":     "",
  "source":         "email-forward"
}</pre>
			</td>
		</tr>
		<tr>
			<th>Response (201 Created)</th>
			<td>
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;">{
  "status":           "created",
  "submission_group": "a1b2c3d4-...",
  "request_ids":      [47],
  "events_created":   1,
  "queue_url":        "https://yoursite.com/wp-admin/admin.php?page=hostlinks-event-requests"
}</pre>
			</td>
		</tr>
		<tr>
			<th>Dry run</th>
			<td>Add <code>"dry_run": true</code> to the body. The endpoint returns HTTP 200 with <code>"status": "would_insert"</code> and a <code>"would_insert"</code> array showing the exact rows that would have been written — nothing is saved to the database.</td>
		</tr>
		<tr>
			<th>category values</th>
			<td>Must be exactly one of: <code>Management</code>, <code>Writing</code>, <code>Subaward</code></td>
		</tr>
		<tr>
			<th>Date format</th>
			<td><code>YYYY-MM-DD</code> (e.g. <code>2026-08-19</code>)</td>
		</tr>
		<tr>
			<th>Multiple events</th>
			<td>Include multiple objects in the <code>events</code> array. Each creates a separate queue row sharing the same <code>submission_group</code> — they appear linked in the admin queue.</td>
		</tr>
	</tbody>
</table>

<hr style="margin:28px 0;" />

<?php /* ── n8n Setup Guide ─────────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:0 0 12px;">n8n Workflow Setup</h3>
<p style="color:#555;">These steps configure n8n at <strong>n8n.digitalsolution.com</strong> to watch for trainer-assignment emails and automatically update Hostlinks.</p>

<h4 style="margin:0 0 8px;">Workflow A — Trainer Assignment from Email</h4>
<ol style="line-height:2;color:#333;padding-left:1.4rem;max-width:800px;">
	<li>
		<strong>Store credentials in n8n</strong><br>
		In n8n → Credentials, create a new <em>Header Auth</em> credential:<br>
		<code>Name: X-HL-Key</code> &nbsp;|&nbsp; <code>Value: {the key labeled for this workflow}</code>
		<br><span style="color:#666;font-size:13px;">Use a key whose allowed endpoints include <code>POST /assign-instructor</code> (or All endpoints).</span>
	</li>
	<li>
		<strong>Gmail Trigger node</strong><br>
		Watch for emails from your trainer's email address, or filter by a subject keyword like <em>"instructors"</em> or <em>"trainers"</em>.
	</li>
	<li>
		<strong>Code node — parse email body</strong><br>
		Paste this JavaScript:
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;margin-top:6px;">const body = $input.first().json.text || $input.first().json.snippet || '';
const lines = body.split(/\r?\n/).map(l => l.trim()).filter(l => l && l.includes('-'));

const assignments = lines.map(line => {
  const parts = line.split(/\s*[-–]\s*/);
  return {
    city:       (parts[0] || '').trim(),
    instructor: (parts[1] || '').trim(),
  };
}).filter(a => a.city && a.instructor);

return [{ json: { assignments } }];</pre>
	</li>
	<li>
		<strong>HTTP Request node — POST to Hostlinks</strong><br>
		<ul style="margin:4px 0 4px 1rem;line-height:1.8;">
			<li>Method: <code>POST</code></li>
			<li>URL: <code><?php echo esc_html( rest_url( 'hostlinks/v1/assign-instructor' ) ); ?></code></li>
			<li>Authentication: <em>Header Auth</em> → select your credential</li>
			<li>Body Content Type: <em>JSON</em></li>
			<li>Body: <code>{{ $json }}</code> (passes the <code>{ assignments: [...] }</code> object)</li>
		</ul>
	</li>
	<li>
		<strong>Code node — build verification summary</strong><br>
		Paste this JavaScript:
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;margin-top:6px;">const data   = $input.first().json;
const results = data.results || [];
const summary = data.summary || {};

const icon = { updated:'✅', no_change:'⬜', not_found_event:'❌', not_found_instructor:'❌', ambiguous_event:'⚠️' };
const lines = results.map(r => {
  const loc  = r.eve_location ? ` (${r.eve_location} · ${r.eve_start})` : '';
  const warn = r.warning ? `\n   ⚠ ${r.warning}` : '';
  return `${icon[r.status] || '?'} ${r.input_city} → ${r.input_instructor}${loc}${warn}`;
});

const body = `Instructor Assignment Results\n${'─'.repeat(40)}\n${lines.join('\n')}\n\nSummary: ${summary.updated} updated · ${summary.not_found} not found · ${summary.needs_review} need review`;
return [{ json: { emailBody: body, subject: `Hostlinks: ${summary.updated}/${summary.total} instructors assigned` } }];</pre>
	</li>
	<li>
		<strong>Gmail node — send verification email</strong><br>
		Send to yourself (or reply to sender).<br>
		Subject: <code>{{ $json.subject }}</code> &nbsp;|&nbsp; Body: <code>{{ $json.emailBody }}</code>
	</li>
</ol>

<hr style="margin:28px 0;" />

<h4 style="margin:0 0 8px;">Workflow B — New Event from Forwarded Host Email</h4>
<p style="color:#555;margin-bottom:10px;">Forward a host info email to a Gmail address watched by n8n. An AI node (OpenAI) extracts all the structured data and POSTs it to Hostlinks, creating a new entry in the Event Request Queue ready to review and convert.</p>
<ol style="line-height:2;color:#333;padding-left:1.4rem;max-width:800px;">
	<li>
		<strong>Gmail Trigger node</strong><br>
		Watch a dedicated forwarding address (e.g. <em>hostlinks-queue@yourdomain.com</em>) or filter by label/subject keyword like "host info".
	</li>
	<li>
		<strong>OpenAI node — parse the email</strong><br>
		Model: <code>gpt-4o</code> (or Claude via HTTP). Prompt:
<pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;margin-top:6px;">You are parsing a workshop host-info email into structured JSON for a training event management system.

Extract the following and return ONLY valid JSON (no markdown, no explanation):

{
  "events": [
    {
      "category": "Management | Writing | Subaward",   // pick one
      "start_date": "YYYY-MM-DD",
      "end_date":   "YYYY-MM-DD",
      "trainer": "TBA",
      "is_zoom": false
    }
  ],
  "marketer": "first name only from 'This class will be NAME's'",
  "city": "",
  "state": "2-letter abbreviation",
  "zip_code": "",
  "street_address_1": "",
  "street_address_2": "",
  "location_name": "building/organization name",
  "host_name": "",
  "displayed_as": "",
  "special_instructions": "",
  "max_attendees": null,
  "host_contacts": [
    { "name":"", "title":"", "agency":"", "email":"", "phone":"", "phone2":"" }
  ],
  "hotels": [
    { "name":"", "address":"single line", "phone":"", "url":"" }
  ],
  "ship_name": "name after 'Att:'",
  "ship_address_1": "",
  "ship_address_2": "",
  "ship_city": "",
  "ship_state": "",
  "ship_zip": "",
  "ship_phone": "",
  "ship_notes": "",
  "source": "email-forward"
}

Rules:
- category: "Grant Management Workshop" → "Management", "Grant Writing Workshop" → "Writing", "Subaward" → "Subaward"
- Dates in "Month Day-Day, Year" → convert to YYYY-MM-DD (start = first day, end = last day)
- marketer: extract the first name from "This class will be Nikki's" → "Nikki". If ZOOM, use "Zoom".
- Hotels: each named hotel is a separate entry in the hotels array.
- Shipping: the "Att:" block at the bottom is the shipping address.
- If a field is absent, use "" or null as appropriate.
- Return ONLY the JSON object, nothing else.

Email text:
{{ $json.text }}</pre>
	</li>
	<li>
		<strong>HTTP Request node — POST to Hostlinks</strong><br>
		<ul style="margin:4px 0 4px 1rem;line-height:1.8;">
			<li>Method: <code>POST</code></li>
			<li>URL: <code><?php echo esc_html( rest_url( 'hostlinks/v1/create-event-request' ) ); ?></code></li>
			<li>Authentication: <em>Header Auth</em> → select your credential</li>
			<li>Body Content Type: <em>JSON</em></li>
			<li>Body: parse the OpenAI response text as JSON — use n8n's <em>JSON Parse</em> node or <code>JSON.parse({{ $json.message.content }})</code></li>
		</ul>
	</li>
	<li>
		<strong>Gmail node — send confirmation</strong><br>
		Subject: <code>✅ Event queued: {{ $json.events_created }} event(s) added to Hostlinks queue</code><br>
		Body: Include <code>{{ $json.queue_url }}</code> so you can click straight to the admin queue to review.
	</li>
</ol>

<hr style="margin:28px 0;" />

<?php /* ── Test with curl ──────────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:0 0 8px;">Quick Test (curl)</h3>
<p style="color:#555;margin-bottom:8px;">Verify the endpoints are live. Replace <code>YOUR-KEY-HERE</code> with a key from the table above that is allowed to call that endpoint. A key scoped only to <code>/email-events</code> will get HTTP 403 on write endpoints.</p>
<p style="font-weight:600;margin:12px 0 4px;">Trainer assignment:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s -X POST \
  "<?php echo esc_html( rest_url( 'hostlinks/v1/assign-instructor' ) ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-HL-Key: YOUR-KEY-HERE" \
  -d '{"assignments":[{"city":"Casper","instructor":"Sudie"}]}' | python3 -m json.tool</pre>
<p style="font-weight:600;margin:12px 0 4px;">Create event request (minimal test):</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s -X POST \
  "<?php echo esc_html( rest_url( 'hostlinks/v1/create-event-request' ) ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-HL-Key: YOUR-KEY-HERE" \
  -d '{
    "events":[{"category":"Management","start_date":"2026-08-19","end_date":"2026-08-20","trainer":"TBA","is_zoom":false}],
    "marketer":"Nikki","city":"Billings","state":"MT","zip_code":"59101",
    "street_address_1":"4810 Midland Road","location_name":"Billings Police Department",
    "host_contacts":[{"name":"Brad Mansur","title":"Administrative Sergeant","agency":"Billings PD","email":"mansurb@billingsmt.gov","phone":"406-247-8557"}],
    "hotels":[{"name":"Comfort Suites","address":"4908 Southgate Dr, Billings MT 59101","phone":"406-969-2300"}],
    "ship_name":"Sgt. Brad Mansur","ship_address_1":"220 North 27th Street","ship_city":"Billings","ship_state":"MT","ship_zip":"59101","ship_phone":"406-247-8557",
    "source":"email-forward"
  }' | python3 -m json.tool</pre>

<p style="color:#555;margin-top:8px;">List upcoming classes for email merge fields:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s "<?php echo esc_html( rest_url( 'hostlinks/v1/email-events' ) ); ?>?days=90" \
  -H "X-HL-Key: YOUR-KEY-HERE" | python3 -m json.tool</pre>

<p style="color:#555;margin-top:8px;">List all instructors:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s "<?php echo esc_html( rest_url( 'hostlinks/v1/instructors' ) ); ?>" \
  -H "X-HL-Key: YOUR-KEY-HERE" | python3 -m json.tool</pre>
