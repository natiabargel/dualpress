# Changelog

## [0.8.1] - 2026-03-29

### Fixed
- Fixed SSL verification not working in test_connection (cURL error 60 with self-signed certs)
- Fixed daemon AJAX handlers being outside class (PHP fatal error)
- Changed crontab example to use simple `php` instead of PHP_BINARY path


## [0.8.0] - 2026-03-29

### Added
- **Real-time Sync Daemon**: New background process that continuously monitors and syncs changes
- Daemon runs as a persistent PHP process, checking for changes every X seconds (configurable)
- Toggle to enable/disable daemon from the Connection settings page
- Crontab example to auto-restart daemon if it stops
- daemon-ensure.php script for cron-based health checks


## [0.7.3] - 2026-03-29

### Added
- Post-deploy cleanup prompt: asks to delete deploy.php and backup archive (default: Yes)
- Improves security by encouraging removal of sensitive files after deployment


## [0.7.2] - 2026-03-29

### Fixed
- Changed User-Agent from WordPress default to 'DualPress/VERSION' to avoid being blocked by uPress nginx security rules that block WordPress user agents.
- This fixes HTTP 405 errors when syncing between uPress-hosted sites.


All notable changes to DualPress will be documented in this file.

## [0.7.1] - 2026-03-29

### Changed
- **Dynamic URL in wp-config.php**: Deploy tool now injects WP_HOME/WP_SITEURL detection code directly into wp-config.php instead of separate file
- Automatically detects scheme (http/https) and host from request
- Prevents redirect issues when restoring to different URL

## [0.7.0] - 2026-03-29

### Added
- **Backup Tool**: New full-site backup feature under DualPress menu
  - Creates `.tar.gz` archive with files + database
  - Real-time progress bar during backup
  - Options to skip database, files, or uploads
  - Custom exclusion paths
  - Auto-excludes cache dirs, logs, and DualPress internal data
  - Download and delete existing backups
  - Direct URL with curl command for CLI download
- **Deploy Tool**: Standalone `deploy.php` CLI script for restoring backups
  - Auto-detects table prefix from SQL dump
  - Reads/saves DB credentials to `.env` file
  - Checks for existing files before extraction
  - Relaxed SQL mode for compatibility
  - Skip SSL verification for self-signed certs
- **Site URL Check**: New section on Connection page
  - Shows `home` and `siteurl` vs current server URL
  - Visual mismatch indicator (red/green)
  - One-click fix button via AJAX

### Fixed
- **DualPress options excluded from backup**: `dualpress_*` options in wp_options are now excluded from SQL dump to prevent overwriting destination site settings

## [0.6.5] - 2026-03-26

### Added
- **DB Bundle Size setting**: Configure max size per database sync request (default 2MB)
- **DB Compression setting**: Optional gzip compression for database sync (default enabled)
- **Full table sync button**: "Sync All" button per table with live progress percentage
- **Table truncate endpoint**: `/table-truncate` for full sync cleanup

### Fixed
- **Comment trash/untrash hooks**: Changed from `trash_comment` to `trashed_comment` hook (fires after DB update, not before)
- **Gzip compression for table-data**: Fixed REST API JSON parsing issue by using base64 encoding for compressed payloads

## [0.6.4] - 2026-03-26

### Changed
- **Faster file sync**: Increased default batch size from 100 to 500 files per cron run
- **Use configured bundle size**: Batch selection now uses the configured Bundle Size (default 10MB) instead of hardcoded 5MB
- **Removed inter-bundle delay**: Removed unnecessary 25ms delay between bundle transfers

## [0.6.3] - 2026-03-26

### Changed
- **File sync exempt from rate limit**: File sync endpoints (`/file-push`, `/file-chunk`, `/file-bundle`, `/finalize`) are now exempt from API rate limiting since they already use bundling for efficiency

## [0.6.2] - 2026-03-26

### Fixed
- **Sync Settings not syncing to remote**: Added missing settings to remote sync: `db_sync_interval`, `db_sync_method`, `excluded_tables`, `excluded_meta_keys`, `excluded_option_keys`
- **REST API allowed keys**: Added the new DB sync settings to the allowed keys list in `/settings` endpoint

