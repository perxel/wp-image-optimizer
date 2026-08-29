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

	/** Seconds without a heartbeat before a running job counts as stalled. */
	const STALE_AFTER = 300;

	/** Hard ceiling on images per chunk — catches "thousands of tiny images". */
	const MAX_PER_CHUNK = 50;

	/** Soft wall-time budget per chunk, well under any proxy / php-fpm kill. */
	const CHUNK_SECONDS = 15;

	/** DB page size when pulling candidate IDs for the current month. */
	const DB_BATCH = 100;

	/**
	 * Register the Action Scheduler callback. Called on every load (front,
	 * admin, cron) so in-flight chunks always have a handler.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_chunk' ) );
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
	 *     @type string   $scope          all|months.
	 *     @type string[] $months         Selected YM keys when scope = months.
	 *     @type bool     $skip_converted Skip already-converted images (default true).
	 * }
	 * @return array|false State, or false when there is nothing to do.
	 */
	public static function start( array $args ) {
		$scope          = ( isset( $args['scope'] ) && 'months' === $args['scope'] ) ? 'months' : 'all';
		$skip_converted = ! isset( $args['skip_converted'] ) || ! empty( $args['skip_converted'] );

		$all_months = wp_list_pluck( Sections::months(), 'ym' ); // newest → oldest

		$chosen = ( 'months' === $scope )
			? array_values( array_intersect( $all_months, (array) ( $args['months'] ?? array() ) ) )
			: $all_months;

		if ( empty( $chosen ) ) {
			return false;
		}

		$estimate = Estimator::project( 'months' === $scope ? $chosen : null );

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
		$state['total_candidates'] = self::candidate_total( $scope, $chosen, $skip_converted, (int) $estimate['images'] );
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
	 * Expected images to process: pending-only from the scan estimate, or the
	 * full month totals when "skip already-converted" is off.
	 *
	 * @param string   $scope          all|months.
	 * @param string[] $chosen         Selected YM keys.
	 * @param bool     $skip_converted Whether converted images are skipped.
	 * @param int      $fallback       Estimator pending count.
	 * @return int
	 */
	private static function candidate_total( $scope, $chosen, $skip_converted, $fallback ) {
		if ( $skip_converted ) {
			return $fallback;
		}

		$total  = 0;
		$months = Scan::data()['months'] ?? array();

		foreach ( $months as $ym => $month ) {
			if ( 'all' === $scope || in_array( $ym, $chosen, true ) ) {
				$total += (int) $month['total'];
			}
		}

		return $total > 0 ? $total : $fallback;
	}

	/**
	 * Pause — stop scheduling work, keep the cursor.
	 */
	public static function pause() {
		$state = self::state();

		if ( 'running' !== $state['phase'] ) {
			return;
		}

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
	 * Cancel — stop future work, drop the cursor. Converted files are kept.
	 */
	public static function cancel() {
		$state = self::state();

		if ( 'idle' === $state['phase'] ) {
			return;
		}

		$partial  = $state;
		$was_bulk = 'bulk' === $state['trigger'];

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
	 * Called on a Status page load: if a run is stalled and nothing is
	 * scheduled, kick a fresh chunk. Cheap — one AS lookup.
	 */
	public static function nudge() {
		if ( ! self::is_stale() ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' )
			&& false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
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
		$started = microtime( true );
		$mp_done = 0.0;
		$count   = 0;
		$drained = false;

		while ( $count < self::MAX_PER_CHUNK && ( microtime( true ) - $started ) < self::CHUNK_SECONDS ) {
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
					Metrics::apply( $result );

					++$state['processed'];
					$mp_done += $mp;
				}

				++$count;
				$state['last_beat'] = time();
				self::save( $state );

				if ( $count >= self::MAX_PER_CHUNK || ( microtime( true ) - $started ) >= self::CHUNK_SECONDS ) {
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

		self::save( $state );
		self::unschedule();
		Scan::mark_stale();

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
	 * Full-size megapixels from stored metadata — no file read.
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
	 * Record an attachment as skipped — too large — and settle its meta so the
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

		Failures::record(
			$id,
			sprintf( 'too large for this server — %.1f MP over the %d MP ceiling', $mp, $ceiling ),
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

		$planned          = max( 1, (int) $state['months_planned'] );
		$remaining_months = count( $state['months'] );
		$position         = $planned - $remaining_months + ( 'complete' === $state['phase'] ? 0 : 1 );
		$position         = max( 1, min( $planned, $position ) );

		$total     = max( (int) $state['total_candidates'], (int) $state['processed'] );
		$remaining = max( 0, $total - (int) $state['processed'] );
		$per_image = $state['processed'] > 0 ? ( $state['seconds'] / $state['processed'] ) : 0.0;

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
			'month_label'   => self::month_label( $state['cursor']['month'] ),
			'month_pos'     => $position,
			'months_total'  => $planned,
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
			'rate'          => $per_image > 0 ? round( 1 / $per_image, 1 ) : 0,
			'eta_seconds'   => (int) round( $remaining * $per_image ),
			'started_at'    => (int) $state['started_at'],
			'finished_at'   => (int) $state['finished_at'],
			'finish_reason' => $state['finish_reason'],
			'estimate'      => $state['estimate'],
			'projected'     => self::projection( $state ),
		);
	}

	/**
	 * Extrapolate the measured saving so far to the whole run.
	 *
	 * @param array $state Job state.
	 * @return array{saved_bytes:int,webp_bytes:int,percent:int}
	 */
	private static function projection( array $state ) {
		if ( $state['src_bytes'] <= 0 || $state['processed'] <= 0 ) {
			return array(
				'saved_bytes' => (int) $state['estimate']['saved_bytes'],
				'webp_bytes'  => (int) $state['estimate']['webp_bytes'],
				'percent'     => 0,
			);
		}

		$total  = max( (int) $state['total_candidates'], (int) $state['processed'] );
		$factor = $total / max( 1, (int) $state['processed'] );

		$saved   = (int) round( $state['saved_bytes'] * $factor );
		$webp    = (int) round( $state['webp_bytes'] * $factor );
		$percent = (int) round( ( $state['src_bytes'] - $state['webp_bytes'] ) / $state['src_bytes'] * 100 );

		return array(
			'saved_bytes' => $saved,
			'webp_bytes'  => $webp,
			'percent'     => max( 0, $percent ),
		);
	}

	/**
	 * @param string $ym 'YYYY-MM'.
	 * @return string Localised "July 2024", or '' for an empty cursor.
	 */
	public static function month_label( $ym ) {
		if ( '' === (string) $ym ) {
			return '';
		}

		$ts = strtotime( $ym . '-01' );

		return $ts ? date_i18n( 'F Y', $ts ) : $ym;
	}

	/**
	 * Reset to a clean idle state (used by "Remove all WebP" / uninstall paths).
	 */
	public static function reset() {
		self::unschedule();
		self::save( self::defaults() );
	}
}
