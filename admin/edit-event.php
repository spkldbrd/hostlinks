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

// ── Helper: normalize a phone number to XXX-XXX-XXXX ─────────────────────────
function _hl_edit_phone( string $raw ): string {
	$digits = preg_replace( '/\D/', '', $raw );
	if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
		$digits = substr( $digits, 1 );
	}
	if ( strlen( $digits ) === 10 ) {
		return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
	}
	return $raw;
}

// ── Handle save ───────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hl_edit_full_event'] ) ) {
	check_admin_referer( 'hostlinks_edit_full_event_' . $eve_id );

	// Core event fields
	$location      = sanitize_text_field( $_POST['eve_location']    ?? '' );
	$eve_type      = (int) ( $_POST['eve_type']       ?? 0 );
	$eve_marketer  = (int) ( $_POST['eve_marketer']   ?? 0 );
	$eve_instructor= (int) ( $_POST['eve_instructor'] ?? 0 );
	$eve_zoom      = sanitize_text_field( $_POST['eve_zoom']      ?? '' );
	$eve_zoom_time = sanitize_text_field( $_POST['eve_zoom_time'] ?? '' );
	$eve_paid      = (int) ( $_POST['eve_paid']  ?? 0 );
	$eve_free      = (int) ( $_POST['eve_free']  ?? 0 );
	$eve_public_hide = isset( $_POST['eve_public_hide'] ) ? 1 : 0;
	$eve_status    = (int) ( $_POST['eve_status'] ?? 1 );

	// Date fields
	$eve_start    = sanitize_text_field( $_POST['eve_start_date'] ?? '' );
	$eve_end      = sanitize_text_field( $_POST['eve_end_date']   ?? $eve_start );
	$eve_tot_date = str_replace( '-', '/', $eve_start ) . ' - ' . str_replace( '-', '/', $eve_end );

	// URLs
	$eve_host_url    = esc_url_raw( trim( $_POST['eve_host_url']    ?? '' ) );
	$eve_roster_url  = esc_url_raw( trim( $_POST['eve_roster_url']  ?? '' ) );
	$eve_trainer_url = esc_url_raw( trim( $_POST['eve_trainer_url'] ?? '' ) );
	$eve_web_url     = esc_url_raw( trim( $_POST['eve_web_url']     ?? '' ) );
	$eve_email_url   = esc_url_raw( trim( $_POST['eve_email_url']   ?? '' ) );

	// Shipping
	$ship_workbooks_raw = trim( $_POST['ship_workbooks'] ?? '' );
	$has_shipping       = isset( $_POST['hl_add_shipping'] );

	// ── Host & Venue ─────────────────────────────────────────────────────
	$host_name     = sanitize_text_field( $_POST['edit_host_name']        ?? '' );
	$displayed_as  = sanitize_text_field( $_POST['edit_displayed_as']     ?? '' );
	$location_name = sanitize_text_field( $_POST['edit_location_name']    ?? '' );
	$addr1         = sanitize_text_field( $_POST['edit_street_address_1'] ?? '' );
	$addr2         = sanitize_text_field( $_POST['edit_street_address_2'] ?? '' );
	$addr3         = sanitize_text_field( $_POST['edit_street_address_3'] ?? '' );
	$ev_city       = sanitize_text_field( $_POST['edit_city']             ?? '' );
	$ev_state      = sanitize_text_field( $_POST['edit_state']            ?? '' );
	$zip_code      = sanitize_text_field( $_POST['edit_zip_code']         ?? '' );

	// ── Additional Details ────────────────────────────────────────────────
	$max_attendees_raw    = trim( $_POST['edit_max_attendees']       ?? '' );
	$special_instructions = sanitize_textarea_field( $_POST['edit_special_instructions'] ?? '' );
	$parking_file_url     = esc_url_raw( trim( $_POST['edit_parking_file_url'] ?? '' ) );
	$custom_email_intro   = sanitize_textarea_field( $_POST['edit_custom_email_intro']   ?? '' );

	// ── Host Contacts (repeatable → JSON) ────────────────────────────────
	$host_contacts = array();
	foreach ( (array) ( $_POST['edit_contact_name'] ?? array() ) as $i => $cname ) {
		$cname = sanitize_text_field( $cname );
		if ( $cname === '' ) continue;
		$host_contacts[] = array(
			'name'             => $cname,
			'agency'           => sanitize_text_field( $_POST['edit_contact_agency'][$i]  ?? '' ),
			'title'            => sanitize_text_field( $_POST['edit_contact_title'][$i]   ?? '' ),
			'email'            => sanitize_email(      $_POST['edit_contact_email'][$i]   ?? '' ),
			'phone'            => _hl_edit_phone( sanitize_text_field( $_POST['edit_contact_phone'][$i]  ?? '' ) ),
			'phone2'           => _hl_edit_phone( sanitize_text_field( $_POST['edit_contact_phone2'][$i] ?? '' ) ),
			'cc_on_alerts'     => ! empty( $_POST['edit_contact_cc'][$i] ),
			'include_in_email' => ! empty( $_POST['edit_contact_include'][$i] ),
			'dnl_phone'        => ! empty( $_POST['edit_contact_dnl_phone'][$i] ),
			'dnl_phone2'       => ! empty( $_POST['edit_contact_dnl_phone2'][$i] ),
		);
	}

	// ── Hotels (repeatable → JSON) ────────────────────────────────────────
	$hotels_arr = array();
	foreach ( (array) ( $_POST['edit_hotel_name'] ?? array() ) as $i => $hname ) {
		$hname = sanitize_text_field( $hname );
		if ( $hname === '' ) continue;
		$hotels_arr[] = array(
			'name'    => $hname,
			'phone'   => sanitize_text_field( $_POST['edit_hotel_phone'][$i]   ?? '' ),
			'address' => sanitize_text_field( $_POST['edit_hotel_address'][$i] ?? '' ),
			'url'     => esc_url_raw( trim( $_POST['edit_hotel_url'][$i] ?? '' ) ),
		);
	}

	$update_data = array(
		// Core
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
		// URLs
		'eve_host_url'    => $eve_host_url,
		'eve_roster_url'  => $eve_roster_url,
		'eve_trainer_url' => $eve_trainer_url,
		'eve_web_url'     => $eve_web_url,
		'eve_email_url'   => $eve_email_url,
		// Shipping
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
		// Host & Venue
		'host_name'            => $host_name,
		'displayed_as'         => $displayed_as,
		'location_name'        => $location_name,
		'street_address_1'     => $addr1,
		'street_address_2'     => $addr2,
		'street_address_3'     => $addr3,
		'city'                 => $ev_city,
		'state'                => $ev_state,
		'zip_code'             => $zip_code,
		// Additional
		'max_attendees'        => $max_attendees_raw !== '' ? (int) $max_attendees_raw : null,
		'special_instructions' => $special_instructions,
		'parking_file_url'     => $parking_file_url,
		'custom_email_intro'   => $custom_email_intro,
		// JSON blobs
		'host_contacts'        => wp_json_encode( $host_contacts ),
		'hotels'               => wp_json_encode( $hotels_arr ),
	);

	$wpdb->update( $table, $update_data, array( 'eve_id' => $eve_id ) );
	$notice = '<div class="notice notice-success is-dismissible"><p>Event updated successfully.</p></div>';
}

