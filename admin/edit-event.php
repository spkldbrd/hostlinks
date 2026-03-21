<?php
/**
 * Full card-based edit form for a single event in event_details_list.
 * Accessed via: ?page=booking-menu&edit_event={id}
 * Included from admin/booking.php when $_GET['edit_event'] is set.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

global $wpdb;

$eve_id    = (int) ( $_GET['edit_event'] ?? 0 );
$list_url  = admin_url( 'admin.php?page=booking-menu' );
$timezone  = wp_timezone();

if ( ! $eve_id ) {
	wp_safe_redirect( $list_url );
	exit;
}

$table = $wpdb->prefix . 'event_details_list';

// ── Load lookup tables ────────────────────────────────────────────────────────
$all_types       = $wpdb->get_results( "SELECT event_type_id AS id, event_type_name AS name FROM {$wpdb->prefix}event_type WHERE event_type_status = 1 ORDER BY name", ARRAY_A );
$all_marketers   = $wpdb->get_results( "SELECT event_marketer_id AS id, event_marketer_name AS name FROM {$wpdb->prefix}event_marketer WHERE event_marketer_status = 1 ORDER BY name", ARRAY_A );
$all_instructors = $wpdb->get_results( "SELECT event_instructor_id AS id, event_instructor_name AS name FROM {$wpdb->prefix}event_instructor WHERE event_instructor_status = 1 ORDER BY name", ARRAY_A );

// ── Handle save ───────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hl_edit_full_event'] ) ) {
	check_admin_referer( 'hostlinks_edit_full_event_' . $eve_id );

	// Core event fields
	$location    = sanitize_text_field( $_POST['eve_location']    ?? '' );
	$eve_type    = (int) ( $_POST['eve_type']    ?? 0 );
	$eve_marketer= (int) ( $_POST['eve_marketer']?? 0 );
	$eve_instructor=(int)( $_POST['eve_instructor']??0);
	$eve_zoom    = sanitize_text_field( $_POST['eve_zoom']    ?? '' );
	$eve_zoom_time = sanitize_text_field( $_POST['eve_zoom_time'] ?? '' );
	$eve_paid    = (int) ( $_POST['eve_paid']    ?? 0 );
	$eve_free    = (int) ( $_POST['eve_free']    ?? 0 );
	$eve_public_hide = isset( $_POST['eve_public_hide'] ) ? 1 : 0;
	$eve_status  = (int) ( $_POST['eve_status']  ?? 1 );

	// Date range
	$date_range  = sanitize_text_field( $_POST['evedate'] ?? '' );
	$dates       = explode( '-', $date_range );
	$eve_start   = trim( $dates[0] ?? '' );
	$eve_end     = trim( $dates[1] ?? '' );
	$eve_tot_date = $date_range;

	// If a plain date range was not entered, try two separate date fields
	if ( isset( $_POST['eve_start_date'] ) ) {
		$eve_start    = sanitize_text_field( $_POST['eve_start_date'] );
		$eve_end      = sanitize_text_field( $_POST['eve_end_date'] ?? $eve_start );
		$eve_tot_date = $eve_start . '-' . $eve_end;
	}

	// URLs
	$eve_host_url    = esc_url_raw( trim( $_POST['eve_host_url']    ?? '' ) );
	$eve_roster_url  = esc_url_raw( trim( $_POST['eve_roster_url']  ?? '' ) );
	$eve_trainer_url = esc_url_raw( trim( $_POST['eve_trainer_url'] ?? '' ) );
	$eve_web_url     = esc_url_raw( trim( $_POST['eve_web_url']     ?? '' ) );

	// Shipping
	$ship_workbooks_raw = trim( $_POST['ship_workbooks'] ?? '' );
	$has_shipping = isset( $_POST['hl_add_shipping'] );

	$update_data = array(
		'eve_location'    => $location,
		'eve_type'        => $eve_type,
		'eve_marketer'    => $eve_marketer,
		'eve_instructor'  => $eve_instructor,
		'eve_zoom'        => $eve_zoom,
		'eve_zoom_time'   => $eve_zoom_time,
		'eve_paid'        => $eve_paid,
		'eve_free'        => $eve_free,
		'eve_public_hide' => $eve_public_hide,
		'eve_status'      => $eve_status,
		'eve_start'       => $eve_start,
		'eve_end'         => $eve_end,
		'eve_tot_date'    => $eve_tot_date,
		'eve_host_url'    => $eve_host_url,
		'eve_roster_url'  => $eve_roster_url,
		'eve_trainer_url' => $eve_trainer_url,
		'eve_web_url'     => $eve_web_url,
		'ship_name'      => $has_shipping ? sanitize_text_field( $_POST['ship_name']      ?? '' ) : '',
		'ship_email'     => $has_shipping ? sanitize_email(      $_POST['ship_email']     ?? '' ) : '',
		'ship_phone'     => $has_shipping ? sanitize_text_field( $_POST['ship_phone']     ?? '' ) : '',
		'ship_address_1' => $has_shipping ? sanitize_text_field( $_POST['ship_address_1'] ?? '' ) : '',
		'ship_address_2' => $has_shipping ? sanitize_text_field( $_POST['ship_address_2'] ?? '' ) : '',
		'ship_address_3' => $has_shipping ? sanitize_text_field( $_POST['ship_address_3'] ?? '' ) : '',
		'ship_city'      => $has_shipping ? sanitize_text_field( $_POST['ship_city']      ?? '' ) : '',
		'ship_state'     => $has_shipping ? sanitize_text_field( $_POST['ship_state']     ?? '' ) : '',
		'ship_zip'       => $has_shipping ? sanitize_text_field( $_POST['ship_zip']       ?? '' ) : '',
		'ship_workbooks' => ( $has_shipping && $ship_workbooks_raw !== '' ) ? (int) $ship_workbooks_raw : null,
		'ship_notes'     => $has_shipping ? sanitize_textarea_field( $_POST['ship_notes'] ?? '' ) : '',
	);

	$wpdb->update( $table, $update_data, array( 'eve_id' => $eve_id ) );
	$notice = '<div class="notice notice-success is-dismissible"><p>Event updated successfully.</p></div>';

	// Refresh the row from DB so the form shows current values.
}

// ── Load the event row ────────────────────────────────────────────────────────
$ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE eve_id = %d", $eve_id ), ARRAY_A );
if ( ! $ev ) {
	echo '<div class="wrap"><div class="notice notice-error"><p>Event not found.</p></div></div>';
	return;
}

// Resolved display names for the view header
$type_name       = '';
foreach ( $all_types as $t ) { if ( (int) $t['id'] === (int) $ev['eve_type'] ) { $type_name = $t['name']; break; } }
$marketer_name   = '';
foreach ( $all_marketers as $m ) { if ( (int) $m['id'] === (int) $ev['eve_marketer'] ) { $marketer_name = $m['name']; break; } }

$maps_api_key = get_option( 'hostlinks_google_maps_api_key', '' );
$has_shipping_data = ! empty( $ev['ship_name'] ) || ! empty( $ev['ship_email'] ) || ! empty( $ev['ship_address_1'] ) || ! empty( $ev['ship_notes'] );

// Enqueue Maps API on this admin page if a key is set
if ( $maps_api_key ) {
	wp_enqueue_script(
		'google-maps-places',
		'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $maps_api_key ) . '&libraries=places',
		array(),
		null,
		true
	);
}

$us_states = [ 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC' ];
?>
<div class="wrap">
<h1>
	<a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none;color:#50575e;font-size:14px;margin-right:8px;">&#8592; Event List</a>
	Edit Event #<?php echo $eve_id; ?>
	<?php if ( $marketer_name ) echo '<span style="font-size:14px;color:#50575e;font-weight:400;margin-left:8px;">— ' . esc_html( $marketer_name ) . '</span>'; ?>
</h1>

<?php echo $notice; ?>

<form method="post" id="hl-edit-event-form">
<?php wp_nonce_field( 'hostlinks_edit_full_event_' . $eve_id ); ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1100px;">

	<!-- ── LEFT COLUMN ───────────────────────────────────────────────── -->
	<div>

		<!-- Event Info -->
		<h2 style="font-size:15px;margin-bottom:8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Event Info</h2>
		<table class="form-table" style="margin-bottom:0;">
			<tr>
				<th style="width:140px;padding:8px 12px;">Location</th>
				<td style="padding:8px 12px;">
					<input type="text" name="eve_location" value="<?php echo esc_attr( $ev['eve_location'] ); ?>"
						class="regular-text" required />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Start Date</th>
				<td style="padding:8px 12px;">
					<input type="date" name="eve_start_date" value="<?php echo esc_attr( $ev['eve_start'] ?? '' ); ?>"
						class="regular-text" required />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">End Date</th>
				<td style="padding:8px 12px;">
					<input type="date" name="eve_end_date" value="<?php echo esc_attr( $ev['eve_end'] ?? '' ); ?>"
						class="regular-text" required />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Type</th>
				<td style="padding:8px 12px;">
					<select name="eve_type" required>
						<option value="">— select —</option>
						<?php foreach ( $all_types as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) $ev['eve_type'], (int) $t['id'] ); ?>>
							<?php echo esc_html( $t['name'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Marketer</th>
				<td style="padding:8px 12px;">
					<select name="eve_marketer" required>
						<option value="">— select —</option>
						<?php foreach ( $all_marketers as $m ) : ?>
						<option value="<?php echo (int) $m['id']; ?>" <?php selected( (int) $ev['eve_marketer'], (int) $m['id'] ); ?>>
							<?php echo esc_html( $m['name'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Instructor</th>
				<td style="padding:8px 12px;">
					<select name="eve_instructor">
						<option value="0">— select —</option>
						<?php foreach ( $all_instructors as $i ) : ?>
						<option value="<?php echo (int) $i['id']; ?>" <?php selected( (int) $ev['eve_instructor'], (int) $i['id'] ); ?>>
							<?php echo esc_html( $i['name'] ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">ZOOM?</th>
				<td style="padding:8px 12px;">
					<label>
						<input type="checkbox" id="hl-edit-zoom" name="eve_zoom" value="yes"
							<?php checked( $ev['eve_zoom'] ?? '', 'yes' ); ?>>
						Yes — this is a virtual event
					</label>
				</td>
			</tr>
			<tr id="hl-edit-zoom-time-row" style="<?php echo ( $ev['eve_zoom'] !== 'yes' ) ? 'display:none;' : ''; ?>">
				<th style="padding:8px 12px;">Zoom Time</th>
				<td style="padding:8px 12px;">
					<input type="text" name="eve_zoom_time" value="<?php echo esc_attr( $ev['eve_zoom_time'] ?? '' ); ?>"
						class="regular-text" placeholder="e.g. 9:30–4:30 EST" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Paid / Free</th>
				<td style="padding:8px 12px;">
					<input type="number" name="eve_paid" value="<?php echo (int) $ev['eve_paid']; ?>"
						style="width:70px;" min="0" />
					<span style="margin:0 8px;color:#666;">paid</span>
					<input type="number" name="eve_free" value="<?php echo (int) $ev['eve_free']; ?>"
						style="width:70px;" min="0" />
					<span style="margin-left:8px;color:#666;">free</span>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Status</th>
				<td style="padding:8px 12px;">
					<select name="eve_status">
						<option value="1" <?php selected( (int) $ev['eve_status'], 1 ); ?>>Active</option>
						<option value="0" <?php selected( (int) $ev['eve_status'], 0 ); ?>>Inactive</option>
						<option value="2" <?php selected( (int) $ev['eve_status'], 2 ); ?>>Deleted</option>
					</select>
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Hide from Public</th>
				<td style="padding:8px 12px;">
					<label>
						<input type="checkbox" name="eve_public_hide" value="1"
							<?php checked( 1, (int) ( $ev['eve_public_hide'] ?? 0 ) ); ?>>
						Hide this event from the public-facing event list
					</label>
				</td>
			</tr>
		</table>

		<!-- URLs & Links -->
		<h2 style="font-size:15px;margin:24px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">URLs &amp; Links</h2>
		<table class="form-table" style="margin-bottom:0;">
			<tr>
				<th style="width:140px;padding:8px 12px;">Host URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_host_url" value="<?php echo esc_attr( $ev['eve_host_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Roster URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_roster_url" value="<?php echo esc_attr( $ev['eve_roster_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Trainer URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_trainer_url" value="<?php echo esc_attr( $ev['eve_trainer_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Web / Sign-in URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_web_url" value="<?php echo esc_attr( $ev['eve_web_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
		</table>

	</div>

	<!-- ── RIGHT COLUMN ──────────────────────────────────────────────── -->
	<div>

		<!-- CVENT -->
		<h2 style="font-size:15px;margin-bottom:8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">CVENT Link</h2>
		<table style="width:100%;border-collapse:collapse;">
			<?php
			$cvent_rows = array(
				'CVENT Event ID'    => $ev['cvent_event_id']        ?? '',
				'CVENT Title'       => $ev['cvent_event_title']     ?? '',
				'Match Status'      => $ev['cvent_match_status']    ?? '',
				'Match Score'       => isset( $ev['cvent_match_score'] ) ? (string) $ev['cvent_match_score'] : '',
				'Last Synced'       => $ev['cvent_last_synced']     ?? '',
			);
			foreach ( $cvent_rows as $lbl => $val ) :
				if ( $val === '' ) continue;
			?>
			<tr>
				<th style="width:140px;padding:8px 12px;background:#f9f9f9;border:1px solid #e5e5e5;font-weight:600;vertical-align:top;"><?php echo esc_html( $lbl ); ?></th>
				<td style="padding:8px 12px;border:1px solid #e5e5e5;font-family:monospace;font-size:12px;"><?php echo esc_html( $val ); ?></td>
			</tr>
			<?php endforeach; ?>
			<?php if ( empty( $ev['cvent_event_id'] ) ) : ?>
			<tr><td colspan="2" style="padding:8px 12px;color:#888;font-style:italic;">No CVENT link — sync or manually add a CVENT ID on the Event List.</td></tr>
			<?php endif; ?>
		</table>

		<!-- Shipping Details -->
		<h2 style="font-size:15px;margin:24px 0 8px;border-bottom:2px solid #0da2e7;padding-bottom:4px;">Shipping Details</h2>

		<p style="margin-bottom:10px;">
			<label style="font-weight:500;">
				<input type="checkbox" id="hl-edit-shipping-toggle" name="hl_add_shipping" value="1"
					<?php checked( $has_shipping_data ); ?>>
				Add / Edit Shipping Details
			</label>
		</p>

		<div id="hl-edit-shipping-fields" <?php if ( ! $has_shipping_data ) echo 'style="display:none;"'; ?>>
			<table class="form-table" style="margin-bottom:0;">
				<tr>
					<th style="width:140px;padding:8px 12px;">Name</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_name" value="<?php echo esc_attr( $ev['ship_name'] ?? '' ); ?>"
							class="regular-text" placeholder="Recipient name" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Email</th>
					<td style="padding:8px 12px;">
						<input type="email" name="ship_email" value="<?php echo esc_attr( $ev['ship_email'] ?? '' ); ?>"
							class="regular-text" placeholder="recipient@example.com" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Phone</th>
					<td style="padding:8px 12px;">
						<input type="tel" name="ship_phone" id="hl-ship-phone" value="<?php echo esc_attr( $ev['ship_phone'] ?? '' ); ?>"
							class="regular-text" placeholder="123-456-7890" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Address 1</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_address_1" id="hl-ship-addr1"
							value="<?php echo esc_attr( $ev['ship_address_1'] ?? '' ); ?>"
							class="large-text" placeholder="Street address" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Address 2</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_address_2" value="<?php echo esc_attr( $ev['ship_address_2'] ?? '' ); ?>"
							class="large-text" placeholder="Suite, floor, etc." />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Address 3</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_address_3" value="<?php echo esc_attr( $ev['ship_address_3'] ?? '' ); ?>"
							class="large-text" placeholder="Additional address info" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">City</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_city" id="hl-ship-city" value="<?php echo esc_attr( $ev['ship_city'] ?? '' ); ?>"
							class="regular-text" placeholder="City" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">State</th>
					<td style="padding:8px 12px;">
						<select name="ship_state" id="hl-ship-state">
							<option value="">— select —</option>
							<?php foreach ( $us_states as $abbr ) : ?>
							<option value="<?php echo esc_attr( $abbr ); ?>" <?php selected( $ev['ship_state'] ?? '', $abbr ); ?>>
								<?php echo esc_html( $abbr ); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">ZIP Code</th>
					<td style="padding:8px 12px;">
						<input type="text" name="ship_zip" id="hl-ship-zip" value="<?php echo esc_attr( $ev['ship_zip'] ?? '' ); ?>"
							class="regular-text" placeholder="12345" maxlength="10" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;"># Workbooks</th>
					<td style="padding:8px 12px;">
						<input type="number" name="ship_workbooks" min="0" style="width:80px;"
							value="<?php echo esc_attr( $ev['ship_workbooks'] ?? '' ); ?>" placeholder="0" />
					</td>
				</tr>
				<tr>
					<th style="padding:8px 12px;">Notes</th>
					<td style="padding:8px 12px;">
						<textarea name="ship_notes" rows="3" class="large-text"
							placeholder="Any other items to ship or special instructions."><?php
							echo esc_textarea( $ev['ship_notes'] ?? '' );
						?></textarea>
					</td>
				</tr>
			</table>
		</div>

	</div>
</div>

<p class="submit" style="margin-top:24px;">
	<button type="submit" name="hl_edit_full_event" class="button button-primary" style="font-size:14px;padding:6px 20px;">Save Changes</button>
	&nbsp;
	<a href="<?php echo esc_url( $list_url ); ?>" class="button button-secondary">Cancel</a>
</p>
</form>
</div>

<script>
(function() {
	// ── Zoom time row toggle ─────────────────────────────────────────────
	var zoomChk      = document.getElementById('hl-edit-zoom');
	var zoomTimeRow  = document.getElementById('hl-edit-zoom-time-row');
	if (zoomChk && zoomTimeRow) {
		zoomChk.addEventListener('change', function() {
			zoomTimeRow.style.display = this.checked ? '' : 'none';
		});
	}

	// ── Shipping section toggle ──────────────────────────────────────────
	var shipToggle = document.getElementById('hl-edit-shipping-toggle');
	var shipFields = document.getElementById('hl-edit-shipping-fields');
	if (shipToggle && shipFields) {
		shipToggle.addEventListener('change', function() {
			shipFields.style.display = this.checked ? '' : 'none';
		});
	}

	// ── Phone formatter ──────────────────────────────────────────────────
	var shipPhone = document.getElementById('hl-ship-phone');
	if (shipPhone) {
		shipPhone.addEventListener('blur', function() {
			var digits = this.value.replace(/\D/g, '');
			if (digits.length === 11 && digits[0] === '1') digits = digits.slice(1);
			if (digits.length === 10) {
				this.value = digits.slice(0,3) + '-' + digits.slice(3,6) + '-' + digits.slice(6);
			}
		});
	}

<?php if ( $maps_api_key ) : ?>
	// ── Google Places autocomplete for shipping address ──────────────────
	function initShipAutocomplete() {
		if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
		var addr1 = document.getElementById('hl-ship-addr1');
		if (!addr1) return;
		var ac = new google.maps.places.Autocomplete(addr1, {
			types: ['address'],
			componentRestrictions: { country: 'us' },
			fields: ['address_components']
		});
		ac.addListener('place_changed', function() {
			var place = ac.getPlace();
			if (!place || !place.address_components) return;
			var streetNum = '', route = '', city = '', state = '', zip = '';
			place.address_components.forEach(function(c) {
				var t = c.types[0];
				if      (t === 'street_number')               streetNum = c.long_name;
				else if (t === 'route')                       route     = c.short_name;
				else if (t === 'locality')                    city      = c.long_name;
				else if (t === 'administrative_area_level_1') state     = c.short_name;
				else if (t === 'postal_code')                 zip       = c.long_name;
			});
			addr1.value = (streetNum + ' ' + route).trim();
			var cityEl  = document.getElementById('hl-ship-city');
			var stateEl = document.getElementById('hl-ship-state');
			var zipEl   = document.getElementById('hl-ship-zip');
			if (cityEl)  cityEl.value  = city;
			if (stateEl) stateEl.value = state;
			if (zipEl)   zipEl.value   = zip;
		});
	}
	if (typeof google !== 'undefined' && google.maps && google.maps.places) {
		initShipAutocomplete();
	} else {
		window.addEventListener('load', initShipAutocomplete);
	}
<?php endif; ?>
})();
</script>
