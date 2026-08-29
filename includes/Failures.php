<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small, incrementally-maintained index of attachments that failed to convert
 * or were skipped as too large for this server.
 *
 * Replaces the old `Scanner::failures()` full library walk (§9): the runner and
 * the catch-up path add/remove entries as they go, so the monitor's failures
 * list is a single `get_option`. Per-image detail still lives in the
 * `_perxel_image_optimizer` post meta - this is only the "which ones" index.
 *
 * Shape: `[ (int) attachment_id => [ 'reason' => string, 'kind' =>
 * failed|too_large, 'ts' => int ] ]`.
 */
class Failures {

	const OPTION = 'perxel_image_optimizer_failures';
	const MAX    = 500;

	/**
	 * @return array
	 */
	public static function all() {
		$data = get_option( self::OPTION, array() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param int    $id     Attachment ID.
	 * @param string $reason Human-readable reason.
	 * @param string $kind   failed|too_large.
	 */
	public static function record( $id, $reason, $kind = 'failed' ) {
		$data              = self::all();
		$data[ (int) $id ] = array(
			'reason' => (string) $reason,
			'kind'   => 'too_large' === $kind ? 'too_large' : 'failed',
			'ts'     => time(),
		);

		self::save( $data );
	}

	/**
	 * Drop one entry - the attachment converted cleanly on a later pass or a
	 * retry.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function clear_one( $id ) {
		$data = self::all();

		if ( isset( $data[ (int) $id ] ) ) {
			unset( $data[ (int) $id ] );
			self::save( $data );
		}
	}

	/**
	 * Drop every entry of one kind (a "retry all failed" leaves the too-large
	 * set alone).
	 *
	 * @param string $kind failed|too_large.
	 */
	public static function clear_kind( $kind ) {
		$kind = 'too_large' === $kind ? 'too_large' : 'failed';
		$data = self::all();
		$keep = array();

		foreach ( $data as $id => $row ) {
			if ( ( $row['kind'] ?? 'failed' ) !== $kind ) {
				$keep[ $id ] = $row;
			}
		}

		self::save( $keep );
	}

	/**
	 * Wipe the index (called when a run starts).
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * @return array{failed:int,too_large:int}
	 */
	public static function counts() {
		$failed = 0;
		$large  = 0;

		foreach ( self::all() as $row ) {
			if ( 'too_large' === ( $row['kind'] ?? 'failed' ) ) {
				++$large;
			} else {
				++$failed;
			}
		}

		return array(
			'failed'    => $failed,
			'too_large' => $large,
		);
	}

	/**
	 * IDs of the failed (not too-large) entries - used to re-queue them.
	 *
	 * @return int[]
	 */
	public static function failed_ids() {
		$ids = array();

		foreach ( self::all() as $id => $row ) {
			if ( 'too_large' !== ( $row['kind'] ?? 'failed' ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Enriched rows for the monitor's failures list. Self-heals: a `failed`
	 * entry whose attachment is now settled under the current signature (fixed
	 * on a retry, per-image button, or catch-up pass) is dropped from the index
	 * as it is read - no walk, bounded by $limit.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function listing( $limit = 100 ) {
		$rows      = array();
		$data      = self::all();
		$signature = Settings::signature();
		$pruned    = false;

		foreach ( $data as $id => $row ) {
			$id   = (int) $id;
			$kind = $row['kind'] ?? 'failed';

			if ( 'attachment' !== get_post_type( $id ) ) {
				unset( $data[ $id ] );
				$pruned = true;
				continue;
			}

			if ( 'failed' === $kind && get_post_meta( $id, Converter::META_SIG, true ) === $signature ) {
				unset( $data[ $id ] );
				$pruned = true;
				continue;
			}

			if ( count( $rows ) >= $limit ) {
				continue;
			}

			$rows[] = array(
				'id'     => $id,
				'name'   => get_the_title( $id ),
				'file'   => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
				'thumb'  => wp_get_attachment_image_url( $id, array( 60, 60 ) ),
				'edit'   => get_edit_post_link( $id, 'raw' ),
				'kind'   => $kind,
				'reason' => $row['reason'] ?? '',
			);
		}

		if ( $pruned ) {
			update_option( self::OPTION, $data, false );
		}

		return $rows;
	}

	/**
	 * @param array $data Index to persist (trimmed to the newest MAX entries).
	 */
	private static function save( array $data ) {
		if ( count( $data ) > self::MAX ) {
			$data = array_slice( $data, -self::MAX, null, true );
		}

		update_option( self::OPTION, $data, false );
	}
}
