<?php

namespace AIFB\Product;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Templates {

	public static function all(): array {
		$raw   = (string) Settings::get( 'templates' );
		$items = [];

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
			$line = trim( $line );

			if ( '' === $line || ! str_contains( $line, '|' ) ) {
				continue;
			}

			[ $name, $template ] = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );

			if ( '' !== $name && '' !== $template ) {
				$items[ $name ] = $template;
			}
		}

		return $items;
	}

	public static function apply( string $name, array $product, array $captions ): string|\WP_Error {
		$templates = self::all();

		if ( ! isset( $templates[ $name ] ) ) {
			return new \WP_Error( 'aifbp_template', __( 'Template not found.', 'aifbp' ) );
		}

		$price = '' !== ( $product['sale_price'] ?? '' ) && '' !== $product['sale_price']
			? $product['sale_price'] . ' ' . $product['currency']
			: ( $product['regular_price'] ?? '' ) . ' ' . $product['currency'];

		$replacements = [
			'{name}'       => $product['name'],
			'{price}'      => $price,
			'{link}'       => $product['url'] ?? '',
			'{short_bn}'   => $captions['short_bn'] ?? '',
			'{short_en}'   => $captions['short_en'] ?? '',
			'{long_bn}'    => $captions['long_bn'] ?? '',
			'{long_en}'    => $captions['long_en'] ?? '',
			'{cta}'        => $captions['cta'] ?? '',
			'{hashtags}'   => implode( ' ', $captions['hashtags'] ?? [] ),
		];

		return trim( str_replace( array_keys( $replacements ), array_values( $replacements ), $templates[ $name ] ) );
	}
}
