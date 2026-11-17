<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $wpdb, $post;
$timezone = new DateTimeZone( 'America/Los_Angeles' );
$table11 = $wpdb->prefix . 'event_details_list';
$table12 = $wpdb->prefix . 'event_type';
$table13 = $wpdb->prefix . 'event_marketer';
$table14 = $wpdb->prefix . 'event_instructor';

// ── Full event form (add new, add from CVENT, add from request, or edit) ────
if ( isset( $_GET['edit_event'] ) || isset( $_GET['add_event'] ) || isset( $_GET['add_cvent'] ) || isset( $_GET['add_request'] ) || isset( $_GET['copy_event'] ) ) {
	require_once __DIR__ . '/edit-event.php';
	return;
}

// ── Determine active year-month filter (default: current month) ──────────
$currentYear  = (int) date('Y');
$currentMonth = date('m');
$defaultSyear = $currentYear . '-' . $currentMonth;

$syear = ( isset( $_GET['syear'] ) && $_GET['syear'] !== '' )
	? sanitize_text_field( $_GET['syear'] )
	: $defaultSyear;

$syearParts  = explode( '-', $syear );
$filterYear  = isset( $syearParts[0] ) ? (int) $syearParts[0] : $currentYear;
// $filterMonth is 0 when showing the whole year (syear=YYYY with no -MM suffix).
$filterMonth = isset( $syearParts[1] ) ? (int) $syearParts[1] : 0;
$allMonths   = ( $filterMonth === 0 );

