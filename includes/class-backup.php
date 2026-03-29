<?php
/**
 * Backup engine — file archiving + DB dump.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------ //
// Minimal streaming tar writer                                         //
// ------------------------------------------------------------------ //

/**
 * Appends POSIX ustar entries to a .tar file without loading the whole
 * archive into memory.  Call finalize() once at the very end.
 */
class DualPress_Tar_Writer {

	/** @var resource|null */
	private $fh;

	/**
	 * @param string $path File path (created if absent, appended if exists).
	 * @throws RuntimeException
	 */
	public function __construct( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$this->fh = fopen( $path, 'ab' );
		if ( ! $this->fh ) {
			throw new RuntimeException( 'Cannot open tar file: ' . $path );
		}
	}

	/**
	 * Add a real file from disk.
	 *
	 * @param string $real_path    Absolute path on disk.
	 * @param string $archive_name Relative path inside archive.
	 * @return bool
	 */
	public function add_file( $real_path, $archive_name ) {
		if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			return false;
		}
		$size  = filesize( $real_path );
		$mtime = (int) filemtime( $real_path );
		$mode  = (int) fileperms( $real_path ) & 07777;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		fwrite( $this->fh, $this->build_header( $archive_name, $size, $mtime, $mode, '0' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$fp = fopen( $real_path, 'rb' );
		if ( $fp ) {
			while ( ! feof( $fp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions
				fwrite( $this->fh, fread( $fp, 65536 ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			fclose( $fp );
		}

		if ( $size > 0 ) {
			$pad = ( 512 - ( $size % 512 ) ) % 512;
			if ( $pad > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
				fwrite( $this->fh, str_repeat( "\0", $pad ) );
			}
		}
		return true;
	}

	/**
	 * Add a string as a file entry.
	 *
	 * @param string $content      File content.
	 * @param string $archive_name Path inside archive.
	 */
	public function add_string( $content, $archive_name ) {
		$size = strlen( $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		fwrite( $this->fh, $this->build_header( $archive_name, $size, time(), 0644, '0' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		fwrite( $this->fh, $content );

		if ( $size > 0 ) {
			$pad = ( 512 - ( $size % 512 ) ) % 512;
			if ( $pad > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
				fwrite( $this->fh, str_repeat( "\0", $pad ) );
			}
		}
	}

	/** Close without EOF markers (use between chunks). */
	public function close() {
		if ( $this->fh ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	/** Write two null blocks (end-of-archive) and close. */
	public function finalize() {
		if ( $this->fh ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
			fwrite( $this->fh, str_repeat( "\0", 1024 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	/**
	 * Build a 512-byte POSIX ustar header.
	 */
	private function build_header( $name, $size, $mtime, $mode, $typeflag ) {
		$prefix = '';
		if ( strlen( $name ) > 100 ) {
			$cut = strrpos( substr( $name, 0, 155 ), '/' );
			if ( false !== $cut && strlen( $name ) - $cut - 1 <= 100 ) {
				$prefix = substr( $name, 0, $cut );
				$name   = substr( $name, $cut + 1 );
			} else {
				$name = substr( $name, 0, 100 );
			}
		}

		$h  = pack( 'a100', $name );
		$h .= pack( 'a8',   sprintf( '%07o', $mode ) );
		$h .= pack( 'a8',   '0000000' );
		$h .= pack( 'a8',   '0000000' );
		$h .= pack( 'a12',  sprintf( '%011o', $size ) );
		$h .= pack( 'a12',  sprintf( '%011o', $mtime ) );
		$h .= '        ';              // checksum placeholder.
		$h .= $typeflag;
		$h .= pack( 'a100', '' );      // link name.
		$h .= "ustar\000";
		$h .= '00';
		$h .= pack( 'a32', '' );       // uname.
		$h .= pack( 'a32', '' );       // gname.
		$h .= pack( 'a8',  '' );       // devmajor.
		$h .= pack( 'a8',  '' );       // devminor.
		$h .= pack( 'a155', $prefix );
		$h .= pack( 'a12',  '' );      // padding.

		// Compute & insert checksum (bytes 148-155).
		$chk = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$chk += ord( $h[ $i ] );
		}
		$chk_str = sprintf( '%06o', $chk ) . "\0 ";
		return substr( $h, 0, 148 ) . $chk_str . substr( $h, 156 );
	}
}

// ------------------------------------------------------------------ //
// Backup engine                                                        //
// ------------------------------------------------------------------ //

/**
 * Static backup helpers used by the admin AJAX handlers.
 */
class DualPress_Backup {

	/** Files archived per AJAX chunk. */
	const CHUNK_SIZE = 500;

	/**
	 * Default exclusion patterns (relative to ABSPATH, POSIX paths).
	 * Directories must end with '/'.
	 */
	const DEFAULT_EXCLUDES = array(
		'wp-content/cache/',
		'wp-content/uploads/cache/',
		'wp-content/backup*/',
		'wp-content/updraft/',
		'wp-content/ai1wm-backups/',
		'wp-content/debug.log',
		'.git/',
		'*.log',
		'dualpress*.tar.gz',
	);

	/** Table name substrings that indicate a cache/transient table. */
	const CACHE_TABLE_PATTERNS = array(
		'wfcache', 'wflive', 'wf2fa', 'wfblockediplog',
		'litespeed', 'lscwp',
		'rocket_cache',
		'w3tc',
		'breeze',
		'wpfc',
	);

	// ---------------------------------------------------------------- //
	// Public API                                                        //
	// ---------------------------------------------------------------- //

	/**
	 * Phase 1 — scan files, create temp dir + empty .tar, return file count.
	 *
	 * @param array $extra_excludes Additional paths to exclude (user-supplied).
	 * @param array $options        Backup options: skip_db, skip_files, skip_uploads.
	 * @return array|WP_Error  Keys: backup_id, total_files, filename.
	 */
	public static function init( $extra_excludes = array(), $options = array() ) {
		@set_time_limit( 300 ); // phpcs:ignore

		$skip_db      = ! empty( $options['skip_db'] );
		$skip_files   = ! empty( $options['skip_files'] );
		$skip_uploads = ! empty( $options['skip_uploads'] );

		$id        = substr( md5( uniqid( 'dp', true ) ), 0, 10 );
		$site_slug = self::site_slug();
		$tar_name  = 'dualpress-' . $id . '-' . $site_slug . '.tar';
		$gz_name   = $tar_name . '.gz';

		$temp_dir = self::temp_base() . '/' . $id;
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'mkdir', 'Cannot create temp directory.' );
		}

		$excludes = array_merge( self::DEFAULT_EXCLUDES, array_filter( $extra_excludes ) );

		// Add uploads to excludes if skip_uploads is enabled.
		if ( $skip_uploads ) {
			$excludes[] = 'wp-content/uploads/';
		}

		// Scan files unless skip_files is enabled.
		if ( $skip_files ) {
			$files = array();
		} else {
			$files = self::scan_files( $excludes );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $temp_dir . '/files.txt', implode( "\n", $files ) );

		$info = array(
			'id'          => $id,
			'tar_name'    => $tar_name,
			'gz_name'     => $gz_name,
			'temp_dir'    => $temp_dir,
			'total_files' => count( $files ),
			'processed'   => 0,
			'status'      => 'initialized',
			'created'     => time(),
			'skip_db'     => $skip_db,
			'skip_files'  => $skip_files,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $temp_dir . '/info.json', wp_json_encode( $info ) );

		// Touch the tar file so the writer can open it in append mode.
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $temp_dir . '/' . $tar_name, '' );

		return array(
			'backup_id'   => $id,
			'total_files' => count( $files ),
			'filename'    => $gz_name,
		);
	}

	/**
	 * Phase 2 — append a chunk of files to the .tar archive.
	 *
	 * @param string $id     Backup ID from init().
	 * @param int    $offset File-list offset (0-based).
	 * @return array|WP_Error  Keys: processed, total, progress_pct, has_more.
	 */
	public static function chunk( $id, $offset ) {
		@set_time_limit( 120 ); // phpcs:ignore

		$info = self::load_info( $id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$temp_dir  = $info['temp_dir'];
		$tar_path  = $temp_dir . '/' . $info['tar_name'];
		$files_txt = $temp_dir . '/files.txt';

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$all_files = explode( "\n", file_get_contents( $files_txt ) );
		$all_files = array_filter( $all_files );
		$total     = count( $all_files );
		$chunk     = array_slice( $all_files, $offset, self::CHUNK_SIZE );

		$writer = new DualPress_Tar_Writer( $tar_path );
		$added  = 0;
		foreach ( $chunk as $real_path ) {
			$archive_name = self::relative_path( $real_path );
			if ( $writer->add_file( $real_path, $archive_name ) ) {
				$added++;
			}
		}
		$writer->close();

		$processed  = min( $total, $offset + count( $chunk ) );
		$info['processed'] = $processed;
		$info['status']    = 'archiving';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $temp_dir . '/info.json', wp_json_encode( $info ) );

		$has_more = ( $offset + self::CHUNK_SIZE ) < $total;
		// Reserve last 10% for DB dump + compression.
		$pct = $total > 0 ? (int) round( ( $processed / $total ) * 90 ) : 90;

		return array(
			'processed'    => $processed,
			'total'        => $total,
			'added'        => $added,
			'progress_pct' => $pct,
			'has_more'     => $has_more,
		);
	}

	/**
	 * Phase 3 — dump DB, add SQL to archive, compress to .tar.gz.
	 *
	 * @param string $id Backup ID from init().
	 * @return array|WP_Error  Keys: filename, file_size, download_nonce.
	 */
	public static function finalize( $id ) {
		@set_time_limit( 300 ); // phpcs:ignore

		$info = self::load_info( $id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$temp_dir = $info['temp_dir'];
		$tar_path = $temp_dir . '/' . $info['tar_name'];
		$sql_path = $temp_dir . '/db-dump.sql';
		$gz_path  = ABSPATH . $info['gz_name'];

		$skip_db = ! empty( $info['skip_db'] );

		// Write DB dump (unless skipped).
		$writer = new DualPress_Tar_Writer( $tar_path );
		if ( ! $skip_db ) {
			$sql = self::generate_sql_dump();
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $sql_path, $sql );
			$writer->add_file( $sql_path, 'db-dump.sql' );
		}

		$writer->finalize();

		// Stream-compress .tar → .tar.gz.
		$gz = gzopen( $gz_path, 'wb6' ); // phpcs:ignore
		if ( ! $gz ) {
			return new WP_Error( 'gzip', 'Cannot create .tar.gz file at: ' . $gz_path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fh = fopen( $tar_path, 'rb' );
		while ( ! feof( $fh ) ) {
			gzwrite( $gz, fread( $fh, 65536 ) ); // phpcs:ignore
		}
		fclose( $fh ); // phpcs:ignore
		gzclose( $gz ); // phpcs:ignore

		// Clean up temp dir.
		self::rmdir_recursive( $temp_dir );

		$file_size = file_exists( $gz_path ) ? filesize( $gz_path ) : 0;

		return array(
			'filename'       => $info['gz_name'],
			'file_size'      => $file_size,
			'file_size_hr'   => size_format( $file_size ),
			'download_nonce' => wp_create_nonce( 'dualpress_backup_dl_' . $id ),
		);
	}

	/**
	 * Cancel a running backup and remove temp files.
	 *
	 * @param string $id Backup ID.
	 */
	public static function cancel( $id ) {
		$temp_dir = self::temp_base() . '/' . sanitize_key( $id );
		if ( is_dir( $temp_dir ) ) {
			self::rmdir_recursive( $temp_dir );
		}
	}

	/**
	 * List all existing backup .tar.gz files in ABSPATH.
	 *
	 * @return array  Array of arrays with keys: filename, size_hr, modified.
	 */
	public static function list_backups() {
		$files  = glob( ABSPATH . 'dualpress-*.tar.gz' );
		$result = array();
		if ( $files ) {
			foreach ( $files as $path ) {
				$result[] = array(
					'filename' => basename( $path ),
					'size_hr'  => size_format( filesize( $path ) ),
					'modified' => wp_date( 'Y-m-d H:i', filemtime( $path ) ),
				);
			}
		}
		// Newest first.
		usort( $result, function( $a, $b ) {
			return strcmp( $b['modified'], $a['modified'] );
		} );
		return $result;
	}

	// ---------------------------------------------------------------- //
	// Internals                                                         //
	// ---------------------------------------------------------------- //

	/**
	 * Scan ABSPATH recursively, applying exclusion patterns.
	 *
	 * @param array $excludes Exclusion patterns.
	 * @return string[]  Absolute file paths.
	 */
	private static function scan_files( $excludes ) {
		$abspath = rtrim( ABSPATH, '/\\' );
		$files   = array();

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$abspath,
					RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
				),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			$it->setMaxDepth( 30 );

			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$real = str_replace( '\\', '/', $file->getPathname() );
				$rel  = ltrim( str_replace( $abspath . '/', '', $real ), '/' );

				if ( ! self::is_excluded( $rel, $excludes ) ) {
					$files[] = $real;
				}
			}
		} catch ( Exception $e ) {
			// Swallow unreadable directory errors and continue.
		}

		return $files;
	}

	/**
	 * Check whether a relative path matches any exclusion pattern.
	 *
	 * Supported pattern types:
	 *   - dir/           → anything inside that directory
	 *   - prefix* /      → anything inside directories matching that glob
	 *   - *.ext          → any file with that extension
	 *   - root-glob*.ext → full-path glob match
	 *
	 * @param string   $rel      Relative path (forward slashes).
	 * @param string[] $excludes Patterns.
	 * @return bool
	 */
	private static function is_excluded( $rel, $excludes ) {
		foreach ( $excludes as $excl ) {
			$excl = trim( str_replace( '\\', '/', $excl ) );
			if ( '' === $excl ) {
				continue;
			}

			$is_dir = '/' === substr( $excl, -1 );

			if ( $is_dir ) {
				if ( false === strpos( $excl, '*' ) ) {
					// Simple directory prefix.
					if ( 0 === strpos( $rel . '/', $excl ) || 0 === strpos( $rel, $excl ) ) {
						return true;
					}
				} else {
					// Glob directory pattern, e.g. "wp-content/backup*/".
					$excl_base = rtrim( $excl, '/' );
					$parent    = ltrim( dirname( $excl_base ), '.' );
					$seg_glob  = basename( $excl_base );
					$check_rel = empty( $parent ) ? $rel : ( 0 === strpos( $rel, ltrim( $parent, '/' ) . '/' ) ? substr( $rel, strlen( ltrim( $parent, '/' ) ) + 1 ) : null );
					if ( null !== $check_rel ) {
						$first_seg = strstr( $check_rel, '/', true );
						if ( false !== $first_seg && fnmatch( $seg_glob, $first_seg ) ) {
							return true;
						}
					}
				}
				continue;
			}

			// Glob or exact match (also tries basename for patterns like "*.log").
			if ( fnmatch( $excl, $rel ) || fnmatch( $excl, basename( $rel ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generate a SQL dump of all relevant tables.
	 *
	 * Skips cache tables and DualPress internal tables.
	 *
	 * @return string SQL content.
	 */
	private static function generate_sql_dump() {
		global $wpdb;

		$lines   = array();
		$lines[] = '-- DualPress Backup — SQL Dump';
		$lines[] = '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = '-- Site: ' . esc_url( get_site_url() );
		$lines[] = '';
		$lines[] = 'SET NAMES utf8mb4;';
		$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
		$lines[] = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			if ( self::skip_table( $table ) ) {
				continue;
			}

			// CREATE TABLE statement.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $row ) {
				$lines[] = "DROP TABLE IF EXISTS `{$table}`;";
				$lines[] = $row[1] . ';';
				$lines[] = '';
			}

			// Row data in batches to avoid memory issues.
			$offset     = 0;
			$batch_size = 500;
			$is_options = ( $table === $wpdb->prefix . 'options' );
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
					ARRAY_A
				);
				if ( ! empty( $rows ) ) {
					// Filter out dualpress options from wp_options table.
					if ( $is_options ) {
						$rows = array_filter( $rows, function( $row ) {
							$name = isset( $row['option_name'] ) ? $row['option_name'] : '';
							return strpos( $name, 'dualpress_' ) !== 0;
						} );
						$rows = array_values( $rows ); // Re-index array.
					}
					
					if ( ! empty( $rows ) ) {
						$cols     = array_keys( $rows[0] );
						$col_list = '`' . implode( '`, `', array_map( 'esc_sql', $cols ) ) . '`';
						$vals     = array();
						foreach ( $rows as $r ) {
							$escaped = array_map( function ( $v ) {
								return null === $v ? 'NULL' : "'" . esc_sql( $v ) . "'";
							}, array_values( $r ) );
							$vals[] = '( ' . implode( ', ', $escaped ) . ' )';
						}
						$lines[] = "INSERT INTO `{$table}` ({$col_list}) VALUES";
						$lines[] = implode( ",\n", $vals ) . ';';
						$lines[] = '';
					}
				}
				$offset += $batch_size;
			} while ( count( $rows ) === $batch_size );
		}

		$lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';

		return implode( "\n", $lines );
	}

	/**
	 * Decide whether a table should be excluded from the SQL dump.
	 *
	 * @param string $table Table name (full, with prefix).
	 * @return bool
	 */
	private static function skip_table( $table ) {
		global $wpdb;

		// Always skip DualPress internal tables (all tables starting with prefix + dualpress).
		if ( strpos( $table, $wpdb->prefix . 'dualpress' ) === 0 ) {
			return true;
		}

		// Skip known cache-related table name patterns.
		$lower = strtolower( $table );
		foreach ( self::CACHE_TABLE_PATTERNS as $pat ) {
			if ( false !== strpos( $lower, $pat ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get relative path of a file inside ABSPATH.
	 */
	private static function relative_path( $real_path ) {
		$base = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
		$norm = str_replace( '\\', '/', $real_path );
		return ltrim( str_replace( $base, '', $norm ), '/' );
	}

	/**
	 * WordPress-slug for the site name.
	 */
	private static function site_slug() {
		$name = sanitize_title( get_bloginfo( 'name' ) );
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $name ) );
		$name = trim( $name, '-' );
		return $name !== '' ? substr( $name, 0, 30 ) : 'wordpress';
	}

	/** Base temp directory (inside WP uploads). */
	private static function temp_base() {
		$uploads = wp_upload_dir();
		return $uploads['basedir'] . '/.dualpress-temp';
	}

	/** Load info.json for a backup session, or WP_Error. */
	private static function load_info( $id ) {
		$id       = sanitize_key( $id );
		$dir      = self::temp_base() . '/' . $id;
		$info_file = $dir . '/info.json';
		if ( ! file_exists( $info_file ) ) {
			return new WP_Error( 'not_found', 'Backup session not found or expired.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		return json_decode( file_get_contents( $info_file ), true );
	}

	/** Recursively delete a directory. */
	private static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $f ) {
				$f->isFile() ? unlink( $f->getPathname() ) : rmdir( $f->getPathname() ); // phpcs:ignore
			}
		} catch ( Exception $e ) { // phpcs:ignore
		}
		rmdir( $dir ); // phpcs:ignore
	}
}
