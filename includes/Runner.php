<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The background bulk-conversion runner.
 *
 * Action Scheduler owns the process lifecycle (will-it-run, retries, the
 * activity log). This class owns:
 *
 *   - the job state option (phase, chosen month set, cursor, live counters),
 *   - the month cursor walk, newest → oldest (Sections),
 *   - chunk packing: a per-image memory gate (hard safety), a per-chunk
 *     wall-time budget (pacing) and a max image count cap,
 *   - persisting after every image so a mid-chunk kill resumes cleanly,
 *   - pause / resume / cancel, and the page-visit "nudge" when a run stalls.
 *
 * One recurring single action (`self::HOOK`) re-enqueues itself while the cursor
 * has work and unschedules itself when done.
 */
class Runner {

	const OPTION = 'perxel_image_optimizer_job';
	const GROUP  = 'perxel-image-optimizer';
	const HOOK   = 'perxel_image_optimizer_convert_chunk';

	/** Learned wall-clock seconds per image, EWMA over completed runs. */
	const PACE_OPTION = 'perxel_image_optimizer_pace';

	/** Seconds without a heartbeat before a running job counts as stalled. */
	const STALE_AFTER = 300;

	/** Hard ceiling on images per chunk - catches "thousands of tiny images". */
	const MAX_PER_CHUNK = 150;

	/** DB page size when pulling candidate IDs for the current month. */
	const DB_BATCH = 100;

	/**
	 * Wall-time budget for one chunk. Each Action Scheduler round-trip has real
	 * overhead (and on a host with no async loopback the queue only runs on a
	 * ~60s WP-Cron tick), so a chunk does as much work as the host's
	 * max_execution_time safely allows before rescheduling. Progress is
	 * persisted after every image, so a mid-chunk kill just resumes.
	 *
	 * @return int Seconds.
	 */
	public static function chunk_budget() {
		$max = (int) ini_get( 'max_execution_time' );

		if ( $max <= 0 || $max >= 130 ) {
			return 90;
		}

		return max( 15, (int) floor( $max * 0.7 ) );
	}

