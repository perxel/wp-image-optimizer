<?php
/**
 * Status screen — cheap glance, then one of: not-scanned, prepare, or the live
 * monitor. All server-rendered; assets/admin.js only does the prepare-form
 * arithmetic and the monitor poll.
 *
 * @package Perxel_Image_Optimizer
 *
 * @var array  $snap  Perxel\ImageOptimizer\Ajax::snapshot().
 * @var string $state Perxel\ImageOptimizer\Admin::status_state() result.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report       = $snap['report'];
$job          = $snap['job'];
$scan         = $snap['scan'];
$env          = $snap['environment'];
$settings_url = admin_url( 'upload.php?page=' . \Perxel\ImageOptimizer\Admin::PAGE_SETTINGS );
$free_disk    = isset( $env['free_disk'] ) ? (int) $env['free_disk'] : 0;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

/**
 * The four cached-metrics tiles, as a rows() group (no stat_grid).
 */
$glance = static function () use ( $report, $snap ) {
	$last     = (int) $report['last_full_scan'];
	$recalcing = ! empty( $snap['recalcing'] );

	$recalc = '<p class="pxui-muted" style="margin-top:12px">'
		. ( $last > 0
			/* translators: %s: human time diff. */
			? esc_html( sprintf( __( 'Metrics recalculated %s ago.', 'perxel-image-optimizer' ), human_time_diff( $last ) ) )
			: esc_html__( 'Metrics never fully recalculated.', 'perxel-image-optimizer' ) )
		. ' ';

	if ( $recalcing ) {
		$recalc .= esc_html__( 'Recalculating in the background…', 'perxel-image-optimizer' );
	} else {
		$recalc .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="perxel_image_optimizer_recalc" />'
			. wp_nonce_field( 'perxel_image_optimizer_recalc', '_wpnonce', true, false )
			. '<button type="submit" class="button-link">' . esc_html__( 'Recalculate now', 'perxel-image-optimizer' ) . '</button>'
			. '</form>';
	}

	$recalc .= '</p>';

	$rows = array(
		array(
			'label'   => __( 'Library', 'perxel-image-optimizer' ),
			'sub'     => esc_html__( 'image attachments', 'perxel-image-optimizer' ),
			'content' => esc_html( number_format_i18n( (int) $snap['summary']['attachments'] ) ),
		),
		array(
			'label'   => __( 'Converted', 'perxel-image-optimizer' ),
			'sub'     => esc_html( sprintf( /* translators: %d: percentage. */ __( '%d%% coverage', 'perxel-image-optimizer' ), (int) $report['coverage_pct'] ) ),
			'content' => esc_html( number_format_i18n( (int) $report['converted_files'] ) ),
		),
		array(
			'label'   => __( 'WebP saved', 'perxel-image-optimizer' ),
			'sub'     => esc_html( sprintf( /* translators: %d: percentage. */ __( '%d%% smaller', 'perxel-image-optimizer' ), (int) $report['bandwidth_pct'] ) ),
			'content' => '&minus;' . esc_html( size_format( (int) $report['bandwidth_saved'], 1 ) ),
			'tone'    => 'good',
		),
		array(
			'label'   => __( 'On disk', 'perxel-image-optimizer' ),
			'sub'     => esc_html__( '.webp files added', 'perxel-image-optimizer' ),
			'content' => esc_html( size_format( (int) $report['disk_added'], 1 ) ),
		),
	);

	return Perxel_UI::rows(
		array(
			array(
				'title' => __( 'At a glance', 'perxel-image-optimizer' ),
				'rows'  => $rows,
			),
		)
	) . $recalc;
};

/**
 * A "this run" figures group (label left, value right).
 *
 * @param array $figures [ [label, value, sub], … ].
 * @param string $title Group title.
 */
