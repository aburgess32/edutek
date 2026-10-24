<#
Fix-LibraryFaststart.ps1  -  Edutek library: move the moov atom in front of the media data ("faststart") in MP4/M4V/MOV
files that have it at the end, losslessly. Windows PowerShell 5.1, StrictMode 2.0.

Default: CHECK ONLY. Reads the top-level atoms of every .mp4/.m4v/.mov under the library and lists the files whose moov
comes after mdat (counts and GB by category, report CSV on the Desktop). Nothing is changed.
  -Apply                     fixes every listed file, one at a time (see below)
  -Apply -Limit N            fixes only N files: a test batch, picked round-robin across categories, smallest first
  -Continue latest|<stamp>   finishes an interrupted or limited run (power cut, window closed, Ctrl+C); -Limit also allowed
  -Rollback latest|<stamp>   puts every original of that run back after a hash check (fixed copies go to quarantine)
  -SelfTest                  runs everything on throwaway copies (generated clips plus copies of 2 real library files)
  -NoHttp                    skip the HTTP range checks through the app

Per file (-Apply): the file must be unchanged since the scan, not being transcoded, and still have moov after mdat.
 1. a hard link to it is made in the work folder (same drive, ASCII name; the container never sees the real file name)
 2. SHA256 of the original
 3. in edupak-app: ffprobe -count_packets (original), ffmpeg -map 0 -c copy -map_metadata 0 -movflags +faststart into
    a temp file in the work folder, ffprobe -count_packets (temp), decode of the first and last 5 s of the temp
 4. the temp must have: moov before mdat; duration within 0.1 s; same stream count, codec types and names, sizes,
    sample rates, channels, languages and packet counts per stream; every container tag of the original; size within 5%
 5. the temp gets the original's modified time; SHA256 of the temp
 6. swap (one NTFS ReplaceFile call): the original moves to D:\edutek-system\quarantine\faststart-<stamp>\<same path>,
    the temp takes its place under the same name
 7. after the swap: size, modified time and atom order re-checked; HTTP range requests (start and end of the file)
    through the app must return 206 when they did for the original; if not, the swap is undone at once
