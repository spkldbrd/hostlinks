<?php
/**
 * Event Roster page.
 *
 * URL: admin.php?page=hostlinks-roster&eve_id={HL_EVENT_ID}
 *
 * Fetches attendees from the CVENT API (cached 1 hour), filters out
 * non-attending statuses, sorts by last/first name, and renders a
 * print-ready sign-in sheet.
 *
 * Append &debug=1 (manage_options only) to dump the raw first attendee
 * record so field names can be confirmed against the live API.
 *
 * Append &refresh=1 to bust the transient cache and re-fetch.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

global $wpdb;

$eve_id    = isset( $_GET['eve_id'] ) ? (int) $_GET['eve_id'] : 0;
$do_debug  = ! empty( $_GET['debug'] ) && current_user_can( 'manage_options' );
$do_refresh = ! empty( $_GET['refresh'] );

if ( ! $eve_id ) {
	wp_die( 'No event ID provided. Use admin.php?page=hostlinks-roster&eve_id=N' );
}

// ── Load the HL event row ─────────────────────────────────────────────────────
$table11 = $wpdb->prefix . 'event_details_list';
$row = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$table11} WHERE eve_id = %d LIMIT 1", $eve_id ),
	ARRAY_A
);

if ( ! $row ) {
	wp_die( 'Event #' . $eve_id . ' not found.' );
}

$cvent_id = Hostlinks_CVENT_API::sanitize_uuid( $row['cvent_event_id'] ?? '' );
if ( ! $cvent_id ) {
	wp_die( 'Event #' . $eve_id . ' does not have a linked CVENT ID. Link it via CVENT Sync first.' );
}

// ── Attendee fetch (cached 1 hour) ────────────────────────────────────────────
$cache_key = 'hostlinks_roster_' . md5( $cvent_id );

if ( $do_refresh ) {
	delete_transient( $cache_key );
}

$attendees_raw = get_transient( $cache_key );
$from_cache    = ( $attendees_raw !== false );

if ( ! $from_cache ) {
	$attendees_raw = Hostlinks_CVENT_API::get_attendees( $cvent_id );
	if ( is_wp_error( $attendees_raw ) ) {
		wp_die( 'CVENT API error: ' . esc_html( $attendees_raw->get_error_message() ) );
	}
	set_transient( $cache_key, $attendees_raw, HOUR_IN_SECONDS );
}

// ── Filter out non-attending statuses ────────────────────────────────────────
$skip_statuses = array( 'Cancelled', 'Declined', 'Deleted', 'TestAttendee', 'Waitlisted',
                        'cancelled', 'declined', 'deleted', 'testattendee', 'waitlisted' );

$attendees = array();
foreach ( $attendees_raw as $att ) {
	$status = $att['status'] ?? $att['attendeeStatus'] ?? '';
	if ( in_array( $status, $skip_statuses, true ) ) {
		continue;
	}
	// Normalise name fields — CVENT may nest under 'contact' or at top level.
	$first = $att['firstName']   ?? $att['contact']['firstName']   ?? '';
	$last  = $att['lastName']    ?? $att['contact']['lastName']    ?? '';
	$co    = $att['companyName'] ?? $att['contact']['company']     ?? $att['contact']['companyName'] ?? '';
	$title = $att['title']       ?? $att['contact']['title']       ?? '';

	$attendees[] = array(
		'last'    => $last,
		'first'   => $first,
		'company' => $co,
		'title'   => $title,
		'status'  => $status,
	);
}

// ── Sort by last name, then first name ────────────────────────────────────────
usort( $attendees, function( $a, $b ) {
	$cmp = strcasecmp( $a['last'], $b['last'] );
	return $cmp !== 0 ? $cmp : strcasecmp( $a['first'], $b['first'] );
} );

$count       = count( $attendees );
$event_title = esc_html( $row['eve_location'] ?? 'Event #' . $eve_id );
$start_date  = ! empty( $row['eve_start'] ) ? date( 'F j, Y', strtotime( $row['eve_start'] ) ) : '';
$end_date    = ! empty( $row['eve_end'] ) && $row['eve_end'] !== $row['eve_start']
               ? ' – ' . date( 'F j, Y', strtotime( $row['eve_end'] ) ) : '';

$back_url    = admin_url( 'admin.php?page=booking-menu' );
$refresh_url = admin_url( 'admin.php?page=hostlinks-roster&eve_id=' . $eve_id . '&refresh=1' );
$debug_url   = admin_url( 'admin.php?page=hostlinks-roster&eve_id=' . $eve_id . '&debug=1' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width">
<title>Roster — <?php echo $event_title; ?></title>
<?php wp_print_styles( 'wp-admin' ); ?>
<style>
/* ── Screen styles ── */
body {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	font-size: 14px;
	color: #1d2327;
	background: #f0f0f1;
	margin: 0;
	padding: 0;
}
.hl-roster-wrap {
	max-width: 960px;
	margin: 24px auto;
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 24px 28px;
}
.hl-roster-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	margin-bottom: 18px;
	gap: 16px;
	flex-wrap: wrap;
}
.hl-roster-header h1 {
	font-size: 20px;
	margin: 0 0 4px;
}
.hl-roster-meta {
	font-size: 13px;
	color: #666;
}
.hl-roster-controls {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}
.hl-roster-btn {
	display: inline-block;
	padding: 6px 14px;
	background: #0da2e7;
	color: #fff;
	border: none;
	border-radius: 3px;
	font-size: 13px;
	text-decoration: none;
	cursor: pointer;
	line-height: 1.5;
}
.hl-roster-btn:hover { background: #0b8fcf; color: #fff; }
.hl-roster-btn--secondary {
	background: #f6f7f7;
	color: #2c3338;
	border: 1px solid #c3c4c7;
}
.hl-roster-btn--secondary:hover { background: #e9eaeb; color: #2c3338; }
.hl-cache-note {
	font-size: 12px;
	color: #888;
	margin-top: 4px;
}
.hl-roster-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 8px;
}
.hl-roster-table th {
	background: #1d2327;
	color: #fff;
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: .04em;
	padding: 8px 10px;
	text-align: left;
	border: 1px solid #3c434a;
}
.hl-roster-table td {
	padding: 7px 10px;
	border: 1px solid #dcdcde;
	vertical-align: top;
	font-size: 13px;
}
.hl-roster-table tr:nth-child(even) td { background: #f9f9f9; }
.hl-sign-in-col { width: 140px; min-width: 100px; }
.hl-roster-empty {
	text-align: center;
	padding: 40px;
	color: #666;
}
.hl-debug-box {
	background: #f0f6fc;
	border: 1px solid #0da2e7;
	border-radius: 4px;
	padding: 12px 16px;
	margin-top: 20px;
}
.hl-debug-box pre {
	overflow-x: auto;
	font-size: 12px;
	margin: 8px 0 0;
}

/* ── Print styles ── */
@media print {
	body { background: #fff; font-size: 11pt; }
	.hl-roster-wrap { border: none; box-shadow: none; padding: 0; max-width: 100%; margin: 0; }
	.hl-roster-controls,
	.hl-cache-note,
	.hl-debug-box { display: none !important; }
	.hl-roster-header { margin-bottom: 12px; }
	.hl-roster-header h1 { font-size: 16pt; }
	.hl-roster-table { width: 100%; }
	.hl-roster-table th { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.hl-roster-table td, .hl-roster-table th { border: 1px solid #666 !important; padding: 5px 7px; }
	.hl-sign-in-col { width: 120pt; }
	tr { page-break-inside: avoid; }
}
</style>
</head>
<body>
<div class="hl-roster-wrap">

	<div class="hl-roster-header">
		<div>
			<h1><?php echo $event_title; ?></h1>
			<div class="hl-roster-meta">
				<?php if ( $start_date ) echo esc_html( $start_date . $end_date ) . ' &nbsp;|&nbsp; '; ?>
				<?php echo $count; ?> attendee<?php echo $count !== 1 ? 's' : ''; ?>
				<?php if ( $from_cache && ! $do_refresh ) : ?>
					&nbsp;|&nbsp; <span style="color:#888;">Cached</span>
				<?php endif; ?>
			</div>
			<?php if ( $from_cache && ! $do_refresh ) : ?>
			<div class="hl-cache-note">Data cached for up to 1 hour. <a href="<?php echo esc_url( $refresh_url ); ?>">Refresh now</a></div>
			<?php endif; ?>
		</div>
		<div class="hl-roster-controls">
			<button class="hl-roster-btn" onclick="window.print()">&#x1F5A8; Print</button>
			<a href="<?php echo esc_url( $refresh_url ); ?>" class="hl-roster-btn hl-roster-btn--secondary">&#x21BB; Refresh</a>
			<?php if ( ! $do_debug ) : ?>
			<a href="<?php echo esc_url( $debug_url ); ?>" class="hl-roster-btn hl-roster-btn--secondary" title="Dump raw API fields">Debug</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $back_url ); ?>" class="hl-roster-btn hl-roster-btn--secondary">&#x2190; Back to Events</a>
		</div>
	</div>

	<?php if ( empty( $attendees ) ) : ?>
	<div class="hl-roster-empty">
		<p>No active attendees found for this event.</p>
		<p style="font-size:12px;color:#aaa;">Total raw records fetched: <?php echo count( $attendees_raw ); ?></p>
	</div>
	<?php else : ?>
	<table class="hl-roster-table">
		<thead>
			<tr>
				<th>#</th>
				<th>Last Name</th>
				<th>First Name</th>
				<th>Company / Agency</th>
				<th>Title</th>
				<th class="hl-sign-in-col">Sign In</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $attendees as $i => $att ) : ?>
			<tr>
				<td style="color:#aaa;font-size:12px;"><?php echo $i + 1; ?></td>
				<td><?php echo esc_html( $att['last'] ); ?></td>
				<td><?php echo esc_html( $att['first'] ); ?></td>
				<td><?php echo esc_html( $att['company'] ); ?></td>
				<td><?php echo esc_html( $att['title'] ); ?></td>
				<td class="hl-sign-in-col">&nbsp;</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php if ( $do_debug && ! empty( $attendees_raw ) ) : ?>
	<div class="hl-debug-box">
		<strong>Debug — raw first attendee record from CVENT API:</strong>
		<pre><?php echo esc_html( wp_json_encode( $attendees_raw[0], JSON_PRETTY_PRINT ) ); ?></pre>
		<strong>Total raw records:</strong> <?php echo count( $attendees_raw ); ?><br>
		<strong>After filtering:</strong> <?php echo count( $attendees ); ?>
	</div>
	<?php endif; ?>

</div>
</body>
</html>
<?php
// Stop WP from appending the admin footer to our standalone print page.
exit;
