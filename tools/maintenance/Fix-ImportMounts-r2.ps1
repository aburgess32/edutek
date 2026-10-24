# Fix-ImportMounts.ps1 - the importer no longer needs the old Desktop folders (2026-10-23).
#   Why it was down: compose.override.yaml bound three Desktop folders into the containers with create_host_path: false:
#     C:\Users\Edutek\Desktop\Import Sources -> /import-sources   (web, worker, previews; read-only)
#     C:\Users\Edutek\Desktop\Videos         -> /import-source    (web, worker, previews; read-only; the old "Desktop Videos" source)
#     C:\Users\Edutek\Desktop\Edutek Import Test\videos -> /import-dest (web only; never used by any page or script)
#   After those folders were deleted, Docker could not start the web container after the reboot (Exited 127, "no such file").
#   This installs a new compose.override.yaml without those three binds. Nothing else changes: /host (Browse...), /host/c and
#   /host/d stay read-only, the library is still reached through /host/d, ./app and ./data are the same, the image is the same.
#   Publishing (Publish-Batch.ps1 on Windows, to D:\xampp\htdocs\Edutek\videos) never used these folders.
# Usage (PowerShell, in C:\Users\Edutek):
#   (no args)        check only: hashes, compose config of the new file, compares it with the live file. Changes nothing.
#   -Apply           check, back up compose.override.yaml to D:\edutek-system\backups\import-mounts\<stamp>, install, recreate
#                    web, worker and previews with compose.yaml + compose.override.yaml, then verify http://127.0.0.1:8092.
#   -Rollback latest | -Rollback <backup dir>   put the old file back and recreate (the web container will only start again
#                    if the three Desktop folders exist).
param([switch]$Apply,[string]$Rollback='')
$ErrorActionPreference='Stop'
$App='D:\edutek-system\bulk-loader-fresh-v05'
$Media='C:\Users\Edutek\ImportMounts-media'
$NewFile=Join-Path $Media 'compose.override.nodesktop.yaml'
$NewHash='509A16DB399AACF429F9B1CA543A7357575A2E5419846C2895E3670C65BAB9F0'
$LiveHash='100B3B2110CD1656636D601D921566F6615CEAFFB608D59F552865385F48FADF'
$ComposeHash='D761A7A53BC6D62AC1B6C0657ACCCCC8D93AD4F8E3FFEB9308445B15AE99BDCE'
$Target=Join-Path $App 'compose.override.yaml'
$BackupRoot='D:\edutek-system\backups\import-mounts'
$Docker='C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$Project='edutek-bulk-fresh-v05'
$Services=@('web','worker','previews')
$Gone=@('/import-source','/import-sources','/import-dest')

