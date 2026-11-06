<?php
/**
 * One-click installer for the Hostlinks Zoom companion plugin.
 *
 * Mirrors the pattern established by class-cert-installer.php: fetches the
 * latest release zip from GitHub, installs it via Plugin_Upgrader, and renames
 * the extracted folder to the correct plugin slug.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Zoom_Installer {

	const PLUGIN_FILE = 'hostlinks-zoom/hostlinks-zoom.php';
	const GITHUB_USER = 'spkldbrd';
	const GITHUB_REPO = 'hostlinks-zoom';
	const API_URL     = 'https://api.github.com/repos/spkldbrd/hostlinks-zoom/releases/latest';

	public static function init(): void {
		add_action( 'admin_post_hostlinks_install_zoom', array( static::class, 'handle_install' ) );
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
			admin_url( 'admin-post.php?action=hostlinks_install_zoom' ),
			'hostlinks_install_zoom'
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

	/**
	 * Latest GitHub release version (cached briefly), or null on failure.
	 */
	public static function latest_remote_version(): ?string {
		$cache_key = 'hostlinks_zoom_latest_ver';
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached === '__none__' ? null : $cached;
		}

		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $cache_key, '__none__', 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $release->tag_name ) ) {
			set_transient( $cache_key, '__none__', 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$version = ltrim( (string) $release->tag_name, 'vV' );
		set_transient( $cache_key, $version, 15 * MINUTE_IN_SECONDS );
		return $version;
	}

	// ── admin-post handler ───────────────────────────────────────────────────

	public static function handle_install(): void {
		check_admin_referer( 'hostlinks_install_zoom' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( 'You do not have permission to install plugins.' );
		}

		$redirect_base = admin_url( 'admin.php?page=hostlinks-plugin-info' );

		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			wp_safe_redirect( add_query_arg( 'hl_zoom', 'github_fail', $redirect_base ) );
			exit;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $release->tag_name ) ) {
			wp_safe_redirect( add_query_arg( 'hl_zoom', 'github_fail', $redirect_base ) );
			exit;
		}

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

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

		WP_Filesystem();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		add_filter( 'upgrader_source_selection', array( static::class, 'fix_source_dir' ), 10, 4 );

		$result = $upgrader->install( $zip_url );

		remove_filter( 'upgrader_source_selection', array( static::class, 'fix_source_dir' ), 10 );

		delete_transient( 'hostlinks_zoom_latest_ver' );

		if ( is_wp_error( $result ) || false === $result ) {
			wp_safe_redirect( add_query_arg( 'hl_zoom', 'install_fail', $redirect_base ) );
			exit;
		}

		$status = ( null === $result ) ? 'already_installed' : 'installed';
		wp_safe_redirect( add_query_arg( 'hl_zoom', $status, $redirect_base ) );
		exit;
	}

	// ── Folder rename filter ─────────────────────────────────────────────────

	public static function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
		global $wp_filesystem;

		$correct_slug = self::GITHUB_REPO;
		$main_file    = $correct_slug . '.php';
		$correct_dir  = trailingslashit( $remote_source ) . $correct_slug;

		if ( untrailingslashit( $source ) === untrailingslashit( $correct_dir ) ) {
			return $source;
		}

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
