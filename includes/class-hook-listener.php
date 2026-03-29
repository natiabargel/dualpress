<?php
/**
 * WordPress hook listener — captures real-time database changes and adds them
 * to the sync queue.
 *
 * Layer 1 of the two-layer change detection system. Layer 2 (polling fallback)
 * is handled by DualPress_Poller.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Hook_Listener
 */
class DualPress_Hook_Listener {

	/**
	 * Default excluded meta keys (server-specific data).
	 */
	const DEFAULT_EXCLUDED_META_KEYS = array(
		// User meta — session/activity data.
		'session_tokens',
		'wc_last_active',
		'_woocommerce_persistent_cart',
		'_woocommerce_persistent_cart_1',
		// Post meta — edit locks.
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Default excluded option keys (server-specific).
	 */
	const DEFAULT_EXCLUDED_OPTION_KEYS = array(
		'cron',
		'rewrite_rules',
		'recently_edited',
		'auto_updater.lock',
		'core_updater.lock',
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Flag: are we currently applying changes received from the remote?
	 * When true we skip enqueueing to avoid echo-backs.
	 *
	 * @var bool
	 */
	private static $applying_remote = false;

	/**
	 * Check if this server should queue outbound changes.
	 *
	 * Returns false if:
	 * - Currently applying remote changes (prevents loops)
	 * - Server is secondary in active-passive mode
	 *
	 * @return bool True if should queue, false if should skip.
	 */
	private static function should_queue_changes() {
		if ( self::$applying_remote ) {
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
	 * Check if a meta key should be excluded from sync.
	 *
	 * @param string $meta_key The meta key to check.
	 * @return bool True if excluded, false if should sync.
	 */
	private static function is_meta_key_excluded( $meta_key ) {
		// Null or empty meta_key — skip.
		if ( empty( $meta_key ) ) {
			return true;
		}

		// Check default exclusions.
		if ( in_array( $meta_key, self::DEFAULT_EXCLUDED_META_KEYS, true ) ) {
			return true;
		}

		// Check user-defined exclusions.
		$user_excluded = DualPress_Settings::get_excluded_meta_keys();
		if ( in_array( $meta_key, $user_excluded, true ) ) {
			return true;
		}

		// Check patterns (e.g., _woocommerce_persistent_cart_*).
		foreach ( array_merge( self::DEFAULT_EXCLUDED_META_KEYS, $user_excluded ) as $pattern ) {
			if ( substr( $pattern, -1 ) === '*' ) {
				$prefix = substr( $pattern, 0, -1 );
				if ( strpos( $meta_key, $prefix ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if an option key should be excluded from sync.
	 *
	 * @param string $option_name The option name to check.
	 * @return bool True if excluded, false if should sync.
	 */
	private static function is_option_excluded( $option_name ) {
		// Null or empty option — skip.
		if ( empty( $option_name ) ) {
			return true;
		}

		// Transients are always excluded.
		if ( strpos( $option_name, '_transient_' ) === 0 || strpos( $option_name, '_site_transient_' ) === 0 ) {
			return true;
		}

		// Check default exclusions.
		if ( in_array( $option_name, self::DEFAULT_EXCLUDED_OPTION_KEYS, true ) ) {
			return true;
		}

		// Check user-defined exclusions.
		$user_excluded = DualPress_Settings::get_excluded_option_keys();
		if ( in_array( $option_name, $user_excluded, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns the singleton and registers all hooks.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	/** Private constructor. */
	private function __construct() {}

	/**
	 * Set / unset the "applying remote" guard flag.
	 *
	 * Call DualPress_Hook_Listener::set_applying_remote(true) before
	 * writing incoming changes to the DB, then set it back to false after.
	 *
	 * @param bool $value True to suppress outgoing queue writes.
	 */
	public static function set_applying_remote( $value ) {
		self::$applying_remote = (bool) $value;
	}

	/**
	 * Register all WordPress and WooCommerce action hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// -- Posts --------------------------------------------------------- //
		add_action( 'save_post', array( $this, 'on_post_save' ), 99, 3 );
		add_action( 'delete_post', array( $this, 'on_post_delete' ), 99 );
		add_action( 'wp_trash_post', array( $this, 'on_post_trash' ), 99 );

		// -- Post Meta ------------------------------------------------------ //
		add_action( 'added_post_meta', array( $this, 'on_postmeta_add' ), 99, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_postmeta_update' ), 99, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_postmeta_delete' ), 99, 4 );

		// -- Users ---------------------------------------------------------- //
		add_action( 'user_register', array( $this, 'on_user_create' ), 99 );
		add_action( 'profile_update', array( $this, 'on_user_update' ), 99, 2 );
		add_action( 'delete_user', array( $this, 'on_user_delete' ), 99 );

		// -- User Meta ------------------------------------------------------ //
		add_action( 'added_user_meta', array( $this, 'on_usermeta_add' ), 99, 4 );
		add_action( 'updated_user_meta', array( $this, 'on_usermeta_update' ), 99, 4 );
		add_action( 'deleted_user_meta', array( $this, 'on_usermeta_delete' ), 99, 4 );

		// -- Comments ------------------------------------------------------- //
		add_action( 'wp_insert_comment', array( $this, 'on_comment_insert' ), 99, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_edit' ), 99 );
		add_action( 'delete_comment', array( $this, 'on_comment_delete' ), 99 );
		add_action( 'trashed_comment', array( $this, 'on_comment_trash' ), 99 );
		add_action( 'untrashed_comment', array( $this, 'on_comment_untrash' ), 99 );

		// -- Options -------------------------------------------------------- //
		add_action( 'added_option', array( $this, 'on_option_add' ), 99, 2 );
		add_action( 'updated_option', array( $this, 'on_option_update' ), 99, 3 );
		add_action( 'deleted_option', array( $this, 'on_option_delete' ), 99 );

		// -- Terms ---------------------------------------------------------- //
		add_action( 'created_term', array( $this, 'on_term_create' ), 99, 3 );
		add_action( 'edited_term', array( $this, 'on_term_edit' ), 99, 3 );
		add_action( 'delete_term', array( $this, 'on_term_delete' ), 99, 4 );
		add_action( 'set_object_terms', array( $this, 'on_object_terms_set' ), 99, 6 );

		// -- WooCommerce ---------------------------------------------------- //
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_new_order', array( $this, 'on_order_create' ), 99 );
			add_action( 'woocommerce_update_order', array( $this, 'on_order_update' ), 99 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 99, 4 );
			add_action( 'woocommerce_new_product', array( $this, 'on_product_create' ), 99 );
			add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ), 99 );
			add_action( 'woocommerce_created_customer', array( $this, 'on_customer_create' ), 99, 3 );
			add_action( 'woocommerce_update_customer', array( $this, 'on_customer_update' ), 99 );
		}
	}

	// ------------------------------------------------------------------ //
	// Posts                                                                //
	// ------------------------------------------------------------------ //

	/** @param int $post_id */
	public function on_post_save( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->enqueue_row( 'posts', 'ID', $post_id, $update ? 'UPDATE' : 'INSERT' );
	}

	/** @param int $post_id */
	public function on_post_delete( $post_id ) {
		$this->enqueue_delete( 'posts', 'ID', $post_id );
	}

	/** @param int $post_id */
	public function on_post_trash( $post_id ) {
		$this->enqueue_row( 'posts', 'ID', $post_id, 'UPDATE' );
	}

	// ------------------------------------------------------------------ //
	// Post Meta                                                            //
	// ------------------------------------------------------------------ //

	public function on_postmeta_add( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( self::is_meta_key_excluded( $meta_key ) ) {
			return;
		}
		$this->enqueue_meta_row( 'postmeta', 'meta_id', $meta_id, 'INSERT' );
	}

	public function on_postmeta_update( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( self::is_meta_key_excluded( $meta_key ) ) {
			return;
		}
		$this->enqueue_meta_row( 'postmeta', 'meta_id', $meta_id, 'UPDATE' );
	}

	public function on_postmeta_delete( $meta_ids, $post_id, $meta_key, $meta_value ) {
		if ( self::is_meta_key_excluded( $meta_key ) ) {
			return;
		}
		foreach ( (array) $meta_ids as $meta_id ) {
			$this->enqueue_delete( 'postmeta', 'meta_id', $meta_id );
		}
	}

	// ------------------------------------------------------------------ //
	// Users                                                                //
	// ------------------------------------------------------------------ //

	public function on_user_create( $user_id ) {
		$this->enqueue_row( 'users', 'ID', $user_id, 'INSERT' );
	}

	public function on_user_update( $user_id, $old_user_data ) {
		$this->enqueue_row( 'users', 'ID', $user_id, 'UPDATE' );
	}

	public function on_user_delete( $user_id ) {
		$this->enqueue_delete( 'users', 'ID', $user_id );
	}

	// ------------------------------------------------------------------ //
	// User Meta                                                            //
	// ------------------------------------------------------------------ //

	public function on_usermeta_add( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( self::is_meta_key_excluded( $meta_key ) ) {
			return;
		}
		$this->enqueue_meta_row( 'usermeta', 'umeta_id', $meta_id, 'INSERT' );
	}

	public function on_usermeta_update( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( self::is_meta_key_excluded( $meta_key ) ) {
			return;
		}
		$this->enqueue_meta_row( 'usermeta', 'umeta_id', $meta_id, 'UPDATE' );
	}

	public function on_usermeta_delete( $meta_ids, $user_id, $meta_key, $meta_value ) {
		if ( self::is_meta_key_excluded( $meta_key ) ) {
			return;
		}
		foreach ( (array) $meta_ids as $meta_id ) {
			$this->enqueue_delete( 'usermeta', 'umeta_id', $meta_id );
		}
	}

	// ------------------------------------------------------------------ //
	// Comments                                                             //
	// ------------------------------------------------------------------ //

	public function on_comment_insert( $comment_id, $comment_object ) {
		$this->enqueue_row( 'comments', 'comment_ID', $comment_id, 'INSERT' );
	}

	public function on_comment_edit( $comment_id ) {
		$this->enqueue_row( 'comments', 'comment_ID', $comment_id, 'UPDATE' );
	}

	public function on_comment_delete( $comment_id ) {
		$this->enqueue_delete( 'comments', 'comment_ID', $comment_id );
	}

	public function on_comment_trash( $comment_id ) {
		$this->enqueue_row( 'comments', 'comment_ID', $comment_id, 'UPDATE' );
	}

	public function on_comment_untrash( $comment_id ) {
		$this->enqueue_row( 'comments', 'comment_ID', $comment_id, 'UPDATE' );
	}

	// ------------------------------------------------------------------ //
	// Options                                                              //
	// ------------------------------------------------------------------ //

	public function on_option_add( $option, $value ) {
		if ( $this->should_skip_option( $option ) ) {
			return;
		}
		$this->enqueue_option( $option, 'INSERT' );
	}

	public function on_option_update( $option, $old_value, $new_value ) {
		if ( $this->should_skip_option( $option ) ) {
			return;
		}
		$this->enqueue_option( $option, 'UPDATE' );
	}

	public function on_option_delete( $option ) {
		if ( $this->should_skip_option( $option ) ) {
			return;
		}
		$this->enqueue_option( $option, 'DELETE' );
	}

	// ------------------------------------------------------------------ //
	// Terms                                                                //
	// ------------------------------------------------------------------ //

	public function on_term_create( $term_id, $tt_id, $taxonomy ) {
		$this->enqueue_row( 'terms', 'term_id', $term_id, 'INSERT' );
		$this->enqueue_row( 'term_taxonomy', 'term_taxonomy_id', $tt_id, 'INSERT' );
	}

	public function on_term_edit( $term_id, $tt_id, $taxonomy ) {
		$this->enqueue_row( 'terms', 'term_id', $term_id, 'UPDATE' );
		$this->enqueue_row( 'term_taxonomy', 'term_taxonomy_id', $tt_id, 'UPDATE' );
	}

	public function on_term_delete( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		$this->enqueue_delete( 'terms', 'term_id', $term_id );
		$this->enqueue_delete( 'term_taxonomy', 'term_taxonomy_id', $tt_id );
	}

	public function on_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		global $wpdb;
		// Enqueue current term_relationships for this object.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->term_relationships} WHERE object_id = %d",
				$object_id
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			DualPress_Queue::add(
				'term_relationships',
				'UPDATE',
				array( 'object_id' => $object_id, 'term_taxonomy_id' => $row['term_taxonomy_id'] ),
				$row
			);
		}
	}

	// ------------------------------------------------------------------ //
	// WooCommerce                                                          //
	// ------------------------------------------------------------------ //

	public function on_order_create( $order_id ) {
		$this->enqueue_row( 'posts', 'ID', $order_id, 'INSERT' );
	}

	public function on_order_update( $order_id ) {
		$this->enqueue_row( 'posts', 'ID', $order_id, 'UPDATE' );
	}

	public function on_order_status_change( $order_id, $from, $to, $order ) {
		$this->enqueue_row( 'posts', 'ID', $order_id, 'UPDATE' );
	}

	public function on_product_create( $product_id ) {
		$this->enqueue_row( 'posts', 'ID', $product_id, 'INSERT' );
	}

	public function on_product_update( $product_id ) {
		$this->enqueue_row( 'posts', 'ID', $product_id, 'UPDATE' );
	}

	public function on_customer_create( $customer_id, $new_customer_data, $password_generated ) {
		$this->enqueue_row( 'users', 'ID', $customer_id, 'INSERT' );
	}

	public function on_customer_update( $customer_id ) {
		$this->enqueue_row( 'users', 'ID', $customer_id, 'UPDATE' );
	}

	// ------------------------------------------------------------------ //
	// Internal helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Read a full row from the DB and add it to the queue.
	 *
	 * @param string $table_suffix Table name without prefix (e.g. 'posts').
	 * @param string $pk_column    Primary key column name.
	 * @param int    $pk_value     Primary key value.
	 * @param string $action       'INSERT' or 'UPDATE'.
	 */
	private function enqueue_row( $table_suffix, $pk_column, $pk_value, $action ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . $table_suffix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE `$pk_column` = %d", $pk_value ), ARRAY_A );

		if ( ! $row ) {
			return;
		}

		DualPress_Queue::add(
			$table_suffix,
			$action,
			array( $pk_column => $pk_value ),
			$row
		);
	}

	/**
	 * Enqueue a meta row by meta_id.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @param string $pk_column    Primary key column name.
	 * @param int    $meta_id      Meta record ID.
	 * @param string $action       'INSERT' or 'UPDATE'.
	 */
	private function enqueue_meta_row( $table_suffix, $pk_column, $meta_id, $action ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . $table_suffix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE `$pk_column` = %d", $meta_id ), ARRAY_A );

		if ( ! $row ) {
			return;
		}

		DualPress_Queue::add(
			$table_suffix,
			$action,
			array( $pk_column => $meta_id ),
			$row
		);
	}

	/**
	 * Enqueue a DELETE action.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @param string $pk_column    Primary key column.
	 * @param int    $pk_value     Primary key value.
	 */
	private function enqueue_delete( $table_suffix, $pk_column, $pk_value ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		DualPress_Queue::add(
			$table_suffix,
			'DELETE',
			array( $pk_column => $pk_value ),
			null
		);
	}

	/**
	 * Enqueue an option change.
	 *
	 * @param string $option_name Option name.
	 * @param string $action      'INSERT', 'UPDATE', or 'DELETE'.
	 */
	private function enqueue_option( $option_name, $action ) {
		if ( ! self::should_queue_changes() ) {
			return;
		}

		global $wpdb;
		$row = null;

		if ( 'DELETE' !== $action ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->options} WHERE option_name = %s",
					$option_name
				),
				ARRAY_A
			);
		}

		DualPress_Queue::add(
			'options',
			$action,
			array( 'option_name' => $option_name ),
			$row
		);
	}

	/**
	 * Returns true for option names that must never be synced.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function should_skip_option( $option ) {
		// Use the centralized exclusion check.
		if ( self::is_option_excluded( $option ) ) {
			return true;
		}

		// Plugin/theme related options — managed by file-sync module.
		$plugin_theme_options = array(
			'active_plugins',          // Managed by file-sync module — prevents activation before files exist.
			'current_theme',           // Theme activation managed by file-sync.
			'stylesheet',              // Theme-related.
			'template',                // Theme-related.
		);

		if ( in_array( $option, $plugin_theme_options, true ) ) {
			return true;
		}

		// DualPress's own options are always excluded.
		if ( 0 === strpos( $option, 'dualpress_' ) ) {
			return true;
		}

		return false;
	}
}
