<?php

namespace AIFB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, 'AIFB\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'AIFB\\' ) );
		$path     = AIFB_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
