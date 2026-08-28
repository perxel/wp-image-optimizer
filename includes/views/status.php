<?php
/**
 * Status screen — the glance and the one button.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array  $snap  Perxel\ImageOptimizer\Ajax::snapshot().
 * @var string $state Perxel\ImageOptimizer\Admin::status_state() result.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report  = $snap['report'];
$run     = $snap['run'];
$pending = (int) $snap['summary']['pending'];
$failed  = (int) $report['failed'];

$processed = (int) $run['processed'];
$total     = (int) $run['total'];
$run_pct   = $total > 0 ? (int) round( $processed / $total * 100 ) : 0;

$settings_url = admin_url( 'upload.php?page=' . \Perxel\ImageOptimizer\Admin::PAGE_SETTINGS );

/*
 * Headline row. The run loop in assets/admin.js swaps #pxio-headline in place
 * while converting; every other state is rendered here by PHP as a single-row
 * group (icon left, headline + explanation, action on the right).
 */
$row = array();

switch ( $state ) {

	case 'cannot_convert':
		$row = array(
			'icon'    => 'bad',
			'label'   => __( "This host can't encode WebP.", 'perxel-image-optimizer' ),
			'sub'     => esc_html__( 'Neither GD nor Imagick reports WebP support, so conversion is disabled.', 'perxel-image-optimizer' ),
			'content' => '<a class="button" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Settings → Environment', 'perxel-image-optimizer' ) . '</a>',
		);
		break;

	case 'running':
		$row = array(
			'icon'    => '<span class="dashicons dashicons-controls-play"></span>',
			'label'   => __( 'Converting…', 'perxel-image-optimizer' ),
			'sub'     => Perxel_UI::progress_bar(
				$run_pct,
				array( 'label' => '<span id="pxio-run-live">' . esc_html( sprintf( '%d / %d', $processed, $total ) ) . '</span>' )
			),
			'content' => '<button type="button" class="button" id="pxio-resume">'
				. esc_html__( 'Resume in this tab', 'perxel-image-optimizer' ) . '</button>',
		);
		break;

	case 'stale':
	case 'paused':
		$row = array(
			'icon'    => 'warn',
			'label'   => sprintf(
				/* translators: 1: processed count, 2: total count. */
				__( 'A run stopped at %1$d / %2$d.', 'perxel-image-optimizer' ),
				$processed,
				$total
			),
			'sub'     => esc_html__( 'Pick up where it left off, or discard the queue and start fresh.', 'perxel-image-optimizer' ),
			'content' => '<button type="button" class="button button-primary" id="pxio-resume">'
				. esc_html__( 'Resume', 'perxel-image-optimizer' ) . '</button> '
				. '<button type="button" class="button" id="pxio-discard">'
				. esc_html__( 'Discard', 'perxel-image-optimizer' ) . '</button>',
		);
		break;

	case 'work':
		$row = array(
			'icon'    => '<span class="dashicons dashicons-images-alt2"></span>',
			'label'   => sprintf(
				/* translators: %d: number of image files. */
				_n( '%d image file is not WebP yet.', '%d image files are not WebP yet.', $pending, 'perxel-image-optimizer' ),
				$pending
			),
			'content' => '<button type="button" class="button button-primary" id="pxio-start">'
				. esc_html(
					sprintf(
						/* translators: %d: number of image files. */
						_n( 'Convert %d image', 'Convert %d images', $pending, 'perxel-image-optimizer' ),
						$pending
					)
				)
				. '</button>',
		);
		break;

	case 'serve_off':
		$row = array(
			'icon'    => 'warn',
			'label'   => __( 'WebP files exist but are not being served.', 'perxel-image-optimizer' ),
			'content' => '<a class="button button-primary" href="' . esc_url( $settings_url . '#serving' ) . '">'
				. esc_html__( 'Enable serving', 'perxel-image-optimizer' ) . '</a>',
		);
		break;

	default: // done.
		$row = array(
			'icon'    => 'good',
			'label'   => __( 'All images are converted and served as WebP.', 'perxel-image-optimizer' ),
			'content' => '<button type="button" class="button" id="pxio-start">'
				. esc_html__( 'Re-run anyway', 'perxel-image-optimizer' ) . '</button>',
		);
		break;
}

