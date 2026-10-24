# Install-Patch4.ps1 - Edutek importer patch 4: replace a library file from the browser review, with archive, undo and archive cleanup.
# Run in Windows PowerShell on the Beelink as edutek1\edutek: first without -Apply (checks only), then with -Apply.
# Changes app\public\bulk-import-guided.php, Publish-Batch.ps1, Run-PublishQueue.ps1, Build-DeviceIndex.ps1 and Undo-Publish.ps1,
# and adds Clear-ReplacedArchive.ps1. Every input and output is hash-checked; nothing live changes without -Apply.
# Never runs an index, a publish, an undo or a cleanup.
# Rollback: run this script with -Rollback <backup folder printed by -Apply> (or copy the files back by hand, see patch-4.md),
# then restart the publisher task.
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$Force,
  [string]$RecordDir = 'D:\edutek-system\publish-records',
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$BackupRoot = 'D:\edutek-system\backups\importer-replace',
  [string]$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$DockerContext = 'desktop-linux'
)
$ErrorActionPreference = 'Stop'
function NL([string]$s) { $s -replace "`r`n", "`n" }
function RootPath([string[]]$parts) { $p = $Root; foreach ($x in $parts) { $p = [IO.Path]::Combine($p, $x) }; $p }
function HashOf($path) { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash }
function Build-Pairs($src, $tmp, $inHash, $outHash, $pairs) {
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
function Build-Whole($src, $tmp, $inHash, $outHash, [string]$text) {
  if ($src -and (HashOf $src) -ne $inHash) { throw "$src has unexpected SHA256 $(HashOf $src). It differs from the reviewed version. Stop." }
  [IO.File]::WriteAllText($tmp, $text, (New-Object System.Text.UTF8Encoding($true)))
  if ((HashOf $tmp) -ne $outHash) { Remove-Item -LiteralPath $tmp; throw "Built copy for $tmp does not match the expected hash. Stop." }
  "Built OK: $tmp"
}

$guided = RootPath 'app','public','bulk-import-guided.php'; $pub = RootPath 'Publish-Batch.ps1'; $pq = RootPath 'Run-PublishQueue.ps1'
$dix = RootPath 'Build-DeviceIndex.ps1'; $und = RootPath 'Undo-Publish.ps1'; $clr = RootPath 'Clear-ReplacedArchive.ps1'
$files = [ordered]@{
  $guided = @{ in = '0B7A2A930A548343A8F88740FF0A161CC31D92904C665E00281C476993866532'; out = '3A3883C34348FD21D435B4A0BFB09891F2A22EE24C9BA4462B31BBE33F9D5665'; tmp = (RootPath 'app','_p4-guided.php') }
  $pub    = @{ in = '5428EA802B406BD47EF37A44C5543521C3381A29615C27168DF50D149D57363A'; out = 'E337972D50FF5F651FE758C073000F69501651693B99631B27497C878098754A'; tmp = (RootPath '_p4-Publish-Batch.ps1') }
  $pq     = @{ in = '76A3DFD57C1A0C55A29E9AC524AE908F79C86F82CA7FAE84C8721BC6A10FD49D'; out = 'A5926C00BA81339570484E84E27616B2887D6DBCED46CB8B1CA3BCBB127721FA'; tmp = (RootPath '_p4-Run-PublishQueue.ps1') }
  $dix    = @{ in = 'AD5EF90D4FB4DEAF316B13E4AE4C8F39245803FC0DE60A759AE55598E20128BB'; out = '1110B0420EFED0061DD40A07E5B340C549B13C6E372DF2008C6D75CB1FD3DDED'; tmp = (RootPath '_p4-Build-DeviceIndex.ps1') }
  $und    = @{ in = '96939AEE27CF10E1F3E1515E570E3F5B15EF11C36263AF056C511B00A7F31799'; out = 'E41868C2B084B828A9FD077B49C066FD1182381439D3050F2BDC1B3FC5FB7B0C'; tmp = (RootPath '_p4-Undo-Publish.ps1') }
  $clr    = @{ in = ''; out = '0E4469B4AF272D44548291694822170E92438BB9B4C1F74FADF85568F97CB7DD'; tmp = (RootPath '_p4-Clear-ReplacedArchive.ps1') }
}

if ($Rollback) {
  # Puts the backed-up files back (each one hash-checked against the pre-patch hash) and removes Clear-ReplacedArchive.ps1.
  if (-not (Test-Path -LiteralPath $Rollback -PathType Container)) { throw "Backup folder not found: $Rollback" }
  # The pre-patch Undo-Publish.ps1 cannot restore replaced files (it would move the replacement away and delete its database row).
  $live = @()
  foreach ($f in @(Get-ChildItem -LiteralPath $RecordDir -Filter '*.json' -File -ErrorAction SilentlyContinue)) {
    try { $r = [IO.File]::ReadAllText($f.FullName) | ConvertFrom-Json } catch { continue }
    if ([int]$r.replaced -gt 0 -and @('published','failed','attention','publishing') -contains [string]$r.state) { $live += $f.BaseName.Substring(0, [Math]::Min(8, $f.BaseName.Length)) }
  }
  if ($live.Count -gt 0 -and -not $Force) { throw ("Batch(es) " + ($live -join ', ') + " replaced library files and are not undone. Undo them first with the patch-4 Undo-Publish.ps1 (or re-run with -Force to roll back anyway; their originals then stay in D:\edutek-system\replaced-archive and must be restored by hand). Nothing was rolled back.") }
  foreach ($k in $files.Keys) {
    if (-not $files[$k].in) { continue }
    $b = Join-Path $Rollback ([IO.Path]::GetFileName($k))
    if ((HashOf $b) -ne $files[$k].in) { throw "Backup $b does not have the pre-patch hash. Nothing was rolled back." }
  }
  foreach ($k in $files.Keys) {
    if (-not $files[$k].in) { continue }
    $b = Join-Path $Rollback ([IO.Path]::GetFileName($k))
    Copy-Item -LiteralPath $b -Destination ($k + '.p4rb') -Force; Move-Item -LiteralPath ($k + '.p4rb') -Destination $k -Force
    if ((HashOf $k) -ne $files[$k].in) { throw "Rollback hash mismatch: $k" }
  }
  if (Test-Path -LiteralPath $clr) { Move-Item -LiteralPath $clr -Destination (Join-Path $Rollback 'Clear-ReplacedArchive.ps1.removed') -Force }
  'ROLLED BACK to the pre-patch-4 files. Restart the publisher task (see patch-4.md). Archives in D:\edutek-system\replaced-archive are kept.'
  'Note: undo of a batch that replaced files needs the patch-4 Undo-Publish.ps1; keep a copy before rolling back if such batches exist.'
  return
}

$state = @{}
foreach ($k in $files.Keys) {
  if (-not (Test-Path -LiteralPath $k)) { $state[$k] = 'missing' }
  else { $h = HashOf $k; $state[$k] = $(if ($h -eq $files[$k].out) { 'new' } elseif ($h -eq $files[$k].in) { 'old' } else { 'other' }) }
}
if (@($files.Keys | Where-Object { $state[$_] -ne 'new' }).Count -eq 0) { 'Patch 4 is already installed. Nothing to do.'; return }
foreach ($k in $files.Keys) {
  $okState = $(if ($files[$k].in) { 'old' } else { 'missing' })
  if ($state[$k] -ne $okState) { throw "$k is '$($state[$k])' (expected the pre-patch version$(if (-not $files[$k].in) { ', i.e. not present' })). It differs from the reviewed version, or patch 4 is partly installed. Stop; nothing was changed." }
}

$repG = @(
  ,@((NL @'
$c=['meta'=>['present'=>false,'checked'=>time()],'size'=>[],'name'=>[]];
'@), (NL @'
$c=['meta'=>['present'=>false,'checked'=>time()],'size'=>[],'name'=>[],'files'=>[]];
'@))
  ,@((NL @'
  $c['size'][$e.'|'.(int)($it['bytes']??-1)][]=$p;
'@), (NL @'
  $c['files'][$p]=[(int)($it['bytes']??-1),isset($it['mtime'])&&is_numeric($it['mtime'])?(int)$it['mtime']:null];$c['size'][$e.'|'.(int)($it['bytes']??-1)][]=$p;
'@))
  ,@((NL @'
function dx_ago(int $s): string {
'@), (NL @'
/* Patch 4: replace a file in the Edutek library. A row may choose one of its duplicate-rule matches (rp_cands: same name and folder,
   similar name, same size; same file type) as replace = {path, bytes, mtime}. Its target then becomes that library path, so staging,
   cover placement, the approval hash and the publish request all follow it. bytes/mtime come from device-index.json (v3) and are
   re-checked by Publish-Batch.ps1, which archives the old file (D:\edutek-system\replaced-archive) before copying; Undo-Publish.ps1 restores it. */
function rp_cands(array $r): array {
 $ix=dx_index();$t=(string)$r['target'];$m=dx_find($t,(int)$r['bytes']);$o=[];
 $add=function(string $p,string $why)use(&$o,$ix): void {if(!isset($ix['files'][$p]))return;if(isset($o[$p])){$o[$p]['why'].=', '.$why;return;}$o[$p]=['path'=>$p,'bytes'=>$ix['files'][$p][0],'mtime'=>$ix['files'][$p][1],'why'=>$why];};
 if(dx_ext($t)!==''&&isset($ix['files'][$t]))$add($t,'same name and folder');
 foreach($m['name'] as $p)$add($p,'similar name');
 foreach($m['size'] as $p)$add($p,'same size');
 return array_slice(array_values($o),0,8);
}
function rp_apply(array &$r,string $want): void {
 // Called with the planned target (after gp_set). Records the candidates; applies a valid choice.
 unset($r['replace'],$r['lib_exact']);$c=rp_cands($r);$r['rp_cands']=$c;
 if($c&&$c[0]['path']===$r['target']){$r['lib_exact']=true;$r['candidate']=true;$r['dup_lib']=true;$have=false;
  foreach(($r['existing']??[]) as $z)if(($z['kind']??'')==='device'&&rp_dev_path([$z])===$r['target'])$have=true;
  if(!$have)$r['existing']=array_merge([['kind'=>'device','lib'=>'Edutek library','created'=>'','where'=>(dirname($r['target'])==='.'?'':dirname($r['target'])),'file'=>basename($r['target']),'why'=>'same name and folder']],$r['existing']??[]);}
 if($want===''||empty($r['include']))return;
 foreach($c as $x){if($x['path']!==$want)continue;
  if($x['mtime']===null)throw new RuntimeException('The Edutek library list is in the old format, so a replacement cannot be checked yet. The publisher rebuilds it automatically; reload in a minute and choose again.');
  if(dx_ext($want)!==strtolower((string)$r['extension']))throw new RuntimeException('A replacement must have the same file type: '.$want);
  $r['replace']=['path'=>$want,'bytes'=>(int)$x['bytes'],'mtime'=>(int)$x['mtime'],'why'=>$x['why'],'planned_target'=>$r['target']];$r['target']=$want;$r['duplicate_add']=false;return;}
 throw new RuntimeException('The chosen library file is no longer in the Edutek library list: '.$want.'. Review this item again.');
}
function rp_dev_path(array $exs): string {foreach($exs as $x)if(($x['kind']??'')==='device')return ltrim((($x['where']??'')!==''?$x['where'].'/':'').($x['file']??''),'/');return '';}
function rp_card(array $r): string {
 $c=$r['rp_cands']??[];$cur=(string)($r['replace']['path']??'');if(!$c&&$cur==='')return '';$exact=!empty($r['lib_exact']);
 $o='<div class="'.($cur!==''?'notice':'warning').' decision replace-decision"><h3>'.($cur!==''?'This file will replace a file in the Edutek library':($exact?'A file with this name is already in that Edutek library folder':'Replace a file in the Edutek library instead?')).'</h3>';
 if($exact&&$cur==='')$o.='<p>Adding it as another file is not possible at the same place. Replace the library file, or change the title or folder above.</p>';
 $o.='<label>Replace the library copy:<br><select data-role="replace-path" style="max-width:100%"><option value="">'.($exact?'Choose&hellip;':'Do not replace &mdash; add as a new file').'</option>';
 foreach($c as $x){$ok=$x['mtime']!==null;$o.='<option value="'.h($x['path']).'"'.($x['path']===$cur?' selected':'').($ok?'':' disabled').'>'.h($x['path']).' &mdash; '.h($x['why']).', '.number_format($x['bytes']/1048576,1).' MiB'.($ok?'':' (library list too old to check; it is rebuilt automatically)').'</option>';}
 if($cur!==''&&!in_array($cur,array_column($c,'path'),true))$o.='<option value="'.h($cur).'" selected>'.h($cur).'</option>';
 return $o.'</select></label><p class="muted">The new file takes the library file&rsquo;s place and keeps its folder, file name and library title. Just before that, the publisher moves the old file to D:\\edutek-system\\replaced-archive on the Beelink; Undo-Publish.ps1 can put it back. If this item has a cover image, it replaces the cover next to that file too (also archived). Publishing stops if the library file changes after this review, or if both files are identical (replacing would change nothing).</p></div>';
}
function pq_phrase(int $learn,int $rep): string {return $rep>0?'PUBLISH '.($learn-$rep).' NEW REPLACE '.$rep:'PUBLISH '.$learn;}
function dx_ago(int $s): string {
'@))
  ,@((NL @'
function needs_fix(array $r): bool {

'@), (NL @'
function needs_fix(array $r): bool {
 if(!empty($r['replace']))return false;

'@))
  ,@((NL @'
  if(!empty($r['candidate'])&&empty($r['duplicate_add']))$out[]=['id'=>$rid,'kind'=>'duplicate','message'=>'Decide whether to add a possible duplicate'];
'@), (NL @'
  if(!empty($r['candidate'])&&empty($r['duplicate_add'])&&empty($r['replace'])&&empty($r['lib_exact']))$out[]=['id'=>$rid,'kind'=>'duplicate','message'=>'Decide whether to add a possible duplicate'];
  if(!empty($r['lib_exact'])&&empty($r['replace']))$out[]=['id'=>$rid,'kind'=>'duplicate','message'=>'A file with this name is already in that library folder: choose Replace the library copy, or change the title or folder'];
'@))
  ,@((NL @'
      dx_store($batch['rows'][$rid]);if($dev){
'@), (NL @'
      dx_store($batch['rows'][$rid]);rp_apply($batch['rows'][$rid],'');if($dev){
'@))
  ,@((NL @'
if($r['dup_decision']==='reject'||$r['dup_decision']==='replace')$r['include']=false;
'@), (NL @'
$rpw=(string)($p['replace_path']??'');if($r['dup_decision']==='reject'||($r['dup_decision']==='replace'&&$rpw===''))$r['include']=false;rp_apply($r,$rpw);
'@))
  ,@((NL @'
<?php if(!empty($r['already_imported'])):?><div class="warning decision"><h3>Already imported earlier</h3>
'@), (NL @'
<?=rp_card($r)?>
<?php if(!empty($r['already_imported'])):?><div class="warning decision"><h3>Already imported earlier</h3>
'@))
  ,@((NL @'
data-has-orig="<?=$exs?'1':'0'?>">
'@), (NL @'
data-has-orig="<?=$exs?'1':'0'?>" data-lib-path="<?=h(rp_dev_path($exs))?>">
'@))
  ,@((NL @'
<p class="muted">This copy stays where it is whatever you choose.</p>
'@), (NL @'
<p class="muted"><?=($x['kind']??'')==='device'?'Choose only the source copy to replace this library file (the old file is archived and can be restored with Undo-Publish.ps1).':'This copy stays where it is whatever you choose.'?></p>
'@))
  ,@((NL @'
Click only the source copy: a replace choice is recorded, but the item is skipped; the original is not replaced and nothing is copied for it.
'@), (NL @'
Click only the source copy: it replaces the library file when publishing (the old file is moved to the replaced-files archive and can be restored with Undo-Publish.ps1); a copy staged in an earlier import is not replaced, so that source copy is skipped.
'@))
  ,@((NL @'
r.dataset.dupDecision=d;get(r,'include').checked=add;
'@), (NL @'
r.dataset.dupDecision=d;const lp=p.dataset.libPath||'',sel=get(r,'replace-path'),rep=d==='replace'&&lp!==''&&!!sel&&[...sel.options].some(o=>o.value===lp&&!o.disabled);if(sel){if(rep)sel.value=lp;else if(sel.value===lp)sel.value=''}get(r,'include').checked=add||rep;
'@))
  ,@((NL @'
else if(W.size){txt='KEPT - replace is not supported yet';cls='replaced'}
'@), (NL @'
else if(W.size){txt=p.dataset.libPath?'REPLACED - moved to the archive when publishing':'KEPT - only library files can be replaced';cls='replaced'}
'@))
  ,@((NL @'
else if(d==='replace'){txt='CHOSEN - skipped, replace is not supported yet';cls='winner'}
'@), (NL @'
else if(d==='replace'){txt=p.dataset.libPath?'WINNER - replaces the library file':'CHOSEN - skipped, only library files can be replaced';cls='winner'}
'@))
  ,@((NL @'
else if(hasOrig)msg='Replace is recorded but not supported yet: the original stays and this source copy is skipped. Nothing is copied for it.';
'@), (NL @'
else if(hasOrig)msg=p.dataset.libPath?'The source copy replaces the library file when publishing. The old library file is moved to the replaced-files archive and can be restored with Undo-Publish.ps1.':'Only files already in the Edutek library can be replaced: the earlier staged copy stays and this source copy is skipped.';
'@))
  ,@((NL @'
(d==='replace'?'SKIPPED - REPLACE NOT SUPPORTED YET':
'@), (NL @'
(d==='replace'?(get(r,'replace-path')?.value?'WILL REPLACE THE LIBRARY FILE':'SKIPPED - ONLY LIBRARY FILES CAN BE REPLACED'):
'@))
  ,@((NL @'
const art=get(r,'art-choice');if(art)art.onchange=changed;
'@), (NL @'
const art=get(r,'art-choice');if(art)art.onchange=changed;const rps=get(r,'replace-path');if(rps)rps.onchange=()=>{if(rps.value)get(r,'include').checked=true;changed();render()};
'@))
  ,@((NL @'
dup_decision:r.dataset.dupDecision||''}});
'@), (NL @'
dup_decision:r.dataset.dupDecision||'',replace_path:get(r,'replace-path')?.value||''}});
'@))
  ,@((NL @'
function pq_can_reverse(array $pi): bool {return !$pi['requested']&&in_array($pi['state'],['','blocked','reversed'],true);}
'@), (NL @'
function pq_can_reverse(array $pi): bool {return !$pi['requested']&&in_array($pi['state'],['','blocked','reversed','undone'],true);}
'@))
  ,@((NL @'
if(empty($b['rows'][$rid]['support']))$l++;}return [$l,$f,$by];}
'@), (NL @'
if(empty($b['rows'][$rid]['support'])){$l++;if(!empty($b['rows'][$rid]['replace']))$rp++;}}return [$l,$f,$by,$rp];}
'@))
  ,@((NL @'
function pq_counts(array $b): array {$l=0;$f=0;$by=0;
'@), (NL @'
function pq_counts(array $b): array {$l=0;$f=0;$by=0;$rp=0;
'@))
  ,@((NL @'
 [$learn,$files,$bytes]=pq_counts($b);
'@), (NL @'
 [$learn,$files,$bytes,$rep]=pq_counts($b);$new=$learn-$rep;$phr=pq_phrase($learn,$rep);$what=$rep>0?$new.' new, '.$rep.' replacement'.($rep>1?'s':''):$learn.' materials';
'@))
  ,@((NL @'
were copied into D:\xampp\htdocs\Edutek\videos. Finished '.h($st['finished']??'').'.</p>';
'@), (NL @'
were copied into D:\xampp\htdocs\Edutek\videos. Finished '.h($st['finished']??'').'.</p>'.((int)($st['replaced_learning']??0)>0?'<p class="notice">'.(int)$st['replaced_learning'].' of them replaced library files. The old files are in D:\\edutek-system\\replaced-archive ('.h($st['archive_manifest']??'').'); Undo-Publish.ps1 can put them back until that archive is cleared.</p>':'');
'@))
  ,@((NL @'
 if($s==='failed')return $o.'<h2>Publishing failed</h2>'
'@), (NL @'
 if($s==='undone'||$s==='reversed')return $o.'<h2>Publish undone</h2><p>Undo-Publish.ps1 reversed this publish'.(!empty($st['undone'])?' on '.h($st['undone']):'').'. New files were removed from the Edutek library and replaced files were put back. The publish record and archive manifest are kept.</p>'.'<p>This import is not published again. To start over, reverse the staged copies below and run a new import.</p></div>';
 if($s==='failed')return $o.'<h2>Publishing failed</h2>'
'@))
  ,@((NL @'
return $o.'<h2>Publish '.$learn.' materials to the Edutek library</h2><p>The publisher on the Beelink copies these '.$files.' verified staged files ('.$mib.' MiB) into D:\xampp\htdocs\Edutek\videos, saves a publish record and a database backup, and then queues indexing for only these learning files. It re-checks every file first, never overwrites, and stops on same-size library files you did not confirm as duplicates.</p>'.$hb
'@), (NL @'
return $o.'<h2>Publish '.$what.' to the Edutek library</h2><p>The publisher on the Beelink copies these '.$files.' verified staged files ('.$mib.' MiB) into D:\xampp\htdocs\Edutek\videos, saves a publish record and a database backup, and then queues indexing for only these learning files. It re-checks every file first, overwrites nothing except the replacements you chose, and stops on same-size library files you did not confirm as duplicates.</p>'.($rep>0?'<p class="notice">'.$rep.' file'.($rep>1?'s':'').' replace'.($rep>1?'':'s').' a library file. Each old file is first moved to D:\\edutek-system\\replaced-archive (restorable with Undo-Publish.ps1 until that archive is cleared). Publishing stops if a library file changed since this review or is identical to its replacement.</p>':'').$hb
'@))
  ,@((NL @'
<label>Type <strong>PUBLISH '.$learn.'</strong> to confirm<br>
'@), (NL @'
<label>Type <strong>'.h($phr).'</strong> to confirm<br>
'@))
  ,@((NL @'
<button class="primary" name="action" value="publish">Publish '.$learn.' materials to the Edutek library</button>
'@), (NL @'
<button class="primary" name="action" value="publish">Publish '.$what.' to the Edutek library</button>
'@))
  ,@((NL @'
.json_encode('Publish '.$learn.' materials ('.$files.' files, '.$mib.' MiB) to the Edutek library now?'
'@), (NL @'
.json_encode('Publish '.$what.' ('.$files.' files, '.$mib.' MiB) to the Edutek library now?'.($rep>0?' '.$rep.' library file'.($rep>1?'s are':' is').' replaced; the old cop'.($rep>1?'ies go':'y goes').' to the archive.':'')
'@))
  ,@((NL @'
$targets=[];$ack=[];$learnN=0;$bytes=0;
'@), (NL @'
$targets=[];$ack=[];$learnN=0;$bytes=0;$repN=0;$repL=[];
'@))
  ,@((NL @'
if(empty($r['support'])){$learnN++;if(!empty($r['duplicate_add']))$ack[]=$op['target'];}}
'@), (NL @'
if(empty($r['support'])){$learnN++;if(!empty($r['duplicate_add']))$ack[]=$op['target'];
       if(!empty($r['replace'])){if(($r['replace']['path']??null)!==$r['target']||!is_int($r['replace']['mtime']??null))throw new RuntimeException('A replacement choice is incomplete; review that item again');$repN++;$repL[]=['target'=>$r['target'],'bytes'=>(int)$r['replace']['bytes'],'mtime'=>(int)$r['replace']['mtime']];}}}
'@))
  ,@((NL @'
if(trim((string)($_POST['confirm_text']??''))!=='PUBLISH '.$learnN)throw new RuntimeException('Type PUBLISH '.$learnN.' to confirm publishing');
'@), (NL @'
if(trim((string)($_POST['confirm_text']??''))!==pq_phrase($learnN,$repN))throw new RuntimeException('Type '.pq_phrase($learnN,$repN).' to confirm publishing');
'@))
  ,@((NL @'
'confirm'=>'PUBLISH '.$learnN,'learning_count'=>$learnN,
'@), (NL @'
'confirm'=>pq_phrase($learnN,$repN),'learning_count'=>$learnN,'replace_count'=>$repN,'replace'=>$repL,
'@))
)
Build-Pairs $guided $files[$guided].tmp $files[$guided].in $files[$guided].out $repG
Build-Whole $pub $files[$pub].tmp $files[$pub].in $files[$pub].out ((NL @'
param(
  [Parameter(Mandatory=$true)][string]$BatchId,
  [switch]$Execute,
  [switch]$AllowSameSize,
  [string[]]$ExcludeTarget = @(),
  [int]$MaxLearning = 5,
  [string[]]$AllowSameSizeTarget = @(),
  [string]$ApprovedRequest = '',
  [string]$ProductionRoot = 'D:\xampp\htdocs\Edutek\videos',
  [string]$ArchiveRoot = 'D:\edutek-system\replaced-archive',
  [string]$DataRoot = 'D:\edutek-system\bulk-loader-fresh-v05\data',
  [string]$RecordDir = 'D:\edutek-system\publish-records'
)
$ErrorActionPreference = 'Stop'
$data   = $DataRoot
$guided = Join-Path $data 'guided-v08'
$trig   = Join-Path $data 'index-trigger'
$recDir = $RecordDir
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

# Patch 4: replace. A reviewed row may carry replace = {path, bytes, mtime}: its target is then an existing library file
# (chosen in the browser from the duplicate-rule matches). The old file is MOVED to $ArchiveRoot\<yyyyMMdd>\<batch id8>\<library path>
# (a manifest.json there lists every replaced file), then the new file takes its place. Undo-Publish.ps1 puts the old file back.
function Native([string]$rel) { $rel.Replace('/', [IO.Path]::DirectorySeparatorChar) }
function Get-Sha([string]$p) { (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLower() }
function Test-InsideLibrary([string]$path) {
  # True when $path is a real file below $ProductionRoot with no link (junction/symlink) on the way.
  $root = [IO.Path]::GetFullPath($ProductionRoot).TrimEnd('\', '/')
  $full = [IO.Path]::GetFullPath($path)
  if (-not $full.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { return $false }
  $p = $full
  while ($p.Length -gt $root.Length) {
    if (-not (Test-Path -LiteralPath $p)) { return $false }
    if ((Get-Item -LiteralPath $p -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { return $false }
    $p = [IO.Path]::GetDirectoryName($p)
  }
  return (Test-Path -LiteralPath $full -PathType Leaf)
}
function Move-Verified([string]$from, [string]$to, [string]$sha) {
  # Same volume: a rename. Other volume: copy, verify SHA256, then delete the original.
  New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($to)) | Out-Null
  if (Test-Path -LiteralPath $to) { throw "Archive path already exists: $to" }
  if ([IO.Path]::GetPathRoot([IO.Path]::GetFullPath($from)) -eq [IO.Path]::GetPathRoot([IO.Path]::GetFullPath($to))) { [IO.File]::Move($from, $to) }
  else { [IO.File]::Copy($from, $to, $false); if ((Get-Sha $to) -ne $sha) { Remove-Item -LiteralPath $to -Force; throw "Archive copy verification failed: $from" }; Remove-Item -LiteralPath $from -Force }
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
    src = (Join-Path $filesRoot (Native $t)); dst = (Join-Path $ProductionRoot (Native $t))
    gen_thumb = $false; state = 'pending'; parent = [string]$r.parent
    replace = $null; old_sha256 = $null; old_bytes = $null; archive = $null
  }
  if (-not $r.support -and $r.replace) {
    $rp = $r.replace
    if ([string]$rp.path -cne $t) { throw "Item $($p.Name): the replace choice does not match its target." }
    $items[-1].replace = [pscustomobject]@{ kind = 'reviewed'; path = [string]$rp.path; bytes = [int64]$rp.bytes; mtime = [int64]$rp.mtime; why = [string]$rp.why }
  }
}
# A cover file of a replaced item replaces the existing cover at the same place, if there is one.
foreach ($i in @($items | Where-Object { $_.support -and $_.parent })) {
  $par = @($items | Where-Object { $_.rid -eq $i.parent -and $_.replace })
  if ($par.Count -eq 1 -and (Test-Path -LiteralPath $i.dst -PathType Leaf)) { $i.replace = [pscustomobject]@{ kind = 'cover'; path = $i.target; bytes = -1; mtime = -1; why = 'cover of a replaced item' } }
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
    if ((Test-Path -LiteralPath $i.dst) -and -not $i.replace) { $problems.Add("Already exists in the library: $($i.target)") }
  }
}
if ($problems.Count -eq 0) {
  foreach ($i in $items) {
    $h = (Get-FileHash -LiteralPath $i.src -Algorithm SHA256).Hash.ToLower()
    if ($h -ne $i.sha256) { $problems.Add("Sandbox copy changed (hash mismatch): $($i.target)") }
  }
}
$replacing = @($items | Where-Object { $_.replace })
if ($problems.Count -eq 0 -and $replacing.Count -gt 0) {
  foreach ($i in $replacing) {
    if (-not (Test-InsideLibrary $i.dst)) { $problems.Add("Replace target is not a plain file inside the library (missing, outside it, or reached through a link): $($i.target)"); continue }
    $fi = Get-Item -LiteralPath $i.dst -Force
    if ([IO.Path]::GetExtension($fi.Name).ToLower() -ne [IO.Path]::GetExtension($i.target).ToLower()) { $problems.Add("Replace target has a different file type: $($i.target)"); continue }
    if ($i.replace.kind -eq 'reviewed' -and ($fi.Length -ne $i.replace.bytes -or $fi.LastWriteTimeUtc.ToFileTimeUtc() -ne $i.replace.mtime)) { $problems.Add("The library file changed since the review (size or time differs), so it is not replaced: $($i.target). Review this import again."); continue }
    $i.old_sha256 = Get-Sha $i.dst; $i.old_bytes = [int64]$fi.Length
    if ($i.old_sha256 -eq $i.sha256) { $problems.Add("The new file is identical to the library file it would replace; replacing it would change nothing: $($i.target)") }
  }
}
if ($problems.Count -eq 0) {
  $ids = @($items | Where-Object { $_.media -and -not $_.replace } | ForEach-Object { SqlQ ('content/' + $_.target) })
  if ($ids.Count -gt 0) {
    $cnt = Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($ids -join ',') + ");")
    if ([int]("$cnt".Trim()) -ne 0) { $problems.Add("The database already has $("$cnt".Trim()) rows for these paths.") }
  }
}

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
        if ($i.replace -and $m -eq $i.dst) { continue }
        $mh = $null
        try { $mh = (Get-FileHash -LiteralPath $m -Algorithm SHA256).Hash.ToLower() } catch { $problems.Add("Could not read a same-size library file to compare contents: $m ($($_.Exception.Message))"); continue }
        if ($mh -eq $i.sha256) { $identical = $true; $problems.Add("Identical content is already in the library (an identical copy is never published): $($i.target)  ==  $m") }
        else { $warnings.Add("Same size, different content (allowed): $($i.target)  <>  $m") }
      }
    }
    $nk = Get-DupKey $i.target
    if (-not $identical -and $nk.Length -ge 12 -and $byKey.ContainsKey($e + '|' + $nk)) {
      foreach ($m in $byKey[$e + '|' + $nk]) {
        if ($i.replace -and $m -eq $i.dst) { continue }
        $msg = "Similar name already in the library: $($i.target)  ~  $m"
        if ($AllowSameSize -or $i.replace -or ($AllowSameSizeTarget -ccontains $i.target)) { $warnings.Add($msg + ' (acknowledged)') } else { $problems.Add($msg + ' (not acknowledged: choose "Add this copy anyway" in the browser review)') }
      }
    }
  }
}
$need = ($items | Measure-Object -Property bytes -Sum).Sum
Write-Host ""
Write-Host "Batch $id"
Write-Host ("Learning items: {0}   Cover files: {1}   Total: {2:N1} MiB" -f $learning.Count, ($items.Count - $learning.Count), ($need / 1MB))
$repLearn = @($learning | Where-Object { $_.replace }).Count
$phrase = $(if ($repLearn -gt 0) { "PUBLISH $($learning.Count - $repLearn) NEW REPLACE $repLearn" } else { "PUBLISH $($learning.Count)" })
Write-Host ("New: {0}   Replacements: {1} (old copies go to {2})" -f ($learning.Count - $repLearn), $replacing.Count, $ArchiveRoot)
foreach ($i in $learning) { Write-Host ($(if ($i.replace) { "  R " } else { "  + " }) + $i.target) }
foreach ($i in @($items | Where-Object { $_.support -and $_.replace })) { Write-Host ("  R " + $i.target + " (cover)") }
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

$drive = New-Object IO.DriveInfo ([IO.Path]::GetPathRoot([IO.Path]::GetFullPath($ProductionRoot)))
if ($drive.AvailableFreeSpace -lt ($need + 1GB)) { throw 'Not enough free space on the library drive.' }
if ($ApprovedRequest) {
  # Non-interactive approval from the browser publish queue (Run-PublishQueue.ps1). Replaces the typed PUBLISH prompt only when the request matches this batch exactly.
  $areq = [IO.File]::ReadAllText($ApprovedRequest, $utf8) | ConvertFrom-Json
  $bad = @()
  $want = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($i in $items) { [void]$want.Add($i.target + '|' + $i.sha256) }
  $got = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($t in @($areq.targets)) { if ($t -and -not $got.Add(([string]$t.target) + '|' + ([string]$t.sha256).ToLower())) { $bad += 'duplicate file in request' } }
  if ([string]$areq.batch -cne $id) { $bad += 'batch id' }
  if (-not $b.approval -or [string]$areq.revision -cne [string]$b.approval) { $bad += 'approval revision' }
  if ([int]$areq.learning_count -ne $learning.Count -or [int]$areq.file_count -ne $items.Count) { $bad += 'counts' }
  if ([string]$areq.confirm -cne $phrase) { $bad += 'typed confirmation' }
  $wantR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($i in @($learning | Where-Object { $_.replace })) { [void]$wantR.Add($i.target + '|' + $i.replace.bytes + '|' + $i.replace.mtime) }
  $gotR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($x in @($areq.replace)) { if ($x) { [void]$gotR.Add(([string]$x.target) + '|' + [int64]$x.bytes + '|' + [int64]$x.mtime) } }
  if (-not $wantR.SetEquals($gotR) -or [int]$areq.replace_count -ne $repLearn) { $bad += 'replace list' }
  if (-not $want.SetEquals($got)) { $bad += 'file list' }
  $acked = @(@($areq.allow_same_size) | Where-Object { $_ } | ForEach-Object { [string]$_ })
  foreach ($t in $AllowSameSizeTarget) { if ($acked -cnotcontains $t) { $bad += "same-size acknowledgement not in request: $t" } }
  if ($ExcludeTarget.Count -gt 0 -or $AllowSameSize) { $bad += '-ExcludeTarget/-AllowSameSize cannot be combined with -ApprovedRequest' }
  if ($bad.Count -gt 0) { Write-Host ('Cancelled. The approved request does not match this batch: ' + ($bad -join '; ') + '. Nothing was copied.') -ForegroundColor Red; return }
  Write-Host "Approved browser request accepted: $ApprovedRequest"
} else {
  $conf = Read-Host "Type $phrase to copy $($items.Count) files into the library ($($replacing.Count) of them replace library files)"
  if ($conf -ne $phrase) { Write-Host 'Cancelled. Nothing was copied.'; return }
}

$created = New-Object System.Collections.Generic.List[string]
$archDir = $null; $manPath = $null
if ($replacing.Count -gt 0) { $archDir = Join-Path (Join-Path $ArchiveRoot (Get-Date -Format 'yyyyMMdd')) $id.Substring(0, 8); $manPath = Join-Path $archDir 'manifest.json' }
$record = [ordered]@{ batch = $id; state = 'publishing'; started = (Get-Date).ToString('s'); production_root = $ProductionRoot; created_dirs = @(); items = $items; index_requested = $false; replaced = $replacing.Count; archive_manifest = $manPath }
$manifest = [ordered]@{ batch = $id; state = 'publishing'; created = (Get-Date).ToString('s'); production_root = $ProductionRoot; archive_dir = $archDir; record = $recPath; files = @() }
function Save-Rec { [IO.File]::WriteAllText($recPath, ($record | ConvertTo-Json -Depth 6), $utf8) }
function Save-Man { if ($manPath) { New-Item -ItemType Directory -Force -Path $archDir | Out-Null; [IO.File]::WriteAllText($manPath, ($manifest | ConvertTo-Json -Depth 5), $utf8) } }
Save-Rec; Save-Man
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
    if ($i.replace) {
      if ((Get-Sha $i.dst) -ne $i.old_sha256) { Remove-Item -LiteralPath $tmp -Force; throw "The library file changed during publishing, so it was not replaced: $($i.target)" }
      $arch = Join-Path $archDir (Native $i.target)
      Move-Verified $i.dst $arch $i.old_sha256
      $i.archive = $arch
      $manifest.files += [ordered]@{ target = $i.target; original_path = $i.dst; original_sha256 = $i.old_sha256; original_bytes = $i.old_bytes; archive_path = $arch; replaced_by_sha256 = $i.sha256; kind = $i.replace.kind; time = (Get-Date).ToString('s'); state = 'archived' }
      Save-Man
      try { [IO.File]::Move($tmp, $i.dst) } catch { [IO.File]::Move($arch, $i.dst); $manifest.files[-1].state = 'put back'; Save-Man; throw "Could not put the new file in place; the original was put back: $($i.target)" }
      $i.state = 'replaced'; Save-Rec
      Write-Host ("  replaced " + $i.target + "  (old copy: " + $arch + ")")
    } else {
      [IO.File]::Move($tmp, $i.dst)
      $i.state = 'copied'; Save-Rec
      Write-Host ("  copied " + $i.target)
    }
  }
  $record.state = 'published'; $record.finished = (Get-Date).ToString('s')
  if ($manPath) { $manifest.state = 'active'; $manifest.published = $record.finished; Save-Man }
  $mediaTargets = @($items | Where-Object { $_.media } | ForEach-Object { $_.target })
  $reqName = "publish-$($id.Substring(0,8))"
  $req = [ordered]@{ batch = $reqName; files = $mediaTargets }
  $tmpReq = Join-Path $trig ($reqName + '.request.tmp')
  [IO.File]::WriteAllText($tmpReq, ($req | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmpReq -Destination (Join-Path $trig ($reqName + '.request.json')) -Force
  $record.index_requested = $true; Save-Rec
  Write-Host ""; Write-Host "PUBLISHED. Record: $recPath" -ForegroundColor Green
  Write-Host "Indexing request $reqName was queued. Indexing starts automatically (Edutek Import Partial Indexer); watch the Step 4 status in the browser until it says Indexing complete."
} catch {
  $record.state = 'failed'; $record.error = $_.Exception.Message; Save-Rec
  if ($manPath -and $manifest.files.Count -gt 0) { $manifest.state = 'failed'; Save-Man }
  Write-Host ""; Write-Host ("FAILED: " + $_.Exception.Message) -ForegroundColor Red
  Write-Host "Run Undo-Publish.ps1 -BatchId $($id.Substring(0,8)) to review and clean up what was copied."
}
'@) + "`n")
Build-Whole $pq $files[$pq].tmp $files[$pq].in $files[$pq].out ((NL @'
# Edutek browser publish queue watcher. Runs as the "Edutek Import Publisher" scheduled task.
# Reads data\publish-queue\<batch>.request.json written by bulk-import-guided.php, re-validates it against the
# staged batch, refuses any batch that already has a publish record, backs up the database, then runs
# Publish-Batch.ps1 -ApprovedRequest non-interactively. Writes data\publish-status\<batch>.json before retiring
# each request with a unique .done name. A request is processed once; nothing is retried silently.
# Patch 4: requests may replace reviewed library files (Publish-Batch.ps1 archives the old copies; Undo-Publish.ps1 restores them).
param(
  [switch]$Once,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$RecordDir = 'D:\edutek-system\publish-records',
  [string]$DbBackupDir = 'D:\edutek-system\backups\db',
  [int]$MaxLearningCeiling = 200,
  [string]$LibraryRoot = 'D:\xampp\htdocs\Edutek\videos'
)
$ErrorActionPreference = 'Stop'
$data = Join-Path $Root 'data'
$queue = Join-Path $data 'publish-queue'
$statusDir = Join-Path $data 'publish-status'
$guided = Join-Path $data 'guided-v08'
$publisher = Join-Path $Root 'Publish-Batch.ps1'
New-Item -ItemType Directory -Force -Path $queue, $statusDir, $DbBackupDir | Out-Null
$enc = New-Object Text.UTF8Encoding($false)
try { $lock = [IO.File]::Open((Join-Path $queue 'runner.lock'), 'OpenOrCreate', 'ReadWrite', 'None') } catch { Write-Host 'Another publisher is already running.'; exit 1 }

function Log($m) { Write-Host ('{0}  {1}' -f (Get-Date -Format HH:mm:ss), $m) }
function Read-Json($p) { [IO.File]::ReadAllText($p, $enc) | ConvertFrom-Json }
function S($v) { if ($v -is [datetime]) { $v.ToString('s') } elseif ($null -eq $v) { '' } else { [string]$v } }
function Tail($s, $n) { $s = S $s; if ($s.Length -gt $n) { $s.Substring($s.Length - $n) } else { $s } }
function Write-Status($id, $o) {
  $o['updated'] = (Get-Date).ToString('s')
  $tmp = Join-Path $statusDir ($id + '.status.tmp')
  [IO.File]::WriteAllText($tmp, ($o | ConvertTo-Json -Depth 5 -Compress), $enc)
  Move-Item -LiteralPath $tmp -Destination (Join-Path $statusDir ($id + '.json')) -Force
}
function Retire($f) { Rename-Item -LiteralPath $f.FullName -NewName ($f.Name + '.' + [guid]::NewGuid().ToString('N') + '.done') }
# Patch 3a: while index requests wait, make sure the partial indexer task is running. This only starts the existing
# 'Edutek Import Partial Indexer' task when it is idle (Ready), at most once per 2 minutes. It never writes, edits or replays requests.
$trigDir = Join-Path $data 'index-trigger'
$indexerTask = 'Edutek Import Partial Indexer'
$script:wakeLast = [datetime]::MinValue; $script:wakeNoteLast = [datetime]::MinValue
function Write-IndexerNote($m) {
  Log $m
  try { [IO.File]::WriteAllText((Join-Path $queue 'indexer-watch.txt'), ((Get-Date).ToString('s') + '  ' + $m), $enc) } catch {}
}
function Wake-Indexer {
  $pend = @(Get-ChildItem -LiteralPath $trigDir -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
  if ($pend.Count -eq 0) { return }
  $now = Get-Date; $hb = Join-Path $trigDir 'indexer-heartbeat.txt'; $age = -1
  if (Test-Path -LiteralPath $hb) { $age = [int]($now - (Get-Item -LiteralPath $hb).LastWriteTime).TotalSeconds }
  $ageText = $(if ($age -lt 0) { 'no indexer heartbeat' } else { "indexer heartbeat $age s old" })
  $quiet = ($now - $script:wakeNoteLast).TotalSeconds -lt 120
  try { $state = [string](Get-ScheduledTask -TaskName $indexerTask -ErrorAction Stop).State } catch {
    if (-not $quiet) { $script:wakeNoteLast = $now; Write-IndexerNote "could not read task '$indexerTask': $($_.Exception.Message)" }
    return
  }
  if ($state -ne 'Ready') {
    if (($age -lt 0 -or $age -gt 120) -and -not $quiet) { $script:wakeNoteLast = $now; Write-IndexerNote "$($pend.Count) index request(s) waiting; task '$indexerTask' is $state ($ageText). Not starting it." }
    return
  }
  if (($now - $script:wakeLast).TotalSeconds -lt 120) { return }
  $script:wakeLast = $now; $script:wakeNoteLast = $now
  try { Start-ScheduledTask -TaskName $indexerTask -ErrorAction Stop; Write-IndexerNote "started task '$indexerTask': it was idle with $($pend.Count) index request(s) waiting ($ageText)." }
  catch { Write-IndexerNote "could not start task '$indexerTask': $($_.Exception.Message)" }
}
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
  $it = @($rec.items); $learn = @($it | Where-Object { -not $_.support })
  # Patch 4: a record reversed by Undo-Publish.ps1 shows as 'undone' in the browser, which then offers to reverse the staging and start again.
  $map = @{ published = 'published'; failed = 'failed'; publishing = 'publishing'; reversed = 'undone'; attention = 'attention' }
  $state = $map[[string]$rec.state]; if (-not $state) { $state = 'attention' }
  $mib = [math]::Round(((@($it | Measure-Object -Property bytes -Sum)[0].Sum) / 1MB), 1)
  [ordered]@{ batch = $id; state = $state; record_state = [string]$rec.state
    learning = $learn.Count; covers = $it.Count - $learn.Count; copied = @($it | Where-Object { $_.state -eq 'copied' -or $_.state -eq 'replaced' }).Count; mib = $mib
    replaced = @($it | Where-Object { $_.replace }).Count; replaced_learning = @($learn | Where-Object { $_.replace }).Count; archive_manifest = (S $rec.archive_manifest); undone = (S $rec.undone)
    index_request = $(if ($rec.index_requested) { 'publish-' + $id.Substring(0, 8) } else { $null })
    started = (S $rec.started); finished = (S $rec.finished); record = (Join-Path $RecordDir ($id + '.json')); message = (Tail ((S $message) + ' ' + (S $rec.error)).Trim() 3000) }
}
function Sync-Records {
  # Mirrors existing publish records (including ones made by hand, e.g. 57915b08, or reversed by Undo-Publish.ps1) so the page never offers a repeat.
  foreach ($f in @(Get-ChildItem -LiteralPath $RecordDir -Filter '*.json' -File -ErrorAction SilentlyContinue)) {
    if ($f.Name -notmatch '^([a-f0-9]{32})\.json$') { continue }
    $id = $Matches[1]
    if (-not (Test-Path -LiteralPath (Join-Path $guided $id) -PathType Container)) { continue }
    try {
      $rec = Read-Json $f.FullName; $sp = Join-Path $statusDir ($id + '.json'); $cur = $null
      if (Test-Path -LiteralPath $sp) { $cur = Read-Json $sp }
      if (-not $cur -or (S $cur.record_state) -ne (S $rec.state)) { $o = Status-FromRecord $id $rec 'Status taken from the publish record.'; $o['synced'] = $true; Write-Status $id $o; $script:dixDirty = $true; Log "synced status for $($id.Substring(0,8)) from record ($($rec.state))" }
    } catch { Log "could not sync $($f.Name): $($_.Exception.Message)" }
  }
}
function Check-Request($id, $req) {
  $p = New-Object System.Collections.Generic.List[string]
  $bd = Join-Path $guided $id; $bj = Join-Path $bd 'batch.json'
  if (Test-Path -LiteralPath (Join-Path $RecordDir ($id + '.json'))) { $p.Add('A publish record already exists for this batch. A batch is never published twice.'); return @{ problems = $p } }
  if (-not $req) { $p.Add('The request is not valid JSON.'); return @{ problems = $p } }
  if (-not (Test-Path -LiteralPath $bj -PathType Leaf)) { $p.Add('The staged batch was not found.'); return @{ problems = $p } }
  if ((Get-Item -LiteralPath $bd -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { $p.Add('The batch folder is a link.'); return @{ problems = $p } }
  $b = Read-Json $bj
  if ((S $req.batch) -cne $id) { $p.Add('The request names a different batch.') }
  if ($b.state -ne 'complete') { $p.Add("The staged batch state is '$($b.state)', not 'complete'.") }
  if (-not $b.approval -or (S $req.revision) -cne (S $b.approval)) { $p.Add('The approval revision does not match the staged batch.') }
  $want = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  $learning = New-Object System.Collections.Generic.List[string]; $byTarget = @{}
  $wantR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($prop in @($b.operations.PSObject.Properties)) {
    $op = $prop.Value; $row = $b.rows.($prop.Name); $t = S $op.target
    if ($op.state -ne 'copied' -or -not $op.sha256) { $p.Add("Not a verified staged copy: $t"); continue }
    [void]$want.Add($t + '|' + (S $op.sha256).ToLower())
    $src = Join-Path (Join-Path $bd 'files') ($t -replace '/', '\')
    if (-not (Test-Path -LiteralPath $src -PathType Leaf)) { $p.Add("Staged file missing: $t") }
    elseif ((Get-Item -LiteralPath $src).Length -ne [int64]$op.bytes) { $p.Add("Staged file size changed: $t") }
    if (-not $row.support) { $learning.Add($t); $byTarget[$t] = $row; if ($row.replace) { [void]$wantR.Add($t + '|' + [int64]$row.replace.bytes + '|' + [int64]$row.replace.mtime); if ((S $row.replace.path) -cne $t) { $p.Add("Replace choice does not match its target: $t") } } }
  }
  $got = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($t in @($req.targets)) { if ($t -and -not $got.Add((S $t.target) + '|' + (S $t.sha256).ToLower())) { $p.Add('The request lists a file twice.') } }
  if (-not $want.SetEquals($got)) { $p.Add('The requested files or hashes differ from the staged batch.') }
  if ([int]$req.learning_count -ne $learning.Count -or [int]$req.file_count -ne $want.Count) { $p.Add('The requested counts differ from the staged batch.') }
  $gotR = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($x in @($req.replace)) { if ($x) { [void]$gotR.Add((S $x.target) + '|' + [int64]$x.bytes + '|' + [int64]$x.mtime) } }
  if (-not $wantR.SetEquals($gotR) -or [int]$req.replace_count -ne $wantR.Count) { $p.Add('The requested replacements differ from the reviewed plan.') }
  $phrase = $(if ($wantR.Count -gt 0) { "PUBLISH $($learning.Count - $wantR.Count) NEW REPLACE $($wantR.Count)" } else { "PUBLISH $($learning.Count)" })
  if ((S $req.confirm) -cne $phrase) { $p.Add('The typed confirmation does not match.') }
  if ($learning.Count -gt $MaxLearningCeiling) { $p.Add("$($learning.Count) learning items exceed the publisher limit of $MaxLearningCeiling.") }
  $ack = New-Object System.Collections.Generic.List[string]
  foreach ($a in @($req.allow_same_size)) {
    if (-not $a) { continue }; $a = S $a
    if (-not $byTarget.ContainsKey($a) -or -not $byTarget[$a].duplicate_add) { $p.Add("Same-size acknowledgement is not backed by the reviewed plan: $a") } else { $ack.Add($a) }
  }
  @{ problems = $p; learning = $learning.Count; ack = [string[]]$ack.ToArray() }
}
function Backup-Db($id) {
  $name = 'pre-publish-' + $id.Substring(0, 8) + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql'
  $dest = Join-Path $DbBackupDir $name
  & docker exec edupak-db sh -c ('mysqldump -uroot -p$MYSQL_ROOT_PASSWORD --single-transaction --default-character-set=utf8mb4 edupak > /tmp/' + $name + ' 2>/tmp/' + $name + '.err')
  if ($LASTEXITCODE -ne 0) { throw 'Database backup failed (mysqldump).' }
  & docker cp ('edupak-db:/tmp/' + $name) $dest | Out-Null
  $cpOk = ($LASTEXITCODE -eq 0)
  & docker exec edupak-db rm -f ('/tmp/' + $name) ('/tmp/' + $name + '.err') | Out-Null
  if (-not $cpOk -or -not (Test-Path -LiteralPath $dest)) { throw 'Database backup could not be copied out of edupak-db.' }
  $fs = [IO.File]::OpenRead($dest); try { $n = [Math]::Min(400, $fs.Length); [void]$fs.Seek(-$n, 'End'); $buf = New-Object byte[] $n; [void]$fs.Read($buf, 0, $n) } finally { $fs.Close() }
  if (-not $enc.GetString($buf).Contains('-- Dump completed')) { throw "Database backup looks incomplete: $dest" }
  $dest
}
function Process-Request($r, $id) {
  $rp0 = Join-Path $RecordDir ($id + '.json')
  if (Test-Path -LiteralPath $rp0) { $o = Status-FromRecord $id (Read-Json $rp0) 'A new publish request was refused: this batch already has a publish record and is never published twice.'; $o['synced'] = $true; Write-Status $id $o; Log "$($id.Substring(0,8))  refused: record exists"; return }
  $st = [ordered]@{ batch = $id; state = 'checking'; requested = $r.LastWriteTime.ToString('s'); message = '' }
  Write-Status $id $st
  $req = $null; try { $req = Read-Json $r.FullName } catch {}
  $c = Check-Request $id $req
  if ($c.problems.Count -gt 0) { $st.state = 'blocked'; $st.message = ($c.problems -join "`n"); Write-Status $id $st; Log "$($id.Substring(0,8))  blocked: $($c.problems[0])"; return }
  $st.state = 'publishing'; $st.started = (Get-Date).ToString('s'); Write-Status $id $st
  try { $st.db_backup = Backup-Db $id } catch { $st.state = 'blocked'; $st.message = $_.Exception.Message + ' Nothing was copied.'; Write-Status $id $st; Log "$($id.Substring(0,8))  blocked: db backup"; return }
  $out = ''; $err = ''
  $args2 = @{ BatchId = $id; Execute = $true; ApprovedRequest = $r.FullName; MaxLearning = $c.learning; AllowSameSizeTarget = $c.ack }
  try { $out = (& $publisher @args2 *>&1 | Out-String) } catch { $err = $_.Exception.Message }
  $recPath = Join-Path $RecordDir ($id + '.json')
  if (Test-Path -LiteralPath $recPath) {
    $s2 = Status-FromRecord $id (Read-Json $recPath) ((Tail $out 2500) + ' ' + $err)
    $s2['db_backup'] = $st.db_backup; $s2['requested'] = $st.requested; Write-Status $id $s2; $script:dixDirty = $true; Log "$($id.Substring(0,8))  $($s2.state)"
  } else {
    $st.state = 'blocked'; $st.message = ('Publish-Batch.ps1 did not publish; nothing was copied. ' + $err + "`n" + (Tail $out 3000)).Trim(); Write-Status $id $st; Log "$($id.Substring(0,8))  blocked by Publish-Batch"
  }
}
try {
  Log "Watching $queue  (Ctrl+C to stop)"
  do {
    [IO.File]::WriteAllText((Join-Path $queue 'heartbeat.txt'), (Get-Date).ToString('s'), $enc)
    try { Sync-Records } catch { Log "sync error: $($_.Exception.Message)" }
    $reqs = @(Get-ChildItem -LiteralPath $queue -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
    if ($reqs.Count -gt 0) {
      Start-Sleep -Seconds 2
      foreach ($r in @(Get-ChildItem -LiteralPath $queue -Filter '*.request.json' -File | Sort-Object LastWriteTime)) {
        if ($r.Name -cnotmatch '^([a-f0-9]{32})\.request\.json$') { Log "ignored unexpected file $($r.Name)"; Retire $r; continue }
        $id = $Matches[1]
        try { Process-Request $r $id } catch {
          $em = 'Publisher error: ' + $_.Exception.Message; $rp = Join-Path $RecordDir ($id + '.json')
          if (Test-Path -LiteralPath $rp) { Write-Status $id (Status-FromRecord $id (Read-Json $rp) $em) } else { Write-Status $id ([ordered]@{ batch = $id; state = 'blocked'; message = $em + ' Nothing was copied.' }) }
          Log "$($id.Substring(0,8))  error: $($_.Exception.Message)"
        }
        Retire $r
      }
    }
    try { Wake-Indexer } catch { Log "indexer check error: $($_.Exception.Message)" }
    try { Update-DeviceIndexIfDue } catch { Log "device index check error: $($_.Exception.Message)" }
    if (-not $Once) { Start-Sleep -Seconds 5 }
  } while (-not $Once)
} finally { $lock.Close() }
'@) + "`n")
Build-Whole $dix $files[$dix].tmp $files[$dix].in $files[$dix].out ((NL @'
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
'@) + "`n")
Build-Whole $und $files[$und].tmp $files[$und].in $files[$und].out ((NL @'
param(
  [Parameter(Mandatory=$true)][string]$BatchId,
  [switch]$Execute,
  [switch]$OverrideChanged,
  [string]$RecordDir = 'D:\edutek-system\publish-records',
  [string]$RecoveryRoot = 'D:\edutek-system\publish-recovery',
  [string]$DataRoot = 'D:\edutek-system\bulk-loader-fresh-v05\data'
)
# Undo a published batch. Dry run by default; -Execute asks for the typed word UNDO.
#  - Files that were NEW in the batch: moved to publish-recovery\<batch>\ and their database rows deleted (as before).
#  - Files that REPLACED a library file (patch 4): the replacement is moved to publish-recovery\<batch>\replaced-by-this-batch\,
#    the archived original (replaced-archive\...\manifest.json) is moved back and its SHA256 checked, and a partial index
#    request (undo-<id8>, listed files only) refreshes their database rows. The manifest is marked 'restored'.
#  - Refuses when a replaced file was changed after publishing, unless -OverrideChanged (the changed file is then kept in recovery).
#  - Refuses when an archived original is missing or damaged (for example deleted by Clear-ReplacedArchive.ps1): it cannot be restored.
$ErrorActionPreference = 'Stop'
$recDir = $RecordDir
$recoveryRoot = $RecoveryRoot
$trig = Join-Path $DataRoot 'index-trigger'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Native([string]$rel) { $rel.Replace('/', [IO.Path]::DirectorySeparatorChar) }
function Get-Sha([string]$p) { (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLower() }
function Move-Into([string]$from, [string]$to) {
  New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($to)) | Out-Null
  if (Test-Path -LiteralPath $to) { $to = $to + '.' + (Get-Date -Format 'yyyyMMdd-HHmmss') }
  [IO.File]::Move($from, $to); $to
}
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

# Archive manifest (only for batches that replaced library files).
$man = $null; $manPath = [string]$rec.archive_manifest; $archByTarget = @{}
if (@($rec.items | Where-Object { $_.replace }).Count -gt 0) {
  if ($manPath -and (Test-Path -LiteralPath $manPath -PathType Leaf)) {
    $man = [IO.File]::ReadAllText($manPath, $utf8) | ConvertFrom-Json
    foreach ($f in @($man.files)) { if ($f -and ($f.state -eq 'archived' -or $f.state -eq 'restored')) { $archByTarget[[string]$f.target] = $f } }
  }
}

$plan = @(); $conflicts = @(); $refuse = @()
foreach ($i in $rec.items) {
  $act = 'absent'; $a = $archByTarget[[string]$i.target]; $dstHash = $null
  if (Test-Path -LiteralPath $i.dst -PathType Leaf) { $dstHash = Get-Sha $i.dst }
  if ($i.replace) {
    if (-not $a) {
      if ($i.state -eq 'replaced') { $refuse += "The archived original of $($i.target) is not listed in $manPath (archive deleted or moved). It cannot be restored, so nothing is undone." }
      else { $act = 'untouched' }   # publishing stopped before this file was replaced: the original never left.
    } elseif ($a.state -eq 'restored') {
      # An earlier undo run already put the original back.
      if ($dstHash -eq [string]$a.original_sha256) { $act = 'already-original' } else { $act = 'conflict'; $conflicts += "Changed after an earlier undo restored it, left in place: $($i.target)" }
    } else {
      if (-not (Test-Path -LiteralPath $a.archive_path -PathType Leaf)) { $refuse += "The archived original is missing: $($a.archive_path). $($i.target) cannot be restored, so nothing is undone." }
      elseif ((Get-Sha $a.archive_path) -ne [string]$a.original_sha256) { $refuse += "The archived original is damaged (SHA256 differs): $($a.archive_path). Nothing is undone." }
      if ($null -eq $dstHash) { $act = 'restore' }
      elseif ($dstHash -eq $i.sha256) { $act = 'restore' }
      elseif ($dstHash -eq [string]$a.original_sha256) { $act = 'already-original' }
      else {
        $act = 'restore-changed'
        if (-not $OverrideChanged) { $refuse += "Changed since publishing: $($i.target). The file there now is neither this batch's replacement nor the original. Re-run with -OverrideChanged to keep that file in recovery and restore the original." }
      }
    }
  } elseif ($null -ne $dstHash) {
    if ($dstHash -eq $i.sha256) { $act = 'move' } else { $act = 'conflict'; $conflicts += "Changed since publish, left in place: $($i.target)" }
  }
  $plan += [pscustomobject]@{ item = $i; action = $act; arch = $a; partial = (Test-Path -LiteralPath ($i.dst + '.partial')) }
}
$gen = @()
foreach ($i in $rec.items) {
  if ($i.gen_thumb) {
    $jpg = [IO.Path]::ChangeExtension($i.dst, '.jpg')
    if (Test-Path -LiteralPath $jpg) { $gen += [pscustomobject]@{ path = $jpg; rel = [IO.Path]::ChangeExtension($i.target, '.jpg') } }
  }
}
$dbIds = @($plan | Where-Object { $_.item.media -and -not $_.item.replace -and $_.action -ne 'conflict' } | ForEach-Object { SqlQ ('content/' + $_.item.target) })
$dbCount = 0
if ($dbIds.Count -gt 0) {
  $dbCount = [int](("" + (Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");"))).Trim())
}
$restores = @($plan | Where-Object { $_.action -eq 'restore' -or $_.action -eq 'restore-changed' })

Write-Host ""
Write-Host "Undo plan for batch $($rec.batch)"
Write-Host ("  New files to move to recovery:      {0}" -f @($plan | Where-Object { $_.action -eq 'move' }).Count)
Write-Host ("  Replaced files to restore:          {0}" -f $restores.Count)
foreach ($p in $restores) { Write-Host ("    restore " + $p.item.target + "  <=  " + $p.arch.archive_path + $(if ($p.action -eq 'restore-changed') { '  (CHANGED file there is kept in recovery)' } else { '' })) }
Write-Host ("  Already the original / untouched:   {0}" -f @($plan | Where-Object { $_.action -eq 'already-original' -or $_.action -eq 'untouched' }).Count)
Write-Host ("  Generated thumbnails to move:       {0}" -f $gen.Count)
Write-Host ("  Already absent:                     {0}" -f @($plan | Where-Object { $_.action -eq 'absent' }).Count)
Write-Host ("  Database rows to delete:            {0} (at most {1} expected; replaced files keep their rows and are re-indexed)" -f $dbCount, $dbIds.Count)
foreach ($c in $conflicts) { Write-Host ("  ! " + $c) -ForegroundColor Yellow }
if ($refuse.Count -gt 0) { Write-Host ""; Write-Host 'STOPPED. Nothing was changed:' -ForegroundColor Red; foreach ($x in $refuse) { Write-Host ("  - " + $x) -ForegroundColor Red }; return }
if ($dbCount -gt $dbIds.Count) { Write-Host 'STOPPED: more database rows match than this batch published.' -ForegroundColor Red; return }
if (-not $Execute) { Write-Host ""; Write-Host 'DRY RUN. Nothing was changed. Re-run with -Execute to undo.' -ForegroundColor Green; return }

$conf = Read-Host 'Type UNDO to undo this batch (move its new files to recovery, restore replaced originals, delete the new files'' database rows)'
if ($conf -ne 'UNDO') { Write-Host 'Cancelled.'; return }

if ($dbIds.Count -gt 0) {
  Invoke-Sql ("DELETE FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");") | Out-Null
  $left = [int](("" + (Invoke-Sql ("SELECT COUNT(*) FROM content_meta WHERE content_id IN (" + ($dbIds -join ',') + ");"))).Trim())
  if ($left -ne 0) { throw "Database rows remain after delete: $left" }
}
$restored = @()
foreach ($p in $plan) {
  if ($p.partial) { Remove-Item -LiteralPath ($p.item.dst + '.partial') -Force }
  if ($p.action -eq 'move') {
    [void](Move-Into $p.item.dst (Join-Path $recovery (Native $p.item.target)))
  }
  if ($p.action -eq 'restore' -or $p.action -eq 'restore-changed') {
    $sub = $(if ($p.action -eq 'restore') { 'replaced-by-this-batch' } else { 'changed-after-publish' })
    if (Test-Path -LiteralPath $p.item.dst) { [void](Move-Into $p.item.dst (Join-Path (Join-Path $recovery $sub) (Native $p.item.target))) }
    [IO.File]::Move($p.arch.archive_path, $p.item.dst)
    if ((Get-Sha $p.item.dst) -ne [string]$p.arch.original_sha256) { throw "Restored file does not match the original SHA256: $($p.item.dst)" }
    $restored += $p
    Write-Host ("  restored " + $p.item.target)
  }
}
foreach ($g in $gen) {
  [void](Move-Into $g.path (Join-Path (Join-Path $recovery 'generated') (Native $g.rel)))
}
foreach ($d in @($rec.created_dirs) | Sort-Object { $_.Length } -Descending) {
  if ((Test-Path -LiteralPath $d) -and -not (Get-ChildItem -LiteralPath $d -Force | Select-Object -First 1)) { Remove-Item -LiteralPath $d -Force }
}
$idxName = $null
$idxFiles = @($restored | Where-Object { $_.item.media } | ForEach-Object { [string]$_.item.target })
if ($idxFiles.Count -gt 0) {
  $idxName = 'undo-' + ([string]$rec.batch).Substring(0, 8) + '-' + (Get-Date -Format 'HHmmss')
  New-Item -ItemType Directory -Force -Path $trig | Out-Null
  $tmpReq = Join-Path $trig ($idxName + '.request.tmp')
  [IO.File]::WriteAllText($tmpReq, ([ordered]@{ batch = $idxName; files = $idxFiles } | ConvertTo-Json -Compress), $utf8)
  Move-Item -LiteralPath $tmpReq -Destination (Join-Path $trig ($idxName + '.request.json')) -Force
}
if ($man) {
  foreach ($f in @($man.files)) { if ($f -and @($restored | Where-Object { $_.arch.target -eq $f.target }).Count) { $f.state = 'restored' } }
  $man.state = 'restored'; $man | Add-Member -NotePropertyName restored -NotePropertyValue ((Get-Date).ToString('s')) -Force
  [IO.File]::WriteAllText($manPath, ($man | ConvertTo-Json -Depth 5), $utf8)
}
$rec.state = $(if ($conflicts.Count -gt 0) { 'attention' } else { 'reversed' })
$rec | Add-Member -NotePropertyName undone -NotePropertyValue ((Get-Date).ToString('s')) -Force
$rec | Add-Member -NotePropertyName undo_index_request -NotePropertyValue $idxName -Force
$rec | Add-Member -NotePropertyName undo_restored -NotePropertyValue $restored.Count -Force
[IO.File]::WriteAllText($recPath, ($rec | ConvertTo-Json -Depth 6), $utf8)
Write-Host ""
Write-Host ("UNDO finished: " + $rec.state) -ForegroundColor Green
Write-Host "Moved files are in $recovery"
if ($restored.Count) { Write-Host "$($restored.Count) replaced file(s) were restored from the archive." }
if ($idxName) { Write-Host "Partial index request $idxName was queued for the restored files (listed files only)." }
Write-Host 'In the browser this import now shows as undone; reverse its staging there to start again.'
'@) + "`n")
Build-Whole $null $files[$clr].tmp '' $files[$clr].out ((NL @'
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
Write-Host ("{0} of {1} batch archive(s) can be deleted ({2:N1} MiB)." -f $del.Count, $rows.Count, (($del | Measure-Object -Property mib -Sum).Sum))
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
'@) + "`n")
foreach ($k in $files.Keys) { $f = $files[$k].tmp; if ($f -notlike '*.ps1') { continue }; $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$null, [ref]$e); if ($e.Count) { throw "PowerShell parse errors in $f : $e" } }
'PowerShell parse checks passed.'
$c = @(& $Docker --context $DockerContext ps --filter 'publish=8092' --format '{{.Names}}')
if ($c.Count -ne 1 -or $c[0] -ne 'edutek-bulk-fresh-v05-web-1') { throw "Unexpected importer container(s): $($c -join ', ')" }
& $Docker --context $DockerContext exec $c[0] php -l /sandbox/_p4-guided.php
if ($LASTEXITCODE -ne 0) { throw 'php -l failed for the patched guided page. Live files unchanged.' }
$t = Get-ScheduledTask -TaskName 'Edutek Import Publisher' -ErrorAction Stop; "Task 'Edutek Import Publisher': $($t.State)"
$pend = @(Get-ChildItem -LiteralPath (RootPath 'data','publish-queue') -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
"Publish requests waiting: $($pend.Count)"
if ($pend.Count -gt 0) { 'WARNING: wait until no publish request is waiting before -Apply.' }

if (-not $Apply) {
  foreach ($k in $files.Keys) { Remove-Item -LiteralPath $files[$k].tmp -Force -ErrorAction SilentlyContinue }
  ''; 'ALL CHECKS PASSED. Nothing live was changed (temporary built copies removed). Re-run with -Apply to install.'; return
}
if ($pend.Count -gt 0) { foreach ($k in $files.Keys) { Remove-Item -LiteralPath $files[$k].tmp -Force -ErrorAction SilentlyContinue }; throw 'A publish request is waiting. Let the publisher finish it first; nothing was changed.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $bak = Join-Path $BackupRoot $stamp
New-Item -ItemType Directory -Force -Path $bak | Out-Null
foreach ($k in $files.Keys) { if (-not $files[$k].in) { continue }; $d = Join-Path $bak ([IO.Path]::GetFileName($k)); Copy-Item -LiteralPath $k -Destination $d; if ((HashOf $k) -ne (HashOf $d)) { throw "Backup mismatch: $k" } }
"Backups OK in $bak"
foreach ($k in @($dix, $und, $clr, $pub, $pq, $guided)) { Move-Item -LiteralPath $files[$k].tmp -Destination $k -Force }
foreach ($k in $files.Keys) { if ((HashOf $k) -ne $files[$k].out) { throw "Live hash mismatch: $k. Run: .\Install-Patch4.ps1 -Rollback '$bak'" } }
& $Docker --context $DockerContext exec $c[0] php -l /sandbox/public/bulk-import-guided.php
if ($LASTEXITCODE -ne 0) { throw "Live php -l failed. Run: .\Install-Patch4.ps1 -Rollback '$bak'" }
''; "INSTALLED. Backups: $bak"
"Rollback if needed: .\Install-Patch4.ps1 -Rollback '$bak'"
'Next: restart the publisher task when no publish requests are waiting (see patch-4.md). It rebuilds the device index (v3, with file times) on start.'
