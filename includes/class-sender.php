<?php
/**
 * Outgoing sync sender.
 *
 * Reads pending items from the queue, groups them into a signed batch
 * payload, and POSTs to the remote server's /push endpoint.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Sender
 */
class DualPress_Sender {

	/**
	 * HTTP request timeout in seconds.
	 */
	const HTTP_TIMEOUT = 30;

	/**
	 * Process and send the next batch of pending queue items.
	 *
	 * Called by DualPress_Cron on the 'dualpress_sync' schedule.
	 *
	 * @return bool True if a batch was sent successfully, false otherwise.
	 */
	public static function process_queue() {
		// Reset stuck processing items from a previous crashed run.
		DualPress_Queue::reset_stuck_processing();

		$remote_url = DualPress_Settings::get_remote_url();
		if ( empty( $remote_url ) ) {
			return false;
		}

		$batch_size = apply_filters( 'dualpress_batch_size', DualPress_Queue::DEFAULT_BATCH_SIZE );
		$items      = DualPress_Queue::get_pending_batch( $batch_size );

		if ( empty( $items ) ) {
			return true; // Nothing to send.
		}

		$batch_id = self::generate_uuid();
		$result   = self::send_batch( $batch_id, $items );

		if ( is_wp_error( $result ) ) {
			$error_msg = $result->get_error_message();

			DualPress_Queue::mark_status( array_column( $items, 'id' ), 'failed', $error_msg );

			DualPress_Logger::error(
				'batch_failed',
				sprintf( 'Failed to send batch %s: %s', $batch_id, $error_msg ),
				array( 'batch_id' => $batch_id, 'items' => count( $items ) )
			);

			// Check for high-failure items and notify.
			DualPress_Notifier::maybe_notify_failed_items();

			return false;
		}

		DualPress_Queue::mark_status( array_column( $items, 'id' ), 'completed' );

		DualPress_Logger::info(
			'batch_sent',
			sprintf( 'Batch %s sent successfully (%d items).', $batch_id, count( $items ) ),
			array( 'batch_id' => $batch_id, 'items' => count( $items ) )
		);

		// Prune old completed items periodically.
		DualPress_Queue::prune_completed( 7 );

		// Check queue threshold notification.
		$stats = DualPress_Queue::get_stats();
		if ( $stats['pending'] >= 100 ) {
			DualPress_Notifier::notify_queue_threshold( $stats['pending'] );
		}

		return true;
	}

	/**
	 * Build and send a batch POST request to the remote server.
	 *
	 * @param string $batch_id UUID for this batch.
	 * @param array  $items    Array of queue rows (ARRAY_A format).
	 * @return true|WP_Error
	 */
	public static function send_batch( $batch_id, array $items ) {
		$remote_url = rtrim( DualPress_Settings::get_remote_url(), '/' );
		$endpoint   = $remote_url . '/wp-json/dualpress/v1/push';

		$changes = array();
		foreach ( $items as $item ) {
			$changes[] = array(
				'table'       => $item['table_name'],
				'action'      => $item['action'],
				'primary_key' => json_decode( $item['primary_key_data'], true ),
				'data'        => $item['row_data'] ? json_decode( $item['row_data'], true ) : null,
				'checksum'    => $item['row_checksum'],
			);
		}

		$payload = wp_json_encode(
			array(
				'batch_id'      => $batch_id,
				'source_server' => DualPress_Settings::get_server_role(),
				'timestamp'     => time(),
				'changes'       => $changes,
			)
		);

		$headers  = DualPress_Auth::build_headers( $payload );
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'user-agent' => 'DualPress/' . DUALPRESS_VERSION,
				'body'    => $payload,
				'timeout' => self::HTTP_TIMEOUT,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			DualPress_Notifier::notify_connection_lost( $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'dualpress_remote_error',
				sprintf( 'Remote returned HTTP %d: %s', $code, substr( $body, 0, 200 ) )
			);
		}

