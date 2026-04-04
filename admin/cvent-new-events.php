<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$s     = Hostlinks_CVENT_API::get_settings();
$ready = ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && ! empty( $s['account_number'] );

$action_msg  = '';
$action_type = 'success';

// ── Fetch action ─────────────────────────────────────────────────────────────
if ( isset( $_POST['hostlinks_cvent_fetch_new'] ) ) {
	check_admin_referer( 'hostlinks_cvent_new_events' );
	$result = Hostlinks_CVENT_Sync::find_new_events( true );
	if ( is_wp_error( $result ) ) {
		$action_msg  = 'API error: ' . esc_html( $result->get_error_message() );
		$action_type = 'error';
	} else {
		$cnt        = count( $result );
		$action_msg = $cnt > 0
			? sprintf( 'Fetch complete — %d new CVENT event(s) found.', $cnt )
			: 'Fetch complete — no new CVENT events detected.';
	}
}

// ── Ignore action ────────────────────────────────────────────────────────────
if ( isset( $_POST['hostlinks_cvent_ignore'] ) ) {
	check_admin_referer( 'hostlinks_cvent_new_events' );
	$ignore_uuid = sanitize_text_field( $_POST['ignore_uuid'] ?? '' );
	if ( $ignore_uuid ) {
		$ignored = (array) get_option( 'hostlinks_cvent_ignored_events', array() );
		if ( ! in_array( $ignore_uuid, $ignored, true ) ) {
			$ignored[] = $ignore_uuid;
			update_option( 'hostlinks_cvent_ignored_events', $ignored );
		}
		// Remove from transient so it disappears immediately.
		$cached = get_transient( 'hostlinks_cvent_new_events' );
		if ( is_array( $cached ) ) {
			$cached = array_values( array_filter(
				$cached,
				function( $e ) use ( $ignore_uuid ) {
					return Hostlinks_CVENT_API::sanitize_uuid( $e['id'] ?? '' ) !== $ignore_uuid;
				}
			) );
			set_transient( 'hostlinks_cvent_new_events', $cached, HOUR_IN_SECONDS );
			update_option( 'hostlinks_cvent_new_count', count( $cached ) );
		}
		$action_msg = 'Event ignored — it will no longer appear in this list.';
	}
}

// ── Un-ignore action ─────────────────────────────────────────────────────────
if ( isset( $_POST['hostlinks_cvent_unignore'] ) ) {
	check_admin_referer( 'hostlinks_cvent_new_events' );
	$unignore_uuid = sanitize_text_field( $_POST['unignore_uuid'] ?? '' );
	if ( $unignore_uuid ) {
		$ignored = (array) get_option( 'hostlinks_cvent_ignored_events', array() );
		$ignored = array_values( array_filter( $ignored, function( $u ) use ( $unignore_uuid ) {
			return $u !== $unignore_uuid;
		} ) );
		update_option( 'hostlinks_cvent_ignored_events', $ignored );
		// Bust the transient so next display re-checks.
		delete_transient( 'hostlinks_cvent_new_events' );
		update_option( 'hostlinks_cvent_new_count', 0 );
		$action_msg = 'Event removed from the ignore list. Click "Fetch New Events" to re-evaluate it.';
	}
}

