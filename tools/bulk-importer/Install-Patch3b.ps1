# Install-Patch3b.ps1 - Edutek importer patch 3b: one duplicate rule for the guided page and the publisher, fresh device index.
# Run in Windows PowerShell on the Beelink as edutek1\edutek: first without -Apply (checks only), then with -Apply.
# Changes app\public\bulk-import-guided.php, Publish-Batch.ps1, Run-PublishQueue.ps1 and Build-DeviceIndex.ps1.
# Every input and output is hash-checked; nothing live changes without -Apply. Never runs an index or a publish.
# Rollback: copy the four files back from the backup folder printed by -Apply, then restart the publisher task (see patch-3b.md).
param(
  [switch]$Apply,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$BackupRoot = 'D:\edutek-system\backups\importer-dedup',
  [string]$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$DockerContext = 'desktop-linux'
)
$ErrorActionPreference = 'Stop'
function NL([string]$s) { $s -replace "`r`n", "`n" }
function RootPath([string[]]$parts) { $p = $Root; foreach ($x in $parts) { $p = [IO.Path]::Combine($p, $x) }; $p }
function HashOf($path) { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash }
function Build($src, $tmp, $inHash, $outHash, $pairs) {
  $h = HashOf $src
  if ($h -ne $inHash) { throw "$src has unexpected SHA256 $h. It differs from the reviewed version. Stop." }
  $b = [IO.File]::ReadAllBytes($src); $bom = $b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF
  $enc = New-Object System.Text.UTF8Encoding($bom); $off = $(if ($bom) { 3 } else { 0 })
  $t = $enc.GetString($b, $off, $b.Length - $off)
  foreach ($p in $pairs) { $n = ([regex]::Matches($t, [regex]::Escape($p[0]))).Count; if ($n -ne 1) { throw "$src : marker found $n times, expected 1." } }
  foreach ($p in $pairs) { $t = $t.Replace($p[0], $p[1]) }
  [IO.File]::WriteAllText($tmp, $t, $enc)
  if ((HashOf $tmp) -ne $outHash) { Remove-Item -LiteralPath $tmp; throw "Built copy of $src does not match the expected hash. Stop." }
  "Built OK: $tmp"
}

$guided = RootPath 'app','public','bulk-import-guided.php'; $pub = RootPath 'Publish-Batch.ps1'; $pq = RootPath 'Run-PublishQueue.ps1'; $dix = RootPath 'Build-DeviceIndex.ps1'
$tG = RootPath 'app','_p3b-guided.php'; $tP = RootPath '_p3b-Publish-Batch.ps1'; $tQ = RootPath '_p3b-Run-PublishQueue.ps1'; $tD = RootPath '_p3b-Build-DeviceIndex.ps1'
$hGin = '9323E7BEA5113D396123C9980A7D894CADE23591D82B780065CD20D02BF782D8'; $hGout = '0B7A2A930A548343A8F88740FF0A161CC31D92904C665E00281C476993866532'
$hPin = '15783D770E47D1613B0A4E83796781742DA9772248C81BE94EE7F40DC73FAE49'; $hPout = '5428EA802B406BD47EF37A44C5543521C3381A29615C27168DF50D149D57363A'
$hQin = 'B71E640935BC66BE907DDF5EBC30175A7A1AC97F30B564F26149935D32F11AE9'; $hQout = '76A3DFD57C1A0C55A29E9AC524AE908F79C86F82CA7FAE84C8721BC6A10FD49D'
$hDin = '1777CE36BDBB740F4DCC5C97E93AC28875DF21C1287B80DD52130D56D7EDDE20'; $hDout = 'AD5EF90D4FB4DEAF316B13E4AE4C8F39245803FC0DE60A759AE55598E20128BB'
$live = [ordered]@{ $guided = $hGout; $pub = $hPout; $pq = $hQout; $dix = $hDout }
$done = $true; foreach ($k in $live.Keys) { if ((HashOf $k) -ne $live[$k]) { $done = $false } }
if ($done) { 'Patch 3b is already installed. Nothing to do.'; return }

