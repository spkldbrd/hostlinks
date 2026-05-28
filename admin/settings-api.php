<?php
/**
 * Settings → Automation API tab.
 *
 * Manages the secret API key used by n8n (or any HTTP client) to call
 * the Hostlinks REST API endpoints under /wp-json/hostlinks/v1/.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$notice   = '';
$api_key  = get_option( 'hostlinks_automation_api_key', '' );
$base_url = rest_url( 'hostlinks/v1' );

if ( isset( $_GET['hl_key_regen'] ) ) {
	$api_key = get_option( 'hostlinks_automation_api_key', '' ); // re-read after regeneration
	$notice  = '<div class="notice notice-success is-dismissible"><p>API key regenerated. Update it in n8n and any other automation clients before your next run.</p></div>';
}
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Automation API</h2>
<p>These REST endpoints let external automation tools (n8n, Make, Zapier, etc.) read and update Hostlinks event data without touching the WordPress admin. Every request must include the secret key in the <code>X-HL-Key</code> HTTP header.</p>

<?php /* ── API Key ────────────────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:24px 0 8px;">Secret API Key</h3>
<table class="widefat striped" style="max-width:700px;margin-bottom:14px;">
	<tbody>
		<tr>
			<th style="width:180px;">Current key</th>
			<td>
				<?php if ( $api_key ) : ?>
					<code id="hl-api-key-display" style="font-size:13px;background:#f0f0f1;padding:4px 8px;border-radius:3px;user-select:all;"><?php echo esc_html( $api_key ); ?></code>
					&nbsp;
					<button type="button" class="button button-small"
					        onclick="navigator.clipboard.writeText('<?php echo esc_js( $api_key ); ?>').then(()=>this.textContent='Copied!').catch(()=>{})">
						Copy
					</button>
				<?php else : ?>
					<em style="color:#d63638;">No key set — generate one below before using the API.</em>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th>Header name</th>
			<td><code>X-HL-Key</code></td>
		</tr>
	</tbody>
</table>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'hostlinks_regenerate_api_key' ); ?>
	<input type="hidden" name="action" value="hostlinks_regenerate_api_key">
	<button type="submit" class="button button-primary"
	        onclick="return confirm('This will invalidate the current key immediately. Any automation using the old key will stop working until you update it. Continue?');">
		<?php echo $api_key ? '&#x21BA; Regenerate Key' : '&#x2B; Generate Key'; ?>
	</button>
	<?php if ( $api_key ) : ?>
		<span style="color:#666;font-size:12px;margin-left:10px;line-height:30px;">Regenerating invalidates the current key immediately.</span>
	<?php endif; ?>
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
			<td><code>/instructors</code></td>
			<td>List all active instructors (id + name). Use to validate instructor names before posting assignments.</td>
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
			<th>Status values</th>
			<td>
				<code>updated</code> — matched and saved<br>
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
		<code>Name: X-HL-Key</code> &nbsp;|&nbsp; <code>Value: {your key above}</code>
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
<p style="color:#555;margin-bottom:8px;">Verify the endpoints are live and your key works:</p>
<p style="font-weight:600;margin:12px 0 4px;">Trainer assignment:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s -X POST \
  "<?php echo esc_html( rest_url( 'hostlinks/v1/assign-instructor' ) ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-HL-Key: <?php echo $api_key ? esc_html( $api_key ) : 'YOUR-KEY-HERE'; ?>" \
  -d '{"assignments":[{"city":"Casper","instructor":"Sudie"}]}' | python3 -m json.tool</pre>
<p style="font-weight:600;margin:12px 0 4px;">Create event request (minimal test):</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s -X POST \
  "<?php echo esc_html( rest_url( 'hostlinks/v1/create-event-request' ) ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-HL-Key: <?php echo $api_key ? esc_html( $api_key ) : 'YOUR-KEY-HERE'; ?>" \
  -d '{
    "events":[{"category":"Management","start_date":"2026-08-19","end_date":"2026-08-20","trainer":"TBA","is_zoom":false}],
    "marketer":"Nikki","city":"Billings","state":"MT","zip_code":"59101",
    "street_address_1":"4810 Midland Road","location_name":"Billings Police Department",
    "host_contacts":[{"name":"Brad Mansur","title":"Administrative Sergeant","agency":"Billings PD","email":"mansurb@billingsmt.gov","phone":"406-247-8557"}],
    "hotels":[{"name":"Comfort Suites","address":"4908 Southgate Dr, Billings MT 59101","phone":"406-969-2300"}],
    "ship_name":"Sgt. Brad Mansur","ship_address_1":"220 North 27th Street","ship_city":"Billings","ship_state":"MT","ship_zip":"59101","ship_phone":"406-247-8557",
    "source":"email-forward"
  }' | python3 -m json.tool</pre>

<p style="color:#555;margin-top:8px;">List all instructors:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s "<?php echo esc_html( rest_url( 'hostlinks/v1/instructors' ) ); ?>" \
  -H "X-HL-Key: <?php echo $api_key ? esc_html( $api_key ) : 'YOUR-KEY-HERE'; ?>" | python3 -m json.tool</pre>
