#!/usr/bin/env php
<?php
/**
 * DualPress Deploy Tool
 *
 * Restores a DualPress backup (.tar.gz) on a fresh server.
 *
 * Usage:
 *   php deploy.php
 *
 * Requirements:
 *   - PHP 7.4+ CLI
 *   - tar command (recommended) or PHP PharData extension
 *   - curl command (recommended) or PHP allow_url_fopen enabled
 *   - MySQL client OR PDO_MySQL / mysqli extension
 *
 * @package DualPress
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "This script must be run from the command line.\n" );
}

define( 'DEPLOY_VERSION', '1.0.0' );

// ------------------------------------------------------------------ //
// Helpers                                                             //
// ------------------------------------------------------------------ //

function dp_prompt( $message, $default = '', $secret = false ) {
	echo $message;
	if ( '' !== $default ) {
		echo ' [' . $default . ']';
	}
	echo ': ';

	if ( $secret && PHP_OS_FAMILY !== 'Windows' ) {
		$value = rtrim( shell_exec( 'stty -echo; read VALUE; stty echo; echo $VALUE' ) );
		echo "\n";
	} else {
		$value = trim( fgets( STDIN ) );
	}

	return '' !== $value ? $value : $default;
}

function dp_info( $msg ) {
	echo "\033[36m→ {$msg}\033[0m\n";
}

function dp_success( $msg ) {
	echo "\033[32m✓ {$msg}\033[0m\n";
}

function dp_warn( $msg ) {
	echo "\033[33m⚠ {$msg}\033[0m\n";
}

function dp_error( $msg ) {
	echo "\033[31m✗ ERROR: {$msg}\033[0m\n";
	exit( 1 );
}

function dp_step( $n, $label ) {
	echo "\n\033[1mStep {$n}: {$label}\033[0m\n";
	echo str_repeat( '─', 50 ) . "\n";
}

function dp_cmd_exists( $cmd ) {
	$out = shell_exec( 'which ' . escapeshellarg( $cmd ) . ' 2>/dev/null' );
	return ! empty( $out );
}

function dp_download( $url, $dest ) {
	dp_info( "Downloading: {$url}" );
	dp_info( "Destination: {$dest}" );

	if ( dp_cmd_exists( 'curl' ) ) {
		$ret = 0;
		passthru( 'curl -kL --progress-bar -o ' . escapeshellarg( $dest ) . ' ' . escapeshellarg( $url ), $ret );
		if ( 0 !== $ret ) {
			dp_error( 'curl download failed (exit code ' . $ret . ').' );
		}
	} elseif ( ini_get( 'allow_url_fopen' ) ) {
		$ctx = stream_context_create( array(
			'http' => array( 'timeout' => 600 ),
			'ssl'  => array( 'verify_peer' => false, 'verify_peer_name' => false ),
		) );
		$data = file_get_contents( $url, false, $ctx ); // phpcs:ignore
		if ( false === $data ) {
			dp_error( 'file_get_contents download failed.' );
		}
		file_put_contents( $dest, $data ); // phpcs:ignore
	} else {
		dp_error( 'Neither curl nor allow_url_fopen is available. Cannot download file.' );
	}

	if ( ! file_exists( $dest ) || filesize( $dest ) < 100 ) {
		dp_error( 'Downloaded file appears to be empty or invalid.' );
	}
	dp_success( 'Download complete (' . number_format( filesize( $dest ) / 1024 / 1024, 2 ) . ' MB).' );
}

function dp_extract( $archive, $dest ) {
	dp_info( "Extracting archive to: {$dest}" );

	if ( dp_cmd_exists( 'tar' ) ) {
		$ret = 0;
		passthru( 'tar -xzf ' . escapeshellarg( $archive ) . ' -C ' . escapeshellarg( $dest ), $ret );
		if ( 0 !== $ret ) {
			dp_error( 'tar extraction failed (exit code ' . $ret . ').' );
		}
	} elseif ( class_exists( 'PharData' ) ) {
		try {
			$phar = new PharData( $archive );
			$phar->extractTo( $dest, null, true );
		} catch ( Exception $e ) {
			dp_error( 'PharData extraction failed: ' . $e->getMessage() );
		}
	} else {
		dp_error( 'Neither the tar command nor PharData is available. Cannot extract archive.' );
	}
	dp_success( 'Extraction complete.' );
}

function dp_update_wpconfig( $config_path, $db ) {
	if ( ! file_exists( $config_path ) ) {
		dp_warn( "wp-config.php not found at: {$config_path}" );
		return false;
	}

	$content = file_get_contents( $config_path ); // phpcs:ignore

	$replacements = array(
		'/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\)/'     => "define( 'DB_HOST', '" . addslashes( $db['host'] ) . "' )",
		'/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\)/'     => "define( 'DB_NAME', '" . addslashes( $db['name'] ) . "' )",
		'/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\)/'     => "define( 'DB_USER', '" . addslashes( $db['user'] ) . "' )",
		'/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\)/' => "define( 'DB_PASSWORD', '" . addslashes( $db['pass'] ) . "' )",
	);

	foreach ( $replacements as $pattern => $replacement ) {
		$new = preg_replace( $pattern, $replacement, $content );
		if ( null !== $new ) {
			$content = $new;
		}
	}

	// Add dynamic URL detection directly in wp-config.php if not already present.
	if ( strpos( $content, 'WP_HOME' ) === false ) {
		$dynamic_code = <<<'CODE'

// DualPress: Dynamic URL detection
if ( ! defined( 'WP_HOME' ) ) {
	$_dp_scheme = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) ? 'https' : 'http';
	$_dp_host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
	define( 'WP_HOME', $_dp_scheme . '://' . $_dp_host );
	define( 'WP_SITEURL', $_dp_scheme . '://' . $_dp_host );
}

CODE;
		// Insert after opening <?php tag.
		$content = preg_replace( '/^<\?php\s*/', "<?php" . $dynamic_code, $content, 1 );
		dp_info( 'Added dynamic URL detection to wp-config.php.' );
	}

	file_put_contents( $config_path, $content ); // phpcs:ignore
	dp_success( 'wp-config.php updated with new DB credentials.' );
	return true;
}

