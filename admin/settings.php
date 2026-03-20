<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$valid_tabs = array(
	'general'           => 'General',
	'event-settings'    => 'Build Request Form',
	'user-access'       => 'User Access',
	'cvent'             => 'CVENT',
	'import-export'     => 'Import / Export',
	'type-settings'     => 'Type Settings',
	'public-event-list' => 'Public Event List',
	'roster'            => 'Roster',
);

$current_tab = sanitize_key( $_GET['tab'] ?? 'general' );
if ( ! array_key_exists( $current_tab, $valid_tabs ) ) {
	$current_tab = 'general';
}

$base_url = admin_url( 'admin.php?page=hostlinks-settings' );
?>
<div class="wrap">
<h1>Hostlinks — Settings</h1>

<nav class="nav-tab-wrapper" style="margin-bottom:0;">
	<?php foreach ( $valid_tabs as $slug => $label ) :
		$url    = add_query_arg( 'tab', $slug, $base_url );
		$active = ( $slug === $current_tab ) ? ' nav-tab-active' : '';
	?>
	<a href="<?php echo esc_url( $url ); ?>"
		class="nav-tab<?php echo $active; ?>"><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>

<div class="hl-settings-tab-content" style="border:1px solid #c3c4c7;border-top:none;background:#fff;padding:20px 20px 24px;">

<?php
$hl_embedded = true;

switch ( $current_tab ) {
	case 'general':
		include HOSTLINKS_PLUGIN_DIR . 'admin/settings-general.php';
		break;
	case 'event-settings':
		include HOSTLINKS_PLUGIN_DIR . 'admin/event-request-settings.php';
		break;
	case 'user-access':
		include HOSTLINKS_PLUGIN_DIR . 'admin/user-access.php';
		break;
	case 'cvent':
		include HOSTLINKS_PLUGIN_DIR . 'admin/cvent-settings.php';
		break;
	case 'import-export':
		include HOSTLINKS_PLUGIN_DIR . 'admin/import-export.php';
		break;
	case 'type-settings':
		include HOSTLINKS_PLUGIN_DIR . 'admin/type-menu.php';
		break;
	case 'public-event-list':
		include HOSTLINKS_PLUGIN_DIR . 'admin/public-event-list-settings.php';
		break;
	case 'roster':
		include HOSTLINKS_PLUGIN_DIR . 'admin/settings-roster.php';
		break;
}
?>

</div><!-- .hl-settings-tab-content -->
</div><!-- .wrap -->
