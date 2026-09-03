<?php
/**
 * Plugin Name:       Perxel Image Optimizer
 * Plugin URI:        https://github.com/perxel/wp-image-optimizer
 * Description:        Local WebP conversion for your media library. No third-party CDN or external service, free, and it runs in the background. Serves WebP via a managed .htaccess block with a picture-tag fallback.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Perxel
 * Author URI:        https://perxel.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perxel-image-optimizer
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PERXEL_IMAGE_OPTIMIZER_VERSION', '1.1.0' );
define( 'PERXEL_IMAGE_OPTIMIZER_FILE', __FILE__ );
define( 'PERXEL_IMAGE_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERXEL_IMAGE_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for Perxel\ImageOptimizer\* -> includes/*.php.
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'Perxel\\ImageOptimizer\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'Perxel\\ImageOptimizer\\' ) );
		$path     = PERXEL_IMAGE_OPTIMIZER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Action Scheduler - bundled background job runner (vendored, committed; not
 * Composer-managed here). Self-negotiates its version when several active
 * plugins ship a copy, so loading it unconditionally is safe. See CLAUDE.md
 * for how to refresh the vendored copy. Guarded so a botched deploy degrades
 * (no background runner) rather than fatals on every request.
 */
if ( is_readable( __DIR__ . '/vendor/action-scheduler/action-scheduler.php' ) ) {
	require_once __DIR__ . '/vendor/action-scheduler/action-scheduler.php';
}

/**
 * Shared Perxel admin UI. Standalone, versioned independently of this plugin;
 * see ui/README.md. Overwriting the ui/ folder cannot break plugin behaviour.
 */
define( 'PERXEL_UI_SHOWCASE_HOSTED', true ); // We host the kit showcase as our 3rd screen; see Admin::render_ui().
require_once __DIR__ . '/ui/loader.php';
Perxel_UI_Loader::register( '0.15.0', __DIR__ . '/ui', plugins_url( 'ui', __FILE__ ) );

register_deactivation_hook( __FILE__, array( '\Perxel\ImageOptimizer\Serve', 'on_deactivate' ) );
register_deactivation_hook( __FILE__, array( '\Perxel\ImageOptimizer\Runner', 'pause' ) ); // Freeze a bulk run; Resume picks it up on reactivation.

add_action(
	'plugins_loaded',
	function () {
		\Perxel\ImageOptimizer\Plugin::instance()->boot();
	}
);
