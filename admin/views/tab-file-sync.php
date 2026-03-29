<?php
/**
 * Admin view — File Sync tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

$enabled         = (bool) DualPress_Settings::get( 'file_sync_enabled', false );
$max_size        = (int) DualPress_Settings::get( 'file_sync_max_size', 100 );
$sync_uploads    = (bool) DualPress_Settings::get( 'file_sync_uploads', true );
$sync_themes     = (bool) DualPress_Settings::get( 'file_sync_themes', false );
$sync_plugins    = (bool) DualPress_Settings::get( 'file_sync_plugins', false );
$delete_remote   = (bool) DualPress_Settings::get( 'file_sync_delete_remote', false );
$configured      = DualPress_Settings::is_configured();
$remote_warning  = get_transient( 'dualpress_file_sync_remote_warning' );
$file_stats      = class_exists( 'DualPress_File_Queue' ) ? DualPress_File_Queue::get_stats() : array(
	'pending'    => 0,
	'processing' => 0,
	'completed'  => 0,
	'failed'     => 0,
	'skipped'    => 0,
	'total'      => 0,
);
?>

<?php if ( $remote_warning ) : ?>
<div class="notice notice-error">
	<p><strong><?php esc_html_e( 'Warning:', 'dualpress' ); ?></strong> <?php esc_html_e( 'File Sync is enabled on this server, but it is NOT enabled on the remote server. Please enable File Sync on both servers for synchronization to work.', 'dualpress' ); ?></p>
</div>
<?php delete_transient( 'dualpress_file_sync_remote_warning' ); endif; ?>

<?php
$synced_url = get_transient( 'dualpress_settings_synced_url' );
if ( $synced_url ) : ?>
<div class="notice notice-success is-dismissible">
	<p><?php printf( esc_html__( 'Settings saved and synced to remote server: %s', 'dualpress' ), '<a href="' . esc_url( $synced_url ) . '" target="_blank">' . esc_html( $synced_url ) . '</a>' ); ?></p>
</div>
<?php delete_transient( 'dualpress_settings_synced_url' ); endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'dualpress_save_settings', 'dualpress_nonce' ); ?>
	<input type="hidden" name="action" value="dualpress_save_settings">
	<input type="hidden" name="dualpress_tab" value="file-sync">

	<div class="dualpress-tools-grid">

		<!-- Enable / Settings Card -->
		<div class="dualpress-card dualpress-full-width">
			<h2><?php esc_html_e( 'File Synchronization', 'dualpress' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable File Sync', 'dualpress' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dualpress_file_sync_enabled" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( 'Enable file synchronization between servers', 'dualpress' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, files will be pushed to the remote server automatically via hooks and on a periodic schedule.', 'dualpress' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Directories to Sync', 'dualpress' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="dualpress_file_sync_uploads" value="1" <?php checked( $sync_uploads ); ?>>
								<?php esc_html_e( 'Media uploads (wp-content/uploads)', 'dualpress' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="dualpress_file_sync_themes" value="1" <?php checked( $sync_themes ); ?>>
								<?php esc_html_e( 'Themes (wp-content/themes)', 'dualpress' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="dualpress_file_sync_plugins" value="1" <?php checked( $sync_plugins ); ?>>
								<?php esc_html_e( 'Plugins (wp-content/plugins)', 'dualpress' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dualpress_file_sync_max_size"><?php esc_html_e( 'Max File Size', 'dualpress' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="dualpress_file_sync_max_size"
							name="dualpress_file_sync_max_size"
							value="<?php echo absint( $max_size ); ?>"
							min="1"
							max="2048"
							class="small-text"
						>
						<?php esc_html_e( 'MB', 'dualpress' ); ?>
						<p class="description">
							<?php esc_html_e( 'Files larger than this will be skipped and logged. Large files are transferred in 1 MB chunks automatically.', 'dualpress' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Delete on Remote', 'dualpress' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dualpress_file_sync_delete_remote" value="1" <?php checked( $delete_remote ); ?>>
							<?php esc_html_e( 'Delete files on the remote server when deleted locally', 'dualpress' ); ?>
						</label>
						<div class="notice notice-warning inline dualpress-warning-inline" style="margin-top:6px;">
							<p>&#9888; <?php esc_html_e( 'Warning: This action cannot be undone. Ensure both servers are healthy before enabling.', 'dualpress' ); ?></p>
						</div>
					</td>
				</tr>
			</table>

			<h3 style="margin-top:24px; border-top:1px solid #ccc; padding-top:16px;"><?php esc_html_e( 'Transfer Settings', 'dualpress' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="dualpress_file_sync_bundle_mb"><?php esc_html_e( 'Bundle Size', 'dualpress' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="dualpress_file_sync_bundle_mb"
							name="dualpress_file_sync_bundle_mb"
							value="<?php echo absint( DualPress_Settings::get( 'file_sync_bundle_mb', 10 ) ); ?>"
							min="1"
							max="50"
							class="small-text"
						>
						<?php esc_html_e( 'MB per request', 'dualpress' ); ?>
						<p class="description">
							<?php esc_html_e( 'Files are bundled together until this size is reached, then sent as a single request. Larger = faster sync, but requires more memory.', 'dualpress' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Compression', 'dualpress' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dualpress_file_sync_compress" value="1" <?php checked( (bool) DualPress_Settings::get( 'file_sync_compress', true ) ); ?>>
							<?php esc_html_e( 'Enable gzip compression', 'dualpress' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Compress file bundles before sending. Reduces bandwidth but uses more CPU. Recommended for most setups.', 'dualpress' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save File Sync Settings', 'dualpress' ); ?>
				</button>
			</p>
		</div><!-- /.dualpress-card -->

		<!-- Sync Status Card -->
		<div class="dualpress-card">
			<h2><?php esc_html_e( 'File Queue Status', 'dualpress' ); ?></h2>

			<table class="dualpress-stats-table">
				<tr>
					<td><?php esc_html_e( 'Pending', 'dualpress' ); ?></td>
					<td><span class="dualpress-badge dualpress-badge-pending"><?php echo absint( $file_stats['pending'] ); ?></span></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Processing', 'dualpress' ); ?></td>
					<td><span class="dualpress-badge dualpress-badge-processing"><?php echo absint( $file_stats['processing'] ); ?></span></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Completed', 'dualpress' ); ?></td>
					<td><span class="dualpress-badge dualpress-badge-completed"><?php echo absint( $file_stats['completed'] ); ?></span></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Failed', 'dualpress' ); ?></td>
					<td><span class="dualpress-badge dualpress-badge-failed"><?php echo absint( $file_stats['failed'] ); ?></span></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Skipped', 'dualpress' ); ?></td>
					<td><span class="dualpress-badge"><?php echo absint( $file_stats['skipped'] ); ?></span></td>
				</tr>
			</table>

			<div class="dualpress-queue-actions" style="margin-top:12px;">
				<button
					type="button"
					id="dualpress-file-sync-now"
					class="button button-primary"
					<?php disabled( ! $configured || ! $enabled ); ?>
				>
					<?php esc_html_e( 'Sync Files Now', 'dualpress' ); ?>
				</button>
				<button
					type="button"
					id="dualpress-file-retry-failed"
					class="button button-secondary"
					<?php disabled( $file_stats['failed'] < 1 ); ?>
				>
					<?php esc_html_e( 'Retry Failed', 'dualpress' ); ?>
				</button>
				<button type="button" id="dualpress-file-clear-queue" class="button button-link-delete">
					<?php esc_html_e( 'Clear File Queue', 'dualpress' ); ?>
				</button>
			</div>
			<span id="dualpress-file-sync-msg" class="dualpress-inline-msg"></span>
		</div><!-- /.dualpress-card -->

		<!-- Initial File Sync Card -->
		<div class="dualpress-card">
			<h2><?php esc_html_e( 'Initial File Sync', 'dualpress' ); ?></h2>
			<p>
				<?php esc_html_e( 'Scan all configured directories and queue every file for transfer to the remote server. Use this the first time you enable file sync.', 'dualpress' ); ?>
			</p>
			<div class="notice notice-warning inline dualpress-warning-inline">
				<p>&#9888; <?php esc_html_e( 'This may take several minutes for large media libraries.', 'dualpress' ); ?></p>
			</div>
			<br>

			<!-- Progress bar (hidden until scan starts) -->
			<div id="dualpress-file-progress-wrap" style="display:none; margin-bottom:12px;">
				<div class="dualpress-progress-bar-outer" style="background:#e0e0e0; border-radius:4px; height:22px; overflow:hidden;">
					<div
						id="dualpress-file-progress-bar"
						style="background:#0073aa; height:100%; width:0%; transition:width 0.3s ease; border-radius:4px;"
					></div>
				</div>
				<p id="dualpress-file-progress-label" style="margin:6px 0 0; font-size:13px;"></p>
			</div>

			<button
				type="button"
				id="dualpress-file-initial-sync"
				class="button button-secondary"
				<?php disabled( ! $configured || ! $enabled ); ?>
			>
				<?php esc_html_e( 'Start Initial File Sync', 'dualpress' ); ?>
			</button>
			<span id="dualpress-file-initial-msg" class="dualpress-inline-msg"></span>
		</div><!-- /.dualpress-card -->

		<!-- File Queue Viewer -->
		<div class="dualpress-card dualpress-full-width">
			<h2><?php esc_html_e( 'File Queue', 'dualpress' ); ?></h2>

			<?php
			$queue_data  = DualPress_File_Queue::get_items( '', 25, 1 );
			$queue_rows  = $queue_data['rows'];
			$queue_total = $queue_data['total'];
			?>

			<?php if ( empty( $queue_rows ) ) : ?>
				<p class="dualpress-empty"><?php esc_html_e( 'The file queue is empty.', 'dualpress' ); ?></p>
			<?php else : ?>
				<div class="dualpress-table-wrapper">
					<table class="widefat striped dualpress-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'File Path', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'Action', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'Size', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'Status', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'Attempts', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'Created', 'dualpress' ); ?></th>
								<th><?php esc_html_e( 'Error', 'dualpress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $queue_rows as $row ) : ?>
								<tr>
									<td><?php echo absint( $row['id'] ); ?></td>
									<td>
										<code style="word-break:break-all; font-size:11px;">
											<?php echo esc_html( $row['file_path'] ); ?>
										</code>
									</td>
									<td>
										<span class="dualpress-action-badge dualpress-action-<?php echo esc_attr( strtolower( $row['action'] ) ); ?>">
											<?php echo esc_html( $row['action'] ); ?>
										</span>
									</td>
									<td>
										<?php
										$bytes = (int) $row['file_size'];
										if ( $bytes > 0 ) {
											echo esc_html( size_format( $bytes ) );
										} else {
											echo '&#8212;';
										}
										?>
									</td>
									<td>
										<span class="dualpress-badge dualpress-badge-<?php echo esc_attr( $row['status'] ); ?>">
											<?php echo esc_html( $row['status'] ); ?>
										</span>
									</td>
									<td><?php echo absint( $row['attempts'] ); ?></td>
									<td><?php echo esc_html( $row['created_at'] ); ?></td>
									<td class="dualpress-error-cell">
										<?php if ( $row['error_message'] ) : ?>
											<span title="<?php echo esc_attr( $row['error_message'] ); ?>" class="dualpress-error-truncate">
												<?php echo esc_html( mb_substr( $row['error_message'], 0, 80 ) ); ?>
											</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="dualpress-table-count">
					<?php
					printf(
						/* translators: %d: total items */
						esc_html( _n( 'Showing 25 of %d item.', 'Showing 25 of %d items.', $queue_total, 'dualpress' ) ),
						absint( $queue_total )
					);
					?>
				</p>
			<?php endif; ?>
		</div><!-- /.dualpress-card -->

	</div><!-- .dualpress-tools-grid -->

