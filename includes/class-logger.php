<?php
/**
 * Logging service — writes to wp_dualpress_sync_log.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Logger
 */
class DualPress_Logger {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Valid log levels in ascending severity.
	 *
	 * @var string[]
	 */
	const LEVELS = array( 'debug', 'info', 'warning', 'error' );

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

	// ------------------------------------------------------------------ //
	// Public log-level helpers                                             //
	// ------------------------------------------------------------------ //

	/**
	 * Log a debug message.
	 *
	 * @param string $event_type Event type slug.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Optional structured data.
	 */
	public static function debug( $event_type, $message, array $context = array() ) {
		self::log( 'debug', $event_type, $message, $context );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $event_type Event type slug.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Optional structured data.
	 */
	public static function info( $event_type, $message, array $context = array() ) {
		self::log( 'info', $event_type, $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $event_type Event type slug.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Optional structured data.
	 */
	public static function warning( $event_type, $message, array $context = array() ) {
		self::log( 'warning', $event_type, $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $event_type Event type slug.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Optional structured data.
	 */
	public static function error( $event_type, $message, array $context = array() ) {
		self::log( 'error', $event_type, $message, $context );
	}

	/**
	 * Core log method.
	 *
	 * @param string $level      Log level (debug|info|warning|error).
	 * @param string $event_type Event type slug.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Optional structured data.
	 */
	public static function log( $level, $event_type, $message, array $context = array() ) {
		global $wpdb;

		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		$table = DualPress_Database::log_table();

		$wpdb->insert(
			$table,
			array(
				'log_level'  => $level,
				'event_type' => sanitize_text_field( $event_type ),
				'message'    => sanitize_textarea_field( $message ),
				'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete log entries older than the given number of days.
	 *
	 * @param int $days Number of days to retain. Default 30.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function cleanup( $days = 30 ) {
		global $wpdb;

		$table = DualPress_Database::log_table();
		$days  = absint( $days );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM `$table` WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	/**
	 * Delete all log entries.
	 *
	 * @return int|false
	 */
	public static function clear_all() {
		global $wpdb;
		$table = DualPress_Database::log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "TRUNCATE TABLE `$table`" );
	}

	/**
	 * Retrieve paginated log entries.
	 *
	 * @param array $args {
	 *     @type string $level      Filter by log level.
	 *     @type string $event_type Filter by event type.
	 *     @type string $search     Search in message.
	 *     @type string $since      ISO datetime string — only entries after this.
	 *     @type int    $per_page   Results per page. Default 50.
	 *     @type int    $page       Page number (1-based). Default 1.
	 * }
	 * @return array{ rows: array, total: int }
	 */
	public static function get_logs( array $args = array() ) {
		global $wpdb;

		$table    = DualPress_Database::log_table();
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['level'] ) && in_array( $args['level'], self::LEVELS, true ) ) {
			$where[]  = 'log_level = %s';
			$params[] = $args['level'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$params[] = sanitize_text_field( $args['event_type'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $args['since'] );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM `$table` WHERE $where_sql";
		$rows_sql  = "SELECT * FROM `$table` WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
		// phpcs:enable

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$rows  = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( $rows_sql, $per_page, $offset ),
				ARRAY_A
			);
		}

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
