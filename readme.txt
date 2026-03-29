=== DualPress - WordPress Bi-Directional Sync ===
Contributors: upress
Tags: sync, replication, backup, multi-site, database sync, file sync, disaster recovery
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.8.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time bi-directional synchronization between two WordPress sites. Keep your content, media, and database in sync across servers.

== Description ==

DualPress enables real-time bi-directional synchronization between two WordPress installations. Perfect for disaster recovery, load balancing, or maintaining staging/production environments.

**Key Features:**

* **Real-time Database Sync** - Posts, pages, users, comments, options, and custom tables
* **File Sync** - Media uploads, themes, and plugins (optional)
* **Bi-directional** - Changes flow both ways automatically
* **Conflict Resolution** - Automatic handling of simultaneous edits
* **Real-time Daemon** - Optional background process for instant sync (no cron delays)
* **WooCommerce Support** - Orders, products, and customer data
* **Secure** - HMAC authentication between sites
* **Bandwidth Efficient** - Only syncs changes, with compression and bundling

**Use Cases:**

* **Disaster Recovery** - Automatic failover to secondary server
* **Geographic Distribution** - Serve content from multiple locations
* **Development Workflow** - Keep staging and production in sync
* **Load Balancing** - Distribute traffic across multiple servers

**How It Works:**

1. Install DualPress on both WordPress sites
2. Configure one as Primary, one as Secondary
3. Enter the remote site URL and shared secret key
4. Enable sync - changes automatically replicate in real-time

== Installation ==

1. Upload the `dualpress` folder to `/wp-content/plugins/`
2. Activate the plugin on **both** WordPress sites
3. Go to DualPress → Connection on each site
4. Set one site as "Primary" and the other as "Secondary"
5. Enter the remote site URL and generate/share the secret key
6. Click "Test Connection" to verify communication
7. Enable the sync options you need

**Important:** Both sites must have DualPress installed and configured with matching secret keys.

== Frequently Asked Questions ==

= Do both sites need to be on the same server? =

No. DualPress works across different servers, hosting providers, and even continents. The only requirement is that both sites can reach each other via HTTP/HTTPS.

= What happens if both sites are edited at the same time? =

DualPress includes conflict detection. By default, the most recent change wins, but you can configure this behavior in the settings.

= Does it sync media files? =

Yes, File Sync is available as an optional module. You can sync uploads, themes, and/or plugins.

= Is it compatible with WooCommerce? =

Yes. DualPress can sync WooCommerce orders, products, customers, and all related data.

= What about multisite? =

Currently DualPress supports single-site WordPress installations. Multisite support is planned for a future release.

= How secure is the sync? =

All communication is authenticated using HMAC-SHA256 signatures with a shared secret key. We strongly recommend using HTTPS between sites.

== Screenshots ==

1. Connection settings - Configure the remote site URL and authentication
2. Sync options - Choose what content types to synchronize
3. Real-time daemon - Optional instant sync without cron delays
4. File sync - Sync media, themes, and plugins
5. Logs - Monitor sync activity and troubleshoot issues

== Changelog ==

= 0.8.6 =
* Increased default API rate limit to 300 requests/minute

= 0.8.5 =
* Fixed daemon status display for containerized PHP-FPM environments
* Fixed JavaScript error on File Sync admin page
* Daemon auto-restarts after 12 hours to prevent memory leaks
* Daemon stops gracefully when disabled or during plugin updates

= 0.8.0 =
* Added Real-time Sync Daemon for instant synchronization
* Daemon can be enabled/disabled from admin UI
* Auto-restart via cron if daemon stops unexpectedly

= 0.7.0 =
* Added File Sync module for media, themes, and plugins
* Chunked uploads for large files
* Compression support for bandwidth efficiency

= 0.6.0 =
* WooCommerce support
* Improved conflict resolution
* Better error handling and logging

= 0.5.0 =
* Initial release
* Database synchronization
* Bi-directional sync support
* HMAC authentication

== Upgrade Notice ==

= 0.8.6 =
Recommended update. Increases default rate limit for better sync performance.

= 0.8.5 =
Important bug fixes for daemon status display and admin JavaScript errors.

== Privacy Policy ==

DualPress synchronizes data between two WordPress sites that you control. No data is sent to third parties. The plugin communicates only between the two configured WordPress installations using encrypted HTTPS connections (recommended).

Data synchronized includes:
* Database content (posts, users, comments, options, etc.)
* Media files (if File Sync is enabled)
* Theme/plugin files (if enabled in File Sync settings)

You are responsible for ensuring compliance with privacy regulations (GDPR, etc.) for any personal data synchronized between your sites.
