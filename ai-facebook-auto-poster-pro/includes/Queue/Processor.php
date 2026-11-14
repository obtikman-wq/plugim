<?php

namespace AIFB\Queue;

use AIFB\AI\Manager as AIManager;
use AIFB\AI\PriceBadge;
use AIFB\Direct\Engine as DirectEngine;
use AIFB\Facebook\Client;
use AIFB\Product\Rotation;
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
		if ( ! QueueManager::acquire_lock() ) {
			Logger::info( 'queue', 'Queue processing skipped: another process holds the lock.' );

			return 0;
		}

		try {
			$items = QueueManager::claim_due( $limit );
			$done  = 0;

			foreach ( $items as $item ) {
				self::process_item( $item );
				$done++;
			}
		} finally {
			QueueManager::release_lock();
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

		$mode = (string) ( $item['mode'] ?? 'ai' );

		if ( ! in_array( $mode, [ 'ai', 'direct' ], true ) ) {
			$mode = 'ai';
		}

		$product = Rotation::pick(
			[
				'selection'         => 'manual',
				'product_id'        => (int) $item['product_id'],
				'page_id'           => $page_id,
				'schedule_id'       => (int) ( $meta['schedule_id'] ?? 0 ),
				'mode'              => $mode,
				'skip_out_of_stock' => 0,
			]
		);

		if ( is_wp_error( $product ) ) {
			QueueManager::mark_failed( (int) $item['id'], $product->get_error_message(), (int) $item['attempts'] );

			return false;
		}

		$captured = self::build_caption( $product, $mode, $meta );

		if ( is_wp_error( $captured ) ) {
			QueueManager::mark_failed( (int) $item['id'], $captured->get_error_message(), (int) $item['attempts'] );

			return false;
		}

		$caption = $captured['caption'];
		$used    = $captured['mode'];

		if ( '' !== trim( (string) ( $meta['caption_override'] ?? '' ) ) ) {
			$caption = (string) $meta['caption_override'];
		}

		$image_url = (string) ( $item['image_url'] ?: $product['image_url'] );

		if ( ! empty( $meta['image_override'] ) ) {
			$image_url = (string) $meta['image_override'];
		}

		$poster = self::maybe_poster( $product, $mode, $meta );

		if ( '' !== $poster ) {
			$image_url = $poster;
		}

		if ( ! empty( Settings::get( 'ai_image_gen' ) ) && 'ai' === $mode && '' === $poster ) {
			$generated = AIManager::generate_promo_image( $product );

			if ( ! is_wp_error( $generated ) ) {
				$image_url = $generated;
			}
		}

		if ( ! empty( Settings::get( 'price_badge' ) ) && '' !== $image_url && '' === $poster ) {
			$badged = PriceBadge::apply( $image_url, $product );

			if ( ! is_wp_error( $badged ) ) {
				$image_url = $badged;
			}
		}

		$link = (string) ( $product['url'] ?? '' );

		if ( '' !== $image_url ) {
			$result = Client::post_photo( $page_id, $token, $image_url, $caption );
		} else {
			$result = Client::post_feed( $page_id, $token, $caption, $link );
		}

		if ( is_wp_error( $result ) ) {
			if ( 'ai' === $mode && ! empty( Settings::get( 'ai_fallback_direct' ) ) && self::is_ai_error( $result ) ) {
				$direct = self::try_direct_fallback( $product, $page_id, $token, $item, $meta );

				if ( true === $direct ) {
					return true;
				}
			}

			QueueManager::mark_failed( (int) $item['id'], $result->get_error_message(), (int) $item['attempts'] );

			return false;
		}

		return self::finish( $item, $product, $page_id, $used, $result['post_id'], $caption );
	}

	private static function build_caption( array $product, string $mode, array $meta ): array|\WP_Error {
		if ( 'direct' === $mode ) {
			$caption = DirectEngine::build(
				$product,
				(string) ( $meta['direct_format'] ?? null ),
				(string) ( $meta['direct_template'] ?? null )
			);

			if ( '' === trim( $caption ) ) {
				return new \WP_Error( 'aifbp_direct_empty', __( 'Direct engine produced an empty caption. Check the product description or template.', 'aifbp' ) );
			}

			return [ 'caption' => $caption, 'mode' => 'direct' ];
		}

		$captions = AIManager::captions( $product );

		if ( is_wp_error( $captions ) ) {
			if ( ! empty( Settings::get( 'ai_fallback_direct' ) ) ) {
				$caption = DirectEngine::build( $product, (string) ( $meta['direct_format'] ?? null ), (string) ( $meta['direct_template'] ?? null ) );

				if ( '' !== trim( $caption ) ) {
					return [ 'caption' => $caption, 'mode' => 'direct' ];
				}
			}

			return new \WP_Error( 'aifbp_ai', 'AI: ' . $captions->get_error_message() );
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

		return [ 'caption' => $caption, 'mode' => 'ai' ];
	}

	private static function is_ai_error( \WP_Error $error ): bool {
		$code = (string) $error->get_error_code();

		return str_starts_with( $code, 'aifbp_ai' ) || str_contains( $code, 'ai' ) || str_contains( $code, 'openai' ) || str_contains( $code, 'gemini' );
	}

	private static function try_direct_fallback( array $product, string $page_id, string $token, array $item, array $meta ): bool {
		$caption = DirectEngine::build( $product, (string) ( $meta['direct_format'] ?? null ), (string) ( $meta['direct_template'] ?? null ) );

		if ( '' === trim( $caption ) ) {
			return false;
		}

		$image_url = (string) ( $item['image_url'] ?: $product['image_url'] );

		if ( ! empty( $meta['image_override'] ) ) {
			$image_url = (string) $meta['image_override'];
		}

		if ( '' !== $image_url ) {
			$result = Client::post_photo( $page_id, $token, $image_url, $caption );
		} else {
			$result = Client::post_feed( $page_id, $token, $caption, (string) ( $product['url'] ?? '' ) );
		}

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return self::finish( $item, $product, $page_id, 'direct', $result['post_id'], $caption );
	}

	private static function maybe_poster( array $product, string $mode, array $meta ): string {
		if ( empty( Settings::get( 'poster_enabled' ) ) ) {
			return '';
		}

		if ( ! class_exists( '\AIFB\Poster\Designer' ) ) {
			return '';
		}

		$poster = \AIFB\Poster\Designer::generate_for_product(
			$product,
			[
				'size'      => (string) ( $meta['poster_size'] ?? Settings::get( 'poster_size' ) ),
				'template'  => (string) ( $meta['poster_template'] ?? 'classic' ),
				'attach_to' => 'queue',
			]
		);

		if ( is_wp_error( $poster ) || empty( $poster['url'] ) ) {
			Logger::warning( 'poster', 'Poster generation failed: ' . ( is_wp_error( $poster ) ? $poster->get_error_message() : 'no URL' ) );

			return '';
		}

		return (string) $poster['url'];
	}

	private static function finish( array $item, array $product, string $page_id, string $mode, string $fb_post_id, string $caption ): bool {
		QueueManager::mark_posted( (int) $item['id'], $fb_post_id );
		Settings::mark_posted( (int) $item['product_id'] );

		Rotation::record(
			(int) $item['product_id'],
			$page_id,
			(int) ( json_decode( (string) ( $item['meta'] ?? '{}' ), true )['schedule_id'] ?? 0 ),
			$mode
		);

		\AIFB\Analytics\Analytics::record( $fb_post_id, (int) $item['id'], $page_id, $mode );

		Logger::success(
			'queue',
			sprintf( 'Posted "%s" to %s via %s mode (post %s).', $product['name'], (string) $item['page_name'], $mode, $fb_post_id )
		);

		return true;
	}
}
