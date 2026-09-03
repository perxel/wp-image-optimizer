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

/* --- serve_off | done (whole library converted) --- */

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
				'label' => empty( $snap['settings']['serve'] )
					? __( 'Every image is handled under the current settings.', 'perxel-image-optimizer' )
					: __( 'All images are converted and served as WebP.', 'perxel-image-optimizer' ),
				'sub'   => ! empty( $scan['scanned_at'] )
					? esc_html( sprintf( /* translators: %s: human time diff. */ __( 'Last checked %s ago.', 'perxel-image-optimizer' ), human_time_diff( (int) $scan['scanned_at'] ) ) )
					: '',
			),
		)
	);
	echo $glance();
	return;
}

/* --- ready: the prepare form --- */

$cfg            = (array) $snap['settings'];
$skip_converted = ! empty( $cfg['skip_converted'] );
$serve_on       = ! empty( $cfg['serve'] );
$total          = (int) ( $scan['total'] ?? $snap['summary']['attachments'] );
$est_all        = \Perxel\ImageOptimizer\Estimator::project( null );

// Effective megapixel ceiling: the Settings override, or the server's computed
// safe value.
$mp_ceiling = (int) ( $cfg['skip_megapixels'] ?? 0 );
if ( $mp_ceiling <= 0 ) {
	$mp_ceiling = (int) ( $env['safe_megapixels'] ?? 0 );
}

if ( $total < 1 ) {
	echo Perxel_UI::rows(
		array(
			array(
				'icon'  => '<span class="dashicons dashicons-format-image"></span>',
				'label' => __( 'No images in the Media Library yet', 'perxel-image-optimizer' ),
				'sub'   => esc_html__( 'Upload some JPEG or PNG images and they will show up here.', 'perxel-image-optimizer' ),
			),
		)
	);
	echo $glance();
	return;
}

/* Intro: one plain sentence describing what the run does. */
echo Perxel_UI::notice(
	'info',
	$skip_converted
		? esc_html(
			sprintf(
				/* translators: %s: image count. */
				__( 'Goes through all %s images in the Media Library, newest first, and creates a WebP copy for any that don\'t have one. Images that already have one are skipped (about a second each).', 'perxel-image-optimizer' ),
				number_format_i18n( $total )
			)
		)
		: esc_html(
			sprintf(
				/* translators: %s: image count. */
				__( 'Re-encodes all %s images in the Media Library to WebP, newest first, replacing the current copies. "Skip already-converted images" is off in Settings.', 'perxel-image-optimizer' ),
				number_format_i18n( $total )
			)
		)
);

/* "This run" figures - image count + ETA update in JS as months are ticked. */
$eta      = (int) $est_all['eta_seconds'];
$eta_text = $eta < 90
	? esc_html__( 'a few seconds', 'perxel-image-optimizer' )
	: esc_html__( 'up to', 'perxel-image-optimizer' ) . ' &asymp; ' . esc_html( human_time_diff( 0, $eta ) );

$pace_sub = ! empty( $scan['pace_measured'] )
	? esc_html__( "Based on your last run's speed here.", 'perxel-image-optimizer' )
	: esc_html__( 'Rough guess until the first run measures this server.', 'perxel-image-optimizer' );
if ( $skip_converted ) {
	$pace_sub .= ' ' . esc_html__( 'Less if many images already have a WebP copy.', 'perxel-image-optimizer' );
}

$run_rows = array(
	array(
		'label' => __( 'Images', 'perxel-image-optimizer' ),
		'id'    => 'pxio-fig-images',
		'value' => esc_html( number_format_i18n( (int) $est_all['images'] ) ),
		'sub'   => '<span id="pxio-fig-scope">' . esc_html__( 'whole library', 'perxel-image-optimizer' ) . '</span>',
	),
);

if ( $mp_ceiling > 0 ) {
	$run_rows[] = array(
		'label' => __( 'Skipped', 'perxel-image-optimizer' ),
		/* translators: %d: megapixels. */
		'value' => esc_html( sprintf( __( 'over %d MP', 'perxel-image-optimizer' ), $mp_ceiling ) ),
		'sub'   => esc_html__( 'Larger images risk running out of memory on this server.', 'perxel-image-optimizer' ),
	);
}

