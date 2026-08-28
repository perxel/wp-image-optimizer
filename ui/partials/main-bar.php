<?php
/**
 * Layout partial: main column sticky bar (page title + actions).
 *
 * @package Perxel_UI
 *
 * @var array $d Layout args from Perxel_UI_Layout::open().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_title   = '' !== (string) $d['title'];
$has_actions = '' !== trim( (string) $d['actions'] );

if ( ! $has_title && ! $has_actions ) {
	return;
}
?>
<div class="pxui-main__bar">
	<?php if ( $has_title ) : ?>
		<h1 class="pxui-title"><?php echo esc_html( $d['title'] ); ?></h1>
	<?php endif; ?>
	<?php if ( $has_actions ) : ?>
		<div class="pxui-main__actions"><?php echo $d['actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted caller-supplied HTML. ?></div>
	<?php endif; ?>
</div>
