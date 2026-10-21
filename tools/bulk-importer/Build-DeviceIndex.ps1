$ErrorActionPreference = 'Stop'
$out = 'D:\edutek-system\bulk-loader-fresh-v05\data\device-index.json'
$libs = @(
  @{ key = 'production'; label = 'Edutek library'; root = 'D:\xampp\htdocs\Edutek\videos' },
  @{ key = 'test'; label = 'Test library (Edutek Import Test)'; root = 'C:\Users\Edutek\Desktop\Edutek Import Test\videos' }
)
$items = New-Object System.Collections.ArrayList
$info = @()
foreach ($l in $libs) {
  if (-not (Test-Path -LiteralPath $l.root -PathType Container)) { Write-Warning "Not found, skipped: $($l.root)"; continue }
  $root = $l.root.TrimEnd('\')
  $n = 0
  Get-ChildItem -LiteralPath $root -Recurse -File -Force -ErrorAction SilentlyContinue |
    Where-Object { $_.Extension -match '^\.(pdf|mp4)$' } |
    ForEach-Object {
      $rel = $_.FullName.Substring($root.Length).TrimStart('\') -replace '\\', '/'
      [void]$items.Add([ordered]@{ lib = $l.key; path = $rel; bytes = [int64]$_.Length })
      $n++
    }
  $info += [ordered]@{ key = $l.key; label = $l.label; root = $l.root; count = $n }
  Write-Host ("{0}: {1} PDF/MP4 files" -f $l.label, $n)
}
$doc = [ordered]@{ built = (Get-Date -Format 'yyyy-MM-dd HH:mm'); libraries = $info; items = $items }
$json = $doc | ConvertTo-Json -Depth 5 -Compress
[IO.File]::WriteAllText($out, $json, (New-Object Text.UTF8Encoding($false)))
Write-Host ("Wrote {0} ({1:N0} bytes)" -f $out, (Get-Item $out).Length)
