<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Hostlinks_Updater $updater */
$updater         = Hostlinks_Updater::instance();
$current_version = HOSTLINKS_VERSION;

// Always fetch a fresh release on every page load (admin-only, infrequent).
$release          = $updater->fetch_fresh_release();
$latest_version   = $release ? ltrim( $release->tag_name, 'vV' ) : null;
$update_available = $latest_version && version_compare( $latest_version, $current_version, '>' );

$update_url = admin_url( 'update-core.php' );
$github_url = 'https://github.com/' . HOSTLINKS_GITHUB_USER . '/' . HOSTLINKS_GITHUB_REPO;
?>
<div class="wrap">
	<h1>Hostlinks — Plugin Info</h1>

	<table class="widefat striped" style="max-width:680px;margin-top:20px;">
		<tbody>
			<tr>
				<th style="width:200px;">Plugin Name</th>
				<td>Hostlinks</td>
			</tr>
			<tr>
				<th>Author</th>
				<td>Digital Solution</td>
			</tr>
			<tr>
				<th>Installed Version</th>
				<td><strong><?php echo esc_html( $current_version ); ?></strong></td>
			</tr>
			<tr>
				<th>Latest Version</th>
				<td>
					<?php if ( $latest_version ) : ?>
						<strong><?php echo esc_html( $latest_version ); ?></strong>
						<?php if ( $update_available ) : ?>
							&nbsp;<span style="color:#d63638;font-weight:600;">&#9650; Update available</span>
						<?php else : ?>
							&nbsp;<span style="color:#00a32a;font-weight:600;">&#10003; Up to date</span>
						<?php endif; ?>
					<?php else : ?>
						<span style="color:#999;">Unable to reach GitHub — check server connectivity.</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>GitHub Repository</th>
				<td><a href="<?php echo esc_url( $github_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $github_url ); ?></a></td>
			</tr>
			<?php if ( $release ) : ?>
			<tr>
				<th>Release Notes</th>
				<td>
					<?php
					$notes = trim( $release->body ?? '' );
					echo $notes ? nl2br( esc_html( $notes ) ) : '<em style="color:#999;">No release notes provided.</em>';
					?>
				</td>
			</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $update_available ) : ?>
	<p style="margin-top:20px;">
		<a href="<?php echo esc_url( $update_url ); ?>" class="button button-primary" style="color:#fff;background:#d63638;border-color:#c32d2e;">
			Go to Updates &rarr;
		</a>
	</p>
	<?php endif; ?>

	<p style="color:#999;font-size:12px;margin-top:12px;">
		The latest GitHub release is checked live each time this page loads.
	</p>

	<?php /* ── Shortcode Reference ─────────────────────────────────────── */ ?>
	<h2 style="margin-top:2rem;">Shortcode Reference</h2>
	<p style="color:#555;margin-bottom:1rem;">Add these shortcodes to any WordPress page. All shortcodes respect the access settings configured under <strong>Hostlinks → Settings → User Access</strong>.</p>

	<table class="widefat striped" style="max-width:900px;">
		<thead>
			<tr>
				<th style="width:220px;">Shortcode</th>
				<th style="width:200px;">Page / Purpose</th>
				<th>Description</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>[eventlisto]</code></td>
				<td><strong>Upcoming Events</strong></td>
				<td>
					Displays the main calendar card grid of all upcoming events (current month forward).
					Includes a marketer <strong>Focus</strong> dropdown to filter to a single marketer's events,
					a <em>Past Events</em> nav link, a <em>Reports</em> nav link (auto-detected once that page is published),
					and the last-synced date below the Upcoming Events button.
					<br><small style="color:#888;">URL parameter: <code>?focus=MARKETER_ID</code></small>
				</td>
			</tr>
			<tr>
				<td><code>[oldeventlisto]</code></td>
				<td><strong>Past Events</strong></td>
				<td>
					Displays past events in the same card grid layout, filtered by year.
					Includes a year dropdown (2022 → next year), a marketer <strong>Focus</strong> dropdown,
					and nav links back to Upcoming Events and Reports.
					<br><small style="color:#888;">URL parameters: <code>?syear=2025</code> &nbsp;|&nbsp; <code>?focus=MARKETER_ID</code></small>
				</td>
			</tr>
			<tr>
				<td><code>[hostlinks_reports]</code></td>
				<td><strong>Reports / Marketer Performance</strong></td>
				<td>
					Month-over-month marketer performance dashboard powered by Chart.js.
					Shows 4 summary stat cards (total registrations, paid, event count, avg per event),
					a multi-line trend chart with toggles for <em>Registrations / Paid Only / Events / Top 5</em>,
					and a sortable marketer summary table.
					Date range selector: 6 Months, 1 Year (default), 2 Years.
					Once published, a <em>Reports</em> button automatically appears in the Upcoming and Past Events nav bars.
					<br><small style="color:#888;">URL parameter: <code>?months=6|12|24</code></small>
				</td>
			</tr>
			<tr>
				<td><code>[public_event_list]</code></td>
				<td><strong>Public Event List</strong></td>
				<td>
					A simplified, publicly accessible listing of upcoming events — no login or access control required.
					Suitable for embedding on a public-facing page where you want to show event dates and locations
					without exposing the full calendar.
				</td>
			</tr>
			<tr>
				<td><code>[hostlinks_roster]</code></td>
				<td><strong>Event Roster</strong></td>
				<td>
					Displays a print-ready attendee roster for a specific event linked to a CVENT ID.
					Loads via AJAX with a loading spinner. Admins see a <em>Refresh Roster</em> button to bypass the cache.
					A <em>Print</em> button triggers landscape layout with the company logo (set under <strong>Settings → Roster</strong>).
					Optional Email and Phone columns can be toggled on for admins.
					<br><small style="color:#888;">URL parameter: <code>?eve_id=EVENT_ID</code> — auto-populated on new events when the Roster URL field is left blank.</small>
				</td>
			</tr>
			<tr>
				<td><code>[hostlinks_event_request_form]</code></td>
				<td><strong>Event Request Form</strong></td>
				<td>
					Renders the front-end event intake form. Visitors can submit structured event requests
					including dates, venue details, hotel recommendations, and host contacts.
					Submissions are stored in a separate <em>Event Requests</em> table and reviewed under
					<strong>Hostlinks → Event Requests</strong> — they are not published immediately.
					<br><small style="color:#888;">Configure notification email and success message under <strong>Settings → Build Request Form</strong>.</small>
				</td>
			</tr>
		</tbody>
	</table>

	<h3 style="margin-top:1.5rem;">Tips</h3>
	<ul style="list-style:disc;padding-left:1.5rem;color:#444;line-height:1.8;">
		<li>Each shortcode page should be protected — access mode is configured per-shortcode under <strong>Hostlinks → Settings → User Access</strong>.</li>
		<li>The <strong>Focus dropdown</strong> only lists <em>active</em> marketers. Mark a marketer Inactive under <em>Hostlinks → Marketers</em> to remove them from the dropdown while keeping their historical events intact.</li>
		<li>The <strong>Updated date</strong> shown on the Upcoming Events page reflects the last CVENT sync (manual or automatic). It updates when any sync saves at least one event.</li>
		<li>Page URL overrides for the nav buttons (Upcoming Events, Past Events, Reports) are configured under <strong>Hostlinks → Settings → General</strong>.</li>
	</ul>

</div>
