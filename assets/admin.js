/* global jQuery, UMP */
( function ( $ ) {
	'use strict';

	function post( action, step ) {
		return $.post( UMP.ajaxUrl, {
			action: action,
			nonce: UMP.nonce,
			step: step
		} );
	}

	function progressFor( $btn ) {
		var $p = $btn.closest( '.ump-index-banner' ).find( '.ump-progress' );
		if ( ! $p.length ) {
			$p = $( '.ump-progress' );
		}
		return {
			wrap: $p,
			bar: $p.find( '.ump-progress-bar' ),
			text: $p.find( '.ump-progress-text' )
		};
	}

	// Run one batched action to completion, calling whenDone() at the end.
	function runBatches( action, ui, label, whenDone ) {
		function loop( step ) {
			post( action, step )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						ui.text.text( 'Error: ' + label + '.' );
						return;
					}
					var d = res.data;
					var pct = d.total ? Math.min( 100, Math.round( ( d.processed / d.total ) * 100 ) ) : 100;
					ui.bar.css( 'width', pct + '%' );
					ui.text.text( label + ': ' + d.processed + ' / ' + d.total + ' (' + pct + '%)' );
					if ( d.done ) {
						whenDone();
						return;
					}
					loop( 'continue' );
				} )
				.fail( function () {
					ui.text.text( 'Request failed. Please try again.' );
				} );
		}
		loop( 'start' );
	}

	function reloadSoon( ui ) {
		ui.bar.css( 'width', '100%' );
		ui.text.text( 'Done. Reloading…' );
		setTimeout( function () {
			window.location.reload();
		}, 800 );
	}

	$( document ).on( 'click', '.ump-rebuild', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var ui = progressFor( $btn );
		ui.wrap.show();
		$btn.prop( 'disabled', true );
		runBatches( 'umedia_rebuild_index', ui, 'Scanning content', function () {
			reloadSoon( ui );
		} );
	} );

	// Scan for external images, then chain into the availability health check.
	$( document ).on( 'click', '.ump-extscan', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var ui = progressFor( $btn );
		ui.wrap.show();
		$btn.prop( 'disabled', true );
		runBatches( 'umedia_scan_external', ui, 'Scanning content', function () {
			ui.bar.css( 'width', '0%' );
			runBatches( 'umedia_check_external', ui, 'Checking availability', function () {
				reloadSoon( ui );
			} );
		} );
	} );

	// Import one external URL (download + re-attach references).
	$( document ).on( 'click', '.ump-import', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var hash = $btn.data( 'hash' );
		var $cell = $btn.closest( 'td' );
		$btn.prop( 'disabled', true ).text( 'Importing…' );
		$.post( UMP.ajaxUrl, {
			action: 'umedia_import_external',
			nonce: UMP.externalNonce,
			hash: hash
		} ).done( function ( res ) {
			if ( res && res.success ) {
				$cell.html(
					'<button type="button" class="button ump-undo" data-hash="' + hash + '">Undo</button>'
				);
			} else {
				var msg = res && res.data && res.data.message ? res.data.message : 'Import failed.';
				$btn.prop( 'disabled', false ).text( 'Import & re-attach' );
				$cell.append( ' <span style="color:#b32d2e;">' + msg + '</span>' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Import & re-attach' );
		} );
	} );

	// Undo one import (delete downloaded file, restore external references).
	$( document ).on( 'click', '.ump-undo', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( 'Undo this import? The downloaded file will be deleted and references restored to the external URL.' ) ) {
			return;
		}
		var $btn = $( this );
		var hash = $btn.data( 'hash' );
		var $cell = $btn.closest( 'td' );
		$btn.prop( 'disabled', true ).text( 'Undoing…' );
		$.post( UMP.ajaxUrl, {
			action: 'umedia_undo_external',
			nonce: UMP.externalNonce,
			hash: hash
		} ).done( function ( res ) {
			if ( res && res.success ) {
				$cell.html(
					'<button type="button" class="button button-primary ump-import" data-hash="' + hash + '">Import &amp; re-attach</button>'
				);
			} else {
				$btn.prop( 'disabled', false ).text( 'Undo' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Undo' );
		} );
	} );

	// Bulk import: import every checked working URL sequentially.
	$( document ).on( 'click', '.ump-import-selected', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $status = $( '.ump-bulk-status' );
		var hashes = $( 'input[name="ehash[]"]:checked' ).map( function () {
			return this.value;
		} ).get();
		if ( ! hashes.length ) {
			window.alert( 'Select one or more working images first (use the checkboxes).' );
			return;
		}
		$btn.prop( 'disabled', true );
		var total = hashes.length;
		var done = 0;
		function next() {
			if ( ! hashes.length ) {
				$status.text( 'Imported ' + done + ' / ' + total + '. Reloading…' );
				setTimeout( function () {
					window.location.reload();
				}, 900 );
				return;
			}
			var hash = hashes.shift();
			done++;
			$status.text( 'Importing ' + done + ' / ' + total + '…' );
			$.post( UMP.ajaxUrl, {
				action: 'umedia_import_external',
				nonce: UMP.externalNonce,
				hash: hash
			} ).always( function () {
				next();
			} );
		}
		next();
	} );

	var PURGE_MSG =
		'Permanently delete the selected file(s)? This removes them from disk and cannot be undone.';

	// Confirm a per-row permanent delete.
	$( document ).on( 'click', '.ump-confirm-purge', function ( e ) {
		if ( ! window.confirm( PURGE_MSG ) ) {
			e.preventDefault();
		}
	} );

	// Confirm a bulk permanent delete on the trash form.
	$( document ).on( 'submit', 'form', function ( e ) {
		var $form = $( this );
		if ( ! $form.find( 'input[name="media[]"]' ).length ) {
			return;
		}
		var action = $form.find( 'select[name="action"]' ).val();
		var action2 = $form.find( 'select[name="action2"]' ).val();
		if ( ( 'purge' === action || 'purge' === action2 ) && ! window.confirm( PURGE_MSG ) ) {
			e.preventDefault();
		}
	} );
} )( jQuery );
