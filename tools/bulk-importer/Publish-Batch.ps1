param(
  [Parameter(Mandatory=$true)][string]$BatchId,
  [switch]$Execute,
  [switch]$AllowSameSize,
  [string[]]$ExcludeTarget = @(),
  [int]$MaxLearning = 5,
  [string]$ProductionRoot = 'D:\xampp\htdocs\Edutek\videos'
)
$ErrorActionPreference = 'Stop'
$data   = 'D:\edutek-system\bulk-loader-fresh-v05\data'
$guided = Join-Path $data 'guided-v08'
$trig   = Join-Path $data 'index-trigger'
$recDir = 'D:\edutek-system\publish-records'
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
    src = (Join-Path $filesRoot ($t -replace '/','\')); dst = (Join-Path $ProductionRoot ($t -replace '/','\'))
    gen_thumb = $false; state = 'pending'
  }
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
if ($problems.Count -eq 0) {
  foreach ($i in $items) {
    if (-not (Test-Path -LiteralPath $i.src -PathType Leaf)) { $problems.Add("Missing sandbox copy: $($i.target)"); continue }
    if ($i.dst.Length -gt 250) { $problems.Add("Path too long ($($i.dst.Length) characters): $($i.target)") }
    if (Test-Path -LiteralPath $i.dst) { $problems.Add("Already exists in the library: $($i.target)") }
  }
}
if ($problems.Count -eq 0) {
  foreach ($i in $items) {
    $h = (Get-FileHash -LiteralPath $i.src -Algorithm SHA256).Hash.ToLower()
    if ($h -ne $i.sha256) { $problems.Add("Sandbox copy changed (hash mismatch): $($i.target)") }
  }
}
if ($problems.Count -eq 0) {
  $ids = @($items | Where-Object { $_.media } | ForEach-Object { SqlQ ('content/' + $_.target) })
  if ($ids.Count -gt 0) {
    $cnt = Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($ids -join ',') + ");")
    if ([int]("$cnt".Trim()) -ne 0) { $problems.Add("The database already has $("$cnt".Trim()) rows for these paths.") }
  }
}

$warnings = New-Object System.Collections.Generic.List[string]
if ($learning.Count -gt 0 -and $learning.Count -le $MaxLearning) {
  Write-Host "Scanning the library for same-size and similar-name files..."
  $exts = @($learning | ForEach-Object { [IO.Path]::GetExtension($_.target).ToLower() } | Select-Object -Unique)
  $bySize = @{}; $byStem = @{}
  foreach ($e in $exts) {
    Get-ChildItem -LiteralPath $ProductionRoot -Recurse -File -Filter ("*" + $e) -ErrorAction SilentlyContinue | ForEach-Object {
      $k = $e + '|' + $_.Length
      if (-not $bySize.ContainsKey($k)) { $bySize[$k] = New-Object System.Collections.Generic.List[string] }
      $bySize[$k].Add($_.FullName)
      $stem = [regex]::Replace([IO.Path]::GetFileNameWithoutExtension($_.Name).ToLower(), '[^\p{L}\p{N}]', '')
      if ($stem.Length -ge 12) {
        if (-not $byStem.ContainsKey($stem)) { $byStem[$stem] = New-Object System.Collections.Generic.List[string] }
        $byStem[$stem].Add($_.FullName)
      }
    }
  }
  foreach ($i in $learning) {
    $e = [IO.Path]::GetExtension($i.target).ToLower()
    $k = $e + '|' + $i.bytes
    if ($bySize.ContainsKey($k)) {
      foreach ($m in $bySize[$k]) {
        $msg = "Same size as an existing library file: $($i.target)  ==  $m"
        if ($AllowSameSize) { $warnings.Add($msg) } else { $problems.Add($msg) }
      }
    }
    $stem = [regex]::Replace([IO.Path]::GetFileNameWithoutExtension($i.target).ToLower(), '[^\p{L}\p{N}]', '')
    if ($stem.Length -ge 12 -and $byStem.ContainsKey($stem)) {
      foreach ($m in $byStem[$stem]) { $warnings.Add("Similar name already in the library: $($i.target)  ~  $m") }
    }
  }
}
$need = ($items | Measure-Object -Property bytes -Sum).Sum
Write-Host ""
Write-Host "Batch $id"
Write-Host ("Learning items: {0}   Cover files: {1}   Total: {2:N1} MiB" -f $learning.Count, ($items.Count - $learning.Count), ($need / 1MB))
foreach ($i in $learning) { Write-Host ("  + " + $i.target) }
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

$drive = Get-PSDrive -Name $ProductionRoot.Substring(0,1)
if ($drive.Free -lt ($need + 1GB)) { throw 'Not enough free space on the library drive.' }
$conf = Read-Host "Type PUBLISH $($learning.Count) to copy $($items.Count) files into the library"
if ($conf -ne "PUBLISH $($learning.Count)") { Write-Host 'Cancelled. Nothing was copied.'; return }

$created = New-Object System.Collections.Generic.List[string]
$record = [ordered]@{ batch = $id; state = 'publishing'; started = (Get-Date).ToString('s'); production_root = $ProductionRoot; created_dirs = @(); items = $items; index_requested = $false }
function Save-Rec { [IO.File]::WriteAllText($recPath, ($record | ConvertTo-Json -Depth 6), $utf8) }
Save-Rec
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
    [IO.File]::Move($tmp, $i.dst)
    $i.state = 'copied'; Save-Rec
    Write-Host ("  copied " + $i.target)
  }
  $record.state = 'published'; $record.finished = (Get-Date).ToString('s')
  $mediaTargets = @($items | Where-Object { $_.media } | ForEach-Object { $_.target })
  $reqName = "publish-$($id.Substring(0,8))"
  $req = [ordered]@{ batch = $reqName; files = $mediaTargets }
  $tmpReq = Join-Path $trig ($reqName + '.request.tmp')
  [IO.File]::WriteAllText($tmpReq, ($req | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmpReq -Destination (Join-Path $trig ($reqName + '.request.json')) -Force
  $record.index_requested = $true; Save-Rec
  Write-Host ""; Write-Host "PUBLISHED. Record: $recPath" -ForegroundColor Green
  Write-Host "An indexer request was queued. Run the runner once to index these files."
} catch {
  $record.state = 'failed'; $record.error = $_.Exception.Message; Save-Rec
  Write-Host ""; Write-Host ("FAILED: " + $_.Exception.Message) -ForegroundColor Red
  Write-Host "Run Undo-Publish.ps1 -BatchId $($id.Substring(0,8)) to review and clean up what was copied."
}
