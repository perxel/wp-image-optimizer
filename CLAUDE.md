# CLAUDE.md

Guidance for working on this repository. This is the **only** agent/maintainer
document (see "Documentation rules" below).

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
- **`fast`** - pumped by the browser: `assets/js/admin.js` (`bindFastRunner`) calls
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

Slug / text domain `perxel-image-optimizer`, namespace `Perxel_Image_Optimizer\`,
prefix `perxel_image_optimizer_` / `PERXEL_IMAGE_OPTIMIZER_`.

## Documentation rules

Every Perxel plugin follows these; they are owned by the starter.

- **`README.md` is public-facing only**: what the plugin does, screenshots,
  install, requirements, what data it stores / external services, license. No
  architecture, folder layout, build/lint/release steps, or "how to extend" -
  none of that belongs on the public page.
- **`CLAUDE.md` is the one and only file for developers and agents**:
  architecture, conventions, compliance, releasing. There is **no `AGENTS.md`**
  (and no second "playbook" file) - do not recreate it or duplicate content
  across the two. Claude Code reads `CLAUDE.md`; other agents can be pointed at it.
- `readme.txt` is the WordPress.org listing, `CHANGELOG.md` (optional) the
  changelog. Neither carries developer guidance.
- Master/source art for `.wordpress-org/` lives in `.claude/assets-src/`.
- `.env.local` holds credentials: never commit it (it is in `.gitignore`).
- `bin/*.sh` derive the slug from the main plugin file, so they are byte-identical
  across plugins - never hard-code a slug in them. Per-plugin Plugin Check
  suppressions go in `lint.yml` -> `ignore-codes`.
- `languages/` is optional; `.org` auto-loads translations.

## Layout

```
perxel-image-optimizer.php   Main file: constants, autoloader, Action Scheduler + UI-kit loaders, boot
uninstall.php                Removes options + post meta + .htaccess block on delete
includes/*.php               One PSR-4-ish class per concern (Ucfirst.php, namespaced)
includes/views/*.php         Admin screen templates - dumb, fed vars by Admin.php
assets/css, assets/js        admin.css, admin.js (admin pages), media.js (Media library)
vendor/perxel-ui/            Shared Perxel admin-UI kit - vendored, see below
vendor/action-scheduler/     Bundled background job runner - see below
.github/workflows/           lint.yml (PHPCS + Plugin Check), release.yml
README.md                   Public-facing GitHub page only (see "Documentation rules")
bin/                        build-zip.sh, update-ui.sh - identical in every plugin
.claude/assets-src/         Master/source art for the listing assets - committed, not shipped
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
`!/vendor/action-scheduler/`. Bump
"Requires at least" if the new AS raises its WP floor. This is the one recurring
maintenance cost.

## The `vendor/perxel-ui/` kit

Standalone repo [`perxel/wp-plugin-ui`](https://github.com/perxel/wp-plugin-ui),
vendored via `bin/update-ui.sh <version>` (curl a tagged tarball into
`vendor/perxel-ui/`, Action Scheduler style - no Composer). Committed;
`.gitignore` keeps it out of the general `vendor/` ignore, `.distignore` strips
only its dev-only `showcase/`. Overwriting it can never change plugin behaviour
- the `loader.php` "highest version wins" negotiation picks the newest copy
across every active plugin, and a second copy is inert.

The version passed to `Perxel_UI_Loader::register()` in the main file **and** the
`vendor/perxel-ui/` contents must match the tag you vendored. Update both when
you run `bin/update-ui.sh`.

We host the kit's component showcase as a hidden maintainer-only screen
(`PERXEL_UI_SHOWCASE_HOSTED` + `Admin::can_see_showcase()`), so its own Tools
page is suppressed.

The kit's component showcase is registered as this plugin's hidden third screen
(`Admin::can_see_showcase()`, visible only to `phucbm` / `phucbm.dev@gmail.com`).
`vendor/perxel-ui/showcase/` is stripped from the distributed build
(`.distignore`); the loader tolerates its absence (>= 0.15.0) and
`can_see_showcase()` is `false` when the class is absent. Kit development rules
(backwards-compatible loader, additive API, prefixes) live in the kit's own repo.
`Perxel_UI::stat_grid()` is retired - the Status screen renders every metric as
a `rows()` group.

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
  `Scan::run()`). `assets/js/admin.js` does the prepare-form arithmetic (image
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

`vendor/` is partly committed: `vendor/action-scheduler/` and `vendor/perxel-ui/`
ship, everything else under `vendor/` is gitignored dev tooling, so the build zip
(committed files minus `.distignore`) never contains it.

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

## WordPress.org / Plugin Check compliance

Rules that are not obvious and cost real time when re-derived per plugin:

| Rule | Why |
|---|---|
| Namespace root = slug in `Ucfirst_Snake` (`Perxel_Image_Optimizer`) | `PrefixAllGlobals` accepts it as the prefix; a `Vendor\Package` namespace is flagged (`NonPrefixedNamespaceFound`) and Plugin Check ignores the `phpcs.xml.dist` prefix list |
| Custom-table names via `%i`, never string-concatenated | `WordPress.DB.PreparedSQL.NotPrepared` is **error-level** and blocks .org (see "Custom tables") |
| No `load_plugin_textdomain()` | .org auto-loads translations (slug == text domain); calling it on `plugins_loaded` is "too early" on WP 6.7+ |
| Prefix any variable you **assign** in a view (`$pxio_url`); vars passed in via `extract()` are fine | `NonPrefixedVariableFound` fires on template-scope assignments |
| No `phpcs:disable WordPress.Security.*` in `includes/` or the main file, and no `EscapeOutput` suppression at all: escape late - kit markup through the views' `$render()` = `echo wp_kses( $html, Perxel_UI::allowed_html() )`, other built HTML through `wp_kses()` with a narrow allowlist; no inline `on*` handlers in kit markup (kses strips them) | Reviewers flag file-wide security disables (this plugin 2026-09-06, perxel-ai-translate 2026-09-22) and per-line "escaped earlier" `phpcs:ignore` echoes (perxel-ai-translate 2026-09-23); `bin/check-suppressions.sh` (run by `composer run lint`, so CI) enforces both |
| No `'suppress_filters' => true` (Plugin Check **error**); `get_posts()` already defaults to it | error-level `WordPressVIPMinimum...SuppressFilters_suppress_filters` |
| A MySQL `GET_LOCK` result must be checked; skip the guarded work when it isn't `1` | reviewer flagged an ignored lock result as a race condition |
| `set_time_limit()` etc.: `function_exists()` guard + inline `// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- <reason>` | discouraged-function warning |
| Calling another plugin's hooks (WPML `wpml_*`, WooCommerce): scope a `phpcs.xml.dist` exclude to the wrapper file **and** add the code to `lint.yml` -> `ignore-codes` | `NonPrefixedHooknameFound`; the two tools don't share config |
| `'suppress_filters' => true` in a query: same dual-suppression, code `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters` | deliberate but flagged |

The split that bites: **Plugin Check runs its own ruleset, not `phpcs.xml.dist`.**
Any suppression for a documented false positive goes in *both* places -
`phpcs.xml.dist` (for `composer run lint`) and `lint.yml` -> `ignore-codes`.

## Releasing

Bump the version in `perxel-image-optimizer.php` (header + `PERXEL_IMAGE_OPTIMIZER_VERSION`)
and `readme.txt` (`Stable tag`), add a changelog entry, tag, create a GitHub
Release. `release.yml` builds the zip and (with SVN secrets) pushes to
WordPress.org. `dist/` is never committed.

Publishing a GitHub Release also deploys to WordPress.org: the `deploy` job in
`release.yml` runs the (SHA-pinned) 10up action, which commits trunk +
`tags/<version>` + `.wordpress-org/` (banners, icons, screenshots) to SVN. It needs
the `SVN_USERNAME` / `SVN_PASSWORD` secrets and fails unless the tag, plugin
`Version` and readme `Stable tag` all match. Test with Actions -> Release -> Run
workflow (dry run is the default).

The release / WordPress.org process (first submission, org secrets, dry run,
gotchas) is owned by the starter, https://github.com/perxel/wp-plugin-starter
(`CLAUDE.md` -> "Releasing"). `release.yml` / `lint.yml` here match the starter's;
if you improve the shared process, make the same change in the starter.
