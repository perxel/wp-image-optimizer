# Perxel shared admin UI (`ui/`)

A small, server-rendered admin-UI layer that every Perxel WordPress plugin
bundles verbatim. It provides one master layout (feature sidebar + main) and a
handful of components built **on top of** native wp-admin CSS — not a
replacement for it.

## How a plugin uses it

In the plugin's main file, after its own constants:

```php
require_once __DIR__ . '/ui/loader.php';
Perxel_UI_Loader::register( '0.3.0', __DIR__ . '/ui', plugins_url( 'ui', __FILE__ ) );
```

In each admin-page callback:

```php
Perxel_UI::enqueue();

Perxel_UI_Layout::open( array(
    'title'   => __( 'Status', 'my-plugin' ),
    'plugin'  => 'My Plugin',
    'version' => MY_PLUGIN_VERSION,
    'menu'    => array( '' => array(
        'my-plugin'          => __( 'Status', 'my-plugin' ),
        'my-plugin-settings' => __( 'Settings', 'my-plugin' ),
    ) ),
    'current' => 'my-plugin',
    'base'    => 'admin.php', // admin base file the sidebar links off
) );

include __DIR__ . '/views/status.php'; // plugin-owned main content

Perxel_UI_Layout::close();
```

`Perxel_UI::enqueue()` must run on `admin_enqueue_scripts` for the page.

## Versioning & the "overwrite is safe" guarantee

- `ui/` is versioned independently of the plugin (see `CHANGELOG.md`). Bump it
  when the kit changes, not when the plugin does.
- The loader keeps the **highest** registered copy when several plugins are
  active; the others are inert. Two versions never collide.
- **Within a major version the public API is additive-only.** Dropping `ui/` from
  a newer plugin into an older one, or vice versa, can never fatal and never
  changes plugin behaviour — at worst a plugin that needs a newer kit shows an
  admin notice (`Perxel_UI_Loader::require_version()`).
- A breaking change = major bump, and every plugin must adopt the new `ui/`
  before shipping it.
- `loader.php` itself must stay backwards compatible **forever** — it is the one
  file an old plugin still runs when a newer copy wins.

## Public API

`Perxel_UI_Layout`

| Method | Purpose |
| --- | --- |
| `open( array $args )` | `.wrap` → shell → sidebar (sticky brand bar: `plugin` + `version`) → `<main>` (sticky title bar: `<h1>` + `actions`). Args: `title`, `plugin`, `version`, `menu`, `current`, `base`, `links`, `author`, `actions`, `wrap_class`, `text_domain`. `actions` is trusted HTML pinned to the right of the title bar — the house home for a page's Save button; wire it to the page's `<form>` with the HTML5 `form="<form-id>"` attribute (`get_submit_button( $text, 'primary', 'submit', false, array( 'form' => 'my-form' ) )`). `author` (`[ 'name' => …, 'url' => … ]`) shows left in the footer; `links` (`[ label => url ]`) show right. |
| `close()` | Renders the footer (`author` left, `links` right) inside `<main>`, then closes what `open()` opened. |

`Perxel_UI` (each returns an HTML string — `echo` it)

| Method | Purpose |
| --- | --- |
| `enqueue()` | Registers the kit CSS/JS under the shared `perxel-ui` handle. |
| `notice( $type, $html, $args )` | `success\|warning\|error\|info`, on WP `.notice`. `$args`: `dismissible`, `inline` (stay put instead of being hoisted to `.wp-header-end`). |
| `panel( $args )` | The one headline block per screen. `status`: `success\|warning\|error\|info\|action`; `icon`, `title`, `body`, `actions`, `progress`. |
| `progress_bar( $pct, $args )` | Standalone bar. |
| `stat_grid( $tiles )` | Tile: `label`, `value`, `sub`, `bar` (0-100\|null), `tone` (`good\|warn\|bad`). |
| `card( $args )` | `title`, `body`, `actions`, `id`, `class`. |
| `rows( $groups )` | iOS-style grouped settings list. Flat row list, or groups `[ 'title' => …, 'rows' => [ … ] ]` — optional title above a rounded card of rows (the card is the only shadowed element). Row: `label` left, `content` right (text, `toggle()`, a `<select>`, a button), plus `sub`, `tone` (`good\|warn\|bad`). |
| `toggle( $args )` | A checkbox the kit renders as an iOS switch. `name`, `checked`, `value`, `id`, `form`, `label`. A bare `<input type="checkbox">` in `.pxui-wrap` looks identical. |
| `spinner()` | Inline CSS loading spinner (`.pxui-spinner`). |
| `danger_zone( $html, $args )` | Red-bordered wrapper for destructive actions. |

### Escaping contract

The helpers escape their own structural markup and the `title` / `label` fields.
`body`, `actions`, `value`, `content`, `sub` are treated as **trusted HTML** —
the caller escapes their dynamic parts. At the call site:

```php
echo Perxel_UI::panel( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes internally.
```

## What belongs in `ui/`

- **In `ui/`:** anything another Perxel plugin could plausibly reuse — layout,
  notices, panels, stat tiles, cards, row groups, the danger zone, tokens.
- **In the plugin:** anything specific to that plugin's domain (its own widgets,
  domain-specific tables). Plugin CSS/JS may be inline or in the plugin's own
  `assets/`.
- **Grey area:** start plugin-local; promote to `ui/` when a second plugin needs
  it, and bump the kit version.

## Showcase

**Tools → Perxel UI** renders every component in the real layout. Always
registered in the admin (visible to `manage_options`) — the review surface after
any `ui/` change.

## Constraints

- Server-rendered PHP + a few lines of vanilla JS. No build step, no framework.
- Two stylesheets, both enqueued by `Perxel_UI::enqueue()`:
  `assets/ui.css` (tokens, layout, surface treatment, display components) and
  `assets/ui-forms.css` (row groups, form controls, spinner — it reads the
  tokens `ui.css` declares, so it loads second and depends on `perxel-ui`).
  Each stays around ~500 lines. Past that, you're fighting wp-admin instead of
  using it.
- Kit files are plain (no namespace) and loaded by `loader.php`, never by a
  plugin's autoloader. Prefixes: `Perxel_UI`, `perxel_ui`, `PERXEL_UI`, `pxui`.
