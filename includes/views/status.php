<?php
/**
 * Optimization screen - cheap glance, then one of: not-scanned, prepare, or the live
 * monitor. All server-rendered; assets/admin.js only does the prepare-form
 * arithmetic and the monitor poll.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array  $snap  Perxel\ImageOptimizer\Ajax::snapshot().
 * @var string $state Perxel\ImageOptimizer\Admin::status_state() result.
 *   queued|running|stalled|paused|complete route to views/status-monitor.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$job          = $snap['job'];
$scan         = $snap['scan'];
$stats        = $snap['stats'];
$env          = $snap['environment'];
$settings_url = admin_url( 'upload.php?page=' . \Perxel\ImageOptimizer\Admin::PAGE_SETTINGS );
$free_disk    = isset( $env['free_disk'] ) ? (int) $env['free_disk'] : 0;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

/**
 * The "At a glance" tiles - exact figures from the last scan (Scan::stats()).
 */
$glance = static function () use ( $stats, $snap ) {
	$rows = array(
		array(
			'label'   => __( 'Library', 'perxel-image-optimizer' ),
			'sub'     => esc_html__( 'image attachments', 'perxel-image-optimizer' ),
			'content' => esc_html( number_format_i18n( (int) $snap['summary']['attachments'] ) ),
		),
		array(
			'label'   => __( 'Converted', 'perxel-image-optimizer' ),
			'sub'     => esc_html( sprintf( /* translators: %d: percentage. */ __( '%d%% of the library handled', 'perxel-image-optimizer' ), (int) $stats['coverage_pct'] ) ),
			'content' => esc_html( number_format_i18n( (int) $stats['converted'] ) ),
		),
		array(
			'label'   => __( 'WebP saved', 'perxel-image-optimizer' ),
			'sub'     => esc_html( sprintf( /* translators: %d: percentage. */ __( '%d%% smaller', 'perxel-image-optimizer' ), (int) $stats['saved_pct'] ) ),
			'content' => '&minus;' . esc_html( size_format( (int) $stats['saved_bytes'], 1 ) ),
			'tone'    => 'good',
		),
		array(
			'label'   => __( 'On disk', 'perxel-image-optimizer' ),
			'sub'     => esc_html__( '.webp files this plugin wrote', 'perxel-image-optimizer' ),
			'content' => esc_html( size_format( (int) $stats['webp_bytes'], 1 ) ),
		),
	);

	$note = ! empty( $stats['scanned_at'] )
		? '<p class="pxui-muted" style="margin-top:12px">'
			. esc_html( sprintf( /* translators: %s: human time diff. */ __( 'Exact as of the last scan, %s ago.', 'perxel-image-optimizer' ), human_time_diff( (int) $stats['scanned_at'] ) ) )
			. '</p>'
		: '';

	return Perxel_UI::rows(
		array(
			array(
				'title' => __( 'At a glance', 'perxel-image-optimizer' ),
				'rows'  => $rows,
			),
		)
	) . $note;
};

/**
 * A "this run" figures group (label left, value right).
 *
 * @param array  $figures [ [label, value, sub], … ].
 * @param string $title   Group title.
 * @param string $note    Trusted HTML footnote below the card.
 */
$figure_group = static function ( array $figures, $title, $note = '' ) {
	$rows = array();
	foreach ( $figures as $f ) {
		$rows[] = array(
			'label'   => $f['label'],
			'sub'     => isset( $f['sub'] ) ? $f['sub'] : '',
			'content' => isset( $f['id'] )
				? '<span id="' . esc_attr( $f['id'] ) . '">' . $f['value'] . '</span>'
				: $f['value'],
		);
	}

	return Perxel_UI::rows(
		array(
			array(
				'title' => $title,
				'rows'  => $rows,
				'note'  => $note,
			),
		)
	);
};

/* --- cannot_convert --- */

