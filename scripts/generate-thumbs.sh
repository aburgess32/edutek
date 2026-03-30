#!/bin/bash
#
# EduPak Thumbnail Generator
# Extracts a frame from the first video in each videos/ subfolder.
# Outputs WebP + JPG to htdocs/assets/tiles/
#
# Usage: ./scripts/generate-thumbs.sh [--force]
#
# Requires: ffmpeg (for video thumbnails)
# Optional: convert (ImageMagick, for service placeholders)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
VIDEOS_DIR="$PROJECT_ROOT/htdocs/videos"
OUTPUT_DIR="$PROJECT_ROOT/htdocs/assets/tiles"
SIZE="400x400"
FORCE=false

if [ "${1:-}" = "--force" ]; then
    FORCE=true
fi

# Create output directory
mkdir -p "$OUTPUT_DIR"

# Check for ffmpeg
if ! command -v ffmpeg &>/dev/null; then
    echo "WARNING: ffmpeg not found. Skipping video thumbnail extraction."
    echo "Install ffmpeg to generate thumbnails from video content."
    exit 0
fi

echo "=== EduPak Thumbnail Generator ==="
echo "Videos dir: $VIDEOS_DIR"
echo "Output dir: $OUTPUT_DIR"
echo "Force: $FORCE"
echo ""

generated=0
skipped=0

# Process each video folder
if [ -d "$VIDEOS_DIR" ]; then
    for folder in "$VIDEOS_DIR"/*/; do
        [ -d "$folder" ] || continue

        folder_name="$(basename "$folder")"
        slug="$(echo "$folder_name" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/-/g' | sed 's/--*/-/g' | sed 's/^-//;s/-$//')"

        webp_out="$OUTPUT_DIR/${slug}.webp"
        jpg_out="$OUTPUT_DIR/${slug}.jpg"

        # Skip if already exists (unless --force)
        if [ "$FORCE" = false ] && [ -f "$webp_out" ]; then
            skipped=$((skipped + 1))
            continue
        fi

        # Find first video file in folder (recurse one level)
        video_file=""
        for ext in mp4 mov avi mkv webm flv f4v wmv; do
            video_file="$(find "$folder" -maxdepth 2 -iname "*.${ext}" -type f 2>/dev/null | head -1)"
            [ -n "$video_file" ] && break
        done

        if [ -z "$video_file" ]; then
            echo "SKIP: No video found in $folder_name"
            skipped=$((skipped + 1))
            continue
        fi

        echo "Processing: $folder_name"

        # Get duration and extract frame at 10%
        duration="$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$video_file" 2>/dev/null || echo "0")"
        if [ -z "$duration" ] || [ "$duration" = "0" ]; then
            timestamp="00:00:05"
        else
            # Calculate 10% point
            seek_seconds="$(echo "$duration * 0.1" | bc 2>/dev/null || echo "5")"
            seek_int="${seek_seconds%%.*}"
            [ -z "$seek_int" ] && seek_int=5
            timestamp="$(printf '%02d:%02d:%02d' $((seek_int/3600)) $(((seek_int%3600)/60)) $((seek_int%60)))"
        fi

        # Extract and resize to JPG
        if ffmpeg -y -ss "$timestamp" -i "$video_file" -vframes 1 -vf "scale=${SIZE%%x*}:${SIZE##*x}:force_original_aspect_ratio=increase,crop=${SIZE%%x*}:${SIZE##*x}" "$jpg_out" 2>/dev/null; then
            # Convert to WebP if possible
            if ffmpeg -y -i "$jpg_out" -quality 80 "$webp_out" 2>/dev/null; then
                echo "  -> $slug.webp + $slug.jpg"
            else
                echo "  -> $slug.jpg (WebP conversion failed)"
            fi
            generated=$((generated + 1))
        else
            echo "  FAILED: Could not extract frame from $folder_name"
            skipped=$((skipped + 1))
        fi
    done
fi

echo ""
echo "Done: $generated generated, $skipped skipped."

# Generate colored placeholders for services (if ImageMagick available)
if command -v convert &>/dev/null; then
    echo ""
    echo "Generating service placeholders..."
    declare -A SERVICE_COLORS=(
        ["kiwix-khan"]="#06B6D4"
        ["kiwix-khmer"]="#4ECDC4"
        ["kiwix-medical"]="#10B981"
        ["kiwix-wiki"]="#64748B"
        ["ai-tools"]="#F59E0B"
        ["ai-image"]="#8B5CF6"
        ["khan-interactive"]="#14B8A6"
    )

    for slug in "${!SERVICE_COLORS[@]}"; do
        color="${SERVICE_COLORS[$slug]}"
        out="$OUTPUT_DIR/${slug}.jpg"
        if [ "$FORCE" = false ] && [ -f "$out" ]; then
            continue
        fi
        convert -size 400x400 "xc:${color}" "$out" 2>/dev/null && echo "  -> $slug.jpg" || true
    done
fi

echo ""
echo "All done."
