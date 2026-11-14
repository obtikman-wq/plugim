<?php

namespace AIFB\Poster;

use AIFB\Direct\Engine as DirectEngine;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Designer {

	const SIZES = [
		'square'   => [ 1080, 1080 ],
		'portrait' => [ 1080, 1350 ],
		'story'    => [ 1080, 1920 ],
	];

	const TEMPLATES = [ 'classic', 'bold', 'minimal' ];

	public static function generate_for_product( array $product, array $args = [] ): array|\WP_Error {
		$s = Settings::all();

		$size     = (string) ( $args['size'] ?? $s['poster_size'] );
		$size     = isset( self::SIZES[ $size ] ) ? $size : 'square';
		$template = (string) ( $args['template'] ?? 'classic' );

		if ( ! in_array( $template, self::TEMPLATES, true ) ) {
			$template = 'classic';
		}

		[ $width, $height ] = self::SIZES[ $size ];

		$image_url = (string) ( $product['image_url'] ?? '' );
		$source    = null;

		if ( '' !== $image_url ) {
			$source = self::download( $image_url );
		}

		$canvas = imagecreatetruecolor( $width, $height );

		if ( ! $canvas ) {
			return new \WP_Error( 'aifbp_gd', __( 'GD image creation failed.', 'aifbp' ) );
		}

		$bg       = self::hex( (string) ( $s['poster_bg'] ?? '#1e3a8a' ) );
		$accent   = self::hex( (string) ( $s['poster_accent'] ?? '#f59e0b' ) );
		$text_col = self::hex( (string) ( $s['poster_text'] ?? '#ffffff' ) );

		self::paint_background( $canvas, $width, $height, $bg, $accent, $template );

		if ( $source ) {
			self::overlay_product_image( $canvas, $source, $width, $height, $template );
			imagedestroy( $source );
		}

		$name = (string) ( $product['name'] ?? '' );
		self::draw_wrapped_text( $canvas, $name, $width, $template, $text_col, 'name' );

		$price_lines = DirectEngine::price_lines( $product );
		self::draw_wrapped_text( $canvas, $price_lines, $width, $template, $accent, 'price' );

		$cta = trim( (string) ( $s['direct_cta'] ?? '' ) );

		if ( '' !== $cta ) {
			self::draw_wrapped_text( $canvas, $cta, $width, $template, $text_col, 'cta' );
		}

		$discount = DirectEngine::discount_percent( $product );

		if ( null !== $discount ) {
			self::draw_badge( $canvas, sprintf( '-%d%%', $discount ), $width, $accent );
		}

		$path = self::save( $canvas );

		imagedestroy( $canvas );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$attachment_id = 0;

		if ( ! empty( $args['save_media'] ) || ! empty( $s['poster_save_media'] ) ) {
			$attachment_id = self::attach_to_media( $path, $name );
		}

		$url = self::url_from_path( $path );

		if ( '' === $url ) {
			return new \WP_Error( 'aifbp_gd', __( 'Poster URL could not be resolved.', 'aifbp' ) );
		}

		return [
			'url'           => $url,
			'path'          => $path,
			'attachment_id' => $attachment_id,
			'size'          => $size,
			'template'      => $template,
		];
	}

	private static function download( string $url ) {
		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			Logger::warning( 'poster', 'Product image download failed: ' . $tmp->get_error_message() );

			return null;
		}

		$info = wp_getimagesize( $tmp );

		if ( ! $info ) {
			@unlink( $tmp );

			return null;
		}

		$mime = (string) ( $info['mime'] ?? '' );

		$source = match ( $mime ) {
			'image/png'  => @imagecreatefrompng( $tmp ),
			'image/webp' => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $tmp ) : null,
			default      => @imagecreatefromjpeg( $tmp ),
		};

		@unlink( $tmp );

		return $source ?: null;
	}

	private static function paint_background( $canvas, int $width, int $height, array $bg, array $accent, string $template ): void {
		$fill = imagecolorallocate( $canvas, $bg[0], $bg[1], $bg[2] );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $fill );

		if ( 'minimal' === $template ) {
			return;
		}

		$steps = 60;

		for ( $i = 0; $i < $steps; $i++ ) {
			$t     = $i / $steps;
			$color = imagecolorallocate(
				$canvas,
				(int) ( $bg[0] + ( $accent[0] - $bg[0] ) * $t * 0.35 ),
				(int) ( $bg[1] + ( $accent[1] - $bg[1] ) * $t * 0.35 ),
				(int) ( $bg[2] + ( $accent[2] - $bg[2] ) * $t * 0.35 )
			);

			imagefilledrectangle( $canvas, 0, (int) ( $height * $t ), $width, (int) ( $height * ( $t + 1.0 / $steps ) ) + 1, $color );
		}

		if ( 'bold' === $template ) {
			$bar = imagecolorallocate( $canvas, $accent[0], $accent[1], $accent[2] );
			imagefilledrectangle( $canvas, 0, (int) ( $height * 0.06 ), $width, (int) ( $height * 0.06 ) + 14, $bar );
		}
	}

	private static function overlay_product_image( $canvas, $source, int $width, int $height, string $template ): void {
		$sw     = imagesx( $source );
		$sh     = imagesy( $source );
		$box_w  = (int) ( $width * ( 'minimal' === $template ? 0.6 : 0.72 ) );
		$box_h  = (int) ( $height * ( 'story' === $template ? 0.42 : 0.5 ) );

		$scale = min( $box_w / $sw, $box_h / $sh, 1.0 );
		$dw    = max( 1, (int) ( $sw * $scale ) );
		$dh    = max( 1, (int) ( $sh * $scale ) );

		$dx = (int) ( ( $width - $dw ) / 2 );
		$dy = (int) ( $height * ( 'story' === $template ? 0.14 : 0.16 ) );

		imagecopyresampled( $canvas, $source, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
	}

	private static function draw_wrapped_text( $canvas, string $text, int $width, string $template, array $color, string $slot ): void {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( '' === $text ) {
			return;
		}

		$col = imagecolorallocate( $canvas, $color[0], $color[1], $color[2] );

		$font     = self::font_path();
		$size     = 'name' === $slot ? 54 : ( 'price' === $slot ? 44 : 36 );
		$line_h   = (int) ( $size * 1.35 );
		$max_w    = (int) ( $width * 0.84 );

		$lines = self::wrap( $text, $max_w, $font, $size );
		$total = count( $lines ) * $line_h;

		$y = match ( $slot ) {
			'name'  => (int) ( imagesy( $canvas ) * ( 'story' === $template ? 0.62 : 0.70 ) ),
			'price' => (int) ( imagesy( $canvas ) * ( 'story' === $template ? 0.78 : 0.84 ) ),
			default => (int) ( imagesy( $canvas ) * 0.93 ),
		};

		$y = min( $y, imagesy( $canvas ) - $total - 10 );

		if ( $font && is_readable( $font ) ) {
			foreach ( $lines as $i => $line ) {
				$box   = imagettfbbox( $size, 0, $font, $line );
				$line_w = abs( $box[4] - $box[0] );
				$x     = (int) ( ( $width - $line_w ) / 2 );

				imagettftext( $canvas, $size, 0, $x, $y + $i * $line_h, $col, $font, $line );
			}

			return;
		}

		$bitmap_size = 'name' === $slot ? 5 : 4;
		$char_w      = imagefontwidth( $bitmap_size );
		$per_line    = max( 8, (int) ( $max_w / max( 1, $char_w ) ) );
		$lines       = self::wrap( $text, $per_line, null, 0 );

		foreach ( $lines as $i => $line ) {
			$line_w = strlen( $line ) * $char_w;
			$x      = (int) ( ( $width - $line_w ) / 2 );

			imagestring( $canvas, $bitmap_size, $x, $y + $i * ( $bitmap_size + 8 ), $line, $col );
		}
	}

	private static function draw_badge( $canvas, string $text, int $width, array $accent ): void {
		$badge_w = 260;
		$badge_h = 110;

		$bg   = imagecolorallocate( $canvas, $accent[0], $accent[1], $accent[2] );
		$white = imagecolorallocate( $canvas, 255, 255, 255 );

		imagefilledellipse( $canvas, $width - $badge_w / 2 - 30, 150, $badge_w, $badge_h, $bg );

		$font = self::font_path();

		if ( $font && is_readable( $font ) ) {
			$box = imagettfbbox( 44, 0, $font, $text );
			$tw  = abs( $box[4] - $box[0] );
			imagettftext( $canvas, 44, 0, (int) ( $width - $badge_w / 2 - 30 - $tw / 2 ), 165, $white, $font, $text );

			return;
		}

		$char_w = imagefontwidth( 5 ) * strlen( $text );
		imagestring( $canvas, 5, (int) ( $width - $badge_w / 2 - 30 - $char_w / 2 ), 145, $text, $white );
	}

	private static function wrap( string $text, int $max_width, ?string $font, int $size ): array {
		$words = preg_split( '/\s+/u', $text ) ?: [];
		$lines = [];
		$line  = '';

		foreach ( $words as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;

			$w = $font && is_readable( $font )
				? ( function_exists( 'imagettfbbox' ) ? abs( imagettfbbox( $size, 0, $font, $candidate )[4] - imagettfbbox( $size, 0, $font, $candidate )[0] ) : mb_strlen( $candidate ) )
				: mb_strlen( $candidate );

			if ( $w > $max_width && '' !== $line ) {
				$lines[] = $line;
				$line    = $word;

				continue;
			}

			$line = $candidate;
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return array_slice( $lines, 0, 6 );
	}

	private static function font_path(): string {
		$custom = trim( (string) Settings::get( 'poster_font_path' ) );

		if ( '' !== $custom && is_readable( $custom ) ) {
			return $custom;
		}

		$candidates = [
			AIFB_DIR . 'assets/fonts/poster.ttf',
			WP_CONTENT_DIR . '/uploads/aifbp/poster.ttf',
		];

		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private static function hex( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return [ 26, 26, 46 ];
		}

		return [
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		];
	}

	private static function save( $canvas ): string|\WP_Error {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'aifbp_upload', (string) $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'aifbp/posters';

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'aifbp_upload', __( 'Could not create poster directory.', 'aifbp' ) );
		}

		$path = $dir . '/poster-' . time() . '-' . wp_generate_password( 6, false ) . '.jpg';

		if ( ! imagejpeg( $canvas, $path, 90 ) ) {
			return new \WP_Error( 'aifbp_gd', __( 'Failed to write poster image.', 'aifbp' ) );
		}

		return $path;
	}

	private static function url_from_path( string $path ): string {
		$uploads = wp_upload_dir();

		return str_replace( trailingslashit( $uploads['basedir'] ), trailingslashit( $uploads['baseurl'] ), $path );
	}

	private static function attach_to_media( string $path, string $title ): int {
		$uploads = wp_upload_dir();
		$url     = self::url_from_path( $path );

		$file = wp_check_filetype( basename( $path ), null );

		$attachment = [
			'post_mime_type' => $file['type'] ?? 'image/jpeg',
			'post_title'     => sanitize_text_field( $title ?: __( 'Product Poster', 'aifbp' ) ),
			'post_status'    => 'inherit',
		];

		$attach_id = wp_insert_attachment( $attachment, $path );

		if ( ! is_wp_error( $attach_id ) && $attach_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $path ) );

			return (int) $attach_id;
		}

		return 0;
	}
}
