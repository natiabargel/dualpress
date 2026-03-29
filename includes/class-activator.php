<?php
/**
 * Plugin activation, deactivation, and upgrade logic.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Activator
 */
class DualPress_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		// Stop daemon before upgrade (will restart via cron if enabled)
		if ( class_exists( 'DualPress_Daemon' ) && method_exists( 'DualPress_Daemon', 'stop' ) ) {
			DualPress_Daemon::stop();
		}

		// Create/upgrade database tables.
		DualPress_Database::create_tables();

		// Seed default option values if not already set.
		self::seed_default_options();

		// Configure auto-increment based on server role.
		self::configure_auto_increment();

		// Schedule cron events.
		DualPress_Cron::schedule_events();

		// Store the schema version for future upgrades.
		update_option( 'dualpress_db_version', DUALPRESS_VERSION );

		// Flush rewrite rules so REST routes are available immediately.
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		DualPress_Cron::unschedule_events();
		flush_rewrite_rules();
	}

	/**
	 * Seeds default wp_options values if they don't already exist.
	 *
	 * @return void
	 */
	private static function seed_default_options() {
		$defaults = array(
			'dualpress_server_role'                => 'primary',
			'dualpress_remote_url'                 => '',
			'dualpress_secret_key'                 => wp_generate_password( 64, true, true ),
			'dualpress_sync_mode'                  => 'active-active',
			'dualpress_sync_interval'              => 5,
			'dualpress_excluded_tables'            => wp_json_encode( self::default_excluded_tables() ),
			'dualpress_included_tables'            => wp_json_encode( array() ),
			'dualpress_notification_email'         => get_option( 'admin_email' ),
			'dualpress_notification_events'        => wp_json_encode( self::default_notification_events() ),
			'dualpress_last_poll_time'             => current_time( 'mysql' ),
			'dualpress_auto_increment_configured'  => false,
			'dualpress_sync_posts'                 => true,
			'dualpress_sync_users'                 => true,
			'dualpress_sync_comments'              => true,
			'dualpress_sync_options'               => true,
			'dualpress_sync_terms'                 => true,
			'dualpress_sync_woocommerce'           => true,
			// File Sync module defaults.
			'dualpress_file_sync_enabled'          => false,
			'dualpress_file_sync_max_size'         => 100,
			'dualpress_file_sync_uploads'          => true,
			'dualpress_file_sync_themes'           => false,
			'dualpress_file_sync_plugins'          => false,
			'dualpress_file_sync_delete_remote'    => false,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * Default list of excluded table patterns.
	 *
	 * @return string[]
	 */
	private static function default_excluded_tables() {
		return array(
			'dualpress_*',
			'actionscheduler_*',
			'wc_sessions',
			'woocommerce_sessions',
			'litespeed_*',
			'w3tc_*',
			'wpr_*',
			'breeze_*',
		);
	}

	/**
	 * Default notification events that are enabled.
	 *
	 * @return string[]
	 */
	private static function default_notification_events() {
		return array(
			'connection_lost',
			'connection_restored',
			'sync_item_failed',
			'full_sync_completed',
			'queue_threshold',
		);
	}

	/**
	 * Configure MySQL auto_increment_increment / auto_increment_offset
	 * based on the server role stored in options.
	 *
	 * This only affects the current session. A my.cnf recommendation is shown
	 * in the admin UI for persistence across restarts.
	 *
	 * @return void
	 */
	public static function configure_auto_increment() {
		$role = get_option( 'dualpress_server_role', 'primary' );

		if ( ! in_array( $role, array( 'primary', 'secondary' ), true ) ) {
			return;
		}

		$offset = ( 'primary' === $role ) ? 1 : 2;

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- values are validated integers.
		$wpdb->query( 'SET SESSION auto_increment_increment = 2' );
		$wpdb->query( $wpdb->prepare( 'SET SESSION auto_increment_offset = %d', $offset ) );
		// phpcs:enable

		update_option( 'dualpress_auto_increment_configured', true );
	}
}
