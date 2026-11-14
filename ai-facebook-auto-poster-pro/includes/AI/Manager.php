<?php

namespace AIFB\AI;

use AIFB\Utils\Logger;
use AIFB\Utils\Settings;
use AIFB\Utils\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Manager {
	public const PRIORITY = [ 'gemini', 'groq', 'openrouter', 'custom', 'openai' ];

	public static function make( string $id ): ProviderInterface|\WP_Error {
		$s = Settings::all();

		$provider = match ( $id ) {
			'gemini'     => new GeminiProvider( (string) $s['gemini_key'], (string) $s['gemini_model'] ),
			'groq'       => new GroqProvider(),
			'openrouter' => new OpenRouterProvider(),
			'custom'     => new CustomProvider(),
			default      => new OpenAIProvider( (string) $s['openai_base'], (string) $s['openai_key'], (string) $s['openai_model'] ),
		};

		if ( ! $provider->is_configured() ) {
			return new \WP_Error( 'aifbp_ai', sprintf( '%s API key is not set.', $provider->label() ) );
		}

		return $provider;
	}

	public static function enabled( string $id ): bool {
		return (int) Settings::get( 'provider_enabled_' . $id ) === 1;
	}

	public static function eligible(): array {
		$pool = [];

		foreach ( self::PRIORITY as $id ) {
			if ( self::enabled( $id ) && ! is_wp_error( self::make( $id ) ) && ! get_transient( 'aifbp_rl_' . $id ) ) {
				$pool[] = $id;
			}
		}

		return $pool;
	}

	public static function limit_error(): \WP_Error|null {
		$s     = Settings::all();
		$day   = Usage::today( 'ai' );
		$month = Usage::month()['ai'];

		if ( (int) $s['ai_daily_limit'] > 0 && $day >= (int) $s['ai_daily_limit'] ) {
			return new \WP_Error(
				'aifbp_ai_limit',
				sprintf( 'Daily AI usage limit reached (%d/%d requests). AI generation is paused until you raise the limit in AI Settings.', $day, (int) $s['ai_daily_limit'] )
			);
		}

		if ( (int) $s['ai_monthly_limit'] > 0 && $month >= (int) $s['ai_monthly_limit'] ) {
			return new \WP_Error(
				'aifbp_ai_limit',
				sprintf( 'Monthly AI usage limit reached (%d/%d requests). AI generation is paused until you raise the limit in AI Settings.', $month, (int) $s['ai_monthly_limit'] )
			);
		}

		return null;
	}

	private static function notify_limit( \WP_Error $error ): void {
		Logger::error( 'ai_limit', $error->get_error_message() );

		if ( get_transient( 'aifbp_limit_notified' ) ) {
			return;
		}

		set_transient( 'aifbp_limit_notified', 1, 6 * HOUR_IN_SECONDS );

		if ( (int) Settings::get( 'usage_alerts' ) === 1 ) {
			wp_mail(
				get_option( 'admin_email' ),
				__( 'AI Facebook Poster: AI usage limit reached', 'aifbp' ),
				$error->get_error_message()
			);
		}
	}

	private static function call( callable $task ): mixed {
		$limit = self::limit_error();

		if ( null !== $limit ) {
			self::notify_limit( $limit );

			return $limit;
		}

		$pool     = self::eligible();
		$last_err = null;

		if ( empty( $pool ) ) {
			return new \WP_Error( 'aifbp_ai', __( 'No AI provider is enabled and configured. Configure Gemini, Groq, OpenRouter, or a custom endpoint in AI Settings — or use Direct posting mode.', 'aifbp' ) );
		}

		if ( ! (int) Settings::get( 'ai_fallback' ) ) {
			$pool = [ $pool[0] ];
		}

		foreach ( $pool as $id ) {
			$provider = self::make( $id );

			if ( is_wp_error( $provider ) ) {
				$last_err = $provider;
				continue;
			}

			$result = $task( $provider );

			if ( ! is_wp_error( $result ) ) {
				self::set_status( $id, true, 'OK' );

				return $result;
			}

			$last_err = $result;

			if ( 'aifbp_ai_limit' === $result->get_error_code() ) {
				self::notify_limit( $result );

				return $result;
			}

			Logger::warning( 'ai_fallback', sprintf( 'Provider %s failed: %s', $id, $result->get_error_message() ) );
		}

		return $last_err ?? new \WP_Error( 'aifbp_ai', __( 'All configured AI providers failed.', 'aifbp' ) );
	}

	public static function provider(): ProviderInterface|\WP_Error {
		$pool = self::eligible();

		if ( empty( $pool ) ) {
			return new \WP_Error( 'aifbp_ai', __( 'No AI provider is enabled and configured.', 'aifbp' ) );
		}

		return self::make( $pool[0] );
	}

	public static function captions( array $product, ?array $options = null ): array|\WP_Error {
		$s        = Settings::all();
		$options ??= [
			'language'   => $s['caption_language'],
			'variations' => $s['variations'],
		];

		if ( ! empty( $s['ai_image_understand'] ) && ! empty( $product['image_url'] ) && empty( $product['image_description'] ) ) {
			$analysis = self::call( fn( $p ) => $p->analyze_image( $product['image_url'] ) );

			if ( ! is_wp_error( $analysis ) ) {
				$product['image_description'] = $analysis;
			}
		}

		return self::call( fn( $p ) => $p->captions( $product, $options ) );
	}

	public static function reply( string $system, string $user ): string|\WP_Error {
		$result = self::call( fn( $p ) => method_exists( $p, 'ask' ) ? $p->ask( $system, $user ) : new \WP_Error( 'aifbp_ai', __( 'Provider does not support assistant replies.', 'aifbp' ) ) );

		return $result;
	}

	public static function rewrite( string $text, string $language = 'Bangla' ): string|\WP_Error {
		return self::call( fn( $p ) => $p->rewrite( $text, $language ) );
	}

	public static function generate_promo_image( array $product ): string|\WP_Error {
		if ( 'off' === (string) Settings::get( 'image_provider' ) ) {
			return new \WP_Error( 'aifbp_ai', __( 'AI image generation is switched off (image provider = off). The built-in non-AI poster designer is used instead.', 'aifbp' ) );
		}

		$price = '' !== ( $product['sale_price'] ?? '' ) ? $product['sale_price'] : ( $product['regular_price'] ?? '' );

		$prompt = sprintf(
			'Clean professional e-commerce promotional social media image featuring: %s. Vibrant colors, studio lighting, space for text overlay, no text in the image, square format.',
			$product['name'] . ( '' !== $price ? ' priced ' . $price . ' ' . ( $product['currency'] ?? '' ) : '' )
		);

		return self::call(
			function ( $p ) use ( $prompt ) {
				if ( 'openai' !== $p->id() ) {
					return new \WP_Error( 'aifbp_ai', __( 'Selected image provider does not support image generation.', 'aifbp' ) );
				}

				return $p->generate_image( $prompt );
			}
		);
	}

	public static function test_provider( string $id ): array|\WP_Error {
		if ( ! in_array( $id, self::PRIORITY, true ) ) {
			return new \WP_Error( 'aifbp_ai', 'Unknown provider.' );
		}

		$provider = self::make( $id );

		if ( is_wp_error( $provider ) ) {
			self::set_status( $id, false, $provider->get_error_message() );

			return $provider;
		}

		if ( ! method_exists( $provider, 'ask' ) ) {
			$error = new \WP_Error( 'aifbp_ai', 'Provider does not support text generation.' );
			self::set_status( $id, false, $error->get_error_message() );

			return $error;
		}

		$start   = microtime( true );
		$result  = $provider->ask( 'You are a connection tester. Reply with exactly: OK', 'Reply with exactly: OK', 0.0 );
		$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			self::set_status( $id, false, $result->get_error_message() );

			return $result;
		}

		$message = sprintf( 'Connected (%d ms) — model replied: %s', $elapsed, wp_html_excerpt( trim( $result ), 40, '…' ) );
		self::set_status( $id, true, $message );

		return [ 'message' => $message, 'ms' => $elapsed ];
	}

	public static function set_status( string $id, bool $ok, string $message ): void {
		$status   = get_option( 'aifbp_provider_status', [] );
		$status   = is_array( $status ) ? $status : [];
		$status[ $id ] = [
			'ok'        => $ok,
			'message'   => $message,
			'tested_at' => current_time( 'mysql' ),
		];

		update_option( 'aifbp_provider_status', $status, false );
	}

	public static function status( string $id ): array {
		$status = get_option( 'aifbp_provider_status', [] );

		return is_array( $status ) ? ( $status[ $id ] ?? [] ) : [];
	}

	public static function free_tier_info( string $id ): string {
		return match ( $id ) {
			'gemini'     => __( 'Free tier: yes (generous daily quota, paid tier optional).', 'aifbp' ),
			'groq'       => __( 'Free tier: yes (daily request + token limits).', 'aifbp' ),
			'openrouter' => __( 'Free tier: yes for models ending in ":free" — choose a :free model to avoid charges.', 'aifbp' ),
			'custom'     => __( 'Free tier: depends on your endpoint provider.', 'aifbp' ),
			default      => __( 'Free tier: no — OpenAI text models are PAID. Enabled only for compatibility; image generation is paid per image.', 'aifbp' ),
		};
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
