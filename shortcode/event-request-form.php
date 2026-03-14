<?php
/**
 * Front-end Event Request Form template.
 *
 * Variables provided by Hostlinks_Event_Request_Shortcode::render():
 *   $errors      array   Validation error messages keyed by field name.
 *   $old         array   Previous POST values for sticky fields.
 *   $marketers   array   [ { id, name } ] active marketers.
 *   $instructors array   [ { id, name } ] active instructors.
 *   $categories  array   [ { id, name } ] active event types.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$o = function( string $key, string $default = '' ) use ( $old ): string {
	return esc_attr( $old[ $key ] ?? $default );
};
$err = function( string $key ) use ( $errors ): string {
	return isset( $errors[ $key ] )
		? '<span class="hl-field-error">' . esc_html( $errors[ $key ] ) . '</span>'
		: '';
};

// Restore multi-event rows from old POST on validation failure.
$old_event_categories  = (array) ( $old['hl_event_category']   ?? array( '' ) );
$old_event_start_dates = (array) ( $old['hl_event_start_date']  ?? array( '' ) );
$old_event_end_dates   = (array) ( $old['hl_event_end_date']    ?? array( '' ) );
$old_event_trainers    = (array) ( $old['hl_event_trainer']     ?? array( '' ) );
if ( empty( $old_event_categories ) ) {
	$old_event_categories = $old_event_start_dates = $old_event_end_dates = $old_event_trainers = array( '' );
}

$nonce_field = wp_nonce_field( 'hl_event_request_form', '_wpnonce', true, false );

// Build category/trainer/timezone option HTML for JS templates (rendered once, reused).
$category_options = '<option value="">— select type —</option>';
foreach ( $categories as $cat ) {
	$category_options .= '<option value="' . esc_attr( $cat['name'] ) . '">' . esc_html( $cat['name'] ) . '</option>';
}
$trainer_options = '<option value="TBA" selected>TBA</option>';
foreach ( $instructors as $inst ) {
	$trainer_options .= '<option value="' . esc_attr( $inst['name'] ) . '">' . esc_html( $inst['name'] ) . '</option>';
}
$timezone_options = '<option value="">— select timezone —</option>';
foreach ( Hostlinks_Event_Request::TIMEZONES as $tz ) {
	$timezone_options .= '<option value="' . esc_attr( $tz ) . '">' . esc_html( $tz ) . '</option>';
}

// Restore ZOOM/timezone per-event row values on validation failure.
$old_event_zooms     = (array) ( $old['hl_event_zoom']     ?? array() );
$old_event_timezones = (array) ( $old['hl_event_timezone']  ?? array() );
?>
<div class="hl-event-request-wrap">

<?php if ( ! empty( $errors ) ) : ?>
<div class="hl-form-errors">
	<strong>Please fix the following errors before submitting:</strong>
	<ul>
	<?php foreach ( $errors as $key => $msg ) : ?>
		<li><?php echo esc_html( $msg ); ?></li>
	<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<form id="hl-event-request-form" method="post" enctype="multipart/form-data" novalidate>
	<?php echo $nonce_field; ?>
	<div style="display:none;" aria-hidden="true">
		<input type="text" name="hl_hp_field" value="" tabindex="-1" autocomplete="off" />
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 1: Event Rows
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Events <span class="hl-section-note">(add one row per class date)</span></h3>

		<?php if ( isset( $errors['hl_events'] ) ) : ?>
			<p class="hl-field-error"><?php echo esc_html( $errors['hl_events'] ); ?></p>
		<?php endif; ?>

		<div id="hl-event-rows">
		<?php foreach ( $old_event_categories as $i => $old_cat ) :
			$is_zoom = ! empty( $old_event_zooms[$i] );
			$row_tz  = $old_event_timezones[$i] ?? '';
		?>
			<div class="hl-repeatable-row hl-event-row-item">
				<div class="hl-event-row-grid">
					<div class="hl-field-group">
						<label>Type <span class="hl-req">*</span></label>
						<select name="hl_event_category[]" class="<?php echo isset($errors['hl_event_category_' . $i]) ? 'hl-has-error' : ''; ?>">
							<option value="">— select type —</option>
							<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat['name'] ); ?>"
								<?php selected( $old_cat, $cat['name'] ); ?>>
								<?php echo esc_html( $cat['name'] ); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="hl-field-group">
						<label>Start Date <span class="hl-req">*</span></label>
						<input type="date" name="hl_event_start_date[]"
							value="<?php echo esc_attr( $old_event_start_dates[$i] ?? '' ); ?>"
							class="hl-date-pick<?php echo isset($errors['hl_event_start_date_' . $i]) ? ' hl-has-error' : ''; ?>" />
					</div>
					<div class="hl-field-group">
						<label>End Date <span class="hl-req">*</span></label>
						<input type="date" name="hl_event_end_date[]"
							value="<?php echo esc_attr( $old_event_end_dates[$i] ?? '' ); ?>"
							class="hl-date-pick<?php echo isset($errors['hl_event_end_date_' . $i]) ? ' hl-has-error' : ''; ?>" />
					</div>
					<div class="hl-field-group">
						<label>Trainer</label>
						<select name="hl_event_trainer[]">
							<option value="TBA" <?php selected( $old_event_trainers[$i] ?? 'TBA', 'TBA' ); ?>>TBA</option>
							<?php foreach ( $instructors as $inst ) : ?>
							<option value="<?php echo esc_attr( $inst['name'] ); ?>"
								<?php selected( $old_event_trainers[$i] ?? 'TBA', $inst['name'] ); ?>>
								<?php echo esc_html( $inst['name'] ); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="hl-field-group hl-event-zoom-col">
						<label>ZOOM?</label>
						<input type="checkbox" name="hl_event_zoom[]" value="1"
							<?php checked( $is_zoom ); ?>
							class="hl-zoom-toggle" />
					</div>
					<div class="hl-field-group hl-event-tz-col">
						<label>Timezone</label>
						<span class="hl-tz-na"<?php echo $is_zoom ? ' style="display:none;"' : ''; ?>>N/A</span>
						<select name="hl_event_timezone[]"<?php echo ! $is_zoom ? ' style="display:none;"' : ''; ?>>
							<option value="">— select timezone —</option>
							<?php foreach ( Hostlinks_Event_Request::TIMEZONES as $tz ) : ?>
							<option value="<?php echo esc_attr( $tz ); ?>"
								<?php selected( $row_tz, $tz ); ?>>
								<?php echo esc_html( $tz ); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<button type="button" class="hl-remove-row" aria-label="Remove event row"
					<?php echo $i === 0 ? 'style="visibility:hidden;"' : ''; ?>>✕</button>
			</div>
		<?php endforeach; ?>
		</div>
		<button type="button" class="hl-add-row" data-target="hl-event-rows" data-template="event">+ Add Event</button>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 2: Additional Event Details
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Additional Event Details</h3>
		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_marketer">Who is marketing this event? <span class="hl-req">*</span></label>
				<select id="hl_marketer" name="hl_marketer" class="<?php echo isset($errors['hl_marketer']) ? 'hl-has-error' : ''; ?>">
					<option value="">— select marketer —</option>
					<?php foreach ( $marketers as $mkt ) : ?>
					<option value="<?php echo esc_attr( $mkt['name'] ); ?>"
						<?php selected( $old['hl_marketer'] ?? '', $mkt['name'] ); ?>>
						<?php echo esc_html( $mkt['name'] ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php echo $err('hl_marketer'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_max_attendees">Event Capacity <span class="hl-optional">(Optional)</span></label>
				<input type="number" id="hl_max_attendees" name="hl_max_attendees"
					value="<?php echo $o('hl_max_attendees'); ?>"
					placeholder="Unlimited" min="1" />
				<?php echo $err('hl_max_attendees'); ?>
			</div>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 4: Host / Venue
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Host &amp; Venue</h3>

		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_host_name">Host Name <span class="hl-optional">(Optional)</span></label>
				<input type="text" id="hl_host_name" name="hl_host_name"
					value="<?php echo $o('hl_host_name'); ?>"
					placeholder="e.g. City of Phoenix" />
			</div>
			<div class="hl-field-group">
				<label for="hl_displayed_as">Displayed as <span class="hl-optional">(Optional)</span></label>
				<input type="text" id="hl_displayed_as" name="hl_displayed_as"
					value="<?php echo $o('hl_displayed_as'); ?>"
					placeholder="Hosted by …" />
				<small style="color:#888;">Auto-filled from Host Name — edit if needed.</small>
			</div>
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_location_name">Location Name / Building <span class="hl-optional">(Optional)</span></label>
			<input type="text" id="hl_location_name" name="hl_location_name"
				value="<?php echo $o('hl_location_name'); ?>" placeholder="e.g. City Hall, Conference Center" />
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_street_address_1">Address Line 1</label>
			<input type="text" id="hl_street_address_1" name="hl_street_address_1"
				value="<?php echo $o('hl_street_address_1'); ?>" placeholder="123 Main St" />
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_street_address_2">Address Line 2 <span class="hl-optional">(Optional)</span></label>
			<input type="text" id="hl_street_address_2" name="hl_street_address_2"
				value="<?php echo $o('hl_street_address_2'); ?>" placeholder="Suite, Floor, etc." />
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_street_address_3">Address Line 3 <span class="hl-optional">(Optional)</span></label>
			<input type="text" id="hl_street_address_3" name="hl_street_address_3"
				value="<?php echo $o('hl_street_address_3'); ?>" placeholder="" />
		</div>

		<div class="hl-field-row hl-col-3">
			<div class="hl-field-group">
				<label for="hl_city">City <span id="hl-city-req" class="hl-req" style="<?php echo ! empty( $o('hl_street_address_1') ) ? '' : 'display:none;'; ?>">*</span></label>
				<input type="text" id="hl_city" name="hl_city"
					value="<?php echo $o('hl_city'); ?>" placeholder="City"
					class="<?php echo isset($errors['hl_city']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_city'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_state">State <span id="hl-state-req" class="hl-req" style="<?php echo ! empty( $o('hl_street_address_1') ) ? '' : 'display:none;'; ?>">*</span></label>
				<select id="hl_state" name="hl_state"
					class="<?php echo isset($errors['hl_state']) ? 'hl-has-error' : ''; ?>">
					<option value="">— select —</option>
					<?php
					$us_states = [ 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC' ];
					foreach ( $us_states as $abbr ) :
					?>
					<option value="<?php echo esc_attr( $abbr ); ?>"
						<?php selected( $o('hl_state'), $abbr ); ?>>
						<?php echo esc_html( $abbr ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php echo $err('hl_state'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_zip_code">ZIP Code</label>
				<input type="text" id="hl_zip_code" name="hl_zip_code"
					value="<?php echo $o('hl_zip_code'); ?>" placeholder="12345" maxlength="10" />
			</div>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 5: Special Instructions / Parking
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Special Instructions / Parking <span class="hl-section-note">(Optional)</span></h3>

		<div class="hl-field-group hl-col-1">
			<textarea id="hl_special_instructions" name="hl_special_instructions" rows="3"
				placeholder="Parking instructions, building access notes, etc."><?php
				echo esc_textarea( $old['hl_special_instructions'] ?? '' );
			?></textarea>
		</div>

		<div class="hl-field-group hl-col-1" style="margin-top:10px;">
			<label for="hl_parking_file">Attach Parking / Instructions PDF <span class="hl-optional">(Optional — PDF only)</span></label>
			<input type="file" id="hl_parking_file" name="hl_parking_file" accept="application/pdf,.pdf" />
			<?php echo $err('hl_parking_file'); ?>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 6: Custom Email Intro
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Custom Email Intro <span class="hl-section-note">(Optional)</span></h3>
		<div class="hl-field-group hl-col-1">
			<textarea id="hl_custom_email_intro" name="hl_custom_email_intro" rows="4"
				placeholder="Opening paragraph for registration / marketing emails for this event."><?php
				echo esc_textarea( $old['hl_custom_email_intro'] ?? '' );
			?></textarea>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 7: Host Contacts
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Host Contacts <span class="hl-section-note">(Optional)</span></h3>

		<div id="hl-contact-rows">
			<?php
			$c_names    = (array) ( $old['hl_contact_name']   ?? array() );
			$c_agencies = (array) ( $old['hl_contact_agency'] ?? array() );
			$c_titles   = (array) ( $old['hl_contact_title']  ?? array() );
			$c_emails   = (array) ( $old['hl_contact_email']  ?? array() );
			$c_phones   = (array) ( $old['hl_contact_phone']  ?? array() );
			$c_phones2  = (array) ( $old['hl_contact_phone2'] ?? array() );
			$c_cc       = (array) ( $old['hl_contact_cc']     ?? array() );
			$c_include  = (array) ( $old['hl_contact_include_email'] ?? array() );
			if ( empty( $c_names ) ) {
				$c_names = $c_agencies = $c_titles = $c_emails = $c_phones = $c_phones2 = array( '' );
				$c_cc      = array( '1' );
				$c_include = array( '1' );
			}
			foreach ( $c_names as $i => $cname ) :
				$is_first    = ( $i === 0 );
				$cc_checked  = isset( $c_cc[$i] )      ? $c_cc[$i]      : ( $is_first ? '1' : '' );
				$inc_checked = isset( $c_include[$i] ) ? $c_include[$i] : ( $is_first ? '1' : '' );
			?>
			<div class="hl-repeatable-row hl-contact-row">
				<div class="hl-contact-inner">
					<!-- Input row -->
					<div class="hl-contact-fields">
						<div class="hl-field-group">
							<label>Name</label>
							<input type="text" name="hl_contact_name[]"
								value="<?php echo esc_attr( $cname ); ?>" placeholder="Name" />
						</div>
						<div class="hl-field-group">
							<label>Agency</label>
							<input type="text" name="hl_contact_agency[]"
								value="<?php echo esc_attr( $c_agencies[$i] ?? '' ); ?>" placeholder="Agency" />
						</div>
						<div class="hl-field-group">
							<label>Title</label>
							<input type="text" name="hl_contact_title[]"
								value="<?php echo esc_attr( $c_titles[$i] ?? '' ); ?>" placeholder="Title" />
						</div>
						<div class="hl-field-group">
							<label>Email</label>
							<input type="email" name="hl_contact_email[]"
								value="<?php echo esc_attr( $c_emails[$i] ?? '' ); ?>" placeholder="email@example.com" />
						</div>
						<div class="hl-field-group">
							<label>Phone</label>
							<input type="tel" name="hl_contact_phone[]"
								value="<?php echo esc_attr( $c_phones[$i] ?? '' ); ?>" placeholder="Phone" />
						</div>
						<div class="hl-field-group">
							<label>Phone 2</label>
							<input type="tel" name="hl_contact_phone2[]"
								value="<?php echo esc_attr( $c_phones2[$i] ?? '' ); ?>" placeholder="Phone 2" />
						</div>
						<div class="hl-reg-alerts-cell">
							<span class="hl-reg-alerts-label">Reg<br>Alerts</span>
							<label class="hl-check-reg-alerts" title="CC on Registration Alerts">
								<input type="checkbox" name="hl_contact_cc[<?php echo $i; ?>]" value="1"
									<?php checked( $cc_checked, '1' ); ?> />
							</label>
						</div>
					</div>
					<!-- Checkbox row — same columns, checkboxes aligned under their fields -->
					<div class="hl-contact-checks-row">
						<div style="grid-column: 1 / 5">
							<label class="hl-check-label">
								<input type="checkbox" name="hl_contact_include_email[<?php echo $i; ?>]" value="1"
									<?php checked( $inc_checked, '1' ); ?> />
								Include in Email Template
							</label>
						</div>
						<div>
							<label class="hl-check-label">
								<input type="checkbox" name="hl_contact_dnl_phone[<?php echo $i; ?>]"
									<?php checked( ! empty( $old['hl_contact_dnl_phone'][$i] ) ); ?> />
								Do Not List
							</label>
						</div>
						<div>
							<label class="hl-check-label">
								<input type="checkbox" name="hl_contact_dnl_phone2[<?php echo $i; ?>]"
									<?php checked( ! empty( $old['hl_contact_dnl_phone2'][$i] ) ); ?> />
								Do Not List
							</label>
						</div>
						<div></div><!-- spacer under Reg Alerts column -->
					</div>
				</div>
				<button type="button" class="hl-remove-row" aria-label="Remove"
					<?php echo $is_first ? 'style="visibility:hidden;"' : ''; ?>>✕</button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="hl-add-row" data-target="hl-contact-rows" data-template="contact">+ Add Host Contact</button>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 8: Hotel Recommendations
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Hotel Recommendations <span class="hl-section-note">(Optional)</span></h3>

		<div id="hl-hotel-rows">
			<?php
			$hotel_names   = (array) ( $old['hl_hotel_name']    ?? array() );
			$hotel_phones  = (array) ( $old['hl_hotel_phone']   ?? array() );
			$hotel_addrs   = (array) ( $old['hl_hotel_address'] ?? array() );
			$hotel_urls    = (array) ( $old['hl_hotel_url']     ?? array() );
			if ( empty( $hotel_names ) ) {
				$hotel_names = $hotel_phones = $hotel_addrs = $hotel_urls = array( '' );
			}
			foreach ( $hotel_names as $i => $hname ) :
			?>
			<div class="hl-repeatable-row hl-hotel-row">
				<div class="hl-hotel-grid">
					<div class="hl-field-group">
						<label>Hotel Name</label>
						<input type="text" name="hl_hotel_name[]"
							value="<?php echo esc_attr( $hname ); ?>" placeholder="Hotel Name" />
					</div>
					<div class="hl-field-group">
						<label>Phone</label>
						<input type="tel" name="hl_hotel_phone[]"
							value="<?php echo esc_attr( $hotel_phones[$i] ?? '' ); ?>" placeholder="Phone" />
					</div>
					<div class="hl-field-group">
						<label>Address</label>
						<input type="text" name="hl_hotel_address[]"
							value="<?php echo esc_attr( $hotel_addrs[$i] ?? '' ); ?>" placeholder="Address" />
					</div>
				</div>
				<button type="button" class="hl-remove-row" aria-label="Remove"
					<?php echo $i === 0 ? 'style="visibility:hidden;"' : ''; ?>>✕</button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="hl-add-row" data-target="hl-hotel-rows" data-template="hotel">+ Add Hotel</button>
	</div>

	<!-- Submit -->
	<div class="hl-form-section hl-form-submit">
		<button type="submit" name="hl_event_request_submit" value="1" class="hl-submit-btn">
			Submit Event Request
		</button>
	</div>
</form>
</div><!-- .hl-event-request-wrap -->

<?php /* ── JS templates for repeatable rows ─────────────────────────────── */ ?>
<script id="hl-tpl-event" type="text/x-template">
<div class="hl-repeatable-row hl-event-row-item">
	<div class="hl-event-row-grid">
		<div class="hl-field-group">
			<label>Type <span class="hl-req">*</span></label>
			<select name="hl_event_category[]"><?php echo $category_options; ?></select>
		</div>
		<div class="hl-field-group">
			<label>Start Date <span class="hl-req">*</span></label>
			<input type="date" name="hl_event_start_date[]" class="hl-date-pick" />
		</div>
		<div class="hl-field-group">
			<label>End Date <span class="hl-req">*</span></label>
			<input type="date" name="hl_event_end_date[]" class="hl-date-pick" />
		</div>
		<div class="hl-field-group">
			<label>Trainer</label>
			<select name="hl_event_trainer[]"><?php echo $trainer_options; ?></select>
		</div>
		<div class="hl-field-group hl-event-zoom-col">
			<label>ZOOM?</label>
			<input type="checkbox" name="hl_event_zoom[]" value="1" class="hl-zoom-toggle" />
		</div>
		<div class="hl-field-group hl-event-tz-col">
			<label>Timezone</label>
			<span class="hl-tz-na">N/A</span>
			<select name="hl_event_timezone[]" style="display:none;"><?php echo $timezone_options; ?></select>
		</div>
	</div>
	<button type="button" class="hl-remove-row" aria-label="Remove event row">✕</button>
