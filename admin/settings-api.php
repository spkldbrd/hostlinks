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

<hr style="margin:28px 0;" />

<?php /* ── n8n Setup Guide ─────────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:0 0 12px;">n8n Workflow Setup</h3>
<p style="color:#555;">These steps configure n8n at <strong>n8n.digitalsolution.com</strong> to watch for trainer-assignment emails and automatically update Hostlinks.</p>

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

<?php /* ── Test with curl ──────────────────────────────────────────────────── */ ?>
<h3 style="font-size:14px;margin:0 0 8px;">Quick Test (curl)</h3>
<p style="color:#555;margin-bottom:8px;">Run this from any terminal to verify the endpoint is live and your key works:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s -X POST \
  "<?php echo esc_html( rest_url( 'hostlinks/v1/assign-instructor' ) ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-HL-Key: <?php echo $api_key ? esc_html( $api_key ) : 'YOUR-KEY-HERE'; ?>" \
  -d '{"assignments":[{"city":"Casper","instructor":"Sudie"}]}' | python3 -m json.tool</pre>

<p style="color:#555;margin-top:8px;">List all instructors:</p>
<pre style="background:#f6f7f7;padding:12px 16px;border-radius:4px;font-size:12px;overflow-x:auto;max-width:900px;">curl -s "<?php echo esc_html( rest_url( 'hostlinks/v1/instructors' ) ); ?>" \
  -H "X-HL-Key: <?php echo $api_key ? esc_html( $api_key ) : 'YOUR-KEY-HERE'; ?>" | python3 -m json.tool</pre>
