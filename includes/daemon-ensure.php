<?php
/**
 * DualPress Daemon Ensure Script
 *
 * Run this from cron every minute to ensure the daemon stays alive.
 * Usage: php daemon-ensure.php /path/to/wordpress
 */

if ( php_sapi_name() !== 'cli' ) {
	die( 'CLI only.' );
}

if ( ! isset( $argv[1] ) ) {
	die( "Usage: php daemon-ensure.php /path/to/wordpress\n" );
}

$wp_path = rtrim( $argv[1], '/' );

// Load WordPress
define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

// Check if daemon should be running
if ( ! DualPress_Daemon::is_enabled() ) {
	exit( 0 );
}

// Start if not running
if ( ! DualPress_Daemon::is_running() ) {
	$result = DualPress_Daemon::start();
	if ( is_wp_error( $result ) ) {
		echo "[" . date('Y-m-d H:i:s') . "] Failed to start daemon: " . $result->get_error_message() . "\n";
		exit( 1 );
	}
	echo "[" . date('Y-m-d H:i:s') . "] Daemon restarted.\n";
}

exit( 0 );
