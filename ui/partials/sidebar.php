<?php
/**
 * Layout partial: left feature sidebar.
 *
 * @package Perxel_UI
 *
 * @var array $d Layout args from Perxel_UI_Layout::open().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<nav class="pxui-sidebar" aria-label="<?php echo esc_attr( $d['plugin'] ); ?>">
	<?php foreach ( (array) $d['menu'] as $group => $items ) : ?>
		<?php if ( '' !== (string) $group ) : ?>
			<div class="pxui-sidebar__group"><?php echo esc_html( $group ); ?></div>
		<?php endif; ?>
		<ul class="pxui-sidebar__list">
			<?php foreach ( (array) $items as $slug => $label ) : ?>
				<?php $is_active = ( (string) $slug === (string) $d['current'] ); ?>
				<li class="pxui-sidebar__item<?php echo $is_active ? ' is-active' : ''; ?>">
					<a href="<?php echo esc_url( admin_url( $d['base'] . '?page=' . $slug ) ); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
</nav>
