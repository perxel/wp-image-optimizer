<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns cached scan data into a "this run" projection: image count and estimated
 * time, plus an upper-bound bandwidth / disk projection.
 *
 * Deliberately just arithmetic on Scan::data() - assets/admin.js mirrors the
 * image count and the ETA so the prepare screen updates as months are ticked
 * without a round-trip. The scan no longer tracks which images are already
 * converted, so `images` counts every image in scope and the byte figures are an
 * "up to" ceiling; the moment a run starts, live measured numbers replace them.
 */
class Estimator {

	/**
	 * @param string[]|null $months YM keys to include; null = every month in the
	 *                              scan.
	 * @return array{images:int,eta_seconds:int,saved_bytes:int,webp_bytes:int,percent:int}
	 */
	public static function project( $months = null ) {
		$scan = Scan::data();

		$empty = array(
			'images'      => 0,
			'eta_seconds' => 0,
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
			$images += (int) ( $month['total'] ?? 0 );
		}

		if ( $images < 1 ) {
			return $empty;
		}

		$avg_src   = (int) ( $scan['avg_src'] ?? 0 );
		$avg_frac  = (float) ( $scan['avg_frac'] ?? 0.7 );
		$per_image = (float) ( $scan['per_image'] ?? 1 );

		// Upper bound: assume every image in scope needs a fresh conversion.
		// Already-converted images add ~no new saving, so the live run comes in
		// under this.
		$src_total  = $images * $avg_src;
		$webp_total = (int) round( $src_total * $avg_frac );
		$saved      = (int) max( 0, $src_total - $webp_total );

		return array(
			'images'      => $images,
			'eta_seconds' => (int) round( $images * $per_image ),
			'saved_bytes' => $saved,
			'webp_bytes'  => $webp_total,
			'percent'     => $src_total > 0 ? (int) round( $saved / $src_total * 100 ) : 0,
		);
	}
}
