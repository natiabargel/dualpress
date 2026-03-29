<?php
/**
 * Settings manager — typed accessors over wp_options.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Settings
 */
class DualPress_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * In-memory cache so we don't hit get_option() multiple times per request.
	 *
	 * @var array<string, mixed>
	 */
	private static $cache = array();

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key without the 'dualpress_' prefix.
	 * @param mixed  $default Default value if option is not set.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$option = 'dualpress_' . $key;

		if ( array_key_exists( $option, self::$cache ) ) {
			return self::$cache[ $option ];
		}

		$value = get_option( $option, $default );
		self::$cache[ $option ] = $value;
		return $value;
	}

	/**
	 * Set a setting value.
	 *
	 * @param string $key   Setting key without the 'dualpress_' prefix.
	 * @param mixed  $value Value to store.
	 * @return bool True if updated, false otherwise.
	 */
	public static function set( $key, $value ) {
		$option = 'dualpress_' . $key;
		self::$cache[ $option ] = $value;
		return update_option( $option, $value );
	}

	/**
	 * Delete a setting.
	 *
	 * @param string $key Setting key without the 'dualpress_' prefix.
	 * @return bool
	 */
	public static function delete( $key ) {
		$option = 'dualpress_' . $key;
		unset( self::$cache[ $option ] );
		return delete_option( $option );
	}

	/**
	 * Flush the in-memory cache (useful in tests or after bulk updates).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	// ------------------------------------------------------------------ //
	// Typed convenience accessors                                          //
	// ------------------------------------------------------------------ //

	/**
	 * @return string 'primary' or 'secondary'
	 */
	public static function get_server_role() {
		return (string) self::get( 'server_role', 'primary' );
	}

	/**
	 * @return string Remote site URL.
	 */
	public static function get_remote_url() {
		return (string) self::get( 'remote_url', '' );
	}

	/**
	 * @return string HMAC secret key.
	 */
	public static function get_secret_key() {
		return (string) self::get( 'secret_key', '' );
	}

	/**
	 * @return string 'active-active' or 'active-passive'
	 */
	public static function get_sync_mode() {
		return (string) self::get( 'sync_mode', 'active-active' );
	}

	/**
	 * @return int Sync interval in minutes.
	 */
	public static function get_sync_interval() {
		return (int) self::get( 'sync_interval', 60 ); // Default: 60 seconds.
	}

	/**
	 * @return int Max API requests per 60-second window per IP.
	 */
	public static function get_rate_limit_max() {
		return (int) self::get( 'rate_limit_max', 300 );
	}

	/**
	 * @return string[] Array of excluded table patterns.
	 */
	public static function get_excluded_tables() {
		$raw = self::get( 'excluded_tables', '[]' );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @return string[] Array of additional table names to include.
	 * @deprecated Use get_excluded_tables() instead.
	 */
	public static function get_included_tables() {
		$raw = self::get( 'included_tables', '[]' );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @return string Notification email address.
	 */
	public static function get_notification_email() {
		return (string) self::get( 'notification_email', get_option( 'admin_email' ) );
	}

	/**
	 * @return string[] Array of enabled notification event types.
	 */
	public static function get_notification_events() {
		$raw = self::get( 'notification_events', '[]' );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Checks whether a specific notification event is enabled.
	 *
	 * @param string $event Event type slug.
	 * @return bool
	 */
	public static function is_notification_enabled( $event ) {
		return in_array( $event, self::get_notification_events(), true );
	}

	/**
	 * Returns true when plugin has been fully configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return ! empty( self::get_remote_url() ) && ! empty( self::get_secret_key() );
	}

	/**
	 * @return string[] Array of excluded meta keys.
	 */
	public static function get_excluded_meta_keys() {
		$raw = self::get( 'excluded_meta_keys', '[]' );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @return string[] Array of excluded option keys.
	 */
	public static function get_excluded_option_keys() {
		$raw = self::get( 'excluded_option_keys', '[]' );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Delete all plugin options (used by uninstall).
	 *
	 * @return void
	 */
	public static function delete_all_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dualpress_%'" );
		self::flush_cache();
	}
}
