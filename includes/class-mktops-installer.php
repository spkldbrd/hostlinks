<?php
/**
 * One-click installer for the Hostlinks Marketing Ops companion plugin.
 *
 * Fetches the latest release zip from GitHub, installs it via Plugin_Upgrader,
 * and renames the extracted folder to match the correct plugin slug — the same
 * approach used by class-updater.php for Hostlinks updates.
 *
 * Registers an admin-post action so the install runs as a normal POST, not AJAX,
 * which avoids output-buffering issues with the WP filesystem layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_MktOps_Installer {

	const PLUGIN_FILE = 'hostlinks-marketing-ops/hostlinks-marketing-ops.php';
	const GITHUB_USER = 'spkldbrd';
	const GITHUB_REPO = 'hostlinks-marketing-ops';
	const API_URL     = 'https://api.github.com/repos/spkldbrd/hostlinks-marketing-ops/releases/latest';

	public static function init(): void {
		add_action( 'admin_post_hostlinks_install_mktops', array( static::class, 'handle_install' ) );
	}

	// ── Status helpers used by plugin-info.php ──────────────────────────────

	public static function is_installed(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE );
	}

	public static function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::PLUGIN_FILE );
	}

	/** URL for the one-click install button. */
	public static function install_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=hostlinks_install_mktops' ),
			'hostlinks_install_mktops'
		);
	}

	/** URL to activate an already-installed-but-inactive plugin. */
	public static function activate_url(): string {
		return wp_nonce_url(
			admin_url( 'plugins.php?action=activate&plugin=' . urlencode( self::PLUGIN_FILE ) ),
			'activate-plugin_' . self::PLUGIN_FILE
		);
	}

	/** Installed version string, or null if not installed / not readable. */
	public static function installed_version(): ?string {
		$path = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $path, false, false );
		return $data['Version'] ?? null;
	}

	// ── admin-post handler ───────────────────────────────────────────────────

	public static function handle_install(): void {
		check_admin_referer( 'hostlinks_install_mktops' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( 'You do not have permission to install plugins.' );
		}

		$redirect_base = admin_url( 'admin.php?page=hostlinks-plugin-info' );

		// ── Fetch latest GitHub release ──────────────────────────────────────
		$response = wp_remote_get( self::API_URL, array(
			'timeout' => 20,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			wp_safe_redirect( add_query_arg( 'hl_mktops', 'github_fail', $redirect_base ) );
			exit;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $release->tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'hl_mktops', 'github_fail', $redirect_base ) );
			exit;
		}

		// ── Resolve download URL ─────────────────────────────────────────────
		// Prefer the pre-built asset zip (correct folder structure).
		// Fall back to the GitHub archive zip (needs folder rename via filter).
		$zip_url = null;
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if (
					isset( $asset->name, $asset->browser_download_url ) &&
					$asset->name  === self::GITHUB_REPO . '.zip' &&
					$asset->state === 'uploaded'
				) {
					$zip_url = $asset->browser_download_url;
					break;
				}
			}
		}
		if ( ! $zip_url ) {
			$zip_url = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO
				. '/archive/refs/tags/' . $release->tag_name . '.zip';
		}

		// ── Load WP upgrader stack ───────────────────────────────────────────
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

		WP_Filesystem();

		// ── Install ──────────────────────────────────────────────────────────
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		// Attach our folder-rename filter (same logic as Hostlinks_Updater::fix_source_dir).
		add_filter( 'upgrader_source_selection', array( static::class, 'fix_source_dir' ), 10, 4 );

		$result = $upgrader->install( $zip_url );

		remove_filter( 'upgrader_source_selection', array( static::class, 'fix_source_dir' ), 10 );

		if ( is_wp_error( $result ) || false === $result ) {
			wp_safe_redirect( add_query_arg( 'hl_mktops', 'install_fail', $redirect_base ) );
			exit;
		}

		// null result means "already installed" (upgrader skipped it)
		$status = ( null === $result ) ? 'already_installed' : 'installed';
		wp_safe_redirect( add_query_arg( 'hl_mktops', $status, $redirect_base ) );
		exit;
	}

	// ── Folder rename filter ─────────────────────────────────────────────────
	// GitHub archive zips extract to "{repo}-{tag}/" instead of "{repo}/".
	// This filter renames it to the correct slug before WP copies it into place.

	public static function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
		global $wp_filesystem;

		$correct_slug = self::GITHUB_REPO;
		$main_file    = $correct_slug . '.php';
		$correct_dir  = trailingslashit( $remote_source ) . $correct_slug;

		// Already correctly named — nothing to do.
		if ( untrailingslashit( $source ) === untrailingslashit( $correct_dir ) ) {
			return $source;
		}

		// Only act when the extracted folder contains the expected main plugin file.
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_file ) ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $correct_dir ) ) {
			$wp_filesystem->delete( $correct_dir, true );
		}

		if ( $wp_filesystem->move( $source, $correct_dir ) ) {
			return trailingslashit( $correct_dir );
		}

		return $source;
	}
}