// ── Bulk action processing (POST) ────────────────────────────────────────
$sucessmsgnew = '';
if ( isset( $_POST['deleteentire'] ) ) {
	check_admin_referer( 'hostlinks_manage_events' );

	if ( $_POST['actiondelete'] === 'delete' ) {
		$users = isset( $_POST['users'] ) ? (array) $_POST['users'] : array();
		foreach ( $users as $user ) {
			$wpdb->update( $table11, array( 'eve_status' => 2 ), array( 'eve_id' => intval( $user ) ), array( '%d' ), array( '%d' ) );
		}
		do_action( 'hostlinks_event_updated' );
		update_option( 'last_data_updation', wp_date( 'Y-m-d H:i:s', null, $timezone ) );
		$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Event(s) deleted. <a href="admin.php?page=booking-menu&syear=' . esc_attr( $syear ) . '">Back to list</a></p></div>';
	}

	if ( $_POST['actiondelete'] === 'uppydate' ) {
		$users        = isset( $_POST['originalid'] ) ? (array) $_POST['originalid'] : array();
		$usersadvance = isset( $_POST['users'] )      ? (array) $_POST['users']      : array();
		if ( ! empty( $usersadvance ) ) {
			foreach ( $users as $key => $user ) {
				if ( in_array( $user, $usersadvance ) ) {
					$userid           = intval( $user );
					$eve_location     = sanitize_text_field( $_POST['eve_location'][ $key ] );
					$eve_paid         = intval( $_POST['eve_paid'][ $key ] );
					$eve_free         = intval( $_POST['eve_free'][ $key ] );
					$eve_tot_date     = sanitize_text_field( $_POST['evedate'][ $key ] );
					$_eveparts        = preg_split( '/ - /', $eve_tot_date, 2 );
					$_ts_start        = isset( $_eveparts[0] ) ? strtotime( trim( $_eveparts[0] ) ) : false;
					$_ts_end          = isset( $_eveparts[1] ) ? strtotime( trim( $_eveparts[1] ) ) : false;
					if ( $_ts_start && $_ts_end ) {
						$eve_start = date( 'Y-m-d', $_ts_start );
						$eve_end   = date( 'Y-m-d', $_ts_end );
					} else {
						// Unparseable date — preserve existing DB values rather than writing garbage.
						$_row         = $wpdb->get_row( $wpdb->prepare( "SELECT eve_start, eve_end, eve_tot_date FROM {$table11} WHERE eve_id = %d", $userid ), ARRAY_A );
						$eve_start    = $_row ? $_row['eve_start']    : '';
						$eve_end      = $_row ? $_row['eve_end']      : '';
						$eve_tot_date = $_row ? $_row['eve_tot_date'] : $eve_tot_date;
					}
					$eve_type         = intval( $_POST['eve_type'][ $key ] );
					$eve_zoom_array   = isset( $_POST['eve_zoom'] ) ? (array) $_POST['eve_zoom'] : array();
					$eve_zoom         = in_array( (string) $user, $eve_zoom_array ) ? 'yes' : '';
					$eve_marketer     = intval( $_POST['eve_marketer'][ $key ] );
					$eve_host_url     = esc_url_raw( trim( $_POST['eve_host_url'][ $key ] ) );
					$eve_roster_url   = esc_url_raw( trim( $_POST['eve_roster_url'][ $key ] ) );
					$eve_trainer_url  = esc_url_raw( trim( $_POST['eve_trainer_url'][ $key ] ) );
					$eve_web_url      = esc_url_raw( trim( $_POST['eve_web_url'][ $key ] ) );
					$eve_email_url    = esc_url_raw( trim( $_POST['eve_email_url'][ $key ] ?? '' ) );
					$eve_zoom_time    = sanitize_text_field( $_POST['eve_zoom_time'][ $key ] ?? '' );
					$hide_ids         = isset( $_POST['eve_public_hide_ids'] ) ? (array) $_POST['eve_public_hide_ids'] : array();
					$eve_public_hide  = in_array( (string) $user, $hide_ids ) ? 1 : 0;
					$eve_instructor   = intval( $_POST['eve_instructor'][ $key ] );
					// Auto-fill blank roster URL on update if roster page is configured.
					if ( ! $eve_roster_url ) {
						$roster_base = Hostlinks_Page_URLs::get_roster();
						if ( $roster_base ) {
							$eve_roster_url = rtrim( $roster_base, '/' ) . '/?eve_id=' . $userid;
						}
					}
					$wpdb->update(
						$table11,
						array(
							'eve_location'    => $eve_location,
							'eve_paid'        => $eve_paid,
							'eve_free'        => $eve_free,
							'eve_start'       => $eve_start,
							'eve_end'         => $eve_end,
							'eve_type'        => $eve_type,
							'eve_zoom'        => $eve_zoom,
							'eve_marketer'    => $eve_marketer,
							'eve_host_url'    => $eve_host_url,
							'eve_roster_url'  => $eve_roster_url,
							'eve_trainer_url' => $eve_trainer_url,
							'eve_web_url'     => $eve_web_url,
							'eve_email_url'   => $eve_email_url,
							'eve_zoom_time'   => $eve_zoom_time,
							'eve_public_hide' => $eve_public_hide,
							'eve_instructor'  => $eve_instructor,
							'eve_tot_date'    => $eve_tot_date,
						),
						array( 'eve_id' => $userid ),
						array( '%s','%d','%d','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s','%d','%d','%s' ),
						array( '%d' )
					);
				}
			}
			update_option( 'last_data_updation', wp_date( 'Y-m-d H:i:s', null, $timezone ) );
			do_action( 'hostlinks_event_updated' );
			// Bust Marketing Ops public REST cache so the front-end sees fresh data immediately.
			if ( class_exists( 'HMO_REST', false ) && is_callable( array( 'HMO_REST', 'flush_public_events_cache' ) ) ) {
				HMO_REST::flush_public_events_cache();
			}
			$sucessmsgnew = '<div class="updated below-h2" id="message"><p>Event(s) successfully updated.</p></div>';
		}
	}
}

// ── Pre-fetch lookup tables once (used for dropdowns in every row) ────────
$all_pending_toter   = $wpdb->get_results( "SELECT * FROM $table12 WHERE `event_type_status` = '1'", ARRAY_A );
$all_pending_toterx  = $wpdb->get_results( "SELECT * FROM $table13 WHERE `event_marketer_status` = '1'", ARRAY_A );
$all_pending_toterxx = $wpdb->get_results( "SELECT * FROM $table14 WHERE `event_instructor_status` = '1'", ARRAY_A );

