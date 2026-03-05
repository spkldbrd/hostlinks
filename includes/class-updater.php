<?php
/**
 * GitHub-based auto-updater for the Hostlinks plugin.
 *
 * How it works:
 *  1. On every WP update check, this class calls the GitHub Releases API for the
 *     configured repo and caches the response for 12 hours.
 *  2. If the latest release tag is newer than the installed version, WordPress
 *     shows the standard "update available" notice in Plugins > Installed Plugins.
 *  3. WordPress handles the actual download using the zipball URL from the release.
 *
 * To ship a new version:
 *  - Bump HOSTLINKS_VERSION in hostlinks.php
 *  - Push to GitHub and create a new Release with a tag matching the version
 *    number (e.g. tag "2.1.0" for version 2.1.0 – no "v" prefix needed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Updater {

	private $plugin_slug;
	private $plugin_file;
	private $github_user;
	private $github_repo;
	private $api_url;
	private $transient_key;

	public function __construct( $plugin_file, $github_user, $github_repo ) {
		$this->plugin_file   = $plugin_file;
		$this->plugin_slug   = plugin_basename( $plugin_file );
		$this->github_user   = $github_user;
		$this->github_repo   = $github_repo;
		$this->api_url       = "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest";
		$this->transient_key = 'hostlinks_github_update_' . md5( $this->api_url );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection',             array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete',             array( $this, 'clear_cache' ), 10, 2 );
		add_action( 'admin_post_hostlinks_force_check',      array( $this, 'handle_force_check' ) );
	}

	// ── Fetch latest release from GitHub (cached 12 h) ─────────────────────

	private function get_release() {
		$cached = get_transient( $this->transient_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$response = wp_remote_get( $this->api_url, array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
			),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $release->tag_name ) ) {
			return false;
		}

		set_transient( $this->transient_key, $release, 12 * HOUR_IN_SECONDS );
		return $release;
	}

	// Strip optional leading "v" from tag names like "v2.1.0"
	private function clean_version( $tag ) {
		return ltrim( $tag, 'vV' );
	}

	// ── Hook: inject update data into the WP update transient ───────────────

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version   = $this->clean_version( $release->tag_name );
		$current_version  = $transient->checked[ $this->plugin_slug ] ?? HOSTLINKS_VERSION;

		if ( version_compare( $remote_version, $current_version, '>' ) ) {
			$transient->response[ $this->plugin_slug ] = (object) array(
				'id'          => $this->plugin_slug,
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $remote_version,
				'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
				'package'     => $release->zipball_url,
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires_php'=> '',
			);
		}

		return $transient;
	}

	// ── Hook: supply plugin info for the "View details" popup ───────────────

	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Hostlinks',
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $this->clean_version( $release->tag_name ),
			'author'        => 'Digital Solution',
			'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'download_link' => $release->zipball_url,
			'sections'      => array(
				'description' => 'Event management tool for tracking hosted events, marketers, instructors, and types.',
				'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
			),
		);
	}

	// ── Hook: rename GitHub's auto-generated folder to the correct slug ─────
	//
	// GitHub zipballs extract to a folder named "{user}-{repo}-{hash}" rather
	// than the plugin slug. WordPress would treat that as a brand-new plugin
	// instead of an update. This filter renames the extracted folder to the
	// correct slug (e.g. "hostlinks") before WordPress copies it into place.

	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		// Only act when this is an update for our plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $source;
		}

		$correct_slug = dirname( $this->plugin_slug );  // e.g. "hostlinks"
		$correct_dir  = trailingslashit( $remote_source ) . $correct_slug;

		// If the folder is already correctly named, nothing to do
		if ( trailingslashit( $source ) === trailingslashit( $correct_dir ) ) {
			return $source;
		}

		// Rename the extracted folder to the correct slug
		if ( $wp_filesystem->move( $source, $correct_dir, true ) ) {
			return $correct_dir;
		}

		// If the rename failed, surface a WP_Error so the update is aborted
		// cleanly rather than installing under the wrong folder name
		return new WP_Error(
			'hostlinks_rename_failed',
			sprintf(
				'Could not rename extracted plugin folder to <code>%s</code>. Update aborted.',
				esc_html( $correct_slug )
			)
		);
	}

	// ── Hook: clear cached release after a plugin update ───────────────────

	public function clear_cache( $upgrader, $options ) {
		if (
			$options['action'] === 'update' &&
			$options['type']   === 'plugin' &&
			isset( $options['plugins'] ) &&
			in_array( $this->plugin_slug, $options['plugins'], true )
		) {
			delete_transient( $this->transient_key );
		}
	}

	// ── Public helpers used by the Plugin Info admin page ───────────────────

	// Bust the local cache and return a fresh release object (or false)
	public function fetch_fresh_release() {
		delete_transient( $this->transient_key );
		return $this->get_release();
	}

	// Return cached release (fetches if not yet cached)
	public function get_latest_release() {
		return $this->get_release();
	}

	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

	// ── Handler: force-check button in the Plugin Info page ─────────────────

	public function handle_force_check() {
		check_admin_referer( 'hostlinks_force_check' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Clear our GitHub transient and the WP core update_plugins transient
		delete_transient( $this->transient_key );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'admin.php?page=hostlinks-plugin-info&hl_checked=1' ) );
		exit;
	}
}
