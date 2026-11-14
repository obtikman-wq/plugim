<?php

namespace AIFB\Assistants;

use AIFB\Utils\Settings;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhooks {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			'aifbp/v1',
			'/webhook',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'verify_subscription' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'receive' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	public static function verify_subscription( \WP_REST_Request $request ) {
		$mode      = (string) $request->get_param( 'hub.mode' );
		$token     = (string) $request->get_param( 'hub.verify_token' );
		$challenge = (string) $request->get_param( 'hub.challenge' );

		$expected = (string) Settings::get( 'webhook_verify_token' );

		if ( 'subscribe' !== $mode || '' === $expected || ! hash_equals( $expected, $token ) ) {
			Logger::warning( 'webhook', 'Webhook subscription verification failed.' );

			return new \WP_Error( 'aifbp_webhook_verify', __( 'Verification failed.', 'aifbp' ), [ 'status' => 403 ] );
		}

		$response = new \WP_REST_Response( $challenge );
		$response->set_headers( [ 'Content-Type' => 'text/plain; charset=utf-8' ] );

		return $response;
	}

	public static function receive( \WP_REST_Request $request ) {
		$body = (string) $request->get_body();

		if ( ! self::verify_signature( $body ) ) {
			Logger::warning( 'webhook', 'Invalid X-Hub-Signature-256.' );

			return new \WP_Error( 'aifbp_webhook_signature', __( 'Invalid signature.', 'aifbp' ), [ 'status' => 401 ] );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( [ 'ok' => true ] );
		}

		foreach ( (array) ( $payload['entry'] ?? [] ) as $entry ) {
			$page_id = (string) ( $entry['id'] ?? '' );

			foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
				self::handle_change( $page_id, (array) $change );
			}

			foreach ( (array) ( $entry['messaging'] ?? [] ) as $messaging ) {
				Inbox::handle_event( $page_id, (array) $messaging );
			}
		}

		return new \WP_REST_Response( [ 'ok' => true ] );
	}

	private static function handle_change( string $page_id, array $change ): void {
		$field = (string) ( $change['field'] ?? '' );
		$value = (array) ( $change['value'] ?? [] );

		if ( 'feed' === $field ) {
			Comments::handle_event( $page_id, $value );

			return;
		}

		if ( 'messages' === $field ) {
			Inbox::handle_event( $page_id, $value );
		}
	}

	private static function verify_signature( string $body ): bool {
		$secret = (string) Settings::get( 'app_secret' );

		if ( '' === $secret ) {
			Logger::warning( 'webhook', 'App secret not configured; webhook rejected.' );

			return false;
		}

		$header = (string) ( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '' );

		if ( '' === $header || ! str_starts_with( $header, 'sha256=' ) ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		return hash_equals( $expected, $header );
	}
}
