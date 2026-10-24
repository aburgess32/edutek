#requires -Version 5.1
<#
  Find-LibraryDuplicates-r3b.ps1  (Edutek)  -- READ-ONLY duplicate analysis of the library.
  r3b = r3 with a fix for the exact-duplicate BY CATEGORY summary table (r3 printed System.Object[]).
  r3 (Oct 22, 2026) = r2 with a space-saving keeper rule for near-duplicates:
    * Default: KEEP the SMALLEST usable copy; larger copies are REMOVE-LARGER candidates (never QUARANTINE,
      nothing moves). Guard: a smaller copy that is not H.264 while a larger one is, or that looks unusable
      (ffprobe failed, duration off by more than -NearDupSeconds, height < 240, < 150 kbps at 360p+), is
      not kept; it is REVIEW with the reason and the next copy up is kept.
    * -KeepHigherQuality restores the r2 rule (keep best quality, others REVIEW-LOWER-QUALITY).
    * SpaceSavedMB column (QUARANTINE and REMOVE-LARGER rows = file size; others 0).
  r2 = Find-LibraryDuplicates.ps1 plus:
    * Width, Height, Codec, BitrateKbps, DurationSec columns (ffprobe header read inside the edupak-app
      container; one probe per identical set, results cached in probe-cache.tsv)
    * -NearDuplicates: same title (case, punctuation, accents and 720p/480p/HD/SD tags ignored) and duration
      within -NearDupSeconds (default 2) but DIFFERENT content. Best version is KEEP (higher resolution, then
      H.264 over HEVC for phones, then higher bitrate); the others are REVIEW-LOWER-QUALITY (r3: see above).
    * -Only <folder under the library>, e.g. -Only History, to analyse one category.
  It moves, renames and deletes NOTHING in the library and changes NOTHING in the catalog
  (it only SELECTs id, file_path, thumbnail_path, category from content_meta).

  Method: group by exact byte size -> quick fingerprint (SHA256 of first 1 MB + last 1 MB + size;
  files up to 2 MB are hashed whole) -> full SHA256 only where quick fingerprints match.
  Only files with the same size AND the same full SHA256 are called duplicates.

  Writes:
    Desktop\Duplicate analysis <stamp>.csv                          (the marks)
    Desktop\Duplicate analysis <stamp> summary.txt
    Desktop\Duplicate analysis <stamp> same-name-different-content.csv (info only)
    D:\edutek-system\reports\dupe-scan\hash-cache.tsv   (append-only resume cache)
    D:\edutek-system\reports\dupe-scan\progress.txt
  Usage:
    powershell -ExecutionPolicy Bypass -File C:\Users\Edutek\Find-LibraryDuplicates-r3b.ps1 -SelfTest
    powershell -ExecutionPolicy Bypass -File C:\Users\Edutek\Find-LibraryDuplicates-r3b.ps1 -Resume -NearDuplicates -Only History
    powershell -ExecutionPolicy Bypass -File C:\Users\Edutek\Find-LibraryDuplicates-r3b.ps1 -Resume -NearDuplicates
    powershell -ExecutionPolicy Bypass -File C:\Users\Edutek\Find-LibraryDuplicates-r3b.ps1 -Resume -NearDuplicates -KeepHigherQuality
#>
[CmdletBinding()]
param(
  [string]$LibraryRoot = 'D:\xampp\htdocs\Edutek\videos',
  [string]$CacheDir = 'D:\edutek-system\reports\dupe-scan',
  [string]$OutDir = '',
  [switch]$Resume,
  [string]$SeedFile = '',
  [datetime]$SeedValidBefore = [datetime]'2026-10-22T12:24:00',
  [switch]$NoSeed,
  [switch]$SelfTest,
  [string]$DockerExe = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$DockerContext = 'desktop-linux',
  [string]$Container = 'edupak-app',
  [string]$ContainerRoot = '/content',
  [string]$Only = '',
  [switch]$NearDuplicates,
  [double]$NearDupSeconds = 2,
  [switch]$KeepHigherQuality
)
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
try { [Diagnostics.Process]::GetCurrentProcess().PriorityClass = [Diagnostics.ProcessPriorityClass]::BelowNormal } catch { }

