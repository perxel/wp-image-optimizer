#!/usr/bin/env bash
# Fail on blanket WordPress.Security suppressions. WordPress.org reviewers flag
# a file-wide `phpcs:disable WordPress.Security.*` as an escaping/nonce failure.
# Use a per-line `phpcs:ignore <code> -- <reason>` instead (see CLAUDE.md).
# Identical in every Perxel plugin - do not hard-code a slug.
set -euo pipefail
cd "$(dirname "$0")/.."

hits=$(grep -rnE 'phpcs:disable[^\n]*WordPress\.Security' --include='*.php' \
	--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=build --exclude-dir=dist . || true)

if [ -n "$hits" ]; then
	echo "Blanket WordPress.Security suppression found (use per-line phpcs:ignore with a reason):" >&2
	echo "$hits" >&2
	exit 1
fi
echo "No blanket WordPress.Security suppressions."
