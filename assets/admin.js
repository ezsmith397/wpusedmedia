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

	function run( $btn, action ) {
		var $progress = $btn.closest( '.ump-index-banner' ).find( '.ump-progress' );
		if ( ! $progress.length ) {
			$progress = $( '.ump-progress' );
		}
		var $bar = $progress.find( '.ump-progress-bar' );
		var $text = $progress.find( '.ump-progress-text' );

		$progress.show();
		$btn.prop( 'disabled', true );
		$text.text( 'Starting…' );

		function loop( step ) {
			post( action, step )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						$text.text( 'Error while building the index.' );
						$btn.prop( 'disabled', false );
						return;
					}

					var d = res.data;
					var pct = d.total ? Math.round( ( d.processed / d.total ) * 100 ) : 100;
					pct = Math.min( 100, pct );

					$bar.css( 'width', pct + '%' );
					$text.text( d.processed + ' / ' + d.total + ' objects scanned (' + pct + '%)' );

					if ( d.done ) {
						$bar.css( 'width', '100%' );
						$text.text( 'Done — ' + d.processed + ' objects scanned. Reloading…' );
						setTimeout( function () {
							window.location.reload();
						}, 800 );
						return;
					}

					loop( 'continue' );
				} )
				.fail( function () {
					$text.text( 'Request failed. Please try again.' );
					$btn.prop( 'disabled', false );
				} );
		}

		loop( 'start' );
	}

	$( document ).on( 'click', '.ump-rebuild', function ( e ) {
		e.preventDefault();
		run( $( this ), 'umedia_rebuild_index' );
	} );

	$( document ).on( 'click', '.ump-extscan', function ( e ) {
		e.preventDefault();
		run( $( this ), 'umedia_scan_external' );
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
