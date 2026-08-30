<?php
/**
 * Perxel shared admin UI — component showcase.
 *
 * Renders every component in the real layout — the review surface: change a
 * component, reload this page, see it everywhere. By default it self-registers
 * as a hidden page under Tools ("Perxel UI"). A plugin that would rather host
 * the showcase as one of its own screens defines `PERXEL_UI_SHOWCASE_HOSTED`
 * before the kit boots (suppressing the Tools page) and echoes
 * `Perxel_UI_Showcase::body()` between its own layout open/close.
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
	 * Hook registration. Skipped when a plugin hosts the showcase itself
	 * (`PERXEL_UI_SHOWCASE_HOSTED`) — no Tools page in that case.
	 */
	public static function init() {
		if ( defined( 'PERXEL_UI_SHOWCASE_HOSTED' ) && PERXEL_UI_SHOWCASE_HOSTED ) {
			return;
		}

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
	 * Render the standalone Tools page (kit-hosted).
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

		self::body();

		Perxel_UI_Layout::close();
	}

	/**
	 * Echo just the component showcase — every component in document order, no
	 * layout wrapper. A plugin hosting the showcase as one of its own screens
	 * calls this between its own `Perxel_UI_Layout::open()` / `close()`.
	 */
	public static function body() {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes internally; static demo strings.

		echo '<h2>Progress bar</h2>';
		echo Perxel_UI::progress_bar( 62, array( 'label' => '1,842 / 4,110 · ETA 4m' ) );

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
							'icon'    => 'good',
							'label'   => 'WebP encoding',
							'sub'     => 'Preset status dot — centred against label + sub.',
							'content' => 'Imagick',
							'tone'    => 'good',
						),
						array(
							'icon'    => '<span class="dashicons dashicons-admin-network"></span>',
							'label'   => 'PHP',
							'content' => PHP_VERSION,
						),
						array(
							'icon'    => 'bad',
							'label'   => '.htaccess',
							'content' => 'not writable',
							'tone'    => 'bad',
						),
					),
				),
				array(
					'title' => 'Conversion',
					'note'  => 'A group <code>note</code> — trusted HTML below the card for a description or caveat. <a href="#">Learn more</a> about conversion settings.',
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
							'label'   => 'Skip images larger than',
							'sub'     => 'A number input as row content — sized to its value.',
							'content' => '<input type="number" min="1" max="200" value="24" /> megapixels',
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
						array(
							'summary' => '2025',
							'sub'     => 'Disclosure with a right-edge value — a count sits just left of the chevron.',
							'content' => '8 months &middot; 842 images',
							'details' => Perxel_UI::code( "08/2025   420 images\n07/2025   180 images\n06/2025   242 images" ),
						),
						array(
							'summary' => 'WebP conversion is supported',
							'sub'     => 'Disclosure with an icon — the status dot reads pass/fail closed.',
							'icon'    => 'good',
							'details' => Perxel_UI::code( "Engine       Imagick - PNG lossless available\nPHP          8.2.0\nMemory limit 256M" ),
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

		echo '<h2>Danger row group</h2>';
		echo Perxel_UI::rows(
			array(
				array(
					'title'  => 'Danger zone',
					'danger' => true,
					'note'   => 'In a danger group the <code>note</code> tints muted-red. These actions cannot be undone.',
					'rows'   => array(
						array(
							'label'   => 'Remove all WebP files',
							'sub'     => 'Deletes every .webp file and resets plugin data.',
							'content' => '<button type="button" class="button" data-pxui-confirm="Really?">Remove files</button>',
						),
						array(
							'label'   => 'Remove .htaccess block',
							'sub'     => 'Deletes the managed rewrite rules.',
							'content' => '<button type="button" class="button">Remove block</button>',
						),
					),
				),
			)
		);

		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