$panel = Perxel_UI::rows( array( array( 'rows' => array( $row ) ) ) );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped above.

echo '<div id="pxio-headline">' . $panel . '</div>';

if ( (int) $report['converted_files'] > 0 ) {
	printf(
		'<p class="pxui-lead">%s <b>%s</b> %s <span class="pxui-muted">(%s)</span></p>',
		esc_html__( "You're saving", 'perxel-image-optimizer' ),
		esc_html( size_format( (int) $report['bandwidth_saved'], 1 ) ),
		esc_html__( 'of image bandwidth', 'perxel-image-optimizer' ),
		esc_html(
			sprintf(
				/* translators: %d: percentage. */
				__( '%d%% smaller', 'perxel-image-optimizer' ),
				(int) $report['bandwidth_pct']
			)
		)
	);
}

echo Perxel_UI::stat_grid(
	array(
		array(
			'label' => __( 'Library', 'perxel-image-optimizer' ),
			'value' => esc_html( number_format_i18n( (int) $snap['summary']['attachments'] ) ),
			'sub'   => esc_html__( 'image attachments', 'perxel-image-optimizer' ),
		),
		array(
			'label' => __( 'Image files', 'perxel-image-optimizer' ),
			'value' => esc_html( number_format_i18n( (int) $report['candidate_files'] ) ),
			'sub'   => esc_html__( 'all sizes served', 'perxel-image-optimizer' ),
		),
		array(
			'label' => __( 'Converted', 'perxel-image-optimizer' ),
			'value' => esc_html( number_format_i18n( (int) $report['converted_files'] ) ),
			'sub'   => esc_html(
				sprintf(
					/* translators: %d: percentage. */
					__( '%d%% coverage', 'perxel-image-optimizer' ),
					(int) $report['coverage_pct']
				)
			),
			'bar'   => (int) $report['coverage_pct'],
		),
		array(
			'label' => __( 'Unconverted', 'perxel-image-optimizer' ),
			'value' => esc_html( number_format_i18n( (int) $report['pending_files'] ) ),
			'sub'   => $failed > 0 ? esc_html(
				sprintf(
					/* translators: %d: number of failed files. */
					_n( '%d failed', '%d failed', $failed, 'perxel-image-optimizer' ),
					$failed
				)
			) : '',
			'tone'  => $report['pending_files'] > 0 ? 'warn' : null,
		),
		array(
			'label' => __( 'Image payload', 'perxel-image-optimizer' ),
			'value' => esc_html( size_format( (int) $report['served_before'], 1 ) ),
			'sub'   => esc_html__( 'without WebP', 'perxel-image-optimizer' ),
		),
		array(
			'label' => __( 'WebP saved', 'perxel-image-optimizer' ),
			'value' => '&minus;' . esc_html( size_format( (int) $report['bandwidth_saved'], 1 ) ),
			'sub'   => esc_html(
				sprintf(
					/* translators: %s: formatted file size. */
					__( '%s on disk', 'perxel-image-optimizer' ),
					size_format( (int) $report['disk_added'], 1 )
				)
			),
			'tone'  => 'good',
		),
	)
);

if ( $failed > 0 ) {
	echo Perxel_UI::notice(
		'warning',
		esc_html(
			sprintf(
				/* translators: %d: number of failed files. */
				_n( '%d image failed to convert.', '%d images failed to convert.', $failed, 'perxel-image-optimizer' ),
				$failed
			)
		)
		. ' <button type="button" class="button button-small" id="pxio-retry-failed">'
		. esc_html__( 'Retry failed', 'perxel-image-optimizer' ) . '</button>'
	);
}

$last = (int) $report['last_full_scan'];
printf(
	'<p class="pxui-muted" style="margin-top:16px;">%s &middot; <button type="button" class="button-link" id="pxio-recalc">%s</button></p>',
	$last > 0
		? esc_html(
			sprintf(
				/* translators: %s: human time difference, e.g. "2 hours". */
				__( 'Last scanned %s ago', 'perxel-image-optimizer' ),
				human_time_diff( $last )
			)
		)
		: esc_html__( 'Never scanned', 'perxel-image-optimizer' ),
	esc_html__( 'Recalculate', 'perxel-image-optimizer' )
);

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
