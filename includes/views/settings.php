<?php
/**
 * Settings screen - environment, conversion config, serving, cleanup.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array  $snap       Perxel\ImageOptimizer\Ajax::snapshot().
 * @var bool   $updated    Whether the settings form just saved.
 * @var bool   $reset      Whether settings were just reset to defaults.
 * @var string $test_email '', 'sent' or 'failed' after a test-email attempt.
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
} elseif ( $reset ) {
	echo Perxel_UI::notice( 'success', esc_html__( 'Settings reset to defaults.', 'perxel-image-optimizer' ), array( 'dismissible' => true ) );
}

if ( '' !== $test_email ) {
	$mail_err_key = 'perxel_image_optimizer_test_mail_err_' . get_current_user_id();
	$mail_err     = (string) get_transient( $mail_err_key );
	delete_transient( $mail_err_key );

	if ( 'sent' === $test_email ) {
		echo Perxel_UI::notice(
			'success',
			esc_html__( 'Test email handed to the server. If it does not arrive, check spam, then your site mail configuration - "sent" only means the server accepted it.', 'perxel-image-optimizer' ),
			array( 'dismissible' => true )
		);
	} else {
		$msg = esc_html__( 'Test email could not be sent.', 'perxel-image-optimizer' );
		if ( '' !== $mail_err ) {
			$msg .= '<br><span class="pxui-muted">' . esc_html( $mail_err ) . '</span>';
		}
		echo Perxel_UI::notice( 'error', $msg, array( 'dismissible' => true ) );
	}
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
						'sub'     => $env['safe_megapixels'] > 0
							/* translators: %d: megapixels. */
							? esc_html( sprintf( __( 'Bigger images risk running out of memory. Leave at 0 to use this server\'s computed ceiling (%d MP).', 'perxel-image-optimizer' ), (int) $env['safe_megapixels'] ) )
							: esc_html__( 'Bigger images risk running out of memory, so they are left alone. Leave at 0 to use the computed ceiling.', 'perxel-image-optimizer' ),
						'content' => '<input type="number" id="pxio-mp" name="skip_megapixels" min="0" max="200" value="'
							. esc_attr( (int) $cfg['skip_megapixels'] ) . '" /> ' . esc_html__( 'megapixels', 'perxel-image-optimizer' ),
					),
					array(
						'label'   => __( 'Auto-optimize new uploads', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Convert each image to WebP shortly after it is uploaded.', 'perxel-image-optimizer' ),
						'content' => Perxel_UI::toggle(
							array(
								'name'    => 'convert_on_upload',
								'checked' => $cfg['convert_on_upload'],
								'label'   => __( 'Auto-optimize new uploads', 'perxel-image-optimizer' ),
							)
						),
					),
					array(
						'label'   => __( 'Skip already-converted images', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Off makes every bulk run re-encode the whole library, even current WebP copies.', 'perxel-image-optimizer' ),
						'content' => Perxel_UI::toggle(
							array(
								'name'    => 'skip_converted',
								'checked' => ! empty( $cfg['skip_converted'] ),
								'label'   => __( 'Skip already-converted images', 'perxel-image-optimizer' ),
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

$serve_sub = esc_html__(
	'Send the converted .webp files to browsers that support them, via a managed .htaccess rule (or a picture-tag fallback where .htaccess is not available). Off by default. Turning it off serves the originals again for everyone; nothing is deleted.',
	'perxel-image-optimizer'
);
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
						'sub'     => $serve_sub,
						'content' => Perxel_UI::toggle(
							array(
								'name'    => 'serve',
								'form'    => 'pxio-settings-form',
								'checked' => $cfg['serve'],
								'label'   => __( 'Serve WebP to supported browsers', 'perxel-image-optimizer' ),
							)
						),
					),
				),
			),
		)
	);
	?>
</div>

<?php
/* --- Notifications ---------------------------------------------- */

$email_to     = (string) $cfg['email_report_to'];
$report_on    = ! empty( $cfg['email_report'] );
$missing_addr = $report_on && '' === trim( $email_to );

