<?php
/**
 * Uninstall cleanup for Perxel Image Optimizer.
 *
 * Removes the plugin's own settings, state, and the managed .htaccess block, plus
 * every per-attachment status record (old and new keys).
 *
 * It does NOT delete the .webp files under uploads/ — that can be a very large,
 * slow operation. Run "Media → Image Optimizer → Remove all WebP" before deleting
 * the plugin if you want the files gone too.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'perxel_image_optimizer_settings',
	'perxel_image_optimizer_metrics',
	'perxel_image_optimizer_state',   // pre-redesign runner state
	'perxel_image_optimizer_job',
	'perxel_image_optimizer_scan',
	'perxel_image_optimizer_failures',
	'perxel_image_optimizer_serve_mode',
	'perxel_image_optimizer_purge',
	'perxel_image_optimizer_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'perxel_image_optimizer_recalcing' );

// Best-effort: drop our scheduled background actions if Action Scheduler is
// loaded. Any leftover rows are inert once the callbacks are gone and Action
// Scheduler prunes them on its own retention schedule.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'perxel-image-optimizer' );
}

// Action Scheduler's own tables (actionscheduler_*) are left in place — the
// library is shared infrastructure and may still be in use by another plugin.

// Per-attachment status meta (current keys + pre-1.0 key).
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_perxel_image_optimizer', '_perxel_image_optimizer_sig', '_perxel_webp' )"
);

// Per-attachment conversion locks.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_perxel\\_image\\_optimizer\\_lock\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_perxel\\_image\\_optimizer\\_lock\\_%'"
);

// Managed .htaccess block.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$htaccess = get_home_path() . '.htaccess';

if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	insert_with_markers( $htaccess, 'Perxel Image Optimizer', array() );
	insert_with_markers( $htaccess, 'Perxel WebP', array() );
}

wp_cache_flush();
