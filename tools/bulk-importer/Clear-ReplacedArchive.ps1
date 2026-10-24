param(
  [int]$Days = 30,
  [switch]$Execute,
  [switch]$Yes,
  [string]$ArchiveRoot = 'D:\edutek-system\replaced-archive',
  [string]$RecordDir = 'D:\edutek-system\publish-records'
)
# Lists replaced-file archives (replaced-archive\<yyyyMMdd>\<batch id8>\) older than -Days (default 30). Dry run by default.
# -Execute deletes the FINAL ones after you type DELETE <n> (-Yes skips the prompt, for a scheduled task).
# FINAL = the batch is still published (record 'published', manifest 'active') or was undone (manifest 'restored'), and its
# publish/undo is older than -Days. Anything else (failed, attention, publishing, missing or unreadable manifest/record,
# links inside the folder) is kept and listed with the reason.
# After deletion, Undo-Publish.ps1 can no longer restore the replaced originals of that batch. Deletions are logged to cleanup-log.txt.
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
if (-not (Test-Path -LiteralPath $ArchiveRoot -PathType Container)) { Write-Host "No archive folder ($ArchiveRoot). Nothing to do."; return }
$rootFull = [IO.Path]::GetFullPath($ArchiveRoot).TrimEnd('\', '/')
$now = Get-Date; $rows = @()
foreach ($day in @(Get-ChildItem -LiteralPath $ArchiveRoot -Directory -Force | Where-Object { $_.Name -match '^\d{8}$' } | Sort-Object Name)) {
  foreach ($bd in @(Get-ChildItem -LiteralPath $day.FullName -Directory -Force | Sort-Object Name)) {
    $files = @(Get-ChildItem -LiteralPath $bd.FullName -Recurse -Force -ErrorAction SilentlyContinue)
    $bytes = [int64](($files | Where-Object { -not $_.PSIsContainer } | Measure-Object -Property Length -Sum).Sum)
    $row = [pscustomobject]@{ folder = $bd.FullName; day = $day.Name; batch = $bd.Name; files = @($files | Where-Object { -not $_.PSIsContainer -and $_.Name -ne 'manifest.json' }).Count; mib = [math]::Round($bytes / 1MB, 1); age_days = $null; state = ''; final = $false; reason = '' }
    $rows += $row
    if (($bd.Attributes -band [IO.FileAttributes]::ReparsePoint) -or @($files | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count) { $row.reason = 'contains a link (junction/symlink); not touched'; continue }
    if (-not ([IO.Path]::GetFullPath($bd.FullName)).StartsWith($rootFull + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { $row.reason = 'outside the archive root'; continue }
    $mp = Join-Path $bd.FullName 'manifest.json'
    if (-not (Test-Path -LiteralPath $mp -PathType Leaf)) { $row.reason = 'no manifest.json; kept'; continue }
    try { $man = [IO.File]::ReadAllText($mp, $utf8) | ConvertFrom-Json } catch { $row.reason = 'manifest unreadable; kept'; continue }
    $row.state = [string]$man.state
    $when = $(if ($man.state -eq 'restored') { $man.restored } else { $man.published })
    $t = [datetime]::MinValue
    if (-not $when -or -not [datetime]::TryParse([string]$when, [ref]$t)) { $row.reason = "manifest state '$($man.state)' has no finish time; kept"; continue }
    $row.age_days = [int][math]::Floor(($now - $t).TotalDays)
    if ($man.state -eq 'active') {
      $rp = Join-Path $RecordDir (([string]$man.batch) + '.json'); $rs = ''
      if (Test-Path -LiteralPath $rp -PathType Leaf) { try { $rs = [string]([IO.File]::ReadAllText($rp, $utf8) | ConvertFrom-Json).state } catch { $rs = '?' } }
      if ($rs -ne 'published') { $row.reason = "publish record state is '$rs' (not 'published'); an undo may still need this archive; kept"; continue }
    } elseif ($man.state -ne 'restored') { $row.reason = "manifest state '$($man.state)' (publish failed or unfinished; Undo-Publish.ps1 may need it); kept"; continue }
    if ($row.age_days -lt $Days) { $row.reason = "only $($row.age_days) day(s) old (limit $Days)"; continue }
    $row.final = $true; $row.reason = $(if ($man.state -eq 'restored') { 'undone earlier; originals already restored' } else { 'final: after deletion this batch can no longer be undone for its replaced files' })
  }
}
if ($rows.Count -eq 0) { Write-Host "No batch archives in $ArchiveRoot."; return }
$rows | Sort-Object final, day | Format-Table @{ n = 'Delete'; e = { if ($_.final) { 'YES' } else { 'no' } } }, day, batch, files, mib, age_days, state, reason -AutoSize -Wrap | Out-String -Width 220 | Write-Host
$del = @($rows | Where-Object { $_.final })
Write-Host ("{0} of {1} batch archive(s) can be deleted ({2:N1} MiB)." -f $del.Count, $rows.Count, [double](@($del | Measure-Object -Property mib -Sum).Sum))
if ($del.Count -eq 0) { return }
if (-not $Execute) { Write-Host 'DRY RUN. Nothing was deleted. Re-run with -Execute to delete the archives marked YES. Undo-Publish.ps1 cannot restore their originals afterwards.' -ForegroundColor Green; return }
if (-not $Yes) { $c = Read-Host "Type DELETE $($del.Count) to delete these archives permanently"; if ($c -ne "DELETE $($del.Count)") { Write-Host 'Cancelled.'; return } }
$log = Join-Path $ArchiveRoot 'cleanup-log.txt'
foreach ($r in $del) {
  $list = @(Get-ChildItem -LiteralPath $r.folder -Recurse -File -Force | ForEach-Object { $_.FullName.Substring($r.folder.Length) }) -join '; '
  Remove-Item -LiteralPath $r.folder -Recurse -Force
  [IO.File]::AppendAllText($log, ("{0}  deleted {1} ({2} files, {3} MiB, manifest state {4}, age {5} d): {6}`r`n" -f $now.ToString('s'), $r.folder, $r.files, $r.mib, $r.state, $r.age_days, $list), $utf8)
  Write-Host "  deleted $($r.folder)"
  $dayDir = Split-Path $r.folder -Parent
  if (-not (Get-ChildItem -LiteralPath $dayDir -Force | Select-Object -First 1)) { Remove-Item -LiteralPath $dayDir -Force }
}
Write-Host "Done. Logged to $log"
