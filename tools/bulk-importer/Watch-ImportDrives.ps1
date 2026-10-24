# Watch-ImportDrives.ps1 - Edutek importer (patch 4d, 4e): connects USB drives to the importer's folder browser, read-only,
# and disconnects them when they are unplugged or the page asks to "Prepare to eject". Runs as the
# "Edutek Import Drive Watcher" scheduled task at logon. C: and D: are never mounted or unmounted here.
# Nothing on any drive is written. It writes only data\host-drives.json and files in data\drive-queue and data\folder-pick.
# 4e: a drive mounted several times over is now fully disconnected (every layer), and the page's "Browse..." button
# opens a Windows folder window through Pick-ImportFolder.ps1 (started here, one at a time, closed after 5 minutes).
# Console use (the task keeps running alongside; operations are serialized):
#   .\Watch-ImportDrives.ps1 -List           show drives; changes nothing
#   .\Watch-ImportDrives.ps1 -Once           one refresh (connect new USB drives, drop unplugged ones)
#   .\Watch-ImportDrives.ps1 -Disconnect F   prepare F: for ejecting (refused while the importer reads from it)
#   .\Watch-ImportDrives.ps1 -Connect F      connect F: again after "Prepare to eject"
param(
  [switch]$List, [switch]$Once, [string]$Disconnect = '', [string]$Connect = '',
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$Wsl = (Join-Path $env:WINDIR 'System32\wsl.exe'),
  [int]$PollSeconds = 20, [int]$MaxLoops = 0
)
$ErrorActionPreference = 'Stop'
$data = Join-Path $Root 'data'; $q = Join-Path $data 'drive-queue'
$hostJson = Join-Path $data 'host-drives.json'; $stateFile = Join-Path $q 'drive-state.json'
$logFile = Join-Path $q 'drive-watch.txt'; $lastFile = Join-Path $q 'last-result.json'
$web = 'edutek-bulk-fresh-v05-web-1'; $prev = 'edutek-bulk-fresh-v05-previews-1'
$okFs = @('NTFS', 'FAT32', 'FAT', 'EXFAT')
$enc = New-Object System.Text.UTF8Encoding($false)
New-Item -ItemType Directory -Force -Path $q | Out-Null
$script:errSeen = @{}; $script:dockerUp = $null

function Log([string]$m) {
  $line = '{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m; Write-Host $line
  try {
    if ((Test-Path -LiteralPath $logFile) -and (Get-Item -LiteralPath $logFile).Length -gt 1MB) { Move-Item -LiteralPath $logFile -Destination (Join-Path $q 'drive-watch.1.txt') -Force }
    [IO.File]::AppendAllText($logFile, $line + "`r`n", $enc)
  } catch { }
}
function Save-Json([string]$p, $o) { $t = $p + '.tmp'; [IO.File]::WriteAllText($t, ($o | ConvertTo-Json -Depth 6), $enc); Move-Item -LiteralPath $t -Destination $p -Force }
function Run-Native([string]$exe, [string[]]$a) { $ErrorActionPreference = 'Continue'; $o = & $exe @a 2>&1; [pscustomobject]@{ Code = $LASTEXITCODE; Out = ((@($o) | ForEach-Object { "$_" }) -join "`n").Trim() } }
function Vm([string]$cmd) { Run-Native $Wsl @('-d', 'docker-desktop', '-u', 'root', '-e', 'sh', '-c', $cmd) }
function Docker-Ready {
  if (-not (Test-Path -LiteralPath $Docker)) { return $false }
  $r = Run-Native $Docker @('--context', 'desktop-linux', 'info', '--format', '{{.ServerVersion}}'); return ($r.Code -eq 0 -and $r.Out -match '^\d')
}
function Vm-Mounts { $m = @{}; $r = Vm 'cat /proc/mounts'; if ($r.Code -ne 0) { return $null }
  foreach ($l in ($r.Out -split "`n")) { $f = $l -split ' '; if ($f.Count -gt 2 -and $f[1] -match '^/mnt/host/([a-z])$') { $m[$Matches[1]] = $true } }; $m }
