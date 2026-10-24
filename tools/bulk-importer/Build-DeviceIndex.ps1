# Edutek device index v3 (patch 4: adds mtime = last write time as UTC FileTime, used to detect changes since review; v2 from patch 3b). Lists the pdf/mp4 files of the Edutek library for the guided import page's duplicate check.
# Run by the "Edutek Import Publisher" task after each publish and every 6 hours; it can also be run by hand.
# Scan errors are recorded in the index (error_count, errors) and give exit code 1; nothing is hidden.
param(
  [string]$Out = 'D:\edutek-system\bulk-loader-fresh-v05\data\device-index.json',
  [string]$Root = 'D:\xampp\htdocs\Edutek\videos'
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $Root -PathType Container)) { Write-Host "Library folder not found: $Root. The index was not changed."; exit 2 }
$sw = [Diagnostics.Stopwatch]::StartNew()
$base = $Root.TrimEnd('\', '/')
$items = New-Object System.Collections.ArrayList
$scanErr = $null
Get-ChildItem -LiteralPath $base -Recurse -File -Force -ErrorAction SilentlyContinue -ErrorVariable scanErr |
  Where-Object { $_.Extension -match '^\.(pdf|mp4)$' } |
  ForEach-Object {
    $rel = $_.FullName.Substring($base.Length).TrimStart('\', '/') -replace '\\', '/'
    [void]$items.Add([ordered]@{ lib = 'production'; path = $rel; bytes = [int64]$_.Length; mtime = $_.LastWriteTimeUtc.ToFileTimeUtc() })
  }
$nErr = 0; if ($scanErr) { $nErr = $scanErr.Count }
$errs = @($scanErr | Where-Object { $_ } | Select-Object -First 20 | ForEach-Object { [string]$_.Exception.Message })
$doc = [ordered]@{
  version = 3; built = (Get-Date -Format 'yyyy-MM-dd HH:mm'); built_epoch = [DateTimeOffset]::Now.ToUnixTimeSeconds()
  scan_seconds = [math]::Round($sw.Elapsed.TotalSeconds, 1); complete = ($nErr -eq 0); error_count = $nErr; errors = $errs
  libraries = @([ordered]@{ key = 'production'; label = 'Edutek library'; root = $Root; count = $items.Count }); items = $items
}
$json = $doc | ConvertTo-Json -Depth 5 -Compress
$tmp = $Out + '.tmp'
[IO.File]::WriteAllText($tmp, $json, (New-Object Text.UTF8Encoding($false)))
for ($t = 1; $true; $t++) { try { Move-Item -LiteralPath $tmp -Destination $Out -Force; break } catch { if ($t -ge 5) { throw }; Start-Sleep -Seconds 1 } }
Write-Host ("Edutek library: {0} PDF/MP4 files, {1} scan error(s). Wrote {2} ({3:N0} bytes) in {4:N1} s" -f $items.Count, $nErr, $Out, (Get-Item -LiteralPath $Out).Length, $sw.Elapsed.TotalSeconds)
foreach ($m in $errs) { Write-Host ("  scan error: " + $m) }
if ($nErr -gt 0) { exit 1 }
