<?php
/**
 * Plugin Name: AI Facebook Product Auto Poster Pro
 * Description: AI + Direct Facebook marketing automation for WooCommerce: scheduled posting with or without AI, rotation, comment and inbox assistants, poster designer, calendar and analytics.
 * Version: 3.1.1
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: AI Facebook Auto Poster Pro
 * License: GPL-2.0-or-later
 * Text Domain: aifbp
 */

namespace AIFB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIFB_VERSION', '3.1.1' );
define( 'AIFB_DB_VERSION', '3' );
define( 'AIFB_FILE', __FILE__ );
define( 'AIFB_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIFB_URL', plugin_dir_url( __FILE__ ) );

require_once AIFB_DIR . 'includes/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, [ Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Core\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	function () {
		Core\Plugin::instance()->boot();
	},
	20
);
