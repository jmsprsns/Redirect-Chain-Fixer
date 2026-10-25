/**
 * Content Powered > Redirect Chain Fixer page behaviour. Only loaded on that page.
 *
 * "Scan now" runs the scan in short AJAX batches with a progress bar, then
 * reloads the page to show what it found. A scan that's already running when
 * the page opens is picked up the same way. Without JavaScript the button
 * still works: the form starts the scan and WP-Cron runs it.
 *
 * redirect_chain_fixer_admin.ajaxUrl, .nonce and .error are passed in from
 * PHP (see Redirect_Chain_Fixer_Admin::enqueue_assets).
 */

/* global redirect_chain_fixer_admin */
( function () {
	'use strict';

	var data = window.redirect_chain_fixer_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		var box = document.querySelector( '[data-rcf-scan]' );

		if ( typeof data === 'undefined' || ! box || ! window.fetch ) {
			return;
		}

		var button = box.querySelector( '[data-rcf-scan-button]' );
		var status = box.querySelector( '[data-rcf-scan-status]' );
		var progress = box.querySelector( '[data-rcf-progress]' );
		var bar = progress ? progress.querySelector( 'span' ) : null;
		var failures = 0;

		function setRunning( running ) {
			box.classList.toggle( 'is-running', running );
			button.disabled = running;
			if ( progress ) {
				progress.hidden = ! running;
			}
		}

		function step( start ) {
			var body = new window.FormData();

			body.append( 'action', 'redirect_chain_fixer_scan' );
			body.append( 'nonce', data.nonce );
			body.append( 'step', start ? 'start' : 'run' );

			window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( 'scan' );
					}

					var moved = status.textContent !== json.data.status;

					failures = 0;
					status.textContent = json.data.status;
					if ( bar ) {
						bar.style.width = json.data.percent + '%';
					}

					if ( json.data.running ) {
						// No progress usually means a background batch has the
						// lock; give it a moment instead of asking again at once.
						window.setTimeout( function () {
							step( false );
						}, moved ? 0 : 1500 );
					} else {
						window.location.reload();
					}
				} )
				.catch( function () {
					// A slow batch or a blip: try a few more times before giving up.
					failures++;
					if ( failures < 4 ) {
						window.setTimeout( function () {
							step( false );
						}, 3000 );
						return;
					}

					status.textContent = data.error;
					setRunning( false );
				} );
		}

		button.closest( 'form' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( bar ) {
				bar.style.width = '0%';
			}
			setRunning( true );
			step( true );
		} );

		if ( '1' === box.getAttribute( 'data-running' ) ) {
			setRunning( true );
			step( false );
		}
	} );
} )();
