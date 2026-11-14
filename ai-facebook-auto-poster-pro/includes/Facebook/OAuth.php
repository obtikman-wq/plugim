<?php

namespace AIFB\Facebook;

use AIFB\Utils\Settings;
use AIFB\Utils\Crypto;
use AIFB\Utils\Logger;
use AIFB\Utils\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuth {

	const GRAPH_VERSION = 'v21.0';
	const SCOPES        = 'pages_show_list,pages_manage_posts,pages_read_engagement,business_management';

	public static function redirect_uri(): string {
		return add_query_arg( 'action', 'aifbp_oauth_callback', admin_url( 'admin-post.php' ) );
	}

	public static function is_configured(): bool {
		$s = Settings::all();
		return '' !== $s['app_id'] && '' !== $s['app_secret'];
	}

	public static function login_url(): string {
		$state = wp_generate_password( 24, false );
		set_transient( 'aifbp_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		return add_query_arg(
			[
				'client_id'     => Settings::get( 'app_id' ),
				'redirect_uri'  => self::redirect_uri(),
				'state'         => $state,
				'scope'         => self::SCOPES,
				'response_type' => 'code',
			],
			'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth'
		);
	}

	public static function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'aifbp' ) );
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';

		$expected = get_transient( 'aifbp_oauth_state' );
		delete_transient( 'aifbp_oauth_state' );

		$back = admin_url( 'admin.php?page=aifbp&tab=connection' );

		if ( '' !== $error ) {
			Logger::error( 'oauth', $error );
			wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( $error ), $back ) );
			exit;
		}

		if ( '' === $code || '' === $expected || ! hash_equals( $expected, $state ) ) {
			wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( __( 'Invalid OAuth state. Please try again.', 'aifbp' ) ), $back ) );
			exit;
		}

		$s = Settings::all();

		$token_body = self::exchange(
			[
				'client_id'     => $s['app_id'],
				'client_secret' => $s['app_secret'],
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
			]
		);

		if ( is_wp_error( $token_body ) ) {
			Logger::error( 'oauth', $token_body->get_error_message() );
			wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( $token_body->get_error_message() ), $back ) );
			exit;
		}

		$short_token = (string) ( $token_body['access_token'] ?? '' );

		if ( '' === $short_token ) {
			wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( __( 'Facebook did not return an access token.', 'aifbp' ) ), $back ) );
			exit;
		}

		Usage::track( 'fb' );

		$long_body = self::exchange(
			[
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $s['app_id'],
				'client_secret'     => $s['app_secret'],
				'fb_exchange_token' => $short_token,
			]
		);

		if ( is_wp_error( $long_body ) ) {
			Logger::error( 'oauth', $long_body->get_error_message() );
			wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( $long_body->get_error_message() ), $back ) );
			exit;
		}

		$user_token  = (string) ( $long_body['access_token'] ?? $short_token );
		$expires_in  = (int) ( $long_body['expires_in'] ?? 0 );
		$expiry_time = $expires_in > 0 ? time() + $expires_in : 0;

		update_option(
			'aifbp_oauth_token',
			[
				'token'   => Crypto::encrypt( $user_token ),
				'expires' => $expiry_time,
				'user_id' => get_current_user_id(),
			],
			false
		);

		Usage::track( 'fb' );

		$pages = Client::fetch_pages( $user_token );

		if ( is_wp_error( $pages ) ) {
			Logger::error( 'oauth', $pages->get_error_message() );
			wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( $pages->get_error_message() ), $back ) );
			exit;
		}

		$stored = Settings::pages();

		foreach ( $pages as $page ) {
			$stored[ $page['id'] ] = [
				'id'           => $page['id'],
				'name'         => $page['name'],
				'token'        => Crypto::encrypt( $page['access_token'] ),
				'connected_at' => time(),
			];
		}

		Settings::save_pages( $stored );

		Logger::success( 'oauth', sprintf( 'Connected %d Facebook page(s).', count( $pages ) ) );

		wp_safe_redirect( add_query_arg( 'aifbp_msg', rawurlencode( sprintf( 'Connected %d page(s).', count( $pages ) ) ), $back ) );
		exit;
	}

	public static function user_token(): string {
		$stored = get_option( 'aifbp_oauth_token', [] );

		if ( ! is_array( $stored ) || empty( $stored['token'] ) ) {
			return '';
		}

		return Crypto::decrypt( (string) $stored['token'] );
	}

	public static function token_expiry(): int {
		$stored = get_option( 'aifbp_oauth_token', [] );
		return is_array( $stored ) ? (int) ( $stored['expires'] ?? 0 ) : 0;
	}

	public static function disconnect(): void {
		delete_option( 'aifbp_oauth_token' );
		Settings::save_pages( [] );
	}

	private static function exchange( array $params ) {
		$response = wp_remote_get(
			'https://graph.facebook.com/' . self::GRAPH_VERSION . '/oauth/access_token',
			[ 'timeout' => 30, 'body' => $params ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'aifbp_fb', 'Invalid response from Facebook.' );
		}

		if ( isset( $body['error']['message'] ) ) {
			return new \WP_Error( 'aifbp_fb', (string) $body['error']['message'] );
		}

		return $body;
	}
}
