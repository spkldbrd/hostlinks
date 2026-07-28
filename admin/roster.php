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
$attendees     = Hostlinks_Roster::build_rows( $order_items, $is_past_event, $cvent_id );
$att_cache_ttl = $is_past_event ? 0 : 24 * HOUR_IN_SECONDS;
$attendees_raw = Hostlinks_Roster::resolve_attendees_map( $order_items, $cvent_id, $att_cache_ttl );

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
.hl-sign-in { width: 280px; min-width: 200px; }

/* Hidden columns — toggled by JS */
<?php echo Hostlinks_Roster::optional_col_css( 'hl' ); ?>

.hl-roster-totals { display: none; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
.hl-roster-total-card { display: none; flex: 1 1 180px; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 10px; font-size: 12px; background: #fafafa; }
.hl-roster-total-card strong { display: block; margin-bottom: 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.hl-roster-total-card span { display: block; color: #555; line-height: 1.5; }

.hl-view-presets { display: flex; gap: 8px; align-items: center; padding: 8px 0 0; font-size: 13px; color: #444; flex-wrap: wrap; }
.hl-view-presets .hl-roster-btn { margin: 0; }
.hl-view-presets .hl-roster-btn--active { background: #0da2e7; color: #fff; border-color: #0b8fcf; }

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
	.hl-roster-btn, .hl-col-toggles, .hl-view-presets,
	.hl-cache-note, .hl-debug-box { display: none !important; }
	.hl-roster-controls { display: flex !important; justify-content: flex-end; gap: 0; }
	.hl-roster-logo { display: block !important; max-height: 72px; max-width: 240px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.hl-roster-header { margin-bottom: 12px; }
	.hl-roster-header h1 { font-size: 16pt; }
	.hl-roster-table { width: 100%; }
	.hl-roster-table th { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.hl-roster-table td, .hl-roster-table th { border: 1px solid #666 !important; padding: 5px 7px; }
	.hl-sign-in { width: 240pt; }
	<?php echo Hostlinks_Roster::optional_col_visible_css( 'hl' ); ?>
	.hl-roster-totals { display: flex !important; }
	.hl-roster-total-card.hl-col-visible { display: block !important; }
	tr { page-break-inside: avoid; }
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
	<div class="hl-view-presets">
		<span style="color:#888;font-size:12px;">View:</span>
		<button type="button" id="hl-view-signin" class="hl-roster-btn hl-roster-btn--active">Sign-in sheet</button>
		<button type="button" id="hl-view-details" class="hl-roster-btn hl-roster-btn--secondary">Registrant details</button>
	</div>
	<div class="hl-col-toggles">
		<span style="color:#888;font-size:12px;">Show columns:</span>
		<?php
		$toggle_labels = array(
			'participant'       => 'Participant',
			'email'             => 'Email',
			'status'            => 'Invitee Status',
			'work_phone'        => 'Work Phone',
			'mobile_phone'      => 'Mobile Phone',
			'amount_ordered'    => 'Amount Ordered',
			'amount_paid'       => 'Amount Paid',
			'discounts_applied' => 'Discounts Applied',
			'balance_due'       => 'Amount Due',
			'payment_type'      => 'Payment Type',
			'work_city'         => 'Work City',
			'work_state'        => 'Work State',
			'discount_code'     => 'Discount Code',
		);
		foreach ( $toggle_labels as $slug => $label ) :
			$id = 'hl-toggle-' . str_replace( '_', '-', $slug );
		?>
		<label id="<?php echo esc_attr( $id ); ?>-wrap"<?php echo ( $slug === 'participant' && ! $is_past_event ) ? ' style="display:none;"' : ''; ?>>
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" data-col="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( $label ); ?>
		</label>
		<?php endforeach; ?>
		<em style="color:#aaa;font-size:11px;margin-left:4px;">(staff only)</em>
	</div>
	<?php endif; ?>

	<?php if ( empty( $attendees ) ) : ?>
	<div class="hl-roster-empty">
		<p>No active attendees found for this event.</p>
		<p style="font-size:12px;color:#aaa;">Total raw records fetched: <?php echo count( $attendees_raw ); ?></p>
	</div>
	<?php else : ?>
	<?php echo Hostlinks_Roster::render_table( $attendees, $is_past_event, 'hl' ); ?>
	<?php echo Hostlinks_Roster::render_totals( $attendees, 'hl' ); ?>
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
			if ( Hostlinks_Roster::attendee_has_identity( $sample ) ) {
				echo '<span style="color:green;">order-item expand includes identity ✓</span>';
			} else {
				echo '<span style="color:#b36b00;">stub expand — full attendee/contact lookup used</span>';
			}
		?>:</strong><br>
		<?php if ( ! empty( $attendees_raw ) ) : ?>
			<strong>First raw attendee record:</strong>
			<pre><?php echo esc_html( wp_json_encode( reset( $attendees_raw ), JSON_PRETTY_PRINT ) ); ?></pre>
			<?php
			$sample_att = reset( $attendees_raw );
			list( $dbg_city, $dbg_state ) = Hostlinks_Roster::extract_work_location( $sample_att );
			?>
			<strong>Extracted work location (first attendee):</strong>
			city=<?php echo esc_html( $dbg_city ?: '(empty)' ); ?>,
			state=<?php echo esc_html( $dbg_state ?: '(empty)' ); ?>
		<?php else : ?>
			<em style="color:#c00;">No attendee records — either order items were empty or no attendee UUIDs could be extracted.</em>
		<?php endif; ?>
	</div>
	<?php endif; ?>

</div>

<script>
(function() {
	var detailsCols = <?php echo wp_json_encode( Hostlinks_Roster::DETAILS_PRESET ); ?>;
	var prefix = 'hl';

	function colClass(slug) {
		return prefix + '-col-' + slug.replace(/_/g, '-');
	}

	function toggleCol(slug, show) {
		var cls = colClass(slug);
		var els = document.querySelectorAll('.' + cls);
		for (var i = 0; i < els.length; i++) {
			els[i].style.display = show ? 'table-cell' : 'none';
			els[i].classList[show ? 'add' : 'remove'](prefix + '-col-visible');
		}
		updateTotalsVisibility();
	}

	function updateTotalsVisibility() {
		var totals = document.querySelector('.hl-roster-totals');
		if (!totals) return;
		var amountSlugs = ['amount_ordered', 'amount_paid', 'discounts_applied', 'balance_due'];
		var any = false;
		for (var i = 0; i < amountSlugs.length; i++) {
			var card = totals.querySelector('.' + colClass(amountSlugs[i]));
			var chk = document.querySelector('[data-col="' + amountSlugs[i] + '"]');
			var on = chk && chk.checked;
			if (card) {
				card.style.display = on ? 'block' : 'none';
				card.classList[on ? 'add' : 'remove'](prefix + '-col-visible');
			}
			if (on) any = true;
		}
		totals.style.display = any ? 'flex' : 'none';
	}

	function setPreset(cols) {
		var checks = document.querySelectorAll('.hl-col-toggles [data-col]');
		for (var i = 0; i < checks.length; i++) {
			var slug = checks[i].getAttribute('data-col');
			var show = cols.indexOf(slug) !== -1;
			checks[i].checked = show;
			toggleCol(slug, show);
		}
	}

	var checks = document.querySelectorAll('.hl-col-toggles [data-col]');
	for (var i = 0; i < checks.length; i++) {
		(function(el) {
			el.addEventListener('change', function() {
				toggleCol(el.getAttribute('data-col'), el.checked);
			});
		})(checks[i]);
	}

	var signinBtn = document.getElementById('hl-view-signin');
	var detailsBtn = document.getElementById('hl-view-details');
	if (signinBtn) {
		signinBtn.addEventListener('click', function() {
			setPreset([]);
			signinBtn.classList.add('hl-roster-btn--active');
			if (detailsBtn) detailsBtn.classList.remove('hl-roster-btn--active');
		});
	}
	if (detailsBtn) {
		detailsBtn.addEventListener('click', function() {
			setPreset(detailsCols);
			detailsBtn.classList.add('hl-roster-btn--active');
			if (signinBtn) signinBtn.classList.remove('hl-roster-btn--active');
		});
	}
})();
</script>
</body>
</html>
<?php
exit;
