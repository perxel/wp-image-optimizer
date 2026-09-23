#!/usr/bin/env bash
# Fail on suppressions WordPress.org reviewers reject:
#  - a file-wide `phpcs:disable WordPress.Security.*` (read as an escaping /
#    nonce failure) - use a per-line `phpcs:ignore <code> -- <reason>`;
#  - any EscapeOutput suppression, even per line (`echo $html; // phpcs:ignore
#    ... escaped earlier` is flagged as unescaped output) - escape late with
#    esc_*() / wp_kses( $html, Perxel_UI::allowed_html() ) instead.
# Identical in every Perxel plugin - do not hard-code a slug.
set -euo pipefail
cd "$(dirname "$0")/.."

hits=$(grep -rnE 'phpcs:disable.*WordPress\.Security' --include='*.php' \
	--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=build --exclude-dir=dist . || true)

if [ -n "$hits" ]; then
	echo "Blanket WordPress.Security suppression found (use per-line phpcs:ignore with a reason):" >&2
	echo "$hits" >&2
	exit 1
fi

hits=$(grep -rnE 'phpcs:(ignore|disable).*EscapeOutput' --include='*.php' \
	--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=build --exclude-dir=dist . || true)

if [ -n "$hits" ]; then
	echo "EscapeOutput suppression found (escape late with esc_*() / wp_kses() instead):" >&2
	echo "$hits" >&2
	exit 1
fi
echo "No blanket WordPress.Security or EscapeOutput suppressions."
