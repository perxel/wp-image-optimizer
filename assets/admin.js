/* Perxel Image Optimizer - admin pages (Status + Settings).
 *
 * Both screens are server-rendered by PHP. This script only drives:
 *   - the prepare form: month picker + "This run" arithmetic (no round-trip),
 *   - the live monitor poll while a run is active,
 *   - the Settings dirty-state guard and the "Remove all WebP" purge loop.
 * Scan / Start / Pause / Cancel / Resume / Retry are plain form submits.
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
		var avgSrc   = parseFloat( form.dataset.avgSrc ) || 0;
		var avgFrac  = parseFloat( form.dataset.avgFrac ) || 0.7;
		var perImage = parseFloat( form.dataset.perImage ) || 1;
		var freeDisk = parseFloat( form.dataset.freeDisk ) || 0;
		var scopeAll   = parseInt( form.dataset.scopeAll, 10 ) || 0;
		var pendingAll = parseInt( form.dataset.pendingAll, 10 ) || 0;
		var scopeWord  = scopeAll === pendingAll ? 'everything pending' : 'every image';
		var STORE = 'pxioPrepare';

		function monthBoxes() { return form.querySelectorAll( '.pxio-month' ); }
		function checkedMonths() { return form.querySelectorAll( '.pxio-month:checked' ); }

		function duration( s ) {
			s = Math.max( 0, Math.round( s ) );
			if ( s < 90 ) { return 'a few seconds'; }
			var m = Math.round( s / 60 );
			if ( m < 60 ) { return '≈ ' + m + ' min'; }
			var h = m / 60;
			return '≈ ' + ( h < 10 ? h.toFixed( 1 ).replace( /\.0$/, '' ) : Math.round( h ) ) + ' hr';
		}

		// [ images in scope, pending in scope ] for the current selection.
		function selected() {
			if ( ! scope || scope.value !== 'months' ) { return [ scopeAll, pendingAll ]; }
			var img = 0;
			var pend = 0;
			checkedMonths().forEach( function ( cb ) {
				img  += parseInt( cb.dataset.scope, 10 ) || 0;
				pend += parseInt( cb.dataset.pending, 10 ) || 0;
			} );
			return [ img, pend ];
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
					months: picked
				} ) );
			} catch ( e ) {}
		}

		function restore() {
			var saved;
			try { saved = JSON.parse( window.sessionStorage.getItem( STORE ) || 'null' ); } catch ( e ) {}
			if ( ! saved ) { return; }
			if ( scope && saved.scope ) { scope.value = saved.scope; }
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
		var metricKey = months && months.dataset.metric === 'pending' ? 'pending' : 'scope';

		function syncYearInfo() {
			form.querySelectorAll( '.pxio-year-count' ).forEach( function ( el ) {
				var sel = 0;
				form.querySelectorAll( '.pxio-month[data-year="' + el.dataset.year + '"]:checked' ).forEach( function ( cb ) {
					sel += parseInt( cb.dataset[ metricKey ], 10 ) || 0;
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

			var sel     = selected();
			var images  = sel[ 0 ];
			var pending = sel[ 1 ];
			// New savings come only from images that aren't WebP yet.
			var src   = pending * avgSrc;
			var webp  = Math.round( src * avgFrac );
			var saved = Math.max( 0, src - webp );
			var pct   = src > 0 ? Math.round( saved / src * 100 ) : 0;

			setText( 'pxio-fig-images', images.toLocaleString() );
			setText( 'pxio-fig-time', duration( images * perImage ) );
			setText( 'pxio-fig-saved', '−' + pct + '%  ·  ≈ ' + bytes( saved ) );
			setText( 'pxio-fig-disk', '≈ +' + bytes( webp ) );
			setText( 'pxio-fig-scope', isMonths ? 'across selected months' : scopeWord );

			// Plain-language lead line for the note - the four figures as one
			// sentence a non-technical reader can act on.
			if ( images < 1 ) {
				setText( 'pxio-run-summary', 'Pick one or more months to see the estimate.' );
			} else {
				var dur = duration( images * perImage );
				var timePhrase = dur === 'a few seconds'
					? 'takes only a few seconds'
					: 'takes about ' + dur.replace( '≈ ', '' );
				setText( 'pxio-run-summary',
					'Converting ' + images.toLocaleString() + ' image' + ( images === 1 ? '' : 's' ) +
					( isMonths ? ' across the selected months' : '' ) + ' ' + timePhrase +
					'. Your visitors save about ' + bytes( saved ) + ' (a ' + pct + '% cut per image); ' +
					'your server gains about ' + bytes( webp ) + '.' );
			}

			var warn = byId( 'pxio-run-warning' );
			if ( warn ) {
				warn.innerHTML = ( freeDisk > 0 && webp > freeDisk * 0.8 )
					? '<div class="notice notice-warning pxui-notice inline"><p>' +
						'Estimated disk added (' + bytes( webp ) + ') is near the free space the server reports (' + bytes( freeDisk ) + ').</p></div>'
					: '';
			}

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
			var cb  = row && row.querySelector( '.pxio-month, .pxio-year-all' );
			if ( ! cb ) { return; }
			cb.checked = ! cb.checked;
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
