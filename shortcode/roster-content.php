<?php
/**
 * Roster inner content — shared by both the AJAX handler and direct include paths.
 *
 * Expects $eve_id (int) and $wpdb (global) to be available.
 * Outputs the .hl-fe-roster div + CSS.  Does NOT output any loader or toggle JS
 * (toggle JS is handled by the caller).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ── Load HL event row ─────────────────────────────────────────────────────────
$table11 = $wpdb->prefix . 'event_details_list';
$_rc_row = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$table11} WHERE eve_id = %d AND eve_status = '1' LIMIT 1", $eve_id ),
	ARRAY_A
);

if ( ! $_rc_row ) {
	echo '<p class="hl-fe-error">Event not found.</p>';
	return;
}

$_rc_cvent_id = Hostlinks_CVENT_API::sanitize_uuid( $_rc_row['cvent_event_id'] ?? '' );
if ( ! $_rc_cvent_id ) {
	echo '<p class="hl-fe-error">This event does not have a linked registration system ID yet.</p>';
	return;
}

// ── Cache ─────────────────────────────────────────────────────────────────────
$_rc_end_ts      = ! empty( $_rc_row['eve_end'] ) ? strtotime( $_rc_row['eve_end'] ) : 0;
$_rc_is_past     = $_rc_end_ts > 0 && $_rc_end_ts < strtotime( 'today midnight' );
$_rc_cache_ttl   = $_rc_is_past ? 0 : 24 * HOUR_IN_SECONDS;
$_rc_cache_key   = 'hostlinks_roster_' . md5( $_rc_cvent_id );
$_rc_do_refresh  = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );

if ( $_rc_do_refresh ) {
	delete_transient( $_rc_cache_key );
}

$_rc_raw      = get_transient( $_rc_cache_key );
$_rc_from_cache = ( $_rc_raw !== false );

if ( ! $_rc_from_cache ) {
	$_rc_raw = Hostlinks_CVENT_API::get_roster_attendees( $_rc_cvent_id );
	if ( is_wp_error( $_rc_raw ) ) {
		echo '<p class="hl-fe-error">Could not load roster. Please try again later.</p>';
		return;
	}
	set_transient( $_rc_cache_key, $_rc_raw, $_rc_cache_ttl );

	// Schedule 5-day finalize cron for recently-ended events.
	if ( $_rc_is_past && $_rc_end_ts > strtotime( '-5 days' ) ) {
		$_rc_cron_args = array( $_rc_cvent_id, $eve_id );
		if ( ! wp_next_scheduled( 'hostlinks_roster_finalize', $_rc_cron_args ) ) {
			wp_schedule_single_event( $_rc_end_ts + ( 5 * DAY_IN_SECONDS ), 'hostlinks_roster_finalize', $_rc_cron_args );
		}
	}
}

// ── Phone formatter ───────────────────────────────────────────────────────────
if ( ! function_exists( 'hl_roster_format_phone' ) ) {
	function hl_roster_format_phone( $raw ) {
		$digits = preg_replace( '/\D/', '', $raw );
		if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
			$digits = substr( $digits, 1 );
		}
		if ( strlen( $digits ) === 10 ) {
			return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}
		return $raw;
	}
}

// ── Filter + sort ─────────────────────────────────────────────────────────────
$_rc_skip = array( 'Cancelled', 'Declined', 'Deleted', 'TestAttendee', 'Waitlisted',
                   'cancelled', 'declined', 'deleted', 'testattendee', 'waitlisted' );

$_rc_attendees = array();
foreach ( $_rc_raw as $_rc_att ) {
	if ( in_array( $_rc_att['status'] ?? $_rc_att['attendeeStatus'] ?? '', $_rc_skip, true ) ) {
		continue;
	}
	$_rc_c = is_array( $_rc_att['contact'] ?? null ) ? $_rc_att['contact'] : array();
	$_rc_attendees[] = array(
		'last'    => $_rc_att['lastName']    ?? $_rc_c['lastName']    ?? '',
		'first'   => $_rc_att['firstName']   ?? $_rc_c['firstName']   ?? '',
		'company' => $_rc_att['companyName'] ?? $_rc_c['company']     ?? $_rc_c['companyName'] ?? '',
		'title'   => $_rc_att['title']       ?? $_rc_c['title']       ?? '',
		'email'   => $_rc_att['email']       ?? $_rc_c['email']       ?? '',
		'phone'   => hl_roster_format_phone( $_rc_att['workPhone'] ?? $_rc_c['workPhone'] ?? $_rc_att['phone'] ?? $_rc_c['phone'] ?? '' ),
	);
}
usort( $_rc_attendees, function( $a, $b ) {
	$c = strcasecmp( $a['last'], $b['last'] );
	return $c !== 0 ? $c : strcasecmp( $a['first'], $b['first'] );
} );

// ── Header title ─────────────────────────────────────────────────────────────
$_rc_type_raw = strtolower( trim( (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT event_type_name FROM `{$wpdb->prefix}event_type` WHERE event_type_id = %d",
	(int) ( $_rc_row['eve_type'] ?? 0 )
) ) ) );
$_rc_is_zoom  = ( strtolower( trim( $_rc_row['eve_zoom'] ?? '' ) ) === 'yes' );

if ( $_rc_is_zoom ) {
	$_rc_type_label = 'ZOOM';
} elseif ( strpos( $_rc_type_raw, 'management' ) !== false ) {
	$_rc_type_label = 'Management';
} elseif ( strpos( $_rc_type_raw, 'writing' ) !== false ) {
	$_rc_type_label = 'Writing';
} else {
	$_rc_type_label = '';
}

$_rc_header_parts = array_filter( array( 'Roster', $_rc_row['eve_location'] ?? 'Event #' . $eve_id, $_rc_type_label ) );
$_rc_title        = implode( ' – ', $_rc_header_parts );

$_rc_count      = count( $_rc_attendees );
$_rc_start_date = ! empty( $_rc_row['eve_start'] ) ? date( 'F j, Y', strtotime( $_rc_row['eve_start'] ) ) : '';
$_rc_end_date   = ! empty( $_rc_row['eve_end'] ) && $_rc_row['eve_end'] !== $_rc_row['eve_start']
                  ? ' – ' . date( 'F j, Y', strtotime( $_rc_row['eve_end'] ) ) : '';
?>
<?php $_rc_logo = get_option( 'hostlinks_roster_logo_url', '' ); ?>
<div class="hl-fe-roster">

	<div class="hl-fe-roster-header">
		<div>
			<h2 class="hl-fe-roster-title"><?php echo esc_html( $_rc_title ); ?></h2>
			<p class="hl-fe-roster-meta">
				<?php if ( $_rc_start_date ) echo esc_html( $_rc_start_date . $_rc_end_date ) . ' &nbsp;|&nbsp; '; ?>
				<?php echo $_rc_count; ?> attendee<?php echo $_rc_count !== 1 ? 's' : ''; ?>
			</p>
		</div>
		<div class="hl-fe-roster-actions">
			<?php if ( $_rc_logo ) : ?>
			<img src="<?php echo esc_url( $_rc_logo ); ?>" alt="" class="hl-fe-roster-logo" />
			<?php endif; ?>
			<button class="hl-fe-roster-btn" onclick="window.print()">&#x1F5A8; Print</button>
		</div>
	</div>

	<?php if ( ! empty( $_rc_attendees ) ) : ?>
	<div class="hl-fe-roster-toggles">
		<span>Show columns:</span>
		<label><input type="checkbox" id="hl-fe-email"> Email</label>
		<label><input type="checkbox" id="hl-fe-phone"> Phone</label>
		<em style="color:#aaa;font-size:11px;margin-left:4px;">(not for public view)</em>
	</div>
	<?php endif; ?>

	<?php if ( empty( $_rc_attendees ) ) : ?>
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
		<?php foreach ( $_rc_attendees as $_rc_i => $_rc_att ) : ?>
			<tr>
				<td class="hl-fe-num"><?php echo $_rc_i + 1; ?></td>
				<td><?php echo esc_html( $_rc_att['last'] ); ?></td>
				<td><?php echo esc_html( $_rc_att['first'] ); ?></td>
				<td><?php echo esc_html( $_rc_att['company'] ); ?></td>
				<td><?php echo esc_html( $_rc_att['title'] ); ?></td>
				<td class="hl-fe-col-email"><?php echo esc_html( $_rc_att['email'] ); ?></td>
				<td class="hl-fe-col-phone"><?php echo esc_html( $_rc_att['phone'] ); ?></td>
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
.hl-fe-roster-actions { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.hl-fe-roster-logo { max-height:48px; max-width:180px; object-fit:contain; display:block; }
.hl-fe-roster-btn { display:inline-block; padding:6px 14px; background:#0da2e7; color:#fff; border:none; border-radius:3px; font-size:13px; text-decoration:none; cursor:pointer; line-height:1.5; }
.hl-fe-roster-btn:hover { background:#0b8fcf; color:#fff; }
.hl-fe-roster-toggles { display:flex; gap:14px; align-items:center; font-size:13px; color:#555; padding:6px 0 10px; }
.hl-fe-roster-toggles label { cursor:pointer; display:flex; align-items:center; gap:4px; }
.hl-fe-roster-toggles input[type=checkbox] { width:14px; height:14px; }
.hl-fe-roster-table { width:100%; border-collapse:collapse; font-size:13px; }
.hl-fe-roster-table th { background:#1d2327; color:#fff; padding:7px 10px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; border:1px solid #3c434a; }
.hl-fe-roster-table td { padding:6px 10px; border:1px solid #ddd; vertical-align:top; }
.hl-fe-roster-table tr:nth-child(even) td { background:#f9f9f9; }
.hl-fe-num { color:#aaa; font-size:11px; width:30px; }
.hl-fe-sign-in { width:260px; min-width:160px; }
.hl-fe-col-email, .hl-fe-col-phone { display:none; }
.hl-fe-error { color:#d63638; padding:20px 0; }
@media print {
	@page                           { size: landscape; margin: 0.5in; }
	body *                          { visibility:hidden; }
	body                            { background:#fff !important; margin:0 !important; padding:0 !important; }
	.hl-fe-roster                   { visibility:visible; position:absolute; left:0; top:0; width:100%; padding:0 16px; box-sizing:border-box; }
	.hl-fe-roster *                 { visibility:visible; }
	.hl-fe-roster-actions           { display:flex !important; justify-content:flex-end; border-bottom:none !important; padding-bottom:0 !important; }
	.hl-fe-roster-btn, .hl-fe-roster-toggles { display:none !important; }
	.hl-fe-roster-logo              { display:block !important; max-height:72px; max-width:240px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-roster-table             { width:100%; border-collapse:collapse; }
	.hl-fe-roster-table th          { background:#000 !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-roster-table td,
	.hl-fe-roster-table th          { border:1px solid #666 !important; padding:5px 8px; }
	.hl-fe-col-email.hl-fe-col-visible,
	.hl-fe-col-phone.hl-fe-col-visible { display:table-cell !important; }
	.hl-fe-sign-in                  { width:200pt; }
}
</style>
