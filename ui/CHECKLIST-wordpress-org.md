# WordPress.org submission checklist — Perxel plugins

Distilled from the first submission (`perxel-image-optimizer` 1.0.0). Do these
from day one of a new plugin; retrofitting them later is the expensive path.
Nothing here is `ui/`-specific — it ships in `ui/` only because `ui/` is the
folder every Perxel plugin already copies verbatim.

---

## 1. Plugin header (main file)

```php
/**
 * Plugin Name:       <Human Name>
 * Plugin URI:        https://github.com/perxel/<repo>
 * Description:        <=140 chars, plain text, no markup. Lead with what it does locally / for free.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Perxel
 * Author URI:        https://perxel.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       <slug>            (must equal the plugin slug)
 * Domain Path:       /languages
 */
```

- **No `Update URI:` header.** A value other than the `.org` slug tells WP core
  to skip wordpress.org for updates — it silently breaks auto-update for every
  user. Only set it for plugins hosted *off* `.org`.
- `Version` must match the constant (`define( '<PREFIX>_VERSION', '1.0.0' )`),
  `readme.txt` `Stable tag`, and the git tag. `release.yml` enforces tag==header.
- Start at `1.0.0`, not `0.x`.

## 2. `readme.txt`

Run it through <https://wordpress.org/plugins/developers/readme-validator/>
before every submission. (Agent tip: `GET .../readme-validator/?output_format=md`
with `--data-urlencode "readme@readme.txt"` returns a clean pass/fail.)

- `Contributors:` = real wordpress.org usernames only. The plugin-header
  `Author:` is **not** shown on the `.org` listing — the listing's "By …" line
  comes from `Contributors:`. To publish under `perxel`, register that account
  and submit the plugin with it (submitter = initial owner/committer).
- `Tested up to:` must be a really-released WP version. Bump it every release.
- Short description (line after the header block): ≤150 chars, one line, no
  markup.
- Sections: `== Description ==`, `== Installation ==`, `== FAQ ==`,
  `== Changelog ==`. `== Screenshots ==` / `== Upgrade Notice ==` are optional
  (validator only *notes* their absence) — add screenshots post-approval via
  SVN `/assets/`.

## 3. No artificial restrictions

`.org` guidelines forbid license gates, paywalls, time-limited trials, usage
quotas, and premium-only core features. Perxel plugins are fully free — say so
in the description ("free, no upsell").

## 4. Security baseline (every request path)

- **Nonce before input.** Verify the nonce (`check_admin_referer` /
  `check_ajax_referer` / `wp_verify_nonce`) *before the first read* of
  `$_POST` / `$_GET` / `$_REQUEST` in the same function. PHPCS / Plugin Check
  flag a superglobal read that textually precedes the check even when a helper
  would have caught it — reorder, don't rely on the helper.
- **Capability check** on every `admin_post_*` / `wp_ajax_*` handler
  (`current_user_can( 'manage_options' )`, or `edit_post` for per-object).
- **Sanitize on input** (`absint`, `sanitize_text_field( wp_unslash( … ) )`,
  `sanitize_key`), **escape on output** (`esc_html`, `esc_attr`, `esc_url`).
  Server-rendered views: escape every dynamic value inline; a
  `// phpcs:disable WordPress.Security.EscapeOutput...` at the top of a view is
  only OK with an inline note that structure comes from an escaping helper.
- One `admin-ajax` endpoint per genuine AJAX need (a poll, per-row buttons);
  everything else is a plain `admin_post` form POST → handler → redirect.

## 5. Direct database queries

Prefer `WP_Query` / `get_posts` / core APIs. When a grouped `COUNT`/`SUM`, a
`LEFT JOIN` on postmeta, or a `LIKE` sweep genuinely has no API equivalent:

- Always `$wpdb->prepare()` anything with a variable.
- Put a reasoned ignore on the call line:
  `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- <why no API, why not cached>`
- Cache the *result set* in a single option/transient (the analysis layer), not
  individual rows in object cache.

## 6. Filesystem operations

- Deleting files: `wp_delete_file( $path )` — not `@unlink()`. It swallows the
  warning internally, so no `@` and no `NoSilencedErrors` finding. It returns
  void; check success with `! file_exists( $path )`.