A file that fails any check is skipped and logged; its original is never touched.
Manifest and journal: D:\edutek-system\backups\faststart\<stamp>\ (plan.tsv, journal.tsv, run.log, state.txt, report CSV)
Temp/work folder: D:\edutek-system\index-status\faststart-work (mounted in edupak-app as /system/index-status/faststart-work)
Partial indexer: never asked to index. Before every swap the script waits while index requests are queued or a catalog
cleanup is running in edupak-app (up to -IndexerWaitMin minutes, then it stops; resume with -Continue latest).
Catalog: content_meta stores paths only (no size, hash or mtime); paths do not change, so no catalog row is touched.
Never deletes a library file, never runs an index, never touches covers or other quarantines.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [int]$Limit = 0,
  [string]$Continue = '',
  [string]$Rollback = '',
  [switch]$SelfTest,
  [switch]$NoHttp,
  [switch]$KeepWork,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$BackupRoot = 'D:\edutek-system\backups\faststart',
  [string]$QuarantineRoot = 'D:\edutek-system\quarantine',
  [string]$WorkRoot = 'D:\edutek-system\index-status\faststart-work',
  [string]$ContainerWorkRoot = '/system/index-status/faststart-work',
  [string]$ReportDir = '',
  [string]$BaseUrl = 'http://localhost:8080',
  [string]$Container = 'edupak-app',
  [string]$DockerContext = 'desktop-linux',
  [string]$DockerExe = '',
  [string]$TriggerDir = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger',
  [int]$HeartbeatMaxAgeSec = 90,
  [int]$IndexerWaitMin = 30,
  [double]$MinFreeGB = 20,
  [double]$DurationTolSec = 0.1,
  [string]$FailAt = ''
)
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$OutputEncoding = $utf8
$VideoExt = @('.mp4', '.m4v', '.mov')
if (-not $ReportDir) { $ReportDir = [Environment]::GetFolderPath('Desktop') }
if (-not $DockerExe) { $DockerExe = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'; if (-not (Test-Path -LiteralPath $DockerExe)) { $DockerExe = 'docker.exe' } }
$script:Dk = $DockerExe
$script:Lib = $Library.TrimEnd('\'); $script:BRoot = $BackupRoot.TrimEnd('\'); $script:QRoot = $QuarantineRoot.TrimEnd('\')
$script:Work = $WorkRoot.TrimEnd('\'); $script:CWork = $ContainerWorkRoot.TrimEnd('/'); $script:Trig = $TriggerDir
$script:Heartbeat = Join-Path $TriggerDir 'indexer-heartbeat.txt'; $script:Sim = $false; $script:FailAt = $FailAt; $script:Log = ''
$script:IndexerWaitSec = [Math]::Max(0, $IndexerWaitMin) * 60; $script:Report = $ReportDir; $script:NoHttp = $NoHttp.IsPresent
$script:Catalog = $true; $script:Quiet = $false
$script:Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
if (-not (Test-Path -LiteralPath $script:Curl)) { $script:Curl = 'curl.exe' }
$TagIgnore = @('major_brand', 'minor_version', 'compatible_brands', 'encoder')

if (-not ('EdutekFsNative1' -as [type])) {
  Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class EdutekFsNative1 {
  [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool CreateHardLink(string lpFileName, string lpExistingFileName, IntPtr lpSecurityAttributes);
}
'@
}

# ---------------------------------------------------------------- small helpers
function Say([string]$m) { if (-not $script:Quiet) { Write-Host $m }; if ($script:Log) { try { [IO.File]::AppendAllText($script:Log, $m + "`r`n", $utf8) } catch { } } }
function Fmt-GB([double]$b) { return ('{0:N2} GB' -f ($b / 1GB)) }
function Fmt-MB([double]$b) { return ('{0:N1} MB' -f ($b / 1MB)) }
function Fmt-Span([double]$sec) {
  if ($sec -lt 0 -or [double]::IsNaN($sec) -or [double]::IsInfinity($sec)) { return '?' }
  $ts = [TimeSpan]::FromSeconds([Math]::Round($sec))
  if ($ts.TotalHours -ge 1) { return ('{0}h {1:00}m' -f [int][Math]::Floor($ts.TotalHours), $ts.Minutes) }
  return ('{0}m {1:00}s' -f $ts.Minutes, $ts.Seconds)
}
function LongP([string]$p) { if ($p.StartsWith('\\?\')) { return $p }; return ('\\?\' + $p) }
function Get-Prop($o, [string]$n) { if ($null -eq $o) { return $null }; $pp = $o.PSObject.Properties[$n]; if ($pp) { return $pp.Value }; return $null }
function Test-FailAt([string]$at) { return ($script:FailAt -and (@($script:FailAt -split ',') -contains $at)) }
function Check-Fail([string]$at) { if (Test-FailAt $at) { throw "SIMULATED CRASH at $at" } }
function Get-Top([string]$rel) { $i = $rel.IndexOf('\'); if ($i -lt 0) { return '(library root)' }; return $rel.Substring(0, $i) }
function Clean([string]$s) { if ($null -eq $s) { return '' }; return (($s -replace "[`t`r`n]+", ' ').Trim()) }
function Tail-Text([string]$s, [int]$n) { $s = Clean $s; if ($s.Length -gt $n) { return ('...' + $s.Substring($s.Length - $n)) }; return $s }
function Read-Text([string]$p) { if (Test-Path -LiteralPath $p) { return [IO.File]::ReadAllText($p, $utf8) }; return '' }
function Read-Rc([string]$p) { $t = (Read-Text $p).Trim(); if ($t -match '^-?\d+$') { return [int]$t }; return -999 }
function Get-ParentL([string]$p) { $i = $p.TrimEnd('\').LastIndexOf('\'); if ($i -lt 0) { return $p }; return $p.Substring(0, $i) }
function Test-FileL([string]$p) { return [IO.File]::Exists((LongP $p)) }
function Get-FileL([string]$p) { return (New-Object IO.FileInfo((LongP $p))) }
function Ensure-Dir([string]$p) { [void][IO.Directory]::CreateDirectory((LongP $p)) }
function Get-Free([string]$p) { return [int64](New-Object IO.DriveInfo([IO.Path]::GetPathRoot($p))).AvailableFreeSpace }
function New-Stamp([string]$root) {
  while ($true) { $s = Get-Date -Format 'yyyyMMdd-HHmmss'; if (-not (Test-Path -LiteralPath (Join-Path $root $s))) { return $s }; Start-Sleep -Milliseconds 1100 }
}
function Get-Fmt([string]$ext) { if ($ext -eq '.mov') { return 'mov' }; return 'mp4' }

function Invoke-Docker([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Dk --context $DockerContext @a 2>&1 | ForEach-Object { "$_" }); $c = $LASTEXITCODE } finally { $ErrorActionPreference = $old }
  return [pscustomobject]@{ code = $c; out = (@($o) -join "`n") }
}
function Invoke-Curl([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $script:Curl @a 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  return (@($o) -join "`n").Trim()
}

# ---------------------------------------------------------------- top-level atom reader (moov / mdat order)
function Read-Full($fs, [byte[]]$buf, [int]$cnt) {
  $got = 0
  while ($got -lt $cnt) { $r = $fs.Read($buf, $got, $cnt - $got); if ($r -le 0) { break }; $got += $r }
  return $got
}
function Get-Atoms([string]$path) {
  $res = [ordered]@{ status = ''; moov = [int64]-1; mdat = [int64]-1; moof = [int64]-1; first = ''; note = '' }
  $fs = $null
  try {
    $fs = New-Object IO.FileStream((LongP $path), [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete), 4096, [IO.FileOptions]::RandomAccess)
    $len = [int64]$fs.Length; $pos = [int64]0; $buf = New-Object byte[] 16; $n = 0; $bad = ''
    while ($pos -le ($len - 8) -and $n -lt 10000) {
      $n++
      [void]$fs.Seek($pos, [IO.SeekOrigin]::Begin)
      if ((Read-Full $fs $buf 8) -lt 8) { $bad = 'short read'; break }
      $sz = ([int64]$buf[0] * 16777216) + ([int64]$buf[1] * 65536) + ([int64]$buf[2] * 256) + [int64]$buf[3]
      $ok = $true; for ($k = 4; $k -lt 8; $k++) { $c = $buf[$k]; if (-not (($c -ge 32 -and $c -le 126) -or $c -eq 169)) { $ok = $false } }
      if (-not $ok) { $bad = ('unreadable atom header at byte ' + $pos); break }
      $typ = [Text.Encoding]::GetEncoding(28591).GetString($buf, 4, 4)
      $hdr = 8
      if ($sz -eq 1) {
        if ((Read-Full $fs $buf 8) -lt 8) { $bad = 'short read'; break }
        $hi = ([int64]$buf[0] * 16777216) + ([int64]$buf[1] * 65536) + ([int64]$buf[2] * 256) + [int64]$buf[3]
        $lo = ([int64]$buf[4] * 16777216) + ([int64]$buf[5] * 65536) + ([int64]$buf[6] * 256) + [int64]$buf[7]
        if ($hi -gt 2147483647) { $bad = 'atom too large'; break }
        $sz = $hi * 4294967296 + $lo; $hdr = 16
      } elseif ($sz -eq 0) { $sz = $len - $pos }
      if ($sz -lt $hdr) { $bad = ('bad atom size at byte ' + $pos); break }
      if ($n -eq 1) { $res.first = $typ }
      if ($typ -eq 'moov' -and $res.moov -lt 0) { $res.moov = $pos }
      if ($typ -eq 'mdat' -and $res.mdat -lt 0) { $res.mdat = $pos }
      if ($typ -eq 'moof' -and $res.moof -lt 0) { $res.moof = $pos }
      if ($res.moov -ge 0 -and $res.mdat -ge 0) { break }
      if ($pos + $sz -gt $len) { $bad = ("atom '" + $typ + "' runs past the end of the file (truncated)"); break }
      $pos += $sz
    }
    if ($res.first -and @('ftyp', 'moov', 'mdat', 'wide', 'free', 'skip', 'pnot', 'uuid', 'junk', 'PICT') -notcontains $res.first) { $res.status = 'NOT-MP4'; $res.note = ("first atom '" + $res.first + "'") }
    elseif (-not $res.first) { $res.status = 'NOT-MP4'; $res.note = $(if ($bad) { $bad } else { 'empty or too small' }) }
    elseif ($res.moov -ge 0 -and $res.mdat -ge 0) { if ($res.moov -lt $res.mdat) { $res.status = 'OK' } else { $res.status = 'NEEDS-FIX' } }
    elseif ($res.moov -ge 0) { $res.status = 'OK'; $res.note = $(if ($res.moof -ge 0) { 'fragmented' } else { 'no mdat' }) }
    else { $res.status = 'BROKEN'; $res.note = ('no moov atom' + $(if ($bad) { ' (' + $bad + ')' } else { '' })) }
  } catch { $res.status = 'UNREADABLE'; $res.note = Clean $_.Exception.Message }
  finally { if ($fs) { $fs.Dispose() } }
  return [pscustomobject]$res
}
function Get-Sha([string]$p) {
  $fs = New-Object IO.FileStream((LongP $p), [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read, 1048576, [IO.FileOptions]::SequentialScan)
  try { $h = [Security.Cryptography.SHA256]::Create(); try { return ([BitConverter]::ToString($h.ComputeHash($fs)) -replace '-', '') } finally { $h.Dispose() } } finally { $fs.Dispose() }
}
function New-HardLink([string]$link, [string]$target) {
  if (-not [EdutekFsNative1]::CreateHardLink((LongP $link), (LongP $target), [IntPtr]::Zero)) {
    $err = [Runtime.InteropServices.Marshal]::GetLastWin32Error(); throw ("could not make the work hard link (Win32 error " + $err + ")")
  }
}

# ---------------------------------------------------------------- catalog (read-only: which files have a content_meta row)
$CatalogPhp = @'
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
try {
    require_once '/var/www/html/includes/config.php';
    require_once '/var/www/html/includes/auth.php';
    $pdo = getDbConnection();
    $rows = $pdo->query('SELECT file_path FROM content_meta')->fetchAll(PDO::FETCH_COLUMN);
    echo "\nCAT:" . base64_encode(implode("\n", $rows)) . "\n";
} catch (Throwable $e) { echo "\nERR:" . base64_encode($e->getMessage()) . "\n"; }
'@
function Get-CatalogKeys {
  $map = New-Object System.Collections.Hashtable ([StringComparer]::OrdinalIgnoreCase)
  if ($script:Sim -or -not $script:Catalog) { return $map }
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($CatalogPhp | & $script:Dk --context $DockerContext exec -i $Container php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  $line = @($o | Where-Object { $_.StartsWith('CAT:') -or $_.StartsWith('ERR:') }) | Select-Object -Last 1
  if (-not $line) { Say '  note: could not read the catalog (no answer from edupak-app); the In catalog column is left empty'; return $null }
  $txt = $utf8.GetString([Convert]::FromBase64String($line.Substring(4)))
  if ($line.StartsWith('ERR:')) { Say ('  note: could not read the catalog: ' + $txt); return $null }
  foreach ($fp in ($txt -split "`n")) {
    $k = $fp.Replace('\', '/').TrimStart('/')
    if ($k.ToLowerInvariant().StartsWith('content/')) { $map[$k.Substring(8).Replace('/', '\')] = 1 }
  }
  return $map
}

# ---------------------------------------------------------------- HTTP (read-only ranged GETs through the app)
$script:Jar = ''
function Get-Jar {
  if (-not $script:Jar) {
    $script:Jar = Join-Path $env:TEMP ('fs-jar-' + [guid]::NewGuid().ToString('N') + '.txt')
    [void](Invoke-Curl @('-s', '-g', '-o', 'NUL', '-c', $script:Jar, '-b', $script:Jar, "$BaseUrl/guest.php"))
  }
  return $script:Jar
}
function Encode-Path([string]$p) { return ((@($p -split '/') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/') }
function Test-Http([string]$rel, [int64]$size) {
  # GET bytes 0-1023 and the last 1 KB of content/<rel>: both must be 206 and not an HTML page
  if ($script:Sim -or $script:NoHttp) {
    $why = $(if ($script:Sim) { 'simulated' } else { 'skipped (-NoHttp)' })
    return [pscustomobject]@{ ok = (Test-FileL (Join-Path $script:Lib $rel)); info = $why; skipped = $true }
  }
  $jar = Get-Jar
  $url = "$BaseUrl/" + (Encode-Path ('content/' + $rel.Replace('\', '/')))
  $r1 = Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-r', '0-1023', '-w', '%{http_code}|%{content_type}', $url)
  $r2 = 'n/a'
  if ($size -gt 4096) { $r2 = Invoke-Curl @('-s', '-g', '-b', $jar, '-o', 'NUL', '-r', (([string]($size - 1024)) + '-' + ([string]($size - 1))), '-w', '%{http_code}|%{content_type}', $url) }
  $ok = ($r1 -match '^206\|' -and $r1 -notmatch '\|text/html' -and ($r2 -eq 'n/a' -or ($r2 -match '^206\|' -and $r2 -notmatch '\|text/html')))
  return [pscustomobject]@{ ok = $ok; info = ('start ' + $r1 + ', end ' + $r2); skipped = $false }
}

# ---------------------------------------------------------------- partial indexer guard (never asks it to index)
function Get-HeartbeatInfo {
  if (-not (Test-Path -LiteralPath $script:Heartbeat)) { return [pscustomobject]@{ fresh = $false; text = "no heartbeat file ($script:Heartbeat)" } }
  $age = ((Get-Date) - (Get-Item -LiteralPath $script:Heartbeat).LastWriteTime).TotalSeconds
  if ($age -gt $HeartbeatMaxAgeSec) { return [pscustomobject]@{ fresh = $false; text = ('heartbeat ' + [int]$age + ' s old: the partial indexer is not running, so it cannot interfere') } }
  return [pscustomobject]@{ fresh = $true; text = ('heartbeat ' + [int]$age + ' s old: the partial indexer is running; the script pauses whenever it has work') }
}
function Get-IndexerBusy {
  $why = @()
  if (Test-Path -LiteralPath $script:Trig) {
    $req = @(Get-ChildItem -LiteralPath $script:Trig -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
    if ($req.Count -gt 0) { $why += ([string]$req.Count + ' queued index request(s): ' + ((@($req | Select-Object -First 3 | ForEach-Object { $_.Name })) -join ', ')) }
  }
  if (-not $script:Sim) {
    $r = Invoke-Docker @('exec', $Container, 'sh', '-c', 'test -e /tmp/edutek-catalog-cleanup.php && echo BUSY || echo IDLE')
    if ($r.out -match 'BUSY') { $why += 'a catalog cleanup is running in edupak-app' }
  }
  return ($why -join '; ')
}
function Wait-Indexer([int]$maxSec) {
  $t0 = Get-Date; $said = $false
  while ($true) {
    $b = Get-IndexerBusy
    if (-not $b) { if ($said) { Say '  indexer idle again, continuing' }; return '' }
    if (((Get-Date) - $t0).TotalSeconds -ge $maxSec) { return $b }
    if (-not $said) { Say ('  waiting for the partial indexer: ' + $b); $said = $true }
    Start-Sleep -Seconds $(if ($maxSec -lt 20) { 1 } else { 5 })
  }
}

# ---------------------------------------------------------------- scan
function Get-Scan([string]$lib, $catalog) {
  $list = New-Object System.Collections.Generic.List[object]
  $root = LongP $lib; $cut = $root.Length + 1; $i = 0; $sw = [Diagnostics.Stopwatch]::StartNew()
  foreach ($f in [IO.Directory]::EnumerateFiles($root, '*', [IO.SearchOption]::AllDirectories)) {
    $ext = [IO.Path]::GetExtension($f).ToLowerInvariant()
    if ($VideoExt -notcontains $ext) { continue }
    $i++
    if (($i % 500) -eq 0 -and -not $script:Quiet) { Write-Progress -Activity 'Reading atoms' -Status ("$i video files, " + [int]$sw.Elapsed.TotalSeconds + ' s') }
    $rel = $f.Substring($cut); $fi = New-Object IO.FileInfo($f)
    $a = Get-Atoms $f.Substring(4)
    $st = $a.status; $note = $a.note
    if ($st -eq 'NEEDS-FIX' -and [IO.File]::Exists($f + '.transcoding')) { $st = 'BUSY'; $note = 'a transcode is writing this file (.transcoding lock)' }
    $inCat = ''
    if ($null -ne $catalog) { $inCat = $(if ($catalog.ContainsKey($rel)) { 'yes' } else { 'no' }) }
    $list.Add([pscustomobject]@{ rel = $rel; cat = (Get-Top $rel); ext = $ext; size = [int64]$fi.Length; mtime = [int64]$fi.LastWriteTimeUtc.Ticks; status = $st; moov = [int64]$a.moov; mdat = [int64]$a.mdat; note = $note; incat = $inCat })
  }
  if (-not $script:Quiet) { Write-Progress -Activity 'Reading atoms' -Completed }
  return $list
}
function Write-ScanReport($scan, [string]$path) {
  $rows = foreach ($s in $scan) {
    [pscustomobject][ordered]@{ Status = $s.status; Category = $s.cat; 'Relative path' = $s.rel; Ext = $s.ext; 'Size MB' = [Math]::Round($s.size / 1MB, 1)
      'moov at byte' = $s.moov; 'mdat at byte' = $s.mdat; 'In catalog' = $s.incat; Modified = ([DateTime]::new($s.mtime, [DateTimeKind]::Utc).ToLocalTime().ToString('yyyy-MM-dd HH:mm')); Note = $s.note }
  }
  $order = @{ 'NEEDS-FIX' = 0; 'BUSY' = 1; 'BROKEN' = 2; 'UNREADABLE' = 3; 'NOT-MP4' = 4; 'OK' = 5 }
  @($rows | Sort-Object @{ e = { $order[$_.Status] } }, Category, 'Relative path') | Export-Csv -LiteralPath $path -NoTypeInformation -Encoding UTF8
}
function Get-Throughput {
  $f = @(Get-ChildItem -LiteralPath $script:BRoot -Filter 'throughput*.json' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1 | ForEach-Object { $_.FullName })[0]
  if ($f) { try { $j = (Read-Text $f) | ConvertFrom-Json; return [pscustomobject]@{ overhead = [double]$j.overhead_sec; mbps = [double]$j.mb_per_sec; src = ('measured by -SelfTest ' + $j.measured) } } catch { } }
  return [pscustomobject]@{ overhead = 6.0; mbps = 60.0; src = 'default guess (run -SelfTest to measure)' }
}
function Get-Eta([int]$n, [int64]$bytes) { $t = Get-Throughput; return [pscustomobject]@{ sec = ($n * $t.overhead + ($bytes / 1MB) / $t.mbps); src = $t.src; mbps = $t.mbps; overhead = $t.overhead } }
function Show-ScanSummary($scan) {
  $need = @($scan | Where-Object { $_.status -eq 'NEEDS-FIX' })
  $all = @($scan).Count; $allB = [int64]0; foreach ($s in $scan) { $allB += $s.size }
  $needB = [int64]0; $maxB = [int64]0; foreach ($s in $need) { $needB += $s.size; if ($s.size -gt $maxB) { $maxB = $s.size } }
  Say ''
  Say ("Video files read (.mp4 .m4v .mov): {0:N0}  ({1})" -f $all, (Fmt-GB $allB))
  foreach ($g in @($scan | Group-Object status | Sort-Object Name)) { $b = [int64]0; foreach ($x in $g.Group) { $b += $x.size }; Say ("  {0,-11} {1,7:N0}  {2,12}" -f $g.Name, $g.Count, (Fmt-GB $b)) }
  $pct = 0; if ($all -gt 0) { $pct = 100.0 * $need.Count / $all }
  Say ("moov after mdat (NEEDS-FIX): {0:N0} files = {1:N1}% of video files, {2}" -f $need.Count, $pct, (Fmt-GB $needB))
  if ($need.Count -gt 0) {
    Say ''; Say ("  {0,-42} {1,7} {2,12} {3,10}" -f 'Category', 'Files', 'Size', 'In catalog')
    foreach ($g in @($need | Group-Object cat | Sort-Object @{ e = { $_.Count } } -Descending)) {
      $b = [int64]0; $c = 0; foreach ($x in $g.Group) { $b += $x.size; if ($x.incat -eq 'yes') { $c++ } }
      Say ("  {0,-42} {1,7:N0} {2,12} {3,10:N0}" -f $g.Name, $g.Count, (Fmt-GB $b), $c)
    }
    foreach ($g in @($need | Group-Object ext | Sort-Object Name)) { $b = [int64]0; foreach ($x in $g.Group) { $b += $x.size }; Say ("  by type {0}: {1:N0} files, {2}" -f $g.Name, $g.Count, (Fmt-GB $b)) }
    $eta = Get-Eta $need.Count $needB
    Say ''
    Say ("Largest file {0}; temp space needed at any moment: about that size. Free on {1} {2} (minimum kept free {3} GB)." -f (Fmt-GB $maxB), ([IO.Path]::GetPathRoot($script:Lib)), (Fmt-GB (Get-Free $script:Lib)), $MinFreeGB)
    Say ("Estimated time for -Apply on all: {0}  ({1}: {2:N0} MB/s plus {3:N1} s per file)" -f (Fmt-Span $eta.sec), $eta.src, $eta.mbps, $eta.overhead)
  }
}

# ---------------------------------------------------------------- container job (ASCII work paths only)
$JobSh = @'
#!/bin/sh
# args: <work dir> <ext without dot> <muxer> <full|remux> <movflags>
d="$1"; e="$2"; f="$3"; m="$4"; mf="$5"
cd "$d" || exit 90
if [ "$m" = full ]; then
  ffprobe -v error -count_packets -show_format -show_streams -of json "src.$e" > orig.json 2> orig.err; echo $? > orig.rc
  ffmpeg -nostdin -hide_banner -v error -threads 1 -i "src.$e" -map '0:v:0?' -map '0:a:0?' -t 5 -f null - 2> sdec1.err; echo $? > sdec1.rc
  ffmpeg -nostdin -hide_banner -v error -threads 1 -sseof -5 -i "src.$e" -map '0:v:0?' -map '0:a:0?' -f null - 2> sdec2.err; echo $? > sdec2.rc
fi
rm -f "out.$e"
ffmpeg -nostdin -hide_banner -v error -threads 1 -i "src.$e" -map 0 -c copy -map_metadata 0 -map_chapters 0 -movflags "$mf" -f "$f" -y "out.$e" 2> ff.err; echo $? > ff.rc
ffprobe -v error -count_packets -show_format -show_streams -of json "out.$e" > out.json 2> out.err; echo $? > out.rc
ffmpeg -nostdin -hide_banner -v error -threads 1 -i "out.$e" -map '0:v:0?' -map '0:a:0?' -t 5 -f null - 2> dec1.err; echo $? > dec1.rc
ffmpeg -nostdin -hide_banner -v error -threads 1 -sseof -5 -i "out.$e" -map '0:v:0?' -map '0:a:0?' -f null - 2> dec2.err; echo $? > dec2.rc
exit 0
'@
function Write-Lf([string]$path, [string]$text) { [IO.File]::WriteAllText($path, ($text -replace "`r`n", "`n"), $utf8) }
function Invoke-Job([string]$runDirName, [string]$id, [string]$ext, [string]$mode, [string]$flags) {
  $cdir = $script:CWork + '/' + $runDirName + '/' + $id
  return (Invoke-Docker @('exec', $Container, 'sh', ($script:CWork + '/' + $runDirName + '/job.sh'), $cdir, $ext.TrimStart('.'), (Get-Fmt $ext), $mode, $flags))
}
function Test-Remux([string]$wd, [string]$ext, [int64]$origSize) {
  $e = $ext.TrimStart('.')
  $r = [ordered]@{ ok = $false; reason = ''; tags = $false; note = '' }
  if ((Read-Rc (Join-Path $wd 'orig.rc')) -ne 0) { $r.reason = 'ffprobe cannot read the original: ' + (Tail-Text (Read-Text (Join-Path $wd 'orig.err')) 300); return [pscustomobject]$r }
  if ((Read-Rc (Join-Path $wd 'ff.rc')) -ne 0) { $r.reason = 'ffmpeg stream copy failed: ' + (Tail-Text (Read-Text (Join-Path $wd 'ff.err')) 300); return [pscustomobject]$r }
  $outF = Join-Path $wd ('out.' + $e)
  if (-not (Test-FileL $outF)) { $r.reason = 'ffmpeg wrote no temp file'; return [pscustomobject]$r }
  if ((Read-Rc (Join-Path $wd 'out.rc')) -ne 0) { $r.reason = 'ffprobe cannot read the temp file: ' + (Tail-Text (Read-Text (Join-Path $wd 'out.err')) 300); return [pscustomobject]$r }
  $a = Get-Atoms $outF
  if ($a.status -ne 'OK' -or $a.moov -lt 0 -or ($a.mdat -ge 0 -and $a.moov -gt $a.mdat)) { $r.reason = ('temp file: moov is not before mdat (' + $a.status + ' ' + $a.note + ')'); return [pscustomobject]$r }
  try { $jo = (Read-Text (Join-Path $wd 'orig.json')) | ConvertFrom-Json; $jt = (Read-Text (Join-Path $wd 'out.json')) | ConvertFrom-Json } catch { $r.reason = 'ffprobe output could not be parsed'; return [pscustomobject]$r }
  $inv = [Globalization.CultureInfo]::InvariantCulture
  $fo = Get-Prop $jo 'format'; $ft = Get-Prop $jt 'format'
  $do = Get-Prop $fo 'duration'; $dt = Get-Prop $ft 'duration'
  if ($null -ne $do -or $null -ne $dt) {
    if ($null -eq $do -or $null -eq $dt) { $r.reason = 'duration missing in one file'; return [pscustomobject]$r }
    $diff = [Math]::Abs([double]::Parse([string]$do, $inv) - [double]::Parse([string]$dt, $inv))
    if ($diff -gt $DurationTolSec) { $r.reason = ('duration differs by {0:N3} s ({1} vs {2})' -f $diff, $do, $dt); return [pscustomobject]$r }
  }
  $so = @(Get-Prop $jo 'streams'); $stt = @(Get-Prop $jt 'streams')
  if ($so.Count -ne $stt.Count) { $r.reason = ('stream count ' + $so.Count + ' -> ' + $stt.Count); return [pscustomobject]$r }
  for ($i = 0; $i -lt $so.Count; $i++) {
    foreach ($k in @('codec_type', 'codec_name', 'width', 'height', 'sample_rate', 'channels', 'nb_read_packets')) {
      $x = [string](Get-Prop $so[$i] $k); $y = [string](Get-Prop $stt[$i] $k)
      if ($x -ne $y) { $r.reason = ('stream ' + $i + ' ' + $k + ': ' + $x + ' -> ' + $y); return [pscustomobject]$r }
    }
    $lo = [string](Get-Prop (Get-Prop $so[$i] 'tags') 'language'); $lt = [string](Get-Prop (Get-Prop $stt[$i] 'tags') 'language')
    if ($lo -and $lo -ne 'und' -and $lo -ne $lt) { $r.reason = ('stream ' + $i + ' language ' + $lo + ' -> ' + $lt); $r.tags = $true; return [pscustomobject]$r }
  }
  $to = Get-Prop $fo 'tags'; $tt = Get-Prop $ft 'tags'
  if ($to) {
    foreach ($p in $to.PSObject.Properties) {
      if ($TagIgnore -contains $p.Name.ToLowerInvariant()) { continue }
      $v2 = Get-Prop $tt $p.Name
      if ($null -eq $v2 -and $tt) { foreach ($q in $tt.PSObject.Properties) { if ($q.Name -ieq $p.Name) { $v2 = $q.Value } } }
      if ([string]$v2 -ne [string]$p.Value) { $r.reason = ("container tag '" + $p.Name + "' not kept"); $r.tags = $true; return [pscustomobject]$r }
    }
  }
  $sz = (Get-FileL $outF).Length
  if ($origSize -gt 0) {
    $ratio = [double]$sz / [double]$origSize
    if ($ratio -lt 0.95 -or $sz -gt ($origSize * 1.05 + 1MB)) { $r.reason = ('temp size {0} vs original {1} ({2:N1}%)' -f $sz, $origSize, (100 * $ratio)); return [pscustomobject]$r }
  }
  $hasAv = @($so | Where-Object { @('video', 'audio') -contains [string](Get-Prop $_ 'codec_type') }).Count -gt 0
  if ($hasAv) {
    foreach ($dn in @('dec1', 'dec2')) {
      $rc = Read-Rc (Join-Path $wd ($dn + '.rc'))
      if ($rc -ne 0) {
        $src = Read-Rc (Join-Path $wd ('s' + $dn + '.rc'))
        if ($src -eq 0) { $r.reason = ('temp file does not decode (' + $(if ($dn -eq 'dec1') { 'first' } else { 'last' }) + ' 5 s): ' + (Tail-Text (Read-Text (Join-Path $wd ($dn + '.err'))) 300)); return [pscustomobject]$r }
        $r.note = ('decode errors in the ' + $(if ($dn -eq 'dec1') { 'first' } else { 'last' }) + ' 5 s exist in the original too')
      }
    }
  }
  $r.ok = $true
  return [pscustomobject]$r
}

# ---------------------------------------------------------------- manifest / journal
$TerminalStates = @('DONE', 'SKIP', 'CHECK-FAIL', 'REVERTED', 'ERROR')
function Add-J([string]$id, [string]$state, [string]$a = '', [string]$b = '', [string]$msg = '') {
  $line = (@((Get-Date).ToString('s'), $id, $state, (Clean $a), (Clean $b), (Clean $msg)) -join "`t")
  [IO.File]::AppendAllText($script:Journal, $line + "`r`n", $utf8)
}
function Read-Journal([string]$path) {
  $m = @{}
  if (-not (Test-Path -LiteralPath $path)) { return $m }
  foreach ($ln in [IO.File]::ReadAllLines($path, $utf8)) {
    $pp = $ln -split "`t"; if ($pp.Count -lt 6) { continue }
    $id = $pp[1]
    if (-not $m.ContainsKey($id)) { $m[$id] = [pscustomobject]@{ state = ''; sha_orig = ''; sha_fixed = ''; size_fixed = [int64]-1; msg = ''; rb = ''; rbmsg = '' } }
    $o = $m[$id]
    switch ($pp[2]) {
      'HASHED' { $o.sha_orig = $pp[3] }
      'SWAP-BEGIN' { $o.sha_fixed = $pp[3]; $o.size_fixed = [int64]$pp[4] }
    }
    if (@('ROLLED-BACK', 'RB-REFUSED') -contains $pp[2]) { $o.rb = $pp[2]; $o.rbmsg = $pp[5] } else { $o.state = $pp[2]; $o.msg = $pp[5] }
  }
  return $m
}
function Write-Plan([string]$path, $items) {
  $sb = New-Object Text.StringBuilder
  [void]$sb.Append("id`tcat`trel`tsize`tmtime_utc_ticks`tmoov`tmdat`tin_catalog`r`n")
  foreach ($p in $items) { [void]$sb.Append(($p.id, $p.cat, $p.rel, $p.size, $p.mtime, $p.moov, $p.mdat, $p.incat) -join "`t"); [void]$sb.Append("`r`n") }
  [IO.File]::WriteAllText($path, $sb.ToString(), $utf8)
}
function Read-Plan([string]$path) {
  $list = New-Object System.Collections.Generic.List[object]
  $lines = [IO.File]::ReadAllLines($path, $utf8)
  for ($i = 1; $i -lt $lines.Count; $i++) {
    $pp = $lines[$i] -split "`t"; if ($pp.Count -lt 8) { continue }
    $list.Add([pscustomobject]@{ id = $pp[0]; cat = $pp[1]; rel = $pp[2]; size = [int64]$pp[3]; mtime = [int64]$pp[4]; moov = [int64]$pp[5]; mdat = [int64]$pp[6]; incat = $pp[7] })
  }
  return $list
}
function Resolve-Stamp([string]$which) {
  if ($which -eq 'latest') {
    $d = @(Get-ChildItem -LiteralPath $script:BRoot -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^\d{8}-\d{6}$' -and (Test-Path -LiteralPath (Join-Path $_.FullName 'plan.tsv')) } | Sort-Object Name -Descending)
    if ($d.Count -eq 0) { throw "No faststart run found under $script:BRoot" }
    return $d[0].Name
  }
  if (-not (Test-Path -LiteralPath (Join-Path (Join-Path $script:BRoot $which) 'plan.tsv'))) { throw "No faststart run '$which' under $script:BRoot (expected $script:BRoot\$which\plan.tsv)" }
  return $which
}
function Set-State([string]$bdir, [string]$s) { [IO.File]::AppendAllText((Join-Path $bdir 'state.txt'), ((Get-Date).ToString('s') + '  ' + $s + "`r`n"), $utf8) }

# ---------------------------------------------------------------- preflight
function Invoke-Preflight([bool]$needJob) {
  if (-not [IO.Directory]::Exists((LongP $script:Lib))) { throw "Library not found: $script:Lib" }
  $r1 = [IO.Path]::GetPathRoot($script:Lib); $r2 = [IO.Path]::GetPathRoot($script:Work); $r3 = [IO.Path]::GetPathRoot($script:QRoot)
  if ($r1 -ne $r2 -or $r1 -ne $r3) { throw "Library ($r1), work folder ($r2) and quarantine ($r3) must be on the same drive (swaps are renames, never copies)" }
  if (-not $needJob) { return }
  $r = Invoke-Docker @('exec', $Container, 'sh', '-c', 'command -v ffmpeg && command -v ffprobe')
  if ($r.code -ne 0) { throw ("ffmpeg/ffprobe not available in $Container (" + (Tail-Text $r.out 200) + ')') }
  Ensure-Dir $script:Work
  $tok = 'probe-' + [guid]::NewGuid().ToString('N') + '.txt'
  [IO.File]::WriteAllText((Join-Path $script:Work $tok), $tok, $utf8)
  try { $r = Invoke-Docker @('exec', $Container, 'cat', ($script:CWork + '/' + $tok)) } finally { Remove-Item -LiteralPath (Join-Path $script:Work $tok) -Force -ErrorAction SilentlyContinue }
  if ($r.out.Trim() -ne $tok) { throw ("The work folder $script:Work is not visible in $Container as $script:CWork (" + (Tail-Text $r.out 200) + ')') }
  if (-not $script:Sim -and -not $script:NoHttp) {
    $c = Invoke-Curl @('-s', '-g', '-o', 'NUL', '-w', '%{http_code}', "$BaseUrl/guest.php")
    if ($c -notmatch '^(200|30\d)$') { throw "The app does not answer at $BaseUrl (guest.php gave '$c'); start edupak-app or use -NoHttp" }
  }
}

# ---------------------------------------------------------------- one file
function Remove-WorkDir([string]$wd, $p) {
  if (-not [IO.Directory]::Exists((LongP $wd))) { return }
  $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel
  foreach ($f in [IO.Directory]::GetFiles((LongP $wd))) {
    $nm = [IO.Path]::GetFileName($f)
    if ($nm -like 'src.*') {
      # a hard link to the original: only drop it while the original is still reachable under its library or quarantine name
      $safe = ((Test-FileL $q) -and (Get-FileL $q).Length -eq $p.size) -or ((Test-FileL $full) -and (Get-FileL $full).Length -eq $p.size)
      if (-not $safe) { Say ("  kept $wd (its src link may be the only name of the original)"); return }
    }
    [IO.File]::Delete($f)
  }
  [IO.Directory]::Delete((LongP $wd))
}
function Save-Failure([string]$wd, [string]$id) {
  $fd = Join-Path (Join-Path $script:BDir 'failed') $id; Ensure-Dir $fd
  foreach ($f in [IO.Directory]::GetFiles((LongP $wd))) { $nm = [IO.Path]::GetFileName($f); if ($nm -match '\.(err|rc|json)$') { [IO.File]::Copy($f, (LongP (Join-Path $fd $nm)), $true) } }
}
function Invoke-Swap([string]$out, [string]$full, [string]$q) {
  for ($t = 1; $t -le 3; $t++) {
    try { [IO.File]::Replace((LongP $out), (LongP $full), (LongP $q), $true); return [pscustomobject]@{ ok = $true; msg = '' } }
    catch {
      $msg = Clean $_.Exception.Message
      $fe = Test-FileL $full; $qe = Test-FileL $q; $oe = Test-FileL $out
      if (-not $fe -and $qe) {
        if ($oe) { try { [IO.File]::Move((LongP $out), (LongP $full)); return [pscustomobject]@{ ok = $true; msg = 'completed in two steps' } } catch { } }
        [IO.File]::Move((LongP $q), (LongP $full)); return [pscustomobject]@{ ok = $false; msg = ('swap failed, original put back: ' + $msg) }
      }
      if ($fe -and -not $qe -and $oe) { if ($t -lt 3) { Start-Sleep -Seconds 10; continue }; return [pscustomobject]@{ ok = $false; msg = ('file in use, not swapped (3 tries): ' + $msg) } }
      return [pscustomobject]@{ ok = $false; msg = ("unexpected state after a failed swap (library $fe, quarantine $qe, temp $oe): " + $msg); bad = $true }
    }
  }
}
function Undo-Swap($p, [string]$why) {
  $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel
  $rj = Join-Path ($script:QDir + '-rejected') $p.rel; Ensure-Dir (Get-ParentL($rj))
  if (Test-FileL $full) { [IO.File]::Replace((LongP $q), (LongP $full), (LongP $rj), $true) } else { [IO.File]::Move((LongP $q), (LongP $full)) }
  Add-J $p.id 'REVERTED' '' '' ($why + '; original put back, fixed copy kept in ' + ($script:QDir + '-rejected'))
  Say ('  REVERTED ' + $p.rel + ': ' + $why)
  return 'REVERTED'
}
function Complete-One($p, [int64]$sizeF, $httpBefore, [string]$note) {
  $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel
  $probs = @()
  if (-not (Test-FileL $full)) { $probs += 'library file missing after the swap' }
  else {
    $fi = Get-FileL $full
    if ($fi.Length -ne $sizeF) { $probs += ('library file size ' + $fi.Length + ', expected ' + $sizeF) }
    if ($fi.LastWriteTimeUtc.Ticks -ne $p.mtime) { $fi.LastWriteTimeUtc = [DateTime]::new($p.mtime, [DateTimeKind]::Utc); $fi.Refresh(); if ($fi.LastWriteTimeUtc.Ticks -ne $p.mtime) { $probs += 'modified time could not be kept' } }
    $a = Get-Atoms $full; if ($a.status -ne 'OK') { $probs += ('library file atoms: ' + $a.status + ' ' + $a.note) }
  }
  if (-not (Test-FileL $q) -or (Get-FileL $q).Length -ne $p.size) { $probs += 'original not found in quarantine with its size' }
  if ($probs.Count -gt 0) {
    if ((Test-FileL $q) -and (Get-FileL $q).Length -eq $p.size) { return (Undo-Swap $p ($probs -join '; ')) }
    Add-J $p.id 'ERROR' '' '' ('after swap: ' + ($probs -join '; ') + ' - check by hand'); Say ('  ERROR ' + $p.rel + ': ' + ($probs -join '; ')); return 'ERROR'
  }
  $h = Test-Http $p.rel $sizeF
  if (-not $h.ok) {
    if ($null -eq $httpBefore -or $httpBefore.ok) { $script:HttpFails++; return (Undo-Swap $p ('HTTP range check failed after the swap: ' + $h.info)) }
    $note = (($note + '; HTTP not 206 for the original either (' + $httpBefore.info + ')').Trim('; '))
  } elseif (-not $h.skipped) { $script:HttpFails = 0 }
  Add-J $p.id 'DONE' $h.info '' $note
  Remove-WorkDir (Join-Path $script:WRun $p.id) $p
  return 'DONE'
}
function Invoke-One($p) {
  $id = $p.id; $rel = $p.rel; $full = Join-Path $script:Lib $rel; $ext = [IO.Path]::GetExtension($rel).ToLowerInvariant()
  $wd = Join-Path $script:WRun $id; $note = ''
  if (-not (Test-FileL $full)) { Add-J $id 'SKIP' '' '' 'file no longer exists'; return 'SKIP' }
  $fi = Get-FileL $full
  if ($fi.Length -ne $p.size -or $fi.LastWriteTimeUtc.Ticks -ne $p.mtime) { Add-J $id 'SKIP' '' '' 'file changed since the scan'; return 'SKIP' }
  if (Test-FileL ($full + '.transcoding')) { Add-J $id 'SKIP' '' '' 'a transcode is writing this file'; return 'SKIP' }
  $a = Get-Atoms $full
  if ($a.status -ne 'NEEDS-FIX') { Add-J $id 'SKIP' '' '' ('now ' + $a.status + ' ' + $a.note); return 'SKIP' }
  $free = Get-Free $full; $needB = [int64]($p.size * 1.1) + [int64]($MinFreeGB * 1GB)
  if ($free -lt $needB) { return ('STOP:only ' + (Fmt-GB $free) + ' free; need ' + (Fmt-GB $needB) + ' for this file') }
  if ([IO.Directory]::Exists((LongP $wd))) { Remove-WorkDir $wd $p }
  if ([IO.Directory]::Exists((LongP $wd))) { Add-J $id 'ERROR' '' '' "old work folder $wd could not be cleared"; return 'ERROR' }
  Ensure-Dir $wd
  $src = Join-Path $wd ('src' + $ext); $out = Join-Path $wd ('out' + $ext)
  try { New-HardLink $src $full } catch { Add-J $id 'SKIP' '' '' (Clean $_.Exception.Message); Remove-WorkDir $wd $p; return 'SKIP' }
  $sha = Get-Sha $full
  Add-J $id 'HASHED' $sha
  [void](Invoke-Job $script:RunName $id $ext 'full' '+faststart')
  $chk = Test-Remux $wd $ext $p.size
  if (-not $chk.ok -and $chk.tags) {
    [void](Invoke-Job $script:RunName $id $ext 'remux' '+faststart+use_metadata_tags')
    $chk2 = Test-Remux $wd $ext $p.size
    if ($chk2.ok) { $chk = $chk2; $note = 'tags kept with use_metadata_tags' }
  }
  if ($chk.ok -and (Test-FailAt ('verify:' + [IO.Path]::GetFileName($rel)))) { $chk = [pscustomobject]@{ ok = $false; reason = 'injected test failure'; tags = $false; note = '' } }
  if (-not $chk.ok) { Save-Failure $wd $id; Add-J $id 'CHECK-FAIL' '' '' $chk.reason; Remove-WorkDir $wd $p; Say ('  CHECK-FAIL ' + $rel + ': ' + $chk.reason); return 'FAIL' }
  if ($chk.note) { $note = (($note + '; ' + $chk.note).Trim('; ')) }
  $of = Get-FileL $out; $of.LastWriteTimeUtc = [DateTime]::new($p.mtime, [DateTimeKind]::Utc)
  $shaF = Get-Sha $out; $sizeF = (Get-FileL $out).Length
  [IO.File]::Delete((LongP $src))
  $fi = Get-FileL $full
  if (-not $fi.Exists -or $fi.Length -ne $p.size -or $fi.LastWriteTimeUtc.Ticks -ne $p.mtime) { Add-J $id 'SKIP' '' '' 'original changed while it was being processed'; Remove-WorkDir $wd $p; return 'SKIP' }
  $hb = Test-Http $rel $p.size
  $busy = Wait-Indexer $script:IndexerWaitSec
  if ($busy) { Add-J $id 'PENDING' '' '' ('stopped before the swap: ' + $busy); Remove-WorkDir $wd $p; return ('STOP:the partial indexer stayed busy for ' + (Fmt-Span $script:IndexerWaitSec) + ': ' + $busy) }
  $q = Join-Path $script:QDir $rel
  if (Test-FileL $q) { Add-J $id 'SKIP' '' '' 'quarantine already holds a file with this name'; Remove-WorkDir $wd $p; return 'SKIP' }
  Ensure-Dir (Get-ParentL($q))
  Add-J $id 'SWAP-BEGIN' $shaF ([string]$sizeF)
  Check-Fail 'crash-before-replace'
  $sw = Invoke-Swap $out $full $q
  if (-not $sw.ok) {
    $bad = $false; if ($sw.PSObject.Properties['bad']) { $bad = $true }
    Add-J $id $(if ($bad) { 'ERROR' } else { 'SKIP' }) '' '' $sw.msg; if (-not $bad) { Remove-WorkDir $wd $p }; Say ('  ' + $(if ($bad) { 'ERROR ' } else { 'SKIP ' }) + $rel + ': ' + $sw.msg); return $(if ($bad) { 'ERROR' } else { 'SKIP' })
  }
  Check-Fail 'crash-after-replace'
  Add-J $id 'SWAPPED' '' '' $sw.msg
  return (Complete-One $p $sizeF $hb $note)
}
function Resolve-InFlight($p, $j) {
  # a run was interrupted around the swap of this file: find out from the disk which side it is on
  $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel; $out = Join-Path (Join-Path $script:WRun $p.id) ('out' + [IO.Path]::GetExtension($p.rel).ToLowerInvariant())
  if ($j.state -eq 'SWAPPED') { Say ('  resuming checks after the swap: ' + $p.rel); return (Complete-One $p $j.size_fixed $null 'resumed after interruption') }
  $fe = Test-FileL $full; $qe = Test-FileL $q; $oe = Test-FileL $out
  if ($fe -and $qe) {
    if ((Get-FileL $full).Length -eq $j.size_fixed -and (Get-Sha $full) -eq $j.sha_fixed) { Add-J $p.id 'SWAPPED' '' '' 'found swapped after interruption'; return (Complete-One $p $j.size_fixed $null 'resumed after interruption') }
    Add-J $p.id 'ERROR' '' '' 'interrupted swap: library and quarantine both have a file and the library one is not the fixed copy - check by hand'; return 'ERROR'
  }
  if ($fe -and -not $qe) { Add-J $p.id 'PENDING' '' '' 'interrupted before the swap; original untouched, will be redone'; Remove-WorkDir (Join-Path $script:WRun $p.id) $p; return 'REDO' }
  if (-not $fe -and $qe) {
    if ($oe) { [IO.File]::Move((LongP $out), (LongP $full)); Add-J $p.id 'SWAPPED' '' '' 'swap completed after interruption'; return (Complete-One $p $j.size_fixed $null 'resumed after interruption') }
    [IO.File]::Move((LongP $q), (LongP $full)); Add-J $p.id 'PENDING' '' '' 'original put back after an interrupted swap; will be redone'; return 'REDO'
  }
  Add-J $p.id 'ERROR' '' '' 'interrupted swap: the file is in neither the library nor the quarantine - check the work folder by hand'; return 'ERROR'
}
function Select-RoundRobin($items, [int]$n) {
  $groups = @($items | Group-Object cat | Sort-Object Name | ForEach-Object { , @($_.Group | Sort-Object size) })
  $pick = New-Object System.Collections.Generic.List[object]; $round = 0
  while ($pick.Count -lt $n) {
    $added = $false
    foreach ($g in $groups) { if ($round -lt $g.Count -and $pick.Count -lt $n) { $pick.Add($g[$round]); $added = $true } }
    if (-not $added) { break }; $round++
  }
  return $pick
}
function Set-RunPaths([string]$stamp) {
  $script:Stamp = $stamp; $script:BDir = Join-Path $script:BRoot $stamp; $script:QDir = Join-Path $script:QRoot ('faststart-' + $stamp)
  $script:Journal = Join-Path $script:BDir 'journal.tsv'; $script:RunName = 'run-' + $stamp; $script:WRun = Join-Path $script:Work $script:RunName
  $script:Log = Join-Path $script:BDir 'run.log'
}
function Write-RunReport($plan, [string]$tag) {
  $jm = Read-Journal $script:Journal
  $rows = foreach ($p in $plan) {
    $j = $null; if ($jm.ContainsKey($p.id)) { $j = $jm[$p.id] }
    [pscustomobject][ordered]@{ Id = $p.id; Result = $(if ($j) { $j.state } else { 'not done yet' }); Rollback = $(if ($j) { $j.rb } else { '' }); Category = $p.cat; 'Relative path' = $p.rel
      'Size MB' = [Math]::Round($p.size / 1MB, 1); 'Fixed size MB' = $(if ($j -and $j.size_fixed -ge 0) { [Math]::Round($j.size_fixed / 1MB, 1) } else { '' }); Message = $(if ($j) { (($j.msg + ' ' + $j.rbmsg).Trim()) } else { '' }) }
  }
  $f1 = Join-Path $script:BDir ('report-' + $tag + '-' + (Get-Date -Format 'HHmmss') + '.csv')
  @($rows) | Export-Csv -LiteralPath $f1 -NoTypeInformation -Encoding UTF8
  if (-not $script:Sim) { $f2 = Join-Path $script:Report ('Faststart ' + $tag + ' ' + $script:Stamp + ' at ' + (Get-Date -Format 'HHmmss') + '.csv'); @($rows) | Export-Csv -LiteralPath $f2 -NoTypeInformation -Encoding UTF8; return $f2 }
  return $f1
}

# ---------------------------------------------------------------- modes
function Open-RunLock {
  Ensure-Dir $script:BRoot
  try { return [IO.File]::Open((Join-Path $script:BRoot 'faststart.lock'), 'OpenOrCreate', 'ReadWrite', 'None') } catch { throw 'Another Fix-LibraryFaststart run is active (faststart.lock is held). Nothing was changed.' }
}
function Invoke-Check {
  Invoke-Preflight $false
  Say ("Fix-LibraryFaststart  CHECK ONLY  " + (Get-Date -Format 'yyyy-MM-dd HH:mm') + "  library $script:Lib  (nothing is changed)")
  $t0 = Get-Date
  $cat = Get-CatalogKeys
  $scan = Get-Scan $script:Lib $cat
  Say ("Read the atoms of {0:N0} video files in {1:N0} s." -f @($scan).Count, ((Get-Date) - $t0).TotalSeconds)
  Show-ScanSummary $scan
  $hb = Get-HeartbeatInfo; Say ''; Say ('Partial indexer: ' + $hb.text)
  $busy = Get-IndexerBusy; if ($busy) { Say ('  busy right now: ' + $busy + ' (-Apply waits for it before every swap)') }
  if ($Limit -gt 0) { Say ("-Limit $Limit only applies together with -Apply or -Continue.") }
  $rp = Join-Path $script:Report ('Faststart check ' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.csv')
  if (-not $script:Sim) { Write-ScanReport $scan $rp; Say ("Report: $rp") }
  return $scan
}
function Invoke-Apply([string]$cont) {
  Invoke-Preflight $true
  $lk = Open-RunLock
  try {
    if ($cont) {
      $stamp = Resolve-Stamp $cont; Set-RunPaths $stamp
      $plan = Read-Plan (Join-Path $script:BDir 'plan.tsv')
      Say ''; Say ("Fix-LibraryFaststart  CONTINUE $stamp  " + (Get-Date -Format 'yyyy-MM-dd HH:mm') + "  ($(@($plan).Count) files in the plan)")
    } else {
      $stamp = New-Stamp $script:BRoot; Set-RunPaths $stamp; Ensure-Dir $script:BDir
      Say ''; Say ("Fix-LibraryFaststart  APPLY $stamp  " + (Get-Date -Format 'yyyy-MM-dd HH:mm') + "  library $script:Lib")
      $cat = Get-CatalogKeys
      $scan = Get-Scan $script:Lib $cat
      $need = @($scan | Where-Object { $_.status -eq 'NEEDS-FIX' } | Sort-Object cat, rel)
      $plan = New-Object System.Collections.Generic.List[object]; $n = 0
      foreach ($s in $need) { $n++; $plan.Add([pscustomobject]@{ id = ('F{0:00000}' -f $n); cat = $s.cat; rel = $s.rel; size = $s.size; mtime = $s.mtime; moov = $s.moov; mdat = $s.mdat; incat = $s.incat }) }
      Write-Plan (Join-Path $script:BDir 'plan.tsv') $plan
      Write-ScanReport $scan (Join-Path $script:BDir 'scan.csv')
      Set-State $script:BDir ('planned ' + $plan.Count + ' files')
      Say ("Plan: {0:N0} files with moov after mdat; manifest {1}\plan.tsv" -f $plan.Count, $script:BDir)
    }
    Ensure-Dir $script:WRun; Write-Lf (Join-Path $script:WRun 'job.sh') $JobSh
    Say ('Partial indexer: ' + (Get-HeartbeatInfo).text)
    $script:HttpFails = 0
    $jm = Read-Journal $script:Journal
    foreach ($p in $plan) {
      if ($jm.ContainsKey($p.id) -and @('SWAP-BEGIN', 'SWAPPED') -contains $jm[$p.id].state) { $o = Resolve-InFlight $p $jm[$p.id]; Say ("  interrupted file {0}: {1}" -f $p.rel, $o) }
    }
    $jm = Read-Journal $script:Journal
    $todo = @($plan | Where-Object { -not ($jm.ContainsKey($_.id) -and ($TerminalStates -contains $jm[$_.id].state)) })
    if ($Limit -gt 0 -and $todo.Count -gt $Limit) { $todo = @(Select-RoundRobin $todo $Limit); Say ("-Limit ${Limit}: test batch of $($todo.Count) files (round-robin across categories, smallest first)") }
    $totB = [int64]0; foreach ($p in $todo) { $totB += $p.size }
    $eta0 = Get-Eta $todo.Count $totB
    Say ("To do now: {0:N0} files, {1}; first estimate {2}" -f $todo.Count, (Fmt-GB $totB), (Fmt-Span $eta0.sec))
    Set-State $script:BDir ('running ' + $todo.Count + ' files')
    $cnt = @{ DONE = 0; SKIP = 0; FAIL = 0; REVERTED = 0; ERROR = 0 }; $doneB = [int64]0; $t0 = Get-Date; $i = 0; $stop = ''
    $script:Timings = New-Object System.Collections.Generic.List[object]
    $mdl = Get-Throughput; $predDone = 0.0
    foreach ($p in $todo) {
      $i++; $f0 = Get-Date
      Write-Progress -Activity "Faststart $stamp" -Status ("{0}/{1}  {2}" -f $i, $todo.Count, $p.rel) -PercentComplete ([Math]::Min(100, [int](100.0 * $doneB / [Math]::Max(1, $totB))))
      $o = ''
      try { $o = Invoke-One $p }
      catch {
        if ($_.Exception.Message -like 'SIMULATED CRASH*') { throw }
        $msg = Clean $_.Exception.Message
        $jx = Read-Journal $script:Journal
        if ($jx.ContainsKey($p.id) -and @('SWAP-BEGIN', 'SWAPPED') -contains $jx[$p.id].state) { $stop = ('unexpected error during the swap of ' + $p.rel + ': ' + $msg + ' - run -Continue latest to finish it'); $o = 'ERROR' }
        else { Add-J $p.id 'ERROR' '' '' $msg; $o = 'ERROR'; Say ('  ERROR ' + $p.rel + ': ' + $msg) }
      }
      if ($o -like 'STOP:*') { $stop = $o.Substring(5); break }
      if ($o -eq 'REDO') { $o = 'SKIP' }
      if ($cnt.ContainsKey($o)) { $cnt[$o]++ }
      $sec = ((Get-Date) - $f0).TotalSeconds; $script:Timings.Add([pscustomobject]@{ size = $p.size; sec = $sec; result = $o })
      $doneB += $p.size
      $el = ((Get-Date) - $t0).TotalSeconds; $left = $totB - $doneB
      $predDone += $mdl.overhead + ($p.size / 1MB) / $mdl.mbps
      $eta = (($todo.Count - $i) * $mdl.overhead + ($left / 1MB) / $mdl.mbps) * ($el / [Math]::Max(0.001, $predDone))
      Say ("[{0}/{1}] {2,-10} {3}  ({4}, {5:N0} s)  ETA {6}" -f $i, $todo.Count, $o, $p.rel, (Fmt-MB $p.size), $sec, (Fmt-Span $eta))
      if ($stop) { break }
      if ($script:HttpFails -ge 3) { $stop = '3 HTTP checks in a row failed after swaps (all undone); is edupak-app serving /content?'; break }
    }
    Write-Progress -Activity "Faststart $stamp" -Completed
    $jm = Read-Journal $script:Journal
    $left = @($plan | Where-Object { -not ($jm.ContainsKey($_.id) -and ($TerminalStates -contains $jm[$_.id].state)) }).Count
    Say ''
    Say ("Result: {0} fixed, {1} skipped, {2} failed checks (originals untouched), {3} undone after the swap, {4} errors. {5:N0} files of the plan not done yet. Time {6}." -f $cnt.DONE, $cnt.SKIP, $cnt.FAIL, $cnt.REVERTED, $cnt.ERROR, $left, (Fmt-Span ((Get-Date) - $t0).TotalSeconds))
    if ($stop) { Say ('STOPPED: ' + $stop); Say "Resume with:  -Continue $stamp" }
    elseif ($left -gt 0) { Say "Finish the rest with:  -Continue $stamp" }
    Say ("Originals: $script:QDir   Manifest/journal: $script:BDir   Undo: -Rollback $stamp")
    $rp = Write-RunReport $plan 'apply'; Say ("Report: $rp")
    Set-State $script:BDir ("finished batch: done $($cnt.DONE), skipped $($cnt.SKIP), failed $($cnt.FAIL), reverted $($cnt.REVERTED), errors $($cnt.ERROR), left $left" + $(if ($stop) { ', stopped: ' + $stop } else { '' }))
    try { if ([IO.Directory]::Exists((LongP $script:WRun)) -and @([IO.Directory]::GetDirectories((LongP $script:WRun))).Count -eq 0) { [IO.File]::Delete((LongP (Join-Path $script:WRun 'job.sh'))); [IO.Directory]::Delete((LongP $script:WRun)) } } catch { }
    return [pscustomobject]@{ stamp = $stamp; done = $cnt.DONE; skip = $cnt.SKIP; fail = $cnt.FAIL; reverted = $cnt.REVERTED; error = $cnt.ERROR; left = $left; stop = $stop }
  } finally { $lk.Close() }
}
function Invoke-Rollback([string]$which) {
  Invoke-Preflight $false
  $lk = Open-RunLock
  try {
    $stamp = Resolve-Stamp $which; Set-RunPaths $stamp
    $plan = Read-Plan (Join-Path $script:BDir 'plan.tsv'); $jm = Read-Journal $script:Journal
    $rbStamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $rbDir = $script:QDir + '-undone-' + $rbStamp
    Say ''; Say ("Fix-LibraryFaststart  ROLLBACK $stamp  " + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
    $targets = New-Object System.Collections.Generic.List[object]; $refused = 0; $restored = 0; $i = 0
    $cands = @($plan | Where-Object { $jm.ContainsKey($_.id) -and $jm[$_.id].rb -ne 'ROLLED-BACK' -and @('DONE', 'SWAPPED', 'SWAP-BEGIN') -contains $jm[$_.id].state })
    Say ("Checking {0} fixed files (SHA256 of the library copy and of the quarantined original)..." -f $cands.Count)
    foreach ($p in $cands) {
      $i++; $j = $jm[$p.id]; $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel
      Write-Progress -Activity "Rollback $stamp (checking)" -Status ("{0}/{1} {2}" -f $i, $cands.Count, $p.rel) -PercentComplete ([int](100.0 * $i / [Math]::Max(1, $cands.Count)))
      $fe = Test-FileL $full; $qe = Test-FileL $q
      if ($j.state -eq 'SWAP-BEGIN' -and $fe -and -not $qe) { continue }
      if ($j.state -eq 'SWAP-BEGIN' -and -not $fe -and $qe) { [IO.File]::Move((LongP $q), (LongP $full)); Add-J $p.id 'ROLLED-BACK' '' '' 'interrupted swap: original put back'; $restored++; continue }
      $probs = @()
      if (-not $fe) { $probs += 'library file missing' }
      elseif ((Get-FileL $full).Length -ne $j.size_fixed -or (Get-Sha $full) -ne $j.sha_fixed) { $probs += 'library file changed since the fix (size or SHA256 differ), left as it is' }
      if (-not $qe) { $probs += 'original missing from the quarantine' }
      elseif ((Get-Sha $q) -ne $j.sha_orig) { $probs += 'quarantined original SHA256 differs from the manifest' }
      if ($probs.Count -gt 0) { Add-J $p.id 'RB-REFUSED' '' '' ($probs -join '; '); $refused++; Say ('  REFUSED ' + $p.rel + ': ' + ($probs -join '; ')); continue }
      $targets.Add($p)
    }
    Write-Progress -Activity "Rollback $stamp (checking)" -Completed
    if ($targets.Count -gt 0) {
      $busy = Wait-Indexer $script:IndexerWaitSec
      if ($busy) { throw ('The partial indexer stayed busy (' + $busy + '); nothing was moved back. Run the rollback again later.') }
    }
    $i = 0
    foreach ($p in $targets) {
      $i++; $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel; $rb = Join-Path $rbDir $p.rel
      Write-Progress -Activity "Rollback $stamp" -Status ("{0}/{1} {2}" -f $i, $targets.Count, $p.rel) -PercentComplete ([int](100.0 * $i / [Math]::Max(1, $targets.Count)))
      if (($i % 25) -eq 0) { $b2 = Wait-Indexer $script:IndexerWaitSec; if ($b2) { Say ('STOPPED: partial indexer busy: ' + $b2 + '. Run the rollback again to finish.'); break } }
      Ensure-Dir (Get-ParentL($rb))
      [IO.File]::Replace((LongP $q), (LongP $full), (LongP $rb), $true)
      $fi = Get-FileL $full; if ($fi.LastWriteTimeUtc.Ticks -ne $p.mtime) { $fi.LastWriteTimeUtc = [DateTime]::new($p.mtime, [DateTimeKind]::Utc) }
      $fi.Refresh(); $a = Get-Atoms $full
      $ok = ($fi.Length -eq $p.size -and $a.status -eq 'NEEDS-FIX')
      Add-J $p.id 'ROLLED-BACK' '' '' ('fixed copy moved to ' + $rbDir + $(if ($ok) { '' } else { '; WARNING: restored file size/atoms unexpected' }))
      $restored++
    }
    Write-Progress -Activity "Rollback $stamp" -Completed
    $h = 0; $hf = 0
    foreach ($p in ($targets | Select-Object -First 5)) { $r = Test-Http $p.rel $p.size; if (-not $r.skipped) { $h++; if (-not $r.ok) { $hf++; Say ('  HTTP after rollback: ' + $p.rel + ' ' + $r.info) } } }
    Say ''
    Say ("Rollback: {0} originals put back, {1} refused (left as they are; see the report). HTTP sample: {2} checked, {3} not 206." -f $restored, $refused, $h, $hf)
    if ($restored -gt 0) { Say ("Fixed copies that were taken out: $rbDir") }
    $rp = Write-RunReport $plan 'rollback'; Say ("Report: $rp")
    Set-State $script:BDir ("rollback $rbStamp : restored $restored, refused $refused")
    return [pscustomobject]@{ restored = $restored; refused = $refused }
  } finally { $lk.Close() }
}

# ---------------------------------------------------------------- self-test (throwaway copies only)
$GenSh = @'
#!/bin/sh
cd "$1" || exit 91
V=libx264; ffmpeg -hide_banner -encoders 2>/dev/null | grep -q libx264 || V=mpeg4
X="-pix_fmt yuv420p"; [ "$V" = libx264 ] && X="-preset ultrafast -pix_fmt yuv420p"
T="-f lavfi -i testsrc=size=320x240:rate=25:duration=4 -f lavfi -i sine=frequency=440:duration=4"
ffmpeg -nostdin -v error $T -map 0 -map 1 -c:v $V $X -c:a aac -metadata title='Self test A' -metadata comment='keep me' -metadata artist='Edutek' -y a.mp4 || exit 1
ffmpeg -nostdin -v error $T -map 0 -map 1 -c:v $V $X -c:a aac -movflags +faststart -y b.mp4 || exit 2
ffmpeg -nostdin -v error $T -map 0 -map 1 -c:v mpeg4 -c:a pcm_s16le -metadata title='Mov file' -f mov -y c.mov || exit 3
ffmpeg -nostdin -v error $T -f lavfi -i sine=frequency=880:duration=4 -i subs.srt -map 0 -map 1 -map 2 -map 3 -c:v $V $X -c:a aac -c:s mov_text -metadata:s:a:0 language=eng -metadata:s:a:1 language=fra -metadata:s:s:0 language=eng -f mp4 -y d.m4v || exit 4
echo GEN-OK
'@
function Invoke-SelfTest {
  $saved = @{ Lib = $script:Lib; BRoot = $script:BRoot; QRoot = $script:QRoot; Work = $script:Work; CWork = $script:CWork; Trig = $script:Trig; Heartbeat = $script:Heartbeat; Report = $script:Report; Sim = $script:Sim; FailAt = $script:FailAt; Wait = $script:IndexerWaitSec; Log = $script:Log }
  $prodLib = $script:Lib; $prodBRoot = $script:BRoot
  $st = 'selftest-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
  $root = Join-Path $WorkRoot $st; $croot = $ContainerWorkRoot.TrimEnd('/') + '/' + $st
  $fails = New-Object System.Collections.Generic.List[string]; $passes = 0
  function Assert([bool]$c, [string]$m) { if ($c) { $script:StPass++; Write-Host ('  ok    ' + $m) } else { $fails.Add($m); Write-Host ('  FAIL  ' + $m) -ForegroundColor Red } }
  $script:StPass = 0
  Write-Host "SELF-TEST in $root (throwaway copies; the library, the catalog and the real quarantine are not touched)"
  try {
    Ensure-Dir (Join-Path $root 'gen'); Ensure-Dir (Join-Path $root 'trigger'); Ensure-Dir (Join-Path $root 'reports')
    $script:Lib = Join-Path $root 'lib'; $script:BRoot = Join-Path $root 'backups'; $script:QRoot = Join-Path $root 'quarantine'
    $script:Work = Join-Path $root 'work'; $script:CWork = $croot + '/work'; $script:Trig = Join-Path $root 'trigger'
    $script:Heartbeat = Join-Path $script:Trig 'indexer-heartbeat.txt'; $script:Report = Join-Path $root 'reports'; $script:Sim = $true; $script:FailAt = ''
    $script:IndexerWaitSec = 3; $script:Quiet = $false
    Ensure-Dir $script:Lib; Ensure-Dir $script:Work
    # 1. generate clips in the container
    [IO.File]::WriteAllText((Join-Path $root 'gen\subs.srt'), "1`n00:00:00,500 --> 00:00:02,000`nHello`n`n2`n00:00:02,500 --> 00:00:03,500`nWorld`n", $utf8)
    Write-Lf (Join-Path $root 'gen.sh') $GenSh
    $g = Invoke-Docker @('exec', $Container, 'sh', ($croot + '/gen.sh'), ($croot + '/gen'))
    Assert ($g.out -match 'GEN-OK') ('test clips generated in ' + $Container + $(if ($g.out -notmatch 'GEN-OK') { ': ' + (Tail-Text $g.out 300) } else { '' }))
    if ($g.out -notmatch 'GEN-OK') { throw 'cannot continue without test clips' }
    $gen = Join-Path $root 'gen'; $old = [DateTime]::new(2020, 1, 2, 3, 4, 5, [DateTimeKind]::Utc)
    $layout = [ordered]@{
      'CatA\Sub One\a.mp4' = 'a.mp4'; 'CatA\b.mp4' = 'b.mp4'; 'CatB\c.mov' = 'c.mov'; 'CatB\x\d.m4v' = 'd.m4v'
      ('CatC\' + [string][char]0x00DC + 'n' + [char]0x00EF + 'code ' + [char]0x2013 + " it's #1 & 50% " + [char]0x00FC + '.mp4') = 'a.mp4'
      'CatD\inject.mp4' = 'a.mp4'; 'CatD\z.h264.mp4' = 'a.mp4'
    }
    $long = 'CatC\' + ((1..5 | ForEach-Object { "deep folder level $_ for the long path test (Windows)" }) -join '\') + '\long name.mp4'
    $layout[$long] = 'a.mp4'
    foreach ($k in $layout.Keys) { $dst = Join-Path $script:Lib $k; Ensure-Dir (Get-ParentL($dst)); [IO.File]::Copy((LongP (Join-Path $gen $layout[$k])), (LongP $dst)); (Get-FileL $dst).LastWriteTimeUtc = $old }
    Assert ((Join-Path $script:Lib $long).Length -gt 260) ('long path test file is ' + (Join-Path $script:Lib $long).Length + ' characters')
    $ab = [IO.File]::ReadAllBytes((Join-Path $gen 'a.mp4')); $cut = New-Object byte[] ([int]($ab.Length * 0.6)); [Array]::Copy($ab, $cut, $cut.Length)
    [IO.File]::WriteAllBytes((Join-Path $script:Lib 'CatC\broken.mp4'), $cut)
    [IO.File]::WriteAllText((Join-Path $script:Lib 'CatD\z.h264.mp4.transcoding'), 'lock', $utf8)
    [IO.File]::WriteAllText((Join-Path $script:Lib 'CatA\notes.txt'), 'not a video', $utf8)
    # 2. two real library files (copies) for a realistic speed measurement
    $nReal = 0; $seen = 0
    foreach ($f in [IO.Directory]::EnumerateFiles((LongP $prodLib), '*.mp4', [IO.SearchOption]::AllDirectories)) {
      $seen++; if ($seen -gt 6000 -or $nReal -ge 2) { break }
      $fi = New-Object IO.FileInfo($f)
      if ($fi.Length -lt 60MB -or $fi.Length -gt 400MB) { continue }
      if ((Get-Atoms $f.Substring(4)).status -ne 'NEEDS-FIX') { continue }
      $nReal++; $dst = Join-Path $script:Lib ("CatR\real$nReal.mp4"); Ensure-Dir (Get-ParentL($dst)); [IO.File]::Copy($f, (LongP $dst))
      Write-Host ("  copied a real library file for the speed test: {0} ({1})" -f $f.Substring(4 + $prodLib.Length + 1), (Fmt-MB $fi.Length))
    }
    $all = New-Object System.Collections.Generic.List[object]
    $init = @{}
    foreach ($f in [IO.Directory]::EnumerateFiles((LongP $script:Lib), '*', [IO.SearchOption]::AllDirectories)) { $rel = $f.Substring((LongP $script:Lib).Length + 1); $fi = New-Object IO.FileInfo($f); $init[$rel] = [pscustomobject]@{ sha = (Get-Sha $f.Substring(4)); mtime = $fi.LastWriteTimeUtc.Ticks; size = $fi.Length } }
    $uni = @($layout.Keys)[4]
    $needExpect = 6 + $nReal
    # T1 check-only
    Write-Host 'T1 check-only scan'
    $scan = @(Invoke-Check)
    $need = @($scan | Where-Object { $_.status -eq 'NEEDS-FIX' })
    Assert ($need.Count -eq $needExpect) ("NEEDS-FIX = $($need.Count) (expected $needExpect)")
    Assert (@($scan | Where-Object { $_.status -eq 'OK' -and $_.rel -eq 'CatA\b.mp4' }).Count -eq 1) 'faststart file b.mp4 is OK'
    Assert (@($scan | Where-Object { $_.status -eq 'BROKEN' -and $_.rel -eq 'CatC\broken.mp4' }).Count -eq 1) 'truncated file is BROKEN (not a candidate)'
    Assert (@($scan | Where-Object { $_.status -eq 'BUSY' -and $_.rel -eq 'CatD\z.h264.mp4' }).Count -eq 1) 'file with a .transcoding lock is BUSY (not a candidate)'
    Assert (@($need | Where-Object { $_.rel -eq $uni -or $_.rel -eq $long }).Count -eq 2) 'unicode and long-path files found'
    # T2 queued index request -> no swap
    Write-Host 'T2 partial indexer busy (queued request): apply must stop before any swap'
    [IO.File]::WriteAllText($script:Heartbeat, (Get-Date).ToString('s'), $utf8)
    [IO.File]::WriteAllText((Join-Path $script:Trig 'x.request.json'), '{"files":["a"]}', $utf8)
    $r2 = Invoke-Apply ''
    Assert ($r2.stop -like '*partial indexer*' -and $r2.done -eq 0) ('apply stopped: ' + $r2.stop)
    Assert ((-not [IO.Directory]::Exists((LongP $script:QRoot))) -or @([IO.Directory]::EnumerateFiles((LongP $script:QRoot), '*', [IO.SearchOption]::AllDirectories)).Count -eq 0) 'nothing quarantined while the indexer was busy'
    Remove-Item -LiteralPath (Join-Path $script:Trig 'x.request.json') -Force
    # T3 test batch
    Write-Host 'T3 -Apply -Limit 2'
    $script:Limit = 2
    try { $r3 = Invoke-Apply '' } finally { $script:Limit = 0 }
    foreach ($x in $script:Timings.ToArray()) { $all.Add($x) }
    Assert ($r3.done -eq 2 -and $r3.left -eq ($needExpect - 2)) ("test batch fixed $($r3.done), left $($r3.left)")
    # T4/T5 crashes
    Write-Host 'T4 crash right after a swap, T5 crash right before a swap'
    $c4 = $false; $script:FailAt = 'crash-after-replace'; try { [void](Invoke-Apply 'latest') } catch { $c4 = ($_.Exception.Message -like 'SIMULATED CRASH*') }
    Assert $c4 'simulated crash after the swap'
    $c5 = $false; $script:FailAt = 'crash-before-replace'; try { [void](Invoke-Apply 'latest') } catch { $c5 = ($_.Exception.Message -like 'SIMULATED CRASH*') }
    Assert $c5 'simulated crash before the swap (after resolving the first one)'
    Write-Host 'T6 -Continue latest (with an injected verification failure on inject.mp4)'
    $script:FailAt = 'verify:inject.mp4'
    $r6 = Invoke-Apply 'latest'
    $script:FailAt = ''
    foreach ($x in $script:Timings.ToArray()) { $all.Add($x) }
    Set-RunPaths (Resolve-Stamp 'latest'); $jm = Read-Journal $script:Journal; $plan = @(Read-Plan (Join-Path $script:BDir 'plan.tsv'))
    $done = @($plan | Where-Object { $jm.ContainsKey($_.id) -and $jm[$_.id].state -eq 'DONE' })
    Assert ($done.Count -eq ($needExpect - 1)) ("fixed $($done.Count) of $needExpect (expected all but inject.mp4)")
    $inj = @($plan | Where-Object { $_.rel -eq 'CatD\inject.mp4' })[0]
    Assert ($jm[$inj.id].state -eq 'CHECK-FAIL') ('inject.mp4 logged as ' + $jm[$inj.id].state)
    Assert ((Get-Sha (Join-Path $script:Lib 'CatD\inject.mp4')) -eq $init['CatD\inject.mp4'].sha) 'failed file left untouched (same SHA256)'
    $bad = 0
    foreach ($p in $done) {
      $full = Join-Path $script:Lib $p.rel; $q = Join-Path $script:QDir $p.rel; $fi = Get-FileL $full
      $okA = ((Get-Atoms $full).status -eq 'OK'); $okT = ($fi.LastWriteTimeUtc.Ticks -eq $init[$p.rel].mtime); $okQ = ((Test-FileL $q) -and (Get-Sha $q) -eq $init[$p.rel].sha); $okD = ((Get-Sha $full) -ne $init[$p.rel].sha)
      if (-not ($okA -and $okT -and $okQ -and $okD)) { $bad++; Write-Host ("    {0}: atoms {1} mtime {2} quarantine {3} changed {4}" -f $p.rel, $okA, $okT, $okQ, $okD) }
    }
    Assert ($bad -eq 0) 'every fixed file: moov first, same modified time, original in quarantine with the same SHA256'
    foreach ($k in @('CatA\b.mp4', 'CatC\broken.mp4', 'CatD\z.h264.mp4')) { Assert ((Get-Sha (Join-Path $script:Lib $k)) -eq $init[$k].sha) ("$k untouched") }
    $left = @(Get-ChildItem -LiteralPath $script:Work -Recurse -File -ErrorAction SilentlyContinue)
    Assert ($left.Count -eq 0) ("work folder empty afterwards ($($left.Count) files left)")
    # metadata and streams kept (d.m4v: 4 streams, languages; a.mp4 copy: title/comment)
    $pd = @($plan | Where-Object { $_.rel -eq 'CatB\x\d.m4v' })[0]
    Assert ($jm[$pd.id].state -eq 'DONE') 'multi-stream m4v (video, 2 audio languages, subtitles) fixed with all streams kept'
    # T7 re-scan
    Write-Host 'T7 check-only again'
    $scan2 = @(Invoke-Check); $need2 = @($scan2 | Where-Object { $_.status -eq 'NEEDS-FIX' })
    Assert ($need2.Count -eq 1 -and $need2[0].rel -eq 'CatD\inject.mp4') ("only the failed file still needs fixing ($($need2.Count))")
    # T8 rollback with one tampered fixed file
    Write-Host 'T8 -Rollback latest with one fixed file changed afterwards'
    $fsx = [IO.File]::Open((LongP (Join-Path $script:Lib 'CatB\c.mov')), 'Append', 'Write'); $fsx.WriteByte(0); $fsx.Close()
    $rb = Invoke-Rollback 'latest'
    Assert ($rb.refused -eq 1) "changed file refused ($($rb.refused))"
    Assert ($rb.restored -eq ($needExpect - 2)) "originals put back: $($rb.restored) (expected $($needExpect - 2))"
    $bad = 0
    foreach ($p in $done) { if ($p.rel -eq 'CatB\c.mov') { continue }; $full = Join-Path $script:Lib $p.rel; if ((Get-Sha $full) -ne $init[$p.rel].sha -or (Get-FileL $full).LastWriteTimeUtc.Ticks -ne $init[$p.rel].mtime) { $bad++ } }
    Assert ($bad -eq 0) 'rolled-back files identical to the originals (SHA256 and modified time)'
    $rb2 = Invoke-Rollback 'latest'
    Assert ($rb2.restored -eq 0) 'second rollback has nothing left to do'
    # speed measurement for the estimate (whole pipeline per file)
    Write-Host ''
    if ($nReal -gt 0) {
      $small = @($all | Where-Object { $_.size -lt 20MB -and $_.result -eq 'DONE' }); $big = @($all | Where-Object { $_.size -ge 20MB -and $_.result -eq 'DONE' })
      if ($big.Count -gt 0 -and $small.Count -gt 0) {
        $ov = ($small | Measure-Object sec -Average).Average; $mb = 0.0; $sec = 0.0
        foreach ($x in $big) { $mb += $x.size / 1MB; $sec += [Math]::Max(0.1, $x.sec - $ov) }
        $rate = $mb / $sec
        Ensure-Dir $prodBRoot
        $tf = Join-Path $prodBRoot ('throughput-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
        [IO.File]::WriteAllText($tf, (([ordered]@{ overhead_sec = [Math]::Round($ov, 2); mb_per_sec = [Math]::Round($rate, 1); measured = (Get-Date).ToString('s'); files = $big.Count; mb = [Math]::Round($mb, 1) }) | ConvertTo-Json -Compress), $utf8)
        Write-Host ("Speed (whole pipeline: hash, probe, copy, probe, decode, hash, swap): {0:N1} MB/s plus {1:N1} s per file; saved to {2}" -f $rate, $ov, $tf)
      }
    }
  } catch { $fails.Add('self-test aborted: ' + $_.Exception.Message + ' @ ' + $_.InvocationInfo.ScriptLineNumber); Write-Host ('  FAIL  aborted: ' + $_.Exception.Message + ' (line ' + $_.InvocationInfo.ScriptLineNumber + ')') -ForegroundColor Red }
  finally {
    $script:Lib = $saved.Lib; $script:BRoot = $saved.BRoot; $script:QRoot = $saved.QRoot; $script:Work = $saved.Work; $script:CWork = $saved.CWork; $script:Trig = $saved.Trig
    $script:Heartbeat = $saved.Heartbeat; $script:Report = $saved.Report; $script:Sim = $saved.Sim; $script:FailAt = $saved.FailAt; $script:IndexerWaitSec = $saved.Wait; $script:Log = $saved.Log
  }
  Write-Host ''
  if ($fails.Count -eq 0) {
    Write-Host ("SELF-TEST PASSED ({0} checks)" -f $script:StPass) -ForegroundColor Green
    if (-not $KeepWork) { try { [IO.Directory]::Delete((LongP $root), $true); Write-Host "Removed the throwaway folder $root" } catch { Write-Host ("Could not remove $root : " + $_.Exception.Message) } }
    return $true
  }
  Write-Host ("SELF-TEST FAILED: {0} of {1} checks. Throwaway files kept in {2}" -f $fails.Count, ($fails.Count + $script:StPass), $root) -ForegroundColor Red
  foreach ($f in $fails) { Write-Host ('  - ' + $f) }
  return $false
}

# ---------------------------------------------------------------- main
try {
  if ($SelfTest) { if (Invoke-SelfTest) { exit 0 } else { exit 1 } }
  elseif ($Rollback) { [void](Invoke-Rollback $Rollback) }
  elseif ($Continue) { [void](Invoke-Apply $Continue) }
  elseif ($Apply) { [void](Invoke-Apply '') }
  else { [void](Invoke-Check) }
} finally {
  if ($script:Jar -and (Test-Path -LiteralPath $script:Jar)) { Remove-Item -LiteralPath $script:Jar -Force -ErrorAction SilentlyContinue }
}