function dp_import_sql( $sql_file, $db ) {
	dp_info( "Importing SQL: {$sql_file}" );

	if ( dp_cmd_exists( 'mysql' ) ) {
		// Use relaxed SQL mode to avoid strict errors
		$cmd = sprintf(
			'mysql -h %s -u %s %s %s --init-command="SET SESSION sql_mode=\'NO_ENGINE_SUBSTITUTION\';" < %s',
			escapeshellarg( $db['host'] ),
			escapeshellarg( $db['user'] ),
			empty( $db['pass'] ) ? '' : '-p' . escapeshellarg( $db['pass'] ),
			escapeshellarg( $db['name'] ),
			escapeshellarg( $sql_file )
		);
		$ret = 0;
		passthru( $cmd, $ret );
		if ( 0 !== $ret ) {
			dp_error( 'mysql import failed (exit code ' . $ret . ').' );
		}
	} else {
		// Fall back to PDO.
		try {
			$dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
			$pdo = new PDO( $dsn, $db['user'], $db['pass'], array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$pdo->exec( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';" );
			$sql = file_get_contents( $sql_file ); // phpcs:ignore
			$pdo->exec( $sql );
		} catch ( Exception $e ) {
			dp_error( 'SQL import failed: ' . $e->getMessage() );
		}
	}
	dp_success( 'SQL imported.' );
}

function dp_url_replace( $pdo, $prefix, $old_url, $new_url ) {
	dp_info( "Replacing URLs: {$old_url} → {$new_url}" );
	$tables = array( $prefix . 'options', $prefix . 'postmeta', $prefix . 'posts' );

	foreach ( $tables as $table ) {
		try {
			if ( $prefix . 'options' === $table ) {
				$pdo->exec( "UPDATE `{$table}` SET option_value = REPLACE(option_value, " . $pdo->quote( $old_url ) . ', ' . $pdo->quote( $new_url ) . ') WHERE option_name IN (\'siteurl\', \'home\')' );
			}
		} catch ( Exception $e ) {
			dp_warn( 'URL replacement on ' . $table . ' failed: ' . $e->getMessage() );
		}
	}
	dp_success( 'Site URL updated.' );
}

function dp_read_env( $env_file ) {
	$cfg = array();
	foreach ( file( $env_file ) as $line ) { // phpcs:ignore
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) continue;
		list( $k, $v ) = array_map( 'trim', explode( '=', $line, 2 ) ) + array( '', '' );
		$cfg[ $k ] = trim( $v, '\'"' );
	}
	return $cfg;
}

function dp_detect_prefix( $sql_file ) {
	// Read first 100KB of SQL to find table prefix
	$handle = fopen( $sql_file, 'r' ); // phpcs:ignore
	if ( ! $handle ) {
		return 'wp_';
	}
	
	$content = fread( $handle, 100 * 1024 ); // phpcs:ignore
	fclose( $handle ); // phpcs:ignore
	
	// Look for CREATE TABLE statements with common WP tables
	$wp_tables = array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'termmeta', 'comments' );
	
	foreach ( $wp_tables as $table ) {
		if ( preg_match( '/CREATE TABLE [`"\']?([a-zA-Z0-9_]+)' . preg_quote( $table, '/' ) . '[`"\']?\s/i', $content, $m ) ) {
			$prefix = str_replace( $table, '', $m[1] );
			if ( ! empty( $prefix ) ) {
				return $prefix;
			}
		}
	}
	
	return 'wp_';
}

