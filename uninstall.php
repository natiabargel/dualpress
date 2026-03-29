<?php
/**
 * Plugin uninstall handler.
 *
 * Called by WordPress when the plugin is deleted from the Plugins screen.
 * Removes all database tables and option values created by DualPress.
 *
 * This file is executed in a bare WordPress context — the plugin's own
 * autoloader is not available, so we load only what is necessary.
 *
 * @package DualPress
 */

// Only run when called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the two classes we need.
require_once __DIR__ . '/includes/class-database.php';
require_once __DIR__ . '/includes/class-settings.php';

// Drop all plugin tables.
DualPress_Database::drop_tables();

// Delete all plugin options.
DualPress_Settings::delete_all_options();

// Delete db version option.
delete_option( 'dualpress_db_version' );

// Clean up any throttle transients we may have set.
global $wpdb;
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dualpress\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dualpress\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
