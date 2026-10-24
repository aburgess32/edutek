# Install-Patch4b.ps1 - Edutek importer patch 4b: comparable review cards, one-click Step 4 publish, browser undo,
# automatic catalog cleanup after publish/undo indexing, and the main-app "Bulk import content" link.
# Run in Windows PowerShell on the Beelink as edutek1\edutek: first without -Apply (checks only), then with -Apply.
# Changes (each hash-checked before and after; nothing live changes without -Apply):
#   importer: app\public\bulk-import-guided.php, Undo-Publish.ps1, Run-PublishQueue.ps1, Clear-ReplacedArchive.ps1,
#             Run-ImportIndexer.ps1; adds catalog-cleanup-cli.php (outside the app; copied into edupak-app /tmp only while it runs)
#   main app: D:\edutek\htdocs\js\local-index-hotkey.js (button label + link), D:\edutek\htdocs\bulk-import-sandbox.php (now a redirect)
# Checks Publish-Batch.ps1 is the reviewed version (not changed). Never runs an index, a publish, an undo or a cleanup.
# Rollback: run this script with -Rollback <backup folder printed by -Apply>, then restart both tasks (see patch-4b.md).
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [switch]$Force,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$AppRoot = 'D:\edutek\htdocs',
  [string]$BackupRoot = 'D:\edutek-system\backups\importer-review-cards',
  [string]$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$DockerContext = 'desktop-linux'
)
$ErrorActionPreference = 'Stop'
function NL([string]$s) { $s -replace "`r`n", "`n" }
function P([string]$base, [string[]]$parts) { $p = $base; foreach ($x in $parts) { $p = [IO.Path]::Combine($p, $x) }; $p }
function HashOf($path) { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash }
function D { & $Docker --context $DockerContext @args }
function Build-Pairs($src, $tmp, $inHash, $outHash, $pairs) {
  $h = HashOf $src
  if ($h -ne $inHash) { throw "$src has unexpected SHA256 $h. It differs from the reviewed version. Stop." }
  $b = [IO.File]::ReadAllBytes($src); $bom = $b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF
  $enc = New-Object System.Text.UTF8Encoding($bom); $off = $(if ($bom) { 3 } else { 0 })
  $t = $enc.GetString($b, $off, $b.Length - $off)
  # Pairs are applied in order; each marker must occur exactly once at the moment it is applied.
  $i = 0; foreach ($p in $pairs) { $i++; $n = ([regex]::Matches($t, [regex]::Escape($p[0]))).Count; if ($n -ne 1) { throw "$src : marker $i found $n times, expected 1." }; $t = $t.Replace($p[0], $p[1]) }
  [IO.File]::WriteAllText($tmp, $t, $enc)
  if ((HashOf $tmp) -ne $outHash) { Remove-Item -LiteralPath $tmp; throw "Built copy of $src does not match the expected hash. Stop." }
  "Built OK: $tmp"
}
function Build-Whole($src, $tmp, $inHash, $outHash, [string]$text) {
  # PHP files are written without a BOM (a BOM before <?php would be sent to the browser and break the redirect header).
  if ($src -and (HashOf $src) -ne $inHash) { throw "$src has unexpected SHA256 $(HashOf $src). It differs from the reviewed version. Stop." }
  [IO.File]::WriteAllText($tmp, $text, (New-Object System.Text.UTF8Encoding($false)))
  if ((HashOf $tmp) -ne $outHash) { Remove-Item -LiteralPath $tmp; throw "Built copy for $tmp does not match the expected hash. Stop." }
  "Built OK: $tmp"
}
function AppLint($file, $label) {
  # php -l inside edupak-app on a copy in /tmp; the app's own folder is not touched.
  D cp $file 'edupak-app:/tmp/_p4b-check.php' | Out-Null
  if ($LASTEXITCODE -ne 0) { throw "Could not copy $label into edupak-app for php -l." }
  D exec edupak-app php -l /tmp/_p4b-check.php; $rc = $LASTEXITCODE
  D exec edupak-app rm -f /tmp/_p4b-check.php | Out-Null
  if ($rc -ne 0) { throw "php -l failed in edupak-app for $label." }
}

