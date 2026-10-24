<#
Restore-SubcategoryCovers.ps1  -  Edutek library: put back the subcategory tile covers that run 20261023-063248 of
Move-LibraryDuplicates-r2.ps1 moved to quarantine as "exact duplicate spares". Windows PowerShell 5.1, StrictMode 2.0.

Why: the app shows a subcategory tile image by FILE NAME (tutorials.php findSubcategoryThumb: <Category>/<Subfolder>.jpg|jpeg|png|webp
next to the folder), not through content_meta.thumbnail_path. Those files were byte-identical to another image (usually
<Category>\<Subfolder>\<Subfolder>.jpg), no catalog row pointed at them, so the duplicate rules saw a spare copy and moved it.
The tile then fell back to the first item's thumbnail, or to images/sample.png (which does not exist -> broken image).

Selected: every 'file' manifest entry of the run whose original path is <Library>\<Category>\<Name>.(jpg|jpeg|png|webp) and
  - <Name> is a catalog subcategory of <Category> (tile on the category page), or
  - a folder <Library>\<Category>\<Name> exists.
Only these cover files move back. No catalog rows are involved (none of them had or needs a row).

Default: CHECK ONLY (hash every quarantine copy against the manifest, show what would move back, write a Desktop CSV).
  -Apply              re-checks, writes a backup folder D:\edutek-system\backups\subcategory-covers\<stamp>\ (plan.tsv,
                      restored.log, run.log, state.txt), moves each quarantine copy back to its original path, re-hashes it,
                      then verifies over HTTP (each /content/<Category>/<Name>.<ext> URL and each affected tile on its
                      category page). Any failure moves the files of this restore back into quarantine by itself.
  -Rollback latest|<stamp>   moves the files of that restore back into quarantine (hash-checked), verifies.
  -Run <stamp>        library-dupes run to repair (default 20261023-063248)
  -NoHttp             skip the HTTP checks