</div>
</script>

<script id="hl-tpl-hotel" type="text/x-template">
<div class="hl-repeatable-row hl-hotel-row">
	<div class="hl-hotel-grid">
		<div class="hl-field-group"><label>Hotel Name</label><input type="text" name="hl_hotel_name[]" value="" placeholder="Hotel Name" /></div>
		<div class="hl-field-group"><label>Phone</label><input type="tel" name="hl_hotel_phone[]" value="" placeholder="Phone" /></div>
		<div class="hl-field-group"><label>Address</label><input type="text" name="hl_hotel_address[]" value="" placeholder="Address" /></div>
	</div>
	<button type="button" class="hl-remove-row" aria-label="Remove">✕</button>
</div>
</script>

<script id="hl-tpl-contact" type="text/x-template">
<div class="hl-repeatable-row hl-contact-row">
	<div class="hl-contact-inner">
		<div class="hl-contact-fields">
			<div class="hl-field-group"><label>Name</label><input type="text" name="hl_contact_name[]" value="" placeholder="Name" /></div>
			<div class="hl-field-group"><label>Agency</label><input type="text" name="hl_contact_agency[]" value="" placeholder="Agency" /></div>
			<div class="hl-field-group"><label>Title</label><input type="text" name="hl_contact_title[]" value="" placeholder="Title" /></div>
			<div class="hl-field-group"><label>Email</label><input type="email" name="hl_contact_email[]" value="" placeholder="email@example.com" /></div>
			<div class="hl-field-group"><label>Phone</label><input type="tel" name="hl_contact_phone[]" value="" placeholder="Phone" /></div>
			<div class="hl-field-group"><label>Phone 2</label><input type="tel" name="hl_contact_phone2[]" value="" placeholder="Phone 2" /></div>
			<div class="hl-reg-alerts-cell">
				<span class="hl-reg-alerts-label">Reg<br>Alerts</span>
				<label class="hl-check-reg-alerts" title="CC on Registration Alerts">
					<input type="checkbox" name="hl_contact_cc[NEW_INDEX]" value="1" />
				</label>
			</div>
		</div>
		<div class="hl-contact-checks-row">
			<div style="grid-column: 1 / 5">
				<label class="hl-check-label"><input type="checkbox" name="hl_contact_include_email[NEW_INDEX]" value="1" /> Include in Email Template</label>
			</div>
			<div><label class="hl-check-label"><input type="checkbox" name="hl_contact_dnl_phone[NEW_INDEX]" /> Do Not List</label></div>
			<div><label class="hl-check-label"><input type="checkbox" name="hl_contact_dnl_phone2[NEW_INDEX]" /> Do Not List</label></div>
			<div></div>
		</div>
	</div>
	<button type="button" class="hl-remove-row" aria-label="Remove">✕</button>
