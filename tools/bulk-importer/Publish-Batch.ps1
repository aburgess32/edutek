param(
  [Parameter(Mandatory=$true)][string]$BatchId,
  [switch]$Execute,
  [switch]$AllowSameSize,
  [string[]]$ExcludeTarget = @(),
  [int]$MaxLearning = 5,
  [string[]]$AllowSameSizeTarget = @(),
  [string]$ApprovedRequest = '',
  [string]$ProductionRoot = 'D:\xampp\htdocs\Edutek\videos',
  [string]$ArchiveRoot = 'D:\edutek-system\replaced-archive',
  [string]$DataRoot = 'D:\edutek-system\bulk-loader-fresh-v05\data',
  [string]$RecordDir = 'D:\edutek-system\publish-records'
)
$ErrorActionPreference = 'Stop'
$data   = $DataRoot
$guided = Join-Path $data 'guided-v08'
$trig   = Join-Path $data 'index-trigger'
$recDir = $RecordDir
New-Item -ItemType Directory -Force -Path $recDir, $trig | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)
$mediaExt = @('mp4','webm','mkv','avi','mp3','ogg','wav','pdf')
$videoExt = @('mp4','webm','mkv','avi')

function SqlQ([string]$s) { "'" + $s.Replace('\','\\').Replace("'","''") + "'" }
function Invoke-Sql([string]$sql) {
  $f = Join-Path $env:TEMP ('pub-' + [guid]::NewGuid().ToString('N') + '.sql')
  [IO.File]::WriteAllText($f, $sql, $utf8)
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try {
    docker cp $f edupak-db:/tmp/pub.sql | Out-Null
    $out = docker exec edupak-db sh -c 'mysql -N -B -uroot -p$MYSQL_ROOT_PASSWORD --default-character-set=utf8mb4 edupak < /tmp/pub.sql 2>/dev/null'
    if ($LASTEXITCODE -ne 0) { throw 'Database command failed' }
    return $out
  } finally { $ErrorActionPreference = $prev; Remove-Item $f -Force -ErrorAction SilentlyContinue }
}

# Patch 4: replace. A reviewed row may carry replace = {path, bytes, mtime}: its target is then an existing library file
# (chosen in the browser from the duplicate-rule matches). The old file is MOVED to $ArchiveRoot\<yyyyMMdd>\<batch id8>\<library path>
# (a manifest.json there lists every replaced file), then the new file takes its place. Undo-Publish.ps1 puts the old file back.
function Native([string]$rel) { $rel.Replace('/', [IO.Path]::DirectorySeparatorChar) }
function Get-Sha([string]$p) { (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLower() }
function Test-InsideLibrary([string]$path) {
  # True when $path is a real file below $ProductionRoot with no link (junction/symlink) on the way.
  $root = [IO.Path]::GetFullPath($ProductionRoot).TrimEnd('\', '/')
  $full = [IO.Path]::GetFullPath($path)
  if (-not $full.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { return $false }
  $p = $full
  while ($p.Length -gt $root.Length) {
    if (-not (Test-Path -LiteralPath $p)) { return $false }
    if ((Get-Item -LiteralPath $p -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { return $false }
    $p = [IO.Path]::GetDirectoryName($p)
  }
  return (Test-Path -LiteralPath $full -PathType Leaf)
}
function Move-Verified([string]$from, [string]$to, [string]$sha) {
  # Same volume: a rename. Other volume: copy, verify SHA256, then delete the original.
  New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($to)) | Out-Null
  if (Test-Path -LiteralPath $to) { throw "Archive path already exists: $to" }
  if ([IO.Path]::GetPathRoot([IO.Path]::GetFullPath($from)) -eq [IO.Path]::GetPathRoot([IO.Path]::GetFullPath($to))) { [IO.File]::Move($from, $to) }
  else { [IO.File]::Copy($from, $to, $false); if ((Get-Sha $to) -ne $sha) { Remove-Item -LiteralPath $to -Force; throw "Archive copy verification failed: $from" }; Remove-Item -LiteralPath $from -Force }
}

$m = @(Get-ChildItem $guided -Directory | Where-Object { $_.Name -like "$BatchId*" })
if ($m.Count -ne 1) { throw "Batch id '$BatchId' matches $($m.Count) batches; give more characters." }
$bd = $m[0]; $id = $bd.Name; $filesRoot = Join-Path $bd.FullName 'files'
$b = [IO.File]::ReadAllText((Join-Path $bd.FullName 'batch.json'), $utf8) | ConvertFrom-Json
if ($b.state -ne 'complete') { throw "Batch state is '$($b.state)', not 'complete'." }
$recPath = Join-Path $recDir "$id.json"
if (Test-Path -LiteralPath $recPath) { throw "A publish record already exists for this batch: $recPath" }

$items = @()
foreach ($p in $b.operations.PSObject.Properties) {
  $op = $p.Value; $r = $b.rows.($p.Name)
  if ($op.state -ne 'copied' -or -not $op.sha256) { throw "Item $($p.Name) is not a verified copy." }
  $t = [string]$op.target
  if ($t -match '(^|/)\.\.(/|$)' -or $t.StartsWith('/') -or $t.Contains('\') -or $t.Contains(':')) { throw "Unsafe target path: $t" }
  $ext = ([IO.Path]::GetExtension($t)).TrimStart('.').ToLower()
  $items += [pscustomobject]@{
    rid = $p.Name; target = $t; sha256 = ([string]$op.sha256).ToLower(); bytes = [int64]$op.bytes
    support = [bool]$r.support; media = ($mediaExt -contains $ext); video = ($videoExt -contains $ext)
    src = (Join-Path $filesRoot (Native $t)); dst = (Join-Path $ProductionRoot (Native $t))
    gen_thumb = $false; state = 'pending'; parent = [string]$r.parent
    replace = $null; old_sha256 = $null; old_bytes = $null; archive = $null
  }
  if (-not $r.support -and $r.replace) {
    $rp = $r.replace
    if ([string]$rp.path -cne $t) { throw "Item $($p.Name): the replace choice does not match its target." }
    $items[-1].replace = [pscustomobject]@{ kind = 'reviewed'; path = [string]$rp.path; bytes = [int64]$rp.bytes; mtime = [int64]$rp.mtime; why = [string]$rp.why }
  }
}
# A cover file of a replaced item replaces the existing cover at the same place, if there is one.
foreach ($i in @($items | Where-Object { $_.support -and $_.parent })) {
  $par = @($items | Where-Object { $_.rid -eq $i.parent -and $_.replace })
  if ($par.Count -eq 1 -and (Test-Path -LiteralPath $i.dst -PathType Leaf)) { $i.replace = [pscustomobject]@{ kind = 'cover'; path = $i.target; bytes = -1; mtime = -1; why = 'cover of a replaced item' } }
}
if ($ExcludeTarget.Count -gt 0) {
  $dropRids = @()
  foreach ($x in $ExcludeTarget) {
    $hit = @($items | Where-Object { -not $_.support -and $_.target -eq $x })
    if ($hit.Count -ne 1) { throw "ExcludeTarget did not match exactly one learning item: $x" }
    $dropRids += $hit[0].rid
    $dropRids += ('art-' + $hit[0].rid)
  }
  $items = @($items | Where-Object { $dropRids -notcontains $_.rid })
  Write-Host ("Excluding {0} item(s) as requested." -f $ExcludeTarget.Count)
}
$learning = @($items | Where-Object { -not $_.support })
$artTargets = @($items | Where-Object { $_.support } | ForEach-Object { $_.target.ToLower() })
foreach ($i in ($items | Where-Object { $_.video })) {
  $jpgDst = [IO.Path]::ChangeExtension($i.dst, '.jpg')
  $jpgRel = ([IO.Path]::ChangeExtension($i.target, '.jpg')).ToLower()
  $i.gen_thumb = ((-not (Test-Path -LiteralPath $jpgDst)) -and ($artTargets -notcontains $jpgRel))
}

$problems = New-Object System.Collections.Generic.List[string]
if ($learning.Count -eq 0) { $problems.Add('Batch has no learning items.') }
if ($learning.Count -gt $MaxLearning) { $problems.Add("Batch has $($learning.Count) learning items; the pilot limit is $MaxLearning.") }
# Patch 4g: a batch planned with rules v3 must use names that follow edutek_safe_name v1, checked with the SAME
# lib/edutek-safe-name.php the review page uses (in the importer web container, which has PHP with intl).
# Library folders that already exist keep their spelling (and so does the automatic subfolder named like such a category). Batches planned before 4g (placement_rules below 3) are not checked.
# Any failure of the check itself blocks the publish (fail closed); nothing has been copied at this point.
if ($problems.Count -eq 0 -and [int]('0' + [string]$b.placement_rules) -ge 3) {
  $nameList = New-Object System.Collections.Generic.List[object]
  foreach ($i in @($items | Where-Object { -not $_.replace })) {
    $parts = $i.target.Split('/'); $pre = ''
    for ($k = 0; $k -lt $parts.Count; $k++) {
      $rel = if ($pre) { $pre + '/' + $parts[$k] } else { $parts[$k] }
      if ($k -eq $parts.Count - 1) { $nameList.Add([pscustomobject]@{ name = $parts[$k]; kind = 'file'; target = $i.target }) }
      # The automatic subfolder repeats the category name (4f), so it is exempt when that category is an existing library folder.
      elseif ($k -eq 1 -and $parts[1] -ceq $parts[0] -and (Test-Path -LiteralPath (Join-Path $ProductionRoot (Native $parts[0])) -PathType Container)) { }
      elseif (-not (Test-Path -LiteralPath (Join-Path $ProductionRoot (Native $rel)) -PathType Container)) { $nameList.Add([pscustomobject]@{ name = $parts[$k]; kind = 'folder'; target = $i.target }) }
      $pre = $rel
    }
  }
  $ncDir = Join-Path $data 'name-check'; $ncFile = $null
  try {
    New-Item -ItemType Directory -Force -Path $ncDir | Out-Null
    $ncLeaf = [guid]::NewGuid().ToString('N') + '.json'; $ncFile = Join-Path $ncDir $ncLeaf
    [IO.File]::WriteAllText($ncFile, (ConvertTo-Json -InputObject ([pscustomobject]@{ names = $nameList.ToArray() }) -Depth 4 -Compress), $utf8)
    $ncOut = & docker exec edutek-bulk-fresh-v05-web-1 php /sandbox/lib/edutek-safe-name-cli.php check ('/sandbox-data/name-check/' + $ncLeaf)
    if ($LASTEXITCODE -ne 0) { throw "the name check exited with code $LASTEXITCODE" }
    $ncRes = (@($ncOut) -join "`n") | ConvertFrom-Json
    if ([string]$ncRes.rule -ne 'edutek_safe_name v1' -or @($ncRes.results).Count -ne $nameList.Count) { throw 'the name check gave an unexpected answer' }
    $ncR = @($ncRes.results)
    for ($k = 0; $k -lt $nameList.Count; $k++) {
      if (-not $ncR[$k].ok) { $problems.Add("Name does not follow the Edutek naming rule (edutek_safe_name v1): '" + $nameList[$k].name + "' should be '" + [string]$ncR[$k].clean + "' (" + $nameList[$k].kind + ' in ' + $nameList[$k].target + '). Open the batch in the importer and save it again.') }
    }
  } catch {
    $problems.Add('The Edutek naming rule check could not run, so names cannot be confirmed: ' + $_.Exception.Message)
  } finally {
    if ($ncFile -and (Test-Path -LiteralPath $ncFile)) { Remove-Item -LiteralPath $ncFile -Force -ErrorAction SilentlyContinue }
  }
}
if ($problems.Count -eq 0) {
  foreach ($i in $items) {
    if (-not (Test-Path -LiteralPath $i.src -PathType Leaf)) { $problems.Add("Missing sandbox copy: $($i.target)"); continue }
    if ($i.dst.Length -gt 250) { $problems.Add("Path too long ($($i.dst.Length) characters): $($i.target)") }
    if ((Test-Path -LiteralPath $i.dst) -and -not $i.replace) { $problems.Add("Already exists in the library: $($i.target)") }
  }
}
if ($problems.Count -eq 0) {
  foreach ($i in $items) {
    $h = (Get-FileHash -LiteralPath $i.src -Algorithm SHA256).Hash.ToLower()
    if ($h -ne $i.sha256) { $problems.Add("Sandbox copy changed (hash mismatch): $($i.target)") }
  }
}
$replacing = @($items | Where-Object { $_.replace })
if ($problems.Count -eq 0 -and $replacing.Count -gt 0) {
  foreach ($i in $replacing) {
    if (-not (Test-InsideLibrary $i.dst)) { $problems.Add("Replace target is not a plain file inside the library (missing, outside it, or reached through a link): $($i.target)"); continue }
    $fi = Get-Item -LiteralPath $i.dst -Force
    if ([IO.Path]::GetExtension($fi.Name).ToLower() -ne [IO.Path]::GetExtension($i.target).ToLower()) { $problems.Add("Replace target has a different file type: $($i.target)"); continue }
    if ($i.replace.kind -eq 'reviewed' -and ($fi.Length -ne $i.replace.bytes -or $fi.LastWriteTimeUtc.ToFileTimeUtc() -ne $i.replace.mtime)) { $problems.Add("The library file changed since the review (size or time differs), so it is not replaced: $($i.target). Review this import again."); continue }
    $i.old_sha256 = Get-Sha $i.dst; $i.old_bytes = [int64]$fi.Length
    if ($i.old_sha256 -eq $i.sha256) { $problems.Add("The new file is identical to the library file it would replace; replacing it would change nothing: $($i.target)") }
  }
}
if ($problems.Count -eq 0) {
  $ids = @($items | Where-Object { $_.media -and -not $_.replace } | ForEach-Object { SqlQ ('content/' + $_.target) })
  if ($ids.Count -gt 0) {
    $cnt = Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($ids -join ',') + ");")
    if ([int]("$cnt".Trim()) -ne 0) { $problems.Add("The database already has $("$cnt".Trim()) rows for these paths.") }
  }
}

# Patch 3b: Edutek duplicate rule 1. bulk-import-guided.php (dx_key, dx_find) implements the same rule; change both together.
#  - SAME SIZE: a library file with the same extension and size. Contents decide: an identical copy is never published; a different file is allowed.
#  - SIMILAR NAME: a library file with the same extension and name key (key of at least 12 UTF-16 units). Needs a per-file acknowledgement
#    (-AllowSameSizeTarget, which the browser request carries for "Add this copy anyway"; -AllowSameSize acknowledges all by hand).
#  Name key: file name without its last extension, Unicode NFC, U+0130 -> i, lower case, then only letters and digits (\p{L}\p{N}) of the BMP are kept
#  (.NET regex drops surrogate pairs, so characters above U+FFFF never count; PHP drops them explicitly).
function Get-DupKey([string]$name) {
  $f = $name.Replace('\', '/'); $f = $f.Substring($f.LastIndexOf('/') + 1)
  $d = $f.LastIndexOf('.'); $s = $(if ($d -lt 0) { $f } else { $f.Substring(0, $d) })
  try { $s = $s.Normalize([Text.NormalizationForm]::FormC) } catch {}
  [regex]::Replace($s.Replace([string][char]0x130, 'i').ToLowerInvariant(), '[^\p{L}\p{N}]+', '')
}
$warnings = New-Object System.Collections.Generic.List[string]
if ($learning.Count -gt 0 -and $learning.Count -le $MaxLearning) {
  Write-Host "Checking the library for duplicates (same size: contents compared; similar name: needs acknowledgement)..."
  $exts = @($learning | ForEach-Object { [IO.Path]::GetExtension($_.target).ToLower() } | Select-Object -Unique)
  $bySize = @{}; $byKey = @{}; $scanErr = @()
  foreach ($e in $exts) {
    $ev = $null
    Get-ChildItem -LiteralPath $ProductionRoot -Recurse -File -Force -Filter ("*" + $e) -ErrorAction SilentlyContinue -ErrorVariable ev | ForEach-Object {
      if ($_.Extension.ToLower() -ne $e) { return }
      $k = $e + '|' + $_.Length
      if (-not $bySize.ContainsKey($k)) { $bySize[$k] = New-Object System.Collections.Generic.List[string] }
      $bySize[$k].Add($_.FullName)
      $nk = Get-DupKey $_.Name
      if ($nk.Length -ge 12) {
        $nk = $e + '|' + $nk
        if (-not $byKey.ContainsKey($nk)) { $byKey[$nk] = New-Object System.Collections.Generic.List[string] }
        $byKey[$nk].Add($_.FullName)
      }
    }
    if ($ev) { $scanErr += @($ev) }
  }
  if ($scanErr.Count -gt 0) { $problems.Add("The library scan reported $($scanErr.Count) error(s), so duplicates cannot be ruled out. First: $($scanErr[0].Exception.Message)") }
  foreach ($i in $learning) {
    $e = [IO.Path]::GetExtension($i.target).ToLower()
    $k = $e + '|' + $i.bytes; $identical = $false
    if ($bySize.ContainsKey($k)) {
      foreach ($m in $bySize[$k]) {
        if ($i.replace -and $m -eq $i.dst) { continue }
        $mh = $null
        try { $mh = (Get-FileHash -LiteralPath $m -Algorithm SHA256).Hash.ToLower() } catch { $problems.Add("Could not read a same-size library file to compare contents: $m ($($_.Exception.Message))"); continue }
        if ($mh -eq $i.sha256) { $identical = $true; $problems.Add("Identical content is already in the library (an identical copy is never published): $($i.target)  ==  $m") }
        else { $warnings.Add("Same size, different content (allowed): $($i.target)  <>  $m") }
      }
    }
    $nk = Get-DupKey $i.target
    if (-not $identical -and $nk.Length -ge 12 -and $byKey.ContainsKey($e + '|' + $nk)) {
      foreach ($m in $byKey[$e + '|' + $nk]) {
        if ($i.replace -and $m -eq $i.dst) { continue }
        $msg = "Similar name already in the library: $($i.target)  ~  $m"
        if ($AllowSameSize -or $i.replace -or ($AllowSameSizeTarget -ccontains $i.target)) { $warnings.Add($msg + ' (acknowledged)') } else { $problems.Add($msg + ' (not acknowledged: choose "Add this copy anyway" in the browser review)') }
      }
    }
  }
}
$need = ($items | Measure-Object -Property bytes -Sum).Sum
Write-Host ""
Write-Host "Batch $id"
Write-Host ("Learning items: {0}   Cover files: {1}   Total: {2:N1} MiB" -f $learning.Count, ($items.Count - $learning.Count), ($need / 1MB))
$repLearn = @($learning | Where-Object { $_.replace }).Count
$phrase = $(if ($repLearn -gt 0) { "PUBLISH $($learning.Count - $repLearn) NEW REPLACE $repLearn" } else { "PUBLISH $($learning.Count)" })
Write-Host ("New: {0}   Replacements: {1} (old copies go to {2})" -f ($learning.Count - $repLearn), $replacing.Count, $ArchiveRoot)
foreach ($i in $learning) { Write-Host ($(if ($i.replace) { "  R " } else { "  + " }) + $i.target) }
foreach ($i in @($items | Where-Object { $_.support -and $_.replace })) { Write-Host ("  R " + $i.target + " (cover)") }
foreach ($w in $warnings) { Write-Host ("  ! " + $w) -ForegroundColor Yellow }
if ($problems.Count -gt 0) {
  Write-Host ""; Write-Host "NOT READY:" -ForegroundColor Red
  foreach ($x in $problems) { Write-Host ("  - " + $x) -ForegroundColor Red }
  return
}
if (-not $Execute) {
  Write-Host ""; Write-Host "DRY RUN OK. Nothing was copied. Re-run with -Execute to publish." -ForegroundColor Green
  return
}

$drive = New-Object IO.DriveInfo ([IO.Path]::GetPathRoot([IO.Path]::GetFullPath($ProductionRoot)))
if ($drive.AvailableFreeSpace -lt ($need + 1GB)) { throw 'Not enough free space on the library drive.' }
if ($ApprovedRequest) {
  # Non-interactive approval from the browser publish queue (Run-PublishQueue.ps1). Replaces the typed PUBLISH prompt only when the request matches this batch exactly.
  $areq = [IO.File]::ReadAllText($ApprovedRequest, $utf8) | ConvertFrom-Json
  $bad = @()
  $want = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($i in $items) { [void]$want.Add($i.target + '|' + $i.sha256) }
  $got = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($t in @($areq.targets)) { if ($t -and -not $got.Add(([string]$t.target) + '|' + ([string]$t.sha256).ToLower())) { $bad += 'duplicate file in request' } }
  if ([string]$areq.batch -cne $id) { $bad += 'batch id' }
  if (-not $b.approval -or [string]$areq.revision -cne [string]$b.approval) { $bad += 'approval revision' }
  if ([int]$areq.learning_count -ne $learning.Count -or [int]$areq.file_count -ne $items.Count) { $bad += 'counts' }
  if ([string]$areq.confirm -cne $phrase) { $bad += 'typed confirmation' }
  $wantR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($i in @($learning | Where-Object { $_.replace })) { [void]$wantR.Add($i.target + '|' + $i.replace.bytes + '|' + $i.replace.mtime) }
  $gotR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($x in @($areq.replace)) { if ($x) { [void]$gotR.Add(([string]$x.target) + '|' + [int64]$x.bytes + '|' + [int64]$x.mtime) } }
  if (-not $wantR.SetEquals($gotR) -or [int]$areq.replace_count -ne $repLearn) { $bad += 'replace list' }
  if (-not $want.SetEquals($got)) { $bad += 'file list' }
  $acked = @(@($areq.allow_same_size) | Where-Object { $_ } | ForEach-Object { [string]$_ })
  foreach ($t in $AllowSameSizeTarget) { if ($acked -cnotcontains $t) { $bad += "same-size acknowledgement not in request: $t" } }
  if ($ExcludeTarget.Count -gt 0 -or $AllowSameSize) { $bad += '-ExcludeTarget/-AllowSameSize cannot be combined with -ApprovedRequest' }
  if ($bad.Count -gt 0) { Write-Host ('Cancelled. The approved request does not match this batch: ' + ($bad -join '; ') + '. Nothing was copied.') -ForegroundColor Red; return }
  Write-Host "Approved browser request accepted: $ApprovedRequest"
} else {
  $conf = Read-Host "Type $phrase to copy $($items.Count) files into the library ($($replacing.Count) of them replace library files)"
  if ($conf -ne $phrase) { Write-Host 'Cancelled. Nothing was copied.'; return }
}

$created = New-Object System.Collections.Generic.List[string]
$archDir = $null; $manPath = $null
if ($replacing.Count -gt 0) { $archDir = Join-Path (Join-Path $ArchiveRoot (Get-Date -Format 'yyyyMMdd')) $id.Substring(0, 8); $manPath = Join-Path $archDir 'manifest.json' }
$record = [ordered]@{ batch = $id; state = 'publishing'; started = (Get-Date).ToString('s'); production_root = $ProductionRoot; created_dirs = @(); items = $items; index_requested = $false; replaced = $replacing.Count; archive_manifest = $manPath }
$manifest = [ordered]@{ batch = $id; state = 'publishing'; created = (Get-Date).ToString('s'); production_root = $ProductionRoot; archive_dir = $archDir; record = $recPath; files = @() }
function Save-Rec { [IO.File]::WriteAllText($recPath, ($record | ConvertTo-Json -Depth 6), $utf8) }
function Save-Man { if ($manPath) { New-Item -ItemType Directory -Force -Path $archDir | Out-Null; [IO.File]::WriteAllText($manPath, ($manifest | ConvertTo-Json -Depth 5), $utf8) } }
Save-Rec; Save-Man
try {
  foreach ($i in $items) {
    $dir = [IO.Path]::GetDirectoryName($i.dst)
    $chain = @(); $d = $dir
    while (-not (Test-Path -LiteralPath $d)) { $chain += $d; $d = [IO.Path]::GetDirectoryName($d) }
    if ($chain.Count -gt 0) {
      New-Item -ItemType Directory -Force -Path $dir | Out-Null
      foreach ($c in $chain) { if (-not $created.Contains($c)) { $created.Add($c) } }
      $record.created_dirs = @($created)
    }
    $tmp = $i.dst + '.partial'
    [IO.File]::Copy($i.src, $tmp, $false)
    $h = (Get-FileHash -LiteralPath $tmp -Algorithm SHA256).Hash.ToLower()
    if ($h -ne $i.sha256) { Remove-Item -LiteralPath $tmp -Force; throw "Copy verification failed: $($i.target)" }
    if ($i.replace) {
      if ((Get-Sha $i.dst) -ne $i.old_sha256) { Remove-Item -LiteralPath $tmp -Force; throw "The library file changed during publishing, so it was not replaced: $($i.target)" }
      $arch = Join-Path $archDir (Native $i.target)
      Move-Verified $i.dst $arch $i.old_sha256
      $i.archive = $arch
      $manifest.files += [ordered]@{ target = $i.target; original_path = $i.dst; original_sha256 = $i.old_sha256; original_bytes = $i.old_bytes; archive_path = $arch; replaced_by_sha256 = $i.sha256; kind = $i.replace.kind; time = (Get-Date).ToString('s'); state = 'archived' }
      Save-Man
      try { [IO.File]::Move($tmp, $i.dst) } catch { [IO.File]::Move($arch, $i.dst); $manifest.files[-1].state = 'put back'; Save-Man; throw "Could not put the new file in place; the original was put back: $($i.target)" }
      $i.state = 'replaced'; Save-Rec
      Write-Host ("  replaced " + $i.target + "  (old copy: " + $arch + ")")
    } else {
      [IO.File]::Move($tmp, $i.dst)
      $i.state = 'copied'; Save-Rec
      Write-Host ("  copied " + $i.target)
    }
  }
  $record.state = 'published'; $record.finished = (Get-Date).ToString('s')
  if ($manPath) { $manifest.state = 'active'; $manifest.published = $record.finished; Save-Man }
  $mediaTargets = @($items | Where-Object { $_.media } | ForEach-Object { $_.target })
  $reqName = "publish-$($id.Substring(0,8))"
  $req = [ordered]@{ batch = $reqName; files = $mediaTargets }
  $tmpReq = Join-Path $trig ($reqName + '.request.tmp')
  [IO.File]::WriteAllText($tmpReq, ($req | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmpReq -Destination (Join-Path $trig ($reqName + '.request.json')) -Force
  $record.index_requested = $true; Save-Rec
  Write-Host ""; Write-Host "PUBLISHED. Record: $recPath" -ForegroundColor Green
  Write-Host "Indexing request $reqName was queued. Indexing starts automatically (Edutek Import Partial Indexer); watch the Step 4 status in the browser until it says Indexing complete."
} catch {
  $record.state = 'failed'; $record.error = $_.Exception.Message; Save-Rec
  if ($manPath -and $manifest.files.Count -gt 0) { $manifest.state = 'failed'; Save-Man }
  Write-Host ""; Write-Host ("FAILED: " + $_.Exception.Message) -ForegroundColor Red
  Write-Host "Run Undo-Publish.ps1 -BatchId $($id.Substring(0,8)) to review and clean up what was copied."
}
