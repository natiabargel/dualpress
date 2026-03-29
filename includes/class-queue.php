<?php
/**
 * Sync queue — persistence layer for pending changes.
 *
 * Writes to wp_dualpress_sync_queue and provides batch-read methods
 * consumed by DualPress_Sender.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Queue
 */
class DualPress_Queue {

	/** Default maximum items returned per batch. */
	const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Add a change to the queue.
	 *
	 * @param string     $table_suffix  Table name without DB prefix (e.g. 'posts').
	 * @param string     $action        'INSERT', 'UPDATE', or 'DELETE'.
	 * @param array      $primary_key   Associative array of PK column → value.
	 * @param array|null $row_data      Full row data (null for DELETE).
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public static function add( $table_suffix, $action, array $primary_key, $row_data = null ) {
		global $wpdb;

		// In active-passive mode, secondary servers don't queue outbound changes.
		if (
			'active-passive' === DualPress_Settings::get_sync_mode() &&
			'secondary' === DualPress_Settings::get_server_role()
		) {
			return false;
		}

		// Skip actions for excluded tables.
		if ( self::is_table_excluded( $table_suffix ) ) {
			return false;
		}

		$checksum = null;
		if ( $row_data ) {
			$checksum = md5( serialize( $row_data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		$inserted = $wpdb->insert(
			DualPress_Database::queue_table(),
			array(
				'table_name'      => sanitize_text_field( $table_suffix ),
				'action'          => $action,
				'primary_key_data'=> wp_json_encode( $primary_key ),
				'row_data'        => $row_data ? wp_json_encode( $row_data ) : null,
				'row_checksum'    => $checksum,
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Fetch a batch of pending queue items and mark them as 'processing'.
	 *
	 * @param int $limit Max items to return. Default 100.
	 * @return array Array of queue row objects.
	 */
	public static function get_pending_batch( $limit = self::DEFAULT_BATCH_SIZE ) {
		global $wpdb;

		$table = DualPress_Database::queue_table();
		$limit = absint( $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `$table` WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$ids = array_column( $rows, 'id' );
		self::mark_status( $ids, 'processing' );

		return $rows;
	}

	/**
	 * Mark a set of queue rows with the given status.
	 *
	 * @param int[]  $ids    Queue row IDs.
	 * @param string $status 'pending', 'processing', 'completed', or 'failed'.
	 * @param string $error  Optional error message (stored on failure).
	 */
	public static function mark_status( array $ids, $status, $error = '' ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return;
		}

		$table       = DualPress_Database::queue_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$data = array(
			'status'       => $status,
			'last_attempt' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );

		if ( 'failed' === $status && $error ) {
			$data['error_message'] = $error;
			$formats[]             = '%s';
		}

		// Increment attempt counter only when we are finishing a processing attempt.
		if ( in_array( $status, array( 'completed', 'failed' ), true ) ) {
			// We need a raw UPDATE for the increment + WHERE IN.
			// Build a safe query.
			$ids_sql = implode( ',', array_map( 'intval', $ids ) );
			$now     = current_time( 'mysql', true );
			$err_sql = '';
			if ( 'failed' === $status && $error ) {
				$err_sql = ', error_message = ' . $wpdb->prepare( '%s', $error );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE `$table` SET status = %s, attempts = attempts + 1, last_attempt = %s $err_sql WHERE id IN ($ids_sql)",
					$status,
					$now
				)
			);
			return;
		}

		// For 'pending' / 'processing' — no attempt counter increment.
		$ids_sql = implode( ',', array_map( 'intval', $ids ) );
		$now     = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `$table` SET status = %s, last_attempt = %s WHERE id IN ($ids_sql)",
				$status,
				$now
			)
		);
	}

	/**
	 * Reset 'processing' items that are stuck (e.g. after a PHP crash).
	 *
	 * Items that have been 'processing' for more than 10 minutes are reset to 'pending'.
	 *
	 * @return int Number of rows reset.
	 */
	public static function reset_stuck_processing() {
		global $wpdb;
		$table = DualPress_Database::queue_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE `$table` SET status = 'pending' WHERE status = 'processing' AND last_attempt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)"
		);
	}

	/**
	 * Retry all failed items by resetting their status to 'pending'.
	 *
	 * @return int Number of rows updated.
	 */
	public static function retry_failed() {
		global $wpdb;
		$table = DualPress_Database::queue_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE `$table` SET status = 'pending', error_message = NULL WHERE status = 'failed'"
		);
	}

	/**
	 * Auto-retry failed items that haven't exceeded max attempts.
	 *
	 * @param int $max_attempts Maximum retry attempts (default 5).
	 * @return int Number of rows updated.
	 */
	public static function auto_retry_failed( $max_attempts = 5 ) {
		global $wpdb;
		$table = DualPress_Database::queue_table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `$table` SET status = 'pending' WHERE status = 'failed' AND attempts < %d",
				$max_attempts
			)
		);
	}

	/**
	 * Clear completed items older than the given number of days.
	 *
	 * @param int $days Default 7.
	 * @return int Rows deleted.
	 */
	public static function prune_completed( $days = 7 ) {
		global $wpdb;
		$table = DualPress_Database::queue_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM `$table` WHERE status = 'completed' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				absint( $days )
			)
		);
	}

	/**
	 * Clear all items from the queue.
	 *
	 * @return int|false
	 */
	public static function clear() {
		global $wpdb;
		$table = DualPress_Database::queue_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "TRUNCATE TABLE `$table`" );
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array{ pending: int, processing: int, completed: int, failed: int, high_failures: int }
	 */
	public static function get_stats() {
		global $wpdb;
		$table = DualPress_Database::queue_table();

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT status, COUNT(*) as cnt FROM `$table` GROUP BY status",
			ARRAY_A
		);

		$stats = array(
			'pending'      => 0,
			'processing'   => 0,
			'completed'    => 0,
			'failed'       => 0,
			'high_failures'=> 0,
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $stats[ $row['status'] ] ) ) {
				$stats[ $row['status'] ] = (int) $row['cnt'];
			}
		}

		// Items that have failed 10+ times.
		$stats['high_failures'] = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `$table` WHERE status = 'failed' AND attempts >= 10"
		);

		return $stats;
	}

	/**
	 * Get paginated queue items.
	 *
	 * @param string $status   Filter by status. Empty = all.
	 * @param int    $per_page Items per page.
	 * @param int    $page     Page number (1-based).
	 * @return array{ rows: array, total: int }
	 */
	public static function get_items( $status = '', $per_page = 50, $page = 1 ) {
		global $wpdb;
		$table    = DualPress_Database::queue_table();
		$per_page = absint( $per_page );
		$offset   = ( max( 1, absint( $page ) ) - 1 ) * $per_page;

		if ( $status ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM `$table` WHERE status = %s",
					$status
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM `$table` WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	// ------------------------------------------------------------------ //
	// Internal helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Check whether a table suffix matches any excluded pattern.
	 *
	 * @param string $table_suffix Table name without DB prefix.
	 * @return bool
	 */
	private static function is_table_excluded( $table_suffix ) {
		$excluded = DualPress_Settings::get_excluded_tables();

		foreach ( $excluded as $pattern ) {
			// Convert shell-style wildcard to regex.
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
			if ( preg_match( $regex, $table_suffix ) ) {
				return true;
			}
		}

		return false;
	}
}
