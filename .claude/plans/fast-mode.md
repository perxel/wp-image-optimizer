# Fast mode — tab-driven conversion

Status: **shipped** in 1.1.0. Implementation notes below the design match the
code; the "Files touched" table is what actually landed.

A second way to run the bulk conversion: the browser tab drives the encode loop
directly, so the server works continuously instead of waiting on Action
Scheduler's queue cadence. On a host with no async loopback this is the
difference between ~1–3 h (tab open) and ~24 h (background).

Background mode stays the default and the unattended path. Fast mode is an
informed opt-in for "I'll sit and watch this finish."

---

## 1. Why

The background runner does ~90 s of work per Action Scheduler chunk, then hands
control back to AS and waits to be re-invoked — via the async loopback (throttled
or blocked on much of shared hosting) or the ~60 s WP-Cron tick (itself only
fired by site traffic). Result: the CPU is idle most of the time. Measured on the
trigger case — 14,953 images, ~7,600 converted in ~24 h and not finishing.

The previous DMD `perxel-webp` mu-plugin had no this problem because `admin.js`
called a synchronous `run_batch` endpoint in a tight loop (`next()` re-fires the
moment the response lands). Same `Converter`, same engine — the only difference
was who pumped the loop. Fast mode brings that loop back, with guardrails the old
one lacked.

## 2. What it is

- One AJAX endpoint (`…_fast_step`) that does a **time-boxed batch** of
  conversions **synchronously in the request** and returns `Runner::progress()`.
- `admin.js` calls it back-to-back while the Optimization tab is open, with a
  short configurable gap between calls (duty-cycle throttle).
- **Smart auto-pause** when the host starts pushing back (CPU throttle, 5xx/508,
  slow responses, memory pressure) → waits, then resumes gently. Escalating
  backoff if it keeps happening.
- **Kill-safe**: job state is persisted after every image and `Converter` writes
  each `.webp` atomically, so closing the tab / a host kill / Stop / Cancel never
  reverts or corrupts anything — the run just pauses and resumes from the cursor.
- No Action Scheduler, no WP-Cron, no loopback on this path.

## 3. Design: reuse `Runner`, add a `driver`

The job is still one `Runner` state machine. Mode only changes **who pumps the
loop**.

### `includes/Runner.php`

- `defaults()` gains `'driver' => 'background'` (`background` | `fast`).
- `start( array $args )` reads `$args['driver']`, stores it.
- `run_chunk()` (the AS callback) early-returns unless `driver === 'background'`.
- `nudge()` / `enqueue()` become no-ops unless `driver === 'background'` — fast
  mode must never schedule an AS action.
- **`fast_step( string $token ): array`** — new. The fast pump entry point:
  - `phase !== 'running'` or `driver !== 'fast'` → return `progress()` with a
    matching `status` (`done` / `paused` / `idle`).
  - Single-flight: compare `$token` to `$state['lock']`; if another fresh token
    owns it, return `status => 'locked'` (the other tab keeps pumping; this one
    goes passive). Take/refresh the lock otherwise, stamp `last_beat`.
  - `@set_time_limit( 0 )`, `wp_raise_memory_limit( 'image' )`.
  - Loop `advance()` → `Converter::convert_attachment()` → `absorb()` (all
    **shared with `run_chunk()` unchanged**), persisting after every image, until
    `Throttle::stop_batch()` says the time budget / memory ceiling / pace-spike
    is hit.
  - Drained → `finish( $state, 'complete' )` (shared: tallies, `Scan::run()`,
    email, unschedule).
  - Not drained → save, return `progress()` + `status => 'running'` + the
    `throttle` block (next gap, batch size, any `resume_after`).
- **Driver-aware stale threshold.** `STALE_AFTER` stays 300 s for background;
  fast uses ~60 s (the browser pumps every ~15 s, so a 60 s gap means the tab is
  gone). `is_stale()` / `progress()['stalled']` switch on `driver`.
- `record_pace()` writes a driver-keyed option
  (`perxel_image_optimizer_pace_fast` vs `…_background`) so each mode's ETA
  learns its own rate. `Scan` / `Estimator` read the key for the selected driver.

Shared and untouched: `advance()`, `absorb()`, `finish()`, `pause()`,
`resume()`, `cancel()`, `progress()`, `megapixel_ceiling()`, `megapixels()`,
`mark_skipped_large()`, the `Failures` integration, `Sections::pending_ids/count`.

### `includes/Throttle.php` — new, small

Fast-mode policy only (keeps `Runner` from growing a second personality):

- `profile( string $intensity ): array` — `gentle|balanced|turbo` →
  `[ 'budget' => 8|12|18 s, 'gap' => 10|3|1 s, 'batch_cap' => 8|14|20 ]`.
