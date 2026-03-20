<?php
/**
 * Settings → Roster tab.
 *
 * Stores branding options used by the front-end and admin roster reports.
 * Included from admin/settings.php with $hl_embedded = true.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$notice = '';
if ( isset( $_POST['hostlinks_save_roster_settings'] ) ) {
	check_admin_referer( 'hostlinks_roster_settings' );
	$logo_url = esc_url_raw( trim( wp_unslash( $_POST['hostlinks_roster_logo_url'] ?? '' ) ) );
	update_option( 'hostlinks_roster_logo_url', $logo_url );
	$notice = '<div class="notice notice-success is-dismissible"><p>Roster settings saved.</p></div>';
}

$logo_url = get_option( 'hostlinks_roster_logo_url', '' );
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Roster Branding</h2>
<p>Settings that apply to the printed roster report — both the front-end <code>[hostlinks_roster]</code> page and the admin roster page.</p>

<form method="post">
	<?php wp_nonce_field( 'hostlinks_roster_settings' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hostlinks_roster_logo_url">Company Logo URL</label></th>
			<td>
				<div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
					<input type="url" id="hostlinks_roster_logo_url" name="hostlinks_roster_logo_url"
						value="<?php echo esc_attr( $logo_url ); ?>"
						class="regular-text" placeholder="https://" />
					<button type="button" id="hl-roster-logo-picker" class="button">
						&#x1F5BC; Choose from Media Library
					</button>
				</div>
				<p class="description" style="margin-top:6px;">
					Logo appears at the top-right corner when printing the roster. Recommended: PNG with transparent background, max height 80 px.
				</p>
				<?php if ( $logo_url ) : ?>
				<div style="margin-top:10px;padding:10px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;display:inline-block;">
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo preview"
						style="max-height:80px;max-width:320px;display:block;" />
					<p style="margin:6px 0 0;font-size:11px;color:#666;">Current logo</p>
				</div>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hostlinks_save_roster_settings" class="button button-primary">Save Roster Settings</button>
	</p>
</form>

<script>
(function () {
	var btn   = document.getElementById( 'hl-roster-logo-picker' );
	var input = document.getElementById( 'hostlinks_roster_logo_url' );
	if ( ! btn || ! input ) return;

	var frame;
	btn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		// Check wp.media here (inside click handler) — media scripts load in
		// the footer, after this inline script runs, so the check must be deferred.
		if ( typeof wp === 'undefined' || ! wp.media ) {
			alert( 'Media library unavailable. Please paste the image URL directly into the field.' );
			return;
		}
		if ( frame ) { frame.open(); return; }
		frame = wp.media( {
			title:    'Select Roster Logo',
			button:   { text: 'Use this image' },
			multiple: false,
			library:  { type: 'image' },
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			input.value = att.url;
		} );
		frame.open();
	} );
})();
</script>
