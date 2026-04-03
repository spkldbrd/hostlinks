<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb;

$table11 = $wpdb->prefix . 'event_details_list';
$table13 = $wpdb->prefix . 'event_marketer';

// ── Range filter ──────────────────────────────────────────────────────────────
$months_options = array(
	6  => '6 Months',
	12 => '1 Year',
	24 => '2 Years',
	36 => '3 Years',
	60 => '5 Years',
);

// Determine active range mode: 'months', 'current_year', or 'custom'.
$range_mode  = 'months';
$months_back = 12;
$date_from   = '';
$date_to     = '';

if ( isset( $_GET['range'] ) && $_GET['range'] === 'current_year' ) {
	$range_mode = 'current_year';
} elseif ( isset( $_GET['range'] ) && $_GET['range'] === 'custom'
	&& ! empty( $_GET['from'] ) && ! empty( $_GET['to'] ) ) {
	$range_mode = 'custom';
	$date_from  = sanitize_text_field( wp_unslash( $_GET['from'] ) );
	$date_to    = sanitize_text_field( wp_unslash( $_GET['to'] ) );
	// Validate dates — fall back to 1 year if malformed.
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from )
		|| ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
		$range_mode = 'months';
	}
} elseif ( isset( $_GET['months'] ) && isset( $months_options[ (int) $_GET['months'] ] ) ) {
	$months_back = (int) $_GET['months'];
}

// ── Nav URLs ──────────────────────────────────────────────────────────────────
$upcoming_url    = Hostlinks_Page_URLs::get_upcoming();
$past_events_url = Hostlinks_Page_URLs::get_past_events();
$reports_url     = get_permalink();

// Marketing Ops button
$mktops_btn_mode = get_option( 'hostlinks_mktops_btn', 'disabled' );
$mktops_url      = ( $mktops_btn_mode !== 'disabled' ) ? Hostlinks_Page_URLs::get_mktops_hub() : '';
$show_mktops_btn = false;
if ( $mktops_url ) {
	if ( $mktops_btn_mode === 'admin' && current_user_can( 'manage_options' ) ) {
		$show_mktops_btn = true;
	} elseif ( $mktops_btn_mode === 'admin_plus_mgr' ) {
		if ( current_user_can( 'manage_options' ) ) {
			$show_mktops_btn = true;
		} elseif ( class_exists( 'HMO_Access_Service' ) && HMO_Access_Service::current_user_is_marketing_admin() ) {
			$show_mktops_btn = true;
		}
	} elseif ( $mktops_btn_mode === 'all' && Hostlinks_Access::can_view_shortcode( 'hostlinks_reports' ) ) {
		$show_mktops_btn = true;
	}
}

// + Event button
$add_event_btn_mode = get_option( 'hostlinks_add_event_btn', 'disabled' );
$event_request_url  = ( $add_event_btn_mode !== 'disabled' ) ? Hostlinks_Page_URLs::get_event_request_form() : '';
$show_add_event_btn = false;
if ( $event_request_url ) {
	if ( $add_event_btn_mode === 'admin' && current_user_can( 'manage_options' ) ) {
		$show_add_event_btn = true;
	} elseif ( $add_event_btn_mode === 'all' && Hostlinks_Access::can_view_shortcode( 'hostlinks_reports' ) ) {
		$show_add_event_btn = true;
	}
}

// ── Resolve cutoff / end dates from mode ──────────────────────────────────────
if ( $range_mode === 'current_year' ) {
	$cutoff   = gmdate( 'Y' ) . '-01-01';
	$date_end = gmdate( 'Y-m-d' );
} elseif ( $range_mode === 'custom' ) {
	$cutoff   = gmdate( 'Y-m-01', strtotime( $date_from ) );
	$date_end = $date_to;
} else {
	$cutoff   = gmdate( 'Y-m-01', strtotime( "-{$months_back} months" ) );
	$date_end = gmdate( 'Y-m-d' );
}

$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT DATE_FORMAT(e.eve_start, '%%Y-%%m') AS month,
	        m.event_marketer_name               AS marketer,
	        SUM(e.eve_paid + e.eve_free)        AS total_regs,
	        SUM(e.eve_paid)                     AS paid_regs,
	        COUNT(e.eve_id)                     AS event_count
	 FROM   {$table11} e
	 JOIN   {$table13} m ON e.eve_marketer = m.event_marketer_id
	 WHERE  e.eve_status = '1'
	   AND  e.eve_start  >= %s
	   AND  e.eve_start  <= %s
	 GROUP  BY month, m.event_marketer_id
	 ORDER  BY month ASC, m.event_marketer_name ASC",
	$cutoff,
	$date_end
), ARRAY_A );

// ── Build full month labels (no gaps even if data is missing) ─────────────────
$labels    = array();
$ts        = strtotime( $cutoff );
$end_ym    = gmdate( 'Y-m', strtotime( $date_end ) );
while ( gmdate( 'Y-m', $ts ) <= $end_ym ) {
	$labels[] = gmdate( 'Y-m', $ts );
	$ts        = strtotime( '+1 month', $ts );
}

// ── Collect unique marketer names ─────────────────────────────────────────────
$all_marketers = array();
foreach ( $rows as $row ) {
	$all_marketers[ $row['marketer'] ] = true;
}
$all_marketers = array_keys( $all_marketers );
sort( $all_marketers );

