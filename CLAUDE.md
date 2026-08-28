# CLAUDE.md

Guidance for working on this repository.

## What this is

`perxel-image-optimizer` — a WordPress plugin that converts the media library to
WebP (PHP `WP_Image_Editor`) and serves it via a managed `.htaccess` block, with
an `<img>`→`<picture>` fallback. Everything runs from an admin page: a bulk
conversion loop, per-attachment buttons, automatic conversion of new uploads. No
cron, no WP-CLI, no external service — built for shared hosting.

Slug / text domain `perxel-image-optimizer`, namespace `Perxel\ImageOptimizer\`,
prefix `perxel_image_optimizer_` / `PERXEL_IMAGE_OPTIMIZER_`.

## Layout

```
perxel-image-optimizer.php   Main file: constants, autoloader, ui/ loader, boot
uninstall.php                Removes options + post meta + .htaccess block on delete
includes/*.php               One PSR-4-ish class per concern (Ucfirst.php, namespaced)
includes/views/*.php         Admin screen templates — dumb, fed vars by Admin.php
assets/                      admin.js (admin pages), media.js (Media library), admin.css
ui/                          Shared Perxel admin-UI kit — see below
.github/workflows/           lint.yml (PHPCS + Plugin Check), release.yml
```

`includes/` classes are loaded by the hand-rolled `spl_autoload_register` in the
main file (not Composer). `Plugin::boot()` on `plugins_loaded` wires everything.
`Ajax::snapshot()` is the single source of state for the admin screens.

## The `ui/` folder — read `ui/README.md` before touching it

`ui/` is a **standalone, separately-versioned admin-UI kit**, copied verbatim
into each Perxel plugin. It is NOT specific to this plugin.

**Rules:**
- **Overwriting `ui/` must never fatal and never change plugin behaviour.** The
  loader keeps the highest registered version across active plugins; extra copies
  are inert (`class_exists` guard).
- **`ui/loader.php` must stay backwards compatible forever** — it is the fixed
  entry point (`require __DIR__ . '/ui/loader.php'`) that an old plugin still runs
  when a newer copy wins.
- **Public API is additive-only within a major version.** A breaking change =
  major bump + every plugin must adopt the new copy. Bump `ui/CHANGELOG.md` and
  `PERXEL_UI_VERSION` (in `loader.php`) when the kit changes — separately from the
  plugin version.
- **Server-rendered PHP + minimal vanilla JS. No build step.** `ui/assets/ui.css`
  stays under ~600 lines: neutral tokens aliased to wp-admin CSS vars, the accent
  fixed to the Perxel brand blue (`--pxui-brand` `#082ae5`), components layered
  on native `.wrap` / `.button` / `.notice` / `.form-table`.
- Prefixes inside `ui/`: `Perxel_UI` / `perxel_ui` / `PERXEL_UI` / `pxui-`.
  Kit files are plain (no namespace), loaded only by `loader.php`.
- **What belongs in `ui/`:** anything another Perxel plugin could reuse (layout,
  panel, stat grid, notices, cards, spec table, danger zone). Plugin-specific
  styling/markup stays in `assets/` or inline. Grey area → start plugin-local,
  promote to `ui/` when a second plugin needs it.
- Public API: `Perxel_UI_Layout::open()/close()`, `Perxel_UI::notice/panel/
  progress_bar/stat_grid/card/spec_table/danger_zone`. Details in `ui/README.md`.
- **Tools → Perxel UI** is a component showcase (always registered for
  `manage_options`) — the review surface after any `ui/` change.

## Admin screens

Two `?page=` screens under **Media → WebP**, both inside `Perxel_UI_Layout` with a
shared sidebar (Settings is registered then `remove_submenu_page`d so only "WebP"
shows in WP's menu):

- **Status** (`views/status.php`) — metrics at a glance + one action. Headline
  state chosen by `Admin::status_state()`. Server-rendered; `assets/admin.js`
  only drives the conversion run loop (swaps `#pxio-headline` in place, then
  `location.reload()`), Recalculate and Retry-failed.
- **Settings** (`views/settings.php`) — environment, conversion settings (plain
  form POST → `admin_post_perxel_image_optimizer_save_settings` → `Settings::update()`),
  serving toggle + self-test, savings estimate, danger zone. The "Save settings"
  button sits in the sticky title bar (`layout_args`' `actions`), wired to
  `#pxio-settings-form` via the HTML5 `form` attribute.

House UX rule: dead-simple, 1–2 steps to run the function; keep configuration on
its own page so a client can be told "go here, click this, done". One primary
action per screen. Never add a step or a second primary button without reason.

## Before committing

```bash
php -l <changed files>
composer install          # first time — creates vendor/ (gitignored, dev-only)
composer run lint         # PHPCS
composer run build        # bin/build-zip.sh — installable zip in dist/
```

Keep the diff focused: `composer run lint:fix` / `phpcbf` will happily reformat
unrelated files — revert anything you didn't mean to touch.

**Known:** a clean checkout currently does NOT pass `composer run lint` —
`composer.json` pins wpcs `^3.1` but 3.4 tightened many sniffs, so CI lint is red
independent of any change. Match the conventions the existing code uses; don't
chase a bar the rest of the repo doesn't meet. New `ui/` code should stay clean.

## Releasing

Bump the version in `perxel-image-optimizer.php` (header + `PERXEL_IMAGE_OPTIMIZER_VERSION`)
and `readme.txt` (`Stable tag`), add a changelog entry, tag, create a GitHub
Release. `release.yml` builds the zip and (with SVN secrets) pushes to
WordPress.org. `dist/` is never committed.
