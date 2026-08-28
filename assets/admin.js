/* Perxel Image Optimizer — admin UI + tab-driven conversion loop */
( function () {
	'use strict';

	var cfg = window.PerxelImageOptimizer || {};

	/* ------------------------------------------------------------------ */
	/* helpers                                                            */
	/* ------------------------------------------------------------------ */

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			body.append( k, typeof v === 'object' ? JSON.stringify( v ) : v );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
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
		if ( m < 60 ) { return m + 'm ' + ( s % 60 ) + 's'; }
		return Math.floor( m / 60 ) + 'h ' + ( m % 60 ) + 'm';
	}

	function el( html ) {
		var t = document.createElement( 'template' );
		t.innerHTML = html.trim();
		return t.content.firstElementChild;
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function token() {
		return Math.random().toString( 36 ).slice( 2, 10 );
	}

	/* ------------------------------------------------------------------ */
	/* main page                                                          */
	/* ------------------------------------------------------------------ */

	var App = {
		root: null,
		snap: null,
		lock: token(),
		running: false,
		stopFlag: false,

		init: function ( root ) {
			this.root = root;
			try {
				this.snap = JSON.parse( root.getAttribute( 'data-snapshot' ) );
			} catch ( e ) {
				this.snap = null;
			}
			this.render();
		},

		refresh: function () {
			var self = this;
			return post( 'perxel_image_optimizer_status', {} ).then( function ( res ) {
				if ( res.json && res.json.success ) {
					self.snap = res.json.data;
					self.render();
				}
			} );
		},

		render: function () {
			if ( ! this.snap ) {
				this.root.textContent = 'Could not load status.';
				return;
			}

			var s = this.snap;
			var canConvert = s.environment.webp_encode;

			this.root.innerHTML = '';
			this.root.appendChild( this.cardEnvironment( s ) );
			this.root.appendChild( this.cardLibrary( s ) );
			this.root.appendChild( this.cardSample( s ) );
			if ( canConvert ) {
				this.root.appendChild( this.cardConversion( s ) );
			}
			if ( s.failures && s.failures.length ) {
				this.root.appendChild( this.cardFailures( s ) );
			}
			this.root.appendChild( this.cardSettings( s ) );
			this.root.appendChild( this.cardServing( s ) );
			this.root.appendChild( this.cardCleanup( s ) );

			this.wire();
		},

		/* --- cards --- */

		cardEnvironment: function ( s ) {
			var e = s.environment;
			var webp = e.webp_encode
				? '<span class="good">WebP ✓</span>'
				: '<span class="bad">WebP ✗ (cannot convert)</span>';
			var engine = e.imagick_webp ? 'Imagick' : ( e.gd ? 'GD ' + esc( e.gd_version ) : 'none' );
			var disk = e.free_disk == null ? 'unknown' : '~' + bytes( e.free_disk ) + ' (approx)';

			return el(
				'<div class="pxw-card"><h2>Environment</h2><div class="pxw-env">' +
				'Engine: ' + esc( engine ) + ' &nbsp; ' + webp +
				( e.imagick_lossless ? ' &nbsp; <span class="good">PNG lossless available</span>' : '' ) +
				'<br>PHP ' + esc( e.php_version ) +
				' &nbsp; memory_limit ' + esc( e.memory_limit_raw ) +
				' &nbsp; max_execution_time ' + esc( e.max_execution ) + 's' +
				' &nbsp; set_time_limit ' + ( e.set_time_limit ? 'allowed' : '<span class="pxw-warn">blocked</span>' ) +
				'<br>Apache: ' + ( e.is_apache ? 'yes' : 'no (fallback serving)' ) +
				' &nbsp; .htaccess ' + ( e.htaccess_writable ? 'writable' : '<span class="pxw-warn">not writable</span>' ) +
				' &nbsp; free disk ' + esc( disk ) +
				'</div></div>'
			);
		},

		cardLibrary: function ( s ) {
			var r = s.report || {};
			var sum = s.summary;

			var covPct = r.coverage_pct || 0;
			var bwPct = r.bandwidth_pct || 0;

			var lastScan = r.last_full_scan
				? new Date( r.last_full_scan * 1000 ).toLocaleString()
				: 'never';

			var wrap = el( '<div class="pxw-card"><h2>Results <button class="button button-small" data-act="recalc">Recalculate</button></h2></div>' );

			// Headline: the actual win.
			wrap.appendChild( el(
				'<div class="pxw-headline">You are saving <b>' + bytes( r.bandwidth_saved ) +
				'</b> of image bandwidth <span class="pxw-muted">(' + bwPct + '% smaller)</span></div>'
			) );

			wrap.appendChild( el(
				'<table class="pxw-ledger"><tbody>' +
				'<tr><th>Served image payload before</th><td>' + bytes( r.served_before ) + '</td>' +
				'<td class="pxw-muted">what a browser downloads for the converted files, as JPEG/PNG</td></tr>' +
				'<tr><th>Served image payload now</th><td>' + bytes( r.served_after ) + '</td>' +
				'<td class="pxw-muted">same files, served as WebP</td></tr>' +
				'<tr class="pxw-win"><th>Bandwidth saved</th><td>−' + bytes( r.bandwidth_saved ) + ' (' + bwPct + '%)</td>' +
				'<td class="pxw-muted">every time those images are downloaded</td></tr>' +
				'<tr><th>Disk added</th><td>+' + bytes( r.disk_added ) + '</td>' +
				'<td class="pxw-muted">' + ( r.webp_files || 0 ) + ' .webp files — originals kept for browsers without WebP</td></tr>' +
				'</tbody></table>'
			) );

			// Coverage.
			wrap.appendChild( el( '<h3>Coverage</h3>' ) );
			wrap.appendChild( el( '<div class="pxw-bar"><span style="width:' + covPct + '%"></span></div>' ) );
			wrap.appendChild( el(
				'<div class="pxw-stats">' +
				'<span><b>' + ( r.covered_files || 0 ) + '</b> / ' + ( r.candidate_files || 0 ) + ' served image files handled (' + covPct + '%)</span>' +
				'<span>Converted <b>' + ( r.converted_files || 0 ) + '</b></span>' +
				'<span>Left as-is <b>' + ( r.no_gain_files || 0 ) + '</b> <span class="pxw-muted">(WebP no smaller)</span></span>' +
				'<span class="' + ( r.pending_files ? 'pxw-warn' : '' ) + '">Pending <b>' + ( r.pending_files || 0 ) + '</b></span>' +
				'<span class="' + ( r.failed ? 'pxw-warn' : '' ) + '">Failed <b>' + ( r.failed || 0 ) + '</b></span>' +
				'</div>'
			) );
			wrap.appendChild( el(
				'<p class="pxw-muted">' + sum.attachments + ' image attachments · candidate pool ' +
				bytes( r.candidate_bytes ) + ' across ' + ( r.candidate_files || 0 ) + ' files · ' +
				'last full scan ' + esc( lastScan ) + '</p>'
			) );

			return wrap;
		},

		cardSample: function ( s ) {
			var wrap = el( '<div class="pxw-card"><h2>Sample preview <button class="button button-small" data-act="sample">Run sample</button></h2></div>' );
			var sm = s.sample;

			if ( ! sm || ! sm.samples || ! sm.samples.length ) {
				wrap.appendChild( el( '<p class="pxw-muted">No sample yet. Run a sample to estimate saving and run time before committing.</p>' ) );
				return wrap;
			}

			var est = sm.estimate || {};
			wrap.appendChild( el(
				'<div class="pxw-stats">' +
				'<span>avg ratio <b>' + sm.ratio + '</b></span>' +
				'<span>est. saving <b>' + bytes( est.estimated_saved_bytes ) + '</b></span>' +
				'<span>est. run time <b>' + secs( est.estimated_seconds_low ) + ' – ' + secs( est.estimated_seconds_high ) + '</b></span>' +
				'<span class="pxw-muted">' + sm.samples.length + ' samples</span>' +
				'</div>'
			) );

			var grid = el( '<div class="pxw-samples"></div>' );
			sm.samples.forEach( function ( x ) {
				grid.appendChild( el(
					'<figure><img loading="lazy" src="' + esc( x.url ) + '" alt="">' +
					esc( x.name ) + '<br>' + bytes( x.src ) + ' → ' + bytes( x.webp ) +
					' (' + Math.round( x.ratio * 100 ) + '%)</figure>'
				) );
			} );
			wrap.appendChild( grid );

			return wrap;
		},

		cardConversion: function ( s ) {
			var run = s.run;
			var wrap = el( '<div class="pxw-card"><h2>Conversion</h2></div>' );

			if ( this.running ) {
				wrap.appendChild( el( '<div class="pxw-bar"><span id="pxw-run-bar" style="width:0%"></span></div>' ) );
				wrap.appendChild( el( '<div class="pxw-run-live" id="pxw-run-live">Starting…</div>' ) );
				wrap.appendChild( el( '<button class="button" data-act="pause">Pause</button>' ) );
				return wrap;
			}

			if ( run.stale ) {
				wrap.appendChild( el( '<p class="pxw-warn">A previous run stopped unexpectedly at ' + run.processed + ' / ' + run.total + '.</p>' ) );
				wrap.appendChild( el( '<button class="button button-primary" data-act="resume">Resume</button> <button class="button" data-act="discard">Discard</button>' ) );
				return wrap;
			}

			if ( run.remaining > 0 && run.processed > 0 ) {
				wrap.appendChild( el( '<p>Paused at ' + run.processed + ' / ' + run.total + '. ' + run.failed + ' failed.</p>' ) );
				wrap.appendChild( el( '<button class="button button-primary" data-act="resume">Resume ' + run.processed + '/' + run.total + '</button>' ) );
				return wrap;
			}

			if ( s.summary.pending === 0 ) {
				wrap.appendChild( el( '<p class="good">Everything is converted.</p>' ) );
				wrap.appendChild( el( '<button class="button" data-act="start">Re-run anyway</button>' ) );
				return wrap;
			}

			wrap.appendChild( el( '<p>' + s.summary.pending + ' attachment(s) need conversion.</p>' ) );
			wrap.appendChild( el( '<button class="button button-primary button-hero" data-act="start">▶ Start conversion</button>' ) );
			return wrap;
		},

		cardFailures: function ( s ) {
			var wrap = el( '<div class="pxw-card"><h2>Failures (' + s.failures.length + ') <button class="button button-small" data-act="retry-failed">Retry all</button></h2></div>' );
			var list = el( '<div class="pxw-failures"></div>' );
			s.failures.forEach( function ( f ) {
				list.appendChild( el( '<div>⚠ ' + esc( f.file || f.name ) + ' — ' + esc( f.error ) +
					' <button class="button-link" data-retry="' + f.id + '">retry</button></div>' ) );
			} );
			wrap.appendChild( list );
			return wrap;
		},

		cardSettings: function ( s ) {
			var g = s.settings;
			var sizeBoxes = s.sizes.map( function ( name ) {
				var checked = g.sizes.indexOf( '*' ) > -1 || g.sizes.indexOf( name ) > -1;
				return '<label><input type="checkbox" data-size="' + esc( name ) + '"' + ( checked ? ' checked' : '' ) + '> ' + esc( name ) + '</label>';
			} ).join( '' );

			return el(
				'<div class="pxw-card"><h2>Settings</h2>' +
				'<div class="pxw-field"><label>JPEG → WebP quality</label>' +
				'<input type="range" min="40" max="100" id="pxw-jq" value="' + g.jpeg_quality + '"> <output id="pxw-jq-o">' + g.jpeg_quality + '</output></div>' +
				'<div class="pxw-field"><label>PNG → WebP quality</label>' +
				'<input type="range" min="40" max="100" id="pxw-pq" value="' + g.png_quality + '"> <output id="pxw-pq-o">' + g.png_quality + '</output>' +
				' &nbsp; <label><input type="checkbox" id="pxw-cpng"' + ( g.convert_png ? ' checked' : '' ) + '> Convert PNG</label></div>' +
				'<div class="pxw-field pxw-sizes"><label>Sizes to convert</label><span>' + sizeBoxes + '</span></div>' +
				'<div class="pxw-field"><label>Skip images over</label>' +
				'<input type="number" id="pxw-mp" min="1" max="200" value="' + g.skip_megapixels + '"> megapixels</div>' +
				'<div class="pxw-field"><label>On upload</label>' +
				'<label><input type="checkbox" id="pxw-cou"' + ( g.convert_on_upload ? ' checked' : '' ) + '> Convert new uploads automatically</label></div>' +
				'<p><button class="button button-primary" data-act="save">Save settings</button> <span id="pxw-save-msg" class="pxw-muted"></span></p>' +
				'</div>'
			);
		},

		cardServing: function ( s ) {
			var v = s.serving;
			var on = s.settings.serve;
			var modeText = { apache: 'Active — via .htaccess', fallback: 'Active — via <picture> fallback', off: 'Off' }[ v.mode ] || v.mode;

			var wrap = el(
				'<div class="pxw-card"><h2>Serving</h2>' +
				'<div class="pxw-field"><label><input type="checkbox" id="pxw-serve"' + ( on ? ' checked' : '' ) + '> Serve WebP to browsers</label>' +
				' <span class="pxw-muted">Status: ' + esc( modeText ) + '</span></div>' +
				'<p><button class="button button-small" data-act="selftest">Run self-test</button> <span id="pxw-selftest" class="pxw-muted"></span></p>' +
				'<details><summary>Managed .htaccess block</summary><code class="pxw-block">' + esc( v.rules_preview ) + '</code></details>' +
				'</div>'
			);
			return wrap;
		},

		cardCleanup: function () {
			return el(
				'<div class="pxw-card pxw-danger"><h2>Cleanup</h2>' +
				'<p><button class="button" data-act="purge">Remove all WebP files</button> ' +
				'<button class="button" data-act="htaccess-rm">Remove .htaccess block</button></p>' +
				'<p id="pxw-purge-msg" class="pxw-muted"></p>' +
				'<p class="pxw-muted">Deleting the plugin folder does not undo these.</p>' +
				'</div>'
			);
		},

		/* --- events --- */

		wire: function () {
			var self = this;

			this.root.querySelectorAll( '[data-act]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					self.action( btn.getAttribute( 'data-act' ), btn );
				} );
			} );

			var jq = this.root.querySelector( '#pxw-jq' );
			if ( jq ) { jq.addEventListener( 'input', function () { self.root.querySelector( '#pxw-jq-o' ).textContent = jq.value; } ); }
			var pq = this.root.querySelector( '#pxw-pq' );
			if ( pq ) { pq.addEventListener( 'input', function () { self.root.querySelector( '#pxw-pq-o' ).textContent = pq.value; } ); }

			var serve = this.root.querySelector( '#pxw-serve' );
			if ( serve ) {
				serve.addEventListener( 'change', function () {
					post( 'perxel_image_optimizer_serve', { on: serve.checked ? 1 : 0 } ).then( function () { self.refresh(); } );
				} );
			}

			this.root.querySelectorAll( '[data-retry]' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					post( 'perxel_image_optimizer_convert_one', { id: b.getAttribute( 'data-retry' ), force: 1 } ).then( function () { self.refresh(); } );
				} );
			} );
		},

		action: function ( act, btn ) {
			var self = this;

			switch ( act ) {
				case 'recalc':
					btn.disabled = true;
					btn.textContent = 'Recalculating…';
					post( 'perxel_image_optimizer_recalc', {} ).then( function ( r ) {
						self.snap = r.json.data; self.render();
					} );
					break;

				case 'sample':
					btn.disabled = true;
					btn.textContent = 'Running…';
					post( 'perxel_image_optimizer_sample', {} ).then( function ( r ) {
						if ( r.json && r.json.success ) { self.snap = r.json.data.snapshot; }
						self.render();
					} );
					break;

				case 'start':
					this.startRun( 'perxel_image_optimizer_start' );
					break;

				case 'resume':
					this.startRun( 'perxel_image_optimizer_resume' );
					break;

				case 'pause':
					this.stopFlag = true;
					break;

				case 'discard':
					post( 'perxel_image_optimizer_cancel', {} ).then( function () { self.refresh(); } );
					break;

				case 'save':
					this.saveSettings();
					break;

				case 'selftest':
					this.selfTest();
					break;

				case 'retry-failed':
					this.retryFailed();
					break;

				case 'purge':
					this.purge();
					break;

				case 'htaccess-rm':
					post( 'perxel_image_optimizer_htaccess_rm', {} ).then( function () { self.refresh(); } );
					break;
			}
		},

		startRun: function ( startAction ) {
			var self = this;
			this.lock = token();
			this.stopFlag = false;
			this.running = true;
			this.render();

			post( startAction, { lock: this.lock } ).then( function () {
				self.loop();
			} );
		},

		loop: function () {
			var self = this;
			var fails = 0;

			function bar( d ) {
				var live = self.root.querySelector( '#pxw-run-live' );
				var b = self.root.querySelector( '#pxw-run-bar' );
				var totalDone = d.processed;
				var pct = d.total ? Math.round( ( totalDone / d.total ) * 100 ) : 0;
				if ( b ) { b.style.width = pct + '%'; }
				if ( live ) {
					live.textContent = totalDone + ' / ' + d.total +
						' · ' + ( d.rate || 0 ) + ' img/s' +
						' · ETA ' + secs( d.eta_seconds ) +
						' · saved ' + bytes( d.saved_bytes ) +
						' · ' + d.failed + ' failed' +
						' · batch ' + d.batch_size;
				}
			}

			function next() {
				if ( self.stopFlag ) {
					self.finishRun();
					return;
				}

				post( 'perxel_image_optimizer_run_batch', { lock: self.lock } ).then( function ( res ) {
					if ( res.status === 409 ) {
						self.running = false;
						self.refresh();
						alert( 'Another tab is running the conversion.' );
						return;
					}

					if ( ! res.json || ! res.json.success ) {
						fails++;
						if ( fails >= 3 ) {
							self.finishRun( 'Paused — the server stopped responding. Click Resume to continue.' );
							return;
						}
						setTimeout( next, 2000 * fails );
						return;
					}

					fails = 0;
					var d = res.json.data;
					bar( d );

					if ( d.status === 'done' ) {
						self.finishRun();
						return;
					}

					next();
				} ).catch( function () {
					fails++;
					if ( fails >= 3 ) {
						self.finishRun( 'Paused — connection lost. Click Resume to continue.' );
						return;
					}
					setTimeout( next, 2000 * fails );
				} );
			}

			next();
		},

		finishRun: function ( msg ) {
			var self = this;
			this.running = false;
			this.stopFlag = false;
			post( 'perxel_image_optimizer_cancel', {} ).then( function () {
				self.refresh().then( function () {
					if ( msg ) {
						var c = self.root.querySelector( '.pxw-card h2' );
						if ( c ) { alert( msg ); }
					}
				} );
			} );
		},

		saveSettings: function () {
			var self = this;
			var sizes = [];
			this.root.querySelectorAll( '[data-size]' ).forEach( function ( b ) {
				if ( b.checked ) { sizes.push( b.getAttribute( 'data-size' ) ); }
			} );
			var allSizes = this.root.querySelectorAll( '[data-size]' ).length;
			if ( sizes.length === allSizes ) { sizes = [ '*' ]; }

			var payload = {
				jpeg_quality: this.root.querySelector( '#pxw-jq' ).value,
				png_quality: this.root.querySelector( '#pxw-pq' ).value,
				convert_png: this.root.querySelector( '#pxw-cpng' ).checked ? 1 : 0,
				convert_on_upload: this.root.querySelector( '#pxw-cou' ).checked ? 1 : 0,
				skip_megapixels: this.root.querySelector( '#pxw-mp' ).value,
				sizes: sizes
			};

			post( 'perxel_image_optimizer_save', { settings: payload } ).then( function ( r ) {
				self.snap = r.json.data;
				self.render();
				var msg = self.root.querySelector( '#pxw-save-msg' );
				if ( msg ) { msg.textContent = 'Saved.'; }
			} );
		},

		selfTest: function () {
			var out = this.root.querySelector( '#pxw-selftest' );
			var sm = this.snap.sample;
			if ( ! sm || ! sm.samples || ! sm.samples.length ) {
				out.textContent = 'Run a sample first.';
				return;
			}
			var url = sm.samples[ 0 ].url;
			out.textContent = 'testing…';
			fetch( url, { headers: { Accept: 'image/webp,*/*' }, cache: 'no-store' } ).then( function ( r ) {
				var ct = r.headers.get( 'content-type' ) || '';
				out.textContent = ct.indexOf( 'webp' ) > -1
					? 'got image/webp ✓'
					: 'got ' + ct + ' — serving not active for this request';
			} ).catch( function () { out.textContent = 'request failed'; } );
		},

		retryFailed: function () {
			var self = this;
			var ids = ( this.snap.failures || [] ).map( function ( f ) { return f.id; } );
			( function nextId( i ) {
				if ( i >= ids.length ) { self.refresh(); return; }
				post( 'perxel_image_optimizer_convert_one', { id: ids[ i ], force: 1 } ).then( function () { nextId( i + 1 ); } );
			} )( 0 );
		},

		purge: function () {
			if ( ! window.confirm( 'Delete every .webp file under uploads and reset all plugin data?' ) ) {
				return;
			}
			var self = this;
			var msg = this.root.querySelector( '#pxw-purge-msg' );
			msg.textContent = 'Building file list…';

			post( 'perxel_image_optimizer_purge_start', {} ).then( function ( r ) {
				var total = r.json.data.total;
				( function step() {
					post( 'perxel_image_optimizer_purge_step', {} ).then( function ( res ) {
						var d = res.json.data;
						msg.textContent = 'Deleted ' + d.deleted + ' / ' + ( d.total || total ) + ' (' + bytes( d.bytes ) + ')';
						if ( d.status === 'running' ) { step(); }
						else { self.refresh(); }
					} );
				} )();
			} );
		}
	};

	/* ------------------------------------------------------------------ */
	/* media library row / modal buttons                                  */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.perxel-image-optimizer-row-action' ) : null;
		if ( ! btn ) { return; }
		ev.preventDefault();

		var id = btn.getAttribute( 'data-id' );
		var action = btn.getAttribute( 'data-action' );
		var cell = document.querySelector( '.perxel-image-optimizer-cell[data-id="' + id + '"]' ) ||
			( btn.closest( '.perxel-image-optimizer-attachment' ) && btn.closest( '.perxel-image-optimizer-attachment' ).querySelector( '.perxel-image-optimizer-cell' ) );

		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = action === 'remove' ? 'Removing…' : 'Converting…';

		post( action === 'remove' ? 'perxel_image_optimizer_remove_one' : 'perxel_image_optimizer_convert_one', { id: id, force: 1 } )
			.then( function ( res ) {
				btn.disabled = false;
				btn.textContent = original;
				if ( res.json && res.json.success && cell ) {
					cell.textContent = res.json.data.label;
				}
			} );
	} );

	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.getElementById( 'perxel-image-optimizer-root' );
		if ( root ) { App.init( root ); }
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( App.running ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );
} )();
