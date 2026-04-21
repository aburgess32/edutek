#!/usr/bin/env bash
#
# setup-vendor.sh — Bootstrap frontend vendor assets for EduPak
#
# Copies bundled assets from htdocs/assets/ into htdocs/vendor/ so
# Bootstrap, jQuery, Font Awesome, and jQuery Easing are available
# at the paths expected by navhome.php and navbar.php.
#
# This is run once after cloning (or after wiping vendor/).
# No internet access required — all source files are in assets/.
#
# Usage:
#   bash scripts/setup-vendor.sh
#   make vendor       (if using the Makefile target)
#

set -euo pipefail

HTDOCS="$(cd "$(dirname "$0")/../htdocs" && pwd)"

echo "[setup-vendor] Bootstrapping vendor assets from htdocs/assets/ ..."

# ── jQuery ──────────────────────────────────────────────────────────────────
mkdir -p "$HTDOCS/vendor/jquery"
cp "$HTDOCS/assets/js/jquery.min.js" "$HTDOCS/vendor/jquery/jquery.min.js"
echo "[setup-vendor]   jQuery          -> vendor/jquery/jquery.min.js"

# ── Bootstrap CSS ────────────────────────────────────────────────────────────
mkdir -p "$HTDOCS/vendor/bootstrap/css"
cp "$HTDOCS/assets/css/bootstrap.min.css" "$HTDOCS/vendor/bootstrap/css/bootstrap.min.css"
echo "[setup-vendor]   Bootstrap CSS   -> vendor/bootstrap/css/bootstrap.min.css"

# ── Bootstrap JS ─────────────────────────────────────────────────────────────
mkdir -p "$HTDOCS/vendor/bootstrap/js"
cp "$HTDOCS/assets/js/bootstrap.js" "$HTDOCS/vendor/bootstrap/js/bootstrap.bundle.min.js"
echo "[setup-vendor]   Bootstrap JS    -> vendor/bootstrap/js/bootstrap.bundle.min.js"

# ── Font Awesome ─────────────────────────────────────────────────────────────
mkdir -p "$HTDOCS/vendor/font-awesome/css"
mkdir -p "$HTDOCS/vendor/font-awesome/fonts"
cp "$HTDOCS/assets/css/font-awesome.min.css" "$HTDOCS/vendor/font-awesome/css/font-awesome.min.css"
# Copy all webfont variants
for ext in eot ttf woff woff2 svg; do
    src="$HTDOCS/assets/fonts/fontawesome-webfont.$ext"
    if [ -f "$src" ]; then
        cp "$src" "$HTDOCS/vendor/font-awesome/fonts/"
        echo "[setup-vendor]   Font Awesome    -> vendor/font-awesome/fonts/fontawesome-webfont.$ext"
    fi
done
echo "[setup-vendor]   Font Awesome CSS -> vendor/font-awesome/css/font-awesome.min.css"

# ── jQuery Easing ─────────────────────────────────────────────────────────────
mkdir -p "$HTDOCS/vendor/jquery-easing"
cp "$HTDOCS/assets/js/jquery.easing.min.js" "$HTDOCS/vendor/jquery-easing/jquery.easing.min.js"
echo "[setup-vendor]   jQuery Easing   -> vendor/jquery-easing/jquery.easing.min.js"

echo "[setup-vendor] Done. All vendor assets are in place."
