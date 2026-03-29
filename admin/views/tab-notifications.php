<?php
/**
 * Admin view — Notifications tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

$email           = DualPress_Settings::get_notification_email();
$enabled_events  = DualPress_Settings::get_notification_events();

$events = array(
	'connection_lost'      => array(
		'label'       => __( 'Connection to remote server fails', 'dualpress' ),
		'description' => __( 'Sent when DualPress cannot reach the remote server.', 'dualpress' ),
		'default'     => true,
	),
	'connection_restored'  => array(
		'label'       => __( 'Connection restored after failure', 'dualpress' ),
		'description' => __( 'Sent when connectivity to the remote is re-established.', 'dualpress' ),
		'default'     => true,
	),
	'sync_item_failed'     => array(
		'label'       => __( 'Sync item fails 10+ times', 'dualpress' ),
		'description' => __( 'Sent when one or more queue items have failed more than 10 consecutive attempts.', 'dualpress' ),
		'default'     => true,
	),
	'conflict_detected'    => array(
		'label'       => __( 'Conflict detected (may be frequent)', 'dualpress' ),
		'description' => __( 'Sent each time a write conflict is detected and resolved automatically. Disable if conflicts are very frequent.', 'dualpress' ),
		'default'     => false,
	),
	'full_sync_completed'  => array(
		'label'       => __( 'Full sync completed', 'dualpress' ),
		'description' => __( 'Sent after a full database synchronization finishes successfully.', 'dualpress' ),
		'default'     => true,
	),
	'queue_threshold'      => array(
		'label'       => __( 'Queue has 100+ pending items', 'dualpress' ),
		'description' => __( 'Sent when the sync queue backlog exceeds 100 items, which may indicate connectivity problems.', 'dualpress' ),
		'default'     => true,
	),
);
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dualpress-form">
	<?php wp_nonce_field( 'dualpress_save_settings', 'dualpress_nonce' ); ?>
	<input type="hidden" name="action" value="dualpress_save_settings">
	<input type="hidden" name="dualpress_tab" value="notifications">

	<table class="form-table dualpress-form-table" role="presentation">

		<!-- Notification Email -->
		<tr>
			<th scope="row">
				<label for="dualpress_notification_email">
					<?php esc_html_e( 'Send notifications to', 'dualpress' ); ?>
				</label>
			</th>
			<td>
				<input
					type="email"
					id="dualpress_notification_email"
					name="dualpress_notification_email"
					value="<?php echo esc_attr( $email ); ?>"
					class="regular-text"
					placeholder="admin@example.com"
				>
				<p class="description">
					<?php esc_html_e( 'Email address to receive sync notifications. Leave blank to disable all email notifications.', 'dualpress' ); ?>
				</p>
			</td>
		</tr>

		<!-- Event Toggles -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Notify me when', 'dualpress' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Notification events', 'dualpress' ); ?></legend>

					<?php foreach ( $events as $slug => $event ) : ?>
						<?php $checked = in_array( $slug, $enabled_events, true ); ?>
						<div class="dualpress-notification-event">
							<label>
								<input
									type="checkbox"
									name="dualpress_notify_<?php echo esc_attr( $slug ); ?>"
									value="1"
									<?php checked( $checked ); ?>
								>
								<strong><?php echo esc_html( $event['label'] ); ?></strong>
							</label>
							<p class="description"><?php echo esc_html( $event['description'] ); ?></p>
						</div>
					<?php endforeach; ?>

				</fieldset>
			</td>
		</tr>

	</table>

	<div class="dualpress-card dualpress-info-card">
		<h3><?php esc_html_e( 'About Notifications', 'dualpress' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Notifications are sent via WordPress\'s wp_mail() function using your server\'s email configuration.', 'dualpress' ); ?></li>
			<li><?php esc_html_e( 'Repeat notifications of the same type are throttled to at most once per hour.', 'dualpress' ); ?></li>
			<li><?php esc_html_e( 'For reliable email delivery, ensure you have an SMTP plugin or transactional email service configured.', 'dualpress' ); ?></li>
		</ul>
	</div>

	<?php submit_button( __( 'Save Notification Settings', 'dualpress' ) ); ?>
</form>
