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

	public static function table_rotation(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_rotation';
	}

	public static function table_comments(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_comments';
	}

	public static function table_conversations(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifbp_conversations';
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
		$rot     = self::table_rotation();
		$cmts    = self::table_comments();
		$conv    = self::table_conversations();

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
				mode VARCHAR(10) NOT NULL DEFAULT 'ai',
				content_type VARCHAR(40) NOT NULL DEFAULT '',
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
				times VARCHAR(200) NOT NULL DEFAULT '',
				window_start VARCHAR(5) NOT NULL DEFAULT '',
				window_end VARCHAR(5) NOT NULL DEFAULT '',
				mode VARCHAR(10) NOT NULL DEFAULT 'ai',
				selection VARCHAR(20) NOT NULL DEFAULT 'random',
				categories TEXT NULL,
				content_format VARCHAR(20) NOT NULL DEFAULT 'full',
				template_name VARCHAR(190) NOT NULL DEFAULT '',
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
			"CREATE TABLE {$rotation} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				page_id VARCHAR(64) NOT NULL DEFAULT '',
				schedule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				mode VARCHAR(10) NOT NULL DEFAULT 'ai',
				posted_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY product_id (product_id),
				KEY page_id (page_id),
				KEY posted_at (posted_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$comments} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				page_id VARCHAR(64) NOT NULL DEFAULT '',
				post_id VARCHAR(64) NOT NULL DEFAULT '',
				comment_id VARCHAR(64) NOT NULL DEFAULT '',
				parent_id VARCHAR(64) NOT NULL DEFAULT '',
				from_name VARCHAR(190) NOT NULL DEFAULT '',
				from_id VARCHAR(64) NOT NULL DEFAULT '',
				message TEXT NULL,
				intent VARCHAR(30) NOT NULL DEFAULT '',
				reply_text TEXT NULL,
				reply_comment_id VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NULL,
				replied_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY comment_id (comment_id),
				KEY page_id (page_id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$conversations} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				page_id VARCHAR(64) NOT NULL DEFAULT '',
				sender_id VARCHAR(64) NOT NULL DEFAULT '',
				sender_name VARCHAR(190) NOT NULL DEFAULT '',
				last_message TEXT NULL,
				last_reply TEXT NULL,
				context LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				unread TINYINT UNSIGNED NOT NULL DEFAULT 0,
				updated_at DATETIME NULL,
				created_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY page_sender (page_id, sender_id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$analyt} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				fb_post_id VARCHAR(64) NOT NULL DEFAULT '',
				queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				page_id VARCHAR(64) NOT NULL DEFAULT '',
				mode VARCHAR(10) NOT NULL DEFAULT 'ai',
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
		foreach ( [ self::table_queue(), self::table_logs(), self::table_schedules(), self::table_analytics(), self::table_rotation(), self::table_comments(), self::table_conversations() ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
