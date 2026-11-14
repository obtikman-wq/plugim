<?php

namespace AIFB\Analytics;

use AIFB\Core\DB;
use AIFB\Facebook\Client;
use AIFB\Queue\QueueManager;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Analytics {

	const REFRESH_HOOK = 'aifbp_refresh_analytics';

	public static function init(): void {
		add_action( self::REFRESH_HOOK, [ __CLASS__, 'refresh_all' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );

		add_filter(
			'cron_schedules',
			function ( array $schedules ): array {
				$schedules['aifbp_6h'] = [
					'interval' => 6 * HOUR_IN_SECONDS,
					'display'  => __( 'Every 6 Hours (AI FB Poster)', 'aifbp' ),
				];

				return $schedules;
			}
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + 300, 'aifbp_6h', self::REFRESH_HOOK );
		}
	}

	public static function record( string $fb_post_id, int $queue_id, string $page_id, string $mode = 'ai' ): void {
		global $wpdb;

		$wpdb->replace(
			DB::table_analytics(),
			[
				'fb_post_id' => sanitize_text_field( $fb_post_id ),
				'queue_id'   => $queue_id,
				'page_id'    => sanitize_text_field( $page_id ),
				'mode'       => in_array( $mode, [ 'ai', 'direct' ], true ) ? $mode : 'ai',
				'fetched_at' => current_time( 'mysql' ),
			]
		);
	}

	public static function refresh_all( int $limit = 25 ): int {
		global $wpdb;
		$table = DB::table_analytics();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.fb_post_id, a.page_id FROM {$table} a WHERE a.fetched_at < DATE_SUB(%s, INTERVAL 1 HOUR) ORDER BY a.id DESC LIMIT %d",
				current_time( 'mysql' ),
				$limit
			),
			ARRAY_A
		);

		$updated = 0;

		foreach ( $rows ?: [] as $row ) {
			$token = Settings::page_token( (string) $row['page_id'] );

			if ( '' === $token ) {
				continue;
			}

			$insights = Client::post_insights( (string) $row['page_id'], $token, (string) $row['fb_post_id'] );

			if ( is_wp_error( $insights ) ) {
				continue;
			}

			$wpdb->update(
				$table,
				[
					'impressions' => (int) $insights['impressions'],
					'clicks'      => (int) $insights['clicks'],
					'reactions'   => (int) $insights['reactions'],
					'comments'    => (int) $insights['comments'],
					'fetched_at'  => current_time( 'mysql' ),
				],
				[ 'id' => (int) $row['id'] ]
			);

			$updated++;
		}

		return $updated;
	}

	public static function totals(): array {
		global $wpdb;
		$table  = DB::table_analytics();
		$counts = QueueManager::counts();

		$totals = $wpdb->get_row(
			"SELECT COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(reactions),0) AS reactions, COALESCE(SUM(comments),0) AS comments FROM {$table}",
			ARRAY_A
		) ?: [];

		$per_day = $wpdb->get_results(
			"SELECT DATE(q.posted_at) AS day, COUNT(*) AS posts FROM " . DB::table_queue() . " q WHERE q.status = 'posted' AND q.posted_at IS NOT NULL GROUP BY DATE(q.posted_at) ORDER BY day ASC LIMIT 30",
			ARRAY_A
		) ?: [];

		return [
			'queue'      => $counts,
			'impressions' => (int) ( $totals['impressions'] ?? 0 ),
			'clicks'      => (int) ( $totals['clicks'] ?? 0 ),
			'reactions'   => (int) ( $totals['reactions'] ?? 0 ),
			'comments'    => (int) ( $totals['comments'] ?? 0 ),
			'per_day'    => $per_day,
		];
	}
}
