# Perxel shared admin UI — changelog

Versioned independently of any plugin. Within a major version, changes are
additive only (see `README.md` → "Versioning").

## 0.9.0

- `Perxel_UI_Layout::set_page_titles( [ slug => page name ], $plugin )` — own the
  browser `<title>` for the kit's screens. WordPress builds a tab title as
  "Page ‹ Site — WordPress", and a page hidden with `remove_submenu_page()` (the
  usual pattern for a settings screen kept off the menu) drops even that, leaving
  a bare " ‹ Site — WordPress". A kit screen carries the wp-admin chrome in its
  sidebar, so via the `admin_title` filter the tab instead reads
  `Site • Page • Plugin`. Call on `admin_menu` with the slugs passed to
  `add_submenu_page()`.
- Additive — new static method, no markup or CSS change.

## 0.8.0

- Text and number `<input>`s used as a `rows()` row `content` now get a compact
  field style — hairline box at rest, muted border on hover, brand ring on
  focus — to match the `<select>` row-value treatment. Number inputs are sized
  to their value and right-aligned. New hooks:
  `.pxui-row__content input[type="text"|"number"|"email"|"url"|"search"]`.
- Buttons in a danger row group (`.pxui-rows__group--danger`) and in
  `danger_zone()` fill with the destructive red on hover/focus, instead of
  falling back to wp-admin's blue `.button` hover.
- Purely additive — CSS on existing markup; no API change.

## 0.7.0

- `Perxel_UI::rows()` groups take `'danger' => true` — the group renders as a
  destructive zone: red uppercase title, red hairline card over the warning
  tint, buttons in the warning colour. The grouped-row equivalent of
  `danger_zone()`, so a screen's cleanup section matches its other settings
  groups. New hook: `.pxui-rows__group--danger`. `danger_zone()` is unchanged.
  Additive — existing groups render exactly as before.

## 0.6.0

- `Perxel_UI::code( $text, $args )` — a read-only preformatted block for config
  snippets, generated rules or log output. Scrolls sideways instead of
  wrapping; optional `label` caption, `id`. Text is escaped. New hooks:
  `.pxui-code`, `.pxui-code__label`.
- `Perxel_UI::rows()` gains a disclosure row: a row with a `summary` key renders
  as a native `<details>` styled as a row — summary text where the label sits, a
  chevron in the content slot, `details` (trusted HTML) revealing full-width
  below on click. Also takes `sub`, `open`, `tone`. No JS. New hooks:
  `.pxui-row--disclosure`, `.pxui-row__summary`, `.pxui-row__chevron`,
  `.pxui-row__reveal`.
  Additive — new method, new opt-in row shape, new CSS; existing rows unchanged.

## 0.5.0

- `Perxel_UI::checkbox_group( $args )` — a "pick several" list for a settings
  row, rendered as selectable **pills**. Each option keeps a real hidden
  `<input type="checkbox">` (form state, keyboard, a11y); the pill is the
  control — hairline border at rest, brand fill when selected. Flows inline
  and wraps. `options` is `value => label` or an array per option (`value`,
  `label`, `sub` — a muted second line under the label, `checked`); `name`
  (auto-appends `[]`), `form`, `selected`. New hooks: `.pxui-checks`,
  `.pxui-check`, `.pxui-check__label`, `.pxui-check__sub`.
- New `.pxui-checkbox` class — add it to an `<input type="checkbox">` to opt
  out of the iOS-toggle default and get a real square box with a brand tick.
- Additive — new method + CSS, nothing else changes.

## 0.4.0

- Layout: the page content between `Perxel_UI_Layout::open()` and `close()` is
  now wrapped in `<div class="pxui-main__body">` — everything inside `<main>`
  except the sticky title bar and the footer. Gives screens a single content
  scope to target (padding, max-width, scroll containment) without reaching the
  bar or footer. The `wp-header-end` marker sits just inside it, so hoisted
  `.notice` elements still land in the content flow. Additive — new hook
  `.pxui-main__body`; `open()/close()` signature unchanged.

## 0.3.0

- Row-content `<select>` (`.pxui-row__content select`) now renders as a quiet
  "ghost" control: transparent border/background and muted text at rest, so it
  reads as the row's value; hairline border + full-contrast text on hover,
  brand-accent border on focus. Tokens only, no new markup hooks. Additive —
  a `<select>` passed as row `content` picks it up automatically.

## 0.2.0

- Showcase page (**Tools → Perxel UI**) is now always registered in the admin
  for `manage_options` users, no longer gated behind `WP_DEBUG`.
