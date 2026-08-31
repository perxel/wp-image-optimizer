<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-ajax endpoints. Every handler checks the nonce and capability.
 *
 * Only two kinds of thing stay on AJAX: the live progress poll while a run is
 * active, and the per-attachment Media-library buttons. Everything else - Scan,
 * Start, Pause, Cancel, Retry, the test email - is a plain form POST to an
 * `admin_post_*` handler (Admin.php), matching the plugin's server-rendered
 * house style.
 */
class Ajax {

	const NONCE = 'perxel_image_optimizer';

	/**
	 * Register handlers.
	 */
	public function register() {
		$map = array(
			'perxel_image_optimizer_progress'    => 'progress',
			'perxel_image_optimizer_convert_one' => 'convert_one',
			'perxel_image_optimizer_remove_one'  => 'remove_one',
			'perxel_image_optimizer_purge_start' => 'purge_start',
			'perxel_image_optimizer_purge_step'  => 'purge_step',
		);

		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Live run progress - polled by the monitor every few seconds.
	 */
	public function progress() {
		$this->guard();

		// Keep the worker alive while the tab is open (no-op if a chunk is
		// already queued; spawn_cron() self-throttles).
		Runner::nudge();

		wp_send_json_success(
			Runner::progress() + array( 'failures' => Failures::listing( 100 ) )
		);
	}

	/**
	 * Convert a single attachment (Media view buttons).
	 */
	public function convert_one() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad nonce' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}

		$force  = ! empty( $_POST['force'] );
		$result = Converter::convert_attachment( $id, $force );

		if ( 'failed' === ( $result['status'] ?? '' ) ) {
			Failures::record( $id, ( ! empty( $result['error'] ) ? $result['error'] : 'conversion failed' ), 'failed' );
		} else {
			Failures::clear_one( $id );
		}

		// The Optimization page refreshes its cached figures when they are stale.
		Scan::mark_stale();

		wp_send_json_success(
			array(
				'id'     => $id,
				'result' => $result,
				'label'  => Admin::attachment_status_label( $id ),
			)
		);
	}

	/**
	 * Remove a single attachment's siblings.
	 */
	public function remove_one() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad nonce' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}

		$removed = Converter::remove_attachment( $id );
		Failures::clear_one( $id );
		Scan::mark_stale();

		wp_send_json_success(
			array(
				'id'      => $id,
				'removed' => $removed,
				'label'   => Admin::attachment_status_label( $id ),
			)
		);
	}

	/**
	 * Begin a purge.
	 */
	public function purge_start() {
		$this->guard();

		$total = Purger::start();

		wp_send_json_success( array( 'total' => $total ) );
	}

	/**
	 * Purge one chunk.
	 */
	public function purge_step() {
		$this->guard();

		wp_send_json_success( Purger::step() );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Nonce + manage_options check for the screen-level endpoints (progress poll,
	 * purge loop). The per-attachment handlers check the nonce and `edit_post`
	 * inline. Dies with a JSON error on failure.
	 */
	private function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad nonce' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}
	}

	/**
	 * Everything the admin screens need in one object. Cheap - reads options and
	 * one `wp_count_attachments()`, never walks the library.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$serve = new Serve();

		return array(
			'environment' => Environment::probe(),
			'settings'    => Settings::all(),
			'summary'     => Scanner::summary(),
			'scan'        => Scan::data(),
			'stats'       => Scan::stats(),
			'job'         => Runner::progress(),
			'sections'    => Sections::months(),
			'serving'     => array(
				'mode'          => $serve->mode(),
				'block_present' => $serve->block_present(),
				'rules_preview' => $serve->rules_preview(),
			),
			'failures'    => Failures::listing( 100 ),
			'sizes'       => array_merge( array( 'full' ), get_intermediate_image_sizes() ),
		);
	}
}
