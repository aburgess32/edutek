# Install-Patch2.ps1 - Edutek importer patch 2: browser approval -> production publishing.
# Run in Windows PowerShell on the Beelink as edutek1\edutek:  first without -Apply (checks only), then with -Apply.
# Changes: app\public\bulk-import-guided.php, Publish-Batch.ps1, Run-ImportIndexer.ps1; adds Run-PublishQueue.ps1.
# Every input is hash-checked; every output must match its expected hash; nothing live changes without -Apply.
param(
  [switch]$Apply,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$BackupRoot = 'D:\edutek-system\backups\importer-publish',
  [string]$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe',
  [string]$DockerContext = 'desktop-linux'
)
$ErrorActionPreference = 'Stop'
function NL([string]$s) { $s -replace "`r`n", "`n" }
function RootPath([string[]]$parts) { $p = $Root; foreach ($x in $parts) { $p = [IO.Path]::Combine($p, $x) }; $p }
function HashOf($path) { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash }
function Build($src, $tmp, [string[]]$inHashes, [string[]]$outHashes, $pairs) {
  $h = HashOf $src; $k = [array]::IndexOf($inHashes, $h)
  if ($k -lt 0) { throw "$src has unexpected SHA256 $h. It differs from the reviewed version. Stop." }
  $b = [IO.File]::ReadAllBytes($src); $bom = $b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF
  $enc = New-Object System.Text.UTF8Encoding($bom); $off = $(if ($bom) { 3 } else { 0 })
  $t = $enc.GetString($b, $off, $b.Length - $off)
  foreach ($p in $pairs) { $n = ([regex]::Matches($t, [regex]::Escape($p[0]))).Count; if ($n -ne $p[2]) { throw "$src : marker found $n times, expected $($p[2])." } }
  foreach ($p in $pairs) { $t = $t.Replace($p[0], $p[1]) }
  [IO.File]::WriteAllText($tmp, $t, $enc)
  if ((HashOf $tmp) -ne $outHashes[$k]) { Remove-Item -LiteralPath $tmp; throw "Built copy of $src does not match the expected hash. Stop." }
  "Built OK: $tmp"
}

$guided = RootPath 'app','public','bulk-import-guided.php'; $pub = RootPath 'Publish-Batch.ps1'; $idx = RootPath 'Run-ImportIndexer.ps1'; $newf = RootPath 'Run-PublishQueue.ps1'
$tG = RootPath 'app','_p2-guided.php'; $tP = RootPath '_p2-Publish-Batch.ps1'; $tI = RootPath '_p2-Run-ImportIndexer.ps1'; $tN = RootPath '_p2-Run-PublishQueue.ps1'
$hGin = '1CF31409FBCAC71E962B3C9E061DC1A15A41C280BEE97CA1C442F2D68CB4DA96'; $hGout = '9323E7BEA5113D396123C9980A7D894CADE23591D82B780065CD20D02BF782D8'; $hPin = @('60074321440C86E6E6236AA88956627816FABFE67669BB147E13D9658A673DA8','7944B179B82843F1798E4B1F6D9C86241CDE0AE7991A469702A4EB806F3A28B3'); $hPout = @('A977B053DB165AB6410223152ED1B8D56A866E81F1B5B04ADA76FC6A1F088AEE','11EDC1D7B227630A77AA12C2BADDDD698E5B1CE87B0C258731F7C7FEC4669680'); $hIin = '6295C5E109E2A3E6919DE5EA14156579B2FAAEE03D2A9ACD23E3E6CB100257B0'; $hIout = 'A82A1B4EE415CF368A72C11BE8FAAC8EE5A99542810FE457A635D2415561D58C'; $hNew = '8E642495904E20A1F72C3E0B5FA446FA8DA68022FB1DEB7BE336C16C0D8250E7'
$liveOut = @{ $guided = $hGout; $idx = $hIout; $newf = $hNew }