- Layout: dropped the full-width page header. Brand + version now live in a
  `position: sticky` bar at the top of the sidebar; the page title sits in a
  matching sticky bar at the top of `<main>`. The footer is rendered inside
  `<main>` and carries `author` (left) + `links` (right) — no longer the brand
  and version, which would just repeat the sidebar. `Perxel_UI_Layout::open()/
  close()` signature is unchanged. New markup hooks: `.pxui-sidebar__bar`,
  `.pxui-sidebar__nav`, `.pxui-main__bar`, `.pxui-footer__author`,
  `.pxui-footer__links` (replacing `.pxui-header*`). The layout emits
  `<hr class="wp-header-end">` so WP-hoisted `.notice` elements land below the
  sticky title bar, not inside it.
- Layout `actions` arg — trusted HTML (buttons) pinned to the right of the
  sticky title bar. The house home for a page's Save button, wired to its form
  with the HTML5 `form` attribute. New hook: `.pxui-main__actions`.
- Buttons (`.pxui-wrap .button*`) get pill corners; `.button-primary` is
  brand blue.
- New `--pxui-brand` token (`#082ae5`, Perxel blue). `--pxui-accent` /
  `--pxui-accent-dark` now derive from it instead of aliasing the wp-admin
  colour scheme; `.pxui-brand` text uses it.
- iOS/macOS-style surface treatment, shared by the sidebar, panels, notices,
  stat tiles, cards and the danger zone: soft radius, near-white hairline
  border, warm off-white fill (`#fffafa`), diffuse shadow instead of a hard
  edge. New tokens `--pxui-radius-lg`, `--pxui-radius-pill`, `--pxui-surface`,
  `--pxui-surface-border`, `--pxui-shadow`, `--pxui-shadow-lg`,
  `--pxui-brand-bg`; `--pxui-radius` base bumped `4px` → `8px`. Panel state is
  now the fill tint + icon colour (the 4px accent left-border is gone); notices
  drop WP's accent bar for the same tinted card.
- `Perxel_UI::rows()` replaces `spec_table()` — an iOS-style grouped settings
  list, `<div>`s with flex, no `<table>`. Pass a flat row list or a list of
  groups (`[ 'title' => …, 'rows' => [ … ] ]`). Each group is an optional
  uppercase title above a rounded card of rows; the card is the only element
  that carries a shadow. Each row is a flex line — `label` left, `content`
  right (text, a `toggle()`, a `<select>`, a button); `sub`, `tone`
  (`good|warn|bad`) supported. New hooks: `.pxui-rows`, `.pxui-rows__group`,
  `.pxui-rows__title`, `.pxui-rows__card`, `.pxui-row`, `.pxui-row__label`,
  `.pxui-row__sub`, `.pxui-row__content`, `.pxui-row--{good,warn,bad}`.
  `.pxui-spec*` removed — callers pass `content` where they passed `value`.
- Form controls, scoped to `.pxui-wrap`: `<input type="checkbox">` now renders
  as an iOS toggle switch (pill track + sliding knob, brand accent when on),
  `<input type="radio">` as a filled brand-accent dot. `Perxel_UI::toggle()`
  is a convenience wrapper (handles `name`, `checked`, `value`, `id`, `form`,
  `label`) — a bare checkbox looks the same. `.pxui-switch` markup wrapper
  dropped.
- `Perxel_UI::spinner()` + `.pxui-spinner` — an inline CSS loading spinner,
  brand accent, respects `prefers-reduced-motion`.
- New stylesheet `assets/ui-forms.css` (row groups + form controls + spinner),
  enqueued by `Perxel_UI::enqueue()` right after `ui.css` (handle
  `perxel-ui-forms`, depends on `perxel-ui`). It reads the tokens `ui.css`
  declares on `.pxui-wrap`.
- `Perxel_UI::notice()` takes an `inline` arg — keeps a notice where it is
  rendered instead of letting WP hoist it to `.wp-header-end`.

## 0.1.0

- Initial kit.
- `Perxel_UI_Loader` — highest-version-wins loader with a version-floor check.
- `Perxel_UI_Layout::open()` / `::close()` — master layout: header, feature
  sidebar, main content, footer.
- `Perxel_UI` components: `notice`, `panel`, `progress_bar`, `stat_grid`,
  `card`, `spec_table`, `danger_zone`.
- `ui.css` — tokens aliased to wp-admin variables + component styles.
- `ui.js` — confirm guard for `[data-pxui-confirm]`.
- Showcase page under Tools when `WP_DEBUG`.
- First adopter: Perxel Image Optimizer.
