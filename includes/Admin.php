<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages, asset loading, and Media-library integration.
 *
 * Screens under Media, all rendered inside the shared Perxel UI layout (see ui/):
 *   - Optimization ( upload.php?page=perxel-image-optimizer )       - the glance + the run button.
 *   - Settings ( upload.php?page=perxel-image-optimizer-settings ) - environment, config, serving, cleanup.
 *   - Perxel UI ( upload.php?page=perxel-image-optimizer-ui )      - the ui/ kit showcase, maintainer-only.
 * Only the "WebP" entry shows in WP's Media menu; the sidebar links the rest.
 */
class Admin {

	const PAGE          = 'perxel-image-optimizer';
	const PAGE_SETTINGS = 'perxel-image-optimizer-settings';
	const PAGE_UI       = 'perxel-image-optimizer-ui';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_perxel_image_optimizer_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_perxel_image_optimizer_reset_settings', array( $this, 'reset_settings' ) );

		// Status-screen actions - plain form POST → do the thing → redirect back.
		foreach ( array( 'scan', 'start', 'pause', 'resume', 'cancel', 'retry_failed', 'test_email' ) as $verb ) {
			add_action( 'admin_post_perxel_image_optimizer_' . $verb, array( $this, 'handle_' . $verb ) );
		}

		// Media library list table.
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_value' ), 10, 2 );

