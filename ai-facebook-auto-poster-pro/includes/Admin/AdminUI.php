<?php

namespace AIFB\Admin;

use AIFB\Analytics\Analytics;
use AIFB\Facebook\Client;
use AIFB\Facebook\OAuth;
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

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
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
				esc_html__( 'AI FB Poster: your Facebook user token is expiring soon. Reconnect on the Connection tab to keep scheduled posts working.', 'aifbp' )
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

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE !== $page ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		if ( isset( $_POST['aifbp_save_settings'] ) ) {
			check_admin_referer( 'aifbp_settings' );
			$this->save_settings();
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => $tab, 'aifbp_msg' => rawurlencode( __( 'Settings saved.', 'aifbp' ) ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_disconnect'] ) ) {
			check_admin_referer( 'aifbp_settings' );
			OAuth::disconnect();
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'connection', 'aifbp_msg' => rawurlencode( __( 'Disconnected from Facebook.', 'aifbp' ) ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_test_fb'] ) ) {
			check_admin_referer( 'aifbp_settings' );
			$this->save_settings();

			$page_id = (string) Settings::get( 'test_page_id' );
			$token   = Settings::page_token( $page_id );
			$result  = Client::verify_page_token( $page_id, $token );

			$msg = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Page OK: %s (%s)', $result['name'], $result['id'] );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'connection', 'aifbp_msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_create_schedule'] ) ) {
			check_admin_referer( 'aifbp_schedule' );

			$result = ScheduleManager::create(
				[
					'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
					'type'             => sanitize_key( wp_unslash( $_POST['type'] ?? 'daily' ) ),
					'time_of_day'      => sanitize_text_field( wp_unslash( $_POST['time_of_day'] ?? '10:00' ) ),
					'days'             => implode( ',', array_map( 'absint', (array) ( $_POST['days'] ?? [] ) ) ),
					'posts_per_run'    => absint( wp_unslash( $_POST['posts_per_run'] ?? 1 ) ),
					'interval_minutes' => absint( wp_unslash( $_POST['interval_minutes'] ?? 120 ) ),
					'pages'            => array_map( 'sanitize_text_field', (array) ( $_POST['pages'] ?? [] ) ),
					'start_date'       => sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ),
					'end_date'         => sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) ),
					'active'           => 1,
				]
			);

			$msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Schedule created.', 'aifbp' );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'scheduling', 'aifbp_msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_bulk_schedule'] ) ) {
			check_admin_referer( 'aifbp_schedule' );

			$result = ScheduleManager::bulk_enqueue(
				absint( wp_unslash( $_POST['count'] ?? 1 ) ),
				sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['end'] ?? '' ) ),
				array_map( 'sanitize_text_field', (array) ( $_POST['pages'] ?? [] ) ),
				absint( wp_unslash( $_POST['interval_minutes'] ?? 60 ) )
			);

			$msg = is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%d post(s) queued.', $result );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'scheduling', 'aifbp_msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_delete_schedule'], $_POST['schedule_id'] ) ) {
			check_admin_referer( 'aifbp_schedule' );
			ScheduleManager::delete( absint( wp_unslash( $_POST['schedule_id'] ) ) );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'scheduling', 'aifbp_msg' => rawurlencode( __( 'Schedule deleted.', 'aifbp' ) ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_toggle_schedule'], $_POST['schedule_id'] ) ) {
			check_admin_referer( 'aifbp_schedule' );

			$id       = absint( wp_unslash( $_POST['schedule_id'] ) );
			$schedule = ScheduleManager::get( $id );

			if ( $schedule ) {
				ScheduleManager::update( $id, [ 'active' => empty( $schedule['active'] ) ? 1 : 0 ] );
			}

			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'scheduling' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_clear_log'] ) ) {
			check_admin_referer( 'aifbp_tools' );
			Logger::clear();
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'tools', 'aifbp_msg' => rawurlencode( __( 'Log cleared.', 'aifbp' ) ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['aifbp_refresh_analytics'] ) ) {
			check_admin_referer( 'aifbp_tools' );
			$updated = Analytics::refresh_all( 50 );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'dashboard', 'aifbp_msg' => rawurlencode( sprintf( '%d post(s) refreshed.', $updated ) ) ], admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	private function save_settings(): void {
		$s = Settings::all();

		$keys = [
			'app_id', 'app_secret', 'ai_provider', 'openai_model', 'openai_base',
			'gemini_model', 'custom_base', 'custom_model', 'caption_language',
			'currency', 'test_page_id',
		];

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$s[ $key ] = 'app_secret' === $key || str_ends_with( $key, '_key' )
					? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
					: sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( [ 'openai_key', 'gemini_key', 'custom_key' ] as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== (string) $_POST[ $key ] ) {
				$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( [ 'include_price', 'include_link', 'include_hashtags', 'include_cta', 'price_badge', 'ai_image_gen', 'ai_image_understand' ] as $key ) {
			$s[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}

		$s['variations']        = max( 0, min( 5, absint( wp_unslash( $_POST['variations'] ?? 2 ) ) ) );
		$s['cooldown_days']     = max( 0, absint( wp_unslash( $_POST['cooldown_days'] ?? 7 ) ) );
		$s['max_attempts']      = max( 1, absint( wp_unslash( $_POST['max_attempts'] ?? 3 ) ) );
		$s['retry_delay_min']   = max( 1, absint( wp_unslash( $_POST['retry_delay_min'] ?? 30 ) ) );
		$s['post_interval_min'] = max( 5, absint( wp_unslash( $_POST['post_interval_min'] ?? 60 ) ) );
		$s['templates']         = sanitize_textarea_field( wp_unslash( $_POST['templates'] ?? '' ) );

		if ( '' !== (string) $s['custom_base'] && ! wp_http_validate_url( $s['custom_base'] ) ) {
			$s['custom_base'] = '';
		}

		if ( '' !== (string) $s['openai_base'] && ! wp_http_validate_url( $s['openai_base'] ) ) {
			$s['openai_base'] = 'https://api.openai.com/v1';
		}

		Settings::update( $s );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$msg = isset( $_GET['aifbp_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['aifbp_msg'] ) ) ) : '';

		$tabs = [
			'dashboard'  => __( 'Dashboard', 'aifbp' ),
			'connection' => __( 'Connection', 'aifbp' ),
			'ai'         => __( 'AI Settings', 'aifbp' ),
			'scheduling' => __( 'Scheduling', 'aifbp' ),
			'queue'      => __( 'Queue', 'aifbp' ),
			'history'    => __( 'History & Logs', 'aifbp' ),
			'templates'  => __( 'Templates', 'aifbp' ),
			'tools'      => __( 'Tools', 'aifbp' ),
		];

		?>
		<div class="wrap aifbp-wrap">
			<h1 class="aifbp-title"><?php esc_html_e( 'AI Facebook Product Auto Poster Pro', 'aifbp' ); ?></h1>

			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success is-dismissible aifbp-fade"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<nav class="aifbp-tabs nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
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
					case 'scheduling':
						$this->tab_scheduling();
						break;
					case 'queue':
						$this->tab_queue();
						break;
					case 'history':
						$this->tab_history();
						break;
					case 'templates':
						$this->tab_templates();
						break;
					case 'tools':
						$this->tab_tools();
						break;
					default:
						$this->tab_dashboard();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function tab_dashboard(): void {
		$totals = Analytics::totals();
		$counts = $totals['queue'];
		$month  = Usage::month();
		$max    = max( 1, ...array_merge( [ 1 ], array_column( $totals['per_day'], 'posts' ) ?: [ 1 ] ) );
		?>
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
						<div class="aifbp-bar" title="<?php echo esc_attr( $row['day'] . ': ' . $row['posts'] ); ?>" style="height: <?php echo esc_attr( max( 4, (int) ( 100 * $row['posts'] / $max ) ) ); ?>%"></div>
					<?php endforeach; ?>
					<?php if ( empty( $totals['per_day'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No posts yet.', 'aifbp' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'API usage', 'aifbp' ); ?></h2>
				<p><?php printf( esc_html__( 'Today — AI calls: %d, Facebook calls: %d', 'aifbp' ), esc_html( Usage::today( 'ai' ) ), esc_html( Usage::today( 'fb' ) ) ); ?></p>
				<p><?php printf( esc_html__( 'This month — AI calls: %d, Facebook calls: %d', 'aifbp' ), esc_html( $month['ai'] ), esc_html( $month['fb'] ) ); ?></p>
				<p><?php printf( esc_html__( 'Queue engine: %s', 'aifbp' ), esc_html( Processor::action_scheduler_available() ? 'Action Scheduler' : 'WP-Cron' ) ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'aifbp_tools' ); ?>
					<button type="submit" name="aifbp_refresh_analytics" class="button button-secondary"><?php esc_html_e( 'Refresh analytics from Facebook', 'aifbp' ); ?></button>
				</form>
			</div>
		</div>

		<div class="aifbp-panel">
			<h2><?php esc_html_e( 'Quick actions', 'aifbp' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="aifbp-preview-btn"><?php esc_html_e( 'Generate AI preview', 'aifbp' ); ?></button>
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
		$s     = Settings::all();
		$pages = Settings::pages();
		?>
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
						<p class="description"><?php esc_html_e( 'Stored encrypted. Create a Business app at developers.facebook.com and add the Facebook Login for Business product.', 'aifbp' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'OAuth connection', 'aifbp' ); ?></h2>
			<?php if ( OAuth::is_configured() ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( OAuth::login_url() ); ?>"><?php esc_html_e( 'Connect Facebook account', 'aifbp' ); ?></a>
				</p>
				<p class="description">
					<?php esc_html_e( 'Add this redirect URI to your Facebook app settings (Valid OAuth Redirect URIs):', 'aifbp' ); ?><br />
					<code><?php echo esc_html( OAuth::redirect_uri() ); ?></code>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Save your App ID and App Secret first, then connect your Facebook account.', 'aifbp' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connected pages', 'aifbp' ); ?></h2>
			<?php if ( empty( $pages ) ) : ?>
				<p class="description"><?php esc_html_e( 'No pages connected yet.', 'aifbp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 640px">
					<thead><tr><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page ID', 'aifbp' ); ?></th><th><?php esc_html_e( 'Token', 'aifbp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $pages as $page ) : ?>
							<tr>
								<td><?php echo esc_html( $page['name'] ); ?></td>
								<td><code><?php echo esc_html( $page['id'] ); ?></code></td>
								<td><?php echo esc_html( Crypto::mask( Crypto::decrypt( (string) $page['token'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<table class="form-table" role="presentation">
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
		<?php
	}

	private function tab_ai(): void {
		$s = Settings::all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'aifbp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ai_provider"><?php esc_html_e( 'AI provider', 'aifbp' ); ?></label></th>
					<td>
						<select id="ai_provider" name="ai_provider">
							<option value="openai" <?php selected( $s['ai_provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'aifbp' ); ?></option>
							<option value="gemini" <?php selected( $s['ai_provider'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'aifbp' ); ?></option>
							<option value="custom" <?php selected( $s['ai_provider'], 'custom' ); ?>><?php esc_html_e( 'Custom OpenAI-compatible', 'aifbp' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'OpenAI', 'aifbp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="openai_key"><?php esc_html_e( 'API key', 'aifbp' ); ?></label></th>
					<td><input type="password" id="openai_key" name="openai_key" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== $s['openai_key'] ? __( '(saved)', 'aifbp' ) : '' ); ?>" autocomplete="new-password" /></td>
				</tr>
				<tr>
					<th><label for="openai_base"><?php esc_html_e( 'Base URL', 'aifbp' ); ?></label></th>
					<td><input type="url" id="openai_base" name="openai_base" class="regular-text" value="<?php echo esc_attr( $s['openai_base'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="openai_model"><?php esc_html_e( 'Model', 'aifbp' ); ?></label></th>
					<td><input type="text" id="openai_model" name="openai_model" class="regular-text" value="<?php echo esc_attr( $s['openai_model'] ); ?>" /></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Google Gemini', 'aifbp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="gemini_key"><?php esc_html_e( 'API key', 'aifbp' ); ?></label></th>
					<td><input type="password" id="gemini_key" name="gemini_key" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== $s['gemini_key'] ? __( '(saved)', 'aifbp' ) : '' ); ?>" autocomplete="new-password" /></td>
				</tr>
				<tr>
					<th><label for="gemini_model"><?php esc_html_e( 'Model', 'aifbp' ); ?></label></th>
					<td><input type="text" id="gemini_model" name="gemini_model" class="regular-text" value="<?php echo esc_attr( $s['gemini_model'] ); ?>" /></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Custom endpoint (OpenAI-compatible)', 'aifbp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="custom_base"><?php esc_html_e( 'Base URL', 'aifbp' ); ?></label></th>
					<td><input type="url" id="custom_base" name="custom_base" class="regular-text" value="<?php echo esc_attr( $s['custom_base'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="custom_key"><?php esc_html_e( 'API key', 'aifbp' ); ?></label></th>
					<td><input type="password" id="custom_key" name="custom_key" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== $s['custom_key'] ? __( '(saved)', 'aifbp' ) : '' ); ?>" autocomplete="new-password" /></td>
				</tr>
				<tr>
					<th><label for="custom_model"><?php esc_html_e( 'Model', 'aifbp' ); ?></label></th>
					<td><input type="text" id="custom_model" name="custom_model" class="regular-text" value="<?php echo esc_attr( $s['custom_model'] ); ?>" /></td>
				</tr>
			</table>

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
				<tr>
					<th><label for="variations"><?php esc_html_e( 'Caption variations', 'aifbp' ); ?></label></th>
					<td><input type="number" id="variations" name="variations" min="0" max="5" value="<?php echo esc_attr( $s['variations'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="currency"><?php esc_html_e( 'Currency label', 'aifbp' ); ?></label></th>
					<td><input type="text" id="currency" name="currency" value="<?php echo esc_attr( $s['currency'] ); ?>" /></td>
				</tr>
				<tr><th><?php esc_html_e( 'Include in caption', 'aifbp' ); ?></th><td>
					<label><input type="checkbox" name="include_price" value="1" <?php checked( $s['include_price'], 1 ); ?> /> <?php esc_html_e( 'Price (regular + sale)', 'aifbp' ); ?></label><br />
					<label><input type="checkbox" name="include_link" value="1" <?php checked( $s['include_link'], 1 ); ?> /> <?php esc_html_e( 'Product link', 'aifbp' ); ?></label><br />
					<label><input type="checkbox" name="include_hashtags" value="1" <?php checked( $s['include_hashtags'], 1 ); ?> /> <?php esc_html_e( 'AI hashtags', 'aifbp' ); ?></label><br />
					<label><input type="checkbox" name="include_cta" value="1" <?php checked( $s['include_cta'], 1 ); ?> /> <?php esc_html_e( 'AI call-to-action', 'aifbp' ); ?></label>
				</td></tr>
				<tr><th><?php esc_html_e( 'Advanced AI features', 'aifbp' ); ?></th><td>
					<label><input type="checkbox" name="ai_image_understand" value="1" <?php checked( $s['ai_image_understand'], 1 ); ?> /> <?php esc_html_e( 'AI image understanding (vision)', 'aifbp' ); ?></label><br />
					<label><input type="checkbox" name="ai_image_gen" value="1" <?php checked( $s['ai_image_gen'], 1 ); ?> /> <?php esc_html_e( 'AI promotional image generation (OpenAI only)', 'aifbp' ); ?></label><br />
					<label><input type="checkbox" name="price_badge" value="1" <?php checked( $s['price_badge'], 1 ); ?> /> <?php esc_html_e( 'Automatic price badge overlay (GD)', 'aifbp' ); ?></label>
				</td></tr>
			</table>

			<?php submit_button( __( 'Save AI settings', 'aifbp' ), 'primary', 'aifbp_save_settings', false ); ?>
		</form>
		<?php
	}

	private function tab_scheduling(): void {
		$schedules = ScheduleManager::all();
		$pages     = Settings::pages();
		?>
		<div class="aifbp-grid">
			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'New schedule', 'aifbp' ); ?></h2>
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
							<td>
								<?php foreach ( $pages as $page ) : ?>
									<label style="margin-right:8px"><input type="checkbox" name="pages[]" value="<?php echo esc_attr( $page['id'] ); ?>" checked /> <?php echo esc_html( $page['name'] ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr><th><label for="sch-start"><?php esc_html_e( 'Campaign start', 'aifbp' ); ?></label></th><td><input type="date" id="sch-start" name="start_date" /></td></tr>
						<tr><th><label for="sch-end"><?php esc_html_e( 'Campaign end', 'aifbp' ); ?></label></th><td><input type="date" id="sch-end" name="end_date" /></td></tr>
					</table>
					<button type="submit" name="aifbp_create_schedule" class="button button-primary"><?php esc_html_e( 'Create schedule', 'aifbp' ); ?></button>
				</form>
			</div>

			<div class="aifbp-panel">
				<h2><?php esc_html_e( 'Bulk scheduling', 'aifbp' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'aifbp_schedule' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="bulk-count"><?php esc_html_e( 'Number of posts', 'aifbp' ); ?></label></th><td><input type="number" id="bulk-count" name="count" min="1" max="100" value="10" /></td></tr>
						<tr><th><label for="bulk-start"><?php esc_html_e( 'Start', 'aifbp' ); ?></label></th><td><input type="datetime-local" id="bulk-start" name="start" /></td></tr>
						<tr><th><label for="bulk-end"><?php esc_html_e( 'End', 'aifbp' ); ?></label></th><td><input type="datetime-local" id="bulk-end" name="end" /></td></tr>
						<tr><th><label for="bulk-int"><?php esc_html_e( 'Interval (minutes)', 'aifbp' ); ?></label></th><td><input type="number" id="bulk-int" name="interval_minutes" min="5" value="60" /></td></tr>
						<tr>
							<th><?php esc_html_e( 'Pages', 'aifbp' ); ?></th>
							<td>
								<?php foreach ( $pages as $page ) : ?>
									<label style="margin-right:8px"><input type="checkbox" name="pages[]" value="<?php echo esc_attr( $page['id'] ); ?>" checked /> <?php echo esc_html( $page['name'] ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
					</table>
					<button type="submit" name="aifbp_bulk_schedule" class="button button-primary"><?php esc_html_e( 'Queue posts', 'aifbp' ); ?></button>
				</form>
			</div>
		</div>

		<h2><?php esc_html_e( 'Schedules', 'aifbp' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'aifbp' ); ?></th><th><?php esc_html_e( 'Type', 'aifbp' ); ?></th><th><?php esc_html_e( 'Time', 'aifbp' ); ?></th><th><?php esc_html_e( 'Posts/run', 'aifbp' ); ?></th><th><?php esc_html_e( 'Next run', 'aifbp' ); ?></th><th><?php esc_html_e( 'Active', 'aifbp' ); ?></th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $schedules ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No schedules yet.', 'aifbp' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $schedules as $schedule ) : ?>
					<tr>
						<td><?php echo esc_html( $schedule['name'] ); ?></td>
						<td><?php echo esc_html( $schedule['type'] ); ?></td>
						<td><?php echo esc_html( $schedule['time_of_day'] ); ?></td>
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

		<h2><?php esc_html_e( 'Upcoming queue calendar', 'aifbp' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Scheduled at', 'aifbp' ); ?></th><th><?php esc_html_e( 'Product', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Source', 'aifbp' ); ?></th></tr></thead>
			<tbody>
			<?php $upcoming = QueueManager::upcoming(); ?>
			<?php if ( empty( $upcoming ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Nothing scheduled.', 'aifbp' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $upcoming as $item ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $item['scheduled_at'] ); ?></td>
						<td><?php echo esc_html( $item['product_name'] ); ?></td>
						<td><?php echo esc_html( $item['page_name'] ); ?></td>
						<td><?php echo esc_html( $item['source'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function tab_queue(): void {
		$counts = QueueManager::counts();
		?>
		<div class="aifbp-cards">
			<?php foreach ( $counts as $status => $count ) : ?>
				<div class="aifbp-card"><span class="aifbp-card-num"><?php echo esc_html( $count ); ?></span><span><?php echo esc_html( ucfirst( $status ) ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" class="button button-primary" id="aifbp-process-btn"><?php esc_html_e( 'Run queue now', 'aifbp' ); ?></button>
		</p>
		<div id="aifbp-result" class="aifbp-result"></div>

		<h2><?php esc_html_e( 'Queue items', 'aifbp' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th>#</th><th><?php esc_html_e( 'Product', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Status', 'aifbp' ); ?></th><th><?php esc_html_e( 'Scheduled', 'aifbp' ); ?></th><th><?php esc_html_e( 'Attempts', 'aifbp' ); ?></th><th><?php esc_html_e( 'Last error', 'aifbp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( QueueManager::list( '', 50 ) as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['id'] ); ?></td>
					<td><?php echo esc_html( $item['product_name'] ); ?></td>
					<td><?php echo esc_html( $item['page_name'] ); ?></td>
					<td><span class="aifbp-status aifbp-status-<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( $item['status'] ); ?></span></td>
					<td><?php echo esc_html( (string) $item['scheduled_at'] ); ?></td>
					<td><?php echo esc_html( $item['attempts'] ); ?></td>
					<td><?php echo esc_html( (string) $item['last_error'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function tab_history(): void {
		?>
		<h2><?php esc_html_e( 'Post history', 'aifbp' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Posted at', 'aifbp' ); ?></th><th><?php esc_html_e( 'Product', 'aifbp' ); ?></th><th><?php esc_html_e( 'Page', 'aifbp' ); ?></th><th><?php esc_html_e( 'Facebook post', 'aifbp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php $posted = QueueManager::list( 'posted', 50 ); ?>
			<?php if ( empty( $posted ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No posts yet.', 'aifbp' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $posted as $item ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $item['posted_at'] ); ?></td>
						<td><?php echo esc_html( $item['product_name'] ); ?></td>
						<td><?php echo esc_html( $item['page_name'] ); ?></td>
						<td><a href="https://www.facebook.com/<?php echo esc_attr( $item['fb_post_id'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['fb_post_id'] ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'System log', 'aifbp' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Time', 'aifbp' ); ?></th><th><?php esc_html_e( 'Level', 'aifbp' ); ?></th><th><?php esc_html_e( 'Context', 'aifbp' ); ?></th><th><?php esc_html_e( 'Message', 'aifbp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( Logger::recent( 100 ) as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( $entry['created_at'] ); ?></td>
					<td><?php echo esc_html( $entry['level'] ); ?></td>
					<td><?php echo esc_html( $entry['context'] ); ?></td>
					<td><?php echo esc_html( $entry['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function tab_templates(): void {
		$s         = Settings::all();
		$templates = Templates::all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'aifbp_settings' ); ?>
			<p class="description">
				<?php esc_html_e( 'One template per line: Name | Template. Placeholders: {name} {price} {link} {short_bn} {short_en} {long_bn} {long_en} {cta} {hashtags}', 'aifbp' ); ?>
			</p>
			<textarea name="templates" rows="8" class="large-text code"><?php echo esc_textarea( $s['templates'] ); ?></textarea>
			<?php submit_button( __( 'Save templates', 'aifbp' ), 'primary', 'aifbp_save_settings', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Available templates', 'aifbp' ); ?></h2>
		<ul>
			<?php foreach ( array_keys( $templates ) as $name ) : ?>
				<li><code><?php echo esc_html( $name ); ?></code></li>
			<?php endforeach; ?>
		</ul>
		<p class="description"><?php esc_html_e( 'Select a template per post by enqueuing with meta "template" = template name.', 'aifbp' ); ?></p>
		<?php
	}

	private function tab_tools(): void {
		?>
		<h2><?php esc_html_e( 'Maintenance', 'aifbp' ); ?></h2>
		<form method="post" style="display:inline-block;margin-right:12px">
			<?php wp_nonce_field( 'aifbp_tools' ); ?>
			<button type="submit" name="aifbp_clear_log" class="button button-secondary"><?php esc_html_e( 'Clear log', 'aifbp' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'Uninstalling the plugin removes all plugin tables and options automatically.', 'aifbp' ); ?></p>
		<?php
	}
}
