<?php

namespace Perxel_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together.
 */
class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Singleton - use Plugin::instance().
	 */
	private function __construct() {}

	/**
	 * Boot - called on plugins_loaded.
	 */
	public function boot() {
		// Background job callbacks. Registered on every load (front, admin,
		// cron, Action Scheduler's async loopback) so in-flight work always has
		// a handler - even while the plugin is soft-disabled, so a paused run
		// can still be wound down.
		Runner::register();
		( new Catchup() )->register();

		// Deleting an image deletes its WebP copies, even while soft-disabled.
		add_action( 'delete_attachment', array( Converter::class, 'on_delete' ) );

		if ( Settings::get( 'disabled' ) ) {
			return;
		}

		// Translations: WordPress.org auto-loads them for a hosted plugin since
		// WP 4.6, so no load_plugin_textdomain() call and no bundled /languages.

		// New uploads: the size limit and dropping the kept original.
		( new Resizer() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'perxel-image', Cli::class );
		}

		// Front + admin: serving layer.
		( new Serve() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
			( new Ajax() )->register();
		}
	}
}
