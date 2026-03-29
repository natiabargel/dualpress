<?php
/**
 * Table Schema Synchronization.
 *
 * Handles syncing of custom table schemas between servers when plugins
 * create new tables (e.g., WooCommerce, Action Scheduler).
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Table_Sync
 */
class DualPress_Table_Sync {

	/**
	 * Core WordPress tables that should never be synced as "new".
	 *
	 * @var array
	 */
	private static $core_tables = array(
		'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
		'term_relationships', 'term_taxonomy', 'termmeta', 'terms',
		'usermeta', 'users',
	);

	/**
	 * DualPress internal tables.
	 *
	 * @var array
	 */
	private static $internal_tables = array(
		'dualpress_queue', 'dualpress_file_queue', 'dualpress_conflict',
		'dualpress_log', 'dualpress_sync_state',
	);

	/**
	 * Get list of all tables in the database.
	 *
	 * @return array Table names (without prefix).
	 */
	public static function get_all_tables() {
		global $wpdb;

		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
		$result = array();

		foreach ( $tables as $table ) {
			$name = str_replace( $wpdb->prefix, '', $table );
			$result[] = $name;
		}

		return $result;
	}

	/**
	 * Get the CREATE TABLE statement for a table.
	 *
	 * @param string $table Table name (without prefix).
	 * @return string|false CREATE TABLE statement or false.
	 */
	public static function get_table_schema( $table ) {
		global $wpdb;

		$full_name = $wpdb->prefix . $table;
		$result = $wpdb->get_row( "SHOW CREATE TABLE `{$full_name}`", ARRAY_A );

		if ( $result && isset( $result['Create Table'] ) ) {
			return $result['Create Table'];
		}

		return false;
	}

	/**
	 * Get schemas for multiple tables.
	 *
	 * @param array $tables Table names (without prefix).
	 * @return array Table name => CREATE statement.
	 */
	public static function get_table_schemas( $tables ) {
		$schemas = array();

		foreach ( $tables as $table ) {
			$schema = self::get_table_schema( $table );
			if ( $schema ) {
				$schemas[ $table ] = $schema;
			}
		}

		return $schemas;
	}

