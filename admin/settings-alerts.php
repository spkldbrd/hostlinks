<?php
/**
 * Settings → Alerts tab.
 *
 * Configures per-alert enable toggle, days-out threshold, paid-reg threshold,
 * and border/glow color for the Upcoming Events calendar.
 * Included from admin/settings.php with $hl_embedded = true.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}

$notice = '';
if ( isset( $_POST['hostlinks_save_alerts'] ) ) {
	check_admin_referer( 'hostlinks_alerts' );

	update_option( 'hostlinks_alert_1_enabled', isset( $_POST['hostlinks_alert_1_enabled'] ) ? 1 : 0 );
	update_option( 'hostlinks_alert_1_days',    max( 1, (int) ( $_POST['hostlinks_alert_1_days']  ?? 30 ) ) );
	update_option( 'hostlinks_alert_1_regs',    max( 1, (int) ( $_POST['hostlinks_alert_1_regs']  ?? 15 ) ) );
	$c1 = sanitize_hex_color( $_POST['hostlinks_alert_1_color'] ?? '#f59e0b' );
	update_option( 'hostlinks_alert_1_color',   $c1 ?: '#f59e0b' );
	update_option( 'hostlinks_alert_1_message', sanitize_text_field( wp_unslash( $_POST['hostlinks_alert_1_message'] ?? '' ) ) );

	update_option( 'hostlinks_alert_2_enabled', isset( $_POST['hostlinks_alert_2_enabled'] ) ? 1 : 0 );
	update_option( 'hostlinks_alert_2_days',    max( 1, (int) ( $_POST['hostlinks_alert_2_days']  ?? 20 ) ) );
	update_option( 'hostlinks_alert_2_regs',    max( 1, (int) ( $_POST['hostlinks_alert_2_regs']  ?? 20 ) ) );
	$c2 = sanitize_hex_color( $_POST['hostlinks_alert_2_color'] ?? '#dc2626' );
	update_option( 'hostlinks_alert_2_color',   $c2 ?: '#dc2626' );
	update_option( 'hostlinks_alert_2_message', sanitize_text_field( wp_unslash( $_POST['hostlinks_alert_2_message'] ?? '' ) ) );

	update_option( 'hostlinks_alert_badge_enabled', isset( $_POST['hostlinks_alert_badge_enabled'] ) ? 1 : 0 );

	$notice = '<div class="notice notice-success is-dismissible"><p>Alert settings saved.</p></div>';
}

$a1_enabled = (int) get_option( 'hostlinks_alert_1_enabled', 1 );
$a1_days    = (int) get_option( 'hostlinks_alert_1_days',    30 );
$a1_regs    = (int) get_option( 'hostlinks_alert_1_regs',    15 );
$a1_color   =       get_option( 'hostlinks_alert_1_color',   '#f59e0b' );
$a1_message =       get_option( 'hostlinks_alert_1_message', '' );

$a2_enabled = (int) get_option( 'hostlinks_alert_2_enabled', 1 );
$a2_days    = (int) get_option( 'hostlinks_alert_2_days',    20 );
$a2_regs    = (int) get_option( 'hostlinks_alert_2_regs',    20 );
$a2_color   =       get_option( 'hostlinks_alert_2_color',   '#dc2626' );
$a2_message =       get_option( 'hostlinks_alert_2_message', '' );

$badge_on   = (int) get_option( 'hostlinks_alert_badge_enabled', 1 );
?>
<?php echo $notice; ?>

<h2 style="margin-top:0;">Registration Alerts</h2>
<p>Highlight upcoming events on the calendar when registration counts are low relative to how close the event is.
Alerts only apply to <strong>future</strong> events. If both alert conditions are met for the same event, Alert 2 takes priority.</p>

<form method="post">
	<?php wp_nonce_field( 'hostlinks_alerts' ); ?>

	<!-- ── Alert 1 ─────────────────────────────────────────────────────────── -->
	<h3 style="margin-top:24px;border-bottom:1px solid #ddd;padding-bottom:8px;">Alert 1</h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Enable</th>
			<td>
				<label>
					<input type="checkbox" name="hostlinks_alert_1_enabled" value="1" <?php checked( $a1_enabled, 1 ); ?>>
					Active
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">Days until event</th>
			<td>
				<input type="number" name="hostlinks_alert_1_days" value="<?php echo esc_attr( $a1_days ); ?>"
					min="1" max="365" style="width:80px;">
				<span class="description">Trigger when the event is this many days away or fewer</span>
			</td>
		</tr>
		<tr>
			<th scope="row">Paid registrations below</th>
			<td>
				<input type="number" name="hostlinks_alert_1_regs" value="<?php echo esc_attr( $a1_regs ); ?>"
					min="1" max="999" style="width:80px;">
				<span class="description">Trigger when paid count is less than this number</span>
			</td>
		</tr>
		<tr>
			<th scope="row">Border &amp; glow color</th>
			<td>
				<input type="color" id="hl-alert-1-color" name="hostlinks_alert_1_color"
					value="<?php echo esc_attr( $a1_color ); ?>"
					style="width:52px;height:36px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
				<span class="description" style="margin-left:6px;">Used for the card border and glow</span>
			</td>
		</tr>
		<tr>
			<th scope="row">Tooltip second line</th>
			<td>
				<input type="text" name="hostlinks_alert_1_message" value="<?php echo esc_attr( $a1_message ); ?>"
					class="regular-text" placeholder="Optional — leave blank to omit">
				<p class="description">Appears as a second line in the hover tooltip. Leave blank to show only the registration count and days.</p>
			</td>
		</tr>
	</table>

	<!-- ── Alert 2 ─────────────────────────────────────────────────────────── -->
	<h3 style="margin-top:28px;border-bottom:1px solid #ddd;padding-bottom:8px;">Alert 2</h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Enable</th>
			<td>
				<label>
					<input type="checkbox" name="hostlinks_alert_2_enabled" value="1" <?php checked( $a2_enabled, 1 ); ?>>
					Active
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">Days until event</th>
			<td>
				<input type="number" name="hostlinks_alert_2_days" value="<?php echo esc_attr( $a2_days ); ?>"
					min="1" max="365" style="width:80px;">
				<span class="description">Trigger when the event is this many days away or fewer</span>
			</td>
		</tr>
		<tr>
			<th scope="row">Paid registrations below</th>
			<td>
				<input type="number" name="hostlinks_alert_2_regs" value="<?php echo esc_attr( $a2_regs ); ?>"
					min="1" max="999" style="width:80px;">
				<span class="description">Trigger when paid count is less than this number</span>
			</td>
		</tr>
		<tr>
			<th scope="row">Border &amp; glow color</th>
			<td>
				<input type="color" id="hl-alert-2-color" name="hostlinks_alert_2_color"
					value="<?php echo esc_attr( $a2_color ); ?>"
					style="width:52px;height:36px;padding:2px;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
				<span class="description" style="margin-left:6px;">Used for the card border and glow</span>
			</td>
		</tr>
		<tr>
			<th scope="row">Tooltip second line</th>
			<td>
				<input type="text" name="hostlinks_alert_2_message" value="<?php echo esc_attr( $a2_message ); ?>"
					class="regular-text" placeholder="Optional — leave blank to omit">
				<p class="description">Appears as a second line in the hover tooltip. Leave blank to show only the registration count and days.</p>
			</td>
		</tr>
	</table>

	<!-- ── Triangle badge ─────────────────────────────────────────────────── -->
	<h3 style="margin-top:28px;border-bottom:1px solid #ddd;padding-bottom:8px;">Triangle Badge</h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Show triangle badge</th>
			<td>
				<label>
					<input type="checkbox" name="hostlinks_alert_badge_enabled" value="1" <?php checked( $badge_on, 1 ); ?>>
					Display a colored triangle on alerted cards
				</label>
				<p class="description">The triangle appears inline with the days-to-event text at the bottom of the card. Hovering over it shows the current registration count, days remaining, and the threshold that triggered the alert.</p>
			</td>
		</tr>
	</table>

	<!-- ── Live preview ────────────────────────────────────────────────────── -->
	<h3 style="margin-top:28px;border-bottom:1px solid #ddd;padding-bottom:8px;">Preview</h3>
	<p class="description">Updates live as you change the colors above.</p>
	<div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:12px;">

		<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">
			<strong style="font-size:12px;color:#666;">Alert 1</strong>
			<div id="hl-preview-1" style="
				background:#fff;
				border:1px solid <?php echo esc_attr( $a1_color ); ?>;
				box-shadow: 0 0 0 3px <?php echo esc_attr( $a1_color ); ?>59;
				border-radius:0.75rem;
				padding:14px 18px;
				min-width:180px;
				font-family:sans-serif;
				font-size:14px;
				line-height:1.6;
			">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px;">
					<strong>8+0</strong>
					<span style="color:#6b7280;">Jane Smith</span>
				</div>
				<div style="color:#0ea5e9;font-weight:600;">Austin, TX</div>
				<div style="color:#374151;font-size:13px;">March 15–17, 2026</div>
				<div style="color:#6b7280;font-size:13px;">28 days to event</div>
			</div>
		</div>

		<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">
			<strong style="font-size:12px;color:#666;">Alert 2</strong>
			<div id="hl-preview-2" style="
				background:#fff;
				border:1px solid <?php echo esc_attr( $a2_color ); ?>;
				box-shadow: 0 0 0 3px <?php echo esc_attr( $a2_color ); ?>59;
				border-radius:0.75rem;
				padding:14px 18px;
				min-width:180px;
				font-family:sans-serif;
				font-size:14px;
				line-height:1.6;
			">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px;">
					<strong>12+0</strong>
					<span style="color:#6b7280;">Bob Jones</span>
				</div>
				<div style="color:#0ea5e9;font-weight:600;">Denver, CO</div>
				<div style="color:#374151;font-size:13px;">March 22–24, 2026</div>
				<div style="color:#6b7280;font-size:13px;">15 days to event</div>
			</div>
		</div>

	</div>

	<p class="submit" style="margin-top:24px;">
		<button type="submit" name="hostlinks_save_alerts" class="button button-primary">Save Alert Settings</button>
	</p>
</form>

<script>
(function () {
	function hex2rgba59( hex ) {
		// Append 59 (≈35% opacity) to a 6-char hex color for the glow.
		return hex + '59';
	}

	var c1    = document.getElementById( 'hl-alert-1-color' );
	var c2    = document.getElementById( 'hl-alert-2-color' );
	var prev1 = document.getElementById( 'hl-preview-1' );
	var prev2 = document.getElementById( 'hl-preview-2' );

	if ( c1 && prev1 ) {
		c1.addEventListener( 'input', function () {
			prev1.style.borderColor = c1.value;
			prev1.style.boxShadow   = '0 0 0 3px ' + hex2rgba59( c1.value );
		} );
	}
	if ( c2 && prev2 ) {
		c2.addEventListener( 'input', function () {
			prev2.style.borderColor = c2.value;
			prev2.style.boxShadow   = '0 0 0 3px ' + hex2rgba59( c2.value );
		} );
	}
})();
</script>