// ── Add to Hostlinks action ───────────────────────────────────────────────────
if ( isset( $_POST['hostlinks_cvent_add_event'] ) ) {
	check_admin_referer( 'hostlinks_cvent_new_events' );

	$table       = $wpdb->prefix . 'event_details_list';
	$cvent_uuid  = sanitize_text_field( $_POST['cvent_uuid'] ?? '' );
	$cvent_title = sanitize_text_field( $_POST['cvent_title'] ?? '' );
	$cvent_start_utc = sanitize_text_field( $_POST['cvent_start_utc'] ?? '' );

	$eve_location    = sanitize_text_field( $_POST['eve_location'] ?? '' );
	$eve_type        = intval( $_POST['eve_type'] ?? 0 );
	$eve_zoom        = sanitize_text_field( $_POST['eve_zoom'] ?? '' );
	$eve_marketer    = intval( $_POST['eve_marketer'] ?? 0 );
	$eve_instructor  = intval( $_POST['eve_instructor'] ?? 0 );
	$eve_host_url    = esc_url_raw( trim( $_POST['eve_host_url'] ?? '' ) );
	$eve_roster_url  = esc_url_raw( trim( $_POST['eve_roster_url'] ?? '' ) );
	$eve_trainer_url = esc_url_raw( trim( $_POST['eve_trainer_url'] ?? '' ) );
	$eve_web_url = esc_url_raw( trim( $_POST['eve_web_url'] ?? '' ) );
	$eve_start       = sanitize_text_field( $_POST['eve_start'] ?? '' );
	$eve_end         = sanitize_text_field( $_POST['eve_end'] ?? '' );
	$eve_tot_date    = str_replace( '-', '/', $eve_start ) . ' - ' . str_replace( '-', '/', $eve_end );

	if ( ! $eve_location || ! $eve_type || ! $eve_marketer || ! $eve_start || ! $eve_end ) {
		$action_msg  = 'Please fill in all required fields: Location, Type, Marketer, Start Date, End Date.';
		$action_type = 'error';
	} else {
		$wpdb->insert(
			$table,
			array(
				'eve_location'          => $eve_location,
				'eve_paid'              => 0,
				'eve_free'              => 0,
				'eve_start'             => $eve_start,
				'eve_end'               => $eve_end,
				'eve_type'              => $eve_type,
				'eve_zoom'              => $eve_zoom,
				'eve_marketer'          => $eve_marketer,
				'eve_host_url'          => $eve_host_url,
				'eve_roster_url'        => $eve_roster_url,
				'eve_trainer_url'       => $eve_trainer_url,
				'eve_web_url'       => $eve_web_url,
				'eve_instructor'        => $eve_instructor,
				'eve_tot_date'          => $eve_tot_date,
				'eve_status'            => 1,
				'cvent_event_id'        => $cvent_uuid,
				'cvent_event_title'     => $cvent_title,
				'cvent_event_start_utc' => $cvent_start_utc ? gmdate( 'Y-m-d H:i:s', strtotime( $cvent_start_utc ) ) : null,
				'cvent_match_status'    => 'manual',
				'cvent_last_synced'     => null,
				'eve_created_at'        => current_time( 'mysql' ),
			),
			array( '%s','%d','%d','%s','%s','%d','%s','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s' )
		);

		$new_eve_id = (int) $wpdb->insert_id;

		if ( $new_eve_id ) {
			do_action( 'hostlinks_event_created', $new_eve_id, $eve_start );
		}

		// Auto-populate eve_roster_url if left blank.
		if ( ! $eve_roster_url && $new_eve_id ) {
			$roster_base = Hostlinks_Page_URLs::get_roster();
			if ( $roster_base ) {
				$auto_roster_url = rtrim( $roster_base, '/' ) . '/?eve_id=' . $new_eve_id;
				$wpdb->update( $table, array( 'eve_roster_url' => $auto_roster_url ), array( 'eve_id' => $new_eve_id ), array( '%s' ), array( '%d' ) );
			}
		}

		// Remove from transient cache.
		if ( $cvent_uuid ) {
			$cached = get_transient( 'hostlinks_cvent_new_events' );
			if ( is_array( $cached ) ) {
				$cached = array_values( array_filter(
					$cached,
					function( $e ) use ( $cvent_uuid ) {
						return Hostlinks_CVENT_API::sanitize_uuid( $e['id'] ?? '' ) !== $cvent_uuid;
					}
				) );
				set_transient( 'hostlinks_cvent_new_events', $cached, HOUR_IN_SECONDS );
				update_option( 'hostlinks_cvent_new_count', count( $cached ) );
			}
		}

		$action_msg = sprintf(
			'Event added to Hostlinks! <a href="admin.php?page=booking-menu">View Events List</a>',
			$new_eve_id,
			$new_eve_id
		);
	}
}

