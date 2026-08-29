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

		// Background job callbacks. Registered on every load (front, admin,
		// cron, Action Scheduler's async loopback) so in-flight work always has
		// a handler — even while the plugin is soft-disabled, so a paused run
		// can still be wound down.
		Runner::register();
		( new Catchup() )->register();
		add_action( 'perxel_image_optimizer_recalc', array( $this, 'run_recalc' ) );

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

	/**
	 * Authoritative metrics recalculation, run as a background action so a large
	 * library does not block an admin request.
	 */
	public function run_recalc() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		Metrics::recalculate();
		delete_transient( 'perxel_image_optimizer_recalcing' );
	}
}
