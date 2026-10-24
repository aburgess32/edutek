#!/bin/sh
# Patch 4p test clips. sh make-4p-clips.sh <throwaway folder> [<copy of Import Big Test>]   (ffmpeg with libx264/libx265; the importer web image has both)
# 1) <dir>/src/Science/HEVC zero-filled.mp4: a complete MP4 (index and data sizes intact) whose second half of picture data is zeros.
#    It is NOT damaged by the 4p check (nothing is missing), but its conversion fails -> the verified original is copied (4i fallback).
# 2) With a Big Test copy: the files Patch 4p must list as left out or damaged (never silently skipped):
#    a cut-short .mp4 (damaged), a .mkv and a .mov (only .mp4 is imported), a stray picture, a text file, and a cover picture that IS used.
set -e
D=${1:?folder}; B=${2:-}
F="ffmpeg -nostdin -v error -y"
X="-c:v libx265 -preset ultrafast -x265-params log-level=error -tag:v hvc1"
H="-c:v libx264 -preset veryfast -pix_fmt yuv420p"
mkdir -p "$D/src/Science"
$F -f lavfi -i testsrc2=s=640x360:r=25:d=6 -f lavfi -i sine=d=6 $X -c:a aac -shortest -movflags +faststart "$D/whole4p.mp4"
sz=$(stat -c %s "$D/whole4p.mp4"); keep=$((sz*55/100))
head -c $keep "$D/whole4p.mp4" > "$D/src/Science/HEVC zero-filled.mp4"; head -c $((sz-keep)) /dev/zero >> "$D/src/Science/HEVC zero-filled.mp4"; rm -f "$D/whole4p.mp4"
if [ -n "$B" ]; then
 mkdir -p "$B/Geography/Solar System" "$B/Geography/Volcanos" "$B/Physics/Simple Machines" "$B/Test Health/First Aid"
 $F -f lavfi -i testsrc2=s=640x360:r=25:d=6 -f lavfi -i sine=d=6 $H -c:a aac -shortest -movflags +faststart "$D/whole4p.mp4"
 sz=$(stat -c %s "$D/whole4p.mp4"); head -c $((sz*60/100)) "$D/whole4p.mp4" > "$B/Geography/Solar System/Eclipse Half Download.mp4"; rm -f "$D/whole4p.mp4"
 $F -f lavfi -i testsrc2=s=640x360:r=25:d=3 -f lavfi -i sine=d=3 $H -c:a aac -shortest "$B/Geography/Volcanos/Lava Flow Camera.mkv"
 $F -f lavfi -i testsrc2=s=640x360:r=25:d=3 -f lavfi -i sine=d=3 $H -c:a aac -shortest "$B/Physics/Simple Machines/Gears Phone Clip.mov"
 $F -f lavfi -i color=c=steelblue:s=320x240 -frames:v 1 "$B/Stray Photo.png"
 $F -f lavfi -i color=c=darkgreen:s=320x180 -frames:v 1 "$B/Welcome to the Big Test.jpg"
 printf 'First aid notes (Patch 4p test file: not a video, book or audio file, so it is listed as left out).\r\n' > "$B/Test Health/First Aid/notes.txt"
fi
ls -l "$D/src/Science"; [ -z "$B" ] || ls -lR "$B" | head -100
