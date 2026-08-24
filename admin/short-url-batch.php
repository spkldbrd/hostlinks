<?php
/**
 * Settings → Short Links tab.
 *
 * Upload a Long URL / Short URL CSV and replace matching Web URLs on future events.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$notice  = '';
$preview = null;
$result  = null;

if ( isset( $_POST['hl_short_url_preview'] ) ) {
	check_admin_referer( 'hostlinks_short_url_batch' );
	$preview = Hostlinks_Short_URL_Batch::preview_from_upload( $_FILES['hl_short_url_csv'] ?? array() );
	if ( is_wp_error( $preview ) ) {
		$notice  = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		$preview = null;
	}
}

if ( isset( $_POST['hl_short_url_apply'] ) ) {
	check_admin_referer( 'hostlinks_short_url_batch' );
	$token  = sanitize_text_field( wp_unslash( $_POST['hl_batch_token'] ?? '' ) );
	$result = Hostlinks_Short_URL_Batch::apply( $token );
	if ( is_wp_error( $result ) ) {
		$notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		$result = null;
	} else {
		$n = (int) $result['updated'];
		$notice = '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( sprintf( _n( 'Updated Web URL on %d event.', 'Updated Web URL on %d events.', $n ), $n ) )
			. '</p></div>';
	}
}

$future_count = count( Hostlinks_Short_URL_Batch::future_events() );
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Short Links — future events</h2>
<p>Upload a CSV with <strong>Long URL</strong> and <strong>Short URL</strong> columns. Each Long URL is compared to the <strong>Web / Sign-in URL</strong> on events starting today or later. On a match, that field is replaced with the Short URL.</p>
<p class="description">UTM query strings are ignored when matching, so a long URL with campaign parameters still matches the stored event page URL. There <?php echo ( 1 === (int) $future_count ) ? 'is' : 'are'; ?> <strong><?php echo (int) $future_count; ?></strong> future event<?php echo ( 1 === (int) $future_count ) ? '' : 's'; ?> right now.</p>

<?php if ( $result ) : ?>
	<?php if ( ! empty( $result['skipped'] ) ) : ?>
		<h3>Skipped</h3>
		<table class="widefat striped" style="max-width:960px;">
			<thead><tr><th>Event</th><th>Reason</th></tr></thead>
			<tbody>
			<?php foreach ( $result['skipped'] as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['location'] ?? ( 'Event #' . (int) ( $row['eve_id'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( $row['reason'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $preview ) : ?>
	<?php
	$match_n     = count( $preview['matches'] );
	$unmatch_n   = count( $preview['unmatched'] );
	$already_n   = count( $preview['already'] );
	?>
	<div class="notice notice-info" style="margin:16px 0;padding:12px 16px;">
		<p style="margin:0;"><strong>Preview</strong> — <?php echo (int) $preview['csv_rows']; ?> CSV row(s),
			<strong><?php echo (int) $match_n; ?></strong> will be updated,
			<?php echo (int) $already_n; ?> already have the short URL,
			<?php echo (int) $unmatch_n; ?> CSV row(s) unmatched.
			Nothing has been saved yet.</p>
	</div>

	<?php if ( $match_n > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=hostlinks-settings&tab=short-links' ) ); ?>" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'hostlinks_short_url_batch' ); ?>
			<input type="hidden" name="hl_batch_token" value="<?php echo esc_attr( $preview['token'] ); ?>">
			<p>
				<button type="submit" name="hl_short_url_apply" value="1" class="button button-primary">
					Replace <?php echo (int) $match_n; ?> Web URL<?php echo ( 1 === $match_n ) ? '' : 's'; ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hostlinks-settings&tab=short-links' ) ); ?>" class="button">Cancel</a>
			</p>
		</form>

		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th>Event</th>
					<th>Start</th>
					<th>Current Web URL</th>
					<th>Short URL</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['matches'] as $m ) :
				$edit = admin_url( 'admin.php?page=booking-menu&edit_event=' . (int) $m['eve_id'] );
				$label = trim( ( $m['type'] ?? '' ) . ' — ' . ( $m['location'] ?? '' ), " —" );
				if ( '' === $label ) {
					$label = 'Event #' . (int) $m['eve_id'];
				}
				?>
				<tr>
					<td><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a></td>
					<td><?php echo esc_html( $m['start'] ?? '' ); ?></td>
					<td style="word-break:break-all;font-size:12px;"><a href="<?php echo esc_url( $m['current'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $m['current'] ); ?></a></td>
					<td style="word-break:break-all;font-size:12px;"><a href="<?php echo esc_url( $m['short'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $m['short'] ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p>No Web URLs will be changed. Check unmatched rows below, or confirm the Long URLs match the event page URLs stored on each event.</p>
	<?php endif; ?>

	<?php if ( $already_n > 0 ) : ?>
		<h3>Already short</h3>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th>Event</th><th>Start</th><th>Web URL</th></tr></thead>
			<tbody>
			<?php foreach ( $preview['already'] as $m ) :
				$label = trim( ( $m['type'] ?? '' ) . ' — ' . ( $m['location'] ?? '' ), " —" );
				?>
				<tr>
					<td><?php echo esc_html( $label !== '' ? $label : ( 'Event #' . (int) $m['eve_id'] ) ); ?></td>
					<td><?php echo esc_html( $m['start'] ?? '' ); ?></td>
					<td style="word-break:break-all;font-size:12px;"><?php echo esc_html( $m['current'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $unmatch_n > 0 ) : ?>
		<h3>Unmatched CSV rows</h3>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th>Line</th><th>Long URL</th><th>Reason</th></tr></thead>
			<tbody>
			<?php foreach ( $preview['unmatched'] as $u ) : ?>
				<tr>
					<td><?php echo (int) ( $u['line'] ?? 0 ); ?></td>
					<td style="word-break:break-all;font-size:12px;"><?php echo esc_html( $u['long'] ?? '' ); ?></td>
					<td><?php echo esc_html( $u['reason'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr style="margin:28px 0;">
	<h3>Upload a different file</h3>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=hostlinks-settings&tab=short-links' ) ); ?>">
	<?php wp_nonce_field( 'hostlinks_short_url_batch' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hl_short_url_csv">CSV file</label></th>
			<td>
				<input type="file" id="hl_short_url_csv" name="hl_short_url_csv" accept=".csv,text/csv" required>
				<p class="description">First row must be headers: <code>Long URL</code>, <code>Short URL</code>.</p>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hl_short_url_preview" value="1" class="button button-primary">Preview matches</button>
	</p>
</form>
