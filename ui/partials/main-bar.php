<?php
/**
 * Layout partial: main column sticky bar (page title + external links).
 *
 * @package Perxel_UI
 *
 * @var array $d Layout args from Perxel_UI_Layout::open().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( '' === (string) $d['title'] && empty( $d['links'] ) ) {
	return;
}
?>
<div class="pxui-main__bar">
	<?php if ( '' !== (string) $d['title'] ) : ?>
		<h1 class="pxui-title"><?php echo esc_html( $d['title'] ); ?></h1>
	<?php endif; ?>
	<?php if ( ! empty( $d['links'] ) ) : ?>
		<div class="pxui-main__links">
			<?php foreach ( (array) $d['links'] as $label => $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
