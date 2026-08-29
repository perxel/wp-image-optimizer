# Perxel Image Optimizer

Convert the WordPress media library to WebP and serve it — entirely from an
admin page, on shared hosting, with no SSH, no WP-CLI, and no external service.

- **Conversion** — PHP (`WP_Image_Editor` → GD/Imagick). `foo.jpg` → `foo.jpg.webp`
  sibling. Originals are never modified.
- **Serving** — a managed `.htaccess` block: Apache serves the `.webp` when the
  browser sends `Accept: image/webp`. Falls back to `<img>` → `<picture>`
  rewriting on nginx or when `.htaccess` isn't writable.
- **Bulk run** — tab-driven AJAX loop with adaptive batch sizing, durable
  progress, and Resume. No cron, no background work: close the tab and it stops.
- **Per-attachment** — Convert / Reconvert / Remove buttons in the Media list
  table and the attachment detail panel.
- **New uploads** — converted automatically.

## Install

1. Copy this folder to `wp-content/plugins/perxel-image-optimizer/` (or install
   the release zip).
2. Activate **Perxel Image Optimizer** from the Plugins screen.
3. Open **Media → WebP**.

Slug `perxel-image-optimizer`, text domain `perxel-image-optimizer`, namespace
`Perxel\ImageOptimizer\`.

## Admin

**Media → WebP** — two screens inside a shared sidebar layout:

- **Status** — the numbers at a glance (library, coverage, bandwidth saved) and a
  single action: *Convert N images*, or a success state when everything is done.
- **Settings** — environment probe, conversion settings, serving toggle +
  self-test, the sample-based saving estimate, and the danger zone (remove all
  `.webp`, remove the `.htaccess` block).

## Shared admin UI (`ui/`)

`ui/` is a standalone, separately-versioned admin-UI kit (layout + a few
server-rendered components on top of native wp-admin CSS). It is meant to be
copied verbatim into other Perxel plugins; overwriting it cannot break plugin
behaviour or fatal. See [`ui/README.md`](ui/README.md). With `WP_DEBUG` on,
**Tools → Perxel UI** is a component showcase.

## Requirements

- PHP 7.4+, WordPress 6.0+
- GD or Imagick with WebP support (`wp_image_editor_supports(['mime_type' => 'image/webp'])`).
  The admin page hard-stops with a notice if neither is available.
- Apache/LiteSpeed with a writable `.htaccess` for zero-markup serving;
  otherwise the `<picture>` fallback is used.

## Data it stores

| Key | Where | What |
|---|---|---|
| `perxel_image_optimizer_settings` | option | quality, sizes, toggles |
| `perxel_image_optimizer_metrics` | option | library totals |
| `perxel_image_optimizer_state` | option | resumable conversion queue |
| `perxel_image_optimizer_serve_mode` | option | `apache` / `fallback` / `off` |
| `perxel_image_optimizer_purge` | option | in-progress purge queue |
| `_perxel_image_optimizer` | post meta | per-attachment status + per-size sizes |
| `perxel_image_optimizer_sample` | transient | cached sample estimate |

"Remove all WebP" deletes every `.webp` under `uploads/` and clears all of the
above. **Uninstalling** the plugin (Plugins → Delete) removes the options, post
meta, and the `.htaccess` block, but **not** the `.webp` files — run "Remove all
WebP" first if you want those gone too.

## Notes

- The `.webp` siblings live in `uploads/`. If `uploads/` is tracked in git,
  expect a lot of new untracked files after a run.
- No AVIF (shared-hosting encode support is unreliable). No video.
