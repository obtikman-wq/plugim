<?php

namespace AIFB\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Uninstaller {

	public static function uninstall(): void {
		DB::drop_tables();

		delete_option( 'aifbp_settings' );
		delete_option( 'aifbp_pages' );
		delete_option( 'aifbp_posted_products' );
		delete_option( 'aifbp_api_usage' );
		delete_option( 'aifbp_db_version' );
		delete_option( 'aifbp_oauth_state' );
		delete_option( 'aifbp_oauth_token' );
	}
}
