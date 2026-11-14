<?php

namespace AIFB\AI;

use AIFB\Utils\Usage;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GeminiProvider extends AbstractProvider {

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	public function __construct(
		private string $key,
		private string $model
	) {}

	public function id(): string {
		return 'gemini';
	}

	public function label(): string {
		return __( 'Google Gemini', 'aifbp' );
	}

	public function is_configured(): bool {
		return '' !== $this->key;
	}

	private function generate( array $parts, float $temperature = 0.8, bool $json_mode = true ): array|\WP_Error {
		Usage::track( 'ai' );

		$body = [
			'contents'         => [ [ 'parts' => $parts ] ],
			'generationConfig' => [
				'temperature' => $temperature,
			],
		];

		if ( $json_mode ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}

		$response = wp_remote_post(
			sprintf( '%s/models/%s:generateContent?key=%s', self::BASE, rawurlencode( $this->model ), rawurlencode( $this->key ) ),
			[
				'timeout' => 90,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'ai_gemini', $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['error']['message'] ) ) {
			$message = (string) $data['error']['message'];
			Logger::error( 'ai_gemini', $message );
			return new \WP_Error( 'aifbp_ai', $message );
		}

		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( 200 !== $code || null === $text ) {
			$message = sprintf( 'Gemini API HTTP %d', $code );
			Logger::error( 'ai_gemini', $message );
			return new \WP_Error( 'aifbp_ai', $message );
		}

		return [ 'text' => (string) $text ];
	}

	public function ask( string $system, string $user, float $temperature = 0.5 ): string|\WP_Error {
		$result = $this->generate( [ [ 'text' => $system . "\n\n" . $user ] ], $temperature, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return trim( (string) $result['text'] );
	}

	public function captions( array $product, array $options ): array|\WP_Error {
		$response = $this->generate(
			[
				[ 'text' => self::product_block( $product ) . "\n\n" . self::caption_instruction( $options ) ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = self::extract_json( $response['text'] );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return self::build_caption_payload( $decoded );
	}

	public function rewrite( string $text, string $language ): string|\WP_Error {
		$response = $this->generate(
			[
				[ 'text' => sprintf( "Rewrite this Facebook product caption to be more engaging. Language: %s. Return ONLY the rewritten caption text, nothing else.\n\n%s", $language, $text ) ],
			],
			0.8,
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return sanitize_textarea_field( trim( $response['text'] ) );
	}

	public function analyze_image( string $image_url, string $prompt = '' ): string|\WP_Error {
		$fetched = wp_remote_get( $image_url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		$bytes = wp_remote_retrieve_body( $fetched );

		if ( '' === $bytes ) {
			return new \WP_Error( 'aifbp_ai', 'Could not download product image for analysis.' );
		}

		$content_type = (string) wp_remote_retrieve_header( $fetched, 'content-type' );
		$mime         = str_starts_with( $content_type, 'image/' ) ? $content_type : 'image/jpeg';

		$response = $this->generate(
			[
				[ 'text' => $prompt ?: 'Describe this product photo for social media marketing: product type, colors, style, notable details.' ],
				[
					'inline_data' => [
						'mime_type' => $mime,
						'data'      => base64_encode( $bytes ),
					],
				],
			],
			0.4,
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return sanitize_textarea_field( trim( $response['text'] ) );
	}

	public function generate_image( string $prompt ): string|\WP_Error {
		return new \WP_Error( 'aifbp_ai', __( 'Image generation is not available for the Gemini provider. Use OpenAI for AI image generation.', 'aifbp' ) );
	}
}
