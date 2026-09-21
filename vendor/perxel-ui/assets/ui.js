/**
 * Perxel shared admin UI - minimal behaviour.
 *
 * WordPress core already wires dismiss buttons onto `.notice.is-dismissible`,
 * so the kit adds only a handful of behaviours:
 *
 * 1. Destructive-action confirm. Any element carrying `data-pxui-confirm` is
 *    click-blocked unless the native confirm is accepted:
 *
 *        <button class="button" data-pxui-confirm="Delete everything?">Delete</button>
 *
 * 2. Media picker. `Perxel_UI::media()` markup is wired to the native
 *    `wp.media` frame - Choose opens it, Remove clears, the chosen attachment
 *    IDs are kept as a comma-joined string in the hidden input. The screen
 *    must call `wp_enqueue_media()`.
 *
 * 3. Colour picker. `Perxel_UI::color()` pairs a native `<input type="color">`
 *    swatch with a hex text field; this keeps the two in sync.
 *
 * 4. Unsaved-changes guard. A `<form data-pxui-dirty-guard>` is snapshotted on
 *    load; once a field differs, leaving the page (menu link, back button,
 *    closing the tab) trips the browser's native "Leave site?" prompt. The
 *    guard clears itself when that form submits.
 *
 *      - Fields that are `[disabled]`, `[readonly]`, `[type=hidden]`, buttons,
 *        or carry `data-pxui-dirty-ignore` are left out of the snapshot.
 *      - Programmatic `.value =` writes fire no event and so never mark the
 *        form dirty; a script that changes state the user should be warned
 *        about calls `pxui.dirtyGuard.mark( form )`. `clear( form )` and
 *        `resnapshot( form )` are the counterparts.
 *      - The native prompt text cannot be customised; the attribute takes no
 *        value.
 */
