<?php
/**
 * File Synchronization controller.
 *
 * Handles attachment hooks, file pushing/receiving via REST, and the
 * initial directory-scan queue builder.
 *
 * Push flow  (outbound):
 *   add/edit/delete_attachment → DualPress_File_Queue::enqueue() →
 *   WP-Cron or manual trigger → self::process_queue() →
 *   POST /dualpress/v1/file-push (or /file-chunk for large files)
 *
 * Receive flow (inbound):
 *   Remote POSTs to /file-push or /file-chunk →
 *   self::handle_file_push() / handle_file_chunk() →
 *   writes file to local filesystem
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_File_Sync
 */
class DualPress_File_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Guard flag to prevent re-entrant queuing when receiving files locally
	 * triggers attachment hooks.
	 *
	 * @var bool
	 */
	private static $receiving = false;

	/**
	 * Check if this server should queue outbound changes.
	 *
	 * Returns false if:
	 * - Currently receiving files (prevents loops)
	 * - Server is secondary in active-passive mode
	 *
	 * @return bool True if should queue, false if should skip.
	 */
	private static function should_queue_changes() {
		if ( self::$receiving ) {
			return false;
		}

		// In active-passive mode, secondary servers don't push changes.
		if (
			'active-passive' === DualPress_Settings::get_sync_mode() &&
			'secondary' === DualPress_Settings::get_server_role()
		) {
			return false;
		}

		return true;
	}

	/**
	 * Chunk size for large-file transfers (1 MiB).
	 */
	const CHUNK_SIZE = 1048576;

	/**
	 * Returns the singleton and registers hooks.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/** Private constructor. */
	private function __construct() {}

	/**
	 * Register WordPress hooks (only when file sync is enabled).
	 *
	 * @return void
	 */
	private function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Attachment hooks (media uploads).
		add_action( 'add_attachment',    array( $this, 'on_file_added' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_attachment_metadata_generated' ), 10, 2 );
		add_action( 'edit_attachment',   array( $this, 'on_file_modified' ) );
		add_action( 'delete_attachment', array( $this, 'on_file_deleted' ), 10, 2 );

		// Plugin/Theme installation and activation hooks.
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
		add_action( 'activate_plugin',           array( $this, 'before_plugin_activated' ), 1 ); // Before activation.
		add_action( 'activated_plugin',          array( $this, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin',        array( $this, 'on_plugin_deactivated' ) );
		add_action( 'deleted_plugin',            array( $this, 'on_plugin_deleted' ), 10, 2 );
		add_action( 'switch_theme',              array( $this, 'on_theme_switched' ), 10, 3 );

		// WP-CLI hooks for plugin/theme operations.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( $this, 'register_cli_hooks' ) );
		}
	}

	/**
	 * Register WP-CLI specific hooks.
	 *
	 * @return void
	 */
	public function register_cli_hooks() {
		// Hook into WP-CLI's plugin/theme commands via filters.
		WP_CLI::add_hook( 'after_invoke:plugin install', array( $this, 'on_cli_plugin_install' ) );
		WP_CLI::add_hook( 'after_invoke:plugin update',  array( $this, 'on_cli_plugin_update' ) );
		WP_CLI::add_hook( 'after_invoke:plugin delete',  array( $this, 'on_cli_plugin_delete' ) );
		WP_CLI::add_hook( 'after_invoke:theme install',  array( $this, 'on_cli_theme_install' ) );
		WP_CLI::add_hook( 'after_invoke:theme update',   array( $this, 'on_cli_theme_update' ) );
		WP_CLI::add_hook( 'after_invoke:theme delete',   array( $this, 'on_cli_theme_delete' ) );
	}

	/**
	 * WP-CLI: After plugin install.
	 *
	 * @return void
	 */
	public function on_cli_plugin_install() {
		$this->sync_recently_modified_plugins();
	}

	/**
	 * WP-CLI: After plugin update.
	 *
	 * @return void
	 */
	public function on_cli_plugin_update() {
		$this->sync_recently_modified_plugins();
	}

	/**
	 * WP-CLI: After plugin delete — handled by deleted_plugin hook.
	 *
	 * @return void
	 */
	public function on_cli_plugin_delete() {
		// Deletion is handled by the 'deleted_plugin' action hook.
	}

	/**
	 * WP-CLI: After theme install.
	 *
	 * @return void
	 */
	public function on_cli_theme_install() {
		$this->sync_recently_modified_themes();
	}

	/**
	 * WP-CLI: After theme update.
	 *
	 * @return void
	 */
	public function on_cli_theme_update() {
		$this->sync_recently_modified_themes();
	}

	/**
	 * WP-CLI: After theme delete — handled by switch_theme or manual cleanup.
	 *
	 * @return void
	 */
	public function on_cli_theme_delete() {
		// Theme deletion sync would need delete_remote enabled.
	}

	/**
	 * Find and sync plugins modified in the last 60 seconds.
	 *
	 * @return void
	 */
	private function sync_recently_modified_plugins() {
		if ( ! (bool) DualPress_Settings::get( 'file_sync_plugins', false ) ) {
			return;
		}

		$now = time();
		$plugin_dirs = glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR );

		foreach ( $plugin_dirs as $dir ) {
			$mtime = $this->get_directory_mtime( $dir );
			if ( $mtime && ( $now - $mtime ) < 60 ) {
				$this->enqueue_directory( $dir );
				DualPress_Logger::info(
					'cli_plugin_sync',
					sprintf( 'WP-CLI: Syncing recently modified plugin: %s', basename( $dir ) )
				);
			}
		}
	}

	/**
	 * Find and sync themes modified in the last 60 seconds.
	 *
	 * @return void
	 */
	private function sync_recently_modified_themes() {
		if ( ! (bool) DualPress_Settings::get( 'file_sync_themes', false ) ) {
			return;
		}

		$now = time();
		$theme_dirs = glob( get_theme_root() . '/*', GLOB_ONLYDIR );

		foreach ( $theme_dirs as $dir ) {
			$mtime = $this->get_directory_mtime( $dir );
			if ( $mtime && ( $now - $mtime ) < 60 ) {
				$this->enqueue_directory( $dir );
				DualPress_Logger::info(
					'cli_theme_sync',
					sprintf( 'WP-CLI: Syncing recently modified theme: %s', basename( $dir ) )
				);
			}
		}
	}

	/**
	 * Get the most recent modification time of any file in a directory.
	 *
	 * @param string $dir Directory path.
	 * @return int|false Most recent mtime or false on error.
	 */
	private function get_directory_mtime( $dir ) {
		$latest = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$mtime = $file->getMTime();
					if ( $mtime > $latest ) {
						$latest = $mtime;
					}
				}
			}
		} catch ( Exception $e ) {
			return false;
		}

		return $latest ?: false;
	}

	// ------------------------------------------------------------------ //
	// Settings helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Whether file sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) DualPress_Settings::get( 'file_sync_enabled', false );
	}

	/**
	 * Max file size in bytes (derived from the MB setting).
	 *
	 * @return int
	 */
	public static function get_max_size_bytes() {
		$mb = (int) DualPress_Settings::get( 'file_sync_max_size', 100 );
		return max( 1, $mb ) * 1024 * 1024;
	}

	/**
	 * Absolute paths of directories that should be synced.
	 *
	 * @return string[]
	 */
	public static function get_sync_dirs() {
		$dirs       = array();
		$upload_dir = wp_upload_dir();

		if ( (bool) DualPress_Settings::get( 'file_sync_uploads', true ) ) {
			$dirs[] = $upload_dir['basedir'];
		}
		if ( (bool) DualPress_Settings::get( 'file_sync_themes', false ) ) {
			$dirs[] = get_theme_root();
		}
		if ( (bool) DualPress_Settings::get( 'file_sync_plugins', false ) ) {
			$dirs[] = WP_PLUGIN_DIR;
		}

		return $dirs;
	}

	// ------------------------------------------------------------------ //
	// Attachment hooks                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Fires when a new attachment is added.
	 *
	 * @param int $attachment_id Post ID of the new attachment.
	 * @return void
	 */
	public function on_file_added( $attachment_id ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}
		$this->enqueue_attachment( $attachment_id, 'PUSH' );
	}

	/**
	 * Fires after WordPress generates attachment metadata (thumbnails).
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Post ID.
	 * @return array Unchanged metadata.
	 */
	public function on_attachment_metadata_generated( $metadata, $attachment_id ) {
		if ( ! self::should_queue_changes() ) {
			return $metadata;
		}

		$attached_file = get_attached_file( $attachment_id );
		if ( empty( $attached_file ) ) {
			return $metadata;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = dirname( $attached_file );
		$max_bytes  = self::get_max_size_bytes();

		// Enqueue scaled image if exists (has '-scaled' in filename).
		if ( ! empty( $metadata['file'] ) && strpos( $metadata['file'], '-scaled' ) !== false ) {
			$scaled_path = trailingslashit( $upload_dir['basedir'] ) . $metadata['file'];
			if ( file_exists( $scaled_path ) && self::is_path_in_sync_scope( $scaled_path ) ) {
				$size    = (int) filesize( $scaled_path );
				$item_id = DualPress_File_Queue::enqueue(
					self::relative_path( $scaled_path ),
					'PUSH',
					$size,
					md5_file( $scaled_path )
				);
				if ( $size > $max_bytes && $item_id ) {
					DualPress_File_Queue::mark_skipped( $item_id, __( 'File exceeds maximum size limit.', 'dualpress' ) );
				}
			}
		}

		// Enqueue all generated thumbnail sizes.
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				$thumb_path = $base_dir . '/' . $size_data['file'];
				if ( file_exists( $thumb_path ) && self::is_path_in_sync_scope( $thumb_path ) ) {
					$size    = (int) filesize( $thumb_path );
					$item_id = DualPress_File_Queue::enqueue(
						self::relative_path( $thumb_path ),
						'PUSH',
						$size,
						md5_file( $thumb_path )
					);

					if ( $size > $max_bytes && $item_id ) {
						DualPress_File_Queue::mark_skipped( $item_id, __( 'File exceeds maximum size limit.', 'dualpress' ) );
					}
				}
			}
		}

		return $metadata;
	}

	/**
	 * Fires when an attachment is edited/updated.
	 *
	 * @param int $attachment_id Post ID.
	 * @return void
	 */
	public function on_file_modified( $attachment_id ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}
		$this->enqueue_attachment( $attachment_id, 'PUSH' );
	}

	/**
	 * Fires just before an attachment is deleted.
	 *
	 * @param int     $attachment_id Post ID.
	 * @param WP_Post $post          Post object.
	 * @return void
	 */
	public function on_file_deleted( $attachment_id, $post ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}
		if ( ! (bool) DualPress_Settings::get( 'file_sync_delete_remote', false ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		DualPress_File_Queue::enqueue( self::relative_path( $file ), 'DELETE', 0, '' );
	}

	/**
	 * Resolve attachment path and add to queue.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $action        'PUSH' or 'DELETE'.
	 * @return void
	 */
	private function enqueue_attachment( $attachment_id, $action ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}
		if ( ! self::is_path_in_sync_scope( $file ) ) {
			return;
		}

		$size      = (int) filesize( $file );
		$max_bytes = self::get_max_size_bytes();

		$item_id = DualPress_File_Queue::enqueue(
			self::relative_path( $file ),
			$action,
			$size,
			md5_file( $file )
		);

		if ( $size > $max_bytes && $item_id ) {
			DualPress_File_Queue::mark_skipped( $item_id, __( 'File exceeds maximum size limit.', 'dualpress' ) );
			DualPress_Logger::info(
				'file_sync_skipped',
				sprintf( 'File skipped (too large): %s (%d bytes)', $file, $size )
			);
		}
	}

	// ------------------------------------------------------------------ //
	// Plugin/Theme hooks                                                   //
	// ------------------------------------------------------------------ //

	/**
	 * Fires after a plugin or theme is installed/updated via the upgrader.
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array       $options  Array of bulk item update data.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, $options ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		$type   = isset( $options['type'] ) ? $options['type'] : '';
		$action = isset( $options['action'] ) ? $options['action'] : '';

		// Handle plugin install/update.
		if ( 'plugin' === $type && (bool) DualPress_Settings::get( 'file_sync_plugins', false ) ) {
			$plugins = array();

			if ( 'install' === $action && isset( $upgrader->result['destination_name'] ) ) {
				$plugins[] = $upgrader->result['destination_name'];
			} elseif ( isset( $options['plugins'] ) ) {
				$plugins = $options['plugins'];
			} elseif ( isset( $options['plugin'] ) ) {
				$plugins[] = $options['plugin'];
			}

			foreach ( $plugins as $plugin ) {
				$plugin_slug = dirname( $plugin );
				if ( '.' === $plugin_slug ) {
					$plugin_slug = basename( $plugin, '.php' );
				}
				$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

				// Disable plugin on remote before syncing (prevents partial load crash).
				self::remote_plugin_control( $plugin_slug, 'prepare', 'plugin' );

				// Queue all files.
				$file_count = $this->enqueue_directory( $plugin_dir );

				// Schedule re-enable after queue is processed.
				if ( $file_count > 0 ) {
					self::schedule_plugin_enable( $plugin_slug, 'plugin', $file_count );
				}
			}
		}

		// Handle theme install/update.
		if ( 'theme' === $type && (bool) DualPress_Settings::get( 'file_sync_themes', false ) ) {
			$themes = array();

			if ( 'install' === $action && isset( $upgrader->result['destination_name'] ) ) {
				$themes[] = $upgrader->result['destination_name'];
			} elseif ( isset( $options['themes'] ) ) {
				$themes = $options['themes'];
			} elseif ( isset( $options['theme'] ) ) {
				$themes[] = $options['theme'];
			}

			foreach ( $themes as $theme_slug ) {
				$theme_dir = get_theme_root() . '/' . $theme_slug;

				// Disable theme on remote before syncing.
				self::remote_plugin_control( $theme_slug, 'prepare', 'theme' );

				// Queue all files.
				$file_count = $this->enqueue_directory( $theme_dir );

				// Schedule re-enable after queue is processed.
				if ( $file_count > 0 ) {
					self::schedule_plugin_enable( $theme_slug, 'theme', $file_count );
				}
			}
		}
	}

	/**
	 * Snapshot of tables before plugin activation.
	 *
	 * @var array
	 */
	private static $tables_before_activation = array();

	/**
	 * Plugin being activated (for table sync).
	 *
	 * @var string
	 */
	private static $activating_plugin = '';

	/**
	 * Fires BEFORE a plugin is activated — take table snapshot.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory.
	 * @return void
	 */
	public function before_plugin_activated( $plugin ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		// Take snapshot of tables before activation.
		if ( class_exists( 'DualPress_Table_Sync' ) ) {
			self::$tables_before_activation = DualPress_Table_Sync::snapshot_tables();
			self::$activating_plugin = $plugin;
		}
	}

	/**
	 * Fires when a plugin is activated.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory.
	 * @return void
	 */
	public function on_plugin_activated( $plugin ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		// If file sync for plugins is enabled, FINALIZE will handle activation after files are synced.
		// Only send immediate activation if file sync is OFF (files already exist on remote).
		if ( ! (bool) DualPress_Settings::get( 'file_sync_plugins', false ) ) {
			// File sync off — just sync the activation state.
			$this->send_plugin_activation( $plugin );
			return;
		}

		$plugin_slug = dirname( $plugin );
		$is_single_file = ( '.' === $plugin_slug );
		if ( $is_single_file ) {
			$plugin_slug = basename( $plugin, '.php' );
		}
		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

		// Sync any new tables created by the plugin.
		if ( class_exists( 'DualPress_Table_Sync' ) && ! empty( self::$tables_before_activation ) ) {
			$table_result = DualPress_Table_Sync::sync_new_tables( self::$tables_before_activation, $plugin_slug );
			if ( ! empty( $table_result['new_tables'] ) ) {
				DualPress_Logger::info(
					'tables_synced',
					sprintf( 'Synced %d new tables for %s: %s',
						$table_result['synced'],
						$plugin_slug,
						implode( ', ', $table_result['new_tables'] )
					)
				);
			}
			self::$tables_before_activation = array();
		}

		// Queue files.
		if ( $is_single_file ) {
			// Single-file plugin: queue just the file.
			$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
			if ( file_exists( $plugin_file ) ) {
				$rel_path = self::relative_path( $plugin_file );
				$file_count = 1;
				DualPress_File_Queue::enqueue( $rel_path, 'PUSH', filesize( $plugin_file ), '' );
			} else {
				$file_count = 0;
			}
		} else {
			// Directory plugin: queue all files.
			$file_count = $this->enqueue_directory( $plugin_dir );
		}

		// Queue FINALIZE command after all files (will be processed last).
		if ( $file_count > 0 ) {
			DualPress_File_Queue::enqueue_finalize( 'plugin', $plugin_slug );
		}

		DualPress_Logger::info(
			'plugin_activated_sync',
			sprintf( 'Plugin activated, queued for sync: %s (%d files)', $plugin, $file_count )
		);
	}

	/**
	 * Fires when a plugin is deactivated.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory.
	 * @return void
	 */
	public function on_plugin_deactivated( $plugin ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		// Send deactivation request to remote.
		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/plugin-control';
		$payload = wp_json_encode( array(
			'action' => 'deactivate',
			'plugin' => $plugin,
		) );

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
			DualPress_Logger::warning(
				'plugin_deactivate_sync_error',
				sprintf( 'Failed to sync plugin deactivation: %s - %s', $plugin, $response->get_error_message() )
			);
		} else {
			DualPress_Logger::info(
				'plugin_deactivated_sync',
				sprintf( 'Plugin deactivated and synced: %s', $plugin )
			);
		}
	}

	/**
	 * Send plugin activation request to remote.
	 *
	 * @param string $plugin Plugin path (e.g., "redirection/redirection.php").
	 * @return void
	 */
	private function send_plugin_activation( $plugin ) {
		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/plugin-control';
		$payload = wp_json_encode( array(
			'action' => 'activate',
			'plugin' => $plugin,
		) );

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
			DualPress_Logger::warning(
				'plugin_activate_sync_error',
				sprintf( 'Failed to sync plugin activation: %s - %s', $plugin, $response->get_error_message() )
			);
		} else {
			DualPress_Logger::info(
				'plugin_activated_sync',
				sprintf( 'Plugin activation synced: %s', $plugin )
			);
		}
	}

	/**
	 * Fires after a plugin is deleted.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory.
	 * @param bool   $deleted Whether the plugin deletion was successful.
	 * @return void
	 */
	public function on_plugin_deleted( $plugin, $deleted ) {
		if ( ! self::should_queue_changes() || ! $deleted ) {
			return;
		}
		if ( ! (bool) DualPress_Settings::get( 'file_sync_plugins', false ) ) {
			return;
		}
		if ( ! (bool) DualPress_Settings::get( 'file_sync_delete_remote', false ) ) {
			return;
		}

		$plugin_slug = dirname( $plugin );
		if ( '.' === $plugin_slug ) {
			$plugin_slug = basename( $plugin, '.php' );
		}

		// Queue delete for the plugin directory.
		$rel_path = 'wp-content/plugins/' . $plugin_slug;
		DualPress_File_Queue::enqueue( $rel_path, 'DELETE', 0, '' );

		DualPress_Logger::info(
			'plugin_deleted_sync',
			sprintf( 'Plugin deleted, queued delete sync: %s', $plugin )
		);
	}

	/**
	 * Fires when the active theme is switched.
	 *
	 * @param string   $new_name  Name of the new theme.
	 * @param WP_Theme $new_theme WP_Theme instance of the new theme.
	 * @param WP_Theme $old_theme WP_Theme instance of the old theme.
	 * @return void
	 */
	public function on_theme_switched( $new_name, $new_theme, $old_theme ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}
		if ( ! (bool) DualPress_Settings::get( 'file_sync_themes', false ) ) {
			return;
		}

		$theme_dir = $new_theme->get_stylesheet_directory();
		$this->enqueue_directory( $theme_dir );

		// Also sync parent theme if this is a child theme.
		if ( $new_theme->parent() ) {
			$parent_dir = $new_theme->parent()->get_stylesheet_directory();
			$this->enqueue_directory( $parent_dir );
		}

		DualPress_Logger::info(
			'theme_switched_sync',
			sprintf( 'Theme switched to %s, queued for sync', $new_name )
		);
	}

	/**
	 * Recursively scan a directory and enqueue all files for sync.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return int Number of files enqueued.
	 */
	private function enqueue_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$max_bytes = self::get_max_size_bytes();
		$count     = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$abs_path = $file_info->getPathname();
				$size     = (int) $file_info->getSize();
				$rel_path = self::relative_path( $abs_path );

				$item_id = DualPress_File_Queue::enqueue(
					$rel_path,
					'PUSH',
					$size,
					md5_file( $abs_path )
				);

				if ( $size > $max_bytes && $item_id ) {
					DualPress_File_Queue::mark_skipped(
						$item_id,
						__( 'File exceeds maximum size limit.', 'dualpress' )
					);
				} else {
					$count++;
				}
			}
		} catch ( Exception $e ) {
			DualPress_Logger::warning(
				'directory_scan_failed',
				sprintf( 'Failed to scan directory %s: %s', $dir, $e->getMessage() )
			);
		}

		return $count;
	}

	// ------------------------------------------------------------------ //
	// Remote plugin control (safe sync)                                    //
	// ------------------------------------------------------------------ //

	/**
	 * Send a plugin control command to the remote server.
	 *
	 * @param string $slug   Plugin or theme slug.
	 * @param string $action 'disable' or 'enable'.
	 * @param string $type   'plugin' or 'theme'.
	 * @return true|WP_Error
	 */
	public static function remote_plugin_control( $slug, $action, $type = 'plugin' ) {
		if ( ! DualPress_Settings::is_configured() ) {
			return new WP_Error( 'not_configured', 'DualPress not configured' );
		}

		$endpoint = rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/plugin-control';

		$payload = wp_json_encode( array(
			'action' => $action,
			'plugin' => $slug,
			'type'   => $type,
		) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => DualPress_Auth::build_headers( $payload ),
				'body'      => $payload,
				'timeout'   => 30,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			DualPress_Logger::warning(
				'plugin_control_failed',
				sprintf( 'Failed to %s %s %s: %s', $action, $type, $slug, $response->get_error_message() )
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			DualPress_Logger::warning(
				'plugin_control_failed',
				sprintf( 'Failed to %s %s %s: HTTP %d - %s', $action, $type, $slug, $code, $body )
			);
			return new WP_Error( 'http_error', sprintf( 'HTTP %d', $code ) );
		}

		DualPress_Logger::info(
			'plugin_control_success',
			sprintf( 'Remote %s %sd: %s', $type, $action, $slug )
		);

		return true;
	}

	/**
	 * Schedule a plugin/theme to be re-enabled after sync completes.
	 *
	 * Stores pending re-enables in an option. The cron job checks this after
	 * processing the queue and re-enables when all files are synced.
	 *
	 * @param string $slug       Plugin or theme slug.
	 * @param string $type       'plugin' or 'theme'.
	 * @param int    $file_count Number of files queued for this plugin/theme.
	 * @return void
	 */
	public static function schedule_plugin_enable( $slug, $type, $file_count ) {
		$pending = get_option( 'dualpress_pending_enables', array() );

		$pending[ $slug ] = array(
			'type'        => $type,
			'file_count'  => $file_count,
			'queued_at'   => time(),
			'prefix'      => 'wp-content/' . ( 'theme' === $type ? 'themes' : 'plugins' ) . '/' . $slug,
		);

		update_option( 'dualpress_pending_enables', $pending, false );

		DualPress_Logger::info(
			'plugin_enable_scheduled',
			sprintf( 'Scheduled re-enable for %s %s after %d files sync', $type, $slug, $file_count )
		);
	}

	/**
	 * Check if any pending plugins/themes can be re-enabled.
	 *
	 * Called after process_queue() completes. Checks if all files for a
	 * plugin/theme have been synced, then sends enable command.
	 *
	 * @return void
	 */
	public static function check_pending_enables() {
		$pending = get_option( 'dualpress_pending_enables', array() );

		if ( empty( $pending ) ) {
			return;
		}

		global $wpdb;
		$table = DualPress_File_Queue::table();

		foreach ( $pending as $slug => $info ) {
			$prefix = $info['prefix'];

			// Count remaining pending/processing files for this plugin/theme.
			$remaining = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE file_path LIKE %s AND status IN ('pending', 'processing')",
				$prefix . '%'
			) );

			if ( 0 === $remaining ) {
				// All files synced — re-enable on remote.
				$result = self::remote_plugin_control( $slug, 'finalize', $info['type'] );

				if ( ! is_wp_error( $result ) ) {
					unset( $pending[ $slug ] );
					update_option( 'dualpress_pending_enables', $pending, false );

					DualPress_Logger::info(
						'plugin_enabled_after_sync',
						sprintf( '%s %s re-enabled after sync completed', ucfirst( $info['type'] ), $slug )
					);
				}
			}
		}
	}

	// ------------------------------------------------------------------ //
	// Outbound: push queue to remote                                       //
	// ------------------------------------------------------------------ //

	/**
	 * Process the file queue — push pending files to the remote server.
	 *
	 * Uses adaptive batching: fetches more small files, fewer large files,
	 * based on total byte budget per batch.
	 *
	 * Called by WP-Cron or manually via the admin Tools tab.
	 *
	 * @param int $batch_size Max number of items (used as upper limit).
	 * @return array{ pushed: int, failed: int }
	 */
	public static function process_queue( $batch_size = 500 ) {
		if ( ! self::is_enabled() || ! DualPress_Settings::is_configured() ) {
			return array( 'pushed' => 0, 'failed' => 0 );
		}

		// Get transfer settings.
		$bundle_mb    = (int) DualPress_Settings::get( 'file_sync_bundle_mb', 10 );
		$bundle_bytes = $bundle_mb * 1024 * 1024;

		// Adaptive batching: use configured bundle_mb for batch selection.
		$items = self::get_adaptive_batch( $batch_size, $bundle_bytes );

		$pushed = 0;
		$failed = 0;

		// Separate items by type: bundles, deletes, and finalize commands.
		$bundle_items = array();
		$delete_items = array();
		$finalize_items = array();

		foreach ( $items as $item ) {
			if ( 'DELETE' === $item['action'] ) {
				$delete_items[] = $item;
			} elseif ( 'FINALIZE' === $item['action'] ) {
				$finalize_items[] = $item;
			} else {
				$bundle_items[] = $item;
			}
		}

		// Process files in bundles (up to configured MB).
		$bundle = array();
		$bundle_size = 0;

		global $wpdb;

		foreach ( $bundle_items as $item ) {
			$size = (int) $item['file_size'];

			// If bundle is full (reached size limit), send it.
			if ( $bundle_size + $size > $bundle_bytes && count( $bundle ) > 0 ) {
				$result = self::push_file_bundle( $bundle );
				if ( is_wp_error( $result ) ) {
					foreach ( $bundle as $b ) {
						DualPress_File_Queue::mark_failed( $b['id'], $result->get_error_message() );
						$failed++;
					}
					if ( strpos( $result->get_error_message(), '429' ) !== false ) {
						usleep( 500000 );
					}
				} else {
					foreach ( $bundle as $b ) {
						DualPress_File_Queue::mark_completed( $b['id'] );
						$pushed++;
					}
				}
				$bundle = array();
				$bundle_size = 0;
			}

			// Mark as processing and add to bundle.
			$wpdb->update(
				DualPress_File_Queue::table(),
				array( 'status' => 'processing' ),
				array( 'id' => (int) $item['id'] )
			);
			$bundle[] = $item;
			$bundle_size += $size;
		}

		// Send remaining bundle.
		if ( ! empty( $bundle ) ) {
			$result = self::push_file_bundle( $bundle );
			if ( is_wp_error( $result ) ) {
				foreach ( $bundle as $b ) {
					DualPress_File_Queue::mark_failed( $b['id'], $result->get_error_message() );
					$failed++;
				}
			} else {
				foreach ( $bundle as $b ) {
					DualPress_File_Queue::mark_completed( $b['id'] );
					$pushed++;
				}
			}
		}

		// Process delete actions one by one.
		foreach ( $delete_items as $item ) {
			$wpdb->update(
				DualPress_File_Queue::table(),
				array( 'status' => 'processing' ),
				array( 'id' => (int) $item['id'] )
			);

			$result = self::push_delete( $item['file_path'] );

			if ( is_wp_error( $result ) ) {
				DualPress_File_Queue::mark_failed( $item['id'], $result->get_error_message() );
				$failed++;
			} else {
				DualPress_File_Queue::mark_completed( $item['id'] );
				$pushed++;
			}
		}

		// FINALIZE commands run ONLY when there are no other pending items.
		// They are processed in a separate batch, always last.
		if ( ! empty( $finalize_items ) && empty( $bundle_items ) && empty( $delete_items ) ) {
			// No regular files left — safe to process FINALIZE.
			// But first check if there are ANY other pending items in the entire queue.
			$other_pending = $wpdb->get_var(
				"SELECT COUNT(*) FROM " . DualPress_File_Queue::table() . "
				 WHERE action != 'FINALIZE'
				   AND status IN ('pending', 'processing')"
			);

			if ( (int) $other_pending === 0 ) {
				// Process all FINALIZE commands.
				foreach ( $finalize_items as $item ) {
					$wpdb->update(
						DualPress_File_Queue::table(),
						array( 'status' => 'processing' ),
						array( 'id' => (int) $item['id'] )
					);

					$result = self::push_finalize( $item['file_path'] );

					if ( is_wp_error( $result ) ) {
						DualPress_File_Queue::mark_failed( $item['id'], $result->get_error_message() );
						$failed++;
					} else {
						DualPress_File_Queue::mark_completed( $item['id'] );
						$pushed++;
					}
				}
			}
			// If other_pending > 0, FINALIZE stays pending for next batch.
		}

		return array( 'pushed' => $pushed, 'failed' => $failed );
	}

	/**
	 * Get an adaptive batch of files based on total byte budget.
	 *
	 * Fetches small files first (more efficient), then fills remaining budget
	 * with larger files. This allows syncing many small files quickly while
	 * still handling large files appropriately.
	 *
	 * @param int $max_items Maximum number of items.
	 * @param int $max_bytes Maximum total bytes for the batch.
	 * @return array Array of queue items.
	 */
	private static function get_adaptive_batch( $max_items, $max_bytes ) {
		global $wpdb;
		$table = DualPress_File_Queue::table();

		// First, get small files (under 50KB) — these can be batched aggressively.
		$small_files = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} 
				 WHERE status = 'pending' AND file_size < 51200 
				 ORDER BY file_size ASC 
				 LIMIT %d",
				$max_items
			),
			ARRAY_A
		);

		$batch = array();
		$total_bytes = 0;

		foreach ( $small_files as $item ) {
			if ( count( $batch ) >= $max_items || $total_bytes >= $max_bytes ) {
				break;
			}
			$batch[] = $item;
			$total_bytes += (int) $item['file_size'];
		}

		// If we have room, add medium/large files.
		$remaining_items = $max_items - count( $batch );
		$remaining_bytes = $max_bytes - $total_bytes;

		if ( $remaining_items > 0 && $remaining_bytes > 0 ) {
			$large_files = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} 
					 WHERE status = 'pending' AND file_size >= 51200 
					 ORDER BY file_size ASC 
					 LIMIT %d",
					$remaining_items
				),
				ARRAY_A
			);

			foreach ( $large_files as $item ) {
				$size = (int) $item['file_size'];
				if ( $total_bytes + $size > $max_bytes && count( $batch ) > 0 ) {
					// Would exceed budget, but allow at least one large file if batch is empty.
					continue;
				}
				if ( count( $batch ) >= $max_items ) {
					break;
				}
				$batch[] = $item;
				$total_bytes += $size;
			}
		}

		return $batch;
	}

	/**
	 * Scan configured sync directories and enqueue every file for initial sync.
	 *
	 * Large files are enqueued and immediately marked as skipped.
	 *
	 * @return int Total files enqueued (excluding skipped).
	 */
	public static function scan_for_initial_sync() {
		$dirs      = self::get_sync_dirs();
		$max_bytes = self::get_max_size_bytes();
		$total     = 0;

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$abs_path = $file_info->getPathname();
				$size     = (int) $file_info->getSize();
				$rel_path = self::relative_path( $abs_path );

				$item_id = DualPress_File_Queue::enqueue( $rel_path, 'PUSH', $size, '' );

				if ( $size > $max_bytes && $item_id ) {
					DualPress_File_Queue::mark_skipped( $item_id, __( 'File exceeds maximum size limit.', 'dualpress' ) );
				} else {
					$total++;
				}
			}
		}

		return $total;
	}

	/**
	 * Push multiple files in a single compressed HTTP request.
	 *
	 * Creates a gzip-compressed bundle of files for efficient transfer.
	 *
	 * @param array $items Array of queue items.
	 * @return true|WP_Error
	 */
	private static function push_file_bundle( $items ) {
		if ( empty( $items ) ) {
			return true;
		}

		$endpoint = self::remote_endpoint( 'file-bundle' );
		$files = array();

		foreach ( $items as $item ) {
			$abs_path = self::absolute_path( $item['file_path'] );
			if ( ! file_exists( $abs_path ) ) {
				continue;
			}

			$files[] = array(
				'path'     => $item['file_path'],
				'content'  => base64_encode( file_get_contents( $abs_path ) ),
				'checksum' => md5_file( $abs_path ),
				'modified' => (int) filemtime( $abs_path ),
				'size'     => (int) filesize( $abs_path ),
			);
		}

		if ( empty( $files ) ) {
			return true;
		}

		// Create JSON payload.
		$json_payload = wp_json_encode( array( 'files' => $files ) );

		// Check if compression is enabled.
		$use_compression = (bool) DualPress_Settings::get( 'file_sync_compress', true );

		if ( $use_compression ) {
			$compressed = gzencode( $json_payload, 6 ); // Level 6 = good balance.
			if ( false !== $compressed ) {
				return self::do_post_compressed( $endpoint, $compressed, 180 );
			}
		}

		// Send uncompressed.
		return self::do_post( $endpoint, $json_payload, 180 );
	}

	/**
	 * Send a gzip-compressed POST request.
	 *
	 * @param string $url      Endpoint URL.
	 * @param string $data     Gzip-compressed data.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return true|WP_Error
	 */
	private static function do_post_compressed( $url, $data, $timeout = 60 ) {
		$headers = DualPress_Auth::build_headers( $data );
		$headers['Content-Encoding'] = 'gzip';
		$headers['Content-Type'] = 'application/octet-stream';

		$response = wp_remote_post(
			$url,
			array(
				'headers'   => $headers,
				'body'      => $data,
				'timeout'   => $timeout,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			$msg  = sprintf( 'Remote returned HTTP %d. %s', $code, wp_strip_all_tags( $body ) );
			return new WP_Error( 'dualpress_remote_error', $msg );
		}

		return true;
	}

	/**
	 * Send multiple bundles in parallel using curl_multi.
	 *
	 * @param array $bundles Array of bundles, each bundle is array of items.
	 * @return array Results indexed by bundle index: true or WP_Error.
	 */
	private static function push_bundles_parallel( $bundles ) {
		if ( empty( $bundles ) || ! function_exists( 'curl_multi_init' ) ) {
			// Fallback to sequential if curl_multi not available.
			$results = array();
			foreach ( $bundles as $i => $bundle ) {
				$results[ $i ] = self::push_file_bundle( $bundle );
			}
			return $results;
		}

		$endpoint = self::remote_endpoint( 'file-bundle' );
		$sslverify = apply_filters( 'dualpress_sslverify', true );

		$mh = curl_multi_init();
		$handles = array();
		$payloads = array();

		foreach ( $bundles as $i => $bundle ) {
			// Build payload for this bundle.
			$files = array();
			foreach ( $bundle as $item ) {
				$abs_path = self::absolute_path( $item['file_path'] );
				if ( ! file_exists( $abs_path ) ) {
					continue;
				}
				$files[] = array(
					'path'     => $item['file_path'],
					'content'  => base64_encode( file_get_contents( $abs_path ) ),
					'checksum' => md5_file( $abs_path ),
					'modified' => (int) filemtime( $abs_path ),
					'size'     => (int) filesize( $abs_path ),
				);
			}

			if ( empty( $files ) ) {
				continue;
			}

			$json = wp_json_encode( array( 'files' => $files ) );
			$compressed = gzencode( $json, 6 );
			if ( false === $compressed ) {
				$compressed = $json;
			}
			$payloads[ $i ] = $compressed;

			// Build headers with auth.
			$headers = DualPress_Auth::build_headers( $compressed );
			$headers['Content-Encoding'] = 'gzip';
			$headers['Content-Type'] = 'application/octet-stream';

			$header_lines = array();
			foreach ( $headers as $k => $v ) {
				$header_lines[] = "$k: $v";
			}

			$ch = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL            => $endpoint,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $compressed,
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 180,
				CURLOPT_SSL_VERIFYPEER => $sslverify,
				CURLOPT_SSL_VERIFYHOST => $sslverify ? 2 : 0,
				CURLOPT_USERAGENT      => 'DualPress/' . DUALPRESS_VERSION,
			) );

			curl_multi_add_handle( $mh, $ch );
			$handles[ $i ] = $ch;
		}

		// Execute all requests in parallel.
		$running = null;
		do {
			curl_multi_exec( $mh, $running );
			curl_multi_select( $mh );
		} while ( $running > 0 );

		// Collect results.
		$results = array();
		foreach ( $handles as $i => $ch ) {
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error = curl_error( $ch );
			$body = curl_multi_getcontent( $ch );

			if ( $error ) {
				$results[ $i ] = new WP_Error( 'curl_error', $error );
			} elseif ( 200 !== (int) $http_code ) {
				$results[ $i ] = new WP_Error( 'http_error', "HTTP $http_code: $body" );
			} else {
				$results[ $i ] = true;
			}

			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $mh );

		return $results;
	}

	/**
	 * Dispatch a single queued file item to the remote server.
	 *
	 * Uses simple single-request transfer for files ≤ CHUNK_SIZE,
	 * chunked upload for larger files.
	 *
	 * @param array $item Queue row as associative array.
	 * @return true|WP_Error
	 */
	private static function push_file_item( $item ) {
		$abs_path = self::absolute_path( $item['file_path'] );

		if ( ! file_exists( $abs_path ) ) {
			return new WP_Error(
				'dualpress_file_not_found',
				sprintf( 'File not found: %s', $item['file_path'] )
			);
		}

		$size = (int) filesize( $abs_path );

		if ( $size <= self::CHUNK_SIZE ) {
			return self::push_file_simple( $item['file_path'], $abs_path );
		}

		return self::push_file_chunked( $item['file_path'], $abs_path );
	}

	/**
	 * Transfer a small file in a single POST request.
	 *
	 * @param string $rel_path Relative file path.
	 * @param string $abs_path Absolute file path.
	 * @return true|WP_Error
	 */
	private static function push_file_simple( $rel_path, $abs_path ) {
		$endpoint = self::remote_endpoint( 'file-push' );

		$payload = wp_json_encode(
			array(
				'path'     => $rel_path,
				'checksum' => md5_file( $abs_path ),
				'modified' => (int) filemtime( $abs_path ),
				'size'     => (int) filesize( $abs_path ),
				'content'  => base64_encode( file_get_contents( $abs_path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			)
		);

		return self::do_post( $endpoint, $payload, 60 );
	}

	/**
	 * Transfer a large file in 1 MiB chunks.
	 *
	 * @param string $rel_path Relative file path.
	 * @param string $abs_path Absolute file path.
	 * @return true|WP_Error
	 */
	private static function push_file_chunked( $rel_path, $abs_path ) {
		$endpoint     = self::remote_endpoint( 'file-chunk' );
		$upload_id    = wp_generate_uuid4();
		$file_size    = (int) filesize( $abs_path );
		$total_chunks = (int) ceil( $file_size / self::CHUNK_SIZE );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$fh = fopen( $abs_path, 'rb' );
		if ( ! $fh ) {
			return new WP_Error( 'dualpress_file_open', sprintf( 'Cannot open: %s', $rel_path ) );
		}

		for ( $i = 0; $i < $total_chunks; $i++ ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fread
			$chunk = fread( $fh, self::CHUNK_SIZE );

			$payload = wp_json_encode(
				array(
					'path'         => $rel_path,
					'chunk_index'  => $i,
					'total_chunks' => $total_chunks,
					'upload_id'    => $upload_id,
					'content'      => base64_encode( $chunk ),
					'checksum'     => ( $i === $total_chunks - 1 ) ? md5_file( $abs_path ) : '',
				)
			);

			$result = self::do_post( $endpoint, $payload, 120 );
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
				fclose( $fh );
				return $result;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $fh );
		return true;
	}

	/**
	 * Push a delete instruction to the remote server.
	 *
	 * @param string $rel_path Relative file path.
	 * @return true|WP_Error
	 */
	private static function push_delete( $rel_path ) {
		$endpoint = self::remote_endpoint( 'file-push' );

		$payload = wp_json_encode(
			array(
				'path'   => $rel_path,
				'delete' => true,
			)
		);

		return self::do_post( $endpoint, $payload, 30 );
	}

	/**
	 * Push a FINALIZE command to the remote server.
	 *
	 * Tells remote to move files from staging to final location.
	 *
	 * @param string $finalize_path Path in format "FINALIZE:type:slug".
	 * @return true|WP_Error
	 */
	private static function push_finalize( $finalize_path ) {
		// Parse: FINALIZE:plugin:slug or FINALIZE:theme:slug
		$parts = explode( ':', $finalize_path );
		if ( count( $parts ) !== 3 || 'FINALIZE' !== $parts[0] ) {
			return new WP_Error( 'invalid_finalize', 'Invalid finalize path format' );
		}

		$type = $parts[1];
		$slug = $parts[2];

		$endpoint = self::remote_endpoint( 'finalize' );

		$payload = wp_json_encode( array(
			'type' => $type,
			'slug' => $slug,
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
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'finalize_failed', sprintf( 'HTTP %d: %s', $code, $body ) );
		}

		DualPress_Logger::info(
			'finalize_sent',
			sprintf( 'Finalize command sent for %s: %s', $type, $slug )
		);

		return true;
	}

	// ------------------------------------------------------------------ //
	// Inbound: receive files from remote                                   //
	// ------------------------------------------------------------------ //

	/**
	 * REST callback: POST /dualpress/v1/file-bundle
	 *
	 * Receives multiple files in a single request, optionally gzip-compressed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_file_bundle( WP_REST_Request $request ) {
		$raw_body = $request->get_body();

		// Check if content is gzip-compressed.
		$content_encoding = $request->get_header( 'Content-Encoding' );
		if ( 'gzip' === $content_encoding ) {
			$decompressed = @gzdecode( $raw_body );
			if ( false === $decompressed ) {
				return new WP_Error( 'dualpress_decompress_failed', __( 'Failed to decompress gzip payload.', 'dualpress' ), array( 'status' => 400 ) );
			}
			$raw_body = $decompressed;
		}

		$body = json_decode( $raw_body, true );

		if ( ! is_array( $body ) || empty( $body['files'] ) || ! is_array( $body['files'] ) ) {
			return new WP_Error( 'dualpress_invalid_payload', __( 'Missing files array.', 'dualpress' ), array( 'status' => 400 ) );
		}

		self::$receiving = true;
		$success = 0;
		$errors = array();

		foreach ( $body['files'] as $file ) {
			if ( empty( $file['path'] ) || empty( $file['content'] ) ) {
				continue;
			}

			$rel_path = sanitize_text_field( $file['path'] );

			if ( ! self::is_safe_path( $rel_path ) ) {
				$errors[] = sprintf( 'Invalid path: %s', $rel_path );
				continue;
			}

			$content = base64_decode( $file['content'] );
			if ( false === $content ) {
				$errors[] = sprintf( 'Decode failed: %s', $rel_path );
				continue;
			}

			if ( ! empty( $file['checksum'] ) && md5( $content ) !== $file['checksum'] ) {
				$errors[] = sprintf( 'Checksum mismatch: %s', $rel_path );
				continue;
			}

			$abs_path = self::absolute_path_with_syncing( $rel_path );
			wp_mkdir_p( dirname( $abs_path ) );

			if ( false === file_put_contents( $abs_path, $content ) ) {
				$errors[] = sprintf( 'Write failed: %s', $rel_path );
				continue;
			}

			if ( ! empty( $file['modified'] ) ) {
				@touch( $abs_path, (int) $file['modified'] );
			}

			$success++;
		}

		self::$receiving = false;

		DualPress_Logger::info(
			'file_bundle_received',
			sprintf( 'Bundle received: %d files written, %d errors', $success, count( $errors ) )
		);

		return new WP_REST_Response(
			array( 'success' => true, 'written' => $success, 'errors' => $errors ),
			200
		);
	}

	/**
	 * REST callback: POST /dualpress/v1/file-push
	 *
	 * Accepts a full file (base64 encoded) or a delete instruction.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_file_push( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) || empty( $body['path'] ) ) {
			return new WP_Error( 'dualpress_invalid_payload', __( 'Missing path.', 'dualpress' ), array( 'status' => 400 ) );
		}

		$rel_path = sanitize_text_field( $body['path'] );

		if ( ! self::is_safe_path( $rel_path ) ) {
			return new WP_Error( 'dualpress_invalid_path', __( 'Path is not allowed.', 'dualpress' ), array( 'status' => 400 ) );
		}

		// Delete instruction.
		if ( ! empty( $body['delete'] ) ) {
			return self::receive_delete( $rel_path );
		}

		if ( empty( $body['content'] ) ) {
			return new WP_Error( 'dualpress_missing_content', __( 'Missing file content.', 'dualpress' ), array( 'status' => 400 ) );
		}

		return self::receive_file( $rel_path, $body );
	}

	/**
	 * REST callback: POST /dualpress/v1/file-chunk
	 *
	 * Accepts one chunk of a large file. Assembles when the final chunk arrives.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_file_chunk( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body )
			|| empty( $body['path'] )
			|| ! isset( $body['chunk_index'] )
			|| empty( $body['total_chunks'] )
			|| empty( $body['upload_id'] )
			|| ! isset( $body['content'] )
		) {
			return new WP_Error( 'dualpress_invalid_payload', __( 'Invalid chunk payload.', 'dualpress' ), array( 'status' => 400 ) );
		}

		$rel_path     = sanitize_text_field( $body['path'] );
		$chunk_index  = (int) $body['chunk_index'];
		$total_chunks = (int) $body['total_chunks'];
		$upload_id    = sanitize_key( $body['upload_id'] );

		if ( ! self::is_safe_path( $rel_path ) ) {
			return new WP_Error( 'dualpress_invalid_path', __( 'Path is not allowed.', 'dualpress' ), array( 'status' => 400 ) );
		}

		// Persist the chunk to a temp location.
		$chunks_dir  = self::get_chunks_dir() . '/' . $upload_id;
		wp_mkdir_p( $chunks_dir );
		$chunk_file = $chunks_dir . '/chunk_' . $chunk_index;

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $chunk_file, base64_decode( $body['content'] ) );

		// Assemble on the final chunk.
		if ( $chunk_index === $total_chunks - 1 ) {
			$checksum = isset( $body['checksum'] ) ? (string) $body['checksum'] : '';
			$result   = self::assemble_chunks( $rel_path, $upload_id, $total_chunks, $checksum );

			self::cleanup_chunks( $upload_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return new WP_REST_Response(
			array( 'success' => true, 'chunk' => $chunk_index, 'total' => $total_chunks ),
			200
		);
	}

	/**
	 * Write a received file to the local filesystem.
	 *
	 * @param string $rel_path Relative file path.
	 * @param array  $body     Decoded request body.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function receive_file( $rel_path, $body ) {
		self::$receiving = true;

		$content = base64_decode( $body['content'] );

		if ( false === $content ) {
			self::$receiving = false;
			return new WP_Error( 'dualpress_decode_failed', __( 'Failed to decode file content.', 'dualpress' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $body['checksum'] ) && md5( $content ) !== $body['checksum'] ) {
			self::$receiving = false;
			return new WP_Error( 'dualpress_checksum_mismatch', __( 'File checksum mismatch.', 'dualpress' ), array( 'status' => 400 ) );
		}

		// Check if this file belongs to a disabled plugin/theme (.syncing).
		$abs_path = self::absolute_path_with_syncing( $rel_path );
		wp_mkdir_p( dirname( $abs_path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$written = file_put_contents( $abs_path, $content );
		self::$receiving = false;

		if ( false === $written ) {
			return new WP_Error( 'dualpress_write_failed', sprintf( __( 'Failed to write file: %s', 'dualpress' ), $rel_path ), array( 'status' => 500 ) );
		}

		if ( ! empty( $body['modified'] ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@touch( $abs_path, (int) $body['modified'] );
		}

		DualPress_Logger::info( 'file_received', sprintf( 'File received from remote: %s (%d bytes)', $rel_path, $written ) );

		return new WP_REST_Response( array( 'success' => true, 'path' => $rel_path ), 200 );
	}

	/**
	 * Delete a locally-stored file on remote's instruction.
	 *
	 * @param string $rel_path Relative file path.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function receive_delete( $rel_path ) {
		$abs_path = self::absolute_path( $rel_path );

		if ( file_exists( $abs_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @unlink( $abs_path ) ) {
				return new WP_Error(
					'dualpress_delete_failed',
					sprintf( __( 'Could not delete file: %s', 'dualpress' ), $rel_path ),
					array( 'status' => 500 )
				);
			}
		}

		DualPress_Logger::info( 'file_deleted', sprintf( 'File deleted by remote instruction: %s', $rel_path ) );

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $rel_path ), 200 );
	}

	/**
	 * Assemble chunk files into the final destination file.
	 *
	 * @param string $rel_path      Relative path of the assembled file.
	 * @param string $upload_id     Unique upload session identifier.
	 * @param int    $total_chunks  Expected number of chunks.
	 * @param string $checksum      Expected MD5 of the full file, or empty.
	 * @return true|WP_Error
	 */
	private static function assemble_chunks( $rel_path, $upload_id, $total_chunks, $checksum = '' ) {
		$chunks_dir = self::get_chunks_dir() . '/' . $upload_id;
		$content    = '';

		for ( $i = 0; $i < $total_chunks; $i++ ) {
			$chunk_file = $chunks_dir . '/chunk_' . $i;
			if ( ! file_exists( $chunk_file ) ) {
				return new WP_Error(
					'dualpress_chunk_missing',
					sprintf( 'Chunk %d missing for: %s', $i, $rel_path ),
					array( 'status' => 400 )
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$content .= file_get_contents( $chunk_file );
		}

		if ( $checksum && md5( $content ) !== $checksum ) {
			return new WP_Error(
				'dualpress_checksum_mismatch',
				sprintf( 'Checksum mismatch after assembly: %s', $rel_path ),
				array( 'status' => 400 )
			);
		}

		// Use syncing-aware path for disabled plugins/themes.
		$abs_path = self::absolute_path_with_syncing( $rel_path );
		wp_mkdir_p( dirname( $abs_path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === file_put_contents( $abs_path, $content ) ) {
			return new WP_Error(
				'dualpress_write_failed',
				sprintf( 'Failed to write assembled file: %s', $rel_path ),
				array( 'status' => 500 )
			);
		}

		DualPress_Logger::info(
			'file_received',
			sprintf( 'Large file assembled from %d chunks: %s', $total_chunks, $rel_path )
		);

		return true;
	}

	// ------------------------------------------------------------------ //
	// Path helpers                                                         //
	// ------------------------------------------------------------------ //

	/**
	 * Convert an absolute path to a path relative to ABSPATH.
	 *
	 * @param string $abs_path Absolute filesystem path.
	 * @return string Relative path (no leading slash).
	 */
	public static function relative_path( $abs_path ) {
		if ( empty( $abs_path ) ) {
			return '';
		}
		return ltrim( str_replace( untrailingslashit( ABSPATH ), '', $abs_path ), '/' );
	}

	/**
	 * Convert a relative path back to absolute.
	 *
	 * @param string $rel_path Relative path (from ABSPATH).
	 * @return string Absolute path.
	 */
	public static function absolute_path( $rel_path ) {
		if ( empty( $rel_path ) ) {
			return '';
		}
		return untrailingslashit( ABSPATH ) . '/' . ltrim( $rel_path, '/' );
	}

	/**
	 * Get the staging directory path.
	 *
	 * @return string
	 */
	public static function get_staging_dir() {
		return WP_CONTENT_DIR . '/dualpress-tmp';
	}

	/**
	 * Convert a relative path to absolute, redirecting plugins/themes to staging.
	 *
	 * Plugin and theme files go to wp-content/dualpress-tmp/ first.
	 * After all files arrive, FINALIZE action moves them to the final location.
	 *
	 * @param string $rel_path Relative path (from ABSPATH).
	 * @return string Absolute path (possibly redirected to staging).
	 */
	public static function absolute_path_with_syncing( $rel_path ) {
		$rel_path = ltrim( $rel_path, '/' );

		// Check if this is a plugin or theme file that should go to staging.
		// Match both directories (plugin/slug/file.php) and single files (plugin/file.php).
		if ( preg_match( '#^wp-content/(plugins|themes)/([^/]+)#', $rel_path ) ) {
			// Write to staging directory.
			$staging_dir = self::get_staging_dir();
			return $staging_dir . '/' . $rel_path;
		}

		// Default: normal path (uploads, etc.).
		return untrailingslashit( ABSPATH ) . '/' . $rel_path;
	}

	/**
	 * Check whether an absolute path is inside at least one configured sync dir.
	 *
	 * @param string $abs_path Absolute file path.
	 * @return bool
	 */
	public static function is_path_in_sync_scope( $abs_path ) {
		$real = realpath( $abs_path );

		foreach ( self::get_sync_dirs() as $dir ) {
			$real_dir = realpath( $dir );
			if ( $real_dir && $real && 0 === strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Security validation: the relative path must stay inside wp-content
	 * and within an allowed sub-directory based on current settings.
	 *
	 * @param string $rel_path Relative path supplied by the remote side.
	 * @return bool
	 */
	public static function is_safe_path( $rel_path ) {
		// Reject directory traversal.
		if ( false !== strpos( $rel_path, '..' ) ) {
			return false;
		}

		// Must live under wp-content/.
		if ( 0 !== strpos( $rel_path, 'wp-content/' ) ) {
			return false;
		}

		$allowed = array();
		$upload  = wp_upload_dir();

		if ( (bool) DualPress_Settings::get( 'file_sync_uploads', true ) ) {
			$allowed[] = self::relative_path( $upload['basedir'] );
		}
		if ( (bool) DualPress_Settings::get( 'file_sync_themes', false ) ) {
			$allowed[] = 'wp-content/themes';
		}
		if ( (bool) DualPress_Settings::get( 'file_sync_plugins', false ) ) {
			$allowed[] = 'wp-content/plugins';
		}

		foreach ( $allowed as $prefix ) {
			if ( 0 === strpos( $rel_path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	// ------------------------------------------------------------------ //
	// Internal helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Build an absolute URL to a file-sync REST endpoint.
	 *
	 * @param string $endpoint Endpoint slug, e.g. 'file-push'.
	 * @return string Full URL.
	 */
	private static function remote_endpoint( $endpoint ) {
		return rtrim( DualPress_Settings::get_remote_url(), '/' ) . '/wp-json/dualpress/v1/' . $endpoint;
	}

	/**
	 * Execute an authenticated POST request to the remote server.
	 *
	 * @param string $url     Full endpoint URL.
	 * @param string $payload JSON-encoded body.
	 * @param int    $timeout Request timeout in seconds.
	 * @return true|WP_Error
	 */
	private static function do_post( $url, $payload, $timeout = 30 ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers'   => DualPress_Auth::build_headers( $payload ),
				'body'      => $payload,
				'timeout'   => $timeout,
				'sslverify' => apply_filters( 'dualpress_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			$msg  = sprintf( 'Remote returned HTTP %d. %s', $code, wp_strip_all_tags( $body ) );
			return new WP_Error( 'dualpress_remote_error', $msg );
		}

		return true;
	}

	/**
	 * Directory used to stage in-progress chunked uploads.
	 *
	 * @return string Absolute path.
	 */
	private static function get_chunks_dir() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/.dualpress-chunks';
	}

	/**
	 * Remove all temporary chunk files for a given upload session.
	 *
	 * @param string $upload_id Upload session ID.
	 * @return void
	 */
	private static function cleanup_chunks( $upload_id ) {
		$dir = self::get_chunks_dir() . '/' . sanitize_key( $upload_id );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '/chunk_*' );
		if ( $files ) {
			foreach ( $files as $f ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $f );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}
}
