<?php
/**
 * Admin UI controller.
 *
 * Registers the DualPress top-level menu with individual submenu pages for
 * each section (Connection, Sync Settings, Tools, Logs, Notifications,
 * File Sync) and handles form submissions and AJAX actions.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Admin
 */
class DualPress_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Top-level menu slug (also used for the Connection submenu).
	 */
	const PAGE_SLUG = 'dualpress';

	/**
	 * Submenu page slugs.
	 */
	const SUBMENU_SLUGS = array(
		'connection'    => 'dualpress',
		'sync'          => 'dualpress-sync',
		'tools'         => 'dualpress-tools',
		'logs'          => 'dualpress-logs',
		'notifications' => 'dualpress-notifications',
		'file-sync'     => 'dualpress-file-sync',
		'backup'        => 'dualpress-backup',
	);

	/**
	 * Returns the singleton and registers WP hooks.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/** Private constructor. */
	private function __construct() {}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	private function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_dualpress_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_dualpress_fix_urls', array( $this, 'ajax_fix_urls' ) );

		// Fix PHP 8.1+ deprecation warning for hidden submenu pages (null $title in strip_tags).
		add_action( 'current_screen', array( $this, 'fix_hidden_page_title' ), 1 );

		// Highlight parent menu for hidden Table Sync page.
		add_filter( 'parent_file', array( $this, 'highlight_tools_menu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_tools_submenu' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_dualpress_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_dualpress_sync_now', array( $this, 'ajax_sync_now' ) );
		add_action( 'wp_ajax_dualpress_full_sync', array( $this, 'ajax_full_sync' ) );
		add_action( 'wp_ajax_dualpress_retry_failed', array( $this, 'ajax_retry_failed' ) );
		add_action( 'wp_ajax_dualpress_clear_queue', array( $this, 'ajax_clear_queue' ) );
		add_action( 'wp_ajax_dualpress_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_dualpress_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_dualpress_generate_key', array( $this, 'ajax_generate_key' ) );

		// Daemon AJAX handlers.
		add_action( 'wp_ajax_dualpress_daemon_toggle', array( $this, 'ajax_daemon_toggle' ) );
		add_action( 'wp_ajax_dualpress_daemon_interval', array( $this, 'ajax_daemon_interval' ) );

		// File Sync AJAX handlers.
		add_action( 'wp_ajax_dualpress_file_sync_now',    array( $this, 'ajax_file_sync_now' ) );
		add_action( 'wp_ajax_dualpress_file_retry_failed', array( $this, 'ajax_file_retry_failed' ) );
		add_action( 'wp_ajax_dualpress_file_clear_queue', array( $this, 'ajax_file_clear_queue' ) );
		add_action( 'wp_ajax_dualpress_file_sync_scan',   array( $this, 'ajax_file_sync_scan' ) );
		add_action( 'wp_ajax_dualpress_file_sync_batch',  array( $this, 'ajax_file_sync_batch' ) );

		// Table Sync AJAX handlers.
		add_action( 'wp_ajax_dualpress_sync_single_table', array( $this, 'ajax_sync_single_table' ) );
		add_action( 'wp_ajax_dualpress_sync_table_full', array( $this, 'ajax_sync_table_full' ) );

		// Backup AJAX handlers.
		add_action( 'wp_ajax_dualpress_backup_init',      array( $this, 'ajax_backup_init' ) );
		add_action( 'wp_ajax_dualpress_backup_chunk',     array( $this, 'ajax_backup_chunk' ) );
		add_action( 'wp_ajax_dualpress_backup_finalize',  array( $this, 'ajax_backup_finalize' ) );
		add_action( 'wp_ajax_dualpress_backup_cancel',    array( $this, 'ajax_backup_cancel' ) );
		add_action( 'wp_ajax_dualpress_backup_download',  array( $this, 'ajax_backup_download' ) );
		add_action( 'wp_ajax_dualpress_backup_dl_deploy', array( $this, 'ajax_backup_dl_deploy' ) );
		add_action( 'wp_ajax_dualpress_backup_delete',    array( $this, 'ajax_backup_delete' ) );
	}

	// ------------------------------------------------------------------ //
	// Menu                                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Fix PHP 8.1+ deprecation warning for hidden submenu pages.
	 *
	 * WordPress core uses strip_tags($title) in admin-header.php but $title may be null
	 * for hidden pages (those registered with empty parent slug). This sets a default.
	 *
	 * @return void
	 */
	public function fix_hidden_page_title() {
		global $title;
		if ( isset( $_GET['page'] ) && 'dualpress-table-sync' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Fix PHP 8.1+ deprecation warning (null $title in strip_tags).
			if ( null === $title ) {
				$title = __( 'Table Sync Manager', 'dualpress' );
			}
		}
	}

	/**
	 * Highlight DualPress menu when on hidden Table Sync page.
	 *
	 * @param string $parent_file The parent file.
	 * @return string
	 */
	public function highlight_tools_menu( $parent_file ) {
		global $plugin_page, $submenu_file;
		if ( isset( $_GET['page'] ) && 'dualpress-table-sync' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$plugin_page  = 'dualpress-tools';
			$submenu_file = 'dualpress-tools';
			return 'admin.php?page=' . self::PAGE_SLUG;
		}
		return $parent_file;
	}

	/**
	 * Highlight DB Tools submenu when on hidden Table Sync page.
	 *
	 * @param string $submenu_file The submenu file.
	 * @return string
	 */
	public function highlight_tools_submenu( $submenu_file ) {
		if ( isset( $_GET['page'] ) && 'dualpress-table-sync' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'dualpress-tools';
		}
		return $submenu_file;
	}

	/**
	 * Register the top-level menu and all submenu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DualPress', 'dualpress' ),
			__( 'DualPress', 'dualpress' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_connection' ),
			'dashicons-update',
			80
		);

		// The first submenu entry replaces the auto-generated duplicate of the parent.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Connection', 'dualpress' ),
			__( 'Connection', 'dualpress' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_connection' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Sync Settings', 'dualpress' ),
			__( 'Sync Settings', 'dualpress' ),
			'manage_options',
			'dualpress-sync',
			array( $this, 'render_sync' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'File Sync', 'dualpress' ),
			__( 'File Sync', 'dualpress' ),
			'manage_options',
			'dualpress-file-sync',
			array( $this, 'render_file_sync' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'DB Tools', 'dualpress' ),
			__( 'DB Tools', 'dualpress' ),
			'manage_options',
			'dualpress-tools',
			array( $this, 'render_tools' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Backup', 'dualpress' ),
			__( 'Backup', 'dualpress' ),
			'manage_options',
			'dualpress-backup',
			array( $this, 'render_backup' )
		);

		// Hidden page (no menu item) for Table Sync Manager.
		add_submenu_page(
			'', // Empty string = hidden from menu (null causes deprecation warnings in PHP 8.1+)
			__( 'Table Sync Manager', 'dualpress' ),
			__( 'Table Sync Manager', 'dualpress' ),
			'manage_options',
			'dualpress-table-sync',
			array( $this, 'render_table_sync' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Logs', 'dualpress' ),
			__( 'Logs', 'dualpress' ),
			'manage_options',
			'dualpress-logs',
			array( $this, 'render_logs' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Notifications', 'dualpress' ),
			__( 'Notifications', 'dualpress' ),
			'manage_options',
			'dualpress-notifications',
			array( $this, 'render_notifications' )
		);
	}

	// ------------------------------------------------------------------ //
	// Assets                                                               //
	// ------------------------------------------------------------------ //

	/**
	 * Enqueue admin CSS and JS only on our pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Load on any DualPress admin page
		if ( strpos( $hook, 'dualpress' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'dualpress-admin',
			DUALPRESS_URL . 'admin/assets/admin.css',
			array(),
			DUALPRESS_VERSION
		);

		wp_enqueue_script(
			'dualpress-admin',
			DUALPRESS_URL . 'admin/assets/admin.js',
			array( 'jquery' ),
			DUALPRESS_VERSION,
			true
		);

		wp_localize_script(
			'dualpress-admin',
			'dualpressAjax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'dualpress_admin' ),
				'i18n'     => array(
					'confirm_clear_queue' => __( 'Are you sure you want to clear the entire sync queue? This cannot be undone.', 'dualpress' ),
					'confirm_full_sync'   => __( 'Are you sure you want to run a full sync? This may take several minutes for large databases.', 'dualpress' ),
					'confirm_clear_logs'  => __( 'Are you sure you want to clear all log entries?', 'dualpress' ),
					'copied'              => __( 'Copied!', 'dualpress' ),
					'syncing'             => __( 'Syncing…', 'dualpress' ),
					'testing'             => __( 'Testing…', 'dualpress' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------ //
	// Page renderers                                                       //
	// ------------------------------------------------------------------ //

	/** Render the Connection page. */
	public function render_connection() {
		$this->render_view( 'tab-connection' );
	}

	/** Render the Sync Settings page. */
	public function render_sync() {
		$this->render_view( 'tab-sync' );
	}

	/** Render the Tools page. */
	public function render_tools() {
		$this->render_view( 'tab-tools' );
	}

	/** Render the Table Sync page. */
	public function render_table_sync() {
		$this->render_view( 'tab-table-sync' );
	}

	/** Render the Logs page. */
	public function render_logs() {
		$this->render_view( 'tab-logs' );
	}

	/** Render the Notifications page. */
	public function render_notifications() {
		$this->render_view( 'tab-notifications' );
	}

	/** Render the File Sync page. */
	public function render_file_sync() {
		$this->render_view( 'tab-file-sync' );
	}

	/** Render the Backup page. */
	public function render_backup() {
		$this->render_view( 'tab-backup' );
	}

	/**
	 * Load a view file inside the standard admin wrapper.
	 *
	 * @param string $view View filename without .php extension.
	 * @return void
	 */
	private function render_view( $view ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dualpress' ) );
		}

		?>
		<div class="wrap dualpress-wrap">
			<h1 class="dualpress-page-title">
				<span class="dualpress-logo">&#x1F501;</span>
				<?php esc_html_e( 'DualPress', 'dualpress' ); ?>
			</h1>

			<?php $this->render_notices(); ?>

			<div class="dualpress-tab-content">
				<?php
				$view_file = DUALPRESS_DIR . 'admin/views/' . $view . '.php';
				if ( file_exists( $view_file ) ) {
					include $view_file;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render admin notices (success / error after form submit).
	 *
	 * @return void
	 */
	private function render_notices() {
		if ( isset( $_GET['dualpress_updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'dualpress' ) . '</p></div>';
		}
		if ( isset( $_GET['dualpress_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Error saving settings. Please try again.', 'dualpress' ) . '</p></div>';
		}
	}

	// ------------------------------------------------------------------ //
	// Form submission handler                                              //
	// ------------------------------------------------------------------ //

	/**
	 * Handle the settings form POST and redirect back to the correct submenu page.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'dualpress' ) );
		}

		check_admin_referer( 'dualpress_save_settings', 'dualpress_nonce' );

		$tab = isset( $_POST['dualpress_tab'] ) ? sanitize_key( $_POST['dualpress_tab'] ) : 'connection';

		switch ( $tab ) {
			case 'connection':
				$this->save_connection_settings();
				break;
			case 'sync':
				$this->save_sync_settings();
				break;
			case 'notifications':
				$this->save_notification_settings();
				break;
			case 'file-sync':
				$this->save_file_sync_settings();
				break;
		}

		// Map tab key → submenu page slug for the redirect.
		$slug_map = array(
			'connection'    => 'dualpress',
			'sync'          => 'dualpress-sync',
			'tools'         => 'dualpress-tools',
			'logs'          => 'dualpress-logs',
			'notifications' => 'dualpress-notifications',
			'file-sync'     => 'dualpress-file-sync',
		);
		$page_slug = isset( $slug_map[ $tab ] ) ? $slug_map[ $tab ] : 'dualpress';

		wp_safe_redirect( admin_url( 'admin.php?page=' . $page_slug . '&dualpress_updated=1' ) );
		exit;
	}

	/**
	 * Save Connection tab settings.
	 */
	private function save_connection_settings() {
		$role           = isset( $_POST['dualpress_server_role'] ) ? sanitize_key( $_POST['dualpress_server_role'] ) : 'primary';
		$remote_url     = isset( $_POST['dualpress_remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['dualpress_remote_url'] ) ) : '';
		$secret_key     = isset( $_POST['dualpress_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['dualpress_secret_key'] ) ) : '';
		$rate_limit_max = isset( $_POST['dualpress_rate_limit_max'] ) ? absint( $_POST['dualpress_rate_limit_max'] ) : 300;

		if ( ! in_array( $role, array( 'primary', 'secondary' ), true ) ) {
			$role = 'primary';
		}
		$rate_limit_max = max( 1, min( 1000, $rate_limit_max ) );

		$skip_ssl_verify = ! empty( $_POST['dualpress_skip_ssl_verify'] );

		DualPress_Settings::set( 'server_role', $role );
		DualPress_Settings::set( 'remote_url', $remote_url );
		DualPress_Settings::set( 'rate_limit_max', $rate_limit_max );
		DualPress_Settings::set( 'skip_ssl_verify', $skip_ssl_verify );

		if ( ! empty( $secret_key ) ) {
			DualPress_Settings::set( 'secret_key', $secret_key );
		}

		// Reconfigure auto-increment for the new role.
		DualPress_Activator::configure_auto_increment();
	}

	/**
	 * AJAX handler for fixing site URLs to match current server.
	 */
	public function ajax_fix_urls() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		check_ajax_referer( 'dualpress_fix_urls', 'nonce' );

		$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';

		if ( empty( $new_url ) ) {
			wp_send_json_error( array( 'message' => 'Invalid URL' ) );
		}

		// Update both options directly in DB to avoid any filters/redirects.
		global $wpdb;
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => $new_url ),
			array( 'option_name' => 'home' )
		);
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => $new_url ),
			array( 'option_name' => 'siteurl' )
		);

		// Clear options cache.
		wp_cache_delete( 'alloptions', 'options' );

		wp_send_json_success( array( 'message' => 'URLs updated', 'new_url' => $new_url ) );
	}

	/**
	 * Save Sync Settings tab.
	 */
	private function save_sync_settings() {
		$mode     = isset( $_POST['dualpress_sync_mode'] ) ? sanitize_key( $_POST['dualpress_sync_mode'] ) : 'active-active';
		$interval = isset( $_POST['dualpress_sync_interval'] ) ? absint( $_POST['dualpress_sync_interval'] ) : 60;
		$db_interval = isset( $_POST['dualpress_db_sync_interval'] ) ? absint( $_POST['dualpress_db_sync_interval'] ) : 3600;
		$db_method = isset( $_POST['dualpress_db_sync_method'] ) ? sanitize_key( $_POST['dualpress_db_sync_method'] ) : 'last_id';
		$db_bundle_mb = isset( $_POST['dualpress_db_bundle_mb'] ) ? absint( $_POST['dualpress_db_bundle_mb'] ) : 2;
		$db_compress = ! empty( $_POST['dualpress_db_compress'] );
		$excluded = isset( $_POST['dualpress_excluded_tables'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dualpress_excluded_tables'] ) ) : '';
		$excluded_meta = isset( $_POST['dualpress_excluded_meta_keys'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dualpress_excluded_meta_keys'] ) ) : '';
		$excluded_options = isset( $_POST['dualpress_excluded_option_keys'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dualpress_excluded_option_keys'] ) ) : '';

		if ( ! in_array( $mode, array( 'active-active', 'active-passive' ), true ) ) {
			$mode = 'active-active';
		}
		// Allowed values: 15, 30, 60, 120, 300, 600 seconds.
		$allowed_intervals = array( 15, 30, 60, 120, 300, 600 );
		if ( ! in_array( $interval, $allowed_intervals, true ) ) {
			$interval = 60;
		}
		// Allowed DB sync intervals: 0 (disabled), 900, 1800, 3600, 7200, 14400, 43200, 86400.
		$allowed_db_intervals = array( 0, 900, 1800, 3600, 7200, 14400, 43200, 86400 );
		if ( ! in_array( $db_interval, $allowed_db_intervals, true ) ) {
			$db_interval = 3600;
		}
		// Allowed DB sync methods.
		if ( ! in_array( $db_method, array( 'last_id', 'checksum', 'full' ), true ) ) {
			$db_method = 'last_id';
		}
		// DB bundle size: 1-20 MB.
		$db_bundle_mb = max( 1, min( 20, $db_bundle_mb ) );

		// Parse excluded tables — comma or newline separated.
		$excluded_tables = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $excluded ) ) );
		// Parse excluded meta keys — allow underscores and asterisks.
		$excluded_meta_keys = array_filter( array_map( function( $k ) {
			return preg_replace( '/[^a-z0-9_*-]/i', '', $k );
		}, preg_split( '/[\s,]+/', $excluded_meta ) ) );
		// Parse excluded option keys.
		$excluded_option_keys = array_filter( array_map( function( $k ) {
			return preg_replace( '/[^a-z0-9_.-]/i', '', $k );
		}, preg_split( '/[\s,]+/', $excluded_options ) ) );

		DualPress_Settings::set( 'sync_mode', $mode );
		DualPress_Settings::set( 'sync_interval', $interval );
		DualPress_Settings::set( 'db_sync_interval', $db_interval );
		DualPress_Settings::set( 'db_sync_method', $db_method );
		DualPress_Settings::set( 'db_bundle_mb', $db_bundle_mb );
		DualPress_Settings::set( 'db_compress', $db_compress );
		DualPress_Settings::set( 'excluded_tables', wp_json_encode( array_values( $excluded_tables ) ) );
		DualPress_Settings::set( 'excluded_meta_keys', wp_json_encode( array_values( $excluded_meta_keys ) ) );
		DualPress_Settings::set( 'excluded_option_keys', wp_json_encode( array_values( $excluded_option_keys ) ) );

		// Boolean toggles.
		$toggles = array( 'sync_posts', 'sync_users', 'sync_comments', 'sync_options', 'sync_terms', 'sync_woocommerce' );
		foreach ( $toggles as $toggle ) {
			DualPress_Settings::set( $toggle, ! empty( $_POST[ 'dualpress_' . $toggle ] ) );
		}

		// Reschedule cron with new interval.
		DualPress_Cron::unschedule_events();
		DualPress_Cron::schedule_events();

		// Sync settings to remote server if connected.
		if ( DualPress_Settings::is_configured() ) {
			$settings = array(
				'sync_mode'            => $mode,
				'sync_interval'        => $interval,
				'db_sync_interval'     => $db_interval,
				'db_sync_method'       => $db_method,
				'db_bundle_mb'         => $db_bundle_mb,
				'db_compress'          => $db_compress,
				'excluded_tables'      => wp_json_encode( array_values( $excluded_tables ) ),
				'excluded_meta_keys'   => wp_json_encode( array_values( $excluded_meta_keys ) ),
				'excluded_option_keys' => wp_json_encode( array_values( $excluded_option_keys ) ),
				'sync_posts'           => ! empty( $_POST['dualpress_sync_posts'] ),
				'sync_users'           => ! empty( $_POST['dualpress_sync_users'] ),
				'sync_comments'        => ! empty( $_POST['dualpress_sync_comments'] ),
				'sync_options'         => ! empty( $_POST['dualpress_sync_options'] ),
				'sync_terms'           => ! empty( $_POST['dualpress_sync_terms'] ),
				'sync_woocommerce'     => ! empty( $_POST['dualpress_sync_woocommerce'] ),
			);
			$result = DualPress_Sender::push_settings( 'sync', $settings );
			if ( ! is_wp_error( $result ) ) {
				$remote_url = DualPress_Settings::get_remote_url();
				set_transient( 'dualpress_sync_settings_synced_url', $remote_url, 60 );
			}
		}
	}

	/**
	 * Push the sync_mode setting to the remote server via the /update-setting endpoint.
	 *
	 * Failures are logged but do not block the local save.
	 *
	 * @param string $mode 'active-active' or 'active-passive'.
	 * @return void
	 */
	private function push_sync_mode_to_remote( $mode ) {
		$remote_url = rtrim( DualPress_Settings::get_remote_url(), '/' );
		if ( empty( $remote_url ) || ! DualPress_Settings::is_configured() ) {
			return;
		}

		$endpoint = $remote_url . '/wp-json/dualpress/v1/update-setting';
		$payload  = wp_json_encode( array( 'setting' => 'sync_mode', 'value' => $mode ) );
		$headers  = DualPress_Auth::build_headers( $payload );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => $headers,
				'body'      => $payload,
				'timeout'   => 15,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			DualPress_Logger::warning(
				'sync_mode_push_failed',
				'Could not push sync_mode to remote: ' . $response->get_error_message()
			);
		} else {
			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== (int) $code ) {
				DualPress_Logger::warning(
					'sync_mode_push_failed',
					sprintf( 'Remote returned HTTP %d when updating sync_mode.', $code )
				);
			}
		}
	}

	/**
	 * Save Notifications tab.
	 */
	private function save_notification_settings() {
		$email = isset( $_POST['dualpress_notification_email'] ) ? sanitize_email( wp_unslash( $_POST['dualpress_notification_email'] ) ) : '';

		$valid_events = array(
			'connection_lost',
			'connection_restored',
			'sync_item_failed',
			'conflict_detected',
			'full_sync_completed',
			'queue_threshold',
		);

		$enabled = array();
		foreach ( $valid_events as $ev ) {
			if ( ! empty( $_POST[ 'dualpress_notify_' . $ev ] ) ) {
				$enabled[] = $ev;
			}
		}

		DualPress_Settings::set( 'notification_email', $email );
		DualPress_Settings::set( 'notification_events', wp_json_encode( $enabled ) );
	}

	// ------------------------------------------------------------------ //
	// AJAX handlers                                                        //
	// ------------------------------------------------------------------ //

	/**
	 * Verify AJAX nonce and capability — shared guard.
	 */
	private function verify_ajax() {
		check_ajax_referer( 'dualpress_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'dualpress' ) ), 403 );
		}
	}

	public function ajax_test_connection() {
		error_log("DUALPRESS AJAX_TEST_CONNECTION HANDLER CALLED");
		$this->verify_ajax();
		$result = DualPress_Sender::test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful!', 'dualpress' ) ) );
	}

	public function ajax_sync_now() {
		$this->verify_ajax();
		$result = DualPress_Sender::process_queue();
		wp_send_json_success( array(
			'message' => $result
				? __( 'Sync completed successfully.', 'dualpress' )
				: __( 'Sync failed or no items to sync. Check the logs.', 'dualpress' ),
		) );
	}

	public function ajax_full_sync() {
		$this->verify_ajax();
		$result = DualPress_Sender::full_sync();
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: tables, 2: items */
				__( 'Full sync completed. Tables: %1$d, Items: %2$d.', 'dualpress' ),
				$result['tables_synced'],
				$result['items_synced']
			),
			'data'    => $result,
		) );
	}

	public function ajax_retry_failed() {
		$this->verify_ajax();
		$count = DualPress_Queue::retry_failed();
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of items reset */
				_n( '%d item reset to pending.', '%d items reset to pending.', $count, 'dualpress' ),
				$count
			),
		) );
	}

	public function ajax_clear_queue() {
		$this->verify_ajax();
		DualPress_Queue::clear();
		wp_send_json_success( array( 'message' => __( 'Queue cleared.', 'dualpress' ) ) );
	}

	public function ajax_clear_logs() {
		$this->verify_ajax();
		DualPress_Logger::clear_all();
		wp_send_json_success( array( 'message' => __( 'Logs cleared.', 'dualpress' ) ) );
	}

	public function ajax_get_logs() {
		$this->verify_ajax();

		$args = array(
			'level'    => isset( $_POST['level'] ) ? sanitize_key( $_POST['level'] ) : '',
			'search'   => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'since'    => isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '',
			'per_page' => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50,
			'page'     => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
		);

		$result = DualPress_Logger::get_logs( $args );
		wp_send_json_success( $result );
	}

	public function ajax_generate_key() {
		$this->verify_ajax();
		$key = DualPress_Auth::generate_secret_key();
		wp_send_json_success( array( 'key' => $key ) );
	}

	// ------------------------------------------------------------------ //
	// File Sync settings                                                   //
	// ------------------------------------------------------------------ //

	/**
	 * Save File Sync tab settings.
	 */
	private function save_file_sync_settings() {
		$enabled       = ! empty( $_POST['dualpress_file_sync_enabled'] );
		$max_size      = isset( $_POST['dualpress_file_sync_max_size'] ) ? absint( $_POST['dualpress_file_sync_max_size'] ) : 100;
		$sync_uploads  = ! empty( $_POST['dualpress_file_sync_uploads'] );
		$sync_themes   = ! empty( $_POST['dualpress_file_sync_themes'] );
		$sync_plugins  = ! empty( $_POST['dualpress_file_sync_plugins'] );
		$delete_remote = ! empty( $_POST['dualpress_file_sync_delete_remote'] );

		// Transfer settings (simplified).
		$bundle_mb = isset( $_POST['dualpress_file_sync_bundle_mb'] ) ? absint( $_POST['dualpress_file_sync_bundle_mb'] ) : 10;
		$compress  = ! empty( $_POST['dualpress_file_sync_compress'] );

		// Sanitize ranges.
		$max_size  = max( 1, min( 2048, $max_size ) );
		$bundle_mb = max( 1, min( 50, $bundle_mb ) );

		DualPress_Settings::set( 'file_sync_enabled',       $enabled );
		DualPress_Settings::set( 'file_sync_max_size',      $max_size );
		DualPress_Settings::set( 'file_sync_uploads',       $sync_uploads );
		DualPress_Settings::set( 'file_sync_themes',        $sync_themes );
		DualPress_Settings::set( 'file_sync_plugins',       $sync_plugins );
		DualPress_Settings::set( 'file_sync_delete_remote', $delete_remote );
		DualPress_Settings::set( 'file_sync_bundle_mb',     $bundle_mb );
		DualPress_Settings::set( 'file_sync_compress',      $compress );

		// Sync settings to remote server if connected.
		if ( DualPress_Settings::is_configured() ) {
			$settings = array(
				'file_sync_enabled'       => $enabled,
				'file_sync_max_size'      => $max_size,
				'file_sync_uploads'       => $sync_uploads,
				'file_sync_themes'        => $sync_themes,
				'file_sync_plugins'       => $sync_plugins,
				'file_sync_delete_remote' => $delete_remote,
				'file_sync_bundle_mb'     => $bundle_mb,
				'file_sync_compress'      => $compress,
			);
			$result = DualPress_Sender::push_settings( 'file_sync', $settings );
			if ( ! is_wp_error( $result ) ) {
				$remote_url = DualPress_Settings::get_remote_url();
				set_transient( 'dualpress_settings_synced_url', $remote_url, 60 );
			}
		}
	}

	// ------------------------------------------------------------------ //
	// File Sync AJAX handlers                                              //
	// ------------------------------------------------------------------ //

	public function ajax_file_sync_now() {
		$this->verify_ajax();
		$result = DualPress_File_Sync::process_queue( 10 );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: pushed count, 2: failed count */
				__( 'Done. Pushed: %1$d, Failed: %2$d.', 'dualpress' ),
				$result['pushed'],
				$result['failed']
			),
		) );
	}

	public function ajax_file_retry_failed() {
		$this->verify_ajax();
		$count = DualPress_File_Queue::retry_failed();
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: count */
				_n( '%d file reset to pending.', '%d files reset to pending.', $count, 'dualpress' ),
				$count
			),
		) );
	}

	public function ajax_file_clear_queue() {
		$this->verify_ajax();
		DualPress_File_Queue::clear();
		wp_send_json_success( array( 'message' => __( 'File queue cleared.', 'dualpress' ) ) );
	}

	/**
	 * Scan configured directories and enqueue all files.
	 * Returns total count of enqueuable files.
	 */
	public function ajax_file_sync_scan() {
		$this->verify_ajax();
		$total = DualPress_File_Sync::scan_for_initial_sync();
		wp_send_json_success( array(
			'total'   => $total,
			'message' => sprintf(
				/* translators: %d: file count */
				__( '%d files queued for sync.', 'dualpress' ),
				$total
			),
		) );
	}

	/**
	 * Process a batch of queued files and return progress.
	 */
	public function ajax_file_sync_batch() {
		$this->verify_ajax();

		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 5;
		$batch_size = max( 1, min( 20, $batch_size ) );

		$result  = DualPress_File_Sync::process_queue( $batch_size );
		$stats   = DualPress_File_Queue::get_stats();

		wp_send_json_success( array(
			'pushed'    => $result['pushed'],
			'failed'    => $result['failed'],
			'pending'   => $stats['pending'],
			'completed' => $stats['completed'],
		) );
	}

	/**
	 * AJAX: Sync a single table to remote.
	 */
	public function ajax_sync_single_table() {
		check_ajax_referer( 'dualpress_table_sync', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$table = isset( $_POST['table'] ) ? sanitize_key( $_POST['table'] ) : '';
		if ( empty( $table ) ) {
			wp_send_json_error( 'Missing table parameter' );
		}

		$result = DualPress_Table_Sync::sync_single_table( $table );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Full sync a table with offset (for progress tracking).
	 */
	public function ajax_sync_table_full() {
		check_ajax_referer( 'dualpress_table_sync', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$table      = isset( $_POST['table'] ) ? sanitize_key( $_POST['table'] ) : '';
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$chunk_size = isset( $_POST['chunk_size'] ) ? absint( $_POST['chunk_size'] ) : 1000;

		if ( empty( $table ) ) {
			wp_send_json_error( 'Missing table parameter' );
		}

		$chunk_size = max( 100, min( 5000, $chunk_size ) );

		$result = DualPress_Table_Sync::sync_table_chunk( $table, $offset, $chunk_size );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	// ------------------------------------------------------------------ //
	// Backup AJAX handlers                                                 //
	// ------------------------------------------------------------------ //

	public function ajax_backup_init() {
		$this->verify_ajax();
		$raw_excludes = isset( $_POST['extra_excludes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra_excludes'] ) ) : '';
		$extra        = array_filter( array_map( 'trim', explode( "\n", $raw_excludes ) ) );

		$options = array(
			'skip_db'      => ! empty( $_POST['skip_db'] ),
			'skip_files'   => ! empty( $_POST['skip_files'] ),
			'skip_uploads' => ! empty( $_POST['skip_uploads'] ),
		);

		$result = DualPress_Backup::init( $extra, $options );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_backup_chunk() {
		$this->verify_ajax();
		$id     = isset( $_POST['backup_id'] ) ? sanitize_key( $_POST['backup_id'] ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => 'Missing backup_id.' ) );
		}

		$result = DualPress_Backup::chunk( $id, $offset );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_backup_finalize() {
		$this->verify_ajax();
		$id = isset( $_POST['backup_id'] ) ? sanitize_key( $_POST['backup_id'] ) : '';

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => 'Missing backup_id.' ) );
		}

		$result = DualPress_Backup::finalize( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_backup_cancel() {
		$this->verify_ajax();
		$id = isset( $_POST['backup_id'] ) ? sanitize_key( $_POST['backup_id'] ) : '';
		if ( $id ) {
			DualPress_Backup::cancel( $id );
		}
		wp_send_json_success( array( 'message' => 'Cancelled.' ) );
	}

	public function ajax_backup_download() {
		check_ajax_referer( 'dualpress_backup_dl_' . sanitize_key( $_GET['backup_id'] ?? '' ), 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}

		$filename = sanitize_file_name( $_GET['filename'] ?? '' );

		// Only allow dualpress-*.tar.gz filenames.
		if ( ! preg_match( '/^dualpress-[a-z0-9]+-[a-z0-9-]+\.tar\.gz$/', $filename ) ) {
			wp_die( 'Invalid filename.' );
		}

		$file_path = ABSPATH . $filename;
		if ( ! file_exists( $file_path ) ) {
			wp_die( 'File not found.' );
		}

		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: no-cache' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		readfile( $file_path );
		exit;
	}

	public function ajax_backup_dl_deploy() {
		check_ajax_referer( 'dualpress_backup_dl_deploy', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}

		$deploy_file = DUALPRESS_DIR . 'deploy.php';
		if ( ! file_exists( $deploy_file ) ) {
			wp_die( 'deploy.php not found.' );
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="deploy.php"' );
		header( 'Content-Length: ' . filesize( $deploy_file ) );
		header( 'Cache-Control: no-cache' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		readfile( $deploy_file );
		exit;
	}

	public function ajax_backup_delete() {
		$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		check_ajax_referer( 'dualpress_backup_delete_' . $backup_id, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';

		// Validate filename format.
		if ( ! preg_match( '/^dualpress-[a-z0-9]+-[a-zA-Z0-9_-]+\.tar\.gz$/', $filename ) ) {
			wp_send_json_error( array( 'message' => 'Invalid filename format.' ) );
		}

		$file_path = ABSPATH . $filename;

		if ( ! file_exists( $file_path ) ) {
			wp_send_json_error( array( 'message' => 'Backup file not found.' ) );
		}

		// Delete the file.
		if ( wp_delete_file( $file_path ) || ! file_exists( $file_path ) ) {
			wp_send_json_success( array( 'message' => 'Backup deleted.' ) );
		} else {
			// Try unlink directly if wp_delete_file doesn't work.
			if ( @unlink( $file_path ) ) {
				wp_send_json_success( array( 'message' => 'Backup deleted.' ) );
			}
			wp_send_json_error( array( 'message' => 'Failed to delete backup file.' ) );
		}
	}

	// ------------------------------------------------------------------ //
	// Daemon AJAX handlers                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Toggle daemon on/off.
	 */
	public function ajax_daemon_toggle() {
		check_ajax_referer( 'dualpress_daemon', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dualpress' ) ) );
		}

		$enabled = ! empty( $_POST['enabled'] );
		DualPress_Daemon::set_enabled( $enabled );

		$status = DualPress_Daemon::get_status();

		wp_send_json_success( array(
			'enabled' => $enabled,
			'running' => $status['running'],
			'pid'     => $status['pid'],
		) );
	}

	/**
	 * Update daemon check interval.
	 */
	public function ajax_daemon_interval() {
		check_ajax_referer( 'dualpress_daemon', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dualpress' ) ) );
		}

		$interval = isset( $_POST['interval'] ) ? (int) $_POST['interval'] : 5;
		DualPress_Daemon::set_interval( $interval );

		wp_send_json_success( array( 'interval' => $interval ) );
	}
}
