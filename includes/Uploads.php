<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert new uploads automatically, right after WordPress generates their
 * sub-sizes.
 */
class Uploads {

	/**
	 * Hooks.
	 */
	public function register() {
		if ( ! Settings::get( 'convert_on_upload' ) ) {
			return;
		}

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_metadata' ), 20, 2 );
	}

	/**
	 * @param array $metadata      Attachment metadata (passed through untouched).
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function on_metadata( $metadata, $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );

		if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) && Environment::can_convert() ) {
			try {
				$result = Converter::convert_attachment( (int) $attachment_id );
				Metrics::apply( $result );
			} catch ( \Throwable $e ) {
				// Never block an upload over a conversion failure.
			}
		}

		return $metadata;
	}
}
