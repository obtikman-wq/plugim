<?php

namespace AIFB\Assistants;

use AIFB\Core\DB;
use AIFB\Facebook\Client;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Comments {

	const POLL_HOOK = 'aifbp_poll_comments';

	public static function init(): void {
		add_action( self::POLL_HOOK, [ __CLASS__, 'poll' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
	}

	public static function schedule(): void {
		if ( empty( Settings::get( 'comment_assistant' ) ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + 300, 'aifbp_5min', self::POLL_HOOK );
		}
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}

	public static function poll( int $limit = 10 ): int {
		global $wpdb;

		if ( empty( Settings::get( 'comment_assistant' ) ) ) {
			return 0;
		}

		$qtable = DB::table_queue();
		$ctable = DB::table_comments();

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fb_post_id, page_id, product_id FROM {$qtable} WHERE status = 'posted' AND fb_post_id != '' AND posted_at > DATE_SUB(%s, INTERVAL 7 DAY) ORDER BY posted_at DESC LIMIT %d",
				current_time( 'mysql' ),
				$limit
			),
			ARRAY_A
		);

		$found = 0;

		foreach ( $posts ?: [] as $row ) {
			$token = Settings::page_token( (string) $row['page_id'] );

			if ( '' === $token ) {
				continue;
			}

			$body = Client::get( (string) $row['fb_post_id'] . '/comments', [ 'fields' => 'id,message,created_time,from{id,name},parent{id}', 'limit' => 25 ], $token );

			if ( is_wp_error( $body ) ) {
				continue;
			}

			foreach ( (array) ( $body['data'] ?? [] ) as $comment ) {
				$comment_id = (string) ( $comment['id'] ?? '' );

				if ( '' === $comment_id ) {
					continue;
				}

				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ctable} WHERE comment_id = %s", $comment_id ) );

				if ( $exists > 0 ) {
					continue;
				}

				$message = trim( wp_strip_all_tags( (string) ( $comment['message'] ?? '' ) ) );

				$wpdb->insert(
					$ctable,
					[
						'page_id'     => (string) $row['page_id'],
						'post_id'     => (string) $row['fb_post_id'],
						'comment_id'  => $comment_id,
						'parent_id'   => (string) ( $comment['parent']['id'] ?? '' ),
						'from_name'   => sanitize_text_field( (string) ( $comment['from']['name'] ?? '' ) ),
						'from_id'     => (string) ( $comment['from']['id'] ?? '' ),
						'message'     => $message,
						'intent'      => self::detect_intent( $message ),
						'product_id'  => (int) $row['product_id'],
						'status'      => 'pending',
						'created_at'  => current_time( 'mysql' ),
					]
				);

				$found++;
			}
		}

		if ( $found > 0 ) {
			Logger::info( 'comments', sprintf( 'Comment assistant discovered %d new comment(s).', $found ) );
			self::process_pending( min( $found, 10 ) );
		}

		return $found;
	}

	public static function detect_intent( string $text ): string {
		$t = mb_strtolower( trim( $text ) );

		$map = [
			'price'     => [ 'price', 'dam', 'দাম', 'কত', 'rate', 'cost', 'how much' ],
			'stock'     => [ 'stock', 'available', 'ache', 'আছে', 'অ্যাভেইলেবল', 'in stock' ],
			'purchase'  => [ 'order', 'buy', 'kinbo', 'কিনব', 'অর্ডার', 'purchase', 'checkout' ],
			'complaint' => [ 'complaint', 'problem', 'problem hocche', 'অভিযোগ', 'সমস্যা', 'refund', 'return', 'broken', 'damaged' ],
			'greeting'  => [ 'hi', 'hello', 'assalam', 'সালাম', 'হ্যালো' ],
		];

		foreach ( $map as $intent => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $t, $keyword ) ) {
					return $intent;
				}
			}
		}

		return 'general';
	}

	public static function process_pending( int $limit = 10 ): int {
		global $wpdb;
		$table = DB::table_comments();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND parent_id = '' ORDER BY id ASC LIMIT %d", $limit ),
			ARRAY_A
		);

		$processed = 0;

		foreach ( $rows ?: [] as $row ) {
			$reply = self::generate_reply( $row );

			$status = ! empty( Settings::get( 'assistant_auto_reply' ) ) ? 'approved' : 'suggested';

			$wpdb->update(
				$table,
				[
					'reply_text' => $reply,
					'status'     => $status,
				],
				[ 'id' => (int) $row['id'] ]
			);

			if ( 'approved' === $status ) {
				self::send( (int) $row['id'] );
			}

			$processed++;
		}

		return $processed;
	}

	public static function generate_reply( array $row ): string {
		$product = null;

		if ( (int) $row['product_id'] > 0 && class_exists( 'WooCommerce' ) ) {
			$wc = wc_get_product( (int) $row['product_id'] );

			if ( $wc ) {
				$product = [
					'name'          => $wc->get_name(),
					'regular_price' => (string) $wc->get_regular_price(),
					'sale_price'    => (string) $wc->get_sale_price(),
					'currency'      => (string) Settings::get( 'currency' ),
					'url'           => $wc->get_permalink(),
					'stock_status'  => $wc->get_stock_status(),
				];
			}
		}

		$ai = self::ai_reply( (string) $row['message'], (string) $row['intent'], $product );

		if ( '' !== $ai ) {
			return $ai;
		}

		return self::template_reply( (string) $row['intent'], $product );
	}

	private static function ai_reply( string $message, string $intent, ?array $product ): string {
		$s = Settings::all();

		if ( empty( $s['comment_assistant'] ) ) {
			return '';
		}

		$language = (string) ( $s['assistant_language'] ?? 'Bangla + English' );
		$tone     = (string) ( $s['assistant_tone'] ?? 'friendly' );
		$rules    = (string) ( $s['assistant_instructions'] ?? '' );
		$blocked  = (string) ( $s['assistant_restricted'] ?? '' );

		$system = sprintf(
			'You are a Facebook page customer support assistant for a WooCommerce shop. Reply in %s with a %s tone. Keep replies under 80 words. Never claim an order or payment is confirmed. Never promise delivery dates you cannot verify. Never discuss these restricted topics: %s. Business instructions: %s',
			$language,
			$tone,
			'' !== $blocked ? $blocked : 'none',
			'' !== $rules ? $rules : 'none'
		);

		$user = 'Customer comment: "' . $message . '" (detected intent: ' . $intent . ').';

		if ( $product ) {
			$user .= sprintf(
				' Product: %s | Regular: %s %s | Sale: %s %s | Stock: %s | Link: %s',
				$product['name'],
				$product['regular_price'],
				$product['currency'],
				$product['sale_price'],
				$product['currency'],
				'instock' === ( $product['stock_status'] ?? '' ) ? 'in stock' : 'check availability',
				$product['url']
			);
		}

		$reply = \AIFB\AI\Manager::reply( $system, $user );

		if ( is_wp_error( $reply ) ) {
			Logger::warning( 'comments', 'AI reply failed: ' . $reply->get_error_message() );

			return '';
		}

		return trim( wp_strip_all_tags( $reply ) );
	}

	private static function template_reply( string $intent, ?array $product ): string {
		if ( $product ) {
			$price = '' !== $product['sale_price']
				? $product['sale_price'] . ' ' . $product['currency']
				: $product['regular_price'] . ' ' . $product['currency'];

			switch ( $intent ) {
				case 'price':
					return sprintf( 'Price: %s. Details: %s', $price, $product['url'] );
				case 'stock':
					return 'instock' === ( $product['stock_status'] ?? '' )
						? sprintf( 'Yes, this is in stock. Order here: %s', $product['url'] )
						: 'Stock status is changing right now — please check the product page: ' . $product['url'];
				case 'purchase':
					return sprintf( 'You can order here: %s', $product['url'] );
			}

			return sprintf( 'Thanks for your interest! Price: %s. Details: %s', $price, $product['url'] );
		}

		return 'Thanks for your comment! Our team will help you shortly.';
	}

	public static function send( int $row_id ): bool {
		global $wpdb;
		$table = DB::table_comments();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row_id ), ARRAY_A );

		if ( ! $row || 'sent' === $row['status'] || '' === trim( (string) $row['reply_text'] ) ) {
			return false;
		}

		$token = Settings::page_token( (string) $row['page_id'] );

		if ( '' === $token ) {
			Logger::error( 'comments', 'Cannot reply: missing page token.' );

			return false;
		}

		$result = Client::reply_comment( (string) $row['comment_id'], $token, (string) $row['reply_text'] );

		if ( is_wp_error( $result ) ) {
			$wpdb->update( $table, [ 'status' => 'failed' ], [ 'id' => $row_id ] );
			Logger::error( 'comments', 'Reply failed: ' . $result->get_error_message() );

			return false;
		}

		$wpdb->update(
			$table,
			[
				'status'           => 'sent',
				'reply_comment_id' => (string) $result['comment_id'],
				'replied_at'       => current_time( 'mysql' ),
			],
			[ 'id' => $row_id ]
		);

		return true;
	}

	public static function handle_event( string $page_id, array $value ): void {
		global $wpdb;

		if ( empty( Settings::get( 'comment_assistant' ) ) ) {
			return;
		}

		$item      = (string) ( $value['item'] ?? '' );
		$comment_id = (string) ( $value['comment_id'] ?? '' );

		if ( 'comment' !== $item || '' === $comment_id ) {
			return;
		}

		$verb = (string) ( $value['verb'] ?? '' );

		if ( ! in_array( $verb, [ 'add', 'edited' ], true ) ) {
			return;
		}

		$ctable = DB::table_comments();

		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ctable} WHERE comment_id = %s", $comment_id ) );

		if ( $exists > 0 ) {
			return;
		}

		$message = trim( wp_strip_all_tags( (string) ( $value['message'] ?? '' ) ) );

		$post_id = (string) ( $value['post_id'] ?? $value['parent_id'] ?? '' );

		$product_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT product_id FROM ' . DB::table_queue() . ' WHERE fb_post_id = %s ORDER BY id DESC LIMIT 1', $post_id )
		);

		$wpdb->insert(
			$ctable,
			[
				'page_id'     => $page_id,
				'post_id'     => $post_id,
				'comment_id'  => $comment_id,
				'parent_id'   => (string) ( $value['parent_id'] ?? '' ),
				'from_name'   => sanitize_text_field( (string) ( $value['from_name'] ?? '' ) ),
				'from_id'     => (string) ( $value['from_id'] ?? '' ),
				'message'     => $message,
				'intent'      => self::detect_intent( $message ),
				'product_id'  => $product_id,
				'status'      => 'pending',
				'created_at'  => current_time( 'mysql' ),
			]
		);

		self::process_pending( 5 );
	}

	public static function list( string $status = '', int $limit = 50 ): array {
		global $wpdb;
		$table = DB::table_comments();

		if ( '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		}

		return $rows ?: [];
	}
}