( function () {
	'use strict';

	/* --- 1. Destructive-action confirm -------------------------------- */

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

	/* --- 2. Media picker (wp.media) --------------------------------- *
	 * `Perxel_UI::media()` renders a hidden <input> + a preview list + Choose
	 * / Remove buttons. The screen must have run wp_enqueue_media(). The
	 * chosen IDs live as a comma-joined string in `.pxui-media__value`; the
	 * hidden input is left out of the dirty-guard snapshot, so every write
	 * calls pxui.dirtyGuard.mark().
	 * -------------------------------------------------------------------- */

	function mediaItemMarkup( a, size ) {
		var s = a.sizes && ( a.sizes[ size ] || a.sizes.thumbnail || a.sizes.medium || a.sizes.full );
		var isImage = 'image' === a.type || /^image\//.test( a.mime || '' );
		var inner = isImage
			? '<img src="' + ( s ? s.url : a.url || a.icon ) + '" alt="">'
			: '<span class="pxui-media__file">' + ( a.filename || a.name || a.title || a.id ) + '</span>';

		return '<span class="pxui-media__item" data-id="' + a.id + '">' + inner +
			'<button type="button" class="pxui-media__drop" aria-label="Remove">×</button></span>';
	}

	function mediaSync( wrap ) {
		var input = wrap.querySelector( '.pxui-media__value' );
		var items = wrap.querySelectorAll( '.pxui-media__item' );
		var ids = [];
		for ( var i = 0; i < items.length; i++ ) {
			ids.push( items[ i ].getAttribute( 'data-id' ) );
		}

		input.value = ids.join( ',' );

		var list = wrap.querySelector( '.pxui-media__list' );
		var clear = wrap.querySelector( '.pxui-media__clear' );
		if ( list ) { list.hidden = 0 === ids.length; }
		if ( clear ) { clear.hidden = 0 === ids.length; }

		if ( window.pxui && window.pxui.dirtyGuard && input.form ) {
			window.pxui.dirtyGuard.mark( input.form );
		}
	}

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		if ( ! t || ! t.closest ) {
			return;
		}

		var drop = t.closest( '.pxui-media__drop' );
		var clear = t.closest( '.pxui-media__clear' );
		var choose = t.closest( '.pxui-media__choose' );
		if ( ! drop && ! clear && ! choose ) {
			return;
		}

		var wrap = ( drop || clear || choose ).closest( '.pxui-media' );
		if ( ! wrap ) {
			return;
		}
		var list = wrap.querySelector( '.pxui-media__list' );

		if ( drop ) {
			var item = drop.closest( '.pxui-media__item' );
			if ( item && item.parentNode ) {
				item.parentNode.removeChild( item );
			}
			mediaSync( wrap );
			return;
		}

		if ( clear ) {
			while ( list && list.firstChild ) {
				list.removeChild( list.firstChild );
			}
			mediaSync( wrap );
			return;
		}

		if ( 'undefined' === typeof wp || ! wp.media ) {
			if ( window.console ) {
				window.console.warn( 'perxel-ui: wp.media is unavailable - the screen must call wp_enqueue_media().' );
			}
			return;
		}

		var multiple = wrap.hasAttribute( 'data-multiple' );
		var type = wrap.getAttribute( 'data-type' ) || '';
		var size = wrap.getAttribute( 'data-preview-size' ) || 'thumbnail';
		var input = wrap.querySelector( '.pxui-media__value' );

		var frame = wp.media( {
			title: choose.textContent,
			button: { text: choose.textContent },
			multiple: multiple ? 'add' : false,
			library: type ? { type: type } : {}
		} );

		frame.on( 'open', function () {
			var selection = frame.state().get( 'selection' );
			( input.value ? input.value.split( ',' ) : [] ).forEach( function ( raw ) {
				var id = parseInt( raw, 10 );
				if ( ! id ) {
					return;
				}
				var att = wp.media.attachment( id );
				att.fetch();
				selection.add( att );
			} );
		} );

		frame.on( 'select', function () {
			var picked = frame.state().get( 'selection' ).toJSON();
			if ( ! multiple ) {
				while ( list.firstChild ) {
					list.removeChild( list.firstChild );
				}
			}
			picked.forEach( function ( a ) {
				if ( wrap.querySelector( '.pxui-media__item[data-id="' + a.id + '"]' ) ) {
					return;
				}
				list.insertAdjacentHTML( 'beforeend', mediaItemMarkup( a, size ) );
			} );
			mediaSync( wrap );
		} );

		frame.open();
	}, true );

	/* --- 3. Colour picker sync ------------------------------------- *
	 * `Perxel_UI::color()` pairs a native <input type="color"> swatch with a
	 * hex text field (the one that carries the name). Keep them in step. The
	 * text field is a normal named control, so the dirty guard already sees
	 * a programmatic change to it - no mark() needed here.
	 * -------------------------------------------------------------------- */

	document.addEventListener( 'input', function ( ev ) {
		var t = ev.target;
		if ( ! t || ! t.classList || ! t.closest ) {
			return;
		}

		var box = t.closest( '.pxui-color' );
		if ( ! box ) {
			return;
		}

		if ( t.classList.contains( 'pxui-color__swatch' ) ) {
			var hex = box.querySelector( '.pxui-color__hex' );
			if ( hex ) {
				hex.value = t.value;
			}
			return;
		}

		if ( t.classList.contains( 'pxui-color__hex' ) ) {
			var v = t.value.trim();
			if ( /^#?[0-9a-fA-F]{6}$/.test( v ) ) {
				if ( '#' !== v.charAt( 0 ) ) {
					v = '#' + v;
				}
				var swatch = box.querySelector( '.pxui-color__swatch' );
				if ( swatch ) {
					swatch.value = v.toLowerCase();
				}
			}
		}
	}, true );

	/* --- 4. Unsaved-changes guard ----------------------------------- */

	var SKIP_TYPES = { hidden: 1, submit: 1, button: 1, reset: 1, image: 1, file: 1 };
	var FORCED = 'PXUI_FORCED_DIRTY';

	function counted( field ) {
		if ( ! field.name || field.disabled || field.readOnly ) {
			return false;
		}
		if ( SKIP_TYPES[ field.type ] ) {
			return false;
		}
		if ( field.closest && field.closest( '[data-pxui-dirty-ignore]' ) ) {
			return false;
		}
		return true;
	}

	function snapshot( form ) {
		var parts = [];
		var fields = form.elements;
		for ( var i = 0; i < fields.length; i++ ) {
			var field = fields[ i ];
			if ( ! counted( field ) ) {
				continue;
			}
			var value;
			if ( 'checkbox' === field.type || 'radio' === field.type ) {
				value = field.checked ? '1' : '0';
			} else if ( field.multiple && field.options ) {
				var picked = [];
				for ( var o = 0; o < field.options.length; o++ ) {
					if ( field.options[ o ].selected ) {
						picked.push( field.options[ o ].value );
					}
				}
				value = picked.join( ',' );
			} else {
				value = field.value;
			}
			// A snapshot line always starts with a digit, so the FORCED
			// sentinel can never compare equal to a real snapshot.
			parts.push( i + ':' + field.name + '=' + value );
		}
		return parts.join( '\n' );
	}

	var guarded = [];

	function entryFor( form ) {
		for ( var i = 0; i < guarded.length; i++ ) {
			if ( guarded[ i ].form === form ) {
				return guarded[ i ];
			}
		}
		return null;
	}

	function anyDirty() {
		for ( var i = 0; i < guarded.length; i++ ) {
			var e = guarded[ i ];
			if ( e.clean === FORCED || e.clean !== snapshot( e.form ) ) {
				return true;
			}
		}
		return false;
	}

	function init() {
		var forms = document.querySelectorAll( 'form[data-pxui-dirty-guard]' );
		if ( ! forms.length ) {
			return;
		}

		Array.prototype.forEach.call( forms, function ( form ) {
			var entry = { form: form, clean: snapshot( form ) };
			guarded.push( entry );

			form.addEventListener( 'submit', function () {
				entry.clean = snapshot( form );
			} );
		} );

		window.addEventListener( 'beforeunload', function ( ev ) {
			if ( anyDirty() ) {
				ev.preventDefault();
				ev.returnValue = '';
			}
		} );

		window.pxui.dirtyGuard = {
			// Force the form dirty - state changed without a field event.
			mark: function ( form ) {
				var e = entryFor( form );
				if ( e ) {
					e.clean = FORCED;
				}
			},
			// Treat the form's current values as the saved baseline.
			clear: function ( form ) {
				var e = entryFor( form );
				if ( e ) {
					e.clean = snapshot( form );
				}
			},
			resnapshot: function ( form ) {
				this.clear( form );
			}
		};
	}

	window.pxui = window.pxui || {};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
