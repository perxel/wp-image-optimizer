<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enumerates image attachments.
 *
 * The heavy "what still needs converting" work moved out: month-scoped queries
 * live in Sections, the cached library scan (counts + exact byte totals) in
 * Scan, the failures list in Failures. What is left here is the full-ID list
 * (used by the "Remove all WebP" purge), a cheap page-load summary, and the
 * newest-first pending query the catch-up path uses.
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
	 * Pending attachment IDs library-wide, newest first, capped. "Pending" is
	 * decided in SQL by the standalone signature meta (see Sections).
	 *
	 * @param int $limit Max IDs.
	 * @return int[]
	 */
	public static function needs_work_ids( $limit ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} sig
				   ON sig.post_id = p.ID AND sig.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
				   AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
				   AND ( sig.meta_id IS NULL OR sig.meta_value <> %s )
				 ORDER BY p.ID DESC
				 LIMIT %d",
				Converter::META_SIG,
				Settings::signature(),
				max( 1, (int) $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @return bool Whether any image still needs work.
	 */
	public static function has_pending() {
		return array() !== self::needs_work_ids( 1 );
	}

	/**
	 * Cheap dashboard counts - no library walk. The attachment total is always
	 * live (wp_count_attachments()); "pending" is known only once scanned.
	 *
	 * @return array{attachments:int,pending:int,scanned:bool}
	 */
	public static function summary() {
		$counts      = (array) wp_count_attachments();
		$attachments = (int) ( $counts['image/jpeg'] ?? 0 ) + (int) ( $counts['image/png'] ?? 0 );

		$scan    = Scan::data();
		$scanned = ! empty( $scan['scanned_at'] );

		return array(
			'attachments' => $attachments,
			'pending'     => $scanned ? (int) $scan['pending'] : 0,
			'scanned'     => $scanned,
		);
	}
}
