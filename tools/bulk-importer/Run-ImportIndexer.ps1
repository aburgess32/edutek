param([switch]$DryRun,[switch]$Once)
$ErrorActionPreference = 'Stop'
$trig   = 'D:\edutek-system\bulk-loader-fresh-v05\data\index-trigger'
$status = 'D:\edutek-system\index-status'
$mdir   = Join-Path $status 'manifests'
New-Item -ItemType Directory -Force -Path $trig, $mdir | Out-Null
$enc = New-Object Text.UTF8Encoding($false)
$lockPath = Join-Path $trig 'runner.lock'
try { $lock = [IO.File]::Open($lockPath, 'OpenOrCreate', 'ReadWrite', 'None') } catch { Write-Host 'Another runner is already running.'; exit 1 }
function Write-Result($name, $obj) {
  $tmp = Join-Path $trig ($name + '.result.tmp')
  [IO.File]::WriteAllText($tmp, ($obj | ConvertTo-Json -Compress), $enc)
  Move-Item $tmp (Join-Path $trig ($name + '.result.json')) -Force
}
try {
  Write-Host "Watching $trig  (Ctrl+C to stop)"
  do {
    $reqs = @(Get-ChildItem $trig -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
    if ($reqs.Count -gt 0) {
      Start-Sleep -Seconds 2
      $reqs = @(Get-ChildItem $trig -Filter '*.request.json' -File | Sort-Object LastWriteTime)
      $files = New-Object System.Collections.Generic.HashSet[string]
      $full = $false
      $valid = @()
      foreach ($r in $reqs) {
        $base = $r.Name -replace '\.request\.json$', ''
        $j = $null
        try { $j = Get-Content $r.FullName -Raw -Encoding UTF8 | ConvertFrom-Json } catch {}
        if ($j -and $j.mode -eq 'full') { $full = $true; $valid += $r }
        elseif ($j -and $j.files -and @($j.files).Count -gt 0) { foreach ($f in @($j.files)) { [void]$files.Add([string]$f) }; $valid += $r }
        else {
          Write-Result $base ([ordered]@{ batch = $base; state = 'failed'; exit_code = 2; output = 'Request has no files list. Use "mode":"full" for a whole-library index.' })
          Rename-Item -LiteralPath $r.FullName -NewName ($r.Name + '.' + [guid]::NewGuid().ToString('N') + '.done')
          Write-Host ("{0}  {1}  rejected (no files list)" -f (Get-Date -Format HH:mm:ss), $base)
        }
      }
      if ($valid.Count -gt 0) {
        $started = Get-Date
        $manifestName = $null
        if (-not $full) {
          $manifestName = 'idx-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json'
          [IO.File]::WriteAllText((Join-Path $mdir $manifestName), (ConvertTo-Json -InputObject @($files) -Compress), $enc)
        }
        if ($DryRun) { $out = "DRY RUN: index " + $(if ($full) { 'whole library' } else { "$($files.Count) listed files" }); $code = 0 }
        elseif ($full) { $out = (& docker exec edupak-app php /var/www/html/api/index-content-cli.php /content 2>&1 | Out-String); $code = $LASTEXITCODE }
        else { $out = (& docker exec edupak-app php /var/www/html/api/index-content-cli.php /content ("--files=/system/index-status/manifests/" + $manifestName) 2>&1 | Out-String); $code = $LASTEXITCODE }
        $finished = Get-Date
        $audit = Get-ChildItem $status -Filter 'index-run-*.csv' -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -ge $started.AddSeconds(-5) } | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        $tail = if ($out.Length -gt 2000) { $out.Substring($out.Length - 2000) } else { $out }
        foreach ($r in $valid) {
          $base = $r.Name -replace '\.request\.json$', ''
          Write-Result $base ([ordered]@{ batch = $base; state = $(if ($code -eq 0) { 'complete' } else { 'failed' }); exit_code = $code; mode = $(if ($full) { 'full' } else { 'files' }); files = $files.Count; started = $started.ToString('s'); finished = $finished.ToString('s'); audit = $(if ($audit) { $audit.Name } else { $null }); output = $tail.Trim() })
          Rename-Item -LiteralPath $r.FullName -NewName ($r.Name + '.' + [guid]::NewGuid().ToString('N') + '.done')
          Write-Host ("{0}  {1}  exit {2}" -f (Get-Date -Format HH:mm:ss), $base, $code)
        }
      }
    }
    if (-not $Once) { Start-Sleep -Seconds 5 }
  } while (-not $Once)
} finally { $lock.Close() }
