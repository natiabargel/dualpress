<?php
/**
 * Incoming batch receiver.
 *
 * Processes a validated batch payload from the remote server:
 *  1. Deduplication check (batch_id already seen?)
 *  2. For each change: compare with local row, resolve conflicts, apply.
 *  3. Record the batch in received_batches.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Receiver
 */
class DualPress_Receiver {

	/**
	 * Apply an incoming batch payload.
	 *
	 * @param array $payload Decoded batch payload (from REST request body).
	 * @return array{ applied: int, skipped: int, errors: string[] }
	 */
	public static function apply_batch( array $payload ) {
		global $wpdb;

		$batch_id = isset( $payload['batch_id'] ) ? sanitize_text_field( $payload['batch_id'] ) : '';
		$changes  = isset( $payload['changes'] ) && is_array( $payload['changes'] ) ? $payload['changes'] : array();

		// ------------------------------------------------------------------ //
		// 1. Deduplication                                                    //
		// ------------------------------------------------------------------ //
		if ( $batch_id && self::batch_already_received( $batch_id ) ) {
			DualPress_Logger::info(
				'batch_duplicate',
				sprintf( 'Duplicate batch %s ignored.', $batch_id ),
				array( 'batch_id' => $batch_id )
			);
			return array( 'applied' => 0, 'skipped' => count( $changes ), 'errors' => array() );
		}

		// ------------------------------------------------------------------ //
		// 2. Apply changes                                                    //
		// ------------------------------------------------------------------ //

		// Suppress hook-listener while we write to prevent echo-backs.
		DualPress_Hook_Listener::set_applying_remote( true );

		$applied  = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $changes as $change ) {
			$result = self::apply_change( $change );

			if ( true === $result ) {
				$applied++;
			} elseif ( 'skipped' === $result ) {
				$skipped++;
			} else {
				$errors[] = is_string( $result ) ? $result : 'Unknown error';
				$skipped++;
			}
		}

		DualPress_Hook_Listener::set_applying_remote( false );

		// ------------------------------------------------------------------ //
		// 3. Record batch                                                     //
		// ------------------------------------------------------------------ //
		if ( $batch_id ) {
			$wpdb->insert(
				DualPress_Database::batches_table(),
				array(
					'batch_id'    => $batch_id,
					'received_at' => current_time( 'mysql', true ),
					'items_count' => count( $changes ),
				),
				array( '%s', '%s', '%d' )
			);
		}

		DualPress_Logger::info(
			'batch_received',
			sprintf( 'Batch %s processed. Applied: %d, Skipped: %d, Errors: %d.', $batch_id, $applied, $skipped, count( $errors ) ),
			array( 'batch_id' => $batch_id, 'applied' => $applied, 'skipped' => $skipped )
		);

		return array(
			'applied' => $applied,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Apply a single change from a batch.
	 *
	 * @param array $change Change item from batch payload.
	 * @return true|'skipped'|string  true = applied, 'skipped' = no-op, string = error message
	 */
	private static function apply_change( array $change ) {
		global $wpdb;

		$table_suffix = isset( $change['table'] ) ? sanitize_key( $change['table'] ) : '';
		$action       = isset( $change['action'] ) ? strtoupper( $change['action'] ) : '';
		$pk           = isset( $change['primary_key'] ) && is_array( $change['primary_key'] ) ? $change['primary_key'] : array();
		$row_data     = isset( $change['data'] ) && is_array( $change['data'] ) ? $change['data'] : array();

		if ( empty( $table_suffix ) || ! in_array( $action, array( 'INSERT', 'UPDATE', 'DELETE' ), true ) || empty( $pk ) ) {
			return 'Invalid change structure.';
		}

		$table = $wpdb->prefix . $table_suffix;

		// Build WHERE clause for primary key.
		$where     = array();
		$where_fmt = array();
		foreach ( $pk as $col => $val ) {
			$col         = sanitize_key( $col );
			$where[]     = "`$col` = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_fmt[] = $val;
		}
		$where_sql = implode( ' AND ', $where );

		// Fetch existing local row.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$local_row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `$table` WHERE $where_sql", $where_fmt ),
			ARRAY_A
		);

		// ------------------------------------------------------------------ //
		// Conflict resolution when row already exists locally.               //
		// ------------------------------------------------------------------ //
		if ( $local_row && 'INSERT' !== $action ) {
			$decision = DualPress_Conflict::resolve( $table_suffix, $local_row, $row_data ?: null, $action );
			if ( 'keep_local' === $decision ) {
				return 'skipped';
			}
		}

		// ------------------------------------------------------------------ //
		// Apply the change.                                                   //
		// ------------------------------------------------------------------ //
		switch ( $action ) {
			case 'DELETE':
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ok = $wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE $where_sql", $where_fmt ) );
				return ( false !== $ok ) ? true : ( $wpdb->last_error ?: 'DELETE failed.' );

			case 'INSERT':
				if ( $local_row ) {
					// Row already exists; treat as UPDATE.
					return self::do_update( $table, $row_data, $where_sql, $where_fmt, $pk );
				}
				return self::do_insert( $table, $row_data );

			case 'UPDATE':
				if ( ! $local_row ) {
					// Row doesn't exist locally; treat as INSERT.
					return self::do_insert( $table, $row_data );
				}
				return self::do_update( $table, $row_data, $where_sql, $where_fmt, $pk );
		}

		return 'skipped';
	}

