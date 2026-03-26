#!/usr/bin/env bash
# ============================================================
# EduPak — Image Optimization Script
# ============================================================
# Converts JPG/PNG images to WebP, resizes based on subdirectory
# conventions, and compresses JPG fallbacks.
#
# Subdirectory-based max dimensions:
#   htdocs/img/tiles/    → 400×400
#   htdocs/img/icons/    → 64×64
#   htdocs/img/avatars/  → 128×128
#   All others           → 1920×1080 (landscape max)
#
# Tools required (checked at startup):
#   - cwebp  (preferred) OR convert (ImageMagick)
#   - convert (ImageMagick) for JPG compression and resizing
#
# Usage:
#   bash scripts/optimize-images.sh [--img-dir PATH] [--dry-run]
#
# Options:
#   --img-dir PATH   Override the image search directory (default: htdocs/img)
#   --dry-run        Print what would be done without making changes
#   --force          Re-optimize all files (ignore mtime checks)
#
# Exit codes:
#   0  Success
#   1  Required tools not found
# ============================================================

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# Defaults
# ─────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
IMG_DIR="${PROJECT_ROOT}/htdocs/img"
DRY_RUN=false
FORCE=false
JPG_QUALITY=80

# Colour codes
RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RESET='\033[0m'

# ─────────────────────────────────────────────────────────────
# Argument parsing
# ─────────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --img-dir)
            IMG_DIR="$2"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --force)
            FORCE=true
            shift
            ;;
        --quality)
            JPG_QUALITY="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '/^# Usage/,/^#$/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${RESET}"
            exit 1
            ;;
    esac
done

# ─────────────────────────────────────────────────────────────
# Tool detection
# ─────────────────────────────────────────────────────────────
HAS_CWEBP=false
HAS_CONVERT=false

if command -v cwebp &>/dev/null; then
    HAS_CWEBP=true
fi

if command -v convert &>/dev/null; then
    HAS_CONVERT=true
fi

if ! $HAS_CWEBP && ! $HAS_CONVERT; then
    echo -e "${RED}ERROR: Neither 'cwebp' nor 'convert' (ImageMagick) is installed.${RESET}"
    echo "Install one of:"
    echo "  sudo apt install webp            # for cwebp"
    echo "  sudo apt install imagemagick     # for convert"
    exit 1
fi

if ! $HAS_CONVERT; then
    echo -e "${YELLOW}WARNING: ImageMagick 'convert' not found — JPG resizing/compression will be skipped.${RESET}"
fi

# ─────────────────────────────────────────────────────────────
# Reporting
# ─────────────────────────────────────────────────────────────
TOTAL_BEFORE=0
TOTAL_AFTER=0
PROCESSED=0
SKIPPED=0
ERRORS=0

log_info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
log_ok()      { echo -e "${GREEN}[OK]${RESET}    $*"; }
log_skip()    { echo -e "        $*"; }
log_warn()    { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
log_error()   { echo -e "${RED}[ERROR]${RESET} $*" >&2; }

# ─────────────────────────────────────────────────────────────
# Dimension lookup by subdirectory
# ─────────────────────────────────────────────────────────────
get_max_dimensions() {
    local filepath="$1"
    local subdir
    subdir="$(basename "$(dirname "$filepath")")"

    case "$subdir" in
        tiles)   echo "400x400"  ;;
        icons)   echo "64x64"    ;;
        avatars) echo "128x128"  ;;
        *)       echo "1920x1080" ;;
    esac
}

# ─────────────────────────────────────────────────────────────
# WebP conversion
# ─────────────────────────────────────────────────────────────
convert_to_webp() {
    local src="$1"
    local dest="${src%.*}.webp"

    if $HAS_CWEBP; then
        if $DRY_RUN; then
            log_info "[DRY-RUN] cwebp -q 85 '$src' -o '$dest'"
        else
            cwebp -q 85 -quiet "$src" -o "$dest" 2>/dev/null
        fi
    elif $HAS_CONVERT; then
        if $DRY_RUN; then
            log_info "[DRY-RUN] convert '$src' -quality 85 '$dest'"
        else
            convert "$src" -quality 85 "$dest" 2>/dev/null
        fi
    fi
}

# ─────────────────────────────────────────────────────────────
# JPG resize + compress
# ─────────────────────────────────────────────────────────────
optimize_jpg() {
    local src="$1"
    local dimensions="$2"

    if ! $HAS_CONVERT; then
        return
    fi

    if $DRY_RUN; then
        log_info "[DRY-RUN] convert '$src' -resize '${dimensions}>' -quality $JPG_QUALITY '$src'"
    else
        convert "$src" \
            -resize "${dimensions}>" \
            -quality "$JPG_QUALITY" \
            -strip \
            "$src" 2>/dev/null
    fi
}

