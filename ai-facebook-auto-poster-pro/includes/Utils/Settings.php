<?php

namespace AIFB\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public static function all(): array {
		$defaults = [
			'app_id'              => '',
			'test_page_id'       => '',
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
			'posting_mode'        => 'both',
			'ai_fallback_direct'  => 1,
			'direct_format'       => 'name_image_price_link',
			'direct_template'     => "🔥 {product_name}\n\n{short_description}\n\n💰 Regular Price: {regular_price}\n🔥 Offer Price: {sale_price}\n\n🛒 Order Now:\n{product_url}\n\n#OnlineShopping #SpecialOffer",
			'direct_hashtags'     => '#OnlineShopping #SpecialOffer',
			'direct_cta'          => 'Order Now!',
			'skip_out_of_stock'   => 1,
			'repeat_after_days'   => 30,
			'comment_assistant'   => 0,
			'inbox_assistant'     => 0,
			'assistant_auto_reply' => 0,
			'assistant_approval'  => 1,
			'assistant_language'  => 'Bangla + English',
			'assistant_tone'      => 'friendly',
			'assistant_instructions' => '',
			'assistant_restricted' => 'politics,medical,legal',
			'assistant_provider'  => 'same',
			'assistant_privacy'   => 1,
			'webhook_verify_token' => '',
			'poster_enabled'      => 0,
			'poster_size'         => 'square',
			'poster_template'     => 'classic',
			'poster_bg'           => '#1e3a8a',
			'poster_accent'       => '#f59e0b',
			'poster_brand'        => '',
			'poster_cta'          => 'Order Now',
			'poster_badge'        => 1,
			'poster_text'         => '#ffffff',
			'poster_save_media'   => 0,
			'poster_font_path'    => '',
			'data_retention_days' => 90,
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
