<?php

namespace Perxel_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps full-size images within a maximum size, and drops the originals
 * WordPress keeps beside them.
 *
 * New uploads: WordPress already scales a big JPEG down to a `-scaled` copy
 * (`big_image_size_threshold`, 2560 px by core); we set that threshold to the
 * `max_size` setting. With `delete_originals` on, the untouched original it
 * keeps (`original_image` in the metadata) is deleted as soon as the upload's
 * metadata is built, and a file core left over the limit is shrunk in place.
 * Silent: no notice, no step.
 *
 * Existing library ("Shrink existing images", `wp perxel-image cleanup`): the
 * same per-attachment pass, walked by ID with a resumable cursor. A full-size
 * file over the limit is resized **in place** (same file name, so every URL in
 * post content keeps working); sub-sizes are left alone. Its stale WebP copy is
 * removed and the catch-up re-converts it.
 */
class Resizer {

	const OPTION = 'perxel_image_optimizer_shrink';
	const MIMES  = array( 'image/jpeg', 'image/png' );
	const BATCH  = 100;

	/**
	 * JPEG quality for a file resized in place: WordPress's own default.
	 * Set explicitly, so a theme's `jpeg_quality` filter (some force 100)
	 * can't make the shrunk file bigger than it needs to be.
	 */
	const JPEG_QUALITY = 82;

	/**
	 * Hooks. Only while the plugin is enabled.
	 */
	public function register() {
		add_filter( 'big_image_size_threshold', array( $this, 'threshold' ) );
		// Before Catchup (20), so the conversion it schedules sees the final files.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_metadata' ), 10, 2 );
	}

	/**
	 * The size WordPress scales a big upload down to; false = never scale.
	 *
	 * @param int|false $threshold Core's threshold (2560).
	 * @return int|false
	 */
	public function threshold( $threshold ) {
		$max = (int) Settings::get( 'max_size' );

		return $max > 0 ? $max : false;
	}

