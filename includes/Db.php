<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and schema installation (dbDelta).
 */
final class Db {

	public static function events(): string {
		global $wpdb;
		return $wpdb->prefix . 'scenecal_events';
	}

	public static function venues(): string {
		global $wpdb;
		return $wpdb->prefix . 'scenecal_venues';
	}

	public static function runs(): string {
		global $wpdb;
		return $wpdb->prefix . 'scenecal_runs';
	}

	/** Create or upgrade tables. Safe to call repeatedly. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events  = self::events();
		$venues  = self::venues();
		$runs    = self::runs();

		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY, KEY not INDEX.
		dbDelta(
			"CREATE TABLE $events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_key char(12) NOT NULL,
  origin_key char(12) NOT NULL,
  venue_id bigint(20) unsigned DEFAULT NULL,
  venue_name varchar(191) NOT NULL DEFAULT '',
  venue_city varchar(100) NOT NULL DEFAULT '',
  venue_province varchar(100) NOT NULL DEFAULT '',
  venue_country varchar(100) NOT NULL DEFAULT '',
  event_date date NOT NULL,
  start_time varchar(5) NOT NULL DEFAULT '',
  end_time varchar(5) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  description text NOT NULL,
  lineup text NOT NULL,
  ticket_price varchar(100) NOT NULL DEFAULT '',
  ticket_link varchar(2048) NOT NULL DEFAULT '',
  source_urls text NOT NULL,
  genre varchar(50) NOT NULL DEFAULT '',
  lineup_checked tinyint(1) NOT NULL DEFAULT 0,
  dedup_exempt tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'publish',
  duplicate_of bigint(20) unsigned DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'scraper',
  found_by varchar(191) NOT NULL DEFAULT '',
  locked tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_seen_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_key (event_key),
  KEY origin_key (origin_key),
  KEY date_status (event_date,status),
  KEY venue_name (venue_name)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE $venues (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'venue',
  city varchar(100) NOT NULL DEFAULT '',
  province varchar(100) NOT NULL DEFAULT '',
  country varchar(100) NOT NULL DEFAULT '',
  url varchar(2048) NOT NULL DEFAULT '',
  instagram varchar(100) NOT NULL DEFAULT '',
  aliases text NOT NULL,
  parser varchar(50) NOT NULL DEFAULT 'auto',
  agent_mode varchar(20) NOT NULL DEFAULT 'fallback',
  meta text NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  last_run_at datetime DEFAULT NULL,
  last_run_status varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name (name)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE $runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'queued',
  run_trigger varchar(20) NOT NULL DEFAULT 'cron',
  phase varchar(30) NOT NULL DEFAULT 'venues',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  heartbeat_at datetime DEFAULT NULL,
  state longtext NOT NULL,
  stats text NOT NULL,
  log longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;"
		);

		update_option( 'scenecal_db_version', SCENECAL_DB_VERSION );
	}

	/** Drop all plugin tables (uninstall only). */
	public static function drop(): void {
		global $wpdb;
		foreach ( array( self::events(), self::venues(), self::runs() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/** Current UTC time in MySQL format. */
	public static function now(): string {
		return current_time( 'mysql', true );
	}
}