$script:MB = 1048576
$script:ImageExt = @('.jpg','.jpeg','.png','.gif','.webp','.bmp')
$script:MediaExt = @('.mp4','.m4v','.mkv','.avi','.mov','.webm','.wmv','.flv','.mpg','.mpeg','.3gp','.ts','.mp3','.m4a','.m4b','.wav','.ogg','.oga','.aac','.flac','.wma','.opus','.pdf','.epub')
$script:JunkNames = @('thumbs.db','desktop.ini','.ds_store')
$script:JunkDirs = @('__macosx','.spotlight-v100','.trashes','.fseventsd','$recycle.bin','.temporaryitems')
$script:CopyRx = '(?i)(^copy of |[ _-]copy( \(\d+\))?$|\(copy( \d+)?\)$)'
$script:Buf = New-Object byte[] (4 * 1048576)
$script:QBuf = New-Object byte[] (2 * 1048576 + 8)
$script:Stats = $null
$script:ProgressFile = $null
$script:CacheW = $null
$OutputEncoding = New-Object Text.UTF8Encoding $false
try { [Console]::OutputEncoding = $OutputEncoding } catch { }
$script:VideoExt = @('.mp4','.m4v','.mkv','.avi','.mov','.webm','.wmv','.flv','.mpg','.mpeg','.3gp','.ts')
$script:ProbeW = $null
# r2: title used for the near-duplicate pass: lower case, accents/look-alikes folded, resolution tags
# (2160p..144p, 4k, uhd, fhd, hd, sd, hq, lq) and all punctuation removed.
function Get-NormTitle([string]$name) {
  $s = [IO.Path]::GetFileNameWithoutExtension($name)
  $s = $s.Normalize([Text.NormalizationForm]::FormKD).ToLowerInvariant()
  $s = [regex]::Replace($s, '(?<![\p{L}\p{Nd}])(2160p|1440p|1080p|720p|480p|360p|240p|144p|4k|uhd|fhd|hd|sd|hq|lq)(?![\p{L}\p{Nd}])', ' ')
  $s = [regex]::Replace($s, '[^\p{L}\p{Nd}]+', ' ')
  return ($s -replace '\s+', ' ').Trim()
}
function ConvertFrom-Probe([string]$s) {
  $m = [pscustomobject]@{ W = $null; H = $null; Codec = ''; Kbps = $null; Dur = $null; Err = '' }
  $ci = [Globalization.CultureInfo]::InvariantCulture
  foreach ($kv in $s.Split(';')) {
    $i = $kv.IndexOf('=')
    if ($i -lt 1) { if ($kv.Trim()) { $m.Err = $kv.Trim() }; continue }
    $k = $kv.Substring(0, $i); $v = $kv.Substring($i + 1).Trim(); $n = 0.0
    $ok = [double]::TryParse($v, [Globalization.NumberStyles]::Float, $ci, [ref]$n)
    switch ($k) {
      'codec_name' { $m.Codec = $v }
      'width' { if ($ok) { $m.W = [int]$n } }
      'height' { if ($ok) { $m.H = [int]$n } }
      'duration' { if ($ok) { $m.Dur = [math]::Round($n, 2) } }
      'bit_rate' { if ($ok) { $m.Kbps = [int][math]::Round($n / 1000) } }
    }
  }
  if (-not $m.Err -and $m.Dur -eq $null -and $m.H -eq $null) { $m.Err = 'no video stream found' }
  return $m
}
# Reads video properties with ffprobe inside the app container (header read only, nothing is written).
function Invoke-DockerProbe($list) {
  $res = @{}
  if ($null -eq $list) { return $res }
  $list = [object[]]$list
  if ($list.Length -eq 0) { return $res }
  $sh = 'CR=' + $ContainerRoot + '; while IFS= read -r f; do [ "$f" = "__EDUTEK_END__" ] && break; printf ''%s\t'' "$f"; ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,width,height:format=duration,bit_rate -of default=nw=1 "$CR/$f" </dev/null 2>&1 | tr ''\n\r'' '';;''; echo; done; echo __EDUTEK_DONE__; exit 0'
  $map = @{}
  $sb = New-Object Text.StringBuilder
  [void]$sb.Append($sh).Append("`n")
  foreach ($o in $list) { $r = $o.Rel.Replace('\', '/'); $map[$r] = $o; [void]$sb.Append($r).Append("`n") }
  [void]$sb.Append("__EDUTEK_END__`n")
  $ea = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $out = @($sb.ToString() | & $DockerExe --context $DockerContext exec -i $Container bash -s 2>$null | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $ea }
  if (-not ($out -contains '__EDUTEK_DONE__')) { throw ('ffprobe batch did not finish: ' + ((@($out) | Select-Object -Last 2) -join ' / ')) }
  foreach ($l in $out) {
    $i = $l.IndexOf("`t"); if ($i -lt 1) { continue }
    $r = $l.Substring(0, $i)
    if ($map.ContainsKey($r)) { $res[$map[$r].Key] = ConvertFrom-Probe $l.Substring($i + 1) }
  }
  return $res
}
function Set-VideoMeta($o, $m) {
  $o.Wd = $null; $o.Ht = $null; $o.Kbps = $null; $o.Dur = $null
  if ($m.W -ne $null) { $o.Wd = [int]$m.W }; if ($m.H -ne $null) { $o.Ht = [int]$m.H }
  if ($m.Kbps -ne $null) { $o.Kbps = [int]$m.Kbps }; if ($m.Dur -ne $null) { $o.Dur = [double]$m.Dur }
  $o.Codec = [string]$m.Codec; $o.PErr = [string]$m.Err; $o.Probed = $true
}
function Get-Hp($o) { if ($o.Ht) { return ([string]$o.Ht + 'p') } else { return '?p' } }
function Get-QDesc($o) {
  if (-not $o.Probed) { return 'not probed' }
  if ($o.PErr -and $o.Dur -eq $null) { return ('probe error: ' + $o.PErr) }
  return ('{0}x{1} {2} {3} kbps {4:N1}s' -f $o.Wd, $o.Ht, $o.Codec, $o.Kbps, $o.Dur)
}
function Get-LowerWhy($k, $o) {
  if ([int]$k.Ht -gt [int]$o.Ht) { return ('lower resolution (' + (Get-Hp $o) + ' vs ' + (Get-Hp $k) + ')') }
  if ($k.Codec -eq 'h264' -and $o.Codec -ne 'h264') { return ('same resolution; the kept copy is H.264 (plays on more phones), this one is ' + $o.Codec) }
  if ([double]$k.Kbps -gt [double]$o.Kbps) { return ('same resolution and codec; lower bitrate (' + $o.Kbps + ' vs ' + $k.Kbps + ' kbps)') }
  return 'same resolution, codec and bitrate; a different encode (tie broken by live row, then path)'
}
function Fmt($v) { if ($v -eq $null) { return '' } else { return $v } }
# r3: near-duplicate keeper rule. Default keeps the SMALLEST usable copy (space saving); -KeepHigherQuality = r2 rule.
$script:GuardLabel = @{
  'codec' = 'smaller copy not H.264 while a larger copy is H.264'
  'unreadable' = 'ffprobe could not read the copy'
  'probe' = 'ffprobe found no usable video stream'
  'duration' = 'duration more than the limit away from the other copies'
  'height' = 'height below 240 lines'
  'bitrate' = 'bitrate below 150 kbps at 360p or more (likely broken)'
}
function Get-GuardLabel([string]$c) { if ($script:GuardLabel.ContainsKey($c)) { return $script:GuardLabel[$c] } else { return $c } }
function Get-UnusableCode($o, [double]$refDur, [double]$lim) {
  if (-not $o.Probed -or $o.Dur -eq $null -or [double]$o.Dur -le 0) { return 'unreadable' }
  if (-not $o.Ht -or -not $o.Codec) { return 'probe' }
  if ([math]::Abs([double]$o.Dur - $refDur) -gt $lim) { return 'duration' }
  if ([int]$o.Ht -lt 240) { return 'height' }
  if ([int]$o.Ht -ge 360 -and $o.Kbps -ne $null -and [int]$o.Kbps -gt 0 -and [int]$o.Kbps -lt 150) { return 'bitrate' }
  return ''
}
function Get-GuardText($o) {
  switch ([string]$o.NGuard) {
    'codec' { return ('this copy is ' + $o.Codec + ' and a larger copy is H.264 (H.264 plays on more phones/browsers)') }
    'unreadable' { return ('ffprobe could not read this copy' + $(if ($o.PErr) { ' (' + $o.PErr + ')' } else { '' })) }
    'probe' { return 'ffprobe found no usable video stream in this copy' }
    'duration' { return ('duration ' + $o.Dur + ' s is more than the limit away from the other copies (may be a different cut)') }
    'height' { return ('only ' + (Get-Hp $o) + ' (below 240 lines)') }
    'bitrate' { return ('only ' + $o.Kbps + ' kbps at ' + (Get-Hp $o) + ' (below 150 kbps, likely broken)') }
  }
  return [string]$o.NGuard
}
function Get-MBs($o) { return ([math]::Round($o.Len / 1MB, 1)).ToString([Globalization.CultureInfo]::InvariantCulture) }
function Resolve-NearCluster([object[]]$c, [object[]]$extra, [string]$title, [bool]$keepHQ, [double]$lim) {
  $durs = [double[]]@($c | ForEach-Object { [double]$_.Dur } | Sort-Object)
  $refDur = $durs[[int][math]::Floor(($durs.Length - 1) / 2)]
  foreach ($o in $c) { $o.NGuard = (Get-UnusableCode $o $refDur $lim); $o.NSave = [long]0 }
  foreach ($o in $extra) { $o.NGuard = 'unreadable'; $o.NSave = [long]0 }
  $g = [pscustomobject]@{ Id = ''; Title = $title; Keeper = $null; Lower = $null; LowerBytes = [long]0 }
  $n = $c.Length + $extra.Length
  $low = New-Object System.Collections.Generic.List[object]
  if ($keepHQ) {
    $ranked = [object[]]@($c | Sort-Object @{e = { -1 * [int]$_.Ht } }, @{e = { if ($_.Codec -eq 'h264') { 0 } else { 1 } } }, @{e = { -1 * [double]$_.Kbps } }, @{e = { -not $_.Live } }, @{e = { $_.Key } })
    $k = $ranked[0]
    $k.NAction = 'KEEP'; $k.NReason = 'KEEP: best of ' + $n + ' versions of this title (' + (Get-QDesc $k) + ')'
    for ($q = 1; $q -lt $ranked.Length; $q++) {
      $o = $ranked[$q]
      $o.NAction = 'REVIEW-LOWER-QUALITY'; $o.NSave = [long]$o.Len; $g.LowerBytes += $o.Len
      $o.NReason = 'REVIEW-LOWER-QUALITY: ' + (Get-LowerWhy $k $o) + '; better version: ' + $k.Rel + ' (' + (Get-QDesc $k) + '). Never quarantined automatically; decide by hand'
      $low.Add($o)
    }
  } else {
    $bySize = [object[]]@($c | Sort-Object @{e = { [long]$_.Len } }, @{e = { -not $_.Live } }, @{e = { [string]$_.Key } })
    $k = $null; $skipped = 0
    for ($q = 0; $q -lt $bySize.Length; $q++) {
      $o = $bySize[$q]
      if ($o.NGuard) { $skipped++; continue }
      if ($o.Codec -ne 'h264') {
        $bigH = $false
        for ($r = $q + 1; $r -lt $bySize.Length; $r++) { if (-not $bySize[$r].NGuard -and $bySize[$r].Codec -eq 'h264') { $bigH = $true } }
        if ($bigH) { $o.NGuard = 'codec'; $skipped++; continue }
      }
      $k = $o; break
    }
    $none = $false
    if ($k -eq $null) { $k = $bySize[$bySize.Length - 1]; $none = $true }
    $k.NAction = 'KEEP'
    if ($none) { $k.NReason = 'KEEP (check by hand): no copy passed the playability/usability checks, so the largest is kept (' + (Get-QDesc $k) + ', ' + (Get-MBs $k) + ' MB; ' + (Get-GuardText $k) + ')' }
    elseif ($skipped -gt 0) { $k.NReason = 'KEEP: smallest copy that passed the playability/usability checks (' + (Get-QDesc $k) + ', ' + (Get-MBs $k) + ' MB); the smaller copy/copies went to REVIEW' }
    else { $k.NReason = 'KEEP: smallest of ' + $n + ' versions of this title (' + (Get-QDesc $k) + ', ' + (Get-MBs $k) + ' MB); space-saving rule' }
    if (-not $none -and [int]$k.Ht -ge 720 -and $k.Kbps -ne $null -and [int]$k.Kbps -gt 0 -and [int]$k.Kbps -lt 500) {
      $k.NReason += '; NOTE: low bitrate for ' + (Get-Hp $k) + ' (' + $k.Kbps + ' kbps) - above the 150 kbps broken-file limit so it is still kept, but spot-check the picture'
    }
    foreach ($o in $bySize) {
      if ([object]::ReferenceEquals($o, $k)) { continue }
      if ($o.NGuard) {
        $o.NAction = 'REVIEW'
        $o.NReason = 'REVIEW (playability/usability check): ' + (Get-GuardText $o) + '; kept instead: ' + $k.Rel + ' (' + (Get-QDesc $k) + ', ' + (Get-MBs $k) + ' MB). Not a removal candidate; decide by hand'
      } else {
        $o.NAction = 'REMOVE-LARGER'; $o.NSave = [long]$o.Len; $g.LowerBytes += $o.Len
        $o.NReason = 'REMOVE-LARGER: a smaller copy is kept: ' + $k.Rel + ' (' + (Get-QDesc $k) + ', ' + (Get-MBs $k) + ' MB); this copy is ' + (Get-QDesc $o) + ', ' + (Get-MBs $o) + ' MB; removing it saves ' + (Get-MBs $o) + ' MB. Candidate only; nothing is moved'
      }
      $low.Add($o)
    }
  }
  foreach ($o in $extra) {
    $o.NAction = 'REVIEW'
    $o.NReason = 'REVIEW (playability/usability check): ' + (Get-GuardText $o) + '; kept instead: ' + $k.Rel + ' (' + (Get-QDesc $k) + '). Not a removal candidate; decide by hand'
    $low.Add($o)
  }
  foreach ($o in $low) {
    if ($o.Live) { $o.NReason += '; HAS LIVE CATALOG ROW ' + ($o.Ids -join ',') }
    if ($o.Set -and $o.Set.Id) { $o.NReason += '; identical copies of this file are in set ' + $o.Set.Id }
  }
  $g.Keeper = $k; $g.Lower = $low.ToArray()
  return $g
}

function New-Sha { try { return (New-Object Security.Cryptography.SHA256Cng) } catch { return [Security.Cryptography.SHA256]::Create() } }
function Get-Hex([byte[]]$b) { return ([BitConverter]::ToString($b)).Replace('-', '') }
function Open-Read([string]$p) {
  return (New-Object IO.FileStream($p, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]'ReadWrite, Delete'), 1048576, [IO.FileOptions]::SequentialScan))
}
function Read-Exact($fs, [byte[]]$buf, [int]$off, [int]$count) {
  $t = 0
  while ($t -lt $count) { $r = $fs.Read($buf, $off + $t, $count - $t); if ($r -le 0) { break }; $t += $r }
  if ($t -ne $count) { throw "short read ($t of $count bytes)" }
}
function Get-FullHash([string]$p) {
  $h = New-Sha; $fs = Open-Read $p
  try {
    while (($r = $fs.Read($script:Buf, 0, $script:Buf.Length)) -gt 0) { [void]$h.TransformBlock($script:Buf, 0, $r, $null, 0) }
    [void]$h.TransformFinalBlock($script:Buf, 0, 0)
    return (Get-Hex $h.Hash)
  } finally { $fs.Dispose(); $h.Dispose() }
}
# Returns @(quick, fullOrNull). Files <= 2 MB are hashed whole, so quick = full.
function Get-QuickHash([string]$p, [long]$len) {
  if ($len -le 2 * $script:MB) { $f = Get-FullHash $p; return @($f, $f) }
  $h = New-Sha; $fs = Open-Read $p
  try {
    Read-Exact $fs $script:QBuf 0 $script:MB
    $fs.Position = $len - $script:MB
    Read-Exact $fs $script:QBuf $script:MB $script:MB
    $lb = [BitConverter]::GetBytes([long]$len); [Array]::Copy($lb, 0, $script:QBuf, 2 * $script:MB, 8)
    return @((Get-Hex $h.ComputeHash($script:QBuf, 0, 2 * $script:MB + 8)), $null)
  } finally { $fs.Dispose(); $h.Dispose() }
}
function Say([string]$m) {
  $line = (Get-Date).ToString('HH:mm:ss') + '  ' + $m
  Write-Host $line
  if ($script:ProgressFile) { try { [IO.File]::WriteAllText($script:ProgressFile, $line + "`r`n") } catch { } }
}
function Test-CopyName([string]$s) { return ($s -match $script:CopyRx) }