	/**
	 * A new upload's metadata is built: drop the original, shrink what core left big.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function on_metadata( $metadata, $attachment_id ) {
		if ( ! Settings::get( 'delete_originals' ) || ! is_array( $metadata ) ) {
			return $metadata;
		}

		$result = self::shrink( (int) $attachment_id, $metadata );

		return $result['meta'];
	}

	/**
	 * What shrinking one attachment would do, or does.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $meta          Metadata to work on (null = the stored one, saved back when changed).
	 * @param bool       $dry_run       Only report.
	 * @return array{meta:array,changed:bool,original:int,resized:bool,freed:int,error:string,from:string,to:string}
	 */
	public static function shrink( $attachment_id, $meta = null, $dry_run = false ) {
		$save = null === $meta;
		$meta = $save ? wp_get_attachment_metadata( $attachment_id ) : $meta;
		$out  = array(
			'meta'     => is_array( $meta ) ? $meta : array(),
			'changed'  => false,
			'original' => 0,
			'resized'  => false,
			'freed'    => 0,
			'error'    => '',
			'from'     => '',
			'to'       => '',
		);

		$file = get_attached_file( $attachment_id );

		if ( ! is_array( $meta ) || ! $file || ! file_exists( $file )
			|| ! in_array( get_post_mime_type( $attachment_id ), self::MIMES, true ) ) {
			return $out;
		}

		// 1. The original WordPress kept beside a -scaled / -rotated copy.
		// Never when it is the attached file itself (the scaled copy not swapped in).
		$original = empty( $meta['original_image'] ) ? '' : path_join( dirname( $file ), $meta['original_image'] );

		if ( '' !== $original && $original !== $file ) {
			if ( file_exists( $original ) ) {
				$out['original'] = (int) filesize( $original );
				$out['freed']   += $out['original'];

				if ( ! $dry_run ) {
					self::delete( $original );
					self::delete( $original . '.webp' );
				}
			}

			unset( $meta['original_image'] );
			$out['changed'] = true;
		}

		// 2. A full-size file still over the limit, resized in place.
		$max = (int) Settings::get( 'max_size' );
		$w   = (int) ( $meta['width'] ?? 0 );
		$h   = (int) ( $meta['height'] ?? 0 );

		if ( $max > 0 && max( $w, $h ) > $max ) {
			$out['from'] = $w . '×' . $h;
			$scale       = $max / max( $w, $h );
			$out['to']   = (int) round( $w * $scale ) . '×' . (int) round( $h * $scale );

			if ( $dry_run ) {
				$out['resized'] = true;
			} else {
				$done = self::resize_in_place( $file, $max );

				if ( is_wp_error( $done ) ) {
					$out['error'] = $done->get_error_message();
				} else {
					$out['freed']    += max( 0, $done['before'] - $done['after'] );
					$out['resized']   = true;
					$out['changed']   = true;
					$out['to']        = $done['width'] . '×' . $done['height'];
					$meta['width']    = $done['width'];
					$meta['height']   = $done['height'];
					$meta['filesize'] = $done['after'];

					// Its WebP copy is now stale: drop it and the status records
					// (the blob for needs_work(), the marker for the SQL pending
					// checks) so the catch-up and `convert` redo the new file.
					self::delete( $file . '.webp' );
					delete_post_meta( $attachment_id, Converter::META );
					delete_post_meta( $attachment_id, Converter::META_SIG );
				}
			}
		}

		$out['meta'] = $meta;

		if ( $save && $out['changed'] && ! $dry_run ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		return $out;
	}

	/**
	 * Resize a file to fit $max × $max, overwriting it through a temp file.
	 *
	 * @param string $file Absolute path.
	 * @param int    $max  Longest side, px.
	 * @return array{width:int,height:int,before:int,after:int}|\WP_Error
	 */
	private static function resize_in_place( $file, $max ) {
		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$resized = $editor->resize( $max, $max, false );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$ext = pathinfo( $file, PATHINFO_EXTENSION );

		if ( in_array( strtolower( $ext ), array( 'jpg', 'jpeg' ), true ) ) {
			$editor->set_quality( self::JPEG_QUALITY );
		}

		$tmp   = trailingslashit( dirname( $file ) ) . '.pxr-tmp-' . wp_generate_password( 10, false ) . '.' . $ext;
		$saved = $editor->save( $tmp );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			self::delete( $tmp );
			return is_wp_error( $saved ) ? $saved : new \WP_Error( 'pxio_resize', 'resize failed' );
		}

		$before = (int) filesize( $file );
		$after  = (int) filesize( $saved['path'] );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap of a temp file we just wrote in the same directory; WP_Filesystem has no atomic move, and a false return is handled.
		if ( ! @rename( $saved['path'], $file ) ) {
			self::delete( $saved['path'] );
			return new \WP_Error( 'pxio_resize', 'could not replace the file' );
		}

		clearstatcache( true, $file );

		return array(
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * @param string $path File to remove if present.
	 */
	private static function delete( $path ) {
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/* ---- the existing library ------------------------------------ */

	/**
	 * Begin a library pass (admin button / CLI). Refused while a bulk WebP run
	 * is walking the library.
	 *
	 * @return array|\WP_Error The fresh state.
	 */
	public static function start() {
		if ( Runner::is_active() ) {
			return new \WP_Error( 'pxio_busy', __( 'A bulk conversion is running. Finish or cancel it first.', 'perxel-image-optimizer' ) );
		}

		$state = self::fresh_state();
		update_option( self::OPTION, $state, false );

		return $state;
	}

	/**
	 * @return array A new pass, at the start of the library.
	 */
	private static function fresh_state() {
		return array(
			'cursor'   => 0,
			'checked'  => 0,
			'changed'  => 0,
			'resized'  => 0,
			'original' => 0,
			'freed'    => 0,
			'failed'   => 0,
			'total'    => (int) count( Scanner::all_image_ids() ),
			'status'   => 'running',
		);
	}

	/**
	 * Work through the library from the cursor for up to $budget seconds.
	 *
	 * @param float         $budget  Seconds (0 = no limit).
	 * @param bool          $dry_run Only count.
	 * @param callable|null $each    Called with ( $id, $result ) for every changed attachment.
	 * @return array The state.
	 */
	public static function step( $budget = 10.0, $dry_run = false, $each = null ) {
		// A dry run walks the whole library in one go and never touches a pass
		// in progress.
		$state = $dry_run ? self::fresh_state() : get_option( self::OPTION, array() );

		if ( ! is_array( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			$state = self::start();
			if ( is_wp_error( $state ) ) {
				return array( 'status' => 'error' );
			}
		}

		wp_raise_memory_limit( 'image' );
		$started = microtime( true );
		$touched = false;

		do {
			$ids   = self::ids_after( (int) $state['cursor'], self::BATCH );
			$count = count( $ids );

			foreach ( $ids as $id ) {
				$state['cursor'] = $id;
				++$state['checked'];

				// Never resize under a conversion in progress.
				if ( get_transient( 'perxel_image_optimizer_lock_' . $id ) ) {
					continue;
				}

				$result = self::shrink( $id, null, $dry_run );

				if ( $result['freed'] > 0 || $result['resized'] || '' !== $result['error'] ) {
					$state['freed']    += $result['freed'];
					$state['original'] += $result['original'] > 0 ? 1 : 0;
					$state['resized']  += $result['resized'] ? 1 : 0;
					$state['failed']   += '' !== $result['error'] ? 1 : 0;
					++$state['changed'];
					$touched = $touched || ( $result['resized'] && ! $dry_run );

					if ( $each ) {
						call_user_func( $each, $id, $result );
					}
				}

				if ( $budget > 0 && ( microtime( true ) - $started ) > $budget ) {
					break 2;
				}
			}
		} while ( self::BATCH === $count );

		if ( $count < self::BATCH && ( 0 === $budget || ( microtime( true ) - $started ) <= $budget ) ) {
			$state['status'] = 'done';
		}

		if ( ! $dry_run ) {
			if ( 'done' === $state['status'] ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $state, false );
			}

			Scan::mark_stale();
			if ( $touched ) {
				Catchup::schedule();
			}
		}

		return $state;
	}

	/**
	 * Image attachment IDs above a cursor, ascending.
	 *
	 * @param int $after Last ID done.
	 * @param int $limit Max IDs.
	 * @return int[]
	 */
	private static function ids_after( $after, $limit ) {
		global $wpdb;

		// A keyset walk by ID - cheap at any library size, and stable while
		// files change under it. Acted on immediately, nothing to cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_status = 'inherit'
				   AND post_mime_type IN ( 'image/jpeg', 'image/png' ) AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
				$after,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
