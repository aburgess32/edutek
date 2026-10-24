#!/bin/sh
# Patch 4k category test clips. sh make-cat-clips.sh <throwaway folder> <existing category name>
# Makes <dir>/import-sources/NEW Edutek Test Videos with two files directly in the folder (like the real "NEW Edutek Test Videos"),
# one in a subfolder "Robotics & AI", one in a subfolder "Unsorted" and one in a subfolder named like an existing library category.
set -e
D=${1:?folder}; C=${2:?category}; N="$D/import-sources/NEW Edutek Test Videos"; mkdir -p "$N/Robotics & AI" "$N/Unsorted" "$N/$C"
F="ffmpeg -nostdin -v error -y"; H="-c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -shortest -movflags +faststart"
$F -f lavfi -i smptehdbars=s=1280x720:r=25:d=3 -f lavfi -i sine=f=1000:d=3 $H "$N/Test Video 01 - Color Bars 720p.mp4"
$F -f lavfi -i rgbtestsrc=s=640x360:r=25:d=3 -f lavfi -i sine=f=440:d=3 $H "$N/Test Video 02 - Tone.mp4"
$F -f lavfi -i mandelbrot=s=640x360:r=25 -f lavfi -i sine=f=660:d=3 -t 3 $H "$N/Robotics & AI/Arm demo.mp4"
$F -f lavfi -i life=s=640x360:r=25 -f lavfi -i sine=f=550:d=3 -t 3 $H "$N/Unsorted/Old clip.mp4"
$F -f lavfi -i cellauto=s=640x360:r=25 -f lavfi -i sine=f=330:d=3 -t 3 $H "$N/$C/Existing cat clip.mp4"
ls -lR "$N"
