<?php

namespace Perxel_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Perxel Image Optimizer over SSH: WebP conversion, image size, settings.
 *
 * Registered only when WP-CLI is loaded (Plugin::boot), so a host without it
 * never sees this class. Same code paths as the admin screens, without the
 * browser's time limits.
 */
class Cli {

	/**
	 * The library at a glance and the settings in effect.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perxel-image status
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		Scan::run();
		$s   = Scan::stats();
		$cfg = Settings::all();
		$job = Runner::progress();

		$rows = array(
			'images'           => $s['attachments'],
			'converted'        => $s['converted'],
			'settled'          => $s['settled'] . ' (' . $s['coverage_pct'] . '%)',
			'webp on disk'     => size_format( $s['webp_bytes'], 1 ),
			'bandwidth saved'  => size_format( $s['saved_bytes'], 1 ) . ' (' . $s['saved_pct'] . '%)',
			'bulk run'         => (string) ( $job['phase'] ?? 'idle' ),
			'max_size'         => $cfg['max_size'] > 0 ? $cfg['max_size'] . ' px' : 'no limit',
			'delete_originals' => $cfg['delete_originals'] ? 'yes' : 'no',
			'serve'            => $cfg['serve'] ? 'yes' : 'no',
		);

		foreach ( $rows as $key => $value ) {
			\WP_CLI::log( str_pad( $key, 18 ) . $value );
		}
	}

	/**
	 * Convert images to WebP.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Default: every image still to do (all with --force).
	 *
	 * [--month=<yyyy-mm>]
	 * : Only images uploaded that month.
	 *
	 * [--force]
	 * : Re-encode even when a current WebP copy exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perxel-image convert
	 *     wp perxel-image convert --month=2026-10
	 *     wp perxel-image convert 123 456 --force
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function convert( $args, $assoc_args ) {
		if ( ! Environment::can_convert() ) {
			\WP_CLI::error( 'This server cannot encode WebP.' );
		}

		$force = ! empty( $assoc_args['force'] );
		$ids   = array_map( 'intval', $args );

		if ( ! $ids ) {
			$query = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);

			if ( ! empty( $assoc_args['month'] ) ) {
				if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $assoc_args['month'], $m ) ) {
					\WP_CLI::error( '--month must be yyyy-mm.' );
				}
				$query['date_query'] = array(
					array(
						'year'  => (int) $m[1],
						'month' => (int) $m[2],
					),
				);
			}

			$ids = array_map( 'intval', get_posts( $query ) );

			if ( ! $force ) {
				$ids = array_values( array_filter( $ids, array( Converter::class, 'needs_work' ) ) );
			}
		}

		if ( ! $ids ) {
			\WP_CLI::success( 'Nothing to convert.' );
			return;
		}

		wp_raise_memory_limit( 'image' );

		$tally    = array();
		$src      = 0;
		$webp     = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Converting', count( $ids ) );

		foreach ( $ids as $id ) {
			try {
				$result = Converter::convert_attachment( $id, $force );

				if ( 'attachment busy' === $result['error'] ) {
					// The background catch-up holds this one right now.
					$result['status'] = 'busy';
				} elseif ( 'failed' === $result['status'] ) {
					Failures::record( $id, ( '' !== $result['error'] ? $result['error'] : 'conversion failed' ), 'failed' );
				} else {
					Failures::clear_one( $id );
				}
			} catch ( \Throwable $e ) {
				$result = array(
					'status'     => 'failed',
					'src_bytes'  => 0,
					'webp_bytes' => 0,
				);
				Failures::record( $id, $e->getMessage(), 'failed' );
			}

			$tally[ $result['status'] ] = ( $tally[ $result['status'] ] ?? 0 ) + 1;
			$src                       += (int) $result['src_bytes'];
			$webp                      += (int) $result['webp_bytes'];
			$progress->tick();
		}

		$progress->finish();
		Scan::mark_stale();

		$parts = array();
		foreach ( $tally as $status => $n ) {
			$parts[] = $status . ' ' . $n;
		}

		\WP_CLI::success(
			sprintf(
				'%d images: %s. WebP written %s for %s of originals.',
				count( $ids ),
				implode( ', ', $parts ),
				size_format( $webp, 1 ),
				size_format( $src, 1 )
			)
		);
	}

	/**
	 * Shrink the existing library: delete kept originals, scale down big images.
	 *
	 * Deletes the originals WordPress kept beside scaled uploads, and scales
	 * every full-size image over max_size down in place. Cannot be undone.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would change and the space freed by the originals; change nothing.
	 *
	 * [--yes]
	 * : Do it without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perxel-image cleanup --dry-run
	 *     wp perxel-image cleanup --yes
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function cleanup( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$max     = (int) Settings::get( 'max_size' );

		if ( ! $dry_run ) {
			\WP_CLI::confirm(
				sprintf(
					'Delete the kept originals and scale every image over %s down, in place? This cannot be undone.',
					$max > 0 ? $max . ' px' : '(no limit: originals only)'
				),
				$assoc_args
			);

			$start = Resizer::start();
			if ( is_wp_error( $start ) ) {
				\WP_CLI::error( $start->get_error_message() );
			}
		}

		$each = static function ( $id, $result ) use ( $dry_run ) {
			$what = array();
			if ( $result['original'] > 0 ) {
				$what[] = 'original ' . size_format( $result['original'], 1 );
			}
			if ( $result['resized'] ) {
				$what[] = $result['from'] . ' → ' . $result['to'];
			}
			if ( '' !== $result['error'] ) {
				$what[] = 'FAILED: ' . $result['error'];
			}
			\WP_CLI::log( sprintf( '%s#%d %s: %s', $dry_run ? '[dry run] ' : '', $id, basename( (string) get_attached_file( $id ) ), implode( ', ', $what ) ) );
		};

		$state = Resizer::step( 0, $dry_run, $each );

		\WP_CLI::success(
			sprintf(
				'%s %d images checked: %d originals %s, %d %s, %d failed. Space %s: %s%s.',
				$dry_run ? 'Dry run.' : 'Done.',
				(int) $state['checked'],
				(int) $state['original'],
				$dry_run ? 'to delete' : 'deleted',
				(int) $state['resized'],
				$dry_run ? 'to scale down' : 'scaled down',
				(int) $state['failed'],
				$dry_run ? 'freed by the originals' : 'freed',
				size_format( (int) $state['freed'], 1 ),
				$dry_run && $state['resized'] ? ' (plus what scaling down saves)' : ''
			)
		);

		if ( ! $dry_run && $state['resized'] ) {
			\WP_CLI::log( 'Their WebP copies are queued again; run `wp perxel-image convert` to do it now.' );
		}
	}

	/**
	 * Show or change settings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : get or set.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<key>]
	 * : Setting name (all settings when omitted with get).
	 *
	 * [<value>]
	 * : New value for set (true/false for switches).
	 *
	 * ## EXAMPLES
	 *
	 *     wp perxel-image settings get
	 *     wp perxel-image settings set max_size 2560
	 *     wp perxel-image settings set delete_originals false
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function settings( $args, $assoc_args ) {
		$action = $args[0];
		$key    = $args[1] ?? '';
		$all    = Settings::all();

		if ( 'get' === $action ) {
			$show = '' === $key ? $all : array( $key => $all[ $key ] ?? null );
			foreach ( $show as $name => $value ) {
				\WP_CLI::log( str_pad( $name, 18 ) . ( is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- CLI output of a setting.
			}
			return;
		}

		// The soft kill switch is not a setting update() saves (and with it on,
		// this command is not registered).
		$keys = array_diff( array_keys( Settings::defaults() ), array( 'disabled' ) );

		if ( '' === $key || ! in_array( $key, $keys, true ) || ! isset( $args[2] ) ) {
			\WP_CLI::error( 'Usage: wp perxel-image settings set <key> <value>. Keys: ' . implode( ', ', $keys ) );
		}

		$value = $args[2];
		if ( is_bool( Settings::defaults()[ $key ] ) ) {
			$value = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		} elseif ( 'sizes' === $key ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}

		// update() resets the switches it is not given, so hand it every value.
		$all[ $key ] = $value;
		$saved       = Settings::update( $all );

		( new Serve() )->reconcile();
		Scan::mark_stale();

		\WP_CLI::success( $key . ' = ' . ( is_scalar( $saved[ $key ] ) ? var_export( $saved[ $key ], true ) : wp_json_encode( $saved[ $key ] ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- CLI output of a setting.
	}
}
