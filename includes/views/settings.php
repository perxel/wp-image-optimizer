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

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; values escaped inline below.

if ( $updated ) {
	echo Perxel_UI::notice( 'success', esc_html__( 'Settings saved.', 'perxel-image-optimizer' ), array( 'dismissible' => true ) );
}

/* --- Environment ------------------------------------------------------ */

echo '<h2>' . esc_html__( 'Environment', 'perxel-image-optimizer' ) . '</h2>';
echo Perxel_UI::spec_table(
	array(
		array(
			'label' => __( 'WebP encoding', 'perxel-image-optimizer' ),
			'value' => $env['webp_encode']
				? esc_html__( 'Available', 'perxel-image-optimizer' )
				: esc_html__( 'Unavailable — conversion disabled', 'perxel-image-optimizer' ),
			'tone'  => $env['webp_encode'] ? 'good' : 'bad',
		),
		array(
			'label' => __( 'Engine', 'perxel-image-optimizer' ),
			'value' => esc_html( $engine ) . ( ! empty( $env['imagick_lossless'] ) ? ' · ' . esc_html__( 'PNG lossless available', 'perxel-image-optimizer' ) : '' ),
		),
		array(
			'label' => __( 'PHP', 'perxel-image-optimizer' ),
			'value' => esc_html( $env['php_version'] ),
		),
		array(
			'label' => __( 'Memory limit', 'perxel-image-optimizer' ),
			'value' => esc_html( $env['memory_limit_raw'] ),
		),
		array(
			'label' => __( 'Max execution time', 'perxel-image-optimizer' ),
			'value' => esc_html( $env['max_execution'] . 's' )
				. ( $env['set_time_limit'] ? '' : ' · ' . esc_html__( 'set_time_limit blocked', 'perxel-image-optimizer' ) ),
			'tone'  => $env['set_time_limit'] ? null : 'warn',
		),
		array(
			'label' => __( 'Server', 'perxel-image-optimizer' ),
			'value' => $env['is_apache'] ? 'Apache / LiteSpeed' : esc_html__( 'Other (fallback serving)', 'perxel-image-optimizer' ),
		),
		array(
			'label' => __( '.htaccess', 'perxel-image-optimizer' ),
			'value' => $env['htaccess_writable']
				? esc_html__( 'Writable', 'perxel-image-optimizer' )
				: esc_html__( 'Not writable', 'perxel-image-optimizer' ),
			'tone'  => $env['htaccess_writable'] ? null : 'warn',
		),
		array(
			'label' => __( 'Free disk', 'perxel-image-optimizer' ),
			'value' => null === $env['free_disk']
				? esc_html__( 'unknown', 'perxel-image-optimizer' )
				: '~' . esc_html( size_format( (int) $env['free_disk'] ) ),
		),
	)
);

/* --- Conversion ----------------------------------------------------- */

echo '<h2>' . esc_html__( 'Conversion', 'perxel-image-optimizer' ) . '</h2>';
?>
<form class="pxui-section" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="perxel_image_optimizer_save_settings" />
	<?php wp_nonce_field( 'perxel_image_optimizer_settings' ); ?>

	<div class="pxui-field">
		<label for="pxio-jq"><?php esc_html_e( 'JPEG → WebP quality', 'perxel-image-optimizer' ); ?></label>
		<input type="range" id="pxio-jq" name="jpeg_quality" min="40" max="100" value="<?php echo esc_attr( $cfg['jpeg_quality'] ); ?>" data-pxio-output="pxio-jq-o" />
		<output id="pxio-jq-o"><?php echo esc_html( $cfg['jpeg_quality'] ); ?></output>
	</div>

	<div class="pxui-field">
		<label for="pxio-pq"><?php esc_html_e( 'PNG → WebP quality', 'perxel-image-optimizer' ); ?></label>
		<input type="range" id="pxio-pq" name="png_quality" min="40" max="100" value="<?php echo esc_attr( $cfg['png_quality'] ); ?>" data-pxio-output="pxio-pq-o" />
		<output id="pxio-pq-o"><?php echo esc_html( $cfg['png_quality'] ); ?></output>
		<label style="flex:none;margin-left:16px;">
			<input type="checkbox" name="convert_png" value="1" <?php checked( $cfg['convert_png'] ); ?> />
			<?php esc_html_e( 'Convert PNG files', 'perxel-image-optimizer' ); ?>
		</label>
	</div>

	<div class="pxui-field pxui-field--stack">
		<label><?php esc_html_e( 'Sizes to convert', 'perxel-image-optimizer' ); ?></label>
		<span>
			<?php foreach ( (array) $snap['sizes'] as $name ) : ?>
				<?php $checked = in_array( '*', $sizes, true ) || in_array( $name, $sizes, true ); ?>
				<label style="display:inline-block;margin:4px 16px 4px 0;">
					<input type="checkbox" name="sizes[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $checked ); ?> />
					<?php echo esc_html( $name ); ?>
				</label>
			<?php endforeach; ?>
		</span>
	</div>

	<div class="pxui-field">
		<label for="pxio-mp"><?php esc_html_e( 'Skip images larger than', 'perxel-image-optimizer' ); ?></label>
		<input type="number" id="pxio-mp" name="skip_megapixels" min="1" max="200" value="<?php echo esc_attr( $cfg['skip_megapixels'] ); ?>" />
		<?php esc_html_e( 'megapixels', 'perxel-image-optimizer' ); ?>
	</div>

	<div class="pxui-field">
		<label><?php esc_html_e( 'New uploads', 'perxel-image-optimizer' ); ?></label>
		<label style="flex:none;">
			<input type="checkbox" name="convert_on_upload" value="1" <?php checked( $cfg['convert_on_upload'] ); ?> />
			<?php esc_html_e( 'Convert new uploads automatically', 'perxel-image-optimizer' ); ?>
		</label>
	</div>

	<?php submit_button( __( 'Save settings', 'perxel-image-optimizer' ) ); ?>