$guided = P $Root 'app','public','bulk-import-guided.php'; $und = P $Root 'Undo-Publish.ps1'; $pq = P $Root 'Run-PublishQueue.ps1'
$clr = P $Root 'Clear-ReplacedArchive.ps1'; $ix = P $Root 'Run-ImportIndexer.ps1'; $cli = P $Root 'catalog-cleanup-cli.php'
$hot = P $AppRoot 'js','local-index-hotkey.js'; $sbx = P $AppRoot 'bulk-import-sandbox.php'; $pub = P $Root 'Publish-Batch.ps1'
$files = [ordered]@{
  $guided = @{ in = '3A3883C34348FD21D435B4A0BFB09891F2A22EE24C9BA4462B31BBE33F9D5665'; out = '74B65343DC49123BAEE25E169B0F74129E5FC8A49DE657DAC7FE4887CA8027BF'; tmp = (P $Root 'app','_p4b-guided.php') }
  $und    = @{ in = 'E41868C2B084B828A9FD077B49C066FD1182381439D3050F2BDC1B3FC5FB7B0C'; out = 'DECF55F3339D87F0827A8108F438BB9E8D23DDA5181D85F1786F8E40C714F0FE'; tmp = (P $Root '_p4b-Undo-Publish.ps1') }
  $pq     = @{ in = 'A5926C00BA81339570484E84E27616B2887D6DBCED46CB8B1CA3BCBB127721FA'; out = '2BAC24FA18822547DF5B5DE8A237B348512794D5D5B242F23059A139F18098B0'; tmp = (P $Root '_p4b-Run-PublishQueue.ps1') }
  $clr    = @{ in = '0E4469B4AF272D44548291694822170E92438BB9B4C1F74FADF85568F97CB7DD'; out = 'C7474F2860D0272D7D2BE2B8B5CD744EA2A0BF296759B1E5E94CB6BE1D113E59'; tmp = (P $Root '_p4b-Clear-ReplacedArchive.ps1') }
  $ix     = @{ in = '7B35FAAF94D4CD4C38EF16EE3EBEE655D829640D8C61A6C41603E5C27AFA2D72'; out = '6C743E65601F8E4A70F0E45D7FF11A5911C6D5732D2D97249BFE3120742F79DC'; tmp = (P $Root '_p4b-Run-ImportIndexer.ps1') }
  $cli    = @{ in = '';        out = 'E9CDC44A5A67288C0BA75A30F6B2A56400D4F864856D238102F2BC5F1E181116'; tmp = (P $Root '_p4b-catalog-cleanup-cli.php') }
  $hot    = @{ in = '32DEBB583765DF59C6CE2F99D75DA3C9761D062E0094C4E3E4CFCE651E1E5662'; out = '20629AA4C984DFFF2280551A034F799CBE22C0DB2E0BC874B634E3AE61C5C227'; tmp = (P $Root '_p4b-local-index-hotkey.js') }
  $sbx    = @{ in = '2DE3BC8423508C5CC7416C8932B48BA138A94D2AB82A98E1385A34255A224E00'; out = '7E65F7BB59599F90361AF11FEBE2BA708DB8E25BD94B008C0DF3A2883DD74792'; tmp = (P $Root '_p4b-bulk-import-sandbox.php') }
}
function Pending {
  @(Get-ChildItem -LiteralPath (P $Root 'data','publish-queue') -Filter '*.request.json' -File -ErrorAction SilentlyContinue) +
  @(Get-ChildItem -LiteralPath (P $Root 'data','index-trigger') -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
}

if ($Rollback) {
  # Puts every backed-up file back (each hash-checked against its pre-patch hash) and removes catalog-cleanup-cli.php.
  if (-not (Test-Path -LiteralPath $Rollback -PathType Container)) { throw "Backup folder not found: $Rollback" }
  $pend = @(Pending)
  if ($pend.Count -gt 0) { throw ("Requests are waiting (" + (($pend | ForEach-Object { $_.Name }) -join ', ') + "). Let the publisher and indexer finish them first. Nothing was rolled back.") }
  foreach ($k in $files.Keys) {
    if (-not $files[$k].in) { continue }
    $b = Join-Path $Rollback ([IO.Path]::GetFileName($k))
    if (-not (Test-Path -LiteralPath $b) -or (HashOf $b) -ne $files[$k].in) { throw "Backup $b is missing or does not have the pre-patch hash. Nothing was rolled back." }
    if ((Test-Path -LiteralPath $k) -and -not $Force) { $h = HashOf $k; if ($h -ne $files[$k].out -and $h -ne $files[$k].in) { throw "$k was changed after patch 4b (SHA256 $h). Nothing was rolled back (use -Force to roll back anyway)." } }
  }
  foreach ($k in $files.Keys) {
    if (-not $files[$k].in) { continue }
    $b = Join-Path $Rollback ([IO.Path]::GetFileName($k))
    Copy-Item -LiteralPath $b -Destination ($k + '.p4brb') -Force; Move-Item -LiteralPath ($k + '.p4brb') -Destination $k -Force
    if ((HashOf $k) -ne $files[$k].in) { throw "Rollback hash mismatch: $k" }
  }
  if (Test-Path -LiteralPath $cli) { Move-Item -LiteralPath $cli -Destination (Join-Path $Rollback 'catalog-cleanup-cli.php.removed') -Force }
  'ROLLED BACK to the pre-patch-4b files. Restart the "Edutek Import Publisher" and "Edutek Import Partial Indexer" tasks (see patch-4b.md), then hard-reload the pages.'
  'Publish records, audits, archives, undo results and catalog-cleanup logs are kept.'
  return
}

$state = @{}
foreach ($k in $files.Keys) {
  if (-not (Test-Path -LiteralPath $k)) { $state[$k] = 'missing' }
  else { $h = HashOf $k; $state[$k] = $(if ($h -eq $files[$k].out) { 'new' } elseif ($h -eq $files[$k].in) { 'old' } else { 'other' }) }
}
if (@($files.Keys | Where-Object { $state[$_] -ne 'new' }).Count -eq 0) { 'Patch 4b is already installed. Nothing to do.'; return }
foreach ($k in $files.Keys) {
  $okState = $(if ($files[$k].in) { 'old' } else { 'missing' })
  if ($state[$k] -ne $okState) { throw "$k is '$($state[$k])' (expected the pre-patch version$(if (-not $files[$k].in) { ', i.e. not present' })). It differs from the reviewed version, or patch 4b is partly installed. Stop; nothing was changed." }
}
if (-not (Test-Path -LiteralPath $pub) -or (HashOf $pub) -ne 'E337972D50FF5F651FE758C073000F69501651693B99631B27497C878098754A') { throw "$pub is not the reviewed patch-4 version (E337972D...). Stop; nothing was changed." }
'All 7 files to change have the reviewed pre-patch hashes; catalog-cleanup-cli.php is not present yet; Publish-Batch.ps1 is the reviewed version.'

$repG = @(
  ,@((NL @'
function pq_phrase(int $learn,int $rep): string {
'@), (NL @'
/* Patch 4b: side-by-side duplicate comparison. Every card in a match shows the same rows in the same order
   (title, file name, type, size, file date, location, outcome). Compared rows carry a SAME / SIMILAR / DIFFERENT marker
   (text, not only colour) against the reference card: the existing copy, or copy 1 when nothing exists yet.
   File names use the shared name key (dx_key) and also report an exact match. Device index v3 mtime is a FileTime. */
function rc_unix($t): ?int {if($t===null||!is_numeric($t))return null;$t=(int)$t;return $t>100000000000000?intdiv($t,10000000)-11644473600:$t;}
function rc_srcname(array $r): string {$root=(string)($r['src_root']??'/import-source');return $root==='/import-source'?'Desktop Videos folder':basename($root);}
function rc_tname(string $ext): string {return $ext==='pdf'?'Book':($ext==='mp4'?'Video':'File');}
function rc_path(string $p): string {return trim(str_replace('/',' > ',$p),' >');}
function rc_cards(array $members,array $exs): array {
 $ix=dx_index();$first=reset($members);$cards=[];
 foreach($exs as $x){
  if(($x['kind']??'earlier')==='device'){$p=rp_dev_path([$x]);$f=$ix['files'][$p]??null;$fn=basename($p);$d=dirname($p);
   $cards[]=['key'=>'orig','dev'=>true,'path'=>$p,'why'=>(string)($x['why']??''),'title'=>bi_tidy_title(pathinfo($fn,PATHINFO_FILENAME)),'name'=>$fn,'ext'=>dx_ext($fn),'bytes'=>$f?(int)$f[0]:null,'time'=>$f?rc_unix($f[1]):null,'loc'=>'Edutek library: Edutek > videos'.($d!=='.'&&$d!==''?' > '.rc_path($d):''),'folder'=>null];}
  else{$w=rc_path((string)($x['where']??''));
   // An earlier staged copy matched on type, title key and size; its file name and date are not recorded.
   $cards[]=['key'=>'orig','dev'=>false,'path'=>'','why'=>'','title'=>null,'name'=>null,'ext'=>strtolower((string)($first['extension']??'')),'bytes'=>(int)$first['bytes'],'time'=>null,'loc'=>'Staged in an earlier import'.(!empty($x['created'])?' ('.substr((string)$x['created'],0,10).')':'').', planned for Edutek > videos'.($w!==''?' > '.$w:''),'folder'=>null];}
 }
 $ci=0;foreach($members as $rid=>$m){$ci++;$src=(string)$m['source'];$fn=basename($src);$sd=dirname($src);$sn=rc_srcname($m);
  $cards[]=['key'=>(string)$rid,'n'=>$ci,'dev'=>false,'title'=>(string)$m['title'],'name'=>$fn,'ext'=>strtolower((string)($m['extension']??dx_ext($fn))),'bytes'=>(int)$m['bytes'],'time'=>rc_unix($m['mtime']??null),
   'loc'=>'Found in: '.(($sd==='.'||$sd==='')?$sn.' (top folder)':$sn.' > '.rc_path($sd)),'folder'=>($sd==='.'?'':$sd),'rep'=>(string)($m['replace']['path']??''),'plan'=>(string)($m['replace']['planned_target']??$m['target'])];}
 return $cards;
}
function rc_cmp(array $a,array $b,string $f): string {
 if($f==='folder'&&($a['folder']===null||$b['folder']===null))return '';
 $x=$a[$f]??null;$y=$b[$f]??null;if($x===null||$y===null)return 'na';
 if($f==='name'||$f==='title'){if($x===$y)return 'same';$k=dx_key($x);return ($k!==''&&$k===dx_key($y))?'similar':'diff';}
 return $x===$y?'same':'diff';
}
function rc_agg(array $all): string {return !$all?'':(in_array('diff',$all,true)?'diff':(in_array('na',$all,true)?'na':(in_array('similar',$all,true)?'similar':'same')));}
function rc_marks(array $cards): array {
 // marks[card index][field]. Card 0 is the reference and gets the combined result of the others. Folders: source copies only, against the first one.
 $mk=[];foreach($cards as $i=>$c)$mk[$i]=[];
 foreach(['title','name','ext','bytes','time'] as $f){$all=[];foreach($cards as $i=>$c){if($i===0)continue;$mk[$i][$f]=rc_cmp($cards[0],$c,$f);$all[]=$mk[$i][$f];}$mk[0][$f]=rc_agg($all);}
 $src=[];foreach($cards as $i=>$c)if($c['key']!=='orig')$src[]=$i;
 if(count($src)>1){$all=[];foreach(array_slice($src,1) as $i){$mk[$i]['folder']=rc_cmp($cards[$src[0]],$cards[$i],'folder');$all[]=$mk[$i]['folder'];}$mk[$src[0]]['folder']=rc_agg($all);}
 return $mk;
}
function rc_badge(string $m,string $f,string $vs): string {
 if($m==='')return '';
 $t=['same'=>$f==='name'?'&#10003; SAME (exact match)':'&#10003; SAME','similar'=>$f==='name'?'&#8776; SIMILAR (same letters and digits, not exact)':'&#8776; SIMILAR (same letters and digits)','diff'=>'&ne; DIFFERENT','na'=>'? NOT COMPARED (not recorded)'][$m]??'';
 return ' <span class="cmp cmp-'.$m.'" title="Compared with '.h($vs).'">'.$t.'</span>';
}
function rc_rows(array $c,array $mk,string $vs): string {
 $b=fn($f)=>rc_badge($mk[$f]??'',$f,$vs);$row=fn($k,$v,$f='')=>'<p class="cmp-row"><span class="cmp-k">'.$k.':</span> '.$v.($f!==''?$b($f):'').'</p>';
 $nr='<span class="muted">not recorded</span>';
 $o=$row('Title',$c['title']===null?$nr:h($c['title']),'title');
 $o.=$row('File name',$c['name']===null?$nr:h($c['name']),'name');
 $o.=$row('Type',h(rc_tname($c['ext'])).($c['ext']!==''?' (.'.h($c['ext']).')':''),'ext');
 $o.=$row('Size',$c['bytes']===null?$nr:number_format($c['bytes']/1048576,1).' MiB ('.number_format($c['bytes']).' bytes)','bytes');
 $o.=$row('File date',$c['time']===null?$nr:h(gmdate('Y-m-d H:i:s',$c['time'])).' UTC','time');
 $o.=$row('Location',h($c['loc']),'folder');
 return $o;
}
function rc_note(array $cards,array $members,bool $dev): string {
 $ref=$cards[0];$s=[];
 if($ref['key']==='orig'&&$dev){$c=null;foreach($cards as $x)if($x['key']!=='orig'){$c=$x;break;}
  if($c){$nm=rc_cmp($ref,$c,'name');$sz=rc_cmp($ref,$c,'bytes');$nmS=$nm==='same'?'Same file name':($nm==='similar'?'Similar file name (same letters and digits)':'Different file name');
   if($sz==='same')$s[]=$nmS.' and same size as the library copy. They may be identical: the publisher compares the contents before copying and never publishes an identical copy.';
   elseif($sz==='diff')$s[]=$nmS.', different size ('.number_format((int)$c['bytes']).' vs '.number_format((int)$ref['bytes']).' bytes): likely a different version of the same material.';
   else $s[]=$nmS.'; the library size is not known (library list too old).';}}
 elseif($ref['key']==='orig')$s[]='Same title and size as the copy staged in an earlier import. Only an exact-content check can prove they are identical.';
 if(count($members)>1){$d=[];$first=reset($members);foreach($members as $m2){if(dirname($m2['source'])!==dirname($first['source']))$d['folder']='different folders';if(basename($m2['source'])!==basename($first['source']))$d['name']='different file names';if((int)$m2['mtime']!==(int)$first['mtime'])$d['date']='different file dates';if(empty($m2['art'])!==empty($first['art']))$d['art']='different artwork';}
  $s[]='Between the source copies: same title and size; '.($d?implode(', ',$d):'no differences in the details shown').'. Only an exact-content check can prove two files are identical.';}
 return implode(' ',$s);
}
function pq_phrase(int $learn,int $rep): string {
'@))
  ,@((NL @'
.dup-tag{font-size:12px;font-weight:600;letter-spacing:.3px;color:#365b80;margin-bottom:8px}
'@), (NL @'
.dup-tag{font-size:12px;font-weight:600;letter-spacing:.3px;color:#365b80;margin-bottom:8px}.cmp-row{margin:5px 0;font-size:14px;color:#4a6075}.cmp-k{font-weight:600;color:#365b80}.cmp{display:inline-block;margin-left:4px;padding:0 6px;border-radius:10px;font-size:11px;font-weight:700;border:1px solid;white-space:nowrap}.cmp-same{background:#d4efdc;color:#14532d;border-color:#6fbf8e}.cmp-similar{background:#dff3ef;color:#134e4a;border-color:#6fb8ad}.cmp-diff{background:#fde8c4;color:#7a4300;border-color:#d99a3a}.cmp-na{background:#eef2f6;color:#4a6075;border-color:#b8c6d4}.dup-why{font-size:12px;color:#4a6075;margin:0 0 4px}.cmp-legend{font-size:13px;color:#4a6075;margin:6px 0 0}
'@))
  ,@((NL @'
<?php foreach($exs as $x):?><div class="dup-col existing" data-key="orig" tabindex="0" role="button" aria-pressed="false"><div class="dup-tag"><?=h(($x['kind']??'earlier')==='device'?'ALREADY ON THIS DEVICE':'ALREADY STAGED IN AN EARLIER IMPORT')?></div><div class="dup-state"></div><div class="art placeholder">EXISTING</div><h4><?=h($first['title'])?></h4><p class="muted"><?=h(bi_dup_where($x))?></p><p class="muted"><?=($x['kind']??'')==='device'?'Choose only the source copy to replace this library file (the old file is archived and can be restored with Undo-Publish.ps1).':'This copy stays where it is whatever you choose.'?></p></div><?php endforeach;?>
'@), (NL @'
<?php $rcC=rc_cards($members,$exs);$rcM=rc_marks($rcC);$rcVs=$exs?((($exs[0]['kind']??'earlier')==='device')?'the copy already on this device':'the copy staged in an earlier import'):'copy 1';$rcI=0;$rcDev=false;foreach($exs as $x)if(($x['kind']??'')==='device')$rcDev=true;?>
<?php foreach($exs as $x):$rc=$rcC[$rcI];$rcK=$rcI;$rcI++;$rcLp='Edutek > videos > '.rc_path($rc['path']);?><div class="dup-col existing" data-key="orig" tabindex="0" role="button" aria-pressed="false" data-o-none="<?=h($rc['dev']?'Stays in the library at '.$rcLp.', unless you choose only a source copy':'Stays staged in that earlier import')?>" data-o-win="<?=h($rc['dev']?'Stays in the library at '.$rcLp:'Stays staged in that earlier import')?>" data-o-replaced="<?=h($rc['dev']?'Moved to D:\\edutek-system\\replaced-archive when publishing (Undo-Publish.ps1 can restore it); the chosen source copy takes its place at '.$rcLp:'Stays staged in that earlier import (only library files can be replaced)')?>"><div class="dup-tag"><?=h(($x['kind']??'earlier')==='device'?'ALREADY ON THIS DEVICE':'ALREADY STAGED IN AN EARLIER IMPORT')?></div><?php if($rc['why']!==''):?><p class="dup-why">Matched by: <?=h($rc['why'])?></p><?php endif;?><div class="dup-state"></div><div class="art placeholder">EXISTING</div><h4><?=h($rc['title']??$first['title'])?></h4><?=rc_rows($rc,$rcM[$rcK]??[],$rcK===0?'the other cards':$rcVs)?><p class="cmp-row"><span class="cmp-k">Outcome:</span> <span class="dup-outcome"><?=h($rc['dev']?'Stays in the library at '.$rcLp.', unless you choose only a source copy':'Stays staged in that earlier import')?></span></p><p class="muted"><?=($x['kind']??'')==='device'?'Choose only the source copy to replace this library file (the old file is archived and can be restored with Undo-Publish.ps1).':'This copy stays where it is whatever you choose.'?></p></div><?php endforeach;?>
'@))
  ,@((NL @'
<div class="dup-col" data-key="<?=h($mrid)?>" tabindex="0" role="button" aria-pressed="false"><div class="dup-tag">COPY <?=$ci?> (IN THE SOURCE)</div><div class="dup-state"></div>
<?php if(!empty($m['art'])):?><img class="art" alt="Artwork for copy <?=$ci?>" src="?id=<?=h($id)?>&amp;art=<?=h($mrid)?>"><?php else:?><div class="art placeholder">NO ARTWORK</div><?php endif;?>
<h4><?=h($m['title'])?></h4>
<p class="muted"><?=h($m['type']==='Books'?'Book':'Video')?> - <?=number_format($m['bytes']/1048576,1)?> MiB (<?=number_format($m['bytes'])?> bytes)</p>
<p class="muted">File date: <?=h(gmdate('Y-m-d H:i',(int)$m['mtime']))?> UTC</p>
<p class="muted">Found in: <?=h(str_replace('/',' > ',dirname($m['source'])))?></p>
<p class="muted">File name: <?=h(basename($m['source']))?></p>
<p class="muted">Would be added to: Edutek &gt; videos &gt; <?=h(str_replace('/',' > ',dirname($m['target'])))?></p>
</div>
'@), (NL @'
<?php $rc=$rcC[$rcI];$rcK=$rcI;$rcI++;?><div class="dup-col" data-key="<?=h($mrid)?>" data-n="<?=$ci?>" tabindex="0" role="button" aria-pressed="false" data-o-add="<?=h('Would be added to: Edutek > videos > '.rc_path($rc['plan']))?>" data-o-reject="Not added (rejected)" data-o-skip="Skipped: only files already in the Edutek library can be replaced"><div class="dup-tag">COPY <?=$ci?> (IN THE SOURCE)</div><?php if($rcDev):?><p class="dup-why">&nbsp;</p><?php endif;?><div class="dup-state"></div>
<?php if(!empty($m['art'])):?><img class="art" alt="Artwork for copy <?=$ci?>" src="?id=<?=h($id)?>&amp;art=<?=h($mrid)?>"><?php else:?><div class="art placeholder">NO ARTWORK</div><?php endif;?>
<h4><?=h($m['title'])?></h4>
<?=rc_rows($rc,$rcM[$rcK]??[],$rcK===0?'the other cards':$rcVs)?>
<p class="cmp-row"><span class="cmp-k">Outcome:</span> <span class="dup-outcome"><?=h($rc['rep']!==''?'Replaces the library file: Edutek > videos > '.rc_path($rc['rep']).' (the old file is archived first)':'Would be added to: Edutek > videos > '.rc_path($rc['plan']))?></span></p>
</div>
'@))
  ,@((NL @'
<?php $diff=[];if(count($members)>1){foreach($members as $m2){if(dirname($m2['source'])!==dirname($first['source']))$diff['folder']='different folders';if(basename($m2['source'])!==basename($first['source']))$diff['name']='different file names';if((int)$m2['mtime']!==(int)$first['mtime'])$diff['date']='different file dates';if(empty($m2['art'])!==empty($first['art']))$diff['art']='different artwork';}}?>
<p class="muted"><?php if(count($members)>1):?>Same title and size. <?=$diff?h('Differences: '.implode(', ',$diff).'.'):'No differences in the details shown.'?> Only an exact-content check can prove two files are identical.<?php else:?>Same title and size as the existing copy. Only an exact-content check can prove they are identical.<?php endif;?></p>
'@), (NL @'
<p class="cmp-legend">Each row is marked SAME, SIMILAR or DIFFERENT compared with <?=h($rcVs)?><?=$exs?'':' (copy 1 is compared with the others)'?>. Locations are compared between source copies only.</p>
<p class="muted rc-note"><?=h(rc_note($rcC,$members,$rcDev))?></p>
'@))
  ,@((NL @'
c.classList.remove('winner','rejected','replaced');if(cls)c.classList.add(cls);
'@), (NL @'
const oc=c.querySelector('.dup-outcome');if(oc){let ot='';if(key==='orig'){ot=W.has('orig')?c.dataset.oWin:(W.size?c.dataset.oReplaced:c.dataset.oNone)}else{const d=res[key]||'',rr=ms.find(z=>z.dataset.id===key),sv=rr?(get(rr,'replace-path')?.value||''):'',lp=sv||(d==='replace'?(p.dataset.libPath||''):'');if(d==='reject')ot=c.dataset.oReject;else if(d==='add')ot=c.dataset.oAdd;else if(d==='replace')ot=lp?'Replaces the library file: Edutek > videos > '+lp.split('/').join(' > ')+' (the old file is archived first)':c.dataset.oSkip;else ot=sv?'Replaces the library file: Edutek > videos > '+sv.split('/').join(' > ')+' (the old file is archived first)':c.dataset.oAdd}if(ot)oc.textContent=ot}
  c.classList.remove('winner','rejected','replaced');if(cls)c.classList.add(cls);
'@))
  ,@((NL @'
<label>Type <strong>'.h($phr).'</strong> to confirm<br><input type="text" name="confirm_text" required autocomplete="off" spellcheck="false" style="max-width:260px"></label> <button class="primary" name="action" value="publish">Publish '.$what.' to the Edutek library</button></form>'
'@), (NL @'
<input type="hidden" name="action" value="publish"><input type="hidden" name="new_count" value="'.$new.'"><input type="hidden" name="replace_count" value="'.$rep.'"><p class="publish-summary"><strong>Ready to publish: '.$new.' new file'.($new===1?'':'s').($rep>0?', '.$rep.' replacement'.($rep===1?'':'s'):'').'</strong> ('.$files.' files including covers, '.$mib.' MiB)</p>'.($rep>0?'<p><label for="replace-ack"><input type="checkbox" name="replace_ack" value="1" id="replace-ack"> I understand '.$rep.' library file'.($rep>1?'s':'').' will be replaced; the old file'.($rep>1?'s are':' is').' archived and can be restored with Undo-Publish.ps1</label></p>':'').'<button class="primary" type="submit" id="publish-btn"'.($rep>0?' disabled':'').'>Publish to the Edutek library</button>'.($rep>0?' <span class="muted" id="publish-hint">Tick the box above first (Tab to it, then press Space).</span>':'').'</form>'
'@))
  ,@((NL @'
.'<script>document.getElementById("publish-form").onsubmit=()=>confirm('.json_encode('Publish '.$what.' ('.$files.' files, '.$mib.' MiB) to the Edutek library now?'.($rep>0?' '.$rep.' library file'.($rep>1?'s are':' is').' replaced; the old cop'.($rep>1?'ies go':'y goes').' to the archive.':''),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');</script></div>';
'@), (NL @'
.'<script>(()=>{const f=document.getElementById("publish-form"),b=document.getElementById("publish-btn"),a=document.getElementById("replace-ack"),hi=document.getElementById("publish-hint");let sent=false;const u=()=>{b.disabled=sent||(!!a&&!a.checked);if(hi)hi.hidden=!a||a.checked};if(a)a.addEventListener("change",u);u();f.addEventListener("submit",e=>{if(sent||(a&&!a.checked)){e.preventDefault();return}sent=true;b.disabled=true;b.textContent="Sending the publish request\u2026"});window.addEventListener("pageshow",e=>{if(e.persisted){sent=false;b.textContent="Publish to the Edutek library";u()}})})();</script></div>';
'@))
  ,@((NL @'
    }elseif($action==='publish'){
     $pi=pq_info($id);

'@), (NL @'
    }elseif($action==='publish'){
     $pi=pq_info($id);
     // Patch 4b: a repeated submit of the same approved plan (double click, reload) changes nothing; the first request stands.
     if(!(($pi['requested']||in_array($pi['state'],['checking','publishing','published'],true))&&!empty($batch['approval'])&&hash_equals((string)$batch['approval'],(string)($_POST['revision']??'')))){

'@))
  ,@((NL @'
     if(trim((string)($_POST['confirm_text']??''))!==pq_phrase($learnN,$repN))throw new RuntimeException('Type '.pq_phrase($learnN,$repN).' to confirm publishing');
'@), (NL @'
     $pn=$_POST['new_count']??null;$pr=$_POST['replace_count']??null;
     if(!is_string($pn)||!is_string($pr)||!ctype_digit($pn)||!ctype_digit($pr)||(int)$pn!==$learnN-$repN||(int)$pr!==$repN)throw new RuntimeException('The publish summary on this page no longer matches the staged import ('.($learnN-$repN).' new, '.$repN.' replacements). Reload the page and check it again. Nothing was requested.');
     if($repN>0&&($_POST['replace_ack']??'')!=='1')throw new RuntimeException('Tick "I understand '.$repN.' library file'.($repN>1?'s':'').' will be replaced" before publishing. Nothing was requested.');
'@))
  ,@((NL @'
'confirm'=>pq_phrase($learnN,$repN),'learning_count'=>$learnN,
'@), (NL @'
'confirm'=>pq_phrase($learnN,$repN),'confirm_method'=>($repN>0?'summary+replace-checkbox':'summary'),'new_count'=>$learnN-$repN,'learning_count'=>$learnN,
'@))
  ,@((NL @'
'requested_at'=>gmdate(DATE_ATOM)]);
    }elseif($action==='reverse'){
'@), (NL @'
'requested_at'=>gmdate(DATE_ATOM)]);
     }
    }elseif($action==='reverse'){
'@))
  ,@((NL @'
function pq_can_request(array $pi): bool {
'@), (NL @'
/* Patch 4b: browser undo. The page only writes publish-queue/<batch>.undo-plan.request.json (dry run) and <batch>.undo.request.json
   (execute the plan that was shown). Run-PublishQueue.ps1 runs Undo-Publish.ps1 on the Beelink and writes publish-status/<batch>.undo.json. */
const UQ_COUNTS=['move','restore','gen','db_rows'];
function uq_write(string $id,string $kind,array $data): void {if(!is_dir(PQ_DIR)&&!mkdir(PQ_DIR,0770,true))throw new RuntimeException('Cannot create the publish queue');bi_json(PQ_DIR.'/'.$id.'.'.$kind.'.request.json',$data);}
function uq_busy(array $pi): bool {return $pi['undo_req']||$pi['undo_plan_req']||in_array((string)($pi['undo']['state']??''),['planning','undoing'],true);}
function uq_plan_ok(?array $u): bool {$pl=$u['plan']??null;return is_array($u)&&($u['state']??'')==='planned'&&is_array($pl)&&($pl['outcome']??'')==='planned'&&empty($pl['refuse'])&&(int)($pl['changed']??0)===0&&is_string($pl['fingerprint']??null)&&preg_match('/^[a-f0-9]{64}$/D',$pl['fingerprint']);}
function uq_panel(string $id,string $csrf,array $pi): string {
 $u=is_array($pi['undo']??null)?$pi['undo']:[];$us=(string)($u['state']??'');$pl=is_array($u['plan']??null)?$u['plan']:null;$i8=h(substr($id,0,8));
 $o='<div class="undo-box" id="undo" style="margin-top:14px;border-top:1px solid #d5e0eb;padding-top:10px"><h3>Undo this publish</h3>';
 $again='<script>setTimeout(()=>location.reload(),5000)</script>';
 $hid='<input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="id" value="'.h($id).'">';
 $planBtn=fn($l)=>'<form method="post" class="undo-form">'.$hid.'<input type="hidden" name="action" value="undo_plan"><button type="submit">'.$l.'</button></form>';
 if($pi['undo_req']||$us==='undoing')return $o.'<p class="notice">Undoing&hellip; The publisher on the Beelink is moving this import&rsquo;s new files to recovery and putting replaced library files back from the archive. Wait for &ldquo;Publish undone&rdquo;.</p>'.$again.'</div>';
 if($pi['undo_plan_req']||$us==='planning')return $o.'<p class="notice">Preparing the undo plan on the Beelink. This is a dry run: nothing is changed.</p>'.$again.'</div>';
 $msg=trim((string)($u['message']??''));$msgH=$msg!==''?'<pre class="report" style="white-space:pre-wrap">'.h($msg).'</pre>':'';
 $console='<p class="muted">Files changed after publishing are never overridden from the browser. To restore anyway, run on the Beelink: <code>powershell -NoProfile -ExecutionPolicy Bypass -File .\\Undo-Publish.ps1 -BatchId '.$i8.' -Execute -OverrideChanged</code> (dry run first without -Execute; it asks you to type UNDO).</p>';
 if($us==='planned'&&$pl&&($pl['outcome']??'')==='planned'){
  $c=is_array($pl['counts']??null)?$pl['counts']:[];$n=fn($k)=>(int)($c[$k]??0);
  $o.='<p>Undo plan from the Beelink (dry run at '.h($pl['written']??'').'):</p><ul>'
   .'<li>New files to remove from the Edutek library (moved to D:\\edutek-system\\publish-recovery): <strong>'.$n('move').'</strong></li>'
   .'<li>Replaced library files to restore from the archive: <strong>'.$n('restore').'</strong></li>'
   .'<li>Already the original or untouched: '.$n('original').'</li><li>Generated thumbnails to move: '.$n('gen').'</li><li>Already absent: '.$n('absent').'</li>'
   .'<li>Database rows to delete: '.$n('db_rows').' (replaced files keep their rows and are re-indexed)</li></ul>';
  $fl=array_slice(is_array($pl['files']??null)?$pl['files']:[],0,60);
  if($fl){$o.='<details open><summary>Files ('.count($pl['files']).')</summary><ul class="muted">';foreach($fl as $f){$a=(string)($f['action']??'');$o.='<li>'.h($a==='move'?'remove':($a==='restore'?'restore original':$a)).': '.h($f['target']??'').'</li>';}$o.='</ul></details>';}
  foreach((array)($pl['conflicts']??[]) as $cf)$o.='<p class="warning">'.h($cf).'</p>';
  if(!uq_plan_ok($u))return $o.'<p class="error">This plan cannot be run from the browser.</p>'.$console.$planBtn('Show the undo plan again').'</div>';
  $ack='I understand '.$n('move').' new file'.($n('move')===1?'':'s').' will be removed from the Edutek library (kept in recovery) and '.$n('restore').' replaced file'.($n('restore')===1?'':'s').' will be restored from the archive';
  $o.='<form method="post" class="undo-form" id="undo-form">'.$hid.'<input type="hidden" name="action" value="undo"><input type="hidden" name="plan_fingerprint" value="'.h($pl['fingerprint']).'">';
  foreach(UQ_COUNTS as $k)$o.='<input type="hidden" name="count_'.$k.'" value="'.$n($k).'">';
  $o.='<p><label for="undo-ack"><input type="checkbox" name="undo_ack" value="1" id="undo-ack"> '.h($ack).'</label></p><button type="submit" class="primary" id="undo-btn" disabled>Undo publish</button> <span class="muted" id="undo-hint">Tick the box above first (Tab to it, then press Space).</span></form>';
  return $o.$planBtn('Show the undo plan again').'</div>';
 }
 if($us==='stopped'||$us==='refused'||$us==='mismatch'||$us==='failed'){
  $t=['stopped'=>'The undo was stopped. Nothing was changed.','refused'=>'The undo request was refused. Nothing was changed.','mismatch'=>'The library changed since the plan was shown, so nothing was undone.','failed'=>'The undo failed. Check the message below; some files may already have moved.'][$us];
  $o.='<p class="'.($us==='failed'?'error':'warning').'">'.$t.'</p>'.$msgH;
  if((int)($pl['changed']??0)>0||str_contains($msg,'OverrideChanged'))$o.=$console;
  if($us==='failed')$o.='<p class="muted">On the Beelink, run Undo-Publish.ps1 -BatchId '.$i8.' (dry run) to review.</p>';
  return $o.$planBtn('Show undo plan').'</div>';
 }
 return $o.'<p>Removes this import&rsquo;s new files from the Edutek library (kept in recovery) and puts back any library files it replaced. First see the plan (a dry run on the Beelink), then confirm it.</p>'.$planBtn('Show undo plan').'<p class="muted">On the Beelink you can also run Undo-Publish.ps1 -BatchId '.$i8.' (dry run first).</p></div>';
}
function pq_can_request(array $pi): bool {
'@))
  ,@((NL @'
return ['status'=>$st,'state'=>(string)($st['state']??''),'requested'=>is_file(PQ_DIR.'/'.$id.'.request.json'),
'@), (NL @'
return ['undo'=>pq_read(PS_DIR.'/'.$id.'.undo.json'),'undo_req'=>is_file(PQ_DIR.'/'.$id.'.undo.request.json'),'undo_plan_req'=>is_file(PQ_DIR.'/'.$id.'.undo-plan.request.json'),'status'=>$st,'state'=>(string)($st['state']??''),'requested'=>is_file(PQ_DIR.'/'.$id.'.request.json'),
'@))
  ,@((NL @'
  return $o.'<p class="muted">To reverse, run Undo-Publish.ps1 -BatchId '.h(substr($id,0,8)).' on the Beelink (dry run first).</p></div>';
'@), (NL @'
  return $o.uq_panel($id,$csrf,$pi).'<script>(()=>{document.querySelectorAll(".undo-form").forEach(f=>{let sent=false;f.addEventListener("submit",e=>{if(sent){e.preventDefault();return}const a=f.querySelector("#undo-ack");if(a&&!a.checked){e.preventDefault();return}sent=true;const b=f.querySelector("button");if(b){b.disabled=true;b.textContent="Sending\\u2026"}})});const a=document.getElementById("undo-ack"),b=document.getElementById("undo-btn"),hi=document.getElementById("undo-hint");if(a&&b){const u=()=>{b.disabled=!a.checked;if(hi)hi.hidden=a.checked};a.addEventListener("change",u);u()}})();</script></div>';
'@))
  ,@((NL @'
 if($s==='undone'||$s==='reversed')return $o.'<h2>Publish undone</h2><p>Undo-Publish.ps1 reversed this publish'.(!empty($st['undone'])?' on '.h($st['undone']):'').'.
'@), (NL @'
 $uix='';$un=(string)($st['undo_index_request']??'');if(preg_match('/^undo-[a-f0-9]{8}-\\d{6}$/D',$un)){$ux=pq_read(BI_ROOT.'/index-trigger/'.$un.'.result.json');$uix=!$ux?'<p class="notice">Indexing of the restored files: queued as '.h($un).'.</p><script>setTimeout(()=>location.reload(),8000)</script>':((($ux['state']??'')==='complete'&&(int)($ux['exit_code']??-1)===0)?'<p class="notice">Indexing complete for '.h($un).'.</p>':'<p class="error">Indexing did not complete for '.h($un).'. Check index-trigger\\'.h($un).'.result.json on the Beelink. Do not run a full index.</p>');}
 if($s==='undone'||$s==='reversed')return $o.'<h2>Publish undone</h2>'.$uix.'<p>Undo-Publish.ps1 reversed this publish'.(!empty($st['undone'])?' on '.h($st['undone']):'').'.
'@))
  ,@((NL @'
    }elseif($action==='reverse'){
'@), (NL @'
    }elseif($action==='undo_plan'||$action==='undo'){
     // Patch 4b: browser undo of a published import. The web container never touches the library; the Beelink publisher runs Undo-Publish.ps1.
     $pi=pq_info($id);
     if($pi['state']!=='published')throw new RuntimeException('Only a published import can be undone here');
     if(uq_busy($pi)){/* repeated submit (double click, reload): the waiting request stands and nothing new is written */}
     elseif($action==='undo_plan'){uq_write($id,'undo-plan',['batch'=>$id,'kind'=>'undo-plan','requested_at'=>gmdate(DATE_ATOM)]);}
     else{
      $u=$pi['undo'];if(!uq_plan_ok($u))throw new RuntimeException('Show the undo plan first, and check it again. Nothing was requested.');
      $fp=$_POST['plan_fingerprint']??null;if(!is_string($fp)||!hash_equals((string)$u['plan']['fingerprint'],$fp))throw new RuntimeException('The undo plan changed since this page was loaded. Show the undo plan again. Nothing was requested.');
      $cn=[];foreach(UQ_COUNTS as $k){$v=$_POST['count_'.$k]??null;if(!is_string($v)||!ctype_digit($v)||(int)$v!==(int)($u['plan']['counts'][$k]??-1))throw new RuntimeException('The undo counts on this page no longer match the plan. Show the undo plan again. Nothing was requested.');$cn[$k]=(int)$v;}
      if(($_POST['undo_ack']??'')!=='1')throw new RuntimeException('Tick "I understand ..." before undoing. Nothing was requested.');
      uq_write($id,'undo',['batch'=>$id,'kind'=>'undo','plan_fingerprint'=>$fp,'counts'=>$cn,'requested_at'=>gmdate(DATE_ATOM)]);
     }
    }elseif($action==='reverse'){
'@))
  ,@((NL @'
<h2>Reverse this import (staged copies only)</h2>
'@), (NL @'
<h2>Clear the staged copies of this import (does not touch the Edutek library)</h2>
'@))
  ,@((NL @'
This does not undo anything already published to the Edutek library; use Undo-Publish.ps1 for that.</p>
'@), (NL @'
This does not undo anything already published to the Edutek library; for that use &ldquo;Undo this publish&rdquo; in Step 4 (or Undo-Publish.ps1 on the Beelink).</p>
'@))
  ,@((NL @'
<button name="action" value="reverse">Check & reverse this entire import</button>
'@), (NL @'
<button name="action" value="reverse">Clear staged copies of this import (does not touch the library)</button>
'@))
  ,@((NL @'
function uq_panel(string $id,string $csrf,array $pi): string {
'@), (NL @'
function cc_line(string $rn): string {
 /* Patch 4b: result of the automatic catalog cleanup that Run-ImportIndexer.ps1 runs after a publish or undo index. */
 if(!preg_match('/^(publish-[a-f0-9]{8}|undo-[a-f0-9]{8}-\d{6})$/D',$rn))return '';
 $c=pq_read(BI_ROOT.'/index-trigger/'.$rn.'.cleanup.json');
 if(!$c){$rf=BI_ROOT.'/index-trigger/'.$rn.'.result.json';$t=is_file($rf)?(int)@filemtime($rf):0;return ($t&&time()-$t<900)?'<p class="notice">Catalog cleanup: running after '.h($rn).'&hellip;</p><script>setTimeout(()=>location.reload(),8000)</script>':'';}
 $s=(string)($c['state']??'');$n=(int)($c['removed']??0);$a=(string)($c['audit']??'');
 if($s==='done')return '<p class="notice">Catalog cleanup: '.$n.' stale row'.($n===1?'':'s').' removed'.($n>0&&$a!==''?' (list of removed rows: D:\\edutek-system\\index-status\\'.h($a).')':'').'.</p>';
 if($s==='held')return '<p class="warning">Catalog cleanup held, nothing was deleted: '.h((string)($c['message']??'')).' Details: data\\index-trigger\\catalog-cleanup.txt on the Beelink.'.($a!==''?' Rows it found: D:\\edutek-system\\index-status\\'.h($a).'.':'').'</p>';
 return '<p class="error">Catalog cleanup did not finish: '.h((string)($c['message']??'unknown error')).' Nothing more is deleted automatically. Details: data\\index-trigger\\catalog-cleanup.txt on the Beelink. Do not run a full index.</p>';
}
function uq_panel(string $id,string $csrf,array $pi): string {
'@))
  ,@((NL @'
$o.='<p class="notice">Indexing complete for '.$rn.' (finished '.h($ix['finished']??'').').</p>';
'@), (NL @'
$o.='<p class="notice">Indexing complete for '.$rn.' (finished '.h($ix['finished']??'').').</p>'.cc_line((string)$st['index_request']);
'@))
  ,@((NL @'
'<p class="notice">Indexing complete for '.h($un).'.</p>':
'@), (NL @'
'<p class="notice">Indexing complete for '.h($un).'.</p>'.cc_line($un):
'@))
)
$repU = @(
  ,@((NL @'
  [switch]$OverrideChanged,

'@), (NL @'
  [switch]$OverrideChanged,
  [string]$ApprovedRequest = '',
  [string]$PlanOut = '',

'@))
  ,@((NL @'
#  - Refuses when an archived original is missing or damaged (for example deleted by Clear-ReplacedArchive.ps1): it cannot be restored.

'@), (NL @'
#  - Refuses when an archived original is missing or damaged (for example deleted by Clear-ReplacedArchive.ps1): it cannot be restored.
# Patch 4b (browser undo, run by Run-PublishQueue.ps1): -PlanOut <file> also writes the plan (counts, files, fingerprint) or the result as JSON.
#  -Execute -ApprovedRequest <undo request> runs without the typed UNDO, but only when the request names this batch and its plan
#  fingerprint and counts equal the plan computed now. -OverrideChanged is refused together with -ApprovedRequest (console only).
#  Run by hand without these parameters, the script behaves exactly as before.

'@))
  ,@((NL @'
$restores = @($plan | Where-Object { $_.action -eq 'restore' -or $_.action -eq 'restore-changed' })

'@), (NL @'
$restores = @($plan | Where-Object { $_.action -eq 'restore' -or $_.action -eq 'restore-changed' })
$fpLines = @(@($plan | ForEach-Object { $_.action + '|' + [string]$_.item.target }) + @($gen | ForEach-Object { 'gen|' + [string]$_.rel }) | Sort-Object) + @("db|$dbCount")
$sha = [Security.Cryptography.SHA256]::Create(); $fp = -join ($sha.ComputeHash($utf8.GetBytes($fpLines -join "`n")) | ForEach-Object { $_.ToString('x2') })
$counts = [ordered]@{ move = @($plan | Where-Object { $_.action -eq 'move' }).Count; restore = $restores.Count
  original = @($plan | Where-Object { $_.action -eq 'already-original' -or $_.action -eq 'untouched' }).Count; gen = $gen.Count
  absent = @($plan | Where-Object { $_.action -eq 'absent' }).Count; db_rows = $dbCount; db_expected = $dbIds.Count }
$idxName = $null
function Emit-Plan([string]$outcome, [string]$msg) {
  if (-not $PlanOut) { return }
  $files = @($plan | Where-Object { $_.action -ne 'absent' } | ForEach-Object { [ordered]@{ action = $_.action; target = [string]$_.item.target; archive = $(if ($_.arch) { [string]$_.arch.archive_path } else { '' }); media = [bool]$_.item.media } })
  $o = [ordered]@{ batch = [string]$rec.batch; record_state = [string]$rec.state; outcome = $outcome; message = $msg; fingerprint = $fp; counts = $counts
    files = $files; generated = @($gen | ForEach-Object { [string]$_.rel }); conflicts = @($conflicts); refuse = @($refuse)
    changed = @($plan | Where-Object { $_.action -eq 'restore-changed' }).Count; index_request = $idxName; written = (Get-Date).ToString('s') }
  $tmp = $PlanOut + '.tmp'
  [IO.File]::WriteAllText($tmp, ($o | ConvertTo-Json -Depth 5), $utf8)
  Move-Item -LiteralPath $tmp -Destination $PlanOut -Force
}
if ($ApprovedRequest) {
  if ($OverrideChanged) { Emit-Plan 'mismatch' '-OverrideChanged is never used for a browser request. Run Undo-Publish.ps1 by hand on the Beelink for that.'; throw '-OverrideChanged cannot be combined with -ApprovedRequest.' }
  if (-not $Execute) { throw '-ApprovedRequest needs -Execute.' }
  $areq = $null; try { $areq = [IO.File]::ReadAllText($ApprovedRequest, $utf8) | ConvertFrom-Json } catch {}
  $bad = @()
  if (-not $areq) { $bad += 'the request is not valid JSON' } else {
    if ([string]$areq.batch -cne [string]$rec.batch) { $bad += 'it names a different batch' }
    if ([string]$areq.kind -cne 'undo') { $bad += 'it is not an undo request' }
    if ([string]$areq.plan_fingerprint -cne $fp) { $bad += 'the plan changed since it was shown' }
    foreach ($k in @('move', 'restore', 'gen', 'db_rows')) { if ($null -eq $areq.counts -or [string]$areq.counts.$k -cne [string]$counts[$k]) { $bad += "count '$k' differs" } }
  }
  if ($bad.Count -gt 0) {
    $mm = 'The undo request does not match the undo plan computed now (' + ($bad -join '; ') + '). Nothing was changed. Show the undo plan again.'
    Write-Host $mm -ForegroundColor Red; Emit-Plan 'mismatch' $mm; return
  }
}

'@))
  ,@((NL @'
if ($refuse.Count -gt 0) { Write-Host ""; Write-Host 'STOPPED. Nothing was changed:' -ForegroundColor Red; foreach ($x in $refuse) { Write-Host ("  - " + $x) -ForegroundColor Red }; return }
if ($dbCount -gt $dbIds.Count) { Write-Host 'STOPPED: more database rows match than this batch published.' -ForegroundColor Red; return }
if (-not $Execute) { Write-Host ""; Write-Host 'DRY RUN. Nothing was changed. Re-run with -Execute to undo.' -ForegroundColor Green; return }

$conf = Read-Host 'Type UNDO to undo this batch (move its new files to recovery, restore replaced originals, delete the new files'' database rows)'

'@), (NL @'
if ($refuse.Count -gt 0) { Write-Host ""; Write-Host 'STOPPED. Nothing was changed:' -ForegroundColor Red; foreach ($x in $refuse) { Write-Host ("  - " + $x) -ForegroundColor Red }; Emit-Plan 'stopped' ('STOPPED. Nothing was changed: ' + ($refuse -join ' ')); return }
if ($dbCount -gt $dbIds.Count) { Write-Host 'STOPPED: more database rows match than this batch published.' -ForegroundColor Red; Emit-Plan 'stopped' 'STOPPED: more database rows match than this batch published. Nothing was changed.'; return }
if (-not $Execute) { Write-Host ""; Write-Host 'DRY RUN. Nothing was changed. Re-run with -Execute to undo.' -ForegroundColor Green; Emit-Plan 'planned' 'Dry run. Nothing was changed.'; return }

if ($ApprovedRequest) { $conf = 'UNDO'; Write-Host 'Approved in the browser: the request matches this plan, so the typed UNDO is not asked.' }
else { $conf = Read-Host 'Type UNDO to undo this batch (move its new files to recovery, restore replaced originals, delete the new files'' database rows)' }

'@))
  ,@((NL @'
$idxName = $null
$idxFiles = @(
'@), (NL @'
$idxFiles = @(
'@))
  ,@((NL @'
Write-Host 'In the browser this import now shows as undone; reverse its staging there to start again.'

'@), (NL @'
Write-Host 'In the browser this import now shows as undone; reverse its staging there to start again.'
Emit-Plan 'done' ("UNDO finished: " + $rec.state + ". " + $restored.Count + " replaced file(s) restored. Moved files are in " + $recovery)

'@))
)
$repQ = @(
  ,@((NL @'
  [string]$LibraryRoot = 'D:\xampp\htdocs\Edutek\videos'
)
'@), (NL @'
  [string]$LibraryRoot = 'D:\xampp\htdocs\Edutek\videos',
  [string]$RecoveryRoot = 'D:\edutek-system\publish-recovery'
)
'@))
  ,@((NL @'
$publisher = Join-Path $Root 'Publish-Batch.ps1'

'@), (NL @'
$publisher = Join-Path $Root 'Publish-Batch.ps1'
$undoScript = Join-Path $Root 'Undo-Publish.ps1'

'@))
  ,@((NL @'
# Patch 4: requests may replace reviewed library files (Publish-Batch.ps1 archives the old copies; Undo-Publish.ps1 restores them).

'@), (NL @'
# Patch 4: requests may replace reviewed library files (Publish-Batch.ps1 archives the old copies; Undo-Publish.ps1 restores them).
# Patch 4b: browser undo. <batch>.undo-plan.request.json runs Undo-Publish.ps1 as a dry run; <batch>.undo.request.json runs it with
# -Execute -ApprovedRequest (it re-checks that the plan is unchanged). Results go to data\publish-status\<batch>.undo.json. Never -OverrideChanged.

'@))
  ,@((NL @'
    replaced = @($it | Where-Object { $_.replace }).Count; replaced_learning = @($learn | Where-Object { $_.replace }).Count; archive_manifest = (S $rec.archive_manifest); undone = (S $rec.undone)

'@), (NL @'
    replaced = @($it | Where-Object { $_.replace }).Count; replaced_learning = @($learn | Where-Object { $_.replace }).Count; archive_manifest = (S $rec.archive_manifest); undone = (S $rec.undone)
    undo_index_request = (S $rec.undo_index_request)

'@))
  ,@((NL @'
function Process-Request($r, $id) {

'@), (NL @'
function Write-UndoStatus($id, $o) {
  $o['updated'] = (Get-Date).ToString('s')
  $tmp = Join-Path $statusDir ($id + '.undo.tmp')
  [IO.File]::WriteAllText($tmp, ($o | ConvertTo-Json -Depth 7 -Compress), $enc)
  Move-Item -LiteralPath $tmp -Destination (Join-Path $statusDir ($id + '.undo.json')) -Force
}
function Process-Undo($r, $id, $kind) {
  $recPath = Join-Path $RecordDir ($id + '.json'); $usp = Join-Path $statusDir ($id + '.undo.json')
  $req = $null; try { $req = Read-Json $r.FullName } catch {}
  $cur = $null; if (Test-Path -LiteralPath $usp) { try { $cur = Read-Json $usp } catch {} }
  $p = New-Object System.Collections.Generic.List[string]
  if (-not $req) { $p.Add('The undo request is not valid JSON.') }
  elseif ((S $req.batch) -cne $id -or (S $req.kind) -cne $kind) { $p.Add('The undo request names a different batch or kind.') }
  if (-not (Test-Path -LiteralPath $recPath -PathType Leaf)) { $p.Add('This batch has no publish record, so there is nothing to undo.') }
  else { $rec = Read-Json $recPath; if ((S $rec.state) -cne 'published') { $p.Add("The publish record state is '$($rec.state)'. Only a published import can be undone from the browser; use Undo-Publish.ps1 on the Beelink.") } }
  if ($kind -eq 'undo') {
    if (-not $cur -or (S $cur.state) -cne 'planned' -or -not $cur.plan -or (S $cur.plan.outcome) -cne 'planned') { $p.Add('No current undo plan was shown for this import. Show the undo plan again.') }
    elseif ((S $req.plan_fingerprint) -cne (S $cur.plan.fingerprint)) { $p.Add('The undo plan changed since it was shown. Show the undo plan again.') }
  }
  if ($p.Count -gt 0) { Write-UndoStatus $id ([ordered]@{ batch = $id; state = 'refused'; kind = $kind; message = ($p -join "`n") + ' Nothing was changed.' }); Log "$($id.Substring(0,8))  $kind refused: $($p[0])"; return }
  $planOut = Join-Path $statusDir ($id + '.undo-result.json')
  Remove-Item -LiteralPath $planOut -Force -ErrorAction SilentlyContinue
  Write-UndoStatus $id ([ordered]@{ batch = $id; state = $(if ($kind -eq 'undo') { 'undoing' } else { 'planning' }); kind = $kind; started = (Get-Date).ToString('s'); plan = $(if ($cur) { $cur.plan } else { $null }) })
  $args3 = @{ BatchId = $id; PlanOut = $planOut; RecordDir = $RecordDir; RecoveryRoot = $RecoveryRoot; DataRoot = $data }
  if ($kind -eq 'undo') { $args3['Execute'] = $true; $args3['ApprovedRequest'] = $r.FullName }
  $out = ''; $err = ''
  try { $out = (& $undoScript @args3 *>&1 | Out-String) } catch { $err = $_.Exception.Message }
  $res = $null; if (Test-Path -LiteralPath $planOut) { try { $res = Read-Json $planOut } catch {}; Remove-Item -LiteralPath $planOut -Force -ErrorAction SilentlyContinue }
  $oc = $(if ($res) { S $res.outcome } else { '' })
  if ($kind -eq 'undo-plan') { $state = $(if ($err) { 'failed' } elseif ($oc -eq 'planned') { 'planned' } elseif ($oc -eq 'stopped') { 'stopped' } else { 'failed' }) }
  else { $state = $(if ($err) { 'failed' } elseif ($oc -eq 'done') { 'done' } elseif ($oc -eq 'stopped') { 'stopped' } elseif ($oc -eq 'mismatch') { 'mismatch' } else { 'failed' }) }
  $msg = ((S $(if ($res) { $res.message } else { '' })) + ' ' + $err).Trim()
  if (-not $msg -and $state -eq 'failed') { $msg = (Tail $out 2000).Trim() }
  Write-UndoStatus $id ([ordered]@{ batch = $id; state = $state; kind = $kind; finished = (Get-Date).ToString('s'); plan = $res; message = (Tail $msg 3000); output = (Tail $out 3000) })
  if ($kind -eq 'undo') { $script:dixDirty = $true }
  Log "$($id.Substring(0,8))  $kind -> $state"
}
function Process-Request($r, $id) {

'@))
  ,@((NL @'
        if ($r.Name -cnotmatch '^([a-f0-9]{32})\.request\.json$') { Log "ignored unexpected file $($r.Name)"; Retire $r; continue }

'@), (NL @'
        if ($r.Name -cmatch '^([a-f0-9]{32})\.(undo-plan|undo)\.request\.json$') {
          $uid = $Matches[1]; $uk = $Matches[2]
          try { Process-Undo $r $uid $uk } catch { Write-UndoStatus $uid ([ordered]@{ batch = $uid; state = 'failed'; kind = $uk; message = 'Publisher error: ' + $_.Exception.Message }); Log "$($uid.Substring(0,8))  $uk error: $($_.Exception.Message)" }
          Retire $r; continue
        }
        if ($r.Name -cnotmatch '^([a-f0-9]{32})\.request\.json$') { Log "ignored unexpected file $($r.Name)"; Retire $r; continue }

'@))
)
$repC = @(
  ,@((NL @'
Write-Host ("{0} of {1} batch archive(s) can be deleted ({2:N1} MiB)." -f $del.Count, $rows.Count, (($del | Measure-Object -Property mib -Sum).Sum))
'@), (NL @'
Write-Host ("{0} of {1} batch archive(s) can be deleted ({2:N1} MiB)." -f $del.Count, $rows.Count, [double](@($del | Measure-Object -Property mib -Sum).Sum))
'@))
)
$repX = @(
  ,@((NL @'
param([switch]$DryRun,[switch]$Once)

'@), (NL @'
param([switch]$DryRun,[switch]$Once,[string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',[string]$DbBackupDir = 'D:\edutek-system\backups\db')

'@))
  ,@((NL @'
function Write-Result($name, $obj) {
'@), (NL @'
# Patch 4b: after a successful partial index for a browser publish (publish-<id8>) or undo (undo-<id8>-HHmmss), run the catalog
# cleanup (content_meta rows whose files no longer exist) with catalog-cleanup-cli.php, copied into edupak-app /tmp for the run.
# Guards: the library mount must hold >= 30,000 PDF/MP4 files and >= 95% of the device list; a report pass runs first; more than
# 25 stale rows (or more than 0.5% of rows) are never deleted automatically, only reported. A database backup is taken before
# any row is deleted. Results: index-trigger\<request>.cleanup.json (shown in the browser) and index-trigger\catalog-cleanup.txt;
# audit CSVs go to D:\edutek-system\index-status. Never indexes and never touches files.
$ccScript = Join-Path $Root 'catalog-cleanup-cli.php'
$ccDevIndex = Join-Path $Root 'data\device-index.json'
function CC-Tail($s, $n) { $s = [string]$s; if ($s.Length -gt $n) { $s.Substring($s.Length - $n) } else { $s } }
function CC-Note($m) {
  Write-Host ("{0}  catalog cleanup: {1}" -f (Get-Date -Format HH:mm:ss), $m)
  try { [IO.File]::AppendAllText((Join-Path $trig 'catalog-cleanup.txt'), ((Get-Date).ToString('s') + '  ' + $m + "`r`n"), $enc) } catch {}
}
function CC-Run([string[]]$a) {
  $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { $o = (& docker exec edupak-app php /tmp/edutek-catalog-cleanup.php @a 2>&1 | Out-String); $c = $LASTEXITCODE } finally { $ErrorActionPreference = $prev }
  $line = @($o -split "`n" | Where-Object { $_.Trim().StartsWith('{') }) | Select-Object -Last 1
  $j = $null; if ($line) { try { $j = $line | ConvertFrom-Json } catch {} }
  @{ code = $c; json = $j; raw = $o }
}
function CC-Backup([string]$tag) {
  New-Item -ItemType Directory -Force -Path $DbBackupDir | Out-Null
  $name = 'pre-cleanup-' + $tag + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql'; $dest = Join-Path $DbBackupDir $name
  & docker exec edupak-db sh -c ('mysqldump -uroot -p$MYSQL_ROOT_PASSWORD --single-transaction --default-character-set=utf8mb4 edupak > /tmp/' + $name + ' 2>/tmp/' + $name + '.err')
  if ($LASTEXITCODE -ne 0) { throw 'Database backup failed (mysqldump); nothing was deleted.' }
  & docker cp ('edupak-db:/tmp/' + $name) $dest | Out-Null; $cpOk = ($LASTEXITCODE -eq 0)
  & docker exec edupak-db rm -f ('/tmp/' + $name) ('/tmp/' + $name + '.err') | Out-Null
  if (-not $cpOk -or -not (Test-Path -LiteralPath $dest)) { throw 'Database backup could not be copied out of edupak-db; nothing was deleted.' }
  $fs = [IO.File]::OpenRead($dest); try { $n = [Math]::Min(400, $fs.Length); [void]$fs.Seek(-$n, 'End'); $buf = New-Object byte[] $n; [void]$fs.Read($buf, 0, $n) } finally { $fs.Close() }
  if (-not $enc.GetString($buf).Contains('-- Dump completed')) { throw "Database backup looks incomplete ($dest); nothing was deleted." }
  $dest
}
function Invoke-CatalogCleanup([string[]]$names) {
  $res = [ordered]@{ requests = $names; started = (Get-Date).ToString('s'); state = ''; library_files = 0; expect_files = 0; checked = 0; stale = 0; removed = 0; audit = ''; db_backup = ''; message = '' }
  try {
    try { $di = [IO.File]::ReadAllText($ccDevIndex, $enc) | ConvertFrom-Json; $res.expect_files = @($di.items | Where-Object { $_.lib -eq 'production' -and ([string]$_.path -match '\.(pdf|mp4)$') }).Count } catch {}
    if ($res.expect_files -lt 30000) { throw "The device list ($ccDevIndex) has $($res.expect_files) library files, fewer than 30,000, so the mount cannot be cross-checked. Nothing was deleted." }
    if (-not (Test-Path -LiteralPath $ccScript -PathType Leaf)) { throw "$ccScript not found. Nothing was deleted." }
    & docker cp $ccScript edupak-app:/tmp/edutek-catalog-cleanup.php | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy the cleanup script into edupak-app. Nothing was deleted.' }
    try {
      $a = @('--min-files=30000', ('--expect-files=' + $res.expect_files), '--max-delete=25', '--max-fraction=0.005', ('--tag=' + $names[0]))
      $r1 = CC-Run $a
      if (-not $r1.json) { throw ('The report pass gave no result (exit ' + $r1.code + '): ' + (CC-Tail $r1.raw 800).Trim()) }
      $res.library_files = [int]$r1.json.library_files; $res.checked = [int]$r1.json.checked; $res.stale = [int]$r1.json.stale; $res.audit = [string]$r1.json.audit
      if ($r1.json.state -ne 'report') { $res.state = $(if ($r1.json.state -eq 'held') { 'held' } else { 'error' }); $res.message = [string]$r1.json.message }
      elseif ($res.stale -eq 0) { $res.state = 'done'; $res.message = '0 stale rows removed.' }
      else {
        $res.db_backup = CC-Backup $names[0]
        $r2 = CC-Run ($a + @('--execute', ('--expect-stale=' + $res.stale)))
        if (-not $r2.json) { throw ('The cleanup pass gave no result (exit ' + $r2.code + '): ' + (CC-Tail $r2.raw 800).Trim()) }
        $res.state = $(if ($r2.json.state -eq 'done') { 'done' } elseif ($r2.json.state -eq 'held') { 'held' } else { 'error' })
        $res.removed = [int]$r2.json.removed; $res.message = [string]$r2.json.message; if ($r2.json.audit) { $res.audit = [string]$r2.json.audit }
      }
    } finally { & docker exec edupak-app rm -f /tmp/edutek-catalog-cleanup.php 2>&1 | Out-Null }
  } catch { if ($res.state -ne 'done') { $res.state = $(if ($_.Exception.Message -match 'Nothing was deleted|nothing was deleted') { 'held' } else { 'error' }) }; $res.message = $_.Exception.Message }
  $res.finished = (Get-Date).ToString('s')
  foreach ($nm in $names) { $tmp = Join-Path $trig ($nm + '.cleanup.tmp'); [IO.File]::WriteAllText($tmp, ($res | ConvertTo-Json -Compress), $enc); Move-Item $tmp (Join-Path $trig ($nm + '.cleanup.json')) -Force }
  CC-Note ("{0} after {1}: {2} (library files {3}, rows checked {4}, stale {5}, removed {6}{7}{8})" -f $res.state.ToUpper(), ($names -join ', '), $res.message, $res.library_files, $res.checked, $res.stale, $res.removed, $(if ($res.audit) { ', audit ' + $res.audit } else { '' }), $(if ($res.db_backup) { ', backup ' + $res.db_backup } else { '' }))
}
function Write-Result($name, $obj) {
'@))
  ,@((NL @'
          Write-Host ("{0}  {1}  exit {2}" -f (Get-Date -Format HH:mm:ss), $base, $code)
        }

'@), (NL @'
          Write-Host ("{0}  {1}  exit {2}" -f (Get-Date -Format HH:mm:ss), $base, $code)
        }
        $ccNames = @($valid | ForEach-Object { $_.Name -replace '\.request\.json\..*$', '' -replace '\.request\.json$', '' } | Where-Object { $_ -cmatch '^(publish-[a-f0-9]{8}|undo-[a-f0-9]{8}-\d{6})$' })
        if ($code -eq 0 -and -not $DryRun -and -not $full -and $ccNames.Count -gt 0) { try { Invoke-CatalogCleanup $ccNames } catch { CC-Note ('ERROR: ' + $_.Exception.Message) } }

'@))
)
$repH = @(
  ,@((NL @'
label: 'Bulk import content (sandbox)',
          href: '/bulk-import-sandbox.php',
'@), (NL @'
label: 'Bulk import content',
          href: 'http://127.0.0.1:8092/bulk-import-guided.php',
'@))
)
Build-Pairs $guided $files[$guided].tmp $files[$guided].in $files[$guided].out $repG
Build-Pairs $und $files[$und].tmp $files[$und].in $files[$und].out $repU
Build-Pairs $pq $files[$pq].tmp $files[$pq].in $files[$pq].out $repQ
Build-Pairs $clr $files[$clr].tmp $files[$clr].in $files[$clr].out $repC
Build-Pairs $ix $files[$ix].tmp $files[$ix].in $files[$ix].out $repX
Build-Whole $null $files[$cli].tmp '' $files[$cli].out (NL @'
<?php
/* Edutek importer patch 4b: catalog cleanup after a publish or undo (CLI only, outside the main app's code).
   Run-ImportIndexer.ps1 copies this file to /tmp in edupak-app and runs it there after a successful partial index.
   Same rule as api/local-catalog-cleanup-start.php: a content_meta row is stale when its file no longer exists on disk.
   Never touches files, never indexes. Default: report only. --execute deletes, but only when the guards pass:
     - the library mount is healthy: CONTENT_PATH is a directory with at least --min-files PDF/MP4 files and at least 95% of --expect-files;
     - stale rows <= --max-delete and <= --max-fraction of the rows checked;
     - with --expect-stale=N, the stale count found now must still be N (the count the report pass saw).
   Prints one JSON line. Audit CSV (same columns as the app's cleanup report) goes to INDEX_STATUS_DIR. Exit 0 = ok/held, 1 = error. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$opt = getopt('', ['execute', 'min-files:', 'expect-files:', 'max-delete:', 'max-fraction:', 'expect-stale:', 'tag:', 'app:']);
$app = rtrim((string)($opt['app'] ?? '/var/www/html'), '/');
function cc_out(array $o, int $code): void { echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; exit($code); }
try {
  require_once $app . '/includes/config.php';
  require_once $app . '/includes/auth.php';
} catch (Throwable $e) { cc_out(['state' => 'error', 'message' => 'Could not load the app configuration: ' . $e->getMessage()], 1); }
$execute = isset($opt['execute']);
$minFiles = (int)($opt['min-files'] ?? 30000); $expectFiles = (int)($opt['expect-files'] ?? 0);
$maxDelete = (int)($opt['max-delete'] ?? 25); $maxFraction = (float)($opt['max-fraction'] ?? 0.005);
$expectStale = isset($opt['expect-stale']) ? (int)$opt['expect-stale'] : null;
$tag = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($opt['tag'] ?? '')) ?? '';
$contentRoot = rtrim(str_replace('\\', '/', (string)CONTENT_PATH), '/');
$statusDir = rtrim((string)(getenv('INDEX_STATUS_DIR') ?: '/system/index-status'), '/');
$res = ['state' => '', 'mode' => $execute ? 'execute' : 'report', 'tag' => $tag, 'content_root' => $contentRoot, 'library_files' => 0, 'expect_files' => $expectFiles,
  'checked' => 0, 'stale' => 0, 'removed' => 0, 'unresolved' => 0, 'errors' => 0, 'audit' => '', 'message' => '', 'stale_paths' => []];
// 1. mount health
if ($contentRoot === '' || !is_dir($contentRoot)) { $res['state'] = 'held'; $res['message'] = "The library folder $contentRoot is not available in edupak-app. Nothing was deleted."; cc_out($res, 0); }
$n = 0;
try {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($contentRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
  foreach ($it as $f) { if ($f->isFile()) { $e = strtolower($f->getExtension()); if ($e === 'pdf' || $e === 'mp4') $n++; } }
} catch (Throwable $e) { $res['state'] = 'held'; $res['message'] = 'Could not list the library: ' . $e->getMessage() . ' Nothing was deleted.'; cc_out($res, 0); }
$res['library_files'] = $n;
if ($n < $minFiles || ($expectFiles > 0 && $n < (int)floor($expectFiles * 0.95))) {
  $res['state'] = 'held'; $res['message'] = "The library mount looks incomplete ($n PDF/MP4 files; need at least $minFiles" . ($expectFiles > 0 ? " and 95% of the $expectFiles in the device list" : '') . '). Nothing was deleted.'; cc_out($res, 0);
}
// 2. find stale rows (same path rule as the app)
function cc_abs(string $webPath, string $contentRoot, string $app): ?string {
  $webPath = str_replace('\\', '/', ltrim($webPath, '/')); $prefix = basename($contentRoot) . '/';
  if ($prefix !== '/' && strpos($webPath, $prefix) === 0) return $contentRoot . '/' . substr($webPath, strlen($prefix));
  $ht = rtrim(str_replace('\\', '/', (string)realpath($app)), '/');
  if (strpos($webPath, 'videos/') === 0 && $ht !== '') return $ht . '/' . $webPath;
  return null;
}
function cc_csv(string $v): string { return ($v !== '' && preg_match('/^[=+\-@]/', $v)) ? "'" . $v : $v; }
$lock = @fopen($statusDir . '/local-catalog-cleanup.lock', 'c');
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) { $res['state'] = 'held'; $res['message'] = 'Another catalog cleanup is running. Nothing was deleted.'; cc_out($res, 0); }
try {
  $pdo = getDbConnection();
  $sel = $pdo->prepare('SELECT content_id, file_path, content_type, category, subcategory, title, thumbnail_path FROM content_meta WHERE content_id > :last ORDER BY content_id LIMIT :n');
  $sel->bindValue(':n', 500, PDO::PARAM_INT); $last = ''; $stale = [];
  while (true) {
    $sel->bindValue(':last', $last, PDO::PARAM_STR); $sel->execute(); $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;
    foreach ($rows as $row) {
      $cid = (string)($row['content_id'] ?? ''); $last = $cid; $res['checked']++;
      if ($cid === '') { $res['errors']++; continue; }
      $abs = cc_abs((string)($row['file_path'] ?? $cid), $contentRoot, $app);
      if ($abs === null) { $res['unresolved']++; continue; }
      if (!is_file($abs)) $stale[] = $row + ['_abs' => $abs];
    }
    if (count($rows) < 500) break;
  }
  $res['stale'] = count($stale); $res['stale_paths'] = array_slice(array_map(fn($r) => (string)$r['file_path'], $stale), 0, 50);
  $runId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
  $held = $res['stale'] > $maxDelete || ($res['checked'] > 0 && $res['stale'] > $res['checked'] * $maxFraction);
  $mismatch = $execute && $expectStale !== null && $res['stale'] !== $expectStale;
  $csvName = ($execute && !$held && !$mismatch && $stale) ? 'local-catalog-cleanup-' . $runId . '.csv' : ($stale ? 'catalog-cleanup-' . ($held ? 'held' : 'report') . '-' . $runId . '.csv' : '');
  $h = null;
  if ($csvName !== '') { $h = @fopen($statusDir . '/' . $csvName, 'wb'); if (!is_resource($h)) throw new RuntimeException('Could not write the audit CSV in ' . $statusDir); fputcsv($h, ['timestamp', 'content_id', 'file_path', 'content_type', 'category', 'subcategory', 'title', 'thumbnail_path', 'message']); $res['audit'] = $csvName; }
  $row2csv = function (array $r, string $msg) use (&$h): void { fputcsv($h, array_map('cc_csv', [gmdate(DATE_ATOM), (string)$r['content_id'], (string)$r['file_path'], (string)($r['content_type'] ?? ''), (string)($r['category'] ?? ''), (string)($r['subcategory'] ?? ''), (string)($r['title'] ?? ''), (string)($r['thumbnail_path'] ?? ''), $msg])); };
  if ($held) {
    foreach ($stale as $r) $row2csv($r, 'Held: file missing, not deleted (more stale rows than the safety limit).');
    $res['state'] = 'held'; $res['message'] = $res['stale'] . ' catalog rows point to missing files, more than the safety limit (' . $maxDelete . ' rows or ' . ($maxFraction * 100) . '% of ' . $res['checked'] . '). Nothing was deleted; check the list and run the cleanup by hand if it is right.';
  } elseif ($mismatch) {
    foreach ($stale as $r) $row2csv($r, 'Not deleted: the stale count changed since the report pass.');
    $res['state'] = 'held'; $res['message'] = 'The number of stale rows changed between the report pass (' . $expectStale . ') and now (' . $res['stale'] . '). Nothing was deleted.';
  } elseif (!$execute) {
    foreach ($stale as $r) $row2csv($r, 'Report only: file missing.');
    $res['state'] = 'report'; $res['message'] = $res['stale'] . ' stale row(s) found (report only).';
  } else {
    $del = $pdo->prepare('DELETE FROM content_meta WHERE content_id = :id');
    foreach ($stale as $r) {
      if (is_file($r['_abs'])) { $row2csv($r, 'Kept: the file exists again.'); continue; }
      try { $del->execute([':id' => (string)$r['content_id']]); if ($del->rowCount() > 0) { $res['removed']++; $row2csv($r, 'Content file no longer exists on disk.'); } }
      catch (PDOException $e) { $res['errors']++; $row2csv($r, 'Could not remove stale catalog record: ' . $e->getMessage()); }
    }
    $res['state'] = 'done'; $res['message'] = $res['removed'] . ' stale catalog row(s) removed.';
  }
  if (is_resource($h)) fclose($h);
} catch (Throwable $e) { $res['state'] = 'error'; $res['message'] = 'Catalog cleanup failed: ' . $e->getMessage(); cc_out($res, 1); }
finally { if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); } }
cc_out($res, 0);

'@)
Build-Pairs $hot $files[$hot].tmp $files[$hot].in $files[$hot].out $repH
Build-Whole $sbx $files[$sbx].tmp $files[$sbx].in $files[$sbx].out (NL @'
<?php
/* Edutek: the old sandbox importer (maintenance key) was retired in patch 4b. Old bookmarks go straight to the bulk importer, which listens on 127.0.0.1:8092 only. */
header('Cache-Control: no-store');
header('Location: http://127.0.0.1:8092/bulk-import-guided.php', true, 302);
exit;

'@)
foreach ($k in $files.Keys) { $f = $files[$k].tmp; if ($f -notlike '*.ps1') { continue }; $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$null, [ref]$e); if ($e.Count) { throw "PowerShell parse errors in $f : $e" } }
'PowerShell parse checks passed (Undo-Publish, Run-PublishQueue, Clear-ReplacedArchive, Run-ImportIndexer).'
function Clean-Tmp { foreach ($k in $files.Keys) { Remove-Item -LiteralPath $files[$k].tmp -Force -ErrorAction SilentlyContinue } }
try {
  $c = @(D ps --filter 'publish=8092' --format '{{.Names}}')
  if ($c.Count -ne 1 -or $c[0] -ne 'edutek-bulk-fresh-v05-web-1') { throw "Unexpected importer container(s): $($c -join ', ')" }
  $a = @(D ps --filter 'name=^edupak-app$' --format '{{.Names}}')
  if ($a.Count -ne 1 -or $a[0] -ne 'edupak-app') { throw 'The edupak-app container is not running.' }
  D exec $c[0] php -l /sandbox/_p4b-guided.php
  if ($LASTEXITCODE -ne 0) { throw 'php -l failed for the patched guided page.' }
  AppLint $files[$sbx].tmp 'bulk-import-sandbox.php (redirect)'
  AppLint $files[$cli].tmp 'catalog-cleanup-cli.php'
} catch { Clean-Tmp; throw "$($_.Exception.Message) Live files unchanged." }
'php -l passed: guided page (importer container), redirect and catalog-cleanup-cli.php (edupak-app).'
foreach ($tn in @('Edutek Import Publisher', 'Edutek Import Partial Indexer')) {
  try { $t = Get-ScheduledTask -TaskName $tn -ErrorAction Stop; "Task '$tn': $($t.State)" } catch { "WARNING: task '$tn' not found." }
}
$pend = @(Pending)
"Requests waiting (publish-queue + index-trigger): $($pend.Count)"
if ($pend.Count -gt 0) { 'WARNING: wait until no request is waiting before -Apply: ' + (($pend | ForEach-Object { $_.Name }) -join ', ') }

if (-not $Apply) { Clean-Tmp; ''; 'ALL CHECKS PASSED. Nothing live was changed (temporary built copies removed). Re-run with -Apply to install.'; return }
if ($pend.Count -gt 0) { Clean-Tmp; throw 'A publish, undo or index request is waiting. Let the tasks finish it first; nothing was changed.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $bak = Join-Path $BackupRoot $stamp
New-Item -ItemType Directory -Force -Path $bak | Out-Null
foreach ($k in $files.Keys) { if (-not $files[$k].in) { continue }; $d = Join-Path $bak ([IO.Path]::GetFileName($k)); Copy-Item -LiteralPath $k -Destination $d; if ((HashOf $d) -ne $files[$k].in) { Clean-Tmp; throw "Backup mismatch: $k. Nothing live was changed." } }
"Backups OK in $bak"
foreach ($k in @($cli, $und, $clr, $pq, $ix, $sbx, $hot, $guided)) { Move-Item -LiteralPath $files[$k].tmp -Destination $k -Force }
foreach ($k in $files.Keys) { if ((HashOf $k) -ne $files[$k].out) { throw "Live hash mismatch: $k. Run: .\Install-Patch4b.ps1 -Rollback '$bak'" } }
D exec $c[0] php -l /sandbox/public/bulk-import-guided.php
if ($LASTEXITCODE -ne 0) { throw "Live php -l failed for the guided page. Run: .\Install-Patch4b.ps1 -Rollback '$bak'" }
D exec edupak-app php -l /var/www/html/bulk-import-sandbox.php
if ($LASTEXITCODE -ne 0) { throw "Live php -l failed for bulk-import-sandbox.php. Run: .\Install-Patch4b.ps1 -Rollback '$bak'" }
''; "INSTALLED. Backups: $bak"
"Rollback if needed: .\Install-Patch4b.ps1 -Rollback '$bak'"
'Next: restart the "Edutek Import Publisher" and "Edutek Import Partial Indexer" tasks when no requests are waiting (see patch-4b.md), then reload the importer page and hard-reload the Edutek Home page (Ctrl+F5).'
