<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s     = Hostlinks_CVENT_API::get_settings();
$ready = ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && ! empty( $s['account_number'] );

// ── Dry-run toggle (persistent via wp_option) ─────────────────────────────────
if ( isset( $_POST['hostlinks_cvent_toggle_dryrun'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$new_val = isset( $_POST['cvent_dry_run'] ) ? 1 : 0;
	update_option( 'hostlinks_cvent_dry_run', $new_val );
}
$dry_run = (bool) get_option( 'hostlinks_cvent_dry_run', 0 );

// ── Action handlers ───────────────────────────────────────────────────────────

$notice      = '';
$sync_report = null;

// Sync All
if ( isset( $_POST['hostlinks_cvent_sync_all'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$sync_report = Hostlinks_CVENT_Sync::sync_all( $dry_run );
}

// Sync One
if ( isset( $_POST['hostlinks_cvent_sync_one'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$eve_id = intval( $_POST['hostlinks_cvent_eve_id'] ?? 0 );
	if ( $eve_id ) {
		$r = Hostlinks_CVENT_Sync::sync_one( $eve_id, $dry_run );
		$sync_report = array( 'results' => array( $r ), 'dry_run' => $dry_run, 'synced' => (int)('synced'==$r['action']), 'matched' => (int)('matched'==$r['action']), 'needs_review' => (int)('needs_review'==$r['action']), 'no_candidates' => (int)('no_candidates'==$r['action']), 'errors' => (int)('error'==$r['action']) );
	}
}

// Re-bootstrap (clear + re-run)
if ( isset( $_POST['hostlinks_cvent_rebootstrap'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$eve_id = intval( $_POST['hostlinks_cvent_eve_id'] ?? 0 );
	if ( $eve_id ) {
		if ( ! $dry_run ) {
			Hostlinks_CVENT_Sync::clear_cvent_mapping( $eve_id );
		}
		$r = Hostlinks_CVENT_Sync::sync_one( $eve_id, $dry_run );
		$sync_report = array( 'results' => array( $r ), 'dry_run' => $dry_run, 'synced' => 0, 'matched' => 0, 'needs_review' => 0, 'no_candidates' => 0, 'errors' => 0 );
	}
}

// Unlink
if ( isset( $_POST['hostlinks_cvent_unlink'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$eve_id = intval( $_POST['hostlinks_cvent_eve_id'] ?? 0 );
	if ( $eve_id ) {
		Hostlinks_CVENT_Sync::clear_cvent_mapping( $eve_id );
		$notice = '<div class="notice notice-success is-dismissible"><p>CVENT link cleared for event #' . $eve_id . '.</p></div>';
	}
}

// Manual link: search CVENT candidates
$manual_candidates = null;
$manual_eve_id     = 0;
if ( isset( $_POST['hostlinks_cvent_manual_search'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$manual_eve_id = intval( $_POST['hostlinks_cvent_eve_id'] ?? 0 );
	$kw_start      = sanitize_text_field( $_POST['manual_start'] ?? '' );
	$kw_end        = sanitize_text_field( $_POST['manual_end']   ?? '' );
	if ( $kw_start ) {
	$start_min = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $kw_start . 'T12:00:00Z -1 day' ) );
	$start_max = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( ( $kw_end ?: $kw_start ) . 'T12:00:00Z +1 day' ) );
		$res = Hostlinks_CVENT_API::search_events( $start_min, $start_max );
		$manual_candidates = is_wp_error( $res ) ? array() : ( $res['data'] ?? array() );
	}
}

// Manual link: save selection
if ( isset( $_POST['hostlinks_cvent_manual_save'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$eve_id   = intval( $_POST['hostlinks_cvent_eve_id']    ?? 0 );
	$cvent_id = sanitize_text_field( $_POST['hostlinks_cvent_chosen_id'] ?? '' );
	if ( $eve_id && $cvent_id ) {
		$r = Hostlinks_CVENT_Sync::save_manual_link( $eve_id, $cvent_id );
		if ( is_wp_error( $r ) ) {
			$notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $r->get_error_message() ) . '</p></div>';
		} else {
			$notice = '<div class="notice notice-success is-dismissible"><p>Manual CVENT link saved for event #' . $eve_id . '. Run Sync to update counts.</p></div>';
		}
	}
}

// ── Load events for the table ─────────────────────────────────────────────────
global $wpdb;
$tbl   = $wpdb->prefix . 'event_details_list';
// Show only events ending within the last 60 days or in the future.
$cutoff = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
$events = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT eve_id, eve_location, eve_start, eve_end, eve_paid, eve_free,
		        cvent_event_id, cvent_event_title, cvent_match_score,
		        cvent_match_status, cvent_last_synced
		 FROM `{$tbl}`
		 WHERE eve_status = 1 AND eve_end >= %s
		 ORDER BY eve_start DESC",
		$cutoff
	),
	ARRAY_A
);

// ── Helpers ───────────────────────────────────────────────────────────────────

function hl_cvent_status_badge( $status ) {
	$map = array(
		'auto'          => array( 'green',  'Auto-matched' ),
		'manual'        => array( '#0073aa', 'Manual' ),
		'needs_review'  => array( '#d63638', 'Needs Review' ),
		'no_candidates' => array( '#888',   'No Candidates' ),
		'unlinked'      => array( '#888',   'Unlinked' ),
	);
	$info  = $map[ $status ] ?? array( '#888', ucfirst( $status ) );
	return '<span style="background:' . $info[0] . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">' . esc_html( $info[1] ) . '</span>';
}
?>
<div class="wrap">
	<h1>CVENT Sync</h1>

	<!-- Daily API call counter -->
	<?php
	$calls_today = Hostlinks_CVENT_API::get_call_count_today();
	$call_limit  = 1000;
	$call_pct    = min( round( $calls_today / $call_limit * 100 ), 100 );
	$bar_color   = $call_pct >= 90 ? '#d63638' : ( $call_pct >= 70 ? '#dba617' : '#0a6b00' );
	?>
	<div style="background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 16px;margin-bottom:16px;display:flex;align-items:center;gap:16px;">
		<div style="flex:0 0 auto;">
			<strong>API calls today:</strong>
			<span style="font-size:18px;font-weight:700;color:<?php echo $bar_color; ?>;margin-left:6px;"><?php echo $calls_today; ?></span>
			<span style="color:#888;font-size:12px;"> / <?php echo $call_limit; ?> (free tier daily limit)</span>
		</div>
		<div style="flex:1;background:#ddd;border-radius:3px;height:10px;max-width:300px;">
			<div style="width:<?php echo $call_pct; ?>%;background:<?php echo $bar_color; ?>;height:10px;border-radius:3px;transition:width .3s;"></div>
		</div>
		<div style="flex:0 0 auto;font-size:11px;color:#888;">Resets midnight UTC · <?php echo gmdate( 'M j, Y' ); ?></div>
	</div>

	<?php if ( ! $ready ) : ?>
		<div class="notice notice-warning">
			<p>CVENT credentials are not configured.
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cvent-settings' ) ); ?>">Go to CVENT Settings →</a></p>
		</div>
	<?php endif; ?>

	<!-- Dry Run toggle -->
	<form method="post" style="margin-bottom:0;">
		<?php wp_nonce_field( 'hostlinks_cvent_sync' ); ?>
		<div style="display:flex;align-items:center;gap:12px;background:<?php echo $dry_run ? '#fff3cd' : '#f6f7f7'; ?>;border:1px solid <?php echo $dry_run ? '#ffc107' : '#ddd'; ?>;padding:10px 16px;border-radius:4px;margin-bottom:16px;">
			<label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;margin:0;">
				<input type="checkbox" name="cvent_dry_run" value="1" <?php checked( $dry_run ); ?>
					onchange="this.form.submit();">
				Dry Run Mode
			</label>
			<?php if ( $dry_run ) : ?>
				<span style="background:#ffc107;color:#000;padding:2px 10px;border-radius:3px;font-size:12px;font-weight:700;">ACTIVE — nothing will be written to the database</span>
			<?php else : ?>
				<span style="color:#666;font-size:12px;">Enable to preview what Sync would do without saving any changes.</span>
			<?php endif; ?>
		</div>
		<input type="hidden" name="hostlinks_cvent_toggle_dryrun" value="1">
	</form>

	<?php echo $notice; ?>

	<?php if ( $sync_report ) : ?>
		<?php $is_dry = ! empty( $sync_report['dry_run'] ); ?>
		<div class="notice <?php echo $is_dry ? 'notice-warning' : 'notice-info'; ?> is-dismissible">
			<p>
				<?php if ( $is_dry ) : ?><strong>[DRY RUN — no data was saved]</strong> &nbsp;<?php endif; ?>
				<strong><?php echo $is_dry ? 'Preview complete:' : 'Sync complete:'; ?></strong>
				<?php echo (int)$sync_report['synced']; ?> <?php echo $is_dry ? 'would sync' : 'synced'; ?> &bull;
				<?php echo (int)$sync_report['matched']; ?> <?php echo $is_dry ? 'would match' : 'newly matched'; ?> &bull;
				<?php echo (int)$sync_report['needs_review']; ?> need review &bull;
				<?php echo (int)$sync_report['no_candidates']; ?> no candidates &bull;
				<?php echo (int)$sync_report['errors']; ?> errors
			</p>
		</div>
		<details open style="margin-bottom:16px;">
			<summary style="cursor:pointer;font-weight:600;padding:4px 0;"><?php echo $is_dry ? 'Dry Run preview details' : 'Sync details'; ?> (click to collapse)</summary>
			<?php foreach ( $sync_report['results'] as $r ) : ?>
				<div style="border:1px solid #ddd;border-radius:4px;padding:12px;margin:8px 0;background:#fff;">
					<table style="width:100%;border-collapse:collapse;">
						<tr>
							<td style="width:80px;font-weight:600;">Event #<?php echo (int)$r['eve_id']; ?></td>
							<td style="width:130px;"><?php echo hl_cvent_status_badge( $r['action'] ); ?><?php if ( $is_dry ) echo ' <span style="font-size:10px;color:#888;">(preview)</span>'; ?></td>
							<td><?php echo esc_html( $r['message'] ); ?></td>
							<td style="width:80px;text-align:center;">
								<?php if ( isset( $r['paid'] ) && $r['paid'] !== null ) : ?>
									<span style="color:#0a6b00;font-weight:600;"><?php echo (int)$r['paid']; ?> paid</span>
								<?php endif; ?>
							</td>
							<td style="width:80px;text-align:center;">
								<?php if ( isset( $r['free'] ) && $r['free'] !== null ) : ?>
									<span style="color:#0073aa;font-weight:600;"><?php echo (int)$r['free']; ?> free</span>
								<?php endif; ?>
							</td>
						</tr>
					</table>

					<?php if ( $is_dry && ! empty( $r['candidates'] ) ) : ?>
						<details style="margin-top:8px;">
							<summary style="cursor:pointer;color:#555;font-size:12px;">Match candidates (<?php echo count( $r['candidates'] ); ?>)</summary>
							<table class="widefat striped" style="margin-top:6px;font-size:12px;">
								<thead><tr><th>Score</th><th>Would auto-match?</th><th>CVENT Title</th><th>Start</th><th>CVENT ID</th></tr></thead>
								<tbody>
								<?php
								$top_score = $r['candidates'][0]['score'] ?? 0;
								foreach ( $r['candidates'] as $i => $cand ) :
									$would_match = ( $i === 0 && $top_score >= 90 && ( isset($r['candidates'][1]) ? ($top_score - $r['candidates'][1]['score']) >= 20 : true ) );
									$bd = $cand['breakdown'] ?? array();
									// Build a colour-coded breakdown label for each criterion.
									$pts = array(
										'SameDay'  => isset( $bd['dates_same_day'] ) ? (int)$bd['dates_same_day'] : null,
										'Overlap'  => isset( $bd['dates_overlap'] )  ? (int)$bd['dates_overlap']  : null,
										'City'     => isset( $bd['city'] )           ? (int)$bd['city']           : null,
										'State'    => isset( $bd['state'] )          ? (int)$bd['state']          : null,
										'Venue'    => isset( $bd['venue'] )          ? (int)$bd['venue']          : null,
										'Title'    => isset( $bd['title'] )          ? (int)$bd['title']          : null,
									);
								?>
									<tr style="<?php echo $would_match ? 'background:#e6f4ea;' : ''; ?>">
										<td><strong><?php echo (int)$cand['score']; ?></strong></td>
										<td><?php echo $would_match ? '<strong style="color:#0a6b00;">YES</strong>' : '<span style="color:#888;">No</span>'; ?></td>
										<td>
											<?php echo esc_html( $cand['event']['title'] ?? '(no title)' ); ?>
											<?php if ( ! empty( $bd ) ) : ?>
												<br><small style="color:#888;">
												<?php foreach ( $pts as $label => $val ) :
													if ( $val === null ) continue;
													$color = $val > 0 ? '#0a6b00' : '#cc1818';
												?>
													<span style="color:<?php echo $color; ?>;margin-right:6px;">
														<?php echo esc_html( $label ); ?>
														<strong><?php echo $val > 0 ? '+' . $val : '0'; ?></strong>
													</span>
												<?php endforeach; ?>
												<?php if ( isset( $bd['hl_city'], $bd['cv_city'] ) && $bd['hl_city'] !== '' ) : ?>
													| city: <em><?php echo esc_html( $bd['hl_city'] ); ?></em> vs <em><?php echo esc_html( $bd['cv_city'] ); ?></em>
												<?php endif; ?>
												<?php if ( isset( $bd['hl_state'], $bd['cv_state'] ) && $bd['hl_state'] !== '' ) : ?>
													| state: <em><?php echo esc_html( $bd['hl_state'] ); ?></em> vs <em><?php echo esc_html( $bd['cv_state'] ); ?></em>
												<?php endif; ?>
												<?php if ( isset( $bd['title_overlap'] ) && $bd['title_overlap'] > 0 ) : ?>
													| overlap: <?php echo esc_html( round( $bd['title_overlap'] * 100 ) ); ?>%
												<?php endif; ?>
												</small>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( isset( $cand['event']['start'] ) ? gmdate( 'M j, Y', strtotime( $cand['event']['start'] ) ) : '—' ); ?></td>
										<td><code style="font-size:10px;"><?php echo esc_html( $cand['event']['id'] ?? '' ); ?></code></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>

					<?php if ( $is_dry && ! empty( $r['attendees_preview'] ) ) : ?>
						<details style="margin-top:8px;">
							<summary style="cursor:pointer;color:#555;font-size:12px;">
								Attendee preview — first <?php echo count( $r['attendees_preview'] ); ?> of <?php echo (int)( $r['total_fetched'] ?? 0 ); ?> fetched
								(<?php echo (int)( $r['filtered_out'] ?? 0 ); ?> filtered out as cancelled/test)
							</summary>
							<table class="widefat striped" style="margin-top:6px;font-size:12px;">
								<thead><tr><th>Attendee ID</th><th>Status</th><th>Discount strings found</th><th>Counted as</th></tr></thead>
								<tbody>
								<?php foreach ( $r['attendees_preview'] as $att ) : ?>
									<tr>
										<td><code style="font-size:10px;"><?php echo esc_html( $att['id'] ); ?></code></td>
										<td><?php echo esc_html( $att['status'] ); ?></td>
										<td><?php echo esc_html( implode( ', ', $att['discount_strings'] ) ?: '(none)' ); ?></td>
										<td><strong style="color:<?php echo $att['counted_as'] === 'FREE' ? '#0073aa' : '#0a6b00'; ?>;">
											<?php echo esc_html( $att['counted_as'] ); ?>
										</strong></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php elseif ( $is_dry && isset( $r['total_fetched'] ) && $r['total_fetched'] === 0 ) : ?>
						<p style="margin:6px 0 0;font-size:12px;color:#888;">No attendees returned from CVENT for this event.</p>
					<?php endif; ?>

				</div>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>

	<?php if ( $manual_candidates !== null ) : ?>
		<div class="notice notice-info" style="margin-bottom:16px;">
			<p><strong><?php echo count( $manual_candidates ); ?> CVENT event(s) found in that date range.</strong>
			Pick one to link to Hostlinks event #<?php echo $manual_eve_id; ?>:</p>
			<?php if ( $manual_candidates ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'hostlinks_cvent_sync' ); ?>
					<input type="hidden" name="hostlinks_cvent_eve_id" value="<?php echo $manual_eve_id; ?>">
					<table class="widefat striped" style="margin:8px 0;">
						<thead><tr><th></th><th>CVENT Title</th><th>Start</th><th>End</th><th>Location</th><th>ID</th></tr></thead>
						<tbody>
						<?php foreach ( $manual_candidates as $ce ) :
							$cv_city = isset( $ce['venues'][0]['city'] ) ? $ce['venues'][0]['city'] : '';
							$cv_reg  = isset( $ce['venues'][0]['regionCode'] ) ? $ce['venues'][0]['regionCode'] : '';
							$cv_loc  = trim( $cv_city . ( $cv_reg ? ', ' . $cv_reg : '' ) );
						?>
							<tr>
								<td><input type="radio" name="hostlinks_cvent_chosen_id" value="<?php echo esc_attr( $ce['id'] ); ?>" required></td>
								<td><?php echo esc_html( $ce['title'] ?? '(no title)' ); ?></td>
								<td><?php echo esc_html( isset( $ce['start'] ) ? gmdate( 'M j, Y', strtotime( $ce['start'] ) ) : '—' ); ?></td>
								<td><?php echo esc_html( isset( $ce['end'] )   ? gmdate( 'M j, Y', strtotime( $ce['end'] ) )   : '—' ); ?></td>
								<td><?php echo esc_html( $cv_loc ?: '—' ); ?></td>
								<td><code style="font-size:10px;"><?php echo esc_html( $ce['id'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<button type="submit" name="hostlinks_cvent_manual_save" class="button button-primary">Save Manual Link</button>
				</form>
			<?php else : ?>
				<p>No events found — try a wider date range.</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Sync All button -->
	<form method="post" style="margin-bottom:16px;">
		<?php wp_nonce_field( 'hostlinks_cvent_sync' ); ?>
		<button type="submit" name="hostlinks_cvent_sync_all" class="button button-primary" <?php echo $ready ? '' : 'disabled'; ?>>
			Sync All Events
		</button>
		<span style="margin-left:12px;color:#666;font-size:12px;">Matches unlinked events and updates paid/free counts on all confirmed events.</span>
	</form>

	<!-- Events table -->
	<table class="widefat striped" id="cvent-events-table">
		<thead>
			<tr>
				<th>ID</th>
				<th>Dates</th>
				<th>Location</th>
				<th>CVENT Status</th>
				<th>CVENT Event</th>
				<th>Score</th>
				<th>Last Synced</th>
				<th style="min-width:280px;">Actions</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $events ) ) : ?>
			<tr><td colspan="8">No active events found.</td></tr>
		<?php else : ?>
			<?php foreach ( $events as $ev ) :
				$status  = $ev['cvent_match_status'] ?: 'unlinked';
				$eve_id  = (int) $ev['eve_id'];
				$loc     = esc_html( $ev['eve_location'] );
				// Use DateTime::createFromFormat to treat DB date strings as plain
			// calendar dates with no timezone conversion.
			$s_dt  = DateTime::createFromFormat( 'Y-m-d', $ev['eve_start'] );
			$e_dt  = DateTime::createFromFormat( 'Y-m-d', $ev['eve_end'] ?: $ev['eve_start'] );
			$dates = esc_html( $s_dt->format( 'M j' ) . ' – ' . $e_dt->format( 'M j, Y' ) );
				$cv_title = esc_html( $ev['cvent_event_title'] ?: '—' );
				$score    = isset( $ev['cvent_match_score'] ) && $ev['cvent_match_score'] !== null ? (int)$ev['cvent_match_score'] : '—';
				$synced   = $ev['cvent_last_synced'] ? esc_html( wp_date( 'M j, Y g:ia', strtotime( $ev['cvent_last_synced'] ) ) ) : '—';
			?>
			<tr>
				<td><?php echo $eve_id; ?></td>
				<td><?php echo $dates; ?></td>
				<td><?php echo $loc; ?></td>
				<td><?php echo hl_cvent_status_badge( $status ); ?></td>
				<td style="font-size:12px;"><?php echo $cv_title; ?></td>
				<td><?php echo $score; ?></td>
				<td style="font-size:11px;"><?php echo $synced; ?></td>
				<td>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'hostlinks_cvent_sync' ); ?>
						<input type="hidden" name="hostlinks_cvent_eve_id" value="<?php echo $eve_id; ?>">
						<button type="submit" name="hostlinks_cvent_sync_one" class="button button-small" <?php echo $ready ? '' : 'disabled'; ?>>Sync</button>
						<button type="submit" name="hostlinks_cvent_rebootstrap" class="button button-small" <?php echo $ready ? '' : 'disabled'; ?>>Re-bootstrap</button>
						<?php if ( $ev['cvent_event_id'] ) : ?>
							<button type="submit" name="hostlinks_cvent_unlink" class="button button-small"
								onclick="return confirm('Unlink CVENT event from #<?php echo $eve_id; ?>?');">Unlink</button>
						<?php endif; ?>
					</form>
					<!-- Manual Link (expands inline) -->
					<button class="button button-small" onclick="document.getElementById('ml-<?php echo $eve_id; ?>').style.display='block';this.style.display='none';">
						Manual Link
					</button>
					<div id="ml-<?php echo $eve_id; ?>" style="display:none;margin-top:8px;background:#f6f7f7;border:1px solid #ddd;padding:10px;border-radius:4px;">
						<form method="post">
							<?php wp_nonce_field( 'hostlinks_cvent_sync' ); ?>
							<input type="hidden" name="hostlinks_cvent_eve_id" value="<?php echo $eve_id; ?>">
							<label><strong>Date range to search:</strong></label><br>
							<input type="date" name="manual_start" value="<?php echo esc_attr( $ev['eve_start'] ); ?>" style="margin-right:4px;">
							to
							<input type="date" name="manual_end" value="<?php echo esc_attr( $ev['eve_end'] ?: $ev['eve_start'] ); ?>" style="margin-left:4px;">
							<button type="submit" name="hostlinks_cvent_manual_search" class="button button-small" style="margin-left:8px;" <?php echo $ready ? '' : 'disabled'; ?>>
								Search CVENT
							</button>
						</form>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<p style="margin-top:12px;color:#666;font-size:12px;">
		Showing events ending within the last 60 days or in the future.
		Auto-match requires score &ge; 90 with a &ge; 20-point gap over the second candidate.
		Lower scores are flagged as &ldquo;Needs Review&rdquo; for manual selection.
	</p>
</div>
