<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings — stored in a single option, always read through defaults.
 */
class Settings {

	const OPTION = 'perxel_image_optimizer_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'disabled'          => false, // soft kill switch
			'jpeg_quality'      => 80,
			'png_quality'       => 88,
			'convert_png'       => true,
			'sizes'             => array( '*' ), // '*' = every registered size + full
			'convert_on_upload' => true,
			'serve'             => true,
			'skip_megapixels'   => 25,
		);
	}

	/**
	 * Full settings array, defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Persist a partial settings array (sanitised).
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$current = self::all();
		$clean   = $current;

		if ( isset( $input['jpeg_quality'] ) ) {
			$clean['jpeg_quality'] = self::clamp_quality( $input['jpeg_quality'] );
		}
		if ( isset( $input['png_quality'] ) ) {
			$clean['png_quality'] = self::clamp_quality( $input['png_quality'] );
		}
		if ( isset( $input['skip_megapixels'] ) ) {
			$clean['skip_megapixels'] = max( 1, min( 200, (int) $input['skip_megapixels'] ) );
		}

		$clean['convert_png']       = ! empty( $input['convert_png'] );
		$clean['convert_on_upload'] = ! empty( $input['convert_on_upload'] );

		if ( array_key_exists( 'sizes', $input ) ) {
			$clean['sizes'] = self::sanitize_sizes( $input['sizes'] );
		}

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Whether a given registered size name should be converted.
	 *
	 * @param string $size Size name ('full' or a registered size).
	 * @return bool
	 */
	public static function converts_size( $size ) {
		$sizes = (array) self::get( 'sizes' );

		return in_array( '*', $sizes, true ) || in_array( $size, $sizes, true );
	}

	/**
	 * Signature of the settings that affect conversion output. Stored per
	 * attachment so a settings change marks work as stale.
	 *
	 * @return string
	 */
	public static function signature() {
		$all = self::all();

		return md5(
			wp_json_encode(
				array(
					$all['jpeg_quality'],
					$all['png_quality'],
					$all['convert_png'],
					$all['sizes'],
					$all['skip_megapixels'],
				)
			)
		);
	}

	/**
	 * @param mixed $value Raw quality.
	 * @return int
	 */
	private static function clamp_quality( $value ) {
		return max( 1, min( 100, (int) $value ) );
	}

	/**
	 * @param mixed $value Raw sizes input.
	 * @return array
	 */
	private static function sanitize_sizes( $value ) {
		$value = (array) $value;

		if ( in_array( '*', $value, true ) ) {
			return array( '*' );
		}

		$allowed = array_merge( array( 'full' ), get_intermediate_image_sizes() );
		$clean   = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $value ) ) );

		return $clean ? $clean : array( '*' );
	}
}
