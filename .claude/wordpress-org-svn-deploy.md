# WordPress.org SVN deploy - playbook for the next Perxel plugin

How `perxel-image-optimizer` gets from GitHub to wordpress.org, and how to set the
same thing up for a new plugin. Nothing here is plugin-specific except the slug.

## The one manual step: first submission

1. Upload the plugin zip at https://wordpress.org/plugins/developers/add/
   (`composer run build` -> `dist/<slug>.zip`). The review team checks it; expect a
   few rounds of email. No SVN repo exists until they approve.
2. On approval the SVN repo `https://plugins.svn.wordpress.org/<slug>` exists but
   holds only an initial `plugin-master` commit ("Adding ...").

**Everything after approval is automated - including the very first version.** No
manual SVN commit is needed.

## One-time setup per GitHub org / repo

- Secrets `SVN_USERNAME` (wordpress.org username) and `SVN_PASSWORD`. Set them as
  **organization** secrets on `perxel` and grant access to each plugin repo, so a new
  plugin needs no secret setup. (Use an SVN-specific password if the wordpress.org
  profile offers one. Never paste the password into chat or commit it.)

## Per-plugin setup

1. Copy `.github/workflows/release.yml` from this repo. Change only:
   - `SLUG:` on the deploy step -> the new plugin slug (**the action defaults to the
     GitHub repo name, which is usually wrong**).
   - The main-file name in the "Check tag ..." step (`perxel-image-optimizer.php`).
   - Keep the 10up action pinned to a commit SHA (the step holds the password).
     Bump deliberately: `gh api repos/10up/action-wordpress-plugin-deploy/releases/latest`
     then resolve the tag to its commit SHA.
2. `.distignore` decides what lands in SVN trunk (dev files, `.github`, `.claude`,
   `bin`, tests, composer files, `README.md`, `CLAUDE.md`, `/.wordpress-org`).
   Copy this repo's and adjust for the new plugin's dev-only files.
3. `.wordpress-org/` holds the listing assets; the action uploads it to SVN `/assets/`.
   Exact names and sizes:
   - `banner-772x250.png` and `banner-1544x500.png` (ratio 3.088:1; if the source is
     slightly off, crop height rather than stretch)
   - `icon-128x128.png` and `icon-256x256.png` (exactly square; resize non-square
     sources)
   - `screenshot-1.png`, `screenshot-2.png`, ... in the order of the readme captions
   - macOS: `sips -c <h> <w> src.png --out c.png` to crop, `sips -z <h> <w>` to resize
4. `readme.txt`: add `== Screenshots ==` with one numbered caption per screenshot,
   and keep `Stable tag` equal to the plugin `Version`.

## Releasing (every version, including the first)

1. Bump the version in the plugin header, the version constant and `readme.txt`
   `Stable tag`; add the changelog entry. Merge to `main` first.
2. **Dry run once per new plugin** (and after touching the workflow): Actions ->
   Release -> Run workflow, tag = the existing tag, `dry_run` on (default). It runs
   the version check and stages the SVN deploy without committing and needs no
   secrets. The zip job is skipped on a dry run.
3. Create the git tag on `main` (after the merge, so the tag matches what ships) and
   publish a GitHub Release. The workflow attaches the zip and deploys to SVN; it
   fails, before touching SVN, unless tag == plugin `Version` == readme `Stable tag`.
4. Verify: `https://wordpress.org/plugins/<slug>/` and
   `https://api.wordpress.org/plugins/info/1.0/<slug>.json` show the new version.

## Gotchas learned on perxel-image-optimizer

- Assets on `ps.w.org` return 404 for a while after the first commit; it is CDN lag,
  not a failed upload. Check `svn ls .../assets` if in doubt.
- A large first commit (hundreds of files, e.g. a bundled Action Scheduler) sits on
  "Committing transaction..." for several minutes. That is normal.
- The `v` in a `vX.Y.Z` tag is stripped by the action on a release event, giving SVN
  tag `X.Y.Z`. On `workflow_dispatch` it cannot infer the version, which is why the
  workflow passes `VERSION` explicitly.
- `wordpress/plugin-check-action` (in `lint.yml`) derives the expected slug from the
  checkout folder, i.e. the GitHub repo name. If that differs from the plugin slug it
  reports a text-domain mismatch on every string and fails CI. Fix (in use here):
  ```yaml
  - uses: wordpress/plugin-check-action@v1
    with:
      slug: <plugin-slug>
      exclude-files: phpcs.xml.dist      # dev-only files .distignore keeps out
      exclude-directories: bin           # of the zip; the check scans the raw repo
  ```
  Without the excludes it reports `application_detected` errors on dev files such as
  `phpcs.xml.dist` and `bin/*.sh`. Adjust the list to the new plugin's dev-only files.
  Remaining output is warnings only (e.g. unprefixed template-scope variables).
- 1.0.1 was deployed by hand (a local script, since removed) before this automation
  existed. If automation ever breaks, the fallback is plain `svn`: `svn co
  https://plugins.svn.wordpress.org/<slug>`, copy the distignore-filtered build into
  `trunk/`, `.wordpress-org/*` into `assets/`, `svn cp trunk tags/<version>`, then
  `svn ci` (set `svn:mime-type image/png` on the PNGs).
- Do not bump the version, tag or publish a release without the maintainer saying so.
