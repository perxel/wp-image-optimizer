<?php
/**
 * Perxel shared admin UI — master layout (feature sidebar + main).
 *
 * Usage from a plugin admin-page callback:
 *
 *     Perxel_UI_Layout::open( array(
 *         'title'   => __( 'Status', 'my-plugin' ),
 *         'plugin'  => 'My Plugin',
 *         'version' => MY_PLUGIN_VERSION,
 *         'menu'    => array( '' => array( 'my-plugin' => 'Status', 'my-plugin-settings' => 'Settings' ) ),
 *         'current' => 'my-plugin',
 *         'base'    => 'admin.php', // or 'upload.php', etc.
 *     ) );
 *     include __DIR__ . '/views/status.php';
 *     Perxel_UI_Layout::close();
 *
 * @package Perxel_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the shared page chrome around plugin-supplied main content.
 */
final class Perxel_UI_Layout {

	/**
	 * Args from the current open() call, kept for close().
	 *
	 * @var array
	 */
	private static $ctx = array();

	/**
	 * Open the layout: .wrap -> header -> shell -> sidebar -> <main> -> <h1>.
	 *
	 * Keys in $args: `title`, `plugin`, `version`, `current` (active slug),
	 * `menu` (`[ group_label => [ page_slug => link_label ] ]`, '' group = no
	 * heading), `base` (admin file for sidebar links, default admin.php),
	 * `links` (`[ label => url ]` shown top-right), `wrap_class`, `text_domain`.
	 * See ui/README.md.
	 *
	 * @param array $args Layout options.
	 */
	public static function open( $args ) {
		$d = array_merge(
			array(
				'title'       => '',
				'plugin'      => '',
				'version'     => '',
				'menu'        => array(),
				'current'     => '',
				'base'        => 'admin.php',
				'links'       => array(),
				'wrap_class'  => '',
				'text_domain' => 'default',
			),
			$args
		);

		self::$ctx = $d;

		$wrap_class = trim( 'wrap pxui-wrap ' . $d['wrap_class'] );

		echo '<div class="' . esc_attr( $wrap_class ) . '">';

		include __DIR__ . '/partials/header.php';

		echo '<div class="pxui-shell">';

		include __DIR__ . '/partials/sidebar.php';

		echo '<main class="pxui-main">';

		if ( '' !== (string) $d['title'] ) {
			echo '<h1 class="pxui-title">' . esc_html( $d['title'] ) . '</h1>';
		}
	}

	/**
	 * Close the layout opened by open().
	 */
	public static function close() {
		$d = self::$ctx;

		echo '</main>'; // .pxui-main
		echo '</div>';  // .pxui-shell

		include __DIR__ . '/partials/footer.php';

		echo '</div>'; // .wrap

		self::$ctx = array();
	}
}
