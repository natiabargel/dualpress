<?php
/**
 * File sync queue management.
 *
 * Manages the wp_dualpress_file_queue table: enqueue, dequeue,
 * stats, pagination, and state transitions.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_File_Queue
 */
class DualPress_File_Queue {

	/**
	 * Returns the full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dualpress_file_queue';
	}

	// ------------------------------------------------------------------ //
	// Write operations                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Add a file to the sync queue.
	 *
	 * If an identical pending/processing entry for the same path+action
	 * already exists, it is reset rather than duplicated.
	 *
	 * @param string $file_path Relative file path (from ABSPATH).
	 * @param string $action    'PUSH', 'PULL', or 'DELETE'.
	 * @param int    $file_size File size in bytes.
	 * @param string $checksum  MD5 hash of the file, or empty string.
	 * @return int Inserted or updated row ID, 0 on failure.
	 */
	public static function enqueue( $file_path, $action, $file_size = 0, $checksum = '' ) {
		global $wpdb;
		$table = self::table();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM `$table` WHERE file_path = %s AND action = %s AND status IN ('pending','processing')",
				$file_path,
				$action
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array(
					'file_size'     => $file_size,
					'checksum'      => $checksum,
					'attempts'      => 0,
					'error_message' => null,
					'status'        => 'pending',
				),
				array( 'id' => (int) $existing_id )
			);
			return (int) $existing_id;
		}

		$wpdb->insert(
			$table,
			array(
				'file_path'  => $file_path,
				'action'     => $action,
				'file_size'  => $file_size,
				'checksum'   => $checksum,
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Add a FINALIZE command to the queue.
	 *
	 * This special entry tells the remote server to move files from staging
	 * to the final location and activate the plugin/theme.
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $slug Plugin or theme slug.
	 * @return int Inserted row ID.
	 */
	public static function enqueue_finalize( $type, $slug ) {
		global $wpdb;

		// Use a special path format: FINALIZE:type:slug
		$file_path = sprintf( 'FINALIZE:%s:%s', $type, $slug );

		$wpdb->insert(
			self::table(),
			array(
				'file_path'  => $file_path,
				'action'     => 'FINALIZE',
				'file_size'  => 0,
				'checksum'   => '',
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a queue item as completed.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public static function mark_completed( $id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Mark a queue item as failed and increment attempts.
	 *
	 * @param int    $id            Row ID.
	 * @param string $error_message Human-readable error.
	 * @return void
	 */
	public static function mark_failed( $id, $error_message = '' ) {
		global $wpdb;
		$table = self::table();

		$wpdb->update(
			$table,
			array(
				'status'        => 'failed',
				'error_message' => $error_message,
				'last_attempt'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET attempts = attempts + 1 WHERE id = %d", (int) $id ) );
	}

	/**
	 * Mark a queue item as skipped (e.g. file too large).
	 *
	 * @param int    $id     Row ID.
	 * @param string $reason Reason for skipping.
	 * @return void
	 */
	public static function mark_skipped( $id, $reason = '' ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'        => 'skipped',
				'error_message' => $reason,
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Reset all failed items to pending so they will be retried.
	 *
	 * @return int Number of rows reset.
	 */
	public static function retry_failed() {
		global $wpdb;
		return (int) $wpdb->update(
			self::table(),
			array(
				'status'        => 'pending',
				'attempts'      => 0,
				'error_message' => null,
			),
			array( 'status' => 'failed' )
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
		$table = self::table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `$table` SET status = 'pending' WHERE status = 'failed' AND attempts < %d",
				$max_attempts
			)
		);
	}

	/**
	 * Truncate the entire queue table.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `$table`" );
	}

	// ------------------------------------------------------------------ //
	// Read operations                                                      //
	// ------------------------------------------------------------------ //

	/**
	 * Fetch pending items for processing.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array[] Rows as associative arrays.
	 */
	public static function get_pending( $limit = 10 ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `$table` WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
				(int) $limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get queue statistics by status.
	 *
	 * @return array{ pending: int, processing: int, completed: int, failed: int, skipped: int, total: int }
	 */
	public static function get_stats() {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT status, COUNT(*) AS cnt FROM `$table` GROUP BY status",
			ARRAY_A
		);

		$stats = array(
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'total'      => 0,
		);

		foreach ( (array) $rows as $row ) {
			$key = $row['status'];
			if ( array_key_exists( $key, $stats ) ) {
				$stats[ $key ] = (int) $row['cnt'];
			}
			$stats['total'] += (int) $row['cnt'];
		}

		return $stats;
	}

	/**
	 * Get paginated queue items.
	 *
	 * @param string $status   Filter by status, or '' for all.
	 * @param int    $per_page Rows per page.
	 * @param int    $page     1-based page number.
	 * @return array{ rows: array[], total: int }
	 */
	public static function get_items( $status = '', $per_page = 25, $page = 1 ) {
		global $wpdb;
		$table  = self::table();
		$offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;

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
					(int) $per_page,
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
					(int) $per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Count pending items (used for progress tracking).
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE status = 'pending'" );
	}
}
