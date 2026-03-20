<?php
/**
 * Event Roster page.
 *
 * URL: admin.php?page=hostlinks-roster&eve_id={HL_EVENT_ID}
 *
 * Fetches attendees from the CVENT API (cached 24 hours), filters out
 * non-attending statuses, sorts by last/first name, and renders a
 * print-ready sign-in sheet.
 *
 * Append &debug=1 (manage_options only) to dump raw API records.
 * Append &refresh=1 to bust the transient cache and re-fetch.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

global $wpdb;

$eve_id     = isset( $_GET['eve_id'] ) ? (int) $_GET['eve_id'] : 0;
$do_debug   = ! empty( $_GET['debug'] ) && current_user_can( 'manage_options' );
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

// ── Determine cache TTL based on whether event is in the past ────────────────
// Past events: cache permanently (TTL = 0) — roster won't change.
// Future / today: cache 24 hours.
$event_end_ts  = ! empty( $row['eve_end'] ) ? strtotime( $row['eve_end'] ) : 0;
$is_past_event = $event_end_ts > 0 && $event_end_ts < strtotime( 'today midnight' );
$cache_ttl     = $is_past_event ? 0 : 24 * HOUR_IN_SECONDS;

// ── Attendee fetch ────────────────────────────────────────────────────────────
$cache_key = 'hostlinks_roster_' . md5( $cvent_id );

if ( $do_refresh ) {
	delete_transient( $cache_key );
}

$attendees_raw = get_transient( $cache_key );
$from_cache    = ( $attendees_raw !== false );
$debug_order_items = array();

if ( ! $from_cache ) {
	$attendees_raw = Hostlinks_CVENT_API::get_roster_attendees( $cvent_id );
	if ( is_wp_error( $attendees_raw ) ) {
		wp_die( 'CVENT API error: ' . esc_html( $attendees_raw->get_error_message() ) );
	}
	set_transient( $cache_key, $attendees_raw, $cache_ttl );

	// ── Schedule the 5-day auto-pull for events that just ended ──────────────
	// Fires once, 5 days after eve_end, to capture final cancellations.
	// Only schedule if: event has ended AND 5-day window hasn't passed AND not yet scheduled.
	if ( $is_past_event && $event_end_ts > strtotime( '-5 days' ) ) {
		$cron_hook = 'hostlinks_roster_finalize';
		$cron_args = array( $cvent_id, $eve_id );
		if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
			$fire_at = $event_end_ts + ( 5 * DAY_IN_SECONDS );
			wp_schedule_single_event( $fire_at, $cron_hook, $cron_args );
		}
	}
}

// In debug mode, fetch raw order items for inspection.
if ( $do_debug ) {
	$debug_order_items = Hostlinks_CVENT_API::get_order_items( $cvent_id );
	if ( is_wp_error( $debug_order_items ) ) {
		$debug_order_items = array( 'error' => $debug_order_items->get_error_message() );
	}
}

// ── Phone number formatter ────────────────────────────────────────────────────
function hl_roster_format_phone( $raw ) {
	$digits = preg_replace( '/\D/', '', $raw );
	// Strip leading country code 1 if 11 digits.
	if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
		$digits = substr( $digits, 1 );
	}
	if ( strlen( $digits ) === 10 ) {
		return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
	}
	return $raw; // Return as-is if not a standard 10-digit number.
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
	// CVENT nests contact fields under 'contact'; handle both flat and nested.
	$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();

	$first = $att['firstName']   ?? $contact['firstName']   ?? '';
	$last  = $att['lastName']    ?? $contact['lastName']    ?? '';
	$co    = $att['companyName'] ?? $contact['company']     ?? $contact['companyName'] ?? '';
	$title = $att['title']       ?? $contact['title']       ?? '';
	$email = $att['email']       ?? $contact['email']       ?? '';
	$phone = hl_roster_format_phone( $att['workPhone'] ?? $contact['workPhone'] ?? $att['phone'] ?? $contact['phone'] ?? '' );

	$attendees[] = array(
		'last'    => $last,
		'first'   => $first,
		'company' => $co,
		'title'   => $title,
		'email'   => $email,
		'phone'   => $phone,
		'status'  => $status,
	);
}

// ── Sort by last name, then first name ────────────────────────────────────────
usort( $attendees, function( $a, $b ) {
	$cmp = strcasecmp( $a['last'], $b['last'] );
	return $cmp !== 0 ? $cmp : strcasecmp( $a['first'], $b['first'] );
} );

$count      = count( $attendees );
$start_date = ! empty( $row['eve_start'] ) ? date( 'F j, Y', strtotime( $row['eve_start'] ) ) : '';
$end_date   = ! empty( $row['eve_end'] ) && $row['eve_end'] !== $row['eve_start']
              ? ' – ' . date( 'F j, Y', strtotime( $row['eve_end'] ) ) : '';

// ── Build header title: "Roster – {Location} – {Type label}" ─────────────────
$type_name_raw = strtolower( trim( (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT event_type_name FROM `{$wpdb->prefix}event_type` WHERE event_type_id = %d",
	(int) ( $row['eve_type'] ?? 0 )
) ) ) );
$is_zoom = ( strtolower( trim( $row['eve_zoom'] ?? '' ) ) === 'yes' );

if ( $is_zoom ) {
	$type_label = 'ZOOM';
} elseif ( strpos( $type_name_raw, 'management' ) !== false ) {
	$type_label = 'Management';
} elseif ( strpos( $type_name_raw, 'writing' ) !== false ) {
	$type_label = 'Writing';
} else {
	$type_label = ''; // Subaward and anything else get no label
}

$location    = $row['eve_location'] ?? 'Event #' . $eve_id;
$header_parts = array_filter( array( 'Roster', $location, $type_label ) );
$event_title  = implode( ' – ', $header_parts );

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
	max-width: 1080px;
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
.hl-roster-header h1 { font-size: 20px; margin: 0 0 4px; }
.hl-roster-meta { font-size: 13px; color: #666; }
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
.hl-cache-note { font-size: 12px; color: #888; margin-top: 4px; }

/* Column toggles */
.hl-col-toggles {
	display: flex;
	gap: 16px;
	align-items: center;
	padding: 8px 0 12px;
	font-size: 13px;
	color: #444;
}
.hl-col-toggles label { cursor: pointer; display: flex; align-items: center; gap: 5px; }
.hl-col-toggles input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; }

