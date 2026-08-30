<?php
/**
 * Optimization screen - the live run monitor.
 *
 * One Perxel_UI::rows() group: the run phase is the group *title*, the
 * plain-language "what's happening" sentence is the first row's `sub`, and the
 * group `note` carries the "safe to close this tab" / activity-log line. Every
 * figure is a row in that same group - no second group, no separate headline.
 *
 * Included by views/status.php; assets/admin.js polls
 * `perxel_image_optimizer_progress`, updates the marked spans in place, and
 * reloads on any phase change (queued -> running -> complete) or stall flip.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array  $snap  Perxel\ImageOptimizer\Ajax::snapshot().
 * @var string $state  queued|running|stalled|paused|complete.
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
$cancelled = 'cancelled' === (string) $job['finish_reason'];
$is_done   = 'complete' === $state;
$elapsed   = (int) $job['started_at'] > 0 ? ( time() - (int) $job['started_at'] ) : 0;
$log_url   = admin_url( 'tools.php?page=action-scheduler&s=perxel_image_optimizer&status=&orderby=schedule&order=desc' );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

/*
 * Per-phase: the group title, the first row's label + icon (spinner while
 * working), and the one sentence that tells a non-technical user what to expect.
 */
$row_label = __( 'Progress', 'perxel-image-optimizer' );

switch ( $state ) {
	case 'queued':
		$phase_title = __( 'Queued', 'perxel-image-optimizer' );
		$row_label   = __( 'Waiting', 'perxel-image-optimizer' );
		$row_icon    = Perxel_UI::spinner();
		$sentence    = ( $elapsed > 90 )
			? esc_html__( 'Still queued. If nothing moves in a minute, reload this page once.', 'perxel-image-optimizer' )
			: esc_html__( 'Running in the background - this page reloads by itself once conversion starts.', 'perxel-image-optimizer' );
		break;

	case 'stalled':
		$phase_title = __( 'Stalled', 'perxel-image-optimizer' );
		$row_icon    = 'warn';
		$sentence    = esc_html__( 'No progress for 5 minutes. Resume below, or reload this page. If it keeps happening, check Settings -> Environment.', 'perxel-image-optimizer' );
		break;

	case 'paused':
		$phase_title = __( 'Paused', 'perxel-image-optimizer' );
		$row_icon    = '<span class="dashicons dashicons-controls-pause"></span>';
		$sentence    = esc_html__( 'Converted files are kept. Resume continues from here.', 'perxel-image-optimizer' );
		break;

	case 'complete':
		$phase_title = $cancelled ? __( 'Cancelled', 'perxel-image-optimizer' ) : __( 'Complete', 'perxel-image-optimizer' );
		$row_label   = __( 'Result', 'perxel-image-optimizer' );
		$row_icon    = $cancelled ? 'warn' : 'good';
		$sentence    = $cancelled
			? esc_html(
				sprintf(
					/* translators: %s: image count. */
					__( 'Stopped after %s images. The rest are untouched; converted files are kept.', 'perxel-image-optimizer' ),
					number_format_i18n( $processed )
				)
			)
			: esc_html(
				sprintf(
					/* translators: %s: image count. */
					__( 'All %s images converted.', 'perxel-image-optimizer' ),
					number_format_i18n( $processed )
				)
			);
		if ( ! $cancelled && ! empty( $snap['settings']['email_report'] )
			&& '' !== \Perxel\ImageOptimizer\Settings::report_recipient() ) {
			$sentence .= ' ' . esc_html__( 'A report was emailed.', 'perxel-image-optimizer' );
		}
		break;

	default: // running.
		$phase_title = __( 'Converting', 'perxel-image-optimizer' );
		$row_icon    = Perxel_UI::spinner();
		$sentence    = '';
		break;
}

/* --- Row 1: Waiting / Progress / Result - icon, one sub-line, "<done> / <total>". --- */

/*
 * While it's actively converting, the sub-line under the count is the one
 * number a watcher wants - "about N left". Rate is not its own row. In every
 * other phase the sub-line is the plain-language "what's happening" sentence.
 */
$live_run = 'running' === $state && $processed > 0;

if ( $live_run ) {
	$eta_txt  = (int) $job['eta_seconds'] > 0
		? human_time_diff( time(), time() + (int) $job['eta_seconds'] )
		: __( 'calculating', 'perxel-image-optimizer' );
	$row1_sub = '<span id="pxio-rate-line">'
		/* translators: %s: human time estimate. */
		. esc_html( sprintf( __( 'about %s left', 'perxel-image-optimizer' ), $eta_txt ) )
		. '</span>';
} else {
	$row1_sub = '' !== $sentence ? '<span id="pxio-headnote">' . $sentence . '</span>' : '';
}

$rows = array(
	array(
		'label'   => $row_label,
		'icon'    => $row_icon,
		'sub'     => $row1_sub,
		'content' => '<span id="pxio-count">'
			. esc_html( sprintf( '%s / %s', number_format_i18n( $processed ), number_format_i18n( $total ) ) )
			. '</span>',
	),
);

