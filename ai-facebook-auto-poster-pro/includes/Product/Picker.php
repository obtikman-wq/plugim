<?php

namespace AIFB\Product;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Picker {

	public static function random_product( ?int $only_id = null ): array|\WP_Error {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error( 'aifbp_products', __( 'WooCommerce is not active.', 'aifbp' ) );
		}

		if ( null !== $only_id ) {
			$product = wc_get_product( $only_id );

			if ( ! $product || 'publish' !== $product->get_status() ) {
				return new \WP_Error( 'aifbp_products', __( 'Product not found or not published.', 'aifbp' ) );
			}

			return self::map( $product );
		}

		$cooldown = max( 0, (int) Settings::get( 'cooldown_days' ) );
		$posted   = Settings::posted_products();
		$cutoff   = $cooldown > 0 ? time() - $cooldown * DAY_IN_SECONDS : 0;

		$found = wc_get_products(
			[
				'status'  => 'publish',
				'limit'   => 200,
				'orderby' => 'rand',
			]
		);

		if ( empty( $found ) ) {
			return new \WP_Error( 'aifbp_products', __( 'No published products found in WooCommerce.', 'aifbp' ) );
		}

		$candidates = [];

		foreach ( $found as $product ) {
			$last = (int) ( $posted[ $product->get_id() ] ?? 0 );

			if ( 0 === $last || $last < $cutoff ) {
				$candidates[] = $product;
			}
		}

		$pool = ! empty( $candidates ) ? $candidates : $found;

		return self::map( $pool[ array_rand( $pool ) ] );
	}

	private static function map( \WC_Product $product ): array {
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';

		$gallery = [];

		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$gurl = wp_get_attachment_url( $gid );

			if ( $gurl ) {
				$gallery[] = $gurl;
			}
		}

		if ( ! empty( $gallery ) ) {
			$image_url = $gallery[ array_rand( $gallery ) ];
		}

		$categories = [];
		$tags       = [];

		foreach ( $product->get_category_ids() as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );

			if ( $term && ! is_wp_error( $term ) ) {
				$categories[] = $term->name;
			}
		}

		foreach ( $product->get_tag_ids() as $term_id ) {
			$term = get_term( $term_id, 'product_tag' );

			if ( $term && ! is_wp_error( $term ) ) {
				$tags[] = $term->name;
			}
		}

		return [
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'regular_price' => (string) $product->get_regular_price(),
			'sale_price'    => (string) $product->get_sale_price(),
			'currency'      => (string) Settings::get( 'currency' ),
			'image_url'     => $image_url,
			'url'           => $product->get_permalink(),
			'categories'    => $categories,
			'tags'          => $tags,
		];
	}
}
