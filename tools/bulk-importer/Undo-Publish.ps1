param(
  [Parameter(Mandatory=$true)][string]$BatchId,
  [switch]$Execute
)
$ErrorActionPreference = 'Stop'
$recDir = 'D:\edutek-system\publish-records'
$recoveryRoot = 'D:\edutek-system\publish-recovery'
$utf8 = New-Object System.Text.UTF8Encoding($false)

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

$m = @(Get-ChildItem $recDir -Filter "$BatchId*.json")
if ($m.Count -ne 1) { throw "Publish record for '$BatchId' matched $($m.Count) files." }
$recPath = $m[0].FullName
$rec = [IO.File]::ReadAllText($recPath, $utf8) | ConvertFrom-Json
if (@('published','failed','publishing','attention') -notcontains $rec.state) { throw "Record state is '$($rec.state)'; nothing to undo." }
$recovery = Join-Path $recoveryRoot $rec.batch

$plan = @(); $conflicts = @()
foreach ($i in $rec.items) {
  $act = 'absent'
  if (Test-Path -LiteralPath $i.dst) {
    $h = (Get-FileHash -LiteralPath $i.dst -Algorithm SHA256).Hash.ToLower()
    if ($h -eq $i.sha256) { $act = 'move' } else { $act = 'conflict'; $conflicts += "Changed since publish, left in place: $($i.target)" }
  }
  $plan += [pscustomobject]@{ item = $i; action = $act; partial = (Test-Path -LiteralPath ($i.dst + '.partial')) }
}
$gen = @()
foreach ($i in $rec.items) {
  if ($i.gen_thumb) {
    $jpg = [IO.Path]::ChangeExtension($i.dst, '.jpg')
    if (Test-Path -LiteralPath $jpg) { $gen += [pscustomobject]@{ path = $jpg; rel = [IO.Path]::ChangeExtension($i.target, '.jpg') } }
  }
}
$dbIds = @($plan | Where-Object { $_.item.media -and $_.action -ne 'conflict' } | ForEach-Object { SqlQ ('content/' + $_.item.target) })
$dbCount = 0
if ($dbIds.Count -gt 0) {
  $dbCount = [int](("" + (Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");"))).Trim())
}

Write-Host ""
Write-Host "Undo plan for batch $($rec.batch)"
Write-Host ("  Files to move to recovery:     {0}" -f @($plan | Where-Object { $_.action -eq 'move' }).Count)
Write-Host ("  Generated thumbnails to move:  {0}" -f $gen.Count)
Write-Host ("  Already absent:                {0}" -f @($plan | Where-Object { $_.action -eq 'absent' }).Count)
Write-Host ("  Database rows to delete:       {0} (at most {1} expected)" -f $dbCount, $dbIds.Count)
foreach ($c in $conflicts) { Write-Host ("  ! " + $c) -ForegroundColor Yellow }
if ($dbCount -gt $dbIds.Count) { Write-Host 'STOPPED: more database rows match than this batch published.' -ForegroundColor Red; return }
if (-not $Execute) { Write-Host ""; Write-Host 'DRY RUN. Nothing was changed. Re-run with -Execute to undo.' -ForegroundColor Green; return }

$conf = Read-Host 'Type UNDO to move these files to recovery and delete their database rows'
if ($conf -ne 'UNDO') { Write-Host 'Cancelled.'; return }

if ($dbIds.Count -gt 0) {
  Invoke-Sql ("DELETE FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");") | Out-Null
  $left = [int](("" + (Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");"))).Trim())
  if ($left -ne 0) { throw "Database rows remain after delete: $left" }
}
foreach ($p in $plan) {
  if ($p.partial) { Remove-Item -LiteralPath ($p.item.dst + '.partial') -Force }
  if ($p.action -eq 'move') {
    $rp = Join-Path $recovery ($p.item.target -replace '/','\')
    New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($rp)) | Out-Null
    [IO.File]::Move($p.item.dst, $rp)
  }
}
foreach ($g in $gen) {
  $rp = Join-Path (Join-Path $recovery 'generated') ($g.rel -replace '/','\')
  New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($rp)) | Out-Null
  [IO.File]::Move($g.path, $rp)
}
foreach ($d in @($rec.created_dirs) | Sort-Object { $_.Length } -Descending) {
  if ((Test-Path -LiteralPath $d) -and -not (Get-ChildItem -LiteralPath $d -Force | Select-Object -First 1)) { Remove-Item -LiteralPath $d -Force }
}
$rec.state = $(if ($conflicts.Count -gt 0) { 'attention' } else { 'reversed' })
[IO.File]::WriteAllText($recPath, ($rec | ConvertTo-Json -Depth 6), $utf8)
Write-Host ""
Write-Host ("UNDO finished: " + $rec.state) -ForegroundColor Green
Write-Host "Moved files are in $recovery"
