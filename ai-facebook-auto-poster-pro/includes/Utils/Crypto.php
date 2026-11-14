<?php

namespace AIFB\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Crypto {

	private static ?string $key = null;

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();
		$iv  = random_bytes( 12 );

		$tag    = '';
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return '';
		}

		return 'enc1:' . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( ! str_starts_with( $stored, 'enc1:' ) ) {
			return $stored;
		}

		$raw = base64_decode( substr( $stored, 5 ), true );

		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );

		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plain ? '' : $plain;
	}

	private static function key(): string {
		return self::$key ??= hash( 'sha256', wp_salt( 'auth' ) . 'aifbp', true );
	}

	public static function mask( string $secret ): string {
		$len = strlen( $secret );

		if ( 0 === $len ) {
			return '';
		}

		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}

		return substr( $secret, 0, 4 ) . str_repeat( '•', $len - 8 ) . substr( $secret, -4 );
	}
}
