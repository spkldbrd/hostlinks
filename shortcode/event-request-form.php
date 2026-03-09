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

/* ── Helpers ──────────────────────────────────────────────────────────────── */
$o = function( string $key, string $default = '' ) use ( $old ): string {
	return esc_attr( $old[ $key ] ?? $default );
};
$err = function( string $key ) use ( $errors ): string {
	return isset( $errors[ $key ] )
		? '<span class="hl-field-error">' . esc_html( $errors[ $key ] ) . '</span>'
		: '';
};
$is_virtual = ( ( $old['hl_format'] ?? '' ) === Hostlinks_Event_Request::FORMAT_VIRTUAL );
$nonce_field = wp_nonce_field( 'hl_event_request_form', '_wpnonce', true, false );
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

<form id="hl-event-request-form" method="post" novalidate>
	<?php echo $nonce_field; ?>
	<!-- Honeypot — must remain empty; bots fill it, humans don't. -->
	<div style="display:none;" aria-hidden="true">
		<input type="text" name="hl_hp_field" value="" tabindex="-1" autocomplete="off" />
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 1: Event Details
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Event Details</h3>

		<div class="hl-field-group hl-col-1">
			<label for="hl_event_title">Event Title <span class="hl-req">*</span></label>
			<input type="text" id="hl_event_title" name="hl_event_title"
				value="<?php echo $o('hl_event_title'); ?>"
				placeholder="Enter event title" class="<?php echo isset($errors['hl_event_title']) ? 'hl-has-error' : ''; ?>" />
			<?php echo $err('hl_event_title'); ?>
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_description">Description</label>
			<textarea id="hl_description" name="hl_description" rows="4"
				placeholder="Enter event description"><?php echo esc_textarea( $old['hl_description'] ?? '' ); ?></textarea>
		</div>

		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_category">Category <span class="hl-req">*</span></label>
				<select id="hl_category" name="hl_category" class="<?php echo isset($errors['hl_category']) ? 'hl-has-error' : ''; ?>">
					<option value="">Select a category</option>
					<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat['name'] ); ?>"
						<?php selected( $old['hl_category'] ?? '', $cat['name'] ); ?>>
						<?php echo esc_html( $cat['name'] ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php echo $err('hl_category'); ?>
			</div>

			<div class="hl-field-group">
				<label>Format <span class="hl-req">*</span></label>
				<div class="hl-radio-group">
					<label class="hl-radio-label">
						<input type="radio" name="hl_format" value="in-person"
							<?php checked( $old['hl_format'] ?? 'in-person', 'in-person' ); ?> />
						In-Person
					</label>
					<label class="hl-radio-label">
						<input type="radio" name="hl_format" value="virtual"
							<?php checked( $old['hl_format'] ?? '', 'virtual' ); ?> />
						Virtual (Zoom)
					</label>
				</div>
				<?php echo $err('hl_format'); ?>
			</div>
		</div>

		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_timezone">Timezone <span class="hl-req">*</span></label>
				<select id="hl_timezone" name="hl_timezone" class="<?php echo isset($errors['hl_timezone']) ? 'hl-has-error' : ''; ?>">
					<option value="">Select timezone</option>
					<?php foreach ( Hostlinks_Event_Request::TIMEZONES as $tz ) : ?>
					<option value="<?php echo esc_attr( $tz ); ?>"
						<?php selected( $old['hl_timezone'] ?? 'EST (Eastern Time)', $tz ); ?>>
						<?php echo esc_html( $tz ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php echo $err('hl_timezone'); ?>
			</div>

			<div class="hl-field-group">
				<label for="hl_trainer">Trainer <span class="hl-req">*</span></label>
				<select id="hl_trainer" name="hl_trainer" class="<?php echo isset($errors['hl_trainer']) ? 'hl-has-error' : ''; ?>">
					<option value="">Select a trainer</option>
					<?php foreach ( $instructors as $inst ) : ?>
					<option value="<?php echo esc_attr( $inst['name'] ); ?>"
						<?php selected( $old['hl_trainer'] ?? '', $inst['name'] ); ?>>
						<?php echo esc_html( $inst['name'] ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php echo $err('hl_trainer'); ?>
			</div>
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_marketer">Marketer <span class="hl-optional">(Optional)</span></label>
			<select id="hl_marketer" name="hl_marketer">
				<option value="">None</option>
				<?php foreach ( $marketers as $mkt ) : ?>
				<option value="<?php echo esc_attr( $mkt['name'] ); ?>"
					<?php selected( $old['hl_marketer'] ?? '', $mkt['name'] ); ?>>
					<?php echo esc_html( $mkt['name'] ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 2: Dates & Times
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Dates &amp; Times</h3>

		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_start_date">Start Date <span class="hl-req">*</span></label>
				<input type="date" id="hl_start_date" name="hl_start_date"
					value="<?php echo $o('hl_start_date'); ?>"
					class="<?php echo isset($errors['hl_start_date']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_start_date'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_end_date">End Date <span class="hl-req">*</span></label>
				<input type="date" id="hl_end_date" name="hl_end_date"
					value="<?php echo $o('hl_end_date'); ?>"
					class="<?php echo isset($errors['hl_end_date']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_end_date'); ?>
			</div>
		</div>

		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_start_time">Start Time <span class="hl-req">*</span></label>
				<input type="time" id="hl_start_time" name="hl_start_time"
					value="<?php echo $o('hl_start_time', '09:00'); ?>"
					class="<?php echo isset($errors['hl_start_time']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_start_time'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_end_time">End Time <span class="hl-req">*</span></label>
				<input type="time" id="hl_end_time" name="hl_end_time"
					value="<?php echo $o('hl_end_time', '16:00'); ?>"
					class="<?php echo isset($errors['hl_end_time']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_end_time'); ?>
			</div>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 3: Venue / Host
	     Hidden when format = Virtual (Zoom) via JS; fields not required then.
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section" id="hl-venue-section"
		<?php if ( $is_virtual ) echo 'style="display:none;"'; ?>>
		<h3 class="hl-section-title">Venue / Host</h3>

		<div class="hl-field-group hl-col-1">
			<label for="hl_host_name">Host / Hosted By</label>
			<input type="text" id="hl_host_name" name="hl_host_name"
				value="<?php echo $o('hl_host_name'); ?>" placeholder="Hosted by" />
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_location_name">Location Name / Building <span class="hl-optional">(Optional)</span></label>
			<input type="text" id="hl_location_name" name="hl_location_name"
				value="<?php echo $o('hl_location_name'); ?>" placeholder="e.g. City Hall, Conference Center" />
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_street_address_1">Street Address <span class="hl-req hl-req-inperson">*</span></label>
			<input type="text" id="hl_street_address_1" name="hl_street_address_1"
				value="<?php echo $o('hl_street_address_1'); ?>" placeholder="123 Main St"
				class="<?php echo isset($errors['hl_street_address_1']) ? 'hl-has-error' : ''; ?>" />
			<?php echo $err('hl_street_address_1'); ?>
		</div>

		<div class="hl-field-group hl-col-1">
			<label for="hl_street_address_2">Street Address 2 <span class="hl-optional">(Optional)</span></label>
			<input type="text" id="hl_street_address_2" name="hl_street_address_2"
				value="<?php echo $o('hl_street_address_2'); ?>" placeholder="Suite, Apt, Floor, etc." />
		</div>

		<div class="hl-field-row hl-col-3">
			<div class="hl-field-group">
				<label for="hl_city">City <span class="hl-req hl-req-inperson">*</span></label>
				<input type="text" id="hl_city" name="hl_city"
					value="<?php echo $o('hl_city'); ?>" placeholder="City"
					class="<?php echo isset($errors['hl_city']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_city'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_state">State <span class="hl-req hl-req-inperson">*</span></label>
				<input type="text" id="hl_state" name="hl_state"
					value="<?php echo $o('hl_state'); ?>" placeholder="State" maxlength="50"
					class="<?php echo isset($errors['hl_state']) ? 'hl-has-error' : ''; ?>" />
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
	     SECTION 4: Event Contacts / CC Emails
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Event Contacts <span class="hl-section-note">(CC on Registration Emails)</span></h3>

		<div id="hl-cc-email-rows">
			<?php
			$cc_raw = (array) ( $old['hl_cc_email'] ?? array( '' ) );
			if ( empty( $cc_raw ) ) $cc_raw = array( '' );
			foreach ( $cc_raw as $i => $email ) :
			?>
			<div class="hl-repeatable-row hl-cc-row">
				<input type="email" name="hl_cc_email[]"
					value="<?php echo esc_attr( $email ); ?>"
					placeholder="contact@example.com"
					class="<?php echo isset($errors[ 'hl_cc_email_' . $i ]) ? 'hl-has-error' : ''; ?>" />
				<button type="button" class="hl-remove-row" aria-label="Remove"
					<?php echo $i === 0 ? 'style="visibility:hidden;"' : ''; ?>>✕</button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="hl-add-row" data-target="hl-cc-email-rows"
			data-template="cc">+ Add Email</button>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 5: Registration / Capacity
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Registration &amp; Capacity</h3>

		<div class="hl-field-row hl-col-2">
			<div class="hl-field-group">
				<label for="hl_price">Price ($) <span class="hl-req">*</span></label>
				<input type="number" id="hl_price" name="hl_price"
					value="<?php echo $o('hl_price'); ?>" placeholder="0.00"
					step="0.01" min="0"
					class="<?php echo isset($errors['hl_price']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_price'); ?>
			</div>
			<div class="hl-field-group">
				<label for="hl_max_attendees">Max Attendees <span class="hl-optional">(Optional)</span></label>
				<input type="number" id="hl_max_attendees" name="hl_max_attendees"
					value="<?php echo $o('hl_max_attendees'); ?>"
					placeholder="Leave empty for unlimited" min="1"
					class="<?php echo isset($errors['hl_max_attendees']) ? 'hl-has-error' : ''; ?>" />
				<?php echo $err('hl_max_attendees'); ?>
			</div>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 6: Special Message
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section">
		<h3 class="hl-section-title">Special Message <span class="hl-section-note">(Optional)</span></h3>
		<div class="hl-field-group hl-col-1">
			<textarea id="hl_special_message" name="hl_special_message" rows="3"
				placeholder="Add a special message for this event (appears on marketing page and can be used in emails)"><?php
				echo esc_textarea( $old['hl_special_message'] ?? '' );
			?></textarea>
		</div>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 7: Hotel Recommendations
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
					<div class="hl-field-group">
						<label>Website URL</label>
						<input type="url" name="hl_hotel_url[]"
							value="<?php echo esc_attr( $hotel_urls[$i] ?? '' ); ?>" placeholder="https://..." />
					</div>
				</div>
				<button type="button" class="hl-remove-row" aria-label="Remove"
					<?php echo $i === 0 ? 'style="visibility:hidden;"' : ''; ?>>✕</button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="hl-add-row" data-target="hl-hotel-rows"
			data-template="hotel">+ Add Hotel</button>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 8: Host Contacts
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
			if ( empty( $c_names ) ) {
				$c_names = $c_agencies = $c_titles = $c_emails = $c_phones = $c_phones2 = array( '' );
			}
			foreach ( $c_names as $i => $cname ) :
			?>
			<div class="hl-repeatable-row hl-contact-row">
				<div class="hl-contact-grid">
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
					<div class="hl-field-group hl-col-checks">
						<label class="hl-check-label">
							<input type="checkbox" name="hl_contact_dnl_phone[<?php echo $i; ?>]"
								<?php checked( ! empty( $old['hl_contact_dnl_phone'][$i] ) ); ?> />
							Do Not List Phone
						</label>
						<label class="hl-check-label">
							<input type="checkbox" name="hl_contact_dnl_phone2[<?php echo $i; ?>]"
								<?php checked( ! empty( $old['hl_contact_dnl_phone2'][$i] ) ); ?> />
							Do Not List Phone 2
						</label>
						<label class="hl-check-label">
							<input type="checkbox" name="hl_contact_publish[<?php echo $i; ?>]"
								<?php checked( ! empty( $old['hl_contact_publish'][$i] ) ); ?> />
							Publish Contact
						</label>
					</div>
				</div>
				<button type="button" class="hl-remove-row" aria-label="Remove"
					<?php echo $i === 0 ? 'style="visibility:hidden;"' : ''; ?>>✕</button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="hl-add-row" data-target="hl-contact-rows"
			data-template="contact">+ Add Host Contact</button>
	</div>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     Submit
	════════════════════════════════════════════════════════════════════════ -->
	<div class="hl-form-section hl-form-submit">
		<button type="submit" name="hl_event_request_submit" value="1" class="hl-submit-btn">
			Submit Event Request
		</button>
	</div>
</form>
</div><!-- .hl-event-request-wrap -->

<?php /* ── Repeatable row JS templates ─────────────────────────────────── */ ?>
<script id="hl-tpl-cc" type="text/x-template">
<div class="hl-repeatable-row hl-cc-row">
	<input type="email" name="hl_cc_email[]" value="" placeholder="contact@example.com" />
	<button type="button" class="hl-remove-row" aria-label="Remove">✕</button>
</div>
</script>

<script id="hl-tpl-hotel" type="text/x-template">
<div class="hl-repeatable-row hl-hotel-row">
	<div class="hl-hotel-grid">
		<div class="hl-field-group"><label>Hotel Name</label><input type="text" name="hl_hotel_name[]" value="" placeholder="Hotel Name" /></div>
		<div class="hl-field-group"><label>Phone</label><input type="tel" name="hl_hotel_phone[]" value="" placeholder="Phone" /></div>
		<div class="hl-field-group"><label>Address</label><input type="text" name="hl_hotel_address[]" value="" placeholder="Address" /></div>
		<div class="hl-field-group"><label>Website URL</label><input type="url" name="hl_hotel_url[]" value="" placeholder="https://..." /></div>
	</div>
	<button type="button" class="hl-remove-row" aria-label="Remove">✕</button>
</div>
</script>

<script id="hl-tpl-contact" type="text/x-template">
<div class="hl-repeatable-row hl-contact-row">
	<div class="hl-contact-grid">
		<div class="hl-field-group"><label>Name</label><input type="text" name="hl_contact_name[]" value="" placeholder="Name" /></div>
		<div class="hl-field-group"><label>Agency</label><input type="text" name="hl_contact_agency[]" value="" placeholder="Agency" /></div>
		<div class="hl-field-group"><label>Title</label><input type="text" name="hl_contact_title[]" value="" placeholder="Title" /></div>
		<div class="hl-field-group"><label>Email</label><input type="email" name="hl_contact_email[]" value="" placeholder="email@example.com" /></div>
		<div class="hl-field-group"><label>Phone</label><input type="tel" name="hl_contact_phone[]" value="" placeholder="Phone" /></div>
		<div class="hl-field-group"><label>Phone 2</label><input type="tel" name="hl_contact_phone2[]" value="" placeholder="Phone 2" /></div>
		<div class="hl-field-group hl-col-checks">
			<label class="hl-check-label"><input type="checkbox" name="hl_contact_dnl_phone[NEW_INDEX]" /> Do Not List Phone</label>
			<label class="hl-check-label"><input type="checkbox" name="hl_contact_dnl_phone2[NEW_INDEX]" /> Do Not List Phone 2</label>
			<label class="hl-check-label"><input type="checkbox" name="hl_contact_publish[NEW_INDEX]" /> Publish Contact</label>
		</div>
	</div>
	<button type="button" class="hl-remove-row" aria-label="Remove">✕</button>
</div>
</script>

<script>
(function(){
	var form = document.getElementById('hl-event-request-form');
	if (!form) return;

	// ── Conditional venue section ──────────────────────────────────────────
	var venueSection = document.getElementById('hl-venue-section');
	var reqInPerson  = venueSection ? venueSection.querySelectorAll('.hl-req-inperson') : [];

	function updateVenueVisibility() {
		var checked = form.querySelector('input[name="hl_format"]:checked');
		var isVirtual = checked && checked.value === 'virtual';
		if (venueSection) venueSection.style.display = isVirtual ? 'none' : '';
		// Remove required attribute when hidden so browser doesn't block submit.
		reqInPerson.forEach(function(el){
			var input = el.closest('.hl-field-group') && el.closest('.hl-field-group').querySelector('input, select, textarea');
			// Visual only — actual server-side validation controls this.
			if (input) input.required = !isVirtual;
		});
	}

	form.querySelectorAll('input[name="hl_format"]').forEach(function(radio){
		radio.addEventListener('change', updateVenueVisibility);
	});
	updateVenueVisibility();

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
		var rows = container.querySelectorAll('.hl-repeatable-row');
		rows.forEach(function(r, i){
			var btn = r.querySelector('.hl-remove-row');
			if (btn) btn.style.visibility = (i === 0) ? 'hidden' : '';
		});
	}

	// Init existing rows.
	document.querySelectorAll('.hl-repeatable-row').forEach(function(r){ attachRemove(r); });

	// Add row buttons.
	document.querySelectorAll('.hl-add-row').forEach(function(btn){
		btn.addEventListener('click', function(){
			var target   = document.getElementById(btn.dataset.target);
			var tpl      = document.getElementById('hl-tpl-' + btn.dataset.template);
			if (!target || !tpl) return;
			var html = tpl.innerHTML;
			// Replace index placeholder in contact checkboxes.
			if (btn.dataset.template === 'contact') {
				html = html.replace(/NEW_INDEX/g, contactIdx++);
			}
			var tmp = document.createElement('div');
			tmp.innerHTML = html.trim();
			var newRow = tmp.firstChild;
			target.appendChild(newRow);
			attachRemove(newRow);
			updateFirstRemoveBtn(target);
		});
	});
})();
</script>
