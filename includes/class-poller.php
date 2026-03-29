<?php
/**
 * Polling fallback — Layer 2 change detection.
 *
 * Runs every 5 minutes via WP-Cron and catches database changes that were
 * made directly via SQL (bypassing WordPress hooks).
 *
 * Only polls tables that expose a reliable timestamp column. For tables
 * without one (wp_options, wp_terms, etc.) we rely on hooks exclusively.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Poller
 */
class DualPress_Poller {

	/**
	 * Map of table suffix → timestamp column used for change detection.
	 *
	 * @var array<string,string>
	 */
	const TIMESTAMP_MAP = array(
		'posts'    => 'post_modified_gmt',
		'comments' => 'comment_date_gmt',
		'users'    => 'user_registered',   // limited — no updated_at on users
	);

	/**
	 * Run the polling cycle.
	 *
	 * Called by DualPress_Cron on the 'dualpress_poll' schedule.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		$last_poll = get_option( 'dualpress_last_poll_time', gmdate( 'Y-m-d H:i:s', strtotime( '-5 minutes' ) ) );

		foreach ( self::TIMESTAMP_MAP as $table_suffix => $ts_column ) {
			$table = $wpdb->prefix . $table_suffix;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM `$table` WHERE `$ts_column` > %s",
					$last_poll
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				continue;
			}

			$pk_column = self::get_pk_column( $table_suffix );

			foreach ( $rows as $row ) {
				// Determine whether this is new or modified.
				// We treat all hits as UPDATE; if the row is genuinely new it will
				// be an INSERT on the remote via conflict resolution.
				DualPress_Queue::add(
					$table_suffix,
					'UPDATE',
					array( $pk_column => $row[ $pk_column ] ),
					$row
				);
			}
		}

		// Update last poll time to now (UTC).
		update_option( 'dualpress_last_poll_time', current_time( 'mysql', true ) );

		DualPress_Logger::debug( 'poller_run', 'Polling cycle completed.' );
	}

	/**
	 * Get the primary key column name for a given table suffix.
	 *
	 * @param string $table_suffix Table name without DB prefix.
	 * @return string
	 */
	private static function get_pk_column( $table_suffix ) {
		$map = array(
			'posts'    => 'ID',
			'users'    => 'ID',
			'comments' => 'comment_ID',
		);
		return isset( $map[ $table_suffix ] ) ? $map[ $table_suffix ] : 'id';
	}
}