// ── Index rows by [month][marketer] ──────────────────────────────────────────
$idx = array();
foreach ( $rows as $row ) {
	$idx[ $row['month'] ][ $row['marketer'] ] = $row;
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
	foreach ( $labels as $ym ) {
		$r           = isset( $idx[ $ym ][ $name ] ) ? $idx[ $ym ][ $name ] : null;
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

// ── Display labels: 'Y-m' → 'Jan 2025' ───────────────────────────────────────
$display_labels = array_map( function( $ym ) {
	return gmdate( 'M Y', strtotime( $ym . '-01' ) );
}, $labels );

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_events        = array_sum( array_map( 'array_sum', $datasets_count ) );
$total_registrations = array_sum( array_map( 'array_sum', $datasets_total ) );
$total_paid          = array_sum( array_map( 'array_sum', $datasets_paid ) );
$avg_per_event       = $total_events > 0 ? round( $total_registrations / $total_events, 1 ) : 0;

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

// ── JSON payload for Chart.js ─────────────────────────────────────────────────
$chart_data = array(
	'labels'    => $display_labels,
	'marketers' => $all_marketers,
	'total'     => $datasets_total,
	'paid'      => $datasets_paid,
	'count'     => $datasets_count,
	'top5'      => $top5_indices,
	'active'    => array_values( $active_marketer_names ),
);
?>
<div class="hostlinks-page">
<div class="hostlinks-container">

	<div class="hostlinks-actions">
		<a href="<?php echo esc_url( $upcoming_url ); ?>" class="hostlinks-btn">Upcoming Events</a>
		<a href="<?php echo esc_url( $past_events_url ); ?>" class="hostlinks-btn">Past Events</a>

		<select id="hl-reports-range" class="hostlinks-year-filter" aria-label="Date range" style="margin-left:auto;">
			<?php foreach ( $months_options as $val => $lbl ) : ?>
			<option value="months:<?php echo (int) $val; ?>"
				<?php selected( $range_mode === 'months' && $months_back === $val ); ?>>
				<?php echo esc_html( $lbl ); ?>
			</option>
			<?php endforeach; ?>
			<option value="current_year" <?php selected( $range_mode, 'current_year' ); ?>>Current Year</option>
			<option value="custom"       <?php selected( $range_mode, 'custom' ); ?>>Custom Range…</option>
		</select>

		<!-- Custom date range inputs — shown only when Custom Range is selected -->
		<span id="hl-custom-range"
			style="display:<?php echo $range_mode === 'custom' ? 'inline-flex' : 'none'; ?>;align-items:center;gap:6px;margin-left:6px;">
			<input type="date" id="hl-from" value="<?php echo esc_attr( $date_from ); ?>"
				style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" />
			<span style="color:#666;">to</span>
			<input type="date" id="hl-to" value="<?php echo esc_attr( $date_to ); ?>"
				style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" />
			<button id="hl-custom-go"
				style="padding:4px 10px;background:#0da2e7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">Go</button>
		</span>

		<a href="<?php echo esc_url( $reports_url ); ?>" class="hostlinks-btn hostlinks-btn--active">&#x1F4CA; Reports</a>
		<?php if ( $show_mktops_btn ) : ?>
		<a href="<?php echo esc_url( $mktops_url ); ?>" class="hostlinks-btn hostlinks-btn--mktops">&#x1F4CB; Marketing Ops</a>
		<?php endif; ?>
		<?php if ( $show_add_event_btn ) : ?>
		<a href="<?php echo esc_url( $event_request_url ); ?>" class="hostlinks-btn hostlinks-btn--add-event">&#x2B; Event</a>
		<?php endif; ?>
		<script>
		(function(){
			var sel      = document.getElementById('hl-reports-range');
			var custom   = document.getElementById('hl-custom-range');
			var fromEl   = document.getElementById('hl-from');
			var toEl     = document.getElementById('hl-to');
			var goBtn    = document.getElementById('hl-custom-go');
			var base     = '<?php echo esc_js( $reports_url ); ?>';

			sel.addEventListener('change', function() {
				var v = this.value;
				if (v === 'custom') {
					custom.style.display = 'inline-flex';
				} else if (v === 'current_year') {
					custom.style.display = 'none';
					window.location.href = base + '?range=current_year';
				} else {
					custom.style.display = 'none';
					var months = v.replace('months:', '');
					window.location.href = base + '?months=' + months;
				}
			});

			goBtn.addEventListener('click', function() {
				var from = fromEl.value;
				var to   = toEl.value;
				if (!from || !to) { alert('Please select both a start and end date.'); return; }
				if (from > to)    { alert('Start date must be before end date.'); return; }
				window.location.href = base + '?range=custom&from=' + from + '&to=' + to;
			});
		})();
		</script>
		<?php
		$_upd_raw     = get_option( 'last_data_updation', '' );
		$_upd_dt      = $_upd_raw ? DateTime::createFromFormat( 'Y-m-d', $_upd_raw ) : null;
		$last_updated = $_upd_dt ? $_upd_dt->format( 'm/d' ) : ( new DateTime() )->format( 'm/d' );
		?>
		<span class="hostlinks-updated">Updated: <?php echo esc_html( $last_updated ); ?></span>
	</div>

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

	<?php /* ── Main chart card ── */ ?>
	<div class="hl-reports-card">
		<div class="hl-reports-header">
			<h2 class="hl-reports-title">Month-over-Month Trends</h2>
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
			<span style="font-size:.85rem;color:#666;">Period: <?php echo esc_html( gmdate( 'M Y', strtotime( $cutoff ) ) ); ?> – <?php echo esc_html( gmdate( 'M Y' ) ); ?></span>
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
					$tot   = array_sum( $datasets_total[ $i ] );
					$paid  = array_sum( $datasets_paid[ $i ] );
					$cnt   = array_sum( $datasets_count[ $i ] );
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

	// Mode toggle buttons (Registrations / Paid Only / Events)
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
})();
}); // DOMContentLoaded
</script>
