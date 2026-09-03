=== Perxel Image Optimizer ===
Contributors: phucbm
Tags: webp, images, performance, optimization, media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local WebP conversion for your media library. No third-party CDN or service, free, and it runs in the background.

== Description ==

Perxel Image Optimizer converts your WordPress media library to WebP **locally**,
on your own server, and serves it - entirely from an admin page, built for shared
hosting.

* **No third party.** Conversion runs in PHP via WP_Image_Editor (GD/Imagick).
  Nothing is uploaded to a CDN or an external optimization service, and there is
  no API key, account or paid tier. Your images never leave your host.
* **Free.** The whole plugin, no upsell.
* **Runs in the background.** A light scan estimates the work, then a background
  job (Action Scheduler) converts the library newest-to-oldest, one calendar
  month at a time. Pause, resume, cancel, close the tab - progress is durable.
  Optional email report when a run finishes.
* **Or run it Fast.** An opt-in mode that drives the conversion from the open
  admin tab, so the server works continuously - much quicker on shared hosting
  with no cron. It throttles itself if the host pushes back, and stopping never
  loses work.
* **Originals are untouched.** `foo.jpg` gets a `foo.jpg.webp` sibling; the
  source file is never modified.
* **Serving** through a managed `.htaccess` block on Apache/LiteSpeed, with an
  `<img>` to `<picture>` fallback on nginx or when `.htaccess` is not writable.
* Per-attachment Convert / Reconvert / Remove buttons in the Media library.
* New uploads are converted automatically, shortly after upload.

The admin area is two screens under Media > Optimization: an **Optimization** page (scan
→ prepare → live monitor) and a separate **Settings** page (environment,
conversion options, serving, notifications, cleanup).

== Installation ==

1. Upload the `perxel-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to Media > Optimization.

== Frequently Asked Questions ==

= Does it modify my original images? =

No. Every converted file is written as a new `.webp` sibling next to the original.

= What happens on nginx? =

The `.htaccess` swap is Apache-only. On nginx the plugin rewrites `<img>` tags to
`<picture>` with a WebP source instead.

== Changelog ==

= 1.1.0 =
* Fast mode - an opt-in second way to run the bulk conversion, chosen on the
  prepare screen. The browser tab drives the encode loop directly, so the
  server works continuously instead of waiting on WP-Cron; on shared hosting
  with no working loopback this is dramatically faster.
* Fast mode pauses itself when the host starts throttling (slow batches, memory
  pressure) and resumes after a cooldown that lengthens if it keeps happening.
* Live Gentle / Balanced / Turbo speed control on the running monitor.
* Closing the tab, Stop, or Cancel never reverts converted files - the run
  parks and resumes from where it left off.
* Background mode is unchanged and remains the default.

= 1.0.0 =
* First public release.
* Local WebP conversion via WP_Image_Editor - no CDN, no external service, free.
* Month-scoped background bulk run on Action Scheduler: pause, resume, cancel,
  durable progress, optional completion email.
* Per-attachment Convert / Reconvert / Remove buttons in the Media library, and
  automatic conversion of new uploads.
* Serving via a managed `.htaccess` block on Apache/LiteSpeed, with an `<img>` to
  `<picture>` fallback elsewhere.
* Two admin screens under Media > Optimization - Optimization (prepare + live monitor)
  and Settings - in a shared sidebar layout, server-rendered.