if ( 'cannot_convert' === $state ) {
	echo Perxel_UI::rows(
		array(
			array(
				'icon'    => 'bad',
				'label'   => __( "This host can't encode WebP.", 'perxel-image-optimizer' ),
				'sub'     => esc_html__( 'Neither GD nor Imagick reports WebP support, so conversion is disabled.', 'perxel-image-optimizer' ),
				'content' => '<a class="button" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Settings → Environment', 'perxel-image-optimizer' ) . '</a>',
			),
		)
	);
	echo $glance();
	return;
}

/* --- queued | running | stalled | paused | complete  - the monitor --- */

if ( in_array( $state, array( 'queued', 'running', 'stalled', 'paused', 'complete' ), true ) ) {
	require __DIR__ . '/status-monitor.php';
	return;
}

/* --- not_scanned --- */

if ( 'not_scanned' === $state ) {
	echo Perxel_UI::rows(
		array(
			array(
				'icon'    => '<span class="dashicons dashicons-search"></span>',
				'label'   => __( 'Library not scanned yet', 'perxel-image-optimizer' ),
				'sub'     => esc_html__( 'A quick scan counts what still needs converting and estimates the run - no images are touched.', 'perxel-image-optimizer' ),
				'content' => '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. '<input type="hidden" name="action" value="perxel_image_optimizer_scan" />'
					. wp_nonce_field( 'perxel_image_optimizer_scan', '_wpnonce', true, false )
					. '<button type="submit" class="button button-primary">' . esc_html__( 'Scan library', 'perxel-image-optimizer' ) . '</button>'
					. '</form>',
			),
		)
	);
	echo $glance();
	return;
}

/* --- serve_off | done (scanned, nothing pending) --- */

if ( 'serve_off' === $state ) {
	$enable_serve_form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-left:8px">'
		. '<input type="hidden" name="action" value="perxel_image_optimizer_enable_serve" />'
		. wp_nonce_field( 'perxel_image_optimizer_enable_serve', '_wpnonce', true, false )
		. '<button type="submit" class="button button-small button-primary">' . esc_html__( 'Serve WebP now', 'perxel-image-optimizer' ) . '</button>'
		. '</form>';

	echo Perxel_UI::notice(
		'warning',
		esc_html__( 'Every image is converted, but WebP is not being served yet.', 'perxel-image-optimizer' ) . $enable_serve_form
	);
	echo $glance();
	return;
}

if ( 'done' === $state ) {
	echo Perxel_UI::rows(
		array(
			array(
				'icon'  => 'good',
				'label' => __( 'All images are converted and served as WebP.', 'perxel-image-optimizer' ),
				'sub'   => ! empty( $scan['scanned_at'] )
					? esc_html( sprintf( /* translators: %s: human time diff. */ __( 'Scanned %s ago.', 'perxel-image-optimizer' ), human_time_diff( (int) $scan['scanned_at'] ) ) )
					: '',
			),
		)
	);
	echo $glance();
	return;
}

/* --- ready: the prepare form --- */

$skip_converted = ! empty( $snap['settings']['skip_converted'] );
$pending        = (int) $scan['pending'];
$total          = (int) ( $scan['total'] ?? $snap['summary']['attachments'] );
$scope_base     = $skip_converted ? $pending : $total;
$est_all        = \Perxel\ImageOptimizer\Estimator::project( null, $skip_converted );

$scanned_ago = ! empty( $scan['scanned_at'] )
	? sprintf( /* translators: %s: human time diff. */ __( 'Scanned %s ago.', 'perxel-image-optimizer' ), human_time_diff( (int) $scan['scanned_at'] ) )
	: '';

