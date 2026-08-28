<?php
/**
 * Perxel shared admin UI — loader.
 *
 * This file is the ONE part of the `ui/` kit that must stay backwards
 * compatible forever. Every plugin that bundles a copy of `ui/` calls
 * Perxel_UI_Loader::register() from its main file; on `after_setup_theme` the
 * highest registered version wins and loads its classes. A second copy is
 * inert (class_exists guard), so overwriting `ui/` in any one plugin can never
 * fatal or change another plugin's behaviour.
 *
 * @package Perxel_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Perxel_UI_Loader' ) ) {

	/**
	 * Collects every bundled copy of the kit and boots the newest one.
	 */
	final class Perxel_UI_Loader {

		/**
		 * Registered copies, each an array of version, dir and url.
		 *
		 * @var array[]
		 */
		private static $candidates = array();

		/**
		 * Whether the boot hook has been added yet.
		 *
		 * @var bool
		 */
		private static $hooked = false;

		/**
		 * Register a bundled copy of the kit.
		 *
		 * @param string $version SemVer of this copy.
		 * @param string $dir     Absolute path to the `ui` directory (no trailing slash).
		 * @param string $url     Public URL of the same directory (no trailing slash).
		 */
		public static function register( $version, $dir, $url ) {
			self::$candidates[] = array(
				'version' => (string) $version,
				'dir'     => untrailingslashit( $dir ),
				'url'     => untrailingslashit( $url ),
			);

			if ( ! self::$hooked ) {
				self::$hooked = true;
				add_action( 'after_setup_theme', array( __CLASS__, 'boot' ), 1 );
			}
		}

		/**
		 * Pick the newest registered copy and load it.
		 */
		public static function boot() {
			if ( class_exists( 'Perxel_UI' ) || ! self::$candidates ) {
				return;
			}

			usort(
				self::$candidates,
				static function ( $a, $b ) {
					return version_compare( $b['version'], $a['version'] );
				}
			);

			$win = self::$candidates[0];

			if ( ! defined( 'PERXEL_UI_VERSION' ) ) {
				define( 'PERXEL_UI_VERSION', $win['version'] );
				define( 'PERXEL_UI_DIR', $win['dir'] );
				define( 'PERXEL_UI_URL', $win['url'] );
			}

			require $win['dir'] . '/class-perxel-ui.php';
			require $win['dir'] . '/class-perxel-ui-layout.php';

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_admin() ) {
				require $win['dir'] . '/showcase/class-perxel-ui-showcase.php';
				Perxel_UI_Showcase::init();
			}
		}

		/**
		 * Loaded kit version, or null if boot() has not run yet.
		 *
		 * @return string|null
		 */
		public static function version() {
			return defined( 'PERXEL_UI_VERSION' ) ? PERXEL_UI_VERSION : null;
		}

		/**
		 * Assert a minimum kit version. On failure: an admin notice, never a fatal.
		 *
		 * @param string $min Minimum acceptable version.
		 * @return bool
		 */
		public static function require_version( $min ) {
			$have = self::version();
			$ok   = $have && version_compare( $have, $min, '>=' );

			if ( ! $ok ) {
				add_action(
					'admin_notices',
					static function () use ( $min, $have ) {
						echo '<div class="notice notice-error"><p>';
						echo esc_html(
							sprintf(
								'A Perxel plugin needs the shared UI library %1$s or newer (found %2$s). Update the plugin that bundles the older copy.',
								$min,
								$have ? $have : 'none'
							)
						);
						echo '</p></div>';
					}
				);
			}

			return $ok;
		}
	}
}
