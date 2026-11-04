<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb;

$table11 = $wpdb->prefix . 'event_details_list';
$table13 = $wpdb->prefix . 'event_marketer';

// ── Range filter ──────────────────────────────────────────────────────────────
$__range        = Hostlinks_Toolbar::parse_reports_range_from_request();
$range_mode     = $__range['range_mode'];
$months_back    = $__range['months_back'];
$days_back      = $__range['days_back'];
$date_from      = $__range['date_from'];
$date_to        = $__range['date_to'];
$bucket_by_day  = ( $range_mode === 'days' );

// ── Resolve cutoff / end dates from mode ──────────────────────────────────────
if ( $range_mode === 'current_year' ) {
	$cutoff   = gmdate( 'Y' ) . '-01-01';
	$date_end = gmdate( 'Y-m-d' );
} elseif ( $range_mode === 'custom' ) {
	$cutoff   = gmdate( 'Y-m-01', strtotime( $date_from ) );
	$date_end = $date_to;
} elseif ( $range_mode === 'days' ) {
	$date_end = gmdate( 'Y-m-d' );
	$cutoff   = gmdate( 'Y-m-d', strtotime( '-' . ( $days_back - 1 ) . ' days' ) );
} else {
	$cutoff   = gmdate( 'Y-m-01', strtotime( "-{$months_back} months" ) );
	$date_end = gmdate( 'Y-m-d' );
}

// Day ranges use stored CVENT order dates (signups that day).
// Month / year / custom ranges still bucket by event start.
if ( $bucket_by_day ) {
	$rows = Hostlinks_DB::get_marketer_daily_order_rows( $cutoff, $date_end );
} else {
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE_FORMAT(e.eve_start, '%%Y-%%m') AS period,
		        m.event_marketer_name               AS marketer,
		        SUM(e.eve_paid + e.eve_free)        AS total_regs,
		        SUM(e.eve_paid)                     AS paid_regs,
		        COUNT(e.eve_id)                     AS event_count
		 FROM   {$table11} e
		 JOIN   {$table13} m ON e.eve_marketer = m.event_marketer_id
		 WHERE  e.eve_status = '1'
		   AND  e.eve_start  >= %s
		   AND  e.eve_start  <= %s
		 GROUP  BY period, m.event_marketer_id
		 ORDER  BY period ASC, m.event_marketer_name ASC",
		$cutoff,
		$date_end
	), ARRAY_A );
}

// ── Build full period labels (no gaps even if data is missing) ────────────────
$labels = array();
$ts     = strtotime( $cutoff );
if ( $bucket_by_day ) {
	$end_ts = strtotime( $date_end );
	while ( $ts <= $end_ts ) {
		$labels[] = gmdate( 'Y-m-d', $ts );
		$ts        = strtotime( '+1 day', $ts );
	}
} else {
	$end_ym = gmdate( 'Y-m', strtotime( $date_end ) );
	while ( gmdate( 'Y-m', $ts ) <= $end_ym ) {
		$labels[] = gmdate( 'Y-m', $ts );
		$ts        = strtotime( '+1 month', $ts );
	}
}

// ── Collect unique marketer names ─────────────────────────────────────────────
$all_marketers = array();
foreach ( $rows as $row ) {
	$all_marketers[ $row['marketer'] ] = true;
}
$all_marketers = array_keys( $all_marketers );
sort( $all_marketers );

// ── Index rows by [period][marketer] ─────────────────────────────────────────
$idx = array();
foreach ( $rows as $row ) {
	$idx[ $row['period'] ][ $row['marketer'] ] = $row;
}

// ── Build per-marketer dataset arrays ─────────────────────────────────────────
$datasets_total  = array();
$datasets_paid   = array();
$datasets_count  = array();
$marketer_totals = array();