# ─────────────────────────────────────────────────────────────
# PNG resize
# ─────────────────────────────────────────────────────────────
optimize_png() {
    local src="$1"
    local dimensions="$2"

    if ! $HAS_CONVERT; then
        return
    fi

    if $DRY_RUN; then
        log_info "[DRY-RUN] convert '$src' -resize '${dimensions}>' '$src'"
    else
        convert "$src" \
            -resize "${dimensions}>" \
            -strip \
            "$src" 2>/dev/null
    fi
}

# ─────────────────────────────────────────────────────────────
# Should we skip this file?
# ─────────────────────────────────────────────────────────────
should_skip() {
    local src="$1"
    local webp="${src%.*}.webp"

    $FORCE && return 1   # --force: never skip

    # Skip if WebP already exists and is newer than source
    if [[ -f "$webp" && "$webp" -nt "$src" ]]; then
        return 0  # skip
    fi

    return 1  # do not skip
}

# ─────────────────────────────────────────────────────────────
# Process a single image file
# ─────────────────────────────────────────────────────────────
process_image() {
    local file="$1"
    local ext="${file##*.}"
    local ext_lower
    ext_lower="$(echo "$ext" | tr '[:upper:]' '[:lower:]')"

    local size_before
    size_before=$(stat -c%s "$file" 2>/dev/null || stat -f%z "$file" 2>/dev/null || echo 0)
    TOTAL_BEFORE=$((TOTAL_BEFORE + size_before))

    if should_skip "$file"; then
        log_skip "SKIP  $(basename "$file") (already optimized)"
        SKIPPED=$((SKIPPED + 1))
        TOTAL_AFTER=$((TOTAL_AFTER + size_before))
        return
    fi

    local dimensions
    dimensions="$(get_max_dimensions "$file")"

    log_info "Processing: ${file#"$PROJECT_ROOT/"} [max: $dimensions]"

    case "$ext_lower" in
        jpg|jpeg)
            optimize_jpg "$file" "$dimensions"
            convert_to_webp "$file"
            ;;
        png)
            optimize_png "$file" "$dimensions"
            convert_to_webp "$file"
            ;;
    esac

    local size_after
    size_after=$(stat -c%s "$file" 2>/dev/null || stat -f%z "$file" 2>/dev/null || echo 0)
    local webp="${file%.*}.webp"
    local webp_size=0
    if [[ -f "$webp" ]]; then
        webp_size=$(stat -c%s "$webp" 2>/dev/null || stat -f%z "$webp" 2>/dev/null || echo 0)
    fi

    TOTAL_AFTER=$((TOTAL_AFTER + size_after + webp_size))
    PROCESSED=$((PROCESSED + 1))

    local saved=$((size_before - size_after))
    log_ok "$(basename "$file") — original: ${size_before}B → compressed: ${size_after}B (saved: ${saved}B) + WebP: ${webp_size}B"
}

# ─────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────
main() {
    echo ""
    echo -e "${CYAN}============================================================${RESET}"
    echo -e "${CYAN} EduPak Image Optimizer${RESET}"
    echo -e "${CYAN}============================================================${RESET}"
    echo ""

    if $DRY_RUN; then
        echo -e "${YELLOW}DRY-RUN MODE — no files will be modified.${RESET}"
        echo ""
    fi

    if [[ ! -d "$IMG_DIR" ]]; then
        log_warn "Image directory not found: $IMG_DIR — nothing to do."
        exit 0
    fi

    log_info "Scanning: $IMG_DIR"
    echo ""

    # Find all JPG and PNG files
    while IFS= read -r -d '' file; do
        process_image "$file"
    done < <(find "$IMG_DIR" \( -iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.png" \) -type f -print0 | sort -z)

    # ── Summary ──────────────────────────────────────────────
    echo ""
    echo -e "${CYAN}────────────────────────────────────────────────────────────${RESET}"
    echo -e "${CYAN} Summary${RESET}"
    echo -e "${CYAN}────────────────────────────────────────────────────────────${RESET}"
    echo "  Files processed : $PROCESSED"
    echo "  Files skipped   : $SKIPPED"
    echo "  Errors          : $ERRORS"

    if [[ $TOTAL_BEFORE -gt 0 ]]; then
        local savings=$(( (TOTAL_BEFORE - TOTAL_AFTER) * 100 / TOTAL_BEFORE ))
        echo "  Size before     : $(numfmt --to=iec-i --suffix=B $TOTAL_BEFORE 2>/dev/null || echo "${TOTAL_BEFORE} bytes")"
        echo "  Size after      : $(numfmt --to=iec-i --suffix=B $TOTAL_AFTER 2>/dev/null || echo "${TOTAL_AFTER} bytes")"
        echo "  Space saved     : ${savings}%"
    fi
    echo ""

    if [[ $ERRORS -gt 0 ]]; then
        exit 1
    fi
}

main "$@"
