<?php
/* Edutek sandbox importer. Never reads/writes /content or USB paths. */
declare(strict_types=1);
const BI_ROOT = '/sandbox-data';
function bi_json(string $path, array $data): void {
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if (file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false || !rename($tmp,$path)) {throw new RuntimeException('State write failed');}
}
function bi_read(string $path): array {return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
/* Independent sandbox access; no Edutek account dependency. */
session_name('EDUTEK_BULK_REAL_V06');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/bulk-import-real.php',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$configPath = BI_ROOT . '/operator-auth.json';
$config = is_file($configPath)
    ? json_decode((string) file_get_contents($configPath), true)
    : null;

if (
    !is_array($config)
    || !preg_match('/^[a-f0-9]{64}$/D', (string) ($config['key_sha256'] ?? ''))
) {
    http_response_code(503);
    exit('Sandbox maintenance access is not configured.');
}

$keyHash = $config['key_sha256'];

if (empty($_SESSION['bi_unlock_csrf'])) {
    $_SESSION['bi_unlock_csrf'] = bin2hex(random_bytes(32));
}

$unlocked =
    !empty($_SESSION['bi_operator_until'])
    && $_SESSION['bi_operator_until'] > time()
    && hash_equals(
        $keyHash,
        (string) ($_SESSION['bi_operator_key_version'] ?? '')
    );

$unlockError = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_POST['bi_maintenance_key'])
) {
    $csrfOK = hash_equals(
        $_SESSION['bi_unlock_csrf'],
        (string) ($_POST['bi_unlock_csrf'] ?? '')
    );

    $keyOK = hash_equals(
        $keyHash,
        hash('sha256', trim((string) $_POST['bi_maintenance_key']))
    );

    if ($csrfOK && $keyOK) {
        session_regenerate_id(true);
        $_SESSION['bi_operator_until'] = time() + 7200;
        $_SESSION['bi_operator_key_version'] = $keyHash;
        $_SESSION['bi_unlock_csrf'] = bin2hex(random_bytes(32));

        header('Location: /bulk-import-real.php');
        exit;
    }

    usleep(500000);
    $unlockError = 'Key not accepted. Check the key and try again.';
}

