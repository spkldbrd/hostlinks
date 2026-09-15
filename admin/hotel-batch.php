<?php
/**
 * Settings → Hotels tab.
 *
 * Upload a hotel CSV and fill Hotel Recommendations on matching events.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$tab_url = admin_url( 'admin.php?page=hostlinks-settings&tab=hotels' );

if ( isset( $_GET['hl_hotel_sample'] ) ) {
	check_admin_referer( 'hostlinks_hotel_sample' );
	Hostlinks_Hotel_Batch::download_sample();
}

$notice  = '';
$preview = null;
$result  = null;

if ( isset( $_POST['hl_hotel_preview'] ) ) {
	check_admin_referer( 'hostlinks_hotel_batch' );
	$preview = Hostlinks_Hotel_Batch::preview_from_upload(
		$_FILES['hl_hotel_csv'] ?? array(),
		! empty( $_POST['hl_include_past'] ),
		! empty( $_POST['hl_replace_existing'] )
	);
	if ( is_wp_error( $preview ) ) {
		$notice  = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		$preview = null;
	}
}

if ( isset( $_POST['hl_hotel_apply'] ) ) {
	check_admin_referer( 'hostlinks_hotel_batch' );
	$token  = sanitize_text_field( wp_unslash( $_POST['hl_batch_token'] ?? '' ) );
	$result = Hostlinks_Hotel_Batch::apply( $token );
	if ( is_wp_error( $result ) ) {
		$notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		$result = null;
	} else {
		$n      = (int) $result['updated'];
		$notice = '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( sprintf( _n( 'Saved hotels on %d event.', 'Saved hotels on %d events.', $n ), $n ) )
			. '</p></div>';
	}
}

$future_count = count( Hostlinks_Hotel_Batch::candidate_events( false ) );
$sample_url   = wp_nonce_url( add_query_arg( 'hl_hotel_sample', '1', $tab_url ), 'hostlinks_hotel_sample' );
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Hotels — CSV importer</h2>
<p>Fill <strong>Hotel Recommendations</strong> on events that were created without hotel data. Matching uses <strong>city + state + date</strong>. Host name and event type are optional tie-breakers when more than one event shares that weekend (for example Grant Writing and Grant Management).</p>
<p class="description">There <?php echo ( 1 === (int) $future_count ) ? 'is' : 'are'; ?> <strong><?php echo (int) $future_count; ?></strong> future event<?php echo ( 1 === (int) $future_count ) ? '' : 's'; ?> right now. County is accepted on the sheet but is not stored in Hostlinks — it is only used if City is blank.</p>

<h3>CSV columns</h3>
<p>First row must be headers. Column names are not case-sensitive. Two hotels for the same event = two rows with the same City / State / Date.</p>
<p><a href="<?php echo esc_url( $sample_url ); ?>" class="button">Download sample CSV</a></p>

<table class="widefat striped" style="max-width:920px;margin:12px 0 20px;">
	<thead>
		<tr>
			<th style="width:140px;">Column</th>
			<th style="width:90px;">Required?</th>
			<th>What to put</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><code>City</code></td>
			<td>Yes*</td>
			<td>Event city, e.g. <code>Sandy</code>. Or use a <code>Location</code> column as <code>Sandy, UT</code> instead.</td>
		</tr>
		<tr>
			<td><code>State</code></td>
			<td>Recommended</td>
			<td><code>UT</code> or <code>Utah</code>. Needed when City is a separate column.</td>
		</tr>
		<tr>
			<td><code>Date</code></td>
			<td>Yes</td>
			<td>Event start (or any day of a 2-day class). Accepts <code>2026-12-08</code> or <code>12/8/2026</code>.</td>
		</tr>
		<tr>
			<td><code>Hotel Name</code></td>
			<td>Yes</td>
			<td>Required to save a hotel. Rows with a blank name are skipped.</td>
		</tr>
		<tr>
			<td><code>Address</code></td>
			<td>Optional</td>
			<td>One line is fine, e.g. <code>100 Main St, Sandy UT 84070</code>.</td>
		</tr>
		<tr>
			<td><code>Phone</code></td>
			<td>Optional</td>
			<td>Hotel phone if you have it.</td>
		</tr>
		<tr>
			<td><code>URL</code></td>
			<td>Optional</td>
			<td>Hotel website or booking link.</td>
		</tr>
		<tr>
			<td><code>Host Name</code></td>
			<td>Optional</td>
			<td>Tie-breaker when two events share the city and date.</td>
		</tr>
		<tr>
			<td><code>Type</code></td>
			<td>Optional</td>
			<td>e.g. <code>Grant Writing USA</code> or an abbreviation. Limits the match to that type.</td>
		</tr>
		<tr>
			<td><code>County</code></td>
			<td>Optional</td>
			<td>Ignored for matching unless City is empty, then the county name (minus “County”) is tried as the city.</td>
		</tr>
		<tr>
			<td><code>Location</code></td>
			<td>Optional</td>
			<td>Alternate to City + State: <code>Sandy, UT</code>. Same format as the event Location field.</td>
		</tr>
	</tbody>
</table>
<p class="description">* Provide either <code>City</code> (plus State) or <code>Location</code>. Also accepted: <code>Start Date</code>, <code>Hotel Address</code>, <code>Website</code>.</p>

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
	?>
	<div class="notice notice-info" style="margin:16px 0;padding:12px 16px;">
		<p style="margin:0;"><strong>Preview</strong> — <?php echo (int) $preview['csv_rows']; ?> CSV row(s),
			scanned <?php echo (int) $preview['events_scanned']; ?> event(s),
			<strong><?php echo (int) $match_n; ?></strong> will get hotels,
			<?php echo (int) $skip_n; ?> already have hotels,
			<?php echo (int) $unmatch_n; ?> CSV row(s) unmatched.
			Nothing has been saved yet.</p>
	</div>

	<?php if ( $match_n > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'hostlinks_hotel_batch' ); ?>
			<input type="hidden" name="hl_batch_token" value="<?php echo esc_attr( $preview['token'] ); ?>">
			<p>
				<button type="submit" name="hl_hotel_apply" value="1" class="button button-primary">
					Save hotels on <?php echo (int) $match_n; ?> event<?php echo ( 1 === $match_n ) ? '' : 's'; ?>
				</button>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="button">Cancel</a>
			</p>
		</form>

		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th>Event</th>
					<th>Start</th>
					<th>Host</th>
					<th>Incoming hotels</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['matches'] as $m ) :
				$edit  = admin_url( 'admin.php?page=booking-menu&edit_event=' . (int) $m['eve_id'] );
				$label = trim( ( $m['type'] ?? '' ) . ' — ' . ( $m['location'] ?? '' ), ' —' );
				if ( '' === $label ) {
					$label = 'Event #' . (int) $m['eve_id'];
				}
				$hotel_bits = array();
				foreach ( (array) ( $m['hotels'] ?? array() ) as $h ) {
					$hotel_bits[] = $h['name'] ?? '';
				}
				?>
				<tr>
					<td><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a></td>
					<td><?php echo esc_html( $m['start'] ?? '' ); ?></td>
					<td><?php echo esc_html( $m['host_name'] ?? '' ); ?></td>
					<td><?php echo esc_html( implode( '; ', array_filter( $hotel_bits ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p>No hotels will be saved. Check skipped and unmatched rows below.</p>
	<?php endif; ?>

	<?php if ( $skip_n > 0 ) : ?>
		<h3>Already have hotels</h3>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th>Event</th><th>Start</th><th>Reason</th></tr></thead>
			<tbody>
			<?php foreach ( $preview['skipped'] as $s ) :
				$label = trim( ( $s['type'] ?? '' ) . ' — ' . ( $s['location'] ?? '' ), ' —' );
				?>
				<tr>
					<td><?php echo esc_html( $label !== '' ? $label : ( 'Event #' . (int) ( $s['eve_id'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( $s['start'] ?? '' ); ?></td>
					<td><?php echo esc_html( $s['reason'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $unmatch_n > 0 ) : ?>
		<h3>Unmatched CSV rows</h3>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th>Line</th>
					<th>City</th>
					<th>State</th>
					<th>Date</th>
					<th>Hotel</th>
					<th>Reason</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['unmatched'] as $u ) : ?>
				<tr>
					<td><?php echo (int) ( $u['line'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $u['city'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['state'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['date'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['hotel_name'] ?? '' ); ?></td>
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
	<?php wp_nonce_field( 'hostlinks_hotel_batch' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hl_hotel_csv">CSV file</label></th>
			<td>
				<input type="file" id="hl_hotel_csv" name="hl_hotel_csv" accept=".csv,text/csv" required>
			</td>
		</tr>
		<tr>
			<th scope="row">Options</th>
			<td>
				<label style="display:block;margin-bottom:6px;">
					<input type="checkbox" name="hl_include_past" value="1">
					Include past events (not only start date today or later)
				</label>
				<label style="display:block;">
					<input type="checkbox" name="hl_replace_existing" value="1">
					Replace hotels that are already filled in
				</label>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hl_hotel_preview" value="1" class="button button-primary">Preview matches</button>
	</p>
</form>
