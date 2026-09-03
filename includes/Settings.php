<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings - stored in a single option, always read through defaults.
 */
class Settings {

	const OPTION = 'perxel_image_optimizer_settings';

	/** Quality steps offered in the admin (WebP encoder quality / effort). */
	const QUALITY_STEPS = array( 60, 70, 80, 90 );

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'disabled'          => false, // soft kill switch
			'jpeg_quality'      => 80,
			'png_quality'       => 90,
			'convert_png'       => true,
			'sizes'             => array( '*' ), // '*' = every registered size + full
			'convert_on_upload' => true,
			'serve'             => false, // opt-in: enabled from the prepare form or Settings, never on activation.
			'skip_megapixels'   => 0, // 0 = auto (Environment::safe_megapixels()).
			'skip_converted'    => true, // bulk runs skip images that already have a current WebP copy
			'email_report'      => false,
			'email_report_to'   => '',
			'fast_intensity'    => 'balanced', // fast-mode pacing: gentle|balanced|turbo. Not part of signature().
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
			$clean['jpeg_quality'] = self::snap_quality( $input['jpeg_quality'] );
		}
		if ( isset( $input['png_quality'] ) ) {
			$clean['png_quality'] = self::snap_quality( $input['png_quality'] );
		}
		if ( isset( $input['skip_megapixels'] ) ) {
			$clean['skip_megapixels'] = max( 0, min( 200, (int) $input['skip_megapixels'] ) );
		}

		$clean['convert_png']       = ! empty( $input['convert_png'] );
		$clean['convert_on_upload'] = ! empty( $input['convert_on_upload'] );

		if ( array_key_exists( 'skip_converted', $input ) ) {
			$clean['skip_converted'] = ! empty( $input['skip_converted'] );
		}

		if ( array_key_exists( 'serve', $input ) ) {
			$clean['serve'] = ! empty( $input['serve'] );
		}

		if ( array_key_exists( 'email_report', $input ) ) {
			$clean['email_report'] = ! empty( $input['email_report'] );
		}

		if ( array_key_exists( 'email_report_to', $input ) ) {
			$clean['email_report_to'] = sanitize_email( (string) $input['email_report_to'] );
		}

		if ( array_key_exists( 'sizes', $input ) ) {
			$clean['sizes'] = self::sanitize_sizes( $input['sizes'] );
		}

		if ( array_key_exists( 'fast_intensity', $input ) ) {
			$intensity               = is_string( $input['fast_intensity'] ) ? $input['fast_intensity'] : '';
			$clean['fast_intensity'] = in_array( $intensity, array( 'gentle', 'balanced', 'turbo' ), true )
				? $intensity
				: 'balanced';
		}

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Wipe stored settings so every key falls back to its default.
	 */
	public static function reset() {
		delete_option( self::OPTION );
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
	 * Where a completion report is sent: the configured address, or an empty
	 * string when the field is blank or invalid - in which case no report is
	 * sent. There is deliberately no silent fallback to the site admin email.
	 *
	 * @return string
	 */
	public static function report_recipient() {
		$to = trim( (string) self::get( 'email_report_to' ) );

		return ( '' !== $to && is_email( $to ) ) ? $to : '';
	}

	/**
	 * @param mixed $value Raw quality.
	 * @return int
	 */
	private static function clamp_quality( $value ) {
		return max( 1, min( 100, (int) $value ) );
	}

	/**
	 * Snap an arbitrary quality value to the nearest step the admin offers.
	 *
	 * @param mixed $value Raw quality.
	 * @return int
	 */
	public static function snap_quality( $value ) {
		$value = self::clamp_quality( $value );
		$best  = self::QUALITY_STEPS[0];

		foreach ( self::QUALITY_STEPS as $step ) {
			if ( abs( $step - $value ) < abs( $best - $value ) ) {
				$best = $step;
			}
		}

		return $best;
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
