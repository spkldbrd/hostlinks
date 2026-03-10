<?php
/**
 * Settings → Public Event List tab.
 * Included from admin/settings.php with $hl_embedded = true.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

// ── Save ─────────────────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hostlinks_save_pel_settings'] ) ) {
	check_admin_referer( 'hostlinks_pel_settings' );

	$fields = array(
		'hostlinks_pel_left_heading'       => 'sanitize_text_field',
		'hostlinks_pel_left_heading_tag'   => 'sanitize_key',
		'hostlinks_pel_left_desc'          => 'wp_kses_post',
		'hostlinks_pel_left_desc_tag'      => 'sanitize_key',
		'hostlinks_pel_right_heading'      => 'sanitize_text_field',
		'hostlinks_pel_right_heading_tag'  => 'sanitize_key',
		'hostlinks_pel_right_desc'         => 'wp_kses_post',
		'hostlinks_pel_right_desc_tag'     => 'sanitize_key',
		'hostlinks_pel_zoom_time_east'     => 'sanitize_text_field',
		'hostlinks_pel_zoom_time_west'     => 'sanitize_text_field',
		'hostlinks_pel_zoom_time_default'  => 'sanitize_text_field',
	);
	foreach ( $fields as $key => $cb ) {
		update_option( $key, call_user_func( $cb, $_POST[ $key ] ?? '' ) );
	}
	// Array options — type checkboxes
	$left_types  = array_map( 'intval', (array) ( $_POST['hostlinks_pel_left_types']  ?? array() ) );
	$right_types = array_map( 'intval', (array) ( $_POST['hostlinks_pel_right_types'] ?? array() ) );
	$sub_types   = array_map( 'intval', (array) ( $_POST['hostlinks_pel_subaward_types'] ?? array() ) );
	update_option( 'hostlinks_pel_left_types',      $left_types );
	update_option( 'hostlinks_pel_right_types',     $right_types );
	update_option( 'hostlinks_pel_subaward_types',  $sub_types );

	$notice = '<div class="notice notice-success is-dismissible"><p>Public Event List settings saved.</p></div>';
}

// ── Load options ──────────────────────────────────────────────────────────────
$tag_options = array( 'h2', 'h3', 'h4', 'h5', 'p' );

$left_heading      = get_option( 'hostlinks_pel_left_heading',      'Grant Writing Workshops' );
$left_heading_tag  = get_option( 'hostlinks_pel_left_heading_tag',  'h2' );
$left_desc         = get_option( 'hostlinks_pel_left_desc',         'Learn how to find funding sources and what it takes to write winning grant proposals.' );
$left_desc_tag     = get_option( 'hostlinks_pel_left_desc_tag',     'p' );
$left_types        = (array) get_option( 'hostlinks_pel_left_types',  array() );

$right_heading     = get_option( 'hostlinks_pel_right_heading',     'Grant Management Workshops' );
$right_heading_tag = get_option( 'hostlinks_pel_right_heading_tag', 'h2' );
$right_desc        = get_option( 'hostlinks_pel_right_desc',        'Learn how to administer government grants and stay in compliance with applicable rules and regulations.' );
$right_desc_tag    = get_option( 'hostlinks_pel_right_desc_tag',    'p' );
$right_types       = (array) get_option( 'hostlinks_pel_right_types', array() );

$sub_types         = (array) get_option( 'hostlinks_pel_subaward_types', array() );

$zoom_east         = get_option( 'hostlinks_pel_zoom_time_east',    '9:30-4:30 EST' );
$zoom_west         = get_option( 'hostlinks_pel_zoom_time_west',    '8:00-3:00 PST' );
$zoom_default      = get_option( 'hostlinks_pel_zoom_time_default', '9:30-4:30 EST' );

// All active event types for checkboxes
global $wpdb;
$all_types = $wpdb->get_results( "SELECT event_type_id, event_type_name FROM {$wpdb->prefix}event_type WHERE event_type_status = 1 ORDER BY event_type_name", ARRAY_A );

// Helper: render a tag select
function pel_tag_select( $name, $current, $options ) {
	echo '<select name="' . esc_attr( $name ) . '">';
	foreach ( $options as $opt ) {
		$label = strtoupper( $opt );
		echo '<option value="' . esc_attr( $opt ) . '"' . selected( $current, $opt, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
}

// Helper: render type checkboxes
function pel_type_checkboxes( $name, $selected, $types ) {
	foreach ( $types as $t ) {
		$id  = intval( $t['event_type_id'] );
		$lbl = esc_html( $t['event_type_name'] );
		$chk = in_array( $id, array_map( 'intval', $selected ) ) ? 'checked' : '';
		echo "<label style='margin-right:16px;'><input type='checkbox' name='{$name}[]' value='{$id}' {$chk}> {$lbl}</label>";
	}
}
?>
<?php if ( empty( $hl_embedded ) ) : ?><div class="wrap"><h1>Hostlinks — Public Event List Settings</h1><?php endif; ?>

<?php echo $notice; ?>

<p style="color:#555;max-width:780px;">
	Configure the <code>[public_event_list]</code> shortcode. This shortcode renders a two-column list of upcoming in-person and ZOOM workshops for public visitors — no login required.
</p>

<form method="post">
<?php wp_nonce_field( 'hostlinks_pel_settings' ); ?>

<!-- ── Left Column ─────────────────────────────────────────────────── -->
<h2 style="margin-top:1.5rem;">Left Column — Grant Writing</h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label>Heading Text</label></th>
		<td><input type="text" name="hostlinks_pel_left_heading" value="<?php echo esc_attr( $left_heading ); ?>" class="regular-text"></td>
	</tr>
	<tr>
		<th scope="row"><label>Heading Tag</label></th>
		<td><?php pel_tag_select( 'hostlinks_pel_left_heading_tag', $left_heading_tag, $tag_options ); ?></td>
	</tr>
	<tr>
		<th scope="row"><label>Description Text</label></th>
		<td><textarea name="hostlinks_pel_left_desc" rows="3" class="large-text"><?php echo esc_textarea( $left_desc ); ?></textarea></td>
	</tr>
	<tr>
		<th scope="row"><label>Description Tag</label></th>
		<td><?php pel_tag_select( 'hostlinks_pel_left_desc_tag', $left_desc_tag, $tag_options ); ?></td>
	</tr>
	<tr>
		<th scope="row"><label>Event Types</label></th>
		<td>
			<?php pel_type_checkboxes( 'hostlinks_pel_left_types', $left_types, $all_types ); ?>
			<p class="description">Events whose type matches one of these will appear in the left column.</p>
		</td>
	</tr>
</table>

<!-- ── Right Column ───────────────────────────────────────────────── -->
<h2 style="margin-top:1.5rem;">Right Column — Grant Management</h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label>Heading Text</label></th>
		<td><input type="text" name="hostlinks_pel_right_heading" value="<?php echo esc_attr( $right_heading ); ?>" class="regular-text"></td>
	</tr>
	<tr>
		<th scope="row"><label>Heading Tag</label></th>
		<td><?php pel_tag_select( 'hostlinks_pel_right_heading_tag', $right_heading_tag, $tag_options ); ?></td>
	</tr>
	<tr>
		<th scope="row"><label>Description Text</label></th>
		<td><textarea name="hostlinks_pel_right_desc" rows="3" class="large-text"><?php echo esc_textarea( $right_desc ); ?></textarea></td>
	</tr>
	<tr>
		<th scope="row"><label>Description Tag</label></th>
		<td><?php pel_tag_select( 'hostlinks_pel_right_desc_tag', $right_desc_tag, $tag_options ); ?></td>
	</tr>
	<tr>
		<th scope="row"><label>Event Types</label></th>
		<td>
			<?php pel_type_checkboxes( 'hostlinks_pel_right_types', $right_types, $all_types ); ?>
			<p class="description">Events whose type matches one of these will appear in the right column.</p>
		</td>
	</tr>
</table>

<!-- ── Subaward ───────────────────────────────────────────────────── -->
<h2 style="margin-top:1.5rem;">Subaward Events</h2>
<p style="color:#555;max-width:700px;">
	When an event's type matches a Subaward type, its public title will be prefixed with <strong>"Managing Subawards"</strong>.
	Example: <em>Managing Subawards Montgomery, AL</em> or <em>Managing Subawards ZOOM WEBINAR</em>.
</p>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label>Subaward Types</label></th>
		<td>
			<?php pel_type_checkboxes( 'hostlinks_pel_subaward_types', $sub_types, $all_types ); ?>
			<p class="description">These types get the "Managing Subawards" prefix on the public list.</p>
		</td>
	</tr>
</table>

<!-- ── ZOOM Time ──────────────────────────────────────────────────── -->
<h2 style="margin-top:1.5rem;">ZOOM Event Times</h2>
<p style="color:#555;max-width:700px;">
	ZOOM events are identified by the <strong>ZOOM</strong> flag on the event. The time displayed is determined by detecting <code>EST</code> / <code>East</code> or <code>PST</code> / <code>West</code> in the event location or CVENT title.
	If neither is found, the default time is used. Individual events can override this by setting the <strong>ZOOM Time</strong> field directly on the event.
</p>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label>East / EST Time</label></th>
		<td>
			<input type="text" name="hostlinks_pel_zoom_time_east" value="<?php echo esc_attr( $zoom_east ); ?>" style="width:180px;" placeholder="9:30-4:30 EST">
			<p class="description">Used when location or CVENT title contains <code>EST</code> or <code>East</code>.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label>West / PST Time</label></th>
		<td>
			<input type="text" name="hostlinks_pel_zoom_time_west" value="<?php echo esc_attr( $zoom_west ); ?>" style="width:180px;" placeholder="8:00-3:00 PST">
			<p class="description">Used when location or CVENT title contains <code>PST</code> or <code>West</code>.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label>Default ZOOM Time</label></th>
		<td>
			<input type="text" name="hostlinks_pel_zoom_time_default" value="<?php echo esc_attr( $zoom_default ); ?>" style="width:180px;" placeholder="9:30-4:30 EST">
			<p class="description">Fallback when neither East nor West can be detected.</p>
		</td>
	</tr>
</table>

<!-- ── How Titles Are Built ───────────────────────────────────────── -->
<h2 style="margin-top:1.5rem;">How Public Titles Are Built</h2>
<table class="widefat striped" style="max-width:800px;margin-bottom:16px;">
	<thead><tr><th>Event type</th><th>Example public title</th></tr></thead>
	<tbody>
		<tr><td>In-person writing or management</td><td><strong>Buffalo, NY</strong> March 19-20, 2026</td></tr>
		<tr><td>In-person subaward</td><td><strong>Managing Subawards Montgomery, AL</strong> May 1, 2026</td></tr>
		<tr><td>ZOOM writing or management</td><td><strong>ZOOM WEBINAR</strong> March 12-13, 2026 | 9:30-4:30 EST</td></tr>
		<tr><td>ZOOM subaward</td><td><strong>Managing Subawards ZOOM WEBINAR</strong> March 17, 2026 | 9:30-4:30 EST</td></tr>
	</tbody>
</table>
<p style="color:#555;max-width:700px;">
	<strong>Location parsing:</strong> For in-person events, the City and State are extracted from the start of the <em>Location</em> field using the pattern <code>City, ST</code>.
	For example, <code>Everett, WA - Grant Writing USA</code> becomes <code>Everett, WA</code>.
	ZOOM events use <code>eve_zoom = yes</code> and display "ZOOM WEBINAR" instead of a city.
</p>

<p class="submit" style="margin-top:1.5rem;">
	<button type="submit" name="hostlinks_save_pel_settings" class="button button-primary">Save Settings</button>
</p>
</form>

<?php if ( empty( $hl_embedded ) ) : ?></div><?php endif; ?>