## [0.6.1] - 2026-03-26

### Fixed
- **PHP 8.1+ deprecation warning**: Fixed `strip_tags(): Passing null to parameter #1` on Table Sync Manager page
- **Admin menu highlight**: DualPress menu now stays open with DB Tools highlighted when viewing Table Sync Manager

## [0.6.0] - 2026-03-25

### Added

#### File Sync - Staging Directory System
- **New staging workflow**: Files now sync to `wp-content/dualpress-tmp/` before being moved to final location
- **FINALIZE queue action**: After all files sync, a FINALIZE command moves them from staging and activates the plugin/theme
- **`/finalize` REST endpoint**: Handles atomic swap from staging to production
- **Safe plugin activation**: Plugins are only activated after ALL files are in place

#### File Sync - Compression & Bundling
- **Gzip compression**: File bundles are compressed before transfer (configurable)
- **Simplified settings**: Reduced to just "Bundle Size (MB)" and "Enable Compression"
- **Removed old settings**: Batch Size, Bundle Size (files), Rate Limit, Delay — simplified to single bundle size

#### Table Schema Sync
- **Automatic table detection**: When activating a plugin that creates tables (e.g., WooCommerce), schemas are automatically synced
- **`DualPress_Table_Sync` class**: Handles schema extraction and remote creation
- **`/table-sync` REST endpoint**: Receives and creates table schemas on remote server
- **Pre-activation snapshot**: Takes table snapshot before plugin activation to detect new tables

#### Settings Improvements
- **Transfer Settings section**: Simplified UI with Bundle Size (MB) and Compression toggle
- Renamed "Tools" menu to "DB Tools"

### Changed

#### Options Sync Exclusions
- **`active_plugins` excluded**: No longer synced via options — managed by file-sync module only
- **Theme options excluded**: `current_theme`, `stylesheet`, `template` now excluded
- Prevents plugin activation before files exist on remote

#### File Sync Flow
- Changed from `.syncing` suffix to `.NEW` staging directory (later changed to `dualpress-tmp/`)
- `prepare` action creates staging directory
- `finalize` action swaps staging with production and activates

### Fixed
- Plugin activation race condition: Files now fully sync before activation
- 502 errors when plugin activated with missing files
- ActionScheduler table creation on remote after WooCommerce sync

### Technical Details

#### New Files
- `includes/class-table-sync.php` — Table schema synchronization

#### Modified Files
- `modules/file-sync/class-file-sync.php` — Staging directory support, FINALIZE handling
- `modules/file-sync/class-file-queue.php` — `enqueue_finalize()` method
- `api/class-rest-api.php` — `/finalize` and `/table-sync` endpoints
- `admin/class-admin.php` — Simplified transfer settings
- `admin/views/tab-file-sync.php` — New Transfer Settings UI
- `includes/class-hook-listener.php` — Excluded `active_plugins` from sync

#### Queue Actions
- `PUSH` — Send file to remote
- `DELETE` — Delete file on remote  
- `FINALIZE` — Move files from staging to final location (format: `FINALIZE:type:slug`)

---

## [0.5.6] - Previous version

- Initial file sync with bundling
- Plugin/theme activation hooks
- Basic compression support

## [0.6.1] - 2026-03-25

### Fixed
- Added 'FINALIZE' to queue action enum in database schema
- FINALIZE commands now properly saved and processed

## [0.6.2] - 2026-03-25

### Added
- Full Sync now creates missing tables on remote before syncing data
- New `/tables` REST endpoint to list database tables
- `sync_missing_tables()` compares local vs remote schemas

## [0.6.3] - 2026-03-25

### Fixed
- FINALIZE now waits for ALL files of plugin/theme to complete before executing
- Prevents race condition where FINALIZE runs before all files are synced

## [0.6.4] - 2026-03-25

### Changed
- FINALIZE runs in separate batch, always last
- Only processes when NO other pending items exist in queue

## [0.7.0] - 2026-03-25

### Added
- Hourly cron job to sync missing tables with data
- `/table-data` REST endpoint for bulk row insert
- `sync_missing_tables_with_data()` finds missing tables, creates them, and syncs data
- Automatic data sync for plugin tables (Redirection, WooCommerce, etc.)