// ── Load lookup data ──────────────────────────────────────────────────────────
$all_types       = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}event_type` WHERE event_type_status = 1", ARRAY_A );
$all_marketers   = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}event_marketer` WHERE event_marketer_status = 1", ARRAY_A );
$all_instructors = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}event_instructor` WHERE event_instructor_status = 1", ARRAY_A );
$ignored_events  = (array) get_option( 'hostlinks_cvent_ignored_events', array() );
$new_events      = get_transient( 'hostlinks_cvent_new_events' );
$last_fetch      = get_option( 'hostlinks_cvent_last_new_fetch', '' );

// Build a keyword→type_id map for auto-guessing event type from title.
$type_keyword_map = array();
foreach ( $all_types as $t ) {
	$type_keyword_map[ strtolower( trim( $t['event_type_name'] ) ) ] = (int) $t['event_type_id'];
}

/**
 * Return true if a string contains any subaward variant:
 * "subaward", "sub-award", "sub award", "subawards", "sub-awards", "sub awards".
 */
function hostlinks_is_subaward_text( $text ) {
	return (bool) preg_match( '/\bsub[-\s]?awards?\b/i', $text );
}

/**
 * Guess the most likely HL type ID from a CVENT event title.
 * Checks for keywords in priority order: writing, subaward variants, management.
 *
 * @param string $title
 * @param array  $map  keyword => type_id
 * @return int  0 if no guess
 */
function hostlinks_cvent_guess_type( $title, $map ) {
	$lc = strtolower( $title );

	// Writing check first.
	if ( false !== strpos( $lc, 'writing' ) ) {
		foreach ( $map as $tname => $tid ) {
			if ( false !== strpos( $tname, 'writing' ) ) {
				return $tid;
			}
		}
	}

	// Subaward check — catches "subaward", "sub-award", "sub award", plurals.
	if ( hostlinks_is_subaward_text( $title ) ) {
		foreach ( $map as $tname => $tid ) {
			if ( false !== strpos( $tname, 'subaward' ) ) {
				return $tid;
			}
		}
		// Subaward type not configured in HL — fall back to management.
		foreach ( $map as $tname => $tid ) {
			if ( false !== strpos( $tname, 'management' ) ) {
				return $tid;
			}
		}
	}

	// Management check.
	if ( false !== strpos( $lc, 'management' ) ) {
		foreach ( $map as $tname => $tid ) {
			if ( false !== strpos( $tname, 'management' ) ) {
				return $tid;
			}
		}
	}

	return 0;
}

/**
 * Build the Hostlinks location string for a ZOOM / webinar event from its CVENT title.
 *
 * Timezone abbreviation → Region:
 *   EST / ET  → East
 *   PST / PT  → West
 *   CST / CT  → Central
 *   MST / MT  → Mountain
 *
 * Type → suffix:
 *   Subaward variant → "Management | SUB"
 *   Writing          → "Writing"
 *   Everything else  → "Management"
 *
 * Examples: "East Writing", "West Management", "Central Management | SUB"
 */
function hostlinks_cvent_zoom_location( $title ) {
	// Longer abbreviations first so "EST" matches before "ET", etc.
	$tz_map = array(
		'EST' => 'East',
		'ET'  => 'East',
		'PST' => 'West',
		'PT'  => 'West',
		'CST' => 'Central',
		'CT'  => 'Central',
		'MST' => 'Mountain',
		'MT'  => 'Mountain',
	);
	$region = '';
	foreach ( $tz_map as $abbr => $label ) {
		if ( preg_match( '/\b' . preg_quote( $abbr, '/' ) . '\b/i', $title ) ) {
			$region = $label;
			break;
		}
	}

	// Subaward takes priority over everything else.
	if ( hostlinks_is_subaward_text( $title ) ) {
		$type_suffix = 'Management | SUB';
	} elseif ( false !== stripos( $title, 'writing' ) ) {
		$type_suffix = 'Writing';
	} else {
		$type_suffix = 'Management';
	}

	return $region ? ( $region . ' ' . $type_suffix ) : $type_suffix;
}

?>
<div class="wrap">
<h1>New CVENT Events</h1>

<?php if ( $action_msg ) : ?>
	<div class="notice notice-<?php echo $action_type === 'error' ? 'error' : 'success'; ?> is-dismissible">
		<p><?php echo wp_kses( $action_msg, array( 'a' => array( 'href' => array() ) ) ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! $ready ) : ?>
	<div class="notice notice-warning">
		<p>CVENT API is not configured. <a href="admin.php?page=cvent-settings">Configure CVENT Settings</a> first.</p>
	</div>
<?php else : ?>

<!-- Fetch button -->
<form method="post" style="margin-bottom:20px;">
	<?php wp_nonce_field( 'hostlinks_cvent_new_events' ); ?>
	<button type="submit" name="hostlinks_cvent_fetch_new" class="button button-primary">
		Fetch New Events from CVENT
	</button>
	<?php if ( $last_fetch ) : ?>
		<span style="margin-left:12px;color:#666;font-size:12px;">
			Last fetched: <?php echo esc_html( $last_fetch ); ?> UTC
			— cached for 1 hour. Click to refresh.
		</span>
	<?php elseif ( false !== $new_events ) : ?>
		<span style="margin-left:12px;color:#666;font-size:12px;">Showing cached results. Click to refresh.</span>
	<?php else : ?>
		<span style="margin-left:12px;color:#666;font-size:12px;">No results yet. Click to fetch.</span>
	<?php endif; ?>
</form>

<?php if ( false === $new_events ) : ?>
	<p style="color:#888;">Click "Fetch New Events" to check CVENT for events not yet in Hostlinks.</p>

<?php elseif ( empty( $new_events ) ) : ?>
	<div class="notice notice-success inline" style="margin:0;">
		<p>✓ No new CVENT events — all active CVENT events are already in Hostlinks (or have been ignored).</p>
	</div>

<?php else : ?>
	<p style="color:#555;font-size:13px;margin-bottom:12px;">
		<strong><?php echo count( $new_events ); ?></strong> CVENT event(s) not yet in Hostlinks.
		Click <strong>+ Add to Hostlinks</strong> to create a Hostlinks record, or <strong>Ignore</strong> to permanently dismiss.
	</p>

	<table class="widefat striped" id="cvent-new-events-table">
		<thead>
			<tr>
				<th>CVENT Title</th>
				<th style="width:90px;">Start</th>
				<th style="width:90px;">End</th>
				<th style="width:200px;">CVENT ID</th>
				<th style="width:160px;">Actions</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $new_events as $idx => $ev ) :
			$uuid       = Hostlinks_CVENT_API::sanitize_uuid( $ev['id'] ?? '' );
			$title      = $ev['title'] ?? '(no title)';
			$start_raw  = $ev['start'] ?? '';
			$end_raw    = $ev['end'] ?? '';
			$start_dt   = $start_raw ? new DateTime( $start_raw ) : null;
			$end_dt     = $end_raw   ? new DateTime( $end_raw )   : null;
			$start_disp = $start_dt  ? $start_dt->format( 'M j, Y' ) : '—';
			$end_disp   = $end_dt    ? $end_dt->format( 'M j, Y' )   : '—';
			$start_val  = $start_dt  ? $start_dt->format( 'Y-m-d' )  : '';
			$end_val    = $end_dt    ? $end_dt->format( 'Y-m-d' )    : '';

		// Pre-fill logic.
		$is_zoom     = ( false !== stripos( $title, 'zoom' ) || false !== stripos( $title, 'webinar' ) );
		$is_subaward = hostlinks_is_subaward_text( $title );

		if ( $is_zoom ) {
			// ZOOM/webinar: derive location from timezone abbreviation + type in the CVENT title.
			$prefilled_loc = hostlinks_cvent_zoom_location( $title );
			// Pre-select "Zoom" marketer and "Ericka" instructor for ZOOM events.
			$zoom_marketer_id   = 0;
			$ericka_instructor_id = 0;
			foreach ( $all_marketers as $m ) {
				if ( strcasecmp( trim( $m['event_marketer_name'] ), 'zoom' ) === 0 ) {
					$zoom_marketer_id = (int) $m['event_marketer_id'];
					break;
				}
			}
			foreach ( $all_instructors as $i ) {
				if ( stripos( trim( $i['event_instructor_name'] ), 'ericka' ) !== false ) {
					$ericka_instructor_id = (int) $i['event_instructor_id'];
					break;
				}
			}
		} else {
			// In-person: extract city/state from title as before.
			$prefilled_loc        = Hostlinks_CVENT_Matcher::title_location_from_cvent( $title );
			$zoom_marketer_id     = 0;
			$ericka_instructor_id = 0;
			if ( $is_subaward && $prefilled_loc && false === stripos( $prefilled_loc, '| SUB' ) ) {
				$prefilled_loc .= ' | SUB';
			}
		}
		$guessed_type  = hostlinks_cvent_guess_type( $title, $type_keyword_map );
		$reg_url       = $ev['registrationUrl'] ?? $ev['publicRegistrationUrl'] ?? $ev['websiteLink'] ?? '';
		$form_id       = 'cvent-add-form-' . $idx;
		?>
		<tr>
			<td><strong><?php echo esc_html( $title ); ?></strong></td>
			<td><?php echo esc_html( $start_disp ); ?></td>
			<td><?php echo esc_html( $end_disp ); ?></td>
			<td style="font-size:11px;color:#888;"><?php echo esc_html( $uuid ); ?></td>
			<td>
				<button type="button" class="button button-small button-primary"
					onclick="var r=document.getElementById('<?php echo esc_js($form_id); ?>');r.style.display=r.style.display==='none'?'table-row':'none';">
					+ Add
				</button>
				&nbsp;
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'hostlinks_cvent_new_events' ); ?>
					<input type="hidden" name="ignore_uuid" value="<?php echo esc_attr( $uuid ); ?>">
					<button type="submit" name="hostlinks_cvent_ignore" class="button button-small"
						style="color:#a00;border-color:#a00;"
						onclick="return confirm('Permanently ignore this CVENT event? It will not appear in this list again.');">
						Ignore
					</button>
				</form>
			</td>
		</tr>

		<!-- Inline add form row (hidden by default) -->
		<tr id="<?php echo esc_attr( $form_id ); ?>" style="display:none;background:#f0f6fc;">
			<td colspan="5" style="padding:16px 20px;">
				<form method="post">
					<?php wp_nonce_field( 'hostlinks_cvent_new_events' ); ?>
					<input type="hidden" name="cvent_uuid"      value="<?php echo esc_attr( $uuid ); ?>">
					<input type="hidden" name="cvent_title"     value="<?php echo esc_attr( $title ); ?>">
					<input type="hidden" name="cvent_start_utc" value="<?php echo esc_attr( $start_raw ); ?>">

					<h3 style="margin:0 0 12px;font-size:14px;color:#1d2327;">
						Add: <em><?php echo esc_html( $title ); ?></em>
					</h3>
					<p style="color:#2271b1;font-size:12px;margin:0 0 12px;">
						Fields marked <span style="color:red;">*</span> are required.
						Pre-filled fields are guessed from CVENT data — review before saving.
					</p>

					<table class="form-table" style="width:auto;margin:0;">
						<tr>
							<th style="width:130px;padding:6px 10px;font-weight:600;">
								<label>Location <span style="color:red;">*</span></label>
							</th>
							<td style="padding:6px 10px;">
								<input type="text" name="eve_location"
									value="<?php echo esc_attr( $prefilled_loc ); ?>"
									style="width:280px;" required
									placeholder="City, ST">
								<?php if ( $prefilled_loc ) : ?>
									<span style="color:#2271b1;font-size:11px;margin-left:8px;">
										✓ pre-filled from title
									</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;">
								<label>Start Date <span style="color:red;">*</span></label>
							</th>
							<td style="padding:6px 10px;">
								<input type="date" name="eve_start"
									value="<?php echo esc_attr( $start_val ); ?>" required>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;">
								<label>End Date <span style="color:red;">*</span></label>
							</th>
							<td style="padding:6px 10px;">
								<input type="date" name="eve_end"
									value="<?php echo esc_attr( $end_val ); ?>" required>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;">
								<label>Type <span style="color:red;">*</span></label>
							</th>
							<td style="padding:6px 10px;">
								<select name="eve_type" required>
									<option value="">— select type —</option>
									<?php foreach ( $all_types as $t ) : ?>
										<option value="<?php echo esc_attr( $t['event_type_id'] ); ?>"
											<?php selected( $guessed_type, (int) $t['event_type_id'] ); ?>>
											<?php echo esc_html( $t['event_type_name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( $guessed_type ) : ?>
									<span style="color:#2271b1;font-size:11px;margin-left:8px;">
										✓ guessed from title
									</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;"><label>Zoom</label></th>
							<td style="padding:6px 10px;">
								<select name="eve_zoom">
									<option value="">No</option>
									<option value="yes" <?php selected( $is_zoom ); ?>>Yes</option>
								</select>
								<?php if ( $is_zoom ) : ?>
									<span style="color:#2271b1;font-size:11px;margin-left:8px;">
										✓ detected from title
									</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;">
								<label>Marketer <span style="color:red;">*</span></label>
							</th>
							<td style="padding:6px 10px;">
								<select name="eve_marketer" required>
									<option value="">— select marketer —</option>
									<?php foreach ( $all_marketers as $m ) : ?>
										<option value="<?php echo esc_attr( $m['event_marketer_id'] ); ?>"
											<?php selected( $zoom_marketer_id, (int) $m['event_marketer_id'] ); ?>>
											<?php echo esc_html( $m['event_marketer_name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( $zoom_marketer_id ) : ?>
									<span style="color:#2271b1;font-size:11px;margin-left:8px;">✓ pre-filled for Zoom</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;"><label>Instructor</label></th>
							<td style="padding:6px 10px;">
								<select name="eve_instructor">
									<option value="0">— none / TBA —</option>
									<?php foreach ( $all_instructors as $i ) : ?>
										<option value="<?php echo esc_attr( $i['event_instructor_id'] ); ?>"
											<?php selected( $ericka_instructor_id, (int) $i['event_instructor_id'] ); ?>>
											<?php echo esc_html( $i['event_instructor_name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( $ericka_instructor_id ) : ?>
									<span style="color:#2271b1;font-size:11px;margin-left:8px;">✓ pre-filled for Zoom</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;"><label>Host URL</label></th>
							<td style="padding:6px 10px;">
								<input type="url" name="eve_host_url" value=""
									style="width:360px;" placeholder="https://">
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;"><label>Roster URL</label></th>
							<td style="padding:6px 10px;">
								<input type="url" name="eve_roster_url" value=""
								style="width:360px;" placeholder="Leave blank to auto-populate">
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;"><label>Reg URL</label></th>
							<td style="padding:6px 10px;">
								<input type="url" name="eve_trainer_url" value="<?php echo esc_attr( $reg_url ); ?>"
									style="width:360px;" placeholder="https://">
								<?php if ( $reg_url ) : ?>
								<span style="color:#2271b1;font-size:11px;margin-left:8px;">✓ pre-filled from CVENT</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:6px 10px;font-weight:600;"><label>Web URL</label></th>
							<td style="padding:6px 10px;">
								<input type="url" name="eve_web_url" value=""
									style="width:360px;" placeholder="https://">
							</td>
						</tr>
					</table>

					<p style="margin:14px 0 0;">
						<button type="submit" name="hostlinks_cvent_add_event" class="button button-primary">
							Save to Hostlinks
						</button>
						&nbsp;
						<button type="button" class="button"
							onclick="document.getElementById('<?php echo esc_js($form_id); ?>').style.display='none';">
							Cancel
						</button>
					</p>
				</form>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; // new_events not empty ?>

<?php endif; // ready ?>

<?php if ( ! empty( $ignored_events ) ) : ?>
<hr style="margin:32px 0 24px;">
<h3>Ignored CVENT Events
	<span style="font-size:13px;font-weight:400;color:#666;">
		(<?php echo count( $ignored_events ); ?>) — these will never appear in the new events list
	</span>
</h3>
<p style="color:#666;font-size:12px;margin-bottom:8px;">
	Remove a UUID from this list to allow it to be re-evaluated on the next fetch.
</p>
<table class="widefat" style="max-width:660px;">
	<thead>
		<tr><th>CVENT UUID</th><th style="width:100px;">Action</th></tr>
	</thead>
	<tbody>
	<?php foreach ( $ignored_events as $ig_uuid ) : ?>
	<tr>
		<td style="font-family:monospace;font-size:12px;"><?php echo esc_html( $ig_uuid ); ?></td>
		<td>
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( 'hostlinks_cvent_new_events' ); ?>
				<input type="hidden" name="unignore_uuid" value="<?php echo esc_attr( $ig_uuid ); ?>">
				<button type="submit" name="hostlinks_cvent_unignore" class="button button-small">
					Remove
				</button>
			</form>
		</td>
	</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

</div>
