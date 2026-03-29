<?php
/**
 * Database table management for DualPress.
 *
 * Creates and manages the four plugin tables:
 *   - wp_dualpress_sync_queue
 *   - wp_dualpress_sync_log
 *   - wp_dualpress_conflict_log
 *   - wp_dualpress_received_batches
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Database
 */
class DualPress_Database {

	/**
	 * Create all plugin tables using dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ------------------------------------------------------------------ //
		// 1. Sync Queue                                                        //
		// ------------------------------------------------------------------ //
		$sql_queue = "CREATE TABLE {$wpdb->prefix}dualpress_sync_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(36) DEFAULT NULL,
			table_name VARCHAR(64) NOT NULL,
			action ENUM('INSERT','UPDATE','DELETE') NOT NULL,
			primary_key_data JSON NOT NULL,
			row_data JSON DEFAULT NULL,
			row_checksum VARCHAR(32) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			attempts INT NOT NULL DEFAULT 0,
			last_attempt DATETIME DEFAULT NULL,
			status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
			error_message TEXT DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status_created (status, created_at),
			KEY idx_batch (batch_id)
		) ENGINE=InnoDB $charset_collate;";

		// ------------------------------------------------------------------ //
		// 2. Sync Log                                                          //
		// ------------------------------------------------------------------ //
		$sql_log = "CREATE TABLE {$wpdb->prefix}dualpress_sync_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			log_level ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
			event_type VARCHAR(50) NOT NULL,
			message TEXT NOT NULL,
			context JSON DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level_created (log_level, created_at),
			KEY idx_type (event_type),
			KEY idx_created (created_at)
		) ENGINE=InnoDB $charset_collate;";

		// ------------------------------------------------------------------ //
		// 3. Conflict Log                                                      //
		// ------------------------------------------------------------------ //
		$sql_conflict = "CREATE TABLE {$wpdb->prefix}dualpress_conflict_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			table_name VARCHAR(64) DEFAULT NULL,
			primary_key_data JSON DEFAULT NULL,
			local_data JSON DEFAULT NULL,
			incoming_data JSON DEFAULT NULL,
			resolution ENUM('applied_incoming','kept_local') DEFAULT NULL,
			resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_table (table_name),
			KEY idx_resolved (resolved_at)
		) ENGINE=InnoDB $charset_collate;";

		// ------------------------------------------------------------------ //
		// 4. Received Batches (deduplication)                                 //
		// ------------------------------------------------------------------ //
		$sql_batches = "CREATE TABLE {$wpdb->prefix}dualpress_received_batches (
			batch_id VARCHAR(36) NOT NULL,
			received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			items_count INT DEFAULT NULL,
			PRIMARY KEY (batch_id),
			KEY idx_received (received_at)
		) ENGINE=InnoDB $charset_collate;";

		// ------------------------------------------------------------------ //
		// 5. File Sync Queue                                                  //
		// ------------------------------------------------------------------ //
		$sql_file_queue = "CREATE TABLE {$wpdb->prefix}dualpress_file_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			file_path VARCHAR(500) NOT NULL,
			action ENUM('PUSH','PULL','DELETE','FINALIZE') NOT NULL,
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			checksum VARCHAR(32) DEFAULT NULL,
			status ENUM('pending','processing','completed','failed','skipped') NOT NULL DEFAULT 'pending',
			attempts INT NOT NULL DEFAULT 0,
			error_message TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at DATETIME DEFAULT NULL,
			last_attempt DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_path (file_path(255))
		) ENGINE=InnoDB $charset_collate;";

		dbDelta( $sql_queue );
		dbDelta( $sql_log );
		dbDelta( $sql_conflict );
		dbDelta( $sql_batches );
		dbDelta( $sql_file_queue );
	}

	/**
	 * Drop all plugin tables.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'dualpress_sync_queue',
			$wpdb->prefix . 'dualpress_sync_log',
			$wpdb->prefix . 'dualpress_conflict_log',
			$wpdb->prefix . 'dualpress_received_batches',
			$wpdb->prefix . 'dualpress_file_queue',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are controlled constants.
			$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
		}
	}

	/**
	 * Returns the table name for the sync queue.
	 *
	 * @return string
	 */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'dualpress_sync_queue';
	}

	/**
	 * Returns the table name for the sync log.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'dualpress_sync_log';
	}

	/**
	 * Returns the table name for the conflict log.
	 *
	 * @return string
	 */
	public static function conflict_table() {
		global $wpdb;
		return $wpdb->prefix . 'dualpress_conflict_log';
	}

	/**
	 * Returns the table name for received batches.
	 *
	 * @return string
	 */
	public static function batches_table() {
		global $wpdb;
		return $wpdb->prefix . 'dualpress_received_batches';
	}

	/**
	 * Returns the table name for the file sync queue.
	 *
	 * @return string
	 */
	public static function file_queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'dualpress_file_queue';
	}
}
