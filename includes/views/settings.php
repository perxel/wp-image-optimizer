<?php
/**
 * Settings screen — environment, conversion config, serving, cleanup.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array $snap    Perxel\ImageOptimizer\Ajax::snapshot().
 * @var bool  $updated Whether the settings form just saved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$env   = $snap['environment'];
$cfg   = $snap['settings'];
$srv   = $snap['serving'];
$sizes = (array) $cfg['sizes'];

$engine = ! empty( $env['imagick_webp'] )
	? 'Imagick'
	: ( ! empty( $env['gd'] ) ? 'GD ' . $env['gd_version'] : __( 'none', 'perxel-image-optimizer' ) );

$serve_mode = array(
	'apache'   => __( 'Active — via .htaccess', 'perxel-image-optimizer' ),
	'fallback' => __( 'Active — via HTML fallback', 'perxel-image-optimizer' ),
	'off'      => __( 'Off', 'perxel-image-optimizer' ),
);

/**
 * Named WebP quality steps. The label carries the guidance so the row needs
 * no long help text; the stored value is snapped to one of these keys.
 */
$quality_steps  = array(
	60 => __( 'Smallest files (60)', 'perxel-image-optimizer' ),
	70 => __( 'Smaller files (70)', 'perxel-image-optimizer' ),
	80 => __( 'Recommended (80)', 'perxel-image-optimizer' ),
	90 => __( 'Best quality (90)', 'perxel-image-optimizer' ),
);
$quality_select = static function ( $id, $name, $current ) use ( $quality_steps ) {
	$current = (int) $current;
	$keys    = array_keys( $quality_steps );
	$sel     = $keys[0];
	foreach ( $keys as $step ) {
		if ( abs( $step - $current ) < abs( $sel - $current ) ) {
			$sel = $step;
		}
	}

	$out = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
	foreach ( $quality_steps as $value => $text ) {
		$out .= '<option value="' . esc_attr( $value ) . '"' . selected( $sel, $value, false ) . '>'
			. esc_html( $text ) . '</option>';
	}

	return $out . '</select>';
};

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; values escaped inline below.

if ( $updated ) {
	echo Perxel_UI::notice( 'success', esc_html__( 'Settings saved.', 'perxel-image-optimizer' ), array( 'dismissible' => true ) );
}

/* --- Conversion --------------------------------------------------- */

$subsizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();

/**
 * Human-readable pixel bounds for a registered image size, so the row shows
 * what each name actually means rather than just "medium".
 */
$size_dimensions = static function ( $name ) use ( $subsizes ) {
	if ( 'full' === $name ) {
		return __( 'the full-size uploaded image', 'perxel-image-optimizer' );
	}
	if ( empty( $subsizes[ $name ] ) ) {
		return '';
	}
	$w = (int) $subsizes[ $name ]['width'];
	$h = (int) $subsizes[ $name ]['height'];

	return empty( $subsizes[ $name ]['crop'] )
		/* translators: 1: width in pixels, 2: height in pixels. */
		? sprintf( __( 'up to %1$d × %2$d px', 'perxel-image-optimizer' ), $w, $h )
		/* translators: 1: width in pixels, 2: height in pixels. */
		: sprintf( __( 'cropped to %1$d × %2$d px', 'perxel-image-optimizer' ), $w, $h );
};

$size_options  = array();
$size_selected = array();
foreach ( (array) $snap['sizes'] as $name ) {
	$size_options[] = array(
		'value' => $name,
		'label' => $name,
		'sub'   => $size_dimensions( $name ),
	);
	if ( in_array( '*', $sizes, true ) || in_array( $name, $sizes, true ) ) {
		$size_selected[] = $name;
	}
}
?>
<form id="pxio-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="perxel_image_optimizer_save_settings" />
	<?php
	wp_nonce_field( 'perxel_image_optimizer_settings' );

	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Conversion', 'perxel-image-optimizer' ),
				'rows'  => array(
					array(
						'label'   => __( 'JPEG → WebP quality', 'perxel-image-optimizer' ),
						'content' => $quality_select( 'pxio-jq', 'jpeg_quality', $cfg['jpeg_quality'] ),
					),
					array(
						'label'   => __( 'PNG → WebP quality', 'perxel-image-optimizer' ),
						'sub'     => ! empty( $env['imagick_lossless'] )
							? esc_html__( 'PNGs convert losslessly here, so this changes file size only.', 'perxel-image-optimizer' )
							: '',
						'content' => $quality_select( 'pxio-pq', 'png_quality', $cfg['png_quality'] ),
					),
					array(
						'label'   => __( 'Convert PNG files', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Otherwise only JPEG files are converted.', 'perxel-image-optimizer' ),
						'content' => Perxel_UI::toggle(
							array(
								'name'    => 'convert_png',
								'checked' => $cfg['convert_png'],
								'label'   => __( 'Convert PNG files', 'perxel-image-optimizer' ),
							)
						),
					),
					array(
						'label'   => __( 'Sizes to convert', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Which thumbnail sizes get a WebP copy.', 'perxel-image-optimizer' ),
						'content' => Perxel_UI::checkbox_group(
							array(
								'name'     => 'sizes',
								'options'  => $size_options,
								'selected' => $size_selected,
							)
						),
					),
					array(
						'label'   => __( 'Skip images larger than', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Bigger images risk running out of memory, so they are left alone.', 'perxel-image-optimizer' ),
						'content' => '<input type="number" id="pxio-mp" name="skip_megapixels" min="1" max="200" value="'
							. esc_attr( $cfg['skip_megapixels'] ) . '" /> ' . esc_html__( 'megapixels', 'perxel-image-optimizer' ),
					),
					array(
						'label'   => __( 'Auto-optimize new uploads', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Convert each image to WebP as it is uploaded.', 'perxel-image-optimizer' ),
						'content' => Perxel_UI::toggle(
							array(
								'name'    => 'convert_on_upload',
								'checked' => $cfg['convert_on_upload'],
								'label'   => __( 'Auto-optimize new uploads', 'perxel-image-optimizer' ),
							)
						),
					),
				),
			),
		)
	);
	// "Save settings" lives in the sticky title bar (Admin::render_settings), wired here via the form id.
	?>
</form>

<?php
/* --- Serving ----------------------------------------------------- */

$serve_status = esc_html__( 'Status:', 'perxel-image-optimizer' ) . ' '
	. esc_html( isset( $serve_mode[ $srv['mode'] ] ) ? $serve_mode[ $srv['mode'] ] : $srv['mode'] );
?>
<div id="serving">
	<?php
	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Serving', 'perxel-image-optimizer' ),
				'rows'  => array(
					array(
						'label'   => __( 'Serve WebP to supported browsers', 'perxel-image-optimizer' ),
						'sub'     => $serve_status,
						'content' => Perxel_UI::toggle(
							array(
								'id'      => 'pxio-serve',
								'checked' => $cfg['serve'],
								'label'   => __( 'Serve WebP to supported browsers', 'perxel-image-optimizer' ),
							)
						),
					),
					array(
						'summary' => __( 'Managed .htaccess block', 'perxel-image-optimizer' ),
						'details' => Perxel_UI::code( $srv['rules_preview'] ),
					),
				),
			),
		)
	);
	?>
