<?php
/**
 * Settings → Import / Export → Host & Venue sub-tab.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$tab_url = admin_url( 'admin.php?page=hostlinks-settings&tab=import-export&hl_ie=venue' );

$notice  = '';
$preview = null;
$result  = null;

if ( isset( $_POST['hl_venue_preview'] ) ) {
	check_admin_referer( 'hostlinks_venue_batch' );
	$preview = Hostlinks_Venue_Batch::preview_from_upload(
		$_FILES['hl_venue_csv'] ?? array(),
		! empty( $_POST['hl_include_past'] ),
		! empty( $_POST['hl_replace_existing'] )
	);
	if ( is_wp_error( $preview ) ) {
		$notice  = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		$preview = null;
	}
}

if ( isset( $_POST['hl_venue_apply'] ) ) {
	check_admin_referer( 'hostlinks_venue_batch' );
	$token  = sanitize_text_field( wp_unslash( $_POST['hl_batch_token'] ?? '' ) );
	$result = Hostlinks_Venue_Batch::apply( $token );
	if ( is_wp_error( $result ) ) {
		$notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		$result = null;
	} else {
		$n      = (int) $result['updated'];
		$notice = '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( sprintf( _n( 'Updated host/venue on %d event.', 'Updated host/venue on %d events.', $n ), $n ) )
			. '</p></div>';
	}
}

$future_count = count( Hostlinks_Venue_Batch::candidate_events( false ) );
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Host &amp; Venue — CSV importer</h2>
<p>Fill <strong>Host &amp; Venue</strong> and <strong>Additional Details</strong> fields on events (the same data shown at the top of GWU marketing pages after sync). Matching uses <strong>city + state + date</strong>. Host name and event type are optional tie-breakers when more than one event shares that weekend.</p>
<p><strong>Always run Test import first.</strong> Dry run only — nothing is written until you confirm.</p>
<p class="description">There <?php echo ( 1 === (int) $future_count ) ? 'is' : 'are'; ?> <strong><?php echo (int) $future_count; ?></strong> future event<?php echo ( 1 === (int) $future_count ) ? '' : 's'; ?> available to match.</p>

<h3>CSV columns</h3>
<p>First row must be headers. Names are not case-sensitive. One row per event.</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
	<?php wp_nonce_field( 'hostlinks_venue_sample' ); ?>
	<input type="hidden" name="action" value="hostlinks_venue_sample">
	<button type="submit" class="button">Download sample CSV</button>
</form>

<table class="widefat striped" style="max-width:960px;margin:12px 0 20px;">
	<thead>
		<tr>
			<th style="width:180px;">Column</th>
			<th style="width:90px;">Required?</th>
			<th>Maps to Edit Event</th>
		</tr>
	</thead>
	<tbody>
		<tr><td><code>City</code></td><td>Yes*</td><td>Match key; also updates event City when provided.</td></tr>
		<tr><td><code>State</code></td><td>Recommended</td><td>Match key; also updates event State.</td></tr>
		<tr><td><code>Date</code></td><td>Yes</td><td>Event start (or any day in range).</td></tr>
		<tr><td><code>Host Name</code></td><td>Optional</td><td>Host Name</td></tr>
		<tr><td><code>Displayed As</code></td><td>Optional</td><td>Displayed As (e.g. <code>Hosted by …</code>)</td></tr>
		<tr><td><code>Location Name</code></td><td>Optional</td><td>Location Name / Building</td></tr>
		<tr><td><code>Address Line 1–3</code></td><td>Optional</td><td>Street address lines</td></tr>
		<tr><td><code>ZIP</code></td><td>Optional</td><td>ZIP code</td></tr>
		<tr><td><code>Special Instructions</code></td><td>Optional</td><td>Special Instructions / Parking</td></tr>
		<tr><td><code>Parking File URL</code></td><td>Optional</td><td>Parking / Instructions File URL</td></tr>
		<tr><td><code>Type</code></td><td>Optional</td><td>Tie-breaker when two events share city/date.</td></tr>
	</tbody>
</table>
<p class="description">* Provide <code>City</code> + <code>State</code> or a <code>Location</code> column as <code>City, ST</code>. At least one host/venue column must have data on each row.</p>

<?php if ( $result && ! empty( $result['skipped'] ) ) : ?>
	<h3>Skipped on apply</h3>
	<table class="widefat striped" style="max-width:960px;">
		<thead><tr><th>Event</th><th>Reason</th></tr></thead>
		<tbody>
		<?php foreach ( $result['skipped'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['location'] ?? '' ); ?></td>
				<td><?php echo esc_html( $row['reason'] ?? '' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( $preview ) : ?>
	<?php
	$match_n   = count( $preview['matches'] );
	$unmatch_n = count( $preview['unmatched'] );
	$skip_n    = count( $preview['skipped'] );
	$q         = $preview['quality'] ?? array();
	$verdict   = $q['verdict'] ?? 'review';
	$q_colors  = array(
		'good'   => array( '#00a32a', '#edfaef' ),
		'review' => array( '#dba617', '#fcf9e8' ),
		'poor'   => array( '#d63638', '#fcf0f1' ),
	);
	$qc = $q_colors[ $verdict ] ?? $q_colors['review'];
	?>
	<div style="border:2px solid <?php echo esc_attr( $qc[0] ); ?>;background:<?php echo esc_attr( $qc[1] ); ?>;border-radius:6px;padding:14px 16px;margin:16px 0;max-width:920px;">
		<p style="margin:0 0 8px;font-size:15px;"><strong>Test import — nothing was written</strong></p>
		<p style="margin:0 0 10px;">
			Verdict: <strong style="color:<?php echo esc_attr( $qc[0] ); ?>;"><?php echo esc_html( $q['label'] ?? 'Needs review' ); ?></strong>
			— <?php echo esc_html( $q['summary'] ?? '' ); ?>
		</p>
		<table style="border-collapse:collapse;">
			<tr><td style="padding:2px 20px 2px 0;">CSV rows</td><td><strong><?php echo (int) $preview['csv_rows']; ?></strong></td></tr>
			<tr><td style="padding:2px 20px 2px 0;">Events that would be updated</td><td><strong><?php echo (int) $match_n; ?></strong></td></tr>
			<tr><td style="padding:2px 20px 2px 0;">Already have data (skipped)</td><td><strong><?php echo (int) $skip_n; ?></strong></td></tr>
			<tr><td style="padding:2px 20px 2px 0;">Unmatched CSV rows</td><td><strong><?php echo (int) $unmatch_n; ?></strong></td></tr>
		</table>
	</div>

	<?php if ( $match_n > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( $tab_url ); ?>" id="hl-venue-apply-form" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'hostlinks_venue_batch' ); ?>
			<input type="hidden" name="hl_batch_token" value="<?php echo esc_attr( $preview['token'] ); ?>">
			<p>
				<button type="submit" name="hl_venue_apply" value="1" class="button <?php echo ( 'poor' === $verdict ) ? 'button-secondary' : 'button-primary'; ?>">
					Run import on <?php echo (int) $match_n; ?> event<?php echo ( 1 === $match_n ) ? '' : 's'; ?>
				</button>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="button">Cancel</a>
			</p>
		</form>
		<script>
		document.getElementById('hl-venue-apply-form').addEventListener('submit', function(e) {
			if (!window.confirm(<?php echo wp_json_encode( 'Import host/venue onto ' . $match_n . ' event(s)? This writes to the database.' ); ?>)) {
				e.preventDefault();
			}
		});
		</script>

		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th>Event</th>
					<th>Start</th>
					<th>Incoming host/venue</th>
					<th>Warnings</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['matches'] as $m ) :
				$edit  = admin_url( 'admin.php?page=booking-menu&edit_event=' . (int) $m['eve_id'] );
				$label = trim( ( $m['type'] ?? '' ) . ' — ' . ( $m['location'] ?? '' ), ' —' );
				if ( '' === $label ) {
					$label = 'Event #' . (int) $m['eve_id'];
				}
				$warns = array_filter( (array) ( $m['warnings'] ?? array() ) );
				?>
				<tr>
					<td><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a></td>
					<td><?php echo esc_html( $m['start'] ?? '' ); ?></td>
					<td><?php echo esc_html( $m['summary'] ?? '' ); ?></td>
					<td style="font-size:12px;color:<?php echo $warns ? '#996800' : '#00a32a'; ?>;">
						<?php echo $warns ? esc_html( implode( '; ', $warns ) ) : 'OK'; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $unmatch_n > 0 ) : ?>
		<h3>Unmatched CSV rows</h3>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th>Line</th><th>City</th><th>State</th><th>Date</th><th>Host / Displayed As</th><th>Reason</th></tr></thead>
			<tbody>
			<?php foreach ( $preview['unmatched'] as $u ) : ?>
				<tr>
					<td><?php echo (int) ( $u['line'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $u['city'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['state'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['date'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['displayed_as'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['reason'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr style="margin:28px 0;">
	<h3>Upload a different file</h3>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $tab_url ); ?>">
	<?php wp_nonce_field( 'hostlinks_venue_batch' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hl_venue_csv">CSV file</label></th>
			<td><input type="file" id="hl_venue_csv" name="hl_venue_csv" accept=".csv,text/csv" required></td>
		</tr>
		<tr>
			<th scope="row">Options</th>
			<td>
				<label style="display:block;margin-bottom:6px;">
					<input type="checkbox" name="hl_include_past" value="1">
					Include past events
				</label>
				<label style="display:block;">
					<input type="checkbox" name="hl_replace_existing" value="1">
					Replace host/venue fields that are already filled in
				</label>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hl_venue_preview" value="1" class="button button-primary">Test import</button>
		<span class="description" style="margin-left:8px;">Dry run only.</span>
	</p>
</form>
