<?php
/**
 * Plugin Name:       Perxel Image Optimizer
 * Plugin URI:        https://github.com/perxel/wp-image-optimizer
 * Description:        Convert the media library to WebP and serve it via .htaccess. No SSH, no external service — a bulk run from an admin page plus per-attachment buttons.
 * Version:           0.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            phucbm
 * Author URI:        https://phucbm.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perxel-image-optimizer
 * Domain Path:       /languages
 * Update URI:        https://github.com/perxel/wp-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PERXEL_IMAGE_OPTIMIZER_VERSION', '0.0.1' );
define( 'PERXEL_IMAGE_OPTIMIZER_FILE', __FILE__ );
define( 'PERXEL_IMAGE_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERXEL_IMAGE_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for Perxel\ImageOptimizer\* -> includes/*.php.
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'Perxel\\ImageOptimizer\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( 'Perxel\\ImageOptimizer\\' ) );
		$path     = PERXEL_IMAGE_OPTIMIZER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Shared Perxel admin UI. Standalone, versioned independently of this plugin;
 * see ui/README.md. Overwriting the ui/ folder cannot break plugin behaviour.
 */
require_once __DIR__ . '/ui/loader.php';
Perxel_UI_Loader::register( '0.6.0', __DIR__ . '/ui', plugins_url( 'ui', __FILE__ ) );

register_activation_hook( __FILE__, array( '\Perxel\ImageOptimizer\Migrator', 'run' ) );

add_action(
	'plugins_loaded',
	function () {
		\Perxel\ImageOptimizer\Plugin::instance()->boot();
	}
);
