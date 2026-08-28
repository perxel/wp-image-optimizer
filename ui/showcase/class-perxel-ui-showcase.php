<?php
/**
 * Perxel shared admin UI — component showcase.
 *
 * A hidden admin page ( Tools > "Perxel UI" ) that renders every component in
 * the real layout. Always registered in the admin. This is the review
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
				'links'   => array( 'Docs' => 'https://github.com/perxel/wp-image-optimizer' ),
				'author'  => array(
					'name' => 'Perxel',
					'url'  => 'https://perxel.com',
				),
				'actions' => '<button type="button" class="button">Secondary</button> '
					. '<button type="button" class="button button-primary">Save changes</button>',
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
		echo Perxel_UI::notice( 'success', 'Saved.', array( 'inline' => true ) );
		echo Perxel_UI::notice( 'warning', '12 items failed. <button class="button button-small">Retry</button>', array( 'inline' => true ) );
		echo Perxel_UI::notice( 'error', 'Something is wrong.', array( 'inline' => true ) );

		echo '<h2>Card</h2>';
		echo Perxel_UI::card(
			array(
				'title'   => 'Estimate savings',
				'body'    => '<p class="pxui-muted">Run a small sample before a full pass.</p>',
				'actions' => '<button class="button">Run estimate</button>',
			)
		);

		echo '<h2>Row groups</h2>';
		echo Perxel_UI::rows(
			array(
				array(
					'title' => 'Environment',
					'rows'  => array(
						array(
							'label'   => 'WebP encoding',
							'content' => 'Imagick',
							'tone'    => 'good',
						),
						array(
							'label'   => 'PHP',
							'content' => PHP_VERSION,
						),
						array(
							'label'   => '.htaccess',
							'content' => 'not writable',
							'tone'    => 'bad',
						),
					),
				),
				array(
					'title' => 'Conversion',
					'rows'  => array(
						array(
							'label'   => 'Convert new uploads',
							'sub'     => 'Runs on every media upload.',
							'content' => Perxel_UI::toggle(
								array(
									'checked' => true,
									'label'   => 'Convert new uploads',
								)
							),
						),
						array(
							'label'   => 'PNG handling',
							'content' => '<select><option>Keep PNG</option><option>Convert to WebP</option></select>',
						),
						array(
							'label'   => 'Sizes to convert',
							'sub'     => 'A "pick several" list — real checkboxes, not toggles.',
							'content' => Perxel_UI::checkbox_group(
								array(
									'name'     => 'demo_sizes',
									'selected' => array( 'full', 'medium' ),
									'options'  => array(
										array(
											'value' => 'full',
											'label' => 'full',
											'sub'   => 'the full-size uploaded image',
										),
										array(
											'value' => 'thumbnail',
											'label' => 'thumbnail',
											'sub'   => 'cropped to 150 × 150 px',
										),
										array(
											'value' => 'medium',
											'label' => 'medium',
											'sub'   => 'up to 300 × 300 px',
										),
									),
								)
							),
						),
						array(
							'label'   => 'Re-scan the library',
							'content' => '<button type="button" class="button button-small">Re-scan</button>',
						),
						array(
							'label'   => 'Rebuilding…',
							'content' => Perxel_UI::spinner(),
						),
						array(
							'summary' => 'Managed .htaccess block',
							'sub'     => 'A disclosure row — click to reveal.',
							'details' => Perxel_UI::code( "# BEGIN Perxel Image Optimizer\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteCond %{HTTP_ACCEPT} image/webp\n  RewriteCond %{REQUEST_FILENAME}.webp -f\n  RewriteRule ^(.+)\\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]\n</IfModule>\n# END Perxel Image Optimizer" ),
						),
					),
				),
			)
		);

		echo '<h2>Code block</h2>';
		echo Perxel_UI::code(
			"$ composer run lint\n$ composer run build\n→ dist/perxel-image-optimizer.zip",
			array( 'label' => 'Build output' )
		);

		echo '<h2>Form controls</h2>';
		echo '<p class="pxui-field"><label><input type="checkbox" checked /> Checkbox renders as a toggle</label></p>';
		echo '<p class="pxui-field"><label><input type="checkbox" class="pxui-checkbox" checked /> With <code>.pxui-checkbox</code> — a real square box</label></p>';
		echo '<p class="pxui-field">Checkbox group: ' . Perxel_UI::checkbox_group(
			array(
				'name'     => 'demo_group',
				'selected' => array( 'a', 'c' ),
				'options'  => array(
					'a' => 'Alpha',
					'b' => 'Beta',
					'c' => 'Gamma',
				),
			)
		) . '</p>';
		echo '<p class="pxui-field">'
			. '<label><input type="radio" name="pxui-demo" checked /> Radio one</label> &nbsp; '
			. '<label><input type="radio" name="pxui-demo" /> Radio two</label></p>';
		echo '<p class="pxui-field"><button type="button" class="button">' . Perxel_UI::spinner() . ' Working</button></p>';

		echo '<h2>Danger zone</h2>';
		echo Perxel_UI::danger_zone(
			'<p><button class="button" data-pxui-confirm="Really?">Remove everything</button></p>'
		);

		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		Perxel_UI_Layout::close();
	}
}