## [0.7.1] - 2026-03-25

### Changed
- Full Sync now uses `sync_missing_tables_with_data()` — syncs tables AND data
- Removed redundant `sync_missing_tables()` from Sender class

## [0.8.0] - 2026-03-25

### Added
- DB Sync Interval setting (15min, 30min, 1h, 2h, 4h, 12h, 24h, or disabled)
- `get_plugin_tables()` to identify non-core tables for sync

### Changed
- DB sync now syncs ALL plugin tables, not just missing ones
- Each sync cycle updates data (REPLACE INTO) for plugin tables
- Excludes core WP tables and cache/session tables

## [0.8.1] - 2026-03-25

### Fixed
- Secondary servers in active-passive mode no longer queue outbound changes
- Added `should_queue_changes()` helper to File Sync and Hook Listener
- Prevents wasted queue entries on read-only replicas

## [0.8.2] - 2026-03-25

### Changed
- Replaced "Custom Tables" (include) with "Excluded Tables" (exclude)
- User can now specify tables to exclude from sync
- Excluded tables are skipped in both DB sync and table sync

## [0.8.3] - 2026-03-25

### Fixed
- Fixed infinite recursion in `should_queue_changes()` causing memory exhaustion

## [0.8.4] - 2026-03-25

### Fixed
- Single-file plugins (like Hello Dolly) now sync correctly
- Added support for single-file plugin staging and finalization

## [0.8.5] - 2026-03-25

### Fixed
- Secondary servers in active-passive mode now skip DB queue writes too
- Added check in DualPress_Queue::add() for server role

## [0.9.0] - 2026-03-26

### Added
- **DB Sync Method setting** with three options:
  - **Last ID Tracking** (default): Only syncs rows with ID > last synced. Best for append-only tables.
  - **Checksum Comparison**: Compares table hash first, syncs only if different. Efficient for rarely-changed tables.
  - **Full Table Sync**: Syncs all rows every time. Use only for small tables.
- `/table-checksum` REST endpoint for checksum comparison
- Stores last synced ID per table in `dualpress_last_synced_{table}` option

## [0.9.1] - 2026-03-26

### Added
- **Table Sync Manager** — new admin page with live visual interface
  - Shows all plugin tables with row counts
  - Real-time sync progress with status badges
  - Sync statistics: tables, rows synced, skipped, elapsed time
  - Optional log output
  - Start/Stop controls
- `sync_single_table()` method for AJAX-based table sync
- AJAX endpoint `dualpress_sync_single_table`

## [0.9.2] - 2026-03-26

### Added
- **Excluded Meta Keys setting** — filter out server-specific usermeta/postmeta
  - Default excluded: `session_tokens`, `wc_last_active`, `_woocommerce_persistent_cart*`, `_edit_lock`, `_edit_last`
  - Supports wildcard patterns with `*` suffix
- **Excluded Option Keys setting** — filter out server-specific options
  - Default excluded: `cron`, `rewrite_rules`, `recently_edited`, `auto_updater.lock`, `core_updater.lock`
- Settings UI for both in Sync Settings tab

## [0.9.6] - 2026-03-26

### Fixed
- Fixed PHP 8.1+ deprecation warnings (null passed to strpos/str_replace)
- Changed hidden submenu page parent from `null` to empty string
- Added null checks for meta_key, option_name, and file paths

## [0.9.7] - 2026-03-26

### Fixed
- Cron events now auto-recover if they go missing
- Added `maybe_schedule_events()` on admin_init to ensure cron is always running

## [0.9.8] - 2026-03-26

### Added
- Plugin deactivation now syncs to remote server
- New `/plugin-control?action=deactivate` endpoint
- `on_plugin_deactivated` hook sends deactivation request to remote

## [0.9.9] - 2026-03-26

### Fixed
- Fixed `sanitize_file_name` stripping slashes from plugin paths (use `sanitize_text_field` instead)
- Plugin activation now syncs to remote when file sync is disabled
- FINALIZE now clears plugins cache before trying to activate (fixes plugin not found after move)
- Added logging for activation errors in FINALIZE

### Changed
- Immediate activation sync only when file_sync_plugins is OFF (FINALIZE handles it when ON)
