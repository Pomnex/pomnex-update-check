/**
 * Pomnex Update Check admin script. Vanilla JS, no dependencies.
 *
 * - Makes each update log row open its details when clicked.
 * - Shows a busy label on buttons whose action runs a check.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( 'tr[data-pomnex-uc-href]' );

		// Let real links, buttons and form fields do their own thing.
		if ( ! row || event.target.closest( 'a, button, input, select, textarea, label' ) ) {
			return;
		}

		window.location.href = row.getAttribute( 'data-pomnex-uc-href' );
	} );

	Array.prototype.forEach.call( document.querySelectorAll( 'form.pomnex-uc-busy-form' ), function ( form ) {
		form.addEventListener( 'submit', function () {
			var button = form.querySelector( '[data-busy-label]' );

			if ( ! button ) {
				return;
			}

			button.textContent = button.getAttribute( 'data-busy-label' );
			button.setAttribute( 'aria-disabled', 'true' );

			// Disable after the submit has started so the form still sends.
			window.setTimeout( function () {
				button.disabled = true;
			}, 0 );
		} );
	} );
}() );