/* --- Figure rows. --- */

if ( $is_done ) {
	// The payoff ledger - shown only once the run has finished. While a run is
	// live, nobody watching this page is here for byte tallies: they want how
	// far along it is and how much longer (row 1), and whether anything broke
	// ("Not converted", below). The savings land here at the end.
	$rows[] = array(
		'label'   => __( 'Converted', 'perxel-image-optimizer' ),
		'content' => '<span id="pxio-m-converted">' . esc_html( number_format_i18n( (int) $job['converted'] ) ) . '</span>',
	);

	$rows[] = array(
		'label'   => __( 'Saved', 'perxel-image-optimizer' ),
		'content' => '&minus;<span id="pxio-m-saved">' . esc_html( size_format( $saved, 1 ) ) . '</span>',
		'tone'    => 'good',
	);

	$rows[] = array(
		'label'   => __( 'Disk added', 'perxel-image-optimizer' ),
		'content' => '<span id="pxio-m-disk">' . esc_html( size_format( (int) $job['webp_bytes'], 1 ) ) . '</span>',
	);
} elseif ( ! $live_run && $processed > 0 ) {
	// Stalled / paused with work already done: keep the estimate as its own
	// row - the count row's sub-line is showing the phase sentence here.
	$eta_txt = (int) $job['eta_seconds'] > 0
		? human_time_diff( time(), time() + (int) $job['eta_seconds'] )
		: __( 'calculating', 'perxel-image-optimizer' );

	$rows[] = array(
		'label'   => __( 'Rate', 'perxel-image-optimizer' ),
		'sub'     => '<span id="pxio-rate-line">'
			/* translators: %s: human time estimate. */
			. esc_html( sprintf( __( 'about %s left', 'perxel-image-optimizer' ), $eta_txt ) )
			. '</span>',
		'content' => '',
	);
}

/* --- "Not converted" - a disclosure row inside the same group. --- */

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

	$rows[] = array(
		'summary' => __( 'Not converted', 'perxel-image-optimizer' ),
		'icon'    => 'warn',
		'open'    => $is_done,
		'content' => '<span id="pxio-failed">' . esc_html( number_format_i18n( (int) $job['failed'] ) ) . '</span> '
			. esc_html__( 'failed', 'perxel-image-optimizer' ) . ' &middot; '
			. '<span id="pxio-large">' . esc_html( number_format_i18n( (int) $job['too_large'] ) ) . '</span> '
			. esc_html__( 'too large', 'perxel-image-optimizer' ),
		'details' => '<ul class="pxui-list">' . $list . '</ul>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">'
			. '<input type="hidden" name="action" value="perxel_image_optimizer_retry_failed" />'
			. wp_nonce_field( 'perxel_image_optimizer_retry_failed', '_wpnonce', true, false )
			. '<button type="submit" class="button button-small">' . esc_html__( 'Retry failed', 'perxel-image-optimizer' ) . '</button>'
			. '</form>',
	);
}

/*
 * Group note, two lines: the reassurance sentence on top, then a muted meta
 * line ("Started 6 minutes ago &middot; View background activity").
 */
$note_lines = array();

if ( in_array( $state, array( 'queued', 'running' ), true ) ) {
	$note_lines[] = esc_html__( 'You can close this tab - it keeps running in the background, at a pace your server can handle.', 'perxel-image-optimizer' );
}

$meta_parts = array();

$since = $is_done ? (int) $job['finished_at'] : (int) $job['started_at'];
if ( $since > 0 ) {
	$rel = ( time() - $since ) < 60
		? esc_html__( 'just now', 'perxel-image-optimizer' )
		/* translators: %s: human time diff, e.g. "4 mins". */
		: esc_html( sprintf( __( '%s ago', 'perxel-image-optimizer' ), human_time_diff( $since ) ) );

	$meta_parts[] = $is_done
		/* translators: %s: relative time. */
		? sprintf( esc_html__( 'Finished %s', 'perxel-image-optimizer' ), $rel )
		/* translators: %s: relative time. */
		: sprintf( esc_html__( 'Started %s', 'perxel-image-optimizer' ), $rel );
}

$meta_parts[] = '<a href="' . esc_url( $log_url ) . '">' . esc_html__( 'View background activity', 'perxel-image-optimizer' ) . '</a>';
$note_lines[] = implode( ' &middot; ', $meta_parts );

/* --- Render. --- */

echo '<div id="pxio-monitor" data-state="' . esc_attr( $state ) . '" data-poll="'
	. esc_attr( in_array( $state, array( 'queued', 'running', 'stalled' ), true ) ? '1' : '0' ) . '">';

echo Perxel_UI::rows(
	array(
		array(
			'title' => $phase_title,
			'rows'  => $rows,
			'note'  => implode( '<br>', $note_lines ),
		),
	)
);

echo '</div>'; // #pxio-monitor

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
