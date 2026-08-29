=== Perxel Image Optimizer ===
Contributors: phucbm
Tags: webp, images, performance, optimization, media
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert the media library to WebP and serve it via .htaccess. No SSH, no WP-CLI, no external service.

== Description ==

Convert the WordPress media library to WebP and serve it — entirely from an admin
page, on shared hosting.

* Conversion in PHP via WP_Image_Editor (GD/Imagick). `foo.jpg` becomes a
  `foo.jpg.webp` sibling; originals are never modified.
* Serving through a managed `.htaccess` block on Apache/LiteSpeed, with an
  `<img>` to `<picture>` fallback elsewhere.
* Scan-first bulk run: a light scan estimates the work, then a background job
  (Action Scheduler) converts the library newest-to-oldest, one month at a time.
  Pause, resume, cancel, close the tab — progress is durable. Optional email
  report when a run finishes.
* Per-attachment Convert / Reconvert / Remove buttons in the Media library.
* New uploads are converted automatically, shortly after upload.

The admin area is two screens under Media > WebP: a **Status** page (scan →
prepare → live monitor) and a separate **Settings** page (environment, conversion
options, serving, notifications, cleanup).

== Installation ==

1. Upload the `perxel-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to Media > WebP.

== Frequently Asked Questions ==

= Does it modify my original images? =

No. Every converted file is written as a new `.webp` sibling next to the original.

= What happens on nginx? =

The `.htaccess` swap is Apache-only. On nginx the plugin rewrites `<img>` tags to
`<picture>` with a WebP source instead.

== Changelog ==

= 0.0.1 =
* First standalone release. Renamed from the internal "Perxel WebP" mu-plugin;
  slug, namespace and stored data keys are now `perxel_image_optimizer_*`, with a
  one-time migration from the old `perxel_webp_*` keys on activation.
* Admin redesign: the single scrolling page is now a **Status** screen (metrics +
  one action button) and a separate **Settings** screen, in a shared sidebar
  layout. Both are server-rendered; JavaScript only drives the conversion run.
* Bundled `ui/` — a standalone, separately-versioned shared admin-UI kit.