function Hash-Of([string]$p){ if(-not (Test-Path -LiteralPath $p -PathType Leaf)){ return 'ABSENT' }; (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToUpper() }
function Dk([string[]]$a){
  $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
  $out=& $Docker --context desktop-linux @a 2>&1 | Out-String
  $code=$LASTEXITCODE; $ErrorActionPreference=$old
  [pscustomobject]@{Code=$code;Out=$out.Trim()}
}
function Compose-Args([string]$override){ @('compose','--project-directory',$App,'--env-file',"$App\.env",'-p',$Project,'-f',"$App\compose.yaml",'-f',$override) }
# The merged configuration (stdout only; never printed because it holds the resolved image settings).
function Config-Of([string]$override){
  $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
  $a=(Compose-Args $override)+@('config','--format','json')
  $json=& $Docker --context desktop-linux @a 2>$null | Out-String; $code=$LASTEXITCODE; $ErrorActionPreference=$old
  if($code -ne 0 -or $json.Trim() -eq ''){ throw "docker compose config failed for $override (exit $code). Not touching anything." }
  $json | ConvertFrom-Json
}
function Targets($svc){ @($svc.volumes | ForEach-Object { [string]$_.target }) }
function Strip-Old($cfg){
  foreach($s in $Services){ $v=$cfg.services.$s; if($v -and $v.volumes){ $v.volumes=@($v.volumes | Where-Object { $Gone -notcontains [string]$_.target }) } }
  $cfg
}
function Busy {
  $n=0; $lines=@()
  foreach($f in @(Get-ChildItem -LiteralPath "$App\data\guided-v08" -Directory -ErrorAction SilentlyContinue)){
    $p=Join-Path $f.FullName 'batch.json'; if(-not (Test-Path -LiteralPath $p -PathType Leaf)){ continue }
    try { $s=[string]((Get-Content -LiteralPath $p -Raw -Encoding UTF8 | ConvertFrom-Json).state) } catch { $s='unreadable' }
    if(@('converting','copying','unreadable') -contains $s){ $n++; $lines+="    $($f.Name.Substring(0,8)) $s" }
  }
  [pscustomobject]@{Count=$n;Lines=$lines}
}
function Run-Checks {
  Write-Host 'Checking (read only)...'
  if((Hash-Of $NewFile) -ne $NewHash){ throw "Package file $NewFile does not match its SHA-256 ($(Hash-Of $NewFile))." }
  Write-Host "  new file OK  $NewHash"
  if((Hash-Of "$App\compose.yaml") -ne $ComposeHash){ throw "compose.yaml is not the expected version. Not touching anything." }
  Write-Host '  compose.yaml unchanged, OK'
  $live=Hash-Of $Target
  if($live -eq $NewHash){ $script:Already=$true; Write-Host '  compose.override.yaml ALREADY is the no-Desktop version' }
  elseif($live -eq $LiveHash){ Write-Host "  compose.override.yaml is the expected Patch 4e version ($live)" }
  else { throw "compose.override.yaml is unexpected ($live). Not touching anything." }
  $tok=$null;$err=$null;[void][System.Management.Automation.Language.Parser]::ParseFile($PSCommandPath,[ref]$tok,[ref]$err)
  if($err -and $err.Count -gt 0){ throw "PowerShell parser error in this script: $($err[0].Message)" }
  $new=Config-Of $NewFile
  foreach($s in $Services){ if(-not $new.services.$s){ throw "New configuration has no $s service." } }
  foreach($s in $Services){
    foreach($v in @($new.services.$s.volumes)){ if((([string]$v.source) -replace '\\','/') -match 'Users/Edutek/Desktop'){ throw "New configuration still binds a Desktop folder in ${s}: $($v.source)" } }
    $t=Targets $new.services.$s
    foreach($g in $Gone){ if($t -contains $g){ throw "New configuration still has $g in $s." } }
    foreach($need in @('/sandbox','/sandbox-data')){ if($t -notcontains $need){ throw "New configuration lost $need in $s." } }
  }
  foreach($s in @('web','previews')){
    $t=Targets $new.services.$s
    foreach($need in @('/host','/host/wsl','/host/c','/host/d')){ if($t -notcontains $need){ throw "New configuration lost $need in $s (Browse... needs it)." } }
    foreach($v in @($new.services.$s.volumes)){ if(@('/host','/host/c','/host/d') -contains [string]$v.target -and -not $v.read_only){ throw "$($v.target) in $s is not read-only." } }
  }
  $p=@($new.services.web.ports); if($p.Count -ne 1 -or [string]$p[0].host_ip -ne '127.0.0.1' -or [string]$p[0].published -ne '8092'){ throw 'Web port is not 127.0.0.1:8092 in the new configuration.' }
  Write-Host '  new configuration: no Desktop folders; /host, /host/wsl, /host/c, /host/d (read-only), ./app, ./data and port 127.0.0.1:8092 kept'
  # The live file minus the three Desktop binds must equal the new file exactly (same image, limits, users, networks, commands).
  $liveCfg=Strip-Old (Config-Of $Target)
  $a=$liveCfg | ConvertTo-Json -Depth 50 -Compress; $b=$new | ConvertTo-Json -Depth 50 -Compress
  if($a -ne $b){ throw 'The new configuration differs from the live one in more than the three Desktop folders. Not touching anything.' }
  Write-Host '  compared with the live file: the ONLY difference is the three Desktop binds (/import-source, /import-sources, /import-dest)'
  $r=Dk @('ps','-a','--filter',"label=com.docker.compose.project=$Project",'--format','{{.Names}}  {{.Status}}')
  Write-Host '  containers now:'; $r.Out -split "`n" | ForEach-Object { Write-Host "    $_" }
  $b=Busy
  if($b.Count -gt 0){ Write-Host '  Batches converting or copying (recreating would stop them):'; $b.Lines | ForEach-Object { Write-Host $_ } }
  else { Write-Host '  No batch is converting or copying.' }
  $script:BusyN=$b.Count
  Write-Host 'ALL CHECKS PASSED'
}
function Recreate {
  Write-Host "  Recreating $($Services -join ', ') with compose.yaml + compose.override.yaml..."
  $r=Dk ((Compose-Args $Target)+@('up','-d','--no-build','--pull','never','--no-deps')+$Services)
  Write-Host ('    ' + ($r.Out -replace "`n","`n    "))
  if($r.Code -ne 0){ throw "docker compose up failed (exit $($r.Code))." }
}
function Verify {
  $resp=$null
  for($i=0;$i -lt 20;$i++){ try { $resp=Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8092/bulk-import-guided.php' -TimeoutSec 20; break } catch { Start-Sleep -Seconds 2 } }
  if(-not $resp){ throw 'The importer did not answer on http://127.0.0.1:8092 within 40 s.' }
  if($resp.StatusCode -ne 200 -or $resp.Content -notmatch 'id="src-key"'){ throw "Importer page answered HTTP $($resp.StatusCode) without the Source section." }
  Write-Host '  http://127.0.0.1:8092/bulk-import-guided.php answers HTTP 200'
  if(-not $resp.Content.TrimEnd().EndsWith('</html>')){ Write-Host '  NOTE: the page is cut off at the end (the Patch 4l line-1507 bug). Install-Patch4m.ps1 fixes that - run it next.' }
  foreach($s in $Services){
    $c="$Project-$s-1"
    $r=Dk @('inspect','-f','{{.State.Status}}|{{range .Mounts}}{{.Destination}}={{.RW}};{{end}}',$c)
    if($r.Code -ne 0 -or $r.Out -notmatch '^running\|'){ throw "$c is not running: $($r.Out)" }
    foreach($g in $Gone){ if($r.Out -match ([regex]::Escape("$g=")) ){ throw "$c still has $g." } }
    if($s -ne 'worker'){ foreach($h in @('/host=false','/host/c=false','/host/d=false')){ if($r.Out -notmatch [regex]::Escape($h)){ throw "$c does not have $h (read-only)." } } }
    Write-Host "  $c running; no Desktop folders$(if($s -ne 'worker'){'; /host, /host/c, /host/d read-only'})"
  }
  $r=Dk @('exec',"$Project-web-1",'sh','-c','test -d /host/d/xampp/htdocs/Edutek/videos && test -d /host/c/Users/Edutek/Desktop && test -w /sandbox-data && echo MOUNTS-OK')
  if($r.Out -notmatch 'MOUNTS-OK'){ throw "Web container cannot see the library (/host/d/xampp/htdocs/Edutek/videos), the Desktop for Browse (/host/c/Users/Edutek/Desktop) or write /sandbox-data: $($r.Out)" }
  Write-Host '  web sees the library and the Desktop through /host (Browse... works) and can write its data folder'
}
$script:Already=$false; $script:BusyN=0
if($Rollback -ne ''){
  if($Rollback -eq 'latest'){ $d=Get-ChildItem -LiteralPath $BackupRoot -Directory -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1; if(-not $d){ throw "No backups in $BackupRoot" }; $dir=$d.FullName } else { $dir=$Rollback }
  $bak=Join-Path $dir 'compose.override.yaml'
  if((Hash-Of $bak) -ne $LiveHash){ throw "Backup $bak is not the expected Patch 4e file." }
  $now=Hash-Of $Target; if($now -ne $NewHash -and $now -ne $LiveHash){ throw "Live compose.override.yaml is unexpected ($now). Not restoring." }
  if((Busy).Count -gt 0){ throw 'A batch is converting or copying. Wait until its page says it is staged, then roll back.' }
  Copy-Item -LiteralPath $bak -Destination $Target -Force
  if((Hash-Of $Target) -ne $LiveHash){ throw 'Restore verification failed.' }
  Write-Host "  restored $Target  $LiveHash"
  foreach($p in 'C:\Users\Edutek\Desktop\Import Sources','C:\Users\Edutek\Desktop\Videos','C:\Users\Edutek\Desktop\Edutek Import Test\videos'){ if(-not (Test-Path -LiteralPath $p -PathType Container)){ Write-Host "  WARNING: $p does not exist; the containers that need it will not start." } }
  Recreate
  Write-Host "ROLLBACK DONE from $dir"
}
elseif($Apply){
  Run-Checks
  if($script:BusyN -gt 0){ throw 'A batch is converting or copying. Wait until it is staged, then run -Apply again.' }
  if(-not $script:Already){
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'; $dir=Join-Path $BackupRoot $stamp
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    Copy-Item -LiteralPath $Target -Destination (Join-Path $dir 'compose.override.yaml')
    if((Hash-Of (Join-Path $dir 'compose.override.yaml')) -ne $LiveHash){ throw 'Backup copy hash mismatch; nothing installed.' }
    Set-Content -LiteralPath (Join-Path $dir 'hashes.txt') -Encoding ASCII -Value @("BACKUP $LiveHash  $Target","NEW    $NewHash  $NewFile","Made $(Get-Date -Format s) by Fix-ImportMounts.ps1")
    Write-Host "  Backup: $dir"
    Copy-Item -LiteralPath $NewFile -Destination $Target -Force
    if((Hash-Of $Target) -ne $NewHash){ throw 'Installed file hash mismatch.' }
    Write-Host "  installed $Target  $NewHash"
  }
  Recreate
  Verify
  Write-Host 'IMPORT MOUNTS FIXED. Next: Install-Patch4m.ps1 (check, -E2E, -Apply).'
}
else { Run-Checks; Write-Host '(check only - nothing changed. Run with -Apply to install and recreate the containers.)' }