- `stop_batch( float $started, float $budget, int $done, array $state ): bool` —
  time budget reached, **or** `memory_get_peak_usage(true) > 0.7 × limit`, **or**
  per-image time for the last few images > 2.5× the run's EWMA (host throttling
  us mid-batch).
- `next_batch_size( int $current, float $elapsed, int $peak, int $limit )` —
  ported from the old `Runner::adapt()`: halve on memory-hot / overrun, grow on
  fast clean batches, clamp `[1, cap]`.
- `escalate( array $state ): int` — the auto-pause backoff schedule:
  30 s → 2 m → 5 m → 10 m (cap). Returns the `resume_after` delay; after 3 hits
  at the cap, signals "give up, tell them to use background".
- `should_auto_pause( array $sample ): bool` — decides from a batch sample
  (per-image time vs EWMA, consecutive slow batches) whether to flip
  `phase => 'paused'`, `pause_reason => 'auto_throttle'`, `resume_after => …`.

### `includes/Ajax.php`

Register two actions:

- `perxel_image_optimizer_fast_step` → `fast_step()`. `guard()` (nonce +
  `manage_options`), then `Runner::fast_step( $token )`, `wp_send_json_success`.
  Accepts an `intensity` field, persisted to Settings when it changes.
- `perxel_image_optimizer_fast_pause` → `fast_pause()`. Minimal,
  `sendBeacon`-friendly (nonce + cap check, `Runner::pause()`), for a clean
  pause on tab close. Falls back to stale-heartbeat detection if the beacon is
  dropped.

The existing `progress` endpoint is unchanged and still serves passive/locked
tabs.

### `includes/Admin.php`

- `handle_start()` reads `driver` from the prepare form
  (`sanitize_key`, whitelist to `background|fast`) and passes it to
  `Runner::start()`.
- Prepare form: a **"How to run it"** radio group (same pattern as the existing
  Scope select), `background` selected by default, one-line caveat under `fast`.
  No new button — the single "Start conversion" primary submits either.
- `status_actions()` / `status_state()` unchanged — pause/resume/cancel/complete
  already cover both drivers. (Fast's tab-closed state surfaces as `paused`, not
  `stalled`, via the beacon or the shorter stale threshold.)
- Monitor view gets `data-driver` so `admin.js` knows to pump.

### `includes/Settings.php`

- `defaults()` gains `'fast_intensity' => 'balanced'`. Sanitised to the three
  values. **Not** part of `signature()` — it changes pace, not output.
- No other settings change.

### `includes/views/status.php` (prepare)

- The "How to run it" radio, above Scope.
- "This run" shows the ETA for the chosen driver (JS swaps it on radio change;
  fast ETA uses `pace_fast`, or a rough 1 s/image until the first fast run
  measures it).
- Fast caveat copy: *"Keeps this tab open and runs your server hard. Pauses
  itself if your host pushes back. ≈ Xh instead of ≈ Yh."*

### `includes/views/status-monitor.php`

- `data-driver="fast"` on `#pxio-monitor`.
- Note line swap: *"Keep this tab open — closing it pauses the run; reopen and
  Resume to continue."* (replaces the "you can close this tab" line).
- A live **intensity** control (Gentle / Balanced / Turbo `<select>`), adjustable
  mid-run — posts with the next `fast_step`.
- Auto-pause banner state: *"Paused to stay within your host's limits — resuming
  in 30 s"* + "Resume now" + a muted "happened N times" after escalation.

### `assets/admin.js`

New `bindFastRunner()`, guarded on
`#pxio-monitor[data-driver="fast"][data-phase="running"]`:

- Generate a random `token` once per tab (single-flight owner id).
- `step()` → `post('…_fast_step', { token, intensity })`:
  - `status:'running'` → update the same spans the poll updates, then
    `setTimeout(step, gap * 1000)` where `gap` comes from the response
    (`throttle.gap`, plus any `retry_after`).
  - `status:'done'` → `reload()` (server renders the completion screen).
  - `status:'paused'` + `auto` → show the banner, `setTimeout(step,
    resume_after − now)`; also honour a manual "Resume now" click.
  - `status:'paused'` (manual) / `status:'idle'` → `reload()`.
  - `status:'locked'` → stop pumping, fall back to the 3 s `progress` poll, show
    "Running in another tab".
  - HTTP 5xx / network error / abort → `fails++`,
    `setTimeout(step, min(60000, 2000 * 2 ** fails))`; after ~5 consecutive show
    "server not responding — still retrying" and keep trying at the cap.
- Each request wrapped in an `AbortController` with a ~60 s timeout; an abort is
  treated as a throttle signal (counts toward `fails`).
