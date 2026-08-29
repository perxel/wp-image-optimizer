<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one function that produces every library-wide figure the admin screens
 * show - pending counts, coverage, exact bytes saved, exact bytes on disk - and
 * the per-month breakdown behind the prepare screen.
 *
 * A page visit never runs it; the "Scan library" button does. It is all cheap
 * SQL: grouped `COUNT()` per month, two `SUM()` over the flat
 * `_perxel_image_optimizer_saved` / `_perxel_image_optimizer_webp` meta keys
 * (written by `Converter` on every convert / remove), plus a ~120-attachment
 * `_wp_attachment_metadata` sample for the pre-run size estimate. No image
 * decode, no file reads, no library walk. Result cached in one option.
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
	 *   attachments:int, settled:int, pending:int, converted:int,
	 *   coverage_pct:int, saved_bytes:int, webp_bytes:int, src_bytes:int,
	 *   saved_pct:int
	 * }
	 */
	public static function stats() {
		$d = self::data();

		$total   = (int) ( $d['total'] ?? 0 );
		$pending = (int) ( $d['pending'] ?? 0 );
		$settled = max( 0, $total - $pending );
		$saved   = (int) ( $d['saved_bytes'] ?? 0 );
		$webp    = (int) ( $d['webp_bytes'] ?? 0 );
		$src     = $saved + $webp;

		return array(
			'scanned'      => ! empty( $d['scanned_at'] ),
			'scanned_at'   => (int) ( $d['scanned_at'] ?? 0 ),
			'stale'        => ! empty( $d['stale'] ),
			'attachments'  => $total,
			'settled'      => $settled,
			'pending'      => $pending,
			'converted'    => (int) ( $d['converted'] ?? 0 ),
			'coverage_pct' => $total > 0 ? (int) round( $settled / $total * 100 ) : 0,
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

		// Exact library-wide byte totals - one indexed SUM per flat meta key.
		$saved_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s", Converter::META_SAVED )
		);
		$webp_total  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s", Converter::META_WEBP )
		);
		$converted   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Converter::META_WEBP )
		);

		list( $avg_src, $fallback_frac, $avg_mp ) = self::sample();

		$measured_frac = $webp_total > 0;
		$avg_frac      = $measured_frac
			? $webp_total / ( $saved_total + $webp_total )
			: $fallback_frac;

		// Seconds per image: what past runs measured on this server, else a
		// rough guess from the average image size.
		$pace          = (float) get_option( Runner::PACE_OPTION, 0 );
		$measured_pace = $pace > 0;
		$per_image     = $measured_pace ? $pace : self::guess_per_image( $avg_mp );

		$data = array(
			'months'        => $months,
			'total'         => $total_all,
			'pending'       => $total_pending,
			'converted'     => $converted,
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
	 * `filesize`) - no file reads. Also returns the average megapixels per
	 * attachment (full + sub-sizes), used to guess conversion speed before a
	 * run has measured it.
	 *
	 * @return array{0:int,1:float,2:float} [ avg source bytes, fallback webp
	 *         fraction, avg megapixels ].
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

		$sum    = array(
			'image/jpeg' => 0,
			'image/png'  => 0,
		);
		$count  = array(
			'image/jpeg' => 0,
			'image/png'  => 0,
		);
		$mp_sum = 0.0;
		$mp_n   = 0;

		foreach ( (array) $ids as $id ) {
			$id   = (int) $id;
			$mime = get_post_mime_type( $id );

			if ( ! isset( $sum[ $mime ] ) ) {
				continue;
			}

			$meta  = wp_get_attachment_metadata( $id );
			$bytes = 0;
			$mp    = 0.0;

			if ( is_array( $meta ) ) {
				$bytes += (int) ( $meta['filesize'] ?? 0 );
				$mp    += ( (int) ( $meta['width'] ?? 0 ) * (int) ( $meta['height'] ?? 0 ) ) / 1000000;

				foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
					$bytes += (int) ( $size['filesize'] ?? 0 );
					$mp    += ( (int) ( $size['width'] ?? 0 ) * (int) ( $size['height'] ?? 0 ) ) / 1000000;
				}
			}

			if ( $bytes > 0 ) {
				$sum[ $mime ] += $bytes;
				++$count[ $mime ];
			}

			if ( $mp > 0 ) {
				$mp_sum += $mp;
				++$mp_n;
			}
		}

		$total_n = $count['image/jpeg'] + $count['image/png'];
		$avg_src = $total_n > 0
			? (int) round( ( $sum['image/jpeg'] + $sum['image/png'] ) / $total_n )
			: 0;
		$avg_mp  = $mp_n > 0 ? $mp_sum / $mp_n : 0.0;

		$jpeg_frac = 0.70; // ~30% smaller
		$png_frac  = extension_loaded( 'imagick' ) ? 0.78 : 0.50;
		$fallback  = $total_n > 0
			? ( ( $jpeg_frac * $count['image/jpeg'] ) + ( $png_frac * $count['image/png'] ) ) / $total_n
			: $jpeg_frac;

		return array( $avg_src, $fallback, $avg_mp );
	}

	/**
	 * Rough seconds-per-image before a run has measured this server: scaled by
	 * the average megapixels processed (all sizes), floored so a thumbnail-only
	 * library still shows a sane number.
	 *
	 * @param float $avg_mp Average megapixels per attachment.
	 * @return float
	 */
	private static function guess_per_image( $avg_mp ) {
		$per_mp = extension_loaded( 'imagick' ) ? 0.5 : 0.35;

		return max( 0.4, $avg_mp > 0 ? $avg_mp * $per_mp : 1.0 );
	}
}
