# WordPress.org plugin review — response tracker

Working doc for the pended `perxel-image-optimizer` submission. Started
2026-09-07, paused 2026-09-09. Come back here to finish and send.

## The review (received 2026-09-06)

Submission auto-pended by `AUTOPREREVIEW` bot. Review ID:
`AUTOPREREVIEW ❗COM-OWN perxel-image-optimizer/phucbm/6Sep26/T1 6Sep26/4.2.1 (P0TDX364229HGN)`.
Reply goes **into that email thread** (not a new email), from `phucbm` /
`phucbm.dev@gmail.com`. Keep the reply short, no change-log, no filler.
3-month deadline or the submission is rejected.

Three checklist items:

1. **Ownership / identity** 🟥 blocking
2. **Escaping of outputs** 🔴 (generic reminder, no line cited)
3. **Crowded-category / originality** ⚠️ (not strictly blocking, manual
   reviewer will judge uniqueness)

## Status

### 1. Ownership — OPEN, user handling it

Account email `phucbm.dev@gmail.com` (gmail) does not match the declared
Author "Perxel" / `https://perxel.com` / `https://github.com/perxel/wp-image-optimizer`.

Options (user said "I will solve this" — method not yet confirmed):
- DNS TXT at `perxel.com` root, value `wordpressorg-phucbm-verification` (easiest)
- Change WordPress.org profile email to an `@perxel.com` address
- Transfer submission to a WordPress.org account under `perxel.com` (reply with
  the username — do NOT resubmit)
- Rename plugin display name **and** slug to drop "Perxel" affiliation

The draft email has a bracketed placeholder for whichever route is taken. If the
*transfer* route is chosen, the whole email changes — redo it.

### 2. Escaping — DONE (committed)

Audited every output path in `includes/views/*.php` and `includes/Admin.php`'s
Media-library column/field output. **Every dynamic value was already escaped
inline** (`esc_html` / `esc_attr` / `esc_url`) — no XSS hole. The real liability
was that each view file switched the `WordPress.Security.EscapeOutput` sniff off
for its *entire length* with a blanket `// phpcs:disable` — which also
contradicts this repo's own `phpcs.xml.dist` policy ("targeted `phpcs:ignore` at
the call sites … not blanket-disabled").

Fix: replaced each file-wide `phpcs:disable` with a single documented `$render()`
closure per file — the one point where escaping is delegated to the `Perxel_UI`
kit (which escapes structure + title/label itself). Its one-line body carries the
only `phpcs:ignore`. Everywhere else in the templates the sniff is now active, so
a future unescaped `echo "$foo"` is caught.

Also in the same commit:
- `includes/Admin.php` `render_settings()` — narrowed a 3-line
  `phpcs:disable NonceVerification` block to per-line `phpcs:ignore` with reason.
- `includes/Admin.php` `assets()` — `media.js` / `admin.css` now load on the
  Media library list + the single **attachment** edit screen only, not every
  `post.php`.
- `readme.txt` — tags changed from `webp, images, performance, optimization,
  media` to `webp, convert to webp, image optimization, media library, webp
  images`.

**Commit:** `024e077` on branch **`webp/plugin-review-fixes`** (5 files, +98/−44).
`vendor/bin/phpcs --standard=phpcs.xml.dist` is green (37/37). Not pushed. No PR.

### 3. Originality — email paragraph drafted, case is thin

Competitors checked (web search, 2026-09-09):

| Plugin | Free/local? | Encode | Bulk driver | Serving | Notes |
|---|---|---|---|---|---|
| **Converter for Media** (`webp-converter-for-media`) | yes, free WebP | GD/Imagick, no exec | on-upload + one-click bulk button (AJAX/loopback); resumable ("continue later"); **no scheduled cron**; WP-CLI available | `.htaccess` into separate `/uploads-webpc/` mirror dir | AVIF is Pro. Documented "bulk optimization stuck" support threads tied to disabled cron / loopback 409 / resource limits. **Closest competitor.** |
| **Squeeze** (`squeeze`) | yes, unlimited, no API | **client-side WASM / Web Workers** (Squoosh codecs) — zero server CPU | in the browser tab, no cron/background; pause/resume; **memory-aware concurrency** (caps parallelism by device RAM to avoid OOM) | 3 modes: replace-on-disk / HTML rewrite (keeps originals) / `.htaccess` | The "runs from the browser tab, no cron, self-throttles" pitch is **NOT unique** — Squeeze does it, more radically. |
| **EWWW** (`ewww-image-optimizer`) local mode | free but needs `exec()` + binaries (`cwebp`, `optipng`…) | binaries via `exec()` | Bulk Optimizer | `.htaccess` / JS rewrite / `<picture>` rewrite | On incompatible hosts falls back to their cloud Compress API / "free exec" API; API-free mode converts JPG only. |
| **Core Modern Image Formats** (`webp-uploads`) | yes, official | GD/Imagick | **new uploads only — no bulk pass over existing library** | core `<picture>` / mime handling | |
| Robin Image Optimizer, CompressX, Image Format Converter, Only WebP Uploads | — | — | — | — | **Not checked in detail.** Robin = free/local/no-API/no-CDN/bulk. CompressX gates AVIF behind Pro. |

