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

	private static $instance = null;

	private $plugin_slug;
	private $plugin_file;
	private $github_user;
	private $github_repo;
	private $api_url;
	private $transient_key;

	/**
	 * Initialize the updater (call once from the main plugin file).
	 */
	public static function init( $plugin_file, $github_user, $github_repo ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file, $github_user, $github_repo );
		}
		return self::$instance;
	}

	/**
	 * Retrieve the already-initialized instance (for use in admin pages).
	 */
	public static function instance() {
		return self::$instance;
	}

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
			// Prefer the uploaded hostlinks.zip release asset — it already has
			// "hostlinks/" as its root folder so no renaming is needed.
			// Fall back to the archive URL for older releases that pre-date
			// the GitHub Actions workflow (fix_source_dir handles renaming there).
			$package = $this->get_package_url( $release, $remote_version );

			$transient->response[ $this->plugin_slug ] = (object) array(
				'id'          => $this->plugin_slug,
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $remote_version,
				'url'         => 'https://digitalsolution.com',
				'package'     => $package,
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

		$version       = $this->clean_version( $release->tag_name );
		$download_link = $this->get_package_url( $release, $version );

		return (object) array(
			'name'               => 'Hostlinks',
			'slug'               => dirname( $this->plugin_slug ),
			'version'            => $version,
			'author'             => '<a href="https://digitalsolution.com">Digital Solution</a>',
			'author_profile'     => 'https://digitalsolution.com',
			'homepage'           => 'https://digitalsolution.com',
			'requires'           => '5.0',
			'requires_php'       => '7.4',
			'tested'             => '',
			'last_updated'       => '',
			'short_description'  => 'Event management tool for tracking hosted events, marketers, instructors, and types.',
			'download_link'      => $download_link,
			'banners'            => array(),
			'icons'              => array(),
			'tags'               => array(),
			'rating'             => 0,
			'num_ratings'        => 0,
			'sections'           => array(
				'description' => 'Event management tool for tracking hosted events, marketers, instructors, and types.',
				'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
			),
		);
	}

	// ── Resolve the best download URL for a release ─────────────────────────
	//
	// Prefers the uploaded hostlinks.zip asset (built by GitHub Actions with
	// the correct "hostlinks/" root folder). Falls back to the predictable
	// archive URL for older releases that pre-date the Actions workflow.

	private function get_package_url( $release, $version ) {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if (
					isset( $asset->name, $asset->browser_download_url ) &&
					$asset->name === $this->github_repo . '.zip' &&
					$asset->state === 'uploaded'
				) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fallback: use the raw tag_name so the 'v' prefix is preserved in the URL.
		// clean_version() strips 'v' for version_compare(), but GitHub archive URLs
		// require the exact tag — e.g. refs/tags/v2.4.19.zip, not refs/tags/2.4.19.zip.
		$tag = isset( $release->tag_name ) ? $release->tag_name : $version;
		return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$tag}.zip";
	}

	// ── Hook: rename GitHub's extracted folder to the correct plugin slug ────
	//
	// GitHub archives extract to a folder named "{repo}-{tag}" (e.g.
	// "hostlinks-2.0.8") rather than the bare plugin slug ("hostlinks").
	// WordPress needs the folder to match the plugin slug, otherwise it
	// treats it as a brand-new plugin instead of an update.
	//
	// Detection strategy: we look for the plugin's own main file
	// (hostlinks.php) inside the extracted folder — no fragile regex needed.
	// Works for automatic WP updates AND manual zip uploads.

	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		$correct_slug    = dirname( $this->plugin_slug );          // "hostlinks"
		$main_plugin_file = $correct_slug . '.php';                // "hostlinks.php"
		$correct_dir     = trailingslashit( $remote_source ) . $correct_slug;

		// Nothing to do if already correctly named
		if ( untrailingslashit( $source ) === untrailingslashit( $correct_dir ) ) {
			return $source;
		}

		// Only act when the extracted folder actually contains our plugin's
		// main file — this prevents accidentally renaming unrelated zips
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_plugin_file ) ) {
			return $source;
		}

		// Remove any stale copy at the destination (e.g. from a failed attempt)
		if ( $wp_filesystem->exists( $correct_dir ) ) {
			$wp_filesystem->delete( $correct_dir, true );
		}

		if ( $wp_filesystem->move( $source, $correct_dir ) ) {
			return trailingslashit( $correct_dir );
		}

		// Rename failed — return the original source so WordPress can still
		// find the plugin file and at least report a meaningful error instead
		// of "No valid plugins were found"
		return $source;
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
