<?php

namespace AIFB\Core;

use AIFB\Schedule\ScheduleManager;
use AIFB\Queue\Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		DB::create_tables();
		update_option( 'aifbp_db_version', AIFB_DB_VERSION );

		add_option(
			'aifbp_settings',
			[
				'app_id'              => '',
			'test_page_id'       => '',
			'groq_key'            => '',
			'groq_model'          => 'llama-3.3-70b-versatile',
			'openrouter_key'      => '',
			'openrouter_model'    => 'meta-llama/llama-3.3-70b-instruct:free',
			'provider_enabled_gemini' => 1,
			'provider_enabled_groq' => 1,
			'provider_enabled_openrouter' => 0,
			'provider_enabled_custom' => 0,
			'provider_enabled_openai' => 1,
			'ai_fallback'         => 1,
			'ai_daily_limit'      => 0,
			'ai_monthly_limit'    => 0,
			'ai_timeout'          => 30,
			'ai_retry_limit'      => 2,
			'usage_alerts'        => 0,
			'image_provider'      => 'off',
			'image_model'         => 'gpt-image-1',
			'default_page'        => '',
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
				'templates'           => "Flash Sale|🔥 {name} এখন মাত্র {price}! সীমিত সময়ের অফার। {link}\nNew Arrival|✨ নতুন এসেছে: {name} — {price}. এখনই অর্ডার করুন! {link}",
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
			]
		);

		add_option( 'aifbp_pages', [] );
		add_option( 'aifbp_posted_products', [] );
		add_option( 'aifbp_api_usage', [] );

		Processor::schedule_events();
		ScheduleManager::reschedule_tick();
	}
}
