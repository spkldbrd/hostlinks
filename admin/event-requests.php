<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

// ── Handle status actions (GET with nonce) ────────────────────────────────────
$action_notice = '';
if ( ! empty( $_GET['hl_action'] ) && ! empty( $_GET['id'] ) && ! empty( $_GET['_wpnonce'] ) ) {
	$action_id = (int) $_GET['id'];
	if ( wp_verify_nonce( $_GET['_wpnonce'], 'hl_request_action_' . $action_id ) ) {
		$new_status = sanitize_text_field( $_GET['hl_action'] );

		// ── Resend notification email ─────────────────────────────────────
		if ( $new_status === 'resend_email' ) {
			$source_row = Hostlinks_Event_Request_Storage::get_by_id( $action_id );
			if ( $source_row ) {
				$group   = $source_row['submission_group'] ?? '';
				$all_rows = $group
					? Hostlinks_Event_Request_Storage::get_by_submission_group( $group )
					: array( $source_row );
				$ids     = array_column( $all_rows, 'id' );
				// Re-encode JSON blobs that get_by_id decodes, so send_notification
				// receives the same shape as the original submission.
				$records = array_map( function( $r ) {
					$r['host_contacts'] = wp_json_encode( is_array( $r['host_contacts'] ) ? $r['host_contacts'] : array() );
					$r['hotels']        = wp_json_encode( is_array( $r['hotels'] )        ? $r['hotels']        : array() );
					$r['cc_emails']     = wp_json_encode( is_array( $r['cc_emails'] )     ? $r['cc_emails']     : array() );
					return $r;
				}, $all_rows );
				Hostlinks_Event_Request_Shortcode::send_notification( $ids, $records );
				$action_notice = '<div class="notice notice-success is-dismissible"><p>Notification email re-sent for request #' . $action_id . ' (' . count( $all_rows ) . ' event' . ( count( $all_rows ) > 1 ? 's' : '' ) . ' in this submission).</p></div>';
			}

		// ── Standard status change ────────────────────────────────────────
		} elseif ( array_key_exists( $new_status, Hostlinks_Event_Request::STATUSES ) ) {
			Hostlinks_Event_Request_Storage::update_status( $action_id, $new_status );
			$label = Hostlinks_Event_Request::STATUSES[ $new_status ];
			$action_notice = '<div class="notice notice-success is-dismissible"><p>Request #' . $action_id . ' marked as <strong>' . esc_html( $label ) . '</strong>.</p></div>';
		}
	}
	// Redirect to clean URL after action.
	if ( empty( $action_notice ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=hostlinks-event-requests' ) );
		exit;
	}
}

// ── Detail view ───────────────────────────────────────────────────────────────
if ( ! empty( $_GET['id'] ) ) {
	$req = Hostlinks_Event_Request_Storage::get_by_id( (int) $_GET['id'] );
	if ( $req ) {
		include __DIR__ . '/event-request-detail.php';
		return;
	}
}

// ── List view ─────────────────────────────────────────────────────────────────
// Default to Pending (status=new) when no explicit filter is requested.
$status_filter = sanitize_text_field( $_GET['status'] ?? 'new' );
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page      = 25;

$result  = Hostlinks_Event_Request_Storage::get_list( $status_filter === 'all' ? '' : $status_filter, $per_page, $current_page );
$rows    = $result['rows'];
$total   = $result['total'];
$counts  = Hostlinks_Event_Request_Storage::count_by_status();
$total_all = array_sum( $counts );

$base_url = admin_url( 'admin.php?page=hostlinks-event-requests' );

// Status tab helper
function hl_status_tab( string $key, string $label, int $count, string $current, string $base ): string {
	$is_active = ( $key === $current );
	$url       = add_query_arg( 'status', $key, $base );
	$cls       = $is_active ? 'current' : '';
	return sprintf(
		'<li class="%s"><a href="%s">%s <span class="count">(%d)</span></a></li>',
		$cls, esc_url( $url ), esc_html( $label ), $count
	);
}
?>
<div class="wrap">
<h1 class="wp-heading-inline">Hostlinks — New Event Queue</h1>
<a href="<?php echo esc_url( admin_url( 'admin.php?page=hostlinks-event-request-settings' ) ); ?>"
	class="page-title-action">Settings</a>
<hr class="wp-header-end" />
<?php echo $action_notice; ?>

<!-- Queue tabs: Pending = new, Completed = converted. Archived + All kept for reference. -->
<ul class="subsubsub">
	<?php
	echo hl_status_tab( 'new',       'Pending',   (int) ( $counts['new']       ?? 0 ), $status_filter, $base_url );
	echo ' | ';
	echo hl_status_tab( 'converted', 'Completed', (int) ( $counts['converted'] ?? 0 ), $status_filter, $base_url );
	echo ' | ';
	echo hl_status_tab( 'archived',  'Archived',  (int) ( $counts['archived']  ?? 0 ), $status_filter, $base_url );
	echo ' | ';
	echo hl_status_tab( 'all',       'All',       $total_all,                            $status_filter, $base_url );
	?>
</ul>

<?php if ( empty( $rows ) ) : ?>
	<p style="margin-top:2em;">No event requests found<?php echo $status_filter ? ' with status "' . esc_html( Hostlinks_Event_Request::STATUSES[ $status_filter ] ?? $status_filter ) . '"' : ''; ?>.</p>
<?php else : ?>

<table class="wp-list-table widefat fixed striped" style="margin-top:1.5em;">
	<thead>
		<tr>
			<th style="width:50px;">ID</th>
			<th>Event Title</th>
			<th style="width:140px;">Category</th>
			<th style="width:90px;">Format</th>
			<th style="width:130px;">City, State</th>
			<th style="width:130px;">Dates</th>
			<th style="width:130px;">Submitted</th>
			<th style="width:90px;">Status</th>
			<th style="width:180px;">Actions</th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $rows as $row ) :
		$rid       = (int) $row['id'];
		$status    = $row['request_status'];
		$status_lbl= Hostlinks_Event_Request::STATUSES[ $status ] ?? ucfirst( $status );
		$detail_url= add_query_arg( 'id', $rid, $base_url );

		$status_badge_colors = array(
			'new'       => '#0da2e7',
			'reviewed'  => '#f0a500',
			'converted' => '#4caf50',
			'archived'  => '#9e9e9e',
		);
		$badge_color = $status_badge_colors[ $status ] ?? '#9e9e9e';

		$nonce_row    = wp_create_nonce( 'hl_request_action_' . $rid );

		$archive_url  = add_query_arg( array( 'hl_action' => 'archived',      'id' => $rid, '_wpnonce' => $nonce_row ), $base_url );
		$reopen_url   = add_query_arg( array( 'hl_action' => 'new',           'id' => $rid, '_wpnonce' => $nonce_row ), $base_url );
		$resend_url   = add_query_arg( array( 'hl_action' => 'resend_email',  'id' => $rid, '_wpnonce' => $nonce_row ), $base_url );
		$convert_url  = admin_url( 'admin.php?page=booking-menu&add_request=' . $rid );

		$city_state = trim( ( $row['city'] ? $row['city'] : '' ) . ( $row['state'] ? ', ' . $row['state'] : '' ), ', ' );
		$dates      = '';
		if ( $row['start_date'] ) {
			$dates = date( 'M j', strtotime( $row['start_date'] ) );
			if ( $row['end_date'] && $row['end_date'] !== $row['start_date'] ) {
				$dates .= ' – ' . date( 'M j, Y', strtotime( $row['end_date'] ) );
			} else {
				$dates .= ', ' . date( 'Y', strtotime( $row['start_date'] ) );
			}
		}
		$submitted = $row['submitted_at'] ? date( 'M j, Y', strtotime( $row['submitted_at'] ) ) : '—';
	?>
	<tr>
		<td><?php echo $rid; ?></td>
		<td><a href="<?php echo esc_url( $detail_url ); ?>"><strong><?php echo esc_html( $row['event_title'] ); ?></strong></a></td>
		<td><?php echo esc_html( $row['category'] ); ?></td>
		<td><?php echo esc_html( $row['format'] === 'virtual' ? 'Virtual' : 'In-Person' ); ?></td>
		<td><?php echo esc_html( $city_state ?: '—' ); ?></td>
		<td><?php echo esc_html( $dates ?: '—' ); ?></td>
		<td><?php echo esc_html( $submitted ); ?></td>
		<td>
			<span style="background:<?php echo $badge_color; ?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;white-space:nowrap;">
				<?php echo esc_html( $status_lbl ); ?>
			</span>
		</td>
		<td>
			<?php if ( $status === 'new' ) : ?>
				<a href="<?php echo esc_url( $convert_url ); ?>" class="button button-small button-primary">+ Add to Hostlinks</a>
				<br><a href="<?php echo esc_url( $detail_url ); ?>" style="font-size:12px;">View</a>
				&nbsp;|&nbsp;<a href="<?php echo esc_url( $resend_url ); ?>" style="font-size:12px;"
					onclick="return confirm('Re-send the notification email for this submission?');">Resend Email</a>
				&nbsp;|&nbsp;<a href="<?php echo esc_url( $archive_url ); ?>" style="font-size:12px;"
					onclick="return confirm('Archive this request?');">Archive</a>
			<?php else : ?>
				<a href="<?php echo esc_url( $detail_url ); ?>">View</a>
				&nbsp;|&nbsp;<a href="<?php echo esc_url( $resend_url ); ?>"
					onclick="return confirm('Re-send the notification email for this submission?');">Resend Email</a>
				<?php if ( $status !== 'archived' ) : ?>
					&nbsp;|&nbsp;<a href="<?php echo esc_url( $archive_url ); ?>"
						onclick="return confirm('Archive this request?');">Archive</a>
				<?php else : ?>
					&nbsp;|&nbsp;<a href="<?php echo esc_url( $reopen_url ); ?>">Re-open</a>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php
// Pagination
$total_pages = ceil( $total / $per_page );
if ( $total_pages > 1 ) :
	$paged_base = $status_filter ? add_query_arg( 'status', $status_filter, $base_url ) : $base_url;
?>
<div class="tablenav bottom" style="margin-top:8px;">
	<div class="tablenav-pages">
		<span class="displaying-num"><?php echo $total; ?> items</span>
		<?php for ( $p = 1; $p <= $total_pages; $p++ ) :
			$paged_url = add_query_arg( 'paged', $p, $paged_base );
			if ( $p === $current_page ) :
		?>
			<span class="page-numbers current"><?php echo $p; ?></span>
		<?php else : ?>
			<a href="<?php echo esc_url( $paged_url ); ?>" class="page-numbers"><?php echo $p; ?></a>
		<?php endif; endfor; ?>
	</div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