.hl-roster-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 4px;
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
.hl-sign-in-col { width: 280px; min-width: 200px; }

/* Hidden columns — toggled by JS */
.hl-col-email, .hl-col-phone { display: none; }

.hl-roster-empty { text-align: center; padding: 40px; color: #666; }
.hl-debug-box {
	background: #f0f6fc;
	border: 1px solid #0da2e7;
	border-radius: 4px;
	padding: 12px 16px;
	margin-top: 20px;
}
.hl-debug-box pre { overflow-x: auto; font-size: 12px; margin: 8px 0 0; }

/* ── Print styles ── */
@media print {
	@page { size: landscape; margin: 0.5in; }
	body { background: #fff; font-size: 11pt; }
	.hl-roster-wrap { border: none; box-shadow: none; padding: 0; max-width: 100%; margin: 0; }
	.hl-roster-controls,
	.hl-col-toggles,
	.hl-cache-note,
	.hl-debug-box { display: none !important; }
	.hl-roster-header { margin-bottom: 12px; }
	.hl-roster-header h1 { font-size: 16pt; }
	.hl-roster-table { width: 100%; }
	.hl-roster-table th { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.hl-roster-table td, .hl-roster-table th { border: 1px solid #666 !important; padding: 5px 7px; }
	.hl-sign-in-col { width: 240pt; }
	tr { page-break-inside: avoid; }
	/* When printing, show whichever optional columns are currently visible */
	.hl-col-email.hl-col-visible, .hl-col-phone.hl-col-visible { display: table-cell !important; }
}
</style>
</head>
<body>
<div class="hl-roster-wrap">

	<div class="hl-roster-header">
		<div>
			<h1><?php echo esc_html( $event_title ); ?></h1>
			<div class="hl-roster-meta">
				<?php if ( $start_date ) echo esc_html( $start_date . $end_date ) . ' &nbsp;|&nbsp; '; ?>
				<?php echo $count; ?> attendee<?php echo $count !== 1 ? 's' : ''; ?>
				<?php if ( $from_cache && ! $do_refresh ) : ?>
					&nbsp;|&nbsp; <span style="color:#888;">Cached</span>
				<?php endif; ?>
			</div>
			<?php if ( $from_cache && ! $do_refresh ) : ?>
			<div class="hl-cache-note">
				<?php echo $is_past_event ? 'Permanently cached (past event).' : 'Cached for up to 24 hours.'; ?>
				<a href="<?php echo esc_url( $refresh_url ); ?>">Refresh now</a>
			</div>
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

	<?php if ( ! empty( $attendees ) ) : ?>
	<div class="hl-col-toggles">
		<span style="color:#888;font-size:12px;">Show columns:</span>
		<label><input type="checkbox" id="hl-toggle-email"> Email</label>
		<label><input type="checkbox" id="hl-toggle-phone"> Phone</label>
		<em style="color:#aaa;font-size:11px;margin-left:4px;">(not for public view)</em>
	</div>
	<?php endif; ?>

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
				<th class="hl-col-email">Email</th>
				<th class="hl-col-phone">Phone</th>
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
				<td class="hl-col-email"><?php echo esc_html( $att['email'] ); ?></td>
				<td class="hl-col-phone"><?php echo esc_html( $att['phone'] ); ?></td>
				<td class="hl-sign-in-col">&nbsp;</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php if ( $do_debug ) : ?>
	<div class="hl-debug-box">
		<strong>Debug — CVENT ID:</strong> <?php echo esc_html( $cvent_id ); ?><br><br>

		<strong>Order Items (<?php echo is_array( $debug_order_items ) ? count( $debug_order_items ) : 0; ?> total):</strong><br>
		<?php if ( empty( $debug_order_items ) ) : ?>
			<em style="color:#c00;">No order items returned — event may have no registrations in CVENT, or the linked CVENT ID may be wrong.</em>
		<?php elseif ( isset( $debug_order_items['error'] ) ) : ?>
			<em style="color:#c00;">Order items error: <?php echo esc_html( $debug_order_items['error'] ); ?></em>
		<?php else : ?>
			<strong>First order item (field names):</strong>
			<pre><?php echo esc_html( wp_json_encode( $debug_order_items[0], JSON_PRETTY_PRINT ) ); ?></pre>
		<?php endif; ?>

		<br><strong>Attendee Records (<?php echo count( $attendees_raw ); ?> fetched, <?php echo count( $attendees ); ?> after status filter)
		— strategy: <?php
			$sample = $debug_order_items[0]['attendee'] ?? array();
			echo ( isset( $sample['firstName'] ) || isset( $sample['lastName'] ) || isset( $sample['contact'] ) )
				? '<span style="color:green;">expand=attendee worked ✓ (1 call)</span>'
				: '<span style="color:#c00;">expand not supported — used individual lookups</span>';
		?>:</strong><br>
		<?php if ( ! empty( $attendees_raw ) ) : ?>
			<strong>First raw attendee record:</strong>
			<pre><?php echo esc_html( wp_json_encode( $attendees_raw[0], JSON_PRETTY_PRINT ) ); ?></pre>
		<?php else : ?>
			<em style="color:#c00;">No attendee records — either order items were empty or no attendee UUIDs could be extracted.</em>
		<?php endif; ?>
	</div>
	<?php endif; ?>

</div>

<script>
(function() {
	function toggleCol(cls, show) {
		var els = document.querySelectorAll('.' + cls);
		for (var i = 0; i < els.length; i++) {
			if (show) {
				els[i].style.display = 'table-cell';
				els[i].classList.add('hl-col-visible');
			} else {
				els[i].style.display = 'none';
				els[i].classList.remove('hl-col-visible');
			}
		}
	}
	var emailChk = document.getElementById('hl-toggle-email');
	var phoneChk = document.getElementById('hl-toggle-phone');
	if (emailChk) emailChk.addEventListener('change', function() { toggleCol('hl-col-email', this.checked); });
	if (phoneChk) phoneChk.addEventListener('change', function() { toggleCol('hl-col-phone', this.checked); });
})();
</script>
</body>
</html>
<?php
exit;