**Verdict:** the originality case is genuinely thin. "Free + local + no CDN +
reversible + originals untouched" is table stakes (Converter for Media has all
of it). The browser-tab-driven angle is shared with Squeeze.

What still holds up, stated **without absolutes** ("no one else does X" is
unfalsifiable and a reviewer who knows Squeeze will hold it against us):
- **vs Squeeze** — server-side encode through WP's own `WP_Image_Editor`, so
  output matches the site's thumbnail sizes and doesn't depend on the machine /
  browser / CPU of whoever clicks "start"; a 10k library isn't pushed through
  one laptop.
- **vs Converter for Media** — the bulk run still completes on a host where the
  WP-Cron loopback is dead, because it doesn't rely on one. Our throttle protects
  the *host's* CPU/pace limits during a server-side encode (a different problem
  than Squeeze's client-RAM guard).
- **Audience** — NOT "agencies" (over-narrow; the plugin's own house UX rule is
  "go here, click this, done" for the non-technical site owner). It's site owners
  on budget shared hosting who want WebP without a CDN, a subscription, or
  touching config.

**The one hard differentiator available: free AVIF.** Converter for Media and
CompressX both gate AVIF behind Pro. User intends to ship AVIF for free "in the
future." Do NOT mention it in the review reply (not in the zip = reviewer padding).
When it ships:
- needs PHP 8.1+ GD `imageavif()` OR Imagick built against ImageMagick + libheif
- that combo is *frequently missing* on the exact budget hosts we target — more
  often than WebP support is
- must detect at runtime (`Environment`), offer AVIF only where the engine can
  do it, fall back to WebP/skip otherwise, and show per-format capability on the
  Settings/Environment screen
- `Converter` already negotiates GD vs Imagick — add an output-format axis
  alongside that

### `Tested up to: 7.1` in readme.txt — UNRESOLVED

I flagged this as "no WP 7.x exists" (my knowledge cutoff is Jan 2026: WP was on
6.x since 6.0 / May 2022, latest ~6.7–6.8, no 7.0 announced). User pushed back
("said who?"). **Not verified this session.** The reviewer's email did NOT flag
it — this is my finding, not theirs.

Rule: `Tested up to:` must be an actually-released WP version and Plugin Check
errors if it's higher than current. Before resubmitting: check
`https://api.wordpress.org/core/version-check/1.7/` or
`wordpress.org/download/releases/` and set it to a real version that's been
tested. Left out of the draft email deliberately — raise only if changed.

## The draft reply email (final version as of 2026-09-09)

Reply into the existing thread. Fill the ownership bracket.

> Hello,
>
> Thanks for the review. An updated version is uploaded.
>
> **Ownership** — *[keep one:]*
> [I've added a DNS TXT record at the root of `perxel.com` with the value `wordpressorg-phucbm-verification`.]
> [I've changed my WordPress.org profile email to an address under `perxel.com`.]
>
> **Escaping** — fixed the flagged output and went through the rest of the plugin's admin views and output paths for the same pattern.
>
> **On originality** — I've looked at the closest free, local options (Converter for Media, Squeeze, EWWW's local mode, core's Modern Image Formats). This plugin sits between the server-side batch approach and the browser-WASM approach: it encodes through WordPress's own `WP_Image_Editor` (GD/Imagick — no binaries, no `exec()`, no API, no CDN), so output matches the site's existing thumbnail sizes and doesn't depend on the machine of whoever runs it. The bulk run can be driven from the open admin tab with throttling that eases off when the host pushes back, so it still completes on hosting where the WP-Cron loopback doesn't work. Conversion is non-destructive and serving is reversible (`.htaccess` on Apache/LiteSpeed, `<picture>` fallback elsewhere). It's aimed at site owners on budget shared hosting who want WebP without a CDN, a subscription, or touching configuration — one screen: scan, click, done.
>
> The slug `perxel-image-optimizer` is unchanged.
>
> Thanks,
> Phuc

## Next steps when resuming

1. User confirms the ownership method → lock the email bracket (or fully rewrite
   if transfer route).
2. Verify current WP version → fix `Tested up to:` in `readme.txt` if 7.1 is
   wrong.
3. Decide: ship free AVIF now as the hard differentiator, or send the
   positioning-only reply?
4. Optional: test Converter for Media on a `DISABLE_WP_CRON` + blocked-loopback
   host to have a first-hand answer for reviewer pushback.
5. `composer run build` → upload the new zip via "Add your plugin" as `phucbm`.
6. Push `webp/plugin-review-fixes`, merge to `main` (or however the user wants
   it), then send the reply.

## Confirmed plugin facts (from this session's code reads)

- `includes/Serve.php` **does** have a real `<img>`→`<picture>` fallback: hooks
  `wp_content_img_tag` + `post_thumbnail_html` when mode is `fallback`,
  `wrap_picture()` handles `srcset` (all candidates + descriptors), carries
  `sizes`, skips existing `<picture>`, bails to original on partial `.webp`
  coverage. Only reaches images rendered through WP filters (post content, post
  thumbnails) — not theme-hard-coded `<img>`. readme already says this.
- Nonces + `current_user_can( 'manage_options' )` on every `admin_post` and AJAX
  handler; inputs sanitized; `$wpdb->prepare()` on the queries taking external
  values. No debug leftovers, no undisclosed external services.