function Seen-In([string]$container, [string]$l) { $r = Run-Native $Docker @('--context', 'desktop-linux', 'exec', $container, 'cat', '/proc/self/mountinfo'); if ($r.Code -ne 0) { return $null }; return [bool]($r.Out -match (' /host/' + $l + ' '))}
function Load-State { if (Test-Path -LiteralPath $stateFile) { try { $s = [IO.File]::ReadAllText($stateFile, $enc) | ConvertFrom-Json; $h = @{}; foreach ($p in @($s.ejected.PSObject.Properties)) { $h[$p.Name] = [string]$p.Value }; return $h } catch { Log "drive-state.json unreadable, starting fresh: $($_.Exception.Message)" } }; @{} }
function Save-State($ej) { Save-Json $stateFile ([ordered]@{ ejected = $ej; written = (Get-Date).ToString('s') }) }
function Result([string]$req, [string]$l, [bool]$ok, [string]$msg) { Log $msg; Save-Json $lastFile ([ordered]@{ request = $req; drive = $l; ok = $ok; message = $msg; at = (Get-Date).ToString('o') }) }

# Volumes with a letter. A drive is handled here ("auto") when it is not C:/D:, has a supported file system, and is
# removable or sits on the USB bus (external USB hard drives report Fixed but BusType USB).
function Get-Drives {
  $bus = @{}
  try { $disks = @{}; foreach ($d in @(Get-Disk -ErrorAction Stop)) { $disks[[int]$d.Number] = [string]$d.BusType }
    foreach ($p in @(Get-Partition -ErrorAction Stop | Where-Object { $_.DriveLetter })) { $bus[([string]$p.DriveLetter).ToLower()] = $disks[[int]$p.DiskNumber] } } catch { }
  $out = @()
  foreach ($v in @(Get-Volume | Where-Object { $_.DriveLetter -and ($_.DriveType -eq 'Fixed' -or $_.DriveType -eq 'Removable') } | Sort-Object DriveLetter)) {
    $k = ([string]$v.DriveLetter).ToLower(); $b = [string]$bus[$k]; $fs = ([string]$v.FileSystem).ToUpper()
    $auto = ($k -ne 'c' -and $k -ne 'd' -and $v.Size -gt 0 -and $okFs -contains $fs -and ([string]$v.DriveType -eq 'Removable' -or $b -eq 'USB'))
    $out += [pscustomobject]@{ l = $k; label = [string]$v.FileSystemLabel; type = [string]$v.DriveType; bus = $b; fs = [string]$v.FileSystem; size = [int64]$v.Size; free = [int64]$v.SizeRemaining; id = [string]$v.UniqueId; auto = $auto }
  }
  $out
}
function Write-HostJson($drives, $m, $ej, [bool]$ready, $errs) {
  $rows = @(foreach ($d in $drives) {
    $con = [bool]($m -and $m[$d.l])
    if ($d.l -eq 'c' -or $d.l -eq 'd' -or -not $d.auto) { $state = $(if ($con) { 'connected' } else { 'not_shared' }) }
    elseif ($ej.ContainsKey($d.l)) { $state = 'safe_to_remove' }
    elseif (-not $ready) { $state = 'waiting' }
    elseif ($con) { $state = 'connected' } elseif ($errs.ContainsKey($d.l)) { $state = 'error' } else { $state = 'connecting' }
    [ordered]@{ letter = $d.l.ToUpper(); label = $d.label; type = $d.type; bus = $d.bus; fs = $d.fs; size = $d.size; free = $d.free; auto = $d.auto; connected = $con; state = $state; message = [string]$errs[$d.l] } })
  Save-Json $hostJson ([ordered]@{ written = (Get-Date).ToString('o'); docker_ready = $ready; drives = $rows })
}
# One pass: connect new USB drives read-only, drop unplugged ones, forget eject marks of removed drives, update the drive list.
function Reconcile([string]$why) {
  $drives = @(Get-Drives); $ej = Load-State; $errs = @{}; $present = @{}; foreach ($d in $drives) { $present[$d.l] = $d }
  foreach ($k in @($ej.Keys)) { if (-not $present.ContainsKey($k) -or $present[$k].id -ne $ej[$k]) { $ej.Remove($k); Log "$($k.ToUpper()): was removed; its eject mark is cleared" } }
  Save-State $ej
  $ready = Docker-Ready; $m = $null
  if ($ready) { $m = Vm-Mounts; if ($null -eq $m) { $ready = $false } }
  if ($ready -ne $script:dockerUp) { Log $(if ($ready) { 'Docker is ready' } else { 'Docker is not ready yet; will retry' }); $script:dockerUp = $ready }
  if ($ready) {
    foreach ($k in @($m.Keys)) {
      if ($k -eq 'c' -or $k -eq 'd' -or $present.ContainsKey($k)) { continue }
      $r = Vm "for i in 1 2 3 4 5 6 7 8 9 10; do grep -q ' /mnt/host/$k ' /proc/mounts || exit 0; umount -l /mnt/host/$k || exit 1; done; exit 1"; if ($r.Code -eq 0) { Log "$($k.ToUpper()): unplugged; disconnected from the importer" } else { Log "$($k.ToUpper()): unplugged; could not disconnect: $($r.Out)" }
      $m.Remove($k)
    }
    foreach ($d in $drives) {
      if (-not $d.auto -or $m[$d.l] -or $ej.ContainsKey($d.l)) { continue }
      $r = Vm "mkdir -p /mnt/host/$($d.l) && mount -t drvfs $($d.l.ToUpper()): /mnt/host/$($d.l) -o ro,noatime"
      if ($r.Code -eq 0) { $m[$d.l] = $true; $script:errSeen.Remove($d.l); Log "$($d.l.ToUpper()): $($d.label) connected (read-only)" }
      else { $errs[$d.l] = $r.Out; if ($script:errSeen[$d.l] -ne $r.Out) { Log "$($d.l.ToUpper()): could not connect: $($r.Out)"; $script:errSeen[$d.l] = $r.Out } }
    }
  }
  Write-HostJson $drives $m $ej $ready $errs
}
# Why the importer is reading from drive $l right now, or $null.
function Busy-Reason([string]$l) {
  $cut = (Get-Date).AddMinutes(-15)
  if (@(Get-ChildItem -LiteralPath $q -Filter "busy-$l-*.json" -File -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -gt $cut }).Count) { return "a folder scan is reading from $($l.ToUpper()): right now" }
  $pat = '"src_root"\s*:\s*"(\\?/)host(\\?/)' + $l + '(\\?/|")'
  foreach ($b in @(Get-ChildItem -Path (Join-Path $data 'guided-v08\*\batch.json') -File -ErrorAction SilentlyContinue)) {
    $t = [IO.File]::ReadAllText($b.FullName, $enc); if ($t -notmatch '"importing"' -or $t -notmatch $pat) { continue }
    try { $j = $t | ConvertFrom-Json; if ($j.state -eq 'importing' -and ([string]$j.src_root) -match ('^/host/' + $l + '(/|$)')) { return "import $(([string]$j.id).Substring(0,8)) is copying files from $($l.ToUpper()):" } } catch { }
  }
  foreach ($f in @(Get-ChildItem -Path (Join-Path $data 'video-previews-v1\jobs\*.json') -File -ErrorAction SilentlyContinue)) { if ([IO.File]::ReadAllText($f.FullName, $enc) -match $pat) { return "previews are being made from $($l.ToUpper()):" } }
  $null
}
function Eject([string]$l, [string]$req) {
  $drives = @(Get-Drives); $d = @($drives | Where-Object { $_.l -eq $l })[0]
  if ($l -eq 'c' -or $l -eq 'd') { Result $req $l $false 'C: and D: stay connected.'; return }
  if (-not $d) { Result $req $l $false "$($l.ToUpper()): is not plugged in."; return }
  if (-not $d.auto) { Result $req $l $false "$($l.ToUpper()): is not a USB drive managed by the importer."; return }
  $why = Busy-Reason $l; if ($why) { Result $req $l $false "Not ejected: $why. Wait until it finishes, then choose Prepare to eject again."; Reconcile 'eject refused'; return }
  $name = "$($l.ToUpper()): $($d.label)".Trim()
  if (Docker-Ready) {
    $m = Vm-Mounts
    if ($m -and $m[$l]) {
      $r = Vm "for i in 1 2 3 4 5 6 7 8 9 10; do grep -q ' /mnt/host/$l ' /proc/mounts || exit 0; umount /mnt/host/$l || exit 1; done; exit 1"
      if ($r.Code -ne 0) { Result $req $l $false "Not ejected: $name is still in use by the importer ($($r.Out)). Close any scan of that drive and try again."; Reconcile 'eject failed'; return }
      foreach ($c in $web, $prev) { if (Seen-In $c $l) { Result $req $l $false "Not ejected yet: the importer ($c) still has $name open. Wait a minute and try again."; Reconcile 'eject pending'; return } }
    }
  }
  $ej = Load-State; $ej[$l] = $d.id; Save-State $ej
  Result $req $l $true "$name is safe to remove."
  Reconcile 'eject'
}
function Connect-Again([string]$l, [string]$req) {
  $ej = Load-State; if ($ej.ContainsKey($l)) { $ej.Remove($l); Save-State $ej }
  Reconcile 'connect'
  $j = [IO.File]::ReadAllText($hostJson, $enc) | ConvertFrom-Json; $e = @($j.drives | Where-Object { $_.letter -eq $l.ToUpper() })[0]
  if ($e -and $e.connected) { Result $req $l $true "$($l.ToUpper()): $($e.label) connected (read-only)." } else { Result $req $l $false "$($l.ToUpper()): could not be connected yet$(if ($e -and $e.message) { ': ' + $e.message } else { '' })." }
}
function With-Lock([scriptblock]$b) {
  $mx = New-Object System.Threading.Mutex($false, 'Local\EdutekImportDrives'); $got = $false
  try { try { $got = $mx.WaitOne(120000) } catch [System.Threading.AbandonedMutexException] { $got = $true }; if (-not $got) { throw 'Another drive operation is still running.' }; & $b } finally { if ($got) { $mx.ReleaseMutex() }; $mx.Dispose() }
}
function Letter([string]$s) { $x = $s.Trim().TrimEnd(':').ToLower(); if ($x -notmatch '^[a-z]$') { throw 'Use a drive letter, for example F' }; $x }
function Process-Requests {
  foreach ($r in @(Get-ChildItem -LiteralPath $q -Filter '*.request.json' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTime)) {
    $n = $r.Name; try { Remove-Item -LiteralPath $r.FullName -Force } catch { continue }
    if ($n -cmatch '^refresh-all\.request\.json$') { With-Lock { Reconcile 'refresh'; Result 'refresh' '' $true 'Drive list updated.' } }
    elseif ($n -cmatch '^eject-([a-z])\.request\.json$') { $l = $Matches[1]; With-Lock { Eject $l 'eject' } }
    elseif ($n -cmatch '^connect-([a-z])\.request\.json$') { $l = $Matches[1]; With-Lock { Connect-Again $l 'connect' } }
    else { Log "ignored unexpected request $n" }
  }
}
# 4e: "Browse..." on the page writes data\folder-pick\request.json. Start Pick-ImportFolder.ps1 as its own process (so drives
# keep being handled while the window is open) with fixed arguments only; it reads and checks the request itself.
$pq = Join-Path $data 'folder-pick'; $pickScript = Join-Path $Root 'Pick-ImportFolder.ps1'; $script:pickProc = $null; $script:pickAt = Get-Date
function Process-Pick {
  if ($script:pickProc) {
    if (-not $script:pickProc.HasExited) {
      if (((Get-Date) - $script:pickAt).TotalSeconds -lt 300) { return }
      try { $script:pickProc.Kill() } catch { }
      $op = Join-Path $pq 'open.json'
      try { $id = [string](([IO.File]::ReadAllText($op, $enc) | ConvertFrom-Json).id); if ($id -cmatch '^[a-f0-9]{32}$') { Save-Json (Join-Path $pq 'result.json') ([ordered]@{ id = $id; state = 'timeout'; path = ''; message = ''; at = (Get-Date).ToString('o') }) } } catch { }
      Remove-Item -LiteralPath $op -ErrorAction SilentlyContinue
      Log 'folder window closed after 5 minutes without a choice'
    } else { Log "folder window finished (exit $($script:pickProc.ExitCode))" }
    $script:pickProc = $null
  }
  $rq = Join-Path $pq 'request.json'
  if (-not (Test-Path -LiteralPath $rq -PathType Leaf)) { return }
  if (-not (Test-Path -LiteralPath $pickScript -PathType Leaf)) { Remove-Item -LiteralPath $rq -ErrorAction SilentlyContinue; Log 'folder window requested, but Pick-ImportFolder.ps1 is missing'; return }
  $script:pickAt = Get-Date
  $script:pickProc = Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -WindowStyle Hidden -PassThru -ArgumentList @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-STA', '-WindowStyle', 'Hidden', '-File', ('"' + $pickScript + '"'), '-Root', ('"' + $Root + '"'))
  Log 'folder window requested by the page; opening it'
}

