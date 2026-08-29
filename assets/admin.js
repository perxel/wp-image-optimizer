/* Perxel Image Optimizer — admin pages (Status + Settings).
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

		var scope   = byId( 'pxio-scope' );
		var months  = byId( 'pxio-months' );
		var avgSrc  = parseFloat( form.dataset.avgSrc ) || 0;
		var avgFrac = parseFloat( form.dataset.avgFrac ) || 0.7;
		var freeDisk = parseFloat( form.dataset.freeDisk ) || 0;
		var pendingAll = parseInt( form.dataset.pendingAll, 10 ) || 0;

		function selectedImages() {
			if ( ! scope || scope.value !== 'months' ) { return pendingAll; }
			var n = 0;
			form.querySelectorAll( '.pxio-month:checked' ).forEach( function ( cb ) {
				n += parseInt( cb.dataset.due, 10 ) || 0;
			} );
			return n;
		}

		function selectedMonthCount() {
			return form.querySelectorAll( '.pxio-month:checked' ).length;
		}

		function recompute() {
			var isMonths = scope && scope.value === 'months';
			if ( months ) { months.hidden = ! isMonths; }

			var images = selectedImages();
			var src    = images * avgSrc;
			var webp   = Math.round( src * avgFrac );
			var saved  = Math.max( 0, src - webp );
			var pct    = src > 0 ? Math.round( saved / src * 100 ) : 0;

			setText( 'pxio-fig-images', images.toLocaleString() );
			setText( 'pxio-fig-saved', '≈ −' + bytes( saved ) );
			setText( 'pxio-fig-pct', '≈ ' + pct + '% smaller' );
			setText( 'pxio-fig-disk', '≈ +' + bytes( webp ) );
			setText( 'pxio-fig-scope', isMonths
				? ( selectedMonthCount() + ' month' + ( selectedMonthCount() === 1 ? '' : 's' ) )
				: 'everything pending' );

			var warn = byId( 'pxio-run-warning' );
			if ( warn ) {
				warn.innerHTML = ( freeDisk > 0 && webp > freeDisk * 0.8 )
					? '<div class="notice notice-warning pxui-notice inline"><p>' +
						'Estimated disk added (' + bytes( webp ) + ') is close to the free space on this server (' + bytes( freeDisk ) + ').</p></div>'
					: '';
			}

			var start = document.querySelector( 'button[form="pxio-prepare"]' );
			if ( start ) { start.disabled = images < 1; }
		}

		function setText( id, txt ) {
			var el = byId( id );
			if ( el ) { el.textContent = txt; }
		}

		if ( scope ) { scope.addEventListener( 'change', recompute ); }

		form.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'pxio-year-all' ) ) {
				var year = e.target.dataset.year;
				form.querySelectorAll( '.pxio-month[data-year="' + year + '"]' ).forEach( function ( cb ) {
					cb.checked = e.target.checked;
				} );
			}
			recompute();
		} );

		recompute();
	}

	/* ---- monitor poll ------------------------------------------- */

	function bindMonitor() {
		var mon = byId( 'pxio-monitor' );
		if ( ! mon || mon.dataset.poll !== '1' ) { return; }

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

				// Phase or liveness changed — let PHP re-render the whole screen.
				if ( d.phase !== 'running' || d.stalled ) { reload(); return; }

				var bar = document.querySelector( '#pxio-bar .pxui-progress__fill' );
				if ( bar ) { bar.style.width = d.percent + '%'; }

				setText( 'pxio-count', d.processed.toLocaleString() + ' / ' + d.total.toLocaleString() );
				setText( 'pxio-headline', d.month_label
					? 'Converting… — ' + d.month_label + ' · month ' + d.month_pos + ' of ' + d.months_total
					: 'Converting…' );
				setText( 'pxio-m-converted', d.converted.toLocaleString() );
				setText( 'pxio-m-remaining', d.remaining.toLocaleString() );
				setText( 'pxio-m-saved', bytes( d.saved_bytes ) );
				setText( 'pxio-m-disk', bytes( d.webp_bytes ) );
				setText( 'pxio-failed', d.failed.toLocaleString() );
				setText( 'pxio-large', d.too_large.toLocaleString() );
				setText( 'pxio-rate-line', d.rate + ' img/s · ETA ' + secs( d.eta_seconds ) +
					' · ' + d.failed + ' failed · ' + d.too_large + ' too large' );
				if ( d.projected ) {
					setText( 'pxio-proj-line', 'Projected −' + bytes( d.projected.saved_bytes ) +
						' (≈ ' + d.projected.percent + '%) · +' + bytes( d.projected.webp_bytes ) + ' disk' );
				}
			} ).catch( function () {
				if ( ++tries >= 4 ) { reload(); }
			} );
		}

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

	document.addEventListener( 'DOMContentLoaded', function () {
		bindPrepare();
		bindMonitor();
		if ( byId( 'pxio-settings-form' ) || byId( 'pxio-purge' ) ) { bindSettings(); }
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( settingsDirty ) { e.preventDefault(); e.returnValue = ''; }
	} );
}() );
