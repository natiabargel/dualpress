/**
 * WP DB Sync — Admin JavaScript
 *
 * Handles all AJAX interactions on the WP DB Sync settings page.
 *
 * Depends on: jQuery, dualpressAjax (wp_localize_script)
 *
 * @package DualPress
 */

/* global dualpressAjax, ClipboardItem */

( function ( $ ) {
	'use strict';

	// Safety check: dualpressAjax must be defined by wp_localize_script
	if ( typeof dualpressAjax === 'undefined' ) {
		console.warn( 'DualPress: dualpressAjax not defined. Script may have loaded on wrong page.' );
		return;
	}

	const ajaxUrl = dualpressAjax.ajax_url;
	const nonce   = dualpressAjax.nonce;
	const i18n    = dualpressAjax.i18n;

	// ------------------------------------------------------------------ //
	// Utility                                                              //
	// ------------------------------------------------------------------ //

	/**
	 * Show a message next to a button.
	 *
	 * @param {jQuery} $el      The message <span> element.
	 * @param {string} text     Message text.
	 * @param {string} type     'success' | 'error' | 'loading'
	 * @param {number} [timeout] Auto-clear after ms (0 = never).
	 */
	function showMsg( $el, text, type, timeout ) {
		$el.text( text )
			.removeClass( 'is-success is-error is-loading' )
			.addClass( 'is-' + type );

		if ( timeout ) {
			setTimeout( function () { $el.text( '' ).removeClass( 'is-success is-error is-loading' ); }, timeout );
		}
	}

	/**
	 * Post an AJAX request.
	 *
	 * @param {string}   action   wp_ajax action name.
	 * @param {Object}   data     Extra POST data.
	 * @param {Function} success  Called with response.data on 200.
	 * @param {Function} [fail]   Called with error message on failure.
	 */
	function ajaxPost( action, data, success, fail ) {
		$.post(
			ajaxUrl,
			Object.assign( { action: action, nonce: nonce }, data ),
			function ( response ) {
				if ( response.success ) {
					success( response.data );
				} else {
					const msg = ( response.data && response.data.message ) ? response.data.message : 'An error occurred.';
					if ( typeof fail === 'function' ) {
						fail( msg );
					}
				}
			}
		).fail( function ( xhr ) {
			const msg = 'HTTP ' + xhr.status + ': ' + xhr.statusText;
			if ( typeof fail === 'function' ) {
				fail( msg );
			}
		} );
	}

	// ------------------------------------------------------------------ //
	// Connection tab                                                       //
	// ------------------------------------------------------------------ //

	// Toggle secret key visibility.
	$( '#dualpress-toggle-key' ).on( 'click', function () {
		const $input = $( '#dualpress_secret_key' );
		const $btn   = $( this );
		if ( $input.attr( 'type' ) === 'password' ) {
			$input.attr( 'type', 'text' );
			$btn.text( $btn.data( 'hide' ) );
		} else {
			$input.attr( 'type', 'password' );
			$btn.text( $btn.data( 'show' ) );
		}
	} );

	// Generate a new secret key.
	$( '#dualpress-generate-key' ).on( 'click', function () {
		const $btn = $( this );
		$btn.prop( 'disabled', true );

		ajaxPost(
			'dualpress_generate_key',
			{},
			function ( data ) {
				$( '#dualpress_secret_key' )
					.attr( 'type', 'text' )
					.val( data.key );
				$btn.prop( 'disabled', false );
				$( '#dualpress-toggle-key' ).text( dualpressAjax.i18n.hide || 'Hide' );
			},
			function () {
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// Copy secret key to clipboard.
	$( '#dualpress-copy-key' ).on( 'click', function () {
		const key  = $( '#dualpress_secret_key' ).val();
		const $btn = $( this );

		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( key ).then( function () {
				$btn.text( i18n.copied );
				setTimeout( function () { $btn.text( 'Copy' ); }, 2000 );
			} );
		} else {
			// Fallback: select the input.
			const $input = $( '#dualpress_secret_key' );
			const prevType = $input.attr( 'type' );
			$input.attr( 'type', 'text' ).select();
			document.execCommand( 'copy' );
			$input.attr( 'type', prevType );
			$btn.text( i18n.copied );
			setTimeout( function () { $btn.text( 'Copy' ); }, 2000 );
		}
	} );

	// ------------------------------------------------------------------ //
	// Connection status helpers                                           //
	// ------------------------------------------------------------------ //

	/**
	 * Update the connection status badge with a colored dot icon.
	 *
	 * @param {'ok'|'error'|'unknown'} state
	 * @param {string}                 text   Label text to show.
	 */
	function setConnectionStatus( state, text ) {
		const $badge = $( '#dualpress-connection-status' );
		const dotClass = { ok: 'dualpress-dot-ok', error: 'dualpress-dot-error', unknown: 'dualpress-dot-unknown' }[ state ] || 'dualpress-dot-unknown';

		$badge
			.removeClass( 'dualpress-status-ok dualpress-status-error dualpress-status-unknown' )
			.addClass( 'dualpress-status-' + state );

		$badge.find( '.dualpress-status-dot' )
			.removeClass( 'dualpress-dot-ok dualpress-dot-error dualpress-dot-unknown' )
			.addClass( dotClass );

		$badge.find( '#dualpress-status-text' ).text( text );
	}

	/**
	 * Silently poll connection status (no button spinner).
	 */
	function autoCheckConnection() {
		if ( ! $( '#dualpress-test-connection' ).length || $( '#dualpress-test-connection' ).prop( 'disabled' ) ) {
			return;
		}
		ajaxPost(
			'dualpress_test_connection',
			{},
			function ( data ) {
				setConnectionStatus( 'ok', data.message );
			},
			function () {
				setConnectionStatus( 'error', 'Disconnected' );
			}
		);
	}

	// Test connection (manual button).
	$( '#dualpress-test-connection' ).on( 'click', function () {
		const $btn = $( this );
		const $msg = $( '#dualpress-connection-message' );

		$btn.prop( 'disabled', true ).addClass( 'dualpress-spinning' );
		setConnectionStatus( 'unknown', i18n.testing );
		$msg.text( '' );

		ajaxPost(
			'dualpress_test_connection',
			{},
			function ( data ) {
				setConnectionStatus( 'ok', data.message );
				$btn.prop( 'disabled', false ).removeClass( 'dualpress-spinning' );
			},
			function ( errMsg ) {
				setConnectionStatus( 'error', 'Disconnected' );
				showMsg( $msg, errMsg, 'error' );
				$btn.prop( 'disabled', false ).removeClass( 'dualpress-spinning' );
			}
		);
	} );

	// Auto-refresh connection status every 30 seconds.
	if ( $( '#dualpress-test-connection' ).length ) {
		// Run once on page load if configured (button is enabled).
		if ( ! $( '#dualpress-test-connection' ).prop( 'disabled' ) ) {
			setTimeout( autoCheckConnection, 800 );
			setInterval( autoCheckConnection, 30000 );
		}
	}

	// ------------------------------------------------------------------ //
	// Tools tab — Sync Now                                                 //
	// ------------------------------------------------------------------ //

	$( '#dualpress-now' ).on( 'click', function () {
		const $btn = $( this );
		const $msg = $( '#dualpress-msg' );

		$btn.prop( 'disabled', true ).addClass( 'dualpress-spinning' );
		showMsg( $msg, i18n.syncing, 'loading' );

		ajaxPost(
			'dualpress_sync_now',
			{},
			function ( data ) {
				$btn.prop( 'disabled', false ).removeClass( 'dualpress-spinning' );
				showMsg( $msg, data.message, 'success', 6000 );
			},
			function ( errMsg ) {
				$btn.prop( 'disabled', false ).removeClass( 'dualpress-spinning' );
				showMsg( $msg, errMsg, 'error', 8000 );
			}
		);
	} );

	// ------------------------------------------------------------------ //
	// Tools tab — Full Sync                                                //
	// ------------------------------------------------------------------ //

	$( '#dualpress-full-sync' ).on( 'click', function () {
		if ( ! window.confirm( i18n.confirm_full_sync ) ) {
			return;
		}

		const $btn = $( this );
		const $msg = $( '#dualpress-full-sync-msg' );

		$btn.prop( 'disabled', true ).addClass( 'dualpress-spinning' );
		showMsg( $msg, i18n.syncing, 'loading' );

		ajaxPost(
			'dualpress_full_sync',
			{},
			function ( data ) {
				$btn.prop( 'disabled', false ).removeClass( 'dualpress-spinning' );
				showMsg( $msg, data.message, 'success', 10000 );
			},
			function ( errMsg ) {
				$btn.prop( 'disabled', false ).removeClass( 'dualpress-spinning' );
				showMsg( $msg, errMsg, 'error', 10000 );
			}
		);
	} );

	// ------------------------------------------------------------------ //
	// Tools tab — Retry Failed                                             //
	// ------------------------------------------------------------------ //

	$( '#dualpress-retry-failed' ).on( 'click', function () {
		const $btn = $( this );
		const $msg = $( '#dualpress-queue-msg' );

		$btn.prop( 'disabled', true );

		ajaxPost(
			'dualpress_retry_failed',
			{},
			function ( data ) {
				showMsg( $msg, data.message, 'success', 5000 );
				$btn.prop( 'disabled', false );
				setTimeout( function () { window.location.reload(); }, 2000 );
			},
			function ( errMsg ) {
				showMsg( $msg, errMsg, 'error', 6000 );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// ------------------------------------------------------------------ //
	// Tools tab — Clear Queue                                              //
	// ------------------------------------------------------------------ //

	$( '#dualpress-clear-queue' ).on( 'click', function () {
		if ( ! window.confirm( i18n.confirm_clear_queue ) ) {
			return;
		}

		const $btn = $( this );
		const $msg = $( '#dualpress-queue-msg' );

		$btn.prop( 'disabled', true );

		// Immediately clear the table visually
		$( '#dualpress-queue-table tbody' ).empty();
		$( '#dualpress-queue-table' ).after( '<p><em>' + ( i18n.queue_cleared || 'Queue cleared.' ) + '</em></p>' );

		ajaxPost(
			'dualpress_clear_queue',
			{},
			function ( data ) {
				showMsg( $msg, data.message, 'success', 5000 );
				$btn.prop( 'disabled', false );
			},
			function ( errMsg ) {
				showMsg( $msg, errMsg, 'error', 6000 );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// ------------------------------------------------------------------ //
	// Logs tab — Filter                                                    //
	// ------------------------------------------------------------------ //

	var logsCurrentPage = 1;

	/**
	 * Compute "since" datetime string from the time-filter select value.
	 *
	 * @param {string} val '1h' | '24h' | '7d' | ''
	 * @returns {string} ISO-ish datetime or empty string.
	 */
	function sinceFromTimeFilter( val ) {
		if ( ! val ) {
			return '';
		}
		const now  = new Date();
		const mins = { '1h': 60, '24h': 1440, '7d': 10080 }[ val ] || 0;
		if ( ! mins ) {
			return '';
		}
		now.setMinutes( now.getMinutes() - mins );
		// Format as MySQL datetime (approximate — server will parse it).
		return now.toISOString().replace( 'T', ' ' ).slice( 0, 19 );
	}

	/**
	 * Fetch and render log rows via AJAX.
	 *
	 * @param {number} page  Page number (1-based).
	 */
	function loadLogs( page ) {
		logsCurrentPage = page;

		const level    = $( '#dualpress-log-level-filter' ).val();
		const timeVal  = $( '#dualpress-log-time-filter' ).val();
		const search   = $( '#dualpress-log-search' ).val();
		const since    = sinceFromTimeFilter( timeVal );

		ajaxPost(
			'dualpress_get_logs',
			{ level: level, search: search, since: since, per_page: 50, page: page },
			function ( data ) {
				renderLogs( data.rows, data.total, page );
			},
			function ( errMsg ) {
				$( '#dualpress-logs-tbody' ).html(
					'<tr><td colspan="4" style="color:#d63638">' + errMsg + '</td></tr>'
				);
			}
		);
	}

	/**
	 * Render log rows into the table.
	 *
	 * @param {Array}  rows  Array of log row objects.
	 * @param {number} total Total count.
	 * @param {number} page  Current page.
	 */
	function renderLogs( rows, total, page ) {
		const levelIcons = { debug: '&#9679;', info: '&#9989;', warning: '&#9888;', error: '&#10060;' };
		const $tbody = $( '#dualpress-logs-tbody' );

		if ( ! $tbody.length ) {
			return;
		}

		if ( ! rows || ! rows.length ) {
			$tbody.html( '<tr><td colspan="4" style="font-style:italic;color:#646970">No log entries found.</td></tr>' );
		} else {
			let html = '';
			$.each( rows, function ( _, row ) {
				const level   = row.log_level || 'info';
				const icon    = levelIcons[ level ] || '';
				const context = row.context
					? '<details class="dualpress-log-context"><summary>Context</summary><pre>' +
					  escHtml( JSON.stringify( JSON.parse( row.context ), null, 2 ) ) +
					  '</pre></details>'
					: '';

				html += '<tr class="dualpress-log-row dualpress-log-' + escAttr( level ) + '">' +
					'<td class="dualpress-col-time">' + escHtml( row.created_at ) + '</td>' +
					'<td class="dualpress-col-level"><span class="dualpress-level-badge dualpress-level-' + escAttr( level ) + '">' +
						icon + ' ' + escHtml( level.toUpperCase() ) + '</span></td>' +
					'<td class="dualpress-col-event"><code>' + escHtml( row.event_type ) + '</code></td>' +
					'<td class="dualpress-col-message">' + escHtml( row.message ) + context + '</td>' +
					'</tr>';
			} );
			$tbody.html( html );
		}

		// Update count + pagination.
		const shown = rows ? rows.length : 0;
		$( '#dualpress-logs-count' ).text( 'Showing ' + shown + ' of ' + total + ' entries' );
		$( '#dualpress-logs-page-indicator' ).text( page );
		$( '#dualpress-logs-prev' ).prop( 'disabled', page <= 1 );
		$( '#dualpress-logs-next' ).prop( 'disabled', ( page * 50 ) >= total );
	}

	$( '#dualpress-log-filter-btn' ).on( 'click', function () {
		loadLogs( 1 );
	} );

	$( '#dualpress-logs-prev' ).on( 'click', function () {
		if ( logsCurrentPage > 1 ) {
			loadLogs( logsCurrentPage - 1 );
		}
	} );

	$( '#dualpress-logs-next' ).on( 'click', function () {
		loadLogs( logsCurrentPage + 1 );
	} );

	// ------------------------------------------------------------------ //
	// Logs tab — Clear All                                                 //
	// ------------------------------------------------------------------ //

	$( '#dualpress-clear-logs' ).on( 'click', function () {
		if ( ! window.confirm( i18n.confirm_clear_logs ) ) {
			return;
		}

		const $btn = $( this );
		$btn.prop( 'disabled', true );

		ajaxPost(
			'dualpress_clear_logs',
			{},
			function () {
				$btn.prop( 'disabled', false );
				if ( $( '#dualpress-logs-tbody' ).length ) {
					$( '#dualpress-logs-tbody' ).html(
						'<tr><td colspan="4" style="font-style:italic;color:#646970">No log entries found.</td></tr>'
					);
					$( '#dualpress-logs-count' ).text( 'Showing 0 of 0 entries' );
				}
			},
			function () {
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// ------------------------------------------------------------------ //
	// HTML-escape helpers                                                  //
	// ------------------------------------------------------------------ //

	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;' )
			.replace( /</g,  '&lt;' )
			.replace( />/g,  '&gt;' )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	function escAttr( str ) {
		return escHtml( str );
	}

} )( jQuery );