// Pre-index active IDs as plain string arrays so the per-row archived-option
// check is a single in_array() call rather than a nested loop.
$_active_mktr_ids = array_column( $all_pending_toterx,  'event_marketer_id' );
$_active_inst_ids = array_column( $all_pending_toterxx, 'event_instructor_id' );

$_type_meta = array();
foreach ( $all_pending_toter as $_t ) {
	$_type_meta[ (int) $_t['event_type_id'] ] = $_t;
}

// ── Single JOIN query — eliminates N+1 per-row lookups ───────────────────
// NOTE: we deliberately DO NOT reference t.event_type_abbr here. That column
// arrived in DB v2.4, and if the migration has not yet run on a given site
// (stale option, silently-failed ALTER, etc.), referencing it would kill the
// whole SELECT and render the event list as empty. The dropdown gets the
// abbreviation from $all_pending_toter (SELECT * below, with ?? '' fallback),
// so omitting it from the JOIN costs nothing.
$all_pending_bookings = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT e.*,
		        t.event_type_name,
		        m.event_marketer_name,
		        i.event_instructor_name
		 FROM   {$table11} e
		 LEFT JOIN {$table12} t ON e.eve_type       = t.event_type_id
		 LEFT JOIN {$table13} m ON e.eve_marketer   = m.event_marketer_id
		 LEFT JOIN {$table14} i ON e.eve_instructor = i.event_instructor_id
		 WHERE  e.eve_status = '1' AND e.eve_start LIKE %s
		 ORDER BY e.eve_start ASC",
		$syear . '%'
	),
	ARRAY_A
);
$tot1 = count( $all_pending_bookings );

// Pre-fetch average daily registration rates for all displayed events in one query.
$_hl_avg_regs = array();
if ( $tot1 > 0 ) {
	$_hl_eve_ids  = array_map( fn( $r ) => (int) $r['eve_id'], $all_pending_bookings );
	$_hl_avg_regs = Hostlinks_DB::get_avg_daily_regs_bulk( $_hl_eve_ids );
}

$period_label = $allMonths
	? ( 'All of ' . $filterYear )
	: date( 'F Y', mktime( 0, 0, 0, $filterMonth, 1, $filterYear ) );

$month_names = array( 1 => 'Jan.', 2 => 'Feb.', 3 => 'Mar.', 4 => 'Apr.', 5 => 'May', 6 => 'Jun.',
	7 => 'Jul.', 8 => 'Aug.', 9 => 'Sep.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Dec.' );
