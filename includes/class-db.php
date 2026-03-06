<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_DB {

	/**
	 * Run dbDelta only when the stored schema version is behind HOSTLINKS_DB_VERSION.
	 * Called on 'plugins_loaded' so it fires after every plugin update, not just activation.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'hostlinks_db_version', '0' );
		if ( ! version_compare( $installed, HOSTLINKS_DB_VERSION, '<' ) ) {
			return;
		}

		global $wpdb;

		// Suppress error output so a failed query never prints to the browser
		// before headers are sent (which cascades into cookie/redirect failures).
		$prev_suppress = $wpdb->suppress_errors( true );

		// v1.1 — rename misspelled column BEFORE dbDelta runs.
		// Running dbDelta first caused it to ADD eve_trainer_url as a new column
		// while eve_trainner_url still existed, then the CHANGE failed with
		// "Duplicate column name". Three states are handled:
		//   old only  → CHANGE (normal rename)
		//   both      → DROP old (recovery: dbDelta already added the new column)
		//   new only  → nothing to do (already migrated)
		if ( version_compare( $installed, '1.1', '<' ) ) {
			$table   = $wpdb->prefix . 'event_details_list';
			$has_old = ! empty( $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'eve_trainner_url' ) ) );
			$has_new = ! empty( $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'eve_trainer_url' ) ) );

			if ( $has_old && ! $has_new ) {
				$wpdb->query( "ALTER TABLE `{$table}` CHANGE `eve_trainner_url` `eve_trainer_url` text NOT NULL DEFAULT ''" );
			} elseif ( $has_old && $has_new ) {
				// Both columns exist: the broken state. Drop the misspelled one.
				$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `eve_trainner_url`" );
			}
		}

		// Now run dbDelta — sees the correct schema and makes no conflicting changes.
		self::create_tables();

		$wpdb->suppress_errors( $prev_suppress );

		update_option( 'hostlinks_db_version', HOSTLINKS_DB_VERSION );
	}

	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$wpdb->prefix}event_details_list (
			eve_id bigint(20) NOT NULL AUTO_INCREMENT,
			eve_location varchar(255) NOT NULL DEFAULT '',
			eve_paid int(11) NOT NULL DEFAULT 0,
			eve_free int(11) NOT NULL DEFAULT 0,
			eve_start date DEFAULT NULL,
			eve_end date DEFAULT NULL,
			eve_type int(11) NOT NULL DEFAULT 0,
			eve_zoom varchar(10) NOT NULL DEFAULT '',
			eve_marketer int(11) NOT NULL DEFAULT 0,
			eve_host_url text NOT NULL,
			eve_roster_url text NOT NULL,
			eve_trainer_url text NOT NULL,
			eve_sign_in_url text NOT NULL,
			eve_instructor int(11) NOT NULL DEFAULT 0,
			eve_tot_date varchar(100) NOT NULL DEFAULT '',
			eve_status tinyint(1) NOT NULL DEFAULT 1,
			cvent_event_id varchar(100) DEFAULT NULL,
			cvent_event_title varchar(500) DEFAULT NULL,
			cvent_event_start_utc datetime DEFAULT NULL,
			cvent_match_score tinyint unsigned DEFAULT NULL,
			cvent_match_status varchar(20) DEFAULT 'unlinked',
			cvent_last_synced datetime DEFAULT NULL,
			cvent_staleness_hash varchar(64) DEFAULT NULL,
			PRIMARY KEY  (eve_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$wpdb->prefix}event_type (
			event_type_id bigint(20) NOT NULL AUTO_INCREMENT,
			event_type_name varchar(255) NOT NULL DEFAULT '',
			event_type_color varchar(50) NOT NULL DEFAULT '',
			event_type_status tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (event_type_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$wpdb->prefix}event_marketer (
			event_marketer_id bigint(20) NOT NULL AUTO_INCREMENT,
			event_marketer_name varchar(255) NOT NULL DEFAULT '',
			event_marketer_status tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (event_marketer_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$wpdb->prefix}event_instructor (
			event_instructor_id bigint(20) NOT NULL AUTO_INCREMENT,
			event_instructor_name varchar(255) NOT NULL DEFAULT '',
			event_instructor_status tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (event_instructor_id)
		) $charset_collate;";
		dbDelta( $sql );
	}
}
