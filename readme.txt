=== Perxel Image Optimizer ===
Contributors: phucbm
Tags: webp, images, performance, optimization, media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
* First public release.
* Local WebP conversion via WP_Image_Editor - no CDN, no external service, free.
* Month-scoped bulk run with two drivers: a background run on Action Scheduler
  (pause, resume, cancel, durable progress, optional completion email) and an
  opt-in Fast mode where the browser tab drives the encode loop directly, for
  shared hosting with no working WP-Cron loopback. Fast mode self-pauses when
  the host throttles and resumes after an escalating cooldown; a live Gentle /
  Balanced / Turbo control sits on the running monitor.
* Closing the tab, Stop, or Cancel never reverts converted files - the run
  parks and resumes from where it left off.
* Per-attachment Convert / Reconvert / Remove buttons in the Media library, and
  automatic conversion of new uploads.
* Serving via a managed `.htaccess` block on Apache/LiteSpeed, with an `<img>` to
  `<picture>` fallback elsewhere.
* Two admin screens under Media > Optimization - Optimization (prepare + live monitor)
  and Settings - in a shared sidebar layout, server-rendered.