	/**
	 * Register the Action Scheduler callback. Called on every load (front,
	 * admin, cron) so in-flight chunks always have a handler.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_chunk' ) );
		add_filter( 'action_scheduler_queue_runner_time_limit', array( __CLASS__, 'as_time_limit' ) );
	}

	/**
	 * Give Action Scheduler's queue runner enough headroom to finish one of our
	 * chunks (default is 30s). Only ever raises the limit.
	 *
	 * @param int $seconds AS default.
	 * @return int
	 */
	public static function as_time_limit( $seconds ) {
		return max( (int) $seconds, self::chunk_budget() + 20 );
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'phase'            => 'idle', // idle|running|paused|complete
			'trigger'          => 'bulk',
			'scope'            => 'all',  // all|months
			'months'           => array(), // ordered newest→oldest, shrinks as months drain
			'months_planned'   => 0,
			'skip_converted'   => true,
			'cursor'           => array(
				'month'   => '',
				'last_id' => 0,
			),
			'total_candidates' => 0,
			'estimate'         => array(
				'saved_bytes' => 0,
				'webp_bytes'  => 0,
			),
			'processed'        => 0,
			'converted'        => 0,
			'failed'           => 0,
			'skipped_large'    => 0,
			'src_bytes'        => 0,
			'webp_bytes'       => 0,
			'saved_bytes'      => 0,
			'seconds'          => 0.0,
			'per_mp'           => 0.0, // EWMA seconds per megapixel
			'started_at'       => 0,
			'last_beat'        => 0,
			'finished_at'      => 0,
			'finish_reason'    => '', // complete|cancelled|stalled
			'emailed'          => false,
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
	 * @return bool True while a run is either working or paused.
	 */
	public static function is_active() {
		$phase = self::state()['phase'];

		return 'running' === $phase || 'paused' === $phase;
	}

	/**
	 * @return bool True when a running job's heartbeat has gone cold.
	 */
	public static function is_stale() {
		$state = self::state();

		return 'running' === $state['phase']
			&& ( time() - (int) $state['last_beat'] ) > self::STALE_AFTER;
	}

	/* ----- Lifecycle ----- */

	/**
	 * Begin a bulk run.
	 *
	 * @param array $args {
	 *     Run options.
	 *
	 *     @type string   $scope  all|months.
	 *     @type string[] $months Selected YM keys when scope = months.
	 * }
	 * @return array|false State, or false when there is nothing to do.
	 */
	public static function start( array $args ) {
		$scope          = ( isset( $args['scope'] ) && 'months' === $args['scope'] ) ? 'months' : 'all';
		$skip_converted = (bool) Settings::get( 'skip_converted' );

		$all_months = wp_list_pluck( Sections::months(), 'ym' ); // newest first

		$chosen = ( 'months' === $scope )
			? array_values( array_intersect( $all_months, (array) ( $args['months'] ?? array() ) ) )
			: $all_months;

		if ( empty( $chosen ) ) {
			return false;
		}

		$estimate = Estimator::project( 'months' === $scope ? $chosen : null, $skip_converted );

		$state = self::defaults();

		$state['phase']            = 'running';
		$state['trigger']          = 'bulk';
		$state['scope']            = $scope;
		$state['months']           = $chosen;
		$state['months_planned']   = count( $chosen );
		$state['skip_converted']   = $skip_converted;
		$state['cursor']           = array(
			'month'   => $chosen[0],
			'last_id' => 0,
		);
		$state['total_candidates'] = (int) $estimate['images'];
		$state['estimate']         = array(
			'saved_bytes' => (int) $estimate['saved_bytes'],
			'webp_bytes'  => (int) $estimate['webp_bytes'],
		);
		$state['started_at']       = time();
		$state['last_beat']        = time();

		self::save( $state );

		Failures::clear();
		self::enqueue();

		return $state;
	}

	/**
	 * Fold this run's measured images-per-second into the persistent pace
	 * estimate (EWMA) so the next run's "estimated time" reflects this server.
	 * Needs a meaningful sample.
	 *
	 * @param array $state Job state.
	 */
	private static function record_pace( array $state ) {
		if ( (int) $state['processed'] < 25 || (float) $state['seconds'] <= 0 ) {
			return;
		}

		$per_image = (float) $state['seconds'] / (int) $state['processed'];
		$previous  = (float) get_option( self::PACE_OPTION, 0 );
		$blended   = $previous > 0 ? ( ( 0.5 * $previous ) + ( 0.5 * $per_image ) ) : $per_image;

		update_option( self::PACE_OPTION, round( $blended, 3 ), false );
	}

	/**
	 * Clear a finished run's completion state back to idle (the operator has
	 * seen the summary and moved on). No-op unless phase is `complete`.
	 */
	public static function acknowledge_complete() {
		$state = self::state();

		if ( 'complete' === $state['phase'] ) {
			$state['phase'] = 'idle';
			self::save( $state );
		}
	}

	/**
	 * Pause - stop scheduling work, keep the cursor.
	 */
	public static function pause() {
		$state = self::state();

		if ( 'running' !== $state['phase'] ) {
			return;
		}

		self::record_pace( $state );
		$state['phase'] = 'paused';
		self::save( $state );
		self::unschedule();
	}

	/**
	 * Resume a paused or stalled run from its cursor.
	 */
	public static function resume() {
		$state = self::state();

		if ( 'paused' !== $state['phase'] && ! self::is_stale() ) {
			return;
		}

		$state['phase']     = 'running';
		$state['last_beat'] = time();
		self::save( $state );
		self::enqueue();
	}

	/**
	 * Cancel - stop future work, drop the cursor. Converted files are kept.
	 */
	public static function cancel() {
		$state = self::state();

		if ( 'idle' === $state['phase'] ) {
			return;
		}

		$partial  = $state;
		$was_bulk = 'bulk' === $state['trigger'];

		self::record_pace( $state );

		$state['phase']         = 'idle';
		$state['cursor']        = array(
			'month'   => '',
			'last_id' => 0,
		);
		$state['months']        = array();
		$state['finished_at']   = time();
		$state['finish_reason'] = 'cancelled';

		self::save( $state );
		self::unschedule();

		if ( $was_bulk && ! $partial['emailed'] && $partial['processed'] > 0 && Settings::get( 'email_report' ) ) {
			Mailer::send_report( $partial, 'cancelled' );
		}

		Scan::mark_stale();
	}

	/**
	 * Called on every monitor page load while a run is active. Belt-and-braces
	 * for hosts where the async loopback is blocked or an async request got
	 * lost: make sure a chunk action is queued, and poke WP-Cron so it actually
	 * runs on this request's traffic. Cheap - one AS lookup.
	 */
	public static function nudge() {
		if ( 'running' !== self::state()['phase'] ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' )
			&& false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/* ----- The chunk ----- */

	/**
	 * Process one chunk. Action Scheduler callback for self::HOOK.
	 */
	public static function run_chunk() {
		if ( Settings::get( 'disabled' ) ) {
			return;
		}

		$state = self::state();

		if ( 'running' !== $state['phase'] ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'image' );

		$state['last_beat'] = time();
		self::save( $state );

		$ceiling = self::megapixel_ceiling();
		$budget  = self::chunk_budget();
		$started = microtime( true );
		$mp_done = 0.0;
		$count   = 0;
		$drained = false;

		while ( $count < self::MAX_PER_CHUNK && ( microtime( true ) - $started ) < $budget ) {
			$ids = self::advance( $state );

			if ( empty( $ids ) ) {
				$drained = true;
				break;
			}

			foreach ( $ids as $id ) {
				$state['cursor']['last_id'] = $id;

				$mp = self::megapixels( $id );

				if ( $ceiling > 0 && $mp > $ceiling ) {
					self::mark_skipped_large( $id, $mp, $ceiling );
					++$state['processed'];
					++$state['skipped_large'];
				} else {
					try {
						// "Skip already-converted" off ⇒ force a re-encode.
						$result = Converter::convert_attachment( $id, ! $state['skip_converted'] );
					} catch ( \Throwable $e ) {
						$result = array(
							'status'     => 'failed',
							'error'      => $e->getMessage(),
							'converted'  => 0,
							'src_bytes'  => 0,
							'webp_bytes' => 0,
						);
					}

					self::absorb( $state, $id, $result );

					++$state['processed'];
					$mp_done += $mp;
				}

				++$count;
				$state['last_beat'] = time();
				self::save( $state );

				if ( $count >= self::MAX_PER_CHUNK || ( microtime( true ) - $started ) >= $budget ) {
					break 2;
				}
			}
		}

		$elapsed           = microtime( true ) - $started;
		$state['seconds'] += $elapsed;

		if ( $mp_done > 0.5 ) {
			$rate            = $elapsed / $mp_done;
			$state['per_mp'] = $state['per_mp'] > 0 ? ( ( 0.7 * $state['per_mp'] ) + ( 0.3 * $rate ) ) : $rate;
		}

		if ( $drained ) {
			self::finish( $state, 'complete' );
			return;
		}

		self::save( $state );
		self::enqueue();
	}

	/**
	 * Next slice of candidate IDs, advancing (and dropping) drained months.
	 * Returns [] only when every remaining month is exhausted.
	 *
	 * @param array $state Job state, mutated in place.
	 * @return int[]
	 */
	private static function advance( array &$state ) {
		while ( '' !== $state['cursor']['month'] ) {
			$ids = Sections::pending_ids(
				$state['cursor']['month'],
				$state['cursor']['last_id'],
				self::DB_BATCH,
				$state['skip_converted']
			);

			if ( ! empty( $ids ) ) {
				return $ids;
			}

			$state['months'] = array_values(
				array_diff( $state['months'], array( $state['cursor']['month'] ) )
			);
			$state['cursor'] = array(
				'month'   => $state['months'][0] ?? '',
				'last_id' => 0,
			);
		}

		return array();
	}

	/**
	 * Fold one conversion result into the live counters and the failures index.
	 *
	 * @param array $state  Job state, mutated in place.
	 * @param int   $id     Attachment ID.
	 * @param array $result Converter::convert_attachment() return.
	 */
	private static function absorb( array &$state, $id, array $result ) {
		$state['converted']  += (int) ( $result['converted'] ?? 0 );
		$state['src_bytes']  += (int) ( $result['src_bytes'] ?? 0 );
		$state['webp_bytes'] += (int) ( $result['webp_bytes'] ?? 0 );

		$saved = (int) ( $result['src_bytes'] ?? 0 ) - (int) ( $result['webp_bytes'] ?? 0 );
		if ( $saved > 0 ) {
			$state['saved_bytes'] += $saved;
		}

		if ( 'failed' === ( $result['status'] ?? '' ) ) {
			++$state['failed'];
			Failures::record( $id, ( ! empty( $result['error'] ) ? $result['error'] : 'conversion failed' ), 'failed' );
		} else {
			Failures::clear_one( $id );
		}
	}

	/**
	 * Finalise: write tallies, mark the scan stale, unschedule, send the email.
	 *
	 * @param array  $state  Job state.
	 * @param string $reason complete|cancelled|stalled.
	 */
	private static function finish( array $state, $reason ) {
		$state['phase']         = 'complete';
		$state['finished_at']   = time();
		$state['finish_reason'] = $reason;
		$state['cursor']        = array(
			'month'   => '',
			'last_id' => 0,
		);

		$send = ! $state['emailed']
			&& 'bulk' === $state['trigger']
			&& Settings::get( 'email_report' );

		if ( $send ) {
			$state['emailed'] = true;
		}

		self::record_pace( $state );
		self::save( $state );
		self::unschedule();

		// Refresh the library figures now, in the worker - the completion screen
		// then shows exact numbers with no "scan again" step. Cheap (grouped
		// COUNTs + two SUMs + a small sample), and we are off the request path.
		Scan::run();

		if ( $send ) {
			Mailer::send_report( self::state(), $reason );
		}
	}

	/* ----- Scheduling helpers ----- */

	/**
	 * Enqueue a chunk if none is pending.
	 */
	private static function enqueue() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Drop every scheduled chunk.
	 */
	private static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/* ----- Chunk-packing maths ----- */

	/**
	 * The megapixel ceiling in force: the Settings override, or the computed
	 * safe value for this server.
	 *
	 * @return int Megapixels (0 = no ceiling).
	 */
	private static function megapixel_ceiling() {
		$override = (int) Settings::get( 'skip_megapixels' );

		return $override > 0 ? $override : Environment::safe_megapixels();
	}

	/**
	 * Full-size megapixels from stored metadata - no file read.
	 *
	 * @param int $id Attachment ID.
	 * @return float
	 */
	private static function megapixels( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$w    = (int) ( $meta['width'] ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );

		return ( $w > 0 && $h > 0 ) ? ( $w * $h ) / 1000000 : 0.0;
	}

	/**
	 * Record an attachment as skipped - too large - and settle its meta so the
	 * runner never comes back to it (the override, a retry, or more memory
	 * clear it).
	 *
	 * @param int   $id      Attachment ID.
	 * @param float $mp      Its megapixels.
	 * @param int   $ceiling The ceiling it exceeded.
	 */
	private static function mark_skipped_large( $id, $mp, $ceiling ) {
		$signature = Settings::signature();

		update_post_meta(
			$id,
			Converter::META,
			array(
				'status'    => 'skipped',
				'sizes'     => array(),
				'error'     => 'too large for this server',
				'signature' => $signature,
				'ts'        => time(),
			)
		);
		update_post_meta( $id, Converter::META_SIG, $signature );
		delete_post_meta( $id, Converter::META_SAVED );
		delete_post_meta( $id, Converter::META_WEBP );

		Failures::record(
			$id,
			sprintf( 'too large for this server - %.1f MP over the %d MP ceiling', $mp, $ceiling ),
			'too_large'
		);
	}

	/* ----- Progress (monitor poll + view) ----- */

	/**
	 * Everything the monitor UI and the progress poll need.
	 *
	 * @return array
	 */
	public static function progress() {
		$state = self::state();

		$total     = max( (int) $state['total_candidates'], (int) $state['processed'] );
		$remaining = max( 0, $total - (int) $state['processed'] );

		// Wall-clock pace (includes the gaps between chunks), so the ETA
		// reflects how fast this is really going, not just encode time.
		$elapsed   = ( $state['started_at'] > 0 && 'complete' !== $state['phase'] )
			? max( 1, time() - (int) $state['started_at'] )
			: (float) $state['seconds'];
		$per_image = $state['processed'] > 0 ? ( $elapsed / $state['processed'] ) : 0.0;
		$encode    = $state['processed'] > 0 ? ( $state['seconds'] / $state['processed'] ) : 0.0;

		$percent = $total > 0 ? (int) round( $state['processed'] / $total * 100 ) : 0;
		if ( 'complete' === $state['phase'] ) {
			$percent = 100;
		}
		$percent = min( 100, max( 0, $percent ) );

		$fail = Failures::counts();

		return array(
			'phase'         => $state['phase'],
			'stalled'       => self::is_stale(),
			'trigger'       => $state['trigger'],
			'month'         => $state['cursor']['month'],
			'processed'     => (int) $state['processed'],
			'converted'     => (int) $state['converted'],
			'total'         => $total,
			'remaining'     => $remaining,
			'percent'       => $percent,
			'failed'        => $fail['failed'],
			'too_large'     => $fail['too_large'],
			'saved_bytes'   => (int) $state['saved_bytes'],
			'webp_bytes'    => (int) $state['webp_bytes'],
			'src_bytes'     => (int) $state['src_bytes'],
			'rate'          => $encode > 0 ? round( 1 / $encode, 1 ) : 0,
			'eta_seconds'   => (int) round( $remaining * $per_image ),
			'started_at'    => (int) $state['started_at'],
			'finished_at'   => (int) $state['finished_at'],
			'finish_reason' => $state['finish_reason'],
			'estimate'      => $state['estimate'],
		);
	}

	/**
	 * Reset to a clean idle state (used by "Remove all WebP" / uninstall paths).
	 */
	public static function reset() {
		self::unschedule();
		self::save( self::defaults() );
	}
}
