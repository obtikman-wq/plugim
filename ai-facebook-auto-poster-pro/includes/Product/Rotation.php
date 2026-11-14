<?php

namespace AIFB\Product;

use AIFB\Core\DB;
use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rotation {

	public const METHODS = [
		'random'       => 'Random product',
		'latest'       => 'Latest product',
		'best_selling' => 'Best selling',
		'sale_first'   => 'Sale products first',
		'featured'     => 'Featured product',
		'category'     => 'Category based',
		'manual'       => 'Manually selected',
	];

	public static function pick( array $args = [] ): array|\WP_Error {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error( 'aifbp_products', __( 'WooCommerce is not active.', 'aifbp' ) );
		}

		$method    = (string) ( $args['selection'] ?? 'random' );
		$page_id   = (string) ( $args['page_id'] ?? '' );
		$schedule  = (int) ( $args['schedule_id'] ?? 0 );
		$mode      = (string) ( $args['mode'] ?? 'ai' );
		$manual_id = (int) ( $args['product_id'] ?? 0 );

		if ( 'manual' === $method && $manual_id > 0 ) {
			$product = Picker::random_product( $manual_id );

			if ( is_wp_error( $product ) ) {
				return $product;
			}

			return self::with_extras( $product );
		}

		$query = [
			'status'  => 'publish',
			'limit'   => 200,
			'orderby' => 'date',
			'order'   => 'DESC',
		];

		$categories = array_filter( array_map( 'absint', (array) ( $args['categories'] ?? [] ) ) );

		if ( ! empty( $categories ) ) {
			$query['category'] = $categories;
			$query['orderby']  = 'rand';
		}

		switch ( $method ) {
			case 'best_selling':
				$query['orderby'] = 'meta_value_num';
				$query['meta_key'] = 'total_sales';
				$query['order']   = 'DESC';
				break;

			case 'featured':
				$query['featured'] = 'yes';
				break;

			case 'category':
			case 'random':
			case 'sale_first':
				$query['orderby'] = 'rand';
				break;
		}

		$products = wc_get_products( $query );

		if ( empty( $products ) && ! empty( $categories ) ) {
			$products = wc_get_products( [ 'status' => 'publish', 'limit' => 200, 'orderby' => 'rand' ] );
		}

		if ( empty( $products ) ) {
			return new \WP_Error( 'aifbp_products', __( 'No published products found in WooCommerce.', 'aifbp' ) );
		}

		if ( 'sale_first' === $method ) {
			$on_sale = array_values( array_filter( $products, fn( $p ) => (float) $p->get_sale_price() > 0 ) );

			if ( ! empty( $on_sale ) ) {
				$products = array_values( array_merge( $on_sale, array_diff_key( $products, $on_sale ) ) );
			}
		}

		$s = Settings::all();

		$skip_oos   = ! empty( $args['skip_out_of_stock'] ) ? (int) $args['skip_out_of_stock'] : (int) $s['skip_out_of_stock'];
		$cooldown   = (int) ( $args['repeat_after_days'] ?? $s['repeat_after_days'] );
		$cooldown   = max( 0, $cooldown );
		$cutoff     = $cooldown > 0 ? time() - $cooldown * DAY_IN_SECONDS : 0;

		$eligible  = [];
		$secondary = [];

		foreach ( $products as $product ) {
			if ( $skip_oos && $product->get_stock_status() && 'instock' !== $product->get_stock_status() ) {
				continue;
			}

			$secondary[] = $product;

			if ( self::recently_posted( $product->get_id(), $page_id, $schedule, $mode, $cutoff ) ) {
				continue;
			}

			$eligible[] = $product;
		}

		$pool = ! empty( $eligible ) ? $eligible : ( ! empty( $secondary ) ? $secondary : $products );

		$chosen = $pool[ array_rand( $pool ) ];

		$product = Picker::random_product( $chosen->get_id() );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return self::with_extras( $product );
	}

	public static function recently_posted( int $product_id, string $page_id, int $schedule_id, string $mode, int $cutoff ): bool {
		global $wpdb;
		$table = DB::table_rotation();

		if ( $cutoff > 0 ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND posted_at > %s",
					$product_id,
					gmdate( 'Y-m-d H:i:s', $cutoff )
				)
			);

			if ( $count > 0 ) {
				return true;
			}
		}

		if ( '' !== $page_id && $schedule_id > 0 ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND page_id = %s AND schedule_id = %d AND mode = %s AND posted_at > DATE_SUB(%s, INTERVAL 1 DAY)",
					$product_id,
					$page_id,
					$schedule_id,
					$mode,
					current_time( 'mysql' )
				)
			);

			if ( $count > 0 ) {
				return true;
			}
		}

		return false;
	}

	public static function record( int $product_id, string $page_id, int $schedule_id, string $mode ): void {
		global $wpdb;

		$wpdb->insert(
			DB::table_rotation(),
			[
				'product_id'  => $product_id,
				'page_id'     => sanitize_text_field( $page_id ),
				'schedule_id' => $schedule_id,
				'mode'        => sanitize_key( $mode ),
				'posted_at'   => current_time( 'mysql' ),
			]
		);
	}

	private static function with_extras( array $product ): array {
		if ( class_exists( 'WooCommerce' ) ) {
			$wc = wc_get_product( $product['id'] );

			if ( $wc ) {
				$product['short_description'] = $wc->get_short_description();
				$product['long_description']  = $wc->get_description();
				$product['stock_status']      = $wc->get_stock_status();
				$product['sku']               = $wc->get_sku();
			}
		}

		return $product;
	}
}
