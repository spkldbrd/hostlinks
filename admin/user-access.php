<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

// ── Handle form save ──────────────────────────────────────────────────────────
$notice = '';
if ( isset( $_POST['hostlinks_save_user_access'] ) ) {
	check_admin_referer( 'hostlinks_user_access' );

	// A. Per-shortcode access modes.
	$raw_modes = isset( $_POST['hl_access_mode'] ) && is_array( $_POST['hl_access_mode'] )
		? $_POST['hl_access_mode']
		: array();
	Hostlinks_Access::save_access_modes( $raw_modes );

	// B. Approved viewers — sent as a hidden comma-separated list of IDs.
	$raw_ids = sanitize_text_field( $_POST['hl_approved_viewer_ids'] ?? '' );
	$ids     = $raw_ids !== '' ? explode( ',', $raw_ids ) : array();
	Hostlinks_Access::save_approved_viewers( $ids );

	// C. Denial message.
	$msg = sanitize_textarea_field( $_POST['hl_denial_message'] ?? '' );
	update_option( Hostlinks_Access::OPT_MESSAGE, $msg );

	$notice = '<div class="notice notice-success is-dismissible"><p>User access settings saved.</p></div>';
}

// ── Current state ─────────────────────────────────────────────────────────────
$saved_modes    = get_option( Hostlinks_Access::OPT_MODES, array() );
$approved_ids   = Hostlinks_Access::get_approved_viewers();
$denial_message = Hostlinks_Access::get_denial_message();

// Fetch full user objects for the saved approved viewers list.
$approved_users = array();
if ( ! empty( $approved_ids ) ) {
	$approved_users = get_users( array(
		'include' => $approved_ids,
		'fields'  => array( 'ID', 'display_name', 'user_email' ),
		'orderby' => 'display_name',
	) );
}

$mode_labels = array(
	'public'           => 'Public — anyone',
	'logged_in'        => 'Logged-in Users',
	'approved_viewers' => 'Approved Viewers Only',
);

$ajax_url = admin_url( 'admin-ajax.php' );
$nonce    = wp_create_nonce( 'hostlinks_user_access' );
?>
<?php if ( empty( $hl_embedded ) ) : ?>
<div class="wrap">
<h1>Hostlinks — User Access</h1>
<?php endif; ?>
<?php echo $notice; ?>

<p>Control who can view each Hostlinks front-end shortcode. Administrators always have access regardless of the setting.</p>

