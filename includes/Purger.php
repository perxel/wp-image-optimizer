<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Remove all WebP" — a resumable, chunked delete of every .webp under uploads/.
 */
class Purger {

	const OPTION = 'perxel_image_optimizer_purge';
	const CHUNK  = 200;

	/**
	 * Build the delete list. Stops serving first.
	 *
	 * @return int Total files queued.
	 */
	public static function start() {
		( new Serve() )->disable();

		$upload = wp_get_upload_dir();
		$files  = array();

		if ( is_dir( $upload['basedir'] ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $upload['basedir'], \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				/** @var \SplFileInfo $file */
				if ( $file->isFile() && 'webp' === strtolower( $file->getExtension() ) ) {
					$files[] = $file->getPathname();
				}
			}
		}

		update_option(
			self::OPTION,
			array(
				'queue'   => $files,
				'total'   => count( $files ),
				'deleted' => 0,
				'bytes'   => 0,
			),
			false
		);

		return count( $files );
	}

	/**
	 * Delete the next chunk.
	 *
	 * @return array {@type string $status, @type int $deleted, @type int $remaining, @type int $total, @type int $bytes}
	 */
	public static function step() {
		$state = get_option( self::OPTION, array() );

		if ( ! is_array( $state ) || empty( $state['queue'] ) ) {
			self::finish();
			return array(
				'status'    => 'done',
				'deleted'   => is_array( $state ) ? (int) ( $state['deleted'] ?? 0 ) : 0,
				'remaining' => 0,
				'total'     => is_array( $state ) ? (int) ( $state['total'] ?? 0 ) : 0,
				'bytes'     => is_array( $state ) ? (int) ( $state['bytes'] ?? 0 ) : 0,
			);
		}

		$chunk = array_splice( $state['queue'], 0, self::CHUNK );

		foreach ( $chunk as $path ) {
			if ( file_exists( $path ) ) {
				$state['bytes'] += (int) filesize( $path );
				if ( @unlink( $path ) ) {
					$state['deleted']++;
				}
			}
		}

		update_option( self::OPTION, $state, false );

		$remaining = count( $state['queue'] );

		if ( 0 === $remaining ) {
			self::finish();
			return array(
				'status'    => 'done',
				'deleted'   => (int) $state['deleted'],
				'remaining' => 0,
				'total'     => (int) $state['total'],
				'bytes'     => (int) $state['bytes'],
			);
		}

		return array(
			'status'    => 'running',
			'deleted'   => (int) $state['deleted'],
			'remaining' => $remaining,
			'total'     => (int) $state['total'],
			'bytes'     => (int) $state['bytes'],
		);
	}

	/**
	 * Clear all plugin state after a full purge.
	 */
	private static function finish() {
		// Clear every per-attachment record AND force the meta cache to drop —
		// a raw $wpdb->delete (or delete_post_meta on already-gone rows) leaves
		// a persistent object cache serving stale "status => done" records.
		foreach ( Scanner::all_image_ids() as $id ) {
			$id = (int) $id;
			delete_post_meta( $id, Converter::META );
			wp_cache_delete( $id, 'post_meta' );
		}

		Metrics::reset();
		Runner::reset();
		delete_option( self::OPTION );
	}
}