if ($List) {
  $ej = Load-State; $m = $null; if (Docker-Ready) { $m = Vm-Mounts }
  ''; 'Drive  Type       Bus    Label                 Free GB  Importer'
  foreach ($d in @(Get-Drives)) { $s = $(if ($m -and $m[$d.l]) { 'connected (read-only)' } elseif ($ej.ContainsKey($d.l)) { 'safe to remove' } elseif ($d.auto) { 'not connected' } else { 'not handled (fixed drive)' }); '{0}:     {1,-10} {2,-6} {3,-20} {4,8:N1}  {5}' -f $d.l.ToUpper(), $d.type, $d.bus, $d.label, ($d.free / 1GB), $s }
  $hb = Join-Path $q 'heartbeat.txt'; if (Test-Path -LiteralPath $hb) { "Watcher heartbeat: $([int]((Get-Date) - (Get-Item -LiteralPath $hb).LastWriteTime).TotalSeconds) s ago" } else { 'Watcher heartbeat: none (task not running?)' }
  return
}
if ($Disconnect) { $l = Letter $Disconnect; With-Lock { Eject $l 'console' }; return }
if ($Connect) { $l = Letter $Connect; With-Lock { Connect-Again $l 'console' }; return }
if ($Once) { With-Lock { Reconcile 'console' }; 'Drive list saved: ' + $hostJson; return }

