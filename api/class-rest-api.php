<?php
/**
 * REST API endpoint registration.
 *
 * Namespace: dualpress/v1
 *
 * Routes:
 *   POST /handshake    — capability exchange / connection test
 *   POST /push         — receive a batch of changes
 *   POST /pull         — request changes since a timestamp
 *   GET  /status       — health check (no auth required)
 *   POST /full-sync    — trigger a full sync from the remote
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Rest_Api
 */
class DualPress_Rest_Api {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'dualpress/v1';

	/**
	 * Rate-limit storage key prefix.
	 */
	const RATE_LIMIT_TRANSIENT = 'dualpress_rl_';


	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/handshake',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handshake' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/push',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'push' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pull',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pull' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/full-sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'full_sync' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/update-setting',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_setting' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// File Sync endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/file-push',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'file_push' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/file-chunk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'file_chunk' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive_settings' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// Plugin management endpoint (for safe sync).
		register_rest_route(
			self::NAMESPACE,
			'/plugin-control',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'plugin_control' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// File bundle endpoint (multiple small files in one request).
		register_rest_route(
			self::NAMESPACE,
			'/file-bundle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'file_bundle' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// Table schema sync endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/table-sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'table_sync' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// Finalize endpoint — move files from staging to final location.
		register_rest_route(
			self::NAMESPACE,
			'/finalize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_finalize' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// List all tables (for schema comparison).
		register_rest_route(
			self::NAMESPACE,
			'/tables',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_tables' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// Receive table data (bulk insert).
		register_rest_route(
			self::NAMESPACE,
			'/table-data',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_table_data' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// Get table checksum for comparison.
		register_rest_route(
			self::NAMESPACE,
			'/table-checksum',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_table_checksum' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// Truncate a table (for full sync).
		register_rest_route(
			self::NAMESPACE,
			'/table-truncate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_table_truncate' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);
	}

	/**
	 * GET /table-checksum — return checksum for a specific table.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_table_checksum( WP_REST_Request $request ) {
		global $wpdb;

		$table_suffix = sanitize_key( $request->get_param( 'table' ) );
		if ( empty( $table_suffix ) ) {
			return new WP_Error( 'missing_table', 'Table parameter required', array( 'status' => 400 ) );
		}

		$full_table = $wpdb->prefix . $table_suffix;

		// Check table exists.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$full_table
			)
		);

		if ( ! $exists ) {
			return new WP_REST_Response(
				array(
					'table'    => $table_suffix,
					'checksum' => null,
					'exists'   => false,
				),
				200
			);
		}

		// Get checksum.
		$result = $wpdb->get_row( "CHECKSUM TABLE `{$full_table}`", ARRAY_A );
		$checksum = isset( $result['Checksum'] ) ? $result['Checksum'] : null;

		return new WP_REST_Response(
			array(
				'table'    => $table_suffix,
				'checksum' => $checksum,
				'exists'   => true,
			),
			200
		);
	}

	/**
	 * POST /table-truncate — truncate a table.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_table_truncate( WP_REST_Request $request ) {
		global $wpdb;

		$body = $this->parse_body( $request );
		$table_suffix = isset( $body['table'] ) ? sanitize_key( $body['table'] ) : '';

		if ( empty( $table_suffix ) ) {
			return new WP_Error( 'missing_table', 'Table parameter required', array( 'status' => 400 ) );
		}

		$full_table = $wpdb->prefix . $table_suffix;

		// Check table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
		if ( ! $exists ) {
			return new WP_Error( 'table_not_found', 'Table not found: ' . $table_suffix, array( 'status' => 404 ) );
		}

		// Truncate.
		$wpdb->query( "TRUNCATE TABLE `{$full_table}`" );

		DualPress_Logger::info( 'table_truncated', sprintf( 'Table %s truncated via full sync.', $table_suffix ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'table'   => $table_suffix,
			),
			200
		);
	}

	/**
	 * POST /table-data — receive and insert table rows.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_table_data( WP_REST_Request $request ) {
		global $wpdb;

		$body = $this->parse_body( $request );

		if ( empty( $body['table'] ) || empty( $body['rows'] ) || ! is_array( $body['rows'] ) ) {
			return new WP_Error( 'invalid_request', 'Missing table or rows', array( 'status' => 400 ) );
		}

		$table_suffix = sanitize_key( $body['table'] );
		$full_table   = $wpdb->prefix . $table_suffix;

		// Verify table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table ) );
		if ( ! $exists ) {
			return new WP_Error( 'table_not_found', 'Table does not exist: ' . $table_suffix, array( 'status' => 404 ) );
		}

		$inserted = 0;
		$errors   = array();

		foreach ( $body['rows'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// Use REPLACE to handle duplicates.
			$result = $wpdb->replace( $full_table, $row );

			if ( false === $result ) {
				$errors[] = $wpdb->last_error;
			} else {
				$inserted++;
			}
		}

		return new WP_REST_Response(
			array(
				'success'  => empty( $errors ),
				'inserted' => $inserted,
				'errors'   => array_slice( $errors, 0, 5 ), // Limit error output.
			),
			200
		);
	}

	/**
	 * GET /tables — list all database tables.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_list_tables( WP_REST_Request $request ) {
		global $wpdb;

		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );

		return new WP_REST_Response(
			array(
				'success' => true,
				'tables'  => $tables,
			),
			200
		);
	}

	/**
	 * POST /finalize — move files from staging to final location.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_finalize( WP_REST_Request $request ) {
		$body = $this->parse_body( $request );

		$type = isset( $body['type'] ) ? sanitize_key( $body['type'] ) : '';
		$slug = isset( $body['slug'] ) ? sanitize_file_name( $body['slug'] ) : '';

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || empty( $slug ) ) {
			return new WP_Error( 'invalid_params', 'Invalid type or slug', array( 'status' => 400 ) );
		}

		// Determine paths.
		$staging_base = WP_CONTENT_DIR . '/dualpress-tmp/wp-content';
		if ( 'plugin' === $type ) {
			$staging_path = $staging_base . '/plugins/' . $slug;
			$final_path   = WP_PLUGIN_DIR . '/' . $slug;
			// Check for single-file plugin.
			$staging_file = $staging_base . '/plugins/' . $slug . '.php';
			$is_single_file = ! is_dir( $staging_path ) && file_exists( $staging_file );
			if ( $is_single_file ) {
				$staging_path = $staging_file;
				$final_path   = WP_PLUGIN_DIR . '/' . $slug . '.php';
			}
		} else {
			$staging_path = $staging_base . '/themes/' . $slug;
			$final_path   = get_theme_root() . '/' . $slug;
			$is_single_file = false;
		}

		// Check staging exists.
		if ( $is_single_file ) {
			if ( ! file_exists( $staging_path ) ) {
				return new WP_Error(
					'staging_not_found',
					sprintf( 'Staging file not found: %s', $staging_path ),
					array( 'status' => 404 )
				);
			}
		} elseif ( ! is_dir( $staging_path ) ) {
			return new WP_Error(
				'staging_not_found',
				sprintf( 'Staging directory not found: %s', $staging_path ),
				array( 'status' => 404 )
			);
		}

		// Deactivate plugin if currently active.
		if ( 'plugin' === $type ) {
			$plugin_file = $is_single_file ? ( $slug . '.php' ) : $this->find_plugin_file( $slug );
			if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
				deactivate_plugins( $plugin_file );
			}
		}

		// Delete old version.
		if ( $is_single_file ) {
			if ( file_exists( $final_path ) ) {
				unlink( $final_path );
			}
		} elseif ( is_dir( $final_path ) ) {
			$this->recursive_rmdir( $final_path );
		}

		// Move staging to final.
		if ( ! rename( $staging_path, $final_path ) ) {
			return new WP_Error(
				'move_failed',
				sprintf( 'Failed to move %s to %s', $staging_path, $final_path ),
				array( 'status' => 500 )
			);
		}

		// Activate plugin.
		$activated = false;
		if ( 'plugin' === $type ) {
			// Clear plugins cache so we can find the newly moved plugin.
			wp_cache_delete( 'plugins', 'plugins' );
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache();
			}

			$plugin_file = $this->find_plugin_file( $slug );
			if ( $plugin_file ) {
				$result = activate_plugin( $plugin_file );
				if ( ! is_wp_error( $result ) ) {
					$activated = true;
				} else {
					DualPress_Logger::warning(
						'finalize_activation_error',
						sprintf( 'Failed to activate %s: %s', $plugin_file, $result->get_error_message() )
					);
				}
			} else {
				DualPress_Logger::warning(
					'finalize_plugin_not_found',
					sprintf( 'Could not find plugin file for slug: %s', $slug )
				);
			}
		}

		DualPress_Logger::info(
			'finalize_complete',
			sprintf( 'Finalized %s: %s (activated: %s)', $type, $slug, $activated ? 'yes' : 'no' )
		);

		return new WP_REST_Response( array(
			'success'   => true,
			'type'      => $type,
			'slug'      => $slug,
			'activated' => $activated,
		), 200 );
	}

	/**
	 * POST /table-sync — receive and create table schemas from remote.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function table_sync( WP_REST_Request $request ) {
		return DualPress_Table_Sync::handle_table_sync( $request );
	}

	// ------------------------------------------------------------------ //
	// Permission callback                                                  //
	// ------------------------------------------------------------------ //

	/**
	 * Shared permission callback — verifies HMAC signature and rate limit.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_auth( WP_REST_Request $request ) {
		// Skip rate limiting for file sync endpoints (they use bundling, so fewer requests with more data).
		$route = $request->get_route();
		$file_sync_routes = array( '/file-push', '/file-chunk', '/file-bundle', '/finalize' );
		$skip_rate_limit = false;
		foreach ( $file_sync_routes as $fs_route ) {
			if ( strpos( $route, $fs_route ) !== false ) {
				$skip_rate_limit = true;
				break;
			}
		}

		// Rate limiting (skip for file sync).
		if ( ! $skip_rate_limit ) {
			$rate_check = $this->check_rate_limit( $request );
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		// HMAC verification.
		return DualPress_Auth::verify_request( $request );
	}

	// ------------------------------------------------------------------ //
	// Endpoint callbacks                                                   //
	// ------------------------------------------------------------------ //

	/**
	 * POST /handshake
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handshake( WP_REST_Request $request ) {
		$body = $this->parse_body( $request );

		DualPress_Logger::info(
			'handshake',
			'Handshake received from remote.',
			array( 'source' => isset( $body['source'] ) ? $body['source'] : 'unknown' )
		);

		return new WP_REST_Response(
			array(
				'success'    => true,
				'server'     => DualPress_Settings::get_server_role(),
				'version'    => DUALPRESS_VERSION,
				'wp_version' => get_bloginfo( 'version' ),
				'timestamp'  => time(),
			),
			200
		);
	}

	/**
	 * POST /push — receive a batch of changes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function push( WP_REST_Request $request ) {
		$payload = $this->parse_body( $request );

		if ( empty( $payload ) || ! isset( $payload['changes'] ) ) {
			return new WP_Error(
				'dualpress_invalid_payload',
				__( 'Invalid batch payload.', 'dualpress' ),
				array( 'status' => 400 )
			);
		}

		$result = DualPress_Receiver::apply_batch( $payload );

		return new WP_REST_Response(
			array(
				'success' => true,
				'applied' => $result['applied'],
				'skipped' => $result['skipped'],
				'errors'  => $result['errors'],
			),
			200
		);
	}

	/**
	 * POST /pull — return local changes since a given timestamp.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function pull( WP_REST_Request $request ) {
		$body  = $this->parse_body( $request );
		$since = isset( $body['since'] ) ? sanitize_text_field( $body['since'] ) : '';

		// Return completed queue items that were added after $since.
		global $wpdb;
		$table = DualPress_Database::queue_table();

		if ( $since ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM `$table` WHERE status = 'completed' AND created_at > %s ORDER BY created_at ASC LIMIT 500",
					$since
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `$table` WHERE status = 'completed' ORDER BY created_at DESC LIMIT 500",
				ARRAY_A
			);
		}

		$changes = array();
		foreach ( (array) $rows as $row ) {
			$changes[] = array(
				'table'       => $row['table_name'],
				'action'      => $row['action'],
				'primary_key' => json_decode( $row['primary_key_data'], true ),
				'data'        => $row['row_data'] ? json_decode( $row['row_data'], true ) : null,
				'checksum'    => $row['row_checksum'],
			);
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'server'    => DualPress_Settings::get_server_role(),
				'timestamp' => time(),
				'changes'   => $changes,
			),
			200
		);
	}

	/**
	 * GET /status — public health check.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		$stats = DualPress_Queue::get_stats();

		return new WP_REST_Response(
			array(
				'status'            => 'ok',
				'version'           => DUALPRESS_VERSION,
				'server'            => DualPress_Settings::get_server_role(),
				'sync_mode'         => DualPress_Settings::get_sync_mode(),
				'queue'             => $stats,
				'file_sync_enabled' => (bool) DualPress_Settings::get( 'file_sync_enabled', false ),
				'timestamp'         => time(),
			),
			200
		);
	}

	/**
	 * POST /full-sync — initiate or receive a full database sync.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function full_sync( WP_REST_Request $request ) {
		$payload = $this->parse_body( $request );

		// If payload contains changes, it's an incoming full-sync batch.
		if ( isset( $payload['changes'] ) ) {
			$result = DualPress_Receiver::apply_batch( $payload );

			return new WP_REST_Response(
				array(
					'success' => true,
					'applied' => $result['applied'],
					'skipped' => $result['skipped'],
					'errors'  => $result['errors'],
				),
				200
			);
		}

		// Otherwise trigger an outgoing full sync.
		$result = DualPress_Sender::full_sync();

		return new WP_REST_Response(
			array(
				'success'       => $result['success'],
				'tables_synced' => $result['tables_synced'],
				'items_synced'  => $result['items_synced'],
				'errors'        => $result['errors'],
			),
			200
		);
	}

	/**
	 * POST /update-setting — receive a setting update from the remote server.
	 *
	 * Only 'sync_mode' is accepted to avoid arbitrary setting overrides.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_setting( WP_REST_Request $request ) {
		$body    = $this->parse_body( $request );
		$setting = isset( $body['setting'] ) ? sanitize_key( $body['setting'] ) : '';
		$value   = isset( $body['value'] ) ? sanitize_key( $body['value'] ) : '';

		$allowed = array( 'sync_mode' );
		if ( ! in_array( $setting, $allowed, true ) ) {
			return new WP_Error(
				'dualpress_invalid_setting',
				__( 'Setting not allowed for remote update.', 'dualpress' ),
				array( 'status' => 400 )
			);
		}

		if ( 'sync_mode' === $setting && ! in_array( $value, array( 'active-active', 'active-passive' ), true ) ) {
			return new WP_Error(
				'dualpress_invalid_value',
				__( 'Invalid sync_mode value.', 'dualpress' ),
				array( 'status' => 400 )
			);
		}

		DualPress_Settings::set( $setting, $value );

		DualPress_Logger::info(
			'setting_updated',
			sprintf( 'Setting "%s" updated remotely to "%s".', $setting, $value )
		);

		return new WP_REST_Response( array( 'success' => true, 'setting' => $setting, 'value' => $value ), 200 );
	}

	/**
	 * POST /file-push — receive a file (or delete instruction) from the remote.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function file_push( WP_REST_Request $request ) {
		if ( ! DualPress_File_Sync::is_enabled() ) {
			return new WP_Error(
				'dualpress_file_sync_disabled',
				__( 'File sync is not enabled on this server.', 'dualpress' ),
				array( 'status' => 503 )
			);
		}

		return DualPress_File_Sync::handle_file_push( $request );
	}

	/**
	 * POST /file-chunk — receive one chunk of a large file from the remote.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function file_chunk( WP_REST_Request $request ) {
		if ( ! DualPress_File_Sync::is_enabled() ) {
			return new WP_Error(
				'dualpress_file_sync_disabled',
				__( 'File sync is not enabled on this server.', 'dualpress' ),
				array( 'status' => 503 )
			);
		}

		return DualPress_File_Sync::handle_file_chunk( $request );
	}

	/**
	 * POST /file-bundle — receive multiple small files in a single request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function file_bundle( WP_REST_Request $request ) {
		if ( ! DualPress_File_Sync::is_enabled() ) {
			return new WP_Error(
				'dualpress_file_sync_disabled',
				__( 'File sync is not enabled on this server.', 'dualpress' ),
				array( 'status' => 503 )
			);
		}

		return DualPress_File_Sync::handle_file_bundle( $request );
	}

	// ------------------------------------------------------------------ //
	// Plugin control (safe sync)                                           //
	// ------------------------------------------------------------------ //

	/**
	 * POST /plugin-control — manage plugin/theme during sync.
	 *
	 * Actions:
	 *   - prepare:  Create {slug}.NEW staging directory for incoming files
	 *   - finalize: Delete old {slug}, rename {slug}.NEW → {slug}, activate
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function plugin_control( WP_REST_Request $request ) {
		$body = $this->parse_body( $request );

		$action = isset( $body['action'] ) ? sanitize_key( $body['action'] ) : '';
		// Plugin path includes slash (e.g., "redirection/redirection.php"), don't use sanitize_file_name.
		$plugin = isset( $body['plugin'] ) ? sanitize_text_field( $body['plugin'] ) : '';
		$type   = isset( $body['type'] ) ? sanitize_key( $body['type'] ) : 'plugin';

		// Support legacy actions.
		if ( 'disable' === $action ) {
			$action = 'prepare';
		}
		if ( 'enable' === $action ) {
			$action = 'finalize';
		}

		// Handle deactivate action immediately.
		if ( 'deactivate' === $action ) {
			if ( empty( $plugin ) ) {
				return new WP_Error( 'missing_plugin', 'Plugin path required', array( 'status' => 400 ) );
			}
			if ( is_plugin_active( $plugin ) ) {
				deactivate_plugins( $plugin );
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => sprintf( 'Plugin deactivated: %s', $plugin ),
					),
					200
				);
			}
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf( 'Plugin already inactive: %s', $plugin ),
				),
				200
			);
		}

		// Handle activate action immediately.
		if ( 'activate' === $action ) {
			if ( empty( $plugin ) ) {
				return new WP_Error( 'missing_plugin', 'Plugin path required', array( 'status' => 400 ) );
			}
			if ( is_plugin_active( $plugin ) ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => sprintf( 'Plugin already active: %s', $plugin ),
					),
					200
				);
			}
			// Check if plugin file exists.
			$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
			if ( ! file_exists( $plugin_file ) ) {
				return new WP_Error(
					'plugin_not_found',
					sprintf( 'Plugin file not found: %s', $plugin ),
					array( 'status' => 404 )
				);
			}
			$result = activate_plugin( $plugin );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'activation_failed',
					sprintf( 'Failed to activate plugin: %s - %s', $plugin, $result->get_error_message() ),
					array( 'status' => 500 )
				);
			}
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf( 'Plugin activated: %s', $plugin ),
				),
				200
			);
		}

		if ( ! in_array( $action, array( 'prepare', 'finalize' ), true ) ) {
			return new WP_Error(
				'dualpress_invalid_action',
				__( 'Invalid action. Use "prepare", "finalize", or "deactivate".', 'dualpress' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $plugin ) ) {
			return new WP_Error(
				'dualpress_missing_plugin',
				__( 'Plugin/theme slug is required.', 'dualpress' ),
				array( 'status' => 400 )
			);
		}

		// Determine base directory.
		if ( 'theme' === $type ) {
			$base_dir = get_theme_root();
		} else {
			$base_dir = WP_PLUGIN_DIR;
		}

		$normal_path  = $base_dir . '/' . $plugin;
		$staging_path = $base_dir . '/' . $plugin . '.NEW';

		if ( 'prepare' === $action ) {
			// Prepare: create staging directory for incoming files.
			// Deactivate plugin first if active.
			if ( 'plugin' === $type ) {
				$plugin_file = $this->find_plugin_file( $plugin );
				if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
					deactivate_plugins( $plugin_file );
					DualPress_Logger::info( 'plugin_deactivated_for_sync', sprintf( 'Deactivated %s for sync', $plugin ) );
				}
			}

			// Remove old staging dir if exists.
			if ( is_dir( $staging_path ) ) {
				$this->recursive_rmdir( $staging_path );
			}

			// Create fresh staging directory.
			if ( ! wp_mkdir_p( $staging_path ) ) {
				return new WP_Error(
					'dualpress_mkdir_failed',
					sprintf( __( 'Failed to create staging directory for %s.', 'dualpress' ), $plugin ),
					array( 'status' => 500 )
				);
			}

			DualPress_Logger::info(
				'plugin_prepare_for_sync',
				sprintf( 'Created staging directory for %s: %s', $type, $plugin )
			);

			return new WP_REST_Response(
				array( 'success' => true, 'action' => 'prepared', 'plugin' => $plugin ),
				200
			);
		}

		if ( 'finalize' === $action ) {
			// Finalize: swap staging with original.
			if ( ! is_dir( $staging_path ) ) {
				return new WP_Error(
					'dualpress_staging_not_found',
					sprintf( __( 'Staging directory not found for %s.', 'dualpress' ), $plugin ),
					array( 'status' => 404 )
				);
			}

			// Delete old version.
			if ( is_dir( $normal_path ) ) {
				$this->recursive_rmdir( $normal_path );
			}

			// Rename staging → normal.
			if ( ! rename( $staging_path, $normal_path ) ) {
				return new WP_Error(
					'dualpress_rename_failed',
					sprintf( __( 'Failed to finalize %s.', 'dualpress' ), $plugin ),
					array( 'status' => 500 )
				);
			}

			// Activate the plugin.
			if ( 'plugin' === $type ) {
				$plugin_file = $this->find_plugin_file( $plugin );
				if ( $plugin_file ) {
					$result = activate_plugin( $plugin_file );
					if ( is_wp_error( $result ) ) {
						DualPress_Logger::warning(
							'plugin_activation_failed',
							sprintf( 'Failed to activate %s: %s', $plugin, $result->get_error_message() )
						);
					} else {
						DualPress_Logger::info(
							'plugin_activated_after_sync',
							sprintf( 'Plugin activated after sync: %s', $plugin )
						);
					}
				}
			}

			DualPress_Logger::info(
				'plugin_finalized_after_sync',
				sprintf( 'Finalized %s sync: %s', $type, $plugin )
			);

			return new WP_REST_Response(
				array( 'success' => true, 'action' => 'finalized', 'plugin' => $plugin, 'activated' => ( 'plugin' === $type ) ),
				200
			);
		}

		return new WP_Error(
			'dualpress_unknown_error',
			__( 'Unknown error.', 'dualpress' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Find the main plugin file for a given plugin slug.
	 *
	 * @param string $slug Plugin directory name.
	 * @return string|false Plugin file path relative to plugins dir, or false.
	 */
	private function find_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $file => $data ) {
			if ( 0 === strpos( $file, $slug . '/' ) ) {
				return $file;
			}
		}

		// Single-file plugin?
		if ( isset( $plugins[ $slug . '.php' ] ) ) {
			return $slug . '.php';
		}

		return false;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		return rmdir( $dir );
	}

	// ------------------------------------------------------------------ //
	// Internal helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Parse request body as JSON.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array Decoded payload or empty array.
	 */
	private function parse_body( WP_REST_Request $request ) {
		$body = $request->get_body();

		// Handle gzip-compressed requests via Content-Encoding header.
		$content_encoding = $request->get_header( 'content-encoding' );
		if ( $content_encoding === 'gzip' && function_exists( 'gzdecode' ) ) {
			$decompressed = @gzdecode( $body );
			if ( $decompressed !== false ) {
				$body = $decompressed;
			}
		}

		$decoded = json_decode( $body, true );

		// Handle base64-encoded gzip compression (for table-data).
		if ( is_array( $decoded ) && isset( $decoded['compressed'] ) && function_exists( 'gzdecode' ) ) {
			$compressed_data = base64_decode( $decoded['compressed'] );
			if ( $compressed_data !== false ) {
				$decompressed = @gzdecode( $compressed_data );
				if ( $decompressed !== false ) {
					$decoded = json_decode( $decompressed, true );
				}
			}
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Simple in-memory + transient-backed rate limiter.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( WP_REST_Request $request ) {
		$ip  = $this->get_client_ip();
		$key = self::RATE_LIMIT_TRANSIENT . md5( $ip );

		$count = (int) get_transient( $key );
		$count++;

		if ( $count > DualPress_Settings::get_rate_limit_max() ) {
			return new WP_Error(
				'dualpress_rate_limited',
				__( 'Too many requests. Please slow down.', 'dualpress' ),
				array( 'status' => 429 )
			);
		}

		// Store with 60-second TTL (resets window each minute).
		set_transient( $key, $count, 60 );

		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		// Prefer REMOTE_ADDR — trusting X-Forwarded-For without proper config
		// is a security risk, so we only use it when REMOTE_ADDR is localhost.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		return $ip;
	}

	/**
	 * POST /settings — receive settings from remote server.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function receive_settings( WP_REST_Request $request ) {
		$payload  = $this->parse_body( $request );
		$group    = isset( $payload['group'] ) ? sanitize_key( $payload['group'] ) : '';
		$settings = isset( $payload['settings'] ) ? $payload['settings'] : array();

		if ( empty( $group ) || empty( $settings ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		$allowed_groups = array( 'file_sync', 'sync' );
		if ( ! in_array( $group, $allowed_groups, true ) ) {
			return new WP_REST_Response( array( 'error' => 'Unknown settings group.' ), 400 );
		}

		// Apply settings based on group.
		foreach ( $settings as $key => $value ) {
			$full_key = sanitize_key( $key );
			// Only allow known settings keys for security.
			$allowed_keys = array(
				'file_sync_enabled', 'file_sync_max_size', 'file_sync_uploads',
				'file_sync_themes', 'file_sync_plugins', 'file_sync_delete_remote',
				'sync_mode', 'sync_interval',
				'sync_posts', 'sync_users', 'sync_comments', 'sync_options', 'sync_terms', 'sync_woocommerce',
				// Transfer settings.
				'file_sync_bundle_mb', 'file_sync_compress',
				// DB sync settings.
				'db_sync_interval', 'db_sync_method', 'db_bundle_mb', 'db_compress',
				'excluded_tables', 'excluded_meta_keys', 'excluded_option_keys',
			);
			if ( in_array( $full_key, $allowed_keys, true ) ) {
				DualPress_Settings::set( $full_key, $value );
			}
		}

		DualPress_Logger::info( 'settings_received', sprintf( 'Settings group "%s" received from remote.', $group ) );

		return new WP_REST_Response( array(
			'status'  => 'ok',
			'message' => 'Settings applied.',
		), 200 );
	}
}
