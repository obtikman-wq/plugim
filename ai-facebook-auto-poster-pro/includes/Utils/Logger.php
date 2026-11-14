<?php

namespace AIFB\Utils;

use AIFB\Core\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	public static function log( string $level, string $context, string $message ): void {
		global $wpdb;

		$wpdb->insert(
			DB::table_logs(),
			[
				'level'      => in_array( $level, [ 'info', 'success', 'warning', 'error' ], true ) ? $level : 'info',
				'context'    => substr( sanitize_text_field( $context ), 0, 40 ),
				'message'    => sanitize_textarea_field( $message ),
				'created_at' => current_time( 'mysql' ),
			]
		);

		if ( 'error' === $level ) {
			$recent = get_transient( 'aifbp_recent_errors' );
			$recent = is_array( $recent ) ? $recent : [];
			array_unshift( $recent, [ 'time' => current_time( 'mysql' ), 'message' => $message ] );
			set_transient( 'aifbp_recent_errors', array_slice( $recent, 0, 5 ), HOUR_IN_SECONDS );
		}
	}

	public static function info( string $context, string $message ): void {
		self::log( 'info', $context, $message );
	}

	public static function success( string $context, string $message ): void {
		self::log( 'success', $context, $message );
	}

	public static function warning( string $context, string $message ): void {
		self::log( 'warning', $context, $message );
	}

	public static function error( string $context, string $message ): void {
		self::log( 'error', $context, $message );
	}

	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = DB::table_logs();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	public static function clear(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . DB::table_logs() );
		delete_transient( 'aifbp_recent_errors' );
	}
}
