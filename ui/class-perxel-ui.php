<?php
/**
 * Perxel shared admin UI — render helpers.
 *
 * Stateless. Every method returns an HTML string; callers echo it, e.g.
 *
 *     echo Perxel_UI::panel( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes internally.
 *
 * Escaping contract:
 *   - Structural markup and the `title` / `label` fields are escaped here.
 *   - `body`, `actions`, `value`, `content`, `sub` are treated as trusted HTML
 *     — the caller is responsible for escaping their dynamic parts.
 *
 * @package Perxel_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Component renderers built on top of native wp-admin classes.
 */
final class Perxel_UI {

	/**
	 * Enqueue the kit stylesheets (ui.css + ui-forms.css) and script
	 * (shared handles, deduped by WP).
	 */
	public static function enqueue() {
		if ( ! defined( 'PERXEL_UI_URL' ) ) {
			return;
		}

		wp_enqueue_style( 'perxel-ui', PERXEL_UI_URL . '/assets/ui.css', array(), PERXEL_UI_VERSION );
		wp_enqueue_style( 'perxel-ui-forms', PERXEL_UI_URL . '/assets/ui-forms.css', array( 'perxel-ui' ), PERXEL_UI_VERSION );
		wp_enqueue_script( 'perxel-ui', PERXEL_UI_URL . '/assets/ui.js', array(), PERXEL_UI_VERSION, true );
	}

	/**
	 * A dismissible notice, built on WP's own `.notice` classes.
	 *
	 * @param string $type success|warning|error|info.
	 * @param string $html Trusted message HTML.
	 * @param array  $args ['dismissible' => bool, 'inline' => bool]. `inline`
	 *               keeps WP from hoisting the notice up to `.wp-header-end`;
	 *               use it for notices rendered inside a card or section.
	 * @return string
	 */
	public static function notice( $type, $html, $args = array() ) {
		$type  = in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info';
		$class = 'notice notice-' . $type . ' pxui-notice';

		if ( ! empty( $args['inline'] ) ) {
			$class .= ' inline';
		}

		if ( ! empty( $args['dismissible'] ) ) {
			$class .= ' is-dismissible';
		}

		return '<div class="' . esc_attr( $class ) . '"><p>' . $html . '</p></div>';
	}

	/**
	 * The headline panel — one per screen, the "what now" block.
	 *
	 * Keys in $args: `status` (success|warning|error|info|action, default info),
	 * `icon` (dashicon slug), `title` (plain text), `body` (trusted HTML),
	 * `actions` (trusted HTML), `progress` (0-100, or null for no bar).
	 *
	 * @param array $args Panel options.
	 * @return string
	 */
	public static function panel( $args ) {
		$d = array_merge(
			array(
				'status'   => 'info',
				'icon'     => '',
				'title'    => '',
				'body'     => '',
				'actions'  => '',
				'progress' => null,
			),
			$args
		);

		$status = in_array( $d['status'], array( 'success', 'warning', 'error', 'info', 'action' ), true ) ? $d['status'] : 'info';

		$out  = '<div class="pxui-panel pxui-panel--' . esc_attr( $status ) . '">';
		$out .= '<div class="pxui-panel__inner">';

		if ( $d['icon'] ) {
			$out .= '<span class="pxui-panel__icon dashicons dashicons-' . esc_attr( $d['icon'] ) . '" aria-hidden="true"></span>';
		}

		$out .= '<div class="pxui-panel__content">';

		if ( '' !== (string) $d['title'] ) {
			$out .= '<p class="pxui-panel__title">' . esc_html( $d['title'] ) . '</p>';
		}

		if ( '' !== (string) $d['body'] ) {
			$out .= '<div class="pxui-panel__body">' . $d['body'] . '</div>';
		}

		if ( null !== $d['progress'] ) {
			$out .= self::progress_bar( (int) $d['progress'] );
		}

		if ( '' !== (string) $d['actions'] ) {
			$out .= '<div class="pxui-panel__actions">' . $d['actions'] . '</div>';
		}

		$out .= '</div></div></div>';

		return $out;
	}

	/**
	 * A standalone progress bar.
	 *
	 * @param int   $pct  0-100.
	 * @param array $args ['id' => string, 'label' => string].
	 * @return string
	 */
	public static function progress_bar( $pct, $args = array() ) {
		$pct = max( 0, min( 100, (int) $pct ) );
		$id  = ! empty( $args['id'] ) ? ' id="' . esc_attr( $args['id'] ) . '"' : '';

		$out  = '<div class="pxui-progress"' . $id . ' role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100">';
		$out .= '<span class="pxui-progress__fill" style="width:' . esc_attr( (string) $pct ) . '%"></span>';
		$out .= '</div>';

		if ( ! empty( $args['label'] ) ) {
			$out .= '<div class="pxui-progress__label">' . $args['label'] . '</div>';
		}

		return $out;
	}

