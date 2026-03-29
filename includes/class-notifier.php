<?php
/**
 * Email notification service.
 *
 * Sends admin alerts for configurable sync events.
 * Throttling is applied via transients to avoid email floods.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Notifier
 */
class DualPress_Notifier {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Throttle window in seconds — one notification per event per window.
	 */
	const THROTTLE_SECONDS = 3600; // 1 hour

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor. */
	private function __construct() {}

	// ------------------------------------------------------------------ //
	// Notification triggers                                                //
	// ------------------------------------------------------------------ //

	/**
	 * Notify when connection to remote is lost.
	 *
	 * @param string $error Error message.
	 */
	public static function notify_connection_lost( $error = '' ) {
		if ( ! DualPress_Settings::is_notification_enabled( 'connection_lost' ) ) {
			return;
		}
		if ( self::is_throttled( 'connection_lost' ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] DualPress — Connection to remote server lost', 'dualpress' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: site URL, 2: remote URL, 3: error message */
			__(
				"DualPress on %1\$s lost connection to the remote server.\n\nRemote URL: %2\$s\nError: %3\$s\n\nPlease check your server connectivity and plugin settings.",
				'dualpress'
			),
			home_url(),
			DualPress_Settings::get_remote_url(),
			$error
		);

		self::send( $subject, $message );
		self::throttle( 'connection_lost' );
	}

	/**
	 * Notify when connection is restored after a failure.
	 */
	public static function notify_connection_restored() {
		if ( ! DualPress_Settings::is_notification_enabled( 'connection_restored' ) ) {
			return;
		}
		// Don't throttle "restored" — it's a positive event that fires once.
		delete_transient( 'dualpress_notified_connection_lost' );

		$subject = sprintf(
			__( '[%s] DualPress — Connection to remote server restored', 'dualpress' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			__(
				"DualPress on %s has successfully re-established connection to the remote server.\n\nSync will resume automatically.",
				'dualpress'
			),
			home_url()
		);

		self::send( $subject, $message );
	}

	/**
	 * Check for sync items that have failed 10+ times and notify.
	 */
	public static function maybe_notify_failed_items() {
		if ( ! DualPress_Settings::is_notification_enabled( 'sync_item_failed' ) ) {
			return;
		}
		if ( self::is_throttled( 'sync_item_failed' ) ) {
			return;
		}

		$stats = DualPress_Queue::get_stats();
		if ( $stats['high_failures'] < 1 ) {
			return;
		}

		$subject = sprintf(
			__( '[%s] DualPress — Sync items persistently failing', 'dualpress' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			__(
				"DualPress on %s has %d sync items that have failed 10 or more times.\n\nPlease review the Sync Queue in your admin dashboard and retry or investigate the failures.\n\nAdmin URL: %s",
				'dualpress'
			),
			home_url(),
			$stats['high_failures'],
			admin_url( 'admin.php?page=dualpress&tab=tools' )
		);

		self::send( $subject, $message );
		self::throttle( 'sync_item_failed' );
	}

	/**
	 * Notify when a full sync completes.
	 *
	 * @param int $tables Number of tables synced.
	 * @param int $items  Number of items synced.
	 */
	public static function notify_full_sync_completed( $tables, $items ) {
		if ( ! DualPress_Settings::is_notification_enabled( 'full_sync_completed' ) ) {
			return;
		}

		$subject = sprintf(
			__( '[%s] DualPress — Full sync completed', 'dualpress' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			__(
				"DualPress on %s has completed a full database synchronization.\n\nTables synced: %d\nItems synced: %d\n\nTimestamp: %s",
				'dualpress'
			),
			home_url(),
			$tables,
			$items,
			current_time( 'mysql' )
		);

		self::send( $subject, $message );
	}

	/**
	 * Notify when the queue exceeds the threshold.
	 *
	 * @param int $count Current pending count.
	 */
	public static function notify_queue_threshold( $count ) {
		if ( ! DualPress_Settings::is_notification_enabled( 'queue_threshold' ) ) {
			return;
		}
		if ( self::is_throttled( 'queue_threshold' ) ) {
			return;
		}

		$subject = sprintf(
			__( '[%s] DualPress — Sync queue backlog warning', 'dualpress' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			__(
				"DualPress on %s has a large backlog in the sync queue.\n\nPending items: %d\n\nThis may indicate connectivity issues or a high rate of content changes. Please review the queue in your admin dashboard.",
				'dualpress'
			),
			home_url(),
			$count
		);

		self::send( $subject, $message );
		self::throttle( 'queue_threshold' );
	}

	// ------------------------------------------------------------------ //
	// Internal helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * Send an email notification.
	 *
	 * @param string $subject Email subject.
	 * @param string $message Plain-text message body.
	 * @return bool
	 */
	private static function send( $subject, $message ) {
		$to = DualPress_Settings::get_notification_email();
		if ( empty( $to ) ) {
			return false;
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Check if a notification type is currently throttled.
	 *
	 * @param string $event_type Event slug.
	 * @return bool
	 */
	private static function is_throttled( $event_type ) {
		return (bool) get_transient( 'dualpress_notified_' . $event_type );
	}

	/**
	 * Mark a notification type as recently sent.
	 *
	 * @param string $event_type Event slug.
	 */
	private static function throttle( $event_type ) {
		set_transient( 'dualpress_notified_' . $event_type, true, self::THROTTLE_SECONDS );
	}
}