foreach ( $all_marketers as $i => $name ) {
	$total_arr   = array();
	$paid_arr    = array();
	$count_arr   = array();
	$grand_total = 0;
	foreach ( $labels as $period ) {
		$r           = isset( $idx[ $period ][ $name ] ) ? $idx[ $period ][ $name ] : null;
		$total_arr[] = $r ? (int) $r['total_regs']  : 0;
		$paid_arr[]  = $r ? (int) $r['paid_regs']   : 0;
		$count_arr[] = $r ? (int) $r['event_count'] : 0;
		$grand_total += $r ? (int) $r['total_regs'] : 0;
	}
	$datasets_total[]          = $total_arr;
	$datasets_paid[]           = $paid_arr;
	$datasets_count[]          = $count_arr;
	$marketer_totals[ $i ]     = $grand_total;
}

// Top 5 indices by total registrations in the period
arsort( $marketer_totals );
$top5_indices = array_values( array_slice( array_keys( $marketer_totals ), 0, 5 ) );

// ── Display labels ────────────────────────────────────────────────────────────
if ( $bucket_by_day ) {
	$display_labels = array_map( function( $ymd ) {
		return gmdate( 'M j', strtotime( $ymd ) );
	}, $labels );
	$period_label = gmdate( 'M j, Y', strtotime( $cutoff ) ) . ' – ' . gmdate( 'M j, Y', strtotime( $date_end ) );
	$trends_title = 'Day-over-Day Trends';
} else {
	$display_labels = array_map( function( $ym ) {
		return gmdate( 'M Y', strtotime( $ym . '-01' ) );
	}, $labels );
	$period_label = gmdate( 'M Y', strtotime( $cutoff ) ) . ' – ' . gmdate( 'M Y', strtotime( $date_end ) );
	$trends_title = 'Month-over-Month Trends';
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_registrations = array_sum( array_map( 'array_sum', $datasets_total ) );
$total_paid          = array_sum( array_map( 'array_sum', $datasets_paid ) );
if ( $bucket_by_day ) {
	// Don't sum daily distinct-event counts (would double-count across days).
	$total_events = Hostlinks_DB::count_events_with_orders( $cutoff, $date_end );
} else {
	$total_events = array_sum( array_map( 'array_sum', $datasets_count ) );
}
$avg_per_event = $total_events > 0 ? round( $total_registrations / $total_events, 1 ) : 0;

// Marketer summary event counts (distinct events in range for day mode).
$marketer_order_summary = $bucket_by_day
	? Hostlinks_DB::get_marketer_order_summary( $cutoff, $date_end )
	: array();

// ── Active marketer names (for "Current Marketers" filter) ───────────────────
$active_marketer_names = $wpdb->get_col(
	"SELECT event_marketer_name FROM {$table13} WHERE event_marketer_status = 1"
);

// ── Year-over-Year data (always last 4 calendar years, active marketers only) ──
$yoy_current_year = (int) gmdate( 'Y' );
$yoy_years        = array( $yoy_current_year, $yoy_current_year - 1, $yoy_current_year - 2, $yoy_current_year - 3 );

$yoy_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT YEAR(e.eve_start)             AS yr,
	        m.event_marketer_name         AS marketer,
	        SUM(e.eve_paid + e.eve_free)  AS total_regs,
	        COUNT(e.eve_id)               AS event_count
	 FROM   {$table11} e
	 JOIN   {$table13} m ON e.eve_marketer = m.event_marketer_id
	 WHERE  e.eve_status = '1'
	   AND  m.event_marketer_status = 1
	   AND  YEAR(e.eve_start) >= %d
	 GROUP  BY yr, m.event_marketer_id
	 ORDER  BY m.event_marketer_name ASC, yr ASC",
	$yoy_current_year - 3
), ARRAY_A );

$yoy_idx       = array();
$yoy_marketers = array();
foreach ( $yoy_rows as $r ) {
	$yoy_idx[ $r['marketer'] ][ (int) $r['yr'] ] = array(
		'total' => (int) $r['total_regs'],
		'count' => (int) $r['event_count'],
	);
	$yoy_marketers[ $r['marketer'] ] = true;
}
$yoy_marketers = array_keys( $yoy_marketers );
sort( $yoy_marketers );

