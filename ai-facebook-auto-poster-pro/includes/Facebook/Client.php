<?php

namespace AIFB\Facebook;

use AIFB\Utils\Usage;
use AIFB\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Client {

	const GRAPH = 'https://graph.facebook.com/v23.0/';

	public static function fetch_pages( string $user_token ) {
		$body = self::get( 'me/accounts', [ 'fields' => 'id,name,access_token,picture{url},tasks', 'limit' => 100 ], $user_token );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$pages = [];

		foreach ( $body['data'] ?? [] as $page ) {
			if ( ! empty( $page['id'] ) && ! empty( $page['access_token'] ) ) {
				$pages[] = [
					'id'           => (string) $page['id'],
					'name'         => (string) ( $page['name'] ?? $page['id'] ),
					'access_token' => (string) $page['access_token'],
					'picture'      => (string) ( $page['picture']['data']['url'] ?? '' ),
					'can_post'     => in_array( 'CREATE_CONTENT', (array) ( $page['tasks'] ?? [] ), true ) || in_array( 'MANAGE', (array) ( $page['tasks'] ?? [] ), true ),
				];
			}
		}

		if ( empty( $pages ) ) {
			return new \WP_Error( 'aifbp_fb', 'No Facebook Pages found for this account.' );
		}

		return $pages;
	}

	public static function verify_page_token( string $page_id, string $page_token ) {
		$body = self::get( $page_id, [ 'fields' => 'id,name' ], $page_token );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return [
			'id'   => (string) ( $body['id'] ?? '' ),
			'name' => (string) ( $body['name'] ?? '' ),
		];
	}

	public static function post_photo( string $page_id, string $page_token, string $image_url, string $caption ) {
		Usage::track( 'fb' );

		$response = wp_remote_post(
			self::GRAPH . $page_id . '/photos',
			[
				'timeout' => 60,
				'body'    => [
					'url'          => $image_url,
					'caption'      => $caption,
					'access_token' => $page_token,
				],
			]
		);

		return self::result( $response, 'photos' );
	}

	public static function post_feed( string $page_id, string $page_token, string $message, string $link = '' ) {
		Usage::track( 'fb' );

		$args = [
			'message'      => $message,
			'access_token' => $page_token,
		];

		if ( '' !== $link ) {
			$args['link'] = $link;
		}

		$response = wp_remote_post(
			self::GRAPH . $page_id . '/feed',
			[ 'timeout' => 60, 'body' => $args ]
		);

		return self::result( $response, 'feed' );
	}

	public static function post_insights( string $page_id, string $page_token, string $post_id ) {
		Usage::track( 'fb' );

		$body = self::get(
			$post_id . '/insights',
			[ 'metric' => 'post_impressions,post_clicks,post_reactions_like_total,post_comments' ],
			$page_token
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$values = [];

		foreach ( $body['data'] ?? [] as $row ) {
			$name            = (string) ( $row['name'] ?? '' );
			$values[ $name ] = (int) ( $row['values'][0]['value'] ?? 0 );
		}

		return [
			'impressions' => $values['post_impressions'] ?? 0,
			'clicks'      => $values['post_clicks'] ?? 0,
			'reactions'   => $values['post_reactions_like_total'] ?? 0,
			'comments'    => $values['post_comments'] ?? 0,
		];
	}

	public static function result( $response, string $context ) {
		if ( is_wp_error( $response ) ) {
			Logger::error( 'fb_' . $context, $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			$message = (string) $body['error']['message'];
			Logger::error( 'fb_' . $context, $message );

			$fb_code = (int) ( $body['error']['code'] ?? 0 );

			if ( in_array( $fb_code, [ 4, 17, 32, 613 ], true ) ) {
				return new \WP_Error( 'aifbp_fb_ratelimit', $message );
			}

			if ( in_array( $fb_code, [ 190, 102, 10 ], true ) ) {
				\AIFB\Facebook\OAuth::flag_auth_invalid( $message );

				return new \WP_Error( 'aifbp_fb_auth', 'Facebook authorization invalid: ' . $message . ' — reconnect on the Facebook Connection tab.' );
			}

			return new \WP_Error( 'aifbp_fb', $message );
		}

		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = sprintf( 'Facebook API HTTP %d', $code );
			Logger::error( 'fb_' . $context, $message );
			return new \WP_Error( 'aifbp_fb', $message );
		}

		$post_id = (string) ( $body['post_id'] ?? $body['id'] ?? '' );

		if ( '' === $post_id ) {
			return new \WP_Error( 'aifbp_fb', 'Facebook returned no post id.' );
		}

		return [ 'post_id' => $post_id ];
	}

	public static function get( string $path, array $args, string $token ) {
		$args['access_token'] = $token;

		$response = wp_remote_get(
			self::GRAPH . $path,
			[ 'timeout' => 30, 'body' => $args ]
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

	public static function get_comments( string $page_id, string $token, string $since = '' ) {
		$args = [
			'fields' => 'id,message,created_time,from{id,name},parent{id}',
			'limit'  => 25,
			'order'  => 'reverse_chronological',
		];

		if ( '' !== $since ) {
			$args['since'] = $since;
		}

		return self::get( $page_id . '/feed', $args, $token );
	}

	public static function post_comment( string $post_id, string $token, string $message ) {
		Usage::track( 'fb' );

		$response = wp_remote_post(
			self::GRAPH . $post_id . '/comments',
			[
				'timeout' => 30,
				'body'    => [
					'message'      => $message,
					'access_token' => $token,
				],
			]
		);

		$body = self::raw( $response, 'comment' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return [ 'comment_id' => (string) ( $body['id'] ?? '' ) ];
	}

	public static function reply_comment( string $comment_id, string $token, string $message ) {
		Usage::track( 'fb' );

		$response = wp_remote_post(
			self::GRAPH . $comment_id . '/comments',
			[
				'timeout' => 30,
				'body'    => [
					'message'      => $message,
					'access_token' => $token,
				],
			]
		);

		$body = self::raw( $response, 'comment_reply' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return [ 'comment_id' => (string) ( $body['id'] ?? '' ) ];
	}

	public static function send_message( string $page_id, string $token, string $recipient_id, string $text ) {
		Usage::track( 'fb' );

		$response = wp_remote_post(
			self::GRAPH . 'me/messages',
			[
				'timeout' => 30,
				'body'    => [
					'recipient'    => wp_json_encode( [ 'id' => $recipient_id ] ),
					'message'      => wp_json_encode( [ 'text' => $text ] ),
					'access_token' => $token,
				],
			]
		);

		$body = self::raw( $response, 'messenger' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return [ 'recipient_id' => (string) ( $body['recipient_id'] ?? $recipient_id ) ];
	}

	public static function page_picture( string $page_id, string $token ): string {
		$body = self::get( $page_id, [ 'fields' => 'picture{url}' ], $token );

		if ( is_wp_error( $body ) ) {
			return '';
		}

		return (string) ( $body['picture']['data']['url'] ?? '' );
	}

	private static function raw( $response, string $context ) {
		if ( is_wp_error( $response ) ) {
			Logger::error( 'fb_' . $context, $response->get_error_message() );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			$message = (string) $body['error']['message'];
			Logger::error( 'fb_' . $context, $message );

			$fb_code = (int) ( $body['error']['code'] ?? 0 );

			if ( in_array( $fb_code, [ 4, 17, 32, 613 ], true ) ) {
				return new \WP_Error( 'aifbp_fb_ratelimit', $message );
			}

			return new \WP_Error( 'aifbp_fb', $message );
		}

		if ( $code >= 400 || ! is_array( $body ) ) {
			return new \WP_Error( 'aifbp_fb', sprintf( 'Facebook API HTTP %d', $code ) );
		}

		return $body;
	}
}