<form method="post" id="hl-user-access-form">
	<?php wp_nonce_field( 'hostlinks_user_access' ); ?>

	<!-- Hidden field that carries the current approved viewer ID list on save -->
	<input type="hidden" id="hl_approved_viewer_ids" name="hl_approved_viewer_ids"
		value="<?php echo esc_attr( implode( ',', $approved_ids ) ); ?>" />

	<?php /* ── A. Per-shortcode access modes ───────────────────────────── */ ?>
	<h2>Shortcode Access Modes</h2>
	<table class="widefat striped" style="max-width:720px;margin-bottom:24px;">
		<thead>
			<tr>
				<th style="width:180px;">Shortcode</th>
				<th style="width:200px;">Page</th>
				<th>Access Mode</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( Hostlinks_Access::SHORTCODES as $key => $label ) :
			$current_mode = $saved_modes[ $key ] ?? 'approved_viewers';
		?>
			<tr>
				<td><code>[<?php echo esc_html( $key ); ?>]</code></td>
				<td><?php echo esc_html( $label ); ?></td>
				<td>
					<select name="hl_access_mode[<?php echo esc_attr( $key ); ?>]" style="width:220px;">
					<?php foreach ( $mode_labels as $mode => $mode_label ) : ?>
						<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $current_mode, $mode ); ?>>
							<?php echo esc_html( $mode_label ); ?>
						</option>
					<?php endforeach; ?>
					</select>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php /* ── B. Approved viewers ──────────────────────────────────────── */ ?>
	<h2>Approved Viewers</h2>
	<p>Users added here can view shortcodes set to <strong>Approved Viewers Only</strong>. Administrators are always approved and do not need to be added.</p>

	<div style="max-width:560px;margin-bottom:8px;">
		<label for="hl-user-search" style="display:block;font-weight:600;margin-bottom:6px;">Search users to add</label>
		<div style="display:flex;gap:8px;">
			<input type="text" id="hl-user-search" placeholder="Type a name or email…"
				class="regular-text" autocomplete="off" style="flex:1;" />
			<span id="hl-search-spinner" style="display:none;line-height:30px;color:#888;">Searching…</span>
		</div>
		<ul id="hl-search-results" style="
			list-style:none;margin:0;padding:0;max-width:560px;
			border:1px solid #ddd;border-top:none;display:none;
			background:#fff;position:relative;z-index:100;
		"></ul>
	</div>

	<table class="widefat striped" style="max-width:560px;margin-bottom:24px;" id="hl-viewers-table">
		<thead>
			<tr>
				<th>Name</th>
				<th>Email</th>
				<th style="width:80px;"></th>
			</tr>
		</thead>
		<tbody id="hl-viewers-tbody">
		<?php if ( empty( $approved_users ) ) : ?>
			<tr id="hl-viewers-empty"><td colspan="3" style="color:#888;font-style:italic;">No approved viewers yet.</td></tr>
		<?php else : ?>
			<?php foreach ( $approved_users as $u ) : ?>
			<tr id="hl-viewer-row-<?php echo (int) $u->ID; ?>">
				<td><?php echo esc_html( $u->display_name ); ?></td>
				<td><?php echo esc_html( $u->user_email ); ?></td>
				<td>
					<button type="button" class="button button-small hl-remove-viewer"
						data-id="<?php echo (int) $u->ID; ?>">Remove</button>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php /* ── C. Denial message ──────────────────────────────────────────── */ ?>
	<h2>Access Denied Message</h2>
	<p>This message is shown inline when a user does not have access to a restricted shortcode.</p>
	<textarea id="hl_denial_message" name="hl_denial_message" rows="4"
		class="large-text" style="max-width:720px;"><?php echo esc_textarea( $denial_message ); ?></textarea>
	<p class="description">
		<a href="#" id="hl-reset-message">Reset to default</a>
	</p>

	<p class="submit" style="margin-top:16px;">
		<button type="submit" name="hostlinks_save_user_access" class="button button-primary">Save Settings</button>
	</p>
</form>
<?php if ( empty( $hl_embedded ) ) : ?></div><?php endif; ?>

