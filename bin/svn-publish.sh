#!/usr/bin/env bash
#
# Stage the plugin for the WordPress.org SVN repository.
#
# Usage:
#   bin/svn-publish.sh            # stage only: sync trunk + assets, tag, show `svn status`
#   bin/svn-publish.sh --commit   # ...then commit (prompts for your .org password)
#
# Trunk = the .distignore-filtered build (bin/build-zip.sh); assets = .wordpress-org/.
# The SVN working copy lives in dist/svn (gitignored). Requires `svn` and rsync.
# Set WPORG_USER to your wordpress.org username (used for --commit).

set -euo pipefail

SLUG="perxel-image-optimizer"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

command -v svn >/dev/null || { echo "svn not found (macOS: brew install subversion)" >&2; exit 1; }

COMMIT=0
[[ "${1:-}" == "--commit" ]] && COMMIT=1

VERSION="$(grep -oE "Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+" "$SLUG.php" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
STABLE="$(grep -oE "Stable tag:[[:space:]]*[0-9.]+" readme.txt | grep -oE "[0-9.]+$")"
[[ "$VERSION" == "$STABLE" ]] || { echo "Plugin Version ($VERSION) != readme Stable tag ($STABLE)" >&2; exit 1; }

bin/build-zip.sh >/dev/null
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
unzip -q "dist/$SLUG.zip" -d "$BUILD"

WC="$ROOT/dist/svn"
if [[ -d "$WC/.svn" ]]; then
	svn update "$WC"
else
	svn checkout "https://plugins.svn.wordpress.org/$SLUG" "$WC"
fi

mkdir -p "$WC/trunk" "$WC/assets" "$WC/tags"
rsync -a --delete --exclude '.svn' "$BUILD/$SLUG/" "$WC/trunk/"
rsync -a --delete --exclude '.svn' .wordpress-org/ "$WC/assets/"

# Register adds / removes.
( cd "$WC" && svn status | awk '/^\?/ {print $2}' | xargs -I{} svn add --force {} >/dev/null 2>&1 || true )
( cd "$WC" && svn status | awk '/^!/ {print $2}' | xargs -I{} svn rm {} >/dev/null 2>&1 || true )

# Correct MIME types so images render on the plugin page.
( cd "$WC/assets" && svn propset svn:mime-type image/png *.png >/dev/null )

if [[ ! -d "$WC/tags/$VERSION" ]]; then
	svn cp "$WC/trunk" "$WC/tags/$VERSION"
else
	echo "tags/$VERSION already exists - not re-tagging (bump the version for a new release)."
fi

( cd "$WC" && svn status )

if [[ "$COMMIT" -eq 1 ]]; then
	( cd "$WC" && svn ci --username "${WPORG_USER:?set WPORG_USER}" -m "Release $VERSION" )
else
	echo
	echo "Staged only. Review the status above, then re-run with --commit."
fi
