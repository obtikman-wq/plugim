<?php

namespace AIFB\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DB {

	public static function table_queue(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_queue';
	}

	public static function table_logs(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_logs';
	}

	public static function table_schedules(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_schedules';
	}

	public static function table_analytics(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_analytics';
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'aifbp_db_version' ) === AIFB_DB_VERSION ) {
			return;
		}

		self::create_tables();
		update_option( 'aifbp_db_version', AIFB_DB_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$queue   = self::table_queue();
		$logs    = self::table_logs();
		$sched   = self::table_schedules();
		$analyt  = self::table_analytics();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$queue} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				product_name VARCHAR(255) NOT NULL DEFAULT '',
				page_id VARCHAR(64) NOT NULL DEFAULT '',
				page_name VARCHAR(190) NOT NULL DEFAULT '',
				caption TEXT NULL,
				image_url TEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				scheduled_at DATETIME NULL,
				posted_at DATETIME NULL,
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				fb_post_id VARCHAR(64) NOT NULL DEFAULT '',
				source VARCHAR(20) NOT NULL DEFAULT 'manual',
				meta LONGTEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY status (status),
				KEY scheduled_at (scheduled_at),
				KEY product_id (product_id),
				KEY page_id (page_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				level VARCHAR(10) NOT NULL DEFAULT 'info',
				context VARCHAR(40) NOT NULL DEFAULT '',
				message TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$sched} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL DEFAULT '',
				type VARCHAR(20) NOT NULL DEFAULT 'daily',
				time_of_day VARCHAR(5) NOT NULL DEFAULT '10:00',
				days VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,0',
				posts_per_run INT UNSIGNED NOT NULL DEFAULT 1,
				interval_minutes INT UNSIGNED NOT NULL DEFAULT 120,
				pages TEXT NULL,
				start_date DATE NULL,
				end_date DATE NULL,
				active TINYINT UNSIGNED NOT NULL DEFAULT 1,
				last_run DATETIME NULL,
				next_run DATETIME NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY next_run (next_run),
				KEY active (active)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$analyt} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				fb_post_id VARCHAR(64) NOT NULL DEFAULT '',
				queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				page_id VARCHAR(64) NOT NULL DEFAULT '',
				impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
				clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
				reactions BIGINT UNSIGNED NOT NULL DEFAULT 0,
				comments BIGINT UNSIGNED NOT NULL DEFAULT 0,
				fetched_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY fb_post_id (fb_post_id),
				KEY queue_id (queue_id)
			) {$charset};"
		);
	}

	public static function drop_tables(): void {
		global $wpdb;
		foreach ( [ self::table_queue(), self::table_logs(), self::table_schedules(), self::table_analytics() ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