</form>

<script type="text/javascript">
/* File Sync tab — inline JS */
(function ($) {
	// Use WordPress built-in ajaxurl, create nonce inline
	var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var nonce   = '<?php echo esc_js( wp_create_nonce( 'dualpress_admin' ) ); ?>';

	/* ---- Sync Now ---- */
	$('#dualpress-file-sync-now').on('click', function () {
		var $btn = $(this), $msg = $('#dualpress-file-sync-msg');
		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing\u2026', 'dualpress' ) ); ?>');
		$msg.text('');

		$.post(ajaxUrl, { action: 'dualpress_file_sync_now', nonce: nonce }, function (res) {
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sync Files Now', 'dualpress' ) ); ?>');
			$msg.text(res.success ? res.data.message : (res.data && res.data.message) || '<?php echo esc_js( __( 'Error.', 'dualpress' ) ); ?>');
		});
	});

	/* ---- Retry Failed ---- */
	$('#dualpress-file-retry-failed').on('click', function () {
		var $btn = $(this), $msg = $('#dualpress-file-sync-msg');
		$btn.prop('disabled', true);

		$.post(ajaxUrl, { action: 'dualpress_file_retry_failed', nonce: nonce }, function (res) {
			$btn.prop('disabled', false);
			$msg.text(res.success ? res.data.message : '<?php echo esc_js( __( 'Error.', 'dualpress' ) ); ?>');
		});
	});

	/* ---- Clear File Queue ---- */
	$('#dualpress-file-clear-queue').on('click', function () {
		if (!confirm('<?php echo esc_js( __( 'Clear the entire file sync queue? This cannot be undone.', 'dualpress' ) ); ?>')) {
			return;
		}
		var $msg = $('#dualpress-file-sync-msg');

		// Immediately clear the table visually
		$('#dualpress-file-queue-table tbody').empty();
		$('#dualpress-file-queue-table').after('<p><em><?php echo esc_js( __( 'Queue cleared.', 'dualpress' ) ); ?></em></p>');

		$.post(ajaxUrl, { action: 'dualpress_file_clear_queue', nonce: nonce }, function (res) {
			$msg.text(res.success ? res.data.message : '<?php echo esc_js( __( 'Error.', 'dualpress' ) ); ?>');
		});
	});

	/* ---- Initial File Sync with Progress ---- */
	var totalFiles    = 0;
	var syncInterval  = null;

	$('#dualpress-file-initial-sync').on('click', function () {
		var $btn     = $(this);
		var $wrap    = $('#dualpress-file-progress-wrap');
		var $bar     = $('#dualpress-file-progress-bar');
		var $label   = $('#dualpress-file-progress-label');
		var $msg     = $('#dualpress-file-initial-msg');

		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Scanning\u2026', 'dualpress' ) ); ?>');
		$msg.text('');
		$wrap.show();
		$bar.css('width', '0%');
		$label.text('<?php echo esc_js( __( 'Scanning directories\u2026', 'dualpress' ) ); ?>');

		/* Step 1: Scan and enqueue */
		$.post(ajaxUrl, { action: 'dualpress_file_sync_scan', nonce: nonce }, function (res) {
			if (!res.success) {
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Start Initial File Sync', 'dualpress' ) ); ?>');
				$label.text((res.data && res.data.message) || '<?php echo esc_js( __( 'Scan failed.', 'dualpress' ) ); ?>');
				return;
			}

			totalFiles = res.data.total;
			if (totalFiles === 0) {
				$bar.css('width', '100%');
				$label.text('<?php echo esc_js( __( 'No files to sync.', 'dualpress' ) ); ?>');
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Start Initial File Sync', 'dualpress' ) ); ?>');
				return;
			}

			$label.text('<?php echo esc_js( __( 'Found', 'dualpress' ) ); ?> ' + totalFiles + ' <?php echo esc_js( __( 'files. Starting transfer\u2026', 'dualpress' ) ); ?>');
			$btn.text('<?php echo esc_js( __( 'Syncing\u2026', 'dualpress' ) ); ?>');

			/* Step 2: Process batches */
			processNextBatch();
		});
	});

	function processNextBatch() {
		var $bar   = $('#dualpress-file-progress-bar');
		var $label = $('#dualpress-file-progress-label');
		var $btn   = $('#dualpress-file-initial-sync');

		$.post(ajaxUrl, { action: 'dualpress_file_sync_batch', nonce: nonce, batch_size: 5 }, function (res) {
			if (!res.success) {
				$label.text('<?php echo esc_js( __( 'Error during sync. Check the file queue for details.', 'dualpress' ) ); ?>');
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Start Initial File Sync', 'dualpress' ) ); ?>');
				return;
			}

			var data      = res.data;
			var completed = data.completed || 0;
			var pending   = data.pending   || 0;
			var pct       = totalFiles > 0 ? Math.round((completed / totalFiles) * 100) : 100;

			$bar.css('width', pct + '%');
			$label.text(completed + ' / ' + totalFiles + ' <?php echo esc_js( __( 'files synced', 'dualpress' ) ); ?> (' + pct + '%)');

			if (pending > 0) {
				/* Continue processing */
				setTimeout(processNextBatch, 500);
			} else {
				$bar.css('width', '100%');
				$label.text('<?php echo esc_js( __( 'Initial file sync complete!', 'dualpress' ) ); ?> (' + completed + ' <?php echo esc_js( __( 'files', 'dualpress' ) ); ?>)');
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Start Initial File Sync', 'dualpress' ) ); ?>');
			}
		});
	}
}(jQuery));
</script>
