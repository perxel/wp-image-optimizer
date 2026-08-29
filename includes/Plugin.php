<?php

namespace Perxel\ImageOptimizer;

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

		if ( Settings::get( 'disabled' ) ) {
			return;
		}

		load_plugin_textdomain( 'perxel-image-optimizer', false, dirname( plugin_basename( PERXEL_IMAGE_OPTIMIZER_FILE ) ) . '/languages' );

		// Front + admin: serving layer.
		( new Serve() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
			( new Ajax() )->register();
		}
	}
}
