<?php
/**
 * Layout partial: footer line.
 *
 * @package Perxel_UI
 *
 * @var array $d Layout args from Perxel_UI_Layout::open().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pxui-footer">
	<?php
	echo esc_html( $d['plugin'] );
	if ( '' !== (string) $d['version'] ) {
		echo ' &middot; v' . esc_html( $d['version'] );
	}
	?>
</div>