- `.htaccess`: only ever via core `insert_with_markers( $path, $marker, $lines )`
  (and `$lines = array()` to remove). Reverse it on deactivate **and** in
  `uninstall.php`.
- `@rename` / `@copy` / `@chmod` / `@getimagesize` / `@disk_free_space` and a
  local `file_get_contents` on a path you manage: keep them, add a one-line
  `// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged[, …AlternativeFunctions.<code>] -- <why>`.

## 7. Server-config changes are opt-in

Never write `.htaccess` (or any server config) on activation or silently on
`admin_init`. Default the setting **off**; enable it only on an explicit user
action. Make that action reachable where the user already is — a pre-checked
box in the primary flow, and/or a one-click button in the "done but not wired
up" state — not just a buried Settings toggle. Removal must be one step and
must clean up.

## 8. Bundled libraries

- Ship human-readable source (unminified JS/CSS). No build step.
- A vendored runtime lib (e.g. Action Scheduler) is fine — commit it, document
  the refresh procedure, list its dev-only siblings in `.distignore`.
- Don't bundle anything WP core already provides (jQuery, etc.).

## 9. PHPCS — keep `composer run lint` green from commit 1

`composer.json` dev deps: `wp-coding-standards/wpcs:^3`,
`phpcompatibility/phpcompatibility-wp`, `dealerdirect/phpcodesniffer-composer-installer`.

Start from this plugin's `phpcs.xml.dist`. It runs the base `WordPress` standard
and curates it:

- `testVersion` / `minimum_supported_wp_version` match the plugin headers.
- `WordPress.NamingConventions.PrefixAllGlobals` — declare every prefix
  (`<prefix>`, `<PREFIX>`, `Namespace\`, plus `perxel_ui` / `PERXEL_UI` /
  `Perxel_UI` / `pxui` for the kit).
- `WordPress.WP.I18n` — declare the text domain.
- Excluded (house style, mostly WP-Docs sniffs wpcs 3.4 folded into base):
  `WordPress.Files.FileName` (PSR-4-ish `Ucfirst.php` autoloader),
  `Squiz.Commenting.FileComment`, `Generic.Commenting.DocComment.MissingShort`,
  `Squiz.Commenting.InlineComment.InvalidEndChar`,
  `Generic.CodeAnalysis.UnusedFunctionParameter` (WP hook signatures),
  `Squiz.PHP.CommentedOutCode` (false-positive-prone).
- `NonPrefixedVariableFound` excluded for `includes/views/*`, `ui/partials/*`,
  `uninstall.php` (procedural / include-scope, not real globals).

Rule: never silence a *new, real* finding to keep lint green — fix the code or
add a reasoned inline ignore. `phpcbf` fixes alignment; review its diff, it
touches whatever the `<file>` scope covers.

## 10. Plugin Check

CI runs `wordpress/plugin-check-action@v1` (see `.github/workflows/lint.yml`).
It respects inline `phpcs:ignore`, not `phpcs.xml.dist` exclusions — so the
per-call-site ignores in §5–6 are what keep it quiet. Its ruleset does **not**
enforce the §9 doc/filename sniffs, so those exclusions are lint-only.

## 11. Build & dist

- `bin/build-zip.sh` stages committed files minus `.distignore`, folder named
  for the slug.
- `.distignore` must drop: `/.git /.github /.idea /.claude /bin /dist /tests`,
  the composer dev packages by name, `/ui/showcase` (maintainer-only), and the
  dev config files (`phpcs.xml.dist`, `composer.*`, `CLAUDE.md`, `README.md`,
  `.editorconfig`, …). Keep `/vendor/action-scheduler` if used.
- `ui/loader.php` (≥ 0.15.0) tolerates a missing `ui/showcase/`.

## 12. Submission

1. `phpcs` green, `php -l` clean, `bin/build-zip.sh` produces the zip.
2. readme validator: pass.
3. Submit the ZIP at <https://wordpress.org/plugins/developers/add/> with the
   account that should own it.
4. On approval: `svn co` the repo, copy files to `trunk`, then
   `svn cp trunk tags/<version>` so `Stable tag` resolves. Add
   icon/banner/screenshots under `assets/`.
5. Releases thereafter: bump version in 4 places (header, constant, readme
   `Stable tag`, changelog), tag, GitHub Release — `release.yml` builds the zip;
   with SVN secrets it also pushes to `.org`.
