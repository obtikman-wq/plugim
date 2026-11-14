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
			]
		);

		add_option( 'aifbp_pages', [] );
		add_option( 'aifbp_posted_products', [] );
		add_option( 'aifbp_api_usage', [] );

		Processor::schedule_events();
		ScheduleManager::reschedule_tick();
	}
}