</form>

<?php
/* --- Serving -------------------------------------------------------- */

echo '<h2 id="serving">' . esc_html__( 'Serving', 'perxel-image-optimizer' ) . '</h2>';
?>
<div class="pxui-section">
	<div class="pxui-field">
		<label style="flex:none;">
			<input type="checkbox" id="pxio-serve" <?php checked( $cfg['serve'] ); ?> />
			<?php esc_html_e( 'Serve WebP to browsers that support it', 'perxel-image-optimizer' ); ?>
		</label>
		<span class="pxui-muted">
			<?php
			echo esc_html__( 'Status:', 'perxel-image-optimizer' ) . ' ';
			echo esc_html( isset( $serve_mode[ $srv['mode'] ] ) ? $serve_mode[ $srv['mode'] ] : $srv['mode'] );
			?>
		</span>
	</div>
	<p>
		<button type="button" class="button button-small" id="pxio-selftest"><?php esc_html_e( 'Run self-test', 'perxel-image-optimizer' ); ?></button>
		<span id="pxio-selftest-out" class="pxui-muted"></span>
	</p>
	<details>
		<summary><?php esc_html_e( 'Managed .htaccess block', 'perxel-image-optimizer' ); ?></summary>
		<pre class="pxio-block"><?php echo esc_html( $srv['rules_preview'] ); ?></pre>
	</details>
</div>

<?php
/* --- Estimate savings --------------------------------------------- */

$sample     = $snap['sample'];
$sample_out = '<p class="pxui-muted">' . esc_html__( 'Convert a small sample first to estimate total saving and run time.', 'perxel-image-optimizer' ) . '</p>';

if ( ! empty( $sample['samples'] ) ) {
	$est      = isset( $sample['estimate'] ) ? $sample['estimate'] : array();
	$low_min  = max( 1, (int) round( (int) ( $est['estimated_seconds_low'] ?? 0 ) / 60 ) );
	$high_min = max( 1, (int) round( (int) ( $est['estimated_seconds_high'] ?? 0 ) / 60 ) );

	$sample_out = '<div class="pxw-stats">'
		. '<span>' . esc_html__( 'avg ratio', 'perxel-image-optimizer' ) . ' <b>' . esc_html( (string) ( $sample['ratio'] ?? '' ) ) . '</b></span>'
		. '<span>' . esc_html__( 'est. saving', 'perxel-image-optimizer' ) . ' <b>' . esc_html( size_format( (int) ( $est['estimated_saved_bytes'] ?? 0 ), 1 ) ) . '</b></span>'
		. '<span>' . esc_html__( 'est. run time', 'perxel-image-optimizer' ) . ' <b>' . esc_html(
			sprintf(
				/* translators: 1: low minute estimate, 2: high minute estimate. */
				__( '%1$d–%2$d min', 'perxel-image-optimizer' ),
				$low_min,
				$high_min
			)
		) . '</b></span>'
		. '<span>' . esc_html__( 'samples', 'perxel-image-optimizer' ) . ' <b>' . count( $sample['samples'] ) . '</b></span>'
		. '</div>';
}

echo Perxel_UI::card(
	array(
		'title'   => __( 'Estimate savings', 'perxel-image-optimizer' ),
		'id'      => 'pxio-sample-card',
		'body'    => '<div id="pxio-sample-result">' . $sample_out . '</div>',
		'actions' => '<button type="button" class="button" id="pxio-sample">' . esc_html__( 'Run estimate', 'perxel-image-optimizer' ) . '</button>',
	)
);

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

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
