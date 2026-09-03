/* Perxel Image Optimizer - admin pages (Status + Settings).
 *
 * Both screens are server-rendered by PHP. This script only drives:
 *   - the prepare form: month picker + "This run" image-count / ETA (no round-trip),
 *   - the live monitor poll while a run is active,
 *   - the Settings dirty-state guard and the "Remove all WebP" purge loop.
 * Start / Pause / Cancel / Resume / Retry are plain form submits.
 */
( function () {
	'use strict';

	var cfg = window.PerxelImageOptimizer || {};

	/* ---- helpers ---------------------------------------------------- */

	function byId( id ) { return document.getElementById( id ); }

	function on( id, ev, fn ) {
		var el = byId( id );
		if ( el ) { el.addEventListener( ev, fn ); }
	}

	function reload() { window.location.reload(); }

	function bytes( n ) {
		n = Number( n ) || 0;
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < u.length - 1 ) { n /= 1024; i++; }
		return ( i === 0 ? Math.round( n ) : n.toFixed( 1 ) ) + ' ' + u[ i ];
	}

	function secs( s ) {
		s = Math.max( 0, Math.round( Number( s ) || 0 ) );
		if ( s < 60 ) { return s + 's'; }
		var m = Math.floor( s / 60 );
		return m < 60 ? m + 'm' : Math.floor( m / 60 ) + 'h ' + ( m % 60 ) + 'm';
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { body.append( k, data[ k ] ); } );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, status: r.status, json: j }; } ); } );
	}

	/* ---- prepare form -------------------------------------------- */

	function bindPrepare() {
		var form = byId( 'pxio-prepare' );
		if ( ! form ) { return; }

		var scope    = byId( 'pxio-scope' );
		var months   = byId( 'pxio-months' );
		var perImage = parseFloat( form.dataset.perImage ) || 1;
		var perImageFast = parseFloat( form.dataset.perImageFast ) || perImage;
		var scopeAll = parseInt( form.dataset.scopeAll, 10 ) || 0;
		var STORE = 'pxioPrepare';

		function monthBoxes() { return form.querySelectorAll( '.pxio-month' ); }
		function checkedMonths() { return form.querySelectorAll( '.pxio-month:checked' ); }

		function selectedDriver() {
			var d = form.querySelector( '.pxio-driver:checked' );
			return d ? d.value : 'background';
		}

		function duration( s ) {
			s = Math.max( 0, Math.round( s ) );
			if ( s < 90 ) { return 'a few seconds'; }
			var m = Math.round( s / 60 );
			if ( m < 60 ) { return 'up to ≈ ' + m + ' min'; }
			var h = m / 60;
			return 'up to ≈ ' + ( h < 10 ? h.toFixed( 1 ).replace( /\.0$/, '' ) : Math.round( h ) ) + ' hr';
		}

		// Images in scope for the current selection.
		function selectedImages() {
			if ( ! scope || scope.value !== 'months' ) { return scopeAll; }
			var img = 0;
			checkedMonths().forEach( function ( cb ) {
				img += parseInt( cb.dataset.scope, 10 ) || 0;
			} );
			return img;
		}

		function setText( id, txt ) {
			var el = byId( id );
			if ( el ) { el.textContent = txt; }
		}

		function persist() {
			try {
				var picked = [];
				checkedMonths().forEach( function ( cb ) { picked.push( cb.value ); } );
				window.sessionStorage.setItem( STORE, JSON.stringify( {
					scope: scope ? scope.value : 'all',
					driver: selectedDriver(),
					months: picked
				} ) );
			} catch ( e ) {}
		}

		function restore() {
			var saved;
			try { saved = JSON.parse( window.sessionStorage.getItem( STORE ) || 'null' ); } catch ( e ) {}
			if ( ! saved ) { return; }
			if ( scope && saved.scope ) { scope.value = saved.scope; }
			if ( saved.driver ) {
				var d = form.querySelector( '.pxio-driver[value="' + saved.driver + '"]' );
				if ( d ) { d.checked = true; }
			}
			if ( saved.months && saved.months.length ) {
				var want = {};
				saved.months.forEach( function ( m ) { want[ m ] = 1; } );
				monthBoxes().forEach( function ( cb ) {
					cb.checked = !! want[ cb.value ];
					// Open the year so a restored pick is visible, not buried.
					var d = cb.checked && cb.closest( 'details' );
					if ( d ) { d.open = true; }
				} );
				syncYearAll();
			}
		}

		function syncYearAll() {
			form.querySelectorAll( '.pxio-year-all' ).forEach( function ( ya ) {
				var group = form.querySelectorAll( '.pxio-month[data-year="' + ya.dataset.year + '"]' );
				var on = group.length > 0;
				group.forEach( function ( cb ) { if ( ! cb.checked ) { on = false; } } );
				ya.checked = on;
			} );
		}

		// Right of the chevron: each year's total, or "<n> of <total>" while it
		// has selected months — so picks show without expanding the year.
		function syncYearInfo() {
			form.querySelectorAll( '.pxio-year-count' ).forEach( function ( el ) {
				var sel = 0;
				form.querySelectorAll( '.pxio-month[data-year="' + el.dataset.year + '"]:checked' ).forEach( function ( cb ) {
					sel += parseInt( cb.dataset.scope, 10 ) || 0;
				} );
				el.textContent = sel > 0
					? sel.toLocaleString() + ' of ' + el.dataset.totalText
					: el.dataset.totalText;
				el.classList.toggle( 'is-selected', sel > 0 );
			} );
		}

		function recompute() {
			var isMonths = scope && scope.value === 'months';
			if ( months ) { months.hidden = ! isMonths; }

			syncYearInfo();

			var images = selectedImages();
			var fast   = selectedDriver() === 'fast';

			setText( 'pxio-fig-images', images.toLocaleString() );
			setText( 'pxio-fig-time', duration( images * ( fast ? perImageFast : perImage ) ) );
			setText( 'pxio-fig-scope', isMonths ? 'across selected months' : 'whole library' );
			setText( 'pxio-run-note', fast
				? 'Fast mode keeps this tab open and works your server continuously until it finishes.'
				: 'Background mode runs on a schedule — close the tab anytime.' );

			var start = document.querySelector( 'button[form="pxio-prepare"]' );
			if ( start ) { start.disabled = images < 1; }

			persist();
		}

		// A change anywhere in the form (scope select, a month box, a year box)
		// re-runs the estimate; a year box also toggles its months first.
		form.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'pxio-year-all' ) ) {
				form.querySelectorAll( '.pxio-month[data-year="' + e.target.dataset.year + '"]' ).forEach( function ( cb ) {
					cb.checked = e.target.checked;
				} );
				var d = e.target.checked && e.target.closest( 'details' );
				if ( d ) { d.open = true; }
			} else if ( e.target && e.target.classList.contains( 'pxio-month' ) ) {
				syncYearAll();
			}
			recompute();
		} );

		// The whole row is a click target, not just the 18px box — but not the
		// year summary line, where a click drives the native <details> toggle.
		form.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( 'input, a, button, select, label, summary' ) ) { return; }
			var row = e.target.closest( '.pxui-row:not(.pxui-row--disclosure)' );
			var cb  = row && row.querySelector( '.pxio-month, .pxio-year-all, .pxio-driver' );
			if ( ! cb ) { return; }
			cb.checked = ( cb.type === 'radio' ) ? true : ! cb.checked;
			cb.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		form.addEventListener( 'submit', function () {
			try { window.sessionStorage.removeItem( STORE ); } catch ( e ) {}
		} );

		restore();
		recompute();
	}

	/* ---- monitor poll ------------------------------------------- */

	function bindMonitor() {
		var mon = byId( 'pxio-monitor' );
		if ( ! mon || mon.dataset.poll !== '1' ) { return; }

		var wasStalled = mon.dataset.state === 'stalled';
		var wasQueued = mon.dataset.state === 'queued';
		var tries = 0;

		function setText( id, txt ) {
			var el = byId( id );
			if ( el ) { el.textContent = txt; }
		}

		function tick() {
			post( 'perxel_image_optimizer_progress', {} ).then( function ( res ) {
				if ( ! res.json || ! res.json.success ) {
					if ( ++tries >= 4 ) { reload(); return; }
					return;
				}
				tries = 0;
				var d = res.json.data;

				// Re-render server-side only when the situation actually changes:
				// the run ended/paused, it crossed the stalled threshold either
				// way, or the first image landed (queued → running).
				if ( d.phase !== 'running' || !! d.stalled !== wasStalled ||
					( wasQueued && d.processed > 0 ) ) { reload(); return; }

				// Only the live-run rows exist mid-run: the progress count, the
				// failure tallies, and the rate line. The saved / disk / converted
				// rows render server-side once the run is complete (a phase change
				// forces a full reload above), so there is nothing to update here.
				setText( 'pxio-count', d.processed.toLocaleString() + ' / ' + d.total.toLocaleString() );
				setText( 'pxio-failed', d.failed.toLocaleString() );
				setText( 'pxio-large', d.too_large.toLocaleString() );
				setText( 'pxio-rate-line', 'about ' + ( d.eta_seconds > 0 ? secs( d.eta_seconds ) : 'calculating' ) + ' left' );
			} ).catch( function () {
				if ( ++tries >= 4 ) { reload(); }
			} );
		}

		tick();
		setInterval( tick, 3000 );
	}

	/* ---- fast mode: tab-driven conversion loop ------------------- */

	function bindFastRunner() {
		var mon = byId( 'pxio-monitor' );
		if ( ! mon || mon.dataset.driver !== 'fast' ) { return; }

		var state = mon.dataset.state;
		// 'queued' is just 'running' before the first image lands.
		var pumping = ( state === 'running' || state === 'queued' );
		if ( ! pumping && state !== 'paused' ) { return; }

		var token    = 'fx-' + Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );
		var banner   = byId( 'pxio-throttle-banner' );
		var stopped  = false;
		var fails    = 0;
		var polling  = false;
		var wasQueued = ( state === 'queued' );
		// True once we navigate on purpose (our own reload, or a Pause/Resume/
		// Cancel form submit) - so the unload beacon doesn't pause the run.
		var intentionalNav = false;

		function goReload() { intentionalNav = true; stopped = true; reload(); }

		function setText( id, txt ) {
			var el = byId( id );
			if ( el ) { el.textContent = txt; }
		}

		function intensity() {
			var el = byId( 'pxio-intensity' );
			return el ? el.value : 'balanced';
		}

		function showBanner( html ) {
			if ( ! banner ) { return; }
			banner.innerHTML = html;
			banner.hidden = false;
		}

		function hideBanner() {
			if ( banner ) { banner.hidden = true; }
		}

		function paint( d ) {
			if ( ! d ) { return; }
			setText( 'pxio-count', ( d.processed || 0 ).toLocaleString() + ' / ' + ( d.total || 0 ).toLocaleString() );
			setText( 'pxio-failed', ( d.failed || 0 ).toLocaleString() );
			setText( 'pxio-large', ( d.too_large || 0 ).toLocaleString() );
			if ( d.eta_seconds != null ) {
				setText( 'pxio-rate-line', 'about ' + ( d.eta_seconds > 0 ? secs( d.eta_seconds ) : 'calculating' ) + ' left' );
			}
		}

		function later( fn, ms ) {
			if ( ! stopped ) { window.setTimeout( fn, Math.max( 0, ms ) ); }
		}

		function countdown( ts ) {
			function tickB() {
				if ( stopped ) { return; }
				var left = Math.max( 0, Math.round( ts - Date.now() / 1000 ) );
				showBanner( 'Paused to stay within your host’s limits. Resuming in ' + left +
					's… <button type="button" class="button button-small" id="pxio-resume-now">Resume now</button>' );
				var b = byId( 'pxio-resume-now' );
				if ( b ) { b.onclick = function () { hideBanner(); step( true ); }; }
				if ( left > 0 ) { window.setTimeout( tickB, 1000 ); }
			}
			tickB();
		}

		function passivePoll() {
			if ( polling ) { return; }
			polling = true;
			( function p() {
				if ( stopped ) { return; }
				post( 'perxel_image_optimizer_progress', {} ).then( function ( res ) {
					if ( res.json && res.json.success ) {
						var d = res.json.data;
						if ( d.phase !== 'running' || d.driver !== 'fast' ) { goReload(); return; }
						paint( d );
					}
					window.setTimeout( p, 3000 );
				} ).catch( function () { window.setTimeout( p, 5000 ); } );
			}() );
		}

		function step( force ) {
			if ( stopped ) { return; }

			var ctrl = ( 'AbortController' in window ) ? new AbortController() : null;
			var dog  = ctrl ? window.setTimeout( function () { ctrl.abort(); }, 60000 ) : null;

			var body = new FormData();
			body.append( 'action', 'perxel_image_optimizer_fast_step' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'token', token );
			body.append( 'intensity', intensity() );
			if ( force ) { body.append( 'force', '1' ); }

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
				signal: ctrl ? ctrl.signal : undefined
			} ).then( function ( r ) {
				if ( dog ) { window.clearTimeout( dog ); }
				if ( r.status >= 500 ) { throw new Error( 'server ' + r.status ); }
				return r.json();
			} ).then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( 'bad response' ); }
				fails = 0;
				var d = j.data;
				if ( d.nonce ) { cfg.nonce = d.nonce; }

				if ( d.status === 'running' ) {
					// Queued → running: reload once so the view swaps to the
					// live-run layout (matches the background monitor).
					if ( wasQueued && d.processed > 0 ) { goReload(); return; }
					paint( d );
					if ( d.hits > 0 ) {
						showBanner( 'Easing off — your host is under load. Still going.' );
					} else {
						hideBanner();
					}
					later( step, ( d.gap || 3 ) * 1000 );
					return;
				}

				if ( d.status === 'done' ) { goReload(); return; }

				if ( d.status === 'locked' ) {
					showBanner( 'This run is being driven by another open tab.' );
					passivePoll();
					return;
				}

				if ( d.status === 'paused' ) {
					if ( d.auto ) {
						paint( d );
						if ( d.give_up ) {
							showBanner( 'Your host keeps throttling this. Switch to Background mode, or ' +
								'<button type="button" class="button button-small" id="pxio-resume-now">try again now</button>.' );
							var rb = byId( 'pxio-resume-now' );
							if ( rb ) { rb.onclick = function () { hideBanner(); step( true ); }; }
							return;
						}
						var ra = ( d.resume_after || 0 ) * 1000;
						countdown( d.resume_after );
						later( step, Math.max( 0, ra - Date.now() ) + 500 );
						return;
					}
					// A manual / tab-closed pause happened elsewhere.
					goReload();
					return;
				}

				goReload(); // idle / unknown
			} ).catch( function () {
				if ( dog ) { window.clearTimeout( dog ); }
				fails++;
				if ( fails >= 5 ) {
					showBanner( 'The server stopped responding. Still retrying — leave this tab open, or reload to check.' );
				}
				later( step, Math.min( 60000, 2000 * Math.pow( 2, fails ) ) );
			} );
		}

		// A Pause / Resume / Cancel / Retry button is a normal form POST - that
		// navigation is intentional, so suppress the unload beacon for it.
		document.addEventListener( 'submit', function () {
			intentionalNav = true;
			stopped = true;
		}, true );

		function pauseBeacon() {
			stopped = true;
			if ( intentionalNav ) { return; }
			try {
				var fd = new FormData();
				fd.append( 'action', 'perxel_image_optimizer_fast_pause' );
				fd.append( 'nonce', cfg.nonce );
				fd.append( 'token', token );
				navigator.sendBeacon( cfg.ajaxUrl, fd );
			} catch ( e ) {}
		}

		window.addEventListener( 'beforeunload', pauseBeacon );
		window.addEventListener( 'pagehide', pauseBeacon );

		if ( pumping ) {
			step();
		} else if ( state === 'paused' && mon.dataset.pauseReason === 'auto_throttle' ) {
			var ts = parseInt( mon.dataset.resumeAfter || '0', 10 );
			countdown( ts );
			later( step, Math.max( 0, ts * 1000 - Date.now() ) + 500 );
		} else if ( state === 'paused' && mon.dataset.pauseReason === 'auto_giveup' ) {
			showBanner( 'Your host is throttling this heavily. Switch to Background mode, or use Resume to try again. ' +
				'<button type="button" class="button button-small" id="pxio-resume-now">Resume now</button>' );
			var b = byId( 'pxio-resume-now' );
			if ( b ) { b.onclick = function () { stopped = false; hideBanner(); step( true ); }; }
		}
	}

	/* ---- settings page ------------------------------------------- */

	var settingsDirty = false;

	function bindSettings() {
		var form = byId( 'pxio-settings-form' );
		var save = byId( 'pxio-save' );

		if ( form ) {
			if ( save ) { save.disabled = true; }

			var markDirty = function ( e ) {
				if ( settingsDirty || ! e.target || e.target.form !== form ) { return; }
				settingsDirty = true;
				if ( save ) { save.disabled = false; }
			};
			document.addEventListener( 'input', markDirty, true );
			document.addEventListener( 'change', markDirty, true );
			document.addEventListener( 'submit', function () { settingsDirty = false; }, true );
		}

		// Test-email form carries whatever is currently typed in the address field.
		var testForm = byId( 'pxio-test-email' );
		var emailTo  = byId( 'pxio-email-to' );
		if ( testForm && emailTo ) {
			testForm.addEventListener( 'submit', function () {
				var hidden = testForm.querySelector( 'input[name="email_report_to"]' );
				if ( hidden ) { hidden.value = emailTo.value; }
			} );
		}

		on( 'pxio-purge', 'click', function () {
			var out = byId( 'pxio-purge-out' );
			out.textContent = 'Building file list…';
			post( 'perxel_image_optimizer_purge_start', {} ).then( function ( r ) {
				var total = ( r.json && r.json.data && r.json.data.total ) || 0;
				( function step() {
					post( 'perxel_image_optimizer_purge_step', {} ).then( function ( res ) {
						var d = res.json.data;
						out.textContent = 'Deleted ' + d.deleted + ' / ' + ( d.total || total ) + ' (' + bytes( d.bytes ) + ')';
						if ( d.status === 'running' ) { step(); } else { settingsDirty = false; reload(); }
					} );
				} )();
			} );
		} );
	}

	/* ---- boot ---------------------------------------------------- */

	function boot() {
		bindPrepare();
		bindMonitor();
		bindFastRunner();
		if ( byId( 'pxio-settings-form' ) || byId( 'pxio-purge' ) ) { bindSettings(); }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( settingsDirty ) { e.preventDefault(); e.returnValue = ''; }
	} );
}() );
