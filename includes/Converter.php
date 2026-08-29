<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single shared conversion entry point. The bulk runner loops
 * convert_attachment(); the per-attachment buttons call it once.
 *
 * Naming: foo.jpg -> foo.jpg.webp (sibling, original untouched).
 */
class Converter {

	const META     = '_perxel_image_optimizer';
	const META_OLD = '_perxel_webp'; // pre-1.0 key; migrated on read.

	/**
	 * Standalone, SQL-filterable settings signature. Present and equal to the
	 * current Settings::signature() exactly when the attachment is fully handled
	 * under the current settings (status done, no-gain, or a deterministic
	 * skip). Absent for partial/failed work — which is how Sections/Scanner
	 * find "still needs converting" without unserialising per-row meta.
	 */
	const META_SIG = '_perxel_image_optimizer_sig';

	/**
	 * Convert every eligible file of one attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Reconvert even if siblings look current.
	 * @return array {
	 *     @type string $status  done|partial|failed|skipped
	 *     @type array  $sizes   per-size results
	 *     @type int    $src_bytes   source bytes newly covered this call
	 *     @type int    $webp_bytes  webp bytes written this call
	 *     @type int    $converted   count of sizes newly written this call
	 *     @type string $error
	 * }
	 */
	public static function convert_attachment( $attachment_id, $force = false ) {
		$attachment_id = (int) $attachment_id;

		$result = array(
			'status'     => 'failed',
			'sizes'      => array(),
			'src_bytes'  => 0,
			'webp_bytes' => 0,
			'converted'  => 0,
			'error'      => '',
		);

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			$result['status'] = 'skipped';
			$result['error']  = 'not an image';
			return $result;
		}

