<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page, asset loading, and Media-library integration.
 */
class Admin {

	const PAGE = 'perxel-image-optimizer';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// Media library list table.
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_value' ), 10, 2 );

		// Attachment detail (modal + edit screen).
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
	}

	/**
	 * Add the submenu under Media.
	 */
	public function menu() {
		add_media_page(
			__( 'WebP', 'perxel-image-optimizer' ),
			__( 'WebP', 'perxel-image-optimizer' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue assets on our page and on the Media library.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		$on_page  = ( 'media_page_' . self::PAGE ) === $hook;
		$on_media = in_array( $hook, array( 'upload.php', 'post.php' ), true );

		if ( ! $on_page && ! $on_media ) {
			return;
		}

		$css = PERXEL_IMAGE_OPTIMIZER_DIR . 'assets/admin.css';
		$js  = PERXEL_IMAGE_OPTIMIZER_DIR . 'assets/admin.js';

		wp_enqueue_style(
			'perxel-image-optimizer-admin',
			PERXEL_IMAGE_OPTIMIZER_URL . 'assets/admin.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : PERXEL_IMAGE_OPTIMIZER_VERSION
		);

		wp_enqueue_script(
			'perxel-image-optimizer-admin',
			PERXEL_IMAGE_OPTIMIZER_URL . 'assets/admin.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : PERXEL_IMAGE_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			'perxel-image-optimizer-admin',
			'PerxelImageOptimizer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'page'    => $on_page,
			)
		);
	}

	/**
	 * Render the admin page. The heavy lifting is client-side; PHP just prints
	 * the shell + the initial snapshot.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$env      = Environment::probe();
		$snapshot = Ajax::snapshot();

		?>
		<div class="wrap perxel-image-optimizer" id="perxel-image-optimizer-app">
			<h1><?php esc_html_e( 'Perxel Image Optimizer', 'perxel-image-optimizer' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Convert the media library to WebP and serve it via .htaccess. Runs entirely from this page — nothing happens in the background.', 'perxel-image-optimizer' ); ?>
			</p>

			<?php if ( ! $env['webp_encode'] ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'This host cannot encode WebP.', 'perxel-image-optimizer' ); ?></strong>
						<?php esc_html_e( 'Neither GD nor Imagick reports WebP support. Conversion is disabled. Ask the host to enable WebP in PHP’s GD or Imagick build.', 'perxel-image-optimizer' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div id="perxel-image-optimizer-root" data-snapshot="<?php echo esc_attr( wp_json_encode( $snapshot ) ); ?>">
				<noscript><?php esc_html_e( 'JavaScript is required.', 'perxel-image-optimizer' ); ?></noscript>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- *
	 * Media library list table
	 * --------------------------------------------------------------------- */

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
			$all++;
			if ( ! empty( $row['ok'] ) ) {
				$ok++;
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

	/* --------------------------------------------------------------------- *
	 * Attachment detail field
	 * --------------------------------------------------------------------- */

	/**
	 * @param array    $fields     Attachment form fields.
	 * @param \WP_Post  $post      Attachment post.
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