usort( $yoy_marketers, function( $a, $b ) use ( $yoy_idx, $yoy_current_year ) {
	$ta = $yoy_idx[ $a ][ $yoy_current_year ]['total'] ?? 0;
	$tb = $yoy_idx[ $b ][ $yoy_current_year ]['total'] ?? 0;
	return $tb - $ta;
} );

// ── Company-wide daily totals by CVENT order date (14/30-day bar chart) ───────
$company_daily      = array(
	'total' => array(),
	'paid'  => array(),
	'count' => array(),
);
$has_order_reg_data = false;
if ( $bucket_by_day ) {
	$order_totals       = Hostlinks_DB::get_company_daily_order_totals( $cutoff, $date_end );
	$has_order_reg_data = Hostlinks_DB::has_cvent_order_regs();
	foreach ( $labels as $ymd ) {
		$company_daily['total'][] = (int) ( $order_totals['total'][ $ymd ] ?? 0 );
		$company_daily['paid'][]  = (int) ( $order_totals['paid'][ $ymd ] ?? 0 );
		$company_daily['count'][] = (int) ( $order_totals['events'][ $ymd ] ?? 0 );
	}
}

// ── JSON payload for Chart.js ─────────────────────────────────────────────────
$chart_data = array(
	'labels'        => $display_labels,
	'marketers'     => $all_marketers,
	'total'         => $datasets_total,
	'paid'          => $datasets_paid,
	'count'         => $datasets_count,
	'top5'          => $top5_indices,
	'active'        => array_values( $active_marketer_names ),
	'bucketByDay'     => $bucket_by_day,
	'companyDaily'    => $company_daily,
	'hasOrderRegData' => $has_order_reg_data,
	'dateKeys'        => $bucket_by_day ? $labels : array(),
	'dayAjax'         => array(
		'url'   => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'hostlinks_day_order_regs' ),
	),
);
?>
<div class="hostlinks-page">
<div class="hostlinks-container">

	<?php Hostlinks_Toolbar::render_reports_style_actions_bar( 'reports', 'hostlinks_reports' ); ?>

	<?php /* ── Summary stat cards ── */ ?>
	<div class="hl-stat-row">
		<div class="hl-stat-card">
			<div class="hl-stat-value"><?php echo number_format( $total_registrations ); ?></div>
			<div class="hl-stat-label">Total Registrations</div>
		</div>
		<div class="hl-stat-card">
			<div class="hl-stat-value"><?php echo number_format( $total_paid ); ?></div>
			<div class="hl-stat-label">Paid Registrations</div>
		</div>
		<div class="hl-stat-card">
			<div class="hl-stat-value"><?php echo number_format( $total_events ); ?></div>
			<div class="hl-stat-label">Total Events</div>
		</div>
		<div class="hl-stat-card">
			<div class="hl-stat-value"><?php echo $avg_per_event; ?></div>
			<div class="hl-stat-label">Avg Registrations / Event</div>
		</div>
	</div>

	<?php if ( $bucket_by_day ) : ?>
	<?php /* ── Company daily bar chart by order/signup date ── */ ?>
	<div class="hl-reports-card">
		<div class="hl-reports-header">
			<div>
				<h2 class="hl-reports-title">Daily Totals</h2>
				<p style="margin:.25rem 0 0;font-size:.85rem;color:#666;">
					By CVENT order date (signups that day)
					<?php if ( ! $has_order_reg_data ) : ?>
						— run a CVENT sync to populate
					<?php endif; ?>
				</p>
			</div>
			<div class="hl-reports-toggles" id="hl-daily-bar-toggles">
				<button type="button" class="hl-toggle-btn hl-toggle-btn--active" data-bar-mode="total">Registrations</button>
				<button type="button" class="hl-toggle-btn" data-bar-mode="paid">Paid Only</button>
				<button type="button" class="hl-toggle-btn" data-bar-mode="count">Events</button>
			</div>
		</div>
		<div class="hl-chart-wrap" style="cursor:pointer;" title="Click a bar to see registrants">
			<canvas id="hlDailyBarChart"></canvas>
		</div>
		<p style="margin:.5rem 0 0;font-size:.8rem;color:#888;">Click a bar to list that day's registrants</p>
	</div>

	<div id="hl-day-regs-modal" class="hl-day-modal" hidden>
		<div class="hl-day-modal__backdrop" data-hl-day-close></div>
		<div class="hl-day-modal__panel" role="dialog" aria-modal="true" aria-labelledby="hl-day-regs-title">
			<div class="hl-day-modal__header">
				<h3 id="hl-day-regs-title" class="hl-day-modal__title">Registrants</h3>
				<button type="button" class="hl-day-modal__close" data-hl-day-close aria-label="Close">&times;</button>
			</div>
			<div id="hl-day-regs-body" class="hl-day-modal__body">
				<p class="hl-day-modal__loading">Loading…</p>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<?php /* ── Main chart card ── */ ?>
	<div class="hl-reports-card"<?php echo $bucket_by_day ? ' style="margin-top:1rem;"' : ''; ?>>
		<div class="hl-reports-header">
			<div>
				<h2 class="hl-reports-title"><?php echo esc_html( $trends_title ); ?></h2>
				<?php if ( $bucket_by_day ) : ?>
				<p style="margin:.25rem 0 0;font-size:.85rem;color:#666;">By CVENT order date (signups that day)</p>
				<?php endif; ?>
			</div>
			<div class="hl-reports-toggles">
				<button class="hl-toggle-btn hl-toggle-btn--active" data-mode="total">Registrations</button>
				<button class="hl-toggle-btn" data-mode="paid">Paid Only</button>
				<button class="hl-toggle-btn" data-mode="count">Events</button>
			</div>
		</div>
		<div class="hl-chart-wrap">
			<canvas id="hlReportsChart"></canvas>
		</div>
		<div class="hl-chart-controls">
			<button class="hl-toggle-btn" id="hl-top5-btn">Top 5 Performers</button>
			<button class="hl-toggle-btn" id="hl-current-btn">Current Marketers</button>
		</div>
	</div>

	<?php /* ── Marketer summary table ── */ ?>
	<div class="hl-reports-card" style="margin-top:1rem;">
		<div class="hl-reports-header">
			<h2 class="hl-reports-title">Marketer Summary</h2>
			<span style="font-size:.85rem;color:#666;">Period: <?php echo esc_html( $period_label ); ?></span>
		</div>
		<table class="hl-summary-table">
			<thead>
				<tr>
					<th>Marketer</th>
					<th>Total Regs</th>
					<th>Paid</th>
					<th>Free</th>
					<th>Events</th>
					<th>Avg / Event</th>
				</tr>
			</thead>
			<tbody>
				<?php
				// Sort marketers by total regs descending for the table
				$summary_rows = array();
				foreach ( $all_marketers as $i => $name ) {
					$tot  = array_sum( $datasets_total[ $i ] );
					$paid = array_sum( $datasets_paid[ $i ] );
					if ( $bucket_by_day && isset( $marketer_order_summary[ $name ] ) ) {
						$cnt = (int) $marketer_order_summary[ $name ]['events'];
					} else {
						$cnt = array_sum( $datasets_count[ $i ] );
					}
					$summary_rows[] = array(
						'name'  => $name,
						'total' => $tot,
						'paid'  => $paid,
						'free'  => $tot - $paid,
						'count' => $cnt,
						'avg'   => $cnt > 0 ? round( $tot / $cnt, 1 ) : 0,
					);
				}
				usort( $summary_rows, function( $a, $b ) { return $b['total'] - $a['total']; } );
				foreach ( $summary_rows as $sr ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $sr['name'] ); ?></strong>
						<?php if ( ! in_array( $sr['name'], $active_marketer_names, true ) ) : ?>
						<span style="color:#999;font-size:.8em;font-weight:normal;"> (inactive)</span>
						<?php endif; ?>
					</td>
					<td><?php echo number_format( $sr['total'] ); ?></td>
					<td><?php echo number_format( $sr['paid'] ); ?></td>
					<td><?php echo number_format( $sr['free'] ); ?></td>
					<td><?php echo number_format( $sr['count'] ); ?></td>
					<td><?php echo $sr['avg']; ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php /* ── Year-over-Year summary table ── */ ?>
	<div class="hl-reports-card" style="margin-top:1rem;">
		<div class="hl-reports-header">
			<h2 class="hl-reports-title">Year over Year</h2>
			<span style="font-size:.85rem;color:#666;">Active marketers &mdash; <?php echo (int)( $yoy_current_year - 3 ); ?> &ndash; <?php echo (int) $yoy_current_year; ?></span>
		</div>
		<table class="hl-summary-table">
			<thead>
				<tr>
					<th>Marketer</th>
					<?php foreach ( $yoy_years as $yr ) : ?>
					<th colspan="2" style="text-align:center;border-left:2px solid #e5e7eb;">
						<?php echo (int) $yr; ?>
					</th>
					<?php endforeach; ?>
				</tr>
				<tr>
					<th></th>
					<?php foreach ( $yoy_years as $yr ) : ?>
					<th style="border-left:2px solid #e5e7eb;color:#888;font-weight:500;font-size:.8rem;">Total</th>
					<th style="color:#888;font-weight:500;font-size:.8rem;">Avg/Class</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $yoy_marketers as $name ) :
					$has_any = false;
					foreach ( $yoy_years as $yr ) {
						if ( isset( $yoy_idx[ $name ][ $yr ] ) ) { $has_any = true; break; }
					}
					if ( ! $has_any ) continue;
				?>
				<tr>
					<td><strong><?php echo esc_html( $name ); ?></strong></td>
					<?php foreach ( $yoy_years as $yr ) :
						$d     = $yoy_idx[ $name ][ $yr ] ?? null;
						$total = $d ? $d['total'] : 0;
						$cnt   = $d ? $d['count'] : 0;
						$avg   = ( $cnt > 0 ) ? round( $total / $cnt, 1 ) : '—';
					?>
					<td style="border-left:2px solid #e5e7eb;">
						<?php echo $total > 0 ? number_format( $total ) : '<span style="color:#ccc;">—</span>'; ?>
					</td>
					<td><?php echo $total > 0 ? $avg : '<span style="color:#ccc;">—</span>'; ?></td>
					<?php endforeach; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr style="font-weight:600;background:#f8fafc;">
					<td>Total</td>
					<?php foreach ( $yoy_years as $yr ) :
						$yr_total = 0; $yr_cnt = 0;
						foreach ( $yoy_marketers as $name ) {
							$d = $yoy_idx[ $name ][ $yr ] ?? null;
							if ( $d ) { $yr_total += $d['total']; $yr_cnt += $d['count']; }
						}
						$yr_avg = $yr_cnt > 0 ? round( $yr_total / $yr_cnt, 1 ) : '—';
					?>
					<td style="border-left:2px solid #e5e7eb;"><?php echo $yr_total > 0 ? number_format( $yr_total ) : '—'; ?></td>
					<td><?php echo $yr_total > 0 ? $yr_avg : '—'; ?></td>
					<?php endforeach; ?>
				</tr>
			</tfoot>
		</table>
	</div>

