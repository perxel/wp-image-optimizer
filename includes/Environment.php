<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Probes what the host can actually do. The plugin hard-stops if WebP encoding
 * is unavailable.
 */
class Environment {

	/**
	 * @return array
	 */
	public static function probe() {
		$gd_info    = function_exists( 'gd_info' ) ? gd_info() : array();
		$has_gd     = function_exists( 'imagewebp' );
		$has_imk    = extension_loaded( 'imagick' );
		$imk_webp   = false;

		if ( $has_imk ) {
			try {
				$imk_webp = in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true );
			} catch ( \Throwable $e ) {
				$imk_webp = false;
			}
		}

		$editor_webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

		$disabled_functions = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		$upload = wp_get_upload_dir();

		return array(
			'php_version'      => PHP_VERSION,
			'gd'               => $has_gd,
			'gd_version'       => $gd_info['GD Version'] ?? '',
			'imagick'          => $has_imk,
			'imagick_webp'     => $imk_webp,
			'webp_encode'      => (bool) $editor_webp,
			'imagick_lossless' => $has_imk && $imk_webp,
			'memory_limit'     => self::bytes_from_ini( ini_get( 'memory_limit' ) ),
			'memory_limit_raw' => (string) ini_get( 'memory_limit' ),
			'max_execution'    => (int) ini_get( 'max_execution_time' ),
			'set_time_limit'   => function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled_functions, true ),
			'htaccess_writable' => self::htaccess_writable(),
			'uploads_writable' => wp_is_writable( $upload['basedir'] ),
			'free_disk'        => self::free_disk( $upload['basedir'] ),
			'is_apache'        => self::is_apache(),
		);
	}

	/**
	 * The one blocking requirement.
	 *
	 * @return bool
	 */
	public static function can_convert() {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * @return bool
	 */
	public static function is_apache() {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		return function_exists( 'apache_get_modules' ) || strpos( $server, 'apache' ) !== false || strpos( $server, 'litespeed' ) !== false;
	}

	/**
	 * @return bool|null true/false, or null when the file doesn't exist yet.
	 */
	public static function htaccess_writable() {
		$path = self::htaccess_path();

		if ( file_exists( $path ) ) {
			return wp_is_writable( $path );
		}

		return wp_is_writable( dirname( $path ) );
	}

	/**
	 * @return string
	 */
	public static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return get_home_path() . '.htaccess';
	}

	/**
	 * @param string $dir Directory.
	 * @return int|null Bytes free, or null if unknown.
	 */
	private static function free_disk( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		$bytes = @disk_free_space( $dir );

		return is_numeric( $bytes ) ? (int) $bytes : null;
	}

	/**
	 * @param string $value e.g. "256M".
	 * @return int Bytes. 0 means unlimited (-1) or unparseable.
	 */
	public static function bytes_from_ini( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '-1' === $value ) {
			return 0;
		}

		$unit  = strtolower( substr( $value, -1 ) );
		$num   = (int) $value;

		switch ( $unit ) {
			case 'g':
				$num *= 1024;
				// no break
			case 'm':
				$num *= 1024;
				// no break
			case 'k':
				$num *= 1024;
		}

		return $num;
	}
}