	/**
	 * A grid of stat tiles.
	 *
	 * @param array $tiles Each: [ 'label', 'value', 'sub', 'bar' (0-100|null), 'tone' ].
	 * @return string
	 */
	public static function stat_grid( $tiles ) {
		$out = '<div class="pxui-stat-grid">';

		foreach ( (array) $tiles as $t ) {
			$tone = isset( $t['tone'] ) && in_array( $t['tone'], array( 'good', 'warn', 'bad' ), true ) ? ' pxui-stat--' . $t['tone'] : '';
			$out .= '<div class="pxui-stat' . $tone . '">';
			$out .= '<div class="pxui-stat__label">' . esc_html( isset( $t['label'] ) ? $t['label'] : '' ) . '</div>';
			$out .= '<div class="pxui-stat__value">' . ( isset( $t['value'] ) ? $t['value'] : '' ) . '</div>';

			if ( ! empty( $t['sub'] ) ) {
				$out .= '<div class="pxui-stat__sub">' . $t['sub'] . '</div>';
			}

			if ( isset( $t['bar'] ) && null !== $t['bar'] ) {
				$bar  = max( 0, min( 100, (int) $t['bar'] ) );
				$out .= '<div class="pxui-stat__bar"><span style="width:' . esc_attr( (string) $bar ) . '%"></span></div>';
			}

			$out .= '</div>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * A plain content card.
	 *
	 * @param array $args [ 'title', 'body', 'actions', 'id', 'class' ].
	 * @return string
	 */
	public static function card( $args ) {
		$d = array_merge(
			array(
				'title'   => '',
				'body'    => '',
				'actions' => '',
				'id'      => '',
				'class'   => '',
			),
			$args
		);

		$attr  = $d['id'] ? ' id="' . esc_attr( $d['id'] ) . '"' : '';
		$class = trim( 'pxui-card ' . $d['class'] );

		$out = '<div class="' . esc_attr( $class ) . '"' . $attr . '>';

		if ( '' !== (string) $d['title'] ) {
			$out .= '<h2 class="pxui-card__title">' . esc_html( $d['title'] ) . '</h2>';
		}

		$out .= '<div class="pxui-card__body">' . $d['body'] . '</div>';

		if ( '' !== (string) $d['actions'] ) {
			$out .= '<div class="pxui-card__actions">' . $d['actions'] . '</div>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * An iOS-style grouped settings list: one or more groups, each an optional
	 * title above a rounded card of flex rows (label left, content right). The
	 * card is the only shadowed element.
	 *
	 * Pass either a flat list of rows (one implicit group) or a list of
	 * groups: `[ [ 'title' => 'Group', 'rows' => [ row, row ] ], … ]`.
	 *
	 * Each row: `[ 'label' => plain text, 'sub' => trusted HTML (secondary
	 * line under the label), 'content' => trusted HTML (text, a toggle(), a
	 * <select> or a button), 'tone' => good|warn|bad ]`.
	 *
	 * @param array $groups Flat row list, or a list of groups.
	 * @return string
	 */
	public static function rows( $groups ) {
		$groups = (array) $groups;

		// Flat row list → wrap in a single untitled group.
		if ( ! isset( $groups[0]['rows'] ) ) {
			$groups = array( array( 'rows' => $groups ) );
		}

		$out = '<div class="pxui-rows">';

		foreach ( $groups as $group ) {
			$out .= '<div class="pxui-rows__group">';

			if ( ! empty( $group['title'] ) ) {
				$out .= '<p class="pxui-rows__title">' . esc_html( $group['title'] ) . '</p>';
			}

			$out .= '<div class="pxui-rows__card">';

			foreach ( (array) ( isset( $group['rows'] ) ? $group['rows'] : array() ) as $r ) {
				$tone = isset( $r['tone'] ) && in_array( $r['tone'], array( 'good', 'warn', 'bad' ), true ) ? ' pxui-row--' . $r['tone'] : '';

				$out .= '<div class="pxui-row' . $tone . '">';
				$out .= '<span class="pxui-row__label">' . esc_html( isset( $r['label'] ) ? $r['label'] : '' );

				if ( ! empty( $r['sub'] ) ) {
					$out .= '<span class="pxui-row__sub">' . $r['sub'] . '</span>';
				}

				$out .= '</span>';
				$out .= '<span class="pxui-row__content">' . ( isset( $r['content'] ) ? $r['content'] : '' ) . '</span>';
				$out .= '</div>';
			}

			$out .= '</div></div>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * A toggle — a checkbox, which the kit CSS renders as an iOS switch.
	 * Handy as row `content`; a plain `<input type="checkbox">` inside
	 * `.pxui-wrap` renders identically.
	 *
	 * @param array $args [ 'name', 'checked' (bool), 'value', 'id', 'form',
	 *              'label' (accessible name) ].
	 * @return string
	 */
	public static function toggle( $args = array() ) {
		$d = array_merge(
			array(
				'name'    => '',
				'checked' => false,
				'value'   => '1',
				'id'      => '',
				'form'    => '',
				'label'   => '',
			),
			$args
		);

		$attr  = $d['name'] ? ' name="' . esc_attr( $d['name'] ) . '"' : '';
		$attr .= $d['id'] ? ' id="' . esc_attr( $d['id'] ) . '"' : '';
		$attr .= $d['form'] ? ' form="' . esc_attr( $d['form'] ) . '"' : '';
		$attr .= ' value="' . esc_attr( $d['value'] ) . '"';
		$attr .= $d['checked'] ? ' checked' : '';
		$attr .= $d['label'] ? ' aria-label="' . esc_attr( $d['label'] ) . '"' : '';

		return '<input type="checkbox"' . $attr . ' />';
	}

	/**
	 * An inline loading spinner.
	 *
	 * @return string
	 */
	public static function spinner() {
		return '<span class="pxui-spinner" role="status" aria-label="Loading"></span>';
	}

	/**
	 * A visually separated "danger zone" wrapper.
	 *
	 * @param string $html Trusted HTML (buttons + copy).
	 * @param array  $args [ 'title' => string ].
	 * @return string
	 */
	public static function danger_zone( $html, $args = array() ) {
		$title = isset( $args['title'] ) && '' !== $args['title'] ? $args['title'] : 'Danger zone';

		return '<div class="pxui-danger"><h2 class="pxui-danger__title">' . esc_html( $title ) . '</h2>' . $html . '</div>';
	}
}