$notif_note = $missing_addr
	? '<span class="pxui-warn">' . esc_html__( 'No address set - no report will be sent. Add one below, or turn the report off.', 'perxel-image-optimizer' ) . '</span>'
	: '';

$notif_rows = array(
	array(
		'label'   => __( 'Email a report when a bulk run finishes', 'perxel-image-optimizer' ),
		'sub'     => esc_html__( 'Converted count, bandwidth saved, disk added, failures. Sent once per run (also on cancel).', 'perxel-image-optimizer' ),
		'content' => Perxel_UI::toggle(
			array(
				'name'    => 'email_report',
				'form'    => 'pxio-settings-form',
				'checked' => $report_on,
				'label'   => __( 'Email a report when a bulk run finishes', 'perxel-image-optimizer' ),
			)
		),
	),
	array(
		'label'   => __( 'Send to', 'perxel-image-optimizer' ),
		'sub'     => esc_html__( 'The report goes to this address. Leave it blank and no report is sent.', 'perxel-image-optimizer' ),
		'content' => '<input type="email" id="pxio-email-to" name="email_report_to" form="pxio-settings-form"'
			. ' value="' . esc_attr( $email_to ) . '" placeholder="you@example.com" class="regular-text" />',
	),
);

// Test-send only makes sense once there's a saved address to send to.
if ( '' !== trim( $email_to ) ) {
	$notif_rows[] = array(
		'label'   => __( 'Test', 'perxel-image-optimizer' ),
		'sub'     => esc_html__( 'Sends a sample report now. Uses the address above; save first if you just changed it and have JavaScript off.', 'perxel-image-optimizer' ),
		'content' => '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="pxio-test-email" style="display:inline">'
			. '<input type="hidden" name="action" value="perxel_image_optimizer_test_email" />'
			. '<input type="hidden" name="email_report_to" value="' . esc_attr( $email_to ) . '" />'
			. wp_nonce_field( 'perxel_image_optimizer_test_email', '_wpnonce', true, false )
			. '<button type="submit" class="button">' . esc_html__( 'Send test email', 'perxel-image-optimizer' ) . '</button>'
			. '</form>',
	);
}
?>
<div id="notifications">
	<?php
	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Notifications', 'perxel-image-optimizer' ),
				'note'  => $notif_note,
				'rows'  => $notif_rows,
			),
		)
	);
	?>
</div>

<?php
/* --- Danger zone ------------------------------------------------- */

echo Perxel_UI::rows(
	array(
		array(
			'title'  => __( 'Danger zone', 'perxel-image-optimizer' ),
			'danger' => true,
			'rows'   => array(
				array(
					'label'   => __( 'Reset settings to defaults', 'perxel-image-optimizer' ),
					'sub'     => esc_html__( 'Restores every conversion and serving option to its default. Converted files and metrics are kept.', 'perxel-image-optimizer' ),
					'content' => '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
						. '<input type="hidden" name="action" value="perxel_image_optimizer_reset_settings" />'
						. wp_nonce_field( 'perxel_image_optimizer_reset_settings', '_wpnonce', true, false )
						. '<button type="submit" class="button" data-pxui-confirm="'
						. esc_attr__( 'Reset all settings to their defaults?', 'perxel-image-optimizer' ) . '">'
						. esc_html__( 'Reset settings', 'perxel-image-optimizer' ) . '</button>'
						. '</form>',
				),
				array(
					'label'   => __( 'Remove all WebP files', 'perxel-image-optimizer' ),
					'sub'     => esc_html__( 'Deletes the .webp copies this plugin created (files named like photo.jpg.webp) and resets all plugin data. Standalone .webp uploads are left alone. Deleting the plugin does not undo this.', 'perxel-image-optimizer' ),
					'content' => '<button type="button" class="button" id="pxio-purge" data-pxui-confirm="'
						. esc_attr__( 'Delete every .webp copy this plugin created and reset all plugin data?', 'perxel-image-optimizer' ) . '">'
						. esc_html__( 'Remove files', 'perxel-image-optimizer' ) . '</button>',
				),
			),
		),
	)
);
echo '<p id="pxio-purge-out" class="pxui-muted"></p>';

