/* Perxel Image Optimizer — admin pages (Status + Settings).
 *
 * Both screens are server-rendered by PHP. This script only drives the
 * interactive parts: the conversion run loop, Recalculate, Retry failed, and
 * the Settings-page actions (serve toggle, self-test, estimate, cleanup).
 * All state rendering stays in PHP — most actions finish with location.reload().
 */
( function () {
	'use strict';

	var cfg = window.PerxelImageOptimizer || {};
	var running = false;
	var stopFlag = false;
	var lock = Math.random().toString( 36 ).slice( 2, 10 );

	/* ---- helpers ---------------------------------------------------- */

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					return { ok: r.ok, status: r.status, json: j };
				} );
			} );
	}

	function bytes( n ) {
		n = Number( n ) || 0;
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < u.length - 1 ) { n /= 1024; i++; }
		return ( i === 0 ? n : n.toFixed( 1 ) ) + ' ' + u[ i ];
	}

	function secs( s ) {
		s = Math.max( 0, Math.round( Number( s ) || 0 ) );
		if ( s < 60 ) { return s + 's'; }
		var m = Math.floor( s / 60 );
		return m < 60 ? m + 'm ' + ( s % 60 ) + 's' : Math.floor( m / 60 ) + 'h ' + ( m % 60 ) + 'm';
	}

	function byId( id ) { return document.getElementById( id ); }

	function on( id, ev, fn ) {
		var el = byId( id );
		if ( el ) { el.addEventListener( ev, fn ); }
	}

	function reload() { window.location.reload(); }

	/* ---- run loop -------------------------------------------------- */

	function showRunning() {
		var head = byId( 'pxio-headline' );
		if ( ! head ) { return; }
		head.innerHTML =
			'<div class="pxui-panel pxui-panel--info"><div class="pxui-panel__inner">' +
			'<span class="pxui-panel__icon dashicons dashicons-controls-play" aria-hidden="true"></span>' +
			'<div class="pxui-panel__content">' +
			'<p class="pxui-panel__title">Converting…</p>' +
			'<div class="pxui-progress"><span class="pxui-progress__fill" style="width:0%"></span></div>' +
			'<p class="pxui-progress__label" id="pxio-run-live">Starting…</p>' +
			'<div class="pxui-panel__actions"><button type="button" class="button" id="pxio-pause">Pause</button></div>' +
			'</div></div></div>';
		on( 'pxio-pause', 'click', function () { stopFlag = true; } );
	}

	function paint( d ) {
		var fill = document.querySelector( '#pxio-headline .pxui-progress__fill' );
		var live = byId( 'pxio-run-live' );
		var pct = d.total ? Math.round( d.processed / d.total * 100 ) : 0;
		if ( fill ) { fill.style.width = pct + '%'; }
		if ( live ) {
			live.textContent = d.processed + ' / ' + d.total +
				' · ' + ( d.rate || 0 ) + ' img/s' +
				' · ETA ' + secs( d.eta_seconds ) +
				' · saved ' + bytes( d.saved_bytes ) +
				( d.failed ? ' · ' + d.failed + ' failed' : '' );
		}
	}

	function loop() {
		var fails = 0;

		function next() {
			if ( stopFlag ) { running = false; post( 'perxel_image_optimizer_cancel', {} ).then( reload ); return; }

			post( 'perxel_image_optimizer_run_batch', { lock: lock } ).then( function ( res ) {
				if ( res.status === 409 ) {
					running = false;
					window.alert( 'Another tab is already running the conversion.' );
					reload();
					return;
				}
				if ( ! res.json || ! res.json.success ) {
					if ( ++fails >= 3 ) { running = false; reload(); return; }
					setTimeout( next, 2000 * fails );
					return;
				}
				fails = 0;
				var d = res.json.data;
				paint( d );
				if ( d.status === 'done' ) { running = false; post( 'perxel_image_optimizer_cancel', {} ).then( reload ); return; }
				next();
			} ).catch( function () {
				if ( ++fails >= 3 ) { running = false; reload(); return; }
				setTimeout( next, 2000 * fails );
			} );
		}

		next();
	}

	function startRun( action ) {
		lock = Math.random().toString( 36 ).slice( 2, 10 );
		stopFlag = false;
		running = true;
		showRunning();
		post( action, { lock: lock } ).then( loop );
	}

	/* ---- status page --------------------------------------------- */

	function bindStatus() {
		on( 'pxio-start', 'click', function () { startRun( 'perxel_image_optimizer_start' ); } );
		on( 'pxio-resume', 'click', function () { startRun( 'perxel_image_optimizer_resume' ); } );
		on( 'pxio-discard', 'click', function () { post( 'perxel_image_optimizer_cancel', {} ).then( reload ); } );

		on( 'pxio-recalc', 'click', function ( e ) {
			e.target.disabled = true;
			e.target.textContent = 'Recalculating…';
			post( 'perxel_image_optimizer_recalc', {} ).then( reload );
		} );

		on( 'pxio-retry-failed', 'click', function ( e ) {
			e.target.disabled = true;
			post( 'perxel_image_optimizer_status', {} ).then( function ( res ) {
				var list = ( res.json && res.json.data && res.json.data.failures ) || [];
				( function step( i ) {
					if ( i >= list.length ) { reload(); return; }
					post( 'perxel_image_optimizer_convert_one', { id: list[ i ].id, force: 1 } ).then( function () { step( i + 1 ); } );
				} )( 0 );
			} );
		} );
	}

	/* ---- settings page ------------------------------------------- */

	function bindSettings() {
		on( 'pxio-serve', 'change', function ( e ) {
			post( 'perxel_image_optimizer_serve', { on: e.target.checked ? 1 : 0 } ).then( reload );
		} );

		on( 'pxio-selftest', 'click', function ( e ) {
			var out = byId( 'pxio-selftest-out' );
			out.textContent = 'testing…';
			post( 'perxel_image_optimizer_status', {} ).then( function ( res ) {
				var sm = res.json && res.json.data && res.json.data.sample;
				if ( ! sm || ! sm.samples || ! sm.samples.length ) {
					out.textContent = 'Run an estimate first.';
					return;
				}
				fetch( sm.samples[ 0 ].url, { headers: { Accept: 'image/webp,*/*' }, cache: 'no-store' } )
					.then( function ( r ) {
						var ct = r.headers.get( 'content-type' ) || '';
						out.textContent = ct.indexOf( 'webp' ) > -1 ? 'got image/webp ✓' : 'got ' + ct + ' — serving not active for this request';
					} )
					.catch( function () { out.textContent = 'request failed'; } );
			} );
		} );

		on( 'pxio-sample', 'click', function ( e ) {
			e.target.disabled = true;
			e.target.textContent = 'Running…';
			post( 'perxel_image_optimizer_sample', {} ).then( reload );
		} );

		on( 'pxio-purge', 'click', function () {
			var out = byId( 'pxio-purge-out' );
			out.textContent = 'Building file list…';
			post( 'perxel_image_optimizer_purge_start', {} ).then( function ( r ) {
				var total = ( r.json && r.json.data && r.json.data.total ) || 0;
				( function step() {
					post( 'perxel_image_optimizer_purge_step', {} ).then( function ( res ) {
						var d = res.json.data;
						out.textContent = 'Deleted ' + d.deleted + ' / ' + ( d.total || total ) + ' (' + bytes( d.bytes ) + ')';
						if ( d.status === 'running' ) { step(); } else { reload(); }
					} );
				} )();
			} );
		} );

		on( 'pxio-htaccess-rm', 'click', function () {
			post( 'perxel_image_optimizer_htaccess_rm', {} ).then( reload );
		} );
	}

	/* ---- boot ---------------------------------------------------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( byId( 'pxio-headline' ) ) { bindStatus(); }
		if ( byId( 'pxio-serve' ) || byId( 'pxio-sample' ) ) { bindSettings(); }
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running ) { e.preventDefault(); e.returnValue = ''; }
	} );
}() );