$notice = esc_html(
	sprintf(
		/* translators: 1: pending count, 2: library total. */
		__( '%1$s of %2$s images aren\'t WebP yet.', 'perxel-image-optimizer' ),
		number_format_i18n( $pending ),
		number_format_i18n( $total )
	)
);
if ( ! $skip_converted ) {
	$notice .= ' ' . esc_html__( 'Re-encoding is on, so the run re-processes converted images too.', 'perxel-image-optimizer' );
}
if ( $scanned_ago ) {
	$notice .= ' <span class="pxui-muted">' . esc_html( $scanned_ago ) . '</span>';
}
if ( ! empty( $scan['stale'] ) ) {
	$notice .= ' <span class="pxui-muted">' . esc_html__( '(may be out of date, scan again)', 'perxel-image-optimizer' ) . '</span>';
}
echo Perxel_UI::notice( 'info', $notice );

// Per-year, per-month rows. data-* carry the numbers admin.js needs to
// recompute the "This run" figures without a round-trip.
$by_year = array();
foreach ( (array) $snap['sections'] as $section ) {
	$ym      = $section['ym'];
	$m_total = isset( $scan['months'][ $ym ]['total'] ) ? (int) $scan['months'][ $ym ]['total'] : 0;
	$m_due   = isset( $scan['months'][ $ym ]['pending'] ) ? (int) $scan['months'][ $ym ]['pending'] : 0;
	$m_scope = $skip_converted ? $m_due : $m_total;
	if ( $m_scope < 1 ) {
		continue;
	}
	$by_year[ $section['year'] ][] = array(
		'ym'      => $ym,
		'label'   => $section['label'],
		'scope'   => $m_scope,
		'pending' => $m_due,
	);
}

