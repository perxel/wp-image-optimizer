/**
 * Perxel shared admin UI — minimal behaviour.
 *
 * WordPress core already wires dismiss buttons onto `.notice.is-dismissible`,
 * so the kit only adds a confirm guard for destructive actions:
 *
 *     <button class="button" data-pxui-confirm="Delete everything?">Delete</button>
 *
 * The click is blocked unless the user accepts the native confirm.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		if ( ! t || ! t.closest ) {
			return;
		}

		var el = t.closest( '[data-pxui-confirm]' );
		if ( ! el ) {
			return;
		}

		var message = el.getAttribute( 'data-pxui-confirm' ) || 'Are you sure?';
		if ( ! window.confirm( message ) ) {
			ev.preventDefault();
			ev.stopImmediatePropagation();
		}
	}, true );

	window.pxui = window.pxui || {};
}() );
