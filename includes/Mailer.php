<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The optional "email a report when a bulk run finishes" notification.
 *
 * Opt-in (Settings, default off). Bulk runs only - the catch-up path never
 * mails. One mail per run, sent by the worker that flips the job to complete
 * (or by cancel / stalled-gave-up, with partial totals). Plain wp_mail(), site
 * default from-address, no external service.
 */
class Mailer {

	/**
	 * Send the completion report for a finished (or stopped) bulk run.
	 *
	 * @param array  $job    Runner job state at finish.
	 * @param string $reason complete|cancelled|stalled.
	 */
	public static function send_report( array $job, $reason = 'complete' ) {
		$to = Settings::report_recipient();

		if ( ! $to ) {
			return;
		}

		list( $subject, $body ) = self::compose( $job, $reason );

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Send a sample report now, so the address can be verified from Settings.
	 *
	 * @param string $to Recipient.
	 * @return true|string True when wp_mail() accepted the message, otherwise a
	 *                     human-readable reason (invalid address, or whatever
	 *                     PHPMailer reported on the `wp_mail_failed` hook).
	 */
	public static function send_test( $to ) {
		$to = sanitize_email( $to );

		if ( ! $to || ! is_email( $to ) ) {
			return __( 'That address is not valid.', 'perxel-image-optimizer' );
		}

		$sample = array_merge(
			Runner::defaults(),
			array(
				'processed'     => 1284,
				'converted'     => 3820,
				'failed'        => 6,
				'skipped_large' => 2,
				'src_bytes'     => 4100000000,
				'webp_bytes'    => 2950000000,
				'saved_bytes'   => 1150000000,
				'started_at'    => time() - 2820,
				'finished_at'   => time(),
			)
		);

		list( $subject, $body ) = self::compose( $sample, 'complete' );

		// wp_mail() only returns false on a hard PHPMailer failure and carries no
		// detail; the reason arrives on `wp_mail_failed` as a WP_Error. Capture it
		// for the duration of this one call so Settings can show what broke.
		$reason  = '';
		$catcher = static function ( $error ) use ( &$reason ) {
			if ( is_wp_error( $error ) ) {
				$reason = $error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $catcher );
		$ok = wp_mail( $to, '[test] ' . $subject, "This is a test of the Perxel Image Optimizer completion report.\n\n" . $body );
		remove_action( 'wp_mail_failed', $catcher );

		if ( $ok ) {
			return true;
		}

		return '' !== $reason
			? $reason
			: __( 'wp_mail() reported a failure with no detail - the host is likely blocking or silently dropping outbound mail. An SMTP plugin usually fixes this.', 'perxel-image-optimizer' );
	}

	/**
	 * @param array  $job    Job state.
	 * @param string $reason complete|cancelled|stalled.
	 * @return array{0:string,1:string} [ subject, body ].
	 */
	private static function compose( array $job, $reason ) {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$saved = max( 0, (int) $job['saved_bytes'] );
		$src   = (int) $job['src_bytes'];
		$pct   = $src > 0 ? round( $saved / $src * 100 ) : 0;

		$headline = array(
			'complete'  => __( 'WebP conversion finished', 'perxel-image-optimizer' ),
			'cancelled' => __( 'WebP conversion cancelled', 'perxel-image-optimizer' ),
			'stalled'   => __( 'WebP conversion stopped (stalled)', 'perxel-image-optimizer' ),
		);
		$headline = $headline[ $reason ] ?? $headline['complete'];

		$subject = sprintf(
			/* translators: 1: headline, 2: site name. */
			__( '%1$s - %2$s', 'perxel-image-optimizer' ),
			$headline,
			$site
		);

		$elapsed = ( $job['finished_at'] && $job['started_at'] )
			? human_time_diff( (int) $job['started_at'], (int) $job['finished_at'] )
			: '';

		$lines = array(
			$headline . '.',
			'',
			sprintf( /* translators: %s: number of attachments. */ __( 'Attachments processed: %s', 'perxel-image-optimizer' ), number_format_i18n( (int) $job['processed'] ) ),
			sprintf( /* translators: %s: number of files. */ __( 'WebP files written: %s', 'perxel-image-optimizer' ), number_format_i18n( (int) $job['converted'] ) ),
			sprintf( /* translators: 1: size, 2: percent. */ __( 'Bandwidth saved: %1$s (%2$d%% smaller)', 'perxel-image-optimizer' ), size_format( $saved, 1 ), $pct ),
			sprintf( /* translators: %s: size. */ __( 'Disk added: %s', 'perxel-image-optimizer' ), size_format( (int) $job['webp_bytes'], 1 ) ),
			sprintf( /* translators: %s: count. */ __( 'Failed: %s', 'perxel-image-optimizer' ), number_format_i18n( (int) $job['failed'] ) ),
			sprintf( /* translators: %s: count. */ __( 'Skipped (too large for this server): %s', 'perxel-image-optimizer' ), number_format_i18n( (int) $job['skipped_large'] ) ),
		);

		if ( $elapsed ) {
			/* translators: %s: human-readable duration. */
			$lines[] = sprintf( __( 'Elapsed: %s', 'perxel-image-optimizer' ), $elapsed );
		}

		$failures = array_slice( Failures::listing( 10 ), 0, 10 );
		if ( $failures ) {
			$lines[] = '';
			$lines[] = __( 'First failures:', 'perxel-image-optimizer' );
			foreach ( $failures as $row ) {
				$lines[] = sprintf( '  - #%d %s - %s', $row['id'], ( '' !== $row['file'] ? $row['file'] : $row['name'] ), $row['reason'] );
			}
		}

		$lines[] = '';
		$lines[] = admin_url( 'upload.php?page=' . Admin::PAGE );

		return array( $subject, implode( "\n", $lines ) );
	}
}