	/**
	 * Sync table schemas to the remote server.
	 *
	 * @param array $tables Table names to sync (without prefix).
	 * @return array Result with 'synced' and 'errors'.
	 */
	public static function sync_schemas_to_remote( $tables ) {
		if ( empty( $tables ) || ! DualPress_Settings::is_configured() ) {
			return array( 'synced' => 0, 'errors' => array() );
		}

		$schemas = self::get_table_schemas( $tables );

		if ( empty( $schemas ) ) {
			return array( 'synced' => 0, 'errors' => array( 'No schemas found' ) );
		}

		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/table-sync';

		$payload = wp_json_encode( array(
			'action'  => 'create',
			'schemas' => $schemas,
		) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => DualPress_Auth::build_headers( $payload ),
				'body'      => $payload,
				'timeout'   => 60,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'synced' => 0, 'errors' => array( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array(
				'synced' => 0,
				'errors' => array( sprintf( 'HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) ) ),
			);
		}

		return array(
			'synced' => isset( $body['created'] ) ? (int) $body['created'] : 0,
			'errors' => isset( $body['errors'] ) ? $body['errors'] : array(),
		);
	}

	/**
	 * Handle incoming table sync request (on receiving server).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_table_sync( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) || empty( $body['schemas'] ) ) {
			return new WP_Error( 'invalid_payload', 'Missing schemas', array( 'status' => 400 ) );
		}

		$schemas = $body['schemas'];
		$created = 0;
		$errors  = array();

		global $wpdb;

		foreach ( $schemas as $table => $create_statement ) {
			$table = sanitize_key( $table );
			$full_name = $wpdb->prefix . $table;

			// Check if table already exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$full_name
			) );

			if ( $exists ) {
				continue;
			}

			// Modify the CREATE statement to use local prefix.
			$local_create = preg_replace(
				'/CREATE TABLE `[^`]+`/',
				"CREATE TABLE `{$full_name}`",
				$create_statement
			);

			// Remove AUTO_INCREMENT value to start fresh.
			$local_create = preg_replace( '/AUTO_INCREMENT=\d+\s*/', '', $local_create );

			$result = $wpdb->query( $local_create );

			if ( false === $result ) {
				$errors[] = sprintf( 'Failed to create %s: %s', $table, $wpdb->last_error );
			} else {
				$created++;
				DualPress_Logger::info(
					'table_created',
					sprintf( 'Table %s created via schema sync', $table )
				);
			}
		}

		return new WP_REST_Response( array(
			'success' => true,
			'created' => $created,
			'errors'  => $errors,
		), 200 );
	}

	/**
	 * Take a snapshot of current tables.
	 *
	 * @return array Current table names.
	 */
	public static function snapshot_tables() {
		return self::get_all_tables();
	}

	/**
	 * Detect and sync new tables after plugin activation.
	 *
	 * @param array  $before_snapshot Tables before activation.
	 * @param string $plugin_slug     Plugin that was activated.
	 * @return array Sync result.
	 */
	public static function sync_new_tables( $before_snapshot, $plugin_slug = '' ) {
		$after_tables = self::get_all_tables();
		$new_tables = array_diff( $after_tables, $before_snapshot );

		// Filter out internal tables.
		$new_tables = array_filter( $new_tables, function( $table ) {
			return strpos( $table, 'dualpress_' ) !== 0;
		} );

		if ( empty( $new_tables ) ) {
			return array( 'synced' => 0, 'new_tables' => array(), 'errors' => array() );
		}

		DualPress_Logger::info(
			'new_tables_detected',
			sprintf( 'New tables after %s activation: %s', $plugin_slug, implode( ', ', $new_tables ) )
		);

		$result = self::sync_schemas_to_remote( array_values( $new_tables ) );
		$result['new_tables'] = array_values( $new_tables );

		return $result;
	}

	/**
	 * Sync missing tables with data (cron job).
	 *
	 * Compares local vs remote tables, creates missing ones,
	 * and syncs data for ALL plugin tables (not just core WP tables).
	 *
	 * @return array|WP_Error Result with synced_tables and rows_synced.
	 */
	public static function sync_missing_tables_with_data() {
		global $wpdb;

		$method = DualPress_Settings::get( 'db_sync_method', 'last_id' );

		// Get local tables (without prefix).
		$local_tables = self::get_all_tables();

		// Get remote tables via REST.
		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/tables';
		$response = wp_remote_get(
			$endpoint,
			array(
				'headers'   => DualPress_Auth::build_headers( '' ),
				'timeout'   => 30,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tables'] ) || ! is_array( $body['tables'] ) ) {
			return new WP_Error( 'remote_tables_error', 'Could not fetch remote tables' );
		}

		// Convert remote tables to suffixes (without prefix).
		$remote_suffixes = array();
		foreach ( $body['tables'] as $table ) {
			$remote_suffixes[] = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $table );
		}

		// Find missing tables.
		$missing = array_diff( $local_tables, $remote_suffixes );

		// Filter out internal tables.
		$missing = array_filter( $missing, function( $table ) {
			return strpos( $table, 'dualpress_' ) !== 0
				&& strpos( $table, 'actionscheduler_' ) !== 0;
		} );

		// Create missing tables first.
		if ( ! empty( $missing ) ) {
			$schema_result = self::sync_schemas_to_remote( array_values( $missing ) );
			if ( is_wp_error( $schema_result ) ) {
				DualPress_Logger::warning( 'schema_sync_error', $schema_result->get_error_message() );
			}
		}

		// Now sync data for plugin tables (not core WP tables).
		$plugin_tables = self::get_plugin_tables( $local_tables );

		$rows_synced = 0;
		$synced_tables = array();

		foreach ( $plugin_tables as $table_suffix ) {
			$full_table = $wpdb->prefix . $table_suffix;

			// Get row count.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
			if ( $count === 0 ) {
				continue; // Empty table, skip.
			}

			// Apply sync method.
			if ( 'checksum' === $method ) {
				// Compare checksums — skip if identical.
				if ( self::table_checksums_match( $table_suffix ) ) {
					continue;
				}
			}

			// Determine starting point.
			$start_id = 0;
			$pk_column = self::get_primary_key_column( $full_table );

			if ( 'last_id' === $method && $pk_column ) {
				// Get last synced ID for this table.
				$start_id = (int) get_option( 'dualpress_last_synced_' . $table_suffix, 0 );
			}

			// Sync data in bundles based on size (default 2MB).
			$bundle_mb    = (int) DualPress_Settings::get( 'db_bundle_mb', 2 );
			$bundle_bytes = $bundle_mb * 1024 * 1024;
			$chunk_size   = 1000; // Fetch 1000 rows at a time from DB.
			$offset       = 0;
			$max_id       = $start_id;
			$bundle       = array();
			$bundle_size  = 0;

			do {
				if ( 'last_id' === $method && $pk_column && $start_id > 0 ) {
					// Only rows with ID > last synced.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM `{$full_table}` WHERE `{$pk_column}` > %d ORDER BY `{$pk_column}` ASC LIMIT %d",
							$start_id + $offset,
							$chunk_size
						),
						ARRAY_A
					);
					// Track max ID for next sync.
					if ( ! empty( $rows ) && $pk_column ) {
						$last_row = end( $rows );
						if ( isset( $last_row[ $pk_column ] ) && (int) $last_row[ $pk_column ] > $max_id ) {
							$max_id = (int) $last_row[ $pk_column ];
						}
					}
				} else {
					// Full sync — all rows.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM `{$full_table}` LIMIT %d OFFSET %d",
							$chunk_size,
							$offset
						),
						ARRAY_A
					);
					$offset += $chunk_size;
				}

