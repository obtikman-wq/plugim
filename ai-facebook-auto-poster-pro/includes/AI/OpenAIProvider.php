<?php

namespace AIFB\AI;

use AIFB\Utils\Usage;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenAIProvider extends AbstractProvider {

	public function __construct(
		private string $base,
		private string $key,
		private string $model
	) {}

	public function id(): string {
		return 'openai';
	}

	public function label(): string {
		return __( 'OpenAI', 'aifbp' );
	}

	public function is_configured(): bool {
		return '' !== $this->key;
	}

	private function endpoint( string $path ): string {
		return untrailingslashit( $this->base ) . $path;
	}

	protected function chat( array $messages, float $temperature = 0.8 ): array|\WP_Error {
		Usage::track( 'ai' );

		$response = wp_remote_post(
			$this->endpoint( '/chat/completions' ),
			[
				'timeout' => 90,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'       => $this->model,
						'temperature' => $temperature,
						'messages'    => $messages,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'ai_openai', $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			$message = (string) $body['error']['message'];
			Logger::error( 'ai_openai', $message );
			return new \WP_Error( 'aifbp_ai', $message );
		}

		if ( 200 !== $code || ! isset( $body['choices'][0]['message']['content'] ) ) {
			$message = sprintf( 'OpenAI API HTTP %d', $code );
			Logger::error( 'ai_openai', $message );
			return new \WP_Error( 'aifbp_ai', $message );
		}

		return $body;
	}

	public function ask( string $system, string $user, float $temperature = 0.5 ): string|\WP_Error {
		$result = $this->chat(
			[
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => $user ],
			],
			$temperature
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return trim( (string) ( $result['choices'][0]['message']['content'] ?? '' ) );
	}

	public function captions( array $product, array $options ): array|\WP_Error {
		$response = $this->chat(
			[
				[
					'role'    => 'system',
					'content' => 'You write high-converting Facebook product posts and always reply with strict JSON only.',
				],
				[
					'role'    => 'user',
					'content' => self::product_block( $product ) . "\n\n" . self::caption_instruction( $options ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = self::extract_json( (string) $response['choices'][0]['message']['content'] );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return self::build_caption_payload( $decoded );
	}

	public function rewrite( string $text, string $language ): string|\WP_Error {
		$response = $this->chat(
			[
				[
					'role'    => 'user',
					'content' => sprintf( "Rewrite this Facebook product caption to be more engaging. Language: %s. Return ONLY the rewritten caption text, nothing else.\n\n%s", $language, $text ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return sanitize_textarea_field( trim( (string) $response['choices'][0]['message']['content'] ) );
	}

	public function analyze_image( string $image_url, string $prompt = '' ): string|\WP_Error {
		$response = $this->chat(
			[
				[
					'role'    => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => $prompt ?: 'Describe this product photo for social media marketing: product type, colors, style, notable details.',
						],
						[
							'type'      => 'image_url',
							'image_url' => [ 'url' => $image_url ],
						],
					],
				],
			],
			0.4
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return sanitize_textarea_field( trim( (string) $response['choices'][0]['message']['content'] ) );
	}

	public function generate_image( string $prompt ): string|\WP_Error {
		Usage::track( 'ai' );

		$response = wp_remote_post(
			$this->endpoint( '/images/generations' ),
			[
				'timeout' => 120,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'  => 'dall-e-3',
						'prompt' => $prompt,
						'n'      => 1,
						'size'   => '1024x1024',
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			return new \WP_Error( 'aifbp_ai', (string) $body['error']['message'] );
		}

		$url = (string) ( $body['data'][0]['url'] ?? '' );

		if ( '' === $url ) {
			return new \WP_Error( 'aifbp_ai', 'Image generation returned no URL.' );
		}

		return $url;
	}
}
