#!/bin/sh
# Patch 4o audio test clips. sh make-audio-clips.sh <throwaway folder>
# Makes <dir>/import-sources/Audio Test with:
#   Road Trip Mix/01 Sunrise.wma      short tagged WMA (title, artist, album, track 1)  -> converted to MP3, tags kept
#   Road Trip Mix/02 Good song.mp3    healthy MP3                                       -> kept as is
#   Road Trip Mix/03 Studio take.aiff AIFF (big-endian PCM)                              -> converted to MP3
#   Road Trip Mix/04 Voice memo.wav   WAV (16-bit PCM)                                   -> kept as is
#   Road Trip Mix/05 Long cut.wma     80 s WMA cut in half: reads fine at first, real sound ends early
#                                     -> conversion check fails -> left out with a clear message (forced-failure test)
#   Road Trip Mix/Broken download.mp3 4 KB of random bytes                              -> "Can't be played - file is damaged", unticked
#   Audiobooks/Guns Germs and Steel/Guns Germs and Steel.mp3  first 4096 bytes of a 60 s MP3 -> damaged, unticked
set -e
D=${1:?folder}; N="$D/import-sources/Audio Test"; M="$N/Road Trip Mix"; A="$N/Audiobooks/Guns Germs and Steel"; mkdir -p "$M" "$A"
F="ffmpeg -nostdin -v error -y -threads 1"
$F -f lavfi -i sine=f=440:d=4 -c:a wmav2 -b:a 96k -metadata title="Sunrise" -metadata artist="Test Band" -metadata album="Road Trip Mix" -metadata track=1 "$M/01 Sunrise.wma"
$F -f lavfi -i sine=f=550:d=4 -c:a libmp3lame -q:a 4 -metadata title="Good song" -metadata track=2 "$M/02 Good song.mp3"
$F -f lavfi -i sine=f=660:d=3 -c:a pcm_s16be "$M/03 Studio take.aiff"
$F -f lavfi -i sine=f=770:d=3 -c:a pcm_s16le "$M/04 Voice memo.wav"
$F -f lavfi -i sine=f=330:d=80 -c:a wmav2 -b:a 64k "$D/long-full.wma"
S=$(wc -c < "$D/long-full.wma"); head -c $((S/2)) "$D/long-full.wma" > "$M/05 Long cut.wma"; rm -f "$D/long-full.wma"
head -c 4096 /dev/urandom > "$M/Broken download.mp3"
$F -f lavfi -i sine=f=220:d=60 -c:a libmp3lame -b:a 128k "$D/ggs-full.mp3"
head -c 4096 "$D/ggs-full.mp3" > "$A/Guns Germs and Steel.mp3"; rm -f "$D/ggs-full.mp3"
ls -lR "$N"
