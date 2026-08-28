# Perxel shared admin UI — changelog

Versioned independently of any plugin. Within a major version, changes are
additive only (see `README.md` → "Versioning").

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
