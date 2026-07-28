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

// ── Attendee fetch ────────────────────────────────────────────────────────────
$do_refresh = ! empty( $_GET['refresh'] );

$loaded = Hostlinks_Roster::load_order_items( $cvent_id, $row, $do_refresh );
if ( is_wp_error( $loaded ) ) {
	wp_die( 'CVENT API error: ' . esc_html( $loaded->get_error_message() ) );
}

$from_cache    = $loaded['from_cache'];
$is_past_event = $loaded['is_past'];
$order_items   = $loaded['items'];
$attendees     = Hostlinks_Roster::build_rows( $order_items, $is_past_event );
$attendees_raw = Hostlinks_Roster::resolve_attendees_map( $order_items );

Hostlinks_Roster::maybe_schedule_finalize( $cvent_id, $eve_id, $row, $is_past_event );

// In debug mode, order items are already available from the fetch above.
$debug_order_items = $do_debug ? $order_items : array();

$count      = count( $attendees );
$start_date = ! empty( $row['eve_start'] ) ? date( 'F j, Y', strtotime( $row['eve_start'] ) ) : '';
$end_date   = ! empty( $row['eve_end'] ) && $row['eve_end'] !== $row['eve_start']
              ? ' – ' . date( 'F j, Y', strtotime( $row['eve_end'] ) ) : '';

$event_title = Hostlinks_Roster::build_title( $row, $eve_id, $wpdb );

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
	gap: 10px;
	align-items: center;
	flex-wrap: wrap;
}
.hl-roster-logo { max-height: 48px; max-width: 180px; object-fit: contain; display: block; }
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
.hl-col-email, .hl-col-phone,
.hl-col-discount, .hl-col-balance,
.hl-col-participant, .hl-col-work-city, .hl-col-work-state { display: none; }

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
	.hl-roster-btn, .hl-col-toggles,
	.hl-cache-note, .hl-debug-box { display: none !important; }
	.hl-roster-controls { display: flex !important; justify-content: flex-end; gap: 0; }
	.hl-roster-logo { display: block !important; max-height: 72px; max-width: 240px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.hl-roster-header { margin-bottom: 12px; }
	.hl-roster-header h1 { font-size: 16pt; }
	.hl-roster-table { width: 100%; }
	.hl-roster-table th { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.hl-roster-table td, .hl-roster-table th { border: 1px solid #666 !important; padding: 5px 7px; }
	.hl-sign-in-col { width: 240pt; }
	tr { page-break-inside: avoid; }
	/* When printing, show whichever optional columns are currently visible */
	.hl-col-email.hl-col-visible, .hl-col-phone.hl-col-visible,
	.hl-col-discount.hl-col-visible, .hl-col-balance.hl-col-visible,
	.hl-col-participant.hl-col-visible, .hl-col-work-city.hl-col-visible,
	.hl-col-work-state.hl-col-visible { display: table-cell !important; }
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
			<?php $hl_roster_logo = get_option( 'hostlinks_roster_logo_url', '' ); ?>
			<?php if ( $hl_roster_logo ) : ?>
			<img src="<?php echo esc_url( $hl_roster_logo ); ?>" alt="" class="hl-roster-logo" />
			<?php endif; ?>
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
		<label><input type="checkbox" id="hl-toggle-work-city"> Work City</label>
		<label><input type="checkbox" id="hl-toggle-work-state"> Work State</label>
		<label><input type="checkbox" id="hl-toggle-discount"> Discount Code</label>
		<label><input type="checkbox" id="hl-toggle-balance"> Balance Due</label>
		<?php if ( $is_past_event ) : ?>
		<label><input type="checkbox" id="hl-toggle-participant"> Participant</label>
		<?php endif; ?>
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
				<th class="hl-col-work-city">Work City</th>
				<th class="hl-col-work-state">Work State</th>
				<th class="hl-col-discount">Discount Code</th>
				<th class="hl-col-balance">Balance Due</th>
				<?php if ( $is_past_event ) : ?>
				<th class="hl-col-participant">Participant</th>
				<?php endif; ?>
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
				<td class="hl-col-work-city"><?php echo esc_html( $att['work_city'] ); ?></td>
				<td class="hl-col-work-state"><?php echo esc_html( $att['work_state'] ); ?></td>
				<td class="hl-col-discount"><?php echo esc_html( $att['discounts'] ); ?></td>
				<td class="hl-col-balance"><?php echo esc_html( $att['balance_due'] ); ?></td>
				<?php if ( $is_past_event ) : ?>
				<td class="hl-col-participant"><?php echo esc_html( $att['participant'] ); ?></td>
				<?php endif; ?>
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

		<br><strong>Attendee Records (<?php echo count( $attendees_raw ); ?> resolved, <?php echo count( $attendees ); ?> after status filter)
		— strategy: <?php
			$sample = $debug_order_items[0]['attendee'] ?? array();
			echo ( isset( $sample['firstName'] ) || isset( $sample['lastName'] ) || isset( $sample['contact'] ) )
				? '<span style="color:green;">expand=attendee worked ✓ (1 call)</span>'
				: '<span style="color:#c00;">expand not supported — used individual lookups</span>';
		?>:</strong><br>
		<?php if ( ! empty( $attendees_raw ) ) : ?>
			<strong>First raw attendee record:</strong>
			<pre><?php echo esc_html( wp_json_encode( reset( $attendees_raw ), JSON_PRETTY_PRINT ) ); ?></pre>
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
	[
		['hl-toggle-work-city', 'hl-col-work-city'],
		['hl-toggle-work-state', 'hl-col-work-state'],
		['hl-toggle-discount', 'hl-col-discount'],
		['hl-toggle-balance', 'hl-col-balance'],
		['hl-toggle-participant', 'hl-col-participant'],
	].forEach(function(pair) {
		var el = document.getElementById(pair[0]);
		if (el) el.addEventListener('change', function() { toggleCol(pair[1], this.checked); });
	});
})();
</script>
</body>
</html>
<?php
exit;