				if ( empty( $rows ) ) {
					break;
				}

				// Add rows to bundle, send when size limit reached.
				foreach ( $rows as $row ) {
					$row_json = wp_json_encode( $row );
					$row_size = strlen( $row_json );

					// If bundle would exceed limit, send it first.
					if ( $bundle_size + $row_size > $bundle_bytes && ! empty( $bundle ) ) {
						$sync_result = self::send_table_data( $table_suffix, $bundle );
						if ( is_wp_error( $sync_result ) ) {
							DualPress_Logger::warning(
								'table_data_sync_error',
								sprintf( 'Failed to sync data for %s: %s', $table_suffix, $sync_result->get_error_message() )
							);
							break 2; // Exit both loops.
						}
						$rows_synced += count( $bundle );
						$bundle      = array();
						$bundle_size = 0;
					}

					$bundle[]     = $row;
					$bundle_size += $row_size;
				}

			} while ( count( $rows ) === $chunk_size );

			// Send remaining bundle.
			if ( ! empty( $bundle ) ) {
				$sync_result = self::send_table_data( $table_suffix, $bundle );
				if ( is_wp_error( $sync_result ) ) {
					DualPress_Logger::warning(
						'table_data_sync_error',
						sprintf( 'Failed to sync data for %s: %s', $table_suffix, $sync_result->get_error_message() )
					);
				} else {
					$rows_synced += count( $bundle );
				}
			}

			// Save last synced ID for last_id method.
			if ( 'last_id' === $method && $pk_column && $max_id > $start_id ) {
				update_option( 'dualpress_last_synced_' . $table_suffix, $max_id, false );
			}

			$synced_tables[] = $table_suffix;
		}

		return array(
			'synced_tables' => $synced_tables,
			'rows_synced'   => $rows_synced,
			'created_tables' => array_values( $missing ),
		);
	}

	/**
	 * Get plugin tables (non-core WordPress tables).
	 *
	 * @param array $all_tables All table suffixes.
	 * @return array Plugin table suffixes.
	 */
	private static function get_plugin_tables( $all_tables ) {
		// Core WordPress tables to exclude.
		$core_tables = array(
			'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
			'term_relationships', 'term_taxonomy', 'termmeta', 'terms',
			'usermeta', 'users',
		);

		// Internal tables to exclude (always).
		$excluded_prefixes = array(
			'dualpress_', 'actionscheduler_', 'wc_sessions', 'woocommerce_sessions',
			'litespeed_', 'w3tc_', 'wpr_', 'breeze_',
		);

		// User-defined excluded tables.
		$user_excluded = DualPress_Settings::get_excluded_tables();

		return array_filter( $all_tables, function( $table ) use ( $core_tables, $excluded_prefixes, $user_excluded ) {
			// Skip core tables.
			if ( in_array( $table, $core_tables, true ) ) {
				return false;
			}

			// Skip user-excluded tables.
			if ( in_array( $table, $user_excluded, true ) ) {
				return false;
			}

			// Skip excluded prefixes.
			foreach ( $excluded_prefixes as $prefix ) {
				if ( strpos( $table, $prefix ) === 0 ) {
					return false;
				}
			}

			return true;
		} );
	}

	/**
	 * Send table data to remote server.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @param array  $rows         Array of row data.
	 * @return true|WP_Error
	 */
	private static function send_table_data( $table_suffix, $rows ) {
		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/table-data';
		$compress = (bool) DualPress_Settings::get( 'db_compress', true );

		$payload = wp_json_encode( array(
			'table' => $table_suffix,
			'rows'  => $rows,
		) );

		// Compress payload if enabled — send as base64 to avoid REST API JSON parsing issues.
		$body = $payload;
		$headers = array( 'Content-Type' => 'application/json' );

		if ( $compress && function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $payload, 6 );
			if ( $compressed !== false ) {
				$body = wp_json_encode( array(
					'compressed' => base64_encode( $compressed ),
				) );
			}
		}

		$headers = array_merge(
			DualPress_Auth::build_headers( $body ),
			$headers
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => $headers,
				'body'      => $body,
				'timeout'   => 60,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'remote_error', 'Remote returned HTTP ' . $code );
		}

		return true;
	}

	/**
	 * Get the primary key column for a table.
	 *
	 * @param string $table Full table name with prefix.
	 * @return string|null Column name or null if no single PK.
	 */
	private static function get_primary_key_column( $table ) {
		global $wpdb;

		$columns = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'"
			),
			ARRAY_A
		);

		// Only return if there's exactly one primary key column.
		if ( count( $columns ) === 1 ) {
			return $columns[0]['Column_name'];
		}

		return null;
	}

	/**
	 * Check if table checksums match between local and remote.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @return bool True if checksums match (no sync needed).
	 */
	private static function table_checksums_match( $table_suffix ) {
		global $wpdb;

		$full_table = $wpdb->prefix . $table_suffix;

		// Get local checksum.
		$local_result = $wpdb->get_row( "CHECKSUM TABLE `{$full_table}`", ARRAY_A );
		if ( ! $local_result || ! isset( $local_result['Checksum'] ) ) {
			return false; // Can't get checksum, assume needs sync.
		}
		$local_checksum = $local_result['Checksum'];

		// Get remote checksum via REST.
		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/table-checksum';
		$response = wp_remote_get(
			add_query_arg( 'table', $table_suffix, $endpoint ),
			array(
				'headers'   => DualPress_Auth::build_headers( '' ),
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['checksum'] ) ) {
			return false;
		}

		return (string) $local_checksum === (string) $body['checksum'];
	}

	/**
	 * Sync a single table to remote.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @return array|WP_Error Result array with rows_synced and skipped keys.
	 */
	public static function sync_single_table( $table_suffix ) {
		global $wpdb;

		$method = DualPress_Settings::get( 'db_sync_method', 'last_id' );
		$full_table = $wpdb->prefix . $table_suffix;

		// Check table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table ) );
		if ( ! $exists ) {
			return new WP_Error( 'table_not_found', 'Table not found: ' . $table_suffix );
		}

		// Get row count.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
		if ( $count === 0 ) {
			return array(
				'rows_synced' => 0,
				'skipped'     => true,
				'reason'      => 'empty',
			);
		}

		// Apply sync method.
		if ( 'checksum' === $method ) {
			if ( self::table_checksums_match( $table_suffix ) ) {
				return array(
					'rows_synced' => 0,
					'skipped'     => true,
					'reason'      => 'checksum_match',
				);
			}
		}

		// Determine starting point.
		$start_id = 0;
		$pk_column = self::get_primary_key_column( $full_table );

		if ( 'last_id' === $method && $pk_column ) {
			$start_id = (int) get_option( 'dualpress_last_synced_' . $table_suffix, 0 );
		}

		// Sync data in bundles based on size (default 2MB).
		$bundle_mb    = (int) DualPress_Settings::get( 'db_bundle_mb', 2 );
		$bundle_bytes = $bundle_mb * 1024 * 1024;
		$chunk_size   = 1000; // Fetch 1000 rows at a time from DB.
		$offset       = 0;
		$max_id       = $start_id;
		$rows_synced  = 0;
		$bundle       = array();
		$bundle_size  = 0;

		do {
			if ( 'last_id' === $method && $pk_column && $start_id > 0 ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM `{$full_table}` WHERE `{$pk_column}` > %d ORDER BY `{$pk_column}` ASC LIMIT %d",
						$start_id + $offset,
						$chunk_size
					),
					ARRAY_A
				);
				if ( ! empty( $rows ) && $pk_column ) {
					$last_row = end( $rows );
					if ( isset( $last_row[ $pk_column ] ) && (int) $last_row[ $pk_column ] > $max_id ) {
						$max_id = (int) $last_row[ $pk_column ];
					}
				}
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM `{$full_table}` LIMIT %d OFFSET %d",
						$chunk_size,
						$offset
					),
					ARRAY_A
				);
				$offset += $chunk_size;
			}

			if ( empty( $rows ) ) {
				break;
			}

			// Add rows to bundle, send when size limit reached.
			foreach ( $rows as $row ) {
				$row_json = wp_json_encode( $row );
				$row_size = strlen( $row_json );

				if ( $bundle_size + $row_size > $bundle_bytes && ! empty( $bundle ) ) {
					$sync_result = self::send_table_data( $table_suffix, $bundle );
					if ( is_wp_error( $sync_result ) ) {
						return $sync_result;
					}
					$rows_synced += count( $bundle );
					$bundle      = array();
					$bundle_size = 0;
				}

				$bundle[]     = $row;
				$bundle_size += $row_size;
			}

		} while ( count( $rows ) === $chunk_size );

		// Send remaining bundle.
		if ( ! empty( $bundle ) ) {
			$sync_result = self::send_table_data( $table_suffix, $bundle );
			if ( is_wp_error( $sync_result ) ) {
				return $sync_result;
			}
			$rows_synced += count( $bundle );
		}

		// Save last synced ID.
		if ( 'last_id' === $method && $pk_column && $max_id > $start_id ) {
			update_option( 'dualpress_last_synced_' . $table_suffix, $max_id, false );
		}

		// Check if we skipped because nothing new (last_id method).
		if ( $rows_synced === 0 && 'last_id' === $method ) {
			return array(
				'rows_synced' => 0,
				'skipped'     => true,
				'reason'      => 'no_new_rows',
			);
		}

		return array(
			'rows_synced' => $rows_synced,
			'skipped'     => false,
		);
	}

	/**
	 * Sync a chunk of a table (for full sync with progress).
	 *
	 * This does a FULL sync (ignores last_id), and also deletes rows on remote
	 * that don't exist locally.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @param int    $offset       Row offset to start from.
	 * @param int    $chunk_size   Number of rows to sync in this chunk.
	 * @return array|WP_Error
	 */
	public static function sync_table_chunk( $table_suffix, $offset = 0, $chunk_size = 1000 ) {
		global $wpdb;

		$full_table = $wpdb->prefix . $table_suffix;

		// Check table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
		if ( ! $exists ) {
			return new WP_Error( 'table_not_found', 'Table not found: ' . $table_suffix );
		}

		// Get total row count.
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );

		// Fetch chunk.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$full_table}` LIMIT %d OFFSET %d",
				$chunk_size,
				$offset
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			// No more rows — we're done. Now clean up deleted rows on remote.
			if ( $offset === 0 ) {
				// Table is empty — truncate on remote.
				self::truncate_remote_table( $table_suffix );
			}
			return array(
				'rows_synced' => 0,
				'has_more'    => false,
				'total_rows'  => $total_rows,
			);
		}

		// Send chunk to remote.
		$sync_result = self::send_table_data( $table_suffix, $rows );
		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}

		$has_more = ( $offset + count( $rows ) ) < $total_rows;

		return array(
			'rows_synced' => count( $rows ),
			'has_more'    => $has_more,
			'total_rows'  => $total_rows,
			'next_offset' => $offset + count( $rows ),
		);
	}

	/**
	 * Truncate a table on the remote server.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @return true|WP_Error
	 */
	private static function truncate_remote_table( $table_suffix ) {
		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/table-truncate';

		$payload = wp_json_encode( array( 'table' => $table_suffix ) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => array_merge(
					DualPress_Auth::build_headers( $payload ),
					array( 'Content-Type' => 'application/json' )
				),
				'body'      => $payload,
				'timeout'   => 30,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
