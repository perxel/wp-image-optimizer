<?php
/**
 * Perxel shared admin UI — component showcase.
 *
 * A hidden admin page ( Tools > "Perxel UI" ) that renders every component in
 * the real layout. Registered only when WP_DEBUG is on. This is the review
 * surface: change a component, reload this page, see it everywhere.
 *
 * @package Perxel_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the kitchen-sink page.
 */
final class Perxel_UI_Showcase {

	const SLUG = 'perxel-ui-showcase';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Hidden page under Tools.
	 */
	public static function menu() {
		add_submenu_page(
			'tools.php',
			'Perxel UI',
			'Perxel UI',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the kit assets on the showcase screen.
	 *
	 * @param string $hook Current screen hook.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG === $hook ) {
			Perxel_UI::enqueue();
		}
	}

	/**
	 * Render the showcase.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Perxel_UI_Layout::open(
			array(
				'title'   => 'Component showcase',
				'plugin'  => 'Perxel UI',
				'version' => defined( 'PERXEL_UI_VERSION' ) ? PERXEL_UI_VERSION : '',
				'menu'    => array(
					'Kit' => array( self::SLUG => 'Showcase' ),
				),
				'current' => self::SLUG,
				'base'    => 'tools.php',
			)
		);

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes internally; static demo strings.

		echo '<h2>Panel</h2>';
		echo Perxel_UI::panel(
			array(
				'status'  => 'action',
				'icon'    => 'update',
				'title'   => '128 items need attention.',
				'body'    => '<p>Short explanation of what the button does.</p>',
				'actions' => '<button class="button button-primary button-hero">Do the thing</button>',
			)
		);
		echo Perxel_UI::panel(
			array(
				'status' => 'success',
				'icon'   => 'yes-alt',
				'title'  => 'Everything is done.',
			)
		);
		echo Perxel_UI::panel(
			array(
				'status'   => 'info',
				'icon'     => 'controls-play',
				'title'    => 'Working…',
				'progress' => 62,
				'body'     => '<p class="pxui-progress__label">1,842 / 4,110 · ETA 4m</p>',
				'actions'  => '<button class="button">Pause</button>',
			)
		);

		echo '<h2>Stat grid</h2>';
		echo Perxel_UI::stat_grid(
			array(
				array(
					'label' => 'Library',
					'value' => '1,240',
					'sub'   => 'images',
				),
				array(
					'label' => 'Converted',
					'value' => '7,284',
					'sub'   => '98% coverage',
					'bar'   => 98,
				),
				array(
					'label' => 'Unconverted',
					'value' => '128',
					'sub'   => '12 failed',
					'tone'  => 'warn',
				),
				array(
					'label' => 'Saved',
					'value' => '&minus;340 MB',
					'sub'   => '62% smaller',
					'tone'  => 'good',
				),
			)
		);

		echo '<h2>Notices</h2>';
		echo Perxel_UI::notice( 'success', 'Saved.' );
		echo Perxel_UI::notice( 'warning', '12 items failed. <button class="button button-small">Retry</button>' );
		echo Perxel_UI::notice( 'error', 'Something is wrong.' );

		echo '<h2>Card</h2>';
		echo Perxel_UI::card(
			array(
				'title'   => 'Estimate savings',
				'body'    => '<p class="pxui-muted">Run a small sample before a full pass.</p>',
				'actions' => '<button class="button">Run estimate</button>',
			)
		);

		echo '<h2>Spec table</h2>';
		echo Perxel_UI::spec_table(
			array(
				array(
					'label' => 'WebP encoding',
					'value' => 'Imagick',
					'tone'  => 'good',
				),
				array(
					'label' => 'PHP',
					'value' => PHP_VERSION,
				),
				array(
					'label' => '.htaccess',
					'value' => 'not writable',
					'tone'  => 'bad',
				),
			)
		);

		echo '<h2>Danger zone</h2>';
		echo Perxel_UI::danger_zone(
			'<p><button class="button" data-pxui-confirm="Really?">Remove everything</button></p>'
		);

		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		Perxel_UI_Layout::close();
	}
}
