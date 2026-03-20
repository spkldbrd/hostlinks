<?php
/**
 * [hostlinks_roster] shortcode shell.
 *
 * Renders an immediate loading screen, then fetches the roster HTML
 * via AJAX (wp_ajax_hostlinks_get_roster) so the loader is visible
 * while the CVENT API call is in progress.
 *
 * When the result is already cached the AJAX round-trip is fast (~200 ms)
 * and the loader fades in only after a 600 ms CSS delay, so it never
 * visually appears on cached loads.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$eve_id = isset( $_GET['eve_id'] ) ? (int) $_GET['eve_id'] : 0;

if ( ! $eve_id ) {
	echo '<div class="hostlinks-access-denied"><p>No event specified.</p></div>';
	return;
}

// Quick validation — two fast DB queries, no API calls.
$table11 = $wpdb->prefix . 'event_details_list';
$_sh_row = $wpdb->get_row(
	$wpdb->prepare( "SELECT eve_id, cvent_event_id FROM {$table11} WHERE eve_id = %d AND eve_status = '1' LIMIT 1", $eve_id ),
	ARRAY_A
);

if ( ! $_sh_row ) {
	echo '<div class="hostlinks-access-denied"><p>Event not found.</p></div>';
	return;
}

$_sh_cvent_id = Hostlinks_CVENT_API::sanitize_uuid( $_sh_row['cvent_event_id'] ?? '' );
if ( ! $_sh_cvent_id ) {
	echo '<div class="hostlinks-access-denied"><p>This event does not have a linked registration system ID yet.</p></div>';
	return;
}

$_sh_do_refresh = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );
$_sh_nonce      = wp_create_nonce( 'hostlinks_roster_fetch' );
$_sh_ajax_url   = admin_url( 'admin-ajax.php' );
?>
<div id="hl-roster-loader">
	<div class="hl-roster-spinner"></div>
	<p>Updating the roster, this can take a moment. Please wait&hellip;</p>
</div>

<div id="hl-roster-output"></div>

<style>
#hl-roster-loader {
	text-align: center;
	padding: 60px 20px;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	/* Fade in after 600 ms — cached loads return before this and the loader
	   never becomes visible. */
	opacity: 0;
	animation: hl-loader-fadein 0.4s ease 0.6s forwards;
}
@keyframes hl-loader-fadein { to { opacity: 1; } }
.hl-roster-spinner {
	width: 44px;
	height: 44px;
	border: 4px solid #e0e0e0;
	border-top-color: #0da2e7;
	border-radius: 50%;
	animation: hl-spin 0.9s linear infinite;
	margin: 0 auto 18px;
}
@keyframes hl-spin { to { transform: rotate(360deg); } }
#hl-roster-loader p { font-size: 15px; color: #555; margin: 0; }
</style>

<script>
(function () {
	var ajaxUrl  = <?php echo wp_json_encode( esc_url_raw( $_sh_ajax_url ) ); ?>;
	var eveId    = <?php echo (int) $eve_id; ?>;
	var nonce    = <?php echo wp_json_encode( $_sh_nonce ); ?>;
	var refresh  = <?php echo $_sh_do_refresh ? 'true' : 'false'; ?>;

	var url = ajaxUrl + '?action=hostlinks_get_roster&eve_id=' + eveId + '&_nonce=' + encodeURIComponent( nonce );
	if ( refresh ) url += '&refresh=1';

	fetch( url )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			var loader = document.getElementById( 'hl-roster-loader' );
			var output = document.getElementById( 'hl-roster-output' );
			if ( loader ) loader.style.display = 'none';
			if ( output ) {
				if ( data.success ) {
					output.innerHTML = data.data.html;
					// Wire up the email/phone column toggles injected with the HTML.
					(function () {
						function tog( cls, show ) {
							var els = output.querySelectorAll( '.' + cls );
							for ( var i = 0; i < els.length; i++ ) {
								els[i].style.display = show ? 'table-cell' : 'none';
								els[i].classList[ show ? 'add' : 'remove' ]( 'hl-fe-col-visible' );
							}
						}
						var ec = output.querySelector( '#hl-fe-email' );
						var pc = output.querySelector( '#hl-fe-phone' );
						if ( ec ) ec.addEventListener( 'change', function () { tog( 'hl-fe-col-email', this.checked ); } );
						if ( pc ) pc.addEventListener( 'change', function () { tog( 'hl-fe-col-phone', this.checked ); } );
					})();
				} else {
					output.innerHTML = '<p style="color:#d63638;padding:20px 0;">' +
						( data.data || 'Could not load roster. Please try again.' ) + '</p>';
				}
			}
		} )
		.catch( function () {
			var loader = document.getElementById( 'hl-roster-loader' );
			if ( loader ) loader.innerHTML = '<p style="color:#d63638;">Could not load roster. Please try again.</p>';
		} );
})();
</script>
