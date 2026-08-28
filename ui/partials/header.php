<?php
/**
 * Layout partial: header bar (brand + version + links).
 *
 * @package Perxel_UI
 *
 * @var array $d Layout args from Perxel_UI_Layout::open().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pxui-header">
	<div class="pxui-brand"><?php echo esc_html( $d['plugin'] ); ?></div>
	<div class="pxui-header-meta">
		<?php if ( '' !== (string) $d['version'] ) : ?>
			<span class="pxui-version">v<?php echo esc_html( $d['version'] ); ?></span>
		<?php endif; ?>
		<?php foreach ( (array) $d['links'] as $label => $url ) : ?>
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</div>
</div>
