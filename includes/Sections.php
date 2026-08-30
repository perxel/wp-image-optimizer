<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slices the media library into calendar months, newest first.
 *
 * A month is the unit the runner walks (§4.1 of the redesign): the per-month
 * candidate query is small and hits the `post_date` / `post_type` indexes, so it
 * never becomes a 10k-row scan. Month is also the progress unit ("month 4 of
 * 39") and the scope-selection unit on the prepare screen.
 */
class Sections {

	/**
	 * Every calendar month that holds at least one JPEG/PNG attachment, newest
	 * first.
	 *
	 * @return array[] Each: [ 'ym' => '2024-07', 'label' => 'July 2024',
	 *                'year' => '2024', 'count' => 312 ].
	 */
	public static function months() {
		global $wpdb;

		// A grouped COUNT by month with a mime filter - no WP_Query equivalent.
		// Runs on the prepare screen only; the whole scan result is cached in the
		// `perxel_image_optimizer_scan` option, so per-row object caching would be
		// redundant and could serve a stale month list.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT DATE_FORMAT(post_date, '%Y-%m') AS ym, COUNT(*) AS c
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_status = 'inherit'
			   AND post_mime_type IN ( 'image/jpeg', 'image/png' )
			 GROUP BY ym
			 ORDER BY ym DESC"
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$ts    = strtotime( $row->ym . '-01' );
			$out[] = array(
				'ym'    => $row->ym,
				'label' => $ts ? date_i18n( 'F Y', $ts ) : $row->ym,
				'year'  => substr( $row->ym, 0, 4 ),
				'count' => (int) $row->c,
			);
		}

		return $out;
	}

	/**
	 * Attachment IDs in one calendar month that still need conversion work, ID
	 * ascending, starting after $after_id.
	 *
	 * "Needs work" is decided in SQL by the standalone signature meta
	 * (Converter::META_SIG): missing, or not equal to the current settings
	 * signature. That covers never-converted, failed, partial and
	 * settings-changed. The rarer "a new thumbnail size appeared under a done
	 * record" case is caught by Converter::needs_work() on the catch-up path
	 * (and by a forced re-run) - the SQL check is deliberately a fast estimate.
	 *
	 * @param string $ym        'YYYY-MM'.
	 * @param int    $after_id   Return IDs strictly greater than this.
	 * @param int    $limit      Max IDs.
	 * @param bool   $skip_done  false = also return already-converted IDs in the
	 *                           month (a forced re-run).
	 * @return int[]
	 */
	public static function pending_ids( $ym, $after_id, $limit, $skip_done = true ) {
		global $wpdb;

		$start    = $ym . '-01 00:00:00';
		$next     = strtotime( $ym . '-01 +1 month' );
		$end      = $next ? gmdate( 'Y-m-d H:i:s', $next ) : $ym . '-31 23:59:59';
		$after_id = (int) $after_id;
		$limit    = max( 1, (int) $limit );

		if ( ! $skip_done ) {
			// Month-scoped ID slice for the background runner - indexed on
			// post_date/post_type, capped by LIMIT, read once per chunk. Not a
			// cacheable result (the runner walks a moving cursor).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'attachment' AND post_status = 'inherit'
					   AND post_mime_type IN ( 'image/jpeg', 'image/png' )
					   AND post_date >= %s AND post_date < %s
					   AND ID > %d
					 ORDER BY ID ASC
					 LIMIT %d",
					$start,
					$end,
					$after_id,
					$limit
				)
			);

			return array_map( 'intval', (array) $ids );
		}

		// Same month-scoped slice, minus records already settled under the current
		// settings signature (a LEFT JOIN on postmeta WP_Query cannot express).
		// Moving-cursor read, not a cacheable result.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} sig
				   ON sig.post_id = p.ID AND sig.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
				   AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
				   AND p.post_date >= %s AND p.post_date < %s
				   AND p.ID > %d
				   AND ( sig.meta_id IS NULL OR sig.meta_value <> %s )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				Converter::META_SIG,
				$start,
				$end,
				$after_id,
				Settings::signature(),
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
