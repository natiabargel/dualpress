<?php
/**
 * DualPress Real-time Sync Daemon
 *
 * Continuously monitors for changes and pushes them immediately.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

class DualPress_Daemon {

	/** @var string Path to PID file */
	private static $pid_file;

	/** @var string Path to log file */
	private static $log_file;

	/** @var int Max runtime in seconds (12 hours) */
	const MAX_RUNTIME = 43200;

	/**
	 * Initialize paths.
	 */
	private static function init_paths() {
		$upload_dir     = wp_upload_dir();
		$base           = $upload_dir['basedir'] . '/dualpress/';
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		self::$pid_file = $base . 'daemon.pid';
		self::$log_file = $base . 'daemon.log';
	}

	/**
	 * Check if daemon is running.
	 *
	 * @return bool
	 */
	public static function is_running() {
		self::init_paths();

		if ( ! file_exists( self::$pid_file ) ) {
			return false;
		}

		$pid = (int) file_get_contents( self::$pid_file );
		if ( $pid <= 0 ) {
			return false;
		}

		// Primary check: last_check timestamp (works across containers/namespaces)
		// If daemon updated last_check within the last 30 seconds, it's running
		// Clear cache first to get fresh value
		wp_cache_delete( 'dualpress_daemon_last_check', 'options' );
		$last_check = get_option( 'dualpress_daemon_last_check' );
		if ( $last_check && ( time() - $last_check ) < 30 ) {
			return true;
		}

		// Fallback: check if process is alive via posix_kill
		if ( function_exists( 'posix_kill' ) ) {
			return @posix_kill( $pid, 0 );
		}

		// Fallback: check /proc on Linux
		if ( file_exists( "/proc/{$pid}" ) ) {
			return true;
		}

		// Fallback: use ps command
		exec( "ps -p {$pid}", $output, $ret );
		return $ret === 0;
	}

	/**
	 * Get daemon status info.
	 *
	 * @return array
	 */
	public static function get_status() {
		self::init_paths();

		$status = array(
			'running'    => self::is_running(),
			'pid'        => null,
			'started_at' => null,
			'last_check' => null,
			'interval'   => self::get_interval(),
		);

		if ( file_exists( self::$pid_file ) ) {
			$status['pid'] = (int) file_get_contents( self::$pid_file );
			$status['started_at'] = filemtime( self::$pid_file );
		}

		$last_check = get_option( 'dualpress_daemon_last_check' );
		if ( $last_check ) {
			$status['last_check'] = $last_check;
		}

		return $status;
	}

	/**
	 * Get check interval in seconds.
	 *
	 * @return int
	 */
	public static function get_interval() {
		return (int) get_option( 'dualpress_daemon_interval', 5 );
	}

	/**
	 * Set check interval.
	 *
	 * @param int $seconds Interval in seconds.
	 */
	public static function set_interval( $seconds ) {
		update_option( 'dualpress_daemon_interval', max( 1, (int) $seconds ) );
	}

	/**
	 * Start the daemon.
	 *
	 * @return bool|WP_Error
	 */
	public static function start() {
		self::init_paths();

		if ( self::is_running() ) {
			return new WP_Error( 'already_running', __( 'Daemon is already running.', 'dualpress' ) );
		}

		// Build the command to run the daemon
		$php_binary  = PHP_BINARY ?: 'php';
		$daemon_file = DUALPRESS_DIR . 'includes/daemon-runner.php';
		$wp_path     = ABSPATH;
		$interval    = self::get_interval();

		// Create runner script if not exists
		self::create_runner_script();

		// Start daemon in background
		$cmd = sprintf(
			'%s %s %s %d > %s 2>&1 & echo $!',
			escapeshellarg( $php_binary ),
			escapeshellarg( $daemon_file ),
			escapeshellarg( $wp_path ),
			$interval,
			escapeshellarg( self::$log_file )
		);

		$pid = (int) shell_exec( $cmd );

		if ( $pid <= 0 ) {
			return new WP_Error( 'start_failed', __( 'Failed to start daemon.', 'dualpress' ) );
		}

		file_put_contents( self::$pid_file, $pid );

		// Wait a moment and verify it started
		usleep( 500000 ); // 0.5 seconds
		if ( ! self::is_running() ) {
			return new WP_Error( 'start_failed', __( 'Daemon started but died immediately. Check logs.', 'dualpress' ) );
		}

		return true;
	}

	/**
	 * Stop the daemon.
	 *
	 * @return bool|WP_Error
	 */
	public static function stop() {
		self::init_paths();

		if ( ! self::is_running() ) {
			// Clean up stale PID file
			if ( file_exists( self::$pid_file ) ) {
				unlink( self::$pid_file );
			}
			return true;
		}

		$pid = (int) file_get_contents( self::$pid_file );

		// Send SIGTERM
		if ( function_exists( 'posix_kill' ) ) {
			posix_kill( $pid, 15 ); // SIGTERM
		} else {
			exec( "kill {$pid} 2>/dev/null" );
		}

		// Wait for graceful shutdown
		$timeout = 5;
		while ( $timeout > 0 && self::is_running() ) {
			sleep( 1 );
			$timeout--;
		}

		// Force kill if still running
		if ( self::is_running() ) {
			if ( function_exists( 'posix_kill' ) ) {
				posix_kill( $pid, 9 ); // SIGKILL
			} else {
				exec( "kill -9 {$pid} 2>/dev/null" );
			}
		}

		if ( file_exists( self::$pid_file ) ) {
			unlink( self::$pid_file );
		}

		return true;
	}

	/**
	 * Ensure daemon is running (called by cron).
	 * Also restarts if running too long (memory leak prevention).
	 */
	public static function ensure_running() {
		if ( ! get_option( 'dualpress_daemon_enabled' ) ) {
			// Daemon disabled - kill it if running
			if ( self::is_running() ) {
				self::stop();
			}
			return;
		}

		if ( ! self::is_running() ) {
			self::start();
			return;
		}

		// Check if running too long (auto-restart after MAX_RUNTIME)
		$status = self::get_status();
		if ( $status['started_at'] && ( time() - $status['started_at'] ) > self::MAX_RUNTIME ) {
			self::stop();
			sleep( 1 );
			self::start();
		}
	}

	/**
	 * Enable/disable daemon auto-start.
	 *
	 * @param bool $enabled Whether to enable.
	 */
	public static function set_enabled( $enabled ) {
		update_option( 'dualpress_daemon_enabled', (bool) $enabled );

		if ( $enabled ) {
			self::start();
		} else {
			self::stop();
		}
	}

	/**
	 * Check if daemon is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( 'dualpress_daemon_enabled', false );
	}

	/**
	 * Get recent log entries.
	 *
	 * @param int $lines Number of lines.
	 * @return string
	 */
	public static function get_log( $lines = 50 ) {
		self::init_paths();

		if ( ! file_exists( self::$log_file ) ) {
			return '';
		}

		$output = array();
		exec( 'tail -n ' . (int) $lines . ' ' . escapeshellarg( self::$log_file ), $output );
		return implode( "\n", $output );
	}

	/**
	 * Create the daemon runner script.
	 */
	private static function create_runner_script() {
		$runner_file = DUALPRESS_DIR . 'includes/daemon-runner.php';
		
		// Always recreate to ensure latest version
		$script = <<<'RUNNER'
<?php
/**
 * DualPress Daemon Runner
 *
 * This script runs continuously and processes the sync queue.
 * Usage: php daemon-runner.php /path/to/wordpress [interval_seconds]
 */

if ( php_sapi_name() !== 'cli' ) {
	die( 'CLI only.' );
}

if ( ! isset( $argv[1] ) ) {
	die( "Usage: php daemon-runner.php /path/to/wordpress [interval]\n" );
}

$wp_path  = rtrim( $argv[1], '/' );
$interval = isset( $argv[2] ) ? (int) $argv[2] : 5;

// Max runtime: 12 hours (43200 seconds)
$max_runtime = 43200;
$start_time  = time();

// Load WordPress
define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

// Set up signal handlers for graceful shutdown
$running = true;

if ( function_exists( 'pcntl_signal' ) ) {
	pcntl_signal( SIGTERM, function() use ( &$running ) {
		$running = false;
		echo "[" . date('Y-m-d H:i:s') . "] Received SIGTERM, shutting down...\n";
	});
	pcntl_signal( SIGINT, function() use ( &$running ) {
		$running = false;
		echo "[" . date('Y-m-d H:i:s') . "] Received SIGINT, shutting down...\n";
	});
}

echo "[" . date('Y-m-d H:i:s') . "] DualPress Daemon started (PID: " . getmypid() . ", interval: {$interval}s, max_runtime: {$max_runtime}s)\n";

while ( $running ) {
	if ( function_exists( 'pcntl_signal_dispatch' ) ) {
		pcntl_signal_dispatch();
	}

	// Check if daemon was disabled in settings
	if ( ! get_option( 'dualpress_daemon_enabled' ) ) {
		echo "[" . date('Y-m-d H:i:s') . "] Daemon disabled in settings, shutting down...\n";
		break;
	}

	// Check max runtime (auto-restart protection)
	if ( ( time() - $start_time ) > $max_runtime ) {
		echo "[" . date('Y-m-d H:i:s') . "] Max runtime reached ({$max_runtime}s), shutting down for restart...\n";
		break;
	}

	try {
		// Update last check timestamp
		update_option( 'dualpress_daemon_last_check', time() );

		// Process DB sync queue
		$db_result = DualPress_Sender::process_queue();
		if ( $db_result > 0 ) {
			echo "[" . date('Y-m-d H:i:s') . "] Synced {$db_result} database items\n";
		}

		// Process file sync queue
		if ( class_exists( 'DualPress_File_Sync' ) ) {
			$file_result = DualPress_File_Sync::process_queue();
			if ( $file_result > 0 ) {
				echo "[" . date('Y-m-d H:i:s') . "] Synced {$file_result} files\n";
			}
		}

	} catch ( Exception $e ) {
		echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
	}

	// Sleep with interrupt check
	for ( $i = 0; $i < $interval && $running; $i++ ) {
		if ( function_exists( 'pcntl_signal_dispatch' ) ) {
			pcntl_signal_dispatch();
		}
		sleep( 1 );
	}
}

echo "[" . date('Y-m-d H:i:s') . "] DualPress Daemon stopped.\n";
RUNNER;

		file_put_contents( $runner_file, $script );
		chmod( $runner_file, 0755 );
	}

	/**
	 * Get crontab example code.
	 *
	 * @return string
	 */
	public static function get_crontab_example() {
		$php_binary  = PHP_BINARY ?: '/usr/bin/php';
		$daemon_file = DUALPRESS_DIR . 'includes/daemon-runner.php';
		$wp_path     = ABSPATH;
		$interval    = self::get_interval();

		return sprintf(
			'* * * * * %s %s %s %d >> /dev/null 2>&1',
			$php_binary,
			$daemon_file,
			rtrim( $wp_path, '/' ),
			$interval
		);
	}
}
