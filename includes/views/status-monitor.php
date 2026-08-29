<?php
/**
 * Status screen - the live run monitor (running / stalled / paused / complete).
 * Included by views/status.php; assets/admin.js polls
 * `perxel_image_optimizer_progress` and updates the marked spans in place.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array  $snap  Perxel\ImageOptimizer\Ajax::snapshot().
 * @var string $state  running|stalled|paused|complete.
 * @var array  $job    $snap['job'] (Runner::progress()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$job       = $snap['job'];
$failures  = (array) $snap['failures'];
$processed = (int) $job['processed'];
$total     = (int) $job['total'];
$saved     = (int) $job['saved_bytes'];
$log_url   = admin_url( 'tools.php?page=action-scheduler&s=perxel_image_optimizer&status=&orderby=schedule&order=desc' );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

$icons = array(
	'running'  => '<span class="dashicons dashicons-controls-play"></span>',
	'stalled'  => 'warn',
	'paused'   => '<span class="dashicons dashicons-controls-pause"></span>',
	'complete' => 'good',
);

$month_line = ( '' !== $job['month_label'] )
	? sprintf(
		/* translators: 1: month name, 2: current month number, 3: total months. */
		__( '%1$s · month %2$d of %3$d', 'perxel-image-optimizer' ),
		$job['month_label'],
		(int) $job['month_pos'],
		(int) $job['months_total']
	)
	: '';

switch ( $state ) {
	case 'stalled':
		$headline = sprintf(
			/* translators: 1: processed, 2: total. */
			__( 'Conversion stalled at %1$s / %2$s', 'perxel-image-optimizer' ),
			number_format_i18n( $processed ),
			number_format_i18n( $total )
		);
		$sub_extra = '<span class="pxui-muted">' . esc_html__( 'No worker responded recently. Opening this page nudged it; if it keeps stalling see Settings → Environment (Loopback / WP-Cron).', 'perxel-image-optimizer' ) . '</span>';
		break;

	case 'paused':
		$headline  = __( 'Paused', 'perxel-image-optimizer' ) . ( $month_line ? ' - ' . $month_line : '' );
		$sub_extra = '';
		break;

	case 'complete':
		$reason    = $job['finish_reason'];
		$emailed   = ! empty( $snap['settings']['email_report'] );
		$headline  = 'cancelled' === $reason
			? sprintf( /* translators: %s: count. */ __( 'Run cancelled after %s images', 'perxel-image-optimizer' ), number_format_i18n( $processed ) )
			: sprintf( /* translators: %s: count. */ __( 'All %s images converted', 'perxel-image-optimizer' ), number_format_i18n( $processed ) );
		$sub_extra = $emailed ? '<span class="pxui-muted">' . esc_html__( 'A report was emailed.', 'perxel-image-optimizer' ) . '</span>' : '';
		break;

	default: // running.
		$headline  = __( 'Converting…', 'perxel-image-optimizer' ) . ( $month_line ? ' - ' . $month_line : '' );
		$sub_extra = '';
		break;
}

$bar = Perxel_UI::progress_bar(
	(int) $job['percent'],
	array(
		'id'    => 'pxio-bar',
		'label' => '<span id="pxio-count">' . esc_html( sprintf( '%s / %s', number_format_i18n( $processed ), number_format_i18n( $total ) ) ) . '</span>',
	)
);

echo '<div id="pxio-monitor" data-poll="' . esc_attr( in_array( $state, array( 'running', 'stalled' ), true ) ? '1' : '0' ) . '">';

echo Perxel_UI::rows(
	array(
		array(
			'icon'  => $icons[ $state ],
			'label' => '<span id="pxio-headline">' . esc_html( $headline ) . '</span>',
			'sub'   => $bar . ( $sub_extra ? '<div>' . $sub_extra . '</div>' : '' ),
		),
	)
);

$rate_line = sprintf(
	/* translators: 1: images/sec, 2: ETA, 3: failed count, 4: too-large count. */
	__( '%1$s img/s · ETA %2$s · %3$s failed · %4$s too large', 'perxel-image-optimizer' ),
	esc_html( (string) $job['rate'] ),
	esc_html( human_time_diff( time(), time() + (int) $job['eta_seconds'] ) ),
	'<span id="pxio-failed">' . esc_html( number_format_i18n( (int) $job['failed'] ) ) . '</span>',
	'<span id="pxio-large">' . esc_html( number_format_i18n( (int) $job['too_large'] ) ) . '</span>'
);