$repG = @(
  ,@((NL @'
function keyname(string $v): string {return mb_strtolower(Normalizer::normalize($v,Normalizer::FORM_C),'UTF-8');}
'@), (NL @'
function keyname(string $v): string {return mb_strtolower(Normalizer::normalize($v,Normalizer::FORM_C),'UTF-8');}
/* Patch 3b: Edutek duplicate rule 1. Publish-Batch.ps1 (Get-DupKey and its library check) implements the same rule; change both together.
   Library = pdf/mp4 files of the Edutek library ("production" in device-index.json). For a learning file with extension E, size B and target name T:
   - SAME SIZE: a library file with extension E and size B. The publisher compares contents (SHA256): an identical copy is never published; a different file is allowed.
   - SIMILAR NAME: a library file with extension E and the same name key, when the key has at least DX_NAME_MIN UTF-16 units. Needs "Add this copy anyway" (duplicate_add), which the publish request carries.
   Name key: file name without its last extension, Unicode NFC, U+0130 (dotted capital I) -> i, lower case, then only letters and digits (\p{L}\p{N}) of the BMP are kept
   (characters above U+FFFF are dropped, as .NET regex does). Verified identical to Get-DupKey on all 33,982 library names (2026-10-21). */
const DX_RULE=1;const DX_NAME_MIN=12;
function dx_key(string $file): string {$f=str_replace(chr(92),'/',$file);$p=strrpos($f,'/');if($p!==false)$f=substr($f,$p+1);$d=strrpos($f,'.');$s=$d===false?$f:substr($f,0,$d);$n=Normalizer::normalize($s,Normalizer::FORM_C);if(is_string($n))$s=$n;$s=str_replace("\u{130}",'i',$s);return (string)preg_replace('/(?:[^\p{L}\p{N}]|[\x{10000}-\x{10FFFF}])+/u','',mb_strtolower($s,'UTF-8'));}
function dx_len(string $k): int {return intdiv(strlen((string)mb_convert_encoding($k,'UTF-16LE','UTF-8')),2);}
function dx_ext(string $file): string {$d=strrpos($file,'.');return $d===false?'':strtolower(substr($file,$d+1));}
function dx_index(): array {
 static $c=null;if($c!==null)return $c;
 $c=['meta'=>['present'=>false,'checked'=>time()],'size'=>[],'name'=>[]];$f=BI_ROOT.'/device-index.json';if(!is_file($f))return $c;
 $j=json_decode((string)@file_get_contents($f),true);if(!is_array($j)){$c['meta']+=['present'=>false,'unreadable'=>true];return $c;}
 $n=0;foreach(($j['items']??[]) as $it){if(!is_array($it)||($it['lib']??'')!=='production')continue;$p=(string)($it['path']??'');$e=dx_ext($p);if($e!=='pdf'&&$e!=='mp4')continue;$n++;
  $c['size'][$e.'|'.(int)($it['bytes']??-1)][]=$p;$k=dx_key($p);if(dx_len($k)>=DX_NAME_MIN)$c['name'][$e.'|'.$k][]=$p;}
 $c['meta']=['present'=>true,'checked'=>time(),'version'=>(int)($j['version']??1),'built'=>(string)($j['built']??''),'built_epoch'=>isset($j['built_epoch'])?(int)$j['built_epoch']:null,'count'=>$n,'error_count'=>(int)($j['error_count']??0),'errors'=>array_slice(array_map('strval',(array)($j['errors']??[])),0,3)];
 return $c;
}
function dx_meta(): array {return dx_index()['meta'];}
function dx_find(string $target,int $bytes): array {$ix=dx_index();$e=dx_ext($target);$k=dx_key($target);return ['size'=>$ix['size'][$e.'|'.$bytes]??[],'name'=>dx_len($k)>=DX_NAME_MIN?($ix['name'][$e.'|'.$k]??[]):[]];}
function dx_lib_entries(array $r): array {$m=dx_find((string)$r['target'],(int)$r['bytes']);$o=[];foreach($m['name'] as $p)$o[]=['kind'=>'device','lib'=>'Edutek library','created'=>'','where'=>(dirname($p)==='.'?'':dirname($p)),'file'=>basename($p),'why'=>in_array($p,$m['size'],true)?'similar name, same size':'similar name'];return $o;}
function dx_store(array &$r): void {$m=dx_find((string)$r['target'],(int)$r['bytes']);$r['lib_size']=array_slice($m['size'],0,5);$r['lib_size_n']=count($m['size']);$r['lib_name']=array_slice($m['name'],0,5);$r['lib_name_n']=count($m['name']);}
function dx_apply(array &$r): void {
 // Re-check after every save: a title or folder change changes the target name, and the publisher checks the final target.
 dx_store($r);$ents=dx_lib_entries($r);
 $keep=array_values(array_filter($r['existing']??[],fn($z)=>($z['kind']??'')!=='device'));
 if($ents){$r['candidate']=true;$r['dup_lib']=true;$r['existing']=array_merge([$ents[0]],$keep);}
 elseif(!empty($r['dup_lib'])){unset($r['dup_lib']);$r['existing']=$keep;if(!$keep)unset($r['existing']);if(empty($r['dup_source']))unset($r['candidate']);}
}
function dx_ago(int $s): string {if($s<60)return 'less than a minute';if($s<3600)return intdiv($s,60).' min';if($s<172800)return intdiv($s,3600).' h '.intdiv($s%3600,60).' min';return intdiv($s,86400).' days';}
function dx_age_html(array $b): string {
 $m=$b['device_index']??null;if(!is_array($m))return '';
 if(empty($m['present']))return '<p class="warning">'.(!empty($m['unreadable'])?'The Edutek library list (device-index.json) could not be read':'No Edutek library list (device-index.json) was found').', so this page could not check the library for duplicates. The publisher still checks the library before copying anything. The &ldquo;Edutek Import Publisher&rdquo; task rebuilds the list automatically.</p>';
 $age=$m['built_epoch']!==null?max(0,(int)$m['checked']-(int)$m['built_epoch']):null;
 $o='<p class="'.($age!==null&&$age<=86400?'notice':'warning').'">Edutek library list used for this check: built '.h($m['built']).' (Beelink time), '.($age===null?'age unknown (old list format)':h(dx_ago($age)).' before this check').', '.number_format((int)$m['count']).' files. It is rebuilt automatically after each publish and every 6 hours.'.($age!==null&&$age>86400?' It is more than a day old, so recent library changes may be missing; the publisher re-checks the library before copying.':'').'</p>';
 if((int)$m['error_count']>0)$o.='<p class="warning">The last library scan reported '.(int)$m['error_count'].' error(s), so some library files may be missing from this check (first: '.h($m['errors'][0]??'').'). The publisher re-scans the library before copying and stops if its own scan has errors.</p>';
 return $o;
}
'@))
  ,@((NL @'
 if(($ex['kind']??'earlier')==='device')return 'On this device, '.($ex['lib']??'device library').': '.($w!==''?$w.' > ':'').($ex['file']??'');
'@), (NL @'
 if(($ex['kind']??'earlier')==='device')return 'On this device, '.($ex['lib']??'device library').': '.($w!==''?$w.' > ':'').($ex['file']??'').(!empty($ex['why'])?' ('.$ex['why'].')':'');
'@))
  ,@((NL @'
    $dix=BI_ROOT.'/device-index.json';$deviceBuilt='';
    if(is_file($dix)){$dj=json_decode((string)@file_get_contents($dix),true);if(is_array($dj)){$deviceBuilt=(string)($dj['built']??'');$dl=[];foreach(($dj['libraries']??[]) as $lb)$dl[(string)($lb['key']??'')]=(string)($lb['label']??'');foreach(($dj['items']??[]) as $di){$dp=(string)($di['path']??'');$dext=strtolower(pathinfo($dp,PATHINFO_EXTENSION));$dty=$dext==='pdf'?'Books':($dext==='mp4'?'Videos':'');if($dty==='')continue;$existing[$dty.'|'.bi_dupkey(pathinfo($dp,PATHINFO_FILENAME)).'|'.(int)($di['bytes']??0)][]=['kind'=>'device','lib'=>($dl[(string)($di['lib']??'')]??'device library'),'created'=>'','where'=>(dirname($dp)==='.'?'':dirname($dp)),'file'=>basename($dp)];}}}
'@), (NL @'
    $batch['device_index']=dx_meta();$deviceBuilt=(string)($batch['device_index']['built']??'');
'@))
  ,@((NL @'
$dev=array_values(array_filter($ents,fn($z)=>($z['kind']??'')==='device'));
'@), (NL @'
$dev=dx_lib_entries($r);
'@))
  ,@((NL @'
      if($dev){$sk[$ek][]=$rid;$batch['rows'][$rid]['candidate']=true;$batch['rows'][$rid]['existing']=[$dev[0]];}
'@), (NL @'
      dx_store($batch['rows'][$rid]);if($dev){$sk[$ek][]=$rid;$batch['rows'][$rid]['candidate']=true;$batch['rows'][$rid]['dup_lib']=true;$batch['rows'][$rid]['existing']=[$dev[0]];}
'@))
  ,@((NL @'
      gp_set($r,$p);
'@), (NL @'
      gp_set($r,$p);dx_apply($r);
'@))
  ,@((NL @'
     rebuild_support($batch);$batch['placement_rules']=GP_RULES;$batch['planned_root']=GP_ROOT;
'@), (NL @'
     $batch['device_index']=dx_meta();rebuild_support($batch);$batch['placement_rules']=GP_RULES;$batch['planned_root']=GP_ROOT;
'@))
  ,@((NL @'
<p>These items have the same title, type and size as other content.
'@), (NL @'
<p>These items have the same title, type and size as other items in this import, or a name similar to a file already in the Edutek library.
'@))
  ,@((NL @'
<?php if(!$dupSets):?><p class="notice">No possible duplicates were found in this import.</p><?php endif;?>
'@), (NL @'
<?php if(!$dupSets):?><p class="notice">No possible duplicates were found in this import.</p><?php endif;?><?=dx_age_html($batch)?>
'@))
  ,@((NL @'
<?php if(!empty($r['candidate'])):?><div class="warning decision"><h3>Possible duplicate</h3><p>Another file has the same title, type and size. This does not prove they are identical. Compare the locations below before deciding.</p>
'@), (NL @'
<?php if(!empty($r['lib_size'])&&empty($r['dup_lib'])):?><p class="notice">Same size as <?=(int)($r['lib_size_n']??count($r['lib_size']))?> file(s) in the Edutek library, for example <?=h($r['lib_size'][0])?>. When publishing, the contents are compared: if they are identical, publishing stops and nothing is copied.</p><?php endif;?><?php if(!empty($r['candidate'])):?><div class="warning decision"><h3>Possible duplicate</h3><p><?=!empty($r['dup_lib'])?'A file in the Edutek library has a similar name (the same letters and digits).':'Another file has the same title, type and size.'?> This does not prove they are identical. When publishing, the contents are compared and an identical copy is never published. Compare the locations below before deciding.</p>
'@))
)
$repP = @(
  ,@((NL @'
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
        if ($AllowSameSize -or ($AllowSameSizeTarget -ccontains $i.target)) { $warnings.Add($msg) } else { $problems.Add($msg) }
      }
    }
    $stem = [regex]::Replace([IO.Path]::GetFileNameWithoutExtension($i.target).ToLower(), '[^\p{L}\p{N}]', '')
    if ($stem.Length -ge 12 -and $byStem.ContainsKey($stem)) {
      foreach ($m in $byStem[$stem]) { $warnings.Add("Similar name already in the library: $($i.target)  ~  $m") }
    }
  }
}
'@), (NL @'
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
        $mh = $null
        try { $mh = (Get-FileHash -LiteralPath $m -Algorithm SHA256).Hash.ToLower() } catch { $problems.Add("Could not read a same-size library file to compare contents: $m ($($_.Exception.Message))"); continue }
        if ($mh -eq $i.sha256) { $identical = $true; $problems.Add("Identical content is already in the library (an identical copy is never published): $($i.target)  ==  $m") }
        else { $warnings.Add("Same size, different content (allowed): $($i.target)  <>  $m") }
      }
    }
    $nk = Get-DupKey $i.target
    if (-not $identical -and $nk.Length -ge 12 -and $byKey.ContainsKey($e + '|' + $nk)) {
      foreach ($m in $byKey[$e + '|' + $nk]) {
        $msg = "Similar name already in the library: $($i.target)  ~  $m"
        if ($AllowSameSize -or ($AllowSameSizeTarget -ccontains $i.target)) { $warnings.Add($msg + ' (acknowledged)') } else { $problems.Add($msg + ' (not acknowledged: choose "Add this copy anyway" in the browser review)') }
      }
    }
  }
}
'@))
)
$repQ = @(
  ,@((NL @'
  [int]$MaxLearningCeiling = 200
)
'@), (NL @'
  [int]$MaxLearningCeiling = 200,
  [string]$LibraryRoot = 'D:\xampp\htdocs\Edutek\videos'
)
'@))
  ,@((NL @'
function Status-FromRecord($id, $rec, $message) {
'@), (NL @'
# Patch 3b: keep the device index (the library list the guided page checks for duplicates) fresh. Rebuilds it with
# Build-DeviceIndex.ps1 after a publish or any publish-record change, and when it is older than 6 hours (retried at most every 30 min).
# Also rebuilt once when the publisher task starts. Runs synchronously (about 30 s), after the indexer check, so indexing is never delayed by it. It only lists files.
$devScript = Join-Path $Root 'Build-DeviceIndex.ps1'
$devIndex = Join-Path $data 'device-index.json'
$script:dixDirty = $true; $script:dixTry = [datetime]::MinValue
function Update-DeviceIndexIfDue {
  $now = Get-Date; $age = 1000000
  if (Test-Path -LiteralPath $devIndex) { $age = ($now - (Get-Item -LiteralPath $devIndex).LastWriteTime).TotalHours }
  $why = $(if ($script:dixDirty) { 'publisher started, or a publish or publish record changed' } elseif ($age -ge 6) { 'the list is older than 6 hours' } else { '' })
  if (-not $why) { return }
  if (-not $script:dixDirty -and ($now - $script:dixTry).TotalMinutes -lt 30) { return }
  $script:dixDirty = $false; $script:dixTry = $now
  $out = ''; $code = -1
  if (-not (Test-Path -LiteralPath $devScript)) { $out = 'Build-DeviceIndex.ps1 not found.' } else {
    Log "rebuilding the device index ($why)"
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $out = (& (Get-Process -Id $PID).Path -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $devScript -Out $devIndex -Root $LibraryRoot 2>&1 | Out-String); $code = $LASTEXITCODE }
    catch { $out = $_.Exception.Message } finally { $ErrorActionPreference = $prev }
  }
  $msg = "device index rebuild ($why): exit $code. " + (Tail $out 600).Trim()
  Log $msg
  try { [IO.File]::WriteAllText((Join-Path $queue 'device-index-refresh.txt'), ((Get-Date).ToString('s') + '  ' + $msg), $enc) } catch {}
}
function Status-FromRecord($id, $rec, $message) {
'@))
  ,@((NL @'
Write-Status $id $o; Log "synced status for
'@), (NL @'
Write-Status $id $o; $script:dixDirty = $true; Log "synced status for
'@))
  ,@((NL @'
Write-Status $id $s2; Log "$($id.Substring(0,8))  $($s2.state)"
'@), (NL @'
Write-Status $id $s2; $script:dixDirty = $true; Log "$($id.Substring(0,8))  $($s2.state)"
'@))
  ,@((NL @'
    try { Wake-Indexer } catch { Log "indexer check error: $($_.Exception.Message)" }
'@), (NL @'
    try { Wake-Indexer } catch { Log "indexer check error: $($_.Exception.Message)" }
    try { Update-DeviceIndexIfDue } catch { Log "device index check error: $($_.Exception.Message)" }
'@))
)
$newD = (NL @'
# Edutek device index v2 (patch 3b). Lists the pdf/mp4 files of the Edutek library for the guided import page's duplicate check.
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
    [void]$items.Add([ordered]@{ lib = 'production'; path = $rel; bytes = [int64]$_.Length })
  }
$nErr = 0; if ($scanErr) { $nErr = $scanErr.Count }
$errs = @($scanErr | Where-Object { $_ } | Select-Object -First 20 | ForEach-Object { [string]$_.Exception.Message })
$doc = [ordered]@{
  version = 2; built = (Get-Date -Format 'yyyy-MM-dd HH:mm'); built_epoch = [DateTimeOffset]::Now.ToUnixTimeSeconds()
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

'@)

Build $guided $tG $hGin $hGout $repG
Build $pub $tP $hPin $hPout $repP
Build $pq $tQ $hQin $hQout $repQ
if ((HashOf $dix) -ne $hDin) { throw "$dix has unexpected SHA256 $(HashOf $dix). It differs from the reviewed version. Stop." }
[IO.File]::WriteAllText($tD, $newD, (New-Object System.Text.UTF8Encoding($true)))
if ((HashOf $tD) -ne $hDout) { Remove-Item -LiteralPath $tD; throw 'Build-DeviceIndex.ps1 content does not match the expected hash. Stop.' }
"Built OK: $tD"
foreach ($f in $tP, $tQ, $tD) { $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$null, [ref]$e); if ($e.Count) { throw "PowerShell parse errors in $f : $e" } }
'PowerShell parse checks passed.'
$c = @(& $Docker --context $DockerContext ps --filter 'publish=8092' --format '{{.Names}}')
if ($c.Count -ne 1 -or $c[0] -ne 'edutek-bulk-fresh-v05-web-1') { throw "Unexpected importer container(s): $($c -join ', ')" }
& $Docker --context $DockerContext exec $c[0] php -l /sandbox/_p3b-guided.php
if ($LASTEXITCODE -ne 0) { throw 'php -l failed for the patched guided page. Live files unchanged.' }
$t = Get-ScheduledTask -TaskName 'Edutek Import Publisher' -ErrorAction Stop; "Task 'Edutek Import Publisher': $($t.State)"
$pend = @(Get-ChildItem -LiteralPath (RootPath 'data','publish-queue') -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
"Publish requests waiting: $($pend.Count)"

if (-not $Apply) { ''; 'ALL CHECKS PASSED. Nothing live was changed. Re-run with -Apply to install.'; return }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $bak = Join-Path $BackupRoot $stamp
New-Item -ItemType Directory -Force -Path $bak | Out-Null
foreach ($f in $live.Keys) { $d = Join-Path $bak ([IO.Path]::GetFileName($f)); Copy-Item -LiteralPath $f -Destination $d; if ((HashOf $f) -ne (HashOf $d)) { throw "Backup mismatch: $f" } }
"Backups OK in $bak"
Move-Item -LiteralPath $tD -Destination $dix -Force
Move-Item -LiteralPath $tP -Destination $pub -Force
Move-Item -LiteralPath $tQ -Destination $pq -Force
Move-Item -LiteralPath $tG -Destination $guided -Force
foreach ($k in $live.Keys) { if ((HashOf $k) -ne $live[$k]) { throw "Live hash mismatch: $k. Run the rollback from $bak." } }
& $Docker --context $DockerContext exec $c[0] php -l /sandbox/public/bulk-import-guided.php
if ($LASTEXITCODE -ne 0) { throw "Live php -l failed. Run the rollback from $bak." }
''; "INSTALLED. Backups: $bak"
'Next: restart the publisher task when no publish requests are waiting (see the instructions). It rebuilds the device index once on start.'