function dp_write_env( $env_file, $db ) {
	$contents  = "DB_HOST=" . $db['host'] . "\n";
	$contents .= "DB_NAME=" . $db['name'] . "\n";
	$contents .= "DB_USER=" . $db['user'] . "\n";
	$contents .= "DB_PASSWORD=" . $db['pass'] . "\n";
	$contents .= "WP_TABLE_PREFIX=" . $db['prefix'] . "\n";
	file_put_contents( $env_file, $contents ); // phpcs:ignore
	dp_success( 'Saved credentials to .env' );
}

// ------------------------------------------------------------------ //
// Main                                                                //
// ------------------------------------------------------------------ //

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║   DualPress Deploy Tool v" . DEPLOY_VERSION . "           ║\n";
echo "╚══════════════════════════════════════════╝\n";
echo "\n";

$cwd = getcwd();
dp_info( "Working directory: {$cwd}" );

// ---- Step 1: Backup URL ----
dp_step( 1, 'Backup Archive' );
$backup_url = dp_prompt( 'Enter URL to the .tar.gz backup file' );
if ( empty( $backup_url ) || ! filter_var( $backup_url, FILTER_VALIDATE_URL ) ) {
	dp_error( 'A valid URL is required.' );
}

// ---- Step 2: Database credentials ----
dp_step( 2, 'Database Credentials' );
$env_file = $cwd . '/.env';
$db       = array();

if ( file_exists( $env_file ) ) {
	dp_info( '.env file found — reading credentials.' );
	$env    = dp_read_env( $env_file );
	$db = array(
		'host'   => $env['DB_HOST']         ?? 'localhost',
		'name'   => $env['DB_NAME']         ?? '',
		'user'   => $env['DB_USER']         ?? '',
		'pass'   => $env['DB_PASSWORD']     ?? '',
		'prefix' => '', // Will be auto-detected from SQL dump
	);

	if ( empty( $db['name'] ) || empty( $db['user'] ) ) {
		dp_warn( '.env is incomplete — need to enter credentials manually.' );
		$db = array();
	} else {
		echo "  Host:     " . $db['host']   . "\n";
		echo "  Database: " . $db['name']   . "\n";
		echo "  User:     " . $db['user']   . "\n";
		echo "  (Table prefix will be auto-detected from backup)\n";
		$confirm = strtolower( dp_prompt( 'Use these credentials? [Y/n]', 'Y' ) );
		if ( 'n' === $confirm ) {
			$db = array();
		}
	}
}

if ( empty( $db ) ) {
	$db['host']   = dp_prompt( 'Database host',   'localhost' );
	$db['name']   = dp_prompt( 'Database name' );
	$db['user']   = dp_prompt( 'Database user' );
	$db['pass']   = dp_prompt( 'Database password', '', true );
	$db['prefix'] = ''; // Will be auto-detected from SQL dump

	if ( empty( $db['name'] ) || empty( $db['user'] ) ) {
		dp_error( 'Database name and user are required.' );
	}

	$save = strtolower( dp_prompt( 'Save credentials to .env? [y/N]', 'N' ) );
	$db['_save_env'] = ( 'y' === $save );
}

// ---- Step 3: Download ----
dp_step( 3, 'Download Archive' );
$archive_name = basename( parse_url( $backup_url, PHP_URL_PATH ) );
$archive_path = $cwd . '/' . $archive_name;

if ( file_exists( $archive_path ) ) {
	$overwrite = strtolower( dp_prompt( "File '{$archive_name}' already exists. Overwrite? [y/N]", 'N' ) );
	if ( 'y' !== $overwrite ) {
		dp_info( 'Using existing archive.' );
	} else {
		dp_download( $backup_url, $archive_path );
	}
} else {
	dp_download( $backup_url, $archive_path );
}

// ---- Step 4: Check for existing files ----
dp_step( 4, 'Check Existing Files' );
$existing_files = array_filter( scandir( $cwd ), function( $f ) use ( $cwd, $archive_name ) {
	// Ignore . , .. , .env, and the archive itself
	if ( in_array( $f, array( '.', '..', '.env', $archive_name, 'deploy.php' ), true ) ) {
		return false;
	}
	return true;
} );

