<?php

namespace AIFB\Schedule;

use AIFB\Core\DB;
use AIFB\Product\Picker;
use AIFB\Queue\QueueManager;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScheduleManager {

	const TICK_HOOK = 'aifbp_schedule_tick';

	public static function init(): void {
		add_action( self::TICK_HOOK, [ __CLASS__, 'tick' ] );
		add_action( 'init', [ __CLASS__, 'reschedule_tick' ] );
	}

	public static function reschedule_tick(): void {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 120, 'aifbp_5min', self::TICK_HOOK );
		}
	}

	public static function clear_tick(): void {
		wp_clear_scheduled_hook( self::TICK_HOOK );
	}

	public static function create( array $data ): int|\WP_Error {
		global $wpdb;

		$type = in_array( $data['type'] ?? '', [ 'daily', 'weekly', 'campaign' ], true ) ? $data['type'] : 'daily';

		$row = [
			'name'             => sanitize_text_field( $data['name'] ?? __( 'Schedule', 'aifbp' ) ),
			'type'             => $type,
			'time_of_day'      => self::sanitize_time( $data['time_of_day'] ?? '10:00' ),
			'days'             => self::sanitize_days( $data['days'] ?? '1,2,3,4,5,6,0' ),
			'posts_per_run'    => max( 1, (int) ( $data['posts_per_run'] ?? 1 ) ),
			'interval_minutes' => max( 5, (int) ( $data['interval_minutes'] ?? 120 ) ),
			'pages'            => wp_json_encode( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['pages'] ?? [] ) ) ) ) ),
			'start_date'       => self::sanitize_date( $data['start_date'] ?? '' ),
			'end_date'         => self::sanitize_date( $data['end_date'] ?? '' ),
			'active'           => ! empty( $data['active'] ) ? 1 : 0,
			'next_run'         => null,
		];

		$row['next_run'] = self::compute_next_run( $row );

		$wpdb->insert( DB::table_schedules(), $row );

		if ( $wpdb->last_error ) {
			return new \WP_Error( 'aifbp_db', $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;

		$existing = self::get( $id );

		if ( ! $existing ) {
			return;
		}

		$merged = array_merge( $existing, $data );
		$merged['next_run'] = self::compute_next_run( $merged );

		$wpdb->update(
			DB::table_schedules(),
			[
				'name'             => sanitize_text_field( $merged['name'] ),
				'type'             => sanitize_key( $merged['type'] ),
				'time_of_day'      => self::sanitize_time( $merged['time_of_day'] ),
				'days'             => self::sanitize_days( $merged['days'] ),
				'posts_per_run'    => max( 1, (int) $merged['posts_per_run'] ),
				'interval_minutes' => max( 5, (int) $merged['interval_minutes'] ),
				'pages'            => wp_json_encode( array_values( array_filter( array_map( 'sanitize_text_field', (array) self::decode_pages( $merged['pages'] ) ) ) ) ),
				'start_date'       => self::sanitize_date( $merged['start_date'] ),
				'end_date'         => self::sanitize_date( $merged['end_date'] ),
				'active'           => ! empty( $merged['active'] ) ? 1 : 0,
				'next_run'         => $merged['next_run'],
			],
			[ 'id' => $id ]
		);
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( DB::table_schedules(), [ 'id' => $id ] );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = DB::table_schedules();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	public static function all(): array {
		global $wpdb;
		$table = DB::table_schedules();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );

		return $rows ?: [];
	}

	public static function tick(): void {
		$now = current_time( 'mysql' );

		foreach ( self::all() as $schedule ) {
			if ( empty( $schedule['active'] ) || empty( $schedule['next_run'] ) ) {
				continue;
			}

			if ( $schedule['next_run'] > $now ) {
				continue;
			}

			if ( 'campaign' === $schedule['type'] && ( '' !== (string) $schedule['end_date'] && $schedule['end_date'] < current_time( 'Y-m-d' ) ) ) {
				self::update( (int) $schedule['id'], [ 'active' => 0 ] );
				Logger::info( 'schedule', sprintf( 'Campaign "%s" ended.', $schedule['name'] ) );

				continue;
			}

			$enqueued = self::run( $schedule );

			$next = self::compute_next_run( $schedule, strtotime( $now ) + 60 );

			global $wpdb;
			$wpdb->update(
				DB::table_schedules(),
				[ 'last_run' => $now, 'next_run' => $next ],
				[ 'id' => $schedule['id'] ]
			);

			Logger::info( 'schedule', sprintf( 'Schedule "%s" enqueued %d post(s).', $schedule['name'], $enqueued ) );
		}
	}

	public static function run( array $schedule ): int {
		$pages = (array) self::decode_pages( $schedule['pages'] );
		$pages = array_values( array_filter( $pages, fn( $pid ) => null !== Settings::page( (string) $pid ) ) );

		if ( empty( $pages ) ) {
			Logger::warning( 'schedule', sprintf( 'Schedule "%s" has no connected pages.', $schedule['name'] ) );

			return 0;
		}

		$posts    = max( 1, (int) $schedule['posts_per_run'] );
		$interval = max( 5, (int) $schedule['interval_minutes'] );
		$base     = strtotime( current_time( 'mysql' ) );
		$enqueued = 0;

		for ( $i = 0; $i < $posts; $i++ ) {
			$product = Picker::random_product();

			if ( is_wp_error( $product ) ) {
				Logger::warning( 'schedule', $product->get_error_message() );

				break;
			}

			$page_id   = $pages[ $i % count( $pages ) ];
			$page      = Settings::page( $page_id );
			$scheduled = date( 'Y-m-d H:i:s', $base + $i * $interval * MINUTE_IN_SECONDS );

			$result = QueueManager::enqueue( $product, $page_id, (string) ( $page['name'] ?? $page_id ), $scheduled, 'schedule' );

			if ( ! is_wp_error( $result ) ) {
				$enqueued++;
			}
		}

		return $enqueued;
	}

	public static function bulk_enqueue( int $count, string $start, string $end, array $pages, int $interval_minutes ): int|\WP_Error {
		$pages = array_values( array_filter( $pages, fn( $pid ) => null !== Settings::page( (string) $pid ) ) );

		if ( empty( $pages ) ) {
			return new \WP_Error( 'aifbp_pages', __( 'No connected Facebook pages selected.', 'aifbp' ) );
		}

		$interval_minutes = max( 5, $interval_minutes );

		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );

		if ( false === $start_ts || false === $end_ts || $end_ts <= $start_ts ) {
			return new \WP_Error( 'aifbp_dates', __( 'Invalid date range for bulk scheduling.', 'aifbp' ) );
		}

		$enqueued = 0;

		for ( $i = 0; $i < $count; $i++ ) {
			$product = Picker::random_product();

			if ( is_wp_error( $product ) ) {
				break;
			}

			$page_id   = $pages[ $i % count( $pages ) ];
			$page      = Settings::page( $page_id );
			$scheduled = date( 'Y-m-d H:i:s', $start_ts + $i * $interval_minutes * MINUTE_IN_SECONDS );

			if ( strtotime( $scheduled ) > $end_ts ) {
				break;
			}

			$result = QueueManager::enqueue( $product, $page_id, (string) ( $page['name'] ?? $page_id ), $scheduled, 'bulk' );

			if ( ! is_wp_error( $result ) ) {
				$enqueued++;
			}
		}

		return $enqueued;
	}

	private static function compute_next_run( array $schedule, ?int $from = null ): ?string {
		$from ??= strtotime( current_time( 'mysql' ) );
		$type = (string) $schedule['type'];

		if ( 'campaign' === $type ) {
			$start = '' !== (string) ( $schedule['start_date'] ?? '' ) ? strtotime( (string) $schedule['start_date'] . ' ' . self::sanitize_time( $schedule['time_of_day'] ) ) : $from;

			if ( false === $start ) {
				return date( 'Y-m-d H:i:s', $from + HOUR_IN_SECONDS );
			}

			$candidate = max( $start, $from + MINUTE_IN_SECONDS );

			return date( 'Y-m-d H:i:s', $candidate );
		}

		$days = array_map( 'intval', explode( ',', self::sanitize_days( $schedule['days'] ) ) );

		for ( $d = 0; $d < 8; $d++ ) {
			$day_start = strtotime( 'today +' . $d . ' days', $from );
			$weekday   = (int) date( 'w', $day_start );

			if ( ! in_array( $weekday, $days, true ) ) {
				continue;
			}

			$candidate = strtotime( date( 'Y-m-d', $day_start ) . ' ' . self::sanitize_time( $schedule['time_of_day'] ) );

			if ( $candidate > $from ) {
				return date( 'Y-m-d H:i:s', $candidate );
			}
		}

		return date( 'Y-m-d H:i:s', $from + DAY_IN_SECONDS );
	}

	private static function sanitize_time( $time ): string {
		$time = trim( (string) $time );

		return preg_match( '/^\d{1,2}:\d{2}$/', $time ) ? sprintf( '%02d:%02d', ...array_map( 'intval', explode( ':', $time ) ) ) : '10:00';
	}

	private static function sanitize_days( $days ): string {
		$valid = [ 0, 1, 2, 3, 4, 5, 6 ];
		$out   = [];

		foreach ( explode( ',', (string) $days ) as $day ) {
			$day = (int) trim( $day );

			if ( in_array( $day, $valid, true ) ) {
				$out[] = $day;
			}
		}

		return empty( $out ) ? '1,2,3,4,5,6,0' : implode( ',', array_unique( $out ) );
	}

	private static function sanitize_date( $date ): ?string {
		$date = trim( (string) $date );

		if ( '' === $date ) {
			return null;
		}

		$ts = strtotime( $date );

		return false === $ts ? null : date( 'Y-m-d', $ts );
	}

	private static function decode_pages( $pages ): array {
		if ( is_array( $pages ) ) {
			return $pages;
		}

		$decoded = json_decode( (string) $pages, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
