# Install-Patch4c.ps1 - Edutek importer patch 4c: shorter duplicate/publish texts, "Published imports" list with
# "Undo this publish..." links, undo button at the top of the published panel, plain-word states in the history.
# Changes only app\public\bulk-import-guided.php (hash-checked before and after). No task or container restart is needed.
# Run in Windows PowerShell on the Beelink: first without -Apply (checks only), then with -Apply.
# Rollback: run this script with -Rollback <backup folder printed by -Apply>.
param(
  [switch]$Apply,
  [string]$Rollback = '',
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$BackupRoot = 'D:\edutek-system\backups\importer-ux',
  [string]$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$DockerContext = 'desktop-linux'
)
$ErrorActionPreference = 'Stop'
function NL([string]$s) { $s -replace "`r`n", "`n" }
function P([string]$base, [string[]]$parts) { $p = $base; foreach ($x in $parts) { $p = [IO.Path]::Combine($p, $x) }; $p }
function HashOf($path) { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash }
function D { & $Docker --context $DockerContext @args }
$guided = P $Root 'app','public','bulk-import-guided.php'; $tmp = P $Root 'app','_p4c-guided.php'
$IN = '74B65343DC49123BAEE25E169B0F74129E5FC8A49DE657DAC7FE4887CA8027BF'; $OUT = '71F98CA0189B8446D5C822632287313A8A5C1887305D092AB30E9CB931BDE67E'
# patch 4b scripts the page talks to (read-only check: the page needs their 4b versions)
$needs = [ordered]@{ (P $Root 'Run-PublishQueue.ps1') = '2BAC24FA18822547DF5B5DE8A237B348512794D5D5B242F23059A139F18098B0'; (P $Root 'Undo-Publish.ps1') = 'DECF55F3339D87F0827A8108F438BB9E8D23DDA5181D85F1786F8E40C714F0FE'; (P $Root 'Run-ImportIndexer.ps1') = '6C743E65601F8E4A70F0E45D7FF11A5911C6D5732D2D97249BFE3120742F79DC' }