	/**
	 * Execute an INSERT.
	 *
	 * @param string $table    Full table name including prefix.
	 * @param array  $row_data Row data to insert.
	 * @return true|string
	 */
	private static function do_insert( $table, array $row_data ) {
		global $wpdb;

		$row_data = self::sanitize_row( $row_data );
		if ( empty( $row_data ) ) {
			return 'Empty row data for INSERT.';
		}

		$cols   = implode( '`, `', array_map( 'sanitize_key', array_keys( $row_data ) ) );
		$vals   = implode( ', ', array_fill( 0, count( $row_data ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO `$table` (`$cols`) VALUES ($vals)", array_values( $row_data ) ) );

		return ( false !== $ok ) ? true : ( $wpdb->last_error ?: 'INSERT failed.' );
	}

	/**
	 * Execute an UPDATE.
	 *
	 * @param string $table     Full table name including prefix.
	 * @param array  $row_data  Row data to set.
	 * @param string $where_sql Prepared WHERE clause.
	 * @param array  $where_fmt WHERE clause values.
	 * @param array  $pk        Primary key map (to exclude from SET clause).
	 * @return true|string
	 */
	private static function do_update( $table, array $row_data, $where_sql, array $where_fmt, array $pk ) {
		global $wpdb;

		$row_data = self::sanitize_row( $row_data );
		if ( empty( $row_data ) ) {
			return 'Empty row data for UPDATE.';
		}

		// Exclude PK columns from the SET clause.
		$pk_cols = array_keys( $pk );
		foreach ( $pk_cols as $pk_col ) {
			unset( $row_data[ $pk_col ] );
		}

		if ( empty( $row_data ) ) {
			return 'skipped'; // Only PK in data — nothing to update.
		}

		$set_parts = array();
		$set_vals  = array();
		foreach ( $row_data as $col => $val ) {
			$col         = sanitize_key( $col );
			$set_parts[] = "`$col` = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$set_vals[]  = $val;
		}
		$set_sql = implode( ', ', $set_parts );

		$all_vals = array_merge( $set_vals, $where_fmt );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE `$table` SET $set_sql WHERE $where_sql", $all_vals ) );

		return ( false !== $ok ) ? true : ( $wpdb->last_error ?: 'UPDATE failed.' );
	}

	/**
	 * Check whether a batch ID has already been processed.
	 *
	 * @param string $batch_id UUID.
	 * @return bool
	 */
	private static function batch_already_received( $batch_id ) {
		global $wpdb;

		$table = DualPress_Database::batches_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM `$table` WHERE batch_id = %s",
				$batch_id
			)
		);

		return $count > 0;
	}

	/**
	 * Sanitize a row array: stringify values and remove null columns.
	 *
	 * @param array $row Raw row data.
	 * @return array Cleaned row.
	 */
	private static function sanitize_row( array $row ) {
		$clean = array();
		foreach ( $row as $col => $val ) {
			$col = sanitize_key( $col );
			if ( $col ) {
				$clean[ $col ] = is_null( $val ) ? null : (string) $val;
			}
		}
		return $clean;
	}
}