		$mime = get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			$result['status'] = 'skipped';
			$result['error']  = 'unsupported mime: ' . $mime;
			self::save_meta( $attachment_id, $result );
			return $result;
		}

		if ( 'image/png' === $mime && ! Settings::get( 'convert_png' ) ) {
			$result['status'] = 'skipped';
			$result['error']  = 'png conversion disabled';
			self::save_meta( $attachment_id, $result );
			return $result;
		}

		$lock_key = 'perxel_image_optimizer_lock_' . $attachment_id;
		if ( get_transient( $lock_key ) ) {
			$result['error'] = 'attachment busy';
			return $result;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$files   = self::attachment_files( $attachment_id );
			$quality = 'image/png' === $mime ? (int) Settings::get( 'png_quality' ) : (int) Settings::get( 'jpeg_quality' );
			$max_mp  = (int) Settings::get( 'skip_megapixels' );
			if ( $max_mp <= 0 ) {
				$max_mp = Environment::safe_megapixels();
			}

			$ok        = 0;
			$fail      = 0;
			$skip      = 0;
			$too_large = 0;

			foreach ( $files as $size => $info ) {
				$path = $info['path'];

				if ( ! Settings::converts_size( $size ) ) {
					continue;
				}

				if ( ! file_exists( $path ) ) {
					$result['sizes'][ $size ] = array( 'ok' => false, 'reason' => 'missing source' );
					continue;
				}

				$target = $path . '.webp';

				// Already current?
				if ( ! $force && file_exists( $target ) && filemtime( $target ) >= filemtime( $path ) ) {
					$result['sizes'][ $size ] = array(
						'ok'   => true,
						'src'  => (int) filesize( $path ),
						'webp' => (int) filesize( $target ),
						'kept' => true,
					);
					$ok++;
					continue;
				}

				// Megapixel guard.
				$mp = self::megapixels( $path, $info );
				if ( $max_mp > 0 && $mp > $max_mp ) {
					$result['sizes'][ $size ] = array( 'ok' => false, 'reason' => sprintf( 'exceeds %d MP', $max_mp ) );
					$skip++;
					$too_large++;
					continue;
				}

				$one = self::convert_file( $path, $target, $mime, $quality );

				$result['sizes'][ $size ] = $one;

				if ( ! empty( $one['ok'] ) ) {
					$ok++;
					if ( empty( $one['kept'] ) ) {
						$result['src_bytes']  += (int) $one['src'];
						$result['webp_bytes'] += (int) $one['webp'];
						$result['converted']++;
					}
				} elseif ( isset( $one['reason'] ) && 'no_gain' === $one['reason'] ) {
					$skip++;
				} else {
					$fail++;
				}
			}

			if ( $fail > 0 && 0 === $ok ) {
				$result['status'] = 'failed';
				$result['error']  = 'all sizes failed';
			} elseif ( $fail > 0 ) {
				$result['status'] = 'partial';
			} elseif ( 0 === $ok && $too_large > 0 ) {
				$result['status'] = 'skipped';
				$result['error']  = 'too large for this server';
			} else {
				$result['status'] = 'done';
			}

			$result['converted_count'] = $ok;
			$result['failed_count']    = $fail;
			$result['skipped_count']   = $skip;
			$result['too_large_count'] = $too_large;
		} catch ( \Throwable $e ) {
			$result['status'] = 'failed';
			$result['error']  = $e->getMessage();
		} finally {
			delete_transient( $lock_key );
		}

		self::save_meta( $attachment_id, $result );

		return $result;
	}

	/**
	 * Remove every .webp sibling of one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array { @type int $deleted, @type int $bytes }
	 */
	public static function remove_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$deleted       = 0;
		$bytes         = 0;

		foreach ( self::attachment_files( $attachment_id ) as $info ) {
			$target = $info['path'] . '.webp';

			if ( file_exists( $target ) ) {
				$bytes += (int) filesize( $target );
				if ( @unlink( $target ) ) {
					$deleted++;
				}
			}
		}

		delete_post_meta( $attachment_id, self::META );
		delete_post_meta( $attachment_id, self::META_SIG );

		return array( 'deleted' => $deleted, 'bytes' => $bytes );
	}

	/**
	 * Convert one file to WebP with an atomic write and a size gate.
	 *
	 * @param string $source  Absolute source path.
	 * @param string $target  Absolute target (foo.jpg.webp).
	 * @param string $mime    Source mime.
	 * @param int    $quality 1-100.
	 * @return array
	 */
	private static function convert_file( $source, $target, $mime, $quality ) {
		$src_bytes = (int) filesize( $source );
		// Hidden temp file with a real .webp extension so the editor never
		// second-guesses the format from the filename.
		$tmp = trailingslashit( dirname( $target ) ) . '.pxw-tmp-' . wp_generate_password( 10, false ) . '.webp';

		$editor = wp_get_image_editor( $source );

		if ( is_wp_error( $editor ) ) {
			return array( 'ok' => false, 'reason' => $editor->get_error_message() );
		}

		$editor->set_quality( $quality );

		// Imagick: prefer lossless for PNG line-art/screenshots.
		if ( 'image/png' === $mime && $editor instanceof \WP_Image_Editor_Imagick ) {
			try {
				$ref = new \ReflectionProperty( \WP_Image_Editor_Imagick::class, 'image' );
				$ref->setAccessible( true );
				$img = $ref->getValue( $editor );
				if ( $img instanceof \Imagick ) {
					$img->setOption( 'webp:lossless', 'true' );
				}
			} catch ( \Throwable $e ) {
				// fall through to lossy
			}
		}

		$saved = $editor->save( $tmp, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			self::cleanup( $tmp );
			return array( 'ok' => false, 'reason' => $saved->get_error_message() );
		}

		$written = isset( $saved['path'] ) ? $saved['path'] : $tmp;

		if ( ! file_exists( $written ) ) {
			return array( 'ok' => false, 'reason' => 'encoder wrote nothing' );
		}

		$webp_bytes = (int) filesize( $written );

		if ( $webp_bytes <= 0 || $webp_bytes >= $src_bytes ) {
			self::cleanup( $written );
			return array( 'ok' => false, 'reason' => 'no_gain', 'src' => $src_bytes, 'webp' => $webp_bytes );
		}

		if ( $written !== $target && ! @rename( $written, $target ) ) {
			// Non-atomic fallback: copy then unlink.
			if ( ! @copy( $written, $target ) ) {
				self::cleanup( $written );
				return array( 'ok' => false, 'reason' => 'could not place target' );
			}
			@unlink( $written );
		}

		@chmod( $target, ( fileperms( $source ) & 0777 ) | 0644 );

		return array( 'ok' => true, 'src' => $src_bytes, 'webp' => $webp_bytes );
	}

	/**
	 * All on-disk files for an attachment: 'full' + each intermediate size.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,array{path:string,width:int,height:int}>
	 */
	public static function attachment_files( $attachment_id ) {
		$full = get_attached_file( $attachment_id );
		$out  = array();

		if ( ! $full ) {
			return $out;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = trailingslashit( dirname( $full ) );

		$out['full'] = array(
			'path'   => $full,
			'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		);

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) ) {
					continue;
				}
				$out[ $size ] = array(
					'path'   => $dir . $info['file'],
					'width'  => isset( $info['width'] ) ? (int) $info['width'] : 0,
					'height' => isset( $info['height'] ) ? (int) $info['height'] : 0,
				);
			}
		}

		return $out;
	}

	/**
	 * @param string $path Source path.
	 * @param array  $info {width,height} hint.
	 * @return float Megapixels.
	 */
	private static function megapixels( $path, $info ) {
		$w = (int) ( $info['width'] ?? 0 );
		$h = (int) ( $info['height'] ?? 0 );

		if ( $w <= 0 || $h <= 0 ) {
			$size = @getimagesize( $path );
			if ( $size ) {
				$w = (int) $size[0];
				$h = (int) $size[1];
			}
		}

		return ( $w * $h ) / 1000000;
	}

	/**
	 * @param string $path File to remove if present.
	 */
	private static function cleanup( $path ) {
		if ( $path && file_exists( $path ) ) {
			@unlink( $path );
		}
	}

	/**
	 * Persist per-attachment status.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Conversion result.
	 */
	private static function save_meta( $attachment_id, array $result ) {
		$signature = Settings::signature();

		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'status'    => $result['status'],
				'sizes'     => $result['sizes'],
				'error'     => $result['error'],
				'signature' => $signature,
				'ts'        => time(),
			)
		);

		// The standalone signature marks "settled under these settings". Written
		// for done / no-gain / deterministic skips; cleared while work remains
		// (partial, failed) so the scan and runner keep seeing the attachment.
		if ( in_array( $result['status'], array( 'done', 'skipped' ), true ) ) {
			update_post_meta( $attachment_id, self::META_SIG, $signature );
		} else {
			delete_post_meta( $attachment_id, self::META_SIG );
		}
	}

	/**
	 * Read per-attachment status.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public static function get_meta( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );

		if ( ! is_array( $meta ) ) {
			// Fall back to the pre-1.0 key and migrate it forward on read.
			$legacy = get_post_meta( $attachment_id, self::META_OLD, true );
			if ( is_array( $legacy ) ) {
				update_post_meta( $attachment_id, self::META, $legacy );
				return $legacy;
			}
		}

		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * Whether an attachment still needs work under the current settings.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function needs_work( $attachment_id ) {
		$meta = self::get_meta( $attachment_id );

		if ( ! $meta ) {
			return true;
		}

		if ( ( $meta['signature'] ?? '' ) !== Settings::signature() ) {
			return true;
		}

		if ( in_array( $meta['status'] ?? '', array( 'partial', 'failed' ), true ) ) {
			return true;
		}

		// Files changed underneath a "done" record — e.g. thumbnails were
		// regenerated with different size names. Any currently-wanted size that
		// isn't recorded (and whose source exists) means there's work to do.
		$recorded = (array) ( $meta['sizes'] ?? array() );

		foreach ( self::attachment_files( $attachment_id ) as $size => $info ) {
			if ( Settings::converts_size( $size )
				&& ! isset( $recorded[ $size ] )
				&& ! empty( $info['path'] )
				&& file_exists( $info['path'] )
			) {
				return true;
			}
		}

		return false;
	}
}
