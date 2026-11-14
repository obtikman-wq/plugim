<?php

namespace AIFB\Rest;

use AIFB\AI\Manager as AIManager;
use AIFB\Calendar\CalendarManager;
use AIFB\Direct\Engine as DirectEngine;
use AIFB\Facebook\OAuth;
use AIFB\Poster\Designer;
use AIFB\Product\Picker;
use AIFB\Queue\Processor;
use AIFB\Queue\QueueManager;
use AIFB\Schedule\ScheduleManager;
use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RestController {

	const NS = 'aifbp/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_post_aifbp_oauth_callback', [ OAuth::class, 'handle_callback' ] );
	}

	public function register_routes(): void {
		$permission = fn() => current_user_can( 'manage_options' );

		register_rest_route(
			self::NS,
			'/status',
			[
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function () {
					$counts = QueueManager::counts();

					return rest_ensure_response(
						[
							'queue'          => $counts,
							'next_scheduled' => QueueManager::next_scheduled(),
							'cron_next'      => wp_next_scheduled( Processor::CRON_HOOK ),
							'action_scheduler' => Processor::action_scheduler_available(),
							'pages'          => count( Settings::pages() ),
						]
					);
				},
			]
		);

		register_rest_route(
			self::NS,
			'/queue/process',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function () {
					$processed = Processor::tick( 3 );

					return rest_ensure_response( [ 'processed' => $processed ] );
				},
			]
		);

		register_rest_route(
			self::NS,
			'/post-now',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$product_id = $request->get_param( 'product_id' );
					$page_id    = (string) $request->get_param( 'page_id' );

					$pages = Settings::pages();

					if ( '' === $page_id ) {
						$default = (string) Settings::get( 'default_page' );
					$page_id = '' !== $default && isset( $pages[ $default ] ) ? $default : (string) array_key_first( $pages );
					}

					if ( '' === $page_id || ! isset( $pages[ $page_id ] ) ) {
						return new \WP_Error( 'aifbp_pages', __( 'No connected Facebook page. Connect one first.', 'aifbp' ), [ 'status' => 400 ] );
					}

					$product = Picker::random_product( $product_id ? (int) $product_id : null );

					if ( is_wp_error( $product ) ) {
						return new \WP_Error( 'aifbp_products', $product->get_error_message(), [ 'status' => 400 ] );
					}

					$id = QueueManager::enqueue(
						$product,
						$page_id,
						(string) ( $pages[ $page_id ]['name'] ?? $page_id ),
						current_time( 'mysql' ),
						'manual',
						[ 'variation' => (string) $request->get_param( 'variation' ) ]
					);

					if ( is_wp_error( $id ) ) {
						return new \WP_Error( 'aifbp_queue', $id->get_error_message(), [ 'status' => 400 ] );
					}

					$item = QueueManager::get( $id );

					$ok = Processor::process_item( $item );

					$updated = QueueManager::get( $id );

					if ( ! $ok ) {
						return new \WP_Error( 'aifbp_post', (string) ( $updated['last_error'] ?? 'Publishing failed. The item was queued for retry.' ), [ 'status' => 502 ] );
					}

					return rest_ensure_response(
						[
							'queue_id'   => $id,
							'fb_post_id' => (string) ( $updated['fb_post_id'] ?? '' ),
							'post_url'   => 'https://www.facebook.com/' . ( $updated['fb_post_id'] ?? '' ),
							'caption'    => (string) ( $updated['caption'] ?? '' ),
						]
					);
				},
			]
		);

		register_rest_route(
			self::NS,
			'/preview',
			[
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$product = Picker::random_product( $request->get_param( 'product_id' ) ? (int) $request->get_param( 'product_id' ) : null );

					if ( is_wp_error( $product ) ) {
						return new \WP_Error( 'aifbp_products', $product->get_error_message(), [ 'status' => 400 ] );
					}

					$mode = 'direct' === (string) $request->get_param( 'mode' ) ? 'direct' : 'ai';

					if ( 'direct' === $mode ) {
						return rest_ensure_response(
							[
								'product' => $product,
								'mode'    => 'direct',
								'caption' => DirectEngine::build( $product ),
							]
						);
					}

					$captions = AIManager::captions( $product );

					if ( is_wp_error( $captions ) ) {
						return new \WP_Error( 'aifbp_ai', $captions->get_error_message(), [ 'status' => 502 ] );
					}

					return rest_ensure_response(
						[
							'product'    => $product,
							'captions'   => $captions,
							'mode'       => 'ai',
							'caption'    => AIManager::compose( $captions, $product ),
						]
					);
				},
			]
		);

		register_rest_route(
			self::NS,
			'/caption/rewrite',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$text     = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
					$language = sanitize_text_field( (string) $request->get_param( 'language' ) );

					if ( '' === $text ) {
						return new \WP_Error( 'aifbp_ai', __( 'Caption text is empty.', 'aifbp' ), [ 'status' => 400 ] );
					}

					$rewritten = AIManager::rewrite( $text, '' !== $language ? $language : 'Bangla' );

					if ( is_wp_error( $rewritten ) ) {
						return new \WP_Error( 'aifbp_ai', $rewritten->get_error_message(), [ 'status' => 502 ] );
					}

					return rest_ensure_response( [ 'text' => $rewritten ] );
				},
			]
		);

		register_rest_route(
			self::NS,
			'/schedules',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$id = ScheduleManager::create(
						[
							'name'             => (string) $request->get_param( 'name' ),
							'type'             => (string) $request->get_param( 'type' ),
							'time_of_day'      => (string) $request->get_param( 'time_of_day' ),
							'days'             => (string) $request->get_param( 'days' ),
							'posts_per_run'    => (int) $request->get_param( 'posts_per_run' ),
							'interval_minutes' => (int) $request->get_param( 'interval_minutes' ),
							'pages'            => (array) $request->get_param( 'pages' ),
							'start_date'       => (string) $request->get_param( 'start_date' ),
							'end_date'         => (string) $request->get_param( 'end_date' ),
							'mode'             => (string) $request->get_param( 'mode' ),
							'active'           => 1,
						]
					);

					if ( is_wp_error( $id ) ) {
						return new \WP_Error( 'aifbp_schedule', $id->get_error_message(), [ 'status' => 400 ] );
					}

					return rest_ensure_response( [ 'id' => $id ] );
				},
			]
		);

		register_rest_route(
			self::NS,
			'/bulk',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$result = ScheduleManager::bulk_enqueue(
						max( 1, (int) $request->get_param( 'count' ) ),
						(string) $request->get_param( 'start' ),
						(string) $request->get_param( 'end' ),
						(array) $request->get_param( 'pages' ),
						max( 5, (int) $request->get_param( 'interval_minutes' ) ),
						'direct' === (string) $request->get_param( 'mode' ) ? 'direct' : 'ai'
					);

					if ( is_wp_error( $result ) ) {
						return new \WP_Error( 'aifbp_bulk', $result->get_error_message(), [ 'status' => 400 ] );
					}

					return rest_ensure_response( [ 'enqueued' => $result ] );
				},
			]
		);

		register_rest_route(
			self::NS,
			'/calendar',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$result = CalendarManager::generate(
						[
							'pages'          => (array) $request->get_param( 'pages' ),
							'duration_days'  => max( 1, min( 60, (int) $request->get_param( 'duration_days' ) ) ),
							'posts_per_day'  => max( 1, min( 6, (int) $request->get_param( 'posts_per_day' ) ) ),
							'mode'           => 'ai' === (string) $request->get_param( 'mode' ) ? 'ai' : 'direct',
							'content_type'   => (string) $request->get_param( 'content_type' ),
							'times'          => (string) $request->get_param( 'times' ),
							'categories'     => array_map( 'absint', (array) $request->get_param( 'categories' ) ),
							'selection'      => (string) $request->get_param( 'selection' ),
							'start_date'     => (string) $request->get_param( 'start_date' ),
							'objective'      => sanitize_text_field( (string) $request->get_param( 'objective' ) ),
							'tone'           => sanitize_text_field( (string) $request->get_param( 'tone' ) ),
							'direct_format'  => sanitize_key( (string) $request->get_param( 'direct_format' ) ),
						]
					);

					if ( is_wp_error( $result ) ) {
						return new \WP_Error( 'aifbp_calendar', $result->get_error_message(), [ 'status' => 400 ] );
					}

					return rest_ensure_response( $result );
				},
			]
		);

		register_rest_route(
			self::NS,
			'/poster',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( \WP_REST_Request $request ) {
					$product = Picker::random_product( $request->get_param( 'product_id' ) ? (int) $request->get_param( 'product_id' ) : null );

					if ( is_wp_error( $product ) ) {
						return new \WP_Error( 'aifbp_products', $product->get_error_message(), [ 'status' => 400 ] );
					}

					$result = Designer::generate_for_product(
						$product,
						[
							'size'       => sanitize_key( (string) $request->get_param( 'size' ) ),
							'template'   => sanitize_key( (string) $request->get_param( 'template' ) ),
							'save_media' => (bool) $request->get_param( 'save_media' ),
						]
					);

					if ( is_wp_error( $result ) ) {
						return new \WP_Error( 'aifbp_poster', $result->get_error_message(), [ 'status' => 500 ] );
					}

					return rest_ensure_response( $result );
				},
			]
		);
	}
}
