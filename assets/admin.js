/* global jQuery, UMP */
( function ( $ ) {
	'use strict';

	function post( step ) {
		return $.post( UMP.ajaxUrl, {
			action: 'ump_rebuild_index',
			nonce: UMP.nonce,
			step: step
		} );
	}

	function run( $btn ) {
		var $progress = $( '.ump-progress' );
		var $bar = $progress.find( '.ump-progress-bar' );
		var $text = $progress.find( '.ump-progress-text' );

		$progress.show();
		$btn.prop( 'disabled', true );
		$text.text( 'Starting…' );

		function loop( step ) {
			post( step )
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
		run( $( this ) );
	} );
} )( jQuery );