- `beforeunload` → `navigator.sendBeacon(ajaxUrl, FormData{action:
  '…_fast_pause', nonce})`.
- Nonce refresh: `fast_step` responses carry a fresh `nonce` every ~30 min; JS
  swaps `cfg.nonce` so multi-hour runs don't outlive the 12–24 h nonce window.
- The 3 s `progress` poll (`bindMonitor`) still runs for `background` and for
  `locked` fast tabs; a pumping fast tab doesn't need it (the `step` response is
  the progress payload).

### `assets/admin.css`

Small: the auto-pause banner, the intensity control row.

## 4. Guardrails (why this won't get the account suspended)

| Lever | Value |
| --- | --- |
| Hard per-request wall cap | ~8–18 s (vs 90 s background) — no single request looks like a runaway |
| Duty-cycle gap between batches | 10 s / 3 s / 1 s by intensity — keeps average CPU ~70–85 % |
| Adaptive batch size | `[1, cap]`, halved when peak memory > 70 % of limit |
| Pace-spike auto-pause | last-N per-image time > 2.5× EWMA → pause + escalating backoff (30 s→10 m) |
| Client backoff | exponential on 5xx / 508 / timeout, honours `Retry-After` |
| Request watchdog | `AbortController` 60 s |
| Single-flight | one pumping tab; others passive |
| Megapixel ceiling | unchanged — no OOM on huge originals |
| Give-up path | after repeated cap-level throttling: "use Background mode / run overnight" |

The old mu-plugin already ran this host flat-out with none of these and was fine,
so this is strictly gentler than what worked.

## 5. Kill / stop semantics (never reverts)

- `Converter::convert_file()` writes a temp `.webp` then atomic-renames — a
  mid-encode kill leaves no partial file.
- `Runner::save()` persists the cursor + counters after **every** image.
- **Close tab** → `beforeunload` beacon sets `phase:'paused'`; if the beacon is
  lost, the 60 s stale heartbeat flips it to `paused` on next visit. Resume
  continues from the cursor.
- **Stop** button → `handle_pause` → `paused`, files kept.
- **Cancel** → `handle_cancel` → cursor dropped, files kept, scan marked stale.
- Switching a paused background run to fast (or vice-versa) on Resume: allowed —
  `resume()` just needs to also accept a `driver` override.

## 6. Files touched

| File | Change | ~LOC |
| --- | --- | --- |
| `includes/Runner.php` | `driver` field, `fast_step()`, driver-aware nudge/stale/pace | 120 |
| `includes/Throttle.php` | new — fast-mode policy | 110 |
| `includes/Ajax.php` | 2 endpoints + register | 70 |
| `includes/Admin.php` | `handle_start` driver arg, prepare radio wiring | 40 |
| `includes/Settings.php` | `fast_intensity` default + sanitise | 10 |
| `includes/views/status.php` | "How to run it" radio, dual ETA | 30 |
| `includes/views/status-monitor.php` | driver note, intensity control, auto-pause banner | 45 |
| `assets/admin.js` | `bindFastRunner()` pump loop | 160 |
| `assets/admin.css` | banner + control | 20 |
| `CLAUDE.md` | document the two drivers | 10 |
| `readme.txt` / changelog / version | release | — |

No change to `Converter`, `Sections`, `Scan`, `Estimator`, `Failures`,
`Catchup`, `Mailer`, `Serve`, the autoloader, or `vendor/action-scheduler/`.

## 7. Test plan

- `php -l` on every changed file; `composer run lint` green.
- Dev library, fast run: watch it complete tab-open; confirm the `step` cadence
  and the gap.
- Close the tab mid-run → reopens as `paused`, Resume continues, no lost/dup
  work, no orphan temp files.
- Simulate throttle (add a `usleep` in `Converter` mid-run) → auto-pause banner,
  auto-resume, backoff escalation, give-up copy.
- Two tabs → second gets `locked`, drops to poll.
- `define('DISABLE_WP_CRON', true)` + no loopback → fast mode still runs (proves
  the AS independence).
- Nonce refresh over a long run (or force a short nonce lifetime).
- Background mode unchanged: start, pause, resume, cancel, complete, email.

## 8. Decisions (settled)

- **Intensity control: monitor-only, persisted to Settings.** No control on the
  Settings screen — the live `<select>` on the running monitor is the only UI,
  its value written to `fast_intensity` so it sticks for next time.
- **Prepare ETA: selected driver only.** "This run" stays one figure; the number
  swaps when the Background/Fast radio changes. No side-by-side comparison.
- **No hard daily wall-clock cap.** The pace-spike auto-pause + escalating
  backoff (30 s → 10 m, then give-up copy) is the only throttle-response
  mechanism. No per-day state, no "paused for today" UI.
