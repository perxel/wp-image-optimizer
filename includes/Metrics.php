<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Library-wide totals, scoped to the files this plugin actually serves
 * (each attachment's "full" + intermediate sizes — not the untouched
 * high-res originals WordPress keeps).
 *
 * Maintained incrementally during a run; "Recalculate" does an authoritative
 * pass over every attachment.
 */
class Metrics {

	const OPTION = 'perxel_image_optimizer_metrics';

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Candidate = a served image file (attachment full + sizes).
			'candidate_files'      => 0,
			'candidate_bytes'      => 0,

			// Of those, the ones that now have a smaller .webp sibling.
			'converted_files'      => 0,
			'converted_src_bytes'  => 0, // originals of the converted files
			'converted_webp_bytes' => 0, // the .webp files themselves

			// Candidates left as-is because WebP wasn't smaller.
			'no_gain_files'        => 0,

			// Candidates not yet processed (missing / stale meta).
			'pending_files'        => 0,

			// Attachments whose last conversion failed.
			'failed_attachments'   => 0,

			// Every .webp on disk (the real added-storage figure).
			'webp_files_total'     => 0,
			'webp_bytes_total'     => 0,

			'last_full_scan'       => 0,
		);
	}

	/**
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Derived numbers for display. Keeps "bandwidth saved" and "disk added"
	 * clearly separate.
	 *
	 * @return array
	 */
	public static function report() {
		$m = self::all();

		$src   = (int) $m['converted_src_bytes'];
		$webp  = (int) $m['converted_webp_bytes'];
		$saved = max( 0, $src - $webp );
		$pct   = $src > 0 ? round( $saved / $src * 100 ) : 0;

		$covered = (int) $m['converted_files'] + (int) $m['no_gain_files'];
		$cov_pct = $m['candidate_files'] > 0 ? round( $covered / $m['candidate_files'] * 100 ) : 0;

		return array(
			// The win.
			'served_before'   => $src,             // bytes browsers would fetch without WebP (converted files)
			'served_after'    => $webp,            // bytes browsers fetch now
			'bandwidth_saved' => $saved,
			'bandwidth_pct'   => $pct,

			// The cost.
			'disk_added'      => (int) $m['webp_bytes_total'],
			'webp_files'      => (int) $m['webp_files_total'],

			// Coverage.
			'candidate_files' => (int) $m['candidate_files'],
			'candidate_bytes' => (int) $m['candidate_bytes'],
			'converted_files' => (int) $m['converted_files'],
			'no_gain_files'   => (int) $m['no_gain_files'],
			'pending_files'   => (int) $m['pending_files'],
			'failed'          => (int) $m['failed_attachments'],
			'covered_files'   => $covered,
			'coverage_pct'    => $cov_pct,

			'last_full_scan'  => (int) $m['last_full_scan'],
		);
	}

	/**
	 * Apply the delta from one converted attachment (incremental, best-effort;
	 * a full recalc is authoritative).
	 *
	 * @param array $result Converter::convert_attachment() return.
	 */
	public static function apply( array $result ) {
		if ( empty( $result['converted'] ) ) {
			return;
		}

		$m = self::all();

		$m['converted_files']      += (int) $result['converted'];
		$m['converted_src_bytes']  += (int) $result['src_bytes'];
		$m['converted_webp_bytes'] += (int) $result['webp_bytes'];
		$m['webp_files_total']     += (int) $result['converted'];
		$m['webp_bytes_total']     += (int) $result['webp_bytes'];

		if ( $m['pending_files'] > 0 ) {
			$m['pending_files'] = max( 0, $m['pending_files'] - (int) $result['converted'] );
		}

		update_option( self::OPTION, $m, false );
	}

	/**
	 * Authoritative recount over every image attachment.
	 *
	 * @return array
	 */
	public static function recalculate() {
		$m = self::defaults();

		$signature = Settings::signature();

		foreach ( Scanner::all_image_ids() as $id ) {
			$meta       = Converter::get_meta( $id );
			$meta_sizes = is_array( $meta ) && ! empty( $meta['sizes'] ) ? $meta['sizes'] : array();
			$stale      = ! $meta || ( $meta['signature'] ?? '' ) !== $signature;

			if ( $meta && 'failed' === ( $meta['status'] ?? '' ) ) {
				$m['failed_attachments']++;
			}

			// Backfill the standalone signature meta for records that predate it
			// (or lost it) so Sections/Scanner see them as settled.
			if ( $meta && ! $stale && in_array( $meta['status'] ?? '', array( 'done', 'skipped' ), true ) ) {
				update_post_meta( $id, Converter::META_SIG, $signature );
			}

			foreach ( Converter::attachment_files( $id ) as $size => $info ) {
				if ( ! Settings::converts_size( $size ) || empty( $info['path'] ) || ! file_exists( $info['path'] ) ) {
					continue;
				}

				$src = (int) filesize( $info['path'] );

				$m['candidate_files']++;
				$m['candidate_bytes'] += $src;

				$webp_path = $info['path'] . '.webp';

				if ( file_exists( $webp_path ) ) {
					$m['converted_files']++;
					$m['converted_src_bytes']  += $src;
					$m['converted_webp_bytes'] += (int) filesize( $webp_path );
				} elseif ( ! $stale && isset( $meta_sizes[ $size ]['reason'] ) && 'no_gain' === $meta_sizes[ $size ]['reason'] ) {
					$m['no_gain_files']++;
				} else {
					$m['pending_files']++;
				}
			}
		}

		// Every .webp on disk — the true storage cost (includes any orphans).
		$upload = wp_get_upload_dir();
		if ( is_dir( $upload['basedir'] ) ) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $upload['basedir'], \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $file ) {
				/** @var \SplFileInfo $file */
				if ( $file->isFile() && 'webp' === strtolower( $file->getExtension() ) ) {
					$m['webp_files_total']++;
					$m['webp_bytes_total'] += (int) $file->getSize();
				}
			}
		}

		$m['last_full_scan'] = time();

		update_option( self::OPTION, $m, false );

		// The authoritative pass also refreshes the derived indexes.
		Failures::rebuild();
		Scan::mark_stale();

		return $m;
	}

	/**
	 * Reset everything (used by "Remove all WebP").
	 */
	public static function reset() {
		update_option( self::OPTION, self::defaults(), false );
	}
}
