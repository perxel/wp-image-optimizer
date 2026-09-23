#!/usr/bin/env bash
#
# Build a distributable plugin zip that matches what the WordPress.org deploy
# workflow ships: only committed files, minus everything listed in .distignore.
#
# Usage:
#   bin/build-zip.sh            # build from HEAD (version read from HEAD too)
#   bin/build-zip.sh --dirty    # build from the working tree (uncommitted changes included)
#
# Output: dist/<slug>.zip  and  dist/<slug>-<version>.zip
#
# The slug is the main plugin file's name (the root *.php with a "Plugin Name:"
# header), so this script is byte-identical in every Perxel plugin.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MAIN="$(grep -lE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' ./*.php | head -n1 || true)"
[[ -z "$MAIN" ]] && { echo "No main plugin file (with a Plugin Name: header) in $ROOT" >&2; exit 1; }
SLUG="$(basename "$MAIN" .php)"

DIRTY=0
[[ "${1:-}" == "--dirty" ]] && DIRTY=1

# Read the version from the same source that gets zipped, so the file name can
# never disagree with the code inside it (a HEAD build of an uncommitted bump
# used to come out as <new version>.zip holding the old code).
if [[ "$DIRTY" -eq 1 ]]; then
	MAIN_SRC="$(cat "$SLUG.php")"
else
	MAIN_SRC="$(git show "HEAD:$SLUG.php")"
	if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
		echo "Warning: uncommitted changes are NOT in this build (it packs HEAD). Commit first, or use --dirty." >&2
	fi
fi

VERSION="$(printf '%s\n' "$MAIN_SRC" | grep -oE "Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)"
[[ -z "$VERSION" ]] && { echo "Could not read Version from $SLUG.php" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

if [[ "$DIRTY" -eq 1 ]]; then
	echo "Staging working tree (--dirty)…"
	# Everything git would track (respects .gitignore), including uncommitted edits.
	git ls-files --cached --others --exclude-standard -z | rsync -a --files-from=- --from0 ./ "$DEST/"
else
	echo "Staging HEAD…"
	git archive --format=tar HEAD | tar -x -C "$DEST"
fi

# Apply .distignore: each non-comment line is a path relative to the plugin root.
echo "Applying .distignore…"
while IFS= read -r line; do
	line="${line%%#*}"
	line="$(echo "$line" | xargs || true)"   # trim whitespace
	[[ -z "$line" ]] && continue
	rm -rf "${DEST:?}/${line#/}"
done < .distignore

mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/$SLUG.zip" "$ROOT/dist/$SLUG-$VERSION.zip"

( cd "$STAGE" && zip -rqX "$ROOT/dist/$SLUG-$VERSION.zip" "$SLUG" -x '*.DS_Store' )
cp "$ROOT/dist/$SLUG-$VERSION.zip" "$ROOT/dist/$SLUG.zip"

echo
echo "Built:"
echo "  dist/$SLUG-$VERSION.zip"
echo "  dist/$SLUG.zip"
echo
unzip -l "$ROOT/dist/$SLUG.zip" | tail -n +2
