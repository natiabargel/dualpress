<?php
/**
 * Admin view — Tools tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

$stats       = DualPress_Queue::get_stats();
$configured  = DualPress_Settings::is_configured();
?>

<div class="dualpress-tools-grid">

	<!-- Manual Sync Card -->
	<div class="dualpress-card">
		<h2><?php esc_html_e( 'Manual Sync', 'dualpress' ); ?></h2>
		<p><?php esc_html_e( 'Process the sync queue immediately without waiting for the next WP-Cron cycle.', 'dualpress' ); ?></p>
		<button
			type="button"
			id="dualpress-now"
			class="button button-primary"
			<?php disabled( ! $configured ); ?>
		>
			<?php esc_html_e( 'Sync Now', 'dualpress' ); ?>
		</button>
		<span id="dualpress-msg" class="dualpress-inline-msg"></span>
	</div>

	<!-- Full Sync Card -->
	<div class="dualpress-card">
		<h2><?php esc_html_e( 'Full Sync', 'dualpress' ); ?></h2>
		<p>
			<?php esc_html_e( 'Push every row from all enabled tables to the remote server. Use this for initial setup or after a long outage.', 'dualpress' ); ?>
		</p>
		<div class="notice notice-warning inline dualpress-warning-inline">
			<p>&#9888; <?php esc_html_e( 'This may take several minutes for large databases. The page will wait for completion.', 'dualpress' ); ?></p>
		</div>
		<br>
		<button
			type="button"
			id="dualpress-full-sync"
			class="button button-secondary"
			<?php disabled( ! $configured ); ?>
		>
			<?php esc_html_e( 'Start Full Sync', 'dualpress' ); ?>
		</button>
		&nbsp;&nbsp;
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=dualpress-table-sync' ) ); ?>" class="dualpress-link-secondary">
			<?php esc_html_e( 'Show Table Sync Manager →', 'dualpress' ); ?>
		</a>
		<span id="dualpress-full-sync-msg" class="dualpress-inline-msg"></span>
	</div>

	<!-- Queue Status Card -->
	<div class="dualpress-card dualpress-queue-card">
		<h2><?php esc_html_e( 'Queue Status', 'dualpress' ); ?></h2>

		<table class="dualpress-stats-table">
			<tr>
				<td><?php esc_html_e( 'Pending', 'dualpress' ); ?></td>
				<td><span class="dualpress-badge dualpress-badge-pending"><?php echo absint( $stats['pending'] ); ?></span></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Processing', 'dualpress' ); ?></td>
				<td><span class="dualpress-badge dualpress-badge-processing"><?php echo absint( $stats['processing'] ); ?></span></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Completed', 'dualpress' ); ?></td>
				<td><span class="dualpress-badge dualpress-badge-completed"><?php echo absint( $stats['completed'] ); ?></span></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Failed', 'dualpress' ); ?></td>
				<td><span class="dualpress-badge dualpress-badge-failed"><?php echo absint( $stats['failed'] ); ?></span></td>
			</tr>
			<?php if ( $stats['high_failures'] > 0 ) : ?>
			<tr class="dualpress-row-warning">
				<td><?php esc_html_e( 'Failing 10+ times', 'dualpress' ); ?></td>
				<td><span class="dualpress-badge dualpress-badge-failed"><?php echo absint( $stats['high_failures'] ); ?></span></td>
			</tr>
			<?php endif; ?>
		</table>

		<div class="dualpress-queue-actions">
			<button type="button" id="dualpress-retry-failed" class="button button-secondary"
				<?php disabled( $stats['failed'] < 1 ); ?>>
				<?php esc_html_e( 'Retry Failed', 'dualpress' ); ?>
			</button>
			<button type="button" id="dualpress-clear-queue" class="button button-link-delete">
				<?php esc_html_e( 'Clear Queue', 'dualpress' ); ?>
			</button>
		</div>
		<span id="dualpress-queue-msg" class="dualpress-inline-msg"></span>
	</div>

	<!-- Queue Viewer -->
	<div class="dualpress-card dualpress-full-width">
		<h2><?php esc_html_e( 'Queue Items', 'dualpress' ); ?></h2>

		<div class="dualpress-queue-filter">
			<select id="dualpress-queue-status-filter">
				<option value=""><?php esc_html_e( 'All', 'dualpress' ); ?></option>
				<option value="pending"><?php esc_html_e( 'Pending', 'dualpress' ); ?></option>
				<option value="processing"><?php esc_html_e( 'Processing', 'dualpress' ); ?></option>
				<option value="completed"><?php esc_html_e( 'Completed', 'dualpress' ); ?></option>
				<option value="failed"><?php esc_html_e( 'Failed', 'dualpress' ); ?></option>
			</select>
		</div>

		<?php
		$queue_data = DualPress_Queue::get_items( '', 25, 1 );
		$queue_rows = $queue_data['rows'];
		$queue_total = $queue_data['total'];
		?>

		<?php if ( empty( $queue_rows ) ) : ?>
			<p class="dualpress-empty"><?php esc_html_e( 'The queue is empty.', 'dualpress' ); ?></p>
		<?php else : ?>
			<div class="dualpress-table-wrapper">
				<table class="widefat striped dualpress-table" id="dualpress-queue-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'dualpress' ); ?></th>
							<th><?php esc_html_e( 'Table', 'dualpress' ); ?></th>
							<th><?php esc_html_e( 'Action', 'dualpress' ); ?></th>
							<th><?php esc_html_e( 'Status', 'dualpress' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'dualpress' ); ?></th>
							<th><?php esc_html_e( 'Created', 'dualpress' ); ?></th>
							<th><?php esc_html_e( 'Error', 'dualpress' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $queue_rows as $row ) : ?>
							<tr class="dualpress-queue-row">
								<td><?php echo absint( $row['id'] ); ?></td>
								<td><code><?php echo esc_html( $row['table_name'] ); ?></code></td>
								<td>
									<span class="dualpress-action-badge dualpress-action-<?php echo esc_attr( strtolower( $row['action'] ) ); ?>">
										<?php echo esc_html( $row['action'] ); ?>
									</span>
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
							<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $row['row_data'] ) ) : ?>
							<tr class="dualpress-queue-detail" style="display:none;">
								<td colspan="7">
									<pre class="dualpress-debug-data"><?php echo esc_html( json_encode( json_decode( $row['row_data'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
								</td>
							</tr>
							<?php endif; ?>
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
	</div>

</div><!-- .dualpress-tools-grid -->

<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
<style>
.dualpress-queue-row { cursor: pointer; }
.dualpress-queue-row:hover { background: #f0f6fc; }
.dualpress-debug-data {
	background: #1e1e1e;
	color: #d4d4d4;
	padding: 12px;
	border-radius: 4px;
	font-size: 12px;
	max-height: 300px;
	overflow: auto;
	margin: 0;
	white-space: pre-wrap;
	word-break: break-word;
}
.dualpress-queue-detail td {
	padding: 0 !important;
	background: #f9f9f9;
}
.dualpress-queue-detail td > pre {
	margin: 8px;
}
</style>
<script>
jQuery(function($) {
	$('#dualpress-queue-table').on('click', '.dualpress-queue-row', function() {
		$(this).next('.dualpress-queue-detail').toggle();
	});
});
</script>
<?php endif; ?>
