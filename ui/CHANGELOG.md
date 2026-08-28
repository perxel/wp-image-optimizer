# Perxel shared admin UI — changelog

Versioned independently of any plugin. Within a major version, changes are
additive only (see `README.md` → "Versioning").

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