$run_rows[] = array(
	'label' => __( 'Estimated time', 'perxel-image-optimizer' ),
	'id'    => 'pxio-fig-time',
	'value' => $eta_text,
	'sub'   => $pace_sub,
);

$run_note  = '<span id="pxio-run-note">' . esc_html__( 'Background mode runs on a schedule - close the tab anytime.', 'perxel-image-optimizer' ) . '</span>';
$report_to = ! empty( $cfg['email_report'] ) ? \Perxel\ImageOptimizer\Settings::report_recipient() : '';
if ( '' !== $report_to ) {
	$run_note .= ' ' . esc_html(
		sprintf(
			/* translators: %s: email address. */
			__( 'A report goes to %s when it finishes.', 'perxel-image-optimizer' ),
			$report_to
		)
	);
}

echo $figure_group( $run_rows, __( 'This run', 'perxel-image-optimizer' ), $run_note );

// Per-year, per-month rows. data-scope carries each month's image count so
// admin.js can sum the selection without a round-trip.
$by_year = array();
foreach ( (array) $snap['sections'] as $section ) {
	$ym    = $section['ym'];
	$count = isset( $scan['months'][ $ym ]['total'] )
		? (int) $scan['months'][ $ym ]['total']
		: (int) $section['count'];
	if ( $count < 1 ) {
		continue;
	}
	$by_year[ $section['year'] ][] = array(
		'ym'    => $ym,
		'label' => $section['label'],
		'count' => $count,
	);
}

$per_image = (float) ( $scan['per_image'] ?? 1 );