$figure_group = static function ( array $figures, $title ) {
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

/* --- running | stalled | paused | complete  — the monitor --- */

if ( in_array( $state, array( 'running', 'stalled', 'paused', 'complete' ), true ) ) {
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
				'sub'     => esc_html__( 'A quick scan counts what still needs converting and estimates the run — no images are touched.', 'perxel-image-optimizer' ),
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
	echo Perxel_UI::notice(
		'warning',
		esc_html__( 'Every image is converted, but WebP is not being served.', 'perxel-image-optimizer' )
		. ' <a class="button button-small" href="' . esc_url( $settings_url . '#serving' ) . '">' . esc_html__( 'Enable serving', 'perxel-image-optimizer' ) . '</a>'
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

/* --- ready — the prepare form --- */

$pending = (int) $scan['pending'];
$total   = (int) ( $scan['total'] ?? $snap['summary']['attachments'] );
$est_all = \Perxel\ImageOptimizer\Estimator::project( null );

$scanned_ago = ! empty( $scan['scanned_at'] )
	? sprintf( /* translators: %s: human time diff. */ __( 'Scanned %s ago.', 'perxel-image-optimizer' ), human_time_diff( (int) $scan['scanned_at'] ) )
	: '';

echo Perxel_UI::notice(
	'info',
	esc_html(
		sprintf(
			/* translators: 1: pending count, 2: library total. */
			__( '%1$s of %2$s images aren\'t WebP yet.', 'perxel-image-optimizer' ),
			number_format_i18n( $pending ),
			number_format_i18n( $total )
		)
	) . ( $scanned_ago ? ' <span class="pxui-muted">' . esc_html( $scanned_ago ) . '</span>' : '' )
	. ( ! empty( $scan['stale'] ) ? ' <span class="pxui-muted">' . esc_html__( '(these numbers may be out of date — scan again)', 'perxel-image-optimizer' ) . '</span>' : '' )
);

// Per-year → per-month rows, pending only. data-* carry the numbers admin.js
// needs to recompute the "This run" figures without a round-trip.
$by_year = array();
foreach ( (array) $snap['sections'] as $section ) {
	$ym  = $section['ym'];
	$due = isset( $scan['months'][ $ym ]['pending'] ) ? (int) $scan['months'][ $ym ]['pending'] : 0;
	if ( $due < 1 ) {
		continue;
	}
	$by_year[ $section['year'] ][] = array(
		'ym'    => $ym,
		'label' => $section['label'],
		'due'   => $due,
	);
}

$avg_src  = (float) ( $scan['avg_src'] ?? 0 );
$avg_frac = (float) ( $scan['avg_frac'] ?? 0.7 );
?>
<form id="pxio-prepare" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-avg-src="<?php echo esc_attr( $avg_src ); ?>"
	data-avg-frac="<?php echo esc_attr( $avg_frac ); ?>"
	data-free-disk="<?php echo esc_attr( $free_disk ); ?>"
	data-pending-all="<?php echo esc_attr( $pending ); ?>">
	<input type="hidden" name="action" value="perxel_image_optimizer_start" />
	<?php
	wp_nonce_field( 'perxel_image_optimizer_start' );

	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'What to convert', 'perxel-image-optimizer' ),
				'rows'  => array(
					array(
						'label'   => __( 'Scope', 'perxel-image-optimizer' ),
						'content' => '<select id="pxio-scope" name="scope">'
							. '<option value="all">' . esc_html( sprintf( /* translators: %s: count. */ __( 'Everything pending (%s)', 'perxel-image-optimizer' ), number_format_i18n( $pending ) ) ) . '</option>'
							. '<option value="months">' . esc_html__( 'Choose months…', 'perxel-image-optimizer' ) . '</option>'
							. '</select>',
					),
					array(
						'label'   => __( 'Skip already-converted images', 'perxel-image-optimizer' ),
						'sub'     => esc_html__( 'Off re-encodes everything in scope, even images that already have a current WebP copy.', 'perxel-image-optimizer' ),
						'content' => Perxel_UI::toggle(
							array(
								'name'    => 'skip_converted',
								'form'    => 'pxio-prepare',
								'checked' => true,
								'label'   => __( 'Skip already-converted images', 'perxel-image-optimizer' ),
							)
						),
					),
				),
			),
		)
	);

	// Month picker — one group per year, hidden until scope = months.
	echo '<div id="pxio-months" hidden>';

	foreach ( $by_year as $group_year => $group_months ) {
		$rows = array(
			array(
				'label'   => sprintf( /* translators: %s: year. */ __( 'Select all of %s', 'perxel-image-optimizer' ), $group_year ),
				'content' => '<input type="checkbox" class="pxio-year-all" data-year="' . esc_attr( $group_year ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: year. */ __( 'Select all of %s', 'perxel-image-optimizer' ), $group_year ) ) . '" />',
			),
		);

		foreach ( $group_months as $gm ) {
			$rows[] = array(
				'label'   => $gm['label'],
				'sub'     => esc_html( sprintf( /* translators: %s: count. */ _n( '%s pending', '%s pending', $gm['due'], 'perxel-image-optimizer' ), number_format_i18n( $gm['due'] ) ) ),
				'content' => '<input type="checkbox" class="pxui-checkbox pxio-month" name="months[]" form="pxio-prepare"'
					. ' value="' . esc_attr( $gm['ym'] ) . '" data-year="' . esc_attr( $group_year ) . '" data-due="' . esc_attr( $gm['due'] ) . '"'
					. ' aria-label="' . esc_attr( $gm['label'] ) . '" />',
			);
		}

		echo Perxel_UI::rows(
			array(
				array(
					'title' => (string) $group_year,
					'rows'  => $rows,
				),
			)
		);
	}

	echo '<p class="pxui-muted">' . esc_html__( 'Fully-converted months aren\'t listed.', 'perxel-image-optimizer' ) . '</p>';
	echo '</div>';

	// "This run" — recomputed in JS on every checkbox change.
	echo '<div id="pxio-run-warning"></div>';

	echo $figure_group(
		array(
			array(
				'label' => __( 'Images', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-images',
				'value' => esc_html( number_format_i18n( (int) $est_all['images'] ) ),
				'sub'   => '<span id="pxio-fig-scope">' . esc_html__( 'everything pending', 'perxel-image-optimizer' ) . '</span>',
			),
			array(
				'label' => __( 'Bandwidth saved', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-saved',
				'value' => '&asymp; &minus;' . esc_html( size_format( (int) $est_all['saved_bytes'], 0 ) ),
				'sub'   => '<span id="pxio-fig-pct">' . esc_html( sprintf( /* translators: %d: percent. */ __( '&asymp; %d%% smaller', 'perxel-image-optimizer' ), (int) $est_all['percent'] ) ) . '</span>',
			),
			array(
				'label' => __( 'Disk added', 'perxel-image-optimizer' ),
				'id'    => 'pxio-fig-disk',
				'value' => '&asymp; +' . esc_html( size_format( (int) $est_all['webp_bytes'], 0 ) ),
				'sub'   => $free_disk > 0
					? esc_html( sprintf( /* translators: %s: size. */ __( 'free disk: %s', 'perxel-image-optimizer' ), size_format( $free_disk, 1 ) ) )
					: '',
			),
		),
		__( 'This run', 'perxel-image-optimizer' )
	);

	echo '<p class="pxui-muted">' . esc_html__( 'Runs in the background · emails a report on finish (if enabled) · close the tab anytime.', 'perxel-image-optimizer' ) . '</p>';
	echo '<p class="pxui-muted">' . esc_html__( 'Estimate = a 100-image sample × your conversion ratio. Live numbers replace it once the run starts.', 'perxel-image-optimizer' ) . '</p>';
	?>
</form>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