?>
<div class="wrap hl-events-wrap">
	<?php echo $sucessmsgnew; ?>

	<div class="hl-events-card">
		<div class="hl-events-head">
			<div>
				<h1 class="hl-events-title">Events</h1>
				<p class="hl-events-sub">Showing <?php echo (int) $tot1; ?> event(s) for <?php echo esc_html( $period_label ); ?></p>
			</div>
			<a class="button button-primary hl-events-add" href="<?php echo esc_url( admin_url( 'admin.php?page=booking-menu&add_event=1' ) ); ?>">+ Add New Event</a>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=booking-menu&syear=' . rawurlencode( $syear ) ) ); ?>" id="posts-filter">
			<?php wp_nonce_field( 'hostlinks_manage_events' ); ?>
			<input type="hidden" value="list" name="mode">

			<div class="hl-events-toolbar">
				<div class="hl-events-bulk">
					<select id="bulk-action-selector-top" name="actiondelete">
						<option value="uppydate">Update</option>
						<option value="delete">Delete</option>
					</select>
					<input type="submit" value="Apply" class="button action" id="doaction" name="deleteentire">
				</div>
				<div class="hl-events-filters">
					<label class="hl-filter-label">Year
						<select id="hl-chooseyear">
							<?php
							$year_start = 2022;
							$year_end   = $currentYear + 1;
							for ( $yr = $year_end; $yr >= $year_start; $yr-- ) {
								echo '<option value="' . (int) $yr . '"' . selected( $yr, $filterYear, false ) . '>' . (int) $yr . '</option>';
							}
							?>
						</select>
					</label>
					<label class="hl-filter-label">Month
						<select id="hl-choosemonth">
							<option value="all" <?php selected( $allMonths ); ?>>All months</option>
							<?php
							for ( $m = 1; $m <= 12; $m++ ) {
								$mv = str_pad( (string) $m, 2, '0', STR_PAD_LEFT );
								echo '<option value="' . esc_attr( $mv ) . '"' . selected( $m, $filterMonth, false ) . '>' . esc_html( $month_names[ $m ] ) . '</option>';
							}
							?>
						</select>
					</label>
				</div>
				<input type="search" id="hl-events-search" class="hl-events-search" placeholder="Search events…" autocomplete="off">
			</div>

			<div class="hl-events-table-wrap">
			<table class="hl-events-table" id="hl-events-table">
				<thead>
					<tr>
						<th class="hl-col-check"><input type="checkbox" id="cb-select-all-1"></th>
						<th class="hl-col-location">Location</th>
						<th class="hl-col-date">Date</th>
						<th class="hl-col-type">Type</th>
						<th class="hl-col-num">Paid</th>
						<th class="hl-col-num">Free</th>
						<th class="hl-col-zoom">Zoom</th>
						<th class="hl-col-marketer">Marketer</th>
						<th class="hl-col-instructor">Instructor</th>
						<th class="hl-col-hide" title="Hide from the public event list">Hide</th>
						<th class="hl-col-proj" title="Projected total registrations at event date, based on avg daily pace since first CVENT sync">Proj. Total</th>
						<th class="hl-col-links">Links</th>
						<th class="hl-col-actions">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php
				if ( $tot1 > 0 ) {
					foreach ( $all_pending_bookings as $alldriver ) {
						$eve_id    = (int) $alldriver['eve_id'];
						$_tmeta    = $_type_meta[ (int) $alldriver['eve_type'] ] ?? array();
						$_tcolor   = $_tmeta['event_type_color'] ?? '';
						$_start_ts = ! empty( $alldriver['eve_start'] ) ? strtotime( $alldriver['eve_start'] ) : false;
						$_end_ts   = ! empty( $alldriver['eve_end'] ) ? strtotime( $alldriver['eve_end'] ) : false;
						$_compact  = '';
						if ( $_start_ts ) {
							$_compact = date( 'm/d', $_start_ts );
							if ( $_end_ts && $_end_ts !== $_start_ts ) {
								$_compact .= '-' . date( 'm/d', $_end_ts );
							}
						}
						$_stat      = $_hl_avg_regs[ $eve_id ] ?? null;
						$_eve_start = ! empty( $alldriver['eve_start'] ) ? strtotime( $alldriver['eve_start'] ) : 0;
						$_proj      = null;
						$_proj_tip  = '';
						$_days_left = 0;
						if ( $_stat !== null && $_eve_start > 0 ) {
							$_days_left = max( 0, (int) floor( ( $_eve_start - time() ) / DAY_IN_SECONDS ) );
							$_proj      = (int) round( $_stat['latest_total'] + $_stat['avg'] * $_days_left );
							$_proj_tip  = 'Now: ' . $_stat['latest_total'] . ' + (' . number_format( $_stat['avg'], 2 ) . '/day × ' . $_days_left . ' days)';
						}
						$edit_url = admin_url( 'admin.php?page=booking-menu&edit_event=' . $eve_id );
						$copy_url = admin_url( 'admin.php?page=booking-menu&copy_event=' . $eve_id );
						?>
					<tr id="user-<?php echo esc_attr( (string) $eve_id ); ?>" data-eve-id="<?php echo esc_attr( (string) $eve_id ); ?>">
						<th class="hl-col-check">
							<input type="checkbox" value="<?php echo esc_attr( (string) $eve_id ); ?>" class="administrator splchkkr" name="users[]">
						</th>
						<td class="hl-col-location" data-order="<?php echo esc_attr( $alldriver['eve_location'] ?? '' ); ?>">
							<p class="hidder"><?php echo esc_html( $alldriver['eve_location'] ?? '' ); ?></p>
							<input type="hidden" name="originalid[]" value="<?php echo esc_attr( (string) $eve_id ); ?>">
							<input type="hidden" class="hl-fld-host" name="eve_host_url[]" value="<?php echo esc_attr( $alldriver['eve_host_url'] ?? '' ); ?>">
							<input type="hidden" class="hl-fld-roster" name="eve_roster_url[]" value="<?php echo esc_attr( $alldriver['eve_roster_url'] ?? '' ); ?>">
							<input type="hidden" class="hl-fld-reg" name="eve_trainer_url[]" value="<?php echo esc_attr( $alldriver['eve_trainer_url'] ?? '' ); ?>">
							<input type="hidden" class="hl-fld-web" name="eve_web_url[]" value="<?php echo esc_attr( $alldriver['eve_web_url'] ?? '' ); ?>">
							<input type="hidden" class="hl-fld-email" name="eve_email_url[]" value="<?php echo esc_attr( $alldriver['eve_email_url'] ?? '' ); ?>">
							<input type="hidden" class="hl-fld-zoom-time" name="eve_zoom_time[]" value="<?php echo esc_attr( $alldriver['eve_zoom_time'] ?? '' ); ?>">
							<input type="text" class="hl-cell-input hl-loc-input" value="<?php echo esc_attr( $alldriver['eve_location'] ?? '' ); ?>" name="eve_location[]" required autocomplete="off">
						</td>
						<td class="hl-col-date" data-order="<?php echo esc_attr( $alldriver['eve_start'] ?? '' ); ?>">
							<p class="hidder"><?php echo esc_html( $alldriver['eve_start'] ?? '' ); ?></p>
							<span class="hl-date-wrap">
								<span class="hl-compact-date" title="<?php echo esc_attr( $alldriver['eve_tot_date'] ?? '' ); ?>"><?php echo esc_html( $_compact !== '' ? $_compact : '—' ); ?></span>
								<input type="text" name="evedate[]" class="sentinal inputfilder eventenddertot hl-date-hidden" id="eventenddertot<?php echo esc_attr( (string) $eve_id ); ?>" value="<?php echo esc_attr( $alldriver['eve_tot_date'] ?? '' ); ?>" required autocomplete="off">
							</span>
						</td>
						<td class="hl-col-type">
							<p class="hidder"><?php echo esc_html( $alldriver['event_type_name'] ?? '' ); ?></p>
							<div class="hl-type-badge" data-color="<?php echo esc_attr( $_tcolor ); ?>">
								<select name="eve_type[]" class="evetype hl-type-select" required>
									<option value="">—</option>
									<?php foreach ( $all_pending_toter as $alldriverx1 ) {
										$_opt_abbr  = $alldriverx1['event_type_abbr'] ?? '';
										$_opt_label = $_opt_abbr !== '' ? $_opt_abbr : $alldriverx1['event_type_name'];
										$_opt_title = $alldriverx1['event_type_name'];
										$_opt_color = $alldriverx1['event_type_color'] ?? '';
										?>
									<option value="<?php echo esc_attr( $alldriverx1['event_type_id'] ); ?>"
										data-color="<?php echo esc_attr( $_opt_color ); ?>"
										title="<?php echo esc_attr( $_opt_title ); ?>"
										<?php selected( $alldriverx1['event_type_id'], $alldriver['eve_type'] ); ?>><?php echo esc_html( $_opt_label ); ?></option>
									<?php } ?>
								</select>
							</div>
						</td>
						<td class="hl-col-num" data-order="<?php echo esc_attr( (string) (int) ( $alldriver['eve_paid'] ?? 0 ) ); ?>">
							<p class="hidder"><?php echo esc_html( $alldriver['eve_paid'] ?? '' ); ?></p>
							<input type="number" class="hl-cell-input hl-num-input" value="<?php echo esc_attr( $alldriver['eve_paid'] ?? '' ); ?>" name="eve_paid[]" required min="0">
						</td>
						<td class="hl-col-num" data-order="<?php echo esc_attr( (string) (int) ( $alldriver['eve_free'] ?? 0 ) ); ?>">
							<p class="hidder"><?php echo esc_html( $alldriver['eve_free'] ?? '' ); ?></p>
							<input type="number" class="hl-cell-input hl-num-input" value="<?php echo esc_attr( $alldriver['eve_free'] ?? '' ); ?>" name="eve_free[]" required min="0">
						</td>
						<td class="hl-col-zoom">
							<input type="checkbox" class="splchkkr" value="<?php echo esc_attr( (string) $eve_id ); ?>" name="eve_zoom[]" <?php checked( $alldriver['eve_zoom'] ?? '', 'yes' ); ?> title="Virtual / Zoom event">
						</td>
						<td class="hl-col-marketer">
							<p class="hidder"><?php echo esc_html( $alldriver['event_marketer_name'] ?? '' ); ?></p>
							<select name="eve_marketer[]" class="evetype hl-cell-select" required>
								<option value="">Please Choose</option>
								<?php foreach ( $all_pending_toterx as $alldriverx2 ) { ?>
								<option value="<?php echo esc_attr( $alldriverx2['event_marketer_id'] ); ?>" <?php selected( $alldriver['eve_marketer'], $alldriverx2['event_marketer_id'] ); ?>><?php echo esc_html( $alldriverx2['event_marketer_name'] ); ?></option>
								<?php } ?>
								<?php if ( $alldriver['eve_marketer'] && ! in_array( (string) $alldriver['eve_marketer'], $_active_mktr_ids ) && ! empty( $alldriver['event_marketer_name'] ) ) { ?>
								<option value="<?php echo esc_attr( $alldriver['eve_marketer'] ); ?>" selected style="color:#999;font-style:italic;"><?php echo esc_html( $alldriver['event_marketer_name'] ); ?> (archived)</option>
								<?php } ?>
							</select>
						</td>
						<td class="hl-col-instructor">
							<p class="hidder"><?php echo esc_html( $alldriver['event_instructor_name'] ?? '' ); ?></p>
							<select name="eve_instructor[]" class="evetype hl-cell-select" required>
								<option value="">Please Choose</option>
								<?php foreach ( $all_pending_toterxx as $alldriverx3 ) { ?>
								<option value="<?php echo esc_attr( $alldriverx3['event_instructor_id'] ); ?>" <?php selected( $alldriver['eve_instructor'], $alldriverx3['event_instructor_id'] ); ?>><?php echo esc_html( $alldriverx3['event_instructor_name'] ); ?></option>
								<?php } ?>
								<?php if ( $alldriver['eve_instructor'] && ! in_array( (string) $alldriver['eve_instructor'], $_active_inst_ids ) && ! empty( $alldriver['event_instructor_name'] ) ) { ?>
								<option value="<?php echo esc_attr( $alldriver['eve_instructor'] ); ?>" selected style="color:#999;font-style:italic;"><?php echo esc_html( $alldriver['event_instructor_name'] ); ?> (archived)</option>
								<?php } ?>
							</select>
						</td>
						<td class="hl-col-hide">
							<input type="checkbox" name="eve_public_hide_ids[]" value="<?php echo esc_attr( (string) $eve_id ); ?>" <?php checked( 1, intval( $alldriver['eve_public_hide'] ?? 0 ) ); ?> title="Hide from public event list">
						</td>
						<td class="hl-col-proj" data-order="<?php echo esc_attr( (string) ( $_proj !== null ? $_proj : -1 ) ); ?>">
							<?php if ( $_proj !== null ) : ?>
							<span class="hl-proj-num<?php echo $_days_left > 0 ? ' is-live' : ''; ?>" title="<?php echo esc_attr( $_proj_tip ); ?>"><?php echo esc_html( (string) $_proj ); ?></span>
							<?php else : ?>
							<span class="hl-proj-empty">—</span>
							<?php endif; ?>
						</td>
						<td class="hl-col-links">
							<button type="button" class="button hl-links-btn">Links</button>
						</td>
						<td class="hl-col-actions">
							<a href="<?php echo esc_url( $edit_url ); ?>" class="hl-icon-btn" title="Edit event" aria-label="Edit event #<?php echo (int) $eve_id; ?>">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
							</a>
							<div class="hl-row-menu">
								<button type="button" class="hl-icon-btn hl-menu-toggle" title="More actions" aria-haspopup="true" aria-expanded="false">
									<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
								</button>
								<div class="hl-row-menu-panel" hidden>
									<a href="<?php echo esc_url( $copy_url ); ?>" class="hl-row-menu-item">
										<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
										Copy event
									</a>
								</div>
							</div>
						</td>
					</tr>
						<?php
					}
				}
				?>
				</tbody>
			</table>
			</div>
		</form>
	</div>