if ((Test-Path -LiteralPath $newf) -and (HashOf $newf) -ne $hNew) { throw "$newf already exists with different content. Stop." }
if ((HashOf $guided) -eq $hGout -and (HashOf $idx) -eq $hIout -and $hPout -contains (HashOf $pub) -and (Test-Path -LiteralPath $newf)) { 'Patch 2 is already installed. Nothing to do.'; return }

$pG = @(
  ,@((NL @'
function learning(array $rows): array {return array_filter($rows,fn($r)=>empty($r['support']));}
'@), (NL @'
const PQ_DIR=BI_ROOT.'/publish-queue';const PS_DIR=BI_ROOT.'/publish-status';
/* Browser publishing: this page only writes publish-queue/<batch>.request.json. The host publisher (Run-PublishQueue.ps1) owns publish-status/ and index-trigger/. */
function pq_read(string $p): ?array {if(!is_file($p))return null;$j=json_decode((string)@file_get_contents($p),true);return is_array($j)?$j:null;}
function pq_info(string $id): array {
 batchdir($id);$st=pq_read(PS_DIR.'/'.$id.'.json');$idx=null;
 if($st&&($st['state']??'')==='published'&&preg_match('/^publish-[a-f0-9]{8}$/D',(string)($st['index_request']??''))){$ix=pq_read(BI_ROOT.'/index-trigger/'.$st['index_request'].'.result.json');if($ix&&strcmp((string)($ix['started']??''),(string)($st['finished']??''))>=0)$idx=$ix;}
 $hb=@filemtime(PQ_DIR.'/heartbeat.txt');
 return ['status'=>$st,'state'=>(string)($st['state']??''),'requested'=>is_file(PQ_DIR.'/'.$id.'.request.json'),'index'=>$idx,'watcher_age'=>$hb?time()-$hb:null];
}
function pq_can_request(array $pi): bool {return !$pi['requested']&&in_array($pi['state'],['','blocked'],true);}
function pq_can_reverse(array $pi): bool {return !$pi['requested']&&in_array($pi['state'],['','blocked','reversed'],true);}
function pq_counts(array $b): array {$l=0;$f=0;$by=0;foreach($b['operations'] as $rid=>$op){if(($op['state']??'')!=='copied')continue;$f++;$by+=(int)$op['bytes'];if(empty($b['rows'][$rid]['support']))$l++;}return [$l,$f,$by];}
function pq_panel(array $b,string $id,string $csrf,array $pi): string {
 if($b['state']!=='complete')return '';
 [$learn,$files,$bytes]=pq_counts($b);$st=$pi['status']??[];$s=$pi['state'];$mib=number_format($bytes/1048576,1);
 $o='<div class="panel" id="publish"><div class="step">STEP 4 &mdash; PUBLISH TO THE EDUTEK LIBRARY</div>';
 $again='<script>setTimeout(()=>location.reload(),6000)</script>';
 $hb=($pi['watcher_age']===null||$pi['watcher_age']>60)?'<p class="warning">The publisher on the Beelink is not running (no recent heartbeat). Requests wait until the &ldquo;Edutek Import Publisher&rdquo; task is running.</p>':'';
 $msg=!empty($st['message'])?'<pre class="report" style="white-space:pre-wrap">'.h($st['message']).'</pre>':'';
 if($s==='checking'||$s==='publishing')return $o.'<h2>'.($s==='checking'?'Checking the publish request&hellip;':'Publishing&hellip;').'</h2><p>'.($s==='checking'?'The publisher is re-checking this import. Nothing has been copied to the Edutek library yet.':'The publisher is copying the verified files into the Edutek library (started '.h($st['started']??'').').').'</p>'.$again.'</div>';
 if($pi['requested'])return $o.'<h2>Publish requested</h2><p>Waiting for the publisher on the Beelink to check and publish '.$learn.' materials. Nothing has been copied to the Edutek library yet.</p>'.$hb.$again.'</div>';
 if($s==='published'){
  $o.='<h2>Published to the Edutek library</h2><p>'.(int)($st['learning']??0).' learning items + '.(int)($st['covers']??0).' cover files ('.h($st['mib']??'').' MiB) were copied into D:\xampp\htdocs\Edutek\videos. Finished '.h($st['finished']??'').'.</p>';
  $ix=$pi['index'];$rn=h($st['index_request']??'');
  if(empty($st['index_request']))$o.='<p class="warning">No indexing request is recorded for this publish.</p>';
  elseif(!$ix&&!empty($st['synced']))$o.='<p class="notice">No indexing result for '.$rn.' is available here. This publish was recorded outside the browser or before it; its indexing results may have been archived (see D:\\edutek-system\\index-status on the Beelink).</p>';
  elseif(!$ix)$o.='<p class="notice">Indexing: queued as '.$rn.'. Waiting for the &ldquo;Edutek Import Partial Indexer&rdquo; task. If this does not change, check index-trigger\\'.$rn.'.result.json on the Beelink.</p>'.$again;
  elseif(($ix['state']??'')==='complete'&&(int)($ix['exit_code']??-1)===0)$o.='<p class="notice">Indexing complete for '.$rn.' (finished '.h($ix['finished']??'').').</p>';
  else $o.='<p class="error">Indexing did not complete for '.$rn.': state '.h($ix['state']??'?').', exit code '.h((string)($ix['exit_code']??'?')).'. Check index-trigger\\'.$rn.'.result.json on the Beelink. Do not run a full index.</p>';
  return $o.'<p class="muted">To reverse, run Undo-Publish.ps1 -BatchId '.h(substr($id,0,8)).' on the Beelink (dry run first).</p></div>';
 }
 if($s==='failed')return $o.'<h2>Publishing failed</h2>'.$msg.'<p>Some files may already be in the Edutek library. On the Beelink, run Undo-Publish.ps1 -BatchId '.h(substr($id,0,8)).' (dry run first) to review. This page will not publish this import again.</p></div>';
 if($s!==''&&$s!=='blocked')return $o.'<h2>Publish record: '.h($st['record_state']??$s).'</h2>'.$msg.'<p>This import is not offered for publishing again. Check the publish record on the Beelink.</p></div>';
 $o.=($s==='blocked'?'<div class="warning"><h3>Not published: the checks stopped it</h3><p>Nothing was copied to the Edutek library.</p>'.$msg.'</div>':'');
 return $o.'<h2>Publish '.$learn.' materials to the Edutek library</h2><p>The publisher on the Beelink copies these '.$files.' verified staged files ('.$mib.' MiB) into D:\xampp\htdocs\Edutek\videos, saves a publish record and a database backup, and then queues indexing for only these learning files. It re-checks every file first, never overwrites, and stops on same-size library files you did not confirm as duplicates.</p>'.$hb
 .'<form method="post" id="publish-form"><input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="revision" value="'.h($b['approval']??'').'"><label>Type <strong>PUBLISH '.$learn.'</strong> to confirm<br><input type="text" name="confirm_text" required autocomplete="off" spellcheck="false" style="max-width:260px"></label> <button class="primary" name="action" value="publish">Publish '.$learn.' materials to the Edutek library</button></form>'
 .'<script>document.getElementById("publish-form").onsubmit=()=>confirm('.json_encode('Publish '.$learn.' materials ('.$files.' files, '.$mib.' MiB) to the Edutek library now?',JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');</script></div>';
}
function learning(array $rows): array {return array_filter($rows,fn($r)=>empty($r['support']));}
'@), 1)
  ,@((NL @'
    }elseif($action==='reverse'){

'@), (NL @'
    }elseif($action==='publish'){
     $pi=pq_info($id);
     if($batch['state']!=='complete')throw new RuntimeException('Only a fully staged import can be published');
     if(!pq_can_request($pi))throw new RuntimeException('This import already has a publish request or result');
     if(empty($batch['approval'])||!hash_equals((string)$batch['approval'],(string)($_POST['revision']??'')))throw new RuntimeException('Open this import again before publishing');
     if(!hash_equals((string)$batch['approval'],hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR))))throw new RuntimeException('Plan changed');
     $targets=[];$ack=[];$learnN=0;$bytes=0;
     foreach($batch['rows'] as $rid=>$r){if(empty($r['include']))continue;$op=$batch['operations'][$rid]??null;
      if(!is_array($op)||($op['state']??'')!=='copied'||!preg_match('/^[a-f0-9]{64}$/D',(string)($op['sha256']??''))||($op['target']??null)!==$r['target'])throw new RuntimeException('Not every approved file has a verified staged copy');
      $targets[]=['target'=>$op['target'],'sha256'=>$op['sha256'],'bytes'=>(int)$op['bytes'],'support'=>!empty($r['support'])];$bytes+=(int)$op['bytes'];
      if(empty($r['support'])){$learnN++;if(!empty($r['duplicate_add']))$ack[]=$op['target'];}}
     if(!$targets||$learnN<1||count($targets)!==count($batch['operations']))throw new RuntimeException('Staged files do not match the approved plan');
     if(trim((string)($_POST['confirm_text']??''))!=='PUBLISH '.$learnN)throw new RuntimeException('Type PUBLISH '.$learnN.' to confirm publishing');
     if(!is_dir(PQ_DIR)&&!mkdir(PQ_DIR,0770,true))throw new RuntimeException('Cannot create the publish queue');
     bi_json(PQ_DIR.'/'.$id.'.request.json',['batch'=>$id,'revision'=>$batch['approval'],'confirm'=>'PUBLISH '.$learnN,'learning_count'=>$learnN,'file_count'=>count($targets),'bytes'=>$bytes,'targets'=>$targets,'allow_same_size'=>$ack,'requested_at'=>gmdate(DATE_ATOM)]);
    }elseif($action==='reverse'){
     if(!pq_can_reverse(pq_info($id)))throw new RuntimeException('This import was sent for publishing, so its staged copies stay in place. Published files are reversed with Undo-Publish.ps1 on the Beelink');

'@), 1)
  ,@((NL @'
<?php if(in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)):?><div class="panel"><h2>Reverse this import (staged copies only)</h2>
'@), (NL @'
<?php $pi=pq_info($id);echo pq_panel($batch,$id,$csrf,$pi);?><?php if(in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)&&pq_can_reverse($pi)):?><div class="panel"><h2>Reverse this import (staged copies only)</h2>
'@), 1)
)
$pP = @(
  ,@((NL @'
  [string]$ProductionRoot = 'D:\xampp\htdocs\Edutek\videos'
'@), (NL @'
  [string[]]$AllowSameSizeTarget = @(),
  [string]$ApprovedRequest = '',
  [string]$ProductionRoot = 'D:\xampp\htdocs\Edutek\videos'
'@), 1)
  ,@((NL @'
        if ($AllowSameSize) { $warnings.Add($msg) } else { $problems.Add($msg) }
'@), (NL @'
        if ($AllowSameSize -or ($AllowSameSizeTarget -ccontains $i.target)) { $warnings.Add($msg) } else { $problems.Add($msg) }
'@), 1)
  ,@((NL @'
$conf = Read-Host "Type PUBLISH $($learning.Count) to copy $($items.Count) files into the library"
'@), (NL @'
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
  if ([string]$areq.confirm -cne "PUBLISH $($learning.Count)") { $bad += 'typed confirmation' }
  if (-not $want.SetEquals($got)) { $bad += 'file list' }
  $acked = @(@($areq.allow_same_size) | Where-Object { $_ } | ForEach-Object { [string]$_ })
  foreach ($t in $AllowSameSizeTarget) { if ($acked -cnotcontains $t) { $bad += "same-size acknowledgement not in request: $t" } }
  if ($ExcludeTarget.Count -gt 0 -or $AllowSameSize) { $bad += '-ExcludeTarget/-AllowSameSize cannot be combined with -ApprovedRequest' }
  if ($bad.Count -gt 0) { Write-Host ('Cancelled. The approved request does not match this batch: ' + ($bad -join '; ') + '. Nothing was copied.') -ForegroundColor Red; return }
  Write-Host "Approved browser request accepted: $ApprovedRequest"
} else {
  $conf = Read-Host "Type PUBLISH $($learning.Count) to copy $($items.Count) files into the library"
'@), 1)
  ,@((NL @'
if ($conf -ne "PUBLISH $($learning.Count)") { Write-Host 'Cancelled. Nothing was copied.'; return }
'@), (NL @'
  if ($conf -ne "PUBLISH $($learning.Count)") { Write-Host 'Cancelled. Nothing was copied.'; return }
}
'@), 1)
)
$pI = @(
  ,@((NL @'
        if ($j -and $j.mode -eq 'full') { $full = $true; $valid += $r }
'@), (NL @'
        if ($j -and $j.mode -eq 'full') {
          Write-Result $base ([ordered]@{ batch = $base; state = 'rejected'; exit_code = 3; output = 'Full-library index requests are not accepted from the queue. A full index is a manual decision, run by hand only.' })
          Rename-Item -LiteralPath $r.FullName -NewName ($r.Name + '.' + [guid]::NewGuid().ToString('N') + '.done')
          Write-Host ("{0}  {1}  rejected (full mode is not accepted from the queue)" -f (Get-Date -Format HH:mm:ss), $base)
        }
'@), 1)
  ,@((NL @'
output = 'Request has no files list. Use "mode":"full" for a whole-library index.' }
'@), (NL @'
output = 'Request has no files list.' }
'@), 1)
)
$newText = NL @'
# Edutek browser publish queue watcher. Runs as the "Edutek Import Publisher" scheduled task.
# Reads data\publish-queue\<batch>.request.json written by bulk-import-guided.php, re-validates it against the
# staged batch, refuses any batch that already has a publish record, backs up the database, then runs
# Publish-Batch.ps1 -ApprovedRequest non-interactively. Writes data\publish-status\<batch>.json before retiring
# each request with a unique .done name. A request is processed once; nothing is retried silently.
param(
  [switch]$Once,
  [string]$Root = 'D:\edutek-system\bulk-loader-fresh-v05',
  [string]$RecordDir = 'D:\edutek-system\publish-records',
  [string]$DbBackupDir = 'D:\edutek-system\backups\db',
  [int]$MaxLearningCeiling = 200
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
function Status-FromRecord($id, $rec, $message) {
  $it = @($rec.items); $learn = @($it | Where-Object { -not $_.support })
  $map = @{ published = 'published'; failed = 'failed'; publishing = 'publishing'; reversed = 'reversed'; attention = 'attention' }
  $state = $map[[string]$rec.state]; if (-not $state) { $state = 'attention' }
  $mib = [math]::Round(((@($it | Measure-Object -Property bytes -Sum)[0].Sum) / 1MB), 1)
  [ordered]@{ batch = $id; state = $state; record_state = [string]$rec.state
    learning = $learn.Count; covers = $it.Count - $learn.Count; copied = @($it | Where-Object { $_.state -eq 'copied' }).Count; mib = $mib
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
      if (-not $cur -or (S $cur.record_state) -ne (S $rec.state)) { $o = Status-FromRecord $id $rec 'Status taken from the publish record.'; $o['synced'] = $true; Write-Status $id $o; Log "synced status for $($id.Substring(0,8)) from record ($($rec.state))" }
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
  foreach ($prop in @($b.operations.PSObject.Properties)) {
    $op = $prop.Value; $row = $b.rows.($prop.Name); $t = S $op.target
    if ($op.state -ne 'copied' -or -not $op.sha256) { $p.Add("Not a verified staged copy: $t"); continue }
    [void]$want.Add($t + '|' + (S $op.sha256).ToLower())
    $src = Join-Path (Join-Path $bd 'files') ($t -replace '/', '\')
    if (-not (Test-Path -LiteralPath $src -PathType Leaf)) { $p.Add("Staged file missing: $t") }
    elseif ((Get-Item -LiteralPath $src).Length -ne [int64]$op.bytes) { $p.Add("Staged file size changed: $t") }
    if (-not $row.support) { $learning.Add($t); $byTarget[$t] = $row }
  }
  $got = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
  foreach ($t in @($req.targets)) { if ($t -and -not $got.Add((S $t.target) + '|' + (S $t.sha256).ToLower())) { $p.Add('The request lists a file twice.') } }
  if (-not $want.SetEquals($got)) { $p.Add('The requested files or hashes differ from the staged batch.') }
  if ([int]$req.learning_count -ne $learning.Count -or [int]$req.file_count -ne $want.Count) { $p.Add('The requested counts differ from the staged batch.') }
  if ((S $req.confirm) -cne "PUBLISH $($learning.Count)") { $p.Add('The typed confirmation does not match.') }
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
    $s2['db_backup'] = $st.db_backup; $s2['requested'] = $st.requested; Write-Status $id $s2; Log "$($id.Substring(0,8))  $($s2.state)"
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
    if (-not $Once) { Start-Sleep -Seconds 5 }
  } while (-not $Once)
} finally { $lock.Close() }

'@

Build $guided $tG @($hGin) @($hGout) $pG
Build $pub $tP $hPin $hPout $pP
Build $idx $tI @($hIin) @($hIout) $pI
[IO.File]::WriteAllText($tN, $newText, (New-Object System.Text.UTF8Encoding($true)))
if ((HashOf $tN) -ne $hNew) { throw 'Run-PublishQueue.ps1 content does not match the expected hash. Stop.' }
"Built OK: $tN"

foreach ($f in $tP, $tI, $tN) { $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$null, [ref]$e); if ($e.Count) { throw "PowerShell parse errors in $f : $e" } }
'PowerShell parse checks passed.'
$c = @(& $Docker --context $DockerContext ps --filter 'publish=8092' --format '{{.Names}}')
if ($c.Count -ne 1 -or $c[0] -ne 'edutek-bulk-fresh-v05-web-1') { throw "Unexpected importer container(s): $($c -join ', ')" }
& $Docker --context $DockerContext exec $c[0] php -l /sandbox/_p2-guided.php
if ($LASTEXITCODE -ne 0) { throw 'php -l failed for the patched guided page. Live files unchanged.' }

if (-not $Apply) { ''; 'ALL CHECKS PASSED. Nothing live was changed. Re-run with -Apply to install.'; return }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'; $bak = Join-Path $BackupRoot $stamp
New-Item -ItemType Directory -Force -Path $bak | Out-Null
foreach ($f in $guided, $pub, $idx) { $d = Join-Path $bak ([IO.Path]::GetFileName($f)); Copy-Item -LiteralPath $f -Destination $d; if ((HashOf $f) -ne (HashOf $d)) { throw "Backup mismatch: $f" } }
"Backups OK in $bak"
Move-Item -LiteralPath $tG -Destination $guided -Force
Move-Item -LiteralPath $tP -Destination $pub -Force
Move-Item -LiteralPath $tI -Destination $idx -Force
Move-Item -LiteralPath $tN -Destination $newf -Force
foreach ($k in $liveOut.Keys) { if ((HashOf $k) -ne $liveOut[$k]) { throw "Live hash mismatch: $k. Run the rollback." } }
if ($hPout -notcontains (HashOf $pub)) { throw 'Live hash mismatch: Publish-Batch.ps1. Run the rollback.' }
& $Docker --context $DockerContext exec $c[0] php -l /sandbox/public/bulk-import-guided.php
if ($LASTEXITCODE -ne 0) { throw 'Live php -l failed. Run the rollback.' }
''; "INSTALLED. Backups: $bak"
'Next: restart the indexer task and register the publisher task (see the instructions).'