		// Attachment detail (modal + edit screen).
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
	}

	/**
	 * Register both pages under Media, then hide Settings from the menu.
	 */
	public function menu() {
		add_submenu_page(
			'upload.php',
			__( 'WebP', 'perxel-image-optimizer' ),
			__( 'WebP', 'perxel-image-optimizer' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_status' )
		);

		add_submenu_page(
			'upload.php',
			__( 'WebP settings', 'perxel-image-optimizer' ),
			__( 'WebP settings', 'perxel-image-optimizer' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( $this, 'render_settings' )
		);

		remove_submenu_page( 'upload.php', self::PAGE_SETTINGS );

		$titles = array(
			self::PAGE          => __( 'Optimization', 'perxel-image-optimizer' ),
			self::PAGE_SETTINGS => __( 'Settings', 'perxel-image-optimizer' ),
		);

		// The shared UI kit's component showcase, hosted here as a hidden third
		// screen for the maintainer only (the kit's own Tools page is suppressed
		// via PERXEL_UI_SHOWCASE_HOSTED).
		if ( self::can_see_showcase() ) {
			add_submenu_page(
				'upload.php',
				'Perxel UI',
				'Perxel UI',
				'manage_options',
				self::PAGE_UI,
				array( $this, 'render_ui' )
			);
			remove_submenu_page( 'upload.php', self::PAGE_UI );
			$titles[ self::PAGE_UI ] = __( 'Perxel UI', 'perxel-image-optimizer' );
		}

		// Own the browser <title> for the hidden screens - "Site • Page • Plugin".
		// They are off the menu, so WP would otherwise leave the tab blank.
		if ( class_exists( 'Perxel_UI_Layout' ) ) {
			\Perxel_UI_Layout::set_page_titles( $titles, __( 'Image Optimization', 'perxel-image-optimizer' ) );
		}
	}

	/**
	 * Whether the current user may see the bundled UI-kit showcase - the
	 * maintainer only, by login or account email.
	 *
	 * @return bool
	 */
	private static function can_see_showcase() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$user = wp_get_current_user();

		return $user && (
			'phucbm' === $user->user_login
			|| 'phucbm.dev@gmail.com' === strtolower( (string) $user->user_email )
		);
	}

	/**
	 * Enqueue assets on our pages and on the Media library.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		$page_hooks = array(
			'media_page_' . self::PAGE,
			'media_page_' . self::PAGE_SETTINGS,
			'media_page_' . self::PAGE_UI,
		);

		$on_page  = in_array( $hook, $page_hooks, true );
		$on_media = in_array( $hook, array( 'upload.php', 'post.php' ), true );

		if ( ! $on_page && ! $on_media ) {
			return;
		}

		$css = PERXEL_IMAGE_OPTIMIZER_DIR . 'assets/admin.css';
		$ver = file_exists( $css ) ? (string) filemtime( $css ) : PERXEL_IMAGE_OPTIMIZER_VERSION;

		wp_enqueue_style(
			'perxel-image-optimizer-admin',
			PERXEL_IMAGE_OPTIMIZER_URL . 'assets/admin.css',
			array(),
			$ver
		);

		if ( $on_page && class_exists( 'Perxel_UI' ) ) {
			\Perxel_UI::enqueue();
		}

		$script = $on_page ? 'assets/admin.js' : 'assets/media.js';
		$handle = $on_page ? 'perxel-image-optimizer-admin' : 'perxel-image-optimizer-media';
		$abs    = PERXEL_IMAGE_OPTIMIZER_DIR . $script;
		$deps   = $on_page && class_exists( 'Perxel_UI' ) ? array( 'perxel-ui' ) : array();

		wp_enqueue_script(
			$handle,
			PERXEL_IMAGE_OPTIMIZER_URL . $script,
			$deps,
			file_exists( $abs ) ? (string) filemtime( $abs ) : PERXEL_IMAGE_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'PerxelImageOptimizer',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Ajax::NONCE ),
				'onStatus'    => 'media_page_' . self::PAGE === $hook,
				'settingsUrl' => admin_url( 'upload.php?page=' . self::PAGE_SETTINGS ),
				'statusUrl'   => admin_url( 'upload.php?page=' . self::PAGE ),
			)
		);
	}

	/*
	 * Shared layout.
	 */

	/**
	 * The plugin's own file header (Name / Author / Author URI / Plugin URI),
	 * read once. `Version` stays on the constant - it is the canonical runtime
	 * source and avoids a file read.
	 *
	 * @return array
	 */
	private function plugin_header() {
		static $header = null;

		if ( null === $header ) {
			$header = get_file_data(
				PERXEL_IMAGE_OPTIMIZER_FILE,
				array(
					'name'       => 'Plugin Name',
					'plugin_uri' => 'Plugin URI',
					'author'     => 'Author',
					'author_uri' => 'Author URI',
				),
				'plugin'
			);
		}

		return $header;
	}

	/**
	 * Layout args for Perxel_UI_Layout::open().
	 *
	 * @param string $current Active page slug.
	 * @param string $title   Page title.
	 * @param array  $extra   Extra keys merged over the defaults (e.g. `actions`).
	 * @return array
	 */
	private function layout_args( $current, $title, $extra = array() ) {
		$header = $this->plugin_header();

		$pages = array(
			self::PAGE          => __( 'Optimization', 'perxel-image-optimizer' ),
			self::PAGE_SETTINGS => __( 'Settings', 'perxel-image-optimizer' ),
		);

		if ( self::can_see_showcase() ) {
			$pages[ self::PAGE_UI ] = __( 'Perxel UI', 'perxel-image-optimizer' );
		}

		return array_merge(
			array(
				'title'       => $title,
				'plugin'      => $header['name'],
				'version'     => PERXEL_IMAGE_OPTIMIZER_VERSION,
				'base'        => 'upload.php',
				'wrap_class'  => 'perxel-image-optimizer',
				'current'     => $current,
				'menu'        => array( '' => $pages ),
				'links'       => array(
					__( 'Docs', 'perxel-image-optimizer' ) => $header['plugin_uri'],
				),
				'author'      => array(
					'name' => $header['author'],
					'url'  => $header['author_uri'],
				),
				'text_domain' => 'perxel-image-optimizer',
			),
			$extra
		);
	}

	/**
	 * True when the shared UI kit failed to load - render a plain fallback.
	 *
	 * @return bool
	 */
	private function ui_ready() {
		return class_exists( 'Perxel_UI' ) && class_exists( 'Perxel_UI_Layout' );
	}

	/*
	 * Status page.
	 */

	/**
	 * Render the Status screen.
	 */
	public function render_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$snap  = Ajax::snapshot();
		$state = self::status_state( $snap );

		// While a run is active, opening this page makes sure a chunk is queued
		// and pokes WP-Cron - covers hosts with no loopback and lost requests.
		if ( in_array( $state, array( 'queued', 'running', 'stalled' ), true ) ) {
			Runner::nudge();
		}

		if ( ! $this->ui_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Perxel Image Optimizer', 'perxel-image-optimizer' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The shared Perxel UI library could not be loaded.', 'perxel-image-optimizer' ) . '</p></div></div>';
			return;
		}

		\Perxel_UI_Layout::open(
			$this->layout_args(
				self::PAGE,
				__( 'Optimization', 'perxel-image-optimizer' ),
				array( 'actions' => $this->status_actions( $state, $snap ) )
			)
		);
		include PERXEL_IMAGE_OPTIMIZER_DIR . 'includes/views/status.php';
		\Perxel_UI_Layout::close();
	}

	/**
	 * The sticky-title-bar buttons for the current Status state.
	 *
	 * @param string $state Status state.
	 * @param array  $snap  Ajax::snapshot().
	 * @return string Trusted HTML.
	 */
	private function status_actions( $state, array $snap ) {
		$scan_pending = (int) ( $snap['scan']['pending'] ?? 0 );

		switch ( $state ) {
			case 'not_scanned':
				return $this->action_form( 'perxel_image_optimizer_scan', __( 'Scan library', 'perxel-image-optimizer' ), 'primary' );

			case 'ready':
			case 'serve_off':
			case 'done':
				$start = '';
				if ( 'ready' === $state && $scan_pending > 0 ) {
					$start = ' <button type="submit" form="pxio-prepare" class="button button-primary">'
						. esc_html__( 'Start conversion', 'perxel-image-optimizer' ) . '</button>';
				}
				return $this->action_form( 'perxel_image_optimizer_scan', __( 'Scan again', 'perxel-image-optimizer' ) ) . $start;

			case 'queued':
			case 'running':
				return $this->action_form( 'perxel_image_optimizer_pause', __( 'Pause', 'perxel-image-optimizer' ) )
					. ' ' . $this->action_form(
						'perxel_image_optimizer_cancel',
						__( 'Cancel', 'perxel-image-optimizer' ),
						'secondary',
						array( 'confirm' => __( 'Stop the run? Converted files are kept.', 'perxel-image-optimizer' ) )
					);

			case 'stalled':
			case 'paused':
				return $this->action_form( 'perxel_image_optimizer_resume', __( 'Resume', 'perxel-image-optimizer' ), 'primary' )
					. ' ' . $this->action_form(
						'perxel_image_optimizer_cancel',
						__( 'Cancel', 'perxel-image-optimizer' ),
						'secondary',
						array( 'confirm' => __( 'Stop the run? Converted files are kept.', 'perxel-image-optimizer' ) )
					);

			case 'complete':
				return $this->action_form( 'perxel_image_optimizer_scan', __( 'Scan library', 'perxel-image-optimizer' ), 'primary' );
		}

		return '';
	}

	/**
	 * A one-button `admin-post` form, for a sticky-bar action.
	 *
	 * @param string $action admin_post action name (also the nonce action).
	 * @param string $label  Button text.
	 * @param string $style  primary|secondary.
	 * @param array  $args   [ 'confirm' => string ].
	 * @return string
	 */
	private function action_form( $action, $label, $style = 'secondary', $args = array() ) {
		$class   = 'primary' === $style ? 'button button-primary' : 'button';
		$confirm = isset( $args['confirm'] )
			? ' data-pxui-confirm="' . esc_attr( $args['confirm'] ) . '"'
			: '';

		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $class ) . '"' . $confirm . '>' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	/**
	 * Render the bundled UI-kit component showcase (maintainer-only third screen).
	 */
	public function render_ui() {
		if ( ! self::can_see_showcase() || ! $this->ui_ready() || ! class_exists( 'Perxel_UI_Showcase' ) ) {
			return;
		}

		\Perxel_UI_Layout::open( $this->layout_args( self::PAGE_UI, __( 'Perxel UI', 'perxel-image-optimizer' ) ) );
		\Perxel_UI_Showcase::body();
		\Perxel_UI_Layout::close();
	}

	/**
	 * Which state the Status page is in.
	 *
	 * @param array $snap Ajax::snapshot().
	 * @return string cannot_convert|queued|running|stalled|paused|complete|not_scanned|ready|serve_off|done
	 */
	public static function status_state( array $snap ) {
		if ( empty( $snap['environment']['webp_encode'] ) ) {
			return 'cannot_convert';
		}

		$job = $snap['job'];

		switch ( $job['phase'] ) {
			case 'running':
				if ( ! empty( $job['stalled'] ) ) {
					return 'stalled';
				}
				return 0 === (int) $job['processed'] ? 'queued' : 'running';
			case 'paused':
				return 'paused';
			case 'complete':
				return 'complete';
		}

		if ( empty( $snap['scan']['scanned_at'] ) ) {
			return 'not_scanned';
		}

		if ( (int) $snap['scan']['pending'] > 0 ) {
			return 'ready';
		}

		if ( empty( $snap['settings']['serve'] ) && (int) $snap['stats']['converted'] > 0 ) {
			return 'serve_off';
		}

		return 'done';
	}

	/*
	 * Settings page.
	 */

	/**
	 * Render the Settings screen.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$snap = Ajax::snapshot();

		// Display-only flash flags set by our own redirects; no nonce to check.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$updated    = isset( $_GET['updated'] );
		$reset      = isset( $_GET['reset'] );
		$test_email = isset( $_GET['test_email'] ) ? sanitize_key( wp_unslash( $_GET['test_email'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $this->ui_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Perxel Image Optimizer', 'perxel-image-optimizer' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The shared Perxel UI library could not be loaded.', 'perxel-image-optimizer' ) . '</p></div></div>';
			return;
		}

		// Enabled by default so it still works without JS; admin.js disables it
		// until a field changes and guards against leaving with unsaved changes.
		$save = get_submit_button(
			__( 'Save settings', 'perxel-image-optimizer' ),
			'primary',
			'pxio-save',
			false,
			array( 'form' => 'pxio-settings-form' )
		);

		\Perxel_UI_Layout::open(
			$this->layout_args(
				self::PAGE_SETTINGS,
				__( 'Settings', 'perxel-image-optimizer' ),
				array( 'actions' => $save )
			)
		);
		include PERXEL_IMAGE_OPTIMIZER_DIR . 'includes/views/settings.php';
		\Perxel_UI_Layout::close();
	}

	/**
	 * Persist the conversion settings form (admin-post, not AJAX).
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-image-optimizer' ) );
		}

		check_admin_referer( 'perxel_image_optimizer_settings' );

		$all_sizes = array_merge( array( 'full' ), get_intermediate_image_sizes() );
		$posted    = isset( $_POST['sizes'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sizes'] ) ) : array();
		$sizes     = array_values( array_intersect( $all_sizes, $posted ) );

		// All sizes ticked -> store the wildcard so new sizes are covered too.
		if ( count( $sizes ) === count( $all_sizes ) ) {
			$sizes = array( '*' );
		}

		$jpeg_quality    = isset( $_POST['jpeg_quality'] ) ? absint( $_POST['jpeg_quality'] ) : 80;
		$png_quality     = isset( $_POST['png_quality'] ) ? absint( $_POST['png_quality'] ) : 90;
		$skip_megapixels = isset( $_POST['skip_megapixels'] ) ? absint( $_POST['skip_megapixels'] ) : 0;
		$email_report_to = isset( $_POST['email_report_to'] ) ? sanitize_email( wp_unslash( $_POST['email_report_to'] ) ) : '';

		Settings::update(
			array(
				'jpeg_quality'      => $jpeg_quality,
				'png_quality'       => $png_quality,
				'convert_png'       => ! empty( $_POST['convert_png'] ),
				'convert_on_upload' => ! empty( $_POST['convert_on_upload'] ),
				'skip_converted'    => ! empty( $_POST['skip_converted'] ),
				'serve'             => ! empty( $_POST['serve'] ),
				'skip_megapixels'   => $skip_megapixels,
				'sizes'             => $sizes,
				'email_report'      => ! empty( $_POST['email_report'] ),
				'email_report_to'   => $email_report_to,
			)
		);

		// Sync the .htaccess block / cached mode to the just-saved serve setting.
		( new Serve() )->reconcile();

		// Conversion settings may have changed, so the cached scan is now suspect.
		Scan::mark_stale();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SETTINGS,
					'updated' => '1',
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/**
	 * Wipe stored settings back to defaults (admin-post, not AJAX). The serving
	 * block is reconciled to the default serve setting on the redirect load.
	 */
	public function reset_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-image-optimizer' ) );
		}

		check_admin_referer( 'perxel_image_optimizer_reset_settings' );

		Settings::reset();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SETTINGS,
					'reset' => '1',
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/*
	 * Status-screen actions (admin-post → do → redirect back to Status).
	 */

	/**
	 * Run the light library scan.
	 */
	public function handle_scan() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_scan' ) ) {
			Runner::acknowledge_complete(); // Dismiss a finished run's summary.
			Scan::run();
		}
		$this->redirect_to_status();
	}

	/**
	 * Start a bulk run from the prepare form (scope + months). Whether
	 * already-converted images are skipped is a Settings option.
	 */
	public function handle_start() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_start' ) ) {
			$scope  = ( isset( $_POST['scope'] ) && 'months' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ) ? 'months' : 'all';
			$months = isset( $_POST['months'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['months'] ) ) : array();

			Runner::start(
				array(
					'scope'  => $scope,
					'months' => $months,
				)
			);
		}
		$this->redirect_to_status();
	}

	/**
	 * Pause the run.
	 */
	public function handle_pause() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_pause' ) ) {
			Runner::pause();
		}
		$this->redirect_to_status();
	}

	/**
	 * Resume a paused / stalled run.
	 */
	public function handle_resume() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_resume' ) ) {
			Runner::resume();
		}
		$this->redirect_to_status();
	}

	/**
	 * Cancel the run (keeps converted files).
	 */
	public function handle_cancel() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_cancel' ) ) {
			Runner::cancel();
		}
		$this->redirect_to_status();
	}

	/**
	 * Re-queue every failed attachment: drop its settled marker so the next
	 * catch-up pass picks it up.
	 */
	public function handle_retry_failed() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_retry_failed' ) ) {
			foreach ( Failures::failed_ids() as $id ) {
				delete_post_meta( $id, Converter::META_SIG );
			}
			Failures::clear_kind( 'failed' );
			Catchup::schedule();
		}
		$this->redirect_to_status();
	}

	/**
	 * Send a sample completion report to the address in Settings.
	 */
	public function handle_test_email() {
		$sent = false;

		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'perxel_image_optimizer_test_email' ) ) {
			$to     = isset( $_POST['email_report_to'] ) ? sanitize_email( wp_unslash( $_POST['email_report_to'] ) ) : '';
			$to     = $to ? $to : Settings::report_recipient();
			$result = Mailer::send_test( $to );
			$sent   = ( true === $result );

			set_transient(
				'perxel_image_optimizer_test_mail_err_' . get_current_user_id(),
				$sent ? '' : (string) $result,
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SETTINGS,
					'test_email' => $sent ? 'sent' : 'failed',
				),
				admin_url( 'upload.php' )
			) . '#notifications'
		);
		exit;
	}

	/**
	 * Redirect back to the Status screen.
	 */
	private function redirect_to_status() {
		wp_safe_redirect( admin_url( 'upload.php?page=' . self::PAGE ) );
		exit;
	}

	/*
	 * Media library list table.
	 */

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public function media_column( $columns ) {
		$columns['perxel_image_optimizer'] = __( 'WebP', 'perxel-image-optimizer' );

		return $columns;
	}

	/**
	 * @param string $column        Column name.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function media_column_value( $column, $attachment_id ) {
		if ( 'perxel_image_optimizer' !== $column ) {
			return;
		}

		echo '<span class="perxel-image-optimizer-cell" data-id="' . esc_attr( $attachment_id ) . '">'
			. esc_html( self::attachment_status_label( $attachment_id ) )
			. '</span>';

		if ( in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			echo ' <button type="button" class="button-link perxel-image-optimizer-row-action" data-action="convert" data-id="' . esc_attr( $attachment_id ) . '">'
				. esc_html__( 'Convert', 'perxel-image-optimizer' ) . '</button>';
			echo ' <button type="button" class="button-link perxel-image-optimizer-row-action" data-action="remove" data-id="' . esc_attr( $attachment_id ) . '">'
				. esc_html__( 'Remove', 'perxel-image-optimizer' ) . '</button>';
		}
	}

	/**
	 * Human status for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function attachment_status_label( $attachment_id ) {
		if ( ! in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return '-';
		}

		$meta = Converter::get_meta( $attachment_id );

		if ( ! $meta ) {
			return __( 'not converted', 'perxel-image-optimizer' );
		}

		$src  = 0;
		$webp = 0;
		$ok   = 0;
		$all  = 0;

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $row ) {
			++$all;
			if ( ! empty( $row['ok'] ) ) {
				++$ok;
				$src  += (int) ( $row['src'] ?? 0 );
				$webp += (int) ( $row['webp'] ?? 0 );
			}
		}

		if ( 'failed' === ( $meta['status'] ?? '' ) ) {
			return __( 'failed', 'perxel-image-optimizer' );
		}

		$pct = $src > 0 ? round( ( 1 - $webp / $src ) * 100 ) : 0;

		return sprintf(
			/* translators: 1: converted count, 2: total count, 3: percent saved */
			__( '%1$d/%2$d · −%3$d%%', 'perxel-image-optimizer' ),
			$ok,
			$all,
			$pct
		);
	}

	/*
	 * Attachment detail field.
	 */

	/**
	 * @param array    $fields Attachment form fields.
	 * @param \WP_Post $post   Attachment post.
	 * @return array
	 */
	public function attachment_field( $fields, $post ) {
		if ( ! in_array( $post->post_mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
			return $fields;
		}

		$label = self::attachment_status_label( $post->ID );
		$meta  = Converter::get_meta( $post->ID );

		$rows = '';
		if ( $meta && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $row ) {
				if ( ! empty( $row['ok'] ) ) {
					$rows .= sprintf(
						'<div>%s: %s → %s</div>',
						esc_html( $size ),
						esc_html( size_format( (int) ( $row['src'] ?? 0 ) ) ),
						esc_html( size_format( (int) ( $row['webp'] ?? 0 ) ) )
					);
				} elseif ( isset( $row['reason'] ) ) {
					$rows .= sprintf( '<div>%s: %s</div>', esc_html( $size ), esc_html( $row['reason'] ) );
				}
			}
		}

		$html = '<div class="perxel-image-optimizer-attachment" data-id="' . esc_attr( $post->ID ) . '">'
			. '<p class="perxel-image-optimizer-cell"><strong>' . esc_html( $label ) . '</strong></p>'
			. '<div class="perxel-image-optimizer-sizes">' . $rows . '</div>'
			. '<button type="button" class="button perxel-image-optimizer-row-action" data-action="convert" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Convert / Reconvert', 'perxel-image-optimizer' ) . '</button> '
			. '<button type="button" class="button perxel-image-optimizer-row-action" data-action="remove" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Remove WebP', 'perxel-image-optimizer' ) . '</button>'
			. '</div>';

		$fields['perxel_image_optimizer'] = array(
			'label' => __( 'WebP', 'perxel-image-optimizer' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $fields;
	}
}
