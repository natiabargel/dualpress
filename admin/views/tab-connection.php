<?php
/**
 * Admin view — Connection tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

$server_role     = DualPress_Settings::get_server_role();
$remote_url      = DualPress_Settings::get_remote_url();
$secret_key      = DualPress_Settings::get_secret_key();
$is_https        = 0 === strpos( home_url(), 'https://' );
$configured      = DualPress_Settings::is_configured();
$rate_limit_max  = DualPress_Settings::get_rate_limit_max();
$sync_mode       = DualPress_Settings::get_sync_mode();
$skip_ssl_verify = (bool) DualPress_Settings::get( 'skip_ssl_verify', false );
?>

<?php if ( ! $is_https ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'Warning: Your site is not running on HTTPS. DualPress requires HTTPS for secure communication between servers.', 'dualpress' ); ?>
		</p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dualpress-form">
	<?php wp_nonce_field( 'dualpress_save_settings', 'dualpress_nonce' ); ?>
	<input type="hidden" name="action" value="dualpress_save_settings">
	<input type="hidden" name="dualpress_tab" value="connection">

	<table class="form-table dualpress-form-table" role="presentation">

		<!-- Server Role -->
		<tr>
			<th scope="row">
				<label><?php esc_html_e( 'Server Role', 'dualpress' ); ?></label>
			</th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Server Role', 'dualpress' ); ?></legend>
					<label>
						<input type="radio" name="dualpress_server_role" value="primary"
							<?php checked( $server_role, 'primary' ); ?>>
						<strong><?php esc_html_e( 'Primary (Server A)', 'dualpress' ); ?></strong>
						&mdash; <?php esc_html_e( 'Generates odd IDs (1, 3, 5&hellip;)', 'dualpress' ); ?>
					</label>
					<br>
					<label>
						<input type="radio" name="dualpress_server_role" value="secondary"
							<?php checked( $server_role, 'secondary' ); ?>>
						<strong><?php esc_html_e( 'Secondary (Server B)', 'dualpress' ); ?></strong>
						&mdash; <?php esc_html_e( 'Generates even IDs (2, 4, 6&hellip;)', 'dualpress' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'The server role determines the MySQL auto_increment_offset applied on activation. Changing this requires careful coordination between both servers.', 'dualpress' ); ?>
					</p>
					<?php if ( get_option( 'dualpress_auto_increment_configured' ) ) : ?>
						<p class="description dualpress-text-success">
							&#10003; <?php esc_html_e( 'Auto-increment configured for this session.', 'dualpress' ); ?>
							<a href="#dualpress-mycnf"><?php esc_html_e( 'Make it permanent &darr;', 'dualpress' ); ?></a>
						</p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>

		<!-- Remote Site URL -->
		<tr>
			<th scope="row">
				<label for="dualpress_remote_url"><?php esc_html_e( 'Remote Site URL', 'dualpress' ); ?></label>
			</th>
			<td>
				<input
					type="url"
					id="dualpress_remote_url"
					name="dualpress_remote_url"
					value="<?php echo esc_attr( $remote_url ); ?>"
					class="regular-text"
					placeholder="https://other-site.com"
				>
				<p class="description">
					<?php esc_html_e( 'The full URL of the other WordPress server (without trailing slash).', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Secret Key -->
		<tr>
			<th scope="row">
				<label for="dualpress_secret_key"><?php esc_html_e( 'Secret Key', 'dualpress' ); ?></label>
			</th>
			<td>
				<div class="dualpress-key-row">
					<input
						type="password"
						id="dualpress_secret_key"
						name="dualpress_secret_key"
						value="<?php echo esc_attr( $secret_key ); ?>"
						class="regular-text dualpress-secret-key-input"
						autocomplete="new-password"
					>
					<button type="button" id="dualpress-toggle-key" class="button dualpress-toggle-key"
						data-show="<?php esc_attr_e( 'Show', 'dualpress' ); ?>"
						data-hide="<?php esc_attr_e( 'Hide', 'dualpress' ); ?>">
						<?php esc_html_e( 'Show', 'dualpress' ); ?>
					</button>
					<button type="button" id="dualpress-generate-key" class="button">
						<?php esc_html_e( 'Generate', 'dualpress' ); ?>
					</button>
					<button type="button" id="dualpress-copy-key" class="button">
						<?php esc_html_e( 'Copy', 'dualpress' ); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( 'The same secret key must be entered on both servers. It is used to sign and verify all sync requests.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Rate Limit -->
		<tr>
			<th scope="row">
				<label for="dualpress_rate_limit_max"><?php esc_html_e( 'API Rate Limit', 'dualpress' ); ?></label>
			</th>
			<td>
				<input
					type="number"
					id="dualpress_rate_limit_max"
					name="dualpress_rate_limit_max"
					value="<?php echo esc_attr( $rate_limit_max ); ?>"
					min="1"
					max="1000"
					class="small-text"
				>
				<?php esc_html_e( 'requests / minute', 'dualpress' ); ?>
				<p class="description">
					<?php esc_html_e( 'Maximum API requests per IP per 60-second window. Default: 100.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Skip SSL Verify (for dev environments) -->
		<tr>
			<th scope="row">
				<label for="dualpress_skip_ssl_verify"><?php esc_html_e( 'Skip SSL Verification', 'dualpress' ); ?></label>
			</th>
			<td>
				<label>
					<input type="checkbox" id="dualpress_skip_ssl_verify" name="dualpress_skip_ssl_verify" value="1" <?php checked( $skip_ssl_verify ); ?>>
					<?php esc_html_e( 'Disable SSL certificate verification (for self-signed certificates in development)', 'dualpress' ); ?>
				</label>
				<p class="description" style="color:#d63638;">
					<?php esc_html_e( '⚠️ Do NOT enable this in production! Only use for local development with self-signed certificates.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Connection Status -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'dualpress' ); ?></th>
			<td>
				<span id="dualpress-connection-status" class="dualpress-status-badge dualpress-status-unknown">
					<span class="dualpress-status-dot dualpress-dot-unknown">&#9679;</span>
					<span id="dualpress-status-text">
					<?php
					if ( ! $configured ) {
						esc_html_e( 'Not configured', 'dualpress' );
					} else {
						esc_html_e( 'Checking&hellip;', 'dualpress' );
					}
					?>
					</span>
				</span>
				<span class="dualpress-status-refresh" id="dualpress-status-refresh-note">
					<?php if ( $configured ) : ?>
						<small style="color:#646970;margin-left:8px"><?php esc_html_e( '(auto-refreshes every 30s)', 'dualpress' ); ?></small>
					<?php endif; ?>
				</span>
				<br><br>
				<button type="button" id="dualpress-test-connection" class="button button-secondary"
					<?php disabled( ! $configured ); ?>>
					<?php esc_html_e( 'Test Connection', 'dualpress' ); ?>
				</button>
				<span id="dualpress-connection-message" class="dualpress-inline-msg"></span>
			</td>
		</tr>

	</table>

	<?php submit_button( __( 'Save Connection Settings', 'dualpress' ) ); ?>
</form>

<!-- Sync direction visualization -->
<?php if ( $configured ) : ?>
<div class="dualpress-card dualpress-direction-card">
	<h3><?php esc_html_e( 'Sync Direction', 'dualpress' ); ?></h3>
	<div class="dualpress-diagram">
		<div class="dualpress-server-node">
			<span class="dualpress-server-label">
				<?php echo 'primary' === $server_role ? '<strong>' . esc_html__( 'Server A', 'dualpress' ) . '</strong>' : esc_html__( 'Server A', 'dualpress' ); ?>
			</span>
			<span class="dualpress-server-role-badge">
				<?php echo 'primary' === $server_role ? esc_html__( 'Primary (this server)', 'dualpress' ) : esc_html__( 'Primary', 'dualpress' ); ?>
			</span>
		</div>
		<div class="dualpress-arrows-block">
			<?php if ( 'active-active' === $sync_mode ) : ?>
				<span class="dualpress-arrow dualpress-arrow-left">&#8592;</span>
				<span class="dualpress-mode-label"><?php esc_html_e( 'Active-Active', 'dualpress' ); ?></span>
				<span class="dualpress-arrow dualpress-arrow-right">&#8594;</span>
			<?php else : ?>
				<span class="dualpress-arrow dualpress-arrow-right dualpress-arrow-only">&#8594;</span>
				<span class="dualpress-mode-label"><?php esc_html_e( 'Active-Passive', 'dualpress' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="dualpress-server-node">
			<span class="dualpress-server-label">
				<?php echo 'secondary' === $server_role ? '<strong>' . esc_html__( 'Server B', 'dualpress' ) . '</strong>' : esc_html__( 'Server B', 'dualpress' ); ?>
			</span>
			<span class="dualpress-server-role-badge">
				<?php echo 'secondary' === $server_role ? esc_html__( 'Secondary (this server)', 'dualpress' ) : esc_html__( 'Secondary', 'dualpress' ); ?>
			</span>
		</div>
	</div>
	<?php if ( 'active-passive' === $sync_mode ) : ?>
		<p class="description" style="text-align:center;margin-top:8px">
			<?php esc_html_e( 'Only Primary (Server A) pushes changes. Secondary (Server B) is read-only.', 'dualpress' ); ?>
		</p>
	<?php else : ?>
		<p class="description" style="text-align:center;margin-top:8px">
			<?php esc_html_e( 'Both servers accept writes and sync changes bidirectionally.', 'dualpress' ); ?>
		</p>
	<?php endif; ?>
</div>
<?php endif; ?>

<!-- Site URL Check -->
<?php
$db_home    = get_option( 'home' );
$db_siteurl = get_option( 'siteurl' );
// HTTP_HOST already includes port if non-standard
$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'];
$home_mismatch    = rtrim( $db_home, '/' ) !== rtrim( $current_url, '/' );
$siteurl_mismatch = rtrim( $db_siteurl, '/' ) !== rtrim( $current_url, '/' );
$has_mismatch     = $home_mismatch || $siteurl_mismatch;
?>
<div id="dualpress-url-check" class="dualpress-card dualpress-info-card" style="<?php echo esc_attr( $has_mismatch ? 'border-left-color:#d63638;' : 'border-left-color:#00a32a;' ); ?>">
	<h3><?php esc_html_e( 'Site URL Configuration', 'dualpress' ); ?></h3>
	
	<?php if ( $has_mismatch ) : ?>
		<div class="notice notice-error inline" style="margin:0 0 12px 0;">
			<p><strong><?php esc_html_e( 'Warning: URL mismatch detected!', 'dualpress' ); ?></strong></p>
			<p><?php esc_html_e( 'The URLs in the database do not match the current server URL. This can cause redirect issues.', 'dualpress' ); ?></p>
		</div>
	<?php endif; ?>
	
	<table class="widefat" style="margin-bottom:12px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Setting', 'dualpress' ); ?></th>
				<th><?php esc_html_e( 'Database Value', 'dualpress' ); ?></th>
				<th><?php esc_html_e( 'Current URL', 'dualpress' ); ?></th>
				<th><?php esc_html_e( 'Status', 'dualpress' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>home</code></td>
				<td><code><?php echo esc_html( $db_home ); ?></code></td>
				<td><code><?php echo esc_html( $current_url ); ?></code></td>
				<td>
					<?php if ( $home_mismatch ) : ?>
						<span style="color:#d63638;">&#10007; <?php esc_html_e( 'Mismatch', 'dualpress' ); ?></span>
					<?php else : ?>
						<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'OK', 'dualpress' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><code>siteurl</code></td>
				<td><code><?php echo esc_html( $db_siteurl ); ?></code></td>
				<td><code><?php echo esc_html( $current_url ); ?></code></td>
				<td>
					<?php if ( $siteurl_mismatch ) : ?>
						<span style="color:#d63638;">&#10007; <?php esc_html_e( 'Mismatch', 'dualpress' ); ?></span>
					<?php else : ?>
						<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'OK', 'dualpress' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
	
	<?php if ( $has_mismatch ) : ?>
		<div style="margin-top:8px;">
			<button type="button" id="dp-fix-urls-btn" class="button button-primary" data-url="<?php echo esc_attr( $current_url ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dualpress_fix_urls' ) ); ?>">
				<?php esc_html_e( 'Fix URLs to match current server', 'dualpress' ); ?>
			</button>
			<span id="dp-fix-urls-status" style="margin-left:10px;"></span>
			<p class="description" style="margin-top:6px;">
				<?php printf( 
					esc_html__( 'This will update both home and siteurl to: %s', 'dualpress' ), 
					'<code>' . esc_html( $current_url ) . '</code>' 
				); ?>
			</p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('#dp-fix-urls-btn').on('click', function() {
				var $btn = $(this);
				var newUrl = $btn.data('url');
				var nonce = $btn.data('nonce');
				
				$btn.prop('disabled', true);
				$('#dp-fix-urls-status').text('<?php esc_html_e( 'Updating...', 'dualpress' ); ?>');
				
				$.post(ajaxurl, {
					action: 'dualpress_fix_urls',
					new_url: newUrl,
					nonce: nonce
				}, function(response) {
					if (response.success) {
						$('#dp-fix-urls-status').text('<?php esc_html_e( 'Done! Redirecting...', 'dualpress' ); ?>');
						// Redirect to the new URL
						window.location.href = newUrl + '/wp-admin/admin.php?page=dualpress&urls_fixed=1';
					} else {
						$('#dp-fix-urls-status').text(response.data.message || '<?php esc_html_e( 'Error', 'dualpress' ); ?>');
						$btn.prop('disabled', false);
					}
				}).fail(function() {
					$('#dp-fix-urls-status').text('<?php esc_html_e( 'Request failed', 'dualpress' ); ?>');
					$btn.prop('disabled', false);
				});
			});
		});
		</script>
	<?php endif; ?>
</div>

<!-- my.cnf recommendation -->
<div id="dualpress-mycnf" class="dualpress-card dualpress-mycnf-card">
	<h3><?php esc_html_e( 'Make auto-increment permanent (my.cnf)', 'dualpress' ); ?></h3>
	<p><?php esc_html_e( 'The auto-increment settings are applied per-session on plugin activation. To persist them across MySQL restarts, add the following to your my.cnf:', 'dualpress' ); ?></p>

	<?php if ( 'primary' === $server_role ) : ?>
		<pre class="dualpress-code">[mysqld]
auto_increment_increment = 2
auto_increment_offset = 1</pre>
	<?php else : ?>
		<pre class="dualpress-code">[mysqld]
auto_increment_increment = 2
auto_increment_offset = 2</pre>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'After editing my.cnf, restart MySQL for the changes to take effect.', 'dualpress' ); ?></p>
</div>

<!-- Real-time Sync Daemon -->
<?php
$daemon_status  = DualPress_Daemon::get_status();
$daemon_enabled = DualPress_Daemon::is_enabled();
$daemon_running = $daemon_status['running'];
?>
<div id="dualpress-daemon" class="dualpress-card dualpress-daemon-card" style="margin-top:20px;">
	<h3><?php esc_html_e( 'Real-time Sync Daemon', 'dualpress' ); ?></h3>
	<p class="description" style="margin-bottom:12px;">
		<?php esc_html_e( 'Run a background process that continuously monitors for changes and syncs immediately, instead of waiting for WP-Cron.', 'dualpress' ); ?>
	</p>

	<table class="form-table dualpress-form-table" style="margin:0;">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Daemon', 'dualpress' ); ?></th>
			<td>
				<label class="dualpress-switch">
					<input type="checkbox" id="dualpress-daemon-toggle" <?php checked( $daemon_enabled ); ?>>
					<span class="dualpress-slider"></span>
				</label>
				<span id="dualpress-daemon-status" class="dualpress-daemon-status" style="margin-left:12px;">
					<?php if ( $daemon_running ) : ?>
						<span class="dualpress-dot-green">●</span> <?php esc_html_e( 'Running', 'dualpress' ); ?>
						<?php if ( $daemon_status['pid'] ) : ?>
							<small style="color:#666;">(PID: <?php echo esc_html( $daemon_status['pid'] ); ?>)</small>
						<?php endif; ?>
					<?php else : ?>
						<span class="dualpress-dot-gray">●</span> <?php esc_html_e( 'Stopped', 'dualpress' ); ?>
					<?php endif; ?>
				</span>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="dualpress-daemon-interval"><?php esc_html_e( 'Check Interval', 'dualpress' ); ?></label>
			</th>
			<td>
				<input type="number" id="dualpress-daemon-interval" value="<?php echo esc_attr( $daemon_status['interval'] ); ?>" min="1" max="60" class="small-text">
				<?php esc_html_e( 'seconds', 'dualpress' ); ?>
				<p class="description"><?php esc_html_e( 'How often to check for pending changes. Lower = faster sync, higher CPU usage.', 'dualpress' ); ?></p>
			</td>
		</tr>
		<?php if ( $daemon_status['last_check'] ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Last Check', 'dualpress' ); ?></th>
			<td>
				<?php echo esc_html( human_time_diff( $daemon_status['last_check'] ) . ' ' . __( 'ago', 'dualpress' ) ); ?>
				<small style="color:#666;">(<?php echo esc_html( date( 'Y-m-d H:i:s', $daemon_status['last_check'] ) ); ?>)</small>
			</td>
		</tr>
		<?php endif; ?>
	</table>

	<div class="dualpress-daemon-crontab" style="margin-top:16px; padding:12px; background:#f6f7f7; border-radius:4px;">
		<h4 style="margin:0 0 8px 0;"><?php esc_html_e( 'Crontab Setup (Recommended)', 'dualpress' ); ?></h4>
		<p class="description" style="margin-bottom:8px;">
			<?php esc_html_e( 'Add this line to your server crontab to automatically restart the daemon if it stops:', 'dualpress' ); ?>
		</p>
		<pre id="dualpress-crontab-code" class="dualpress-code" style="font-size:12px; overflow-x:auto; white-space:pre-wrap; word-break:break-all;">* * * * * php <?php echo esc_html( DUALPRESS_DIR ); ?>includes/daemon-ensure.php <?php echo esc_html( rtrim( ABSPATH, '/' ) ); ?> >> /dev/null 2>&1</pre>
		<button type="button" id="dualpress-copy-crontab" class="button button-small" style="margin-top:8px;">
			<?php esc_html_e( 'Copy to Clipboard', 'dualpress' ); ?>
		</button>
		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'The cron job runs every minute and checks if the daemon is alive. If not, it restarts it.', 'dualpress' ); ?>
		</p>
	</div>
</div>

<style>
.dualpress-switch {
	position: relative;
	display: inline-block;
	width: 50px;
	height: 26px;
	vertical-align: middle;
}
.dualpress-switch input {
	opacity: 0;
	width: 0;
	height: 0;
}
.dualpress-slider {
	position: absolute;
	cursor: pointer;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: #ccc;
	transition: .3s;
	border-radius: 26px;
}
.dualpress-slider:before {
	position: absolute;
	content: "";
	height: 20px;
	width: 20px;
	left: 3px;
	bottom: 3px;
	background-color: white;
	transition: .3s;
	border-radius: 50%;
}
.dualpress-switch input:checked + .dualpress-slider {
	background-color: #2271b1;
}
.dualpress-switch input:checked + .dualpress-slider:before {
	transform: translateX(24px);
}
.dualpress-dot-green { color: #00a32a; }
.dualpress-dot-gray { color: #999; }
.dualpress-dot-red { color: #d63638; }
.dualpress-daemon-status { font-weight: 500; }
</style>

<script>
jQuery(document).ready(function($) {
	// Toggle daemon
	$('#dualpress-daemon-toggle').on('change', function() {
		var enabled = $(this).is(':checked');
		var $status = $('#dualpress-daemon-status');
		
		$status.html('<span class="spinner is-active" style="float:none;margin:0;"></span> ' + (enabled ? '<?php esc_html_e( "Starting...", "dualpress" ); ?>' : '<?php esc_html_e( "Stopping...", "dualpress" ); ?>'));
		
		$.post(ajaxurl, {
			action: 'dualpress_daemon_toggle',
			enabled: enabled ? 1 : 0,
			nonce: '<?php echo esc_js( wp_create_nonce( "dualpress_daemon" ) ); ?>'
		}, function(response) {
			if (response.success) {
				if (response.data.running) {
					$status.html('<span class="dualpress-dot-green">●</span> <?php esc_html_e( "Running", "dualpress" ); ?>' + 
						(response.data.pid ? ' <small style="color:#666;">(PID: ' + response.data.pid + ')</small>' : ''));
				} else {
					$status.html('<span class="dualpress-dot-gray">●</span> <?php esc_html_e( "Stopped", "dualpress" ); ?>');
				}
			} else {
				$status.html('<span class="dualpress-dot-red">●</span> ' + (response.data.message || '<?php esc_html_e( "Error", "dualpress" ); ?>'));
			}
		}).fail(function() {
			$status.html('<span class="dualpress-dot-red">●</span> <?php esc_html_e( "Request failed", "dualpress" ); ?>');
		});
	});
	
	// Update interval
	$('#dualpress-daemon-interval').on('change', function() {
		$.post(ajaxurl, {
			action: 'dualpress_daemon_interval',
			interval: $(this).val(),
			nonce: '<?php echo esc_js( wp_create_nonce( "dualpress_daemon" ) ); ?>'
		});
	});
	
	// Copy crontab
	$('#dualpress-copy-crontab').on('click', function() {
		var code = $('#dualpress-crontab-code').text();
		navigator.clipboard.writeText(code).then(function() {
			$('#dualpress-copy-crontab').text('<?php esc_html_e( "Copied!", "dualpress" ); ?>');
			setTimeout(function() {
				$('#dualpress-copy-crontab').text('<?php esc_html_e( "Copy to Clipboard", "dualpress" ); ?>');
			}, 2000);
		});
	});
});
</script>
