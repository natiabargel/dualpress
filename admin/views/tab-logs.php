<?php
/**
 * Admin view — Logs tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

// Initial log load — first page, no filters.
$log_data = DualPress_Logger::get_logs( array( 'per_page' => 50, 'page' => 1 ) );
$log_rows  = $log_data['rows'];
$log_total = $log_data['total'];

$level_icons = array(
	'debug'   => '&#9679;',
	'info'    => '&#9989;',
	'warning' => '&#9888;',
	'error'   => '&#10060;',
);
?>

<div class="dualpress-logs-toolbar">
	<div class="dualpress-logs-filters">

		<select id="dualpress-log-level-filter">
			<option value=""><?php esc_html_e( 'All Levels', 'dualpress' ); ?></option>
			<option value="debug"><?php esc_html_e( 'Debug', 'dualpress' ); ?></option>
			<option value="info"><?php esc_html_e( 'Info', 'dualpress' ); ?></option>
			<option value="warning"><?php esc_html_e( 'Warning', 'dualpress' ); ?></option>
			<option value="error"><?php esc_html_e( 'Error', 'dualpress' ); ?></option>
		</select>

		<select id="dualpress-log-time-filter">
			<option value=""><?php esc_html_e( 'All Time', 'dualpress' ); ?></option>
			<option value="1h"><?php esc_html_e( 'Last 1 hour', 'dualpress' ); ?></option>
			<option value="24h"><?php esc_html_e( 'Last 24 hours', 'dualpress' ); ?></option>
			<option value="7d"><?php esc_html_e( 'Last 7 days', 'dualpress' ); ?></option>
		</select>

		<input
			type="search"
			id="dualpress-log-search"
			placeholder="<?php esc_attr_e( 'Search messages&hellip;', 'dualpress' ); ?>"
			class="regular-text"
		>

		<button type="button" id="dualpress-log-filter-btn" class="button">
			<?php esc_html_e( 'Filter', 'dualpress' ); ?>
		</button>

	</div>

	<button type="button" id="dualpress-clear-logs" class="button button-link-delete">
		<?php esc_html_e( 'Clear All', 'dualpress' ); ?>
	</button>
</div>

<div id="dualpress-logs-container">

	<?php if ( empty( $log_rows ) ) : ?>
		<p class="dualpress-empty"><?php esc_html_e( 'No log entries found.', 'dualpress' ); ?></p>
	<?php else : ?>

		<div class="dualpress-table-wrapper">
			<table class="widefat striped dualpress-table dualpress-logs-table" id="dualpress-logs-table">
				<thead>
					<tr>
						<th class="dualpress-col-time"><?php esc_html_e( 'Time (UTC)', 'dualpress' ); ?></th>
						<th class="dualpress-col-level"><?php esc_html_e( 'Level', 'dualpress' ); ?></th>
						<th class="dualpress-col-event"><?php esc_html_e( 'Event', 'dualpress' ); ?></th>
						<th><?php esc_html_e( 'Message', 'dualpress' ); ?></th>
					</tr>
				</thead>
				<tbody id="dualpress-logs-tbody">
					<?php foreach ( $log_rows as $row ) : ?>
						<tr class="dualpress-log-row dualpress-log-<?php echo esc_attr( $row['log_level'] ); ?>">
							<td class="dualpress-col-time">
								<span title="<?php echo esc_attr( $row['created_at'] ); ?>">
									<?php echo esc_html( $row['created_at'] ); ?>
								</span>
							</td>
							<td class="dualpress-col-level">
								<span class="dualpress-level-badge dualpress-level-<?php echo esc_attr( $row['log_level'] ); ?>">
									<?php echo isset( $level_icons[ $row['log_level'] ] ) ? $level_icons[ $row['log_level'] ] : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php echo esc_html( strtoupper( $row['log_level'] ) ); ?>
								</span>
							</td>
							<td class="dualpress-col-event">
								<code><?php echo esc_html( $row['event_type'] ); ?></code>
							</td>
							<td class="dualpress-col-message">
								<?php echo esc_html( $row['message'] ); ?>
								<?php if ( $row['context'] ) : ?>
									<details class="dualpress-log-context">
										<summary><?php esc_html_e( 'Context', 'dualpress' ); ?></summary>
										<pre><?php echo esc_html( json_encode( json_decode( $row['context'] ), JSON_PRETTY_PRINT ) ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="dualpress-logs-pagination">
			<span id="dualpress-logs-count">
				<?php
				printf(
					/* translators: 1: shown, 2: total */
					esc_html__( 'Showing %1$d of %2$d entries', 'dualpress' ),
					min( 50, $log_total ),
					$log_total
				);
				?>
			</span>
			<span class="dualpress-pagination-buttons">
				<button type="button" id="dualpress-logs-prev" class="button" disabled>
					&laquo; <?php esc_html_e( 'Prev', 'dualpress' ); ?>
				</button>
				<span id="dualpress-logs-page-indicator">1</span>
				<button type="button" id="dualpress-logs-next" class="button"
					<?php disabled( $log_total <= 50 ); ?>>
					<?php esc_html_e( 'Next', 'dualpress' ); ?> &raquo;
				</button>
			</span>
		</div>

	<?php endif; ?>

</div><!-- #dualpress-logs-container -->
