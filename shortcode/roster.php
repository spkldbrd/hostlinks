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
<div id="hl-roster-shell">
<div id="hl-roster-admin-bar" style="display:none;margin-bottom:12px;">
	<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
		<div id="hl-roster-view-presets" style="display:none;align-items:center;gap:8px;font-size:13px;color:#555;flex-wrap:wrap;">
			<span>View:</span>
			<button type="button" id="hl-roster-view-signin" class="hl-roster-admin-btn hl-roster-admin-btn--primary">Sign-in sheet</button>
			<button type="button" id="hl-roster-view-details" class="hl-roster-admin-btn">Registrant details</button>
		</div>
		<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
			<button id="hl-roster-print-btn" class="hl-roster-admin-btn hl-roster-admin-btn--primary">&#x1F5A8; Print</button>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<button id="hl-roster-refresh-btn" class="hl-roster-admin-btn">&#x21BB; Refresh Roster</button>
			<?php endif; ?>
		</div>
	</div>
	<?php require HOSTLINKS_PLUGIN_DIR . 'shortcode/roster-toggles.php'; ?>
</div>

<div id="hl-roster-loader">
	<div class="hl-roster-spinner"></div>
	<p>Updating the roster, this can take a moment. Please wait&hellip;</p>
</div>

<div id="hl-roster-frame-wrap">
	<iframe id="hl-roster-frame" title="Event roster" scrolling="auto"></iframe>
</div>
</div>

