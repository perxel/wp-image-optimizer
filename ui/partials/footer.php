<?php
/**
 * Layout partial: footer line (author left, links right).
 *
 * @package Perxel_UI
 *
 * @var array $d Layout args from Perxel_UI_Layout::open().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$author      = (array) $d['author'];
$author_name = isset( $author['name'] ) ? (string) $author['name'] : '';
$author_url  = isset( $author['url'] ) ? (string) $author['url'] : '';

if ( '' === $author_name && empty( $d['links'] ) ) {
	return;
}
?>
<div class="pxui-footer">
	<span class="pxui-footer__author">
		<?php if ( '' !== $author_name && '' !== $author_url ) : ?>
			By <a href="<?php echo esc_url( $author_url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $author_name ); ?></a>
		<?php elseif ( '' !== $author_name ) : ?>
			By <?php echo esc_html( $author_name ); ?>
		<?php endif; ?>
	</span>
	<?php if ( ! empty( $d['links'] ) ) : ?>
		<span class="pxui-footer__links">
			<?php foreach ( (array) $d['links'] as $label => $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</span>
	<?php endif; ?>
</div>
