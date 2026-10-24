# Install-Patch4p.ps1 - Importer Patch 4p (on top of Patch 4o): damaged/unsupported files listed as left out, a confirm before
#   new categories/shelves are made, books shelved by you, and an import summary on the Done screen.
#   1) A damaged or cut-short video is never ticked and cannot be ticked; it is shown in the left-out list with its reason.
#   2) Before publishing, a confirm lists the NEW categories, NEW Books shelves and new subfolders under new categories;
#      Y confirms, "No" has the focus.
#   3) Files that cannot be imported (.mov, .mkv, stray pictures, other files) are listed as left out with a reason - never silently
#      skipped. (A later patch could convert .mov/.mkv with the 4i conversion.)
#   4) Done-screen links use this page's host name with port 8080 (no fixed IP); the screen says when a fallback is used.
#   5) Books are never shelved by folder name: you pick a shelf (type to filter, C = new shelf, select several + M). Next stays
#      blocked until every book has a shelf or is skipped. Loose audio still goes to Music > <import folder> (unchanged).
#   Done screen: totals and time, per category/subcategory counts with category links, Open PDF per book, the left-out list,
#   Start a new import (N, focused), Undo (U, asks; "Keep" focused).
#   Unchanged: 4o screens and audio conversion, 4i video conversion, publish through Publish-Batch.ps1, undo. No saved import is changed.
# Files (in D:\edutek-system\bulk-loader-fresh-v05):
#   app\public\bulk-import-guided.php   (changed; replaces the Patch 4o file)
# Usage:
#   (no args)        check only: hashes, PowerShell parser, php -l and unit tests in the web container (real test conversions in
#                    the container /tmp), library folder count. Changes nothing.
#   -E2E             end-to-end tests in THROWAWAY containers and temp folders (production data is not mounted). Changes nothing live.
#                    1) importer tests (4i video, 4k/4l categories, 4m, 4n, 4o audio, 4p: a COPY of Desktop\Import Big Test plus a
#                       cut-short video, .mov/.mkv, a stray picture and a text file; books waiting for a shelf; Done summary)
#                    2) importer with no legacy folders, 3) keyboard-only walk in Edge on the Big Test copy
#                       (screenshots in C:\Users\Edutek\Patch4p-walk-<stamp>)
#   -Apply           check, back up to D:\edutek-system\backups\importer-4p\<stamp>, install, verify (auto-restores if verification fails)
#   -Rollback latest | -Rollback <backup dir>   restore a backup (back to Patch 4o)
# No restart is needed: the PHP server reads files on every request.
param([switch]$Apply,[string]$Rollback='',[switch]$E2E)
$ErrorActionPreference='Stop'
$App='D:\edutek-system\bulk-loader-fresh-v05'
$Src=$PSScriptRoot
$Lib='D:\xampp\htdocs\Edutek\videos'
$Files=@(
  [pscustomobject]@{Name='bulk-import-guided.php'; Target="$App\app\public\bulk-import-guided.php"; New='BC0C3912DD87854B2DF41B059CE9040B26BB71DDCB20F73C0B8FC1E88429F68B';
    Live=@('9B894605F4244DE79CD7E323ED9CD7F62056769A836FF76B406789C9D09AA8E6')}
)
$LiveName=@{'9B894605F4244DE79CD7E323ED9CD7F62056769A836FF76B406789C9D09AA8E6'='Patch 4o'}
$Ec5='ec5de67dfca159e585ccae48900ffa8d'
# Unchanged files 4p depends on.
$Deps=@(
  [pscustomobject]@{Name='edutek-safe-name.php';     Path="$App\app\lib\edutek-safe-name.php";          Hash='FBBFBF56BAEBE6F7C41093EBCE088677C877E4DCAA52E04755932886CB4D3896'},
  [pscustomobject]@{Name='edutek-safe-name-cli.php'; Path="$App\app\lib\edutek-safe-name-cli.php";      Hash='5D6B661CE4485A199A8BAFD9AC3E174D1A16935C0035F8FDAF425BB37E8E4D47'},
  [pscustomobject]@{Name='video-preview-lib.php';    Path="$App\app\public\video-preview-lib.php";      Hash='B2E24E1C2C3832884175BB98097A346F31B2FD9C2506A8936C533F872ADA8096'},
  [pscustomobject]@{Name='video-preview-worker.php'; Path="$App\app\public\video-preview-worker.php";   Hash='6A0871280F6E5DBBC468EB83E2B4AB9EA18D58F1C913AC2BAF751D6069BB25F0'},
  [pscustomobject]@{Name='Publish-Batch.ps1';        Path="$App\Publish-Batch.ps1";                     Hash='76F63F10A798D89CD34CFB0FC07BEEDB78C164A122F4220D414F1A665CE2250E'}
)
$Pkg=@(
  [pscustomobject]@{Name='unit-p4p-v3.php';      Hash='DAF13DE9D6A0E3DE9622A4A57B2607773FBFA42D125DAA3D936128050320DE0E'},
  [pscustomobject]@{Name='unit-p4o.php';         Hash='CE594CA643CEACE3D8A1BA917FC9C5BA1B1EBE7874F6BD9E12062C93875CF2CA'},
  [pscustomobject]@{Name='pairs-4p.json';        Hash='2DADE59D5EE9FDBAE11DDD87CEFD34CB84CE4382E1B8799BF764976F100054E7'},
  [pscustomobject]@{Name='e2e-p4p-v3.php';       Hash='F2E3180CFC433B10FBCA15B91CA8807A7F6F84F264F730338F392FF90BA9CEF7'},
  [pscustomobject]@{Name='make-4p-clips.sh';     Hash='C04467C53731696CFB2005DB7327DC6E7FF93E159A066E8D227954369CBED185'},
  [pscustomobject]@{Name='make-test-clips.sh';   Hash='26FA1D62B0269B20E710B712F256A9E46B12548A45B5DE17334D6C18C1400F3A'},
  [pscustomobject]@{Name='make-cat-clips.sh';    Hash='8B5FE76C7B3891BAD1B909250C2FD0CE63D9A437715324FF4A950F9C9812D124'},
  [pscustomobject]@{Name='make-audio-clips.sh';  Hash='F5513FC3A291A90EB4EF8FAA5F4613230DDCDB905BB98E5665FE35F5AD58128C'},
  [pscustomobject]@{Name='router-4o.php';        Hash='29EFEF03EC6CDBC2CC84B43F316ECA023BFF048830EBF56CE197F197329660F5'},
  [pscustomobject]@{Name='walk-4p-v3.js';        Hash='23A6CEFA37D018266EA6137107C6EB5DC306467F2591F1BEAAB558D8FAEEEF11'}
)
$BackupRoot='D:\edutek-system\backups\importer-4p'
$Big='C:\Users\Edutek\Desktop\Import Big Test'
$Docker='C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$Web='edutek-bulk-fresh-v05-web-1'
$Node='C:\Program Files\nodejs\node.exe'
$Edge='C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$WalkPort=18492

