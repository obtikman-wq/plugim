<?php

namespace AIFB\AI;

use AIFB\Utils\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractProvider implements ProviderInterface {

	protected function chat( array $messages, float $temperature = 0.8 ): array|\WP_Error {
		Usage::track( 'ai' );

		return new \WP_Error( 'aifbp_ai_abstract', 'Not implemented.' );
	}

	protected static function extract_json( string $content ): array|\WP_Error {
		$content = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $content ) );

		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded ) ) {
			$start = strpos( $content, '{' );
			$end   = strrpos( $content, '}' );

			if ( false !== $start && false !== $end && $end > $start ) {
				$decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'aifbp_ai_parse', 'Could not parse AI response as JSON.' );
		}

		return $decoded;
	}

	protected static function product_block( array $product ): string {
		$lines = [ 'Product name: ' . $product['name'] ];

		if ( ! empty( $product['regular_price'] ) ) {
			$lines[] = 'Regular price: ' . $product['regular_price'] . ' ' . ( $product['currency'] ?? '' );
		}

		if ( ! empty( $product['sale_price'] ) ) {
			$lines[] = 'Sale price (current): ' . $product['sale_price'] . ' ' . ( $product['currency'] ?? '' );
		}

		if ( ! empty( $product['categories'] ) ) {
			$lines[] = 'Categories: ' . implode( ', ', $product['categories'] );
		}

		if ( ! empty( $product['tags'] ) ) {
			$lines[] = 'Tags: ' . implode( ', ', $product['tags'] );
		}

		if ( ! empty( $product['url'] ) ) {
			$lines[] = 'Product URL: ' . $product['url'];
		}

		if ( ! empty( $product['image_description'] ) ) {
			$lines[] = 'Product image description (AI vision): ' . $product['image_description'];
		}

		return implode( "\n", $lines );
	}

	protected static function caption_instruction( array $options ): string {
		$lang = $options['language'] ?? 'both';

		$language_line = match ( $lang ) {
			'bn'    => 'Write the captions in Bangla (Bengali).',
			'en'    => 'Write the captions in English.',
			default => 'Write every caption twice: once in Bangla (Bengali) and once in English.',
		};

		$variations = max( 0, min( 5, (int) ( $options['variations'] ?? 2 ) ) );

		return "You are an expert e-commerce social media copywriter for Facebook.\n"
			. $language_line . "\n"
			. "Highlight the price and any discount. Include product-specific hashtags and a clear call-to-action.\n"
			. "Return ONLY valid minified JSON (no markdown fences, no extra text) with this exact shape:\n"
			. '{"short_bn":"catchy Bangla caption, max 150 chars, emojis allowed",'
			. '"long_bn":"selling description in Bangla, 2-4 sentences",'
			. '"short_en":"catchy English caption, max 150 chars, emojis allowed",'
			. '"long_en":"selling description in English, 2-4 sentences",'
			. '"hashtags":["5-10 product-specific hashtags without spaces"],'
			. '"cta":"one call-to-action line",'
			. '"variations":[{"short_bn":"","long_bn":"","short_en":"","long_en"} x ' . $variations . ']}';
	}

	protected static function build_caption_payload( array $decoded ): array {
		$variations = [];

		foreach ( (array) ( $decoded['variations'] ?? [] ) as $variation ) {
			if ( is_array( $variation ) && ! empty( $variation['short_bn'] ?? $variation['short_en'] ?? null ) ) {
				$variations[] = [
					'short_bn' => sanitize_text_field( (string) ( $variation['short_bn'] ?? '' ) ),
					'long_bn'  => sanitize_textarea_field( (string) ( $variation['long_bn'] ?? '' ) ),
					'short_en' => sanitize_text_field( (string) ( $variation['short_en'] ?? '' ) ),
					'long_en'  => sanitize_textarea_field( (string) ( $variation['long_en'] ?? '' ) ),
				];
			}
		}

		$hashtags = [];

		foreach ( (array) ( $decoded['hashtags'] ?? [] ) as $tag ) {
			$tag = trim( (string) preg_replace( '/[^\w\x{0980}-\x{09FF}]/u', '', (string) $tag ) );

			if ( '' !== $tag ) {
				$hashtags[] = '#' . ltrim( $tag, '#' );
			}
		}

		return [
			'short_bn'   => sanitize_text_field( (string) ( $decoded['short_bn'] ?? '' ) ),
			'long_bn'    => sanitize_textarea_field( (string) ( $decoded['long_bn'] ?? '' ) ),
			'short_en'   => sanitize_text_field( (string) ( $decoded['short_en'] ?? '' ) ),
			'long_en'    => sanitize_textarea_field( (string) ( $decoded['long_en'] ?? '' ) ),
			'hashtags'   => array_slice( $hashtags, 0, 12 ),
			'cta'        => sanitize_text_field( (string) ( $decoded['cta'] ?? '' ) ),
			'variations' => $variations,
		];
	}
}
