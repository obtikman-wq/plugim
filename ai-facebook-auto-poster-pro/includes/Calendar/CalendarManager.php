<?php

namespace AIFB\Calendar;

use AIFB\Core\DB;
use AIFB\Product\Rotation;
use AIFB\Queue\QueueManager;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CalendarManager {

	const CONTENT_TYPES = [
		'product_promotion' => 'Product Promotion',
		'special_offer'     => 'Special Offers',
		'product_benefits'  => 'Product Benefits',
		'educational'       => 'Educational Posts',
		'faq'               => 'FAQ',
		'engagement'        => 'Engagement Posts',
		'seasonal'          => 'Seasonal Campaigns',
		'brand_awareness'   => 'Brand Awareness',
		'comparison'        => 'Product Comparisons',
	];

	public static function generate( array $args ): array|\WP_Error {
		$pages = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $args['pages'] ?? [] ) ) ) );
		$pages = array_values( array_filter( $pages, fn( $pid ) => null !== Settings::page( (string) $pid ) ) );

		if ( empty( $pages ) ) {
			return new \WP_Error( 'aifbp_pages', __( 'Select at least one connected Facebook page.', 'aifbp' ) );
		}

		$days      = max( 1, min( 60, (int) ( $args['duration_days'] ?? 7 ) ) );
		$frequency = max( 1, min( 6, (int) ( $args['posts_per_day'] ?? 1 ) ) );
		$mode      = in_array( $args['mode'] ?? '', [ 'ai', 'direct' ], true ) ? $args['mode'] : 'direct';
		$type      = isset( self::CONTENT_TYPES[ $args['content_type'] ?? '' ] ) ? $args['content_type'] : 'product_promotion';
		$times     = array_slice( array_filter( array_map( 'trim', explode( ',', (string) ( $args['times'] ?? '10:00' ) ) ) ), 0, $frequency );
		$times     = array_pad( $times, $frequency, '10:00' );

		$categories = array_filter( array_map( 'absint', (array) ( $args['categories'] ?? [] ) ) );

		$start = strtotime( (string) ( $args['start_date'] ?? date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ) ) );

		if ( false === $start ) {
			return new \WP_Error( 'aifbp_dates', __( 'Invalid start date.', 'aifbp' ) );
		}

		$created = 0;
		$errors  = 0;

		for ( $d = 0; $d < $days; $d++ ) {
			$day = strtotime( '+' . $d . ' days', $start );

			foreach ( $times as $i => $time ) {
				$page_id = $pages[ $created % count( $pages ) ];
				$page    = Settings::page( $page_id );

				$product = Rotation::pick(
					[
						'selection'  => (string) ( $args['selection'] ?? 'random' ),
						'categories' => $categories,
						'page_id'    => $page_id,
						'mode'       => $mode,
					]
				);

				if ( is_wp_error( $product ) ) {
					Logger::warning( 'calendar', $product->get_error_message() );
					$errors++;

					continue;
				}

				$meta = [
					'calendar'       => 1,
					'content_type'   => $type,
					'objective'      => sanitize_text_field( (string) ( $args['objective'] ?? '' ) ),
					'tone'           => sanitize_text_field( (string) ( $args['tone'] ?? '' ) ),
					'direct_format'  => (string) ( $args['direct_format'] ?? '' ),
					'poster_size'    => (string) Settings::get( 'poster_size' ),
				];

				if ( 'ai' === $mode ) {
					$caption = \AIFB\AI\Manager::captions( $product );

					if ( is_wp_error( $caption ) ) {
						if ( ! empty( Settings::get( 'ai_fallback_direct' ) ) ) {
							$mode_item = 'direct';
						} else {
							$errors++;
							Logger::warning( 'calendar', 'AI calendar item skipped: ' . $caption->get_error_message() );

							continue;
						}
					} else {
						$mode_item = 'ai';
						$meta['caption_override'] = \AIFB\AI\Manager::compose( $caption, $product );
					}
				} else {
					$mode_item = 'direct';
				}

				$scheduled = date( 'Y-m-d', $day ) . ' ' . self::sanitize_time( $time ) . ':00';

				$result = QueueManager::enqueue(
					$product,
					$page_id,
					(string) ( $page['name'] ?? $page_id ),
					$scheduled,
					'calendar',
					$meta,
					$mode_item,
					(string) $type
				);

				if ( is_wp_error( $result ) ) {
					$errors++;
				} else {
					$created++;
				}
			}
		}

		if ( 0 === $created ) {
			return new \WP_Error( 'aifbp_calendar', __( 'No calendar posts could be created.', 'aifbp' ) . ( $errors ? ' ' . sprintf( __( '(%d skipped)', 'aifbp' ), $errors ) : '' ) );
		}

		return [ 'created' => $created, 'skipped' => $errors ];
	}

	public static function month( int $year, int $month ): array {
		return self::range(
			sprintf( '%04d-%02d-01 00:00:00', $year, $month ),
			sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) ) )
		);
	}

	public static function week( string $date ): array {
		$ts = strtotime( $date );

		if ( false === $ts ) {
			return [];
		}

		$monday = strtotime( 'monday this week', $ts );

		return self::range( date( 'Y-m-d 00:00:00', $monday ), date( 'Y-m-d 23:59:59', strtotime( '+6 days', $monday ) ) );
	}

	public static function day( string $date ): array {
		$ts = strtotime( $date );

		if ( false === $ts ) {
			return [];
		}

		return self::range( date( 'Y-m-d 00:00:00', $ts ), date( 'Y-m-d 23:59:59', $ts ) );
	}

	private static function range( string $from, string $to ): array {
		global $wpdb;
		$table = DB::table_queue();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_id, product_name, page_id, page_name, image_url, status, mode, content_type, scheduled_at, posted_at, source, meta FROM {$table} WHERE scheduled_at BETWEEN %s AND %s ORDER BY scheduled_at ASC",
				$from,
				$to
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	public static function set_item_status( int $id, string $status ): bool {
		global $wpdb;
		$table = DB::table_queue();

		$allowed = [ 'pending', 'draft' ];

		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		return (bool) $wpdb->update( $table, [ 'status' => $status ], [ 'id' => $id ] );
	}

	public static function sanitize_time( $time ): string {
		$time = trim( (string) $time );

		return preg_match( '/^\d{1,2}:\d{2}$/', $time ) ? sprintf( '%02d:%02d', ...array_map( 'intval', explode( ':', $time ) ) ) : '10:00';
	}
}
