<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration from the pre-1.0 "Perxel WebP" build.
 *
 * The plugin used to ship as an mu-plugin under the perxel_webp_* prefix. This
 * copies that data forward to the perxel_image_optimizer_* prefix. It never
 * deletes the old data — an mu-plugin copy may still be running alongside.
 *
 * Runs from register_activation_hook and, as a safety net, on every plugins_loaded
 * until the version marker is current (covers folder swaps that skip activation).
 */
class Migrator {

	const VERSION_OPTION = 'perxel_image_optimizer_db_version';
	const DB_VERSION     = 1;

	const LEGACY_MARKER = 'Perxel WebP';

	/** old option => new option */
	const OPTION_MAP = array(
		'perxel_webp_settings'   => 'perxel_image_optimizer_settings',
		'perxel_webp_metrics'    => 'perxel_image_optimizer_metrics',
		'perxel_webp_state'      => 'perxel_image_optimizer_state',
		'perxel_webp_serve_mode' => 'perxel_image_optimizer_serve_mode',
		'perxel_webp_purge'      => 'perxel_image_optimizer_purge',
	);

	/**
	 * Idempotent. Cheap to call when already migrated.
	 */
	public static function run() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		self::migrate_options();
		self::migrate_post_meta();
		self::migrate_htaccess();

		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Copy old options to the new keys, only when the new key is absent.
	 */
	private static function migrate_options() {
		foreach ( self::OPTION_MAP as $old => $new ) {
			if ( false !== get_option( $new, false ) ) {
				continue;
			}

			$sentinel = new \stdClass();
			$value    = get_option( $old, $sentinel );

			if ( $value !== $sentinel ) {
				// serve_mode is autoloaded in the original; the rest are not.
				$autoload = ( 'perxel_webp_serve_mode' === $old );
				add_option( $new, $value, '', $autoload ? 'yes' : 'no' );
			}
		}
	}

	/**
	 * Bulk-copy _perxel_webp attachment meta to _perxel_image_optimizer for
	 * every row that does not have the new key yet. One query.
	 */
	private static function migrate_post_meta() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				 SELECT old.post_id, %s, old.meta_value
				 FROM {$wpdb->postmeta} old
				 LEFT JOIN {$wpdb->postmeta} new
				   ON new.post_id = old.post_id AND new.meta_key = %s
				 WHERE old.meta_key = %s AND new.meta_id IS NULL",
				Converter::META,
				Converter::META,
				Converter::META_OLD
			)
		);

		wp_cache_flush();
	}

	/**
	 * Strip the legacy "# BEGIN Perxel WebP" .htaccess block. The new block is
	 * written by Serve::reconcile() on the next admin_init when serving is on.
	 */
	private static function migrate_htaccess() {
		$path = Environment::htaccess_path();

		if ( ! $path || ! file_exists( $path ) || ! is_writable( $path ) ) {
			return;
		}

		if ( false === strpos( (string) file_get_contents( $path ), '# BEGIN ' . self::LEGACY_MARKER ) ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		insert_with_markers( $path, self::LEGACY_MARKER, array() );
	}
}