</div>

<div id="hl-links-modal" class="hl-links-modal" hidden>
	<div class="hl-links-modal__backdrop" data-hl-links-close></div>
	<div class="hl-links-modal__panel" role="dialog" aria-modal="true" aria-labelledby="hl-links-title">
		<div class="hl-links-modal__header">
			<h2 id="hl-links-title" class="hl-links-modal__title">Links</h2>
			<button type="button" class="hl-links-modal__close" data-hl-links-close aria-label="Close">&times;</button>
		</div>
		<div class="hl-links-modal__body">
			<input type="hidden" id="hl-links-eve-id" value="">
			<div class="hl-links-row">
				<label for="hl-links-host">Host URL</label>
				<div class="hl-links-field">
					<input type="url" id="hl-links-host" class="hl-links-input" placeholder="https://">
					<button type="button" class="button hl-links-open" data-for="hl-links-host">Open</button>
					<button type="button" class="button hl-links-copy" data-for="hl-links-host">Copy</button>
				</div>
			</div>
			<div class="hl-links-row">
				<label for="hl-links-roster">Roster URL</label>
				<div class="hl-links-field">
					<input type="url" id="hl-links-roster" class="hl-links-input" placeholder="https://">
					<button type="button" class="button hl-links-open" data-for="hl-links-roster">Open</button>
					<button type="button" class="button hl-links-copy" data-for="hl-links-roster">Copy</button>
				</div>
			</div>
			<div class="hl-links-row">
				<label for="hl-links-reg">REG URL</label>
				<div class="hl-links-field">
					<input type="url" id="hl-links-reg" class="hl-links-input" placeholder="https://">
					<button type="button" class="button hl-links-open" data-for="hl-links-reg">Open</button>
					<button type="button" class="button hl-links-copy" data-for="hl-links-reg">Copy</button>
				</div>
			</div>
			<div class="hl-links-row">
				<label for="hl-links-web">Web / Sign-in URL</label>
				<div class="hl-links-field">
					<input type="url" id="hl-links-web" class="hl-links-input" placeholder="https://">
					<button type="button" class="button hl-links-open" data-for="hl-links-web">Open</button>
					<button type="button" class="button hl-links-copy" data-for="hl-links-web">Copy</button>
				</div>
			</div>
			<div class="hl-links-row">
				<label for="hl-links-email">Email URL</label>
				<div class="hl-links-field">
					<input type="url" id="hl-links-email" class="hl-links-input" placeholder="https://">
					<button type="button" class="button hl-links-open" data-for="hl-links-email">Open</button>
					<button type="button" class="button hl-links-copy" data-for="hl-links-email">Copy</button>
				</div>
			</div>
			<div class="hl-links-row">
				<label for="hl-links-zoom-time">Zoom Time</label>
				<div class="hl-links-field">
					<input type="text" id="hl-links-zoom-time" class="hl-links-input" placeholder="e.g. 9:30–4:30 EST">
				</div>
			</div>
		</div>
		<div class="hl-links-modal__footer">
			<p class="hl-links-status" id="hl-links-status" hidden></p>
			<button type="button" class="button" data-hl-links-close>Cancel</button>
			<button type="button" class="button button-primary" id="hl-links-save">Save</button>
		</div>
	</div>
</div>