Compatible with a later full "Move-LibraryDuplicates-r2.ps1 -Rollback 20261023-063248": that rollback only moves files that
are still in quarantine and refuses only when a file exists in BOTH places, which this script never creates.
#>
[CmdletBinding()]
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [string]$Run = '20261023-063248',
  [switch]$NoHttp,
  [string]$Library = 'D:\xampp\htdocs\Edutek\videos',
  [string]$DupesRoot = 'D:\edutek-system\backups\library-dupes',
  [string]$BackupRoot = 'D:\edutek-system\backups\subcategory-covers',
  [string]$BaseUrl = 'http://localhost:8080'
)
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$Curl = Join-Path $env:SystemRoot 'System32\curl.exe'
$CoverExt = @('.jpg', '.jpeg', '.png', '.webp')
$Lib = $Library.TrimEnd('\')
$script:LogFile = ''
$script:Jar = ''

function Say([string]$t) { Write-Host $t; if ($script:LogFile) { [IO.File]::AppendAllText($script:LogFile, $t + "`r`n", $utf8) } }
function Get-Sha([string]$p) { return (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToUpperInvariant() }
function Invoke-Curl([string[]]$a) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @(& $Curl @a 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  return (@($o) -join "`n").Trim()
}
function Get-Jar {
  if (-not $script:Jar) {
    $script:Jar = Join-Path $env:TEMP ('rsc-jar-' + [guid]::NewGuid().ToString('N') + '.txt')
    [void](Invoke-Curl @('-s', '-g', '-o', 'NUL', '-c', $script:Jar, '-b', $script:Jar, "$BaseUrl/guest.php"))
  }
  return $script:Jar
}
function Encode-Path([string]$p) { return ((@($p -split '/') | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/') }
function Test-Image([string]$url) {
  if ($url -notmatch '^https?://') { $url = "$BaseUrl/" + $url.TrimStart('/') }
  $r = Invoke-Curl @('-s', '-g', '-b', (Get-Jar), '-o', 'NUL', '-r', '0-1023', '-w', '%{http_code}|%{content_type}', $url)
  return [pscustomobject]@{ ok = ($r -match '^(200|206)\|image/'); info = $r }
}
function Invoke-Php([string]$code) {
  $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = @($code | & $Docker --context desktop-linux exec -i edupak-app php 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
  if ($LASTEXITCODE -ne 0) { throw ("docker php failed: " + ((@($o) -join ' ').Trim())) }
  return @($o)
}
function Get-Subcats {
  # distinct (category, subcategory) of the live catalog, read-only
  $php = '<?php require_once "/var/www/html/includes/config.php"; require_once "/var/www/html/includes/auth.php"; $pdo = getDbConnection();' +
         ' foreach ($pdo->query("SELECT DISTINCT category, subcategory FROM content_meta WHERE subcategory <> \"\"")->fetchAll(PDO::FETCH_NUM) as $r) echo "SC:" . base64_encode($r[0]) . ":" . base64_encode($r[1]) . "\n";'
  $set = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($l in (Invoke-Php $php)) {
    if ($l -like 'SC:*') { $p = $l.Split(':'); [void]$set.Add($utf8.GetString([Convert]::FromBase64String($p[1])) + '|' + $utf8.GetString([Convert]::FromBase64String($p[2]))) }
  }
  if ($set.Count -eq 0) { throw 'could not read the catalog subcategories (docker / edupak-app running?)' }
  return ,$set
}
function Get-CatUrls([string[]]$cats) {
  $json = ConvertTo-Json @($cats) -Compress
  $php = '<?php require_once "/var/www/html/includes/config.php"; require_once "/var/www/html/includes/auth.php"; require_once "/var/www/html/includes/tiles.php";' +
         ' foreach (json_decode(base64_decode("' + [Convert]::ToBase64String($utf8.GetBytes($json)) + '"), true) as $c) echo "\nURL:" . base64_encode($c) . "|tutorials.php?&" . tileEncrypt("course") . "=" . tileEncrypt($c) . "\n";'
  $m = @{}
  foreach ($l in (Invoke-Php $php)) { if ($l -like 'URL:*') { $x = $l.Substring(4).Split('|', 2); $m[$utf8.GetString([Convert]::FromBase64String($x[0]))] = $x[1] } }
  return $m
}
function Get-Tiles([string]$rel) {
  $f = Join-Path $env:TEMP ('rsc-page-' + [guid]::NewGuid().ToString('N') + '.html')
  $code = Invoke-Curl @('-s', '-g', '-b', (Get-Jar), '-c', (Get-Jar), '-o', $f, '-w', '%{http_code}', "$BaseUrl/$rel")
  $body = ''; if (Test-Path -LiteralPath $f) { $body = [IO.File]::ReadAllText($f, $utf8); Remove-Item -LiteralPath $f -Force }
  $t = @{}
  $rx = '<button class="tut-section-toggle"[^>]*>\s*<img src="([^"]*)"[^>]*class="tut-thumb">\s*<div class="tut-section-info">\s*<p class="tut-section-title">([^<]*)</p>'
  foreach ($m in [regex]::Matches($body, $rx)) { $t[[Net.WebUtility]::HtmlDecode($m.Groups[2].Value)] = [Net.WebUtility]::HtmlDecode($m.Groups[1].Value) }
  return [pscustomobject]@{ code = $code; tiles = $t }
}
function Read-Manifest([string]$bk) {
  $p = Join-Path $bk 'manifest.tsv'
  if (-not (Test-Path -LiteralPath $p)) { throw "no manifest.tsv in $bk" }
  $out = New-Object System.Collections.Generic.List[object]
  $first = $true
  foreach ($line in [IO.File]::ReadAllLines($p, $utf8)) {
    if ($first) { $first = $false; if ($line -like "type`t*") { continue } }
    if (-not $line) { continue }
    $c = $line.Split("`t")
    $out.Add([pscustomobject]@{ type = $c[0]; src = $c[1]; dst = $c[2]; size = [int64]$c[3]; sha = ([string]$c[5]).ToUpperInvariant(); keeper = $c[6]; rows = $c[7]; kind = $c[8] })
  }
  return $out
}

function Get-Plan([string]$bk) {
  Say "  reading $bk\manifest.tsv"
  $m = Read-Manifest $bk
  Say "  reading catalog subcategories (read-only) ..."
  $subs = Get-Subcats
  $prefix = $Lib + '\'
  $items = New-Object System.Collections.Generic.List[object]
  foreach ($e in $m) {
    if ($e.type -ne 'file') { continue }
    if (-not $e.src.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { continue }
    $rel = $e.src.Substring($prefix.Length); $seg = $rel.Split('\')
    if ($seg.Count -ne 2) { continue }
    $ext = [IO.Path]::GetExtension($seg[1]).ToLowerInvariant(); if ($CoverExt -notcontains $ext) { continue }
    $cat = $seg[0]; $name = [IO.Path]::GetFileNameWithoutExtension($seg[1])
    $tile = $subs.Contains("$cat|$name"); $folder = [IO.Directory]::Exists((Join-Path (Join-Path $Lib $cat) $name))
    if (-not ($tile -or $folder)) { continue }
    $it = [pscustomobject]@{ cat = $cat; name = $name; ext = $ext; src = $e.src; dst = $e.dst; sha = $e.sha; size = $e.size; keeper = $e.keeper
                             why = $(if ($tile) { 'tile' } else { 'folder only (no tile)' }); state = ''; note = ''; url = ('/content/' + (Encode-Path "$cat/$name$ext")) }
    $se = [IO.File]::Exists($e.src); $de = [IO.File]::Exists($e.dst)
    if ($e.rows) { $it.state = 'BLOCK'; $it.note = "has catalog rows in the manifest ($($e.rows)); not handled here" }
    elseif ($se -and $de) { $it.state = 'BLOCK'; $it.note = 'a file exists at the original path AND in quarantine' }
    elseif ($se -and -not $de) {
      if ((Get-Sha $e.src) -eq $e.sha) { $it.state = 'ALREADY'; $it.note = 'already back at the original path (same hash)' } else { $it.state = 'BLOCK'; $it.note = 'a different file is at the original path' }
    }
    elseif (-not $de) { $it.state = 'BLOCK'; $it.note = 'not in quarantine and not at the original path' }
    else {
      $h = Get-Sha $e.dst
      if ($e.sha -and $h -ne $e.sha) { $it.state = 'BLOCK'; $it.note = "quarantine copy hash $h differs from the manifest" }
      else { $it.state = 'RESTORE'; $it.note = 'quarantine copy hash matches the manifest' }
    }
    if ($it.keeper -and -not (Test-Path -LiteralPath $it.keeper)) { $it.note += '; (info) its keeper is not on disk' }
    $items.Add($it)
  }
  return $items
}
function Show-Plan($items) {
  $g = @($items | Group-Object state | ForEach-Object { "$($_.Name)=$($_.Count)" }) -join ', '
  Say ("  covers found: " + @($items).Count + "  (" + $g + ")")
  Say ("  tiles: " + @($items | Where-Object { $_.why -eq 'tile' }).Count + ", folder only: " + @($items | Where-Object { $_.why -ne 'tile' }).Count)
  foreach ($grp in ($items | Group-Object cat | Sort-Object Name)) { Say ("    {0,-45} {1}" -f $grp.Name, $grp.Count) }
  foreach ($it in @($items | Where-Object { $_.state -eq 'BLOCK' })) { Say "  BLOCK $($it.src): $($it.note)" }
}
function Test-Http($items) {
  $bad = 0
  $done = @($items)
  Say "  HTTP: each restored cover URL"
  foreach ($it in $done) { $r = Test-Image $it.url; if (-not $r.ok) { $bad++; Say "    FAIL $($it.url) -> $($r.info)" } }
  Say ("    cover URLs OK: " + ($done.Count - $bad) + " / " + $done.Count)
  $tileItems = @($done | Where-Object { $_.why -eq 'tile' })
  if ($tileItems.Count) {
    Say "  HTTP: each affected tile on its category page"
    $cats = @($tileItems | ForEach-Object { $_.cat } | Sort-Object -Unique)
    $urls = Get-CatUrls $cats; $tb = 0; $tn = 0
    foreach ($c in $cats) {
      if (-not $urls.ContainsKey($c)) { $bad++; Say "    FAIL no page URL for $c"; continue }
      $pg = Get-Tiles $urls[$c]
      if ($pg.code -ne '200') { $bad++; Say "    FAIL $c page HTTP $($pg.code)"; continue }
      foreach ($it in @($tileItems | Where-Object { $_.cat -eq $c } | Group-Object name | ForEach-Object { $_.Group[0] })) {
        $tn++
        if (-not $pg.tiles.ContainsKey($it.name)) { $tb++; $bad++; Say "    FAIL tile '$($it.name)' not on the $c page"; continue }
        $src = $pg.tiles[$it.name]; $dec = [Uri]::UnescapeDataString($src)
        $want = "/content/$($it.cat)/$($it.name)."
        $r = Test-Image $src
        if (-not $dec.StartsWith($want, [StringComparison]::Ordinal) -or -not $r.ok) { $tb++; $bad++; Say "    FAIL tile $c / $($it.name): img $src -> $($r.info)" }
      }
    }
    Say ("    tiles showing their own cover: " + ($tn - $tb) + " / " + $tn)
  }
  return $bad
}
function Get-RestoreDir([string]$which) {
  if (-not (Test-Path -LiteralPath $BackupRoot)) { throw "no restores yet ($BackupRoot)" }
  if ($which -ne 'latest') { $d = Join-Path $BackupRoot $which; if (-not (Test-Path -LiteralPath $d)) { throw "no restore $which" }; return $d }
  $d = @(Get-ChildItem -LiteralPath $BackupRoot -Directory | Where-Object { (Test-Path -LiteralPath (Join-Path $_.FullName 'state.txt')) -and ((Get-Content -LiteralPath (Join-Path $_.FullName 'state.txt') -Raw).Trim() -in @('applied', 'failed-rollback', 'restoring')) } | Sort-Object Name -Descending)
  if (-not $d.Count) { throw 'no applied restore to roll back' }
  return $d[0].FullName
}
function Undo-Restore([string]$rd) {
  # moves the files listed in restored.log back into quarantine (only if unchanged), newest first
  $log = Join-Path $rd 'restored.log'
  $lines = @(); if (Test-Path -LiteralPath $log) { $lines = @([IO.File]::ReadAllLines($log, $utf8) | Where-Object { $_ }) }
  [array]::Reverse($lines)
  $n = 0; $prob = 0
  foreach ($l in $lines) {
    $c = $l.Split("`t"); $src = $c[0]; $dst = $c[1]; $sha = $c[2]
    $se = [IO.File]::Exists($src); $de = [IO.File]::Exists($dst)
    if (-not $se -and $de) { continue }
    if ($se -and $de) { $prob++; Say "    PROBLEM both places: $src"; continue }
    if (-not $se) { $prob++; Say "    PROBLEM gone from both places: $src"; continue }
    if ((Get-Sha $src) -ne $sha) { $prob++; Say "    PROBLEM changed since restore, left in place: $src"; continue }
    $pd = [IO.Path]::GetDirectoryName($dst); if (-not [IO.Directory]::Exists($pd)) { [void][IO.Directory]::CreateDirectory($pd) }
    [IO.File]::Move($src, $dst); $n++
  }
  Say "  moved $n cover(s) back into quarantine; problems: $prob"
  return $prob
}

# ---------------------------------------------------------------- main
$bk = Join-Path $DupesRoot $Run
try {
  if ($Rollback) {
    $rd = Get-RestoreDir $Rollback
    $script:LogFile = Join-Path $rd 'run.log'
    Say "ROLLBACK of restore $(Split-Path -Leaf $rd) (moves the covers back into quarantine)"
    $prob = Undo-Restore $rd
    if ($prob) { [IO.File]::WriteAllText((Join-Path $rd 'state.txt'), 'failed-rollback', $utf8); throw "rollback finished with $prob problem(s); see $rd\run.log" }
    [IO.File]::WriteAllText((Join-Path $rd 'state.txt'), 'rolled-back', $utf8)
    Say "DONE. Covers are back in quarantine. Restore folder kept: $rd"
    return
  }

  $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
  Say "Restore-SubcategoryCovers  run $Run  $(if ($Apply) { 'APPLY' } else { 'CHECK ONLY' })  $stamp"
  if (-not (Test-Path -LiteralPath $bk)) { throw "run folder not found: $bk" }
  $items = Get-Plan $bk
  Show-Plan $items
  $csv = Join-Path ([Environment]::GetFolderPath('Desktop')) "Subcategory covers $(if ($Apply) { 'apply' } else { 'check' }) $stamp.csv"
  $items | Select-Object state, why, cat, name, ext, src, dst, sha, size, keeper, url, note | Export-Csv -LiteralPath $csv -NoTypeInformation -Encoding UTF8
  Say "  report: $csv"
  $todo = @($items | Where-Object { $_.state -eq 'RESTORE' })
  if (@($items | Where-Object { $_.state -eq 'BLOCK' }).Count) { throw 'some covers are BLOCKED (see above); nothing changed' }

  if (-not $Apply) {
    if (-not $NoHttp) {
      Say "  HTTP now (before restore): cover URLs that should return 404"
      $c404 = 0; foreach ($it in $todo) { if (-not (Test-Image $it.url).ok) { $c404++ } }
      Say ("    currently not served: " + $c404 + " / " + $todo.Count)
    }
    Say "CHECK ONLY: $($todo.Count) cover(s) would move back from quarantine. Nothing changed."
    Say "Apply:    powershell -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Apply"
    return
  }

  if (-not $todo.Count) { Say 'Nothing to restore.'; return }
  $rd = Join-Path $BackupRoot $stamp
  [void](New-Item -ItemType Directory -Path $rd -Force)
  $script:LogFile = Join-Path $rd 'run.log'
  Say "  restore folder $rd"
  $todo | Select-Object cat, name, ext, src, dst, sha, size, why | Export-Csv -LiteralPath (Join-Path $rd 'plan.csv') -NoTypeInformation -Encoding UTF8
  [IO.File]::WriteAllText((Join-Path $rd 'state.txt'), 'restoring', $utf8)
  $log = Join-Path $rd 'restored.log'
  $failed = ''
  try {
    foreach ($it in $todo) {
      if ([IO.File]::Exists($it.src)) { throw "something appeared at $($it.src)" }
      if ((Get-Sha $it.dst) -ne $it.sha) { throw "quarantine copy changed: $($it.dst)" }
      [IO.File]::Move($it.dst, $it.src)
      [IO.File]::AppendAllText($log, ($it.src + "`t" + $it.dst + "`t" + $it.sha + "`r`n"), $utf8)
      if ((Get-Sha $it.src) -ne $it.sha) { throw "hash differs after the move: $($it.src)" }
    }
    Say "  moved back $($todo.Count) cover(s); hashes verified"
    if (-not $NoHttp) { $bad = Test-Http $todo; if ($bad) { throw "$bad HTTP check(s) failed" } }
  } catch { $failed = $_.Exception.Message }
  if ($failed) {
    Say "  FAILED: $failed -> moving this restore's covers back into quarantine"
    $prob = Undo-Restore $rd
    if ($prob) { [IO.File]::WriteAllText((Join-Path $rd 'state.txt'), 'failed-rollback', $utf8); throw "restore failed ($failed) AND the automatic undo had $prob problem(s); see $rd\run.log" }
    [IO.File]::WriteAllText((Join-Path $rd 'state.txt'), 'failed-undone', $utf8)
    throw "restore failed and was undone: $failed"
  }
  [IO.File]::WriteAllText((Join-Path $rd 'state.txt'), 'applied', $utf8)
  Say "DONE. Restored $($todo.Count) subcategory cover(s). Log: $rd"
  Say "Undo:  powershell -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Rollback latest"
}
finally {
  if ($script:Jar -and (Test-Path -LiteralPath $script:Jar)) { Remove-Item -LiteralPath $script:Jar -Force -ErrorAction SilentlyContinue }
}
