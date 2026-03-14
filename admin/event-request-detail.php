<?php
/**
 * Event Request detail view.
 * Included from admin/event-requests.php when ?id= is set and valid.
 * Expects $req = Hostlinks_Event_Request_Storage::get_by_id(int).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rid        = (int) $req['id'];
$status     = $req['request_status'];
$status_lbl = Hostlinks_Event_Request::STATUSES[ $status ] ?? ucfirst( $status );
$list_url   = admin_url( 'admin.php?page=hostlinks-event-requests' );
$base_url   = admin_url( 'admin.php?page=hostlinks-event-requests' );

$nonce_reviewed = wp_create_nonce( 'hl_request_action_' . $rid );
$nonce_archived = wp_create_nonce( 'hl_request_action_' . $rid );
$nonce_new      = wp_create_nonce( 'hl_request_action_' . $rid );
$reviewed_url   = add_query_arg( array( 'hl_action' => 'reviewed', 'id' => $rid, '_wpnonce' => $nonce_reviewed ), $base_url );
$archive_url    = add_query_arg( array( 'hl_action' => 'archived', 'id' => $rid, '_wpnonce' => $nonce_archived ), $base_url );
$reopen_url     = add_query_arg( array( 'hl_action' => 'new',      'id' => $rid, '_wpnonce' => $nonce_new      ), $base_url );

$badge_colors = array(
	'new'       => '#0da2e7',
	'reviewed'  => '#f0a500',
	'converted' => '#4caf50',
	'archived'  => '#9e9e9e',
);
$badge_color = $badge_colors[ $status ] ?? '#9e9e9e';

function hl_detail_row( string $label, string $value, bool $full = false ): void {
	if ( $value === '' ) return;
	echo '<tr>';
	echo '<th style="width:180px;padding:8px 12px;background:#f9f9f9;border:1px solid #e5e5e5;font-weight:600;vertical-align:top;">' . esc_html( $label ) . '</th>';
	echo '<td style="padding:8px 12px;border:1px solid #e5e5e5;">' . esc_html( $value ) . '</td>';
	echo '</tr>';
}

// Fetch sibling records from the same submission.
$siblings = array();
if ( ! empty( $req['submission_group'] ) ) {
	$siblings = Hostlinks_Event_Request_Storage::get_siblings( $rid, $req['submission_group'] );
}
?>
<div class="wrap">
<h1>
	<a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none;color:#50575e;font-size:14px;margin-right:8px;">← Event Requests</a>
	Event Request #<?php echo $rid; ?>
	<span style="background:<?php echo $badge_color; ?>;color:#fff;padding:3px 10px;border-radius:3px;font-size:13px;margin-left:8px;vertical-align:middle;">
		<?php echo esc_html( $status_lbl ); ?>
	</span>
</h1>

<!-- Sibling notice -->
<?php if ( ! empty( $siblings ) ) : ?>
<div style="background:#f0f6fc;border-left:4px solid #0da2e7;padding:10px 16px;margin:12px 0;border-radius:0 4px 4px 0;max-width:900px;">
	<strong>Submitted together with <?php echo count( $siblings ); ?> other event<?php echo count( $siblings ) > 1 ? 's' : ''; ?>:</strong>
	<ul style="margin:6px 0 0;padding-left:20px;">
	<?php foreach ( $siblings as $sib ) :
		$sib_url = admin_url( 'admin.php?page=hostlinks-event-requests&id=' . (int) $sib['id'] );
		$sib_badge = $badge_colors[ $sib['request_status'] ] ?? '#9e9e9e';
	?>
		<li>
			<a href="<?php echo esc_url( $sib_url ); ?>">#<?php echo (int) $sib['id']; ?></a>
			— <?php echo esc_html( $sib['category'] ); ?>
			<?php if ( $sib['start_date'] ) echo ' | ' . esc_html( $sib['start_date'] ); ?>
			<?php if ( $sib['trainer'] ) echo ' | Trainer: ' . esc_html( $sib['trainer'] ); ?>
			<span style="background:<?php echo $sib_badge; ?>;color:#fff;padding:1px 7px;border-radius:3px;font-size:11px;margin-left:6px;">
				<?php echo esc_html( Hostlinks_Event_Request::STATUSES[ $sib['request_status'] ] ?? $sib['request_status'] ); ?>
			</span>
		</li>
	<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<!-- Actions row -->
<div style="margin:12px 0 20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
	<?php if ( $status !== 'reviewed' && $status !== 'converted' ) : ?>
	<a href="<?php echo esc_url( $reviewed_url ); ?>" class="button button-primary">Mark Reviewed</a>
	<?php endif; ?>
	<?php if ( $status !== 'archived' ) : ?>
	<a href="<?php echo esc_url( $archive_url ); ?>" class="button"
		onclick="return confirm('Archive this request?');">Archive</a>
	<?php else : ?>
	<a href="<?php echo esc_url( $reopen_url ); ?>" class="button">Re-open</a>
	<?php endif; ?>
	<button class="button" disabled title="Convert to Event — available in a future phase" style="opacity:.5;cursor:not-allowed;">
		Convert to Event (coming soon)
	</button>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1100px;">

	<!-- LEFT: Event Details -->
	<div>
		<h2 style="font-size:15px;margin-bottom:8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Event Details</h2>
		<table style="width:100%;border-collapse:collapse;">
			<?php
			hl_detail_row( 'Category',  $req['category'] );
			hl_detail_row( 'Format',    $req['format'] === 'virtual' ? 'ZOOM / Virtual' : ( $req['format'] ? 'In-Person' : '' ) );
			hl_detail_row( 'Trainer',   $req['trainer'] );
			hl_detail_row( 'Marketer',  $req['marketer'] );
			hl_detail_row( 'Timezone',  $req['timezone'] );
			hl_detail_row( 'Start Date',$req['start_date'] ?? '' );
			hl_detail_row( 'End Date',  $req['end_date']   ?? '' );
			hl_detail_row( 'Capacity',  $req['max_attendees'] !== null ? (string) $req['max_attendees'] : 'Unlimited' );
			?>
		</table>

		<h2 style="font-size:15px;margin:20px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Host &amp; Venue</h2>
		<table style="width:100%;border-collapse:collapse;">
			<?php
			hl_detail_row( 'Host Name',     $req['host_name'] );
			hl_detail_row( 'Displayed As',  $req['displayed_as'] ?? '' );
			hl_detail_row( 'Location Name', $req['location_name'] );
			hl_detail_row( 'Address 1',     $req['street_address_1'] );
			hl_detail_row( 'Address 2',     $req['street_address_2'] );
			hl_detail_row( 'Address 3',     $req['street_address_3'] ?? '' );
			hl_detail_row( 'City',          $req['city'] );
			hl_detail_row( 'State',         $req['state'] );
			hl_detail_row( 'ZIP Code',      $req['zip_code'] );
			?>
		</table>

		<?php if ( ! empty( $req['special_instructions'] ) || ! empty( $req['parking_file_url'] ) ) : ?>
		<h2 style="font-size:15px;margin:20px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Special Instructions / Parking</h2>
		<table style="width:100%;border-collapse:collapse;">
			<?php if ( ! empty( $req['special_instructions'] ) ) : ?>
			<tr>
				<th style="width:180px;padding:8px 12px;background:#f9f9f9;border:1px solid #e5e5e5;font-weight:600;vertical-align:top;">Instructions</th>
				<td style="padding:8px 12px;border:1px solid #e5e5e5;"><?php echo nl2br( esc_html( $req['special_instructions'] ) ); ?></td>
			</tr>
			<?php endif; ?>
			<?php if ( ! empty( $req['parking_file_url'] ) ) : ?>
			<tr>
				<th style="width:180px;padding:8px 12px;background:#f9f9f9;border:1px solid #e5e5e5;font-weight:600;vertical-align:top;">Parking PDF</th>
				<td style="padding:8px 12px;border:1px solid #e5e5e5;">
					<a href="<?php echo esc_url( $req['parking_file_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( basename( $req['parking_file_url'] ) ); ?>
					</a>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php endif; ?>

		<?php if ( ! empty( $req['custom_email_intro'] ) ) : ?>
		<h2 style="font-size:15px;margin:20px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Custom Email Intro</h2>
		<div style="background:#f9f9f9;border:1px solid #e5e5e5;padding:10px 14px;border-radius:4px;">
			<?php echo nl2br( esc_html( $req['custom_email_intro'] ) ); ?>
		</div>
		<?php endif; ?>

		<!-- Submission metadata -->
		<h2 style="font-size:15px;margin:20px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Submission Info</h2>
		<table style="width:100%;border-collapse:collapse;">
			<?php
			hl_detail_row( 'Submitted',        $req['submitted_at'] );
			hl_detail_row( 'Updated',          $req['updated_at'] );
			hl_detail_row( 'Status',           $status_lbl );
			hl_detail_row( 'Submission Group', $req['submission_group'] ?? '' );
			?>
		</table>
	</div>

	<!-- RIGHT: Contacts & Hotels -->
	<div>
		<?php if ( ! empty( $req['host_contacts'] ) ) : ?>
		<h2 style="font-size:15px;margin-bottom:8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Host Contacts</h2>
		<?php foreach ( $req['host_contacts'] as $contact ) : ?>
		<div style="background:#f9f9f9;border:1px solid #e5e5e5;border-radius:4px;padding:10px 14px;margin-bottom:8px;">
			<strong><?php echo esc_html( $contact['name'] ); ?></strong>
			<?php if ( $contact['title'] ) echo ' — ' . esc_html( $contact['title'] ); ?>
			<?php if ( ! empty( $contact['cc_on_alerts'] ) ) : ?>
				<span style="background:#0da2e7;color:#fff;padding:1px 7px;border-radius:3px;font-size:11px;margin-left:6px;">CC on Alerts</span>
			<?php endif; ?>
			<br>
			<?php if ( $contact['agency'] ) echo esc_html( $contact['agency'] ) . '<br>'; ?>
			<?php if ( $contact['email'] )  echo esc_html( $contact['email'] )  . '<br>'; ?>
			<?php if ( $contact['phone'] ) : ?>
				<?php echo esc_html( $contact['phone'] ); ?>
				<?php if ( ! empty( $contact['dnl_phone'] ) ) echo ' <em style="color:#999;">(Do Not List)</em>'; ?>
				<br>
			<?php endif; ?>
			<?php if ( $contact['phone2'] ) : ?>
				<?php echo esc_html( $contact['phone2'] ); ?>
				<?php if ( ! empty( $contact['dnl_phone2'] ) ) echo ' <em style="color:#999;">(Do Not List)</em>'; ?>
				<br>
			<?php endif; ?>
			<?php if ( ! empty( $contact['include_in_email'] ) ) echo '<em style="color:#4caf50;">Include in Email Template</em>'; ?>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( ! empty( $req['hotels'] ) ) : ?>
		<h2 style="font-size:15px;margin:20px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Hotel Recommendations</h2>
		<?php foreach ( $req['hotels'] as $hotel ) : ?>
		<div style="background:#f9f9f9;border:1px solid #e5e5e5;border-radius:4px;padding:10px 14px;margin-bottom:8px;">
			<strong><?php echo esc_html( $hotel['name'] ); ?></strong><br>
			<?php if ( $hotel['phone'] )   echo 'Phone: ' . esc_html( $hotel['phone'] ) . '<br>'; ?>
			<?php if ( $hotel['address'] ) echo 'Address: ' . esc_html( $hotel['address'] ) . '<br>'; ?>
			<?php if ( $hotel['url'] )     echo '<a href="' . esc_url( $hotel['url'] ) . '" target="_blank">' . esc_html( $hotel['url'] ) . '</a>'; ?>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>

</div><!-- grid -->
</div><!-- .wrap -->
