<?php
/**
 * Admin view — Table Sync tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

// Get all local plugin tables.
$all_tables = DualPress_Table_Sync::get_all_tables();
$core_tables = array(
	'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
	'term_relationships', 'term_taxonomy', 'termmeta', 'terms',
	'usermeta', 'users',
);
$excluded_prefixes = array( 'dualpress_', 'actionscheduler_', 'wc_sessions', 'woocommerce_sessions', 'litespeed_', 'w3tc_', 'wpr_', 'breeze_' );
$user_excluded = DualPress_Settings::get_excluded_tables();

$plugin_tables = array_filter( $all_tables, function( $table ) use ( $core_tables, $excluded_prefixes, $user_excluded ) {
	if ( in_array( $table, $core_tables, true ) ) return false;
	if ( in_array( $table, $user_excluded, true ) ) return false;
	foreach ( $excluded_prefixes as $prefix ) {
		if ( strpos( $table, $prefix ) === 0 ) return false;
	}
	return true;
} );

$db_method = DualPress_Settings::get( 'db_sync_method', 'last_id' );
?>

<style>
.dualpress-table-sync-container { max-width: 900px; }
.dualpress-table-list { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0; }
.dualpress-table-item { display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #eee; }
.dualpress-table-item:last-child { border-bottom: none; }
.dualpress-table-name { flex: 1; font-family: monospace; font-size: 13px; }
.dualpress-table-rows { width: 100px; text-align: right; color: #666; font-size: 12px; }
.dualpress-table-action { width: 90px; text-align: center; }
.dualpress-table-action .button { font-size: 11px; padding: 2px 8px; }
.dualpress-table-status { width: 120px; text-align: center; }
.dualpress-table-progress { width: 180px; }
.dualpress-table-progress-text { font-size: 11px; color: #666; text-align: center; margin-top: 2px; }
.dualpress-table-progress-bar { height: 8px; background: #ddd; border-radius: 4px; overflow: hidden; }
.dualpress-table-progress-fill { height: 100%; background: #2271b1; transition: width 0.3s ease; width: 0%; }
.dualpress-status-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 500; }
.dualpress-status-pending { background: #f0f0f1; color: #50575e; }
.dualpress-status-syncing { background: #dff0d8; color: #3c763d; }
.dualpress-status-done { background: #d4edda; color: #155724; }
.dualpress-status-skipped { background: #fff3cd; color: #856404; }
.dualpress-status-error { background: #f8d7da; color: #721c24; }
.dualpress-sync-summary { background: #f6f7f7; padding: 15px 20px; border-radius: 4px; margin: 20px 0; }
.dualpress-sync-summary h3 { margin: 0 0 10px 0; font-size: 14px; }
.dualpress-sync-stats { display: flex; gap: 30px; }
.dualpress-sync-stat { text-align: center; }
.dualpress-sync-stat-value { font-size: 24px; font-weight: 600; color: #2271b1; }
.dualpress-sync-stat-label { font-size: 12px; color: #666; }
.dualpress-sync-actions { margin: 20px 0; display: flex; gap: 10px; }
.dualpress-log-output { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: auto; margin-top: 20px; display: none; }
.dualpress-log-output.active { display: block; }
.dualpress-log-line { margin: 2px 0; }
.dualpress-log-time { color: #6a9955; }
.dualpress-log-table { color: #9cdcfe; }
.dualpress-log-success { color: #4ec9b0; }
.dualpress-log-error { color: #f14c4c; }
.dashicons.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<div class="dualpress-table-sync-container">
	<h2><?php esc_html_e( 'Table Sync Manager', 'dualpress' ); ?></h2>
	
	<p class="description">
		<?php esc_html_e( 'Sync plugin database tables to the remote server. Current method:', 'dualpress' ); ?>
		<strong>
		<?php
		switch ( $db_method ) {
			case 'last_id': esc_html_e( 'Last ID Tracking', 'dualpress' ); break;
			case 'checksum': esc_html_e( 'Checksum Comparison', 'dualpress' ); break;
			case 'full': esc_html_e( 'Full Table Sync', 'dualpress' ); break;
		}
		?>
		</strong>
	</p>

	<div class="dualpress-sync-actions">
		<button type="button" id="dualpress-start-table-sync" class="button button-primary">
			<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
			<?php esc_html_e( 'Start Table Sync', 'dualpress' ); ?>
		</button>
		<button type="button" id="dualpress-stop-sync" class="button" style="display: none;">
			<?php esc_html_e( 'Stop', 'dualpress' ); ?>
		</button>
		<label style="margin-left: 20px; display: flex; align-items: center; gap: 5px;">
			<input type="checkbox" id="dualpress-show-log" />
			<?php esc_html_e( 'Show log', 'dualpress' ); ?>
		</label>
	</div>

	<div class="dualpress-sync-summary" id="dualpress-sync-summary" style="display: none;">
		<h3><?php esc_html_e( 'Sync Progress', 'dualpress' ); ?></h3>
		<div class="dualpress-sync-stats">
			<div class="dualpress-sync-stat">
				<div class="dualpress-sync-stat-value" id="stat-tables">0/<?php echo count( $plugin_tables ); ?></div>
				<div class="dualpress-sync-stat-label"><?php esc_html_e( 'Tables', 'dualpress' ); ?></div>
			</div>
			<div class="dualpress-sync-stat">
				<div class="dualpress-sync-stat-value" id="stat-rows">0</div>
				<div class="dualpress-sync-stat-label"><?php esc_html_e( 'Rows Synced', 'dualpress' ); ?></div>
			</div>
			<div class="dualpress-sync-stat">
				<div class="dualpress-sync-stat-value" id="stat-skipped">0</div>
				<div class="dualpress-sync-stat-label"><?php esc_html_e( 'Skipped', 'dualpress' ); ?></div>
			</div>
			<div class="dualpress-sync-stat">
				<div class="dualpress-sync-stat-value" id="stat-time">0s</div>
				<div class="dualpress-sync-stat-label"><?php esc_html_e( 'Elapsed', 'dualpress' ); ?></div>
			</div>
		</div>
	</div>

	<div class="dualpress-table-list">
		<?php foreach ( $plugin_tables as $table ) : 
			$full_table = $wpdb->prefix . $table;
			$row_count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
			$last_synced = get_option( 'dualpress_last_synced_' . $table, 0 );
		?>
		<div class="dualpress-table-item" data-table="<?php echo esc_attr( $table ); ?>" data-rows="<?php echo esc_attr( $row_count ); ?>">
			<div class="dualpress-table-name">
				<?php echo esc_html( $wpdb->prefix . $table ); ?>
				<?php if ( $last_synced > 0 ) : ?>
					<small style="color: #999;">(last ID: <?php echo esc_html( $last_synced ); ?>)</small>
				<?php endif; ?>
			</div>
			<div class="dualpress-table-rows"><?php echo number_format( $row_count ); ?> rows</div>
			<div class="dualpress-table-action">
				<button type="button" class="button dualpress-sync-full-btn" data-table="<?php echo esc_attr( $table ); ?>">
					<?php esc_html_e( 'Sync All', 'dualpress' ); ?>
				</button>
			</div>
			<div class="dualpress-table-status">
				<span class="dualpress-status-badge dualpress-status-pending"><?php esc_html_e( 'Pending', 'dualpress' ); ?></span>
			</div>
			<div class="dualpress-table-progress">
				<div class="dualpress-table-progress-bar">
					<div class="dualpress-table-progress-fill"></div>
				</div>
				<div class="dualpress-table-progress-text"></div>
			</div>
		</div>
		<?php endforeach; ?>
		
		<?php if ( empty( $plugin_tables ) ) : ?>
		<div class="dualpress-table-item">
			<em><?php esc_html_e( 'No plugin tables found to sync.', 'dualpress' ); ?></em>
		</div>
		<?php endif; ?>
	</div>

	<div class="dualpress-log-output" id="dualpress-log-output"></div>
</div>

<script>
jQuery(function($) {
	var syncing = false, stopRequested = false, startTime = 0, timerInterval = null;
	var tables = <?php echo wp_json_encode( array_values( $plugin_tables ) ); ?>;
	var currentTableIndex = 0, totalRowsSynced = 0, skippedTables = 0;

	function log(message, type) {
		var $log = $('#dualpress-log-output');
		var time = new Date().toLocaleTimeString();
		$log.append('<div class="dualpress-log-line"><span class="dualpress-log-time">[' + time + ']</span> <span class="dualpress-log-' + (type||'') + '">' + message + '</span></div>');
		$log.scrollTop($log[0].scrollHeight);
	}

	function updateTimer() {
		var elapsed = Math.floor((Date.now() - startTime) / 1000);
		var mins = Math.floor(elapsed / 60), secs = elapsed % 60;
		$('#stat-time').text(mins > 0 ? mins + 'm ' + secs + 's' : secs + 's');
	}

	function setTableStatus(table, status, progress) {
		var $item = $('.dualpress-table-item[data-table="' + table + '"]');
		var $badge = $item.find('.dualpress-status-badge');
		var $progress = $item.find('.dualpress-table-progress-fill');
		
		$badge.removeClass('dualpress-status-pending dualpress-status-syncing dualpress-status-done dualpress-status-skipped dualpress-status-error');
		
		switch(status) {
			case 'syncing': $badge.addClass('dualpress-status-syncing').text('Syncing...'); break;
			case 'done': $badge.addClass('dualpress-status-done').text('Done'); $progress.css('width', '100%'); break;
			case 'skipped': $badge.addClass('dualpress-status-skipped').text('Skipped'); $progress.css('width', '100%').css('background', '#ffc107'); break;
			case 'error': $badge.addClass('dualpress-status-error').text('Error'); break;
		}
		if (typeof progress === 'number') $progress.css('width', progress + '%');
	}

	function syncNextTable() {
		if (stopRequested || currentTableIndex >= tables.length) { finishSync(); return; }

		var table = tables[currentTableIndex];
		setTableStatus(table, 'syncing', 10);
		log('Syncing table: <span class="dualpress-log-table">' + table + '</span>');

		$.ajax({
			url: ajaxurl, method: 'POST',
			data: { action: 'dualpress_sync_single_table', table: table, _wpnonce: '<?php echo wp_create_nonce( 'dualpress_table_sync' ); ?>' },
			success: function(response) {
				if (response.success) {
					var data = response.data;
					if (data.skipped) {
						setTableStatus(table, 'skipped'); skippedTables++;
						log('Table <span class="dualpress-log-table">' + table + '</span> skipped (checksum match)', 'success');
					} else {
						setTableStatus(table, 'done');
						totalRowsSynced += data.rows_synced || 0;
						log('Table <span class="dualpress-log-table">' + table + '</span> synced: ' + (data.rows_synced || 0) + ' rows', 'success');
					}
				} else {
					setTableStatus(table, 'error');
					log('Error syncing ' + table + ': ' + (response.data || 'Unknown error'), 'error');
				}
				currentTableIndex++;
				$('#stat-tables').text(currentTableIndex + '/' + tables.length);
				$('#stat-rows').text(totalRowsSynced.toLocaleString());
				$('#stat-skipped').text(skippedTables);
				setTimeout(syncNextTable, 100);
			},
			error: function(xhr, status, error) {
				setTableStatus(table, 'error');
				log('Error syncing ' + table + ': ' + error, 'error');
				currentTableIndex++;
				setTimeout(syncNextTable, 100);
			}
		});
	}

	function finishSync() {
		syncing = false; clearInterval(timerInterval);
		$('#dualpress-start-table-sync').prop('disabled', false).find('.dashicons').removeClass('spin');
		$('#dualpress-stop-sync').hide();
		log('Sync completed! ' + totalRowsSynced + ' rows synced, ' + skippedTables + ' tables skipped.', 'success');
	}

	$('#dualpress-start-table-sync').on('click', function() {
		if (syncing) return;
		syncing = true; stopRequested = false; currentTableIndex = 0; totalRowsSynced = 0; skippedTables = 0; startTime = Date.now();
		$(this).prop('disabled', true).find('.dashicons').addClass('spin');
		$('#dualpress-stop-sync').show();
		$('#dualpress-sync-summary').show();
		$('#dualpress-log-output').empty();
		$('.dualpress-status-badge').removeClass('dualpress-status-done dualpress-status-syncing dualpress-status-skipped dualpress-status-error').addClass('dualpress-status-pending').text('Pending');
		$('.dualpress-table-progress-fill').css('width', '0%').css('background', '#2271b1');
		$('#stat-tables').text('0/' + tables.length); $('#stat-rows').text('0'); $('#stat-skipped').text('0');
		timerInterval = setInterval(updateTimer, 1000);
		log('Starting table sync...', 'success');
		syncNextTable();
	});

	$('#dualpress-stop-sync').on('click', function() { stopRequested = true; log('Stop requested...', 'error'); });
	$('#dualpress-show-log').on('change', function() { $('#dualpress-log-output').toggleClass('active', this.checked); });

	// Full sync for individual table with progress
	$('.dualpress-sync-full-btn').on('click', function() {
		var $btn = $(this);
		var table = $btn.data('table');
		var $item = $('.dualpress-table-item[data-table="' + table + '"]');
		var $progress = $item.find('.dualpress-table-progress-fill');
		var $progressText = $item.find('.dualpress-table-progress-text');
		var $badge = $item.find('.dualpress-status-badge');
		var totalRows = parseInt($item.data('rows')) || 0;
		
		if ($btn.prop('disabled')) return;
		
		$btn.prop('disabled', true).text('Syncing...');
		$badge.removeClass('dualpress-status-pending dualpress-status-done dualpress-status-skipped dualpress-status-error')
			.addClass('dualpress-status-syncing').text('Syncing...');
		$progress.css('width', '0%').css('background', '#2271b1');
		$progressText.text('0%');
		
		var syncedRows = 0;
		var offset = 0;
		var chunkSize = 1000;
		
		function syncChunk() {
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'dualpress_sync_table_full',
					table: table,
					offset: offset,
					chunk_size: chunkSize,
					_wpnonce: '<?php echo wp_create_nonce( 'dualpress_table_sync' ); ?>'
				},
				success: function(response) {
					if (response.success) {
						syncedRows += response.data.rows_synced || 0;
						var percent = totalRows > 0 ? Math.min(100, Math.round((syncedRows / totalRows) * 100)) : 100;
						$progress.css('width', percent + '%');
						$progressText.text(percent + '% (' + syncedRows.toLocaleString() + '/' + totalRows.toLocaleString() + ')');
						
						if (response.data.has_more) {
							offset += chunkSize;
							setTimeout(syncChunk, 50);
						} else {
							// Done
							$badge.removeClass('dualpress-status-syncing').addClass('dualpress-status-done').text('Done');
							$progress.css('width', '100%');
							$progressText.text('100% (' + syncedRows.toLocaleString() + ' rows)');
							$btn.prop('disabled', false).text('Sync All');
						}
					} else {
						$badge.removeClass('dualpress-status-syncing').addClass('dualpress-status-error').text('Error');
						$progressText.text('Error: ' + (response.data || 'Unknown'));
						$btn.prop('disabled', false).text('Sync All');
					}
				},
				error: function(xhr, status, error) {
					$badge.removeClass('dualpress-status-syncing').addClass('dualpress-status-error').text('Error');
					$progressText.text('Error: ' + error);
					$btn.prop('disabled', false).text('Sync All');
				}
			});
		}
		
		syncChunk();
	});
});
</script>
