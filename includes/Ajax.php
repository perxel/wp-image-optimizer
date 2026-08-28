<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * admin-ajax endpoints. Every handler checks the nonce and capability.
 */
class Ajax {

	const NONCE = 'perxel_image_optimizer';

	/**
	 * Register handlers.
	 */
	public function register() {
		$map = array(
			'perxel_image_optimizer_status'      => 'status',
			'perxel_image_optimizer_sample'      => 'sample',
			'perxel_image_optimizer_recalc'      => 'recalc',
			'perxel_image_optimizer_start'       => 'start',
			'perxel_image_optimizer_resume'      => 'resume',
			'perxel_image_optimizer_run_batch'   => 'run_batch',
			'perxel_image_optimizer_cancel'      => 'cancel',
			'perxel_image_optimizer_save'        => 'save_settings',
			'perxel_image_optimizer_serve'       => 'serve_toggle',
			'perxel_image_optimizer_convert_one' => 'convert_one',
			'perxel_image_optimizer_remove_one'  => 'remove_one',
			'perxel_image_optimizer_purge_start' => 'purge_start',
			'perxel_image_optimizer_purge_step'  => 'purge_step',
			'perxel_image_optimizer_htaccess_rm' => 'htaccess_remove',
		);

		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Full snapshot for the admin page.
	 */
	public function status() {
		$this->guard();

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Run the sample estimate.
	 */
	public function sample() {
		$this->guard();

		$data = Scanner::run_sample( 25 );

		wp_send_json_success( array( 'sample' => $data, 'snapshot' => self::snapshot() ) );
	}

	/**
	 * Full metrics recalculation.
	 */
	public function recalc() {
		$this->guard();

		Metrics::recalculate();

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Start a fresh run.
	 */
	public function start() {
		$this->guard();

		$lock = $this->lock_param();
		Runner::start( $lock );

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Resume / take over a run.
	 */
	public function resume() {
		$this->guard();

		$lock = $this->lock_param();
		Runner::resume( $lock );

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Process one batch.
	 */
	public function run_batch() {
		$this->guard();

		$lock   = $this->lock_param();
		$result = Runner::run_batch( $lock );

		if ( 'locked' === ( $result['status'] ?? '' ) ) {
			wp_send_json_error( array( 'reason' => 'locked' ) + $result, 409 );
		}

		wp_send_json_success( $result + array( 'report' => Metrics::report() ) );
	}

	/**
	 * Stop the run.
	 */
	public function cancel() {
		$this->guard();

		Runner::cancel();

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		$this->guard();

		$raw = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		Settings::update( is_array( $raw ) ? $raw : array() );

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Toggle serving.
	 */
	public function serve_toggle() {
		$this->guard();

		$on    = ! empty( $_POST['on'] );
		$serve = new Serve();

		$info = $on ? $serve->enable() : array( 'ok' => true, 'mode' => 'off', 'message' => __( 'Serving disabled.', 'perxel-image-optimizer' ) );

		if ( ! $on ) {
			$serve->disable();
		}

		wp_send_json_success( $info + array( 'snapshot' => self::snapshot() ) );
	}

	/**
	 * Remove just the .htaccess block, leave the setting alone.
	 */
	public function htaccess_remove() {
		$this->guard();

		( new Serve() )->remove_block();

		wp_send_json_success( self::snapshot() );
	}

	/**
	 * Convert a single attachment (Media view buttons).
	 */
	public function convert_one() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$this->guard( $id );

		$force  = ! empty( $_POST['force'] );
		$result = Converter::convert_attachment( $id, $force );
		Metrics::apply( $result );

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
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$this->guard( $id );

		$removed = Converter::remove_attachment( $id );

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
	 * Nonce + capability check. Dies with a JSON error on failure.
	 *
	 * @param int $attachment_id Optional attachment to check edit rights on.
	 */
	private function guard( $attachment_id = 0 ) {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad nonce' ), 403 );
		}

		$cap = $attachment_id ? 'edit_post' : 'manage_options';

		if ( $attachment_id ? ! current_user_can( $cap, $attachment_id ) : ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}
	}

	/**
	 * @return string Sanitised lock token from the request.
	 */
	private function lock_param() {
		return isset( $_POST['lock'] ) ? sanitize_key( wp_unslash( $_POST['lock'] ) ) : '';
	}

	/**
	 * Everything the admin page needs in one object.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$serve = new Serve();
		$state = Runner::state();

		return array(
			'environment' => Environment::probe(),
			'settings'    => Settings::all(),
			'metrics'     => Metrics::all(),
			'report'      => Metrics::report(),
			'summary'     => Scanner::summary(),
			'sample'      => Scanner::cached_sample(),
			'run'         => array(
				'phase'       => $state['phase'],
				'processed'   => (int) $state['processed'],
				'total'       => (int) $state['total'],
				'failed'      => (int) $state['failed'],
				'saved_bytes' => (int) $state['saved_bytes'],
				'remaining'   => count( $state['queue'] ),
				'running'     => Runner::is_running(),
				'stale'       => Runner::is_stale(),
			),
			'serving'     => array(
				'mode'            => $serve->mode(),
				'block_present'   => $serve->block_present(),
				'rules_preview'   => $serve->rules_preview(),
			),
			'failures'    => Scanner::failures( 50 ),
			'sizes'       => array_merge( array( 'full' ), get_intermediate_image_sizes() ),
		);
	}
}
