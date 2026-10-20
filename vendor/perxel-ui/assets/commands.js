/**
 * Perxel shared admin UI - command palette.
 *
 * Registers every command in window.pxuiCommands (built by Perxel_UI_Commands)
 * with WordPress's command palette (Cmd/Ctrl+K). Each is searched by its label
 * and keywords and opens its URL.
 */
( function ( wp, commands ) {
	'use strict';

	if ( ! commands || ! wp || ! wp.commands || ! wp.data ) {
		return;
	}

	var store = wp.data.dispatch( wp.commands.store );

	commands.forEach( function ( command ) {
		store.registerCommand( {
			name: command.name,
			label: command.label,
			keywords: command.keywords,
			callback: function ( args ) {
				args.close();
				document.location = command.url;
			},
		} );
	} );
} )( window.wp, window.pxuiCommands );
