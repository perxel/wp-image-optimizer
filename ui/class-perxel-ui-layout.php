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
	 * Page name per admin page, keyed by its `?page=` slug.
	 *
	 * @var array<string,string>
	 */
	private static $titles = array();

	/**
	 * Plugin name appended to every registered page's <title>.
	 *
	 * @var string
	 */
	private static $titles_plugin = '';

	/**
	 * Whether the `admin_title` filter has been wired yet.
	 *
	 * @var bool
	 */
	private static $titles_hooked = false;

	/**
	 * Own the browser <title> for one or more of the kit's admin pages.
	 *
	 * WordPress builds a screen's <title> from its menu entry — "Page ‹ Site —
	 * WordPress" — and a page kept off the menu with `remove_submenu_page()` (the
	 * usual pattern for a settings screen reached only from within the UI) loses
	 * even that, leaving a bare " ‹ Site — WordPress" in the tab. For a kit
	 * screen the sidebar already carries the wp-admin chrome, so the tab reads as
	 * `Site • Page • Plugin` instead.
	 *
	 * Call on `admin_menu` with the same slugs passed to `add_submenu_page()`.
	 * Each value is that page's name; `$plugin` is appended to all of them.
	 * Additive and idempotent — safe to call repeatedly; later keys win.
	 *
	 * @param array  $map    `[ page_slug => page name ]`.
	 * @param string $plugin Plugin name, appended after the page name.
	 */
	public static function set_page_titles( array $map, $plugin = '' ) {
		self::$titles = array_merge( self::$titles, $map );

		if ( '' !== (string) $plugin ) {
			self::$titles_plugin = (string) $plugin;
		}

		if ( ! self::$titles_hooked ) {
			self::$titles_hooked = true;
			add_filter( 'admin_title', array( __CLASS__, 'filter_admin_title' ) );
		}
	}

	/**
	 * Replace the <title> for a registered page with `Site • Page • Plugin`.
	 *
	 * @param string $admin_title Full <title> text WordPress assembled.
	 * @return string
	 */
	public static function filter_admin_title( $admin_title ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selecting which registered title to show.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( '' === $page || ! isset( self::$titles[ $page ] ) ) {
			return $admin_title;
		}

		$parts = array_filter(
			array(
				trim( (string) get_bloginfo( 'name' ) ),
				trim( (string) self::$titles[ $page ] ),
				trim( self::$titles_plugin ),
			),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		return implode( ' • ', $parts );
	}

	/**
	 * Open the layout: .wrap -> shell -> sidebar (sticky brand bar) ->
	 * <main> (sticky title bar).
	 *
	 * Keys in $args: `title`, `plugin`, `version`, `current` (active slug),
	 * `menu` (`[ group_label => [ page_slug => link_label ] ]`, '' group = no
	 * heading), `base` (admin file for sidebar links, default admin.php),
	 * `links` (`[ label => url ]` shown right in the footer), `author`
	 * (`[ 'name' => string, 'url' => string ]` shown left in the footer),
	 * `actions` (trusted HTML — buttons pinned to the right of the title bar; the
	 * house home for a page's Save button, wired to its form with the HTML5
	 * `form` attribute), `wrap_class`, `text_domain`.
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
				'author'      => array(),
				'actions'     => '',
				'wrap_class'  => '',
				'text_domain' => 'default',
			),
			$args
		);

		self::$ctx = $d;

		$wrap_class = trim( 'wrap pxui-wrap ' . $d['wrap_class'] );

		echo '<div class="' . esc_attr( $wrap_class ) . '">';

		echo '<div class="pxui-shell">';

		include __DIR__ . '/partials/sidebar.php';

		echo '<main class="pxui-main">';

		include __DIR__ . '/partials/main-bar.php';

		echo '<div class="pxui-main__body">';

		// WP hoists every .notice to sit after this marker; without it they land
		// inside the sticky title bar.
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Close the layout opened by open().
	 */
	public static function close() {
		$d = self::$ctx;

		echo '</div>'; // .pxui-main__body

		include __DIR__ . '/partials/footer.php';

		echo '</main>'; // .pxui-main
		echo '</div>';  // .pxui-shell
		echo '</div>';  // .wrap

		self::$ctx = array();
	}
}
