<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable batch state machine. All progress is durable — the queue and
 * counters are written after every attachment, so any interruption just leaves
 * work in the queue.
 */
class Runner {

	const OPTION       = 'perxel_image_optimizer_state';
	const STALE_AFTER  = 120; // seconds without a heartbeat => run considered stopped
	const MIN_BATCH    = 1;
	const MAX_BATCH    = 20;
	const TARGET_SECS  = 12; // aim each request at ~this wall time

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'phase'       => 'idle', // idle|converting|purging
			'queue'       => array(),
			'total'       => 0,
			'processed'   => 0,
			'failed'      => 0,
			'saved_bytes' => 0,
			'batch_size'  => 3,
			'started_at'  => 0,
			'last_beat'   => 0,
			'lock'        => '',
			'seconds'     => 0.0, // cumulative encode wall time (for ETA)
		);
	}

	/**
	 * @return array
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * @param array $state State to persist.
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * True when a run is active and its heartbeat is fresh.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$s = self::state();

		return 'idle' !== $s['phase'] && ( time() - (int) $s['last_beat'] ) < self::STALE_AFTER;
	}

	/**
	 * True when a run exists but its heartbeat is stale.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$s = self::state();

		return 'idle' !== $s['phase'] && ( time() - (int) $s['last_beat'] ) >= self::STALE_AFTER;
	}

	/**
	 * Start (or restart) a conversion run.
	 *
	 * @param string $lock Owner token from the browser.
	 * @return array State.
	 */
	public static function start( $lock ) {
		$queue = Scanner::build_queue();

		$state = self::defaults();
		$state['phase']      = 'converting';
		$state['queue']      = $queue;
		$state['total']      = count( $queue );
		$state['batch_size'] = 3;
		$state['started_at'] = time();
		$state['last_beat']  = time();
		$state['lock']       = $lock;

		self::save( $state );

		return $state;
	}

	/**
	 * Resume an existing (possibly stale) run by taking the lock.
	 *
	 * @param string $lock Owner token.
	 * @return array State.
	 */
	public static function resume( $lock ) {
		$state = self::state();

		// Nothing to resume — build a fresh queue.
		if ( empty( $state['queue'] ) ) {
			return self::start( $lock );
		}

		$state['phase']     = 'converting';
		$state['lock']      = $lock;
		$state['last_beat'] = time();
		self::save( $state );

		return $state;
	}

	/**
	 * Stop the run, keep progress.
	 */
	public static function cancel() {
		$state          = self::state();
		$state['phase'] = 'idle';
		$state['lock']  = '';
		self::save( $state );
	}

	/**
	 * Process the next batch.
	 *
	 * @param string $lock Owner token — must match, unless the run is stale.
	 * @return array {
	 *     @type string $status   running|done|locked
	 *     @type int    $processed
	 *     @type int    $remaining
	 *     @type int    $failed
	 *     @type int    $saved_bytes
	 *     @type int    $batch_size
	 *     @type int    $eta_seconds
	 * }
	 */
	public static function run_batch( $lock ) {
		$state = self::state();

		if ( 'converting' !== $state['phase'] ) {
			return array( 'status' => 'done' ) + self::progress( $state );
		}

		$fresh = ( time() - (int) $state['last_beat'] ) < self::STALE_AFTER;
		if ( $fresh && $state['lock'] && $state['lock'] !== $lock ) {
			return array( 'status' => 'locked' ) + self::progress( $state );
		}

		// Take/refresh ownership.
		$state['lock'] = $lock;

		if ( empty( $state['queue'] ) ) {
			$state['phase'] = 'idle';
			self::save( $state );
			return array( 'status' => 'done' ) + self::progress( $state );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		wp_raise_memory_limit( 'image' );

		$batch = array_splice( $state['queue'], 0, max( 1, (int) $state['batch_size'] ) );

		$t0        = microtime( true );
		$mem_limit = Environment::bytes_from_ini( ini_get( 'memory_limit' ) );

		foreach ( $batch as $id ) {
			try {
				$result = Converter::convert_attachment( (int) $id );
			} catch ( \Throwable $e ) {
				$result = array( 'status' => 'failed', 'converted' => 0, 'src_bytes' => 0, 'webp_bytes' => 0 );
			}

			$state['processed']++;

			if ( 'failed' === ( $result['status'] ?? '' ) ) {
				$state['failed']++;
			}

			$saved = (int) ( $result['src_bytes'] ?? 0 ) - (int) ( $result['webp_bytes'] ?? 0 );
			if ( $saved > 0 ) {
				$state['saved_bytes'] += $saved;
			}

			Metrics::apply( $result );

			// Durable: persist after every attachment.
			$state['last_beat'] = time();
			self::save( $state );
		}

		$elapsed = microtime( true ) - $t0;
		$state['seconds'] += $elapsed;

		$state['batch_size'] = self::adapt( (int) $state['batch_size'], $elapsed, memory_get_peak_usage( true ), $mem_limit );

		if ( empty( $state['queue'] ) ) {
			$state['phase'] = 'idle';
		}

		self::save( $state );

		$out           = self::progress( $state );
		$out['status'] = empty( $state['queue'] ) ? 'done' : 'running';

		return $out;
	}

	/**
	 * Grow or shrink the batch to hover around TARGET_SECS and stay under 70%
	 * of the memory limit.
	 *
	 * @param int   $current  Current batch size.
	 * @param float $elapsed  Seconds for the batch just processed.
	 * @param int   $peak     Peak memory bytes.
	 * @param int   $limit    Memory limit bytes (0 = unlimited).
	 * @return int
	 */
	private static function adapt( $current, $elapsed, $peak, $limit ) {
		$mem_hot = $limit > 0 && $peak > ( $limit * 0.7 );

		if ( $mem_hot || $elapsed > self::TARGET_SECS * 1.6 ) {
			return max( self::MIN_BATCH, (int) floor( $current / 2 ) );
		}

		if ( $elapsed < self::TARGET_SECS * 0.6 ) {
			return min( self::MAX_BATCH, $current + max( 1, (int) floor( $current / 2 ) ) );
		}

		return $current;
	}

	/**
	 * @param array $state State.
	 * @return array
	 */
	private static function progress( array $state ) {
		$remaining = count( $state['queue'] );
		$per_item  = $state['processed'] > 0 ? ( $state['seconds'] / $state['processed'] ) : 0;

		return array(
			'phase'       => $state['phase'],
			'processed'   => (int) $state['processed'],
			'remaining'   => $remaining,
			'total'       => (int) $state['total'],
			'failed'      => (int) $state['failed'],
			'saved_bytes' => (int) $state['saved_bytes'],
			'batch_size'  => (int) $state['batch_size'],
			'eta_seconds' => (int) round( $remaining * $per_item ),
			'rate'        => $per_item > 0 ? round( 1 / $per_item, 2 ) : 0,
		);
	}

	/**
	 * Reset to idle, wipe counters.
	 */
	public static function reset() {
		self::save( self::defaults() );
	}
}
