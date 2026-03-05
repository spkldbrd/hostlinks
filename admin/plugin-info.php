<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Hostlinks_Updater $updater */
$updater         = Hostlinks_Updater::instance();
$just_checked    = isset( $_GET['hl_checked'] ) && $_GET['hl_checked'] === '1';
$current_version = HOSTLINKS_VERSION;

// If the user just clicked "Check for Updates", fetch a fresh release.
// Otherwise use the cached value (or fetch if not yet cached).
$release         = $just_checked ? $updater->fetch_fresh_release() : $updater->get_latest_release();
$latest_version  = $release ? ltrim( $release->tag_name, 'vV' ) : null;
$update_available = $latest_version && version_compare( $latest_version, $current_version, '>' );

$check_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=hostlinks_force_check' ),
	'hostlinks_force_check'
);

$update_url = admin_url( 'update-core.php' );
$github_url = 'https://github.com/' . HOSTLINKS_GITHUB_USER . '/' . HOSTLINKS_GITHUB_REPO;
?>
<div class="wrap">
	<h1>Hostlinks — Plugin Info</h1>

	<?php if ( $just_checked ) : ?>
		<div class="notice notice-info is-dismissible">
			<p>Update check complete. Results shown below.</p>
		</div>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:680px;margin-top:20px;">
		<tbody>
			<tr>
				<th style="width:200px;">Plugin Name</th>
				<td>Hostlinks</td>
			</tr>
			<tr>
				<th>Author</th>
				<td>Digital Solution</td>
			</tr>
			<tr>
				<th>Installed Version</th>
				<td><strong><?php echo esc_html( $current_version ); ?></strong></td>
			</tr>
			<tr>
				<th>Latest Version</th>
				<td>
					<?php if ( $latest_version ) : ?>
						<strong><?php echo esc_html( $latest_version ); ?></strong>
						<?php if ( $update_available ) : ?>
							&nbsp;<span style="color:#d63638;font-weight:600;">&#9650; Update available</span>
						<?php else : ?>
							&nbsp;<span style="color:#00a32a;font-weight:600;">&#10003; Up to date</span>
						<?php endif; ?>
					<?php else : ?>
						<span style="color:#999;">Unable to reach GitHub — check server connectivity.</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>GitHub Repository</th>
				<td><a href="<?php echo esc_url( $github_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $github_url ); ?></a></td>
			</tr>
			<?php if ( $release ) : ?>
			<tr>
				<th>Release Notes</th>
				<td>
					<?php
					$notes = trim( $release->body ?? '' );
					echo $notes ? nl2br( esc_html( $notes ) ) : '<em style="color:#999;">No release notes provided.</em>';
					?>
				</td>
			</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<p style="margin-top:20px;">
		<a href="<?php echo esc_url( $check_url ); ?>" class="button button-primary">
			&#8635;&nbsp; Check for Updates Now
		</a>

		<?php if ( $update_available ) : ?>
			&nbsp;
			<a href="<?php echo esc_url( $update_url ); ?>" class="button button-secondary" style="color:#d63638;border-color:#d63638;">
				Go to Updates &rarr;
			</a>
		<?php endif; ?>
	</p>

	<p style="color:#999;font-size:12px;">
		GitHub releases are cached for 12 hours. Use "Check for Updates Now" to bypass the cache and get the latest result immediately.
	</p>
</div>
