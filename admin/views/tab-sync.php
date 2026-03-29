<?php
/**
 * Admin view — Sync Settings tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

$synced_url = get_transient( 'dualpress_sync_settings_synced_url' );
if ( $synced_url ) : ?>
<div class="notice notice-success is-dismissible">
	<p><?php printf( esc_html__( 'Settings saved and synced to remote server: %s', 'dualpress' ), '<a href="' . esc_url( $synced_url ) . '" target="_blank">' . esc_html( $synced_url ) . '</a>' ); ?></p>
</div>
<?php delete_transient( 'dualpress_sync_settings_synced_url' ); endif;

$sync_mode      = DualPress_Settings::get_sync_mode();
$sync_interval  = DualPress_Settings::get_sync_interval();
$db_sync_interval = (int) DualPress_Settings::get( 'db_sync_interval', 3600 ); // Default 1 hour
$db_sync_method = DualPress_Settings::get( 'db_sync_method', 'last_id' ); // Default last_id
$woo_active     = class_exists( 'WooCommerce' );

$excluded_tables  = DualPress_Settings::get_excluded_tables();
$excluded_tables_str = implode( ', ', $excluded_tables );

$excluded_meta_keys = DualPress_Settings::get_excluded_meta_keys();
$excluded_meta_keys_str = implode( ', ', $excluded_meta_keys );

$excluded_option_keys = DualPress_Settings::get_excluded_option_keys();
$excluded_option_keys_str = implode( ', ', $excluded_option_keys );

$toggles = array(
	'sync_posts'       => __( 'Posts &amp; Pages (wp_posts, wp_postmeta)', 'dualpress' ),
	'sync_users'       => __( 'Users (wp_users, wp_usermeta)', 'dualpress' ),
	'sync_comments'    => __( 'Comments (wp_comments, wp_commentmeta)', 'dualpress' ),
	'sync_options'     => __( 'Options (wp_options) — transients excluded automatically', 'dualpress' ),
	'sync_terms'       => __( 'Terms &amp; Taxonomies (wp_terms, wp_term_taxonomy, wp_term_relationships)', 'dualpress' ),
	'sync_woocommerce' => __( 'WooCommerce Orders &amp; Products', 'dualpress' ),
);
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dualpress-form">
	<?php wp_nonce_field( 'dualpress_save_settings', 'dualpress_nonce' ); ?>
	<input type="hidden" name="action" value="dualpress_save_settings">
	<input type="hidden" name="dualpress_tab" value="sync">

	<table class="form-table dualpress-form-table" role="presentation">

		<!-- Sync Mode -->
		<tr>
			<th scope="row">
				<label><?php esc_html_e( 'Sync Mode', 'dualpress' ); ?></label>
			</th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Sync Mode', 'dualpress' ); ?></legend>
					<label>
						<input type="radio" name="dualpress_sync_mode" value="active-active"
							<?php checked( $sync_mode, 'active-active' ); ?>>
						<strong><?php esc_html_e( 'Active-Active', 'dualpress' ); ?></strong>
						<span class="dualpress-server-tags">
							<span class="dualpress-server-tag"><?php esc_html_e( 'Server A', 'dualpress' ); ?></span>
							&#8596;
							<span class="dualpress-server-tag"><?php esc_html_e( 'Server B', 'dualpress' ); ?></span>
						</span>
						&mdash; <?php esc_html_e( 'Both servers accept writes; changes are synced bidirectionally.', 'dualpress' ); ?>
					</label>
					<br>
					<label>
						<input type="radio" name="dualpress_sync_mode" value="active-passive"
							<?php checked( $sync_mode, 'active-passive' ); ?>>
						<strong><?php esc_html_e( 'Active-Passive', 'dualpress' ); ?></strong>
						<span class="dualpress-server-tags">
							<span class="dualpress-server-tag"><?php esc_html_e( 'Server A', 'dualpress' ); ?></span>
							&#8594;
							<span class="dualpress-server-tag"><?php esc_html_e( 'Server B', 'dualpress' ); ?></span>
						</span>
						&mdash; <?php esc_html_e( 'Only the Primary server pushes changes; Secondary is read-only.', 'dualpress' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>

		<!-- Sync Interval -->
		<tr>
			<th scope="row">
				<label for="dualpress_sync_interval"><?php esc_html_e( 'Sync Interval', 'dualpress' ); ?></label>
			</th>
			<td>
				<select id="dualpress_sync_interval" name="dualpress_sync_interval">
					<option value="15" <?php selected( $sync_interval, 15 ); ?>><?php esc_html_e( '15 seconds', 'dualpress' ); ?></option>
					<option value="30" <?php selected( $sync_interval, 30 ); ?>><?php esc_html_e( '30 seconds', 'dualpress' ); ?></option>
					<option value="60" <?php selected( $sync_interval, 60 ); ?>><?php esc_html_e( '1 minute', 'dualpress' ); ?></option>
					<option value="120" <?php selected( $sync_interval, 120 ); ?>><?php esc_html_e( '2 minutes', 'dualpress' ); ?></option>
					<option value="300" <?php selected( $sync_interval, 300 ); ?>><?php esc_html_e( '5 minutes', 'dualpress' ); ?></option>
					<option value="600" <?php selected( $sync_interval, 600 ); ?>><?php esc_html_e( '10 minutes', 'dualpress' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'How often WP-Cron processes the sync queue. Changes detected by hooks are queued immediately; the interval only controls how often the queue is flushed to the remote.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- DB Sync Interval -->
		<tr>
			<th scope="row">
				<label for="dualpress_db_sync_interval"><?php esc_html_e( 'DB Table Sync Interval', 'dualpress' ); ?></label>
			</th>
			<td>
				<select id="dualpress_db_sync_interval" name="dualpress_db_sync_interval">
					<option value="0" <?php selected( $db_sync_interval, 0 ); ?>><?php esc_html_e( 'Disabled', 'dualpress' ); ?></option>
					<option value="900" <?php selected( $db_sync_interval, 900 ); ?>><?php esc_html_e( '15 minutes', 'dualpress' ); ?></option>
					<option value="1800" <?php selected( $db_sync_interval, 1800 ); ?>><?php esc_html_e( '30 minutes', 'dualpress' ); ?></option>
					<option value="3600" <?php selected( $db_sync_interval, 3600 ); ?>><?php esc_html_e( '1 hour', 'dualpress' ); ?></option>
					<option value="7200" <?php selected( $db_sync_interval, 7200 ); ?>><?php esc_html_e( '2 hours', 'dualpress' ); ?></option>
					<option value="14400" <?php selected( $db_sync_interval, 14400 ); ?>><?php esc_html_e( '4 hours', 'dualpress' ); ?></option>
					<option value="43200" <?php selected( $db_sync_interval, 43200 ); ?>><?php esc_html_e( '12 hours', 'dualpress' ); ?></option>
					<option value="86400" <?php selected( $db_sync_interval, 86400 ); ?>><?php esc_html_e( '24 hours', 'dualpress' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'How often to check for missing plugin tables and sync them with data. This catches tables created by plugins (Redirection, WooCommerce, etc.) that are not tracked by hooks.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- DB Sync Method -->
		<tr>
			<th scope="row">
				<label for="dualpress_db_sync_method"><?php esc_html_e( 'DB Sync Method', 'dualpress' ); ?></label>
			</th>
			<td>
				<select id="dualpress_db_sync_method" name="dualpress_db_sync_method">
					<option value="last_id" <?php selected( $db_sync_method, 'last_id' ); ?>><?php esc_html_e( 'Last ID Tracking (default)', 'dualpress' ); ?></option>
					<option value="checksum" <?php selected( $db_sync_method, 'checksum' ); ?>><?php esc_html_e( 'Checksum Comparison', 'dualpress' ); ?></option>
					<option value="full" <?php selected( $db_sync_method, 'full' ); ?>><?php esc_html_e( 'Full Table Sync', 'dualpress' ); ?></option>
				</select>
				<p class="description">
					<strong><?php esc_html_e( 'Last ID:', 'dualpress' ); ?></strong> <?php esc_html_e( 'Only syncs rows with ID higher than the last synced. Best for append-only tables.', 'dualpress' ); ?><br>
					<strong><?php esc_html_e( 'Checksum:', 'dualpress' ); ?></strong> <?php esc_html_e( 'Compares table hash first, syncs only if different. Efficient for rarely-changed tables.', 'dualpress' ); ?><br>
					<strong><?php esc_html_e( 'Full:', 'dualpress' ); ?></strong> <?php esc_html_e( 'Syncs all rows every time. Use only for small tables.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- DB Bundle Size -->
		<tr>
			<th scope="row">
				<label for="dualpress_db_bundle_mb"><?php esc_html_e( 'DB Bundle Size', 'dualpress' ); ?></label>
			</th>
			<td>
				<input
					type="number"
					id="dualpress_db_bundle_mb"
					name="dualpress_db_bundle_mb"
					value="<?php echo absint( DualPress_Settings::get( 'db_bundle_mb', 2 ) ); ?>"
					min="1"
					max="20"
					step="1"
					style="width: 80px;"
				>
				<span class="description"><?php esc_html_e( 'MB', 'dualpress' ); ?></span>
				<p class="description">
					<?php esc_html_e( 'Maximum size of each database sync request. Rows are bundled together up to this size. Default: 2MB.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- DB Compression -->
		<tr>
			<th scope="row">
				<label for="dualpress_db_compress"><?php esc_html_e( 'DB Compression', 'dualpress' ); ?></label>
			</th>
			<td>
				<label>
					<input
						type="checkbox"
						id="dualpress_db_compress"
						name="dualpress_db_compress"
						value="1"
						<?php checked( DualPress_Settings::get( 'db_compress', true ) ); ?>
					>
					<?php esc_html_e( 'Compress database sync transfers with gzip', 'dualpress' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Reduces bandwidth usage significantly. Disable only if you experience compatibility issues.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Tables to Sync -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Tables to Sync', 'dualpress' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Tables to Sync', 'dualpress' ); ?></legend>

					<?php foreach ( $toggles as $key => $label ) : ?>
						<?php
						$disabled = ( 'sync_woocommerce' === $key && ! $woo_active );
						$checked  = ! $disabled && DualPress_Settings::get( $key, true );
						?>
						<label class="dualpress-toggle-label <?php echo esc_attr( $disabled ? 'dualpress-disabled' : '' ); ?>">
							<input
								type="checkbox"
								name="dualpress_<?php echo esc_attr( $key ); ?>"
								value="1"
								<?php checked( $checked ); ?>
								<?php disabled( $disabled ); ?>
							>
							<?php echo wp_kses( $label, array( 'strong' => array(), 'em' => array(), 'amp' => array() ) ); ?>
							<?php if ( $disabled ) : ?>
								<span class="description"><?php esc_html_e( '(WooCommerce not active)', 'dualpress' ); ?></span>
							<?php endif; ?>
						</label>
						<br>
					<?php endforeach; ?>

				</fieldset>
			</td>
		</tr>

		<!-- Excluded Tables -->
		<tr>
			<th scope="row">
				<label for="dualpress_excluded_tables"><?php esc_html_e( 'Excluded Tables', 'dualpress' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="dualpress_excluded_tables"
					name="dualpress_excluded_tables"
					value="<?php echo esc_attr( $excluded_tables_str ); ?>"
					class="large-text"
					placeholder="my_plugin_logs, custom_cache_table"
				>
				<p class="description">
					<?php esc_html_e( 'Comma-separated list of table names (without DB prefix) to exclude from sync. These tables will never be synced.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Excluded Meta Keys -->
		<tr>
			<th scope="row">
				<label for="dualpress_excluded_meta_keys"><?php esc_html_e( 'Excluded Meta Keys', 'dualpress' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="dualpress_excluded_meta_keys"
					name="dualpress_excluded_meta_keys"
					value="<?php echo esc_attr( $excluded_meta_keys_str ); ?>"
					class="large-text"
					placeholder="custom_session_data, my_plugin_cache"
				>
				<p class="description">
					<?php esc_html_e( 'Comma-separated list of meta keys to exclude from usermeta/postmeta sync. Use * for prefix matching (e.g., my_plugin_*).', 'dualpress' ); ?><br>
					<strong><?php esc_html_e( 'Default excluded:', 'dualpress' ); ?></strong>
					<code>session_tokens</code>, <code>wc_last_active</code>, <code>_woocommerce_persistent_cart*</code>, <code>_edit_lock</code>, <code>_edit_last</code>
				</p>
			</td>
		</tr>

		<!-- Excluded Option Keys -->
		<tr>
			<th scope="row">
				<label for="dualpress_excluded_option_keys"><?php esc_html_e( 'Excluded Option Keys', 'dualpress' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="dualpress_excluded_option_keys"
					name="dualpress_excluded_option_keys"
					value="<?php echo esc_attr( $excluded_option_keys_str ); ?>"
					class="large-text"
					placeholder="my_plugin_cache, custom_option"
				>
				<p class="description">
					<?php esc_html_e( 'Comma-separated list of wp_options keys to exclude from sync.', 'dualpress' ); ?><br>
					<strong><?php esc_html_e( 'Default excluded:', 'dualpress' ); ?></strong>
					<code>cron</code>, <code>rewrite_rules</code>, <code>recently_edited</code>, <code>*_transient_*</code>, <code>active_plugins</code>
				</p>
			</td>
		</tr>

	</table>

	<!-- Default excluded tables information card -->
	<div class="dualpress-card dualpress-info-card">
		<h3><?php esc_html_e( 'Default Excluded Tables', 'dualpress' ); ?></h3>
		<p><?php esc_html_e( 'The following tables are always excluded (in addition to your custom exclusions):', 'dualpress' ); ?></p>
		<ul class="dualpress-excluded-list">
			<li><code>dualpress_*</code> &mdash; <?php esc_html_e( 'Plugin internal tables', 'dualpress' ); ?></li>
			<li><code>actionscheduler_*</code> &mdash; <?php esc_html_e( 'Action Scheduler queues', 'dualpress' ); ?></li>
			<li><code>wc_sessions</code> / <code>woocommerce_sessions</code> &mdash; <?php esc_html_e( 'Session data', 'dualpress' ); ?></li>
			<li><code>litespeed_*</code>, <code>w3tc_*</code>, <code>wpr_*</code>, <code>breeze_*</code> &mdash; <?php esc_html_e( 'Cache plugins', 'dualpress' ); ?></li>
		</ul>
	</div>

	<?php submit_button( __( 'Save Sync Settings', 'dualpress' ) ); ?>
</form>
