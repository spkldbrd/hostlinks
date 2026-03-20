<?php
/**
 * Settings → General tab.
 *
 * Contains: Page URL overrides for the four frontend shortcode pages.
 * Included from admin/settings.php with $hl_embedded = true.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

// ── Save Google Maps API key ──────────────────────────────────────────────────
$maps_notice = '';
if ( isset( $_POST['hostlinks_save_maps_key'] ) ) {
	check_admin_referer( 'hostlinks_maps_key' );
	$key = sanitize_text_field( wp_unslash( $_POST['hostlinks_google_maps_api_key'] ?? '' ) );
	update_option( 'hostlinks_google_maps_api_key', $key );
	$maps_notice = '<div class="notice notice-success is-dismissible"><p>Google Maps API key saved.</p></div>';
}
$maps_api_key = get_option( 'hostlinks_google_maps_api_key', '' );

// ── Save / clear URL cache ────────────────────────────────────────────────────
$page_url_notice = '';
if ( isset( $_POST['hostlinks_save_page_urls'] ) ) {
	check_admin_referer( 'hostlinks_page_urls' );
	Hostlinks_Page_URLs::save_overrides(
		$_POST['hl_url_upcoming']             ?? '',
		$_POST['hl_url_past_events']          ?? '',
		$_POST['hl_url_reports']              ?? '',
		$_POST['hl_url_public_event_list']    ?? '',
		$_POST['hl_url_roster']               ?? '',
		$_POST['hl_url_event_request_form']   ?? ''
	);
	$page_url_notice = '<div class="notice notice-success is-dismissible"><p>Page URLs saved. URL cache cleared.</p></div>';
}

if ( isset( $_POST['hostlinks_clear_url_cache'] ) ) {
	check_admin_referer( 'hostlinks_page_urls' );
	Hostlinks_Page_URLs::clear_cache();
	$page_url_notice = '<div class="notice notice-success is-dismissible"><p>URL cache cleared — auto-detection will re-run on the next frontend page load.</p></div>';
}

$det       = Hostlinks_Page_URLs::detection_status();
$overrides = Hostlinks_Page_URLs::get_overrides();

$source_labels = array(
	'override' => '<span style="color:#0a6cbc;font-weight:600;">&#9679; Override</span>',
	'auto'     => '<span style="color:#00a32a;font-weight:600;">&#9679; Auto-detected</span>',
	'default'  => '<span style="color:#888;font-weight:600;">&#9679; Default fallback</span>',
	'none'     => '<span style="color:#d63638;font-weight:600;">&#9679; Not found</span>',
);
$page_labels = array(
	'upcoming'           => 'Upcoming Events <code>[eventlisto]</code>',
	'past_events'        => 'Past Events <code>[oldeventlisto]</code>',
	'reports'            => 'Reports <code>[hostlinks_reports]</code>',
	'public_event_list'  => 'Public Event List <code>[public_event_list]</code>',
	'roster'             => 'Roster <code>[hostlinks_roster]</code>',
	'event_request_form' => 'Event Request Form <code>[hostlinks_event_request_form]</code>',
);
?>
<?php echo $maps_notice; ?>
<?php echo $page_url_notice; ?>

<h2 style="margin-top:0;">Google Maps API</h2>
<p>Used for address autocomplete on the Event Request form. Requires the <strong>Maps JavaScript API</strong> and <strong>Places API (New)</strong> enabled in Google Cloud Console.</p>
<form method="post">
	<?php wp_nonce_field( 'hostlinks_maps_key' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hostlinks_google_maps_api_key">Google Maps API Key</label></th>
			<td>
				<input type="text" id="hostlinks_google_maps_api_key" name="hostlinks_google_maps_api_key"
					value="<?php echo esc_attr( $maps_api_key ); ?>"
					class="regular-text" placeholder="AIza..." autocomplete="off" />
				<p class="description">
					Restrict this key to your domain(s) in Google Cloud Console → Credentials → HTTP referrers.<br>
					Enable: Maps JavaScript API, Places API (New), Geocoding API, Directions API, Maps Embed API.
				</p>
				<?php if ( $maps_api_key ) : ?>
					<p class="description" style="color:#00a32a;margin-top:4px;">&#9679; Key is set.</p>
				<?php else : ?>
					<p class="description" style="color:#d63638;margin-top:4px;">&#9679; No key set — address autocomplete is disabled.</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hostlinks_save_maps_key" class="button button-primary">Save API Key</button>
	</p>
</form>

<hr style="margin:24px 0;" />

<h2 style="margin-top:0;">Page Link Settings</h2>
<p>The frontend shortcode pages resolve their URLs in this order:</p>
<ol style="padding-left:1.5rem;color:#444;line-height:1.8;margin-bottom:1rem;">
	<li><strong>Manual override</strong> — URL entered in the fields below.</li>
	<li><strong>Auto-detect</strong> — searches all published pages for the shortcode tag (cached 24 h).</li>
	<li><strong>Default</strong> — built-in fallback path (<code>/</code> for Upcoming, <code>/old-event-list/</code> for Past Events, hidden for Reports).</li>
</ol>
<p>Leave override fields blank to let auto-detection do the work. Only fill them in if your pages use a URL that auto-detection cannot find (e.g. the shortcode is inside a block).</p>

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
		<tr>
			<th scope="row"><label for="hl_url_public_event_list">Public Event List URL override</label></th>
			<td>
				<input type="url" id="hl_url_public_event_list" name="hl_url_public_event_list"
					value="<?php echo esc_attr( $overrides['public_event_list'] ?? '' ); ?>"
					class="regular-text" placeholder="Leave blank to auto-detect" />
				<p class="description">Page containing <code>[public_event_list]</code>.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl_url_roster">Roster URL override</label></th>
			<td>
				<input type="url" id="hl_url_roster" name="hl_url_roster"
					value="<?php echo esc_attr( $overrides['roster'] ?? '' ); ?>"
					class="regular-text" placeholder="Leave blank to auto-detect" />
				<p class="description">Page containing <code>[hostlinks_roster]</code>. This URL is auto-populated into new events when their Roster URL field is left blank.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl_url_event_request_form">Event Request Form URL override</label></th>
			<td>
				<input type="url" id="hl_url_event_request_form" name="hl_url_event_request_form"
					value="<?php echo esc_attr( $overrides['event_request_form'] ?? '' ); ?>"
					class="regular-text" placeholder="Leave blank to auto-detect" />
				<p class="description">Page containing <code>[hostlinks_event_request_form]</code>. Used for the optional "+ Event" button on the calendar.</p>
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
