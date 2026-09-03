<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fast-mode (tab-driven) pacing policy.
 *
 * Background runs are paced by Action Scheduler. Fast runs are pumped by the
 * browser calling `Runner::fast_step()` in a loop, so the pacing has to live
 * here: how big each browser-pumped batch is, and when the host is pushing back
 * hard enough that the run should pause itself and wait before probing again.
 *
 * Pure arithmetic on a per-batch sample - nothing here touches job state, the
 * database or the filesystem.
 */
class Throttle {

	/** Auto-pause cooldown ladder (seconds), indexed by prior throttle hits. */
	const COOLDOWNS = array( 30, 120, 300, 600 );

	/**
	 * Once the cooldown ladder is maxed and this many further hits land, tell the
	 * operator to switch to Background mode instead of retrying forever.
	 */
	const GIVE_UP_AFTER = 3;

	/**
	 * Intensity profile: the per-request wall budget, the gap the browser waits
	 * between requests (the duty cycle - this is what keeps average CPU well
	 * under 100%), and the image-count cap for one request.
	 *
	 * @param string $intensity gentle|balanced|turbo.
	 * @return array{budget:float,gap:int,batch_cap:int}
	 */
	public static function profile( $intensity ) {
		switch ( $intensity ) {
			case 'gentle':
				return array(
					'budget'    => 8.0,
					'gap'       => 10,
					'batch_cap' => 8,
				);
			case 'turbo':
				return array(
					'budget'    => 18.0,
					'gap'       => 1,
					'batch_cap' => 20,
				);
			case 'balanced':
			default:
				return array(
					'budget'    => 12.0,
					'gap'       => 3,
					'batch_cap' => 14,
				);
		}
	}

	/**
	 * Normalise an intensity string to one of the three known profiles.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function intensity( $value ) {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, array( 'gentle', 'balanced', 'turbo' ), true ) ? $value : 'balanced';
	}

	/**
	 * Grow or shrink the batch to hover near the wall budget while staying under
	 * ~70% of the memory limit. Ported from the pre-Action-Scheduler runner.
	 *
	 * @param int   $current Batch size just used.
	 * @param float $elapsed Seconds it took.
	 * @param int   $peak    Peak memory bytes this request.
	 * @param int   $limit   Memory limit bytes (0 = unknown/unlimited).
	 * @param array $profile self::profile() result.
	 * @return int
	 */
	public static function next_batch_size( $current, $elapsed, $peak, $limit, array $profile ) {
		$budget  = (float) $profile['budget'];
		$cap     = (int) $profile['batch_cap'];
		$current = max( 1, (int) $current );
		$mem_hot = $limit > 0 && $peak > ( $limit * 0.7 );

		if ( $mem_hot || $elapsed > ( $budget * 1.4 ) ) {
			return max( 1, (int) floor( $current / 2 ) );
		}

		if ( $elapsed < ( $budget * 0.6 ) ) {
			return min( $cap, $current + max( 1, (int) floor( $current / 2 ) ) );
		}

		return min( $cap, $current );
	}

	/**
	 * Should the run pause itself now? Compares this batch's per-image wall time
	 * against the run's learned average, and checks memory headroom.
	 *
	 * @param array   $state     Job state (reads `seconds`, `processed`, `throttle_hits`).
	 * @param float[] $img_times Per-image wall seconds for this batch.
	 * @param int     $peak      Peak memory bytes this request.
	 * @param int     $limit     Memory limit bytes (0 = unknown).
	 * @return array{pause:bool,cooldown:int,give_up:bool,healthy:bool}
	 */
	public static function assess( array $state, array $img_times, $peak, $limit ) {
		$hits     = max( 0, (int) ( $state['throttle_hits'] ?? 0 ) );
		$max_step = count( self::COOLDOWNS ) - 1;
		$cooldown = self::COOLDOWNS[ min( $hits, $max_step ) ];
		$give_up  = $hits >= ( $max_step + self::GIVE_UP_AFTER );

		$count = count( $img_times );

		// Too small a sample to judge either way.
		if ( $count < 2 ) {
			return array(
				'pause'    => false,
				'cooldown' => $cooldown,
				'give_up'  => false,
				'healthy'  => false,
			);
		}

		$avg_img   = array_sum( $img_times ) / $count;
		$processed = (int) ( $state['processed'] ?? 0 );
		$seconds   = (float) ( $state['seconds'] ?? 0 );

		// Baseline expectation: the run's lifetime average encode seconds per
		// image, once there's enough history to trust it. Otherwise this batch.
		$baseline = ( $seconds > 0 && $processed > $count )
			? ( $seconds / $processed )
			: $avg_img;

		$mem_hot   = $limit > 0 && $peak > ( $limit * 0.85 );
		$cpu_choke = $baseline > 0 && $avg_img > ( $baseline * 2.5 );

		if ( $mem_hot || $cpu_choke ) {
			return array(
				'pause'    => true,
				'cooldown' => $cooldown,
				'give_up'  => $give_up,
				'healthy'  => false,
			);
		}

		// A comfortably-paced batch with memory headroom: let the hit counter
		// decay so a one-off spike doesn't ratchet the cooldown forever.
		$healthy = $baseline > 0
			&& $avg_img < ( $baseline * 1.5 )
			&& ( 0 === $limit || $peak < ( $limit * 0.7 ) );

		return array(
			'pause'    => false,
			'cooldown' => $cooldown,
			'give_up'  => false,
			'healthy'  => $healthy,
		);
	}
}