if ( ! empty( $existing_files ) ) {
	dp_warn( 'Found existing files/folders in this directory:' );
	$show_files = array_slice( $existing_files, 0, 10 );
	foreach ( $show_files as $f ) {
		echo "  - {$f}\n";
	}
	if ( count( $existing_files ) > 10 ) {
		echo "  ... and " . ( count( $existing_files ) - 10 ) . " more\n";
	}
	$clean = strtolower( dp_prompt( 'Delete all existing files before extracting? [y/N]', 'N' ) );
	if ( 'y' === $clean ) {
		dp_info( 'Cleaning directory...' );
		foreach ( $existing_files as $f ) {
			$path = $cwd . '/' . $f;
			if ( is_dir( $path ) ) {
				// Recursive delete
				$cmd = 'rm -rf ' . escapeshellarg( $path );
				shell_exec( $cmd );
			} else {
				unlink( $path ); // phpcs:ignore
			}
		}
		dp_success( 'Directory cleaned.' );
	}
} else {
	dp_info( 'Directory is clean.' );
}

// ---- Step 5: Extract ----
dp_step( 5, 'Extract Archive' );
dp_extract( $archive_path, $cwd );

// ---- Step 6: Auto-detect table prefix ----
dp_step( 6, 'Detect Table Prefix' );
$sql_file = $cwd . '/db-dump.sql';
if ( file_exists( $sql_file ) ) {
	$detected_prefix = dp_detect_prefix( $sql_file );
	dp_success( "Detected table prefix: {$detected_prefix}" );
	$db['prefix'] = $detected_prefix;
} else {
	dp_warn( 'db-dump.sql not found. Using default prefix wp_' );
	$db['prefix'] = 'wp_';
}

// Save .env if requested (now with detected prefix)
if ( ! empty( $db['_save_env'] ) ) {
	dp_write_env( $env_file, $db );
}

// ---- Step 7: Update wp-config.php ----
dp_step( 7, 'Configure wp-config.php' );
$config_path = $cwd . '/wp-config.php';
if ( ! file_exists( $config_path ) ) {
	dp_warn( 'wp-config.php not found. You may need to create it manually.' );
} else {
	dp_update_wpconfig( $config_path, $db );
}

// ---- Step 8: Import SQL ----
dp_step( 8, 'Import Database' );
$sql_file = $cwd . '/db-dump.sql';
if ( ! file_exists( $sql_file ) ) {
	dp_warn( 'db-dump.sql not found in extracted archive. Skipping DB import.' );
} else {
	dp_import_sql( $sql_file, $db );

	// Optional URL replacement.
	$old_url = dp_prompt( "\nEnter OLD site URL for search-replace (leave blank to skip)" );
	if ( ! empty( $old_url ) ) {
		$new_url = dp_prompt( 'Enter NEW site URL' );
		if ( ! empty( $new_url ) ) {
			try {
				$dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
				$pdo = new PDO( $dsn, $db['user'], $db['pass'], array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
				dp_url_replace( $pdo, $db['prefix'], $old_url, $new_url );
			} catch ( Exception $e ) {
				dp_warn( 'Could not connect to DB for URL replacement: ' . $e->getMessage() );
			}
		}
	}

	// Clean up SQL file.
	unlink( $sql_file ); // phpcs:ignore
}

// ---- Done ----
echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║          Deployment Complete!            ║\n";
echo "╚══════════════════════════════════════════╝\n";
echo "\n";
dp_success( 'Your WordPress site has been restored.' );
dp_info( 'Next steps:' );
echo "  1. Configure your web server (nginx/Apache) to serve from: {$cwd}\n";
echo "  2. Set correct file permissions: chmod 755 and chown www-data:www-data\n";
echo "  3. Install/activate WordPress plugins if needed\n";

// ---- Cleanup Prompt ----
$cleanup = dp_prompt( 'Delete deploy.php and backup archive? [Y/n]', 'Y' );
if ( strtolower( trim( $cleanup ) ) !== 'n' ) {
	// Delete deploy.php
	$deploy_file = __FILE__;
	if ( file_exists( $deploy_file ) ) {
		unlink( $deploy_file );
		dp_success( 'Deleted deploy.php' );
	}
	
	// Delete backup archive
	if ( ! empty( $archive_path ) && file_exists( $archive_path ) ) {
		unlink( $archive_path );
		dp_success( 'Deleted backup archive: ' . basename( $archive_path ) );
	}
} else {
	dp_info( 'Keeping deploy.php and backup archive.' );
	dp_warn( 'Remember to delete these files manually for security!' );
}

echo "\n";
