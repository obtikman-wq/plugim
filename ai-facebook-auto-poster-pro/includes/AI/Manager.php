<?php

namespace AIFB\AI;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Manager {

	public static function provider(): ProviderInterface|\WP_Error {
		$s = Settings::all();

		$provider = match ( $s['ai_provider'] ) {
			'gemini' => new GeminiProvider( $s['gemini_key'], $s['gemini_model'] ),
			'custom' => new CustomProvider(),
			default  => new OpenAIProvider( $s['openai_base'], $s['openai_key'], $s['openai_model'] ),
		};

		if ( ! $provider->is_configured() ) {
			return new \WP_Error( 'aifbp_ai', sprintf( '%s API key is not set.', $provider->label() ) );
		}

		return $provider;
	}

	public static function captions( array $product, ?array $options = null ): array|\WP_Error {
		$s        = Settings::all();
		$options ??= [
			'language'   => $s['caption_language'],
			'variations' => $s['variations'],
		];

		if ( ! empty( $s['ai_image_understand'] ) && ! empty( $product['image_url'] ) && empty( $product['image_description'] ) ) {
			$provider = self::provider();

			if ( ! is_wp_error( $provider ) ) {
				$analysis = $provider->analyze_image( $product['image_url'] );

				if ( ! is_wp_error( $analysis ) ) {
					$product['image_description'] = $analysis;
				}
			}
		}

		$provider = self::provider();

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		return $provider->captions( $product, $options );
	}

	public static function rewrite( string $text, string $language = 'Bangla' ): string|\WP_Error {
		$provider = self::provider();

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		return $provider->rewrite( $text, $language );
	}

	public static function generate_promo_image( array $product ): string|\WP_Error {
		$provider = self::provider();

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$price = '' !== ( $product['sale_price'] ?? '' ) ? $product['sale_price'] : ( $product['regular_price'] ?? '' );

		$prompt = sprintf(
			'Clean professional e-commerce promotional social media image featuring: %s. Vibrant colors, studio lighting, space for text overlay, no text in the image, square format.',
			$product['name'] . ( '' !== $price ? ' priced ' . $price . ' ' . ( $product['currency'] ?? '' ) : '' )
		);

		return $provider->generate_image( $prompt );
	}

	public static function compose( array $captions, array $product, ?array $settings = null, string $variation_index = '' ): string {
		$s        = $settings ?? Settings::all();
		$language = $s['caption_language'];

		$short = 'en' === $language ? ( $captions['short_en'] ?? '' ) : ( $captions['short_bn'] ?? '' );
		$long  = 'en' === $language ? ( $captions['long_en'] ?? '' ) : ( $captions['long_bn'] ?? '' );

		if ( 'both' === $language ) {
			$long  = trim( ( $captions['long_bn'] ?? '' ) . "\n\n" . ( $captions['long_en'] ?? '' ) );
			$short = $captions['short_bn'] ?? '';
		}

		if ( '' !== $variation_index && isset( $captions['variations'][ (int) $variation_index ] ) ) {
			$v = $captions['variations'][ (int) $variation_index ];

			$short = 'en' === $language ? ( $v['short_en'] ?? $short ) : ( $v['short_bn'] ?? $short );
			$long  = 'en' === $language ? ( $v['long_en'] ?? $long ) : ( $v['long_bn'] ?? $long );

			if ( 'both' === $language ) {
				$long = trim( ( $v['long_bn'] ?? '' ) . "\n\n" . ( $v['long_en'] ?? '' ) );
			}
		}

		$parts = [];

		if ( '' !== $short ) {
			$parts[] = $short;
		}

		if ( ! empty( $s['include_price'] ) ) {
			$currency = $s['currency'];

			if ( ! empty( $product['sale_price'] ) ) {
				$parts[] = sprintf( '💰 %s %s (was %s %s)', $product['sale_price'], $currency, $product['regular_price'], $currency );
			} elseif ( ! empty( $product['regular_price'] ) ) {
				$parts[] = sprintf( '💰 Price: %s %s', $product['regular_price'], $currency );
			}
		}

		if ( '' !== $long ) {
			$parts[] = $long;
		}

		if ( ! empty( $s['include_cta'] ) && ! empty( $captions['cta'] ) ) {
			$parts[] = $captions['cta'];
		}

		if ( ! empty( $s['include_hashtags'] ) && ! empty( $captions['hashtags'] ) ) {
			$parts[] = implode( ' ', $captions['hashtags'] );
		}

		if ( ! empty( $s['include_link'] ) && ! empty( $product['url'] ) ) {
			$parts[] = $product['url'];
		}

		return trim( implode( "\n\n", array_filter( $parts ) ) );
	}
}
