<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns cached scan data into a "this run" projection: image count, bandwidth
 * saved, disk added.
 *
 * Deliberately just arithmetic on Scan::data() — assets/admin.js mirrors the
 * same maths so the prepare screen updates the figures as months are ticked
 * without a round-trip. The moment a run starts, live measured numbers replace
 * the estimate.
 */
class Estimator {

	/**
	 * @param string[]|null $months YM keys to include; null = every pending
	 *                      month in the scan.
	 * @return array{images:int,saved_bytes:int,webp_bytes:int,percent:int}
	 */
	public static function project( $months = null ) {
		$scan = Scan::data();

		$empty = array(
			'images'      => 0,
			'saved_bytes' => 0,
			'webp_bytes'  => 0,
			'percent'     => 0,
		);

		if ( empty( $scan['months'] ) ) {
			return $empty;
		}

		$images = 0;
		foreach ( $scan['months'] as $ym => $month ) {
			if ( null !== $months && ! in_array( $ym, (array) $months, true ) ) {
				continue;
			}
			$images += (int) $month['pending'];
		}

		if ( $images < 1 ) {
			return $empty;
		}

		$avg_src  = (int) ( $scan['avg_src'] ?? 0 );
		$avg_frac = (float) ( $scan['avg_frac'] ?? 0.7 );

		$src_total  = $images * $avg_src;
		$webp_total = (int) round( $src_total * $avg_frac );
		$saved      = (int) max( 0, $src_total - $webp_total );

		return array(
			'images'      => $images,
			'saved_bytes' => $saved,
			'webp_bytes'  => $webp_total,
			'percent'     => $src_total > 0 ? (int) round( $saved / $src_total * 100 ) : 0,
		);
	}
}
