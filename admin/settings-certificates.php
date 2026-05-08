<?php
/**
 * Settings → Certificates tab.
 *
 * Optional toolbar button for the Certificate Generator (requires the plugin).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$notice = '';
if ( isset( $_POST['hostlinks_save_cert_toolbar'] ) ) {
	check_admin_referer( 'hostlinks_cert_toolbar' );

	$btn = sanitize_key( $_POST['hostlinks_cert_btn'] ?? 'disabled' );
	if ( ! in_array( $btn, array( 'disabled', 'admin', 'custom', 'all' ), true ) ) {
		$btn = 'disabled';
	}
	update_option( 'hostlinks_cert_btn', $btn );

	$user_ids = isset( $_POST['hostlinks_cert_btn_users'] ) && is_array( $_POST['hostlinks_cert_btn_users'] )
		? array_map( 'intval', $_POST['hostlinks_cert_btn_users'] )
		: array();
	$user_ids = array_values( array_unique( array_filter( $user_ids, fn( $id ) => $id > 0 ) ) );
	update_option( 'hostlinks_cert_btn_users', $user_ids );

	$notice = '<div class="notice notice-success is-dismissible"><p>Certificate toolbar settings saved.</p></div>';
}

$btn_mode = get_option( 'hostlinks_cert_btn', 'disabled' );
$cert_url = Hostlinks_Page_URLs::get_certificate_hub();
$btn_user_ids = array_map( 'intval', (array) get_option( 'hostlinks_cert_btn_users', array() ) );
$pick_users = array();
foreach ( $btn_user_ids as $uid ) {
	$u = get_userdata( $uid );
	if ( $u ) {
		$pick_users[] = array(
			'id'    => $uid,
			'name'  => $u->display_name,
			'email' => $u->user_email,
		);
	}
}
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Certificate Generator toolbar</h2>
<p>When enabled, a <strong>&#x1F393; Certificates</strong> button appears in the Hostlinks toolbar on <code>[eventlisto]</code> and <code>[hostlinks_reports]</code> (after Reports, before Marketing Ops). It links to the published page that contains <code>[hostlinks_certificate_generator]</code>.</p>

<table class="widefat striped" style="max-width:660px;margin-bottom:16px;">
	<tbody>
		<tr>
			<th style="width:200px;">Shortcode</th>
			<td><code>[hostlinks_certificate_generator]</code> — requires the <strong>Hostlinks Certificate Generator</strong> plugin</td>
		</tr>
		<tr>
			<th>Page detected</th>
			<td>
				<?php if ( $cert_url ) : ?>
					<span style="color:#00a32a;font-weight:600;">&#9679; Yes</span>
					&mdash; <a href="<?php echo esc_url( $cert_url ); ?>" target="_blank"><?php echo esc_html( $cert_url ); ?></a>
				<?php else : ?>
					<span style="color:#d63638;font-weight:600;">&#9679; Not found</span>
					&mdash; Publish a page with the shortcode, or set a manual URL under <strong>Settings → General</strong> (Certificate Generator override).
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<form method="post">
	<?php wp_nonce_field( 'hostlinks_cert_toolbar' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hostlinks_cert_btn">&#x1F393; Certificates button</label></th>
			<td>
				<select id="hostlinks_cert_btn" name="hostlinks_cert_btn">
					<option value="disabled" <?php selected( $btn_mode, 'disabled' ); ?>>Hidden</option>
					<option value="admin" <?php selected( $btn_mode, 'admin' ); ?>>Administrators only</option>
					<option value="custom" <?php selected( $btn_mode, 'custom' ); ?>>Custom users (pick below)</option>
					<option value="all" <?php selected( $btn_mode, 'all' ); ?>>Anyone who can access this calendar/reports page</option>
				</select>
				<p class="description">Who sees the button in the toolbar. Actual certificate access is still controlled in <strong>Settings → HL Certificates</strong> on the Certificate Generator plugin.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hl-cert-user-search">Custom users</label></th>
			<td>
				<p class="description">Used when mode is “Custom users”. Search adds WordPress users to the list.</p>
				<input type="search" id="hl-cert-user-search" class="regular-text" placeholder="Search by name or email…" autocomplete="off" />
				<div id="hl-cert-user-suggest" style="margin-top:8px;"></div>
				<ul id="hl-cert-user-picked" style="list-style:disc;margin-left:1.5rem;">
					<?php foreach ( $pick_users as $pu ) : ?>
					<li data-id="<?php echo (int) $pu['id']; ?>">
						<?php echo esc_html( $pu['name'] . ' — ' . $pu['email'] ); ?>
						<button type="button" class="button-link hl-cert-remove-user" style="color:#b32d2e;">Remove</button>
						<input type="hidden" name="hostlinks_cert_btn_users[]" value="<?php echo (int) $pu['id']; ?>" />
					</li>
					<?php endforeach; ?>
				</ul>
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" name="hostlinks_save_cert_toolbar" class="button button-primary">Save</button>
	</p>
</form>

<script>
(function($){
	'use strict';
	function nonce(){ return { _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'hostlinks_user_access' ) ); ?>' }; }
	var tmr;
	$('#hl-cert-user-search').on('input', function(){
		var q = $(this).val().trim();
		clearTimeout(tmr);
		if (q.length < 2) { $('#hl-cert-user-suggest').empty(); return; }
		tmr = setTimeout(function(){
			$.post(ajaxurl, $.extend({ action: 'hostlinks_search_users', q: q }, nonce()))
				.done(function(res){
					if (!res || !res.success || !res.data) return;
					var html = '';
					res.data.forEach(function(u){
						if ($('#hl-cert-user-picked li[data-id="'+u.id+'"]').length) return;
						html += '<button type="button" class="button hl-cert-suggest" style="margin:2px;" data-id="'+u.id+'">'+$('<div/>').text(u.name+' — '+u.email).html()+'</button>';
					});
					$('#hl-cert-user-suggest').html(html);
				});
		}, 300);
	});
	$(document).on('click', '.hl-cert-suggest', function(){
		var id = $(this).data('id');
		var lab = $(this).text();
		if ($('#hl-cert-user-picked li[data-id="'+id+'"]').length) return;
		var li = $('<li/>').attr('data-id', id);
		li.append(document.createTextNode(lab+' '));
		li.append($('<button type="button" class="button-link hl-cert-remove-user"/>').css('color','#b32d2e').text('Remove'));
		li.append($('<input/>').attr({type:'hidden', name:'hostlinks_cert_btn_users[]', value:id}));
		$('#hl-cert-user-picked').append(li);
		$(this).remove();
	});
	$(document).on('click', '.hl-cert-remove-user', function(){ $(this).closest('li').remove(); });
})(jQuery);
</script>
