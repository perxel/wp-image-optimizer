#!/usr/bin/env bash
# Refresh vendor/perxel-ui/ in a consuming plugin from a tagged release of
# perxel/wp-plugin-ui. Copy this file into the plugin's own bin/ directory.
set -euo pipefail

VERSION="${1:?usage: bin/update-ui.sh <version>   e.g. 0.16.0}"
DEST="$(cd "$(dirname "$0")/.." && pwd)/vendor/perxel-ui"
URL="https://github.com/perxel/wp-plugin-ui/archive/refs/tags/v${VERSION}.tar.gz"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL "$URL" | tar -xz -C "$tmp" --strip-components=1

rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$tmp"/. "$DEST"/

rm -rf "$DEST"/README.md "$DEST"/CHANGELOG.md "$DEST"/CHECKLIST-wordpress-org.md \
       "$DEST"/.gitignore "$DEST"/bin "$DEST"/AGENTS.md "$DEST"/CLAUDE.md

echo "vendor/perxel-ui/ is now at v${VERSION}"