</div>

<?php
/* --- Danger zone ------------------------------------------------- */

echo Perxel_UI::danger_zone(
	'<p>'
	. '<button type="button" class="button" id="pxio-purge" data-pxui-confirm="'
	. esc_attr__( 'Delete every .webp file under uploads and reset all plugin data?', 'perxel-image-optimizer' ) . '">'
	. esc_html__( 'Remove all WebP files', 'perxel-image-optimizer' ) . '</button> '
	. '<button type="button" class="button" id="pxio-htaccess-rm">'
	. esc_html__( 'Remove .htaccess block', 'perxel-image-optimizer' ) . '</button>'
	. '</p>'
	. '<p id="pxio-purge-out" class="pxui-muted"></p>'
	. '<p class="pxui-muted">' . esc_html__( 'Deleting the plugin does not undo these.', 'perxel-image-optimizer' ) . '</p>'
);

/* --- Environment --------------------------------------------------- */

echo Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Environment', 'perxel-image-optimizer' ),
			'rows'  => array(
				array(
					'label'   => __( 'WebP encoding', 'perxel-image-optimizer' ),
					'content' => $env['webp_encode']
						? esc_html__( 'Available', 'perxel-image-optimizer' )
						: esc_html__( 'Unavailable — conversion disabled', 'perxel-image-optimizer' ),
					'tone'    => $env['webp_encode'] ? 'good' : 'bad',
				),
				array(
					'label'   => __( 'Engine', 'perxel-image-optimizer' ),
					'content' => esc_html( $engine ) . ( ! empty( $env['imagick_lossless'] ) ? ' · ' . esc_html__( 'PNG lossless available', 'perxel-image-optimizer' ) : '' ),
				),
				array(
					'label'   => __( 'PHP', 'perxel-image-optimizer' ),
					'content' => esc_html( $env['php_version'] ),
				),
				array(
					'label'   => __( 'Memory limit', 'perxel-image-optimizer' ),
					'content' => esc_html( $env['memory_limit_raw'] ),
				),
				array(
					'label'   => __( 'Max execution time', 'perxel-image-optimizer' ),
					'content' => esc_html( $env['max_execution'] . 's' )
						. ( $env['set_time_limit'] ? '' : ' · ' . esc_html__( 'set_time_limit blocked', 'perxel-image-optimizer' ) ),
					'tone'    => $env['set_time_limit'] ? null : 'warn',
				),
				array(
					'label'   => __( 'Server', 'perxel-image-optimizer' ),
					'content' => $env['is_apache'] ? 'Apache / LiteSpeed' : esc_html__( 'Other (fallback serving)', 'perxel-image-optimizer' ),
				),
				array(
					'label'   => __( '.htaccess', 'perxel-image-optimizer' ),
					'content' => $env['htaccess_writable']
						? esc_html__( 'Writable', 'perxel-image-optimizer' )
						: esc_html__( 'Not writable', 'perxel-image-optimizer' ),
					'tone'    => $env['htaccess_writable'] ? null : 'warn',
				),
				array(
					'label'   => __( 'Free disk', 'perxel-image-optimizer' ),
					'content' => null === $env['free_disk']
						? esc_html__( 'unknown', 'perxel-image-optimizer' )
						: '~' . esc_html( size_format( (int) $env['free_disk'] ) ),
				),
			),
		),
	)
);

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
