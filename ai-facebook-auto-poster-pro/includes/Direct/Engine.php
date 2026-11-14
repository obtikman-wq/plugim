<?php

namespace AIFB\Direct;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Engine {

	public const FORMATS = [
		'short'                 => 'Short description only',
		'long'                  => 'Long description only',
		'short_long'            => 'Short + Long description',
		'name_desc_price'       => 'Name + Description + Price',
		'name_image_price_link' => 'Name + Image + Price + Link',
		'custom'                => 'Custom template',
	];

	public static function build( array $product, ?string $format = null, ?string $custom_template = null ): string {
		$s      = Settings::all();
		$format ??= $s['direct_format'];

		$hashtags = trim( (string) $s['direct_hashtags'] );
		$cta      = trim( (string) $s['direct_cta'] );

		$short = trim( wp_strip_all_tags( (string) ( $product['short_description'] ?? '' ) ) );
		$long  = trim( wp_strip_all_tags( (string) ( $product['long_description'] ?? '' ) ) );

		$price_lines = self::price_lines( $product );
		$parts       = [];

		switch ( $format ) {
			case 'short':
				$parts[] = $short;
				break;

			case 'long':
				$parts[] = $long;
				break;

			case 'short_long':
				$parts[] = $short;
				$parts[] = $long;
				break;

			case 'name_desc_price':
				$parts[] = '🔥 ' . $product['name'];
				$parts[] = '' !== $short ? $short : $long;
				$parts[] = $price_lines;
				break;

			case 'name_image_price_link':
				$parts[] = '🔥 ' . $product['name'];
				$parts[] = $price_lines;
				break;

			case 'custom':
				$template = null !== $custom_template && '' !== trim( $custom_template ) ? $custom_template : (string) $s['direct_template'];
				$parts[]  = self::render_template( $template, $product );
				break;
		}

		if ( ! empty( $s['include_cta'] ) && '' !== $cta ) {
			$parts[] = '👉 ' . $cta;
		}

		if ( ! empty( $s['include_hashtags'] ) && '' !== $hashtags ) {
			$parts[] = $hashtags;
		}

		if ( ! empty( $s['include_link'] ) && ! empty( $product['url'] ) && ! str_contains( implode( "\n", $parts ), (string) $product['url'] ) ) {
			$parts[] = (string) $product['url'];
		}

		return trim( implode( "\n\n", array_filter( array_map( 'trim', $parts ) ) ) );
	}

	public static function price_lines( array $product ): string {
		$currency = $product['currency'] ?? 'Tk';
		$lines    = [];

		if ( ! empty( $product['regular_price'] ) ) {
			$lines[] = sprintf( '💰 Regular Price: %s %s', $product['regular_price'], $currency );
		}

		if ( ! empty( $product['sale_price'] ) ) {
			$lines[] = sprintf( '🔥 Offer Price: %s %s', $product['sale_price'], $currency );
		}

		$discount = self::discount_percent( $product );

		if ( null !== $discount ) {
			$lines[] = sprintf( '🏷️ %d%% OFF', $discount );
		}

		return implode( "\n", $lines );
	}

	public static function discount_percent( array $product ): ?int {
		$regular = (float) ( $product['regular_price'] ?? 0 );
		$sale    = (float) ( $product['sale_price'] ?? 0 );

		if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) {
			return null;
		}

		return (int) round( ( $regular - $sale ) / $regular * 100 );
	}

	public static function render_template( string $template, array $product ): string {
		$discount = self::discount_percent( $product );

		$vars = [
			'{product_name}'      => (string) ( $product['name'] ?? '' ),
			'{short_description}' => trim( wp_strip_all_tags( (string) ( $product['short_description'] ?? '' ) ) ),
			'{long_description}'  => trim( wp_strip_all_tags( (string) ( $product['long_description'] ?? '' ) ) ),
			'{regular_price}'     => (string) ( $product['regular_price'] ?? '' ),
			'{sale_price}'        => (string) ( $product['sale_price'] ?? '' ),
			'{currency}'          => (string) ( $product['currency'] ?? 'Tk' ),
			'{discount_percent}'  => null !== $discount ? (string) $discount . '%' : '',
			'{product_url}'       => (string) ( $product['url'] ?? '' ),
			'{stock_status}'      => (string) ( $product['stock_status'] ?? '' ),
			'{sku}'               => (string) ( $product['sku'] ?? '' ),
			'{category}'          => implode( ', ', (array) ( $product['categories'] ?? [] ) ),
			'{hashtags}'          => trim( (string) Settings::get( 'direct_hashtags' ) ),
			'{cta}'               => trim( (string) Settings::get( 'direct_cta' ) ),
		];

		return trim( str_replace( array_keys( $vars ), array_values( $vars ), $template ) );
	}

	public static function variables_doc(): array {
		return [
			'{product_name}', '{short_description}', '{long_description}', '{regular_price}',
			'{sale_price}', '{currency}', '{discount_percent}', '{product_url}', '{stock_status}',
			'{sku}', '{category}', '{hashtags}', '{cta}',
		];
	}
}
