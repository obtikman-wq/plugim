<?php

namespace AIFB\Queue;

use AIFB\Core\DB;
use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueueManager {

	const LOCK_KEY = 'aifbp_queue_lock';

	public static function enqueue( array $product, string $page_id, string $page_name, string $scheduled_at, string $source = 'manual', array $meta = [], string $mode = 'ai', string $content_type = '' ): int|\WP_Error {
		global $wpdb;

		if ( self::has_active_for_product_page( (int) $product['id'], $page_id ) ) {
			return new \WP_Error( 'aifbp_duplicate', sprintf( 'Product "%s" is already queued or was posted recently for this page.', $product['name'] ) );
		}

		$wpdb->insert(
			DB::table_queue(),
			[
				'product_id'    => (int) $product['id'],
				'product_name'  => sanitize_text_field( $product['name'] ),
				'page_id'       => sanitize_text_field( $page_id ),
				'page_name'     => sanitize_text_field( $page_name ),
				'image_url'     => esc_url_raw( $product['image_url'] ?? '' ),
				'status'        => 'pending',
				'scheduled_at'  => $scheduled_at,
				'source'        => sanitize_key( $source ),
				'mode'          => in_array( $mode, [ 'ai', 'direct' ], true ) ? $mode : 'ai',
				'content_type'  => sanitize_key( $content_type ),
				'meta'          => wp_json_encode( $meta ),
				'created_at'    => current_time( 'mysql' ),
			]
		);

		if ( $wpdb->last_error ) {
			return new \WP_Error( 'aifbp_db', $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public static function has_active_for_product_page( int $product_id, string $page_id ): bool {
		global $wpdb;
		$table = DB::table_queue();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND page_id = %s AND status IN ('pending','processing')",
				$product_id,
				$page_id
			)
		);

		if ( $count > 0 ) {
			return true;
		}

		$cooldown = max( 0, (int) Settings::get( 'cooldown_days' ) );

		if ( 0 === $cooldown ) {
			return false;
		}

		$posted = Settings::posted_products();
		$last   = (int) ( $posted[ $product_id ] ?? 0 );

		return $last > 0 && $last > time() - $cooldown * DAY_IN_SECONDS;
	}

	public static function acquire_lock( int $ttl = 120 ): bool {
		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::LOCK_KEY, (string) getmypid() ?: '1', max( 30, $ttl ) );

		return true;
	}

	public static function release_lock(): void {
		delete_transient( self::LOCK_KEY );
	}

	public static function claim_due( int $limit = 5 ): array {
		global $wpdb;
		$table = DB::table_queue();
		$now   = current_time( 'mysql' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d",
				$now,
				$limit
			)
		);

		if ( empty( $ids ) ) {
			return [];
		}

		$items = [];

		foreach ( array_map( 'intval', $ids ) as $id ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'processing', attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
					$id
				)
			);

			if ( $updated ) {
				$item = self::get( $id );

				if ( $item ) {
					$items[] = $item;
				}
			}
		}

		return $items;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = DB::table_queue();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ?: null;
	}

	public static function mark_posted( int $id, string $fb_post_id ): void {
		global $wpdb;
		$table = DB::table_queue();

		$wpdb->update(
			$table,
			[
				'status'     => 'posted',
				'posted_at'  => current_time( 'mysql' ),
				'fb_post_id' => sanitize_text_field( $fb_post_id ),
				'last_error' => '',
			],
			[ 'id' => $id ]
		);
	}

	public static function mark_failed( int $id, string $error, int $attempts ): void {
		global $wpdb;
		$table = DB::table_queue();

		$max_attempts = max( 1, (int) Settings::get( 'max_attempts' ) );

		if ( $attempts < $max_attempts ) {
			$delay = max( 1, (int) Settings::get( 'retry_delay_min' ) );

			$wpdb->update(
				$table,
				[
					'status'       => 'pending',
					'last_error'   => sanitize_textarea_field( $error ),
					'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $delay * MINUTE_IN_SECONDS ),
				],
				[ 'id' => $id ]
			);

			return;
		}

		$wpdb->update(
			$table,
			[
				'status'     => 'failed',
				'last_error' => sanitize_textarea_field( $error ),
			],
			[ 'id' => $id ]
		);
	}

	public static function set_caption( int $id, string $caption ): bool {
		global $wpdb;
		$table = DB::table_queue();

		$meta = self::meta( $id );
		$meta['caption_override'] = wp_kses_post( $caption );

		return (bool) $wpdb->update( $table, [ 'meta' => wp_json_encode( $meta ) ], [ 'id' => $id ] );
	}

	public static function set_image( int $id, string $image_url ): bool {
		global $wpdb;
		$table = DB::table_queue();

		return (bool) $wpdb->update( $table, [ 'image_url' => esc_url_raw( $image_url ) ], [ 'id' => $id ] );
	}

	public static function set_product( int $id, int $product_id, string $product_name, string $image_url = '' ): bool {
		global $wpdb;
		$table = DB::table_queue();

		return (bool) $wpdb->update(
			$table,
			[
				'product_id'   => $product_id,
				'product_name' => sanitize_text_field( $product_name ),
				'image_url'    => esc_url_raw( $image_url ),
			],
			[ 'id' => $id ]
		);
	}

	public static function reschedule( int $id, string $scheduled_at ): bool {
		global $wpdb;
		$table = DB::table_queue();

		return (bool) $wpdb->update(
			$table,
			[ 'scheduled_at' => $scheduled_at, 'status' => 'pending', 'last_error' => '' ],
			[ 'id' => $id, 'status' => [ 'pending', 'failed', 'cancelled', 'processing' ] ]
		);
	}

	public static function duplicate( int $id, ?string $scheduled_at = null ): int|\WP_Error {
		$item = self::get( $id );

		if ( ! $item ) {
			return new \WP_Error( 'aifbp_not_found', __( 'Queue item not found.', 'aifbp' ) );
		}

		$meta = self::meta( $id );
		unset( $meta['caption_override'] );

		return self::enqueue(
			[
				'id'        => (int) $item['product_id'],
				'name'      => (string) $item['product_name'],
				'image_url' => (string) $item['image_url'],
			],
			(string) $item['page_id'],
			(string) $item['page_name'],
			$scheduled_at ?? date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + HOUR_IN_SECONDS ),
			'duplicate',
			$meta,
			(string) ( $item['mode'] ?? 'ai' ),
			(string) ( $item['content_type'] ?? '' )
		);
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$table = DB::table_queue();

		return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	public static function meta( int $id ): array {
		$item = self::get( $id );
		$meta = json_decode( (string) ( $item['meta'] ?? '{}' ), true );

		return is_array( $meta ) ? $meta : [];
	}

	public static function cancel( int $id ): bool {
		global $wpdb;
		$table = DB::table_queue();

		return (bool) $wpdb->update( $table, [ 'status' => 'cancelled' ], [ 'id' => $id, 'status' => 'pending' ] );
	}

	public static function counts(): array {
		global $wpdb;
		$table = DB::table_queue();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) ?: [];

		$counts = [
			'pending'    => 0,
			'processing' => 0,
			'posted'     => 0,
			'failed'     => 0,
			'cancelled'  => 0,
			'draft'      => 0,
		];

		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}

	public static function mode_counts(): array {
		global $wpdb;
		$table = DB::table_queue();

		$rows = $wpdb->get_results( "SELECT mode, COUNT(*) AS n FROM {$table} WHERE status = 'posted' GROUP BY mode", ARRAY_A ) ?: [];

		$counts = [ 'ai' => 0, 'direct' => 0 ];

		foreach ( $rows as $row ) {
			$mode = (string) $row['mode'];

			if ( isset( $counts[ $mode ] ) ) {
				$counts[ $mode ] = (int) $row['n'];
			}
		}

		return $counts;
	}

	public static function list( string $status = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = DB::table_queue();

		if ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}

		return $rows ?: [];
	}

	public static function upcoming( int $limit = 100 ): array {
		global $wpdb;
		$table = DB::table_queue();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_name, page_name, status, scheduled_at, source, mode FROM {$table} WHERE status IN ('pending','processing') ORDER BY scheduled_at ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	public static function next_scheduled(): ?string {
		global $wpdb;
		$table = DB::table_queue();

		$value = $wpdb->get_var( "SELECT MIN(scheduled_at) FROM {$table} WHERE status = 'pending'" );

		return $value ?: null;
	}

	public static function delete_posted( int $days = 90 ): void {
		global $wpdb;
		$table = DB::table_queue();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'posted' AND posted_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$days
			)
		);
	}
}
