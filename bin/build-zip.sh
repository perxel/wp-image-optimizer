#!/usr/bin/env bash
#
# Build a distributable plugin zip: only committed files, minus everything
# listed in .distignore. The folder inside the zip is the plugin slug
# (perxel-image-optimizer) regardless of the repo name.
#
# Usage:
#   bin/build-zip.sh            # build from HEAD
#   bin/build-zip.sh --dirty    # build from the working tree (uncommitted changes included)
#
# Output: dist/perxel-image-optimizer.zip  and  dist/perxel-image-optimizer-<version>.zip

set -euo pipefail

SLUG="perxel-image-optimizer"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DIRTY=0
[[ "${1:-}" == "--dirty" ]] && DIRTY=1

VERSION="$(grep -oE "Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+" "$SLUG.php" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)"
[[ -z "$VERSION" ]] && { echo "Could not read Version from $SLUG.php" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

if [[ "$DIRTY" -eq 1 ]]; then
	echo "Staging working tree (--dirty)…"
	git ls-files --cached --others --exclude-standard -z | rsync -a --files-from=- --from0 ./ "$DEST/"
else
	echo "Staging HEAD…"
	git archive --format=tar HEAD | tar -x -C "$DEST"
fi

# Apply .distignore: each non-comment line is a path relative to the plugin root.
echo "Applying .distignore…"
while IFS= read -r line; do
	line="${line%%#*}"
	line="$(echo "$line" | xargs || true)"
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
