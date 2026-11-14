<?php

namespace AIFB\Insights;

use AIFB\Core\DB;
use AIFB\Queue\QueueManager;
use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Advisor {

	const MIN_DATA_POINTS = 5;

	public static function best_time(): array {
		global $wpdb;

		$table = DB::table_analytics();
		$queue = DB::table_queue();

		$rows = $wpdb->get_results(
			"SELECT a.impressions, a.clicks, a.reactions, a.comments, q.posted_at
			FROM {$table} a
			INNER JOIN {$queue} q ON q.id = a.queue_id
			WHERE q.status = 'posted' AND q.posted_at IS NOT NULL AND (a.impressions > 0 OR a.clicks > 0 OR a.reactions > 0 OR a.comments > 0)",
			ARRAY_A
		) ?: [];

		if ( count( $rows ) < self::MIN_DATA_POINTS ) {
			return [
				'sufficient' => false,
				'samples'    => count( $rows ),
				'message'    => sprintf(
					__( 'Not enough engagement data yet (%d of %d posts with insights needed). Keep posting; recommendations appear once Meta insights accumulate.', 'aifbp' ),
					count( $rows ),
					self::MIN_DATA_POINTS
				),
			];
		}

		$by_hour  = [];
		$by_day   = [];

		foreach ( $rows as $row ) {
			$ts    = strtotime( (string) $row['posted_at'] );
			$hour  = (int) date( 'G', $ts );
			$day   = (int) date( 'w', $ts );
			$score = (int) $row['impressions'] + 2 * ( (int) $row['clicks'] + (int) $row['reactions'] + (int) $row['comments'] );

			$by_hour[ $hour ] = ( $by_hour[ $hour ] ?? 0 ) + $score;
			$by_day[ $day ]   = ( $by_day[ $day ] ?? 0 ) + $score;
		}

		arsort( $by_hour );
		arsort( $by_day );

		$best_hour = (int) array_key_first( $by_hour );
		$best_day  = (int) array_key_first( $by_day );

		$days = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

		return [
			'sufficient' => true,
			'samples'    => count( $rows ),
			'best_hour'  => sprintf( '%02d:00-%02d:00', $best_hour, ( $best_hour + 1 ) % 24 ),
			'best_day'   => $days[ $best_day ],
			'hour_scores' => $by_hour,
			'day_scores'  => $by_day,
			'message'    => sprintf(
				__( 'Based on %d posts with Meta insights, best window is %s on %s.', 'aifbp' ),
				count( $rows ),
				sprintf( '%02d:00-%02d:00', $best_hour, ( $best_hour + 1 ) % 24 ),
				$days[ $best_day ]
			),
		];
	}

	public static function best_products( int $limit = 10 ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'sufficient' => false,
				'message'    => __( 'WooCommerce is not active; product analysis unavailable.', 'aifbp' ),
				'products'   => [],
			];
		}

		global $wpdb;

		$products = wc_get_products( [ 'status' => 'publish', 'limit' => 100 ] );

		if ( count( $products ) < 1 ) {
			return [
				'sufficient' => false,
				'message'    => __( 'No published products to analyze.', 'aifbp' ),
				'products'   => [],
			];
		}

		$engagement = self::engagement_by_product();
		$posted     = Settings::posted_products();

		$scored = [];

		foreach ( $products as $wc ) {
			$pid        = $wc->get_id();
			$sales      = (int) $wc->get_total_sales();
			$on_sale    = (float) $wc->get_sale_price() > 0;
			$stock      = 'instock' === $wc->get_stock_status();
			$fb         = (int) ( $engagement[ $pid ] ?? 0 );
			$recently   = isset( $posted[ $pid ] ) && (int) $posted[ $pid ] > time() - 3 * DAY_IN_SECONDS;

			$score = $sales * 10 + $fb * 5 + ( $on_sale ? 15 : 0 ) + ( $stock ? 10 : -20 );

			$reasons = [];

			if ( $sales > 0 ) {
				$reasons[] = sprintf( '%d sales', $sales );
			}

			if ( $fb > 0 ) {
				$reasons[] = sprintf( 'Facebook engagement score %d', $fb );
			}

			if ( $on_sale ) {
				$reasons[] = __( 'currently on sale', 'aifbp' );
			}

			if ( ! $stock ) {
				$reasons[] = __( 'out of stock (deprioritized)', 'aifbp' );
			}

			$scored[] = [
				'id'       => $pid,
				'name'     => $wc->get_name(),
				'score'    => $score,
				'in_stock' => $stock,
				'on_sale'  => $on_sale,
				'recently_posted' => $recently,
				'reason'   => empty( $reasons ) ? __( 'New product, not yet promoted.', 'aifbp' ) : implode( ', ', $reasons ),
				'suggested_content' => $on_sale ? 'offer' : 'product_promotion',
			];
		}

		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return [
			'sufficient' => true,
			'products'   => array_slice( $scored, 0, $limit ),
			'message'    => '',
		];
	}

	private static function engagement_by_product(): array {
		global $wpdb;

		$analytics = DB::table_analytics();
		$queue     = DB::table_queue();

		$rows = $wpdb->get_results(
			"SELECT q.product_id, ( a.impressions + 2 * ( a.clicks + a.reactions + a.comments ) ) AS score
			FROM {$analytics} a
			INNER JOIN {$queue} q ON q.id = a.queue_id
			WHERE q.product_id > 0",
			ARRAY_A
		) ?: [];

		$map = [];

		foreach ( $rows as $row ) {
			$pid = (int) $row['product_id'];
			$map[ $pid ] = ( $map[ $pid ] ?? 0 ) + (int) $row['score'];
		}

		return $map;
	}

	public static function assistant_activity(): array {
		global $wpdb;

		$comments = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table_comments() );
		$replied  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . DB::table_comments() . " WHERE status = 'sent'" );
		$convos   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table_conversations() );

		return [
			'comments_tracked' => $comments,
			'comments_replied' => $replied,
			'conversations'    => $convos,
		];
	}
}