function Get-CatalogRows {
  $php = @'
<?php
require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/auth.php';
$pdo = getDbConnection();
$st = $pdo->query("SELECT id, file_path, thumbnail_path, category FROM content_meta");
$n = 0;
while ($r = $st->fetch(PDO::FETCH_NUM)) {
  echo 'R:' . $r[0] . "\t" . base64_encode((string)$r[1]) . "\t" . base64_encode((string)$r[2]) . "\t" . base64_encode((string)$r[3]) . "\n";
  $n++;
}
echo "END:$n\n";
'@
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $out = @($php | & $DockerExe --context $DockerContext exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $end = @($out | Where-Object { $_.StartsWith('END:') })
  if ($end.Count -eq 0) { throw ('Catalog read failed: ' + ((@($out) | Select-Object -Last 3) -join ' / ')) }
  $u = New-Object Text.UTF8Encoding $false
  $rows = New-Object System.Collections.Generic.List[object]
  foreach ($l in $out) {
    if (-not $l.StartsWith('R:')) { continue }
    $p = $l.Substring(2).Split("`t")
    if ($p.Count -lt 4) { continue }
    $rows.Add(@($p[0], $u.GetString([Convert]::FromBase64String($p[1])), $u.GetString([Convert]::FromBase64String($p[2])), $u.GetString([Convert]::FromBase64String($p[3]))))
  }
  if ($rows.Count -ne [int]$end[0].Substring(4)) { throw "Catalog read incomplete: got $($rows.Count) of $($end[0])" }
  return ,$rows
}
function ConvertTo-RelKey([string]$fp) {
  if (-not $fp) { return '' }
  $k = $fp.Replace('\', '/').TrimStart('/')
  if ($k.ToLowerInvariant().StartsWith('content/')) { $k = $k.Substring(8) } else { return '' }
  return $k.Replace('/', '\').ToLowerInvariant()
}
function Get-Basis($k, $f) {
  if ($k.Live -and -not $f.Live) { return 'keeper has a live catalog row, this copy has none' }
  if ((-not $k.Loose) -and $f.Loose) { return 'keeper is inside a subfolder, this copy is loose in the category folder' }
  if ((-not $k.IsCopy) -and $f.IsCopy) { return 'this copy is in a "copy" folder or has a copy name' }
  if ($k.Rel.Length -lt $f.Rel.Length) { return 'keeper has the shorter path' }
  if ($k.Ctime -lt $f.Ctime) { return 'keeper is the older copy' }
  return 'tie on all rules; keeper picked by path order'
}

function Invoke-Analysis {
  param([string]$Root, $Rows, [string]$CDir, [string]$ODir, [string]$Stamp, [bool]$UseCache, [string]$Seed, [datetime]$SeedBefore,
        [string]$Scope = '', [bool]$Near = $false, [scriptblock]$Prober = $null, [double]$NearSec = 2, [bool]$KeepHQ = $false)
  $t0 = Get-Date
  $root = (Resolve-Path -LiteralPath $Root).ProviderPath.TrimEnd('\')
  foreach ($d in @($CDir, $ODir)) {
    $full = [IO.Path]::GetFullPath($d).TrimEnd('\')
    if ($full.ToLowerInvariant().StartsWith($root.ToLowerInvariant())) { throw "Output folder $d is inside the library; refusing." }
    if (-not (Test-Path -LiteralPath $d)) { [void](New-Item -ItemType Directory -Path $d) }
  }
  $script:ProgressFile = Join-Path $CDir 'progress.txt'
  $S = [pscustomobject]@{ Files = 0; Bytes = [long]0; Junk = 0; Zero = 0; Candidates = 0; QuickComputed = 0; FullComputed = 0; FullBytesComputed = [long]0; FromCache = 0; FromSeed = 0; Errors = 0; RowsMatched = 0; Rows = 0; ProbeComputed = 0; ProbeFromCache = 0; ProbeErrors = 0 }
  $script:Stats = $S
  $errList = New-Object System.Collections.Generic.List[string]

  # ---- 1. enumerate ----
  $scanDir = $root
  if ($Scope) { $scanDir = Join-Path $root $Scope; if (-not (Test-Path -LiteralPath $scanDir -PathType Container)) { throw "-Only folder not found: $scanDir" }; $scanDir = (Resolve-Path -LiteralPath $scanDir).ProviderPath.TrimEnd('\') }
  Say "Listing files under $scanDir ..."
  $all = New-Object System.Collections.Generic.List[object]
  $byKey = @{}
  $di = New-Object IO.DirectoryInfo $scanDir
  foreach ($f in $di.EnumerateFiles('*', [IO.SearchOption]::AllDirectories)) {
    $rel = $f.FullName.Substring($root.Length + 1)
    $segs = @($rel.Split('\'))
    $nl = $f.Name.ToLowerInvariant()
    $junk = $nl.StartsWith('.') -or ($script:JunkNames -contains $nl) -or $nl.EndsWith('.lnk')
    if (-not $junk) { foreach ($sg in $segs) { if ($script:JunkDirs -contains $sg.ToLowerInvariant()) { $junk = $true; break } } }
    if ($junk) { $S.Junk++; continue }
    if ($f.Length -eq 0) { $S.Zero++; continue }
    $ext = $f.Extension.ToLowerInvariant()
    $isCopy = $false
    if ($segs.Count -gt 1) { foreach ($sg in $segs[0..($segs.Count - 2)]) { if (Test-CopyName $sg) { $isCopy = $true } } }
    if (Test-CopyName ([IO.Path]::GetFileNameWithoutExtension($f.Name))) { $isCopy = $true }
    $cat = '(library root)'; if ($segs.Count -gt 1) { $cat = $segs[0] }
    $o = [pscustomobject]@{
      Full = $f.FullName; Rel = $rel; Key = $rel.ToLowerInvariant(); Name = $f.Name; Len = [long]$f.Length
      Mtime = $f.LastWriteTimeUtc.Ticks; Ctime = $f.CreationTimeUtc.Ticks; Ext = $ext; Cat = $cat
      Dir = [IO.Path]::GetDirectoryName($rel).ToLowerInvariant(); Stem = [IO.Path]::GetFileNameWithoutExtension($f.Name).ToLowerInvariant()
      Loose = ($segs.Count -eq 2); IsCopy = $isCopy; IsImage = ($script:ImageExt -contains $ext); IsCover = $false
      Quick = $null; H = $null; HSrc = ''; Ids = (New-Object System.Collections.Generic.List[string]); Live = $false
      Action = ''; Reason = ''; Set = $null; Hist = 'No'; Partners = (New-Object System.Collections.Generic.List[object])
      IsVideo = ($script:VideoExt -contains $ext); Wd = $null; Ht = $null; Codec = ''; Kbps = $null; Dur = $null; PErr = ''; Probed = $false
      NT = ''; NAction = ''; NReason = ''; NGuard = ''; NSave = [long]0
    }
    $all.Add($o); $byKey[$o.Key] = $o
    $S.Files++; $S.Bytes += $o.Len
    if ($S.Files % 5000 -eq 0) { Say "Listing: $($S.Files) files so far" }
  }
  Say "Listed $($S.Files) files ($([math]::Round($S.Bytes/1GB,1)) GB); skipped $($S.Junk) junk and $($S.Zero) empty files."

  # ---- 2. catalog ----
  $thumbOf = @{}
  $S.Rows = $Rows.Count
  foreach ($r in $Rows) {
    $k = ConvertTo-RelKey $r[1]
    $fo = $null
    if ($k -and $byKey.ContainsKey($k)) { $fo = $byKey[$k]; $fo.Ids.Add([string]$r[0]); $fo.Live = $true; $S.RowsMatched++ }
    $tk = ConvertTo-RelKey $r[2]
    if ($tk -and $fo -and $byKey.ContainsKey($tk)) {
      $to = $byKey[$tk]
      if ($to.IsImage -and -not $to.Partners.Contains($fo)) { $to.Partners.Add($fo) }
    }
  }
  # covers: an image with the same name as a video/audio/pdf in the same folder, or a row's thumbnail
  $mediaByStem = @{}
  foreach ($o in $all) { if ($script:MediaExt -contains $o.Ext) { $sk = $o.Dir + '\' + $o.Stem; if (-not $mediaByStem.ContainsKey($sk)) { $mediaByStem[$sk] = New-Object System.Collections.Generic.List[object] }; $mediaByStem[$sk].Add($o) } }
  foreach ($o in $all) {
    if (-not $o.IsImage) { continue }
    $sk = $o.Dir + '\' + $o.Stem
    if ($mediaByStem.ContainsKey($sk)) { foreach ($m in $mediaByStem[$sk]) { if (-not $o.Partners.Contains($m)) { $o.Partners.Add($m) } } }
    if ($o.Partners.Count -gt 0) { $o.IsCover = $true }
  }
  Say "Catalog: $($S.Rows) rows read, $($S.RowsMatched) point at an existing file."

  # ---- 3. cache / seed ----
  $cacheQ = @{}; $cacheF = @{}
  $cachePath = Join-Path $CDir 'hash-cache.tsv'
  if ($UseCache -and (Test-Path -LiteralPath $cachePath)) {
    foreach ($l in [IO.File]::ReadLines($cachePath)) {
      $p = $l.Split("`t"); if ($p.Count -ne 3) { continue }
      if ($p[0] -eq 'Q') { $cacheQ[$p[1]] = $p[2] } elseif ($p[0] -eq 'F') { $cacheF[$p[1]] = $p[2] }
    }
    Say "Resume cache: $($cacheQ.Count) quick and $($cacheF.Count) full fingerprints loaded."
  }
  $script:CacheW = New-Object IO.StreamWriter($cachePath, $true, (New-Object Text.UTF8Encoding $false))
  $script:CacheW.AutoFlush = $true
  $seedMap = @{}
  if ($Seed -and (Test-Path -LiteralPath $Seed)) {
    foreach ($r in (Import-Csv -LiteralPath $Seed)) {
      if (-not $r.H -or $r.H.StartsWith('ERR')) { continue }
      if (-not $r.Full.ToLowerInvariant().StartsWith($root.ToLowerInvariant() + '\')) { continue }
      $seedMap[$r.Full.Substring($root.Length + 1).ToLowerInvariant() + '|' + $r.Len] = $r.H.ToUpperInvariant()
    }
    Say "Seed: $($seedMap.Count) full SHA256 values from the earlier survey (used only for files unchanged since $SeedBefore)."
  }
  $seedTicks = $SeedBefore.ToUniversalTime().Ticks
  function Ck($o) { return ($o.Key + '|' + $o.Len + '|' + $o.Mtime) }

  # ---- 4. size groups ----
  $bySize = New-Object 'System.Collections.Generic.Dictionary[long,System.Collections.Generic.List[object]]'
  foreach ($o in $all) { if (-not $bySize.ContainsKey($o.Len)) { $bySize[$o.Len] = New-Object System.Collections.Generic.List[object] }; $bySize[$o.Len].Add($o) }
  $groups = @($bySize.Values | Where-Object { $_.Count -gt 1 })
  $cand = New-Object System.Collections.Generic.List[object]
  foreach ($g in $groups) { foreach ($o in $g) { $cand.Add($o) } }
  $S.Candidates = $cand.Count
  foreach ($o in $cand) {
    $ck = Ck $o
    if ($cacheF.ContainsKey($ck)) { $o.H = $cacheF[$ck]; $o.HSrc = 'cache'; $S.FromCache++ }
    elseif ($seedMap.ContainsKey($o.Key + '|' + $o.Len) -and $o.Mtime -lt $seedTicks -and $o.Ctime -lt $seedTicks) { $o.H = $seedMap[$o.Key + '|' + $o.Len]; $o.HSrc = 'survey'; $S.FromSeed++ }
    if ($cacheQ.ContainsKey($ck)) { $o.Quick = $cacheQ[$ck] }
  }
  Say "Same-size candidates: $($cand.Count) files in $($groups.Count) size groups."

  # ---- 5. quick fingerprints ----
  $i = 0; $last = Get-Date
  foreach ($g in $groups) {
    $need = @($g | Where-Object { -not $_.H })
    if ($need.Count -eq 0) { continue }
    foreach ($o in $g) {
      $i++
      if ($o.Quick) { continue }
      try {
        $q = Get-QuickHash $o.Full $o.Len
        $o.Quick = $q[0]; $script:CacheW.WriteLine("Q`t" + (Ck $o) + "`t" + $q[0]); $S.QuickComputed++
        if ($q[1] -and -not $o.H) { $o.H = $q[1]; $o.HSrc = 'computed'; $script:CacheW.WriteLine("F`t" + (Ck $o) + "`t" + $q[1]); $S.FullComputed++; $S.FullBytesComputed += $o.Len }
      } catch { $S.Errors++; $errList.Add($o.Rel + ' : ' + $_.Exception.Message); $o.Quick = 'ERR' }
      if (((Get-Date) - $last).TotalSeconds -ge 15) { $last = Get-Date; Say "Quick fingerprints: $i of $($cand.Count) candidate files checked" }
    }
  }
  # ---- 6. full SHA256 where quick matches ----
  $todo = New-Object System.Collections.Generic.List[object]
  foreach ($g in $groups) {
    $need = @($g | Where-Object { -not $_.H })
    if ($need.Count -eq 0) { continue }
    $qg = @{}
    foreach ($o in $g) { if ($o.Quick -and $o.Quick -ne 'ERR') { if (-not $qg.ContainsKey($o.Quick)) { $qg[$o.Quick] = New-Object System.Collections.Generic.List[object] }; $qg[$o.Quick].Add($o) } }
    foreach ($sub in $qg.Values) { if ($sub.Count -gt 1) { foreach ($o in $sub) { if (-not $o.H) { $todo.Add($o) } } } }
  }
  $todoBytes = [long]0; foreach ($o in $todo) { $todoBytes += $o.Len }
  Say "Full SHA256 needed for $($todo.Count) files ($([math]::Round($todoBytes/1GB,1)) GB)."
  $doneBytes = [long]0; $j = 0; $last = Get-Date
  foreach ($o in $todo) {
    $j++
    try { $o.H = Get-FullHash $o.Full; $o.HSrc = 'computed'; $script:CacheW.WriteLine("F`t" + (Ck $o) + "`t" + $o.H); $S.FullComputed++; $S.FullBytesComputed += $o.Len }
    catch { $S.Errors++; $errList.Add($o.Rel + ' : ' + $_.Exception.Message) }
    $doneBytes += $o.Len
    if (((Get-Date) - $last).TotalSeconds -ge 15) { $last = Get-Date; Say "Full SHA256: $j of $($todo.Count) files, $([math]::Round($doneBytes/1GB,1)) of $([math]::Round($todoBytes/1GB,1)) GB" }
  }
  $script:CacheW.Dispose(); $script:CacheW = $null

  # ---- 7. duplicate sets ----
  $setMap = @{}
  foreach ($o in $cand) { if ($o.H) { $sk = [string]$o.Len + '|' + $o.H; if (-not $setMap.ContainsKey($sk)) { $setMap[$sk] = New-Object System.Collections.Generic.List[object] }; $setMap[$sk].Add($o) } }
  $sets = New-Object System.Collections.Generic.List[object]
  foreach ($sk in $setMap.Keys) {
    $m = $setMap[$sk]; if ($m.Count -lt 2) { continue }
    $st = [pscustomobject]@{ Id = ''; Len = $m[0].Len; H = $m[0].H; Files = $m; Members = (New-Object System.Collections.Generic.List[object]); Reclaim = [long]0; Keeper = $null }
    foreach ($o in $m) { $o.Set = $st }
    $sets.Add($st)
  }
  $identicalCoverSets = 0
  foreach ($st in $sets) {
    $nc = @($st.Files | Where-Object { -not $_.IsCover })
    if ($nc.Count -eq 0) { $identicalCoverSets++; continue }
    if ($nc.Count -eq 1) { $nc[0].Action = 'KEEP'; $nc[0].Reason = 'KEEP: the only copy that is not a cover/thumbnail; the identical copies are covers of other files'; $st.Keeper = $nc[0]; $st.Members.Add($nc[0]); continue }
    $ranked = @($nc | Sort-Object @{e = { -not $_.Live } }, @{e = { $_.Loose } }, @{e = { $_.IsCopy } }, @{e = { $_.Rel.Length } }, @{e = { $_.Ctime } }, @{e = { $_.Key } })
    $k = $ranked[0]; $st.Keeper = $k
    $k.Action = 'KEEP'; $k.Reason = 'KEEP: ' + (Get-Basis $k $ranked[1])
    $reviewCats = @{}
    foreach ($o in $ranked[1..($ranked.Count - 1)]) {
      if ($o.Live -and $k.Live -and $o.Cat -ne $k.Cat -and -not $reviewCats.ContainsKey($o.Cat)) {
        $reviewCats[$o.Cat] = $true
        $o.Action = 'REVIEW'
        $o.Reason = 'REVIEW: same content is live in another category (' + $k.Rel + '); may be intentional, decide by hand'
      } else {
        $o.Action = 'QUARANTINE'
        $o.Reason = 'duplicate of ' + $k.Rel + ' (' + (Get-Basis $k $o) + ')'
        if ($o.Live) { $o.Reason += '; HAS LIVE CATALOG ROW ' + ($o.Ids -join ',') + ': apply step must repoint or remove it' }
      }
    }
    foreach ($o in $ranked) { $st.Members.Add($o) }
  }
  # History tag (Move-HistoryDuplicates.ps1 handles loose History files with a same-name twin in History\History; PDFs excluded)
  foreach ($st in $sets) {
    foreach ($o in $st.Members) {
      if ($o.Rel -match '^(?i)History\\[^\\]+$' -and $o.Ext -ne '.pdf') {
        $twin = ('history\history\' + $o.Name.ToLowerInvariant())
        if (@($st.Files | Where-Object { $_.Key -eq $twin }).Count -gt 0) {
          $o.Hist = 'Yes'
          if ($o.Action -eq 'KEEP') { $o.Reason += ' | NOTE: Move-HistoryDuplicates.ps1 would quarantine this loose copy instead; run the History move first, then re-run this analysis' }
        }
      }
    }
  }
  # covers follow their partner
  foreach ($o in $all) {
    if (-not $o.IsCover) { continue }
    $ps = $o.Partners.ToArray()
    $q = New-Object System.Collections.Generic.List[object]
    $kept = New-Object System.Collections.Generic.List[object]
    foreach ($pp in $ps) { if ($pp.Action -eq 'QUARANTINE') { $q.Add($pp) } else { $kept.Add($pp) } }
    if ($q.Count -eq $ps.Length) {
      $o.Action = 'QUARANTINE'
      $o.Reason = 'cover/thumbnail of quarantined ' + $q[0].Rel
      if ($o.Live) { $o.Reason += '; HAS LIVE CATALOG ROW ' + ($o.Ids -join ',') }
      $allH = $true; foreach ($pp in $q) { if ($pp.Hist -ne 'Yes') { $allH = $false } }; if ($allH) { $o.Hist = 'Yes' }
      $q[0].Set.Members.Add($o)
    } elseif ($o.Set) {
      $o.Action = 'KEEP'; $o.Reason = 'KEEP: cover/thumbnail of ' + $kept[0].Rel + ', which is not being quarantined (identical image content elsewhere)'
      $anyRev = $false; foreach ($pp in $kept) { if ($pp.Action -eq 'REVIEW') { $anyRev = $true } }; if ($anyRev) { $o.Action = 'REVIEW'; $o.Reason = 'REVIEW: cover of a REVIEW file (' + $kept[0].Rel + ')' }
      $o.Set.Members.Add($o)
    }
  }
  # keep only sets with something to do; number them by reclaimable size
  $act = New-Object System.Collections.Generic.List[object]
  foreach ($st in $sets) {
    if (-not $st.Keeper) { $kk = @($st.Members | Where-Object { $_.Action -eq 'KEEP' }); if ($kk.Count -gt 0) { $st.Keeper = $kk[0] } }
    $st.Reclaim = [long]0
    foreach ($o in $st.Members) { if ($o.Action -eq 'QUARANTINE') { $st.Reclaim += $o.Len } }
    if (@($st.Members | Where-Object { $_.Action -eq 'QUARANTINE' -or $_.Action -eq 'REVIEW' }).Count -gt 0) { $act.Add($st) }
  }
  $act = @($act | Sort-Object @{e = { $_.Reclaim }; Descending = $true }, @{e = { if ($_.Keeper) { $_.Keeper.Key } else { '' } } })
  $n = 0; foreach ($st in $act) { $n++; $st.Id = 'D' + $n.ToString('00000') }

  # ---- 7b. r2: video properties (ffprobe header read) for exact sets and near-duplicate candidates ----
  $want = New-Object System.Collections.Generic.List[object]; $wantK = @{}
  foreach ($st in $act) {
    $vm = @($st.Files | Where-Object { $_.IsVideo })
    if ($vm.Count -eq 0) { continue }
    $rep = $vm[0]; if ($st.Keeper -and $st.Keeper.IsVideo -and $st.Keeper.Set -eq $st) { $rep = $st.Keeper }
    if (-not $wantK.ContainsKey($rep.Key)) { $wantK[$rep.Key] = $true; $want.Add($rep) }
  }
  $titleReps = @{}
  if ($Near) {
    $tg = @{}
    foreach ($o in $all) {
      if (-not $o.IsVideo -or $o.Action -eq 'QUARANTINE') { continue }
      $o.NT = Get-NormTitle $o.Name
      if (-not $o.NT) { continue }
      if (-not $tg.ContainsKey($o.NT)) { $tg[$o.NT] = New-Object System.Collections.Generic.List[object] }
      $tg[$o.NT].Add($o)
    }
    foreach ($nt in $tg.Keys) {
      $g = $tg[$nt]; if ($g.Count -lt 2) { continue }
      $byC = @{}
      foreach ($o in $g) {
        $cid = 'U' + $o.Key; if ($o.Set) { $cid = 'S' + $o.Set.Len + '|' + $o.Set.H }
        if (-not $byC.ContainsKey($cid)) { $byC[$cid] = $o }
        elseif ($o.Action -eq 'KEEP' -and $byC[$cid].Action -ne 'KEEP') { $byC[$cid] = $o }
      }
      if ($byC.Count -lt 2) { continue }
      $repList = New-Object System.Collections.Generic.List[object]
      foreach ($r in $byC.Values) { $repList.Add($r); if (-not $wantK.ContainsKey($r.Key)) { $wantK[$r.Key] = $true; $want.Add($r) } }
      $titleReps[$nt] = [object[]]$repList.ToArray()
    }
    Say "Near-duplicate pass: $($titleReps.Count) titles have 2 or more different files."
  }
  $probePath = Join-Path $CDir 'probe-cache.tsv'
  $pc = @{}
  if ($UseCache -and (Test-Path -LiteralPath $probePath)) {
    foreach ($l in [IO.File]::ReadLines($probePath)) { $p = $l.Split("`t"); if ($p.Count -eq 7) { $pc[$p[0]] = $p } }
  }
  $need = New-Object System.Collections.Generic.List[object]
  foreach ($o in $want) {
    $ck = Ck $o
    if ($pc.ContainsKey($ck)) {
      $p = $pc[$ck]; $m = [pscustomobject]@{ W = $null; H = $null; Codec = $p[3]; Kbps = $null; Dur = $null; Err = $p[6] }
      if ($p[1]) { $m.W = [int]$p[1] }; if ($p[2]) { $m.H = [int]$p[2] }; if ($p[4]) { $m.Kbps = [int]$p[4] }
      if ($p[5]) { $m.Dur = [double]::Parse($p[5], [Globalization.CultureInfo]::InvariantCulture) }
      Set-VideoMeta $o $m; $S.ProbeFromCache++
    } else { $need.Add($o) }
  }
  Say "Video properties: $($want.Count) files to describe, $($S.ProbeFromCache) from the probe cache, $($need.Count) to probe."
  if ($Prober -and $need.Count -gt 0) {
    $script:ProbeW = New-Object IO.StreamWriter($probePath, $true, (New-Object Text.UTF8Encoding $false))
    $script:ProbeW.AutoFlush = $true
    $ci = [Globalization.CultureInfo]::InvariantCulture
    try {
      for ($b = 0; $b -lt $need.Count; $b += 300) {
        [object[]]$batch = $need.GetRange($b, [math]::Min(300, $need.Count - $b)).ToArray()
        $got = @{}
        try { $got = & $Prober $batch } catch { $S.ProbeErrors += $batch.Count; $errList.Add('ffprobe batch ' + $b + ' failed: ' + $_.Exception.Message); continue }
        foreach ($o in $batch) {
          if (-not $got.ContainsKey($o.Key)) { $S.ProbeErrors++; $o.Probed = $true; $o.PErr = 'no ffprobe answer'; continue }
          $m = $got[$o.Key]; Set-VideoMeta $o $m; $S.ProbeComputed++
          if ($m.Err -and $m.Dur -eq $null) { $S.ProbeErrors++; continue }
          $dur = ''; if ($m.Dur -ne $null) { $dur = ([double]$m.Dur).ToString($ci) }
          $script:ProbeW.WriteLine((Ck $o) + "`t" + (Fmt $m.W) + "`t" + (Fmt $m.H) + "`t" + $m.Codec + "`t" + (Fmt $m.Kbps) + "`t" + $dur + "`t" + ($m.Err -replace '[\t\r\n]', ' '))
        }
        Say "ffprobe: $([math]::Min($b + 300, $need.Count)) of $($need.Count) files"
      }
    } finally { $script:ProbeW.Dispose(); $script:ProbeW = $null }
  }
  # byte-identical copies share the properties of the probed copy
  foreach ($o in $want) { if ($o.Probed -and $o.Set) { foreach ($f in $o.Set.Files) { if (-not $f.Probed) { Set-VideoMeta $f ([pscustomobject]@{ W = $o.Wd; H = $o.Ht; Codec = $o.Codec; Kbps = $o.Kbps; Dur = $o.Dur; Err = $o.PErr }) } } } }

  # ---- 7c. near-duplicate pass (same title, duration within NearSec, DIFFERENT content). Never QUARANTINE. ----
  $nearGroups = New-Object System.Collections.Generic.List[object]
  $nearNoProbe = 0
  foreach ($nt in @($titleReps.Keys | Sort-Object)) {
    [object[]]$all2 = $titleReps[$nt]
    [object[]]$reps = @($all2 | Where-Object { $_.Dur -ne $null -and $_.Dur -gt 0 } | Sort-Object @{e = { [double]$_.Dur } }, @{e = { [string]$_.Key } })
    [object[]]$bad = @($all2 | Where-Object { -not ($_.Dur -ne $null -and $_.Dur -gt 0) })
    $clusters = New-Object System.Collections.Generic.List[object]
    $i = 0
    while ($i -lt $reps.Length) {
      [object[]]$nclu = @($reps[$i]); $j = $i + 1
      while ($j -lt $reps.Length -and ($reps[$j].Dur - $reps[$i].Dur) -le $NearSec) { $nclu += $reps[$j]; $j++ }
      $i = $j
      if ($nclu.Length -ge 2) { $clusters.Add($nclu) }
    }
    if ($bad.Length -gt 0 -and $clusters.Count -eq 0 -and $reps.Length -gt 0) {
      [object[]]$big = @($reps | Sort-Object @{e = { [long]$_.Len }; Descending = $true } | Select-Object -First 1); $clusters.Add($big)
    }
    if ($bad.Length -gt 0 -and $clusters.Count -eq 0) { $nearNoProbe += $bad.Length }
    for ($ci2 = 0; $ci2 -lt $clusters.Count; $ci2++) {
      [object[]]$ex = @(); if ($ci2 -eq 0) { $ex = $bad }
      $nearGroups.Add((Resolve-NearCluster ([object[]]$clusters[$ci2]) $ex $nt $KeepHQ $NearSec))
    }
  }
  $nearGroups = @($nearGroups | Sort-Object @{e = { $_.LowerBytes }; Descending = $true }, @{e = { $_.Keeper.Key } })
  $n = 0; foreach ($g in $nearGroups) { $n++; $g.Id = 'N' + $n.ToString('00000') }

  # ---- 8. outputs ----
  $order = @{ 'KEEP' = 0; 'REVIEW' = 1; 'QUARANTINE' = 2 }
  $report = New-Object System.Collections.Generic.List[object]
  foreach ($st in $act) {
    foreach ($o in @($st.Members | Sort-Object @{e = { $order[$_.Action] } }, @{e = { $_.IsImage } }, @{e = { $_.Key } })) {
      $sha = ''; if ($o.Set -eq $st) { $sha = $o.H }
      $report.Add([pscustomobject][ordered]@{
        SetId = $st.Id; Action = $o.Action; Reason = $o.Reason; Category = $o.Cat; Path = $o.Full
        SizeMB = [math]::Round($o.Len / 1MB, 2); SHA256 = $sha; 'CatalogId(s)' = ($o.Ids -join ',')
        HasLiveRow = $(if ($o.Live) { 'Yes' } else { 'No' }); CoveredByHistoryMove = $o.Hist
        Width = (Fmt $o.Wd); Height = (Fmt $o.Ht); Codec = $o.Codec; BitrateKbps = (Fmt $o.Kbps); DurationSec = (Fmt $o.Dur); MatchTitle = ''
        SpaceSavedMB = $(if ($o.Action -eq 'QUARANTINE') { [math]::Round($o.Len / 1MB, 2) } else { 0 })
      })
    }
  }
  $nearRows = New-Object System.Collections.Generic.List[object]
  foreach ($g in $nearGroups) {
    foreach ($o in @(@($g.Keeper) + $g.Lower)) {
      $nearRows.Add([pscustomobject][ordered]@{
        SetId = $g.Id; Action = $o.NAction; Reason = $o.NReason; Category = $o.Cat; Path = $o.Full
        SizeMB = [math]::Round($o.Len / 1MB, 2); SHA256 = [string]$o.H; 'CatalogId(s)' = ($o.Ids -join ',')
        HasLiveRow = $(if ($o.Live) { 'Yes' } else { 'No' }); CoveredByHistoryMove = 'No'
        Width = (Fmt $o.Wd); Height = (Fmt $o.Ht); Codec = $o.Codec; BitrateKbps = (Fmt $o.Kbps); DurationSec = (Fmt $o.Dur); MatchTitle = $g.Title
        SpaceSavedMB = [math]::Round($o.NSave / 1MB, 2)
      })
    }
  }
  foreach ($r in $nearRows) { $report.Add($r) }
  # same name, different content (videos/audio/pdf/other; images left out)
  $byName = @{}
  foreach ($o in $all) { if (-not $o.IsImage) { $nk = $o.Name.ToLowerInvariant(); if (-not $byName.ContainsKey($nk)) { $byName[$nk] = New-Object System.Collections.Generic.List[object] }; $byName[$nk].Add($o) } }
  $same = New-Object System.Collections.Generic.List[object]
  $sameGroups = 0
  foreach ($nk in @($byName.Keys | Sort-Object)) {
    $g = $byName[$nk]; if ($g.Count -lt 2) { continue }
    $ckeys = @{}
    foreach ($o in $g) { $c = 'L' + $o.Len; if ($o.H) { $c = 'H' + $o.H } elseif ($o.Quick -and $o.Quick -ne 'ERR') { $c = 'Q' + $o.Len + $o.Quick }; if (-not $ckeys.ContainsKey($c)) { $ckeys[$c] = [string][char](65 + [math]::Min($ckeys.Count, 25)) } }
    if ($ckeys.Count -lt 2) { continue }
    $sameGroups++
    foreach ($o in $g) {
      $c = 'L' + $o.Len; if ($o.H) { $c = 'H' + $o.H } elseif ($o.Quick -and $o.Quick -ne 'ERR') { $c = 'Q' + $o.Len + $o.Quick }
      $sid = ''; if ($o.Set -and $o.Set.Id) { $sid = $o.Set.Id }
      $same.Add([pscustomobject][ordered]@{ Action = 'NOT DUPLICATE'; Name = $o.Name; ContentVariant = $ckeys[$c]; Category = $o.Cat; Path = $o.Full; SizeMB = [math]::Round($o.Len / 1MB, 2); SHA256 = [string]$o.H; DuplicateSetId = $sid; DuplicateAction = $o.Action; HasLiveRow = $(if ($o.Live) { 'Yes' } else { 'No' }) })
    }
  }
  $csv = Join-Path $ODir ("Duplicate analysis $Stamp.csv")
  $csv2 = Join-Path $ODir ("Duplicate analysis $Stamp same-name-different-content.csv")
  $txt = Join-Path $ODir ("Duplicate analysis $Stamp summary.txt")
  $csv3 = Join-Path $ODir ("Duplicate analysis $Stamp near-duplicates.csv")
  $report | Export-Csv -LiteralPath $csv -NoTypeInformation -Encoding UTF8
  if ($Near) { $nearRows | Export-Csv -LiteralPath $csv3 -NoTypeInformation -Encoding UTF8 }
  $same | Export-Csv -LiteralPath $csv2 -NoTypeInformation -Encoding UTF8

  $Q = @($report | Where-Object { $_.Action -eq 'QUARANTINE' -and $_.SetId -like 'D*' })
  $qObjs = New-Object System.Collections.Generic.List[object]
  foreach ($st in $act) { foreach ($o in $st.Members) { if ($o.Action -eq 'QUARANTINE') { $qObjs.Add($o) } } }
  $qBytes = [long]0; $qHist = [long]0; $qHistN = 0; $qLive = 0; $qImg = 0; $qImgBytes = [long]0
  foreach ($o in $qObjs) { $qBytes += $o.Len; if ($o.Hist -eq 'Yes') { $qHist += $o.Len; $qHistN++ }; if ($o.Live) { $qLive++ }; if ($o.IsImage) { $qImg++; $qImgBytes += $o.Len } }
  $revList = @($report | Where-Object { $_.Action -eq 'REVIEW' -and $_.SetId -like 'D*' })
  $sb = New-Object Text.StringBuilder
  $L = { param($s) [void]$sb.AppendLine($s) }
  & $L 'EDUTEK LIBRARY DUPLICATE ANALYSIS r3  (READ-ONLY: nothing was moved, renamed or deleted)'
  & $L ('Scope: ' + $(if ($Scope) { $scanDir } else { 'whole library' }) + '   Near-duplicate pass: ' + $(if ($Near) { 'ON (duration within ' + $NearSec + ' s)' } else { 'off' }))
  & $L ('Run: ' + $t0.ToString('ddd MMM d, yyyy h:mm tt') + ' to ' + (Get-Date).ToString('h:mm tt') + ' (Edutek1 local time)')
  & $L ('Library: ' + $root)
  & $L ('Marks CSV: ' + $csv)
  & $L ('Same-name-different-content CSV (info only): ' + $csv2)
  & $L ''
  & $L ('Files looked at: {0:N0} ({1:N1} GB). Skipped: {2:N0} junk files (._*, dot-files, Thumbs.db, desktop.ini, .lnk), {3:N0} empty files.' -f $S.Files, ($S.Bytes / 1GB), $S.Junk, $S.Zero)
  & $L ('Same-size candidates: {0:N0} files. Full SHA256: {1:N0} from this run ({2:N1} GB read), {3:N0} from the resume cache, {4:N0} from the 12:24-2:51 PM survey hash (only for files unchanged since then).' -f $S.Candidates, $S.FullComputed, ($S.FullBytesComputed / 1GB), $S.FromCache, $S.FromSeed)
  & $L ('Read errors: {0}' -f $S.Errors)
  & $L ('Video properties (ffprobe): {0:N0} probed now, {1:N0} from the probe cache, {2:N0} could not be read' -f $S.ProbeComputed, $S.ProbeFromCache, $S.ProbeErrors)
  & $L ('Catalog: {0:N0} rows read; {1:N0} point at an existing file.' -f $S.Rows, $S.RowsMatched)
  & $L ''
  & $L 'RESULTS (confirmed by identical size AND identical full SHA256)'
  & $L ('  Duplicate sets with something to do: {0:N0}' -f $act.Count)
  & $L ('  Files marked QUARANTINE: {0:N0}   reclaimable {1:N2} GB' -f $Q.Count, ($qBytes / 1GB))
  & $L ('     of which covers/thumbnails following their video/pdf: {0:N0} ({1:N2} GB)' -f $qImg, ($qImgBytes / 1GB))
  & $L ('     of which already covered by the History move: {0:N0} ({1:N2} GB) -> left for THIS cleanup: {2:N0} files, {3:N2} GB' -f $qHistN, ($qHist / 1GB), ($Q.Count - $qHistN), (($qBytes - $qHist) / 1GB))
  & $L ('     of which have a live catalog row (apply step must repoint/remove the row): {0:N0}' -f $qLive)
  & $L ('  Files marked REVIEW (same content live in two categories, may be intentional): {0:N0}' -f $revList.Count)
  & $L ('  Sets of identical covers only (videos differ, so nothing marked): {0:N0}' -f $identicalCoverSets)
  & $L ('  Same name, different content (NOT duplicates, info only): {0:N0} names, {1:N0} files' -f $sameGroups, $same.Count)
  if ($Near) {
    $rmLbl = $(if ($KeepHQ) { 'REVIEW-LOWER-QUALITY' } else { 'REMOVE-LARGER' })
    $rmN = 0; $rmB = [long]0; $rmLive = 0; $revN = 0; $pairs = @{}; $guards = @{}; $catS = @{}
    foreach ($g in $nearGroups) {
      foreach ($o in $g.Lower) {
        if ($o.NAction -eq $rmLbl) {
          $rmN++; $rmB += $o.Len; if ($o.Live) { $rmLive++ }
          $pk = (Get-Hp $g.Keeper) + ' kept / ' + (Get-Hp $o) + $(if ($KeepHQ) { ' lower' } else { ' removed' })
          if (-not $pairs.ContainsKey($pk)) { $pairs[$pk] = 0 }; $pairs[$pk]++
          if (-not $catS.ContainsKey($o.Cat)) { $catS[$o.Cat] = @(0, [long]0) }; $catS[$o.Cat][0]++; $catS[$o.Cat][1] += $o.Len
        } elseif ($o.NAction -eq 'REVIEW') {
          $revN++; $gc = [string]$o.NGuard; if (-not $guards.ContainsKey($gc)) { $guards[$gc] = 0 }; $guards[$gc]++
        }
      }
    }
    $kNote = @($nearGroups | Where-Object { $_.Keeper.NReason -like '*NOTE: low bitrate*' }).Count
    $kNone = @($nearGroups | Where-Object { $_.Keeper.NReason -like 'KEEP (check by hand)*' }).Count
    & $L ''
    & $L 'NEAR-DUPLICATES (same title, duration within the limit, DIFFERENT files: other encodes / resolutions)'
    if ($KeepHQ) {
      & $L '  Rule: -KeepHigherQuality (r2): keep the highest resolution, then H.264 over HEVC, then higher bitrate; others REVIEW-LOWER-QUALITY.'
    } else {
      & $L '  Rule (default): keep the SMALLEST usable copy to save space on the device; larger copies are REMOVE-LARGER candidates.'
      & $L '  Guard: a smaller copy that is not H.264 while a larger copy is, or that looks unusable (ffprobe failed, duration off by'
      & $L ('  more than ' + $NearSec + ' s, below 240 lines, under 150 kbps at 360p+), is NOT kept: it goes to REVIEW and the next copy up is kept.')
      & $L '  Nothing is QUARANTINE here and nothing is moved. Use -KeepHigherQuality for the old keep-the-best rule.'
    }
    & $L ('  Groups: {0:N0}' -f $nearGroups.Count)
    & $L ('  {0}: {1:N0} files, {2:N2} GB would be saved ({3:N0} have a live catalog row; an apply step must repoint or remove the row)' -f $rmLbl, $rmN, ($rmB / 1GB), $rmLive)
    if (-not $KeepHQ) {
      & $L ('  REVIEW (guard): {0:N0} files' -f $revN)
      foreach ($gc in @($guards.Keys | Sort-Object @{e = { $guards[$_] }; Descending = $true })) { & $L ('    {0,-62} {1,6:N0}' -f (Get-GuardLabel $gc), $guards[$gc]) }
      & $L ('  Groups where no copy passed the checks (largest kept, check by hand): {0:N0}' -f $kNone)
      & $L ('  Groups whose kept copy has a low bitrate for its resolution (<500 kbps at 720p+; note only, still kept): {0:N0}' -f $kNote)
    }
    & $L ('  Titles with copies ffprobe could not read and nothing to compare against: {0:N0} files' -f $nearNoProbe)
    & $L '  Resolution pairs:'
    foreach ($pk in @($pairs.Keys | Sort-Object @{e = { $pairs[$_] }; Descending = $true })) { & $L ('    {0,-30} {1,6:N0}' -f $pk, $pairs[$pk]) }
    & $L ('  By category ({0} files / GB saved):' -f $rmLbl)
    foreach ($ct in @($catS.Keys | Sort-Object @{e = { $catS[$_][1] }; Descending = $true } | Select-Object -First 15)) { & $L ('    {0,-62} {1,6:N0}  {2,8:N2} GB' -f $ct, $catS[$ct][0], ($catS[$ct][1] / 1GB)) }
    & $L '  Top 10 groups by space saved:'
    foreach ($g in @($nearGroups | Select-Object -First 10)) { & $L ('    {0}  KEEP {1} {2} MB {3} | {4}' -f $g.Id, (Get-Hp $g.Keeper), (Get-MBs $g.Keeper), $g.Keeper.Rel, ((@($g.Lower | ForEach-Object { $_.NAction + ' ' + (Get-Hp $_) + ' ' + (Get-MBs $_) + ' MB ' + $_.Rel })) -join ' ; ')) }
    & $L ('  Near-duplicates CSV: ' + $csv3)
  }
  & $L ''
  & $L 'BY CATEGORY (QUARANTINE files / GB / REVIEW files)'
  $cats = @{}
  foreach ($o in $qObjs) { if (-not $cats.ContainsKey($o.Cat)) { $cats[$o.Cat] = @(0, [long]0, 0) }; $cats[$o.Cat][0]++; $cats[$o.Cat][1] += $o.Len }
  foreach ($r in $revList) { if (-not $cats.ContainsKey($r.Category)) { $cats[$r.Category] = @(0, [long]0, 0) }; $cats[$r.Category][2]++ }
  foreach ($c in @($cats.Keys | Sort-Object @{e = { $cats[$_][1] }; Descending = $true })) {
    & $L ('  {0,-62} {1,7:N0}  {2,9:N2} GB  {3,6:N0}' -f $c, $cats[$c][0], ($cats[$c][1] / 1GB), $cats[$c][2])
  }
  & $L ''
  & $L 'TOP 10 SETS BY SPACE RECLAIMED'
  foreach ($st in @($act | Select-Object -First 10)) {
    $qn = @($st.Members | Where-Object { $_.Action -eq 'QUARANTINE' }).Count
    & $L ('  {0}  {1:N2} GB reclaim, {2} copies of {3:N1} MB  KEEP {4}' -f $st.Id, ($st.Reclaim / 1GB), $qn, ($st.Len / 1MB), $(if ($st.Keeper) { $st.Keeper.Rel } else { '(no keeper: covers only)' }))
  }
  & $L ''
  & $L 'HOW KEEPERS WERE CHOSEN: (a) has a live catalog row (the path the app serves), (b) inside a subfolder rather than loose in'
  & $L 'the category folder, (c) not in a "copy" folder/name, (d) shorter path, (e) older. Covers are marked only when their'
  & $L 'video/pdf is marked. Same content live in two different categories is REVIEW, not QUARANTINE.'
  & $L 'Nothing has been moved. A separate apply step (not built yet) must re-check size + SHA256 before moving anything.'
  if ($errList.Count -gt 0) { & $L ''; & $L 'READ ERRORS:'; foreach ($e in $errList) { & $L ('  ' + $e) } }
  [IO.File]::WriteAllText($txt, $sb.ToString(), (New-Object Text.UTF8Encoding $true))
  Say ("DONE. Sets {0}, QUARANTINE {1} files / {2:N2} GB, REVIEW {3}. Summary: {4}" -f $act.Count, $Q.Count, ($qBytes / 1GB), $revList.Count, $txt)
  return [pscustomobject]@{ Report = $report; Same = $same; Stats = $S; Csv = $csv; Txt = $txt; Csv2 = $csv2; Csv3 = $csv3; Near = [object[]]$nearRows.ToArray() }
}

# ======================= SELF-TEST =======================
function Invoke-SelfTest {
  $base = Join-Path $env:TEMP ('dupe-selftest-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
  $lib = Join-Path $base 'lib'; $cd = Join-Path $base 'cache'; $od = Join-Path $base 'out'
  $fails = 0
  function W([string]$rel, [byte[]]$b) { $p = Join-Path $lib $rel; $d = Split-Path $p; if (-not (Test-Path -LiteralPath $d)) { [void](New-Item -ItemType Directory -Path $d -Force) }; [IO.File]::WriteAllBytes($p, $b) }
  function B([int]$size, [int]$seed) { $r = New-Object Random $seed; $b = New-Object byte[] $size; $r.NextBytes($b); return ,$b }
  function Check([bool]$c, [string]$m) { if ($c) { Write-Host "  PASS  $m" } else { Write-Host "  FAIL  $m" -ForegroundColor Red; $script:stFails++ } }
  $script:stFails = 0
  try {
    $A = B (3 * 1048576) 1; $Z = B 50000 2; $P = B 400000 3; $I = B 20000 4; $C = B 900000 5
    $D = B (3 * 1048576 + 5) 6; $E = [byte[]]$D.Clone(); $E[1600000] = $E[1600000] -bxor 0xFF   # same size, same first/last MB, different middle
    $Hh = B 700000 7; $Y = B 30000 8
    W 'CatA\x.mp4' $A; W 'CatA\Sub\x.mp4' $A; W 'CatA\x.jpg' $I; W 'CatA\Sub\x.jpg' $I; W 'CatA\._x.mp4' $A
    W 'CatB\Stuff - Copy\y.pdf' $P; W 'CatB\Stuff\y.pdf' $P; W 'CatB\Stuff - Copy\y.jpg' $Y
    W 'CatC\z.mp3' $Z; W 'CatD\z.mp3' $Z; W 'CatE\z.mp3' $Z
    W 'CatC\same.mp4' $D; W 'CatD\same.mp4' $E
    W 'History\h.mp4' $Hh; W 'History\History\h.mp4' $Hh
    W 'CatA\empty.mp4' ([byte[]]@()); W 'CatB\empty.mp4' ([byte[]]@())
    W 'CatF\only.mp4' $C
    # near-duplicate fixtures (different bytes each; sizes chosen so "smaller" is known)
    W 'NearA\Crash Course 1 #1.mp4' (B 300100 21); W 'NearA\Crash Course 1 1.mp4' (B 300001 22)
    W 'NearB\Lesson 720p.mp4' (B 300003 23); W 'NearB\Lesson.mp4' (B 300004 24)
    W 'NearC\Talk.mp4' (B 300005 25); W 'NearC\Talk HD.mp4' (B 300006 26)
    W 'NearD\Clip.mp4' (B 300007 27); W 'NearD\Clip (480p).mp4' (B 300008 28)
    $F = B 300009 29; W 'NearE\a\Film.mp4' $F; W 'NearE\b\Film.mp4' $F
    W 'NearF\Ancient Egypt 4.mp4' (B 300010 30); W 'NearF\Ancient Egypt #4.mp4' (B 300200 31)
    W 'NearG\Song.mp4' (B 300011 32); W 'NearG\Song HQ.mp4' (B 300300 33)
    W 'NearH\Tiny.mp4' (B 300012 34); W 'NearH\Tiny SD.mp4' (B 300400 35)
    W 'NearI\Doc 4K.mp4' (B 300013 36); W 'NearI\Doc.mp4' (B 300500 37); W 'NearI\Doc HD.mp4' (B 300600 38); W 'NearI\Doc SD.mp4' (B 300700 39)
    W 'NearJ\Lost.mp4' (B 300014 40); W 'NearJ\Lost HD.mp4' (B 300800 41)
    $script:stFake = @{
      'neara\crash course 1 #1.mp4' = @(1920, 1080, 'h264', 2500, 600.0); 'neara\crash course 1 1.mp4' = @(854, 480, 'h264', 800, 600.5)
      'nearb\lesson 720p.mp4' = @(1280, 720, 'hevc', 1500, 300.0); 'nearb\lesson.mp4' = @(1280, 720, 'h264', 1200, 301.0)
      'nearc\talk.mp4' = @(640, 360, 'h264', 500, 100.0); 'nearc\talk hd.mp4' = @(640, 360, 'h264', 900, 101.0)
      'neard\clip.mp4' = @(640, 360, 'h264', 500, 100.0); 'neard\clip (480p).mp4' = @(854, 480, 'h264', 900, 110.0)
      'nearf\ancient egypt 4.mp4' = @(1920, 1080, 'h264', 331, 714.18); 'nearf\ancient egypt #4.mp4' = @(1920, 1080, 'hevc', 1910, 714.11)
      'nearg\song.mp4' = @(854, 480, 'h264', 100, 200.0); 'nearg\song hq.mp4' = @(854, 480, 'h264', 800, 200.2)
      'nearh\tiny.mp4' = @(256, 144, 'h264', 200, 50.0); 'nearh\tiny sd.mp4' = @(854, 480, 'h264', 700, 50.3)
      'neari\doc 4k.mp4' = @(1280, 720, 'h264', 1000, 401.9); 'neari\doc.mp4' = @(1280, 720, 'h264', 1000, 400.0)
      'neari\doc hd.mp4' = @(1280, 720, 'h264', 1000, 401.0); 'neari\doc sd.mp4' = @(1280, 720, 'h264', 1000, 401.5)
      'nearj\lost.mp4' = @($null, $null, '', $null, $null); 'nearj\lost hd.mp4' = @(1280, 720, 'h264', 1000, 77.0)
    }
    $fakeProber = {
      param($list)
      $r = @{}
      foreach ($o in $list) {
        $f = $script:stFake[$o.Key]
        if ($f -and $f[4] -eq $null) { $r[$o.Key] = [pscustomobject]@{ W = $null; H = $null; Codec = ''; Kbps = $null; Dur = $null; Err = 'fake: moov atom not found' } }
        elseif ($f) { $r[$o.Key] = [pscustomobject]@{ W = $f[0]; H = $f[1]; Codec = $f[2]; Kbps = $f[3]; Dur = $f[4]; Err = '' } }
        else { $cs = 0; foreach ($ch in $o.Key.ToCharArray()) { $cs += [int]$ch }; $d = 1000 + (($cs * 13) % 50000); $r[$o.Key] = [pscustomobject]@{ W = 1280; H = 720; Codec = 'h264'; Kbps = 1000; Dur = $d; Err = '' } }
      }
      return $r
    }
    $rows = @(
      @('1', 'content/CatC/z.mp3', '', 'CatC'), @('2', 'content/CatD/z.mp3', '', 'CatD'),
      @('3', 'content/CatA/x.mp4', 'content/CatA/x.jpg', 'CatA'), @('4', 'content/CatA/Sub/x.mp4', 'content/CatA/Sub/x.jpg', 'CatA'),
      @('9', 'content/Gone/missing.mp4', '', 'Gone')
    )
    $res = Invoke-Analysis -Root $lib -Rows $rows -CDir $cd -ODir $od -Stamp 'selftest' -UseCache $false -Seed '' -SeedBefore ([datetime]'2000-01-01') -Near $true -Prober $fakeProber -NearSec 2
    $get = { param($rel) @($res.Report | Where-Object { $_.Path -eq (Join-Path $lib $rel) -and $_.SetId -like 'D*' }) }
    $getN = { param($rel) @($res.Near | Where-Object { $_.Path -eq (Join-Path $lib $rel) }) }
    Check ((& $get 'CatA\Sub\x.mp4')[0].Action -eq 'KEEP') 'subfolder copy kept (both live)'
    $lx = (& $get 'CatA\x.mp4')[0]
    Check ($lx.Action -eq 'QUARANTINE' -and $lx.Reason -match 'LIVE CATALOG ROW 3') 'loose copy quarantined, live row flagged'
    Check ((& $get 'CatA\x.jpg')[0].Action -eq 'QUARANTINE') 'cover of quarantined video quarantined'
    Check (@(& $get 'CatA\Sub\x.jpg' | Where-Object { $_.Action -ne 'KEEP' }).Count -eq 0) 'cover of kept video not marked'
    Check ((& $get 'CatB\Stuff - Copy\y.pdf')[0].Action -eq 'QUARANTINE') 'copy-folder pdf quarantined'
    Check ((& $get 'CatB\Stuff - Copy\y.jpg')[0].Action -eq 'QUARANTINE') 'unique cover follows its quarantined pdf'
    Check ((& $get 'CatB\Stuff\y.pdf')[0].Action -eq 'KEEP') 'normal-folder pdf kept'
    $zs = @(@('CatC\z.mp3', 'CatD\z.mp3', 'CatE\z.mp3') | ForEach-Object { (& $get $_)[0].Action })
    Check (($zs -join ',') -eq 'KEEP,REVIEW,QUARANTINE') ('cross-category live copies: KEEP/REVIEW, non-live copy QUARANTINE (got ' + ($zs -join ',') + ')')
    Check (@(& $get 'CatC\same.mp4').Count -eq 0) 'same quick fingerprint but different full SHA256 is NOT a duplicate'
    Check (@($res.Same | Where-Object { $_.Name -eq 'same.mp4' }).Count -eq 2) 'same-name-different-content listed'
    $h = (& $get 'History\h.mp4')[0]
    Check ($h.Action -eq 'QUARANTINE' -and $h.CoveredByHistoryMove -eq 'Yes') 'loose History copy quarantined and tagged History move'
    Check (@($res.Report | Where-Object { $_.Path -like '*empty.mp4' -or $_.Path -like '*._x.mp4' -or $_.Path -like '*only.mp4' }).Count -eq 0) 'empty, junk and unique files not listed'
    Check ((Test-Path -LiteralPath (Join-Path $lib 'CatA\x.mp4')) -and (Test-Path -LiteralPath (Join-Path $lib 'CatB\Stuff - Copy\y.pdf'))) 'no library file moved'
    Check ((Test-Path -LiteralPath $res.Csv) -and (Test-Path -LiteralPath $res.Txt)) 'CSV and summary written'
    # r2 checks
    $xm = (& $get 'CatA\x.mp4')[0]
    Check ($xm.Height -eq 720 -and $xm.Codec -eq 'h264' -and $xm.Width -eq 1280 -and [string]$xm.BitrateKbps -ne '' -and [string]$xm.DurationSec -ne '') 'video columns filled for a duplicate set (Width/Height/Codec/BitrateKbps/DurationSec)'
    Check ((& $get 'CatA\Sub\x.mp4')[0].Height -eq 720) 'identical copy gets the same properties'
    Check ([string](& $get 'CatA\x.jpg')[0].Height -eq '') 'images get no video properties'
    # near-duplicate checks: default rule = keep the SMALLEST usable copy
    $nA = { param($rel) $x = @(& $getN $rel); if ($x.Length -eq 1) { return [string]$x[0].Action } else { return ('rows=' + $x.Length) } }
    Check ((& $nA 'NearA\Crash Course 1 1.mp4') -eq 'KEEP' -and (& $nA 'NearA\Crash Course 1 #1.mp4') -eq 'REMOVE-LARGER') 'near-dup default: smaller 480p kept, larger 1080p REMOVE-LARGER (titles differ only by #)'
    $rl = @(& $getN 'NearA\Crash Course 1 #1.mp4')[0]
    Check ([double]$rl.SpaceSavedMB -gt 0.28 -and [double]@(& $getN 'NearA\Crash Course 1 1.mp4')[0].SpaceSavedMB -eq 0) ('SpaceSavedMB = size of the REMOVE-LARGER file, 0 for KEEP (got ' + $rl.SpaceSavedMB + ')')
    Check ((& $nA 'NearB\Lesson.mp4') -eq 'KEEP' -and (& $nA 'NearB\Lesson 720p.mp4') -eq 'REVIEW' -and @(& $getN 'NearB\Lesson 720p.mp4')[0].Reason -like '*hevc*H.264*') 'guard: smaller HEVC vs larger H.264 -> larger H.264 kept, HEVC REVIEW with reason'
    Check ((& $nA 'NearC\Talk.mp4') -eq 'KEEP' -and (& $nA 'NearC\Talk HD.mp4') -eq 'REMOVE-LARGER') 'same resolution and codec: smaller (lower bitrate) kept; HD tag ignored'
    $eg = @(& $getN 'NearF\Ancient Egypt 4.mp4')[0]
    Check ($eg.Action -eq 'KEEP' -and $eg.Reason -like '*NOTE: low bitrate*' -and (& $nA 'NearF\Ancient Egypt #4.mp4') -eq 'REMOVE-LARGER') 'Egypt #4 case: 1080p H.264 331 kbps (smaller) kept with a low-bitrate note; 1910 kbps HEVC REMOVE-LARGER'
    Check ((& $nA 'NearG\Song HQ.mp4') -eq 'KEEP' -and (& $nA 'NearG\Song.mp4') -eq 'REVIEW' -and @(& $getN 'NearG\Song.mp4')[0].Reason -like '*150 kbps*') 'guard: smaller copy under 150 kbps at 480p -> larger kept, small REVIEW'
    Check ((& $nA 'NearH\Tiny SD.mp4') -eq 'KEEP' -and (& $nA 'NearH\Tiny.mp4') -eq 'REVIEW' -and @(& $getN 'NearH\Tiny.mp4')[0].Reason -like '*below 240*') 'guard: smaller copy below 240 lines -> larger kept, small REVIEW'
    Check ((& $nA 'NearI\Doc 4K.mp4') -eq 'KEEP' -and (& $nA 'NearI\Doc.mp4') -eq 'REMOVE-LARGER' -and (& $nA 'NearI\Doc HD.mp4') -eq 'REMOVE-LARGER' -and (& $nA 'NearI\Doc SD.mp4') -eq 'REMOVE-LARGER') 'four copies: smallest kept, the other three REMOVE-LARGER (4K/HD/SD tags ignored)'
    $gx = [pscustomobject]@{ Probed = $true; Dur = 405.7; Ht = 720; Codec = 'h264'; Kbps = 1000 }
    Check ((Get-UnusableCode $gx 400.0 2) -eq 'duration' -and (Get-UnusableCode ([pscustomobject]@{ Probed = $true; Dur = 400.0; Ht = 720; Codec = 'h264'; Kbps = 1000 }) 400.0 2) -eq '') 'guard: a copy more than 2 s off the group duration is flagged (duration check)'
    Check ((& $nA 'NearJ\Lost HD.mp4') -eq 'KEEP' -and (& $nA 'NearJ\Lost.mp4') -eq 'REVIEW' -and @(& $getN 'NearJ\Lost.mp4')[0].Reason -like '*could not read*') 'guard: smaller copy ffprobe failed -> larger kept, failed copy REVIEW'
    Check (@(& $getN 'NearD\Clip.mp4').Count -eq 0 -and @(& $getN 'NearD\Clip (480p).mp4').Count -eq 0) 'near-dup: durations 10 s apart are not grouped'
    Check (@($res.Near | Where-Object { $_.Path -like '*\NearE\*' }).Count -eq 0) 'near-dup: byte-identical copies are left to the exact pass'
    $nearCount = $res.Near.Length
    Check (@($res.Near | Where-Object { $_.Action -eq 'QUARANTINE' }).Count -eq 0 -and $nearCount -eq 18) ('near-dup pass never marks QUARANTINE (rows: ' + $nearCount + ', expected 18)')
    Check (@(& $getN 'CatC\same.mp4').Count -eq 0) 'same name, different duration is not a near-duplicate'
    Check ((Test-Path -LiteralPath $res.Csv3)) 'near-duplicates CSV written'
    Check (-not (Select-String -LiteralPath $res.Txt -SimpleMatch 'System.Object[]' -Quiet) -and (Select-String -LiteralPath $res.Txt -Pattern '^  CatA\s+\d' -Quiet)) 'summary BY CATEGORY lists real category names'
    $hdr = (Get-Content -LiteralPath $res.Csv -TotalCount 1)
    Check ($hdr -match 'Width' -and $hdr -match 'Height' -and $hdr -match 'Codec' -and $hdr -match 'BitrateKbps' -and $hdr -match 'DurationSec' -and $hdr -match 'SpaceSavedMB') 'marks CSV has the video columns and SpaceSavedMB'
    $qx = @($res.Report | Where-Object { $_.SetId -like 'D*' -and $_.Action -eq 'QUARANTINE' } | Select-Object -First 1)
    Check ($qx.Length -eq 1 -and [double]$qx[0].SpaceSavedMB -eq [double]$qx[0].SizeMB) 'exact-duplicate QUARANTINE rows carry SpaceSavedMB = SizeMB'
    # -KeepHigherQuality restores the r2 rule
    $resH = Invoke-Analysis -Root $lib -Rows $rows -CDir $cd -ODir $od -Stamp 'selftestHQ' -UseCache $true -Seed '' -SeedBefore ([datetime]'2000-01-01') -Near $true -Prober $fakeProber -NearSec 2 -KeepHQ $true
    $hA = { param($rel) $x = @($resH.Near | Where-Object { $_.Path -eq (Join-Path $lib $rel) }); if ($x.Length -eq 1) { return [string]$x[0].Action } else { return ('rows=' + $x.Length) } }
    Check ((& $hA 'NearA\Crash Course 1 #1.mp4') -eq 'KEEP' -and (& $hA 'NearA\Crash Course 1 1.mp4') -eq 'REVIEW-LOWER-QUALITY') '-KeepHigherQuality: 1080p kept, 480p REVIEW-LOWER-QUALITY'
    Check ((& $hA 'NearB\Lesson.mp4') -eq 'KEEP' -and (& $hA 'NearB\Lesson 720p.mp4') -eq 'REVIEW-LOWER-QUALITY') '-KeepHigherQuality: resolution tie -> H.264 kept over HEVC'
    Check ((& $hA 'NearC\Talk HD.mp4') -eq 'KEEP' -and (& $hA 'NearC\Talk.mp4') -eq 'REVIEW-LOWER-QUALITY') '-KeepHigherQuality: resolution and codec tie -> higher bitrate kept'
    Check (@($resH.Near | Where-Object { $_.Action -eq 'QUARANTINE' -or $_.Action -eq 'REMOVE-LARGER' }).Count -eq 0) '-KeepHigherQuality: no QUARANTINE or REMOVE-LARGER rows'
    Check ($resH.Report.Count -eq $res.Report.Count -and @($resH.Report | Where-Object { $_.SetId -like 'D*' }).Count -eq @($res.Report | Where-Object { $_.SetId -like 'D*' }).Count) '-KeepHigherQuality leaves the exact-duplicate marks unchanged'
    $res2 = Invoke-Analysis -Root $lib -Rows $rows -CDir $cd -ODir $od -Stamp 'selftest2' -UseCache $true -Seed '' -SeedBefore ([datetime]'2000-01-01') -Near $true -Prober $fakeProber -NearSec 2
    Check ($res2.Stats.FullComputed -eq 0 -and $res2.Stats.QuickComputed -eq 0 -and $res2.Stats.FromCache -gt 0) ('-Resume reuses the cache (computed again: ' + $res2.Stats.FullComputed + ')')
    Check ($res2.Stats.ProbeComputed -eq 1 -and $res2.Stats.ProbeFromCache -gt 0) ('-Resume reuses the probe cache; only the 1 file ffprobe failed on is retried (probed again: ' + $res2.Stats.ProbeComputed + ')')
    Check ($res2.Report.Count -eq $res.Report.Count) 'resume run gives the same marks'
    $res3 = Invoke-Analysis -Root $lib -Rows $rows -CDir $cd -ODir $od -Stamp 'selftest3' -UseCache $true -Seed '' -SeedBefore ([datetime]'2000-01-01') -Scope 'History' -Near $true -Prober $fakeProber -NearSec 2
    Check ($res3.Report.Count -gt 0 -and @($res3.Report | Where-Object { $_.Path -notlike (Join-Path $lib 'History\*') }).Count -eq 0) '-Only History limits the scan to History'
  } finally {
    if ($script:CacheW) { $script:CacheW.Dispose(); $script:CacheW = $null }
    if ($script:ProbeW) { $script:ProbeW.Dispose(); $script:ProbeW = $null }
    if ($base.StartsWith($env:TEMP) -and (Test-Path -LiteralPath $base)) { Remove-Item -LiteralPath $base -Recurse -Force }
  }
  if ($script:stFails -eq 0) { Write-Host 'SELFTEST PASSED' -ForegroundColor Green; exit 0 } else { Write-Host "SELFTEST FAILED ($($script:stFails))" -ForegroundColor Red; exit 1 }
}

if ($SelfTest) { Invoke-SelfTest }

if (-not $OutDir) { $OutDir = [Environment]::GetFolderPath('Desktop') }
if (-not $SeedFile -and -not $NoSeed) { $sf = Join-Path $env:TEMP 'edutek-survey-20261022\fullhash.csv'; if (Test-Path -LiteralPath $sf) { $SeedFile = $sf } }
if ($NoSeed) { $SeedFile = '' }
if (-not (Test-Path -LiteralPath $CacheDir)) { [void](New-Item -ItemType Directory -Path $CacheDir -Force) }
$script:ProgressFile = Join-Path $CacheDir 'progress.txt'
Say 'Reading catalog (read-only SELECT) ...'
$rows = Get-CatalogRows
$scopeTag = 'library'; if ($Only) { $scopeTag = ($Only.Trim('\') -replace '[\\/:*?"<>|]', '_') }
$stamp = 'r3 ' + $scopeTag + ' ' + (Get-Date).ToString('yyyy-MM-dd HHmm')
$prb = { param($l) Invoke-DockerProbe $l }
try {
  [void](Invoke-Analysis -Root $LibraryRoot -Rows $rows -CDir $CacheDir -ODir $OutDir -Stamp $stamp -UseCache ([bool]$Resume) -Seed $SeedFile -SeedBefore $SeedValidBefore -Scope $Only.Trim('\') -Near ([bool]$NearDuplicates) -Prober $prb -NearSec $NearDupSeconds -KeepHQ ([bool]$KeepHigherQuality))
} catch {
  Say ('FAILED: ' + $_.Exception.Message + '  (re-run with -Resume to continue from the cache)')
  throw
} finally { if ($script:CacheW) { $script:CacheW.Dispose(); $script:CacheW = $null }; if ($script:ProbeW) { $script:ProbeW.Dispose(); $script:ProbeW = $null } }