</div><!-- .hostlinks-container -->
</div><!-- .hostlinks-page -->

<script>
var hlReportData = <?php echo wp_json_encode( $chart_data ); ?>;
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
(function() {
	var COLORS = [
		'#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6',
		'#06b6d4','#f97316','#ec4899','#84cc16','#6366f1',
		'#14b8a6','#a855f7','#fb923c','#4ade80','#60a5fa'
	];

	var d               = hlReportData;
	var mode            = 'total';
	var showTop5        = false;
	var showCurrentOnly = false;

	function buildDatasets() {
		return d.marketers.map(function(name, i) {
			var hidden = false;
			if ( showTop5        && d.top5.indexOf(i) === -1    ) hidden = true;
			if ( showCurrentOnly && d.active.indexOf(name) === -1 ) hidden = true;
			return {
				label:            name,
				data:             d[mode][i],
				borderColor:      COLORS[i % COLORS.length],
				backgroundColor:  COLORS[i % COLORS.length] + '22',
				borderWidth:      2,
				pointRadius:      3,
				pointHoverRadius: 6,
				tension:          0.35,
				fill:             false,
				hidden:           hidden,
			};
		});
	}

	var ctx   = document.getElementById('hlReportsChart').getContext('2d');
	var chart = new Chart(ctx, {
		type: 'line',
		data: {
			labels:   d.labels,
			datasets: buildDatasets(),
		},
		options: {
			responsive:          true,
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			plugins: {
				legend: {
					position: 'bottom',
					align:    'start',
					labels: {
						boxWidth: 12,
						padding:  16,
						font:     { size: 13 },
					},
				},
				tooltip: {
					callbacks: {
						title: function(items) { return items[0].label; },
					},
				},
			},
			scales: {
				x: {
					grid:  { color: 'rgba(0,0,0,.05)' },
					ticks: { font: { size: 12 }, maxRotation: 45 },
				},
				y: {
					beginAtZero: true,
					grid:  { color: 'rgba(0,0,0,.05)' },
					ticks: { font: { size: 12 }, precision: 0 },
				},
			},
		},
	});

	// Mode toggle buttons (Registrations / Paid Only / Events) — line chart only
	document.querySelectorAll('.hl-toggle-btn[data-mode]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.hl-toggle-btn[data-mode]').forEach(function(b) {
				b.classList.remove('hl-toggle-btn--active');
			});
			this.classList.add('hl-toggle-btn--active');
			mode = this.dataset.mode;
			chart.data.datasets = buildDatasets();
			chart.update();
		});
	});

	// Top 5 Performers toggle
	var top5Btn = document.getElementById('hl-top5-btn');
	if (top5Btn) {
		top5Btn.addEventListener('click', function() {
			showTop5 = !showTop5;
			this.classList.toggle('hl-toggle-btn--active', showTop5);
			chart.data.datasets = buildDatasets();
			chart.update();
		});
	}

	// Current Marketers toggle
	var currentBtn = document.getElementById('hl-current-btn');
	if (currentBtn) {
		currentBtn.addEventListener('click', function() {
			showCurrentOnly = !showCurrentOnly;
			this.classList.toggle('hl-toggle-btn--active', showCurrentOnly);
			chart.data.datasets = buildDatasets();
			chart.update();
		});
	}

	// Company-wide daily bar chart (Past 14 Days)
	var barCanvas = document.getElementById('hlDailyBarChart');
	if (barCanvas && d.bucketByDay && d.companyDaily) {
		var barMode = 'total';
		var barLabelsPlugin = {
			id: 'hlBarValueLabels',
			afterDatasetsDraw: function(c) {
				var ctx2 = c.ctx;
				c.data.datasets.forEach(function(ds, di) {
					var meta = c.getDatasetMeta(di);
					if (meta.hidden) return;
					meta.data.forEach(function(el, i) {
						var v = ds.data[i];
						if (v === null || v === undefined) return;
						var pos = el.tooltipPosition();
						ctx2.save();
						ctx2.fillStyle = '#334155';
						ctx2.font = '600 12px system-ui, sans-serif';
						ctx2.textAlign = 'center';
						ctx2.textBaseline = 'bottom';
						ctx2.fillText(String(v), pos.x, pos.y - 6);
						ctx2.restore();
					});
				});
			},
		};
		var barChart = new Chart(barCanvas.getContext('2d'), {
			type: 'bar',
			data: {
				labels: d.labels,
				datasets: [{
					label: 'Total',
					data: d.companyDaily[barMode],
					backgroundColor: 'rgba(13, 162, 231, 0.75)',
					borderColor: '#0da2e7',
					borderWidth: 1,
					borderRadius: 4,
					maxBarThickness: 48,
				}],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				layout: { padding: { top: 24 } },
				onClick: function(evt, elements) {
					if (!elements || !elements.length) return;
					var idx = elements[0].index;
					var ymd = (d.dateKeys && d.dateKeys[idx]) ? d.dateKeys[idx] : null;
					if (!ymd) return;
					openDayRegsModal(ymd, d.labels[idx] || ymd);
				},
				onHover: function(evt, elements) {
					evt.native.target.style.cursor = elements && elements.length ? 'pointer' : 'default';
				},
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							title: function(items) { return items[0].label; },
							afterBody: function() { return 'Click for registrant list'; },
						},
					},
				},
				scales: {
					x: {
						grid: { display: false },
						ticks: { font: { size: 12 }, maxRotation: 45 },
					},
					y: {
						beginAtZero: true,
						grace: '12%',
						grid: { color: 'rgba(0,0,0,.05)' },
						ticks: { font: { size: 12 }, precision: 0 },
					},
				},
			},
			plugins: [barLabelsPlugin],
		});
		document.querySelectorAll('#hl-daily-bar-toggles .hl-toggle-btn[data-bar-mode]').forEach(function(btn) {
			btn.addEventListener('click', function() {
				document.querySelectorAll('#hl-daily-bar-toggles .hl-toggle-btn[data-bar-mode]').forEach(function(b) {
					b.classList.remove('hl-toggle-btn--active');
				});
				this.classList.add('hl-toggle-btn--active');
				barMode = this.dataset.barMode;
				barChart.data.datasets[0].data = d.companyDaily[barMode];
				barChart.update();
			});
		});

		var modal = document.getElementById('hl-day-regs-modal');
		var modalTitle = document.getElementById('hl-day-regs-title');
		var modalBody = document.getElementById('hl-day-regs-body');
		function closeDayRegsModal() {
			if (!modal) return;
			modal.hidden = true;
			document.body.style.overflow = '';
		}
		function escHtml(s) {
			return String(s == null ? '' : s)
				.replace(/&/g, '&amp;').replace(/</g, '&lt;')
				.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
		}
		function openDayRegsModal(ymd, label) {
			if (!modal || !d.dayAjax) return;
			modal.hidden = false;
			document.body.style.overflow = 'hidden';
			modalTitle.textContent = 'Registrants — ' + label;
			modalBody.innerHTML = '<p class="hl-day-modal__loading">Loading…</p>';
			var url = d.dayAjax.url
				+ (d.dayAjax.url.indexOf('?') === -1 ? '?' : '&')
				+ 'action=hostlinks_day_order_regs&date=' + encodeURIComponent(ymd)
				+ '&_nonce=' + encodeURIComponent(d.dayAjax.nonce);
			fetch(url, { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(json) {
					if (!json || !json.success) {
						modalBody.innerHTML = '<p class="hl-day-modal__empty">'
							+ escHtml((json && json.data) || 'Could not load registrants.') + '</p>';
						return;
					}
					var rows = json.data.rows || [];
					modalTitle.textContent = 'Registrants — ' + label + ' (' + rows.length + ')';
					if (!rows.length) {
						modalBody.innerHTML = '<p class="hl-day-modal__empty">No stored order-date registrants for this day. Run a CVENT sync if this looks empty.</p>';
						return;
					}
					var html = '<table class="hl-day-modal__table"><thead><tr>'
						+ '<th>Name</th><th>Email</th><th>Company</th><th>Event</th><th>Type</th>'
						+ '</tr></thead><tbody>';
					rows.forEach(function(row) {
						var badge = row.paid === 'Paid'
							? '<span class="hl-day-modal__paid">Paid</span>'
							: '<span class="hl-day-modal__free">Free</span>';
						html += '<tr>'
							+ '<td>' + escHtml(row.name) + '</td>'
							+ '<td>' + escHtml(row.email || '—') + '</td>'
							+ '<td>' + escHtml(row.company || '—') + '</td>'
							+ '<td>' + escHtml(row.event) + '</td>'
							+ '<td>' + badge + '</td>'
							+ '</tr>';
					});
					html += '</tbody></table>';
					modalBody.innerHTML = html;
				})
				.catch(function() {
					modalBody.innerHTML = '<p class="hl-day-modal__empty">Could not load registrants.</p>';
				});
		}
		if (modal) {
			modal.querySelectorAll('[data-hl-day-close]').forEach(function(el) {
				el.addEventListener('click', closeDayRegsModal);
			});
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && modal && !modal.hidden) closeDayRegsModal();
			});
		}
	}
})();
}); // DOMContentLoaded
</script>
