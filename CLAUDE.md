# CLAUDE.md

Guidance for working on this repository.

## What this is

`perxel-image-optimizer` - a WordPress plugin that converts the media library to
WebP (PHP `WP_Image_Editor`) and serves it via a managed `.htaccess` block, with
an `<img>`→`<picture>` fallback. Everything runs from an admin page: a scan-first,
month-scoped **background** bulk run (Action Scheduler), per-attachment buttons,
deferred conversion of new uploads. No WP-CLI, no external service - built for
shared hosting.

Background work runs on **Action Scheduler** (bundled, `vendor/action-scheduler/`).
That means WP-Cron plus AS's own async loopback - the plugin is no longer
"no cron". The runner walks the library newest→oldest one calendar month at a
time; a mid-chunk kill resumes from the cursor. See
`.claude/plans/webp-bulk-conversion-redesign.md` for the full design.

Slug / text domain `perxel-image-optimizer`, namespace `Perxel\ImageOptimizer\`,
prefix `perxel_image_optimizer_` / `PERXEL_IMAGE_OPTIMIZER_`.

## Layout

```
perxel-image-optimizer.php   Main file: constants, autoloader, Action Scheduler + ui/ loaders, boot
uninstall.php                Removes options + post meta + .htaccess block on delete
includes/*.php               One PSR-4-ish class per concern (Ucfirst.php, namespaced)
includes/views/*.php         Admin screen templates - dumb, fed vars by Admin.php
assets/                      admin.js (admin pages), media.js (Media library), admin.css
ui/                          Shared Perxel admin-UI kit - see below
vendor/action-scheduler/     Bundled background job runner - see below
.github/workflows/           lint.yml (PHPCS + Plugin Check), release.yml
```

`includes/` classes are loaded by the hand-rolled `spl_autoload_register` in the
main file (not Composer). `Plugin::boot()` on `plugins_loaded` wires everything.
`Ajax::snapshot()` is the single (cheap - no library walk) source of state for the
admin screens.

Key classes: `Runner` (the AS chunk runner + job state), `Sections` (month
enumeration + per-month pending-ID query), `Scan` (**the single source of every
library-wide figure** - see below), `Estimator` (scan → "this run" projection,
math mirrored in `admin.js`), `Catchup` (deferred new-upload conversion),
`Failures` (incrementally-maintained failed / too-large index), `Mailer`
(completion email). There is no `Metrics` class and no "recalculate" - `Scan`
replaced both.

Per-attachment meta written by `Converter` (and read only in SQL, never
unserialised, to find pending work / sum figures):
- `_perxel_image_optimizer_sig` - the settings signature; present & current ⇒
  "settled under these settings" (done / no-gain / deterministic skip).
- `_perxel_image_optimizer_saved` / `_perxel_image_optimizer_webp` - flat integer
  byte tallies (source − webp, and webp) for this attachment's current WebP set.

**`Scan::run()`** (the "Scan library" button, synchronous, cheap even at 10k):
grouped `COUNT()` per month for totals + pending, two indexed `SUM()` over the
flat byte keys for the **exact** library-wide "saved" / "on disk", a
~120-attachment `_wp_attachment_metadata` sample for the pre-run size estimate.
No image decode, no file reads, no library walk. Everything the "At a glance"
tiles and the prepare screen show comes from the one cached
`perxel_image_optimizer_scan` option (`Scan::stats()` / `Scan::data()`).

## `vendor/action-scheduler/` - bundled, committed, not Composer-managed

Vendored verbatim from https://github.com/woocommerce/action-scheduler (currently
3.9.3). `perxel-image-optimizer.php` does `require_once` on its
`action-scheduler.php` at load; AS self-negotiates its version when several active
plugins ship a copy, so loading unconditionally is safe. Our actions are grouped
under `perxel-image-optimizer` (Tools → Scheduled Actions filters cleanly).

**To refresh:** download the target tag's tarball, replace the folder contents,
drop any `CLAUDE.md` / `AGENTS.md` it ships, keep `.gitignore`'s
`!/vendor/action-scheduler/` and `.distignore`'s per-package dev excludes. Bump
"Requires at least" if the new AS raises its WP floor. This is the one recurring
maintenance cost.

## The `ui/` folder - read `ui/README.md` before touching it

`ui/` is a **standalone, separately-versioned admin-UI kit**, copied verbatim
into each Perxel plugin. It is NOT specific to this plugin.

**Rules:**
- **Overwriting `ui/` must never fatal and never change plugin behaviour.** The
  loader keeps the highest registered version across active plugins; extra copies
  are inert (`class_exists` guard).
- **`ui/loader.php` must stay backwards compatible forever** - it is the fixed
  entry point (`require __DIR__ . '/ui/loader.php'`) that an old plugin still runs
  when a newer copy wins.
- **Public API is additive-only within a major version.** A breaking change =
  major bump + every plugin must adopt the new copy. Bump `ui/CHANGELOG.md` and
  `PERXEL_UI_VERSION` (in `loader.php`) when the kit changes - separately from the
  plugin version.
- **Server-rendered PHP + minimal vanilla JS. No build step.** `ui/assets/ui.css`
  stays under ~600 lines: neutral tokens aliased to wp-admin CSS vars, the accent
  fixed to the Perxel brand blue (`--pxui-brand` `#082ae5`), components layered
  on native `.wrap` / `.button` / `.notice` / `.form-table`.
- Prefixes inside `ui/`: `Perxel_UI` / `perxel_ui` / `PERXEL_UI` / `pxui-`.
  Kit files are plain (no namespace), loaded only by `loader.php`.
- **What belongs in `ui/`:** anything another Perxel plugin could reuse (layout,
  row groups, notices, cards, progress bar). Plugin-specific styling/markup stays
  in `assets/` or inline. Grey area → start plugin-local, promote to `ui/` when a
  second plugin needs it.
- Public API: `Perxel_UI_Layout::open()/close()`, `Perxel_UI::notice/
  progress_bar/card/rows/toggle/checkbox_group/code/spinner`. Details in
  `ui/README.md`. **`Perxel_UI::stat_grid()` is retired** - don't use it in new
  code; the Status screen renders every metric as a `rows()` group.
- The **Perxel UI** showcase page (the review surface after any `ui/` change) is
  registered by the plugin as its third screen, visible only to `phucbm` /
  `phucbm.dev@gmail.com`. It lives in `ui/showcase/` and is **stripped from the
  distributed build** (`.distignore`): `ui/loader.php` (>= 0.15.0) tolerates the
  missing folder and `Admin::can_see_showcase()` is `false` when the class is
  absent. Do local `ui/` review from a dev checkout.

## Admin screens

Two `?page=` screens under **Media → WebP**, both inside `Perxel_UI_Layout` with a
shared sidebar (Settings is registered then `remove_submenu_page`d so only "WebP"
shows in WP's menu):

- **Status** (`views/status.php` + `views/status-monitor.php`) - state chosen by
  `Admin::status_state()`: `not_scanned` → `ready` (the prepare form: scope +
  month picker + client-side "this run" estimate) → the live `running` /
  `stalled` / `paused` / `complete` monitor. Scan / Start / Pause / Cancel /
  Resume / Retry are plain form POSTs to `admin_post_*` handlers that redirect
  back. "At a glance" numbers come straight from the last `Scan` - one button,
  one refresh, exact. `assets/admin.js` does only the prepare-form arithmetic and
  the monitor poll (`wp_ajax_perxel_image_optimizer_progress` every ~3s; a phase
  change triggers `location.reload()`).
- **Settings** (`views/settings.php`) - environment, conversion settings (plain
  form POST → `admin_post_perxel_image_optimizer_save_settings` → `Settings::update()`),
  serving toggle + self-test, **Notifications** (opt-in completion email +
  test-send), danger zone. The "Save settings" button sits in the sticky title
  bar (`layout_args`' `actions`), wired to `#pxio-settings-form` via the HTML5
  `form` attribute. `skip_megapixels` 0 = auto (`Environment::safe_megapixels()`).

**Serving is opt-in** (`serve` defaults `false`) - the plugin never writes
`.htaccess` on activation. It is enabled by an explicit user action: the
"Serve them once converted" checkbox on the prepare form (`handle_start` reads
`enable_serve`), the one-click "Serve WebP now" button in the `serve_off` state
(`handle_enable_serve`), or the Settings toggle. Each path calls
`Settings::update(['serve'=>true])` then `Serve::reconcile()`.

AJAX (`Ajax.php`) is now only the monitor poll + the per-attachment Media buttons
(`convert_one` / `remove_one`) + the purge loop. Everything else is `admin_post`.

House UX rule: dead-simple, 1–2 steps to run the function; keep configuration on
its own page so a client can be told "go here, click this, done". One primary
action per screen. Never add a step or a second primary button without reason.

## Before committing

```bash
php -l <changed files>
composer install          # first time - adds dev tooling under vendor/ (gitignored)
composer run lint         # PHPCS
composer run build        # bin/build-zip.sh - installable zip in dist/
```

`vendor/` is partly committed: `vendor/action-scheduler/` ships, everything else
under `vendor/` is gitignored dev tooling. `.distignore` lists the dev packages
by name so the build zip keeps only Action Scheduler.

Keep the diff focused: `composer run lint:fix` / `phpcbf` will happily reformat
unrelated files - revert anything you didn't mean to touch.

`composer run lint` is **green** and should stay that way. `phpcs.xml.dist`
curates the base `WordPress` standard: the file operations this plugin genuinely
needs are handled with targeted `phpcs:ignore` (with a reason) at the call site,
and a short list of WP-Docs sniffs that wpcs 3.4 folded into the base standard
are excluded where they clash with deliberate house style (PSR-4-ish `Ucfirst.php`
filenames, namespace-guard files without an `@package` block, terse
`@param`-only docblocks, unenforced inline-comment punctuation, full hook
signatures with unused params). Don't silence a *new* real finding to keep it
green - fix the code or add a reasoned inline ignore.

## Releasing

Bump the version in `perxel-image-optimizer.php` (header + `PERXEL_IMAGE_OPTIMIZER_VERSION`)
and `readme.txt` (`Stable tag`), add a changelog entry, tag, create a GitHub
Release. `release.yml` builds the zip and (with SVN secrets) pushes to
WordPress.org. `dist/` is never committed.
