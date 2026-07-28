<?php
/**
 * Roster inner content — shared by both the AJAX handler and direct include paths.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

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

$_rc_do_refresh = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );
$_rc_loaded     = Hostlinks_Roster::load_order_items( $_rc_cvent_id, $_rc_row, $_rc_do_refresh );

if ( is_wp_error( $_rc_loaded ) ) {
	echo '<p class="hl-fe-error">Could not load roster. Please try again later.</p>';
	return;
}

$_rc_is_past   = $_rc_loaded['is_past'];
$_rc_items     = $_rc_loaded['items'];
$_rc_attendees = Hostlinks_Roster::build_rows( $_rc_items, $_rc_is_past, $_rc_cvent_id );

Hostlinks_Roster::maybe_schedule_finalize( $_rc_cvent_id, $eve_id, $_rc_row, $_rc_is_past );

$_rc_title      = Hostlinks_Roster::build_title( $_rc_row, $eve_id, $wpdb );
$_rc_count      = count( $_rc_attendees );
$_rc_start_date = ! empty( $_rc_row['eve_start'] ) ? date( 'F j, Y', strtotime( $_rc_row['eve_start'] ) ) : '';
$_rc_end_date   = ! empty( $_rc_row['eve_end'] ) && $_rc_row['eve_end'] !== $_rc_row['eve_start']
                  ? ' – ' . date( 'F j, Y', strtotime( $_rc_row['eve_end'] ) ) : '';
$_rc_logo       = get_option( 'hostlinks_roster_logo_url', '' );

// ── Chart data ────────────────────────────────────────────────────────────────

// Registration Trend: group by ISO week
$_rc_trend_raw = array();
foreach ( $_rc_attendees as $row ) {
	$date = trim( $row['reg_date'] ?? '' );
	if ( $date === '' ) {
		continue;
	}
	$ts = strtotime( $date );
	if ( ! $ts ) {
		continue;
	}
	$key = 'Week ' . (int) date( 'W', $ts ) . ' of ' . (int) date( 'o', $ts );
	if ( ! isset( $_rc_trend_raw[ $key ] ) ) {
		$_rc_trend_raw[ $key ] = array( 'ts' => $ts, 'count' => 0 );
	}
	$_rc_trend_raw[ $key ]['count']++;
}
uasort( $_rc_trend_raw, function ( $a, $b ) { return $a['ts'] - $b['ts']; } );
$_rc_trend_labels = array_keys( $_rc_trend_raw );
$_rc_trend_counts = array_column( $_rc_trend_raw, 'count' );
$_rc_has_trend    = ! empty( $_rc_trend_labels );

// Registration Type: status × discount code cross-tab
$_rc_type_statuses = array();
$_rc_type_codes    = array();
$_rc_type_matrix   = array();
foreach ( $_rc_attendees as $row ) {
	$status = $row['status'] !== '' ? $row['status'] : 'Unknown';
	$codes  = $row['discount_code'] !== '' ? array_map( 'trim', explode( ',', $row['discount_code'] ) ) : array( '' );
	foreach ( $codes as $code ) {
		$_rc_type_statuses[ $status ] = true;
		$_rc_type_codes[ $code ]      = true;
		$_rc_type_matrix[ $status ][ $code ] = ( $_rc_type_matrix[ $status ][ $code ] ?? 0 ) + 1;
	}
}
$_rc_code_list = array_keys( $_rc_type_codes );
usort( $_rc_code_list, function ( $a, $b ) {
	if ( $a === '' && $b !== '' ) return -1;
	if ( $b === '' && $a !== '' ) return 1;
	return strcasecmp( $a, $b );
} );
$_rc_status_list = array_keys( $_rc_type_statuses );
sort( $_rc_status_list );
$_rc_has_type = ! empty( $_rc_status_list );

// Row/col totals for the cross-tab
$_rc_row_totals = array();
$_rc_col_totals = array();
$_rc_grand_total = 0;
foreach ( $_rc_status_list as $s ) {
	$_rc_row_totals[ $s ] = array_sum( $_rc_type_matrix[ $s ] ?? array() );
	$_rc_grand_total     += $_rc_row_totals[ $s ];
}
foreach ( $_rc_code_list as $c ) {
	$_rc_col_totals[ $c ] = 0;
	foreach ( $_rc_status_list as $s ) {
		$_rc_col_totals[ $c ] += $_rc_type_matrix[ $s ][ $c ] ?? 0;
	}
}
?>
<div class="hl-fe-roster" data-is-past="<?php echo $_rc_is_past ? '1' : '0'; ?>">

	<div class="hl-fe-roster-header">
		<div>
			<h2 class="hl-fe-roster-title"><?php echo esc_html( $_rc_title ); ?></h2>
			<p class="hl-fe-roster-meta">
				<?php if ( $_rc_start_date ) echo esc_html( $_rc_start_date . $_rc_end_date ) . ' &nbsp;|&nbsp; '; ?>
				<?php echo (int) $_rc_count; ?> registrant<?php echo $_rc_count !== 1 ? 's' : ''; ?>
			</p>
		</div>
		<?php if ( $_rc_logo ) : ?>
		<div class="hl-fe-roster-actions">
			<img src="<?php echo esc_url( $_rc_logo ); ?>" alt="" class="hl-fe-roster-logo" />
		</div>
		<?php endif; ?>
	</div>

	<?php echo Hostlinks_Roster::render_totals( $_rc_attendees, 'hl-fe' ); ?>

	<?php if ( ( $_rc_has_trend || $_rc_has_type ) && $_rc_count > 0 ) : ?>
	<div class="hl-fe-charts-row">

		<?php if ( $_rc_has_trend ) : ?>
		<div class="hl-fe-chart-card">
			<div class="hl-fe-chart-title">Registration Trend</div>
			<canvas id="hl-trend-canvas" class="hl-fe-trend-canvas"></canvas>
		</div>
		<?php endif; ?>

		<?php if ( $_rc_has_type ) : ?>
		<div class="hl-fe-chart-card">
			<div class="hl-fe-chart-title">Registration Type</div>
			<div style="overflow-x:auto;">
			<table class="hl-fe-type-table">
				<thead>
					<tr>
						<th rowspan="2" class="hl-fe-type-status-hdr">Invitee Status</th>
						<?php if ( count( $_rc_code_list ) > 1 || ( count( $_rc_code_list ) === 1 && $_rc_code_list[0] !== '' ) ) : ?>
						<th colspan="<?php echo count( $_rc_code_list ); ?>" class="hl-fe-type-group-hdr">Discount Code</th>
						<?php endif; ?>
						<th rowspan="2">Totals</th>
					</tr>
					<tr>
						<?php foreach ( $_rc_code_list as $code ) : ?>
						<th><?php echo esc_html( $code === '' ? '(No Value)' : $code ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $_rc_status_list as $s ) : ?>
					<tr>
						<td class="hl-fe-type-label"><?php echo esc_html( $s ); ?></td>
						<?php foreach ( $_rc_code_list as $c ) :
							$val = $_rc_type_matrix[ $s ][ $c ] ?? 0;
							$rt  = $_rc_row_totals[ $s ];
							$pct = $rt > 0 ? round( $val / $rt * 100, 1 ) : 0;
						?>
						<td>
							<?php if ( $val > 0 ) : ?>
							<span class="hl-fe-type-num"><?php echo $val; ?></span><br>
							<span class="hl-fe-type-pct"><?php echo $pct; ?>%</span>
							<?php else : ?><span class="hl-fe-type-zero">0</span><?php endif; ?>
						</td>
						<?php endforeach; ?>
						<td><span class="hl-fe-type-num"><?php echo (int) $_rc_row_totals[ $s ]; ?></span></td>
					</tr>
					<?php endforeach; ?>
					<tr class="hl-fe-type-totals-row">
						<td class="hl-fe-type-label">Totals</td>
						<?php foreach ( $_rc_code_list as $c ) : ?>
						<td><span class="hl-fe-type-num"><?php echo (int) $_rc_col_totals[ $c ]; ?></span></td>
						<?php endforeach; ?>
						<td><span class="hl-fe-type-num"><?php echo (int) $_rc_grand_total; ?></span></td>
					</tr>
				</tbody>
			</table>
			</div>
		</div>
		<?php endif; ?>

	</div>
	<?php endif; ?>

	<div class="hl-fe-table-scroll">
		<?php echo Hostlinks_Roster::render_table( $_rc_attendees, $_rc_is_past, 'hl-fe' ); ?>
	</div>


</div>

<style>
.hl-fe-roster { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display:flex; flex-direction:column; height:100%; padding:8px 12px; box-sizing:border-box; min-width:0; }
.hl-fe-roster-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:10px; flex-shrink:0; }
.hl-fe-roster-title { font-size:1.3em; margin:0 0 4px; }
.hl-fe-roster-meta { font-size:.85em; color:#666; margin:0; }
.hl-fe-roster-actions { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.hl-fe-roster-logo { max-height:72px; max-width:240px; object-fit:contain; display:block; }
/* Charts row */
.hl-fe-charts-row { display:flex; gap:12px; flex-shrink:0; margin-bottom:10px; flex-wrap:wrap; }
.hl-fe-chart-card { flex:1; min-width:260px; border:1px solid #ddd; border-radius:4px; padding:10px 12px; background:#fff; box-sizing:border-box; }
.hl-fe-chart-title { font-size:.88em; font-weight:700; margin:0 0 8px; color:#1d2327; letter-spacing:.02em; }
.hl-fe-trend-canvas { width:100% !important; height:180px !important; display:block; }
.hl-fe-type-table { border-collapse:collapse; font-size:12px; width:100%; }
.hl-fe-type-table th { background:#f0f0f0; border:1px solid #ccc; padding:4px 8px; text-align:center; font-weight:600; font-size:11px; white-space:nowrap; }
.hl-fe-type-status-hdr { text-align:left; }
.hl-fe-type-group-hdr { background:#e4e9f0; }
.hl-fe-type-table td { border:1px solid #ddd; padding:5px 8px; text-align:center; vertical-align:middle; line-height:1.3; }
.hl-fe-type-label { text-align:left; font-weight:500; white-space:nowrap; }
.hl-fe-type-num { color:#0073aa; font-weight:700; }
.hl-fe-type-pct { color:#888; font-size:10px; }
.hl-fe-type-zero { color:#ccc; }
.hl-fe-type-totals-row td { font-weight:600; background:#f5f5f5; }
/* Table */
.hl-fe-table-scroll { flex:1; overflow:auto; min-height:0; -webkit-overflow-scrolling:touch; }
.hl-fe-roster-table { width:100%; border-collapse:collapse; font-size:13px; }
.hl-fe-roster-table th { background:#1d2327; color:#fff; padding:7px 10px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; border:1px solid #3c434a; white-space:nowrap; }
.hl-fe-roster-table td { padding:6px 10px; border:1px solid #ddd; vertical-align:top; }
.hl-fe-roster-table tr:nth-child(even) td { background:#f9f9f9; }
.hl-fe-num { color:#aaa; font-size:11px; width:30px; }
.hl-fe-sign-in { width:260px; min-width:160px; }
/* Financial totals */
.hl-fe-roster-totals { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; flex-shrink:0; }
.hl-fe-roster-total-card { flex:1 1 140px; border:1px solid #ddd; border-radius:4px; padding:10px 14px; font-size:12px; background:#fafafa; }
.hl-fe-roster-total-card strong { display:block; margin-bottom:6px; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#888; }
.hl-fe-roster-total-amount { display:block; font-size:1.45em; font-weight:700; color:#1d2327; }
<?php echo Hostlinks_Roster::optional_col_css( 'hl-fe' ); ?>
.hl-fe-error { color:#d63638; padding:20px 0; }
@media print {
	@page { size: landscape; margin: 0.5in; }
	body * { visibility:hidden; }
	body { background:#fff !important; margin:0 !important; padding:0 !important; }
	.hl-fe-roster { visibility:visible; position:absolute; left:0; top:0; width:100%; padding:0 16px; box-sizing:border-box; }
	.hl-fe-roster * { visibility:visible; }
	.hl-fe-roster-actions { display:flex !important; justify-content:flex-end; }
	.hl-fe-roster-btn, .hl-fe-roster-toggles, #hl-roster-col-toggles { display:none !important; }
	.hl-fe-roster-logo { display:block !important; max-height:72px; max-width:240px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-charts-row { display:flex !important; flex-wrap:wrap; }
	.hl-fe-chart-card { break-inside:avoid; }
	.hl-fe-roster-table { width:100%; border-collapse:collapse; }
	.hl-fe-roster-table th { background:#000 !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-roster-table td, .hl-fe-roster-table th { border:1px solid #666 !important; padding:5px 8px; }
	<?php echo Hostlinks_Roster::optional_col_visible_css( 'hl-fe' ); ?>
	.hl-fe-sign-in { width:200pt; }
	.hl-fe-roster-totals { display:flex !important; }
	.hl-fe-roster-total-card { display:block !important; }
}
</style>

<?php if ( $_rc_has_trend ) : ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
	var labels = <?php echo wp_json_encode( $_rc_trend_labels ); ?>;
	var counts = <?php echo wp_json_encode( $_rc_trend_counts ); ?>;
	var canvas = document.getElementById( 'hl-trend-canvas' );
	if ( ! canvas || ! labels.length ) return;

	new Chart( canvas.getContext( '2d' ), {
		type: 'line',
		data: {
			labels: labels,
			datasets: [ {
				data: counts,
				borderColor: '#7B68EE',
				backgroundColor: 'rgba(123,104,238,0.07)',
				borderWidth: 2,
				pointRadius: 5,
				pointBackgroundColor: '#7B68EE',
				tension: 0.25,
				fill: true,
			} ],
		},
		options: {
			responsive: false,
			plugins: { legend: { display: false } },
			scales: {
				x: {
					title: { display: true, text: 'Last registration date', font: { size: 10 } },
					ticks: { font: { size: 10 }, maxRotation: 30 },
				},
				y: {
					title: { display: true, text: 'Number of new registrations', font: { size: 10 } },
					beginAtZero: true,
					ticks: { stepSize: 1, font: { size: 10 } },
				},
			},
		},
	} );
} )();
</script>
<?php endif; ?>
