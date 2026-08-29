<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deferred "auto-optimize new uploads".
 *
 * A new JPEG/PNG no longer converts inline (that blocked the upload response on
 * shared hosting). Instead it schedules one debounced catch-up action ~60s out
 * — keep-first, so a 40-image drag-drop becomes a single job, and a long upload
 * session is picked up in ~1-minute waves. The job runs one weight-budgeted
 * chunk of "needs work, newest first" and reschedules itself while work remains.
 *
 * The 60s delay also lets WordPress finish any deferred sub-size generation
 * before we look at the attachment.
 */
class Catchup {

	const HOOK          = 'perxel_image_optimizer_catch_up';
	const DELAY         = 60;
	const CHUNK_SECONDS = 15;
	const BATCH         = 40;

	/**
	 * Hooks. Registered on every load so the Action Scheduler callback exists.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_metadata' ), 20, 2 );
	}

	/**
	 * A new attachment finished processing — schedule a catch-up.
	 *
	 * @param array $metadata      Attachment metadata (passed through untouched).
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function on_metadata( $metadata, $attachment_id ) {
		if ( ! Settings::get( 'convert_on_upload' ) || Settings::get( 'disabled' ) ) {
			return $metadata;
		}

		if ( in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			self::schedule();
		}

		return $metadata;
	}

	/**
	 * Schedule one catch-up action, unless one is already pending (keep-first
	 * debounce — never bump an existing job further out).
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array(), Runner::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time() + self::DELAY, self::HOOK, array(), Runner::GROUP );
	}

	/**
	 * Convert one weight-budgeted chunk of pending images, newest first.
	 * Action Scheduler callback for self::HOOK.
	 */
	public function run() {
		if ( Settings::get( 'disabled' ) || ! Environment::can_convert() ) {
			return;
		}

		// A bulk run is already walking the whole library — let it handle these.
		if ( Runner::is_active() ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'image' );

		$started = microtime( true );

		foreach ( Scanner::needs_work_ids( self::BATCH ) as $id ) {
			try {
				$result = Converter::convert_attachment( (int) $id );
				Metrics::apply( $result );

				if ( 'failed' === ( $result['status'] ?? '' ) ) {
					Failures::record( $id, ( ! empty( $result['error'] ) ? $result['error'] : 'conversion failed' ), 'failed' );
				} else {
					Failures::clear_one( $id );
				}
			} catch ( \Throwable $e ) {
				Failures::record( $id, $e->getMessage(), 'failed' );
			}

			if ( ( microtime( true ) - $started ) > self::CHUNK_SECONDS ) {
				break;
			}
		}

		if ( Scanner::has_pending() && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), Runner::GROUP );
		}
	}
}
