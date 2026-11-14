<?php

namespace AIFB\Queue;

use AIFB\AI\Manager as AIManager;
use AIFB\AI\PriceBadge;
use AIFB\Facebook\Client;
use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Processor {

	const CRON_HOOK = 'aifbp_process_queue';

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'tick' ] );
		add_action( 'init', [ __CLASS__, 'schedule_events' ] );

		add_filter(
			'cron_schedules',
			function ( array $schedules ): array {
				$schedules['aifbp_5min'] = [
					'interval' => 5 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 5 Minutes (AI FB Poster)', 'aifbp' ),
				];

				return $schedules;
			}
		);
	}

	public static function action_scheduler_available(): bool {
		return class_exists( 'ActionScheduler' );
	}

	public static function schedule_events(): void {
		if ( self::action_scheduler_available() ) {
			if ( ! self::has_as_recurring() ) {
				as_schedule_recurring_action( time() + 60, 5 * MINUTE_IN_SECONDS, self::CRON_HOOK, [], 'aifbp' );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'aifbp_5min', self::CRON_HOOK );
		}
	}

	private static function has_as_recurring(): bool {
		if ( ! self::action_scheduler_available() ) {
			return false;
		}

		$next = as_next_scheduled_action( self::CRON_HOOK, [], 'aifbp' );

		return null !== $next && false !== $next;
	}

	public static function clear_events(): void {
		if ( self::action_scheduler_available() ) {
			as_unschedule_all_actions( self::CRON_HOOK, [], 'aifbp' );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function tick( int $limit = 5 ): int {
		$items = QueueManager::claim_due( $limit );
		$done  = 0;

		foreach ( $items as $item ) {
			self::process_item( $item );
			$done++;
		}

		return $done;
	}

	public static function process_item( array $item ): bool {
		$page_id = (string) $item['page_id'];
		$token   = Settings::page_token( $page_id );

		if ( '' === $token ) {
			QueueManager::mark_failed( (int) $item['id'], 'No page token for page ' . $page_id . '. Reconnect Facebook.', (int) $item['attempts'] );
			Logger::error( 'queue', 'Missing page token for page ' . $page_id );

			return false;
		}

		$meta = json_decode( (string) ( $item['meta'] ?? '{}' ), true );
		$meta = is_array( $meta ) ? $meta : [];

		$product = \AIFB\Product\Picker::random_product( (int) $item['product_id'] );

		if ( is_wp_error( $product ) ) {
			QueueManager::mark_failed( (int) $item['id'], $product->get_error_message(), (int) $item['attempts'] );

			return false;
		}

		$captions = AIManager::captions( $product );

		if ( is_wp_error( $captions ) ) {
			QueueManager::mark_failed( (int) $item['id'], 'AI: ' . $captions->get_error_message(), (int) $item['attempts'] );

			return false;
		}

		$variation = (string) ( $meta['variation'] ?? '' );
		$template  = (string) ( $meta['template'] ?? '' );

		if ( '' !== $template ) {
			$caption = \AIFB\Product\Templates::apply( $template, $product, $captions );

			if ( is_wp_error( $caption ) ) {
				$caption = AIManager::compose( $captions, $product, null, $variation );
			}
		} else {
			$caption = AIManager::compose( $captions, $product, null, $variation );
		}

		$image_url = (string) ( $item['image_url'] ?: $product['image_url'] );

		if ( ! empty( Settings::get( 'ai_image_gen' ) ) ) {
			$generated = AIManager::generate_promo_image( $product );

			if ( ! is_wp_error( $generated ) ) {
				$image_url = $generated;
			}
		}

		if ( ! empty( Settings::get( 'price_badge' ) ) && '' !== $image_url ) {
			$badged = PriceBadge::apply( $image_url, $product );

			if ( ! is_wp_error( $badged ) ) {
				$image_url = $badged;
			}
		}


		if ( '' !== $image_url ) {
			$result = Client::post_photo( $page_id, $token, $image_url, $caption );
		} else {
			$result = Client::post_feed( $page_id, $token, $caption, $product['url'] ?? '' );
		}

		if ( is_wp_error( $result ) ) {
			QueueManager::mark_failed( (int) $item['id'], $result->get_error_message(), (int) $item['attempts'] );

			return false;
		}

		QueueManager::mark_posted( (int) $item['id'], $result['post_id'] );
		Settings::mark_posted( (int) $item['product_id'] );

		\AIFB\Analytics\Analytics::record( $result['post_id'], (int) $item['id'], $page_id );

		Logger::success(
			'queue',
			sprintf( 'Posted "%s" to %s (post %s).', $product['name'], (string) $item['page_name'], $result['post_id'] )
		);

		return true;
	}
}

