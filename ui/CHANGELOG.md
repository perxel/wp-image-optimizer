# Perxel shared admin UI — changelog

Versioned independently of any plugin. Within a major version, changes are
additive only (see `README.md` → "Versioning").

## 0.2.0

- Showcase page (**Tools → Perxel UI**) is now always registered in the admin
  for `manage_options` users, no longer gated behind `WP_DEBUG`.
- Layout: dropped the full-width page header. Brand + version now live in a
  `position: sticky` bar at the top of the sidebar; the page title (and any
  `links`) sit in a matching sticky bar at the top of `<main>`. The footer is
  rendered inside `<main>`. `Perxel_UI_Layout::open()/close()` signature is
  unchanged. New markup hooks: `.pxui-sidebar__bar`, `.pxui-sidebar__nav`,
  `.pxui-main__bar`, `.pxui-main__links` (replacing `.pxui-header*`). The layout
  emits `<hr class="wp-header-end">` so WP-hoisted `.notice` elements land below
  the sticky title bar, not inside it.
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
