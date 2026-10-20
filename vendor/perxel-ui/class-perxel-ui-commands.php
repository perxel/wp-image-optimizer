<?php
/**
 * Perxel shared admin UI - command palette.
 *
 * Puts a plugin's screens and settings in WordPress's command palette
 * (Cmd/Ctrl+K, on every admin screen since WordPress 6.9, and in the block
 * editor), so a user finds "login logo" without knowing which plugin or page
 * holds it. Every plugin on the kit adds its own commands; the kit loads one
 * script for all of them.
 *
 * Usage, from a hook after `after_setup_theme` (e.g. `admin_init`):
 *
 *     Perxel_UI_Commands::add( 'My Plugin', static function () {
 *         return array(
 *             array(
 *                 'name'     => 'my-plugin/settings',
 *                 'label'    => 'Settings',
 *                 'url'      => admin_url( 'admin.php?page=my-plugin-settings#api' ),
 *                 'keywords' => array( 'API key', 'webhook' ),
 *             ),
 *         );
 *     } );
 *
 * The callback runs only on a screen that loads the palette, and returns only
 * what the current user may open (check capabilities there). Each command
 * shows as "My Plugin > Settings", is searched by its label and `keywords`,
 * and opens `url`. A URL ending in `#<id>` lands on that element, scrolled
 * clear of the kit's sticky title bar and marked for a moment (ui.css).
 *
 * Loaded by class-perxel-ui.php (not the loader), so an older loader that
 * wins the race still loads it from a newer copy.
 *
 * @package Perxel_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects every plugin's commands and hands them to the palette.
 */
final class Perxel_UI_Commands {

	/**
	 * Plugin name => callbacks returning its commands.
	 *
	 * @var array<string,callable[]>
	 */
	private static $providers = array();

	/**
	 * Whether the enqueue hook has been added yet.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Add a plugin's commands. Additive: several calls, several plugins.
	 *
	 * @param string   $plugin   Plugin name, shown before each command's label.
	 * @param callable $callback Returns a list of `[ 'name', 'label', 'url', 'keywords'? ]`.
	 */
	public static function add( $plugin, $callback ) {
		self::$providers[ (string) $plugin ][] = $callback;

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		}
	}

	/**
	 * Load the commands on a screen with the palette. None before WordPress
	 * 6.9: `wp-commands` alone would load the palette without its admin UI.
	 */
	public static function enqueue() {
		if ( ! function_exists( 'wp_enqueue_command_palette_assets' ) ) {
			return;
		}

		$commands = self::commands();
		if ( ! $commands ) {
			return;
		}

		wp_enqueue_script( 'perxel-ui-commands', PERXEL_UI_URL . '/assets/commands.js', array( 'wp-commands', 'wp-data' ), PERXEL_UI_VERSION, true );
		wp_add_inline_script( 'perxel-ui-commands', 'window.pxuiCommands=' . wp_json_encode( $commands ) . ';', 'before' );
	}

	/**
	 * Every provider's commands, labelled "Plugin > Label", the invalid ones
	 * (no name, label or URL) left out.
	 *
	 * @return array<int,array{name:string,label:string,url:string,keywords:string[]}>
	 */
	private static function commands() {
		$commands = array();

		foreach ( self::$providers as $plugin => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				foreach ( (array) call_user_func( $callback ) as $command ) {
					if ( empty( $command['name'] ) || empty( $command['label'] ) || empty( $command['url'] ) ) {
						continue;
					}

					$commands[] = array(
						'name'     => (string) $command['name'],
						'label'    => $plugin . ' > ' . $command['label'],
						'url'      => esc_url_raw( (string) $command['url'] ),
						'keywords' => array_values( array_unique( array_filter( array_map( 'strval', (array) ( $command['keywords'] ?? array() ) ) ) ) ),
					);
				}
			}
		}

		return $commands;
	}
}