if (!$unlocked) {
    $csrfHTML = htmlspecialchars(
        $_SESSION['bi_unlock_csrf'],
        ENT_QUOTES,
        'UTF-8'
    );

    $errorHTML = htmlspecialchars($unlockError, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html lang="en"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Edutek maintenance access</title>'
        . '<body style="font:16px system-ui;max-width:650px;margin:60px auto;padding:20px">'
        . '<h1>Fresh sandbox — maintenance access</h1>'
        . '<p>No teacher or guest sign-in is required.</p>'
        . '<p>Enter the maintenance key generated in PowerShell on this Beelink.</p>'
        . '<p style="color:#a00">' . $errorHTML . '</p>'
        . '<form method="post" action="/bulk-import-real.php">'
        . '<input type="hidden" name="bi_unlock_csrf" value="' . $csrfHTML . '">'
        . '<label>Maintenance key<br>'
        . '<input type="password" name="bi_maintenance_key" required '
        . 'autocomplete="off" spellcheck="false" style="width:95%;padding:10px">'
        . '</label><p><button type="submit" style="padding:10px">'
        . 'Unlock sandbox</button></p></form>'
        . '<p>Access expires after two hours. No application account is created.</p>'
        . '</body></html>';

    exit;
}

const REAL_ROOT='/sandbox-data/real-v06';
function h($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function savebatch(array $b): void {bi_json(REAL_ROOT.'/'.$b['id'].'/batch.json',$b);}
function batchdir(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid batch');return REAL_ROOT.'/'.$id;}
function sourcefile(string $rel): string {
 if(str_contains($rel,'\\')||str_starts_with($rel,'/'))throw new RuntimeException('Invalid source');
 $p='/import-source';foreach(explode('/',$rel) as $c){if($c===''||$c==='.'||$c==='..')throw new RuntimeException('Invalid source');$p.='/'.$c;if(is_link($p))throw new RuntimeException('Source link rejected');}
 $real=realpath($p);if($real===false||!str_starts_with($real,'/import-source/')||!is_file($real))throw new RuntimeException('Source unavailable');return $real;
}
function cleancomponent(string $v): string {
 $v=Normalizer::normalize($v,Normalizer::FORM_C);if($v===false)throw new RuntimeException('Invalid Unicode');
 $v=preg_replace('/[<>:"\/\\\\|?*\x00-\x1f\x7f]/u','_',$v);
 $v=rtrim(trim($v),'. ');
 if($v===''||$v==='.'||$v==='..')$v='Untitled';
 if(preg_match('/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³]|CONIN\$|CONOUT\$)(?:\.|$)/iu',$v))$v='_'.$v;
 return $v;
}
function destfile(string $dir,string $rel): string {
 if(strlen($rel)>200||substr_count($rel,'/')<1)throw new RuntimeException('Demo destination limit: 200 UTF-8 bytes; use collection/filename');
 $p=$dir.'/files';if(is_link($p))throw new RuntimeException('Destination link rejected');
 foreach(explode('/',$rel) as $c){if($c!==cleancomponent($c)||mb_strlen($c,'UTF-8')>120)throw new RuntimeException('Unsafe or overlong destination component');$p.='/'.$c;if(is_link($p))throw new RuntimeException('Destination link rejected');}
 return $p;
}
function keyname(string $v): string {return mb_strtolower(Normalizer::normalize($v,Normalizer::FORM_C),'UTF-8');}
function checkplan(array $b,string $dir): array {
 $chosen=[];$seen=[];$bytes=0;
 foreach($b['rows'] as $id=>$r){if(!$r['include'])continue;
  $src=sourcefile($r['source']);clearstatcache(true,$src);
  if(filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime'])throw new RuntimeException('Source metadata changed: '.$r['source']);
  $dst=destfile($dir,$r['target']);
  if(strtolower(pathinfo($dst,PATHINFO_EXTENSION))!==$r['extension'])throw new RuntimeException('File extension cannot change');
  if(file_exists($dst))throw new RuntimeException('Destination exists; overwrite forbidden');
  $key=keyname($r['target']);if(isset($seen[$key]))throw new RuntimeException('Destination collision: '.$r['target']);$seen[$key]=true;
  $chosen[$id]=$r;$bytes+=$r['bytes'];
 }
 if(!$chosen)throw new RuntimeException('Select at least one learning item');
 if(count($chosen)>5||$bytes>160*1024*1024)throw new RuntimeException('First-test limit: 5 files / 160 MiB. Deselect some items.');
 foreach(array_keys($seen) as $k){$parent=dirname($k);while($parent!=='.'){if(isset($seen[$parent]))throw new RuntimeException('File/folder collision');$parent=dirname($parent);}}
 return $chosen;
}
if(!is_dir(REAL_ROOT)&&!mkdir(REAL_ROOT,0770,true))throw new RuntimeException('Cannot create durable test storage');
if(empty($_SESSION['real_csrf']))$_SESSION['real_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['real_csrf'];$error='';$id=(string)($_POST['id']??$_GET['id']??'');$batch=null;
try{
 if($id!=='')$batch=bi_read(batchdir($id).'/batch.json');
 if(isset($_GET['open'])&&$batch){
  $rid=(string)$_GET['open'];$op=$batch['operations'][$rid]??null;
  if(!$op||$op['state']!=='copied')throw new RuntimeException('Imported item unavailable');
  $p=destfile(batchdir($id),$op['target']);if(!is_file($p))throw new RuntimeException('Imported file missing');
  $size=filesize($p);$start=0;$end=$size-1;
  if(isset($_SERVER['HTTP_RANGE'])){
   if(!preg_match('/^bytes=(\d+)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$m)){http_response_code(416);exit;}
   $start=(int)$m[1];$end=$m[2]===''?$end:min($end,(int)$m[2]);
   if($start>$end||$start>=$size){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
   http_response_code(206);header("Content-Range: bytes $start-$end/$size");
  }
  $ext=strtolower(pathinfo($p,PATHINFO_EXTENSION));header('Content-Type: '.($ext==='pdf'?'application/pdf':'video/mp4'));header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));session_write_close();
  $fp=fopen($p,'rb');fseek($fp,$start);$left=$end-$start+1;while($left>0&&!feof($fp)){$chunk=fread($fp,min(1048576,$left));if($chunk===false||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}fclose($fp);exit;
 }
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Reload.');
  $lock=fopen(REAL_ROOT.'/actions.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another action is running. Wait and reload.');
  try{
   set_time_limit(300);ignore_user_abort(true);$action=(string)($_POST['action']??'');
   if($action==='scan'){
    if(!is_dir('/import-source'))throw new RuntimeException('Read-only source is unavailable');
    $id=bin2hex(random_bytes(16));$dir=batchdir($id);mkdir($dir,0770);mkdir($dir.'/files',0770);mkdir($dir.'/recovery',0770);
    $batch=['id'=>$id,'created'=>gmdate(DATE_ATOM),'state'=>'draft','source'=>'Read-only D: snapshot of USB files','rows'=>[],'operations'=>[],'events'=>[]];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/import-source',FilesystemIterator::SKIP_DOTS));$n=0;
    foreach($it as $f){if($f->isLink())throw new RuntimeException('Source link rejected');if(!$f->isFile())continue;$ext=strtolower($f->getExtension());if(!in_array($ext,['pdf','mp4'],true))continue;
     if(++$n>500)throw new RuntimeException('Demo scan limit: 500 files');
     $rel=substr($f->getPathname(),strlen('/import-source/'));sourcefile($rel);$parts=explode('/',$rel);$group=dirname($rel);if($group==='.')$group='Unsorted';
     $target=implode('/',array_map('cleancomponent',explode('/',$group))).'/'.cleancomponent(basename($rel));
     $rid='r'.$n;$batch['rows'][$rid]=['source'=>$rel,'group'=>$group,'type'=>$ext==='pdf'?'Books':'Videos','extension'=>$ext,'title'=>pathinfo($rel,PATHINFO_FILENAME),'target'=>$target,'include'=>false,'reviewed'=>false,'bytes'=>$f->getSize(),'mtime'=>$f->getMTime(),'names_changed'=>$target!==$rel];
    }
    // Metadata-only duplicate candidates, not identity claims.
    $signals=[];foreach($batch['rows'] as $rid=>$r){$k=$r['type'].'|'.keyname($r['title']).'|'.$r['bytes'];$signals[$k][]=$rid;}
    foreach($signals as $ids)if(count($ids)>1)foreach($ids as $rid)$batch['rows'][$rid]['candidate']=true;
    savebatch($batch);
   }else{
    if(!$batch)throw new RuntimeException('Choose a batch');$dir=batchdir($id);$batch=bi_read($dir.'/batch.json');
    if($action==='save'||$action==='preview'){
     if(!in_array($batch['state'],['draft','approved'],true))throw new RuntimeException('This batch is no longer editable');
     foreach($batch['rows'] as $rid=>&$r){
      $r['include']=isset($_POST['include'][$rid]);$r['reviewed']=isset($_POST['reviewed'][$rid]);
      $title=(string)($_POST['title'][$rid]??$r['title']);$r['title']=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',$title));
      $r['target']=(string)($_POST['target'][$rid]??$r['target']);
     }unset($r);
     $batch['state']='draft';unset($batch['approval']);savebatch($batch);
     if($action==='preview'){
      $chosen=checkplan($batch,$dir);foreach($chosen as $r)if(!$r['reviewed'])throw new RuntimeException('Mark each selected item Reviewed, including any name or duplicate warning.');
      $batch['approval']=hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR));$batch['state']='approved';savebatch($batch);
     }
    }elseif($action==='execute'){
     if($batch['state']!=='approved'||!hash_equals((string)$batch['approval'],(string)($_POST['revision']??''))||($_POST['confirm']??'')!=='COPY TEST')throw new RuntimeException('Approved revision and COPY TEST confirmation required');
     if(!hash_equals($batch['approval'],hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR))))throw new RuntimeException('Plan changed');
     $chosen=checkplan($batch,$dir);$total=array_sum(array_column($chosen,'bytes'));if(disk_free_space($dir)<$total+64*1024*1024)throw new RuntimeException('Insufficient destination space');
     $batch['state']='importing';savebatch($batch);
     foreach($chosen as $rid=>$r){
      $src=sourcefile($r['source']);$dst=destfile($dir,$r['target']);$parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true))throw new RuntimeException('Cannot create destination');
      $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'copying','bytes'=>$r['bytes']];savebatch($batch);
      $in=fopen($src,'rb');$to=@fopen($dst,'xb');if(!$in||!$to)throw new RuntimeException('Cannot create exclusive output');
      $hash=hash_init('sha256');$count=0;
      while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false)throw new RuntimeException('Source read failed');if($chunk==='')break;hash_update($hash,$chunk);$len=strlen($chunk);$off=0;while($off<$len){$w=fwrite($to,substr($chunk,$off));if($w===false||$w===0)throw new RuntimeException('Output write failed');$off+=$w;}$count+=$len;}
      fflush($to);fclose($to);fclose($in);$sha=hash_final($hash);clearstatcache(true,$src);
      if($count!==$r['bytes']||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime']||!hash_equals($sha,hash_file('sha256',$dst)))throw new RuntimeException('Copy verification failed; partial output preserved');
      $batch['operations'][$rid]['state']='copied';$batch['operations'][$rid]['sha256']=$sha;savebatch($batch);
     }
     $batch['state']='complete';$batch['events'][]=gmdate(DATE_ATOM).' Copy complete; no production indexing';savebatch($batch);
    }elseif($action==='reverse'){
     if(!in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)||($_POST['confirm']??'')!=='REVERSE TEST')throw new RuntimeException('Type REVERSE TEST to confirm the entire batch');
     $batch['state']='reversing';savebatch($batch);$conflicts=[];
     foreach($batch['operations'] as $rid=>&$op){
      if($op['state']==='reversed')continue;
      if(!in_array($op['state'],['copied','reversing'],true)||empty($op['sha256'])){$conflicts[]=$rid.': incomplete output preserved';continue;}
      $p=destfile($dir,$op['target']);$recovery=$dir.'/recovery/'.$rid.'.'.$batch['rows'][$rid]['extension'];
      if(!file_exists($p)&&$op['state']==='reversing'&&is_file($recovery)&&!is_link($recovery)&&hash_equals($op['sha256'],hash_file('sha256',$recovery))){$op['state']='reversed';savebatch($batch);continue;}
      if(!is_file($p)||!hash_equals($op['sha256'],hash_file('sha256',$p))||file_exists($recovery)){$conflicts[]=$rid.': missing, changed, or recovery occupied; preserved';continue;}
      $op['state']='reversing';savebatch($batch);
      if(!rename($p,$recovery)){$conflicts[]=$rid.': move failed';continue;}
      $op['state']='reversed';savebatch($batch);
     }unset($op);
     $batch['conflicts']=$conflicts;$batch['state']=$conflicts?'attention':'reversed';$batch['events'][]=gmdate(DATE_ATOM).' Reversal: '.$batch['state'];savebatch($batch);
    }else throw new RuntimeException('Unknown action');
   }
  }finally{flock($lock,LOCK_UN);fclose($lock);}
  header('Location: /bulk-import-real.php?id='.rawurlencode($id));exit;
 }
}catch(Throwable $e){$error=$e->getMessage();if($id!==''&&is_file(batchdir($id).'/batch.json')){$batch=bi_read(batchdir($id).'/batch.json');if(in_array($batch['state'],['importing','reversing'],true)){$batch['state']='interrupted';$batch['events'][]=gmdate(DATE_ATOM).' '.$error;savebatch($batch);}}}
$history=[];foreach(glob(REAL_ROOT.'/*/batch.json')?:[] as $p){try{$history[]=bi_read($p);}catch(Throwable $e){}}usort($history,fn($x,$y)=>strcmp($y['created'],$x['created']));
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edutek real-file test</title>
<style>
*{box-sizing:border-box}body{font:16px system-ui;margin:0;background:#f3f6fa;color:#192b3d}header,main{padding:20px;max-width:1250px;margin:auto}h1{margin:0}button,input{font:inherit}button{padding:9px 14px;cursor:pointer}input[type=text]{width:100%;padding:7px;border:1px solid #aab9cb;border-radius:4px}nav{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.layout{display:grid;grid-template-columns:240px 1fr;gap:20px}aside,.card,details{background:white;border:1px solid #d5dfeb;border-radius:8px;padding:16px;margin-bottom:12px}summary{cursor:pointer;font-weight:600}.row{padding:14px 0;border-top:1px solid #ddd}.muted{font-size:13px;color:#52677d;overflow-wrap:anywhere}.warning{color:#994400}.error{background:#ffe4e4;padding:15px}label{display:block;margin:7px 0}aside a{display:block;margin:12px 0;overflow-wrap:anywhere}.actions{position:sticky;bottom:0;background:#e4edf7;padding:12px;display:flex;gap:12px;flex-wrap:wrap}.active{background:#173c65;color:white}a{color:#164f94}@media(max-width:760px){.layout{display:block}}[hidden]{display:none!important}
</style><header><h1>Edutek — real-file sandbox test</h1><p>Read-only snapshot → review → approved copy → inspect → reverse. No production integration.</p></header><main>
<?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
<div class="layout"><aside><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><button name="action" value="scan">New snapshot scan</button></form><h2>Drafts & history</h2>
<?php foreach($history as $entry):?><a href="?id=<?=h($entry['id'])?>"><?=h($entry['created'])?><br><?=h($entry['state'])?> · <?=count($entry['rows'])?> items</a><?php endforeach;?></aside><section>
<?php if(!$batch):?><div class="card"><h2>Start here</h2><p>Select New snapshot scan. No media copies occur during scanning.</p><p>First-test limit: approve at most five items. Music and audiobook views are empty because this snapshot contains PDFs and MP4s only.</p></div>
<?php else:$dir=batchdir($id);$editable=in_array($batch['state'],['draft','approved'],true);?>
<div class="card"><h2>Batch <?=h(substr($id,0,8))?> · <?=h($batch['state'])?></h2><p class="muted">Storage: <?=h($dir)?> · Source: <?=h($batch['source'])?></p><p>24-file snapshot expected. Counts below are learning items; this test has no supporting images.</p></div>
<nav id="filters"><?php foreach(['All content','Books','Videos','Music','Audiobooks'] as $type):$count=$type==='All content'?count($batch['rows']):count(array_filter($batch['rows'],fn($r)=>$r['type']===$type));?><button type="button" data-filter="<?=h($type)?>"><?=h($type)?> (<?=$count?>)</button><?php endforeach;?></nav>
<p class="muted">Filters change visibility only. Save/preview applies to the full batch, including hidden selections. Reversal always covers the entire batch.</p>
<?php if($editable):?><form method="post" id="editor"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="id" value="<?=h($id)?>">
<?php endif;
$groups=[];foreach($batch['rows'] as $rid=>$r)$groups[$r['group']][$rid]=$r;
foreach($groups as $group=>$rows):?><details class="collection" open><summary><?=h($group)?> · <?=count($rows)?> items</summary>
<?php foreach($rows as $rid=>$r):?><div class="row" data-type="<?=h($r['type'])?>"><div><?=h($r['type'])?> · <?=number_format($r['bytes']/1048576,1)?> MiB</div><p class="muted">Original: <?=h($r['source'])?></p>
<?php if($editable):?><label><input type="checkbox" name="include[<?=h($rid)?>]" <?=$r['include']?'checked':''?>> Include this item</label><label>Display title<input type="text" name="title[<?=h($rid)?>]" value="<?=h($r['title'])?>" maxlength="300"></label><label>Proposed destination<input type="text" name="target[<?=h($rid)?>]" value="<?=h($r['target'])?>" maxlength="200"></label>
<?php if($r['names_changed']):?><p class="warning">Name suggestion: Windows-safe/NFC-normalized destination. USB name is unchanged. Compare Original → Proposed above.</p><?php endif;?>
<?php if(!empty($r['candidate'])):?><p class="warning">Same-type title/size duplicate candidate—not proven identical. Include both only deliberately.</p><?php endif;?>
<label><input type="checkbox" name="reviewed[<?=h($rid)?>]" <?=$r['reviewed']?'checked':''?>> Reviewed this item, destination, and any warnings</label>
<?php else:?><p><?=h($r['title'])?></p><p class="muted"><?=h($r['target'])?></p><?php $op=$batch['operations'][$rid]??null;if($op):?><p><?=h($op['state'])?><?php if($op['state']==='copied'):?> · <a target="_blank" rel="noopener" href="?id=<?=h($id)?>&open=<?=h($rid)?>">Open sandbox copy</a><?php endif;?></p><?php endif;endif;?></div><?php endforeach;?></details><?php endforeach;?>
<p id="empty" hidden>No content matches this view. Your saved decisions are unchanged.</p>
<?php if($editable):?><div class="actions"><button name="action" value="save">Save draft</button><button name="action" value="preview">Save & final check</button><span id="save-state">Server save is explicit—not automatic.</span></div></form><?php endif;?>
<?php if($batch['state']==='approved'):$selected=array_filter($batch['rows'],fn($r)=>$r['include']);?><div class="card"><h2>Final approval</h2><p><?=count($selected)?> learning items · <?=number_format(array_sum(array_column($selected,'bytes'))/1048576,1)?> MiB · 0 supporting files. Only checked items will copy. No overwrites; originals preserved.</p><p>Folder grouping follows the proposed destinations shown above. Editing the plan requires another final check.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="revision" value="<?=h($batch['approval'])?>"><label>Type COPY TEST<input type="text" name="confirm" required autocomplete="off"></label><button name="action" value="execute" id="copy-button">Copy approved saved plan</button></form></div><?php endif;?>
<?php if(in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)):?><div class="card"><h2>Reverse this entire import</h2><p>Fresh checks run when submitted. Unchanged verified copies move into this batch’s recovery folder; changed or uncertain files are preserved and reported. Filters do not limit reversal. Empty folders remain. Recovery purge/restore is not implemented.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="id" value="<?=h($id)?>"><label>Type REVERSE TEST<input type="text" name="confirm" required autocomplete="off"></label><button name="action" value="reverse">Check & reverse entire batch</button></form></div><?php endif;?>
<?php if(!empty($batch['conflicts'])):?><div class="error"><?=h(implode(' | ',$batch['conflicts']))?></div><?php endif;?>
<?php if(!empty($batch['events'])):?><div class="card"><h2>Persistent report</h2><?php foreach($batch['events'] as $event):?><p><?=h($event)?></p><?php endforeach;?></div><?php endif;?>
<?php endif;?></section></div></main>
<script>
const key='edutek-real-v06-filter';function filter(type){let n=0;document.querySelectorAll('.row').forEach(r=>{r.hidden=type!=='All content'&&r.dataset.type!==type;if(!r.hidden)n++});document.querySelectorAll('.collection').forEach(c=>{c.hidden=![...c.querySelectorAll('.row')].some(r=>!r.hidden)});document.querySelectorAll('[data-filter]').forEach(b=>b.classList.toggle('active',b.dataset.filter===type));const e=document.getElementById('empty');if(e)e.hidden=n!==0;try{localStorage.setItem(key,type)}catch(e){}}
document.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>filter(b.dataset.filter)));let initial='All content';try{initial=localStorage.getItem(key)||initial}catch(e){}filter(initial);
const editor=document.getElementById('editor');if(editor){editor.addEventListener('input',()=>{document.getElementById('save-state').textContent='Unsaved changes — save draft or run final check';const b=document.getElementById('copy-button');if(b){b.disabled=true;b.textContent='Run final check again after edits'}});editor.addEventListener('submit',()=>document.getElementById('save-state').textContent='Saving…');}
</script></html>
