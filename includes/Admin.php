<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages, asset loading, and Media-library integration.
 *
 * Two screens, both under Media, both rendered inside the shared Perxel UI
 * layout (see ui/):
 *   - Status   ( upload.php?page=perxel-image-optimizer )          — the glance + the run button.
 *   - Settings ( upload.php?page=perxel-image-optimizer-settings ) — environment, config, serving, cleanup.
 * Only "Status" shows in WP's Media menu; the sidebar links the two.
 */
class Admin {

	const PAGE          = 'perxel-image-optimizer';
	const PAGE_SETTINGS = 'perxel-image-optimizer-settings';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_perxel_image_optimizer_save_settings', array( $this, 'save_settings' ) );

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
	 * read once. `Version` stays on the constant — it is the canonical runtime
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

		return array_merge( array(
			'title'       => $title,
			'plugin'      => $header['name'],
			'version'     => PERXEL_IMAGE_OPTIMIZER_VERSION,
			'base'        => 'upload.php',
			'wrap_class'  => 'perxel-image-optimizer',
			'current'     => $current,
			'menu'        => array(
				'' => array(
					self::PAGE          => __( 'Status', 'perxel-image-optimizer' ),
					self::PAGE_SETTINGS => __( 'Settings', 'perxel-image-optimizer' ),
				),
			),
			'links'       => array(
				__( 'Docs', 'perxel-image-optimizer' ) => $header['plugin_uri'],
			),
			'author'      => array(
				'name' => $header['author'],
				'url'  => $header['author_uri'],
			),
			'text_domain' => 'perxel-image-optimizer',
		), $extra );
	}

	/**
	 * True when the shared UI kit failed to load — render a plain fallback.
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

		if ( ! $this->ui_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Perxel Image Optimizer', 'perxel-image-optimizer' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The shared Perxel UI library could not be loaded.', 'perxel-image-optimizer' ) . '</p></div></div>';
			return;
		}

		\Perxel_UI_Layout::open( $this->layout_args( self::PAGE, __( 'Status', 'perxel-image-optimizer' ) ) );
		include PERXEL_IMAGE_OPTIMIZER_DIR . 'includes/views/status.php';
		\Perxel_UI_Layout::close();
	}

	/**
	 * Which headline state the Status page is in.
	 *
	 * @param array $snap Ajax::snapshot().
	 * @return string cannot_convert|stale|paused|running|work|serve_off|done
	 */
	public static function status_state( array $snap ) {
		if ( empty( $snap['environment']['webp_encode'] ) ) {
			return 'cannot_convert';
		}

		$run = $snap['run'];

		if ( ! empty( $run['running'] ) ) {
			return 'running';
		}

		if ( ! empty( $run['stale'] ) ) {
			return 'stale';
		}

		if ( (int) $run['remaining'] > 0 && (int) $run['processed'] > 0 ) {
			return 'paused';
		}

		if ( (int) $snap['summary']['pending'] > 0 ) {
			return 'work';
		}

		if ( empty( $snap['settings']['serve'] ) && (int) $snap['report']['converted_files'] > 0 ) {
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

		$snap    = Ajax::snapshot();
		$updated = isset( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag from our own redirect.

		if ( ! $this->ui_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Perxel Image Optimizer', 'perxel-image-optimizer' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The shared Perxel UI library could not be loaded.', 'perxel-image-optimizer' ) . '</p></div></div>';
			return;
		}

		$save = get_submit_button(
			__( 'Save settings', 'perxel-image-optimizer' ),
			'primary',
			'submit',
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
		$skip_megapixels = isset( $_POST['skip_megapixels'] ) ? absint( $_POST['skip_megapixels'] ) : 25;

		Settings::update(
			array(
				'jpeg_quality'      => $jpeg_quality,
				'png_quality'       => $png_quality,
				'convert_png'       => ! empty( $_POST['convert_png'] ),
				'convert_on_upload' => ! empty( $_POST['convert_on_upload'] ),
				'skip_megapixels'   => $skip_megapixels,
				'sizes'             => $sizes,
			)
		);

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
			return '—';
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