$per_image_fast = \Perxel\ImageOptimizer\Runner::fast_pace();
if ( $per_image_fast <= 0 ) {
	$per_image_fast = $per_image;
}
?>
<form id="pxio-prepare" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-per-image="<?php echo esc_attr( $per_image ); ?>"
	data-per-image-fast="<?php echo esc_attr( $per_image_fast ); ?>"
	data-scope-all="<?php echo esc_attr( $est_all['images'] ); ?>">
	<input type="hidden" name="action" value="perxel_image_optimizer_start" />
	<?php
	wp_nonce_field( 'perxel_image_optimizer_start' );

	$driver_rows = array(
		array(
			'label'   => __( 'Background', 'perxel-image-optimizer' ),
			'sub'     => esc_html__( 'Runs on a schedule. Close the tab whenever - it keeps going. Slower on shared hosting with no cron.', 'perxel-image-optimizer' ),
			'content' => '<input type="radio" name="driver" value="background" class="pxui-checkbox pxio-driver" checked />',
		),
		array(
			'label'   => __( 'Fast', 'perxel-image-optimizer' ),
			'sub'     => esc_html__( 'Keeps this tab open and works your server continuously. Pauses itself if the host pushes back. Much faster.', 'perxel-image-optimizer' ),
			'content' => '<input type="radio" name="driver" value="fast" class="pxui-checkbox pxio-driver" />',
		),
	);

	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'How to run it', 'perxel-image-optimizer' ),
				'rows'  => $driver_rows,
			),
		)
	);

	$scope_rows = array(
		array(
			'label'   => __( 'Scope', 'perxel-image-optimizer' ),
			'content' => '<select id="pxio-scope" name="scope">'
				. '<option value="all">' . esc_html(
					sprintf(
						/* translators: %s: count. */
						__( 'Whole library (%s)', 'perxel-image-optimizer' ),
						number_format_i18n( $total )
					)
				) . '</option>'
				. '<option value="months">' . esc_html__( 'Choose months', 'perxel-image-optimizer' ) . '</option>'
				. '</select>',
		),
	);

	echo Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Scope', 'perxel-image-optimizer' ),
				'rows'  => $scope_rows,
			),
		)
	);

	// Month picker: one collapsible disclosure row per year, hidden until
	// scope = months.
	echo '<div id="pxio-months" hidden>';

	$year_rows = array();

	foreach ( $by_year as $group_year => $group_months ) {
		$all_label = sprintf( /* translators: %s: year. */ __( 'Select all of %s', 'perxel-image-optimizer' ), $group_year );

		$month_list  = '<div class="pxio-year-months">';
		$month_list .= '<div class="pxui-row">'
			. '<span class="pxui-row__label">' . esc_html( $all_label ) . '</span>'
			. '<span class="pxui-row__content"><input type="checkbox" class="pxui-checkbox pxio-year-all"'
			. ' data-year="' . esc_attr( $group_year ) . '" aria-label="' . esc_attr( $all_label ) . '" /></span>'
			. '</div>';

		$y_count = 0;

		foreach ( $group_months as $gm ) {
			$y_count += $gm['count'];

			$mm = substr( $gm['ym'], 5, 2 ) . '/' . substr( $gm['ym'], 0, 4 );
			/* translators: %s: count. */
			$sub = sprintf( _n( '%s image', '%s images', $gm['count'], 'perxel-image-optimizer' ), number_format_i18n( $gm['count'] ) );

			$month_list .= '<div class="pxui-row">'
				. '<span class="pxui-row__label">' . esc_html( $mm )
				. '<span class="pxui-row__sub">' . esc_html( $sub ) . '</span></span>'
				. '<span class="pxui-row__content"><input type="checkbox" class="pxui-checkbox pxio-month" name="months[]" form="pxio-prepare"'
				. ' value="' . esc_attr( $gm['ym'] ) . '" data-year="' . esc_attr( $group_year ) . '"'
				. ' data-scope="' . esc_attr( $gm['count'] ) . '"'
				. ' aria-label="' . esc_attr( $gm['label'] ) . '" /></span>'
				. '</div>';
		}

		$month_list .= '</div>';

		/* translators: %s: count. */
		$y_total_text = sprintf( _n( '%s image', '%s images', $y_count, 'perxel-image-optimizer' ), number_format_i18n( $y_count ) );

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

	echo '<p class="pxui-muted">' . esc_html__( 'Every month with images is listed.', 'perxel-image-optimizer' ) . '</p>';
	echo '</div>';
	?>
</form>
<?php

/* "Settings in effect" - a read-only recap; the controls live on Settings. */
$sizes_cfg = (array) $cfg['sizes'];
$sizes_txt = in_array( '*', $sizes_cfg, true )
	? __( 'all registered sizes', 'perxel-image-optimizer' )
	/* translators: %s: count. */
	: sprintf( _n( '%s size', '%s sizes', count( $sizes_cfg ), 'perxel-image-optimizer' ), number_format_i18n( count( $sizes_cfg ) ) );

echo Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Settings in effect', 'perxel-image-optimizer' ),
			'rows'  => array(
				array(
					'label'   => __( 'Quality', 'perxel-image-optimizer' ),
					'content' => esc_html( sprintf( 'JPEG %d · PNG %d', (int) $cfg['jpeg_quality'], (int) $cfg['png_quality'] ) ),
				),
				array(
					'label'   => __( 'PNG files', 'perxel-image-optimizer' ),
					'content' => esc_html( ! empty( $cfg['convert_png'] ) ? __( 'converted', 'perxel-image-optimizer' ) : __( 'left as PNG', 'perxel-image-optimizer' ) ),
				),
				array(
					'label'   => __( 'Sizes', 'perxel-image-optimizer' ),
					'content' => esc_html( $sizes_txt ),
				),
				array(
					'label'   => __( 'Re-convert existing', 'perxel-image-optimizer' ),
					'content' => esc_html( $skip_converted ? __( 'off - existing WebP kept', 'perxel-image-optimizer' ) : __( 'on - every image re-encoded', 'perxel-image-optimizer' ) ),
				),
				array(
					'label'   => __( 'Serving', 'perxel-image-optimizer' ),
					'content' => esc_html( $serve_on ? __( 'on', 'perxel-image-optimizer' ) : __( 'off', 'perxel-image-optimizer' ) ),
				),
			),
			'note'  => '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Change these in Settings', 'perxel-image-optimizer' ) . ' &rarr;</a>',
		),
	)
);

echo $glance();
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
