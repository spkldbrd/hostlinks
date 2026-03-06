<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s     = Hostlinks_CVENT_API::get_settings();
$ready = ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && ! empty( $s['account_number'] );

// ── Action handlers ───────────────────────────────────────────────────────────

$notice      = '';
$sync_report = null;

// Sync All
if ( isset( $_POST['hostlinks_cvent_sync_all'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$sync_report = Hostlinks_CVENT_Sync::sync_all();
}

// Sync One
if ( isset( $_POST['hostlinks_cvent_sync_one'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$eve_id = intval( $_POST['hostlinks_cvent_eve_id'] ?? 0 );
	if ( $eve_id ) {
		$r = Hostlinks_CVENT_Sync::sync_one( $eve_id );
		$sync_report = array( 'results' => array( $r ), 'synced' => (int)('synced'==$r['action']), 'matched' => (int)('matched'==$r['action']), 'needs_review' => (int)('needs_review'==$r['action']), 'no_candidates' => (int)('no_candidates'==$r['action']), 'errors' => (int)('error'==$r['action']) );
	}
}

// Re-bootstrap (clear + re-run)
if ( isset( $_POST['hostlinks_cvent_rebootstrap'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );
	$eve_id = intval( $_POST['hostlinks_cvent_eve_id'] ?? 0 );
	if ( $eve_id ) {
		Hostlinks_CVENT_Sync::clear_cvent_mapping( $eve_id );
		$r = Hostlinks_CVENT_Sync::sync_one( $eve_id );
		$sync_report = array( 'results' => array( $r ), 'synced' => 0, 'matched' => 0, 'needs_review' => 0, 'no_candidates' => 0, 'errors' => 0 );
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
		$start_min = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $kw_start . ' -1 day' ) );
		$start_max = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( ( $kw_end ?: $kw_start ) . ' +1 day' ) );
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
$events = $wpdb->get_results(
	"SELECT eve_id, eve_location, eve_start, eve_end, eve_paid, eve_free,
	        cvent_event_id, cvent_event_title, cvent_match_score,
	        cvent_match_status, cvent_last_synced
	 FROM `{$tbl}`
	 WHERE eve_status = 1
	 ORDER BY eve_start DESC",
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

	<?php if ( ! $ready ) : ?>
		<div class="notice notice-warning">
			<p>CVENT credentials are not configured.
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cvent-settings' ) ); ?>">Go to CVENT Settings →</a></p>
		</div>
	<?php endif; ?>

	<?php echo $notice; ?>

	<?php if ( $sync_report ) : ?>
		<div class="notice notice-info is-dismissible">
			<p><strong>Sync complete:</strong>
			<?php echo (int)$sync_report['synced']; ?> synced &bull;
			<?php echo (int)$sync_report['matched']; ?> newly matched &bull;
			<?php echo (int)$sync_report['needs_review']; ?> need review &bull;
			<?php echo (int)$sync_report['no_candidates']; ?> no candidates &bull;
			<?php echo (int)$sync_report['errors']; ?> errors</p>
		</div>
		<details style="margin-bottom:16px;">
			<summary style="cursor:pointer;font-weight:600;padding:4px 0;">Sync details (click to expand)</summary>
			<table class="widefat striped" style="margin-top:8px;">
				<thead><tr><th>Event #</th><th>Result</th><th>Message</th><th>Paid</th><th>Free</th></tr></thead>
				<tbody>
				<?php foreach ( $sync_report['results'] as $r ) : ?>
					<tr>
						<td><?php echo (int)$r['eve_id']; ?></td>
						<td><?php echo hl_cvent_status_badge( $r['action'] ); ?></td>
						<td><?php echo esc_html( $r['message'] ); ?></td>
						<td><?php echo isset( $r['paid'] ) ? (int)$r['paid'] : '—'; ?></td>
						<td><?php echo isset( $r['free'] ) ? (int)$r['free'] : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
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
								<td><?php echo esc_html( isset( $ce['start'] ) ? wp_date( 'M j, Y', strtotime( $ce['start'] ) ) : '—' ); ?></td>
								<td><?php echo esc_html( isset( $ce['end'] )   ? wp_date( 'M j, Y', strtotime( $ce['end'] ) )   : '—' ); ?></td>
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
				$dates   = esc_html( wp_date( 'M j', strtotime( $ev['eve_start'] ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $ev['eve_end'] ?: $ev['eve_start'] ) ) );
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
		Auto-match requires score &ge; 90 with a &ge; 20-point gap over the second candidate.
		Lower scores are flagged as &ldquo;Needs Review&rdquo; for manual selection.
	</p>
</div>