</div>
</script>

<script>
(function(){
	var form = document.getElementById('hl-event-request-form');
	if (!form) return;

	// ── City/State required indicator when Address 1 is filled ──────────
	var addr1Field  = document.getElementById('hl_street_address_1');
	var cityReqSpan = document.getElementById('hl-city-req');
	var stateReqSpan= document.getElementById('hl-state-req');
	function updateAddrRequired() {
		var hasAddr = addr1Field && addr1Field.value.trim() !== '';
		if (cityReqSpan)  cityReqSpan.style.display  = hasAddr ? '' : 'none';
		if (stateReqSpan) stateReqSpan.style.display = hasAddr ? '' : 'none';
	}
	if (addr1Field) {
		addr1Field.addEventListener('input', updateAddrRequired);
		updateAddrRequired();
	}

	// ── Google Places address autocomplete ────────────────────────────────
	<?php if ( ! empty( $maps_api_key ) ) : ?>
	function initPlacesAutocomplete() {
		if ( typeof google === 'undefined' || !google.maps || !google.maps.places ) return;
		if ( !addr1Field ) return;
		var ac = new google.maps.places.Autocomplete( addr1Field, {
			types: ['address'],
			componentRestrictions: { country: 'us' },
			fields: ['address_components']
		});
		ac.addListener('place_changed', function() {
			var place = ac.getPlace();
			if ( !place || !place.address_components ) return;
			var streetNum = '', route = '', city = '', state = '', zip = '';
			place.address_components.forEach(function(c) {
				var t = c.types[0];
				if      ( t === 'street_number' )                streetNum = c.long_name;
				else if ( t === 'route' )                        route     = c.short_name;
				else if ( t === 'locality' )                     city      = c.long_name;
				else if ( t === 'administrative_area_level_1' )  state     = c.short_name;
				else if ( t === 'postal_code' )                  zip       = c.long_name;
			});
			addr1Field.value = (streetNum + ' ' + route).trim();
			var cityField  = document.getElementById('hl_city');
			var stateField = document.getElementById('hl_state');
			var zipField   = document.getElementById('hl_zip');
			if (cityField)  cityField.value  = city;
			if (stateField) stateField.value = state;
			if (zipField)   zipField.value   = zip;
			updateAddrRequired();
		});
	}
	if ( typeof google !== 'undefined' && google.maps && google.maps.places ) {
		initPlacesAutocomplete();
	} else {
		window.addEventListener('load', initPlacesAutocomplete);
	}
	<?php endif; ?>

	// ── Date picker: open on any click in the field ───────────────────────
	function initDatePicker(input) {
		input.addEventListener('click', function() {
			if (typeof this.showPicker === 'function') {
				try { this.showPicker(); } catch(e) {}
			}
		});
	}
	document.querySelectorAll('input.hl-date-pick').forEach(initDatePicker);

	// ── ZOOM toggle: swap N/A text ↔ Timezone dropdown ────────────────────
	function initZoomToggle(checkbox) {
		var grid     = checkbox.closest('.hl-event-row-grid');
		if (!grid) return;
		var naSpan   = grid.querySelector('.hl-tz-na');
		var tzSelect = grid.querySelector('.hl-event-tz-col select');
		checkbox.addEventListener('change', function() {
			var zoomed = this.checked;
			if (naSpan)   naSpan.style.display   = zoomed ? 'none' : '';
			if (tzSelect) tzSelect.style.display  = zoomed ? ''     : 'none';
		});
	}
	document.querySelectorAll('.hl-zoom-toggle').forEach(initZoomToggle);

	// ── "Displayed as" live preview ────────────────────────────────────────
	var hostNameField    = document.getElementById('hl_host_name');
	var displayedAsField = document.getElementById('hl_displayed_as');
	if (hostNameField && displayedAsField) {
		hostNameField.addEventListener('input', function(){
			// Only auto-update if the user hasn't manually edited the field,
			// or if it's still matching the auto pattern.
			var auto = displayedAsField.dataset.manualEdit !== '1';
			if (auto) {
				var name = hostNameField.value.trim();
				displayedAsField.value = name ? 'Hosted by ' + name : '';
			}
		});
		displayedAsField.addEventListener('input', function(){
			displayedAsField.dataset.manualEdit = '1';
		});
		// If displayed_as is empty on load, set it from host name.
		if (!displayedAsField.value && hostNameField.value) {
			displayedAsField.value = 'Hosted by ' + hostNameField.value.trim();
		}
	}

	// ── Repeatable rows ────────────────────────────────────────────────────
	var contactIdx = <?php echo max( 1, count( $c_names ?? array(1) ) ); ?>;

	function attachRemove(row) {
		var btn = row.querySelector('.hl-remove-row');
		if (!btn) return;
		btn.addEventListener('click', function(){
			var container = row.parentNode;
			row.remove();
			updateFirstRemoveBtn(container);
		});
	}

	function updateFirstRemoveBtn(container) {
		var rows = container.querySelectorAll(':scope > .hl-repeatable-row');
		rows.forEach(function(r, i){
			var btn = r.querySelector('.hl-remove-row');
			if (btn) btn.style.visibility = (i === 0) ? 'hidden' : '';
		});
	}

	document.querySelectorAll('.hl-repeatable-row').forEach(function(r){ attachRemove(r); });

	document.querySelectorAll('.hl-add-row').forEach(function(btn){
		btn.addEventListener('click', function(){
			var target = document.getElementById(btn.dataset.target);
			var tpl    = document.getElementById('hl-tpl-' + btn.dataset.template);
			if (!target || !tpl) return;
			var html = tpl.innerHTML;
			if (btn.dataset.template === 'contact') {
				html = html.replace(/NEW_INDEX/g, contactIdx++);
			}
			var tmp = document.createElement('div');
			tmp.innerHTML = html.trim();
			var newRow = tmp.firstChild;
			target.appendChild(newRow);
			attachRemove(newRow);
			updateFirstRemoveBtn(target);
			// Init ZOOM toggles and date pickers in the new row.
			newRow.querySelectorAll('.hl-zoom-toggle').forEach(initZoomToggle);
			newRow.querySelectorAll('input.hl-date-pick').forEach(initDatePicker);
		});
	});
})();
</script>