function Hash-Of([string]$p){ if(-not (Test-Path -LiteralPath $p -PathType Leaf)){ return 'ABSENT' }; (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToUpper() }
function Dk([string[]]$a){
  $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
  $out=& $Docker --context desktop-linux @a 2>&1 | Out-String
  $code=$LASTEXITCODE; $ErrorActionPreference=$old
  [pscustomobject]@{Code=$code;Out=$out.Trim()}
}
# docker cp sometimes answers "Could not find the file /tmp" on this Docker Desktop; then the file is sent through docker exec
# (base64 text on stdin, decoded in the container) and its SHA-256 inside the container is checked against the package file.
function Put([string]$file,[string]$dest){
  $r=Dk @('cp',$file,"${Web}:$dest"); if($r.Code -eq 0){ return }
  $want=(Hash-Of $file).ToLower()
  $b64=[Convert]::ToBase64String([IO.File]::ReadAllBytes($file))
  $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
  $out=$b64 | & $Docker --context desktop-linux exec -i $Web sh -c "tr -d '\r\n' | base64 -d > '$dest' && sha256sum '$dest'" 2>&1 | Out-String; $code=$LASTEXITCODE; $ErrorActionPreference=$old
  if($code -ne 0 -or $out -notmatch [regex]::Escape($want)){ throw "Could not copy $file into the container (docker cp: $($r.Out); docker exec: $($out.Trim()))" }
  Write-Host "  (docker cp unavailable; sent $(Split-Path -Leaf $file) through docker exec, SHA-256 checked in the container)"
}
function Need-Web {
  $r=Dk @('inspect','-f','{{.State.Running}}',$Web)
  if($r.Out -ne 'true'){ throw "The importer web container ($Web) is not running. Run Fix-ImportMounts-r2.ps1 -Apply first (step 1), then run this again." }
}
function Lint-In([string]$path){
  $r=Dk @('exec',$Web,'php','-l',$path)
  if($r.Code -ne 0 -or $r.Out -notmatch 'No syntax errors'){ throw "php -l failed for ${path}: $($r.Out)" }
  Write-Host "  php -l OK  $path"
}
function Parse-Ps([string]$file){
  $tok=$null; $err=$null
  [void][System.Management.Automation.Language.Parser]::ParseFile($file,[ref]$tok,[ref]$err)
  if($err -and $err.Count -gt 0){ throw "PowerShell parser errors in ${file}: $($err[0].Message) (line $($err[0].Extent.StartLineNumber))" }
  Write-Host "  PowerShell parser OK  $file"
}
function Php-Stdin([string]$php){
  $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
  $out=$php | & $Docker --context desktop-linux exec -i $Web php 2>&1 | Out-String; $code=$LASTEXITCODE; $ErrorActionPreference=$old
  [pscustomobject]@{Code=$code;Out=$out.Trim()}
}
$ToolsPhp=@'
<?php
$ok=true;function t($c,$w){global $ok;echo ($c?'  ok   ':'  MISSING ').$w,"\n";if(!$c)$ok=false;}
exec('for t in ffmpeg ffprobe setsid; do command -v $t; done 2>/dev/null',$o,$c);t(count($o)===3,'ffmpeg, ffprobe and setsid in the web container');
exec('ffmpeg -hide_banner -encoders 2>/dev/null',$e);$e=implode("\n",$e);t(str_contains($e,' libx264 ')&&preg_match('/ aac +AAC/',$e)===1,'H.264 (libx264) and AAC encoders');
t(str_contains($e,' libmp3lame '),'MP3 encoder (libmp3lame) for the audio conversion');
exec('ffmpeg -hide_banner -decoders 2>/dev/null',$d);$d=implode("\n",$d);t(str_contains($d,' wmav2 ')&&str_contains($d,' ac3 '),'WMA and AC-3 decoders');
t(function_exists('proc_open'),'proc_open allowed');t(is_writable('/sandbox-data'),'/sandbox-data writable (staging, transcode-ledger)');
echo '  CPUs seen by the container: ',trim((string)shell_exec('nproc')),' (video conversion uses 8 threads, audio 1)',"\n";
echo $ok?"TOOLS OK\n":"TOOLS MISSING\n";
'@
$BatchPhp=@'
<?php
$n=['approved'=>0,'converting'=>0,'copying'=>0];
foreach(glob('/sandbox-data/guided-v08/*/batch.json') as $p){$b=json_decode((string)file_get_contents($p),true);if(!is_array($b))continue;$s=(string)($b['state']??'');
 if(isset($n[$s]))$n[$s]++;if(in_array($s,['approved','converting','copying'],true))printf("    %s %s\n",substr(basename(dirname($p)),0,8),$s);}
printf("COUNTS approved=%d converting=%d copying=%d\n",$n['approved'],$n['converting'],$n['copying']);
'@
function Busy-Count {
  $r=Php-Stdin $BatchPhp
  if($r.Out -notmatch 'COUNTS approved=(\d+) converting=(\d+) copying=(\d+)'){ throw "Cannot read batch states: $($r.Out)" }
  [pscustomobject]@{Approved=[int]$Matches[1];Converting=[int]$Matches[2];Copying=[int]$Matches[3];Out=$r.Out}
}
# Patch 4k: the library categories = top-level folders of the library (same filter as the page: no .name, no $name, no links).
function Lib-Names {
  if(-not (Test-Path -LiteralPath $Lib -PathType Container)){ throw "Library folder $Lib not found." }
  $n=@(Get-ChildItem -LiteralPath $Lib -Directory -Force | Where-Object { -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -and -not $_.Name.StartsWith('.') -and -not $_.Name.StartsWith('$') -and $_.Name -ne 'System Volume Information' } | ForEach-Object { $_.Name })
  if($n.Count -eq 0){ throw "No category folders found in $Lib." }
  [string[]]$a=$n; [Array]::Sort($a,[StringComparer]::Ordinal); ,$a
}
# Patch 4p: the Books shelves = folders inside the library Books folder (same filter as the page).
function Shelf-Names {
  $names=Lib-Names
  $bk=@($names | Where-Object { $_.ToLower() -eq 'books' } | Select-Object -First 1)
  if($bk.Count -eq 0){ return ,@() }
  $d=Join-Path $Lib $bk[0]
  [string[]]$a=@(Get-ChildItem -LiteralPath $d -Directory -Force | Where-Object { -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -and -not $_.Name.StartsWith('.') -and -not $_.Name.StartsWith('$') } | ForEach-Object { $_.Name })
  ,$a
}
$LibPhp=@'
<?php
$d='/host/d/xampp/htdocs/Edutek/videos';$n=0;if(!is_dir($d)){echo "LIBCOUNT none\n";exit;}
foreach(scandir($d)?:[] as $e){if($e==='.'||$e==='..'||$e[0]==='.'||$e[0]==='$'||strcasecmp($e,'System Volume Information')===0)continue;if(is_dir("$d/$e")&&!is_link("$d/$e"))$n++;}
echo "LIBCOUNT $n\n";
'@
function Check-Package {
  foreach($f in $Files){ $n=Hash-Of (Join-Path $Src $f.Name); if($n -ne $f.New){ throw "Patched file hash mismatch for $($f.Name): $n" } }
  foreach($p in $Pkg){ $n=Hash-Of (Join-Path $Src $p.Name); if($n -ne $p.Hash){ throw "Package file hash mismatch for $($p.Name): $n" } }
  Write-Host '  Package files match their expected SHA-256'
}
function Run-Checks {
  Write-Host 'Checking...'
  $script:Already=$true
  Check-Package
  Need-Web
  foreach($f in $Files){
    $t=Hash-Of $f.Target
    if($t -eq $f.New){ Write-Host "  $($f.Name): live ALREADY has Patch 4p" }
    elseif($f.Live -contains $t){ $script:Already=$false; Write-Host "  $($f.Name): new $($f.New), live is the expected $($LiveName[$t]) version ($t)" }
    else { throw "Live $($f.Target) is unexpected ($t). Not touching anything." }
  }
  foreach($d in $Deps){ $h=Hash-Of $d.Path; if($h -ne $d.Hash){ throw "$($d.Name) is not the expected version ($h). Not touching anything." }; Write-Host "  $($d.Name): unchanged, OK" }
  Parse-Ps $PSCommandPath
  $r=Php-Stdin $ToolsPhp; Write-Host $r.Out; if($r.Out -notmatch 'TOOLS OK'){ throw 'The web container lacks a tool Patch 4p needs.' }
  $r=Dk @('exec',$Web,'sh','-c','rm -rf /tmp/p4p && mkdir -p /tmp/p4p'); if($r.Code -ne 0){ throw "Cannot prepare /tmp/p4p in ${Web}: $($r.Out)" }
  try {
    foreach($p in @('bulk-import-guided.php','unit-p4p-v3.php','unit-p4o.php','pairs-4p.json')){ Put (Join-Path $Src $p) "/tmp/p4p/$p" }
    Lint-In '/tmp/p4p/bulk-import-guided.php'
    Write-Host '  Unit tests: 4p checks, then all 4o unit tests (short real test conversions in the container /tmp)...'
    $r=Dk @('exec','-w','/tmp/p4p',$Web,'php','/tmp/p4p/unit-p4p-v3.php','/sandbox/lib/edutek-safe-name.php','/tmp/p4p/bulk-import-guided.php','/tmp/p4p/pairs-4p.json','/tmp/p4p/unit-p4o.php')
    $r.Out -split "`n" | Where-Object { $_ -notmatch '^\s*ok ' } | ForEach-Object { Write-Host "  $_" }
    if($r.Code -ne 0 -or $r.Out -notmatch 'UNIT TESTS PASSED'){ throw 'Unit tests failed.' }
  } finally { [void](Dk @('exec',$Web,'rm','-rf','/tmp/p4p')) }
  $txt=[IO.File]::ReadAllText((Join-Path $Src 'bulk-import-guided.php'))
  if(([regex]::Matches($txt,"const GP_RULES=3;")).Count -ne 1){ throw 'GP_RULES is not 3 in the patched file' }
  if(([regex]::Matches($txt,"const GP_BOOKS_DEFAULT_FOLDER='';")).Count -ne 1){ throw 'Books are still shelved by default in the patched file' }
  if(([regex]::Matches($txt,"const p4Base=location.protocol\+'//'\+location.hostname\+':8080/';")).Count -ne 1){ throw 'Done-screen links do not use the page host name with port 8080' }
  Write-Host '  GP_RULES stays 3; books get no shelf by themselves (you choose one); links = this page host name, port 8080'
  if(([regex]::Matches($txt,"const GP_LIB_DIR='/host/d/xampp/htdocs/Edutek/videos';")).Count -ne 1){ throw 'GP_LIB_DIR is not the library mount in the patched file' }
  $names=Lib-Names
  $r=Php-Stdin $LibPhp
  if($r.Out -notmatch 'LIBCOUNT (\d+)'){ throw "The web container cannot read the library folder /host/d/xampp/htdocs/Edutek/videos: $($r.Out)" }
  if([int]$Matches[1] -ne $names.Count){ throw "The web container sees $($Matches[1]) category folders but $Lib has $($names.Count). The Category list would not match the library." }
  Write-Host "  Library categories: $($names.Count) top-level folders in $Lib; the web container sees the same $($Matches[1]) (read only)."
  $sh=Shelf-Names; Write-Host "  Books shelves offered by the shelf picker: $($sh.Count) (read only)."
  $b=Busy-Count
  Write-Host '  Saved batches waiting or staging (read only):'; Write-Host $b.Out
  Write-Host '  Note: 4p changes no saved import. Imports saved before 4p open in the new screens; their books keep the shelf they already have.'
  Write-Host 'ALL CHECKS PASSED'
}
function Get-Page([string]$u){
  $resp=Invoke-WebRequest -UseBasicParsing -Uri $u -TimeoutSec 30
  if($resp.StatusCode -ne 200){ throw "$u returned HTTP $($resp.StatusCode)" }
  if($resp.Content -match 'Fatal error|Uncaught'){ throw "$u shows a PHP error." }
  # Patch 4m: a PHP error with display_errors off gives HTTP 200 and a page cut off in the middle; check the page is complete.
  if(-not $resp.Content.TrimEnd().EndsWith('</html>') -or $resp.Content -notmatch 'const batchNewCats='){ throw "$u is cut off (page script incomplete)." }
  if($resp.Content -notmatch 'id="ui4o-data"'){ throw "$u does not carry the Patch 4o/4p screens (no ui4o-data)." }
  $resp
}
function Verify-Live {
  Lint-In '/sandbox/public/bulk-import-guided.php'
  $r=Dk @('exec',$Web,'grep','-c','Patch 4p (damaged or unreadable videos','/sandbox/public/bulk-import-guided.php')
  if($r.Out -ne '1'){ throw 'Container does not see the Patch 4p file.' }
  $r=Dk @('exec',$Web,'grep','-c','Patch 4o audio: begin','/sandbox/public/bulk-import-guided.php')
  if($r.Out -ne '1'){ throw 'Container does not see the Patch 4o audio code.' }
  $resp=Get-Page 'http://127.0.0.1:8092/bulk-import-guided.php'
  if($resp.Content -notmatch 'id="src-key"' -or $resp.Content -notmatch 'id="pub-foot"' -or $resp.Content -notmatch 'Published imports and undo'){ throw 'Importer page does not show the classic Source section and Published imports section (under Details).' }
  Write-Host '  Start page: HTTP 200 and complete (ends with </html>, page script and 4o/4p screens present).'
  if(Test-Path -LiteralPath "$App\data\guided-v08\$Ec5\batch.json" -PathType Leaf){
    [void](Get-Page "http://127.0.0.1:8092/bulk-import-guided.php?id=$Ec5")
    Write-Host "  Import $($Ec5.Substring(0,8)) (saved before 4p): HTTP 200 and complete."
  }
  [void](Get-Page 'http://127.0.0.1:8092/bulk-import-guided.php?view=published')
  Write-Host '  Published imports view: complete.'
}
function Restore-From([string]$dir){
  $lines=Get-Content -LiteralPath (Join-Path $dir 'hashes.txt')
  foreach($f in $Files){
    $line=@($lines | Where-Object { $_ -match ('^BACKUP\s+\S+\s+' + [regex]::Escape($f.Target) + '$') })
    if($line.Count -ne 1){ throw "hashes.txt has no BACKUP line for $($f.Target)" }
    $want=($line[0] -replace '^BACKUP\s+(\S+).*','$1')
    $now=Hash-Of $f.Target
    if($now -ne $f.New -and $now -ne $want){ throw "Live $($f.Target) is neither the 4p version nor the backup ($now). Not restoring." }
    $bak=Join-Path $dir $f.Name
    if((Hash-Of $bak) -ne $want){ throw "Backup $bak does not match hashes.txt ($want)" }
    Copy-Item -LiteralPath $bak -Destination $f.Target -Force
    if((Hash-Of $f.Target) -ne $want){ throw "Restore verification failed for $($f.Target)" }
    Write-Host "  restored $($f.Target)  $want"
  }
}
function Wait-Http([string]$u,[int]$sec){
  $t0=Get-Date
  while(((Get-Date)-$t0).TotalSeconds -lt $sec){
    try { $x=Invoke-WebRequest -UseBasicParsing -Uri $u -TimeoutSec 10; if($x.StatusCode -eq 200){ return $true } } catch {}
    Start-Sleep -Milliseconds 500
  }
  $false
}
function Run-E2E {
  Write-Host 'End-to-end tests in throwaway containers (temp folders; production data is not mounted)...'
  Check-Package
  Parse-Ps $PSCommandPath
  foreach($x in @($Node,$Edge)){ if(-not (Test-Path -LiteralPath $x -PathType Leaf)){ throw "$x not found (needed for the keyboard walk)." } }
  if(Get-NetTCPConnection -LocalPort $WalkPort -State Listen -ErrorAction SilentlyContinue){ throw "Port $WalkPort is in use; the walk needs it for the throwaway importer." }
  $img=(Dk @('inspect','-f','{{.Image}}',$Web)).Out
  if($img -notmatch '^sha256:[0-9a-f]{64}$'){ throw "Cannot read the web image: $img" }
  $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'; $name="p4p-e2e-$stamp"
  $w=Join-Path $env:TEMP $name
  $shots="C:\Users\Edutek\Patch4p-walk-$stamp"
  $names=Lib-Names; $shelves=Shelf-Names
  if(-not (Test-Path -LiteralPath $Big -PathType Container)){ throw "$Big not found (the 4p tests run on a copy of it)." }
  foreach($d in @('app\lib','app\public','data','src','import-sources\acceptance','pkg','lib-videos','hostc\Users\Edutek\Desktop')){ New-Item -ItemType Directory -Path (Join-Path $w $d) -Force | Out-Null }
  Copy-Item -LiteralPath "$App\app\lib\edutek-safe-name.php","$App\app\lib\edutek-safe-name-cli.php" -Destination (Join-Path $w 'app\lib')
  Copy-Item -LiteralPath "$App\app\public\video-preview-lib.php","$App\app\public\video-preview-worker.php" -Destination (Join-Path $w 'app\public')
  Copy-Item -LiteralPath (Join-Path $Src 'bulk-import-guided.php') -Destination (Join-Path $w 'app\public')
  Copy-Item -LiteralPath "$App\data\planner-categories.json" -Destination (Join-Path $w 'data')
  foreach($p in @('e2e-p4p-v3.php','make-test-clips.sh','make-cat-clips.sh','make-audio-clips.sh','make-4p-clips.sh','router-4o.php')){ Copy-Item -LiteralPath (Join-Path $Src $p) -Destination (Join-Path $w 'pkg') }
  # Patch 4k: an EMPTY copy of the library's top-level folder names (no files) as the throwaway library, and the expected list
  foreach($n in $names){ [void][IO.Directory]::CreateDirectory((Join-Path (Join-Path $w 'lib-videos') $n)) }
  $utf8=New-Object System.Text.UTF8Encoding($false)
  [IO.File]::WriteAllText((Join-Path $w 'pkg\lib-names.txt'),(($names -join "`n")+"`n"),$utf8)
  # Patch 4p: EMPTY copies of the Books shelf folders (no files) and the expected shelf list
  $bk=@($names | Where-Object { $_.ToLower() -eq 'books' } | Select-Object -First 1)
  if($bk.Count -gt 0){ foreach($n in $shelves){ [void][IO.Directory]::CreateDirectory((Join-Path (Join-Path (Join-Path $w 'lib-videos') $bk[0]) $n)) } }
  [IO.File]::WriteAllText((Join-Path $w 'pkg\shelf-names.txt'),(($shelves -join "`n")+"`n"),$utf8)
  $shelf=@($shelves | Where-Object { $_ -ceq 'Science' }); if($shelf.Count -eq 0){ $shelf=@($shelves | Where-Object { $_ -cmatch '^[A-Za-z0-9 ]{5,}$' } | Select-Object -First 1) }
  if($shelf.Count -eq 0){ $shelf=@('Walk Test Shelf'); [void][IO.Directory]::CreateDirectory((Join-Path $w 'lib-videos\Books\Walk Test Shelf')) }
  # Patch 4p: a COPY of Desktop\Import Big Test (not its EXPECTED/CHECKLIST notes) as Desktop\Import Big Test 4p
  $bigCopy=Join-Path $w 'hostc\Users\Edutek\Desktop\Import Big Test 4p'
  New-Item -ItemType Directory -Path $bigCopy -Force | Out-Null
  Get-ChildItem -LiteralPath $Big -Force | Where-Object { $_.Name -notlike 'EXPECTED*' -and $_.Name -notlike 'CHECKLIST*' } | Copy-Item -Destination $bigCopy -Recurse -Force
  Write-Host "  Copied $Big (read only) to the temp folder as Import Big Test 4p; $($shelves.Count) empty Books shelf folders; walk shelf: $($shelf[0])"
  $pick=@($names | Where-Object { $_ -cmatch '^[A-Za-z0-9 ]+$' -and @('unsorted','books','music','audiobooks','audio books') -notcontains $_.ToLower() } | Select-Object -First 1)
  if($pick.Count -eq 0){ throw 'No simple existing category name found for the test (letters, digits, spaces).' }
  [IO.File]::WriteAllText((Join-Path $w 'pkg\pick-cat.txt'),$pick[0],$utf8)
  Write-Host "  Throwaway library: $($names.Count) empty category folders named like $Lib; test category: $($pick[0])"
  $acc='C:\Users\Edutek\Desktop\Import Acceptance Test'
  if(Test-Path -LiteralPath $acc){ Get-ChildItem -LiteralPath $acc -Force | Where-Object { $_.Name -notlike 'CHECKLIST*' } | Copy-Item -Destination (Join-Path $w 'import-sources\acceptance') -Recurse -Force }
  $m=@('--mount',"type=bind,source=$w\app,target=/sandbox,readonly",'--mount',"type=bind,source=$w\data,target=/sandbox-data",
       '--mount',"type=bind,source=$w\src,target=/import-source,readonly",'--mount',"type=bind,source=$w\import-sources,target=/import-sources,readonly",
       '--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",'--mount',"type=bind,source=$w\lib-videos,target=/host/d/xampp/htdocs/Edutek/videos,readonly",
       '--mount',"type=bind,source=$w\hostc,target=/host/c,readonly")
  $ok=$false
  try {
    Write-Host '  Making test clips (HEVC 360p/1080p 5.1/portrait, H.264 moov-at-end, H.264 fast start, damaged HEVC, loose book, 60 s 1080p HEVC)...'
    $t=Get-Date
    $r=Dk (@('run','--rm','--network','none','--mount',"type=bind,source=$w,target=/w",'--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",$img,'sh','/p4l/make-test-clips.sh','/w'))
    if($r.Code -ne 0){ throw "Test clips failed: $($r.Out)" }
    Write-Host '  Making category test clips (NEW Edutek Test Videos: 2 files directly in the folder, subfolders Robotics & AI, Unsorted, and an existing category)...'
    $r=Dk (@('run','--rm','--network','none','--mount',"type=bind,source=$w,target=/w",'--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",$img,'sh','/p4l/make-cat-clips.sh','/w',$pick[0]))
    if($r.Code -ne 0){ throw "Category test clips failed: $($r.Out)" }
    Write-Host '  Making audio test clips (Audio Test: short tagged WMA, MP3, AIFF, WAV, a cut-short WMA, a broken 4 KB MP3, a cut-off audiobook MP3)...'
    $r=Dk (@('run','--rm','--network','none','--mount',"type=bind,source=$w,target=/w",'--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",$img,'sh','/p4l/make-audio-clips.sh','/w'))
    if($r.Code -ne 0){ throw "Audio test clips failed: $($r.Out)" }
    Write-Host '  Making 4p test files (zero-filled HEVC; in the Big Test copy: a cut-short video, a .mkv, a .mov, a stray picture, a cover picture, a text file)...'
    $r=Dk (@('run','--rm','--network','none','--mount',"type=bind,source=$w,target=/w",'--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",$img,'sh','/p4l/make-4p-clips.sh','/w','/w/hostc/Users/Edutek/Desktop/Import Big Test 4p'))
    if($r.Code -ne 0){ throw "4p test files failed: $($r.Out)" }
    Write-Host ("  clips made in {0:N0} s" -f ((Get-Date)-$t).TotalSeconds)
    # 1) importer tests
    $r=Dk (@('run','-d','--name',$name,'--user','www-data','--network','none','-e','PHP_CLI_SERVER_WORKERS=4') + $m + @($img,'php','-S','0.0.0.0:8080','-t','/sandbox/public'))
    if($r.Code -ne 0){ throw "Throwaway container did not start: $($r.Out)" }
    Start-Sleep -Seconds 2
    [void](Dk @('exec','-d',$name,'php','/sandbox/public/video-preview-worker.php'))
    $r=Dk @('exec',$name,'php','/p4l/e2e-p4p-v3.php','http://127.0.0.1:8080','/sandbox-data/guided-v08','localhost:8092')
    $log=Join-Path $w 'e2e-output.txt'; Set-Content -LiteralPath $log -Value $r.Out -Encoding UTF8
    $r.Out -split "`n" | Where-Object { $_ -notmatch '^ok ' } | ForEach-Object { Write-Host "  $_" }
    if($r.Out -match 'e2e-p4p: (\d+) passed, (\d+) failed'){ Write-Host "  $($Matches[1]) checks passed, $($Matches[2]) failed (all lines: $log)" }
    $ok=($r.Code -eq 0 -and $r.Out -match 'E2E TESTS PASSED')
    $eh=$null; $ecSrc="$App\data\guided-v08\$Ec5\batch.json"
    if(Test-Path -LiteralPath $ecSrc -PathType Leaf){ $eh=Hash-Of $ecSrc }
    # 2) importer with no legacy folders
    if($ok){
      Write-Host '  Second throwaway importer: NO Desktop Videos / Import Sources folders (like production after Fix-ImportMounts), a COPY of import ec5de67d...'
      $w2=Join-Path $w 'nolegacy'
      foreach($d in @('app\lib','app\public','data\guided-v08','hostc\Users\Edutek\Desktop')){ New-Item -ItemType Directory -Path (Join-Path $w2 $d) -Force | Out-Null }
      Copy-Item -Path (Join-Path $w 'app\lib\*') -Destination (Join-Path $w2 'app\lib')
      Copy-Item -Path (Join-Path $w 'app\public\*') -Destination (Join-Path $w2 'app\public')
      Copy-Item -LiteralPath "$App\data\planner-categories.json" -Destination (Join-Path $w2 'data')
      $envs=@('-e','P4M_MODE=nolegacy','-e','P4M_HSRC=h:c/Users/Edutek/Desktop/NEW Edutek Test Videos')
      if($eh){
        New-Item -ItemType Directory -Path (Join-Path $w2 "data\guided-v08\$Ec5") -Force | Out-Null
        Copy-Item -LiteralPath $ecSrc -Destination (Join-Path $w2 "data\guided-v08\$Ec5\batch.json")
        $envs+=@('-e',"P4M_EC5=$Ec5"); Write-Host "    copied $ecSrc (only read; SHA-256 $eh)"
      } else { Write-Host '    (import ec5de67d not found; its copy is skipped)' }
      Copy-Item -LiteralPath (Join-Path $w 'import-sources\NEW Edutek Test Videos') -Destination (Join-Path $w2 'hostc\Users\Edutek\Desktop\NEW Edutek Test Videos') -Recurse
      $m2=@('--mount',"type=bind,source=$w2\app,target=/sandbox,readonly",'--mount',"type=bind,source=$w2\data,target=/sandbox-data",
            '--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",'--mount',"type=bind,source=$w\lib-videos,target=/host/d/xampp/htdocs/Edutek/videos,readonly",
            '--mount',"type=bind,source=$w2\hostc,target=/host/c,readonly")
      $r=Dk (@('run','-d','--name',"$name-nolegacy",'--user','www-data','--network','none','-e','PHP_CLI_SERVER_WORKERS=4') + $m2 + @($img,'php','-S','0.0.0.0:8080','-t','/sandbox/public'))
      if($r.Code -ne 0){ throw "Second throwaway container did not start: $($r.Out)" }
      Start-Sleep -Seconds 2
      [void](Dk @('exec','-d',"$name-nolegacy",'php','/sandbox/public/video-preview-worker.php'))
      $r=Dk (@('exec') + $envs + @("$name-nolegacy",'php','/p4l/e2e-p4p-v3.php','http://127.0.0.1:8080','/sandbox-data/guided-v08','localhost:8092'))
      $log2=Join-Path $w 'e2e-nolegacy-output.txt'; Set-Content -LiteralPath $log2 -Value $r.Out -Encoding UTF8
      $r.Out -split "`n" | ForEach-Object { Write-Host "    $_" }
      $ok=($r.Code -eq 0 -and $r.Out -match 'E2E NOLEGACY PASSED')
    }
    # 3) keyboard-only walk in Edge against a third throwaway importer (reachable only from this PC on 127.0.0.1:$WalkPort)
    if($ok){
      Write-Host "  Third throwaway importer for the keyboard-only walk in Edge (127.0.0.1:$WalkPort, this PC only)..."
      $w3=Join-Path $w 'ui'
      foreach($d in @('app\lib','app\public','data\guided-v08','hostc\Users\Edutek\Desktop')){ New-Item -ItemType Directory -Path (Join-Path $w3 $d) -Force | Out-Null }
      Copy-Item -Path (Join-Path $w 'app\lib\*') -Destination (Join-Path $w3 'app\lib')
      Copy-Item -Path (Join-Path $w 'app\public\*') -Destination (Join-Path $w3 'app\public')
      Copy-Item -LiteralPath "$App\data\planner-categories.json" -Destination (Join-Path $w3 'data')
      $old=@()
      if($eh){
        New-Item -ItemType Directory -Path (Join-Path $w3 "data\guided-v08\$Ec5") -Force | Out-Null
        Copy-Item -LiteralPath $ecSrc -Destination (Join-Path $w3 "data\guided-v08\$Ec5\batch.json"); $old=@('--old',$Ec5)
      }
      Copy-Item -LiteralPath (Join-Path $w 'import-sources\NEW Edutek Test Videos') -Destination (Join-Path $w3 'hostc\Users\Edutek\Desktop\NEW Edutek Test Videos') -Recurse
      Copy-Item -LiteralPath (Join-Path $w 'import-sources\Audio Test') -Destination (Join-Path $w3 'hostc\Users\Edutek\Desktop\Audio Test') -Recurse
      Copy-Item -LiteralPath $bigCopy -Destination (Join-Path $w3 'hostc\Users\Edutek\Desktop\Import Big Test 4p') -Recurse
      $m3=@('--mount',"type=bind,source=$w3\app,target=/sandbox,readonly",'--mount',"type=bind,source=$w3\data,target=/sandbox-data",
            '--mount',"type=bind,source=$w\pkg,target=/p4l,readonly",'--mount',"type=bind,source=$w\lib-videos,target=/host/d/xampp/htdocs/Edutek/videos,readonly",
            '--mount',"type=bind,source=$w3\hostc,target=/host/c,readonly")
      $r=Dk (@('run','-d','--name',"$name-ui",'--user','www-data','-p',"127.0.0.1:${WalkPort}:8080",'-e','PHP_CLI_SERVER_WORKERS=4') + $m3 + @($img,'php','-S','0.0.0.0:8080','-t','/sandbox/public','/p4l/router-4o.php'))
      if($r.Code -ne 0){ throw "Walk container did not start: $($r.Out)" }
      [void](Dk @('exec','-d',"$name-ui",'php','/sandbox/public/video-preview-worker.php'))
      if(-not (Wait-Http "http://127.0.0.1:$WalkPort/bulk-import-guided.php" 60)){ throw "The walk importer did not answer on 127.0.0.1:$WalkPort" }
      New-Item -ItemType Directory -Path $shots -Force | Out-Null
      $wa=@((Join-Path $Src 'walk-4p-v3.js'),'--browser',$Edge,'--base',"http://127.0.0.1:$WalkPort",'--data',(Join-Path $w3 'data'),'--out',$shots,
            '--src','C:\Users\Edutek\Desktop\Import Big Test 4p','--cat',$pick[0],'--shelf',$shelf[0])
      $oe=$ErrorActionPreference; $ErrorActionPreference='Continue'
      $out=& $Node @wa 2>&1 | Out-String; $code=$LASTEXITCODE; $ErrorActionPreference=$oe
      $log3=Join-Path $shots 'walk-output.txt'; Set-Content -LiteralPath $log3 -Value $out -Encoding UTF8
      $out.Trim() -split "`n" | Where-Object { $_ -notmatch '^ok ' } | ForEach-Object { Write-Host "    $_" }
      $ok=($code -eq 0 -and $out -match 'WALK PASSED')
      Write-Host "    screenshots and all walk lines: $shots"
    }
    if($eh){ if((Hash-Of $ecSrc) -ne $eh){ $ok=$false; Write-Host '  FAIL: the real ec5de67d batch.json changed during the test' } else { Write-Host '  the real ec5de67d batch.json is unchanged' } }
  } finally {
    [void](Dk @('rm','-f',$name)); [void](Dk @('rm','-f',"$name-nolegacy")); [void](Dk @('rm','-f',"$name-ui"))
    if($ok){ Remove-Item -LiteralPath $w -Recurse -Force -ErrorAction SilentlyContinue; Write-Host '  Throwaway containers and temp folder removed (screenshots kept).' }
    else { Write-Host "  Throwaway containers removed; temp folder kept for inspection: $w" }
  }
  if(-not $ok){ throw 'END-TO-END TEST FAILED' }
  Write-Host 'END-TO-END TEST PASSED'
}
$script:Already=$false

if($Rollback -ne ''){
  if($Rollback -eq 'latest'){
    $d=Get-ChildItem -LiteralPath $BackupRoot -Directory -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1
    if(-not $d){ throw "No backups in $BackupRoot" }
    $dir=$d.FullName
  } else { $dir=$Rollback }
  if(-not (Test-Path -LiteralPath (Join-Path $dir 'hashes.txt'))){ throw "No hashes.txt in $dir" }
  Need-Web
  $b=Busy-Count
  if($b.Converting -gt 0){ throw "A batch is still converting ($($b.Converting)). Wait until its page says it is staged, then roll back." }
  Restore-From $dir
  Lint-In '/sandbox/public/bulk-import-guided.php'
  Write-Host "ROLLBACK DONE from $dir"
  Write-Host 'This returns the page to Patch 4o (books are shelved by folder name again; no Done summary). The importer-4o backups still take it back to 4n.'
  Write-Host 'No restart needed. Reload the importer page (F5).'
}
elseif($Apply){
  Run-Checks
  if($script:Already){ Write-Host 'Nothing to do.' }
  else {
    $b=Busy-Count
    if($b.Converting -gt 0){ throw "A batch is still converting ($($b.Converting)). Wait until its page says it is staged, then apply." }
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'
    $dir=Join-Path $BackupRoot $stamp
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $hl=@()
    foreach($f in $Files){
      $h=Hash-Of $f.Target
      Copy-Item -LiteralPath $f.Target -Destination (Join-Path $dir $f.Name)
      if((Hash-Of (Join-Path $dir $f.Name)) -ne $h){ throw "Backup copy hash mismatch for $($f.Name); nothing installed." }
      $hl+="BACKUP $h  $($f.Target)"
      $hl+="NEW    $($f.New)  $(Join-Path $Src $f.Name)"
    }
    $hl+="Made $(Get-Date -Format s) by Install-Patch4p.ps1"
    Set-Content -LiteralPath (Join-Path $dir 'hashes.txt') -Encoding ASCII -Value $hl
    Write-Host "  Backup: $dir"
    try {
      foreach($f in $Files){
        Copy-Item -LiteralPath (Join-Path $Src $f.Name) -Destination $f.Target -Force
        if((Hash-Of $f.Target) -ne $f.New){ throw "Installed file hash mismatch: $($f.Target)" }
      }
      Verify-Live
      Write-Host "PATCH 4p APPLIED  (backup $dir)"
    } catch {
      Write-Host "VERIFY FAILED: $($_.Exception.Message) - restoring backup"
      Restore-From $dir
      Write-Host 'Restored.'
      throw
    }
    Write-Host 'No restart needed. Reload the importer page (F5). Follow CHECKLIST-4p.txt (in this folder) with Desktop\Import Big Test.'
  }
}
elseif($E2E){ Run-E2E }
else { Run-Checks; Write-Host '(check only - nothing changed. Run with -E2E for the throwaway end-to-end tests and keyboard walk, -Apply to install.)' }