		return true;
	}

	/**
	 * Initiate a full-database sync by pushing all syncable tables.
	 *
	 * Chunks through each table in batches to avoid memory exhaustion.
	 *
	 * @return array{ success: bool, tables_synced: int, items_synced: int, errors: string[] }
	 */
	public static function full_sync() {
		global $wpdb;

		DualPress_Logger::info( 'full_sync_started', 'Full sync initiated.' );

		// Step 1: Sync missing tables WITH DATA to remote.
		if ( class_exists( 'DualPress_Table_Sync' ) ) {
			$table_result = DualPress_Table_Sync::sync_missing_tables_with_data();
			if ( is_wp_error( $table_result ) ) {
				DualPress_Logger::warning( 'full_sync_table_error', $table_result->get_error_message() );
			} elseif ( ! empty( $table_result['synced_tables'] ) ) {
				DualPress_Logger::info(
					'full_sync_tables_synced',
					sprintf(
						'Created %d missing tables, synced %d rows.',
						count( $table_result['synced_tables'] ),
						$table_result['rows_synced']
					)
				);
			}
		}

		// Step 2: Sync data.
		$tables = self::get_syncable_tables();
		$total_items  = 0;
		$tables_done  = 0;
		$errors       = array();
		$chunk        = 500;

		foreach ( $tables as $table_suffix => $pk_column ) {
			$table  = $wpdb->prefix . $table_suffix;
			$offset = 0;

			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT * FROM `$table` ORDER BY `$pk_column` ASC LIMIT %d OFFSET %d",
						$chunk,
						$offset
					),
					ARRAY_A
				);

				if ( empty( $rows ) ) {
					break;
				}

				$batch_id = self::generate_uuid();
				$items    = array();

				foreach ( $rows as $row ) {
					$pk_value = $row[ $pk_column ];
					$items[]  = array(
						'id'              => 0, // Not a real queue ID — ephemeral.
						'table_name'      => $table_suffix,
						'action'          => 'INSERT',
						'primary_key_data'=> wp_json_encode( array( $pk_column => $pk_value ) ),
						'row_data'        => wp_json_encode( $row ),
						'row_checksum'    => md5( serialize( $row ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					);
				}

				$result = self::send_batch( $batch_id, $items );

				if ( is_wp_error( $result ) ) {
					$errors[] = $table_suffix . ': ' . $result->get_error_message();
					break 2; // Abort full sync on first hard error.
				}

				$total_items += count( $rows );
				$offset      += $chunk;

				// Give the DB a tiny breath on large tables.
				if ( count( $rows ) === $chunk ) {
					usleep( 100000 ); // 0.1 s
				}
			} while ( count( $rows ) === $chunk );

			$tables_done++;
		}

		$success = empty( $errors );

		if ( $success ) {
			DualPress_Logger::info(
				'full_sync_completed',
				sprintf( 'Full sync completed. Tables: %d, Items: %d.', $tables_done, $total_items ),
				array( 'tables' => $tables_done, 'items' => $total_items )
			);
			DualPress_Notifier::notify_full_sync_completed( $tables_done, $total_items );
		} else {
			DualPress_Logger::error(
				'full_sync_failed',
				'Full sync failed with errors.',
				array( 'errors' => $errors )
			);
		}

		return array(
			'success'       => $success,
			'tables_synced' => $tables_done,
			'items_synced'  => $total_items,
			'errors'        => $errors,
		);
	}

	/**
	 * Verify connection to the remote server via the handshake endpoint.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$remote_url = rtrim( DualPress_Settings::get_remote_url(), '/' );

		if ( empty( $remote_url ) ) {
			return new WP_Error( 'dualpress_no_url', __( 'Remote URL is not configured.', 'dualpress' ) );
		}

		$endpoint = $remote_url . '/wp-json/dualpress/v1/handshake';

		$payload = wp_json_encode(
			array(
				'source'    => DualPress_Settings::get_server_role(),
				'timestamp' => time(),
				'version'   => DUALPRESS_VERSION,
			)
		);

		$headers  = DualPress_Auth::build_headers( $payload );
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => $headers,
				'user-agent' => 'DualPress/' . DUALPRESS_VERSION,
				'body'      => $payload,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
				'timeout'   => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'dualpress_handshake_failed',
				sprintf( __( 'Handshake failed — remote returned HTTP %d.', 'dualpress' ), $code )
			);
		}

		return true;
	}

	// ------------------------------------------------------------------ //
	// Internal helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Return the map of syncable table suffixes → primary key column.
	 *
	 * Respects the plugin's sync-enable toggles.
	 *
	 * @return array<string,string>
	 */
	/**
	 * Sync missing table schemas to remote.
	 *
	 * Compares local tables with remote and creates any missing ones.
	 *
	 * @return array|WP_Error Result with 'synced' count and 'errors', or error.
	 */
	private static function get_syncable_tables() {
		$tables = array();

		if ( DualPress_Settings::get( 'sync_posts' ) ) {
			$tables['posts']    = 'ID';
			$tables['postmeta'] = 'meta_id';
		}

		if ( DualPress_Settings::get( 'sync_users' ) ) {
			$tables['users']    = 'ID';
			$tables['usermeta'] = 'umeta_id';
		}

		if ( DualPress_Settings::get( 'sync_comments' ) ) {
			$tables['comments']    = 'comment_ID';
			$tables['commentmeta'] = 'meta_id';
		}

		if ( DualPress_Settings::get( 'sync_terms' ) ) {
			$tables['terms']              = 'term_id';
			$tables['term_taxonomy']      = 'term_taxonomy_id';
			$tables['term_relationships'] = 'object_id';
		}

		// Custom included tables.
		foreach ( DualPress_Settings::get_included_tables() as $t ) {
			$tables[ sanitize_key( $t ) ] = 'id';
		}

		return apply_filters( 'dualpress_syncable_tables', $tables );
	}

	/**
	 * Generate a RFC 4122 UUID v4.
	 *
	 * @return string
	 */
	private static function generate_uuid() {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 ); // version 4
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 ); // variant

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Check if file sync is enabled on the remote server.
	 *
	 * @return array|WP_Error Response with 'file_sync_enabled' key, or WP_Error.
	 */
	public static function check_remote_file_sync_status() {
		$remote_url = DualPress_Settings::get_remote_url();
		if ( empty( $remote_url ) ) {
			return new WP_Error( 'not_configured', 'Remote URL not configured.' );
		}

		$endpoint = trailingslashit( $remote_url ) . 'wp-json/dualpress/v1/status';
		
		$response = wp_remote_get( $endpoint, array(
			'timeout'   => 10,
			'user-agent' => 'DualPress/' . DUALPRESS_VERSION,
			'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			'headers'   => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'http_error', 'Remote returned HTTP ' . $code );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', 'Invalid JSON response.' );
		}

		return $data;
	}

	/**
	 * Push settings to the remote server.
	 *
	 * @param string $group    Settings group (e.g., 'file_sync', 'sync').
	 * @param array  $settings Key-value pairs of settings.
	 * @return array|WP_Error Response or error.
	 */
	public static function push_settings( $group, array $settings ) {
		$remote_url = DualPress_Settings::get_remote_url();
		$secret_key = DualPress_Settings::get_secret_key();

		if ( empty( $remote_url ) || empty( $secret_key ) ) {
			return new WP_Error( 'not_configured', 'Remote not configured.' );
		}

		$endpoint  = trailingslashit( $remote_url ) . 'wp-json/dualpress/v1/settings';
		$timestamp = time();
		$payload   = wp_json_encode( array(
			'group'    => $group,
			'settings' => $settings,
		) );
		$signature = DualPress_Auth::sign( $payload, $timestamp, $secret_key );

		$response = wp_remote_post( $endpoint, array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			'user-agent' => 'DualPress/' . DUALPRESS_VERSION,
			'headers'   => array(
				'Content-Type'      => 'application/json',
				'X-Sync-Signature'  => $signature,
				'X-Sync-Timestamp'  => $timestamp,
			),
			'body'      => $payload,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'http_error', 'Remote returned HTTP ' . $code );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
