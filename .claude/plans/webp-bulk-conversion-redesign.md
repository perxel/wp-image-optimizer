# Bulk conversion redesign — scoped, background, scan-first

Status: **shipped**, with later adjustments. Notably: the standalone `_sig` meta
is now a runner-internal skip signal only — the Optimization screen no longer
shows a "N pending" count (an older build's WebP set has no `_sig`, which made it
misleading) and there is no "Scan library" button or `not_scanned` state; the
page refreshes a stale `Scan` on load instead. The prepare screen summarises the
whole-library image count + ETA + settings-in-effect; the runner fast-skips
images that already have a current `.webp`.

This replaces the current Status-page run model (`Scanner::build_queue()` +
browser-driven `admin.js` batch loop) with a scan-first, month-scoped,
background job runner built on **Action Scheduler**.

---

## 1. Why

The current design does not survive a large library (the trigger case: ~10k
images on shared hosting):

| Path | Cost at 10k | When |
| --- | --- | --- |
| `Scanner::summary()` | 10k × (`get_post_meta` + `get_attached_file` + `wp_get_attachment_metadata` + `needs_work`) | **every page load** (`Ajax::snapshot()`) and every AJAX call |
| `Scanner::build_queue()` | same full walk, 10k-int array into one option | the `start` handler — one synchronous request, times out before the first image |
| `Metrics::recalculate()` | full walk + `RecursiveIteratorIterator` over all of `uploads/` | "Recalculate" |
| `Scanner::failures()` | walks IDs until it finds N | every snapshot |

There is also no way to convert **part** of the library, no pre-run estimate,
and conversion is coupled to the browser tab staying open.

## 2. What changes (summary)

1. **Background runner = Action Scheduler** (bundled). One recurring chunk
   action, cursor-driven, walks the library **newest → oldest by month**.
   AS owns: will-it-run, retry, the activity log, the admin log UI.
2. **Chunking is ours**: per-image memory gate (hard safety) + per-chunk weight
   budget (pacing) + a max image count cap.
3. **Page visit is cheap** — reads two options, never walks the library.
   A **Scan** button (title bar) does the light scan on demand → store → reload.
4. **AJAX only for the live monitor** while a run is active. Everything else is
   server-rendered PHP + form POST + reload, matching the plugin's house style.
5. **`convert_on_upload` becomes deferred** — a new upload schedules one
   debounced catch-up job (~60s) that runs through the same chunk runner.
6. **Email report on finish** — opt-in Settings option, default off.
7. **No per-image chronological log.** Per-image state already lives in post
   meta; a small failures index makes it cheap to list.

## 3. Dependency: Action Scheduler

- **Bundle** the library (`vendor/action-scheduler/`), require its
  `action-scheduler.php` early (on `plugins_loaded`, before `Plugin::boot()`).
  It self-negotiates version when multiple plugins ship it.
- Not Composer-managed here — vendored files, committed. Add a short note in
  `CLAUDE.md` on how to refresh the vendored copy (the one recurring
  maintenance cost).
- Group all our actions under `perxel-image-optimizer` so Tools → Scheduled
  Actions filters cleanly.
- This **reverses the "no cron" line** in `CLAUDE.md` — deliberate. AS uses
  WP-Cron plus its own async loopback. Update `CLAUDE.md` to describe the
  single background mode.
- `Runner`'s hand-rolled state machine (queue, `adapt()`, heartbeat, lock) is
  **largely deleted** — AS + the job-state option replace it. `Metrics` and the
  per-attachment `_perxel_image_optimizer` meta stay.

## 4. Runner model

### 4.1 Sectioning & order

- Walk `post_date` **newest month first**, one calendar month per section.
- Cursor lives in the job-state option: `{ month: "2024-07", last_id: N }`.
- Per-month candidate query is tiny and indexed — never a 10k-row query.
- Month is also the natural progress unit ("month 4 of 39") and the natural
  scope-selection unit (§6).

### 4.2 Chunk sizing — two independent rules

**Per-image memory gate (hard safety).** From stored `width × height`
(metadata, no file read), estimate decode memory:

