<?php
/**
 * Handles plugin activation tasks and theme-conflict cleanup.
 *
 * On activation this class checks whether the active theme's functions.php
 * still contains the old built-in Hostlinks code. If it does, an admin notice
 * is displayed with a single "Clean up theme now" button that rewrites the file
 * to a minimal stub and removes all conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Activation {

	// wp_options key used to persist the conflict flag across page loads
	const CONFLICT_OPTION = 'hostlinks_theme_conflict';

	// Strings that uniquely identify the original theme-embedded Hostlinks code.
	// Intentionally avoids generic slugs like 'booking-menu' that other plugins might use.
	private static $fingerprints = array(
		'oldeventlisto',    // unique shortcode name
		'istructor-menu',   // typo-slug unique to this codebase
		'eventlisto',       // primary shortcode name
		'types-menu',       // combined with the others, specific enough
	);

	// ── Called from register_activation_hook ────────────────────────────────

	public static function on_activate() {
		Hostlinks_DB::create_tables();
		self::detect_theme_conflict();
	}

	// Scan the active theme's functions.php for old Hostlinks registrations
	public static function detect_theme_conflict() {
		$functions_file = get_stylesheet_directory() . '/functions.php';

		if ( ! file_exists( $functions_file ) ) {
			return;
		}

		$content = file_get_contents( $functions_file );

		foreach ( self::$fingerprints as $needle ) {
			if ( strpos( $content, $needle ) !== false ) {
				update_option( self::CONFLICT_OPTION, true );
				return;
			}
		}

		// No conflict found – clear any stale flag from a previous activation
		delete_option( self::CONFLICT_OPTION );
	}

	// ── Instance: hooks registered on every page load ───────────────────────

	public function __construct() {
		add_action( 'admin_notices',                         array( $this, 'show_conflict_notice' ) );
		add_action( 'admin_post_hostlinks_clean_theme',      array( $this, 'handle_theme_cleanup' ) );
	}

	// ── Admin notice ─────────────────────────────────────────────────────────

	public function show_conflict_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show a success message after a successful cleanup
		if ( isset( $_GET['hostlinks_cleaned'] ) && $_GET['hostlinks_cleaned'] === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo '<strong>Hostlinks:</strong> Theme cleanup completed. The old built-in Hostlinks code has been removed from the theme\'s functions.php.';
			echo '</p></div>';
			return;
		}

		// Show an error message if the file wasn't writable
		if ( isset( $_GET['hostlinks_clean_failed'] ) && $_GET['hostlinks_clean_failed'] === '1' ) {
			$functions_file = get_stylesheet_directory() . '/functions.php';
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo '<strong>Hostlinks:</strong> Could not rewrite <code>' . esc_html( $functions_file ) . '</code> — the file is not writable by the web server. ';
			echo 'Please remove the old Hostlinks registrations manually or fix file permissions and try again.';
			echo '</p></div>';
			return;
		}

		if ( ! get_option( self::CONFLICT_OPTION ) ) {
			return;
		}

		$action_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=hostlinks_clean_theme' ),
			'hostlinks_clean_theme'
		);

		echo '<div class="notice notice-warning" style="padding-bottom:12px;">';
		echo '<p><strong>Hostlinks Plugin:</strong> Your active theme\'s <code>functions.php</code> still contains the old built-in Hostlinks code. ';
		echo 'This will conflict with the plugin (duplicate menus, duplicate shortcodes, double asset loads).</p>';
		echo '<p>';
		echo '<a href="' . esc_url( $action_url ) . '" class="button button-primary">Clean up theme now</a> ';
		echo '<span style="margin-left:8px;color:#666;">Replaces <code>functions.php</code> with a minimal stub. Back up the file first if you have other custom code in it.</span>';
		echo '</p>';
		echo '</div>';
	}

	// ── Cleanup handler ───────────────────────────────────────────────────────

	public function handle_theme_cleanup() {
		check_admin_referer( 'hostlinks_clean_theme' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to perform this action.' );
		}

		$functions_file = get_stylesheet_directory() . '/functions.php';

		if ( ! file_exists( $functions_file ) || ! is_writable( $functions_file ) ) {
			wp_safe_redirect( admin_url( 'plugins.php?hostlinks_clean_failed=1' ) );
			exit;
		}

		// Read existing content so we can preserve any non-Hostlinks custom code
		$original = file_get_contents( $functions_file );
		$cleaned  = $this->strip_hostlinks_blocks( $original );

		// If nothing was actually removed, the file may already be clean
		if ( trim( $cleaned ) === trim( $original ) ) {
			delete_option( self::CONFLICT_OPTION );
			wp_safe_redirect( admin_url( 'plugins.php?hostlinks_cleaned=1' ) );
			exit;
		}

		// Write the cleaned content back
		$written = file_put_contents( $functions_file, $cleaned );

		if ( $written === false ) {
			wp_safe_redirect( admin_url( 'plugins.php?hostlinks_clean_failed=1' ) );
			exit;
		}

		delete_option( self::CONFLICT_OPTION );
		wp_safe_redirect( admin_url( 'plugins.php?hostlinks_cleaned=1' ) );
		exit;
	}

	// ── Strip Hostlinks-specific blocks from functions.php content ───────────
	//
	// Strategy: remove any line (or contiguous block of lines) that contains
	// a Hostlinks fingerprint keyword, plus the surrounding add_action /
	// add_menu_page / add_shortcode / require_once call that wraps it.
	// Falls back to the minimal stub if the file appears to be *only*
	// Hostlinks code.

	private function strip_hostlinks_blocks( $content ) {
		// Patterns that mark an entire statement as Hostlinks-owned.
		// Each pattern matches a full PHP statement (ending at ;) that
		// contains a Hostlinks-specific identifier.
		$statement_patterns = array(
			// add_menu_page / add_submenu_page calls containing our slugs
			'/add_(?:menu|submenu)_page\s*\([^;]*(?:booking-menu|types-menu|marketer-menu|istructor-menu|hostlinks-import-export)[^;]*;\s*/s',
			// add_action calls wiring our callbacks
			'/add_action\s*\(\s*[\'"](?:admin_menu|wp_enqueue_scripts|admin_enqueue_scripts)[\'"][^;]*(?:hostlinks|eventlisto|booking|Hostlinks)[^;]*;\s*/si',
			// add_shortcode calls for our shortcodes
			'/add_shortcode\s*\([^;]*(?:eventlisto|oldeventlisto)[^;]*;\s*/s',
			// require_once / include lines pointing to our files
			'/(?:require_once|include_once|require|include)\s*[^;]*(?:booking|type-menu|markter-menu|istructor-menu|shortcode|eventlisto)[^;]*;\s*/s',
			// ob_start/ob_end_clean calls commonly paired with old shortcodes
			'/ob_(?:start|end_clean|get_clean)\s*\(\s*\)\s*;\s*\n?/',
			// Function definitions that are exclusively Hostlinks callbacks
			'/function\s+(?:hostlinks_\w+|booking_page|eventlisto_\w+|types_page|marketer_page|instructor_page)\s*\([^}]*\}\s*/s',
		);

		$cleaned = $content;
		foreach ( $statement_patterns as $pattern ) {
			$cleaned = preg_replace( $pattern, '', $cleaned );
		}

		// If nothing meaningful is left (only the opening <?php tag and whitespace)
		// return a tidy stub so the file isn't just blank
		$without_tag = preg_replace( '/^<\?php\s*/i', '', $cleaned );
		if ( trim( $without_tag ) === '' ) {
			return "<?php\n// Theme functions — Hostlinks functionality has been moved to the Hostlinks plugin.\n// wp-content/plugins/hostlinks/hostlinks.php\n";
		}

		// Collapse runs of blank lines down to a single blank line
		$cleaned = preg_replace( '/\n{3,}/', "\n\n", $cleaned );

		return $cleaned;
	}
}
