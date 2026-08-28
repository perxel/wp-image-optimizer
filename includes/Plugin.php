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
	 * Boot — called on plugins_loaded.
	 */
	public function boot() {
		Migrator::run();

		if ( Settings::get( 'disabled' ) ) {
			return;
		}

		load_plugin_textdomain( 'perxel-image-optimizer', false, dirname( plugin_basename( PERXEL_IMAGE_OPTIMIZER_FILE ) ) . '/languages' );

		// Front + admin: serving layer.
		( new Serve() )->register();

		// New uploads.
		( new Uploads() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
			( new Ajax() )->register();
		}
	}
}
