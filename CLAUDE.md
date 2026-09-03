# CLAUDE.md

Guidance for working on this repository.

## What this is

`perxel-image-optimizer` - a WordPress plugin that converts the media library to
WebP (PHP `WP_Image_Editor`) and serves it via a managed `.htaccess` block, with
an `<img>`→`<picture>` fallback. Everything runs from an admin page: a scan-first,
month-scoped **background** bulk run (Action Scheduler), per-attachment buttons,
deferred conversion of new uploads. No WP-CLI, no external service - built for
shared hosting.

The bulk run has **two drivers**, chosen on the prepare form (`Runner`'s
`driver` field, `handle_start` reads it):

- **`background`** (default) - pumped by **Action Scheduler** (bundled,
  `vendor/action-scheduler/`): WP-Cron plus AS's own async loopback, so the
  plugin is no longer "no cron". Close the tab, it keeps going. Slow on shared
  hosting where the loopback is blocked (one ~90s chunk per cron tick).
- **`fast`** - pumped by the browser: `assets/admin.js` (`bindFastRunner`) calls
  the `…_fast_step` AJAX endpoint in a loop while the Optimization tab is open,
  each call doing a time-boxed batch synchronously. No AS, no cron. `Throttle`
  owns the pacing: intensity profiles (gentle/balanced/turbo = batch budget +
  inter-request gap), adaptive batch size, and a pace-spike / memory auto-pause
  with an escalating cooldown (30s→10m). Closing the tab parks the run as
  `paused` (a `beforeunload` beacon, or the 60s `FAST_STALE_AFTER` heartbeat).

Both share the `Runner` state machine, the month cursor walk, `process_batch()`,
`Failures`, and finish/email/scan-refresh. A mid-batch kill resumes from the
cursor and never reverts converted files. See
`.claude/plans/webp-bulk-conversion-redesign.md` and
`.claude/plans/fast-mode.md` for the full design.

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

Key classes: `Runner` (job state machine + the month cursor walk + the shared
`process_batch()` inner loop + both drivers: `run_chunk()` for AS, `fast_step()`
for the tab), `Throttle` (fast-mode pacing policy only - intensity profiles,
adaptive batch size, auto-pause decision + cooldown ladder), `Sections` (month
enumeration + per-month pending-ID query - the runner's internal skip signal),
`Scan` (**the single source of every library-wide figure** - see below),
`Estimator` (scan → "this run" projection: image count + ETA, math mirrored in
`admin.js`), `Catchup` (deferred new-upload conversion), `Failures`
(incrementally-maintained failed / too-large index), `Mailer` (completion
email). There is no `Metrics` class and no "recalculate" - `Scan` replaced both.

Per-attachment meta written by `Converter` (and read only in SQL, never
unserialised, to find pending work / sum figures):
- `_perxel_image_optimizer_sig` - the settings signature; present & current ⇒
  "settled under these settings" (done / no-gain / deterministic skip). Drives
  the runner's per-month skip query (`Sections::pending_ids`), the catch-up
  pending check, and `Scan`'s `settled` count (⇒ the `done` / `serve_off`
  state). **Not** surfaced as a user-facing "N pending" number - a fresh WebP
  set left by an older build has no `_sig`, so that count was misleading; the
  prepare screen shows the whole-library total instead and the runner fast-skips
  images that already have a current `.webp` (a `filemtime` check, no decode).
- `_perxel_image_optimizer_saved` / `_perxel_image_optimizer_webp` - flat integer
  byte tallies (source − webp, and webp) for this attachment's current WebP set.

**`Scan::run()`** (synchronous, cheap even at 10k): the Optimization page runs
it on load **only when the cache is stale** (settings saved, a run finished,
older than a day, or per-attachment Media action) - never on other admin pages,
no button. A grouped `COUNT()` per month, two indexed `SUM()` over the flat byte
keys for the **exact** library-wide "saved" / "on disk", two indexed `COUNT()`s
(`_webp` rows = "converted", current `_sig` rows = "settled"), a ~120-attachment
`_wp_attachment_metadata` sample for the pre-run size estimate. No image decode,
no file reads, no library walk, no per-row meta join. Everything the "At a
glance" tiles and the prepare screen show comes from the one cached
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

Two `?page=` screens under **Media → Optimization**, both inside `Perxel_UI_Layout` with a
shared sidebar (Settings is registered then `remove_submenu_page`d so only "Optimization"
shows in WP's menu):

- **Optimization** (`views/status.php` + `views/status-monitor.php`) - state
  chosen by `Admin::status_state()`: `ready` (the prepare screen: an intro
  sentence, a "This run" card = whole-library image count + megapixel-skip line +
  ETA, a scope + month picker, a read-only "Settings in effect" recap linking to
  Settings, then "At a glance") → the live `running` / `stalled` / `paused` /
  `complete` monitor; `serve_off` / `done` once `settled` covers the library.
  There is **no Scan button and no `not_scanned` state** - `render_status()`
  refreshes a stale `Scan` on load. The prepare form also carries a **"How to
  run it"** radio (`driver`: background / fast). Start / Pause / Cancel / Resume /
  Retry / "Back to summary" are plain form POSTs to `admin_post_*` handlers that
  redirect back (`handle_scan` is now just the completion-screen ack +
  `Scan::run()`). `assets/admin.js` does the prepare-form arithmetic (image
  count + ETA, per driver), the background monitor poll
  (`wp_ajax_perxel_image_optimizer_progress` every ~3s; a phase change triggers
  `location.reload()`), and — for a fast run — the `bindFastRunner` pump loop
  (`…_fast_step` back-to-back, `…_fast_pause` beacon on unload, a live
  intensity `<select>`, an auto-pause / throttle banner).
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

AJAX (`Ajax.php`): the monitor poll, the fast-mode pump (`fast_step` /
`fast_pause`), the per-attachment Media buttons (`convert_one` / `remove_one`),
and the purge loop. Everything else is `admin_post`.

House UX rule: dead-simple, 1–2 steps to run the function; keep configuration on
its own page so a client can be told "go here, click this, done". One primary
action per screen. Never add a step or a second primary button without reason.
(The `driver` radio is a mode choice on the existing form, not a second primary
button — one "Start conversion" still submits it. The fast-mode intensity control
is monitor-only, persisted to `fast_intensity`; it is deliberately absent from
Settings.)

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

`ui/CHECKLIST-wordpress-org.md` is the shared WordPress.org submission /
compliance checklist for every Perxel plugin (headers, readme, security,
direct-DB / filesystem ignore conventions, opt-in server config, PHPCS setup,
SVN steps). Consult it before a first submission and skim it before each
release.
