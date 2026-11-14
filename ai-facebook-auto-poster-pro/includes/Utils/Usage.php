<?php

namespace AIFB\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Usage {

	public static function track( string $api, int $count = 1 ): void {
		$usage = get_option( 'aifbp_api_usage', [] );
		$usage = is_array( $usage ) ? $usage : [];

		$day = gmdate( 'Y-m-d' );

		$usage[ $day ] ??= [ 'ai' => 0, 'fb' => 0 ];
		$usage[ $day ][ 'ai' === $api ? 'ai' : 'fb' ] += $count;

		if ( count( $usage ) > 60 ) {
			ksort( $usage );
			$usage = array_slice( $usage, -60, null, true );
		}

		update_option( 'aifbp_api_usage', $usage, false );
	}

	public static function today( string $api ): int {
		$usage = get_option( 'aifbp_api_usage', [] );
		$day   = gmdate( 'Y-m-d' );

		return (int) ( is_array( $usage ) ? ( $usage[ $day ][ 'ai' === $api ? 'ai' : 'fb' ] ?? 0 ) : 0 );
	}

	public static function month(): array {
		$usage = get_option( 'aifbp_api_usage', [] );
		$usage = is_array( $usage ) ? $usage : [];
		$prefix = gmdate( 'Y-m' );

		$ai = 0;
		$fb = 0;

		foreach ( $usage as $day => $counts ) {
			if ( str_starts_with( (string) $day, $prefix ) && is_array( $counts ) ) {
				$ai += (int) ( $counts['ai'] ?? 0 );
				$fb += (int) ( $counts['fb'] ?? 0 );
			}
		}

		return [ 'ai' => $ai, 'fb' => $fb ];
	}
}
