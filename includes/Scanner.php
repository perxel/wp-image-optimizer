<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enumerates image attachments, works out what still needs converting, and
 * runs a small sample to estimate saving + run time.
 */
class Scanner {

	const SAMPLE_TRANSIENT = 'perxel_image_optimizer_sample';

	/**
	 * All jpeg/png attachment IDs, newest first.
	 *
	 * @return int[]
	 */
	public static function all_image_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'cache_results'  => false,
			)
		);
	}

	/**
	 * Build the pending queue: image attachments that need work under the
	 * current settings.
	 *
	 * @return int[]
	 */
	public static function build_queue() {
		$pending = array();

		foreach ( self::all_image_ids() as $id ) {
			if ( Converter::needs_work( $id ) ) {
				$pending[] = (int) $id;
			}
		}

		return $pending;
	}

	/**
	 * Counts for the dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		$ids       = self::all_image_ids();
		$total     = count( $ids );
		$done      = 0;
		$partial   = 0;
		$failed    = 0;
		$pending   = 0;

		foreach ( $ids as $id ) {
			$meta   = Converter::get_meta( $id );
			$status = is_array( $meta ) ? ( $meta['status'] ?? '' ) : '';

			if ( 'failed' === $status ) {
				$failed++;
			}

			if ( Converter::needs_work( $id ) ) {
				if ( 'partial' === $status ) {
					$partial++;
				}
				$pending++;
			} else {
				$done++;
			}
		}

		return array(
			'attachments' => $total,
			'done'        => $done,
			'partial'     => $partial,
			'failed'      => $failed,
			'pending'     => $pending,
		);
	}

	/**
	 * List failed attachments with their reason.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function failures( $limit = 100 ) {
		$rows = array();

		foreach ( self::all_image_ids() as $id ) {
			$meta = Converter::get_meta( $id );

			if ( $meta && 'failed' === ( $meta['status'] ?? '' ) ) {
				$rows[] = array(
					'id'    => (int) $id,
					'file'  => (string) get_post_meta( $id, '_wp_attached_file', true ),
					'name'  => get_the_title( $id ),
					'error' => $meta['error'] ?? 'unknown',
				);
			}

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Convert a stratified sample in-place and measure the real ratio + speed.
	 * Results are cached; the sample .webp files are left on disk so they show
	 * a coverage credit and can be eyeballed.
	 *
	 * @param int $count Sample size.
	 * @return array
	 */
	public static function run_sample( $count = 25 ) {
		$ids = self::all_image_ids();

		if ( empty( $ids ) ) {
			return array( 'samples' => array(), 'ratio' => 0, 'ms_per_mp' => 0, 'estimate' => array() );
		}

		// Stratify: mostly jpeg, guarantee a few png.
		$png  = array();
		$jpeg = array();

		foreach ( $ids as $id ) {
			if ( 'image/png' === get_post_mime_type( $id ) ) {
				$png[] = $id;
			} else {
				$jpeg[] = $id;
			}
		}

		$pick = array_merge(
			self::spread( $png, min( 5, count( $png ) ) ),
			self::spread( $jpeg, max( 0, $count - min( 5, count( $png ) ) ) )
		);

		$samples    = array();
		$src_total  = 0;
		$webp_total = 0;
		$mp_total   = 0;
		$ms_total   = 0;

		foreach ( $pick as $id ) {
			$files = Converter::attachment_files( $id );
			$key   = isset( $files['large'] ) ? 'large' : 'full';

			if ( empty( $files[ $key ]['path'] ) || ! file_exists( $files[ $key ]['path'] ) ) {
				continue;
			}

			$path    = $files[ $key ]['path'];
			$w       = (int) $files[ $key ]['width'];
			$h       = (int) $files[ $key ]['height'];
			$mp      = ( $w * $h ) / 1000000;
			$mime    = get_post_mime_type( $id );
			$quality = 'image/png' === $mime ? (int) Settings::get( 'png_quality' ) : (int) Settings::get( 'jpeg_quality' );

			$editor = wp_get_image_editor( $path );
			if ( is_wp_error( $editor ) ) {
				continue;
			}
			$editor->set_quality( $quality );

			$tmp = trailingslashit( dirname( $path ) ) . '.pxw-sample-' . wp_generate_password( 8, false ) . '.webp';
			$t0  = microtime( true );
			$saved = $editor->save( $tmp, 'image/webp' );
			$ms  = ( microtime( true ) - $t0 ) * 1000;

			$written = is_array( $saved ) && isset( $saved['path'] ) ? $saved['path'] : $tmp;

			if ( is_wp_error( $saved ) || ! file_exists( $written ) ) {
				continue;
			}

			$src  = (int) filesize( $path );
			$webp = (int) filesize( $written );

			// Keep the sibling only if it actually helps; otherwise this is a
			// measurement-only pass.
			$final = $path . '.webp';
			if ( $webp > 0 && $webp < $src ) {
				@rename( $written, $final );
			} else {
				@unlink( $written );
			}

			$src_total  += $src;
			$webp_total += $webp;
			$mp_total   += $mp;
			$ms_total   += $ms;

			$kept = file_exists( $final );

			$samples[] = array(
				'id'    => (int) $id,
				'name'  => wp_basename( $path ),
				'mime'  => $mime,
				'src'   => $src,
				'webp'  => $webp,
				'ratio' => $src > 0 ? round( $webp / $src, 3 ) : 0,
				'kept'  => $kept,
				'url'   => self::url_for_path( $kept ? $final : $path ),
			);
		}

		$ratio     = $src_total > 0 ? $webp_total / $src_total : 0;
		$ms_per_mp = $mp_total > 0 ? $ms_total / $mp_total : 0;

		$data = array(
			'samples'   => $samples,
			'ratio'     => round( $ratio, 3 ),
			'ms_per_mp' => round( $ms_per_mp, 1 ),
			'estimate'  => self::project( $ratio, $ms_per_mp ),
			'ts'        => time(),
		);

		set_transient( self::SAMPLE_TRANSIENT, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * @return array|null Cached sample data.
	 */
	public static function cached_sample() {
		$data = get_transient( self::SAMPLE_TRANSIENT );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Project total saving + run time from a measured ratio and speed.
	 *
	 * @param float $ratio     webp/src.
	 * @param float $ms_per_mp Encode ms per megapixel.
	 * @return array
	 */
	private static function project( $ratio, $ms_per_mp ) {
		$src_bytes = 0;
		$megapx    = 0.0;

		foreach ( self::all_image_ids() as $id ) {
			foreach ( Converter::attachment_files( $id ) as $size => $info ) {
				if ( ! Settings::converts_size( $size ) || empty( $info['path'] ) || ! file_exists( $info['path'] ) ) {
					continue;
				}
				if ( file_exists( $info['path'] . '.webp' ) ) {
					continue;
				}
				$src_bytes += (int) filesize( $info['path'] );
				$megapx    += ( (int) $info['width'] * (int) $info['height'] ) / 1000000;
			}
		}

		$saved   = (int) round( $src_bytes * ( 1 - $ratio ) );
		$seconds = (int) round( ( $megapx * $ms_per_mp ) / 1000 * 1.35 ); // 35% batch overhead

		return array(
			'pending_source_bytes' => $src_bytes,
			'estimated_saved_bytes' => max( 0, $saved ),
			'estimated_seconds_low' => (int) round( $seconds * 0.8 ),
			'estimated_seconds_high' => (int) round( $seconds * 1.5 ),
		);
	}

	/**
	 * Evenly-spaced pick from a list.
	 *
	 * @param array $list  Source.
	 * @param int   $n     How many.
	 * @return array
	 */
	private static function spread( array $list, $n ) {
		$list = array_values( $list );
		$len  = count( $list );

		if ( $n <= 0 || 0 === $len ) {
			return array();
		}
		if ( $n >= $len ) {
			return $list;
		}

		$out  = array();
		$step = $len / $n;

		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = $list[ (int) floor( $i * $step ) ];
		}

		return $out;
	}

	/**
	 * Public URL for an absolute uploads path.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function url_for_path( $path ) {
		$upload = wp_get_upload_dir();

		return str_replace( $upload['basedir'], $upload['baseurl'], $path );
	}
}
