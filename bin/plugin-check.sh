#!/usr/bin/env bash
#
# Run the official WordPress Plugin Check against the built plugin - the same
# check CI runs (.github/workflows/lint.yml), against the same shippable zip.
# Plugin Check does NOT read phpcs.xml.dist, so the documented false positives
# live in .plugin-check-ignore (one code per line) and must be mirrored in the
# `ignore-codes:` block of lint.yml. This script is byte-identical in every
# Perxel plugin; the slug comes from the main plugin file's name.
#
# Needs wp-cli with the plugin-check package:
#   wp package install wordpress/plugin-check-cli
#
# Usage: bin/plugin-check.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MAIN="$(grep -lE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' ./*.php | head -n1 || true)"
[[ -z "$MAIN" ]] && { echo "No main plugin file (with a Plugin Name: header) in $ROOT" >&2; exit 1; }
SLUG="$(basename "$MAIN" .php)"

bin/build-zip.sh
rm -rf build && mkdir build
unzip -q "dist/${SLUG}.zip" -d build

# Mirror lint.yml -> ignore-codes. Empty for the base template.
IGNORE=""
if [[ -f .plugin-check-ignore ]]; then
	while IFS= read -r line; do
		line="${line%%#*}"
		line="$(echo "$line" | xargs || true)"
		[[ -z "$line" ]] && continue
		IGNORE+="${IGNORE:+,}${line}"
	done < .plugin-check-ignore
fi

ARGS=( "build/${SLUG}" "--slug=${SLUG}" )
[[ -n "$IGNORE" ]] && ARGS+=( "--ignore-codes=${IGNORE}" )

wp plugin check "${ARGS[@]}"
