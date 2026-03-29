<?php
/**
 * Admin view — Backup tab.
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

$existing_backups = DualPress_Backup::list_backups();
?>

<style>
/* ---- Backup page layout ---- */
.dualpress-backup-wrap { max-width: 860px; }
.dualpress-backup-progress-wrap { display: none; margin-top: 16px; }
.dualpress-backup-progress-bar  { height: 14px; background: #ddd; border-radius: 7px; overflow: hidden; margin-bottom: 6px; }
.dualpress-backup-progress-fill { height: 100%; background: #2271b1; width: 0%; transition: width 0.4s ease; border-radius: 7px; }
.dualpress-backup-progress-text { font-size: 13px; color: #646970; }
.dualpress-backup-result  { display: none; margin-top: 20px; }
.dualpress-backup-result.is-visible { display: block; }
.dualpress-code-block {
	background: #1e1e1e;
	color: #d4d4d4;
	font-family: 'Courier New', Courier, monospace;
	font-size: 12px;
	line-height: 1.5;
	padding: 14px 18px;
	border-radius: 4px;
	overflow-x: auto;
	max-height: 320px;
	overflow-y: auto;
	white-space: pre;
	margin: 10px 0;
}
.dualpress-backup-list-table { margin-top: 10px; font-size: 13px; }
.dualpress-backup-list-table th,
.dualpress-backup-list-table td { padding: 8px 10px; }
.dualpress-deploy-section { margin-top: 24px; }
.dualpress-deploy-actions { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center; }
</style>

<div class="dualpress-backup-wrap">

	<!-- Backup Tool Card -->
	<div class="dualpress-card">
		<h2><?php esc_html_e( 'Create Site Backup', 'dualpress' ); ?></h2>
		<p>
			<?php esc_html_e( 'Creates a full WordPress backup (files + database) as a .tar.gz archive in your site root directory.', 'dualpress' ); ?>
		</p>

		<!-- Backup Options -->
		<table class="form-table dualpress-form-table" style="margin-top:0">
			<tr>
				<th scope="row" style="width:180px;padding-left:0">
					<?php esc_html_e( 'Backup Options', 'dualpress' ); ?>
				</th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:8px;">
							<input type="checkbox" id="dp-backup-skip-db" value="1">
							<?php esc_html_e( 'Skip database backup', 'dualpress' ); ?>
						</label>
						<label style="display:block;margin-bottom:8px;">
							<input type="checkbox" id="dp-backup-skip-files" value="1">
							<?php esc_html_e( 'Skip files backup', 'dualpress' ); ?>
						</label>
						<label style="display:block;margin-bottom:8px;">
							<input type="checkbox" id="dp-backup-skip-uploads" value="1">
							<?php esc_html_e( 'Skip wp-content/uploads/', 'dualpress' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row" style="width:180px;padding-left:0">
					<label for="dp-backup-extra-excludes"><?php esc_html_e( 'Additional Exclusions', 'dualpress' ); ?></label>
				</th>
				<td>
					<textarea
						id="dp-backup-extra-excludes"
						rows="5"
						style="width:100%;max-width:540px;font-family:monospace;font-size:12px"
						placeholder="wp-content/themes/old-theme/&#10;wp-content/uploads/2019/&#10;large-directory/"
					></textarea>
					<p class="description">
						<?php esc_html_e( 'One path per line, relative to WordPress root. Directories must end with /. Supports * wildcards.', 'dualpress' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
			<button type="button" id="dp-backup-start" class="button button-primary">
				<?php esc_html_e( 'Create Backup', 'dualpress' ); ?>
			</button>
			<button type="button" id="dp-backup-cancel" class="button" style="display:none">
				<?php esc_html_e( 'Cancel', 'dualpress' ); ?>
			</button>
			<span id="dp-backup-msg" class="dualpress-inline-msg"></span>
		</div>

		<!-- Progress bar -->
		<div class="dualpress-backup-progress-wrap" id="dp-backup-progress-wrap">
			<div class="dualpress-backup-progress-bar">
				<div class="dualpress-backup-progress-fill" id="dp-backup-progress-fill"></div>
			</div>
			<div class="dualpress-backup-progress-text" id="dp-backup-progress-text">
				<?php esc_html_e( 'Starting…', 'dualpress' ); ?>
			</div>
		</div>

		<!-- Result (shown after completion) -->
		<div class="dualpress-backup-result" id="dp-backup-result">
			<div class="notice notice-success inline dualpress-warning-inline">
				<p>
					<strong><?php esc_html_e( 'Backup complete!', 'dualpress' ); ?></strong>
					<span id="dp-backup-filename-display"></span>
					<span id="dp-backup-size-display" style="color:#646970;margin-left:6px"></span>
				</p>
			</div>
			
			<!-- Download & URL section -->
			<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0;">
				<a id="dp-backup-download-link" href="#" class="button button-primary">
					&#8595; <?php esc_html_e( 'Download Backup', 'dualpress' ); ?>
				</a>
				<button type="button" id="dp-backup-copy-url" class="button">
					<?php esc_html_e( 'Copy Direct URL', 'dualpress' ); ?>
				</button>
			</div>
			
			<!-- Direct URL display -->
			<div style="margin:12px 0;">
				<label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Direct Download URL:', 'dualpress' ); ?></label>
				<input type="text" id="dp-backup-direct-url" readonly style="width:100%;max-width:600px;font-family:monospace;font-size:12px;padding:6px 8px;" />
			</div>
			
			<!-- Deploy Tool section -->
			<div class="dualpress-deploy-section">
				<h3><?php esc_html_e( 'Deploy Tool', 'dualpress' ); ?></h3>
				<p>
					<?php esc_html_e( 'Use this PHP CLI script to restore the backup on a fresh server:', 'dualpress' ); ?>
				</p>
				
				<!-- curl examples -->
				<div style="margin:12px 0;background:#f6f7f7;padding:12px;border-radius:4px;">
					<label style="font-weight:600;display:block;margin-bottom:8px;"><?php esc_html_e( 'Download via CLI:', 'dualpress' ); ?></label>
					<code style="display:block;font-size:12px;margin-bottom:6px;word-break:break-all;">curl -kO <?php echo esc_url( site_url( '/wp-content/plugins/dualpress/deploy.php.txt' ) ); ?> && mv deploy.php.txt deploy.php</code>
					<code id="dp-backup-curl-example" style="display:block;font-size:12px;word-break:break-all;"></code>
				</div>
				
				<div class="dualpress-deploy-actions">
					<a
						href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=dualpress_backup_dl_deploy&nonce=' . wp_create_nonce( 'dualpress_backup_dl_deploy' ) ) ); ?>"
						class="button"
						download="deploy.php"
					>
						&#8595; <?php esc_html_e( 'Download deploy.php', 'dualpress' ); ?>
					</a>
					<button type="button" id="dp-deploy-copy" class="button">
						<?php esc_html_e( 'Copy to clipboard', 'dualpress' ); ?>
					</button>
				</div>
				<pre class="dualpress-code-block" id="dp-deploy-code"><?php echo esc_html( file_get_contents( DUALPRESS_DIR . 'deploy.php' ) ); ?></pre>
			</div>
		</div>
	</div><!-- .dualpress-card -->

	<?php if ( ! empty( $existing_backups ) ) : ?>
	<!-- Existing Backups Card -->
	<div class="dualpress-card dualpress-info-card">
		<h2><?php esc_html_e( 'Existing Backups', 'dualpress' ); ?></h2>
		<div class="dualpress-table-wrapper">
			<table class="widefat striped dualpress-table dualpress-backup-list-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Filename', 'dualpress' ); ?></th>
						<th><?php esc_html_e( 'Size', 'dualpress' ); ?></th>
						<th><?php esc_html_e( 'Created', 'dualpress' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'dualpress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $existing_backups as $backup ) : ?>
						<?php
						$dl_id       = preg_match( '/^dualpress-([a-z0-9]+)-/', $backup['filename'], $m ) ? $m[1] : '';
						$dl_nonce    = wp_create_nonce( 'dualpress_backup_dl_' . $dl_id );
						$del_nonce   = wp_create_nonce( 'dualpress_backup_delete_' . $dl_id );
						?>
						<tr data-backup-id="<?php echo esc_attr( $dl_id ); ?>">
							<td><code><?php echo esc_html( $backup['filename'] ); ?></code></td>
							<td><?php echo esc_html( $backup['size_hr'] ); ?></td>
							<td><?php echo esc_html( $backup['modified'] ); ?></td>
							<td>
								<a
									href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=dualpress_backup_download&backup_id=' . urlencode( $dl_id ) . '&filename=' . urlencode( $backup['filename'] ) . '&nonce=' . $dl_nonce ) ); ?>"
									class="button button-small"
								>
									&#8595; <?php esc_html_e( 'Download', 'dualpress' ); ?>
								</a>
								<button
									type="button"
									class="button button-small dp-backup-delete"
									data-backup-id="<?php echo esc_attr( $dl_id ); ?>"
									data-filename="<?php echo esc_attr( $backup['filename'] ); ?>"
									data-nonce="<?php echo esc_attr( $del_nonce ); ?>"
									style="color:#b32d2e;"
								>
									&#10005; <?php esc_html_e( 'Delete', 'dualpress' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>

	<!-- Auto-excluded paths info card -->
	<div class="dualpress-card dualpress-info-card" style="border-left-color:#72aee6">
		<h2><?php esc_html_e( 'Auto-Excluded Paths', 'dualpress' ); ?></h2>
		<ul style="margin:0 0 0 1.2em;line-height:1.9">
			<li><code>wp-content/cache/</code></li>
			<li><code>wp-content/uploads/cache/</code></li>
			<li><code>wp-content/backup*/</code></li>
			<li><code>wp-content/updraft/</code></li>
			<li><code>wp-content/ai1wm-backups/</code></li>
			<li><code>wp-content/debug.log</code></li>
			<li><code>.git/</code></li>
			<li><code>*.log</code></li>
			<li><code>dualpress*.tar.gz</code></li>
		</ul>
		<p class="description" style="margin-top:8px">
			<?php esc_html_e( 'Database: cache tables, WordFence live tables, LiteSpeed cache tables, and DualPress internal tables are excluded from the SQL dump.', 'dualpress' ); ?>
		</p>
	</div>

</div><!-- .dualpress-backup-wrap -->

<script>
jQuery( document ).ready( function ( $ ) {
	'use strict';

	// Use inline PHP values - more reliable than waiting for localized script
	var ajaxUrl   = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
	var nonce     = '<?php echo esc_attr( wp_create_nonce( 'dualpress_admin' ) ); ?>';
	var backupId  = null;
	var cancelled = false;

	function setProgress( pct, text ) {
		$( '#dp-backup-progress-fill' ).css( 'width', pct + '%' );
		$( '#dp-backup-progress-text' ).text( text );
	}

	function dpAjax( action, data, success, fail ) {
		$.post(
			ajaxUrl,
			Object.assign( { action: action, nonce: nonce }, data ),
			function ( r ) {
				if ( r.success ) {
					success( r.data );
				} else {
					var msg = ( r.data && r.data.message ) ? r.data.message : 'An error occurred.';
					if ( typeof fail === 'function' ) fail( msg );
				}
			}
		).fail( function ( xhr ) {
			if ( typeof fail === 'function' ) fail( 'HTTP ' + xhr.status );
		} );
	}

	function doChunk( offset, total ) {
		if ( cancelled ) return;

		dpAjax(
			'dualpress_backup_chunk',
			{ backup_id: backupId, offset: offset },
			function ( data ) {
				if ( cancelled ) return;
				var pct  = data.progress_pct;
				var text = pct + '% — ' + data.processed.toLocaleString() + ' / ' + data.total.toLocaleString() + ' files';
				setProgress( pct, text );

				if ( data.has_more ) {
					doChunk( offset + <?php echo (int) DualPress_Backup::CHUNK_SIZE; ?>, data.total );
				} else {
					doFinalize();
				}
			},
			function ( err ) {
				onError( err );
			}
		);
	}

	function doFinalize() {
		if ( cancelled ) return;
		setProgress( 95, '<?php esc_html_e( 'Creating database dump and compressing…', 'dualpress' ); ?>');

		dpAjax(
			'dualpress_backup_finalize',
			{ backup_id: backupId },
			function ( data ) {
				if ( cancelled ) return;
				setProgress( 100, '<?php esc_html_e( 'Done!', 'dualpress' ); ?>');
				onComplete( data );
			},
			function ( err ) {
				onError( err );
			}
		);
	}

	function onComplete( data ) {
		$( '#dp-backup-start' ).prop( 'disabled', false );
		$( '#dp-backup-cancel' ).hide();

		$( '#dp-backup-filename-display' ).text( data.filename );
		$( '#dp-backup-size-display' ).text( '(' + data.file_size_hr + ')' );

		// Build download link (via AJAX handler).
		var dlUrl = ajaxUrl + '?action=dualpress_backup_download'
			+ '&backup_id=' + encodeURIComponent( backupId )
			+ '&filename=' + encodeURIComponent( data.filename )
			+ '&nonce=' + encodeURIComponent( data.download_nonce );
		$( '#dp-backup-download-link' ).attr( 'href', dlUrl );

		// Build direct URL (hostname + path to file).
		var siteUrl = '<?php echo esc_url( site_url( '/' ) ); ?>';
		var directUrl = siteUrl + data.filename;
		$( '#dp-backup-direct-url' ).val( directUrl );
		$( '#dp-backup-curl-example' ).text( 'curl -kO ' + directUrl );

		$( '#dp-backup-result' ).addClass( 'is-visible' );
		$( '#dp-backup-msg' ).text( '' ).removeClass( 'is-loading is-error is-success' );
	}

	function onError( msg ) {
		$( '#dp-backup-start' ).prop( 'disabled', false );
		$( '#dp-backup-cancel' ).hide();
		$( '#dp-backup-progress-wrap' ).hide();
		$( '#dp-backup-msg' )
			.text( msg )
			.removeClass( 'is-loading is-success' )
			.addClass( 'is-error' );
		backupId  = null;
		cancelled = false;
	}

	$( '#dp-backup-start' ).on( 'click', function () {
		cancelled = false;
		backupId  = null;
		$( '#dp-backup-result' ).removeClass( 'is-visible' );
		$( '#dp-backup-msg' )
			.text( '<?php esc_html_e( 'Scanning files…', 'dualpress' ); ?>' )
			.removeClass( 'is-success is-error' )
			.addClass( 'is-loading' );
		$( this ).prop( 'disabled', true );
		$( '#dp-backup-cancel' ).show();
		$( '#dp-backup-progress-wrap' ).show();
		setProgress( 2, '<?php esc_html_e( 'Scanning…', 'dualpress' ); ?>');

		var extra       = $( '#dp-backup-extra-excludes' ).val();
		var skipDb      = $( '#dp-backup-skip-db' ).is( ':checked' ) ? 1 : 0;
		var skipFiles   = $( '#dp-backup-skip-files' ).is( ':checked' ) ? 1 : 0;
		var skipUploads = $( '#dp-backup-skip-uploads' ).is( ':checked' ) ? 1 : 0;

		dpAjax(
			'dualpress_backup_init',
			{
				extra_excludes: extra,
				skip_db: skipDb,
				skip_files: skipFiles,
				skip_uploads: skipUploads
			},
			function ( data ) {
				if ( cancelled ) return;
				backupId = data.backup_id;
				$( '#dp-backup-msg' ).text( '<?php esc_html_e( 'Archiving…', 'dualpress' ); ?>' );
				setProgress( 5, '<?php esc_html_e( 'Found', 'dualpress' ); ?> ' + data.total_files.toLocaleString() + ' <?php esc_html_e( 'files. Archiving…', 'dualpress' ); ?>');
				doChunk( 0, data.total_files );
			},
			function ( err ) {
				onError( err );
			}
		);
	} );

	$( '#dp-backup-cancel' ).on( 'click', function () {
		cancelled = true;
		if ( backupId ) {
			$.post( ajaxUrl, { action: 'dualpress_backup_cancel', nonce: nonce, backup_id: backupId } );
		}
		$( '#dp-backup-start' ).prop( 'disabled', false );
		$( this ).hide();
		$( '#dp-backup-progress-wrap' ).hide();
		$( '#dp-backup-msg' )
			.text( '<?php esc_html_e( 'Cancelled.', 'dualpress' ); ?>' )
			.removeClass( 'is-loading is-success' )
			.addClass( 'is-error' );
		backupId = null;
	} );

	// Copy direct URL to clipboard.
	$( '#dp-backup-copy-url' ).on( 'click', function () {
		var url = $( '#dp-backup-direct-url' ).val();
		var $btn = $( this );
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( url ).then( function () {
				$btn.text( '<?php esc_html_e( 'Copied!', 'dualpress' ); ?>' );
				setTimeout( function () { $btn.text( '<?php esc_html_e( 'Copy Direct URL', 'dualpress' ); ?>' ); }, 2000 );
			} );
		} else {
			$( '#dp-backup-direct-url' ).select();
			document.execCommand( 'copy' );
			$btn.text( '<?php esc_html_e( 'Copied!', 'dualpress' ); ?>' );
			setTimeout( function () { $btn.text( '<?php esc_html_e( 'Copy Direct URL', 'dualpress' ); ?>' ); }, 2000 );
		}
	} );

	// Copy deploy script to clipboard.
	$( '#dp-deploy-copy' ).on( 'click', function () {
		var code = $( '#dp-deploy-code' ).text();
		var $btn = $( this );
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( code ).then( function () {
				$btn.text( '<?php esc_html_e( 'Copied!', 'dualpress' ); ?>' );
				setTimeout( function () { $btn.text( '<?php esc_html_e( 'Copy to clipboard', 'dualpress' ); ?>' ); }, 2000 );
			} );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = code;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			$btn.text( '<?php esc_html_e( 'Copied!', 'dualpress' ); ?>' );
			setTimeout( function () { $btn.text( '<?php esc_html_e( 'Copy to clipboard', 'dualpress' ); ?>' ); }, 2000 );
		}
	} );

	// Delete backup.
	$( '.dp-backup-delete' ).on( 'click', function () {
		var $btn = $( this );
		var filename = $btn.data( 'filename' );
		var backupId = $btn.data( 'backup-id' );
		var delNonce = $btn.data( 'nonce' );

		if ( ! confirm( '<?php esc_html_e( 'Are you sure you want to delete this backup?', 'dualpress' ); ?>\n\n' + filename ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( '<?php esc_html_e( 'Deleting...', 'dualpress' ); ?>' );

		$.post(
			ajaxUrl,
			{
				action: 'dualpress_backup_delete',
				backup_id: backupId,
				filename: filename,
				nonce: delNonce
			},
			function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 300, function () {
						$( this ).remove();
					} );
				} else {
					var msg = ( r.data && r.data.message ) ? r.data.message : '<?php esc_html_e( 'Failed to delete backup.', 'dualpress' ); ?>';
					alert( msg );
					$btn.prop( 'disabled', false ).html( '&#10005; <?php esc_html_e( 'Delete', 'dualpress' ); ?>' );
				}
			}
		).fail( function () {
			alert( '<?php esc_html_e( 'Failed to delete backup.', 'dualpress' ); ?>' );
			$btn.prop( 'disabled', false ).html( '&#10005; <?php esc_html_e( 'Delete', 'dualpress' ); ?>' );
		} );
	} );

} );
</script>
