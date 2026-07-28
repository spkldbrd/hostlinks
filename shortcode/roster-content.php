<?php
/**
 * Roster inner content — shared by both the AJAX handler and direct include paths.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table11 = $wpdb->prefix . 'event_details_list';
$_rc_row = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$table11} WHERE eve_id = %d AND eve_status = '1' LIMIT 1", $eve_id ),
	ARRAY_A
);

if ( ! $_rc_row ) {
	echo '<p class="hl-fe-error">Event not found.</p>';
	return;
}

$_rc_cvent_id = Hostlinks_CVENT_API::sanitize_uuid( $_rc_row['cvent_event_id'] ?? '' );
if ( ! $_rc_cvent_id ) {
	echo '<p class="hl-fe-error">This event does not have a linked registration system ID yet.</p>';
	return;
}

$_rc_do_refresh = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );
$_rc_loaded     = Hostlinks_Roster::load_order_items( $_rc_cvent_id, $_rc_row, $_rc_do_refresh );

if ( is_wp_error( $_rc_loaded ) ) {
	echo '<p class="hl-fe-error">Could not load roster. Please try again later.</p>';
	return;
}

$_rc_is_past    = $_rc_loaded['is_past'];
$_rc_items      = $_rc_loaded['items'];
$_rc_attendees  = Hostlinks_Roster::build_rows( $_rc_items, $_rc_is_past );

Hostlinks_Roster::maybe_schedule_finalize( $_rc_cvent_id, $eve_id, $_rc_row, $_rc_is_past );

$_rc_title      = Hostlinks_Roster::build_title( $_rc_row, $eve_id, $wpdb );
$_rc_count      = count( $_rc_attendees );
$_rc_start_date = ! empty( $_rc_row['eve_start'] ) ? date( 'F j, Y', strtotime( $_rc_row['eve_start'] ) ) : '';
$_rc_end_date   = ! empty( $_rc_row['eve_end'] ) && $_rc_row['eve_end'] !== $_rc_row['eve_start']
                  ? ' – ' . date( 'F j, Y', strtotime( $_rc_row['eve_end'] ) ) : '';
$_rc_logo       = get_option( 'hostlinks_roster_logo_url', '' );
?>
<div class="hl-fe-roster" data-is-past="<?php echo $_rc_is_past ? '1' : '0'; ?>">

	<div class="hl-fe-roster-header">
		<div>
			<h2 class="hl-fe-roster-title"><?php echo esc_html( $_rc_title ); ?></h2>
			<p class="hl-fe-roster-meta">
				<?php if ( $_rc_start_date ) echo esc_html( $_rc_start_date . $_rc_end_date ) . ' &nbsp;|&nbsp; '; ?>
				<?php echo (int) $_rc_count; ?> registrant<?php echo $_rc_count !== 1 ? 's' : ''; ?>
			</p>
		</div>
		<?php if ( $_rc_logo ) : ?>
		<div class="hl-fe-roster-actions">
			<img src="<?php echo esc_url( $_rc_logo ); ?>" alt="" class="hl-fe-roster-logo" />
		</div>
		<?php endif; ?>
	</div>

	<?php echo Hostlinks_Roster::render_table( $_rc_attendees, $_rc_is_past, 'hl-fe' ); ?>

</div>

<style>
.hl-fe-roster { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.hl-fe-roster-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px; }
.hl-fe-roster-title { font-size:1.3em; margin:0 0 4px; }
.hl-fe-roster-meta { font-size:.85em; color:#666; margin:0; }
.hl-fe-roster-actions { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.hl-fe-roster-logo { max-height:72px; max-width:240px; object-fit:contain; display:block; }
.hl-fe-roster-table { width:100%; border-collapse:collapse; font-size:13px; }
.hl-fe-roster-table th { background:#1d2327; color:#fff; padding:7px 10px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; border:1px solid #3c434a; white-space:nowrap; }
.hl-fe-roster-table td { padding:6px 10px; border:1px solid #ddd; vertical-align:top; }
.hl-fe-roster-table tr:nth-child(even) td { background:#f9f9f9; }
.hl-fe-num { color:#aaa; font-size:11px; width:30px; }
.hl-fe-sign-in { width:260px; min-width:160px; }
.hl-fe-roster-totals { display:none; gap:10px; flex-wrap:wrap; margin-top:14px; }
.hl-fe-roster-total-card { display:none; flex:1 1 180px; border:1px solid #ddd; border-radius:4px; padding:8px 10px; font-size:12px; background:#fafafa; }
.hl-fe-roster-total-card strong { display:block; margin-bottom:4px; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.hl-fe-roster-total-card span { display:block; color:#555; line-height:1.5; }
<?php echo Hostlinks_Roster::optional_col_css( 'hl-fe' ); ?>
.hl-fe-error { color:#d63638; padding:20px 0; }
@media print {
	@page { size: landscape; margin: 0.5in; }
	body * { visibility:hidden; }
	body { background:#fff !important; margin:0 !important; padding:0 !important; }
	.hl-fe-roster { visibility:visible; position:absolute; left:0; top:0; width:100%; padding:0 16px; box-sizing:border-box; }
	.hl-fe-roster * { visibility:visible; }
	.hl-fe-roster-actions { display:flex !important; justify-content:flex-end; }
	.hl-fe-roster-btn, .hl-fe-roster-toggles, #hl-roster-col-toggles { display:none !important; }
	.hl-fe-roster-logo { display:block !important; max-height:72px; max-width:240px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-roster-table { width:100%; border-collapse:collapse; }
	.hl-fe-roster-table th { background:#000 !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
	.hl-fe-roster-table td, .hl-fe-roster-table th { border:1px solid #666 !important; padding:5px 8px; }
	<?php echo Hostlinks_Roster::optional_col_visible_css( 'hl-fe' ); ?>
	.hl-fe-sign-in { width:200pt; }
	.hl-fe-roster-totals { display:flex !important; }
	.hl-fe-roster-total-card.hl-fe-col-visible { display:block !important; }
}
</style>