/* --- Environment --------------------------------------------------- */

/**
 * One row answers the only question that matters - can this server encode
 * WebP, and with what - and the disclosure spills the full detail for a
 * host that needs to diagnose it.
 */
$env_ok = ! empty( $env['webp_encode'] );

$env_detail = array(
	__( 'Engine', 'perxel-image-optimizer' )             => $engine
		. ( ! empty( $env['imagick_lossless'] ) ? ' - ' . __( 'PNG lossless available', 'perxel-image-optimizer' ) : '' ),
	__( 'PHP', 'perxel-image-optimizer' )                => $env['php_version'],
	__( 'Memory limit', 'perxel-image-optimizer' )       => $env['memory_limit_raw'],
	__( 'Max execution time', 'perxel-image-optimizer' ) => $env['max_execution'] . 's'
		. ( $env['set_time_limit'] ? '' : ' - ' . __( 'set_time_limit blocked', 'perxel-image-optimizer' ) ),
	__( 'Server', 'perxel-image-optimizer' )             => $env['is_apache']
		? 'Apache / LiteSpeed'
		: __( 'Other (fallback serving)', 'perxel-image-optimizer' ),
	__( '.htaccess', 'perxel-image-optimizer' )          => $env['htaccess_writable']
		? __( 'Writable', 'perxel-image-optimizer' )
		: __( 'Not writable', 'perxel-image-optimizer' ),
	__( 'Free disk', 'perxel-image-optimizer' )          => null === $env['free_disk']
		? __( 'unknown', 'perxel-image-optimizer' )
		: '~' . size_format( (int) $env['free_disk'] ),
);

$env_pad   = max( array_map( 'strlen', array_keys( $env_detail ) ) ) + 3;
$env_lines = array();
foreach ( $env_detail as $env_key => $env_value ) {
	$env_lines[] = str_pad( $env_key, $env_pad ) . $env_value;
}

if ( 'apache' === $srv['mode'] ) {
	$serve_row = array(
		'summary' => __( 'WebP serving', 'perxel-image-optimizer' ),
		'sub'     => esc_html__( 'Via .htaccess - covers every image on the site. Expand for the exact rules.', 'perxel-image-optimizer' ),
		'icon'    => 'good',
		'details' => Perxel_UI::code( $srv['rules_preview'] ),
	);
} elseif ( 'fallback' === $srv['mode'] ) {
	$serve_row = array(
		'label' => __( 'WebP serving', 'perxel-image-optimizer' ),
		'sub'   => esc_html__( 'Via an HTML <picture> fallback - reaches images rendered through WordPress, not those hard-coded in a theme.', 'perxel-image-optimizer' ),
		'icon'  => 'warn',
	);
} else {
	$serve_row = array(
		'label' => __( 'WebP serving', 'perxel-image-optimizer' ),
		'sub'   => esc_html__( 'Off - browsers get the original files.', 'perxel-image-optimizer' ),
		'icon'  => 'bad',
	);
}

echo Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Environment', 'perxel-image-optimizer' ),
			'rows'  => array(
				array(
					'summary' => $env_ok
						? __( 'WebP conversion is supported', 'perxel-image-optimizer' )
						: __( 'WebP conversion is not supported', 'perxel-image-optimizer' ),
					'sub'     => $env_ok
						/* translators: %s: image engine name, e.g. Imagick or GD 2.3.0. */
						? esc_html( sprintf( __( 'This server encodes WebP with %s.', 'perxel-image-optimizer' ), $engine ) )
						: esc_html__( 'No WebP-capable image engine is available on this server, so conversion is disabled.', 'perxel-image-optimizer' ),
					'icon'    => $env_ok ? 'good' : 'bad',
					'details' => Perxel_UI::code( implode( "\n", $env_lines ) ),
				),
				$serve_row,
			),
		),
	)
);

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
