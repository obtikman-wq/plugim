<?php

namespace AIFB\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/ai-facebook-auto-poster-pro.php';

Uninstaller::uninstall();
