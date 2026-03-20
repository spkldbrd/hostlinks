<?php
/**
 * [hostlinks_roster] shortcode template.
 *
 * Reads ?eve_id=N from the URL, fetches the roster from CVENT (same
 * cache as the admin roster page), and renders a print-ready table.
 *
 * Access is controlled by Hostlinks_Access::can_view_shortcode('hostlinks_roster').
 * Defaults to 'approved_viewers' — configure in Hostlinks → Settings → User Access.
 *
 * Included via ob_start() from Hostlinks_Shortcodes::render_roster().
 * $wpdb is available; no admin-only functions used.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$eve_id     = isset( $_GET['eve_id'] ) ? (int) $_GET['eve_id'] : 0;
$do_refresh = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );

if ( ! $eve_id ) {
	echo '<div class="hostlinks-access-denied"><p>No event specified.</p></div>';
	return;
}

// ── Load HL event row ─────────────────────────────────────────────────────────
$table11 = $wpdb->prefix . 'event_details_list';
$row = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$table11} WHERE eve_id = %d AND eve_status = '1' LIMIT 1", $eve_id ),
	ARRAY_A
);

if ( ! $row ) {
	echo '<div class="hostlinks-access-denied"><p>Event not found.</p></div>';
	return;
}

$cvent_id = Hostlinks_CVENT_API::sanitize_uuid( $row['cvent_event_id'] ?? '' );
if ( ! $cvent_id ) {
	echo '<div class="hostlinks-access-denied"><p>This event does not have a linked registration system ID yet.</p></div>';
	return;
}

// ── Cache (same key as admin roster so both share the same warm cache) ────────
$event_end_ts  = ! empty( $row['eve_end'] ) ? strtotime( $row['eve_end'] ) : 0;
$is_past_event = $event_end_ts > 0 && $event_end_ts < strtotime( 'today midnight' );
$cache_ttl     = $is_past_event ? 0 : 24 * HOUR_IN_SECONDS;
$cache_key     = 'hostlinks_roster_' . md5( $cvent_id );

if ( $do_refresh ) {
	delete_transient( $cache_key );
}

$attendees_raw = get_transient( $cache_key );
$from_cache    = ( $attendees_raw !== false );

if ( ! $from_cache ) {
	$attendees_raw = Hostlinks_CVENT_API::get_roster_attendees( $cvent_id );
	if ( is_wp_error( $attendees_raw ) ) {
		echo '<div class="hostlinks-access-denied"><p>Could not load roster. Please try again later.</p></div>';
		return;
	}
	set_transient( $cache_key, $attendees_raw, $cache_ttl );

	// Schedule 5-day finalize cron for recently-ended events.
	if ( $is_past_event && $event_end_ts > strtotime( '-5 days' ) ) {
		$cron_args = array( $cvent_id, $eve_id );
		if ( ! wp_next_scheduled( 'hostlinks_roster_finalize', $cron_args ) ) {
			wp_schedule_single_event( $event_end_ts + ( 5 * DAY_IN_SECONDS ), 'hostlinks_roster_finalize', $cron_args );
		}
	}
}

// ── Filter non-attending statuses ─────────────────────────────────────────────
$skip_statuses = array( 'Cancelled', 'Declined', 'Deleted', 'TestAttendee', 'Waitlisted',
                        'cancelled', 'declined', 'deleted', 'testattendee', 'waitlisted' );

$attendees = array();
foreach ( $attendees_raw as $att ) {
	$status = $att['status'] ?? $att['attendeeStatus'] ?? '';
	if ( in_array( $status, $skip_statuses, true ) ) {
		continue;
	}
	$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
	$attendees[] = array(
		'last'    => $att['lastName']    ?? $contact['lastName']    ?? '',
		'first'   => $att['firstName']   ?? $contact['firstName']   ?? '',
		'company' => $att['companyName'] ?? $contact['company']     ?? $contact['companyName'] ?? '',
		'title'   => $att['title']       ?? $contact['title']       ?? '',
		'email'   => $att['email']       ?? $contact['email']       ?? '',
		'phone'   => $att['workPhone']   ?? $contact['workPhone']   ?? $att['phone'] ?? $contact['phone'] ?? '',
	);
}

usort( $attendees, function( $a, $b ) {
	$c = strcasecmp( $a['last'], $b['last'] );
	return $c !== 0 ? $c : strcasecmp( $a['first'], $b['first'] );
} );

$count       = count( $attendees );
$event_title = $row['eve_location'] ?? 'Event #' . $eve_id;
$start_date  = ! empty( $row['eve_start'] ) ? date( 'F j, Y', strtotime( $row['eve_start'] ) ) : '';
$end_date    = ! empty( $row['eve_end'] ) && $row['eve_end'] !== $row['eve_start']
               ? ' – ' . date( 'F j, Y', strtotime( $row['eve_end'] ) ) : '';

$current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$refresh_url = add_query_arg( 'refresh', '1', remove_query_arg( 'refresh', $current_url ) );
?>
<div class="hl-fe-roster">

	<div class="hl-fe-roster-header">
		<div>
			<h2 class="hl-fe-roster-title"><?php echo esc_html( $event_title ); ?></h2>
			<p class="hl-fe-roster-meta">
				<?php if ( $start_date ) echo esc_html( $start_date . $end_date ) . ' &nbsp;|&nbsp; '; ?>
				<?php echo $count; ?> attendee<?php echo $count !== 1 ? 's' : ''; ?>
			</p>
		</div>
		<div class="hl-fe-roster-actions">
			<button class="hl-fe-roster-btn" onclick="window.print()">&#x1F5A8; Print</button>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( $refresh_url ); ?>" class="hl-fe-roster-btn hl-fe-roster-btn--sec">&#x21BB; Refresh</a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $attendees ) ) : ?>
	<div class="hl-fe-roster-toggles">
		<span>Show columns:</span>
		<label><input type="checkbox" id="hl-fe-email"> Email</label>
		<label><input type="checkbox" id="hl-fe-phone"> Phone</label>
	</div>
	<?php endif; ?>

	<?php if ( empty( $attendees ) ) : ?>
	<p style="color:#888;padding:20px 0;">No registered attendees found for this event.</p>
	<?php else : ?>
	<table class="hl-fe-roster-table">
		<thead>
			<tr>
				<th>#</th>
				<th>Last Name</th>
				<th>First Name</th>
				<th>Company / Agency</th>
				<th>Title</th>
				<th class="hl-fe-col-email">Email</th>
				<th class="hl-fe-col-phone">Phone</th>
				<th class="hl-fe-sign-in">Sign In</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $attendees as $i => $att ) : ?>
			<tr>
				<td class="hl-fe-num"><?php echo $i + 1; ?></td>
				<td><?php echo esc_html( $att['last'] ); ?></td>
				<td><?php echo esc_html( $att['first'] ); ?></td>
				<td><?php echo esc_html( $att['company'] ); ?></td>
				<td><?php echo esc_html( $att['title'] ); ?></td>
				<td class="hl-fe-col-email"><?php echo esc_html( $att['email'] ); ?></td>
				<td class="hl-fe-col-phone"><?php echo esc_html( $att['phone'] ); ?></td>
				<td class="hl-fe-sign-in">&nbsp;</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

</div><!-- .hl-fe-roster -->

<style>
.hl-fe-roster { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.hl-fe-roster-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px; }
.hl-fe-roster-title { font-size:1.3em; margin:0 0 4px; }
.hl-fe-roster-meta { font-size:.85em; color:#666; margin:0; }
.hl-fe-roster-actions { display:flex; gap:8px; flex-wrap:wrap; }
.hl-fe-roster-btn { display:inline-block; padding:6px 14px; background:#0da2e7; color:#fff; border:none; border-radius:3px; font-size:13px; text-decoration:none; cursor:pointer; line-height:1.5; }
.hl-fe-roster-btn:hover { background:#0b8fcf; color:#fff; }
.hl-fe-roster-btn--sec { background:#f0f0f0; color:#333; border:1px solid #ccc; }
.hl-fe-roster-btn--sec:hover { background:#e0e0e0; color:#333; }
.hl-fe-roster-toggles { display:flex; gap:14px; align-items:center; font-size:13px; color:#555; padding:6px 0 10px; }
.hl-fe-roster-toggles label { cursor:pointer; display:flex; align-items:center; gap:4px; }
.hl-fe-roster-toggles input[type=checkbox] { width:14px; height:14px; }
.hl-fe-roster-table { width:100%; border-collapse:collapse; font-size:13px; }
.hl-fe-roster-table th { background:#1d2327; color:#fff; padding:7px 10px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; border:1px solid #3c434a; }
.hl-fe-roster-table td { padding:6px 10px; border:1px solid #ddd; vertical-align:top; }
.hl-fe-roster-table tr:nth-child(even) td { background:#f9f9f9; }
.hl-fe-num { color:#aaa; font-size:11px; width:30px; }
.hl-fe-sign-in { width:130px; min-width:80px; }
.hl-fe-col-email, .hl-fe-col-phone { display:none; }
@media print {
	.hl-fe-roster-actions, .hl-fe-roster-toggles { display:none !important; }
	.hl-fe-roster-table th { background:#000 !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-roster-table td, .hl-fe-roster-table th { border:1px solid #666 !important; }
	.hl-fe-col-email.hl-fe-col-visible, .hl-fe-col-phone.hl-fe-col-visible { display:table-cell !important; }
	.hl-fe-sign-in { width:100pt; }
}
</style>
<script>
(function(){
	function tog(cls,show){
		var els=document.querySelectorAll('.'+cls);
		for(var i=0;i<els.length;i++){
			els[i].style.display=show?'table-cell':'none';
			els[i].classList[show?'add':'remove']('hl-fe-col-visible');
		}
	}
	var ec=document.getElementById('hl-fe-email');
	var pc=document.getElementById('hl-fe-phone');
	if(ec) ec.addEventListener('change',function(){tog('hl-fe-col-email',this.checked);});
	if(pc) pc.addEventListener('change',function(){tog('hl-fe-col-phone',this.checked);});
})();
</script>