try { $lock = [IO.File]::Open((Join-Path $q 'watcher.lock'), 'OpenOrCreate', 'ReadWrite', 'None') } catch { Write-Host 'Another drive watcher is already running.'; exit 0 }
try {
  $events = $false
  try { Register-CimIndicationEvent -ClassName Win32_VolumeChangeEvent -SourceIdentifier EdutekVolumeChange -ErrorAction Stop; $events = $true } catch { Log "volume-change events unavailable ($($_.Exception.Message)); polling every $PollSeconds s" }
  Log "Drive watcher started (events: $events, poll: $PollSeconds s)"
  $next = Get-Date; $loops = 0
  while ($true) {
    try {
      [IO.File]::WriteAllText((Join-Path $q 'heartbeat.txt'), (Get-Date).ToString('s'), $enc)
      if ($events) { $ev = @(Get-Event -SourceIdentifier EdutekVolumeChange -ErrorAction SilentlyContinue); if ($ev.Count) { $ev | Remove-Event; Log "volume change event ($($ev.Count))"; Start-Sleep -Seconds 2; $next = Get-Date } }
      Process-Requests
      Process-Pick
      if ((Get-Date) -ge $next) { With-Lock { Reconcile 'poll' }; $next = (Get-Date).AddSeconds($PollSeconds) }
    } catch { Log "error (will retry): $($_.Exception.Message)"; $next = (Get-Date).AddSeconds([Math]::Max($PollSeconds, 30)) }
    $loops++; if ($MaxLoops -gt 0 -and $loops -ge $MaxLoops) { break }
    Start-Sleep -Milliseconds 1000
  }
} finally { if ($events) { Unregister-Event -SourceIdentifier EdutekVolumeChange -ErrorAction SilentlyContinue }; $lock.Close() }
