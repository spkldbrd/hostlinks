<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Handle Page URL form submissions ──────────────────────────────────────────
$page_url_notice = '';
if ( isset( $_POST['hostlinks_save_page_urls'] ) ) {
	check_admin_referer( 'hostlinks_page_urls' );
	if ( current_user_can( 'manage_options' ) ) {
		Hostlinks_Page_URLs::save_overrides(
			$_POST['hl_url_upcoming']    ?? '',
			$_POST['hl_url_past_events'] ?? '',
			$_POST['hl_url_reports']     ?? ''
		);
		$page_url_notice = '<div class="notice notice-success is-dismissible"><p>Page URLs saved. URL cache cleared.</p></div>';
	}
}

if ( isset( $_POST['hostlinks_clear_url_cache'] ) ) {
	check_admin_referer( 'hostlinks_page_urls' );
	if ( current_user_can( 'manage_options' ) ) {
		Hostlinks_Page_URLs::clear_cache();
		$page_url_notice = '<div class="notice notice-success is-dismissible"><p>URL cache cleared — auto-detection will re-run on the next frontend page load.</p></div>';
	}
}

/** @var Hostlinks_Updater $updater */
$updater         = Hostlinks_Updater::instance();
$just_checked    = isset( $_GET['hl_checked'] ) && $_GET['hl_checked'] === '1';
$current_version = HOSTLINKS_VERSION;

// If the user just clicked "Check for Updates", fetch a fresh release.
// Otherwise use the cached value (or fetch if not yet cached).
$release         = $just_checked ? $updater->fetch_fresh_release() : $updater->get_latest_release();
$latest_version  = $release ? ltrim( $release->tag_name, 'vV' ) : null;
$update_available = $latest_version && version_compare( $latest_version, $current_version, '>' );

$check_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=hostlinks_force_check' ),
	'hostlinks_force_check'
);

