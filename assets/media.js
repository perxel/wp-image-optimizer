/* Perxel Image Optimizer — Media library row / modal buttons.
 *
 * The per-attachment "Convert" / "Remove" buttons in the Media list table and
 * the attachment detail panel. Loaded on upload.php and post.php only.
 */
( function () {
	'use strict';

	var cfg = window.PerxelImageOptimizer || {};

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.perxel-image-optimizer-row-action' ) : null;
		if ( ! btn ) { return; }
		ev.preventDefault();

		var id = btn.getAttribute( 'data-id' );
		var action = btn.getAttribute( 'data-action' );
		var cell = document.querySelector( '.perxel-image-optimizer-cell[data-id="' + id + '"]' ) ||
			( btn.closest( '.perxel-image-optimizer-attachment' ) &&
				btn.closest( '.perxel-image-optimizer-attachment' ).querySelector( '.perxel-image-optimizer-cell' ) );

		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = action === 'remove' ? 'Removing…' : 'Converting…';

		post( action === 'remove' ? 'perxel_image_optimizer_remove_one' : 'perxel_image_optimizer_convert_one', { id: id, force: 1 } )
			.then( function ( json ) {
				btn.disabled = false;
				btn.textContent = original;
				if ( json && json.success && cell ) {
					cell.textContent = json.data.label;
				}
			} );
	} );
}() );