if ($Rollback) {
  $b = Join-Path $Rollback 'bulk-import-guided.php'
  if (-not (Test-Path -LiteralPath $b) -or (HashOf $b) -ne $IN) { throw "Backup $b is missing or is not the patch-4b page ($IN). Nothing was rolled back." }
  $h = HashOf $guided; if ($h -ne $OUT -and $h -ne $IN) { throw "$guided was changed after patch 4c (SHA256 $h). Nothing was rolled back." }
  Copy-Item -LiteralPath $b -Destination ($guided + '.p4crb') -Force; Move-Item -LiteralPath ($guided + '.p4crb') -Destination $guided -Force
  if ((HashOf $guided) -ne $IN) { throw "Rollback hash mismatch: $guided" }
  'ROLLED BACK to the patch-4b page. Reload the importer page. No restart needed.'; return
}
$h = HashOf $guided
if ($h -eq $OUT) { 'Patch 4c is already installed. Nothing to do.'; return }
if ($h -ne $IN) { throw "$guided has SHA256 $h, not the patch-4b page ($IN). Stop; nothing was changed." }
foreach ($k in $needs.Keys) { if (-not (Test-Path -LiteralPath $k) -or (HashOf $k) -ne $needs[$k]) { throw "$k is not the patch-4b version. Install patch 4b first. Nothing was changed." } }
'The guided page is the patch-4b version; Run-PublishQueue, Undo-Publish and Run-ImportIndexer are the patch-4b versions.'
$repG = @(
  ,@((NL @'
function pq_can_request(array $pi): bool {
'@), (NL @'
/* Patch 4c: plain-word import states and the "Published imports" list (links only; undo itself keeps all 4b checks). */
function ux_state(array $e): string {
 $id=(string)($e['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))return 'Unknown';
 $st=pq_read(PS_DIR.'/'.$id.'.json');$s=(string)($st['state']??'');$u=pq_read(PS_DIR.'/'.$id.'.undo.json');
 if(is_file(PQ_DIR.'/'.$id.'.undo.request.json')||($u['state']??'')==='undoing')return 'Undo in progress';
 $m=['published'=>'Published','undone'=>'Undone','reversed'=>'Undone','failed'=>'Publish failed','checking'=>'Publishing','publishing'=>'Publishing','attention'=>'Publish needs attention'];
 if(isset($m[$s]))return $m[$s];
 if(is_file(PQ_DIR.'/'.$id.'.request.json'))return 'Publish requested';
 $b=['draft'=>'Draft (not staged)','approved'=>'Reviewed (not staged)','importing'=>'Staging','interrupted'=>'Staging interrupted','complete'=>'Staged (not published)','attention'=>'Needs attention','reversing'=>'Clearing staged copies','reversed'=>'Staged copies cleared'];
 $o=$b[(string)($e['state']??'')]??(string)($e['state']??'');return $s==='blocked'?'Staged (publish blocked)':$o;
}
function ux_when(string $t): string {return preg_match('/^(\d{4}-\d\d-\d\d)T(\d\d:\d\d)/',$t,$m)?$m[1].' '.$m[2]:$t;}
function ux_published(): string {
 $rows=[];foreach(glob(PS_DIR.'/*.json')?:[] as $f){if(!preg_match('/\/([a-f0-9]{32})\.json$/D',$f,$m))continue;$st=pq_read($f);if(!$st)continue;
  $s=ux_state(['id'=>$m[1],'state'=>'complete']);if(!in_array($s,['Published','Undone','Undo in progress','Publish failed','Publish needs attention'],true))continue;
  $rows[]=['id'=>$m[1],'st'=>$st,'s'=>$s,'when'=>(string)($st['finished']??$st['started']??'')];}
 usort($rows,fn($a,$b)=>strcmp($b['when'],$a['when']));$rows=array_slice($rows,0,10);
 $o='<section class="published" aria-labelledby="published-imports"><h2 id="published-imports">Published imports</h2>';
 if(!$rows)return $o.'<p class="muted">Nothing has been published from this page yet.</p></section>';
 $o.='<ul class="pub-list">';
 foreach($rows as $r){$id=$r['id'];$i8=substr($id,0,8);$st=$r['st'];$n=(int)($st['learning']??0);$rp=(int)($st['replaced_learning']??0);$w=ux_when($r['when']);
  $o.='<li><strong>'.h($r['s']).'</strong> &middot; '.h($w).'<br><span class="muted">Import '.h($i8).' &middot; '.$n.' file'.($n===1?'':'s').($rp>0?', '.$rp.' replaced':'').'</span><br>';
  $has=is_file(batchdir($id).'/batch.json');
  if($r['s']==='Published'&&$has)$o.='<a class="leave-page btn-link" href="?id='.h($id).'#undo" aria-label="Undo this publish: import '.h($i8).', published '.h($w).'">Undo this publish&hellip;</a>';
  elseif($r['s']==='Published')$o.='<span class="muted">Not started from this page: undo with Undo-Publish.ps1 -BatchId '.h($i8).' on the Beelink.</span>';
  elseif($has)$o.='<a class="leave-page" href="?id='.h($id).'#publish" aria-label="Open import '.h($i8).' ('.h($r['s']).')">Open</a>';
  $o.='</li>';}
 return $o.'</ul></section>';
}
function pq_can_request(array $pi): bool {
'@))
  ,@((NL @'
.history a{display:block;margin:12px 0;color:#17578f}
'@), (NL @'
.history a{display:block;margin:12px 0;color:#17578f}.published{margin:18px 0}.pub-list{list-style:none;padding:0;margin:0}.pub-list li{margin:0 0 12px;line-height:1.4}.btn-link{display:inline-block;margin-top:4px;padding:6px 10px;border:1px solid #155ea6;border-radius:6px;color:#155ea6;font-weight:600;text-decoration:none}.btn-link:focus,.btn-link:hover{background:#155ea6;color:#fff;outline:3px solid #f0b429;outline-offset:2px}.undo-box button:focus{outline:3px solid #f0b429;outline-offset:2px}
'@))
  ,@((NL @'
the library changes only when the batch is published.</p></header>
'@), (NL @'
the library changes only when the batch is published.</p><p><a href="#published-imports">Published imports and undo</a></p></header>
'@))
  ,@((NL @'
<p class="muted">Scans the selected folder. Nothing is copied.</p>
'@), (NL @'
<p class="muted">Scans the selected folder. Nothing is copied.</p><?=ux_published()?>
'@))
  ,@((NL @'
<?php foreach($history as $entry):?><a class="leave-page" href="?id=<?=h($entry['id'])?>"><?=h($entry['created'])?><br><?=h($entry['state'])?>
'@), (NL @'
<?php foreach($history as $entry):$hs=ux_state($entry);$hn=count(learning($entry['rows']));?><a class="leave-page" href="?id=<?=h($entry['id'])?>" aria-label="<?=h('Import '.substr((string)$entry['id'],0,8).', '.ux_when((string)$entry['created']).': '.$hs.', '.$hn.' materials')?>"><?=h(ux_when((string)$entry['created']))?><br><strong><?=h($hs)?></strong>
'@))
  ,@((NL @'
<p class="muted">Saved imports from this page are listed above. Imports made with older importer pages are not shown here.</p></aside>
'@), (NL @'
<p class="muted">Imports made with older importer pages are not listed.</p></aside>
'@))
  ,@((NL @'
<div class="warning" id="dup-help" hidden><h3>Possible duplicates</h3><p>These items have the same title, type and size as other items in this import, or a name similar to a file already in the Edutek library. Compare each one with the locations listed on its card. Click the winner (or winners) in each match below. Click only the original: nothing is done and the source copy is rejected. Click only the source copy: it replaces the library file when publishing (the old file is moved to the replaced-files archive and can be restored with Undo-Publish.ps1); a copy staged in an earlier import is not replaced, so that source copy is skipped. Click both: both are kept. Nothing is deleted.</p></div>
'@), (NL @'
<div class="warning" id="dup-help" hidden><h3>Possible duplicates</h3><p>These files look like ones already in the library, in an earlier import, or elsewhere in this import. Choose the card(s) to keep (Tab to a card, then Enter or Space); each card&rsquo;s Outcome line shows the result.</p><ul><li>Only the existing copy: the new copy is not added.</li><li>Only the new copy: it replaces the library file; the old file is archived and undoing the publish restores it. (Copies staged in an earlier import can&rsquo;t be replaced, so the new copy is skipped.)</li><li>Both: both are kept.</li></ul></div>
'@))
  ,@((NL @'
<?php if($dupSets):?><div class="notice"><?=count($dupSets)?> matches: <?=$cd?> already on this device, <?=$cs?> duplicated within the source. A match can fall in more than one group. Device list built: <?=h($batch['device_index_built']??'')?:'never - run Build-DeviceIndex.ps1'?></div><?php endif;?>
'@), (NL @'
<?php if($dupSets):?><div class="notice"><?=count($dupSets)?> matches (<?=$cd?> with a library file, <?=$cs?> within this import). Device list built: <?=h($batch['device_index_built']??'')?:'never - run Build-DeviceIndex.ps1'?></div><?php endif;?>
'@))
  ,@((NL @'
data-o-none="<?=h($rc['dev']?'Stays in the library at '.$rcLp.', unless you choose only a source copy':'Stays staged in that earlier import')?>" data-o-win="<?=h($rc['dev']?'Stays in the library at '.$rcLp:'Stays staged in that earlier import')?>" data-o-replaced="<?=h($rc['dev']?'Moved to D:\\edutek-system\\replaced-archive when publishing (Undo-Publish.ps1 can restore it); the chosen source copy takes its place at '.$rcLp:'Stays staged in that earlier import (only library files can be replaced)')?>">
'@), (NL @'
data-o-none="<?=h($rc['dev']?'Stays at '.$rcLp.' unless you keep only the new copy':'Stays staged in that earlier import')?>" data-o-win="<?=h($rc['dev']?'Stays at '.$rcLp:'Stays staged in that earlier import')?>" data-o-replaced="<?=h($rc['dev']?'Archived when publishing (undo restores it); the new copy takes its place at '.$rcLp:'Stays staged in that earlier import (it cannot be replaced)')?>">
'@))
  ,@((NL @'
<span class="dup-outcome"><?=h($rc['dev']?'Stays in the library at '.$rcLp.', unless you choose only a source copy':'Stays staged in that earlier import')?></span></p><p class="muted"><?=($x['kind']??'')==='device'?'Choose only the source copy to replace this library file (the old file is archived and can be restored with Undo-Publish.ps1).':'This copy stays where it is whatever you choose.'?></p></div>
'@), (NL @'
<span class="dup-outcome"><?=h($rc['dev']?'Stays at '.$rcLp.' unless you keep only the new copy':'Stays staged in that earlier import')?></span></p></div>
'@))
  ,@((NL @'
data-o-skip="Skipped: only files already in the Edutek library can be replaced"
'@), (NL @'
data-o-skip="Skipped: only library files can be replaced"
'@))
  ,@((NL @'
<p class="cmp-legend">Each row is marked SAME, SIMILAR or DIFFERENT compared with <?=h($rcVs)?><?=$exs?'':' (copy 1 is compared with the others)'?>. Locations are compared between source copies only.</p>
'@), (NL @'
<p class="cmp-legend">Marks compare each row with <?=h($rcVs)?><?=$exs?'':' (copy 1 with the others)'?>; locations are compared between source copies only.</p>
'@))
  ,@((NL @'
<p class="muted">Click the copy (or copies) you want to keep.</p><p class="dup-status notice">Not decided yet. Click the winner or winners.</p>
'@), (NL @'
<p class="dup-status notice" aria-live="polite">Not decided yet: choose the card(s) to keep.</p>
'@))
  ,@((NL @'
let txt='Click to choose as a winner',cls='';
'@), (NL @'
let txt='Not chosen (Enter or Space to keep)',cls='';
'@))
  ,@((NL @'
let msg='Not decided yet. Click the winner or winners.';
'@), (NL @'
let msg='Not decided yet: choose the card(s) to keep.';
'@))
  ,@((NL @'
msg='The original is kept and nothing is done. The source copy is rejected.';
'@), (NL @'
msg='Existing copy kept; the new copy is not added.';
'@))
  ,@((NL @'
msg='The original is kept and '+srcW+' source cop'+(srcW>1?'ies are':'y is')+' added as extra cop'+(srcW>1?'ies':'y')+'. The others are rejected.';
'@), (NL @'
msg='Existing copy kept; '+srcW+' new cop'+(srcW>1?'ies are':'y is')+' added as well. Any others are not added.';
'@))
  ,@((NL @'
msg=p.dataset.libPath?'The source copy replaces the library file when publishing. The old library file is moved to the replaced-files archive and can be restored with Undo-Publish.ps1.':'Only files already in the Edutek library can be replaced: the earlier staged copy stays and this source copy is skipped.';
'@), (NL @'
msg=p.dataset.libPath?'The new copy replaces the library file when publishing; the old file is archived and undoing the publish restores it.':'A copy staged in an earlier import can\'t be replaced, so this new copy is skipped.';
'@))
  ,@((NL @'
else msg=srcW+' cop'+(srcW>1?'ies':'y')+' will be added. The others are rejected.'}
'@), (NL @'
else msg=srcW+' cop'+(srcW>1?'ies':'y')+' will be added; any others are not added.'}
'@))
  ,@((NL @'
<h3>Possible duplicate</h3><p><?=!empty($r['dup_lib'])?'A file in the Edutek library has a similar name (the same letters and digits).':'Another file has the same title, type and size.'?> This does not prove they are identical. When publishing, the contents are compared and an identical copy is never published. Compare the locations below before deciding.</p>
'@), (NL @'
<h3>Possible duplicate</h3><p><?=!empty($r['dup_lib'])?'A library file has a similar name (same letters and digits).':'Another file has the same title, type and size.'?> Publishing compares the contents and stops if they are identical.</p>
'@))
  ,@((NL @'
<p class="muted">Or unselect this material above. No originals will be removed.</p>
'@), (NL @'
<p class="muted">Or untick this material. Nothing is deleted.</p>
'@))
  ,@((NL @'
<?php $repl=array_filter($primary,fn($rr)=>($rr['dup_decision']??'')==='replace');
'@), (NL @'
<?php $repl=array_filter($primary,fn($rr)=>($rr['dup_decision']??'')==='replace'&&empty($rr['replace']));$repOk=array_filter($primary,fn($rr)=>!empty($rr['include'])&&!empty($rr['replace']));
'@))
  ,@((NL @'
<?php if($repl):?><div class="warning"><h3>Replace choices recorded: these items are skipped, not replaced</h3><ul><?php foreach($repl as $rr):?><li><?=h($rr['title'])?> - nothing will be copied for this item in this import.</li><?php endforeach;?></ul></div><?php endif;?>
'@), (NL @'
<?php if($repOk):?><p class="notice"><?=count($repOk)?> item<?=count($repOk)>1?'s':''?> will replace a library file when published (the old file is archived; undo restores it).</p><?php endif;?><?php if($repl):?><div class="warning"><h3>Skipped: these can&rsquo;t replace anything</h3><ul><?php foreach($repl as $rr):?><li><?=h($rr['title'])?> (no library file to replace; nothing is copied)</li><?php endforeach;?></ul></div><?php endif;?>
'@))
  ,@((NL @'
<p>Your original files stay unchanged. No existing files will be overwritten. You can inspect the staged copies and reverse this staging later.</p>
'@), (NL @'
<p>Your source files stay unchanged. You can clear the staged copies later.</p>
'@))
  ,@((NL @'
<p>The publisher on the Beelink copies these '.$files.' verified staged files ('.$mib.' MiB) into D:\xampp\htdocs\Edutek\videos, saves a publish record and a database backup, and then queues indexing for only these learning files. It re-checks every file first, overwrites nothing except the replacements you chose, and stops on same-size library files you did not confirm as duplicates.</p>
'@), (NL @'
<p>The publisher re-checks each staged file, backs up the database, copies the files into the Edutek library (D:\xampp\htdocs\Edutek\videos) and indexes only these files. Nothing is overwritten except the replacements you chose. You can undo the publish afterwards.</p>
'@))
  ,@((NL @'
<p class="notice">'.$rep.' file'.($rep>1?'s':'').' replace'.($rep>1?'':'s').' a library file. Each old file is first moved to D:\\edutek-system\\replaced-archive (restorable with Undo-Publish.ps1 until that archive is cleared). Publishing stops if a library file changed since this review or is identical to its replacement.</p>
'@), (NL @'
<p class="notice">'.$rep.' replacement'.($rep>1?'s':'').': each old file is archived first (undo restores it until the archive is cleared). Publishing stops if a library file changed since review or is identical to its replacement.</p>
'@))
  ,@((NL @'
archived and can be restored with Undo-Publish.ps1</label>
'@), (NL @'
archived and undoing the publish restores it</label>
'@))
  ,@((NL @'
'<p class="notice">'.(int)$st['replaced_learning'].' of them replaced library files. The old files are in D:\\edutek-system\\replaced-archive ('.h($st['archive_manifest']??'').'); Undo-Publish.ps1 can put them back until that archive is cleared.</p>':'');
'@), (NL @'
'<p class="notice">'.(int)$st['replaced_learning'].' of them replaced library files; the old files are archived ('.h($st['archive_manifest']??'').') and Undo puts them back until the archive is cleared.</p>':'');$o.=uq_panel($id,$csrf,$pi);
'@))
  ,@((NL @'
  return $o.uq_panel($id,$csrf,$pi).'<script>(()=>{document.querySelectorAll(".undo-form")
'@), (NL @'
  return $o.'<script>(()=>{document.querySelectorAll(".undo-form")
'@))
  ,@((NL @'
if(a&&b){const u=()=>{b.disabled=!a.checked;if(hi)hi.hidden=a.checked};a.addEventListener("change",u);u()}})();</script></div>';
'@), (NL @'
if(a&&b){const u=()=>{b.disabled=!a.checked;if(hi)hi.hidden=a.checked};a.addEventListener("change",u);u()}if(location.hash==="#undo")window.addEventListener("load",()=>setTimeout(()=>{const x=document.getElementById("undo"),y=x&&x.querySelector("input[type=checkbox],button");if(y){x.scrollIntoView();y.focus()}},0))})();</script></div>';
'@))
  ,@((NL @'
return $o.'<p>Removes this import&rsquo;s new files from the Edutek library (kept in recovery) and puts back any library files it replaced. First see the plan (a dry run on the Beelink), then confirm it.</p>'.$planBtn('Show undo plan').'<p class="muted">On the Beelink you can also run Undo-Publish.ps1 -BatchId '.$i8.' (dry run first).</p></div>';
'@), (NL @'
return $o.'<p>Removes this import&rsquo;s new files from the library (kept in recovery) and puts back any files it replaced. You see the plan before anything changes.</p>'.$planBtn('Undo this publish&hellip;').'</div>';
'@))
  ,@((NL @'
'<p>Undo-Publish.ps1 reversed this publish'.(!empty($st['undone'])?' on '.h($st['undone']):'').'. New files were removed from the Edutek library and replaced files were put back. The publish record and archive manifest are kept.</p>'.'<p>This import is not published again. To start over, reverse the staged copies below and run a new import.</p></div>';
'@), (NL @'
'<p>Undone'.(!empty($st['undone'])?' on '.h(ux_when((string)$st['undone'])):'').': the new files were removed from the library (kept in recovery) and replaced files were put back.</p><p>This import can&rsquo;t be published again. To start over, clear its staged copies below and run a new import.</p></div>';
'@))
  ,@((NL @'
<p>This checks the entire batch: <?=count($batch['operations'])?> recorded file operations, including artwork. Unchanged verified copies move to recovery storage. Changed or uncertain files are kept and reported.</p><p>Your Desktop source and all earlier imports stay untouched. Empty staging folders remain. This does not undo anything already published to the Edutek library; for that use &ldquo;Undo this publish&rdquo; in Step 4 (or Undo-Publish.ps1 on the Beelink).</p>
'@), (NL @'
<p>Checks all <?=count($batch['operations'])?> recorded file operations (artwork included). Unchanged copies move to recovery storage; changed or uncertain files are kept and reported.</p><p>Your source, earlier imports and the Edutek library are not touched. To remove published files, use &ldquo;Undo this publish&rdquo;.</p>
'@))
  ,@((NL @'
<div class="panel"><h2><?=h($batch['state']==='complete'?'Materials staged for publishing':
'@), (NL @'
<?php $pi=pq_info($id);$pqFirst=$pi['undo_req']||in_array($pi['state'],['published','undone','reversed','failed'],true);if($pqFirst)echo pq_panel($batch,$id,$csrf,$pi);?><div class="panel"><h2><?=h($batch['state']==='complete'?($pqFirst?'Files in this import':'Materials staged for publishing'):
'@))
  ,@((NL @'
<?php $pi=pq_info($id);echo pq_panel($batch,$id,$csrf,$pi);?>
'@), (NL @'
<?php if(!$pqFirst)echo pq_panel($batch,$id,$csrf,$pi);?>
'@))
)
$bytes = [IO.File]::ReadAllBytes($guided); $enc = New-Object System.Text.UTF8Encoding($false); $t = $enc.GetString($bytes)
# Pairs are applied in order; each marker must occur exactly once at the moment it is applied.
$i = 0; foreach ($p in $repG) { $i++; $n = ([regex]::Matches($t, [regex]::Escape($p[0]))).Count; if ($n -ne 1) { throw "marker $i found $n times, expected 1. Nothing was changed." }; $t = $t.Replace($p[0], $p[1]) }
[IO.File]::WriteAllText($tmp, $t, $enc)
if ((HashOf $tmp) -ne $OUT) { Remove-Item -LiteralPath $tmp; throw 'The built page does not match the expected hash. Nothing was changed.' }
"Built OK: $tmp"
$c = @(D ps --filter 'publish=8092' --format '{{.Names}}')
if ($c.Count -ne 1 -or $c[0] -ne 'edutek-bulk-fresh-v05-web-1') { Remove-Item -LiteralPath $tmp; throw "Unexpected importer container(s): $($c -join ', '). Nothing was changed." }
D exec $c[0] php -l /sandbox/_p4c-guided.php
if ($LASTEXITCODE -ne 0) { Remove-Item -LiteralPath $tmp; throw 'php -l failed for the patched page. Nothing was changed.' }
$pend = @(Get-ChildItem -LiteralPath (P $Root 'data','publish-queue') -Filter '*.request.json' -File -ErrorAction SilentlyContinue)
"Publish/undo requests waiting: $($pend.Count) (the page swap does not affect them)"
if (-not $Apply) { Remove-Item -LiteralPath $tmp; ''; 'ALL CHECKS PASSED. Nothing live was changed. Re-run with -Apply to install.'; return }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $bak = Join-Path $BackupRoot $stamp
New-Item -ItemType Directory -Force -Path $bak | Out-Null
Copy-Item -LiteralPath $guided -Destination (Join-Path $bak 'bulk-import-guided.php')
if ((HashOf (Join-Path $bak 'bulk-import-guided.php')) -ne $IN) { Remove-Item -LiteralPath $tmp; throw 'Backup mismatch. Nothing was changed.' }
"Backup OK in $bak"
Move-Item -LiteralPath $tmp -Destination $guided -Force
if ((HashOf $guided) -ne $OUT) { throw "Live hash mismatch. Run: .\Install-Patch4c.ps1 -Rollback '$bak'" }
D exec $c[0] php -l /sandbox/public/bulk-import-guided.php
if ($LASTEXITCODE -ne 0) { throw "Live php -l failed. Run: .\Install-Patch4c.ps1 -Rollback '$bak'" }
''; "INSTALLED. Backup: $bak"
"Rollback if needed: .\Install-Patch4c.ps1 -Rollback '$bak'"
'Next: reload the importer page (no task or container restart needed).'
