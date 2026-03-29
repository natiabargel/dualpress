<?php
/**
 * WP-Cron schedule management.
 *
 * Registers custom cron intervals and schedules the recurring events that
 * drive the sync pipeline.
 *
 * Events:
 *   dualpress_sync   — process queue and send pending changes
 *   dualpress_poll   — polling fallback for missed hook changes
 *   dualpress_cleanup— log and queue housekeeping
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Cron
 */
class DualPress_Cron {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton and binds action hooks.
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
	 * Register cron interval filter and action handlers.
	 *
	 * @return void
	 */
	private function init() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );

		add_action( 'dualpress_sync',    array( $this, 'run_sync' ) );
		add_action( 'dualpress_poll',    array( $this, 'run_poll' ) );
		add_action( 'dualpress_cleanup', array( $this, 'run_cleanup' ) );
		add_action( 'dualpress_table_sync', array( $this, 'run_table_sync' ) );

		// Ensure cron events are scheduled (in case they were lost).
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_events' ) );
	}

	/**
	 * Schedule events if they're missing.
	 */
	public static function maybe_schedule_events() {
		if ( ! wp_next_scheduled( 'dualpress_sync' ) ) {
			self::schedule_events();
		}
	}

	/**
	 * Add custom WP-Cron interval(s).
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		$interval = DualPress_Settings::get_sync_interval(); // Already in seconds.

		$schedules['dualpress_interval'] = array(
			'interval' => max( 15, $interval ), // minimum 15 seconds
			'display'  => $interval < 60
				? sprintf(
					/* translators: %d: number of seconds */
					__( 'Every %d seconds (DualPress)', 'dualpress' ),
					$interval
				)
				: sprintf(
					/* translators: %d: number of minutes */
					__( 'Every %d minute(s) (DualPress)', 'dualpress' ),
					intval( $interval / 60 )
				),
		);

		$schedules['dualpress_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily (DualPress)', 'dualpress' ),
		);

		$schedules['dualpress_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Once Hourly (DualPress)', 'dualpress' ),
		);

		// Dynamic DB sync interval.
		$db_interval = (int) DualPress_Settings::get( 'db_sync_interval', 3600 );
		if ( $db_interval > 0 ) {
			$schedules['dualpress_db_sync'] = array(
				'interval' => $db_interval,
				'display'  => sprintf( __( 'Every %d minutes (DualPress DB Sync)', 'dualpress' ), intval( $db_interval / 60 ) ),
			);
		}

		return $schedules;
	}

	// ------------------------------------------------------------------ //
	// Event registration / removal                                         //
	// ------------------------------------------------------------------ //

	/**
	 * Schedule all plugin cron events (called on activation).
	 *
	 * @return void
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'dualpress_sync' ) ) {
			wp_schedule_event( time(), 'dualpress_interval', 'dualpress_sync' );
		}

		if ( ! wp_next_scheduled( 'dualpress_poll' ) ) {
			wp_schedule_event( time(), 'dualpress_interval', 'dualpress_poll' );
		}

		if ( ! wp_next_scheduled( 'dualpress_cleanup' ) ) {
			wp_schedule_event( time(), 'dualpress_daily', 'dualpress_cleanup' );
		}

		$db_interval = (int) DualPress_Settings::get( 'db_sync_interval', 3600 );
		if ( $db_interval > 0 && ! wp_next_scheduled( 'dualpress_table_sync' ) ) {
			wp_schedule_event( time(), 'dualpress_db_sync', 'dualpress_table_sync' );
		}
	}

	/**
	 * Unschedule all plugin cron events (called on deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_events() {
		$events = array( 'dualpress_sync', 'dualpress_poll', 'dualpress_cleanup', 'dualpress_table_sync' );

		foreach ( $events as $event ) {
			$timestamp = wp_next_scheduled( $event );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $event );
			}
		}
	}

	// ------------------------------------------------------------------ //
	// Cron action callbacks                                                //
	// ------------------------------------------------------------------ //

	/**
	 * Process the sync queue — called on 'dualpress_sync' event.
	 *
	 * @return void
	 */
	public function run_sync() {
		if ( ! DualPress_Settings::is_configured() ) {
			return;
		}

		// Active-Passive: only primary pushes.
		if (
			'active-passive' === DualPress_Settings::get_sync_mode() &&
			'secondary' === DualPress_Settings::get_server_role()
		) {
			return;
		}

		DualPress_Sender::process_queue();

		// Auto-retry failed items (up to 5 attempts).
		DualPress_Queue::auto_retry_failed( 5 );

		// Process file sync queue if enabled.
		if ( class_exists( 'DualPress_File_Sync' ) && DualPress_File_Sync::is_enabled() ) {
			DualPress_File_Sync::process_queue();
			DualPress_File_Queue::auto_retry_failed( 5 );
		}
	}

	/**
	 * Run the polling fallback — called on 'dualpress_poll' event.
	 *
	 * @return void
	 */
	public function run_poll() {
		if ( ! DualPress_Settings::is_configured() ) {
			return;
		}

		DualPress_Poller::run();
	}

	/**
	 * Daily housekeeping — called on 'dualpress_cleanup' event.
	 *
	 * @return void
	 */
	public function run_cleanup() {
		$retention_days = (int) apply_filters( 'dualpress_log_retention_days', 30 );

		DualPress_Logger::cleanup( $retention_days );
		DualPress_Queue::prune_completed( 7 );
		DualPress_Queue::reset_stuck_processing();

		// Clean up old received-batch records (keep 30 days for deduplication).
		global $wpdb;
		$batches_table = DualPress_Database::batches_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE FROM `$batches_table` WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
		);

		DualPress_Logger::debug( 'cleanup', 'Daily housekeeping completed.' );
	}

	/**
	 * Hourly table sync — find and sync missing tables with data.
	 *
	 * @return void
	 */
	public function run_table_sync() {
		if ( ! DualPress_Settings::is_configured() ) {
			return;
		}

		// Only run on primary server.
		if (
			'active-passive' === DualPress_Settings::get_sync_mode() &&
			'secondary' === DualPress_Settings::get_server_role()
		) {
			return;
		}

		if ( ! class_exists( 'DualPress_Table_Sync' ) ) {
			return;
		}

		$result = DualPress_Table_Sync::sync_missing_tables_with_data();

		if ( is_wp_error( $result ) ) {
			DualPress_Logger::error( 'table_sync_cron_error', $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['synced_tables'] ) ) {
			DualPress_Logger::info(
				'table_sync_cron_completed',
				sprintf(
					'Hourly table sync: %d tables created, %d rows synced.',
					count( $result['synced_tables'] ),
					$result['rows_synced']
				),
				$result
			);
		}
	}
}
