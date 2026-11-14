<?php

namespace AIFB\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public static function all(): array {
		$defaults = [
			'app_id'              => '',
			'app_secret'          => '',
			'ai_provider'         => 'openai',
			'openai_key'          => '',
			'openai_model'        => 'gpt-4o-mini',
			'openai_base'         => 'https://api.openai.com/v1',
			'gemini_key'          => '',
			'gemini_model'        => 'gemini-2.0-flash',
			'custom_base'         => '',
			'custom_key'          => '',
			'custom_model'        => '',
			'caption_language'    => 'both',
			'variations'          => 2,
			'include_price'       => 1,
			'include_link'        => 1,
			'include_hashtags'    => 1,
			'include_cta'         => 1,
			'currency'            => 'Tk',
			'cooldown_days'       => 7,
			'max_attempts'        => 3,
			'retry_delay_min'     => 30,
			'post_interval_min'   => 60,
			'price_badge'         => 0,
			'ai_image_gen'        => 0,
			'ai_image_understand' => 0,
			'templates'           => '',
		];

		$settings = get_option( 'aifbp_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		return wp_parse_args( $settings, $defaults );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	public static function update( array $settings ): void {
		update_option( 'aifbp_settings', $settings );
	}

	public static function pages(): array {
		$pages = get_option( 'aifbp_pages', [] );
		return is_array( $pages ) ? $pages : [];
	}

	public static function page( string $page_id ): ?array {
		return self::pages()[ $page_id ] ?? null;
	}

	public static function save_pages( array $pages ): void {
		update_option( 'aifbp_pages', $pages );
	}

	public static function page_token( string $page_id ): string {
		$page = self::page( $page_id );
		return $page ? Crypto::decrypt( (string) ( $page['token'] ?? '' ) ) : '';
	}

	public static function posted_products(): array {
		$posted = get_option( 'aifbp_posted_products', [] );
		return is_array( $posted ) ? $posted : [];
	}

	public static function mark_posted( int $product_id ): void {
		$posted                = self::posted_products();
		$posted[ $product_id ] = time();

		if ( count( $posted ) > 1000 ) {
			asort( $posted );
			$posted = array_slice( $posted, -1000, null, true );
		}

		update_option( 'aifbp_posted_products', $posted, false );
	}
}
