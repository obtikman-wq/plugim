<?php

namespace AIFB\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __clone() {}

	public function boot(): void {
		load_plugin_textdomain( 'aifbp', false, dirname( plugin_basename( AIFB_FILE ) ) . '/languages' );

		DB::maybe_upgrade();

		add_action(
			'admin_notices',
			function () {
				if ( ! class_exists( 'WooCommerce' ) ) {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'AI Facebook Product Auto Poster Pro works best with WooCommerce active. Manual product mode is available in settings.', 'aifbp' ) . '</p></div>';
				}
			}
		);

		new Admin\AdminUI();
		new Rest\RestController();

		Queue\Processor::init();
		Schedule\ScheduleManager::init();
		Analytics\Analytics::init();
		Assistants\Webhooks::init();
		Assistants\Comments::init();
	}
}
