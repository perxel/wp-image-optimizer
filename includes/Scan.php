<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one function that produces every library-wide figure the admin screens
 * show - coverage, exact bytes saved, exact bytes on disk - and the per-month
 * breakdown behind the prepare screen.
 *
 * The Optimization page refreshes it on load when the cache has gone stale
 * (settings saved, a run finished, or older than a day) - never on other admin
 * pages. It is all cheap SQL: a grouped `COUNT()` per month, two `SUM()` over the
 * flat `_perxel_image_optimizer_saved` / `_perxel_image_optimizer_webp` meta keys
 * (written by `Converter` on every convert / remove), plus a ~120-attachment
 * `_wp_attachment_metadata` sample for the pre-run size estimate. No image
 * decode, no file reads, no library walk, and - since it no longer hunts
 * per-attachment "pending" state - no per-row meta join. Result cached in one
 * option.
 *
 * The byte totals are exact for everything converted by this plugin. WebP files
 * left by other tools that were never recorded aren't counted - there is no
 * record of them to sum, and the purge flow already reports those.
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
	 * Derived display figures for the "At a glance" tiles.
	 *
	 * @return array{
	 *   scanned:bool, scanned_at:int, stale:bool,
	 *   attachments:int, converted:int, settled:int, coverage_pct:int,
	 *   saved_bytes:int, webp_bytes:int, src_bytes:int, saved_pct:int
	 * }
	 */
	public static function stats() {
		$d = self::data();

		$total     = (int) ( $d['total'] ?? 0 );
		$converted = (int) ( $d['converted'] ?? 0 );
		$settled   = (int) ( $d['settled'] ?? 0 );
		$saved     = (int) ( $d['saved_bytes'] ?? 0 );
		$webp      = (int) ( $d['webp_bytes'] ?? 0 );
		$src       = $saved + $webp;

		return array(
			'scanned'      => ! empty( $d['scanned_at'] ),
			'scanned_at'   => (int) ( $d['scanned_at'] ?? 0 ),
			'stale'        => ! empty( $d['stale'] ),
			'attachments'  => $total,
			'converted'    => $converted,
			'settled'      => $settled,
			'coverage_pct' => $total > 0 ? min( 100, (int) round( $settled / $total * 100 ) ) : 0,
			'saved_bytes'  => $saved,
			'webp_bytes'   => $webp,
			'src_bytes'    => $src,
			'saved_pct'    => $src > 0 ? (int) round( $saved / $src * 100 ) : 0,
		);
	}

	/**
	 * Run the scan and cache it.
	 *
	 * @return array The stored data.
	 */
	public static function run() {
		global $wpdb;

		$signature = Settings::signature();

		// The single source of every library-wide figure: a handful of grouped
		// COUNT()/SUM() aggregates with no WP_Query equivalent. The Optimization
		// page runs it on load only when the cache has gone stale. The whole
		// result set is cached in the `perxel_image_optimizer_scan` option (see
		// update_option below), which is the intended cache layer - per-row
		// object caching here would be redundant.
		//
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$totals = $wpdb->get_results(
			"SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(*) AS c
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'
			   AND post_mime_type IN ( 'image/jpeg', 'image/png' )
			 GROUP BY y, m"
		);

		$months    = array();
		$total_all = 0;

		foreach ( (array) $totals as $row ) {
			$ym    = sprintf( '%04d-%02d', $row->y, $row->m );
			$count = (int) $row->c;

			$months[ $ym ] = array( 'total' => $count );
			$total_all    += $count;
		}

		krsort( $months );

		// Exact library-wide byte totals - one indexed SUM per flat meta key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate scan query, see the note at the top of run().
		$saved_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s", Converter::META_SAVED )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate scan query, see the note at the top of run().
		$webp_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s", Converter::META_WEBP )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate scan query, see the note at the top of run().
		$converted = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Converter::META_WEBP )
		);
		// Attachments settled under the current settings - done, no-gain, or a
		// deterministic skip (PNG conversion off, too large). The signal the
		// runner writes and the "whole library handled" check reads.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate scan query, see the note at the top of run().
		$settled = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				Converter::META_SIG,
				$signature
			)
		);

		list( $avg_src, $fallback_frac, $avg_mp, $avg_files ) = self::sample();

		$measured_frac = $webp_total > 0;
		$avg_frac      = $measured_frac
			? $webp_total / ( $saved_total + $webp_total )
			: $fallback_frac;

		// Seconds per attachment: what past runs measured on this server, else a
		// deliberately pessimistic guess.
		$pace          = (float) get_option( Runner::PACE_OPTION, 0 );
		$measured_pace = $pace > 0;
		$per_image     = $measured_pace ? $pace : self::guess_per_image( $avg_mp, $avg_files );

		$data = array(
			'months'        => $months,
			'total'         => $total_all,
			'converted'     => $converted,
			'settled'       => min( $settled, $total_all ),
			'saved_bytes'   => $saved_total,
			'webp_bytes'    => $webp_total,
			'avg_src'       => $avg_src,
			'avg_frac'      => max( 0.05, min( 0.95, (float) $avg_frac ) ),
			'frac_source'   => $measured_frac ? 'measured' : 'default',
			'per_image'     => round( $per_image, 3 ),
			'pace_measured' => $measured_pace,
			'scanned_at'    => time(),
			'signature'     => $signature,
			'stale'         => false,
		);

		update_option( self::OPTION, $data, false );

		return $data;
	}

	/**
	 * Sample attachment metadata for the pre-run size estimate: average source
	 * bytes per attachment, plus a public-default WebP fraction to fall back on
	 * before there is any real conversion data.
	 *
	 * Source bytes come from `_wp_attachment_metadata` (WP ≥ 6.0 stores
	 * `filesize`) - no file reads. Also returns the average megapixels and the
	 * average number of size files per attachment, used to guess conversion
	 * speed before a run has measured it.
	 *
	 * @return array{0:int,1:float,2:float,3:float} [ avg source bytes, fallback
	 *         webp fraction, avg megapixels, avg size-file count ].
	 */
	private static function sample() {
		global $wpdb;

		// ~120-row random sample for the pre-run size estimate; runs inside the
		// scan, result folded into the cached option.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		$sum       = array(
			'image/jpeg' => 0,
			'image/png'  => 0,
		);
		$count     = array(
			'image/jpeg' => 0,
			'image/png'  => 0,
		);
		$mp_sum    = 0.0;
		$files_sum = 0;
		$rows_n    = 0;

		foreach ( (array) $ids as $id ) {
			$id   = (int) $id;
			$mime = get_post_mime_type( $id );

			if ( ! isset( $sum[ $mime ] ) ) {
				continue;
			}

			$meta  = wp_get_attachment_metadata( $id );
			$bytes = 0;
			$mp    = 0.0;
			$files = 1; // the full-size file

			if ( is_array( $meta ) ) {
				$bytes += (int) ( $meta['filesize'] ?? 0 );
				$mp    += ( (int) ( $meta['width'] ?? 0 ) * (int) ( $meta['height'] ?? 0 ) ) / 1000000;

				foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
					$bytes += (int) ( $size['filesize'] ?? 0 );
					$mp    += ( (int) ( $size['width'] ?? 0 ) * (int) ( $size['height'] ?? 0 ) ) / 1000000;
					++$files;
				}
			}

			if ( $bytes > 0 ) {
				$sum[ $mime ] += $bytes;
				++$count[ $mime ];
			}

			if ( $mp > 0 ) {
				$mp_sum    += $mp;
				$files_sum += $files;
				++$rows_n;
			}
		}

		$total_n   = $count['image/jpeg'] + $count['image/png'];
		$avg_src   = $total_n > 0
			? (int) round( ( $sum['image/jpeg'] + $sum['image/png'] ) / $total_n )
			: 0;
		$avg_mp    = $rows_n > 0 ? $mp_sum / $rows_n : 0.0;
		$avg_files = $rows_n > 0 ? $files_sum / $rows_n : 1.0;

		$jpeg_frac = 0.70; // ~30% smaller
		$png_frac  = extension_loaded( 'imagick' ) ? 0.78 : 0.50;
		$fallback  = $total_n > 0
			? ( ( $jpeg_frac * $count['image/jpeg'] ) + ( $png_frac * $count['image/png'] ) ) / $total_n
			: $jpeg_frac;

		return array( $avg_src, $fallback, $avg_mp, $avg_files );
	}

	/**
	 * Rough seconds per attachment before a run has measured this server:
	 * per-file overhead (editor init, encode, atomic place) plus an encode cost
	 * scaled by total megapixels across all sizes. Deliberately pessimistic -
	 * the real rate replaces it after the first chunk, and an over-estimate is
	 * friendlier than an "it'll be 2 minutes" that turns into twenty.
	 *
	 * @param float $avg_mp    Average total megapixels per attachment.
	 * @param float $avg_files Average size-file count per attachment.
	 * @return float
	 */
	private static function guess_per_image( $avg_mp, $avg_files ) {
		$per_mp   = extension_loaded( 'imagick' ) ? 1.1 : 0.8;
		$per_file = 0.45;

		return max( 1.5, ( $avg_files * $per_file ) + ( $avg_mp * $per_mp ) );
	}
}