// ── Load the event row ────────────────────────────────────────────────────────
$ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE eve_id = %d", $eve_id ), ARRAY_A );
if ( ! $ev ) {
	echo '<div class="wrap"><div class="notice notice-error"><p>Event not found.</p></div></div>';
	return;
}

// Resolved display names for the view header
$type_name     = '';
foreach ( $all_types as $t ) {
	if ( (int) $t['id'] === (int) $ev['eve_type'] ) { $type_name = $t['name']; break; }
}
$marketer_name = '';
foreach ( $all_marketers as $m ) {
	if ( (int) $m['id'] === (int) $ev['eve_marketer'] ) { $marketer_name = $m['name']; break; }
}

// Parse JSON blobs
$ev_contacts = array();
if ( ! empty( $ev['host_contacts'] ) ) {
	$decoded = json_decode( $ev['host_contacts'], true );
	if ( is_array( $decoded ) ) $ev_contacts = $decoded;
}
$ev_hotels = array();
if ( ! empty( $ev['hotels'] ) ) {
	$decoded = json_decode( $ev['hotels'], true );
	if ( is_array( $decoded ) ) $ev_hotels = $decoded;
}

$maps_api_key      = get_option( 'hostlinks_google_maps_api_key', '' );
$has_shipping_data = ! empty( $ev['ship_name'] ) || ! empty( $ev['ship_email'] ) || ! empty( $ev['ship_address_1'] ) || ! empty( $ev['ship_notes'] );

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
<style>
.hl-edit-section-title {
	font-size: 15px;
	margin: 24px 0 8px;
	padding-bottom: 4px;
	border-bottom: 2px solid #0da2e7;
}
.hl-edit-contact-row,
.hl-edit-hotel-row {
	background: #f9f9f9;
	border: 1px solid #e5e5e5;
	border-radius: 4px;
	padding: 12px 14px;
	margin-bottom: 10px;
	position: relative;
}
.hl-edit-contact-grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 10px 14px;
}
.hl-edit-hotel-grid {
	display: grid;
	grid-template-columns: 2fr 1fr 2fr 2fr;
	gap: 10px 14px;
}
.hl-edit-row-field label {
	display: block;
	font-size: 11px;
	font-weight: 600;
	color: #555;
	margin-bottom: 3px;
}
.hl-edit-row-field input[type="text"],
.hl-edit-row-field input[type="email"],
.hl-edit-row-field input[type="tel"],
.hl-edit-row-field input[type="url"] {
	width: 100%;
	box-sizing: border-box;
}
.hl-edit-contact-checks {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 20px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px dashed #ddd;
	font-size: 12px;
}
.hl-edit-contact-checks label {
	display: flex;
	align-items: center;
	gap: 5px;
	cursor: pointer;
}
.hl-edit-remove-row {
	position: absolute;
	top: 8px;
	right: 8px;
	background: #c0392b;
	color: #fff;
	border: none;
	border-radius: 3px;
	padding: 2px 8px;
	font-size: 11px;
	cursor: pointer;
	line-height: 1.6;
}
.hl-edit-remove-row:hover { background: #922b21; }
.hl-edit-add-row-btn {
	margin-top: 4px;
	font-size: 12px;
}
</style>

<div class="wrap">
<h1>
	<a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none;color:#50575e;font-size:14px;margin-right:8px;">&#8592; Event List</a>
	Edit Event #<?php echo $eve_id; ?>
	<?php if ( $marketer_name ) echo '<span style="font-size:14px;color:#50575e;font-weight:400;margin-left:8px;">— ' . esc_html( $marketer_name ) . '</span>'; ?>
</h1>

<?php echo $notice; ?>

<form method="post" id="hl-edit-event-form">
<?php wp_nonce_field( 'hostlinks_edit_full_event_' . $eve_id ); ?>

<div style="max-width:1100px;">

<!-- ═══════════════════════════════════════════════════════════════════════════
     ROW 1: Core + CVENT (2 columns)
════════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

	<!-- ── LEFT: Event Info + URLs ──────────────────────────────────────── -->
	<div>

		<h2 class="hl-edit-section-title" style="margin-top:0;">Event Info</h2>
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
			<tr id="hl-edit-zoom-time-row" style="<?php echo ( ( $ev['eve_zoom'] ?? '' ) !== 'yes' ) ? 'display:none;' : ''; ?>">
				<th style="padding:8px 12px;">Zoom Time</th>
				<td style="padding:8px 12px;">
					<input type="text" name="eve_zoom_time" value="<?php echo esc_attr( $ev['eve_zoom_time'] ?? '' ); ?>"
						class="regular-text" placeholder="e.g. 9:30–4:30 EST" />
				</td>
			</tr>
			<tr>
				<th style="padding:8px 12px;">Event Capacity</th>
				<td style="padding:8px 12px;">
					<input type="number" name="edit_max_attendees" min="1"
						value="<?php echo esc_attr( $ev['max_attendees'] ?? '' ); ?>"
						style="width:90px;" placeholder="Unlimited" />
					<small style="color:#888;margin-left:6px;">Leave blank for unlimited</small>
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

		<h2 class="hl-edit-section-title">URLs &amp; Links</h2>
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
				<th style="padding:8px 12px;">REG URL</th>
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
			<tr>
				<th style="padding:8px 12px;">Email URL</th>
				<td style="padding:8px 12px;">
					<input type="url" name="eve_email_url" value="<?php echo esc_attr( $ev['eve_email_url'] ?? '' ); ?>"
						class="large-text" placeholder="https://" />
				</td>
			</tr>
		</table>

	</div>

	<!-- ── RIGHT: CVENT + Shipping ──────────────────────────────────────── -->
	<div>

		<h2 class="hl-edit-section-title" style="margin-top:0;">CVENT Link</h2>
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
		<h2 class="hl-edit-section-title">Shipping Details</h2>

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
</div><!-- end 2-col grid -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     FULL-WIDTH SECTIONS (below the 2-col grid)
════════════════════════════════════════════════════════════════════════════ -->

<!-- ── Host & Venue ──────────────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Host &amp; Venue</h2>
<table class="form-table" style="margin-bottom:0;">
	<tr>
		<th style="width:180px;padding:8px 12px;">Host Name</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_host_name"
				value="<?php echo esc_attr( $ev['host_name'] ?? '' ); ?>"
				class="regular-text" placeholder="e.g. City of Phoenix" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Displayed As</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_displayed_as"
				value="<?php echo esc_attr( $ev['displayed_as'] ?? '' ); ?>"
				class="large-text" placeholder="Hosted by …" />
			<small style="color:#888;">Auto-filled from Host Name on the request form — edit if needed.</small>
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Location Name / Building</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_location_name"
				value="<?php echo esc_attr( $ev['location_name'] ?? '' ); ?>"
				class="large-text" placeholder="e.g. City Hall, Conference Center" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Address Line 1</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_street_address_1" id="hl-edit-venue-addr1"
				value="<?php echo esc_attr( $ev['street_address_1'] ?? '' ); ?>"
				class="large-text" placeholder="123 Main St" autocomplete="off" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Address Line 2</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_street_address_2"
				value="<?php echo esc_attr( $ev['street_address_2'] ?? '' ); ?>"
				class="large-text" placeholder="Suite, Floor, etc." />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Address Line 3</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_street_address_3"
				value="<?php echo esc_attr( $ev['street_address_3'] ?? '' ); ?>"
				class="large-text" placeholder="" />
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">City / State / ZIP</th>
		<td style="padding:8px 12px;">
			<input type="text" name="edit_city" id="hl-edit-venue-city"
				value="<?php echo esc_attr( $ev['city'] ?? '' ); ?>"
				style="width:180px;" placeholder="City" />
			&nbsp;
			<select name="edit_state" id="hl-edit-venue-state">
				<option value="">— State —</option>
				<?php foreach ( $us_states as $abbr ) : ?>
				<option value="<?php echo esc_attr( $abbr ); ?>"
					<?php selected( $ev['state'] ?? '', $abbr ); ?>>
					<?php echo esc_html( $abbr ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			&nbsp;
			<input type="text" name="edit_zip_code" id="hl-edit-venue-zip"
				value="<?php echo esc_attr( $ev['zip_code'] ?? '' ); ?>"
				style="width:90px;" placeholder="ZIP" maxlength="10" />
		</td>
	</tr>
</table>

<!-- ── Additional Details ─────────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Additional Details</h2>
<table class="form-table" style="margin-bottom:0;">
	<tr>
		<th style="width:180px;padding:8px 12px;">Special Instructions / Parking</th>
		<td style="padding:8px 12px;">
			<textarea name="edit_special_instructions" rows="4" class="large-text"
				placeholder="Parking instructions, building access notes, etc."><?php
				echo esc_textarea( $ev['special_instructions'] ?? '' );
			?></textarea>
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Parking / Instructions File URL</th>
		<td style="padding:8px 12px;">
			<input type="url" name="edit_parking_file_url"
				value="<?php echo esc_attr( $ev['parking_file_url'] ?? '' ); ?>"
				class="large-text" placeholder="https://… (PDF or image URL)" />
			<small style="color:#888;display:block;margin-top:4px;">Link to a previously uploaded PDF or file.</small>
		</td>
	</tr>
	<tr>
		<th style="padding:8px 12px;">Custom Email Intro</th>
		<td style="padding:8px 12px;">
			<textarea name="edit_custom_email_intro" rows="4" class="large-text"
				placeholder="Opening paragraph for registration / marketing emails."><?php
				echo esc_textarea( $ev['custom_email_intro'] ?? '' );
			?></textarea>
		</td>
	</tr>
</table>

<!-- ── Host Contacts ─────────────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Host Contacts</h2>

<div id="hl-edit-contact-rows">
<?php if ( empty( $ev_contacts ) ) : ?>
	<p id="hl-no-contacts-msg" style="color:#888;font-style:italic;margin:0 0 8px;">No host contacts saved. Click below to add one.</p>
<?php endif; ?>
<?php foreach ( $ev_contacts as $ci => $c ) :
	$ci_cc  = ! empty( $c['cc_on_alerts'] );
	$ci_inc = ! empty( $c['include_in_email'] );
	$ci_dnl = ! empty( $c['dnl_phone'] );
	$ci_dnl2= ! empty( $c['dnl_phone2'] );
?>
<div class="hl-edit-contact-row">
	<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,'hl-no-contacts-msg')">Remove</button>
	<div class="hl-edit-contact-grid">
		<div class="hl-edit-row-field">
			<label>Name</label>
			<input type="text" name="edit_contact_name[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="Name" />
		</div>
		<div class="hl-edit-row-field">
			<label>Agency</label>
			<input type="text" name="edit_contact_agency[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['agency'] ?? '' ); ?>" placeholder="Agency" />
		</div>
		<div class="hl-edit-row-field">
			<label>Title</label>
			<input type="text" name="edit_contact_title[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['title'] ?? '' ); ?>" placeholder="Title" />
		</div>
		<div class="hl-edit-row-field">
			<label>Email</label>
			<input type="email" name="edit_contact_email[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['email'] ?? '' ); ?>" placeholder="email@example.com" />
		</div>
		<div class="hl-edit-row-field">
			<label>Phone</label>
			<input type="tel" name="edit_contact_phone[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['phone'] ?? '' ); ?>" placeholder="123-456-7890"
				class="hl-phone-input" />
		</div>
		<div class="hl-edit-row-field">
			<label>Phone 2</label>
			<input type="tel" name="edit_contact_phone2[<?php echo $ci; ?>]"
				value="<?php echo esc_attr( $c['phone2'] ?? '' ); ?>" placeholder="123-456-7890"
				class="hl-phone-input" />
		</div>
	</div>
	<div class="hl-edit-contact-checks">
		<label>
			<input type="checkbox" name="edit_contact_cc[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_cc ); ?>>
			CC on Registration Alerts
		</label>
		<label>
			<input type="checkbox" name="edit_contact_include[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_inc ); ?>>
			Include in Email Template
		</label>
		<label>
			<input type="checkbox" name="edit_contact_dnl_phone[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_dnl ); ?>>
			Do Not List (Phone)
		</label>
		<label>
			<input type="checkbox" name="edit_contact_dnl_phone2[<?php echo $ci; ?>]" value="1"
				<?php checked( $ci_dnl2 ); ?>>
			Do Not List (Phone 2)
		</label>
	</div>
</div>
<?php endforeach; ?>
</div>

<button type="button" class="button hl-edit-add-row-btn" id="hl-add-contact-btn">+ Add Host Contact</button>

<!-- ── Hotel Recommendations ─────────────────────────────────────────────── -->
<h2 class="hl-edit-section-title">Hotel Recommendations</h2>

<div id="hl-edit-hotel-rows">
<?php if ( empty( $ev_hotels ) ) : ?>
	<p id="hl-no-hotels-msg" style="color:#888;font-style:italic;margin:0 0 8px;">No hotels saved. Click below to add one.</p>
<?php endif; ?>
<?php foreach ( $ev_hotels as $hi => $h ) : ?>
<div class="hl-edit-hotel-row">
	<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,'hl-no-hotels-msg')">Remove</button>
	<div class="hl-edit-hotel-grid">
		<div class="hl-edit-row-field">
			<label>Hotel Name</label>
			<input type="text" name="edit_hotel_name[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['name'] ); ?>" placeholder="Hotel Name" />
		</div>
		<div class="hl-edit-row-field">
			<label>Phone</label>
			<input type="tel" name="edit_hotel_phone[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['phone'] ?? '' ); ?>" placeholder="123-456-7890"
				class="hl-phone-input" />
		</div>
		<div class="hl-edit-row-field">
			<label>Address</label>
			<input type="text" name="edit_hotel_address[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['address'] ?? '' ); ?>" placeholder="Address" />
		</div>
		<div class="hl-edit-row-field">
			<label>URL</label>
			<input type="url" name="edit_hotel_url[<?php echo $hi; ?>]"
				value="<?php echo esc_attr( $h['url'] ?? '' ); ?>" placeholder="https://" />
		</div>
	</div>
