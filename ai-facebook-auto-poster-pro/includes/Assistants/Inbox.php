<?php

namespace AIFB\Assistants;

use AIFB\Core\DB;
use AIFB\Facebook\Client;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Inbox {

	public static function init(): void {
	}

	public static function handle_event( string $page_id, array $messaging ): void {
		global $wpdb;

		if ( empty( Settings::get( 'inbox_assistant' ) ) ) {
			return;
		}

		$sender_id = (string) ( $messaging['sender']['id'] ?? '' );
		$text      = trim( (string) ( $messaging['message']['text'] ?? '' ) );

		if ( '' === $sender_id || '' === $text ) {
			return;
		}

		if ( isset( $messaging['message']['is_echo'] ) && $messaging['message']['is_echo'] ) {
			return;
		}

		$table   = DB::table_conversations();
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE page_id = %s AND sender_id = %s", $page_id, $sender_id ),
			ARRAY_A
		);

		$context = [];

		if ( $existing ) {
			$decoded = json_decode( (string) $existing['context'], true );
			$context = is_array( $decoded ) ? $decoded : [];
		}

		$handoff = $existing && 'human' === (string) $existing['status'];

		$context[] = [ 'role' => 'customer', 'text' => $text, 'at' => current_time( 'mysql' ) ];
		$context   = array_slice( $context, -20 );

		$wpdb->replace(
			$table,
			[
				'page_id'      => $page_id,
				'sender_id'    => $sender_id,
				'sender_name'  => sanitize_text_field( (string) ( $messaging['sender']['name'] ?? ( $existing['sender_name'] ?? '' ) ) ),
				'last_message' => $text,
				'context'      => wp_json_encode( $context ),
				'status'       => $handoff ? 'human' : 'open',
				'unread'       => 1,
				'updated_at'   => current_time( 'mysql' ),
				'created_at'   => $existing ? ( $existing['created_at'] ?? current_time( 'mysql' ) ) : current_time( 'mysql' ),
			]
		);

		if ( $handoff ) {
			Logger::info( 'inbox', 'Human takeover active; AI reply skipped.' );

			return;
		}

		if ( empty( Settings::get( 'assistant_auto_reply' ) ) ) {
			return;
		}

		$reply = self::generate_reply( $page_id, $sender_id, $text, $context );

		if ( '' === $reply ) {
			return;
		}

		self::send( $page_id, $sender_id, $reply, $context );
	}

	public static function generate_reply( string $page_id, string $sender_id, string $text, array $context = [] ): string {
		$s = Settings::all();

		$products = self::search_products( $text );

		$language = (string) ( $s['assistant_language'] ?? 'Bangla + English' );
		$tone     = (string) ( $s['assistant_tone'] ?? 'friendly' );
		$rules    = (string) ( $s['assistant_instructions'] ?? '' );
		$blocked  = (string) ( $s['assistant_restricted'] ?? '' );

		$system = sprintf(
			'You are a Messenger customer support assistant for a WooCommerce shop. Reply in %s with a %s tone. Keep replies under 100 words. Never claim an order or payment is confirmed. Never share customer personal data. Restricted topics: %s. Business instructions: %s',
			$language,
			$tone,
			'' !== $blocked ? $blocked : 'none',
			'' !== $rules ? $rules : 'none'
		);

		$user = 'Customer message: "' . $text . '"';

		if ( ! empty( $products ) ) {
			$lines = [];

			foreach ( array_slice( $products, 0, 3 ) as $p ) {
				$price = '' !== $p['sale_price'] ? $p['sale_price'] : $p['regular_price'];
				$lines[] = sprintf( '%s | %s %s | %s', $p['name'], $price, $p['currency'], $p['url'] );
			}

			$user .= "\nMatching products:\n" . implode( "\n", $lines );
		} else {
			$user .= "\nNo matching products found; ask a clarifying question.";
		}

		if ( ! empty( $context ) ) {
			$recent = array_slice( $context, -6, -1 );
			$history = implode( "\n", array_map( fn( $c ) => ( 'customer' === $c['role'] ? 'Customer: ' : 'Assistant: ' ) . $c['text'], $recent ) );
			$user .= "\nRecent conversation:\n" . $history;
		}

		$reply = \AIFB\AI\Manager::reply( $system, $user );

		if ( is_wp_error( $reply ) ) {
			Logger::warning( 'inbox', 'AI reply failed: ' . $reply->get_error_message() );

			if ( ! empty( $products ) ) {
				$p = $products[0];
				$price = '' !== $p['sale_price'] ? $p['sale_price'] : $p['regular_price'];

				return sprintf( '%s — price %s %s. Order: %s', $p['name'], $price, $p['currency'], $p['url'] );
			}

			return 'Thanks for your message! Our team will reply soon.';
		}

		return trim( wp_strip_all_tags( $reply ) );
	}

	public static function search_products( string $text, int $limit = 5 ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [];
		}

		$keywords = preg_split( '/[\s,،।]+/u', mb_strtolower( trim( $text ) ) ) ?: [];
		$keywords = array_filter( $keywords, fn( $k ) => mb_strlen( $k ) >= 3 );

		if ( empty( $keywords ) ) {
			return [];
		}

		$found = wc_get_products(
			[
				'status'  => 'publish',
				'limit'   => $limit,
				's'       => implode( ' ', array_slice( $keywords, 0, 5 ) ),
			]
		);

		$out = [];

		foreach ( $found as $wc ) {
			$out[] = [
				'name'          => $wc->get_name(),
				'regular_price' => (string) $wc->get_regular_price(),
				'sale_price'    => (string) $wc->get_sale_price(),
				'currency'      => (string) Settings::get( 'currency' ),
				'url'           => $wc->get_permalink(),
			];
		}

		return $out;
	}

	public static function send( string $page_id, string $sender_id, string $text, array $context = [] ): bool {
		$token = Settings::page_token( $page_id );

		if ( '' === $token ) {
			Logger::error( 'inbox', 'Cannot send: missing page token for ' . $page_id );

			return false;
		}

		$result = Client::send_message( $page_id, $token, $sender_id, $text );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'inbox', 'Send failed: ' . $result->get_error_message() );

			return false;
		}

		global $wpdb;
		$table = DB::table_conversations();

		$context[] = [ 'role' => 'assistant', 'text' => $text, 'at' => current_time( 'mysql' ) ];
		$context   = array_slice( $context, -20 );

		$wpdb->update(
			$table,
			[
				'last_reply'  => $text,
				'context'     => wp_json_encode( $context ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'page_id' => $page_id, 'sender_id' => $sender_id ]
		);

		return true;
	}

	public static function set_handoff( string $page_id, string $sender_id, bool $human ): bool {
		global $wpdb;
		$table = DB::table_conversations();

		return (bool) $wpdb->update(
			$table,
			[ 'status' => $human ? 'human' : 'open' ],
			[ 'page_id' => $page_id, 'sender_id' => $sender_id ]
		);
	}

	public static function reply_manual( string $page_id, string $sender_id, string $text ): bool {
		$context = self::context( $page_id, $sender_id );

		return self::send( $page_id, $sender_id, $text, $context );
	}

	public static function context( string $page_id, string $sender_id ): array {
		global $wpdb;
		$table = DB::table_conversations();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT context FROM {$table} WHERE page_id = %s AND sender_id = %s", $page_id, $sender_id ),
			ARRAY_A
		);

		$decoded = json_decode( (string) ( $row['context'] ?? '[]' ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	public static function list_conversations( int $limit = 50 ): array {
		global $wpdb;
		$table = DB::table_conversations();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );

		return $rows ?: [];
	}
}
