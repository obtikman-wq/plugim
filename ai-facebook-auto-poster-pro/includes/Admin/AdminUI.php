<?php

namespace AIFB\Admin;

use AIFB\AI\Manager as AIManager;
use AIFB\Analytics\Analytics;
use AIFB\Assistants\Comments;
use AIFB\Assistants\Inbox;
use AIFB\Calendar\CalendarManager;
use AIFB\Core\DB;
use AIFB\Direct\Engine as DirectEngine;
use AIFB\Facebook\Client;
use AIFB\Facebook\OAuth;
use AIFB\Insights\Advisor;
use AIFB\Poster\Designer;
use AIFB\Product\Rotation;
use AIFB\Product\Templates;
use AIFB\Queue\Processor;
use AIFB\Queue\QueueManager;
use AIFB\Schedule\ScheduleManager;
use AIFB\Utils\Crypto;
use AIFB\Utils\Logger;
use AIFB\Utils\Settings;
use AIFB\Utils\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminUI {

	const PAGE = 'aifbp';

	const TABS = [
		'dashboard'  => 'Dashboard',
		'connection' => 'Facebook Connection',
		'ai'         => 'AI Settings',
		'direct'     => 'Direct Auto Posting',
		'ai_posting' => 'AI Auto Posting',
		'calendar'   => 'Marketing Calendar',
		'poster'     => 'Product Poster Designer',
		'rotation'   => 'Product Rotation',
		'comments'   => 'Comment Assistant',
		'inbox'      => 'Inbox Assistant',
		'insights'   => 'Best Time & Product',
		'queue'      => 'Post Queue',
		'history'    => 'Post History',
		'analytics'  => 'Analytics',
		'templates'  => 'Templates',
		'tools'      => 'Tools',
		'status'     => 'System Status',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_action( 'admin_post_aifbp_export_csv', [ $this, 'export_csv' ] );
	}

	public function menu(): void {
		add_menu_page(
			__( 'AI FB Auto Poster Pro', 'aifbp' ),
			__( 'FB Auto Poster Pro', 'aifbp' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ],
			'dashicons-facebook-alt',
			56
		);

		foreach ( self::TABS as $slug => $label ) {
			add_submenu_page(
				self::PAGE,
				__( $label, 'aifbp' ),
				__( $label, 'aifbp' ),
				'manage_options',
				'dashboard' === $slug ? self::PAGE : self::PAGE . '&tab=' . $slug,
				[ $this, 'render' ]
			);
		}
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'aifbp' ) ) {
			return;
		}

		wp_enqueue_style( 'aifbp-admin', AIFB_URL . 'assets/css/admin.css', [], AIFB_VERSION );
		wp_enqueue_script( 'aifbp-admin', AIFB_URL . 'assets/js/admin.js', [ 'wp-api-fetch' ], AIFB_VERSION, true );

		wp_localize_script(
			'aifbp-admin',
			'AIFBP',
			[
				'rest'  => esc_url_raw( rest_url( 'aifbp/v1' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$expiry = OAuth::token_expiry();

		if ( $expiry > 0 && $expiry < time() + 7 * DAY_IN_SECONDS ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'AI FB Poster: your Facebook user token is expiring soon. Reconnect on the Facebook Connection page to keep scheduled posts working.', 'aifbp' )
			);
		}

		$recent = get_transient( 'aifbp_recent_errors' );

		if ( is_array( $recent ) && ! empty( $recent ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'AI FB Poster recent error:', 'aifbp' ),
				esc_html( (string) $recent[0]['message'] )
			);
		}
	}

	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'aifbp' ) );
		}

		check_admin_referer( 'aifbp_export' );

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'history';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=aifbp-' . $type . '-' . date( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		if ( 'analytics' === $type ) {
			fputcsv( $out, [ 'posted_at', 'product', 'page', 'mode', 'impressions', 'clicks', 'reactions', 'comments', 'fb_post_id' ] );

			global $wpdb;
			$rows = $wpdb->get_results(
				'SELECT q.posted_at, q.product_name, q.page_name, q.mode, a.impressions, a.clicks, a.reactions, a.comments, a.fb_post_id
				FROM ' . DB::table_queue() . ' q INNER JOIN ' . DB::table_analytics() . ' a ON a.queue_id = q.id
				WHERE q.status = \'posted\' ORDER BY q.posted_at DESC LIMIT 5000',
				ARRAY_A
			) ?: [];

			foreach ( $rows as $row ) {
				fputcsv( $out, $row );
			}
		} else {
			fputcsv( $out, [ 'posted_at', 'product', 'page', 'mode', 'content_type', 'fb_post_id' ] );

			foreach ( QueueManager::list( 'posted', 5000 ) as $item ) {
				fputcsv(
					$out,
					[
						$item['posted_at'],
						$item['product_name'],
						$item['page_name'],
						$item['mode'] ?? 'ai',
						$item['content_type'] ?? '',
						$item['fb_post_id'],
					]
				);
			}
		}

		fclose( $out );
		exit;
	}

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE !== $page ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		$redirect = static function ( string $tab, string $msg = '' ) use ( $tab ): never {
			$args = [ 'page' => self::PAGE, 'tab' => $tab ];

			if ( '' !== $msg ) {
				$args['aifbp_msg'] = rawurlencode( $msg );
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		};

		if ( isset( $_POST['aifbp_save_settings'] ) ) {
			check_admin_referer( 'aifbp_settings' );
			$this->save_settings();
			$redirect( $tab, __( 'Settings saved.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_disconnect'] ) ) {
			check_admin_referer( 'aifbp_settings' );
			OAuth::disconnect();
			$redirect( 'connection', __( 'Disconnected from Facebook.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_test_fb'] ) ) {
			check_admin_referer( 'aifbp_settings' );
			$this->save_settings();

			$page_id = (string) Settings::get( 'test_page_id' );
			$token   = Settings::page_token( $page_id );
			$result  = Client::verify_page_token( $page_id, $token );

			$redirect( 'connection', is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Page OK: %s (%s)', $result['name'], $result['id'] ) );
		}

		if ( isset( $_POST['aifbp_refresh_pages'] ) ) {
			check_admin_referer( 'aifbp_connection' );
			$result = OAuth::refresh_pages();
			$redirect( 'connection', is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%d page(s) refreshed.', $result ) );
		}

		if ( isset( $_POST['aifbp_save_page_selection'] ) ) {
			check_admin_referer( 'aifbp_connection' );

			$selected = array_map( 'sanitize_text_field', (array) ( $_POST['selected_pages'] ?? [] ) );
			$stored   = Settings::pages();

			foreach ( $stored as $pid => $data ) {
				$stored[ $pid ]['selected'] = in_array( (string) $pid, $selected, true ) ? 1 : 0;
			}

			Settings::save_pages( $stored );
			$redirect( 'connection', __( 'Page selection saved.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_create_schedule'] ) ) {
			check_admin_referer( 'aifbp_schedule' );

			$result = ScheduleManager::create(
				[
					'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
					'type'             => sanitize_key( wp_unslash( $_POST['type'] ?? 'daily' ) ),
					'time_of_day'      => sanitize_text_field( wp_unslash( $_POST['time_of_day'] ?? '10:00' ) ),
					'times'            => sanitize_text_field( wp_unslash( $_POST['times'] ?? '' ) ),
					'window_start'     => sanitize_text_field( wp_unslash( $_POST['window_start'] ?? '' ) ),
					'window_end'       => sanitize_text_field( wp_unslash( $_POST['window_end'] ?? '' ) ),
					'mode'             => sanitize_key( wp_unslash( $_POST['mode'] ?? 'ai' ) ),
					'selection'        => sanitize_key( wp_unslash( $_POST['selection'] ?? 'random' ) ),
					'categories'       => array_map( 'absint', (array) ( $_POST['categories'] ?? [] ) ),
					'content_format'   => sanitize_key( wp_unslash( $_POST['content_format'] ?? '' ) ),
					'template_name'    => sanitize_text_field( wp_unslash( $_POST['template_name'] ?? '' ) ),
					'days'             => implode( ',', array_map( 'absint', (array) ( $_POST['days'] ?? [] ) ) ),
					'posts_per_run'    => absint( wp_unslash( $_POST['posts_per_run'] ?? 1 ) ),
					'interval_minutes' => absint( wp_unslash( $_POST['interval_minutes'] ?? 120 ) ),
					'pages'            => array_map( 'sanitize_text_field', (array) ( $_POST['pages'] ?? [] ) ),
					'start_date'       => sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ),
					'end_date'         => sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) ),
					'active'           => 1,
				]
			);

			$redirect( 'direct', is_wp_error( $result ) ? $result->get_error_message() : __( 'Schedule created.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_bulk_schedule'] ) ) {
			check_admin_referer( 'aifbp_schedule' );

			$result = ScheduleManager::bulk_enqueue(
				absint( wp_unslash( $_POST['count'] ?? 1 ) ),
				sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['end'] ?? '' ) ),
				array_map( 'sanitize_text_field', (array) ( $_POST['pages'] ?? [] ) ),
				absint( wp_unslash( $_POST['interval_minutes'] ?? 60 ) ),
				sanitize_key( wp_unslash( $_POST['mode'] ?? 'ai' ) )
			);

			$redirect( $tab, is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%d post(s) queued.', $result ) );
		}

		if ( isset( $_POST['aifbp_delete_schedule'], $_POST['schedule_id'] ) ) {
			check_admin_referer( 'aifbp_schedule' );
			ScheduleManager::delete( absint( wp_unslash( $_POST['schedule_id'] ) ) );
			$redirect( $tab, __( 'Schedule deleted.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_toggle_schedule'], $_POST['schedule_id'] ) ) {
			check_admin_referer( 'aifbp_schedule' );

			$id       = absint( wp_unslash( $_POST['schedule_id'] ) );
			$schedule = ScheduleManager::get( $id );

			if ( $schedule ) {
				ScheduleManager::set_active( $id, empty( $schedule['active'] ) );
			}

			$redirect( $tab );
		}

		if ( isset( $_POST['aifbp_clear_log'] ) ) {
			check_admin_referer( 'aifbp_tools' );
			Logger::clear();
			$redirect( 'tools', __( 'Log cleared.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_refresh_analytics'] ) ) {
			check_admin_referer( 'aifbp_tools' );
			$updated = Analytics::refresh_all( 50 );
			$redirect( $tab, sprintf( '%d post(s) refreshed.', $updated ) );
		}

		if ( isset( $_POST['aifbp_queue_action'], $_POST['queue_id'] ) ) {
			check_admin_referer( 'aifbp_queue' );

			$id       = absint( wp_unslash( $_POST['queue_id'] ) );
			$action   = sanitize_key( wp_unslash( $_POST['aifbp_queue_action'] ) );
			$back_tab = sanitize_key( wp_unslash( $_POST['back_tab'] ?? 'queue' ) );

			switch ( $action ) {
				case 'delete':
					QueueManager::delete( $id );
					$redirect( $back_tab, __( 'Post deleted.', 'aifbp' ) );
					break;

				case 'cancel':
					QueueManager::cancel( $id );
					$redirect( $back_tab, __( 'Post cancelled.', 'aifbp' ) );
					break;

				case 'duplicate':
					$result = QueueManager::duplicate( $id );
					$redirect( $back_tab, is_wp_error( $result ) ? $result->get_error_message() : __( 'Post duplicated.', 'aifbp' ) );
					break;

				case 'pause':
					global $wpdb;
					$wpdb->update( DB::table_queue(), [ 'status' => 'draft' ], [ 'id' => $id ] );
					$redirect( $back_tab, __( 'Post paused.', 'aifbp' ) );
					break;

				case 'resume':
					QueueManager::reschedule( $id, current_time( 'mysql' ) );
					$redirect( $back_tab, __( 'Post resumed.', 'aifbp' ) );
					break;

				case 'reschedule':
					$when = sanitize_text_field( wp_unslash( $_POST['reschedule_at'] ?? '' ) );
					$ts   = strtotime( $when );

					if ( false === $ts ) {
						$redirect( $back_tab, __( 'Invalid date/time.', 'aifbp' ) );
					}

					QueueManager::reschedule( $id, date( 'Y-m-d H:i:s', $ts ) );
					$redirect( $back_tab, __( 'Post rescheduled.', 'aifbp' ) );
					break;

				case 'publish':
					QueueManager::reschedule( $id, current_time( 'mysql' ) );
					$item = QueueManager::get( $id );

					if ( $item && 'pending' === $item['status'] ) {
						$item['attempts'] = (int) $item['attempts'];
						$updated          = $GLOBALS['wpdb']->query(
							$GLOBALS['wpdb']->prepare(
								'UPDATE ' . DB::table_queue() . " SET status = 'processing', attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
								$id
							)
						);

						if ( $updated ) {
							$item = QueueManager::get( $id );
							Processor::process_item( $item );
						}
					}

					$redirect( $back_tab, __( 'Publish attempt finished — check Post History.', 'aifbp' ) );
					break;
			}
		}

		if ( isset( $_POST['aifbp_edit_caption'], $_POST['queue_id'] ) ) {
			check_admin_referer( 'aifbp_queue' );
			QueueManager::set_caption( absint( wp_unslash( $_POST['queue_id'] ) ), (string) wp_unslash( $_POST['caption'] ?? '' ) );
			$redirect( sanitize_key( wp_unslash( $_POST['back_tab'] ?? 'queue' ) ), __( 'Caption saved.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_change_image'], $_POST['queue_id'] ) ) {
			check_admin_referer( 'aifbp_queue' );
			QueueManager::set_image( absint( wp_unslash( $_POST['queue_id'] ) ), (string) wp_unslash( $_POST['image_url'] ?? '' ) );
			$redirect( sanitize_key( wp_unslash( $_POST['back_tab'] ?? 'queue' ) ), __( 'Image updated.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_change_product'], $_POST['queue_id'] ) ) {
			check_admin_referer( 'aifbp_queue' );

			$product_id = absint( wp_unslash( $_POST['new_product_id'] ?? 0 ) );
			$wc         = $product_id > 0 && class_exists( 'WooCommerce' ) ? wc_get_product( $product_id ) : null;

			if ( ! $wc ) {
				$redirect( sanitize_key( wp_unslash( $_POST['back_tab'] ?? 'queue' ) ), __( 'Product not found.', 'aifbp' ) );
			}

			$image_id = $wc->get_image_id();

			QueueManager::set_product(
				absint( wp_unslash( $_POST['queue_id'] ) ),
				$product_id,
				$wc->get_name(),
				$image_id ? (string) wp_get_attachment_url( $image_id ) : ''
			);

			$redirect( sanitize_key( wp_unslash( $_POST['back_tab'] ?? 'queue' ) ), __( 'Product changed.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_test_provider'], $_POST['provider_id'] ) ) {
			check_admin_referer( 'aifbp_settings' );

			$id   = sanitize_key( wp_unslash( $_POST['provider_id'] ) );
			$test = AIManager::test_provider( $id );

			$redirect( 'ai', is_wp_error( $test ) ? sprintf( '%s: %s', ucfirst( $id ), $test->get_error_message() ) : sprintf( '%s: %s', ucfirst( $id ), $test['message'] ) );
		}

		if ( isset( $_POST['aifbp_poll_comments'] ) ) {
			check_admin_referer( 'aifbp_comments' );
			$found = Comments::poll();
			$redirect( 'comments', sprintf( '%d new comment(s) discovered.', $found ) );
		}

		if ( isset( $_POST['aifbp_comment_action'], $_POST['comment_row_id'] ) ) {
			check_admin_referer( 'aifbp_comments' );

			$id     = absint( wp_unslash( $_POST['comment_row_id'] ) );
			$action = sanitize_key( wp_unslash( $_POST['aifbp_comment_action'] ) );

			if ( 'delete' === $action ) {
				global $wpdb;
				$wpdb->delete( DB::table_comments(), [ 'id' => $id ], [ '%d' ] );
				$redirect( 'comments', __( 'Comment removed.', 'aifbp' ) );
			}

			if ( 'approve' === $action ) {
				global $wpdb;
				$wpdb->update( DB::table_comments(), [ 'status' => 'approved' ], [ 'id' => $id ] );
				Comments::send( $id );
				$redirect( 'comments', __( 'Reply sent.', 'aifbp' ) );
			}

			if ( 'regenerate' === $action ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table_comments() . ' WHERE id = %d', $id ), ARRAY_A );

				if ( $row ) {
					$reply = Comments::generate_reply( $row );
					$wpdb->update( DB::table_comments(), [ 'reply_text' => $reply, 'status' => 'suggested' ], [ 'id' => $id ] );
				}

				$redirect( 'comments', __( 'Reply regenerated.', 'aifbp' ) );
			}
		}

		if ( isset( $_POST['aifbp_inbox_action'], $_POST['page_id'], $_POST['sender_id'] ) ) {
			check_admin_referer( 'aifbp_inbox' );

			$page_id   = sanitize_text_field( wp_unslash( $_POST['page_id'] ) );
			$sender_id = sanitize_text_field( wp_unslash( $_POST['sender_id'] ) );
			$action    = sanitize_key( wp_unslash( $_POST['aifbp_inbox_action'] ) );

			if ( 'handoff' === $action ) {
				Inbox::set_handoff( $page_id, $sender_id, true );
				$redirect( 'inbox', __( 'Human takeover enabled.', 'aifbp' ) );
			}

			if ( 'resume' === $action ) {
				Inbox::set_handoff( $page_id, $sender_id, false );
				$redirect( 'inbox', __( 'AI assistant resumed.', 'aifbp' ) );
			}

			if ( 'reply' === $action ) {
				$text = trim( (string) wp_unslash( $_POST['reply_text'] ?? '' ) );

				if ( '' !== $text ) {
					Inbox::reply_manual( $page_id, $sender_id, $text );
				}

				$redirect( 'inbox', __( 'Reply sent.', 'aifbp' ) );
			}
		}

		if ( isset( $_POST['aifbp_generate_poster'] ) ) {
			check_admin_referer( 'aifbp_poster' );

			$product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
			$product    = $product_id > 0 && class_exists( 'WooCommerce' ) ? \AIFB\Product\Picker::random_product( $product_id ) : null;

			if ( is_wp_error( $product ) || null === $product ) {
				$redirect( 'poster', __( 'Product not found.', 'aifbp' ) );
			}

			$result = Designer::generate_for_product(
				$product,
				[
					'size'       => sanitize_key( wp_unslash( $_POST['poster_size'] ?? 'square' ) ),
					'template'   => sanitize_key( wp_unslash( $_POST['poster_template'] ?? 'classic' ) ),
					'save_media' => isset( $_POST['save_media'] ),
				]
			);

			$redirect( 'poster', is_wp_error( $result ) ? $result->get_error_message() : __( 'Poster generated.', 'aifbp' ) );
		}

		if ( isset( $_POST['aifbp_generate_calendar'] ) ) {
			check_admin_referer( 'aifbp_calendar' );

			$result = CalendarManager::generate(
				[
					'pages'          => array_map( 'sanitize_text_field', (array) ( $_POST['pages'] ?? [] ) ),
					'duration_days'  => absint( wp_unslash( $_POST['duration_days'] ?? 7 ) ),
					'posts_per_day'  => absint( wp_unslash( $_POST['posts_per_day'] ?? 1 ) ),
					'mode'           => sanitize_key( wp_unslash( $_POST['mode'] ?? 'direct' ) ),
					'content_type'   => sanitize_key( wp_unslash( $_POST['content_type'] ?? 'product_promotion' ) ),
					'times'          => sanitize_text_field( wp_unslash( $_POST['times'] ?? '10:00' ) ),
					'categories'     => array_map( 'absint', (array) ( $_POST['categories'] ?? [] ) ),
					'selection'      => sanitize_key( wp_unslash( $_POST['selection'] ?? 'random' ) ),
					'start_date'     => sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ),
					'objective'      => sanitize_text_field( wp_unslash( $_POST['objective'] ?? '' ) ),
					'tone'           => sanitize_text_field( wp_unslash( $_POST['tone'] ?? '' ) ),
					'direct_format'  => sanitize_key( wp_unslash( $_POST['direct_format'] ?? '' ) ),
				]
			);

			$redirect( 'calendar', is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%d calendar post(s) created (%d skipped).', $result['created'], $result['skipped'] ) );
		}

		if ( isset( $_POST['aifbp_calendar_action'], $_POST['queue_id'] ) ) {
			check_admin_referer( 'aifbp_calendar' );

			$id     = absint( wp_unslash( $_POST['queue_id'] ) );
			$action = sanitize_key( wp_unslash( $_POST['aifbp_calendar_action'] ) );

			if ( 'pause' === $action ) {
				CalendarManager::set_item_status( $id, 'draft' );
				$redirect( 'calendar', __( 'Calendar post paused.', 'aifbp' ) );
			}

			if ( 'resume' === $action ) {
				CalendarManager::set_item_status( $id, 'pending' );
				$redirect( 'calendar', __( 'Calendar post resumed.', 'aifbp' ) );
			}

			if ( 'delete' === $action ) {
				QueueManager::delete( $id );
				$redirect( 'calendar', __( 'Calendar post deleted.', 'aifbp' ) );
			}

			if ( 'publish' === $action ) {
				QueueManager::reschedule( $id, current_time( 'mysql' ) );
				$redirect( 'calendar', __( 'Calendar post queued for immediate publish.', 'aifbp' ) );
			}
		}
	}

	private function save_settings(): void {
		$s = Settings::all();

		$text_keys = [
			'app_id', 'app_secret', 'ai_provider', 'openai_model', 'openai_base',
			'gemini_model', 'custom_base', 'custom_model', 'caption_language',
			'currency', 'test_page_id', 'direct_format', 'direct_hashtags', 'direct_cta',
			'assistant_language', 'assistant_tone', 'assistant_provider', 'webhook_verify_token',
			'poster_size', 'poster_template', 'poster_bg', 'poster_accent', 'poster_text',
			'poster_brand', 'poster_cta', 'poster_font_path', 'posting_mode',
			'groq_model', 'openrouter_model', 'image_provider', 'image_model', 'default_page',
		];

		foreach ( $text_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( [ 'openai_key', 'gemini_key', 'custom_key', 'groq_key', 'openrouter_key' ] as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== (string) $_POST[ $key ] ) {
				$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( [ 'templates', 'direct_template', 'assistant_instructions', 'assistant_restricted' ] as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$s[ $key ] = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( [ 'include_price', 'include_link', 'include_hashtags', 'include_cta', 'price_badge', 'ai_image_gen', 'ai_image_understand', 'ai_fallback_direct', 'skip_out_of_stock', 'comment_assistant', 'inbox_assistant', 'assistant_auto_reply', 'assistant_approval', 'assistant_privacy', 'poster_enabled', 'poster_badge', 'poster_save_media', 'ai_fallback', 'usage_alerts', 'provider_enabled_gemini', 'provider_enabled_groq', 'provider_enabled_openrouter', 'provider_enabled_custom', 'provider_enabled_openai' ] as $key ) {
			$s[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}

		$s['variations']          = max( 0, min( 5, absint( wp_unslash( $_POST['variations'] ?? 2 ) ) ) );
		$s['cooldown_days']       = max( 0, absint( wp_unslash( $_POST['cooldown_days'] ?? 7 ) ) );
		$s['max_attempts']        = max( 1, absint( wp_unslash( $_POST['max_attempts'] ?? 3 ) ) );
		$s['retry_delay_min']     = max( 1, absint( wp_unslash( $_POST['retry_delay_min'] ?? 30 ) ) );
		$s['post_interval_min']   = max( 5, absint( wp_unslash( $_POST['post_interval_min'] ?? 60 ) ) );
		$s['repeat_after_days']   = max( 0, absint( wp_unslash( $_POST['repeat_after_days'] ?? 30 ) ) );
		$s['data_retention_days'] = max( 7, absint( wp_unslash( $_POST['data_retention_days'] ?? 90 ) ) );
		$s['ai_daily_limit']      = max( 0, absint( wp_unslash( $_POST['ai_daily_limit'] ?? 0 ) ) );
		$s['ai_monthly_limit']    = max( 0, absint( wp_unslash( $_POST['ai_monthly_limit'] ?? 0 ) ) );
		$s['ai_timeout']          = max( 10, min( 300, absint( wp_unslash( $_POST['ai_timeout'] ?? 30 ) ) ) );
		$s['ai_retry_limit']      = max( 1, min( 10, absint( wp_unslash( $_POST['ai_retry_limit'] ?? 2 ) ) ) );

		if ( ! in_array( $s['image_provider'], [ 'off', 'openai' ], true ) ) {
			$s['image_provider'] = 'off';
		}

		$pages_list = Settings::pages();

		if ( '' !== (string) $s['default_page'] && ! isset( $pages_list[ (string) $s['default_page'] ] ) ) {
			$s['default_page'] = '';
		}

		if ( ! in_array( $s['posting_mode'], [ 'ai', 'direct', 'both' ], true ) ) {
			$s['posting_mode'] = 'both';
		}

		if ( '' !== (string) $s['custom_base'] && ! wp_http_validate_url( $s['custom_base'] ) ) {
			$s['custom_base'] = '';
		}

		if ( '' !== (string) $s['openai_base'] && ! wp_http_validate_url( $s['openai_base'] ) ) {
			$s['openai_base'] = 'https://api.openai.com/v1';
		}

		if ( '' !== (string) $s['poster_font_path'] && ! is_readable( $s['poster_font_path'] ) ) {
			$s['poster_font_path'] = '';
		}

		Settings::update( $s );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$msg = isset( $_GET['aifbp_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['aifbp_msg'] ) ) ) : '';

		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'dashboard';
		}

		?>
		<div class="wrap aifbp-wrap">
			<h1 class="aifbp-title"><?php esc_html_e( 'AI Facebook Product Auto Poster Pro', 'aifbp' ); ?></h1>

			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success is-dismissible aifbp-fade"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<nav class="aifbp-tabs nav-tab-wrapper">
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( __( $label, 'aifbp' ) ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="aifbp-content">
				<?php
				switch ( $tab ) {
					case 'connection':
						$this->tab_connection();
						break;
					case 'ai':
						$this->tab_ai();
						break;
					case 'direct':
						$this->tab_direct();
						break;
					case 'ai_posting':
						$this->tab_ai_posting();
						break;
					case 'calendar':
						$this->tab_calendar();
						break;
					case 'poster':
						$this->tab_poster();
						break;
					case 'rotation':
						$this->tab_rotation();
						break;
					case 'comments':
						$this->tab_comments();
						break;
					case 'inbox':
						$this->tab_inbox();
						break;
					case 'insights':
						$this->tab_insights();
						break;
					case 'queue':
						$this->tab_queue();
						break;
					case 'history':
						$this->tab_history();
						break;
					case 'analytics':
						$this->tab_analytics();
						break;
					case 'templates':
						$this->tab_templates();
						break;
					case 'tools':
						$this->tab_tools();
						break;
					case 'status':
						$this->tab_status();
						break;
					default:
						$this->tab_dashboard();
				}
				?>
			</div>

			<?php $this->footer(); ?>
		</div>
		<?php
	}

	private function footer(): void {
		$wa_url = 'https://wa.me/8801618576564';
		?>
		<div class="aifbp-footer">
			<div class="aifbp-footer-support">
				<span class="aifbp-footer-label"><?php esc_html_e( 'Need help?', 'aifbp' ); ?></span>
				<a class="aifbp-wa-btn" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer">
					<svg viewBox="0 0 32 32" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.04 3C9.02 3 3.32 8.7 3.32 15.72c0 2.24.59 4.42 1.7 6.35L3.2 29l7.09-1.86a12.7 12.7 0 0 0 5.75 1.38h.01c7.01 0 12.72-5.7 12.72-12.72C28.77 8.7 23.06 3 16.04 3Zm0 23.28c-1.9 0-3.76-.51-5.38-1.48l-.39-.23-4.02 1.05 1.07-3.92-.25-.4a10.55 10.55 0 0 1-1.62-5.58c0-5.83 4.75-10.58 10.6-10.58 2.83 0 5.48 1.1 7.48 3.1a10.5 10.5 0 0 1 3.1 7.49c0 5.84-4.76 10.55-10.59 10.55Zm5.8-7.92c-.32-.16-1.98-.98-2.29-1.09-.31-.11-.53-.17-.75.16-.22.32-.86 1.09-1.06 1.31-.19.22-.39.25-.71.08-.32-.16-1.35-.5-2.57-1.59a9.66 9.66 0 0 1-1.78-2.22c-.19-.32-.02-.5.14-.66.15-.14.32-.37.48-.56.16-.19.22-.32.32-.54.11-.22.05-.41-.03-.57-.08-.16-.72-1.82-1-2.49-.26-.65-.53-.56-.72-.57h-.64c-.22 0-.58.08-.88.4-.3.32-1.15 1.13-1.15 2.75 0 1.62 1.18 3.19 1.34 3.41.16.22 2.29 3.5 5.55 4.9.78.34 1.38.54 1.85.69.79.25 1.5.21 2.07.13.63-.09 1.98-.81 2.26-1.59.28-.78.28-1.45.2-1.59-.08-.14-.29-.22-.61-.38Z"/></svg>
					<?php esc_html_e( 'WhatsApp Support', 'aifbp' ); ?>
				</a>
			</div>
			<p class="aifbp-footer-credit">
				<?php
				printf(
					wp_kses_post( __( 'Made by <strong>Ta Organization</strong>', 'aifbp' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	private function page_selector( string $name = 'pages[]', bool $selected_only = false ): void {
		$pages = Settings::pages();

		if ( empty( $pages ) ) {
			echo '<p class="description">' . esc_html__( 'No pages connected yet — connect Facebook first.', 'aifbp' ) . '</p>';

			return;
		}

		foreach ( $pages as $page ) {
			if ( $selected_only && empty( $page['selected'] ) && ! isset( $page['selected'] ) ) {
				// pages saved before V3 default to selected.
			}

			$is_selected = ! isset( $page['selected'] ) || ! empty( $page['selected'] );

			if ( $selected_only && ! $is_selected ) {
				continue;
			}

			printf(
				'<label style="margin-right:12px"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( (string) $page['id'] ),
				checked( $is_selected, true, false ),
				esc_html( (string) $page['name'] )
			);
		}
	}

	private function tab_dashboard(): void {
		$totals  = Analytics::totals();
		$counts  = $totals['queue'];
		$modes   = QueueManager::mode_counts();
		$month   = Usage::month();
		$health  = ScheduleManager::cron_health();
		$connected = '' !== OAuth::user_token();
		$max     = max( 1, ...array_merge( [ 1 ], array_map( 'intval', array_column( $totals['per_day'], 'posts' ) ?: [ 1 ] ) ) );
		$ai_limit = AIManager::limit_error();
		$fb_notice = OAuth::auth_notice();
		?>
		<?php if ( $fb_notice ) : ?>
			<div class="notice notice-error" style="margin:0 0 16px"><p><strong><?php esc_html_e( 'Facebook reconnect needed — posting is paused.', 'aifbp' ); ?></strong> <?php echo esc_html( $fb_notice ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=aifbp&tab=connection' ) ); ?>"><?php esc_html_e( 'Go to Facebook Connection', 'aifbp' ); ?></a></p></div>
		<?php endif; ?>
		<?php if ( is_wp_error( $ai_limit ) ) : ?>
			<div class="notice notice-warning" style="margin:0 0 16px"><p><strong><?php esc_html_e( 'AI generation is paused.', 'aifbp' ); ?></strong> <?php echo esc_html( $ai_limit->get_error_message() ); ?> <?php esc_html_e( 'Direct posting continues working without AI.', 'aifbp' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=aifbp&tab=ai' ) ); ?>"><?php esc_html_e( 'Go to AI Settings', 'aifbp' ); ?></a></p></div>
		<?php endif; ?>
		<div class="aifbp-cards">
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $counts['posted'] ); ?></span><span><?php esc_html_e( 'Posted', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $counts['pending'] ); ?></span><span><?php esc_html_e( 'Pending', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $counts['failed'] ); ?></span><span><?php esc_html_e( 'Failed', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $totals['impressions'] ); ?></span><span><?php esc_html_e( 'Impressions', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $totals['reactions'] ); ?></span><span><?php esc_html_e( 'Reactions', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $totals['clicks'] ); ?></span><span><?php esc_html_e( 'Clicks', 'aifbp' ); ?></span></div>
		</div>

		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Posts per day (last 30 days)', 'aifbp' ); ?></h2>
				<div class="aifbp-chart">
					<?php foreach ( $totals['per_day'] as $row ) : ?>
						<div class="aifbp-bar" title="<?php echo esc_attr( $row['day'] . ': ' . $row['posts'] ); ?>" style="height: <?php echo esc_attr( max( 4, (int) ( 100 * (int) $row['posts'] / $max ) ) ); ?>%"></div>
					<?php endforeach; ?>
					<?php if ( empty( $totals['per_day'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No posts yet.', 'aifbp' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'System snapshot', 'aifbp' ); ?></h2>
				<p><?php printf( esc_html__( 'Facebook: %s', 'aifbp' ), $connected ? '✅ ' . esc_html__( 'connected', 'aifbp' ) : '❌ ' . esc_html__( 'not connected', 'aifbp' ) ); ?></p>
				<p><?php printf( esc_html__( 'Queue engine: %s', 'aifbp' ), esc_html( Processor::action_scheduler_available() ? 'Action Scheduler' : 'WP-Cron' ) ); ?></p>
				<p><?php printf( esc_html__( 'Next queue run: %s', 'aifbp' ), esc_html( (string) ( $health['queue_next_run'] ?? '—' ) ) ); ?></p>
				<p><?php printf( esc_html__( 'Posted via AI: %d | Direct: %d', 'aifbp' ), esc_html( $modes['ai'] ), esc_html( $modes['direct'] ) ); ?></p>
				<p><?php printf( esc_html__( 'Today — AI calls: %d, Facebook calls: %d', 'aifbp' ), esc_html( Usage::today( 'ai' ) ), esc_html( Usage::today( 'fb' ) ) ); ?></p>
				<p><?php printf( esc_html__( 'This month — AI calls: %d, Facebook calls: %d', 'aifbp' ), esc_html( $month['ai'] ), esc_html( $month['fb'] ) ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'aifbp_tools' ); ?>
					<button type="submit" name="aifbp_refresh_analytics" class="button button-secondary"><?php esc_html_e( 'Refresh analytics from Facebook', 'aifbp' ); ?></button>
				</form>
			</div>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Quick actions', 'aifbp' ); ?></h2>
			<p>
				<select id="aifbp-preview-mode">
					<option value="ai"><?php esc_html_e( 'AI preview', 'aifbp' ); ?></option>
					<option value="direct"><?php esc_html_e( 'Direct preview (no AI)', 'aifbp' ); ?></option>
				</select>
				<button type="button" class="button button-primary" id="aifbp-preview-btn"><?php esc_html_e( 'Generate preview', 'aifbp' ); ?></button>
				<button type="button" class="button button-secondary" id="aifbp-postnow-btn"><?php esc_html_e( 'Post now to first page', 'aifbp' ); ?></button>
				<button type="button" class="button button-secondary" id="aifbp-process-btn"><?php esc_html_e( 'Run queue now', 'aifbp' ); ?></button>
			</p>
			<div id="aifbp-preview" class="aifbp-preview" hidden>
				<div class="aifbp-fbpost">
					<div class="aifbp-fbimg"><img id="aifbp-preview-img" src="" alt="" /></div>
					<div class="aifbp-fbcaption"><pre id="aifbp-preview-caption"></pre></div>
				</div>
			</div>
			<div id="aifbp-result" class="aifbp-result"></div>
		</div>
		<?php
	}

	private function tab_connection(): void {
		$s      = Settings::all();
		$pages  = Settings::pages();
		$expiry = OAuth::token_expiry();
		$notice = OAuth::auth_notice();
		$granted = OAuth::granted_permissions();
		$map = [
			'pages_show_list'         => __( 'Facebook connection & page list', 'aifbp' ),
			'pages_manage_posts'      => __( 'Publishing posts (AI + Direct, queue, calendar, posters)', 'aifbp' ),
			'pages_read_engagement'   => __( 'Insights & analytics (reach, impressions, clicks)', 'aifbp' ),
			'pages_manage_engagement' => __( 'Comment assistant replies', 'aifbp' ),
			'pages_read_user_content' => __( 'Reading page comments (comment assistant)', 'aifbp' ),
			'pages_messaging'         => __( 'Messenger inbox assistant', 'aifbp' ),
		];
		?>
		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-error" style="margin:0 0 16px"><p><strong><?php esc_html_e( 'Facebook authorization problem — reconnect required.', 'aifbp' ); ?></strong> <?php echo esc_html( $notice ); ?> <?php esc_html_e( 'All posting, assistants and insights that use Facebook are paused until you reconnect below. Direct WooCommerce data is untouched.', 'aifbp' ); ?></p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'aifbp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="app_id"><?php esc_html_e( 'Facebook App ID', 'aifbp' ); ?></label></th>
					<td><input type="text" id="app_id" name="app_id" class="regular-text" value="<?php echo esc_attr( $s['app_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="app_secret"><?php esc_html_e( 'Facebook App Secret', 'aifbp' ); ?></label></th>
					<td>
						<input type="password" id="app_secret" name="app_secret" class="regular-text" value="<?php echo esc_attr( $s['app_secret'] ); ?>" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Stored encrypted. Required for webhook signature verification. Create a Business app at developers.facebook.com.', 'aifbp' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'One-time Facebook login', 'aifbp' ); ?></h2>
			<?php if ( OAuth::is_configured() ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( OAuth::login_url() ); ?>"><?php echo '' !== OAuth::user_token() ? esc_html__( 'Reconnect Facebook account', 'aifbp' ) : esc_html__( 'Connect Facebook account', 'aifbp' ); ?></a>
				</p>
				<p class="description">
					<?php esc_html_e( 'One authorization powers every module: AI posting, Direct posting, scheduler, calendar, poster publishing, comment assistant, inbox assistant, analytics and best-time analysis. No per-module login or manual token entry is needed. Facebook login and AI API keys are completely separate.', 'aifbp' ); ?><br />
					<?php esc_html_e( 'Add this redirect URI to your Facebook app settings (Valid OAuth Redirect URIs):', 'aifbp' ); ?><br />
					<code><?php echo esc_html( OAuth::redirect_uri() ); ?></code>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Save your App ID and App Secret first, then connect your Facebook account.', 'aifbp' ); ?></p>
			<?php endif; ?>

			<p>
				<?php if ( '' !== OAuth::user_token() ) : ?>
					<span class="aifbp-status aifbp-status-<?php echo OAuth::is_valid() ? 'posted' : 'failed'; ?>">
						<?php echo OAuth::is_valid() ? esc_html__( '✓ Token valid', 'aifbp' ) : esc_html__( '✖ Token invalid or expired', 'aifbp' ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $expiry > 0 ) : ?>
					<span class="aifbp-status aifbp-status-<?php echo $expiry > time() + 7 * DAY_IN_SECONDS ? 'posted' : 'failed'; ?>">
						<?php echo esc_html( sprintf( __( 'User token expires: %s', 'aifbp' ), date( 'Y-m-d H:i', $expiry ) ) ); ?>
					</span>
				<?php endif; ?>
			</p>
		</form>

		<h2><?php esc_html_e( 'Connected pages', 'aifbp' ); ?></h2>
		<?php if ( empty( $pages ) ) : ?>
			<p class="description"><?php esc_html_e( 'No pages connected yet.', 'aifbp' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'aifbp_connection' ); ?>
				<table class="widefat striped" style="max-width: 780px">
					<thead><tr><th></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page ID', 'aifbp' ); ?></th><th><?php esc_html_e( 'Token', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $pages as $page ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="selected_pages[]" value="<?php echo esc_attr( (string) $page['id'] ); ?>" <?php checked( ! isset( $page['selected'] ) || ! empty( $page['selected'] ) ); ?> />
								</td>
								<td>
									<?php if ( ! empty( $page['picture'] ) ) : ?>
										<img src="<?php echo esc_url( (string) $page['picture'] ); ?>" alt="" style="width:24px;height:24px;border-radius:4px;vertical-align:middle" />
									<?php endif; ?>
									<?php echo esc_html( $page['name'] ); ?>
								</td>
								<td><code><?php echo esc_html( $page['id'] ); ?></code></td>
								<td><?php echo esc_html( Crypto::mask( Crypto::decrypt( (string) $page['token'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" name="aifbp_save_page_selection" class="button button-primary"><?php esc_html_e( 'Save page selection', 'aifbp' ); ?></button>
					<button type="submit" name="aifbp_refresh_pages" class="button button-secondary"><?php esc_html_e( 'Refresh page list', 'aifbp' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'aifbp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="default_page"><?php esc_html_e( 'Default page', 'aifbp' ); ?></label></th>
					<td>
						<select id="default_page" name="default_page">
							<option value=""><?php esc_html_e( '— First selected page —', 'aifbp' ); ?></option>
							<?php foreach ( $pages as $pid => $page ) : ?>
								<option value="<?php echo esc_attr( (string) $pid ); ?>" <?php selected( (string) ( $s['default_page'] ?? '' ), (string) $pid ); ?>><?php echo esc_html( $page['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Used by Post now and single-action publishing. Schedules can pick their own pages.', 'aifbp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="test_page_id"><?php esc_html_e( 'Test page', 'aifbp' ); ?></label></th>
					<td>
						<select id="test_page_id" name="test_page_id">
							<?php foreach ( array_keys( $pages ) as $pid ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $s['test_page_id'] ?? '', $pid ); ?>><?php echo esc_html( $pages[ $pid ]['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save', 'aifbp' ); ?></button>
				<button type="submit" name="aifbp_test_fb" class="button button-secondary"><?php esc_html_e( 'Test page token', 'aifbp' ); ?></button>
				<button type="submit" name="aifbp_disconnect" class="button button-link-delete"><?php esc_html_e( 'Disconnect all', 'aifbp' ); ?></button>
			</p>
		</form>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Permissions & available features', 'aifbp' ); ?></h2>
			<?php if ( is_wp_error( $granted ) ) : ?>
				<p class="description"><?php echo esc_html( $granted->get_error_message() ); ?></p>
			<?php elseif ( empty( $granted ) ) : ?>
				<p class="description"><?php esc_html_e( 'Connect Facebook to load granted permissions.', 'aifbp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 780px">
					<thead><tr><th><?php esc_html_e( 'Permission', 'aifbp' ); ?></th><th><?php esc_html_e( 'Feature', 'aifbp' ); ?></th><th><?php esc_html_e( 'Status', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $map as $perm => $feature ) : ?>
							<tr>
								<td><code><?php echo esc_html( $perm ); ?></code></td>
								<td><?php echo esc_html( $feature ); ?></td>
								<td>
									<?php if ( in_array( $perm, $granted, true ) ) : ?>
										<span class="aifbp-status aifbp-status-posted"><?php esc_html_e( '✓ Granted', 'aifbp' ); ?></span>
									<?php else : ?>
										<span class="aifbp-status aifbp-status-failed"><?php esc_html_e( '✖ Missing — requires additional authorization / App Review', 'aifbp' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Facebook Login does not automatically grant every permission. Features using a missing permission stay disabled until you re-authorize with that permission approved in Meta App Review. Only the permissions required by the features you enable are requested.', 'aifbp' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function tab_ai(): void {
		$s         = Settings::all();
		$providers = AIManager::PRIORITY;
		$free      = [ 'gemini' => true, 'groq' => true, 'openrouter' => true, 'custom' => null, 'openai' => false ];
		$limit_err = AIManager::limit_error();
		?>
		<?php if ( null !== $limit_err ) : ?>
			<div class="notice notice-warning"><p>⚠️ <?php echo esc_html( $limit_err->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Provider priority & fallback', 'aifbp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Fallback order:', 'aifbp' ); ?>
				<?php foreach ( $providers as $i => $id ) : ?>
					<code><?php echo esc_html( (string) ( $i + 1 ) ); ?>. <?php echo esc_html( ucfirst( $id ) ); ?></code>
				<?php endforeach; ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'aifbp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Automatic fallback', 'aifbp' ); ?></th><td><label><input type="checkbox" name="ai_fallback" value="1" <?php checked( $s['ai_fallback'], 1 ); ?> /> <?php esc_html_e( 'If the primary provider fails or is rate limited, try the next enabled provider (only providers you configured and enabled are used — the plugin never switches to a model you did not select)', 'aifbp' ); ?></label></td></tr>
				</table>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'AI providers', 'aifbp' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Provider', 'aifbp' ); ?></th><th><?php esc_html_e( 'API key', 'aifbp' ); ?></th><th><?php esc_html_e( 'Model', 'aifbp' ); ?></th><th><?php esc_html_e( 'Enabled', 'aifbp' ); ?></th><th><?php esc_html_e( 'Connection status', 'aifbp' ); ?></th><th><?php esc_html_e( 'Usage (day / month)', 'aifbp' ); ?></th><th><?php esc_html_e( 'Test', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $providers as $id ) : ?>
							<?php
							$status = AIManager::status( $id );
							$key_id = 'openai' === $id ? 'openai_key' : ( $id . '_key' );
							$model_id = 'openai' === $id ? 'openai_model' : ( $id . '_model' );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( ucfirst( $id ) ); ?></strong><br />
									<span class="description"><?php echo esc_html( AIManager::free_tier_info( $id ) ); ?></span>
								</td>
								<td>
									<?php if ( 'gemini' === $id ) : ?>
										<input type="password" name="gemini_key" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== $s['gemini_key'] ? __( '(saved)', 'aifbp' ) : 'AIza…' ); ?>" autocomplete="new-password" />
									<?php elseif ( 'custom' === $id ) : ?>
										<input type="password" name="custom_key" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== $s['custom_key'] ? __( '(saved)', 'aifbp' ) : '' ); ?>" autocomplete="new-password" />
									<?php else : ?>
										<input type="password" name="<?php echo esc_attr( $key_id ); ?>" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== (string) $s[ $key_id ] ? __( '(saved)', 'aifbp' ) : '' ); ?>" autocomplete="new-password" />
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'custom' === $id ) : ?>
										<input type="text" name="custom_model" class="regular-text" value="<?php echo esc_attr( $s['custom_model'] ); ?>" /><br />
										<input type="url" name="custom_base" class="regular-text" value="<?php echo esc_attr( $s['custom_base'] ); ?>" placeholder="https://your-endpoint/v1" />
									<?php elseif ( 'openai' === $id ) : ?>
										<input type="text" name="openai_model" class="regular-text" value="<?php echo esc_attr( $s['openai_model'] ); ?>" /><br />
										<input type="url" name="openai_base" class="regular-text" value="<?php echo esc_attr( $s['openai_base'] ); ?>" />
									<?php else : ?>
										<input type="text" name="<?php echo esc_attr( $model_id ); ?>" class="regular-text" value="<?php echo esc_attr( (string) $s[ $model_id ] ); ?>" />
									<?php endif; ?>
								</td>
								<td><input type="checkbox" name="provider_enabled_<?php echo esc_attr( $id ); ?>" value="1" <?php checked( (int) $s[ 'provider_enabled_' . $id ], 1 ); ?> /></td>
								<td>
									<?php if ( empty( $status ) ) : ?>
										<span class="description"><?php esc_html_e( 'Not tested', 'aifbp' ); ?></span>
									<?php else : ?>
										<span class="aifbp-status aifbp-status-<?php echo empty( $status['ok'] ) ? 'failed' : 'posted'; ?>"><?php echo esc_html( empty( $status['ok'] ) ? '✖' : '✓' ); ?> <?php echo esc_html( wp_html_excerpt( (string) $status['message'], 60, '…' ) ); ?></span><br />
										<span class="description"><?php echo esc_html( (string) $status['tested_at'] ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( Usage::provider_today( $id ) ); ?> / <?php echo esc_html( Usage::provider_month( $id ) ); ?></td>
								<td>
									<form method="post">
										<?php wp_nonce_field( 'aifbp_settings' ); ?>
										<input type="hidden" name="provider_id" value="<?php echo esc_attr( $id ); ?>" />
										<button type="submit" name="aifbp_test_provider" value="1" class="button button-small"><?php esc_html_e( 'Test', 'aifbp' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php wp_nonce_field( 'aifbp_settings' ); ?>
				<button type="submit" name="aifbp_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save provider settings', 'aifbp' ); ?></button>
				<p class="description"><?php esc_html_e( 'Priority is fixed: Gemini → Groq → OpenRouter → Custom → OpenAI. Disable any provider you do not want used. Fallback never selects a model you have not configured.', 'aifbp' ); ?></p>
			</div>

			<div class="aifbp-grid">
				<div class="aifbp-panel">
					<h2><?php esc_html_e( 'Usage & cost protection', 'aifbp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><label for="ai_daily_limit"><?php esc_html_e( 'Daily AI request limit', 'aifbp' ); ?></label></th><td><input type="number" id="ai_daily_limit" name="ai_daily_limit" min="0" value="<?php echo esc_attr( (string) $s['ai_daily_limit'] ); ?>" /><p class="description"><?php esc_html_e( '0 = unlimited. When reached, AI generation pauses until you raise the limit.', 'aifbp' ); ?></p></td></tr>
						<tr><th><label for="ai_monthly_limit"><?php esc_html_e( 'Monthly AI request limit', 'aifbp' ); ?></label></th><td><input type="number" id="ai_monthly_limit" name="ai_monthly_limit" min="0" value="<?php echo esc_attr( (string) $s['ai_monthly_limit'] ); ?>" /></td></tr>
						<tr><th><label for="ai_timeout"><?php esc_html_e( 'Request timeout (seconds)', 'aifbp' ); ?></label></th><td><input type="number" id="ai_timeout" name="ai_timeout" min="10" max="300" value="<?php echo esc_attr( (string) $s['ai_timeout'] ); ?>" /></td></tr>
						<tr><th><label for="ai_retry_limit"><?php esc_html_e( 'Retry limit per provider', 'aifbp' ); ?></label></th><td><input type="number" id="ai_retry_limit" name="ai_retry_limit" min="1" max="10" value="<?php echo esc_attr( (string) $s['ai_retry_limit'] ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Usage alerts', 'aifbp' ); ?></th><td><label><input type="checkbox" name="usage_alerts" value="1" <?php checked( $s['usage_alerts'], 1 ); ?> /> <?php esc_html_e( 'Email me when a usage limit is reached', 'aifbp' ); ?></label></td></tr>
					</table>
					<p>
						<?php
						printf(
							esc_html__( 'Today: %1$d AI requests / %2$d tokens | This month: %3$d AI requests / %4$d tokens', 'aifbp' ),
							esc_html( Usage::today( 'ai' ) ),
							esc_html( Usage::tokens_today() ),
							esc_html( Usage::month()['ai'] ),
							esc_html( Usage::tokens_month() )
						);
						?>
					</p>
				</div>

				<div class="aifbp-panel">
					<h2><?php esc_html_e( 'AI image generation (separate provider)', 'aifbp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="image_provider"><?php esc_html_e( 'Image provider', 'aifbp' ); ?></label></th>
							<td>
								<select id="image_provider" name="image_provider">
									<option value="off" <?php selected( $s['image_provider'], 'off' ); ?>><?php esc_html_e( 'Off — use the free built-in poster designer (GD)', 'aifbp' ); ?></option>
									<option value="openai" <?php selected( $s['image_provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI (PAID per image)', 'aifbp' ); ?></option>
								</select>
							</td>
						</tr>
						<tr><th><label for="image_model"><?php esc_html_e( 'Image model', 'aifbp' ); ?></label></th><td><input type="text" id="image_model" name="image_model" class="regular-text" value="<?php echo esc_attr( $s['image_model'] ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Status', 'aifbp' ); ?></th>
							<td>
								<?php if ( 'off' === (string) $s['image_provider'] ) : ?>
									<span class="aifbp-status aifbp-status-cancelled"><?php esc_html_e( 'Off — no image API charges possible', 'aifbp' ); ?></span>
								<?php else : ?>
									<span class="aifbp-status aifbp-status-failed"><?php esc_html_e( 'PAID API — costs apply per generated image', 'aifbp' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr><th><?php esc_html_e( 'Enable', 'aifbp' ); ?></th><td><label><input type="checkbox" name="ai_image_gen" value="1" <?php checked( $s['ai_image_gen'], 1 ); ?> /> <?php esc_html_e( 'Use AI image generation when available (poster designer otherwise)', 'aifbp' ); ?></label></td></tr>
					</table>
					<p class="description"><?php esc_html_e( 'A text provider (Gemini/Groq) does not provide image generation. Image generation stays off unless you explicitly enable a paid image provider here.', 'aifbp' ); ?></p>
				</div>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Caption options', 'aifbp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="caption_language"><?php esc_html_e( 'Caption language', 'aifbp' ); ?></label></th>
						<td>
							<select id="caption_language" name="caption_language">
								<option value="both" <?php selected( $s['caption_language'], 'both' ); ?>><?php esc_html_e( 'Bangla + English', 'aifbp' ); ?></option>
								<option value="bn" <?php selected( $s['caption_language'], 'bn' ); ?>><?php esc_html_e( 'Bangla only', 'aifbp' ); ?></option>
								<option value="en" <?php selected( $s['caption_language'], 'en' ); ?>><?php esc_html_e( 'English only', 'aifbp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr><th><label for="variations"><?php esc_html_e( 'Caption variations', 'aifbp' ); ?></label></th><td><input type="number" id="variations" name="variations" min="0" max="5" value="<?php echo esc_attr( (string) $s['variations'] ); ?>" /></td></tr>
					<tr><th><label for="currency"><?php esc_html_e( 'Currency label', 'aifbp' ); ?></label></th><td><input type="text" id="currency" name="currency" value="<?php echo esc_attr( $s['currency'] ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Include in caption', 'aifbp' ); ?></th><td>
						<label><input type="checkbox" name="include_price" value="1" <?php checked( $s['include_price'], 1 ); ?> /> <?php esc_html_e( 'Price (regular + sale)', 'aifbp' ); ?></label><br />
						<label><input type="checkbox" name="include_link" value="1" <?php checked( $s['include_link'], 1 ); ?> /> <?php esc_html_e( 'Product link', 'aifbp' ); ?></label><br />
						<label><input type="checkbox" name="include_hashtags" value="1" <?php checked( $s['include_hashtags'], 1 ); ?> /> <?php esc_html_e( 'AI hashtags', 'aifbp' ); ?></label><br />
						<label><input type="checkbox" name="include_cta" value="1" <?php checked( $s['include_cta'], 1 ); ?> /> <?php esc_html_e( 'AI call-to-action', 'aifbp' ); ?></label>
					</td></tr>
					<tr><th><?php esc_html_e( 'Advanced AI features', 'aifbp' ); ?></th><td>
						<label><input type="checkbox" name="ai_image_understand" value="1" <?php checked( $s['ai_image_understand'], 1 ); ?> /> <?php esc_html_e( 'AI image understanding (vision)', 'aifbp' ); ?></label><br />
						<label><input type="checkbox" name="price_badge" value="1" <?php checked( $s['price_badge'], 1 ); ?> /> <?php esc_html_e( 'Automatic price badge overlay (GD)', 'aifbp' ); ?></label>
					</td></tr>
				</table>
				<button type="submit" name="aifbp_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save AI settings', 'aifbp' ); ?></button>
			</div>
		</form>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Recent AI errors', 'aifbp' ); ?></h2>
			<?php
			$logs = array_filter(
				Logger::recent( 100 ),
				fn( $l ) => str_starts_with( (string) $l['context'], 'ai' ) && 'error' === (string) $l['level']
			);
			$logs = array_slice( $logs, 0, 10 );

			if ( empty( $logs ) ) {
				echo '<p class="description">' . esc_html__( 'No AI errors logged.', 'aifbp' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'aifbp' ) . '</th><th>' . esc_html__( 'Context', 'aifbp' ) . '</th><th>' . esc_html__( 'Message', 'aifbp' ) . '</th></tr></thead><tbody>';
				foreach ( $logs as $log ) {
					echo '<tr><td>' . esc_html( (string) $log['created_at'] ) . '</td><td>' . esc_html( (string) $log['context'] ) . '</td><td>' . esc_html( wp_html_excerpt( (string) $log['message'], 140, '…' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			?>
		</div>
		<?php
	}

	private function tab_direct(): void {
		$s = Settings::all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'aifbp_settings' ); ?>
			<h2><?php esc_html_e( 'Posting mode', 'aifbp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="posting_mode"><?php esc_html_e( 'Default posting mode', 'aifbp' ); ?></label></th>
					<td>
						<select id="posting_mode" name="posting_mode">
							<option value="ai" <?php selected( $s['posting_mode'], 'ai' ); ?>><?php esc_html_e( 'AI Mode (AI-generated captions)', 'aifbp' ); ?></option>
							<option value="direct" <?php selected( $s['posting_mode'], 'direct' ); ?>><?php esc_html_e( 'Direct Mode (no AI)', 'aifbp' ); ?></option>
							<option value="both" <?php selected( $s['posting_mode'], 'both' ); ?>><?php esc_html_e( 'Both Modes', 'aifbp' ); ?></option>
						</select>
					</td>
				</tr>
				<tr><th><?php esc_html_e( 'Reliability', 'aifbp' ); ?></th><td>
					<label><input type="checkbox" name="ai_fallback_direct" value="1" <?php checked( $s['ai_fallback_direct'], 1 ); ?> /> <?php esc_html_e( 'If AI fails, continue with Direct posting', 'aifbp' ); ?></label>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Direct content format', 'aifbp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="direct_format"><?php esc_html_e( 'Format', 'aifbp' ); ?></label></th>
					<td>
						<select id="direct_format" name="direct_format">
							<?php foreach ( DirectEngine::FORMATS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['direct_format'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr><th><label for="direct_template"><?php esc_html_e( 'Custom template', 'aifbp' ); ?></label></th>
					<td>
						<textarea id="direct_template" name="direct_template" rows="8" class="large-text code"><?php echo esc_textarea( $s['direct_template'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Template variables:', 'aifbp' ); ?>
							<code><?php echo esc_html( implode( '</code> <code>', DirectEngine::variables_doc() ) ); ?></code>
						</p>
					</td>
				</tr>
				<tr><th><label for="direct_hashtags"><?php esc_html_e( 'Custom hashtags', 'aifbp' ); ?></label></th><td><input type="text" id="direct_hashtags" name="direct_hashtags" class="regular-text" value="<?php echo esc_attr( $s['direct_hashtags'] ); ?>" /></td></tr>
				<tr><th><label for="direct_cta"><?php esc_html_e( 'Call-to-action', 'aifbp' ); ?></label></th><td><input type="text" id="direct_cta" name="direct_cta" class="regular-text" value="<?php echo esc_attr( $s['direct_cta'] ); ?>" /></td></tr>
				<tr><th><label for="include_hashtags2"><?php esc_html_e( 'Append to direct posts', 'aifbp' ); ?></label></th><td>
					<label><input type="checkbox" name="include_hashtags" value="1" <?php checked( $s['include_hashtags'], 1 ); ?> /> <?php esc_html_e( 'Hashtags', 'aifbp' ); ?></label>
					<label style="margin-left:12px"><input type="checkbox" name="include_cta" value="1" <?php checked( $s['include_cta'], 1 ); ?> /> <?php esc_html_e( 'CTA', 'aifbp' ); ?></label>
					<label style="margin-left:12px"><input type="checkbox" name="include_link" value="1" <?php checked( $s['include_link'], 1 ); ?> /> <?php esc_html_e( 'Product link', 'aifbp' ); ?></label>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Schedules', 'aifbp' ); ?></h2>
			<?php $this->render_schedule_form(); ?>
			<?php $this->render_schedule_table( 'direct' ); ?>

			<h2><?php esc_html_e( 'Bulk scheduling', 'aifbp' ); ?></h2>
			<?php $this->render_bulk_form(); ?>

			<?php submit_button( __( 'Save direct posting settings', 'aifbp' ), 'primary', 'aifbp_save_settings', false ); ?>
		</form>
		<?php
	}

	private function tab_ai_posting(): void {
		?>
		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'AI Auto Posting', 'aifbp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'AI captions are configured on the AI Settings page. Schedules run per-schedule mode: set a schedule mode to "ai" to use AI captions. If the AI API fails and fallback is enabled, Direct posting continues automatically.', 'aifbp' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=ai' ) ); ?>"><?php esc_html_e( 'Open AI Settings', 'aifbp' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=direct' ) ); ?>"><?php esc_html_e( 'Create AI schedule', 'aifbp' ); ?></a>
			</p>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Test AI posting', 'aifbp' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="aifbp-preview-btn"><?php esc_html_e( 'Generate AI preview', 'aifbp' ); ?></button>
				<button type="button" class="button button-secondary" id="aifbp-postnow-btn"><?php esc_html_e( 'Post now to first page', 'aifbp' ); ?></button>
			</p>
			<div id="aifbp-preview" class="aifbp-preview" hidden>
				<div class="aifbp-fbpost">
					<div class="aifbp-fbimg"><img id="aifbp-preview-img" src="" alt="" /></div>
					<div class="aifbp-fbcaption"><pre id="aifbp-preview-caption"></pre></div>
				</div>
			</div>
			<div id="aifbp-result" class="aifbp-result"></div>
		</div>
		<?php
	}

	private function render_schedule_form(): void {
		$pages = Settings::pages();
		?>
		<div class="aifbp-panel">
			<form method="post">
				<?php wp_nonce_field( 'aifbp_schedule' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="sch-name"><?php esc_html_e( 'Name', 'aifbp' ); ?></label></th><td><input type="text" id="sch-name" name="name" class="regular-text" required /></td></tr>
					<tr>
						<th><label for="sch-type"><?php esc_html_e( 'Type', 'aifbp' ); ?></label></th>
						<td>
							<select id="sch-type" name="type">
								<option value="daily"><?php esc_html_e( 'Daily', 'aifbp' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Weekly (selected days)', 'aifbp' ); ?></option>
								<option value="campaign"><?php esc_html_e( 'Campaign (date range)', 'aifbp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr><th><label for="sch-time"><?php esc_html_e( 'Time of day', 'aifbp' ); ?></label></th><td><input type="time" id="sch-time" name="time_of_day" value="10:00" /></td></tr>
					<tr><th><label for="sch-times"><?php esc_html_e( 'Multiple times per day', 'aifbp' ); ?></label></th><td><input type="text" id="sch-times" name="times" placeholder="10:00,14:00,20:00" class="regular-text" /><p class="description"><?php esc_html_e( 'Comma separated. Overrides single time when set.', 'aifbp' ); ?></p></td></tr>
					<tr><th><label for="sch-ws"><?php esc_html_e( 'Random window start', 'aifbp' ); ?></label></th><td><input type="time" id="sch-ws" name="window_start" /></td></tr>
					<tr><th><label for="sch-we"><?php esc_html_e( 'Random window end', 'aifbp' ); ?></label></th><td><input type="time" id="sch-we" name="window_end" /><p class="description"><?php esc_html_e( 'Adds one random time inside this window each day.', 'aifbp' ); ?></p></td></tr>
					<tr>
						<th><label for="sch-mode"><?php esc_html_e( 'Posting mode', 'aifbp' ); ?></label></th>
						<td>
							<select id="sch-mode" name="mode">
								<option value="ai"><?php esc_html_e( 'AI Mode', 'aifbp' ); ?></option>
								<option value="direct" selected><?php esc_html_e( 'Direct Mode (no AI)', 'aifbp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="sch-selection"><?php esc_html_e( 'Product selection', 'aifbp' ); ?></label></th>
						<td>
							<select id="sch-selection" name="selection">
								<?php foreach ( Rotation::METHODS as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'random', $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Categories', 'aifbp' ); ?></th>
						<td>
							<?php
							if ( class_exists( 'WooCommerce' ) ) {
								$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

								if ( ! is_wp_error( $cats ) ) {
									foreach ( $cats as $cat ) {
										printf( '<label style="margin-right:10px"><input type="checkbox" name="categories[]" value="%1$s" /> %2$s</label>', esc_attr( (string) $cat->term_id ), esc_html( $cat->name ) );
									}
								}
							} else {
								echo '<p class="description">' . esc_html__( 'WooCommerce not active.', 'aifbp' ) . '</p>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><label for="sch-format"><?php esc_html_e( 'Direct content format', 'aifbp' ); ?></label></th>
						<td>
							<select id="sch-format" name="content_format">
								<option value=""><?php esc_html_e( 'Use default format', 'aifbp' ); ?></option>
								<?php foreach ( DirectEngine::FORMATS as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="sch-template"><?php esc_html_e( 'Template (optional)', 'aifbp' ); ?></label></th>
						<td>
							<select id="sch-template" name="template_name">
								<option value=""><?php esc_html_e( 'None', 'aifbp' ); ?></option>
								<?php foreach ( array_keys( Templates::all() ) as $name ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Weekdays', 'aifbp' ); ?></th>
						<td>
							<?php foreach ( [ 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' ] as $day => $label ) : ?>
								<label style="margin-right:8px"><input type="checkbox" name="days[]" value="<?php echo esc_attr( $day ); ?>" checked /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr><th><label for="sch-ppr"><?php esc_html_e( 'Posts per run', 'aifbp' ); ?></label></th><td><input type="number" id="sch-ppr" name="posts_per_run" min="1" max="20" value="1" /></td></tr>
					<tr><th><label for="sch-int"><?php esc_html_e( 'Interval between posts (minutes)', 'aifbp' ); ?></label></th><td><input type="number" id="sch-int" name="interval_minutes" min="5" value="120" /></td></tr>
					<tr>
						<th><?php esc_html_e( 'Pages', 'aifbp' ); ?></th>
						<td><?php $this->page_selector(); ?></td>
					</tr>
					<tr><th><label for="sch-start"><?php esc_html_e( 'Campaign start', 'aifbp' ); ?></label></th><td><input type="date" id="sch-start" name="start_date" /></td></tr>
					<tr><th><label for="sch-end"><?php esc_html_e( 'Campaign end', 'aifbp' ); ?></label></th><td><input type="date" id="sch-end" name="end_date" /></td></tr>
				</table>
				<button type="submit" name="aifbp_create_schedule" class="button button-primary"><?php esc_html_e( 'Create schedule', 'aifbp' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_schedule_table( string $back_tab ): void {
		$schedules = ScheduleManager::all();
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'aifbp' ); ?></th><th><?php esc_html_e( 'Type', 'aifbp' ); ?></th><th><?php esc_html_e( 'Mode', 'aifbp' ); ?></th><th><?php esc_html_e( 'Times', 'aifbp' ); ?></th><th><?php esc_html_e( 'Posts/run', 'aifbp' ); ?></th><th><?php esc_html_e( 'Next run', 'aifbp' ); ?></th><th><?php esc_html_e( 'Active', 'aifbp' ); ?></th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $schedules ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No schedules yet.', 'aifbp' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $schedules as $schedule ) : ?>
					<tr>
						<td><?php echo esc_html( $schedule['name'] ); ?></td>
						<td><?php echo esc_html( $schedule['type'] ); ?></td>
						<td><span class="aifbp-mode aifbp-mode-<?php echo esc_attr( $schedule['mode'] ); ?>"><?php echo esc_html( strtoupper( (string) $schedule['mode'] ) ); ?></span></td>
						<td><?php echo esc_html( '' !== (string) $schedule['times'] ? $schedule['times'] : $schedule['time_of_day'] ); ?></td>
						<td><?php echo esc_html( $schedule['posts_per_run'] ); ?></td>
						<td><?php echo esc_html( (string) $schedule['next_run'] ); ?></td>
						<td><?php echo $schedule['active'] ? '✅' : '⏸'; ?></td>
						<td>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'aifbp_schedule' ); ?>
								<input type="hidden" name="schedule_id" value="<?php echo esc_attr( $schedule['id'] ); ?>" />
								<button type="submit" name="aifbp_toggle_schedule" class="button button-small"><?php echo $schedule['active'] ? esc_html__( 'Pause', 'aifbp' ) : esc_html__( 'Resume', 'aifbp' ); ?></button>
								<button type="submit" name="aifbp_delete_schedule" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'aifbp' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_bulk_form(): void {
		?>
		<div class="aifbp-panel">
			<form method="post">
				<?php wp_nonce_field( 'aifbp_schedule' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="bulk-count"><?php esc_html_e( 'Number of posts', 'aifbp' ); ?></label></th><td><input type="number" id="bulk-count" name="count" min="1" max="100" value="10" /></td></tr>
					<tr><th><label for="bulk-start"><?php esc_html_e( 'Start', 'aifbp' ); ?></label></th><td><input type="datetime-local" id="bulk-start" name="start" /></td></tr>
					<tr><th><label for="bulk-end"><?php esc_html_e( 'End', 'aifbp' ); ?></label></th><td><input type="datetime-local" id="bulk-end" name="end" /></td></tr>
					<tr><th><label for="bulk-int"><?php esc_html_e( 'Interval (minutes)', 'aifbp' ); ?></label></th><td><input type="number" id="bulk-int" name="interval_minutes" min="5" value="60" /></td></tr>
					<tr>
						<th><label for="bulk-mode"><?php esc_html_e( 'Mode', 'aifbp' ); ?></label></th>
						<td>
							<select id="bulk-mode" name="mode">
								<option value="ai"><?php esc_html_e( 'AI Mode', 'aifbp' ); ?></option>
								<option value="direct" selected><?php esc_html_e( 'Direct Mode (no AI)', 'aifbp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Pages', 'aifbp' ); ?></th><td><?php $this->page_selector(); ?></td></tr>
				</table>
				<button type="submit" name="aifbp_bulk_schedule" class="button button-primary"><?php esc_html_e( 'Queue posts', 'aifbp' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function pill( string $mode ): void {
		$label = 'direct' === $mode ? __( 'Direct', 'aifbp' ) : __( 'AI', 'aifbp' );

		printf( '<span class="aifbp-mode aifbp-mode-%1$s">%2$s</span>', esc_attr( $mode ), esc_html( $label ) );
	}

	private function product_options( int $selected = 0 ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<option value="0">' . esc_html__( 'WooCommerce not active', 'aifbp' ) . '</option>';

			return;
		}

		$products = wc_get_products( [ 'status' => 'publish', 'limit' => 200, 'orderby' => 'date', 'order' => 'DESC' ] );

		foreach ( $products as $wc ) {
			printf(
				'<option value="%1$d" %2$s>%3$s (#%1$d)</option>',
				(int) $wc->get_id(),
				selected( $selected, (int) $wc->get_id(), false ),
				esc_html( $wc->get_name() )
			);
		}
	}

	private function tab_calendar(): void {
		$s     = Settings::all();
		$year  = isset( $_GET['view_year'] ) ? absint( wp_unslash( $_GET['view_year'] ) ) : (int) current_time( 'Y' );
		$month = isset( $_GET['view_month'] ) ? absint( wp_unslash( $_GET['view_month'] ) ) : (int) current_time( 'n' );

		if ( $year < 2000 || $year > 2100 ) {
			$year = (int) current_time( 'Y' );
		}

		if ( $month < 1 || $month > 12 ) {
			$month = (int) current_time( 'n' );
		}

		$items  = CalendarManager::month( $year, $month );
		$by_day = [];

		foreach ( $items as $item ) {
				$by_day[ date( 'j', (int) strtotime( (string) $item['scheduled_at'] ) ) ][] = $item;
		}

		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$first_offset  = (int) date( 'N', mktime( 0, 0, 0, $month, 1, $year ) ) - 1;
		$day_names     = [ __( 'Mon', 'aifbp' ), __( 'Tue', 'aifbp' ), __( 'Wed', 'aifbp' ), __( 'Thu', 'aifbp' ), __( 'Fri', 'aifbp' ), __( 'Sat', 'aifbp' ), __( 'Sun', 'aifbp' ) ];
		$prev          = [ 'y' => $month > 1 ? $year : $year - 1, 'm' => $month > 1 ? $month - 1 : 12 ];
		$next          = [ 'y' => $month < 12 ? $year : $year + 1, 'm' => $month < 12 ? $month + 1 : 1 ];
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Generate marketing calendar', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_calendar' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Pages', 'aifbp' ); ?></th><td><?php $this->page_selector(); ?></td></tr>
						<tr><th><label for="cal-duration"><?php esc_html_e( 'Campaign duration (days)', 'aifbp' ); ?></label></th><td><input type="number" id="cal-duration" name="duration_days" min="1" max="60" value="7" /></td></tr>
						<tr><th><label for="cal-freq"><?php esc_html_e( 'Posts per day', 'aifbp' ); ?></label></th><td><input type="number" id="cal-freq" name="posts_per_day" min="1" max="6" value="1" /></td></tr>
						<tr><th><label for="cal-times"><?php esc_html_e( 'Posting times (comma separated)', 'aifbp' ); ?></label></th><td><input type="text" id="cal-times" name="times" value="10:00,20:00" class="regular-text" /></td></tr>
						<tr>
							<th><label for="cal-mode"><?php esc_html_e( 'Mode', 'aifbp' ); ?></label></th>
							<td>
								<select id="cal-mode" name="mode">
									<option value="direct" selected><?php esc_html_e( 'Direct (no AI)', 'aifbp' ); ?></option>
									<option value="ai"><?php esc_html_e( 'AI generated', 'aifbp' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="cal-type"><?php esc_html_e( 'Content type', 'aifbp' ); ?></label></th>
							<td>
								<select id="cal-type" name="content_type">
									<?php foreach ( CalendarManager::CONTENT_TYPES as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="cal-selection"><?php esc_html_e( 'Product selection', 'aifbp' ); ?></label></th>
							<td>
								<select id="cal-selection" name="selection">
									<?php foreach ( Rotation::METHODS as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr><th><label for="cal-start"><?php esc_html_e( 'Start date', 'aifbp' ); ?></label></th><td><input type="date" id="cal-start" name="start_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" /></td></tr>
						<tr><th><label for="cal-objective"><?php esc_html_e( 'Marketing objective', 'aifbp' ); ?></label></th><td><input type="text" id="cal-objective" name="objective" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Boost weekend sales', 'aifbp' ); ?>" /></td></tr>
						<tr><th><label for="cal-tone"><?php esc_html_e( 'Content tone', 'aifbp' ); ?></label></th><td><input type="text" id="cal-tone" name="tone" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. friendly, urgent', 'aifbp' ); ?>" /></td></tr>
						<tr>
							<th><label for="cal-format"><?php esc_html_e( 'Direct format', 'aifbp' ); ?></label></th>
							<td>
								<select id="cal-format" name="direct_format">
									<?php foreach ( DirectEngine::FORMATS as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['direct_format'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
					<button type="submit" name="aifbp_generate_calendar" class="button button-primary"><?php esc_html_e( 'Generate calendar', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=calendar&view_year=' . $prev['y'] . '&view_month=' . $prev['m'] ) ); ?>">&laquo;</a>
					<?php echo esc_html( date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=calendar&view_year=' . $next['y'] . '&view_month=' . $next['m'] ) ); ?>">&raquo;</a>
				</h2>
				<?php if ( empty( $items ) ) : ?>
					<p class="description"><?php esc_html_e( 'No scheduled posts this month. Generate a calendar or create a schedule.', 'aifbp' ); ?></p>
				<?php else : ?>
					<table class="aifbp-calendar widefat">
						<thead><tr><?php foreach ( $day_names as $dn ) : ?><th><?php echo esc_html( $dn ); ?></th><?php endforeach; ?></tr></thead>
						<tbody>
							<tr>
								<?php for ( $i = 0; $i < $first_offset; $i++ ) : ?>
									<td class="aifbp-cal-empty"></td>
								<?php endfor; ?>
								<?php
								$cell = $first_offset;
								for ( $d = 1; $d <= $days_in_month; $d++ ) :
									if ( 7 === $cell % 7 && $d > 1 ) {
										echo '</tr><tr>';
									}
									$cell++;
									?>
									<td>
										<strong><?php echo esc_html( (string) $d ); ?></strong>
										<?php foreach ( $by_day[ $d ] ?? [] as $item ) : ?>
											<div class="aifbp-cal-item aifbp-cal-<?php echo esc_attr( (string) $item['status'] ); ?>">
												<?php echo esc_html( date_i18n( 'H:i', (int) strtotime( (string) $item['scheduled_at'] ) ) ); ?>
												<?php $this->pill( (string) $item['mode'] ); ?><br />
												<span title="<?php echo esc_attr( (string) $item['product_name'] ); ?>"><?php echo esc_html( wp_html_excerpt( (string) $item['product_name'], 22, '…' ) ); ?></span>
												<span class="aifbp-cal-actions">
												<form method="post" class="aifbp-inline">
													<?php wp_nonce_field( 'aifbp_calendar' ); ?>
													<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
													<?php if ( 'draft' === $item['status'] ) : ?>
														<button type="submit" name="aifbp_calendar_action" value="resume" class="button-link"><?php esc_html_e( 'Resume', 'aifbp' ); ?></button>
													<?php elseif ( 'pending' === $item['status'] ) : ?>
														<button type="submit" name="aifbp_calendar_action" value="pause" class="button-link"><?php esc_html_e( 'Pause', 'aifbp' ); ?></button>
														<button type="submit" name="aifbp_calendar_action" value="publish" class="button-link"><?php esc_html_e( 'Publish', 'aifbp' ); ?></button>
													<?php endif; ?>
													<?php if ( in_array( (string) $item['status'], [ 'pending', 'draft', 'failed' ], true ) ) : ?>
														<button type="submit" name="aifbp_calendar_action" value="delete" class="button-link-delete"><?php esc_html_e( 'Delete', 'aifbp' ); ?></button>
													<?php endif; ?>
												</form>
												</span>
											</div>
										<?php endforeach; ?>
									</td>
								<?php endfor; ?>
								<?php for ( $i = $cell % 7; $i > 0 && $i < 7; $i++ ) : ?>
									<td class="aifbp-cal-empty"></td>
								<?php endfor; ?>
							</tr>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function tab_poster(): void {
		$s = Settings::all();
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Poster defaults', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Enable', 'aifbp' ); ?></th><td><label><input type="checkbox" name="poster_enabled" value="1" <?php checked( $s['poster_enabled'], 1 ); ?> /> <?php esc_html_e( 'Generate a promotional poster and attach it to every post', 'aifbp' ); ?></label></td></tr>
						<tr>
							<th><label for="poster_size"><?php esc_html_e( 'Size', 'aifbp' ); ?></label></th>
							<td>
								<select id="poster_size" name="poster_size">
									<?php foreach ( Designer::SIZES as $key => $dims ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['poster_size'], $key ); ?>><?php echo esc_html( sprintf( '%s (%dx%d)', $key, $dims[0], $dims[1] ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="poster_template"><?php esc_html_e( 'Template', 'aifbp' ); ?></label></th>
							<td>
								<select id="poster_template" name="poster_template">
									<?php foreach ( Designer::TEMPLATES as $tpl ) : ?>
										<option value="<?php echo esc_attr( $tpl ); ?>" <?php selected( $s['poster_template'], $tpl ); ?>><?php echo esc_html( ucfirst( $tpl ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr><th><label for="poster_bg"><?php esc_html_e( 'Background color', 'aifbp' ); ?></label></th><td><input type="color" id="poster_bg" name="poster_bg" value="<?php echo esc_attr( $s['poster_bg'] ); ?>" /></td></tr>
						<tr><th><label for="poster_accent"><?php esc_html_e( 'Accent color', 'aifbp' ); ?></label></th><td><input type="color" id="poster_accent" name="poster_accent" value="<?php echo esc_attr( $s['poster_accent'] ); ?>" /></td></tr>
						<tr><th><label for="poster_text"><?php esc_html_e( 'Text color', 'aifbp' ); ?></label></th><td><input type="color" id="poster_text" name="poster_text" value="<?php echo esc_attr( $s['poster_text'] ); ?>" /></td></tr>
						<tr><th><label for="poster_brand"><?php esc_html_e( 'Brand name', 'aifbp' ); ?></label></th><td><input type="text" id="poster_brand" name="poster_brand" class="regular-text" value="<?php echo esc_attr( $s['poster_brand'] ); ?>" /></td></tr>
						<tr><th><label for="poster_cta"><?php esc_html_e( 'CTA text', 'aifbp' ); ?></label></th><td><input type="text" id="poster_cta" name="poster_cta" class="regular-text" value="<?php echo esc_attr( $s['poster_cta'] ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Discount badge', 'aifbp' ); ?></th><td><label><input type="checkbox" name="poster_badge" value="1" <?php checked( $s['poster_badge'], 1 ); ?> /> <?php esc_html_e( 'Show discount badge when a sale price exists', 'aifbp' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Media library', 'aifbp' ); ?></th><td><label><input type="checkbox" name="poster_save_media" value="1" <?php checked( $s['poster_save_media'], 1 ); ?> /> <?php esc_html_e( 'Save generated posters to the media library', 'aifbp' ); ?></label></td></tr>
						<tr>
							<th><label for="poster_font_path"><?php esc_html_e( 'TTF font path', 'aifbp' ); ?></label></th>
							<td>
								<input type="text" id="poster_font_path" name="poster_font_path" class="regular-text" value="<?php echo esc_attr( $s['poster_font_path'] ); ?>" placeholder="/absolute/path/to/font.ttf" />
								<p class="description"><?php esc_html_e( 'Required for Bengali text on posters. Upload a Bengali-capable TTF (e.g. Noto Sans Bengali) and enter its absolute server path. Without a TTF, posters fall back to ASCII-only built-in text.', 'aifbp' ); ?></p>
							</td>
						</tr>
					</table>
					<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save poster settings', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Generate a poster now', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_poster' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="poster-product"><?php esc_html_e( 'Product', 'aifbp' ); ?></label></th>
							<td><select id="poster-product" name="product_id"><?php $this->product_options(); ?></select></td>
						</tr>
						<tr>
							<th><label for="poster-gen-size"><?php esc_html_e( 'Size', 'aifbp' ); ?></label></th>
							<td>
								<select id="poster-gen-size" name="poster_size">
									<?php foreach ( Designer::SIZES as $key => $dims ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['poster_size'], $key ); ?>><?php echo esc_html( sprintf( '%dx%d', $dims[0], $dims[1] ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="poster-gen-template"><?php esc_html_e( 'Template', 'aifbp' ); ?></label></th>
							<td>
								<select id="poster-gen-template" name="poster_template">
									<?php foreach ( Designer::TEMPLATES as $tpl ) : ?>
										<option value="<?php echo esc_attr( $tpl ); ?>" <?php selected( $s['poster_template'], $tpl ); ?>><?php echo esc_html( ucfirst( $tpl ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr><th><?php esc_html_e( 'Save to media', 'aifbp' ); ?></th><td><label><input type="checkbox" name="save_media" value="1" <?php checked( $s['poster_save_media'], 1 ); ?> /> <?php esc_html_e( 'Attach to media library', 'aifbp' ); ?></label></td></tr>
					</table>
					<button type="submit" name="aifbp_generate_poster" class="button button-primary"><?php esc_html_e( 'Generate poster', 'aifbp' ); ?></button>
					<p class="description"><?php esc_html_e( 'The poster uses real WooCommerce product data only (name, prices, image). AI image generation is used only when configured; otherwise the built-in template designer renders the poster with GD.', 'aifbp' ); ?></p>
				</form>
			</div>
		</div>
		<?php
	}

	private function tab_rotation(): void {
		$s = Settings::all();
		global $wpdb;
		$history = $wpdb->get_results( 'SELECT * FROM ' . DB::table_rotation() . ' ORDER BY id DESC LIMIT 50', ARRAY_A ) ?: [];
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Rotation rules', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Stock filter', 'aifbp' ); ?></th><td><label><input type="checkbox" name="skip_out_of_stock" value="1" <?php checked( $s['skip_out_of_stock'], 1 ); ?> /> <?php esc_html_e( 'Skip out-of-stock products', 'aifbp' ); ?></label></td></tr>
						<tr><th><label for="repeat_after_days"><?php esc_html_e( 'Repeat interval (days)', 'aifbp' ); ?></label></th><td><input type="number" id="repeat_after_days" name="repeat_after_days" min="0" value="<?php echo esc_attr( (string) $s['repeat_after_days'] ); ?>" /><p class="description"><?php esc_html_e( 'A product is not posted again for the same page until this many days pass (0 = allow any time).', 'aifbp' ); ?></p></td></tr>
						<tr><th><label for="cooldown_days"><?php esc_html_e( 'Global cooldown (days)', 'aifbp' ); ?></label></th><td><input type="number" id="cooldown_days" name="cooldown_days" min="0" value="<?php echo esc_attr( (string) $s['cooldown_days'] ); ?>" /></td></tr>
					</table>
					<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save rotation rules', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Selection methods', 'aifbp' ); ?></h2>
				<table class="widefat striped" style="max-width:600px">
					<thead><tr><th><?php esc_html_e( 'Method', 'aifbp' ); ?></th><th><?php esc_html_e( 'Description', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( Rotation::METHODS as $key => $label ) : ?>
							<tr><td><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( $label ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Each schedule can use a different method, category filter, and page set. Rotation history prevents repeats per page and per schedule.', 'aifbp' ); ?></p>
			</div>
		</div>

		<h2><?php esc_html_e( 'Recent rotation history', 'aifbp' ); ?></h2>
		<?php if ( empty( $history ) ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing posted yet.', 'aifbp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Product ID', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page ID', 'aifbp' ); ?></th><th><?php esc_html_e( 'Mode', 'aifbp' ); ?></th><th><?php esc_html_e( 'Posted at', 'aifbp' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $history as $row ) : ?>
						<tr>
							<td>#<?php echo esc_html( (string) $row['product_id'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['page_id'] ); ?></code></td>
							<td><?php $this->pill( (string) $row['mode'] ); ?></td>
							<td><?php echo esc_html( (string) $row['posted_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function tab_comments(): void {
		$s        = Settings::all();
		$rows     = Comments::list( '', 50 );
		$webhook  = rest_url( 'aifbp/v1/webhook' );
		$statuses = [ 'pending' => __( 'Pending', 'aifbp' ), 'suggested' => __( 'Suggested reply', 'aifbp' ), 'approved' => __( 'Approved', 'aifbp' ), 'sent' => __( 'Replied', 'aifbp' ), 'failed' => __( 'Failed', 'aifbp' ), 'skipped' => __( 'Skipped', 'aifbp' ) ];
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Comment assistant settings', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Enable', 'aifbp' ); ?></th><td><label><input type="checkbox" name="comment_assistant" value="1" <?php checked( $s['comment_assistant'], 1 ); ?> /> <?php esc_html_e( 'Enable AI comment assistant', 'aifbp' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Auto reply', 'aifbp' ); ?></th><td><label><input type="checkbox" name="assistant_auto_reply" value="1" <?php checked( $s['assistant_auto_reply'], 1 ); ?> /> <?php esc_html_e( 'Reply automatically (off = suggestions wait for approval)', 'aifbp' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Manual approval', 'aifbp' ); ?></th><td><label><input type="checkbox" name="assistant_approval" value="1" <?php checked( $s['assistant_approval'], 1 ); ?> /> <?php esc_html_e( 'Require manual approval before sending replies', 'aifbp' ); ?></label></td></tr>
						<tr><th><label for="assistant_language"><?php esc_html_e( 'Reply language', 'aifbp' ); ?></label></th><td><input type="text" id="assistant_language" name="assistant_language" class="regular-text" value="<?php echo esc_attr( $s['assistant_language'] ); ?>" placeholder="Bangla + English" /></td></tr>
						<tr><th><label for="assistant_tone"><?php esc_html_e( 'Reply tone', 'aifbp' ); ?></label></th><td><input type="text" id="assistant_tone" name="assistant_tone" class="regular-text" value="<?php echo esc_attr( $s['assistant_tone'] ); ?>" /></td></tr>
						<tr><th><label for="assistant_provider"><?php esc_html_e( 'AI provider', 'aifbp' ); ?></label></th><td><input type="text" id="assistant_provider" name="assistant_provider" class="regular-text" value="<?php echo esc_attr( $s['assistant_provider'] ); ?>" placeholder="<?php esc_attr_e( 'Empty = use main AI provider', 'aifbp' ); ?>" /></td></tr>
						<tr><th><label for="assistant_instructions"><?php esc_html_e( 'Business instructions', 'aifbp' ); ?></label></th><td><textarea id="assistant_instructions" name="assistant_instructions" rows="4" class="large-text"><?php echo esc_textarea( $s['assistant_instructions'] ); ?></textarea></td></tr>
						<tr><th><label for="assistant_restricted"><?php esc_html_e( 'Restricted topics', 'aifbp' ); ?></label></th><td><textarea id="assistant_restricted" name="assistant_restricted" rows="3" class="large-text"><?php echo esc_textarea( $s['assistant_restricted'] ); ?></textarea><p class="description"><?php esc_html_e( 'The assistant never claims an order or payment is confirmed unless it is verified in WooCommerce.', 'aifbp' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'Privacy', 'aifbp' ); ?></th><td><label><input type="checkbox" name="assistant_privacy" value="1" <?php checked( $s['assistant_privacy'], 1 ); ?> /> <?php esc_html_e( 'Only send product titles/prices to the AI provider (no customer names or message text)', 'aifbp' ); ?></label></td></tr>
						<tr><th><label for="webhook_verify_token"><?php esc_html_e( 'Webhook verify token', 'aifbp' ); ?></label></th><td><input type="text" id="webhook_verify_token" name="webhook_verify_token" class="regular-text" value="<?php echo esc_attr( $s['webhook_verify_token'] ); ?>" /></td></tr>
					</table>
					<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save assistant settings', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Webhook setup', 'aifbp' ); ?></h2>
				<p><?php esc_html_e( 'Callback URL (Meta App > Webhooks > Page):', 'aifbp' ); ?><br /><code><?php echo esc_html( $webhook ); ?></code></p>
				<p><?php esc_html_e( 'Subscribe to the "feed" field to receive comments. The verify token above must match the value you enter in the Meta App dashboard. Signature (X-Hub-Signature-256) is verified with your App Secret.', 'aifbp' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_comments' ); ?>
					<button type="submit" name="aifbp_poll_comments" class="button button-secondary"><?php esc_html_e( 'Poll comments now', 'aifbp' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'Polling runs every 5 minutes in the background when the assistant is enabled; webhooks are the faster, recommended path.', 'aifbp' ); ?></p>
			</div>
		</div>

		<h2><?php esc_html_e( 'Comment activity', 'aifbp' ); ?></h2>
		<?php if ( empty( $rows ) ) : ?>
			<p class="description"><?php esc_html_e( 'No comments tracked yet. Enable the assistant, connect pages, and poll or set up the webhook.', 'aifbp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'From', 'aifbp' ); ?></th><th><?php esc_html_e( 'Comment', 'aifbp' ); ?></th><th><?php esc_html_e( 'Intent', 'aifbp' ); ?></th><th><?php esc_html_e( 'Suggested reply', 'aifbp' ); ?></th><th><?php esc_html_e( 'Status', 'aifbp' ); ?></th><th><?php esc_html_e( 'Actions', 'aifbp' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['from_name'] ); ?></td>
							<td><?php echo esc_html( wp_html_excerpt( (string) $row['message'], 80, '…' ) ); ?></td>
							<td><code><?php echo esc_html( (string) $row['intent'] ); ?></code></td>
							<td><?php echo esc_html( wp_html_excerpt( (string) $row['reply_text'], 120, '…' ) ); ?></td>
							<td><?php echo esc_html( $statuses[ (string) $row['status'] ] ?? (string) $row['status'] ); ?></td>
							<td>
								<form method="post" class="aifbp-inline">
									<?php wp_nonce_field( 'aifbp_comments' ); ?>
									<input type="hidden" name="comment_row_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
									<?php if ( in_array( (string) $row['status'], [ 'pending', 'suggested' ], true ) ) : ?>
										<button type="submit" name="aifbp_comment_action" value="approve" class="button button-small button-primary"><?php esc_html_e( 'Approve & send', 'aifbp' ); ?></button>
										<button type="submit" name="aifbp_comment_action" value="regenerate" class="button button-small"><?php esc_html_e( 'Regenerate', 'aifbp' ); ?></button>
									<?php endif; ?>
									<button type="submit" name="aifbp_comment_action" value="delete" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'aifbp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function tab_inbox(): void {
		$s           = Settings::all();
		$convos      = Inbox::list_conversations( 50 );
		$webhook     = rest_url( 'aifbp/v1/webhook' );
		$has_tokens  = '' !== OAuth::user_token();
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Inbox assistant settings', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Enable', 'aifbp' ); ?></th><td><label><input type="checkbox" name="inbox_assistant" value="1" <?php checked( $s['inbox_assistant'], 1 ); ?> /> <?php esc_html_e( 'Enable Messenger inbox assistant', 'aifbp' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Auto reply', 'aifbp' ); ?></th><td><label><input type="checkbox" name="assistant_auto_reply" value="1" <?php checked( $s['assistant_auto_reply'], 1 ); ?> /> <?php esc_html_e( 'Reply automatically (off = manual takeover only)', 'aifbp' ); ?></label></td></tr>
						<tr><th><label for="inbox-verify"><?php esc_html_e( 'Webhook verify token', 'aifbp' ); ?></label></th><td><input type="text" id="inbox-verify" name="webhook_verify_token" class="regular-text" value="<?php echo esc_attr( $s['webhook_verify_token'] ); ?>" /></td></tr>
					</table>
					<p class="description"><?php esc_html_e( 'Reply language, tone, business instructions, restricted topics, and privacy are shared with the Comment Assistant and configured on its tab.', 'aifbp' ); ?></p>
					<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save inbox settings', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Messenger webhook setup', 'aifbp' ); ?></h2>
				<p><?php esc_html_e( 'Callback URL (Meta App > Webhooks > Messenger):', 'aifbp' ); ?><br /><code><?php echo esc_html( $webhook ); ?></code></p>
				<p><?php esc_html_e( 'Subscribe to the "messages" and "messaging_postbacks" fields. Requires the pages_messaging permission and an approved Meta App. The assistant never sends messages outside the 24-hour standard messaging window and never claims an order or payment is confirmed.', 'aifbp' ); ?></p>
				<?php if ( ! $has_tokens ) : ?>
					<p class="description"><?php esc_html_e( 'Connect Facebook first on the Facebook Connection tab.', 'aifbp' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<h2><?php esc_html_e( 'Conversations', 'aifbp' ); ?></h2>
		<?php if ( empty( $convos ) ) : ?>
			<p class="description"><?php esc_html_e( 'No conversations received yet. Incoming Messenger messages appear here once the webhook is live.', 'aifbp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Sender', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Last message', 'aifbp' ); ?></th><th><?php esc_html_e( 'Last reply', 'aifbp' ); ?></th><th><?php esc_html_e( 'Status', 'aifbp' ); ?></th><th><?php esc_html_e( 'Updated', 'aifbp' ); ?></th><th><?php esc_html_e( 'Actions', 'aifbp' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $convos as $c ) : ?>
						<tr>
							<td><?php echo esc_html( '' !== (string) $c['sender_name'] ? (string) $c['sender_name'] : (string) $c['sender_id'] ); ?></td>
							<td><code><?php echo esc_html( (string) $c['page_id'] ); ?></code></td>
							<td><?php echo esc_html( wp_html_excerpt( (string) $c['last_message'], 70, '…' ) ); ?></td>
							<td><?php echo esc_html( wp_html_excerpt( (string) $c['last_reply'], 70, '…' ) ); ?></td>
							<td><?php echo esc_html( 'human' === (string) $c['status'] ? __( 'Human takeover', 'aifbp' ) : __( 'AI', 'aifbp' ) ); ?></td>
							<td><?php echo esc_html( (string) $c['updated_at'] ); ?></td>
							<td>
								<form method="post" class="aifbp-inline">
									<?php wp_nonce_field( 'aifbp_inbox' ); ?>
									<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $c['page_id'] ); ?>" />
									<input type="hidden" name="sender_id" value="<?php echo esc_attr( (string) $c['sender_id'] ); ?>" />
									<?php if ( 'human' !== (string) $c['status'] ) : ?>
										<button type="submit" name="aifbp_inbox_action" value="handoff" class="button button-small"><?php esc_html_e( 'Take over', 'aifbp' ); ?></button>
									<?php else : ?>
										<button type="submit" name="aifbp_inbox_action" value="resume" class="button button-small"><?php esc_html_e( 'Resume AI', 'aifbp' ); ?></button>
									<?php endif; ?>
								</form>
								<form method="post" class="aifbp-inline">
									<?php wp_nonce_field( 'aifbp_inbox' ); ?>
									<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $c['page_id'] ); ?>" />
									<input type="hidden" name="sender_id" value="<?php echo esc_attr( (string) $c['sender_id'] ); ?>" />
									<input type="text" name="reply_text" placeholder="<?php esc_attr_e( 'Manual reply…', 'aifbp' ); ?>" />
									<button type="submit" name="aifbp_inbox_action" value="reply" class="button button-small button-primary"><?php esc_html_e( 'Send', 'aifbp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function tab_insights(): void {
		$time     = Advisor::best_time();
		$products = Advisor::best_products();
		$activity = Advisor::assistant_activity();
		?>
		<div class="aifbp-cards">
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $activity['comments_tracked'] ); ?></span><span><?php esc_html_e( 'Comments tracked', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $activity['comments_replied'] ); ?></span><span><?php esc_html_e( 'Replies sent', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $activity['conversations'] ); ?></span><span><?php esc_html_e( 'Messenger chats', 'aifbp' ); ?></span></div>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Best posting time', 'aifbp' ); ?></h2>
			<?php if ( empty( $time['sufficient'] ) ) : ?>
				<p class="description">⏳ <?php echo esc_html( (string) $time['message'] ); ?></p>
			<?php else : ?>
				<p>🏆 <strong><?php echo esc_html( (string) $time['best_hour'] ); ?></strong> — <?php echo esc_html( (string) $time['best_day'] ); ?></p>
				<p class="description"><?php echo esc_html( (string) $time['message'] ); ?></p>
				<table class="widefat striped" style="max-width:520px">
					<thead><tr><th><?php esc_html_e( 'Hour', 'aifbp' ); ?></th><th><?php esc_html_e( 'Engagement score', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $time['hour_scores'] as $hour => $score ) : ?>
							<tr><td><?php echo esc_html( sprintf( '%02d:00', (int) $hour ) ); ?></td><td><?php echo esc_html( (string) $score ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Only real Meta insights are used. Nothing is fabricated; recommendations appear once enough posts collect engagement data.', 'aifbp' ); ?></p>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Best products', 'aifbp' ); ?></h2>
			<?php if ( empty( $products['sufficient'] ) ) : ?>
				<p class="description">⏳ <?php echo esc_html( (string) $products['message'] ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Product', 'aifbp' ); ?></th><th><?php esc_html_e( 'Score', 'aifbp' ); ?></th><th><?php esc_html_e( 'Why', 'aifbp' ); ?></th><th><?php esc_html_e( 'Suggested content', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $products['products'] as $p ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $p['name'] ); ?> <?php echo empty( $p['recently_posted'] ) ? '' : '<span class="aifbp-mode">recent</span>'; ?></td>
								<td><strong><?php echo esc_html( (string) $p['score'] ); ?></strong></td>
								<td><?php echo esc_html( (string) $p['reason'] ); ?></td>
								<td><code><?php echo esc_html( (string) $p['suggested_content'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function tab_queue(): void {
		$filter   = isset( $_GET['qstatus'] ) ? sanitize_key( wp_unslash( $_GET['qstatus'] ) ) : '';
		$allowed  = [ 'pending', 'processing', 'failed', 'draft', 'cancelled' ];
		$filter   = in_array( $filter, $allowed, true ) ? $filter : '';
		$rows     = QueueManager::list( $filter, 50 );
		$counts   = QueueManager::counts();
		$labels   = [ 'pending' => __( 'Pending', 'aifbp' ), 'processing' => __( 'Processing', 'aifbp' ), 'posted' => __( 'Posted', 'aifbp' ), 'failed' => __( 'Failed', 'aifbp' ), 'cancelled' => __( 'Cancelled', 'aifbp' ), 'draft' => __( 'Draft', 'aifbp' ), '' => __( 'All', 'aifbp' ) ];
		?>
		<p class="aifbp-filters">
			<?php foreach ( $labels as $slug => $label ) : ?>
				<a class="<?php echo $filter === $slug ? 'aifbp-filter-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=queue' . ( '' !== $slug ? '&qstatus=' . $slug : '' ) ) ); ?>"><?php echo esc_html( $label ); ?> (<?php echo esc_html( '' === $slug ? array_sum( $counts ) : (string) ( $counts[ $slug ] ?? 0 ) ); ?>)</a>
			<?php endforeach; ?>
		</p>

		<?php if ( empty( $rows ) ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing here. Create a schedule or use bulk scheduling to fill the queue.', 'aifbp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Post', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Mode', 'aifbp' ); ?></th><th><?php esc_html_e( 'Status', 'aifbp' ); ?></th><th><?php esc_html_e( 'Scheduled', 'aifbp' ); ?></th><th><?php esc_html_e( 'Manage', 'aifbp' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $item ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( wp_html_excerpt( (string) $item['product_name'], 40, '…' ) ); ?></strong><br />
								<span class="description">#<?php echo esc_html( (string) $item['id'] ); ?> · <?php echo esc_html( (string) $item['source'] ); ?><?php echo (int) $item['attempts'] > 0 ? ' · ' . esc_html( sprintf( __( 'attempts: %d', 'aifbp' ), (int) $item['attempts'] ) ) : ''; ?></span>
								<?php if ( '' !== (string) $item['last_error'] ) : ?>
									<br /><span class="aifbp-error-text"><?php echo esc_html( wp_html_excerpt( (string) $item['last_error'], 100, '…' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $item['page_name'] ); ?></td>
							<td><?php $this->pill( (string) $item['mode'] ); ?></td>
							<td><?php echo esc_html( $labels[ (string) $item['status'] ] ?? (string) $item['status'] ); ?></td>
							<td><?php echo esc_html( (string) $item['scheduled_at'] ); ?></td>
							<td>
								<details>
									<summary class="button button-small"><?php esc_html_e( 'Manage', 'aifbp' ); ?></summary>
									<div class="aifbp-manage">
										<form method="post">
											<?php wp_nonce_field( 'aifbp_queue' ); ?>
											<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
											<input type="hidden" name="back_tab" value="queue" />
											<label><?php esc_html_e( 'Caption', 'aifbp' ); ?><br /><textarea name="caption" rows="4" class="large-text"><?php echo esc_textarea( (string) $item['caption'] ); ?></textarea></label>
											<button type="submit" name="aifbp_edit_caption" value="1" class="button button-small button-primary"><?php esc_html_e( 'Save caption', 'aifbp' ); ?></button>
										</form>
										<form method="post">
											<?php wp_nonce_field( 'aifbp_queue' ); ?>
											<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
											<input type="hidden" name="back_tab" value="queue" />
											<input type="url" name="image_url" placeholder="<?php esc_attr_e( 'Image URL', 'aifbp' ); ?>" class="regular-text" />
											<button type="submit" name="aifbp_change_image" value="1" class="button button-small"><?php esc_html_e( 'Change image', 'aifbp' ); ?></button>
										</form>
										<?php if ( class_exists( 'WooCommerce' ) ) : ?>
										<form method="post">
											<?php wp_nonce_field( 'aifbp_queue' ); ?>
											<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
											<input type="hidden" name="back_tab" value="queue" />
											<select name="product_id"><?php $this->product_options( (int) $item['product_id'] ); ?></select>
											<button type="submit" name="aifbp_change_product" value="1" class="button button-small"><?php esc_html_e( 'Change product', 'aifbp' ); ?></button>
										</form>
										<?php endif; ?>
										<form method="post">
											<?php wp_nonce_field( 'aifbp_queue' ); ?>
											<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
											<input type="hidden" name="back_tab" value="queue" />
											<input type="datetime-local" name="new_time" value="<?php echo esc_attr( date( 'Y-m-d\TH:i', (int) strtotime( (string) $item['scheduled_at'] ) ) ); ?>" />
											<?php if ( in_array( (string) $item['status'], [ 'pending', 'processing' ], true ) ) : ?>
												<button type="submit" name="aifbp_queue_action" value="reschedule" class="button button-small"><?php esc_html_e( 'Reschedule', 'aifbp' ); ?></button>
												<button type="submit" name="aifbp_queue_action" value="pause" class="button button-small"><?php esc_html_e( 'Pause', 'aifbp' ); ?></button>
												<button type="submit" name="aifbp_queue_action" value="publish" class="button button-small button-primary"><?php esc_html_e( 'Publish now', 'aifbp' ); ?></button>
											<?php endif; ?>
											<?php if ( 'draft' === $item['status'] ) : ?>
												<button type="submit" name="aifbp_queue_action" value="resume" class="button button-small"><?php esc_html_e( 'Resume', 'aifbp' ); ?></button>
											<?php endif; ?>
											<button type="submit" name="aifbp_queue_action" value="duplicate" class="button button-small"><?php esc_html_e( 'Duplicate', 'aifbp' ); ?></button>
											<button type="submit" name="aifbp_queue_action" value="cancel" class="button button-small"><?php esc_html_e( 'Cancel', 'aifbp' ); ?></button>
											<button type="submit" name="aifbp_queue_action" value="delete" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'aifbp' ); ?></button>
										</form>
									</div>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function tab_history(): void {
		$rows = QueueManager::list( 'posted', 100 );
		$csv  = wp_nonce_url( admin_url( 'admin-post.php?action=aifbp_export_csv&type=history' ), 'aifbp_export' );
		?>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV', 'aifbp' ); ?></a>
		</p>
		<?php if ( empty( $rows ) ) : ?>
			<p class="description"><?php esc_html_e( 'No published posts yet.', 'aifbp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Product', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Mode', 'aifbp' ); ?></th><th><?php esc_html_e( 'Published', 'aifbp' ); ?></th><th><?php esc_html_e( 'Facebook post', 'aifbp' ); ?></th><th><?php esc_html_e( 'Caption', 'aifbp' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $item ) : ?>
						<tr>
							<td><?php echo esc_html( wp_html_excerpt( (string) $item['product_name'], 40, '…' ) ); ?></td>
							<td><?php echo esc_html( (string) $item['page_name'] ); ?></td>
							<td><?php $this->pill( (string) $item['mode'] ); ?></td>
							<td><?php echo esc_html( (string) $item['posted_at'] ); ?></td>
							<td>
								<?php if ( '' !== (string) $item['fb_post_id'] ) : ?>
									<a href="<?php echo esc_url( 'https://www.facebook.com/' . (string) $item['fb_post_id'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'aifbp' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( wp_html_excerpt( (string) $item['caption'], 100, '…' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function tab_analytics(): void {
		$totals   = Analytics::totals();
		$modes    = QueueManager::mode_counts();
		$activity = Advisor::assistant_activity();
		$period   = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'daily';
		$period   = in_array( $period, [ 'daily', 'weekly', 'monthly' ], true ) ? $period : 'daily';
		$history  = QueueManager::list( 'posted', 100 );
		$csv      = wp_nonce_url( admin_url( 'admin-post.php?action=aifbp_export_csv&type=analytics' ), 'aifbp_export' );
		$buckets  = [];

		foreach ( $history as $item ) {
			if ( '' === (string) $item['posted_at'] ) {
				continue;
			}

			$key = 'daily' === $period
				? date( 'Y-m-d', (int) strtotime( (string) $item['posted_at'] ) )
				: ( 'weekly' === $period
					? date( 'o-\WW', (int) strtotime( (string) $item['posted_at'] ) )
					: date( 'Y-m', (int) strtotime( (string) $item['posted_at'] ) ) );

			$buckets[ $key ] = ( $buckets[ $key ] ?? 0 ) + 1;
		}

		krsort( $buckets );
		?>
		<div class="aifbp-cards">
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $totals['queue']['posted'] ); ?></span><span><?php esc_html_e( 'Published posts', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $totals['queue']['pending'] ); ?></span><span><?php esc_html_e( 'Scheduled', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $totals['queue']['failed'] ); ?></span><span><?php esc_html_e( 'Failed', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $totals['impressions'] ); ?></span><span><?php esc_html_e( 'Impressions', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) $totals['clicks'] ); ?></span><span><?php esc_html_e( 'Clicks', 'aifbp' ); ?></span></div>
			<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( (string) ( $totals['reactions'] + $totals['comments'] ) ); ?></span><span><?php esc_html_e( 'Engagement', 'aifbp' ); ?></span></div>
		</div>

		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Posting mode statistics', 'aifbp' ); ?></h2>
				<p><?php esc_html_e( 'AI posts:', 'aifbp' ); ?> <strong><?php echo esc_html( (string) $modes['ai'] ); ?></strong> | <?php esc_html_e( 'Direct posts:', 'aifbp' ); ?> <strong><?php echo esc_html( (string) $modes['direct'] ); ?></strong></p>
				<h2><?php esc_html_e( 'Assistant activity', 'aifbp' ); ?></h2>
				<p><?php esc_html_e( 'Comments tracked:', 'aifbp' ); ?> <strong><?php echo esc_html( (string) $activity['comments_tracked'] ); ?></strong> | <?php esc_html_e( 'Replies sent:', 'aifbp' ); ?> <strong><?php echo esc_html( (string) $activity['comments_replied'] ); ?></strong> | <?php esc_html_e( 'Messenger chats:', 'aifbp' ); ?> <strong><?php echo esc_html( (string) $activity['conversations'] ); ?></strong></p>
				<h2><?php esc_html_e( 'AI usage', 'aifbp' ); ?></h2>
				<p><?php printf( esc_html__( 'Today: %d AI calls | This month: %d AI calls', 'aifbp' ), esc_html( Usage::today( 'ai' ) ), esc_html( Usage::month()['ai'] ) ); ?></p>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Reports', 'aifbp' ); ?></h2>
				<p class="aifbp-filters">
					<a class="<?php echo 'daily' === $period ? 'aifbp-filter-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=analytics&period=daily' ) ); ?>"><?php esc_html_e( 'Daily', 'aifbp' ); ?></a>
					<a class="<?php echo 'weekly' === $period ? 'aifbp-filter-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=analytics&period=weekly' ) ); ?>"><?php esc_html_e( 'Weekly', 'aifbp' ); ?></a>
					<a class="<?php echo 'monthly' === $period ? 'aifbp-filter-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=analytics&period=monthly' ) ); ?>"><?php esc_html_e( 'Monthly', 'aifbp' ); ?></a>
				</p>
				<?php if ( empty( $buckets ) ) : ?>
					<p class="description"><?php esc_html_e( 'No published posts yet.', 'aifbp' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:420px">
						<thead><tr><th><?php esc_html_e( 'Period', 'aifbp' ); ?></th><th><?php esc_html_e( 'Posts', 'aifbp' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( array_slice( $buckets, 0, 15, true ) as $key => $n ) : ?>
								<tr><td><?php echo esc_html( (string) $key ); ?></td><td><?php echo esc_html( (string) $n ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export analytics CSV', 'aifbp' ); ?></a>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_tools' ); ?>
					<button type="submit" name="aifbp_refresh_analytics" class="button button-secondary"><?php esc_html_e( 'Refresh insights from Facebook', 'aifbp' ); ?></button>
				</form>
			</div>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Top products', 'aifbp' ); ?></h2>
			<?php
			$best = Advisor::best_products( 5 );

			if ( empty( $best['sufficient'] ) ) :
				?>
				<p class="description"><?php echo esc_html( (string) $best['message'] ); ?></p>
			<?php else : ?>
				<ol>
					<?php foreach ( $best['products'] as $p ) : ?>
						<li><?php echo esc_html( (string) $p['name'] ); ?> — <?php echo esc_html( (string) $p['reason'] ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	private function tab_templates(): void {
		$s = Settings::all();
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'AI caption templates', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_settings' ); ?>
					<textarea name="templates" rows="10" class="large-text code"><?php echo esc_textarea( $s['templates'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One template per line: name | body. AI variables:', 'aifbp' ); ?>
						<code>{name}</code> <code>{price}</code> <code>{link}</code> <code>{short_bn}</code> <code>{short_en}</code> <code>{long_bn}</code> <code>{long_en}</code> <code>{cta}</code> <code>{hashtags}</code>
					</p>
					<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save templates', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Direct template variables', 'aifbp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Use these in the custom direct template (Direct Auto Posting tab):', 'aifbp' ); ?></p>
				<p>
					<?php foreach ( DirectEngine::variables_doc() as $var ) : ?>
						<code><?php echo esc_html( $var ); ?></code>
					<?php endforeach; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Direct templates are filled from live WooCommerce product data — no AI involved.', 'aifbp' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function tab_tools(): void {
		$s           = Settings::all();
		$webhook     = rest_url( 'aifbp/v1/webhook' );
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Maintenance', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_tools' ); ?>
					<p>
						<button type="submit" name="aifbp_clear_log" class="button button-secondary"><?php esc_html_e( 'Clear plugin log', 'aifbp' ); ?></button>
						<button type="submit" name="aifbp_refresh_analytics" class="button button-secondary"><?php esc_html_e( 'Refresh analytics', 'aifbp' ); ?></button>
					</p>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_comments' ); ?>
					<p><button type="submit" name="aifbp_poll_comments" class="button button-secondary"><?php esc_html_e( 'Poll comments now', 'aifbp' ); ?></button></p>
				</form>
				<p><button type="button" class="button button-secondary" id="aifbp-process-btn"><?php esc_html_e( 'Run queue now', 'aifbp' ); ?></button></p>
				<div id="aifbp-result" class="aifbp-result"></div>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Data & privacy', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="data_retention_days"><?php esc_html_e( 'Data retention (days)', 'aifbp' ); ?></label></th><td><input type="number" id="data_retention_days" name="data_retention_days" min="7" value="<?php echo esc_attr( (string) $s['data_retention_days'] ); ?>" /><p class="description"><?php esc_html_e( 'Posted queue rows and logs older than this are cleaned up automatically.', 'aifbp' ); ?></p></td></tr>
					</table>
					<button type="submit" name="aifbp_save_settings" class="button button-primary"><?php esc_html_e( 'Save', 'aifbp' ); ?></button>
				</form>
				<h2><?php esc_html_e( 'Webhook endpoint', 'aifbp' ); ?></h2>
				<p><code><?php echo esc_html( $webhook ); ?></code></p>
			</div>
		</div>

		<h2><?php esc_html_e( 'Recent log', 'aifbp' ); ?></h2>
		<?php
		$logs = Logger::recent( 30 );

		if ( empty( $logs ) ) {
			echo '<p class="description">' . esc_html__( 'Log is empty.', 'aifbp' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'aifbp' ) . '</th><th>' . esc_html__( 'Level', 'aifbp' ) . '</th><th>' . esc_html__( 'Context', 'aifbp' ) . '</th><th>' . esc_html__( 'Message', 'aifbp' ) . '</th></tr></thead><tbody>';
			foreach ( $logs as $log ) {
				echo '<tr><td>' . esc_html( (string) $log['created_at'] ) . '</td><td>' . esc_html( (string) $log['level'] ) . '</td><td>' . esc_html( (string) $log['context'] ) . '</td><td>' . esc_html( wp_html_excerpt( (string) $log['message'], 140, '…' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private function tab_status(): void {
		$health  = ScheduleManager::cron_health();
		$pages   = Settings::pages();
		$gd      = extension_loaded( 'gd' );
		$wc      = class_exists( 'WooCommerce' );
		$as      = Processor::action_scheduler_available();
		$version = defined( 'AIFB_VERSION' ) ? AIFB_VERSION : '3.0.0';
		$dbver   = defined( 'AIFB_DB_VERSION' ) ? AIFB_DB_VERSION : '3';
		$perms   = [
			'pages_show_list'        => __( 'List your Facebook Pages (required)', 'aifbp' ),
			'pages_manage_posts'     => __( 'Publish posts to Pages (required)', 'aifbp' ),
			'pages_read_engagement'  => __( 'Read Page posts, comments and insights (required)', 'aifbp' ),
			'pages_manage_engagement' => __( 'Post comments and like on behalf of the Page (comment assistant)', 'aifbp' ),
			'pages_messaging'        => __( 'Send/receive Messenger messages (inbox assistant)', 'aifbp' ),
			'pages_read_user_content' => __( 'Read user comments on Page posts (webhook/polling)', 'aifbp' ),
			'business_management'    => __( 'Only if managing via Business Manager (optional)', 'aifbp' ),
		];
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Environment', 'aifbp' ); ?></h2>
				<table class="widefat striped" style="max-width:640px">
					<tbody>
						<tr><td>Plugin version</td><td><?php echo esc_html( $version ); ?> (DB <?php echo esc_html( $dbver ); ?>)</td></tr>
						<tr><td>PHP version</td><td><?php echo esc_html( PHP_VERSION ); ?> (8.1+ required)</td></tr>
						<tr><td>WordPress version</td><td><?php echo esc_html( (string) get_bloginfo( 'version' ) ); ?></td></tr>
						<tr><td>WooCommerce</td><td><?php echo $wc ? '✅' : '❌'; ?></td></tr>
						<tr><td>PHP GD (poster designer)</td><td><?php echo $gd ? '✅' : '❌'; ?></td></tr>
						<tr><td>Facebook connection</td><td><?php echo '' !== OAuth::user_token() ? '✅' : '❌'; ?></td></tr>
						<tr><td>Connected pages</td><td><?php echo esc_html( (string) count( $pages ) ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Cron & queue health', 'aifbp' ); ?></h2>
				<table class="widefat striped" style="max-width:640px">
					<tbody>
						<tr><td>WP-Cron enabled</td><td><?php echo $health['wp_cron_enabled'] ? '✅' : '⚠️ DISABLE_WP_CRON is set — an external cron must call wp-cron.php'; ?></td></tr>
						<tr><td>Engine</td><td><?php echo esc_html( $as ? 'Action Scheduler' : 'WP-Cron' ); ?></td></tr>
						<tr><td>Next queue run</td><td><?php echo esc_html( (string) ( $health['queue_next_run'] ?? '—' ) ); ?></td></tr>
						<tr><td>Next scheduler tick</td><td><?php echo esc_html( (string) ( $health['scheduler_next_run'] ?? '—' ) ); ?></td></tr>
						<tr><td>Scheduler late</td><td><?php echo $health['last_tick_late'] ? '⚠️ ' . esc_html__( 'yes — check wp-cron traffic', 'aifbp' ) : '✅'; ?></td></tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Webhook endpoint:', 'aifbp' ); ?> <code><?php echo esc_html( rest_url( 'aifbp/v1/webhook' ) ); ?></code></p>
			</div>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Meta permissions checklist', 'aifbp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Request these in your Meta App (App Review required for Advanced Access). The plugin only uses official Graph API endpoints.', 'aifbp' ); ?></p>
			<ul style="list-style:disc;padding-left:20px">
				<?php foreach ( $perms as $perm => $why ) : ?>
					<li><code><?php echo esc_html( $perm ); ?></code> — <?php echo esc_html( $why ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