<style>
#hl-roster-shell {
	width: 100%;
	max-width: 100%;
	margin: 0 auto;
	overflow-x: hidden;
	box-sizing: border-box;
}
#hl-roster-frame-wrap {
	width: 100%;
	overflow: hidden;
}
#hl-roster-frame {
	display: block;
	width: 100%;
	border: 0;
	height: 820px;
	background: #fff;
}
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
.hl-roster-admin-btn {
	padding: 5px 14px; background: #f0f0f0; color: #333;
	border: 1px solid #ccc; border-radius: 3px; font-size: 13px;
	cursor: pointer; line-height: 1.5; margin-left: 6px;
}
.hl-roster-admin-btn:hover { background: #e0e0e0; }
.hl-roster-admin-btn--primary { background: #0da2e7; color: #fff; border-color: #0b8fcf; }
.hl-roster-admin-btn--primary:hover { background: #0b8fcf; color: #fff; }
@media print {
	#hl-roster-admin-bar, #hl-roster-frame-wrap { display: none !important; }
}
</style>

<script>
(function () {
	var ajaxUrl  = <?php echo wp_json_encode( esc_url_raw( $_sh_ajax_url ) ); ?>;
	var eveId    = <?php echo (int) $eve_id; ?>;
	var nonce    = <?php echo wp_json_encode( $_sh_nonce ); ?>;
	var refresh  = <?php echo $_sh_do_refresh ? 'true' : 'false'; ?>;
	var detailsCols = <?php echo wp_json_encode( Hostlinks_Roster::DETAILS_PRESET ); ?>;
	var prefix = 'hl-fe';

	function rosterFrame() {
		return document.getElementById( 'hl-roster-frame' );
	}

	function rosterDoc() {
		var frame = rosterFrame();
		if ( frame && frame.contentDocument ) {
			return frame.contentDocument;
		}
		return document;
	}

	function writeRosterFrame( html ) {
		var frame = rosterFrame();
		if ( ! frame ) return;
		var doc = frame.contentDocument || frame.contentWindow.document;
		doc.open();
		doc.write(
			'<!DOCTYPE html><html><head><meta charset="utf-8">' +
			'<meta name="viewport" content="width=device-width,initial-scale=1">' +
			'<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#fff;}</style>' +
			'</head><body>' + html + '</body></html>'
		);
		doc.close();
	}

	function colClass( slug ) {
		return prefix + '-col-' + slug.replace( /_/g, '-' );
	}

	function toggleCol( slug, show ) {
		var cls = colClass( slug );
		var els = rosterDoc().querySelectorAll( '.' + cls );
		for ( var i = 0; i < els.length; i++ ) {
			els[i].style.display = show ? 'table-cell' : 'none';
			els[i].classList[ show ? 'add' : 'remove' ]( prefix + '-col-visible' );
		}
		updateTotalsVisibility();
	}

	function updateTotalsVisibility() {
		var totals = rosterDoc().querySelector( '.hl-fe-roster-totals' );
		if ( ! totals ) return;
		var amountSlugs = [ 'amount_ordered', 'amount_paid', 'discounts_applied', 'balance_due' ];
		var any = false;
		for ( var i = 0; i < amountSlugs.length; i++ ) {
			var card = totals.querySelector( '.' + colClass( amountSlugs[i] ) );
			var chk  = document.querySelector( '[data-col="' + amountSlugs[i] + '"]' );
			var on   = chk && chk.checked;
			if ( card ) {
				card.style.display = on ? 'block' : 'none';
				card.classList[ on ? 'add' : 'remove' ]( prefix + '-col-visible' );
			}
			if ( on ) any = true;
		}
		totals.style.display = any ? 'flex' : 'none';
	}

	function setPreset( cols ) {
		var checks = document.querySelectorAll( '#hl-roster-col-toggles [data-col]' );
		for ( var i = 0; i < checks.length; i++ ) {
			var slug = checks[i].getAttribute( 'data-col' );
			var show = cols.indexOf( slug ) !== -1;
			checks[i].checked = show;
			toggleCol( slug, show );
		}
	}

	function initRosterToggles() {
		var doc = rosterDoc();
		var viewPresets = document.getElementById( 'hl-roster-view-presets' );
		var colToggles  = document.getElementById( 'hl-roster-col-toggles' );
		if ( colToggles && doc.querySelector( '.hl-fe-roster-table' ) ) {
			if ( viewPresets ) viewPresets.style.display = 'flex';
			colToggles.style.display = 'flex';
		}

		var partWrap = document.getElementById( 'hl-fe-participant-wrap' );
		if ( partWrap && doc.querySelector( '.hl-fe-col-participant' ) ) {
			partWrap.style.display = 'flex';
		}

		var checks = document.querySelectorAll( '#hl-roster-col-toggles [data-col]' );
		for ( var i = 0; i < checks.length; i++ ) {
			checks[i].checked = false;
		}
	}

	function bindRosterControlsOnce() {
		var colToggles = document.getElementById( 'hl-roster-col-toggles' );
		if ( colToggles && ! colToggles.dataset.hlBound ) {
			colToggles.dataset.hlBound = '1';
			colToggles.addEventListener( 'change', function ( e ) {
				var t = e.target;
				if ( t && t.getAttribute( 'data-col' ) ) {
					toggleCol( t.getAttribute( 'data-col' ), t.checked );
				}
			} );
		}

		var signinBtn = document.getElementById( 'hl-roster-view-signin' );
		var detailsBtn = document.getElementById( 'hl-roster-view-details' );
		if ( signinBtn && ! signinBtn.dataset.hlBound ) {
			signinBtn.dataset.hlBound = '1';
			signinBtn.addEventListener( 'click', function () {
				setPreset( [] );
				signinBtn.classList.add( 'hl-roster-admin-btn--primary' );
				if ( detailsBtn ) detailsBtn.classList.remove( 'hl-roster-admin-btn--primary' );
			} );
		}
		if ( detailsBtn && ! detailsBtn.dataset.hlBound ) {
			detailsBtn.dataset.hlBound = '1';
			detailsBtn.addEventListener( 'click', function () {
				setPreset( detailsCols );
				detailsBtn.classList.add( 'hl-roster-admin-btn--primary' );
				if ( signinBtn ) signinBtn.classList.remove( 'hl-roster-admin-btn--primary' );
			} );
		}
	}

	function buildUrl( withRefresh ) {
		var u = ajaxUrl + '?action=hostlinks_get_roster&eve_id=' + eveId + '&_nonce=' + encodeURIComponent( nonce );
		if ( withRefresh ) u += '&refresh=1';
		return u;
	}

	function loadRoster( withRefresh ) {
		var loader   = document.getElementById( 'hl-roster-loader' );
		var adminBar = document.getElementById( 'hl-roster-admin-bar' );
		var frame    = rosterFrame();
			if ( loader )   { loader.style.display = 'block'; loader.style.animation = 'none'; loader.style.opacity = '0'; setTimeout(function(){ loader.style.animation = 'hl-loader-fadein 0.4s ease 0.6s forwards'; }, 10); }
			if ( adminBar ) adminBar.style.display = 'none';
			var viewPresets = document.getElementById( 'hl-roster-view-presets' );
			if ( viewPresets ) viewPresets.style.display = 'none';
			var colToggles = document.getElementById( 'hl-roster-col-toggles' );
			if ( colToggles ) colToggles.style.display = 'none';
			if ( frame ) {
				writeRosterFrame( '' );
			}

		fetch( buildUrl( withRefresh ) )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( loader )   loader.style.display = 'none';
				if ( adminBar ) adminBar.style.display = 'block';
				if ( data.success ) {
					writeRosterFrame( data.data.html );
					initRosterToggles();
				} else {
					writeRosterFrame(
						'<p style="color:#d63638;padding:20px 0;">' +
						( data.data || 'Could not load roster. Please try again.' ) + '</p>'
					);
				}
			} )
			.catch( function () {
				if ( loader ) loader.innerHTML = '<p style="color:#d63638;">Could not load roster. Please try again.</p>';
			} );
	}

	// Initial load.
	bindRosterControlsOnce();
	loadRoster( refresh );

	var printBtn = document.getElementById( 'hl-roster-print-btn' );
	if ( printBtn ) {
		printBtn.addEventListener( 'click', function () {
			var frame = rosterFrame();
			if ( frame && frame.contentWindow ) {
				frame.contentWindow.focus();
				frame.contentWindow.print();
			}
		} );
	}

	// Refresh button (admin only — button may not exist for non-admins).
	var refreshBtn = document.getElementById( 'hl-roster-refresh-btn' );
	if ( refreshBtn ) {
		refreshBtn.addEventListener( 'click', function () { loadRoster( true ); } );
	}
})();
</script>