<script>
(function() {
	var ajaxUrl  = <?php echo json_encode( $ajax_url ); ?>;
	var nonce    = <?php echo json_encode( $nonce ); ?>;
	var defaultMsg = <?php echo json_encode( Hostlinks_Access::DEFAULT_MESSAGE ); ?>;

	// ── Approved viewers (in-memory) ────────────────────────────────────────
	var approvedIds = <?php echo json_encode( array_map( 'intval', $approved_ids ) ); ?>;

	function syncIdsField() {
		document.getElementById('hl_approved_viewer_ids').value = approvedIds.join(',');
	}

	function removeViewer(id) {
		approvedIds = approvedIds.filter(function(x){ return x !== id; });
		syncIdsField();
		var row = document.getElementById('hl-viewer-row-' + id);
		if (row) row.remove();
		if (!document.querySelector('#hl-viewers-tbody tr:not(#hl-viewers-empty)')) {
			showEmptyRow();
		}
	}

	function showEmptyRow() {
		var tbody = document.getElementById('hl-viewers-tbody');
		if (!document.getElementById('hl-viewers-empty')) {
			var tr = document.createElement('tr');
			tr.id = 'hl-viewers-empty';
			tr.innerHTML = '<td colspan="3" style="color:#888;font-style:italic;">No approved viewers yet.</td>';
			tbody.appendChild(tr);
		}
	}

	function addViewer(user) {
		if (approvedIds.indexOf(user.id) !== -1) return; // already added
		approvedIds.push(user.id);
		syncIdsField();

		var empty = document.getElementById('hl-viewers-empty');
		if (empty) empty.remove();

		var tbody = document.getElementById('hl-viewers-tbody');
		var tr = document.createElement('tr');
		tr.id = 'hl-viewer-row-' + user.id;
		tr.innerHTML =
			'<td>' + escHtml(user.name) + '</td>' +
			'<td>' + escHtml(user.email) + '</td>' +
			'<td><button type="button" class="button button-small hl-remove-viewer" data-id="' + user.id + '">Remove</button></td>';
		tbody.appendChild(tr);
	}

	// Delegate remove clicks on the table.
	document.getElementById('hl-viewers-tbody').addEventListener('click', function(e) {
		var btn = e.target.closest('.hl-remove-viewer');
		if (btn) removeViewer(parseInt(btn.dataset.id, 10));
	});

	// ── AJAX user search ────────────────────────────────────────────────────
	var searchInput   = document.getElementById('hl-user-search');
	var resultsBox    = document.getElementById('hl-search-results');
	var spinnerEl     = document.getElementById('hl-search-spinner');
	var searchTimeout = null;

	searchInput.addEventListener('input', function() {
		clearTimeout(searchTimeout);
		var q = this.value.trim();
		resultsBox.style.display = 'none';
		resultsBox.innerHTML = '';
		if (q.length < 2) return;

		searchTimeout = setTimeout(function() {
			spinnerEl.style.display = 'inline';
			var xhr = new XMLHttpRequest();
			xhr.open('GET', ajaxUrl + '?action=hostlinks_search_users&q=' + encodeURIComponent(q) + '&_ajax_nonce=' + encodeURIComponent(nonce));
			xhr.onload = function() {
				spinnerEl.style.display = 'none';
				try {
					var resp = JSON.parse(xhr.responseText);
					if (!resp.success || !resp.data.length) {
						resultsBox.innerHTML = '<li style="padding:8px 12px;color:#888;">No users found.</li>';
					} else {
						resultsBox.innerHTML = '';
						resp.data.forEach(function(u) {
							var li = document.createElement('li');
							li.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;';
							li.textContent = u.name + ' (' + u.email + ')';
							li.addEventListener('mousedown', function(e) {
								e.preventDefault(); // prevent input blur before click
								addViewer(u);
								searchInput.value = '';
								resultsBox.style.display = 'none';
								resultsBox.innerHTML = '';
							});
							li.addEventListener('mouseover', function(){ this.style.background = '#f0f0f0'; });
							li.addEventListener('mouseout',  function(){ this.style.background = ''; });
							resultsBox.appendChild(li);
						});
					}
					resultsBox.style.display = 'block';
				} catch(err) {}
			};
			xhr.onerror = function(){ spinnerEl.style.display = 'none'; };
			xhr.send();
		}, 300);
	});

	// Hide results when clicking elsewhere.
	document.addEventListener('click', function(e) {
		if (e.target !== searchInput) {
			resultsBox.style.display = 'none';
		}
	});
	searchInput.addEventListener('blur', function(){
		// Small delay so mousedown on a result fires first.
		setTimeout(function(){ resultsBox.style.display = 'none'; }, 200);
	});
	searchInput.addEventListener('focus', function(){
		if (resultsBox.children.length) resultsBox.style.display = 'block';
	});

	// ── Reset denial message ────────────────────────────────────────────────
	document.getElementById('hl-reset-message').addEventListener('click', function(e){
		e.preventDefault();
		document.getElementById('hl_denial_message').value = defaultMsg;
	});

	// ── Utility ─────────────────────────────────────────────────────────────
	function escHtml(str) {
		return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
})();
</script>