- GD ≈ `w·h·4 × 2.5` (hard fatal if it doesn't fit)
- Imagick ≈ `w·h·4 × 5` (can spill to disk, slow)

If it exceeds ~60% of `Environment::probe()['memory_limit']` → mark
**skipped — too large for this server**, log, never attempt. This replaces the
hand-set `skip_megapixels`; expose the computed ceiling in Settings (still
overridable).

**Per-chunk weight budget (pacing).** Each chunk-action invocation processes
images until **total megapixels** (preferred) or **total source MB**
(acceptable, cheaper) reaches a target sized for ~10–20s wall time — safely
under `max_execution_time` and any proxy/php-fpm kill. Start conservative
(~3 images) until the first real measurement of seconds-per-megapixel, then
EWMA-smooth. Image count does **not** bound the chunk (100 thumbnails ≈ fine;
100 full-size photos ≈ 150s = killed).

**Max count cap (~50/chunk).** Only to catch the pathological "thousands of
near-empty images" where fixed per-image overhead (DB reads, editor init)
piles up. Rarely binds.

### 4.3 Durability

- Persist per-image (post meta + `Metrics::apply()` + job-state counters)
  after **every** image — a mid-chunk kill resumes at the next image.
- Chunk action reschedules itself while the cursor has remaining target months
  with pending work; unschedules itself when done.
- On completion: write final tallies, send the email if enabled (§8).

### 4.4 Liveness

- Job-state `last_beat` refreshed each chunk.
- Status page derives state from `phase` + `last_beat` + `as_next_scheduled_action()`:
  - `running` + fresh beat → "Converting…"
  - `running` + stale beat → "stalled" + Resume; **the page visit itself
    re-schedules the chunk action** (nudge)
  - `paused` → "Paused" + Resume
  - `complete` → done state + report

## 5. Scan (not on-load scanning)

**Page visit** reads only: `job-state` option, `metrics` option,
`wp_count_attachments()`. No library walk, ever.

**[Scan library]** (title-bar button, plain POST → redirect → render):

- Grouped month-count queries:
  - total per month: `SELECT YEAR(post_date), MONTH(post_date), COUNT(*) …
    GROUP BY 1,2` (one indexed aggregate)
  - pending per month: `LEFT JOIN` the plugin meta, count missing /
    failed / partial / signature-mismatched
- **Schema helper**: store the settings signature as its own meta key
  `_perxel_image_optimizer_sig` so "is it done under current settings" is
  SQL-filterable. Also makes `Converter::needs_work()` cheaper everywhere.
- Metadata sample: read `filesize` (WP ≥ 6.0 stores it in
  `_wp_attachment_metadata`) for ~100 random candidates → avg source bytes
  per mime. No decode, no write.
- Ratio: from `Metrics::report()` real data when `converted_files > 0`,
  otherwise public defaults — JPEG→WebP q80 ≈ 30%, PNG→WebP lossless ≈ 22%,
  PNG lossy ≈ 55%.
- Store all of it in `perxel_image_optimizer_scan` with `scanned_at`.

**Freshness**: show "Scanned N min ago · [Scan again]". Mark stale after a run
finishes, after a settings save, or after ~24h.

**Not the same as the authoritative recalc** (walks every attachment + uploads
dir for orphan `.webp`) — that stays a rare background job, unchanged in intent.

## 6. Prepare UI

Server-rendered. One `<form id="pxio-prepare">`. Round-trips: **Scan again**,
**Start conversion**. Everything between (scope select, month checkboxes,
"This run" figures) is client JS doing arithmetic on the stored scan data held
in `data-` attributes — **no AJAX**.

### 6.1 UI kit rules

- Use `Perxel_UI::rows()` groups for **everything**, including stat display.
- **Do not use `Perxel_UI::stat_grid()`** — treat it as deprecated / to be
  removed from the kit. The glance metrics and the "This run" estimate render
  as `rows()` groups (label left, value right).
- Month list is a **list, one month per row** — never a pill grid.

### 6.2 State 1 — not scanned

- Title-bar action: `[ Scan library ]`
- `rows()` one-row group: icon + "Library not scanned yet" + sub + Scan button
- Glance metrics as a `rows()` group (from cached `Metrics` only):
  Library / Converted / WebP saved / On disk

### 6.3 State 2 — scanned

- Title-bar actions: `[ Scan again ]  [ Start conversion ]`
- `Perxel_UI::notice('info')`: "6,722 of 10,842 images aren't WebP yet.
  Scanned 2 min ago."
- **`rows()` group "What to convert"**:
  - row "Scope" → `<select>`: `Everything pending (6,722)` | `Choose months…`
  - row "Skip already-converted images" → `Perxel_UI::toggle()` (default on)
- **`rows()` groups "Choose months"** (only when scope = months) —
  **one `rows()` group per year**, one **row per month**:
  - group title = the year (e.g. `2024`)
  - first row: `Select all of 2024` → toggle (client JS toggles the year's rows)
  - each month row: `label` = "July 2024", `sub` = "312 pending",
    `content` = a checkbox (`.pxui-checkbox` square box, `name="months[]"`,
    `value="2024-07"`, `form="pxio-prepare"`)
  - months with 0 pending are **not listed**; add a `pxui-muted` line
    "Fully-converted months aren't listed."
- **`rows()` group "This run"** (the confirm panel) — replaces the old card +
  stat_grid:
  - row "Images" → `1,204` · sub "3 months"
  - row "Bandwidth saved" → `≈ −190 MB` · sub "≈28% smaller"
  - row "Disk added" → `≈ +240 MB` · sub "free disk: 11.4 GB"
  - all three values recomputed in JS on every checkbox change
  - `Perxel_UI::notice('warning')` above the group when est. disk add >
    free disk × 0.8
  - body line: "Runs in the background · emails a report on finish · close the
    tab anytime"
- `pxui-muted` footnote: "Estimate = 100-image sample × your conversion ratio.
  Live numbers replace it once the run starts."

### 6.4 Start

`[ Start conversion ]` → POST → write job-state (phase `running`, chosen month
set + filters, cursor at newest selected month, stored estimate) → enqueue the
AS chunk action → redirect → the page now renders the monitor (§7).

## 7. Monitor UI (run active)

Server-rendered on load; a **single light AJAX endpoint**
(`perxel_image_optimizer_progress`) polled every ~3s updates the numbers in
place. Closing the tab does nothing.

Headline (`rows()` one-row group): `▶ Converting… — 2024-07 · month 4 of 39`,
progress bar (`Perxel_UI::progress_bar()`), `4,120 / 10,842`, `[ Pause ] [ Cancel ]`.

Below, a `rows()` group ("This run"): Converted / Remaining / Saved so far /
Disk added — plus a line `1.8 img/s · ETA ~48 min · 12 failed · 18 too large`
and `Projected −1.1 GB (≈28%) · +1.4 GB disk` (measured, not the pre-run sample).

`⚠ 12 failed · 18 too large` → expands to a **failures list** (from the
failures index, §9) — thumbnail, filename, reason, link to attachment, Retry.

`[ View activity log → ]` → Tools → Scheduled Actions filtered to the group
(label it *background activity*, not per-image).

Footer line: "Started 41 min ago · updates live · safe to close this tab."

### 7.1 Headline variants

- **Stalled**: "Conversion stalled at 4,120 / 10,842 · no worker for 6 min" +
  `[ Resume ] [ Cancel ]` + muted "Opening this page nudged it; if it keeps
  stalling see Settings → Environment (Loopback / WP-Cron)."
- **Paused**: "Paused at 2024-07 · month 4 of 39" + `[ Resume ] [ Cancel ]`
- **Complete**: "All 10,842 images converted · −1.09 GB (27%) · 34 failed ·
  18 too large · report emailed" + `[ View log → ]`

### 7.2 Pause / Cancel / run again

Both **keep every converted file** — they stop future work, never undo it.

- **Pause** — phase → `paused`, unschedule chunk action, **cursor kept**.
  Resume re-schedules, continues from cursor, no re-estimate.
- **Cancel** — phase → `idle`, unschedule, **cursor dropped**. Starting again
  goes through Scan/prepare (count is now smaller; skip-converted excludes the
  done images, so it effectively resumes with fresh numbers).
- Second admin opening the page mid-run sees the monitor + Pause/Cancel, not a
  Start button.

## 8. Deferred `convert_on_upload`

- On new JPEG/PNG attachment (`add_attachment` /
  `wp_generate_attachment_metadata`): schedule **one** AS action
  `perxel_image_optimizer_catch_up` for `now + 60s`, **only if not already
  scheduled** (keep-first debounce, do not bump).
  - 40-image drag-drop → one job at ~T+60s.
  - Continuous upload session → picked up in ~1-min waves.
- The job runs one weight-budgeted chunk of "needs work, newest first" through
  the same runner path; reschedules itself while work remains.
- 60s also lets deferred subsize generation finish; if not,
  `needs_work()`'s "a wanted size appeared that isn't recorded" check catches
  it on a later pass.
- Same per-attachment transient lock + `needs_work()` guard → no double
  conversion if a bulk run is also active.
- **Never emails** (bulk runs only, §9).
- Settings label unchanged ("Auto-optimize new uploads") — implementation only.

## 9. Logging & failures

**No per-image chronological log.** Two levels, both cheap:

1. **AS activity log** (free) — one entry per *chunk*: timing, retries,
   chunk-level errors ("memory exhausted at 14:32"). Surfaced via the
   "View activity log" link.
2. **Per-attachment post meta** (already exists) — `_perxel_image_optimizer`:
   `status`, per-size `reason`, `error`, `ts`. "What happened to image 8842" =
   look at image 8842; the Media column already shows it.

**Failures index** — replace the `Scanner::failures()` full walk with a small
`perxel_image_optimizer_failures` option: `[ id => reason ]`, maintained
incrementally by the runner. One `get_option` to render the failures list.
Also carries the "skipped — too large" set.

## 10. Email report (Settings option)

New `rows()` group **"Notifications"** on the Settings screen, matching the
serving self-test pattern:

| Row | Control |
| --- | --- |
| Email a report when a bulk run finishes | toggle, **default off** |
| Send to | text field; placeholder = `admin_email`; required when the toggle is on; sanitized with `sanitize_email` |
| — | `[ Send test email ]` button → sends a sample report to the entered address now, inline success/failure feedback |

Behaviour:

- **Bulk runs only.** The catch-up path never emails.
- **One email per run** — only the worker that flips `phase → complete` sends;
  guarded against retries/overlap.
- Also sent on **cancelled** and **stalled-gave-up**, with partial totals.
- Contents: converted count, MB + % saved, MB added to disk, failed count +
  first ~10 reasons, skipped-too-large count, elapsed time, link to the plugin
  page.
- Plain `wp_mail()`, site default from-address, plain text / minimal HTML. No
  external service.

New setting keys: `email_report` (bool), `email_report_to` (string). Add to
`Settings::defaults()`, `Settings::update()` sanitization, and the settings
form POST handler. **Not** part of `Settings::signature()` (doesn't affect
conversion output).

## 11. Data model

### Options

| Option | Holds | Written by |
| --- | --- | --- |
| `perxel_image_optimizer_job` | `phase` (idle/running/paused/complete), chosen filters + month set, `cursor` {month,last_id}, counters (processed/failed/skipped_large/saved_bytes/webp_bytes), `total_candidates` (from scan), stored estimate, `started_at`, `last_beat`, `finished_at`, `owner` | chunk worker (per image), start/pause/cancel handlers |
| `perxel_image_optimizer_scan` | per-month totals + pending, sample avg bytes per mime, ratio, `scanned_at` | Scan handler |
| `perxel_image_optimizer_failures` | `[ id => reason ]` for failed + skipped-too-large | chunk worker, retry handler |
| `perxel_image_optimizer_metrics` | unchanged (library-wide totals) | `Metrics` |
| `perxel_image_optimizer_settings` | + `email_report`, `email_report_to` | `Settings` |

`perxel_image_optimizer_state` (old `Runner` option) — removed.

### Post meta

| Key | Purpose |
| --- | --- |
| `_perxel_image_optimizer` | unchanged — per-attachment status/sizes/error |
| `_perxel_image_optimizer_sig` | **new** — settings signature as a standalone, SQL-filterable value |

### Actions (AS, group `perxel-image-optimizer`)

| Hook | Role |
| --- | --- |
| `perxel_image_optimizer_convert_chunk` | the bulk runner chunk; reschedules while work remains |
| `perxel_image_optimizer_catch_up` | debounced post-upload conversion |

## 12. File-by-file

| File | Change |
| --- | --- |
| `vendor/action-scheduler/` | **new** — bundled library |
| `perxel-image-optimizer.php` | require `action-scheduler.php` on `plugins_loaded` before boot |
| `includes/Runner.php` | gut the state machine; becomes: cursor advance, chunk packer (memory gate + weight budget + count cap), per-image persist, reschedule/finish, pause/resume/cancel |
| `includes/Sections.php` | **new** — enumerate months newest→oldest, per-month pending-ID query |
| `includes/Scan.php` | **new** — grouped month counts, metadata sample, ratio, store `perxel_image_optimizer_scan` |
| `includes/Estimator.php` | **new** — projections from scan data (client mirrors the same math in JS) |
| `includes/Catchup.php` | **new** — debounced upload trigger + catch-up chunk |
| `includes/Mailer.php` | **new** — completion report, test email |
| `includes/Failures.php` | **new** — the failures index (replaces `Scanner::failures()` walk) |
| `includes/Scanner.php` | `summary()` reads cache; keep `all_image_ids()`; drop the per-load walk |
| `includes/Metrics.php` | keep; authoritative `recalculate()` stays background/rare; write `_perxel_image_optimizer_sig` alongside meta |
| `includes/Converter.php` | write `_perxel_image_optimizer_sig`; memory-gate hook uses `Environment` ceiling |
| `includes/Environment.php` | add computed `safe_megapixels` (memory ÷ engine factor) |
| `includes/Settings.php` | + `email_report`, `email_report_to`; sanitization; exclude from `signature()` |
| `includes/Ajax.php` | remove the old run endpoints; keep **only** `perxel_image_optimizer_progress` (monitor poll) + the existing Media per-image endpoints; move Scan/Start/Pause/Cancel/Retry/test-email to `admin_post_*` handlers |
| `includes/Admin.php` | title-bar `actions` per state (Scan / Start / Scan again + monitor controls); new `admin_post_*` handlers |
| `includes/views/status.php` | rewrite: cheap glance → scan states → prepare (§6) → monitor (§7); `rows()` groups only, **no `stat_grid`** |
| `includes/views/settings.php` | + Notifications group; replace typed `skip_megapixels` with the computed ceiling (keep as override) |
| `assets/admin.js` | remove the batch run loop; add: prepare-form client math (month checkboxes → "This run" figures), "select all year", monitor poll, Scan/Start are plain submits |
| `uninstall.php` | remove new options + post meta key; `as_unschedule_all_actions()` for the group |
| `CLAUDE.md` | update: single background mode (AS), reversed "no cron", `stat_grid` retired, how to refresh vendored AS |
| `ui/` | `stat_grid` deprecation is a **separate kit task** — not in this plan beyond "don't use it in new code" |

## 13. Phasing

1. **Foundation** — bundle AS; `_perxel_image_optimizer_sig`; `Scan` +
   `Sections`; cheap page load (cached `summary`); failures index. Status page
   still uses the old run loop at this point.
2. **Runner** — `convert_chunk` action, cursor/month walk, chunk packer,
   pause/resume/cancel, monitor view + `progress` poll. Retire the old
   `admin.js` loop and old AJAX endpoints.
3. **Prepare UI** — scope select, month list, client-side estimate, Start.
4. **Catch-up** — deferred `convert_on_upload`.
5. **Email** — Settings option + `Mailer` + completion hook.

## 14. Known risks / open items

- **Hosts that block loopback** — AS falls back to WP-Cron-on-traffic (slower);
  the monitor "stalled" state + page-visit nudge cover it. Surface
  loopback / WP-Cron health in Settings → Environment.
- **Zero-traffic staging** — the AS async chain still runs once kicked; only
  recovery-after-a-stall needs traffic. Keep an explicit "run in this tab"
  fallback? — deferred; revisit if it proves necessary.
- **Bundled AS size / updates** — the one ongoing maintenance cost; documented
  in `CLAUDE.md`.
- **`total_candidates` drift** — new uploads during a long run aren't in the
  confirm-time total; the catch-up path handles them and the monitor shows
  "remaining" from live pending, so the bar can move past 100% slightly.
  Acceptable; note it.
- **Megapixel weight vs MB weight** — start with MB (cheaper, already sampled),
  switch to megapixels if pacing is too rough.

---

## 15. Amendment — one function for library figures (implemented)

`Metrics::recalculate()` and the manual **Recalculate** button are **removed**.
`Scan` is the single function that produces every library-wide number.

- `Converter` writes two flat, SQL-summable post-meta keys on every
  convert / remove: `_perxel_image_optimizer_saved` (src − webp bytes) and
  `_perxel_image_optimizer_webp` (webp bytes).
- `Scan::run()` adds two indexed `SUM()` queries over those keys → **exact**
  library-wide "saved" and "on disk", plus `COUNT()` of the webp key for the
  converted-attachment total. No walk, no unserialising the per-size blob.
- `Scan::stats()` is the display view (was `Metrics::report()`): coverage %,
  saved bytes/%, webp bytes, converted count — all from the one cached
  `perxel_image_optimizer_scan` option.
- The `perxel_image_optimizer_metrics` option, `Metrics` class,
  `perxel_image_optimizer_recalc` action, `run_recalc`, and the recalc transient
  are gone. `Failures::rebuild()` (its only caller) replaced by a self-pruning
  read in `Failures::listing()`.
- Trade-off: WebP files left by *other* tools that were never recorded aren't
  counted — no record to sum; the purge flow still walks and reports those.
- Upgrade: existing conversions get the flat keys the next time each attachment
  is touched (the first post-upgrade run re-verifies everything cheaply because
  the default `skip_megapixels` changed, so the signature moved). Numbers
  converge to exact after that first run.