$proj      = $job['projected'];
$proj_line = sprintf(
	/* translators: 1: saved size, 2: percent, 3: disk size. */
	__( 'Projected &minus;%1$s (&asymp; %2$d%%) · +%3$s disk', 'perxel-image-optimizer' ),
	esc_html( size_format( (int) $proj['saved_bytes'], 1 ) ),
	(int) $proj['percent'],
	esc_html( size_format( (int) $proj['webp_bytes'], 1 ) )
);

echo Perxel_UI::rows(
	array(
		array(
			'title' => __( 'This run', 'perxel-image-optimizer' ),
			'rows'  => array(
				array(
					'label'   => __( 'Converted', 'perxel-image-optimizer' ),
					'content' => '<span id="pxio-m-converted">' . esc_html( number_format_i18n( (int) $job['converted'] ) ) . '</span>',
				),
				array(
					'label'   => __( 'Remaining', 'perxel-image-optimizer' ),
					'content' => '<span id="pxio-m-remaining">' . esc_html( number_format_i18n( (int) $job['remaining'] ) ) . '</span>',
				),
				array(
					'label'   => __( 'Saved so far', 'perxel-image-optimizer' ),
					'content' => '&minus;<span id="pxio-m-saved">' . esc_html( size_format( $saved, 1 ) ) . '</span>',
					'tone'    => 'good',
				),
				array(
					'label'   => __( 'Disk added', 'perxel-image-optimizer' ),
					'content' => '<span id="pxio-m-disk">' . esc_html( size_format( (int) $job['webp_bytes'], 1 ) ) . '</span>',
				),
				array(
					'label'   => __( 'Rate', 'perxel-image-optimizer' ),
					'sub'     => '<span id="pxio-rate-line">' . $rate_line . '</span>',
					'content' => '',
				),
				array(
					'label'   => __( 'Projection', 'perxel-image-optimizer' ),
					'sub'     => '<span id="pxio-proj-line">' . $proj_line . '</span>',
					'content' => '',
				),
			),
		),
	)
);

if ( $failures ) {
	$list = '';
	foreach ( $failures as $row ) {
		$list .= '<li>'
			. ( $row['thumb'] ? '<img src="' . esc_url( $row['thumb'] ) . '" alt="" width="40" height="40" style="vertical-align:middle;border-radius:4px;margin-right:8px" />' : '' )
			. ( $row['edit'] ? '<a href="' . esc_url( $row['edit'] ) . '">' : '' )
			. esc_html( $row['file'] ? $row['file'] : $row['name'] )
			. ( $row['edit'] ? '</a>' : '' )
			. ' - <span class="pxui-muted">' . esc_html( $row['reason'] ) . '</span>'
			. '</li>';
	}

	echo Perxel_UI::rows(
		array(
			array(
				'summary' => sprintf(
					/* translators: 1: failed count, 2: too-large count. */
					__( '%1$d failed · %2$d too large', 'perxel-image-optimizer' ),
					(int) $job['failed'],
					(int) $job['too_large']
				),
				'icon'    => 'warn',
				'details' => '<ul class="pxui-list">' . $list . '</ul>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">'
					. '<input type="hidden" name="action" value="perxel_image_optimizer_retry_failed" />'
					. wp_nonce_field( 'perxel_image_optimizer_retry_failed', '_wpnonce', true, false )
					. '<button type="submit" class="button button-small">' . esc_html__( 'Retry failed', 'perxel-image-optimizer' ) . '</button>'
					. '</form>',
			),
		)
	);
}

echo '<p class="pxui-muted" style="margin-top:16px">';
if ( (int) $job['started_at'] > 0 ) {
	printf(
		/* translators: %s: human time diff. */
		esc_html__( 'Started %s ago · updates live · safe to close this tab.', 'perxel-image-optimizer' ),
		esc_html( human_time_diff( (int) $job['started_at'] ) )
	);
}
echo ' &middot; <a href="' . esc_url( $log_url ) . '">' . esc_html__( 'View background activity →', 'perxel-image-optimizer' ) . '</a>';
echo '</p>';

echo '</div>'; // #pxio-monitor

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