</div>
<?php endforeach; ?>
</div>

<button type="button" class="button hl-edit-add-row-btn" id="hl-add-hotel-btn">+ Add Hotel</button>

</div><!-- .max-width wrapper -->

<p class="submit" style="margin-top:28px;">
	<button type="submit" name="hl_edit_full_event" class="button button-primary" style="font-size:14px;padding:6px 20px;">Save Changes</button>
	&nbsp;
	<a href="<?php echo esc_url( $list_url ); ?>" class="button button-secondary">Cancel</a>
</p>
</form>
</div><!-- .wrap -->

<script>
(function() {

	// ── Zoom time row toggle ─────────────────────────────────────────────
	var zoomChk     = document.getElementById('hl-edit-zoom');
	var zoomTimeRow = document.getElementById('hl-edit-zoom-time-row');
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

	// ── Phone formatter (shipping + contact fields) ──────────────────────
	function formatPhone(input) {
		var digits = input.value.replace(/\D/g, '');
		if (digits.length === 11 && digits[0] === '1') digits = digits.slice(1);
		if (digits.length === 10) {
			input.value = digits.slice(0,3) + '-' + digits.slice(3,6) + '-' + digits.slice(6);
		}
	}

	var shipPhone = document.getElementById('hl-ship-phone');
	if (shipPhone) {
		shipPhone.addEventListener('blur', function() { formatPhone(this); });
	}

	document.addEventListener('blur', function(e) {
		if (e.target && e.target.classList.contains('hl-phone-input')) {
			formatPhone(e.target);
		}
	}, true);

	// ── Remove row helper ────────────────────────────────────────────────
	window.hlRemoveRow = function(btn, emptyMsgId) {
		var row = btn.closest('.hl-edit-contact-row, .hl-edit-hotel-row');
		if (!row) return;
		row.remove();
		var container = document.getElementById(
			emptyMsgId === 'hl-no-contacts-msg' ? 'hl-edit-contact-rows' : 'hl-edit-hotel-rows'
		);
		if (container) {
			var hasRows = container.querySelectorAll('.hl-edit-contact-row, .hl-edit-hotel-row').length > 0;
			var msg = document.getElementById(emptyMsgId);
			if (!msg) {
				msg = document.createElement('p');
				msg.id = emptyMsgId;
				msg.style.cssText = 'color:#888;font-style:italic;margin:0 0 8px;';
				msg.textContent = emptyMsgId === 'hl-no-contacts-msg'
					? 'No host contacts saved. Click below to add one.'
					: 'No hotels saved. Click below to add one.';
				if (!hasRows) container.appendChild(msg);
			} else {
				msg.style.display = hasRows ? 'none' : '';
			}
		}
	};

	// ── Repeatable: Add Contact ──────────────────────────────────────────
	var contactCounter = <?php echo count( $ev_contacts ); ?>;

	document.getElementById('hl-add-contact-btn').addEventListener('click', function() {
		var idx = contactCounter++;
		var msg = document.getElementById('hl-no-contacts-msg');
		if (msg) msg.style.display = 'none';

		var row = document.createElement('div');
		row.className = 'hl-edit-contact-row';
		row.innerHTML =
			'<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,\'hl-no-contacts-msg\')">Remove</button>' +
			'<div class="hl-edit-contact-grid">' +
				'<div class="hl-edit-row-field"><label>Name</label>' +
					'<input type="text" name="edit_contact_name[' + idx + ']" placeholder="Name" /></div>' +
				'<div class="hl-edit-row-field"><label>Agency</label>' +
					'<input type="text" name="edit_contact_agency[' + idx + ']" placeholder="Agency" /></div>' +
				'<div class="hl-edit-row-field"><label>Title</label>' +
					'<input type="text" name="edit_contact_title[' + idx + ']" placeholder="Title" /></div>' +
				'<div class="hl-edit-row-field"><label>Email</label>' +
					'<input type="email" name="edit_contact_email[' + idx + ']" placeholder="email@example.com" /></div>' +
				'<div class="hl-edit-row-field"><label>Phone</label>' +
					'<input type="tel" name="edit_contact_phone[' + idx + ']" placeholder="123-456-7890" class="hl-phone-input" /></div>' +
				'<div class="hl-edit-row-field"><label>Phone 2</label>' +
					'<input type="tel" name="edit_contact_phone2[' + idx + ']" placeholder="123-456-7890" class="hl-phone-input" /></div>' +
			'</div>' +
			'<div class="hl-edit-contact-checks">' +
				'<label><input type="checkbox" name="edit_contact_cc[' + idx + ']" value="1"> CC on Registration Alerts</label>' +
				'<label><input type="checkbox" name="edit_contact_include[' + idx + ']" value="1" checked> Include in Email Template</label>' +
				'<label><input type="checkbox" name="edit_contact_dnl_phone[' + idx + ']" value="1"> Do Not List (Phone)</label>' +
				'<label><input type="checkbox" name="edit_contact_dnl_phone2[' + idx + ']" value="1"> Do Not List (Phone 2)</label>' +
			'</div>';

		document.getElementById('hl-edit-contact-rows').appendChild(row);
	});

	// ── Repeatable: Add Hotel ────────────────────────────────────────────
	var hotelCounter = <?php echo count( $ev_hotels ); ?>;

	document.getElementById('hl-add-hotel-btn').addEventListener('click', function() {
		var idx = hotelCounter++;
		var msg = document.getElementById('hl-no-hotels-msg');
		if (msg) msg.style.display = 'none';

		var row = document.createElement('div');
		row.className = 'hl-edit-hotel-row';
		row.innerHTML =
			'<button type="button" class="hl-edit-remove-row" onclick="hlRemoveRow(this,\'hl-no-hotels-msg\')">Remove</button>' +
			'<div class="hl-edit-hotel-grid">' +
				'<div class="hl-edit-row-field"><label>Hotel Name</label>' +
					'<input type="text" name="edit_hotel_name[' + idx + ']" placeholder="Hotel Name" /></div>' +
				'<div class="hl-edit-row-field"><label>Phone</label>' +
					'<input type="tel" name="edit_hotel_phone[' + idx + ']" placeholder="123-456-7890" class="hl-phone-input" /></div>' +
				'<div class="hl-edit-row-field"><label>Address</label>' +
					'<input type="text" name="edit_hotel_address[' + idx + ']" placeholder="Address" /></div>' +
				'<div class="hl-edit-row-field"><label>URL</label>' +
					'<input type="url" name="edit_hotel_url[' + idx + ']" placeholder="https://" /></div>' +
			'</div>';

		document.getElementById('hl-edit-hotel-rows').appendChild(row);
	});

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

	// ── Google Places autocomplete for venue address ─────────────────────
	function initVenueAutocomplete() {
		if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
		var addr1 = document.getElementById('hl-edit-venue-addr1');
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
			var cityEl  = document.getElementById('hl-edit-venue-city');
			var stateEl = document.getElementById('hl-edit-venue-state');
			var zipEl   = document.getElementById('hl-edit-venue-zip');
			if (cityEl)  cityEl.value  = city;
			if (stateEl) stateEl.value = state;
			if (zipEl)   zipEl.value   = zip;
		});
	}

	if (typeof google !== 'undefined' && google.maps && google.maps.places) {
		initShipAutocomplete();
		initVenueAutocomplete();
	} else {
		window.addEventListener('load', function() {
			initShipAutocomplete();
			initVenueAutocomplete();
		});
	}
<?php endif; ?>
})();
</script>
