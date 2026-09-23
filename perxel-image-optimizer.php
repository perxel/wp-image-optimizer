<?php
/**
 * Plugin Name:       Perxel Image Optimizer
 * Plugin URI:        https://github.com/perxel/wp-image-optimizer
 * Description:        Local WebP conversion for your media library. No third-party CDN or external service, free, and it runs in the background. Serves WebP via a managed .htaccess block with a picture-tag fallback.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Perxel
 * Author URI:        https://perxel.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perxel-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PERXEL_IMAGE_OPTIMIZER_VERSION', '1.0.1' );
define( 'PERXEL_IMAGE_OPTIMIZER_FILE', __FILE__ );
define( 'PERXEL_IMAGE_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERXEL_IMAGE_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for Perxel_Image_Optimizer\* -> includes/*.php.
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'Perxel_Image_Optimizer\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'Perxel_Image_Optimizer\\' ) );
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
 * Shared Perxel admin UI. Standalone plugin-ui kit (github.com/perxel/wp-plugin-ui),
 * vendored into vendor/perxel-ui/ via bin/update-ui.sh. Overwriting it can never
 * change plugin behaviour - the loader keeps the highest registered version
 * across active plugins and a second copy is inert. We host the kit's component
 * showcase as our 3rd screen; see Admin::render_ui().
 */
if ( ! defined( 'PERXEL_UI_SHOWCASE_HOSTED' ) ) {
	define( 'PERXEL_UI_SHOWCASE_HOSTED', true );
}

if ( is_readable( __DIR__ . '/vendor/perxel-ui/loader.php' ) ) {
	require_once __DIR__ . '/vendor/perxel-ui/loader.php';
	Perxel_UI_Loader::register( '0.23.0', __DIR__ . '/vendor/perxel-ui', untrailingslashit( plugins_url( 'vendor/perxel-ui', __FILE__ ) ) );
}

register_deactivation_hook( __FILE__, array( '\Perxel_Image_Optimizer\Serve', 'on_deactivate' ) );
register_deactivation_hook( __FILE__, array( '\Perxel_Image_Optimizer\Runner', 'pause' ) ); // Freeze a bulk run; Resume picks it up on reactivation.

add_action(
	'plugins_loaded',
	function () {
		\Perxel_Image_Optimizer\Plugin::instance()->boot();
	}
);
