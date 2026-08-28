<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enumerates image attachments and works out what still needs converting.
 */
class Scanner {

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
}
