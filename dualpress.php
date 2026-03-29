<?php
/**
 * Plugin Name: DualPress
 * Plugin URI:  https://github.com/natiabargel/dualpress-repo
 * Description: Intelligent database synchronization between two WordPress installations for HA/load-balanced environments.
 * Version:     0.8.8
 * Author:      Nati, Omer, Jeremy
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dualpress
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'DUALPRESS_VERSION', '0.8.8' );
define( 'DUALPRESS_FILE', __FILE__ );
define( 'DUALPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DUALPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'DUALPRESS_BASENAME', plugin_basename( __FILE__ ) );
define( 'DUALPRESS_PREFIX', 'dualpress_' );

/**
 * PSR-4-style autoloader for DualPress_* classes.
 * Maps DualPress_Foo_Bar → includes/class-foo-bar.php
 * Maps DualPress_Rest_Api → api/class-rest-api.php
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'DualPress_';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative  = substr( $class, strlen( $prefix ) );
		$file_name = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		// REST API lives in api/ subdirectory.
		if ( 'Rest_Api' === $relative ) {
			$file = DUALPRESS_DIR . 'api/' . $file_name;
		} elseif ( in_array( $relative, array( 'File_Sync', 'File_Queue' ), true ) ) {
			// File Sync module lives in modules/file-sync/.
			$file = DUALPRESS_DIR . 'modules/file-sync/' . $file_name;
		} else {
			$file = DUALPRESS_DIR . 'includes/' . $file_name;
		}

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// Activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__, array( 'DualPress_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DualPress_Activator', 'deactivate' ) );

/**
 * Main plugin bootstrap — fires on plugins_loaded.
 */
function dualpress_init() {
	// Core singletons.
	DualPress_Settings::get_instance();
	DualPress_Logger::get_instance();
	DualPress_Cron::get_instance();
	DualPress_Notifier::get_instance();

	// Admin UI.
	if ( is_admin() ) {
		require_once DUALPRESS_DIR . 'admin/class-admin.php';
		DualPress_Admin::get_instance();
	}

	// Hook listener only runs when the plugin is configured.
	if ( DualPress_Settings::get( 'remote_url' ) ) {
		DualPress_Hook_Listener::get_instance();
	}

	// File Sync module (always instantiated so hooks register when enabled).
	DualPress_File_Sync::get_instance();

	// REST API routes — always registered so remote servers can reach us.
	add_action(
		'rest_api_init',
		function () {
			$api = new DualPress_Rest_Api();
			$api->register_routes();
		}
	);

	// Allow disabling SSL verification for dev environments with self-signed certs.
	add_filter(
		'dualpress_sslverify',
		function ( $verify ) {
			if ( DualPress_Settings::get( 'skip_ssl_verify', false ) ) {
				return false;
			}
			return $verify;
		}
	);
}
add_action( 'plugins_loaded', 'dualpress_init' );
