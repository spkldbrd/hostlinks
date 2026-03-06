<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$result  = null;
$error   = null;
$s       = Hostlinks_CVENT_API::get_settings();
$ready   = ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && ! empty( $s['account_number'] );

if ( isset( $_POST['hostlinks_cvent_pull'] ) ) {
	check_admin_referer( 'hostlinks_cvent_sync' );

	if ( ! $ready ) {
		$error = 'CVENT credentials are not configured. Please complete <a href="' . esc_url( admin_url( 'admin.php?page=cvent-settings' ) ) . '">CVENT Settings</a> first.';
	} else {
		$result = Hostlinks_CVENT_API::test_pull();
		if ( is_wp_error( $result ) ) {
			$error  = esc_html( $result->get_error_message() );
			$result = null;
		}
	}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Extract a readable location string from a CVENT event record.
 */
function hostlinks_cvent_location( $event ) {
	if ( ! empty( $event['venues'] ) && is_array( $event['venues'] ) ) {
		$v    = $event['venues'][0];
		$city = $v['city']       ?? '';
		$reg  = $v['regionCode'] ?? ( $v['region'] ?? '' );
		$name = $v['name']       ?? '';
		$parts = array_filter( array( $name, $city, $reg ) );
		return implode( ', ', $parts );
	}
	return '—';
}

/**
 * Format an ISO 8601 datetime string for display.
 */
function hostlinks_cvent_fmt_date( $iso ) {
	if ( empty( $iso ) ) {
		return '—';
	}
	return esc_html( wp_date( 'M j, Y g:i a', strtotime( $iso ) ) );
}
?>
<div class="wrap">
	<h1>CVENT Sync</h1>

	<?php if ( ! $ready ) : ?>
		<div class="notice notice-warning">
			<p>
				CVENT credentials are not configured.
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cvent-settings' ) ); ?>">Go to CVENT Settings →</a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo wp_kses( $error, array( 'a' => array( 'href' => array() ) ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $result ) : ?>
		<?php
		$event  = $result['event'];
		$title  = esc_html( $event['title'] ?? '(no title)' );
		$start  = hostlinks_cvent_fmt_date( $event['start'] ?? '' );
		$end    = hostlinks_cvent_fmt_date( $event['end']   ?? '' );
		$loc    = esc_html( hostlinks_cvent_location( $event ) );
		$paid   = (int) $result['paid_count'];
		$free   = (int) $result['free_count'];
		$total  = (int) $result['total_count'];
		$calls  = (int) $result['api_calls_used'];
		?>

		<h2>Event Summary</h2>
		<table class="widefat striped" style="max-width:700px;margin-bottom:20px;">
			<tbody>
				<tr><th style="width:180px;">Event Title</th><td><strong><?php echo $title; ?></strong></td></tr>
				<tr><th>CVENT Event ID</th><td><code><?php echo esc_html( $event['id'] ?? '—' ); ?></code></td></tr>
				<tr><th>Start</th><td><?php echo $start; ?></td></tr>
				<tr><th>End</th><td><?php echo $end; ?></td></tr>
				<tr><th>Location</th><td><?php echo $loc; ?></td></tr>
				<tr><th>Capacity</th><td><?php echo isset( $event['capacity'] ) ? (int) $event['capacity'] : '—'; ?></td></tr>
				<tr>
					<th>Registrations (PAID)</th>
					<td><strong style="color:#0a6b00;"><?php echo $paid; ?></strong></td>
				</tr>
				<tr>
					<th>Registrations (FREE)</th>
					<td><strong style="color:#0073aa;"><?php echo $free; ?></strong></td>
				</tr>
				<tr><th>Total Attendees</th><td><?php echo $total; ?></td></tr>
				<tr><th>API Calls Used</th><td><?php echo $calls; ?> of 1,000 daily (free tier)</td></tr>
			</tbody>
		</table>

		<p><em>FREE rule: attendee discount code name contains "free" (case-insensitive). All others counted as PAID.</em></p>

		<details style="margin-bottom:16px;">
			<summary style="cursor:pointer;font-weight:600;padding:6px 0;">
				Raw Event JSON (click to expand)
			</summary>
			<pre style="background:#f6f7f7;border:1px solid #ddd;padding:12px;overflow:auto;max-height:400px;font-size:12px;"><?php
				echo esc_html( json_encode( $event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			?></pre>
		</details>

		<details>
			<summary style="cursor:pointer;font-weight:600;padding:6px 0;">
				Raw Attendees JSON — first <?php echo count( $result['attendees_raw'] ); ?> of <?php echo $total; ?> (click to expand)
			</summary>
			<pre style="background:#f6f7f7;border:1px solid #ddd;padding:12px;overflow:auto;max-height:400px;font-size:12px;"><?php
				echo esc_html( json_encode( $result['attendees_raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			?></pre>
		</details>

	<?php endif; ?>

	<hr style="margin-top:24px;" />

	<form method="post">
		<?php wp_nonce_field( 'hostlinks_cvent_sync' ); ?>
		<p>
			Fetches the first upcoming event (or one that ended within the last 10 days) from CVENT,
			retrieves all attendee records, and displays the raw data for review.
		</p>
		<p class="submit">
			<button
				type="submit"
				name="hostlinks_cvent_pull"
				class="button button-primary"
				<?php echo $ready ? '' : 'disabled'; ?>
			>Pull 1 Event from CVENT</button>
		</p>
	</form>

	<p style="color:#666;font-size:12px;">
		Each pull uses approximately 2 API calls (1 event list + 1 attendee page).
		Free tier limit: 1,000 calls/day.
	</p>
</div>