$update_url = admin_url( 'update-core.php' );
$github_url = 'https://github.com/' . HOSTLINKS_GITHUB_USER . '/' . HOSTLINKS_GITHUB_REPO;
?>
<div class="wrap">
	<h1>Hostlinks — Plugin Info</h1>

	<?php echo $page_url_notice; ?>

	<?php if ( $just_checked ) : ?>
		<div class="notice notice-info is-dismissible">
			<p>Update check complete. Results shown below.</p>
		</div>
	<?php endif; ?>

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

	<p style="margin-top:20px;">
		<a href="<?php echo esc_url( $check_url ); ?>" class="button button-primary">
			&#8635;&nbsp; Check for Updates Now
		</a>

		<?php if ( $update_available ) : ?>
			&nbsp;
			<a href="<?php echo esc_url( $update_url ); ?>" class="button button-secondary" style="color:#d63638;border-color:#d63638;">
				Go to Updates &rarr;
			</a>
		<?php endif; ?>
	</p>

	<p style="color:#999;font-size:12px;">
		GitHub releases are cached for 12 hours. Use "Check for Updates Now" to bypass the cache and get the latest result immediately.
	</p>

	<?php /* ── Shortcode Reference ─────────────────────────────────────── */ ?>
	<h2 style="margin-top:2rem;">Shortcode Reference</h2>
	<p style="color:#555;margin-bottom:1rem;">Add these shortcodes to any WordPress page. All shortcodes require the visitor to be logged in.</p>

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
		</tbody>
	</table>

	<h3 style="margin-top:1.5rem;">Tips</h3>
	<ul style="list-style:disc;padding-left:1.5rem;color:#444;line-height:1.8;">
		<li>Each shortcode page must be set to <strong>private or login-required</strong> — the shortcode itself redirects guests to the home page.</li>
		<li>The <strong>Focus dropdown</strong> only lists <em>active</em> marketers. Mark a marketer Inactive under <em>Hostlinks → Marketers</em> to remove them from the dropdown while keeping their historical events intact.</li>
		<li>The <strong>Updated date</strong> shown on the Upcoming Events page reflects the last CVENT sync (manual or automatic). It updates when any sync saves at least one event.</li>
	</ul>

	<hr />
	<h2>Page Link Settings</h2>
	<p>The three navigation buttons (Upcoming Events, Past Events, Reports) on the frontend calendar resolve their URLs in this order:</p>
	<ol style="padding-left:1.5rem;color:#444;line-height:1.8;margin-bottom:1rem;">
		<li><strong>Manual override</strong> — URL entered in the fields below.</li>
		<li><strong>Auto-detect</strong> — searches all published pages for the shortcode tag (cached 24 h).</li>
		<li><strong>Default</strong> — built-in fallback path (<code>/</code> for Upcoming, <code>/old-event-list/</code> for Past Events, hidden for Reports).</li>
	</ol>
	<p>Leave override fields blank to let auto-detection do the work. Only fill them in if your pages use a URL that auto-detection can't find (e.g. the shortcode is inside a block).</p>

	<?php
	$det       = Hostlinks_Page_URLs::detection_status();
	$overrides = Hostlinks_Page_URLs::get_overrides();
	$source_labels = array(
		'override' => '<span style="color:#0a6cbc;font-weight:600;">&#9679; Override</span>',
		'auto'     => '<span style="color:#00a32a;font-weight:600;">&#9679; Auto-detected</span>',
		'default'  => '<span style="color:#888;font-weight:600;">&#9679; Default fallback</span>',
		'none'     => '<span style="color:#d63638;font-weight:600;">&#9679; Not found</span>',
	);
	$page_labels = array(
		'upcoming'    => 'Upcoming Events <code>[eventlisto]</code>',
		'past_events' => 'Past Events <code>[oldeventlisto]</code>',
		'reports'     => 'Reports <code>[hostlinks_reports]</code>',
	);
	?>

	<table class="widefat striped" style="max-width:780px;margin-bottom:16px;">
		<thead>
			<tr>
				<th style="width:220px;">Page</th>
				<th style="width:140px;">Source</th>
				<th>Resolved URL</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $det as $key => $info ) : ?>
			<tr>
				<td><?php echo $page_labels[ $key ]; ?></td>
				<td><?php echo $source_labels[ $info['source'] ]; ?></td>
				<td>
					<?php if ( $info['url'] ) : ?>
						<a href="<?php echo esc_url( $info['url'] ); ?>" target="_blank"><?php echo esc_html( $info['url'] ); ?></a>
					<?php else : ?>
						<em style="color:#d63638;">None — button will be hidden</em>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<form method="post">
		<?php wp_nonce_field( 'hostlinks_page_urls' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hl_url_upcoming">Upcoming Events URL override</label></th>
				<td>
					<input type="url" id="hl_url_upcoming" name="hl_url_upcoming"
						value="<?php echo esc_attr( $overrides['upcoming'] ); ?>"
						class="regular-text" placeholder="Leave blank to auto-detect" />
					<p class="description">Page containing <code>[eventlisto]</code>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hl_url_past_events">Past Events URL override</label></th>
				<td>
					<input type="url" id="hl_url_past_events" name="hl_url_past_events"
						value="<?php echo esc_attr( $overrides['past_events'] ); ?>"
						class="regular-text" placeholder="Leave blank to auto-detect" />
					<p class="description">Page containing <code>[oldeventlisto]</code>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hl_url_reports">Reports URL override</label></th>
				<td>
					<input type="url" id="hl_url_reports" name="hl_url_reports"
						value="<?php echo esc_attr( $overrides['reports'] ); ?>"
						class="regular-text" placeholder="Leave blank to auto-detect" />
					<p class="description">Page containing <code>[hostlinks_reports]</code>. Leave blank to hide the Reports button until the page is published.</p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" name="hostlinks_save_page_urls" class="button button-primary">Save Page URLs</button>
			&nbsp;
			<button type="submit" name="hostlinks_clear_url_cache" class="button button-secondary">Clear URL Cache</button>
			<span style="margin-left:12px;color:#666;line-height:30px;font-size:12px;">Cache clears automatically when you save. Use "Clear URL Cache" after moving a page to a new URL.</span>
		</p>
	</form>
</div>
