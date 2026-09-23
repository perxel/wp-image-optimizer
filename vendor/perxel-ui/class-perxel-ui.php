<?php
/**
 * Perxel shared admin UI - render helpers.
 *
 * Stateless. Every method returns an HTML string; callers escape it late with
 * the kit's allowlist when they echo it, e.g.
 *
 *     echo wp_kses( Perxel_UI::rows( $groups ), Perxel_UI::allowed_html() );
 *
 * Escaping contract:
 *   - Structural markup and the `title` / `label` fields are escaped here.
 *   - `body`, `actions`, `value`, `content`, `sub` are treated as trusted HTML
 *     - the caller is responsible for escaping their dynamic parts.
 *   - The final echo still goes through `wp_kses()` + `allowed_html()`, so
 *     nothing outside the kit's own tag set reaches the page.
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
	 * The `wp_kses()` allowlist for kit markup: everything `wp_kses_post()`
	 * allows, plus the form controls, disclosure / dialog elements and inline
	 * SVG icons that kit components and their trusted-HTML slots carry. Every
	 * tag also accepts `data-*`, the ARIA attributes the kit uses, `hidden`,
	 * `tabindex` and `style`. No `<script>`, `<style>` or `on*` handlers.
	 * Use it as `echo wp_kses( $html, Perxel_UI::allowed_html() );`.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		static $allowed = null;

		if ( null !== $allowed ) {
			return $allowed;
		}

		$global = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'hidden'           => true,
			'tabindex'         => true,
			'lang'             => true,
			'dir'              => true,
			'data-*'           => true,
			'aria-busy'        => true,
			'aria-checked'     => true,
			'aria-controls'    => true,
			'aria-current'     => true,
			'aria-describedby' => true,
			'aria-disabled'    => true,
			'aria-expanded'    => true,
			'aria-hidden'      => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-live'        => true,
			'aria-modal'       => true,
			'aria-pressed'     => true,
			'aria-selected'    => true,
			'aria-valuemax'    => true,
			'aria-valuemin'    => true,
			'aria-valuenow'    => true,
		);

		$field = array(
			'name'         => true,
			'value'        => true,
			'form'         => true,
			'disabled'     => true,
			'required'     => true,
			'readonly'     => true,
			'autocomplete' => true,
			'autofocus'    => true,
			'placeholder'  => true,
			'spellcheck'   => true,
		);

		$svg_paint = array(
			'fill'              => true,
			'fill-rule'         => true,
			'clip-rule'         => true,
			'stroke'            => true,
			'stroke-width'      => true,
			'stroke-linecap'    => true,
			'stroke-linejoin'   => true,
			'stroke-dasharray'  => true,
			'stroke-dashoffset' => true,
			'opacity'           => true,
			'transform'         => true,
		);

		$extra = array(
			'form'     => array(
				'action'       => true,
				'method'       => true,
				'enctype'      => true,
				'target'       => true,
				'name'         => true,
				'novalidate'   => true,
				'autocomplete' => true,
			),
			'input'    => $field + array(
				'type'      => true,
				'checked'   => true,
				'min'       => true,
				'max'       => true,
				'step'      => true,
				'minlength' => true,
				'maxlength' => true,
				'size'      => true,
				'pattern'   => true,
				'list'      => true,
				'multiple'  => true,
				'accept'    => true,
				'inputmode' => true,
			),
			'select'   => $field + array(
				'multiple' => true,
				'size'     => true,
			),
			'option'   => array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
				'label'    => true,
			),
			'optgroup' => array(
				'label'    => true,
				'disabled' => true,
			),
			'textarea' => $field + array(
				'rows'      => true,
				'cols'      => true,
				'minlength' => true,
				'maxlength' => true,
				'wrap'      => true,
			),
			'button'   => $field + array(
				'type'           => true,
				'formaction'     => true,
				'formmethod'     => true,
				'formnovalidate' => true,
			),
			'label'    => array(
				'for'  => true,
				'form' => true,
			),
			'fieldset' => array(
				'name'     => true,
				'form'     => true,
				'disabled' => true,
			),
			'legend'   => array(),
			'datalist' => array(),
			'output'   => array(
				'for'  => true,
				'form' => true,
				'name' => true,
			),
			'progress' => array(
				'value' => true,
				'max'   => true,
			),
			'meter'    => array(
				'value'   => true,
				'min'     => true,
				'max'     => true,
				'low'     => true,
				'high'    => true,
				'optimum' => true,
			),
			'details'  => array( 'open' => true ),
			'summary'  => array(),
			'dialog'   => array( 'open' => true ),
			'svg'      => $svg_paint + array(
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'focusable'           => true,
				'preserveaspectratio' => true,
			),
			'g'        => $svg_paint,
			'defs'     => array(),
			'symbol'   => array( 'viewbox' => true ),
			'use'      => array(
				'href'       => true,
				'xlink:href' => true,
			),
			'path'     => $svg_paint + array( 'd' => true ),
			'circle'   => $svg_paint + array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'ellipse'  => $svg_paint + array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			),
			'rect'     => $svg_paint + array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			),
			'line'     => $svg_paint + array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'polyline' => $svg_paint + array( 'points' => true ),
			'polygon'  => $svg_paint + array( 'points' => true ),
		);

		$allowed = wp_kses_allowed_html( 'post' );

		foreach ( $extra as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( isset( $allowed[ $tag ] ) ? $allowed[ $tag ] : array(), $attrs );
		}

		foreach ( $allowed as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : array(), $global );
		}

		return $allowed;
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
	 * A compact inline meter for a `rows()` value slot: a short track with the
	 * percentage as its label. Unlike `progress_bar()` (a full-width block that
	 * stands alone), this keeps the row's height and sits among other figures.
	 *
	 * @param int   $pct  0-100.
	 * @param array $args ['id' => wrapper id (for live updates: set
	 *                    `.pxui-meter__fill` width + `.pxui-meter__text` text),
	 *                    'text' => label before the track, default "N%"; pass ''
	 *                    to hide it, 'width' => track width in px, default 96,
	 *                    'tone' => 'good'|'warn'|'bad' fill colour].
	 * @return string
	 */
	public static function meter( $pct, $args = array() ) {
		$pct   = max( 0, min( 100, (int) $pct ) );
		$id    = ! empty( $args['id'] ) ? ' id="' . esc_attr( $args['id'] ) . '"' : '';
		$text  = array_key_exists( 'text', $args ) ? (string) $args['text'] : $pct . '%';
		$width = ! empty( $args['width'] ) ? (int) $args['width'] : 96;
		$tone  = isset( $args['tone'] ) && in_array( $args['tone'], array( 'good', 'warn', 'bad' ), true ) ? ' pxui-meter--' . $args['tone'] : '';

		$out = '<span class="pxui-meter' . $tone . '"' . $id . ' role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100">';
		if ( '' !== $text ) {
			$out .= '<span class="pxui-meter__text">' . esc_html( $text ) . '</span>';
		}
		$out .= '<span class="pxui-meter__track" style="width:' . esc_attr( (string) $width ) . 'px">';
		$out .= '<span class="pxui-meter__fill" style="width:' . esc_attr( (string) $pct ) . '%"></span>';
		$out .= '</span></span>';

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
	 * A group with `'danger' => true` is styled as a destructive zone - red
	 * title, red hairline card, buttons in the warning colour - for a screen's
	 * cleanup / destructive-action section.
	 *
	 * A group with a `note` key renders that trusted HTML (or plain string) as a
	 * muted footnote below the card - a description, a caveat, a "learn more"
	 * link for the whole group. Left-aligned with the title. Groups only; a flat
	 * row list has nowhere to put one.
	 *
	 * Each row: `[ 'label' => plain text, 'sub' => trusted HTML (secondary
	 * line under the label), 'content' => trusted HTML (text, a toggle(), a
	 * <select> or a button), 'tone' => good|warn|bad, 'icon' => … ]`.
	 *
	 * A group takes an optional `title_action` - trusted HTML (a button)
	 * pinned to the right of the group title. Use it instead of putting an
	 * action button next to an input inside a row: a row has little room, so
	 * one action for the whole group belongs on the title line.
	 *
	 * `icon` (any row) puts a fixed square left of the label + sub, centred
	 * against both: `good|warn|bad` draws a filled status dot (✓ / ! / ✕),
	 * `muted` draws a neutral grey dot;
	 * any other non-empty string is trusted HTML (a dashicon, an `<svg>`, an
	 * emoji) sized to the same frame.
	 *
	 * A row with a `summary` key becomes a disclosure instead: the summary text
	 * sits where the label goes, the chevron takes the right edge (with optional
	 * `content` trusted HTML - a count, a status - just left of it), and
	 * `details` (trusted HTML) reveals full-width below when the row is clicked.
	 * Native `<details>` - no JS. `[ 'summary' => plain text, 'sub' => trusted
	 * HTML, 'content' => trusted HTML, 'details' => trusted HTML, 'open' => bool,
	 * 'tone' => good|warn|bad, 'icon' => … ]`.
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
			$danger = ! empty( $group['danger'] );
			$out   .= '<div class="pxui-rows__group' . ( $danger ? ' pxui-rows__group--danger' : '' ) . '">';

			$has_title  = ! empty( $group['title'] );
			$has_action = isset( $group['title_action'] ) && '' !== trim( (string) $group['title_action'] );

			if ( $has_title || $has_action ) {
				$out .= '<div class="pxui-rows__titlebar">';
				$out .= '<p class="pxui-rows__title">' . esc_html( $has_title ? $group['title'] : '' ) . '</p>';
				if ( $has_action ) {
					$out .= '<span class="pxui-rows__title-action">' . $group['title_action'] . '</span>';
				}
				$out .= '</div>';
			}

			$out .= '<div class="pxui-rows__card">';

			foreach ( (array) ( isset( $group['rows'] ) ? $group['rows'] : array() ) as $r ) {
				$tone = isset( $r['tone'] ) && in_array( $r['tone'], array( 'good', 'warn', 'bad' ), true ) ? ' pxui-row--' . $r['tone'] : '';

				// Optional leading icon - its own fixed square, left of the
				// label + sub and centred against both. `icon => good|warn|bad`
				// draws a filled status dot (✓ / ! / ✕), `muted` a neutral grey
				// dot; any other non-empty string is trusted HTML (a dashicon,
				// an <svg>, an emoji) dropped into the same frame.
				$icon = '';
				if ( ! empty( $r['icon'] ) ) {
					$preset = in_array( $r['icon'], array( 'good', 'warn', 'bad', 'muted' ), true );
					$icon   = '<span class="pxui-row__icon' . ( $preset ? ' pxui-row__icon--' . $r['icon'] : '' ) . '" aria-hidden="true">'
						. ( $preset ? '' : $r['icon'] )
						. '</span>';
				}
				$has_icon = '' !== $icon ? ' pxui-row--has-icon' : '';

				// Disclosure row: a native <details> styled as a row. The whole
				// summary line is the click target; the reveal drops below.
				if ( isset( $r['summary'] ) ) {
					$out .= '<details class="pxui-row pxui-row--disclosure' . $tone . $has_icon . '"' . ( empty( $r['open'] ) ? '' : ' open' ) . '>';
					$out .= '<summary class="pxui-row__summary">';
					$out .= $icon;
					$out .= '<span class="pxui-row__label">' . esc_html( $r['summary'] );

					if ( ! empty( $r['sub'] ) ) {
						$out .= '<span class="pxui-row__sub">' . $r['sub'] . '</span>';
					}

					$out .= '</span>';
					$out .= '<span class="pxui-row__content">';
					$out .= isset( $r['content'] ) ? $r['content'] : '';
					$out .= '<span class="pxui-row__chevron" aria-hidden="true"></span>';
					$out .= '</span>';
					$out .= '</summary>';
					$out .= '<div class="pxui-row__reveal">' . ( isset( $r['details'] ) ? $r['details'] : '' ) . '</div>';
					$out .= '</details>';
					continue;
				}

				$content = isset( $r['content'] ) ? (string) $r['content'] : '';

				$out .= '<div class="pxui-row' . $tone . $has_icon . '">';
				$out .= $icon;
				$out .= '<span class="pxui-row__label">' . esc_html( isset( $r['label'] ) ? $r['label'] : '' );

				if ( ! empty( $r['sub'] ) ) {
					$out .= '<span class="pxui-row__sub">' . $r['sub'] . '</span>';
				}

				$out .= '</span>';
				$out .= '<span class="pxui-row__content">' . $content . '</span>';
				$out .= '</div>';
			}

			$out .= '</div>';

			if ( ! empty( $group['note'] ) ) {
				$out .= '<p class="pxui-rows__note">' . $group['note'] . '</p>';
			}

			$out .= '</div>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * A toggle - an `<input type="checkbox" class="pxui-toggle">`, which the
	 * kit CSS renders as an iOS switch. Handy as row `content`. A plain
	 * checkbox (no class) is a square box with a tick; add `pxui-toggle`
	 * yourself for the switch look, or call this.
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

		return '<input type="checkbox" class="pxui-toggle"' . $attr . ' />';
	}

	/**
	 * A checkbox group - a "pick several" list rendered as selectable pills.
	 * Each option keeps a real `<input type="checkbox">` in the DOM (form
	 * state, keyboard, a11y) but hidden; the pill is the control - hairline
	 * border at rest, brand fill when selected. Flows inline and wraps.
	 * Handy as row `content`.
	 *
	 * Each option is `value => label`, or an array with `value`, `label`,
	 * `sub` (a muted second line under the label - dimensions, a hint),
	 * `checked` (overrides `selected`). `label` and `sub` are escaped as
	 * plain text.
	 *
	 * @param array $args [ 'name' ("[]" appended if absent), 'form',
	 *              'options' => [ value => label | [ … ] ],
	 *              'selected' => [ value, … ] ].
	 * @return string
	 */
	public static function checkbox_group( $args = array() ) {
		$d = array_merge(
			array(
				'name'     => '',
				'form'     => '',
				'options'  => array(),
				'selected' => array(),
			),
			$args
		);

		$name = (string) $d['name'];
		if ( '' !== $name && '[]' !== substr( $name, -2 ) ) {
			$name .= '[]';
		}

		$name_attr = '' !== $name ? ' name="' . esc_attr( $name ) . '"' : '';
		$form_attr = $d['form'] ? ' form="' . esc_attr( $d['form'] ) . '"' : '';
		$selected  = array_map( 'strval', (array) $d['selected'] );

		$out = '<span class="pxui-checks">';

		foreach ( (array) $d['options'] as $key => $opt ) {
			if ( ! is_array( $opt ) ) {
				$opt = array( 'label' => $opt );
			}

			$value   = isset( $opt['value'] ) ? (string) $opt['value'] : (string) $key;
			$label   = isset( $opt['label'] ) ? $opt['label'] : $value;
			$sub     = isset( $opt['sub'] ) ? (string) $opt['sub'] : '';
			$checked = array_key_exists( 'checked', $opt )
				? (bool) $opt['checked']
				: in_array( $value, $selected, true );

			$out .= '<label class="pxui-check">'
				. '<input type="checkbox"' . $name_attr . $form_attr
				. ' value="' . esc_attr( $value ) . '"' . ( $checked ? ' checked' : '' ) . ' />'
				. '<span class="pxui-check__label">' . esc_html( $label ) . '</span>'
				. ( '' !== $sub ? '<span class="pxui-check__sub">' . esc_html( $sub ) . '</span>' : '' )
				. '</label>';
		}

		return $out . '</span>';
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
	 * A read-only preformatted block - config snippets, generated rules, log
	 * output. Scrolls sideways rather than wrapping. Reads well inside a
	 * disclosure row's `details`.
	 *
	 * @param string $text Plain text; escaped here.
	 * @param array  $args [ 'label' => caption above the block, 'id' ].
	 * @return string
	 */
	public static function code( $text, $args = array() ) {
		$id_attr = ! empty( $args['id'] ) ? ' id="' . esc_attr( $args['id'] ) . '"' : '';
		$label   = isset( $args['label'] ) && '' !== $args['label']
			? '<span class="pxui-code__label">' . esc_html( $args['label'] ) . '</span>'
			: '';

		return $label . '<pre class="pxui-code"' . $id_attr . '>' . esc_html( (string) $text ) . '</pre>';
	}

	/**
	 * A WordPress media-library picker: a hidden `<input>` holding the chosen
	 * attachment ID (a comma-joined list when `multiple`), a live preview, and
	 * Choose / Remove controls. `ui.js` drives the native `wp.media` frame, so
	 * the screen must call `wp_enqueue_media()` itself - core only auto-loads
	 * the media library on post-edit screens, not a custom admin page.
	 *
	 * Stores bare attachment IDs. Read them back with
	 * `absint( $_POST[ $name ] )`, or `wp_parse_id_list( $_POST[ $name ] )` for
	 * a `multiple` field. Handy as row `content`.
	 *
	 * @param array $args [
	 *   'name'         => string        hidden input name,
	 *   'value'        => int|int[]|string  current ID, or a list / CSV of IDs,
	 *   'type'         => string        '' | 'image' | 'audio' | 'video' - wp.media library filter,
	 *   'multiple'     => bool          allow several; the value becomes a CSV (default false),
	 *   'form'         => string        `form=` attribute for the hidden input,
	 *   'label'        => string        button text (default "Choose file" / "Add files"),
	 *   'preview_size' => string        registered image size for thumbnails (default 'thumbnail'),
	 * ]
	 * @return string
	 */
	public static function media( $args = array() ) {
		$d = array_merge(
			array(
				'name'         => '',
				'value'        => '',
				'type'         => '',
				'multiple'     => false,
				'form'         => '',
				'label'        => '',
				'preview_size' => 'thumbnail',
			),
			$args
		);

		$multiple = ! empty( $d['multiple'] );

		// Normalise the value to a list of positive ints.
		$ids = is_array( $d['value'] )
			? $d['value']
			: preg_split( '/[\s,]+/', (string) $d['value'], -1, PREG_SPLIT_NO_EMPTY );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( ! $multiple ) {
			$ids = $ids ? array( $ids[0] ) : array();
		}

		$size  = (string) $d['preview_size'];
		$type  = preg_replace( '/[^a-z]/', '', strtolower( (string) $d['type'] ) );
		$label = '' !== (string) $d['label']
			? (string) $d['label']
			: ( $multiple ? 'Add files' : 'Choose file' );

		$wrap  = '<div class="pxui-media' . ( $multiple ? ' pxui-media--multiple' : '' ) . '"';
		$wrap .= ' data-preview-size="' . esc_attr( $size ) . '"';
		$wrap .= '' !== $type ? ' data-type="' . esc_attr( $type ) . '"' : '';
		$wrap .= $multiple ? ' data-multiple="1"' : '';
		$wrap .= '>';

		$out  = $wrap;
		$out .= '<input type="hidden" class="pxui-media__value"'
			. ( $d['name'] ? ' name="' . esc_attr( $d['name'] ) . '"' : '' )
			. ( $d['form'] ? ' form="' . esc_attr( $d['form'] ) . '"' : '' )
			. ' value="' . esc_attr( implode( ',', $ids ) ) . '" />';

		$out .= '<span class="pxui-media__list"' . ( $ids ? '' : ' hidden' ) . '>';
		foreach ( $ids as $id ) {
			$out .= self::media_item( $id, $size );
		}
		$out .= '</span>';

		$out .= '<span class="pxui-media__actions">';
		$out .= '<button type="button" class="button button-small pxui-media__choose">' . esc_html( $label ) . '</button>';
		$out .= '<button type="button" class="pxui-media__clear"' . ( $ids ? '' : ' hidden' ) . '>'
			. esc_html( $multiple ? 'Remove all' : 'Remove' ) . '</button>';
		$out .= '</span>';

		$out .= '</div>';

		return $out;
	}

	/**
	 * One preview tile for `media()` - a thumbnail for an image, a filename
	 * chip for anything else, with a per-item remove button. `ui.js` builds
	 * the same shape when the user picks a new attachment, so keep the two in
	 * step.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Registered image size for the thumbnail.
	 * @return string
	 */
	private static function media_item( $id, $size = 'thumbnail' ) {
		$id = absint( $id );
		if ( ! $id ) {
			return '';
		}

		$thumb = wp_get_attachment_image_url( $id, $size );
		if ( $thumb ) {
			$inner = '<img src="' . esc_url( $thumb ) . '" alt="" />';
		} else {
			$file  = wp_basename( (string) get_attached_file( $id ) );
			$inner = '<span class="pxui-media__file">' . esc_html( '' !== $file ? $file : get_the_title( $id ) ) . '</span>';
		}

		return '<span class="pxui-media__item" data-id="' . esc_attr( (string) $id ) . '">'
			. $inner
			. '<button type="button" class="pxui-media__drop" aria-label="Remove">&times;</button>'
			. '</span>';
	}

	/**
	 * A colour picker: a native `<input type="color">` swatch beside a hex
	 * text field. `ui.js` keeps the two in sync; with JS off the swatch alone
	 * still works. The text field carries the input name, so a typed or pasted
	 * `#rrggbb` submits. Value is a `#rrggbb` string ('' renders an unset
	 * control). Handy as row `content`.
	 *
	 * @param array $args [ 'name', 'value' => '#rrggbb', 'form', 'label' (accessible name) ].
	 * @return string
	 */
	public static function color( $args = array() ) {
		$d = array_merge(
			array(
				'name'  => '',
				'value' => '',
				'form'  => '',
				'label' => '',
			),
			$args
		);

		$hex   = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $d['value'] ) ? strtolower( (string) $d['value'] ) : '';
		$form  = $d['form'] ? ' form="' . esc_attr( $d['form'] ) . '"' : '';
		$label = $d['label'] ? ' aria-label="' . esc_attr( $d['label'] ) . '"' : '';

		$out  = '<span class="pxui-color">';
		$out .= '<input type="color" class="pxui-color__swatch" tabindex="-1" aria-hidden="true"'
			. ' value="' . esc_attr( '' !== $hex ? $hex : '#000000' ) . '" />';
		$out .= '<input type="text" class="pxui-color__hex" spellcheck="false" autocomplete="off"'
			. ' maxlength="7" placeholder="#rrggbb"'
			. ( $d['name'] ? ' name="' . esc_attr( $d['name'] ) . '"' : '' )
			. $form . $label
			. ' value="' . esc_attr( $hex ) . '" />';
		$out .= '</span>';

		return $out;
	}
}
