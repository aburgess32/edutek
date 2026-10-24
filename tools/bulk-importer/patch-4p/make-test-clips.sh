#!/bin/sh
# Patch 4i test clips. sh make-test-clips.sh <throwaway folder>   (needs ffmpeg with libx264/libx265; the importer web image has both)
# Makes <dir>/src (the test "Desktop Videos"), <dir>/import-sources/dupcopy (same HEVC original, renamed) and <dir>/import-sources/bench (60 s 1080p HEVC).
set -e
D=${1:?folder}; S="$D/src/Science"; mkdir -p "$S" "$D/import-sources/dupcopy/Other" "$D/import-sources/bench"
F="ffmpeg -nostdin -v error -y"
X="-c:v libx265 -preset ultrafast -x265-params log-level=error -tag:v hvc1"
$F -f lavfi -i testsrc2=s=640x360:r=25:d=5 -f lavfi -i sine=d=5 $X -c:a aac -shortest "$S/HEVC small 5s.mp4"
$F -f lavfi -i testsrc2=s=1920x1080:r=25:d=8 -f lavfi -i "sine=d=8,aformat=channel_layouts=5.1" $X -c:a aac -ac 6 -shortest "$S/HEVC 1080p surround 8s.mp4"
$F -f lavfi -i testsrc2=s=1080x1920:r=25:d=4 -f lavfi -i sine=d=4 $X -c:a aac -shortest "$S/HEVC portrait 4s.mp4"
$F -f lavfi -i testsrc2=s=640x360:r=25:d=6 -f lavfi -i sine=d=6 -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -shortest -movflags +faststart "$S/H264 faststart 6s.mp4"
$F -f lavfi -i testsrc2=s=1280x720:r=25:d=6 -f lavfi -i sine=d=6 -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -shortest "$S/H264 moov at end 6s.mp4"
# a damaged HEVC file: index says 6 s but only about half of the picture data is there (conversion must fail -> fallback)
$F -f lavfi -i testsrc2=s=640x360:r=25:d=6 -f lavfi -i sine=d=6 $X -c:a aac -shortest -movflags +faststart "$D/whole.mp4"
sz=$(stat -c %s "$D/whole.mp4"); head -c $((sz*55/100)) "$D/whole.mp4" > "$S/HEVC truncated.mp4"; rm -f "$D/whole.mp4"
printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF\n' > "$D/src/Loose Book Test.pdf"
cp "$S/HEVC small 5s.mp4" "$D/import-sources/dupcopy/Other/Renamed HEVC copy.mp4"; touch -d '2026-01-01' "$D/import-sources/dupcopy/Other/Renamed HEVC copy.mp4"
if [ "${2:-}" != "nobench" ]; then
$F -f lavfi -i "testsrc2=s=1920x1080:r=30:d=60,noise=alls=12:allf=t" -f lavfi -i sine=d=60:sample_rate=48000 $X -b:v 6M -c:a aac -b:a 160k -shortest "$D/import-sources/bench/Bench HEVC 1080p 60s.mp4"
fi
ls -lR "$D/src" "$D/import-sources"
