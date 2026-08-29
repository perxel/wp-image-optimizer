<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The light, on-demand library scan behind the prepare screen.
 *
 * A page visit never walks the library (§5). The "Scan library" button runs
 * this: a handful of grouped `COUNT()` queries plus a ~120-attachment metadata
 * sample — no image decode, no file writes. The result is cached in one option
 * and drives the month list and the "This run" estimate.
 *
 * This is NOT the authoritative recalc (Metrics::recalculate(), which walks
 * every attachment and the uploads dir for orphan .webp) — that stays rare.
 */
class Scan {

	const OPTION      = 'perxel_image_optimizer_scan';
	const SAMPLE_SIZE = 120;
	const TTL         = DAY_IN_SECONDS;

	/**
	 * Cached scan data, or an empty array when never scanned.
	 *
	 * @return array
	 */
	public static function data() {
		$data = get_option( self::OPTION, array() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return bool
	 */
	public static function has_run() {
		$data = self::data();

		return ! empty( $data['scanned_at'] );
	}

	/**
	 * Stale = never scanned, explicitly flagged (a run finished, settings were
	 * saved), the settings signature moved, or older than the TTL.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$data = self::data();

		if ( empty( $data['scanned_at'] ) || ! empty( $data['stale'] ) ) {
			return true;
		}

		if ( ( $data['signature'] ?? '' ) !== Settings::signature() ) {
			return true;
		}

		return ( time() - (int) $data['scanned_at'] ) > self::TTL;
	}

	/**
	 * Flag the cached scan as stale without discarding its numbers (still shown,
	 * with a "scan again" hint).
	 */
	public static function mark_stale() {
		$data = self::data();

		if ( $data ) {
			$data['stale'] = true;
			update_option( self::OPTION, $data, false );
		}
	}

	/**
	 * Drop the cache entirely.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Run the scan and cache it.
	 *
	 * @return array The stored data.
	 */
	public static function run() {
		global $wpdb;

		$signature = Settings::signature();

		// YEAR()/MONTH() rather than DATE_FORMAT() so the pending query survives
		// wpdb::prepare() (which mangles a literal `%Y`).
		$totals = $wpdb->get_results(
			"SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(*) AS c
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'
			   AND post_mime_type IN ( 'image/jpeg', 'image/png' )
			 GROUP BY y, m"
		);

		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT YEAR(p.post_date) AS y, MONTH(p.post_date) AS m, COUNT(*) AS c
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} sig
				   ON sig.post_id = p.ID AND sig.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
				   AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
				   AND ( sig.meta_id IS NULL OR sig.meta_value <> %s )
				 GROUP BY y, m",
				Converter::META_SIG,
				$signature
			)
		);

		$pending_by_ym = array();
		foreach ( (array) $pending as $row ) {
			$pending_by_ym[ sprintf( '%04d-%02d', $row->y, $row->m ) ] = (int) $row->c;
		}

		$months        = array();
		$total_all     = 0;
		$total_pending = 0;

		foreach ( (array) $totals as $row ) {
			$ym    = sprintf( '%04d-%02d', $row->y, $row->m );
			$count = (int) $row->c;
			$due   = isset( $pending_by_ym[ $ym ] ) ? $pending_by_ym[ $ym ] : 0;

			$months[ $ym ]  = array(
				'total'   => $count,
				'pending' => $due,
			);
			$total_all     += $count;
			$total_pending += $due;
		}

		krsort( $months );

		list( $avg_src, $avg_frac ) = self::sample();

		$data = array(
			'months'     => $months,
			'total'      => $total_all,
			'pending'    => $total_pending,
			'avg_src'    => $avg_src,
			'avg_frac'   => $avg_frac,
			'scanned_at' => time(),
			'signature'  => $signature,
			'stale'      => false,
		);

		update_option( self::OPTION, $data, false );

		return $data;
	}

	/**
	 * Sample attachment metadata to estimate the average source bytes per
	 * pending image and the average WebP size fraction.
	 *
	 * Source bytes come from `_wp_attachment_metadata` (WP ≥ 6.0 stores
	 * `filesize`) — no file reads. The WebP fraction comes from real conversion
	 * data when there is any, otherwise public defaults.
	 *
	 * @return array{0:int,1:float} [ avg source bytes, avg webp fraction ].
	 */
	private static function sample() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_status = 'inherit'
				   AND post_mime_type IN ( 'image/jpeg', 'image/png' )
				 ORDER BY RAND()
				 LIMIT %d",
				self::SAMPLE_SIZE
			)
		);

		$sum   = array(
			'image/jpeg' => 0,
			'image/png'  => 0,
		);
		$count = array(
			'image/jpeg' => 0,
			'image/png'  => 0,
		);

		foreach ( (array) $ids as $id ) {
			$id   = (int) $id;
			$mime = get_post_mime_type( $id );

			if ( ! isset( $sum[ $mime ] ) ) {
				continue;
			}

			$meta  = wp_get_attachment_metadata( $id );
			$bytes = 0;

			if ( is_array( $meta ) ) {
				$bytes += (int) ( $meta['filesize'] ?? 0 );

				foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
					$bytes += (int) ( $size['filesize'] ?? 0 );
				}
			}

			if ( $bytes > 0 ) {
				$sum[ $mime ] += $bytes;
				++$count[ $mime ];
			}
		}

		$total_n = $count['image/jpeg'] + $count['image/png'];
		$avg_src = $total_n > 0
			? (int) round( ( $sum['image/jpeg'] + $sum['image/png'] ) / $total_n )
			: 0;

		// WebP size as a fraction of source, weighted by the sampled mime mix.
		$report = Metrics::report();
		if ( (int) $report['converted_files'] > 0 && (int) $report['served_before'] > 0 ) {
			$frac = $report['served_after'] / $report['served_before'];
		} else {
			$jpeg_frac = 0.70; // ~30% smaller
			$png_frac  = extension_loaded( 'imagick' ) ? 0.78 : 0.50;
			$frac      = $total_n > 0
				? ( ( $jpeg_frac * $count['image/jpeg'] ) + ( $png_frac * $count['image/png'] ) ) / $total_n
				: $jpeg_frac;
		}

		return array( $avg_src, max( 0.05, min( 0.95, (float) $frac ) ) );
	}
}