$avg_src   = (float) ( $scan['avg_src'] ?? 0 );
$avg_frac  = (float) ( $scan['avg_frac'] ?? 0.7 );
$per_image = (float) ( $scan['per_image'] ?? 1 );
?>
<form id="pxio-prepare" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-avg-src="<?php echo esc_attr( $avg_src ); ?>"
	data-avg-frac="<?php echo esc_attr( $avg_frac ); ?>"
	data-per-image="<?php echo esc_attr( $per_image ); ?>"
	data-free-disk="<?php echo esc_attr( $free_disk ); ?>"
	data-scope-all="<?php echo esc_attr( $scope_base ); ?>"
	data-pending-all="<?php echo esc_attr( $pending ); ?>">
	<input type="hidden" name="action" value="perxel_image_optimizer_start" />
	<?php
	wp_nonce_field( 'perxel_image_optimizer_start' );

	$convert_rows = array(
		array(
			'label'   => __( 'Scope', 'perxel-image-optimizer' ),
			'content' => '<select id="pxio-scope" name="scope">'
				. '<option value="all">' . esc_html(
					sprintf(
						$skip_converted
							/* translators: %s: count. */
							? __( 'Everything pending (%s)', 'perxel-image-optimizer' )
							/* translators: %s: count. */
							: __( 'Every image (%s)', 'perxel-image-optimizer' ),
						number_format_i18n( $scope_base )
					)
				) . '</option>'
				. '<option value="months">' . esc_html__( 'Choose months', 'perxel-image-optimizer' ) . '</option>'
				. '</select>',
		),
	);

	// Serving is off by default (never enabled on activation). The choice moves
	// to the title bar: "Start conversion" vs "Enable serving & start conversion"
	// (see Admin::status_actions), so nothing is needed in this form.

	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'What to convert', 'perxel-image-optimizer' ),
				'rows'  => $convert_rows,
			),
		)
	);

	// Month picker: one collapsible disclosure row per year (year left, count
	// right, months revealed on click), hidden until scope = months. data-metric
	// tells admin.js which per-month figure to sum for the "selected" count.
	echo '<div id="pxio-months" data-metric="' . ( $skip_converted ? 'pending' : 'scope' ) . '" hidden>';

	$year_rows = array();

	foreach ( $by_year as $group_year => $group_months ) {
		$all_label = sprintf( /* translators: %s: year. */ __( 'Select all of %s', 'perxel-image-optimizer' ), $group_year );

		$month_list  = '<div class="pxio-year-months">';
		$month_list .= '<div class="pxui-row">'
			. '<span class="pxui-row__label">' . esc_html( $all_label ) . '</span>'
			. '<span class="pxui-row__content"><input type="checkbox" class="pxui-checkbox pxio-year-all"'
			. ' data-year="' . esc_attr( $group_year ) . '" aria-label="' . esc_attr( $all_label ) . '" /></span>'
			. '</div>';

		$y_scope   = 0;
		$y_pending = 0;

		foreach ( $group_months as $gm ) {
			$y_scope   += $gm['scope'];
			$y_pending += $gm['pending'];

			$mm  = substr( $gm['ym'], 5, 2 ) . '/' . substr( $gm['ym'], 0, 4 );
			$sub = $skip_converted
				/* translators: %s: count. */
				? sprintf( _n( '%s pending', '%s pending', $gm['pending'], 'perxel-image-optimizer' ), number_format_i18n( $gm['pending'] ) )
				/* translators: %s: count. */
				: sprintf( _n( '%s image', '%s images', $gm['scope'], 'perxel-image-optimizer' ), number_format_i18n( $gm['scope'] ) );

			$month_list .= '<div class="pxui-row">'
				. '<span class="pxui-row__label">' . esc_html( $mm )
				. '<span class="pxui-row__sub">' . esc_html( $sub ) . '</span></span>'
				. '<span class="pxui-row__content"><input type="checkbox" class="pxui-checkbox pxio-month" name="months[]" form="pxio-prepare"'
				. ' value="' . esc_attr( $gm['ym'] ) . '" data-year="' . esc_attr( $group_year ) . '"'
				. ' data-scope="' . esc_attr( $gm['scope'] ) . '" data-pending="' . esc_attr( $gm['pending'] ) . '"'
				. ' aria-label="' . esc_attr( $gm['label'] ) . '" /></span>'
				. '</div>';
		}

		$month_list .= '</div>';

		$y_total_text = $skip_converted
			/* translators: %s: count. */
			? sprintf( _n( '%s pending', '%s pending', $y_pending, 'perxel-image-optimizer' ), number_format_i18n( $y_pending ) )
			/* translators: %s: count. */
			: sprintf( _n( '%s image', '%s images', $y_scope, 'perxel-image-optimizer' ), number_format_i18n( $y_scope ) );

		$year_rows[] = array(
			'summary' => (string) $group_year,
			// admin.js swaps this to "<n> of <total>" (accent) while the year has
			// selected months; back to the plain total when it has none.
			'content' => '<span class="pxio-year-count" data-year="' . esc_attr( $group_year ) . '"'
				. ' data-total-text="' . esc_attr( $y_total_text ) . '">' . esc_html( $y_total_text ) . '</span>',
			'details' => $month_list,
		);
	}

	if ( $year_rows ) {
		echo Perxel_UI::rows(
			array(
				array(
					'title' => __( 'Choose months', 'perxel-image-optimizer' ),
					'rows'  => $year_rows,
				),
			)
		);
	}

	echo '<p class="pxui-muted">' . esc_html(
		$skip_converted
			? __( 'Fully-converted months aren\'t listed.', 'perxel-image-optimizer' )
			: __( 'Every month with images is listed.', 'perxel-image-optimizer' )
	) . '</p>';
	echo '</div>';

	// "This run" figures, recomputed in JS on every checkbox change.
	echo '<div id="pxio-run-warning"></div>';

	$eta      = (int) $est_all['eta_seconds'];
	$eta_text = $eta > 0 ? '&asymp; ' . esc_html( human_time_diff( 0, $eta ) ) : esc_html__( 'a few seconds', 'perxel-image-optimizer' );

	$pace_sub = ! empty( $scan['pace_measured'] )
		? esc_html__( "From your last run's speed here.", 'perxel-image-optimizer' )
		: esc_html__( 'Rough guess. The first run measures your server.', 'perxel-image-optimizer' );

	$saved_sub = 'measured' === ( $scan['frac_source'] ?? 'default' )
		? esc_html(
			sprintf(
				/* translators: %s: count of images. */
				__( 'Average of the %s images converted here so far.', 'perxel-image-optimizer' ),
				number_format_i18n( (int) ( $scan['converted'] ?? 0 ) )
			)
		)
		: esc_html__( 'Rough estimate. The live run uses your real ratio.', 'perxel-image-optimizer' );

	$disk_sub = $free_disk > 0
		? esc_html(
			sprintf(
				/* translators: %s: formatted size. */
				__( 'Server reports about %s free. On shared hosting that can be the whole disk, not your quota.', 'perxel-image-optimizer' ),
				size_format( $free_disk, 1 )
			)
		)
		: esc_html__( 'Free space unreadable on this server.', 'perxel-image-optimizer' );

	// Plain-language lead line for the note - one sentence pulling the four
	// figures together for a non-technical reader. admin.js rewrites the span
	// on every selection change (recompute()); this is the no-JS fallback.
	$eta_phrase = ( $eta < 90 )
		? __( 'takes only a few seconds', 'perxel-image-optimizer' )
		/* translators: %s: duration, e.g. "18 mins". */
		: sprintf( __( 'takes about %s', 'perxel-image-optimizer' ), human_time_diff( 0, $eta ) );

	$run_summary = esc_html(
		sprintf(
			/* translators: 1: image count, 2: time phrase e.g. "takes about 18 mins", 3: bandwidth saved, 4: percent, 5: disk added. */
			__( 'Converting %1$s images %2$s. Your visitors save about %3$s (a %4$d%% cut per image); your server gains about %5$s.', 'perxel-image-optimizer' ),
			number_format_i18n( (int) $est_all['images'] ),
			$eta_phrase,
			size_format( (int) $est_all['saved_bytes'], 1 ),
			(int) $est_all['percent'],
			size_format( (int) $est_all['webp_bytes'], 1 )
		)
	);

	$run_note  = esc_html__( 'Runs in the background. Close the tab anytime.', 'perxel-image-optimizer' );
	$report_to = ! empty( $snap['settings']['email_report'] ) ? \Perxel\ImageOptimizer\Settings::report_recipient() : '';
	if ( '' !== $report_to ) {
		$run_note .= ' ' . esc_html(
			sprintf(
				/* translators: %s: email address. */
				__( 'A report goes to %s when it finishes.', 'perxel-image-optimizer' ),
				$report_to
			)
		);
	}
	$run_note .= '<br>' . esc_html__( 'Figures are estimates from a library sample; the live run shows real numbers.', 'perxel-image-optimizer' );

	$run_note = '<span id="pxio-run-summary">' . $run_summary . '</span><br>' . $run_note;

	echo $figure_group(
		array(
			array(
				'label' => __( 'Images', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-images',
				'value' => esc_html( number_format_i18n( (int) $est_all['images'] ) ),
				'sub'   => '<span id="pxio-fig-scope">' . esc_html( $skip_converted ? __( 'everything pending', 'perxel-image-optimizer' ) : __( 'every image', 'perxel-image-optimizer' ) ) . '</span>',
			),
			array(
				'label' => __( 'Estimated time', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-time',
				'value' => $eta_text,
				'sub'   => $pace_sub,
			),
			array(
				'label' => __( 'Bandwidth saved', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-saved',
				'value' => '&minus;' . (int) $est_all['percent'] . '%&ensp;&middot;&ensp;&asymp; ' . esc_html( size_format( (int) $est_all['saved_bytes'], 0 ) ),
				'sub'   => $saved_sub,
			),
			array(
				'label' => __( 'Disk added', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-disk',
				'value' => '&asymp; +' . esc_html( size_format( (int) $est_all['webp_bytes'], 0 ) ),
				'sub'   => $disk_sub,
			),
		),
		__( 'This run', 'perxel-image-optimizer' ),
		$run_note
	);
	?>
</form>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
