<?php

namespace AIFB\AI;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceBadge {

	public static function apply( string $image_url, array $product ): string|\WP_Error {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new \WP_Error( 'aifbp_gd', __( 'GD extension is required for price badges.', 'aifbp' ) );
		}

		$s        = Settings::all();
		$currency = $s['currency'];
		$price    = '' !== ( $product['sale_price'] ?? '' ) ? $product['sale_price'] : ( $product['regular_price'] ?? '' );

		if ( '' === $price ) {
			return new \WP_Error( 'aifbp_gd', __( 'No price available for badge.', 'aifbp' ) );
		}

		$response = wp_remote_get( $image_url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$bytes = wp_remote_retrieve_body( $response );

		if ( '' === $bytes ) {
			return new \WP_Error( 'aifbp_gd', __( 'Could not download product image.', 'aifbp' ) );
		}

		$src = @imagecreatefromstring( $bytes );

		if ( false === $src ) {
			return new \WP_Error( 'aifbp_gd', __( 'Unsupported image format.', 'aifbp' ) );
		}

		$w = imagesx( $src );
		$h = imagesy( $src );

		$badge_w = (int) max( 140, $w * 0.32 );
		$badge_h = (int) max( 48, $h * 0.11 );
		$margin  = (int) max( 16, $w * 0.03 );

		$badge = imagecreatetruecolor( $badge_w, $badge_h );
		$rgb   = (int) hexdec( 'E23A3A' );
		$color = imagecolorallocate( $badge, ( $rgb >> 16 ) & 0xFF, ( $rgb >> 8 ) & 0xFF, $rgb & 0xFF );
		$white = imagecolorallocate( $badge, 255, 255, 255 );

		imagefilledrectangle( $badge, 0, 0, $badge_w, $badge_h, $color );
		imagefilledrectangle( $badge, 0, 0, 6, $badge_h, $white );

		$text = $price . ' ' . $currency;
		$font = 5;
		$tw   = imagefontwidth( $font ) * strlen( $text );
		$th   = imagefontheight( $font );

		if ( $tw > $badge_w - 20 ) {
			$font = max( 1, $font - 2 );
			$tw   = imagefontwidth( $font ) * strlen( $text );
			$th   = imagefontheight( $font );
		}

		imagestring(
			$badge,
			$font,
			(int) ( ( $badge_w - $tw ) / 2 ),
			(int) ( ( $badge_h - $th ) / 2 ),
			$text,
			$white
		);

		imagecopymerge(
			$src,
			$badge,
			$w - $badge_w - $margin,
			$h - $badge_h - $margin,
			0,
			0,
			$badge_w,
			$badge_h,
			92
		);

		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'aifbp_gd', $upload['error'] );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'aifbp';
		$url = trailingslashit( $upload['baseurl'] ) . 'aifbp';

		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return new \WP_Error( 'aifbp_gd', __( 'Could not create writable upload directory.', 'aifbp' ) );
		}

		$filename = sprintf( 'badge-%d-%s.jpg', (int) ( $product['id'] ?? 0 ), wp_generate_password( 8, false ) );
		$path     = $dir . '/' . $filename;

		if ( ! imagejpeg( $src, $path, 90 ) ) {
			imagedestroy( $src );
			imagedestroy( $badge );

			return new \WP_Error( 'aifbp_gd', __( 'Could not save badged image.', 'aifbp' ) );
		}

		imagedestroy( $src );
		imagedestroy( $badge );

		return $url . '/' . $filename;
	}
}
