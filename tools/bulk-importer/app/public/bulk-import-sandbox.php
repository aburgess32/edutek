<?php
/* Edutek sandbox importer. Never reads/writes /content or USB paths. */
declare(strict_types=1);
const BI_ROOT = '/sandbox-data';
function bi_json(string $path, array $data): void {
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if (file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false || !rename($tmp,$path)) {throw new RuntimeException('State write failed');}
}
function bi_read(string $path): array {return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
function bi_id(string $id): string {if (!preg_match('/^[a-f0-9]{32}$/D',$id)) {throw new RuntimeException('Invalid job ID');} return BI_ROOT.'/'.$id;}
function bi_save(string $dir,array $s): void {$s['updated_utc']=gmdate(DATE_ATOM);bi_json($dir.'/status.json',$s);}
function bi_csv(string $value): string {return preg_match('/^[\s]*[=+@\-\x09\x0d]/u',$value)||str_starts_with($value,"'")?"'".$value:$value;}
function bi_path(string $root,string $rel): string {
    if ($rel===''||strlen($rel)>200||str_contains($rel,'\\')||str_starts_with($rel,'/')) {throw new RuntimeException('Invalid relative destination');}
    $parts=explode('/',$rel);if(count($parts)<2){throw new RuntimeException('Use category/filename or category/course/filename');}
    $p=$root;
    foreach($parts as $part){
        if($part===''||$part==='.'||$part==='..'||preg_match('/[<>:"\\\\|?*\x00-\x1f]/',$part)||preg_match('/[. ]$/',$part)||preg_match('/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)/i',$part)){throw new RuntimeException('Unsafe Windows path component');}
        $p.='/'.$part;if(is_link($p)){throw new RuntimeException('Link rejected');}
    }
    return $p;
}
function bi_inventory(string $root): array {
    $out=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $f){if($f->isLink()){throw new RuntimeException('Link rejected');}if(!$f->isFile()){continue;}
        $rel=substr($f->getPathname(),strlen($root)+1);$out[$rel]=['bytes'=>$f->getSize(),'mtime'=>$f->getMTime()];}
    ksort($out);return $out;
}
function bi_validate(string $dir): array {
    $m=bi_read($dir.'/manifest.json');$errors=[];$rows=[];$seen=[];$targets=[];$selected=[];
    if(bi_inventory($dir.'/source')!==$m['inventory']){$errors[]='Source inventory changed; create a new sandbox job.';}
    $fp=fopen($dir.'/edited.csv','rb');$head=fgetcsv($fp,0,',','"','');
    $expected=['row_id','include','source_relative_path','content_type','category','subcategory','target_relative_path','action','duplicate_decision'];
    if($head!==$expected){fclose($fp);throw new RuntimeException('CSV headers/order differ from exported CSV.');}
    while(($r=fgetcsv($fp,0,',','"',''))!==false){
        if(count($r)!==count($head)){throw new RuntimeException('Malformed CSV field count');}
        $r=array_combine($head,$r);$id=$r['row_id'];
        if(!isset($m['rows'][$id])||isset($seen[$id])){$errors[]='Unknown or duplicate row ID: '.$id;continue;}$seen[$id]=true;$old=$m['rows'][$id];
        foreach(['source_relative_path','content_type'] as $k){if($r[$k]!==bi_csv($old[$k])){$errors[]=$id.': immutable source field changed';}}
        if(!in_array($r['include'],['0','1'],true)||!in_array($r['action'],['REVIEW','SKIP','COPY','KEEP_BOTH'],true)||!in_array($r['duplicate_decision'],['REVIEW','SKIP','COPY','KEEP_BOTH'],true)){$errors[]=$id.': invalid include/action/decision';}
        if($r['include']==='1'){
            try{
                if(!in_array($r['action'],['COPY','KEEP_BOTH'],true)){throw new RuntimeException('Selected row needs COPY or KEEP_BOTH');}
                if($old['warnings']&&!in_array($r['duplicate_decision'],['COPY','KEEP_BOTH'],true)){throw new RuntimeException('Resolve duplicate warning explicitly');}
                $target=bi_path($dir.'/library',$r['target_relative_path']);
                if(file_exists($target)){throw new RuntimeException('Destination occupied; overwrite forbidden');}
                $p=dirname($target);while($p!==$dir.'/library'){if(file_exists($p)&&!is_dir($p)){throw new RuntimeException('Parent is a file');}$p=dirname($p);}
                $parts=explode('/',$r['target_relative_path']);$sub=count($parts)>=3?$parts[1]:'';
                if($r['category']!==$parts[0]||$r['subcategory']!==$sub){throw new RuntimeException('Category/subcategory disagree with target');}
                if(strtolower(pathinfo($target,PATHINFO_EXTENSION))!==strtolower(pathinfo($old['source_relative_path'],PATHINFO_EXTENSION))){throw new RuntimeException('Extension changes forbidden');}
                $key=strtolower($r['target_relative_path']);if(isset($targets[$key])){throw new RuntimeException('Planned target collision');}$targets[$key]=true;
                $selected[]=$r;
            }catch(Throwable $e){$errors[]=$id.': '.$e->getMessage();}
        }
        $rows[]=$r;
    }fclose($fp);
    if(count($seen)!==count($m['rows'])){$errors[]='Missing rows; exclude rows instead of deleting them.';}
    foreach(array_keys($targets) as $key){$p=dirname($key);while($p!=='.'){if(isset($targets[$p])){$errors[]='Planned file is a parent directory: '.$p;}$p=dirname($p);}}
    return ['errors'=>$errors,'rows'=>$rows,'selected'=>$selected,'csv_sha256'=>hash_file('sha256',$dir.'/edited.csv')];
}
function bi_process(string $dir,array $cmd): void {
    $s=bi_read($dir.'/status.json');$action=$cmd['action'];
    try {
        $s['state']='running';$s['phase']=$action;bi_save($dir,$s);
        if($action==='analyze'){
            foreach(['source/Math/Algebra','source/Unknown','library/Math/Algebra','stage'] as $sub){mkdir($dir.'/'.$sub,0770,true);}
            $fixtures=['Math/Algebra/01 Existing.pdf'=>'existing dummy PDF','Math/Algebra/02 New.pdf'=>'new dummy PDF','Math/Algebra/02 New.jpg'=>'dummy cover','Unknown/02 New.mp4'=>'dummy video; not playable'];
            foreach($fixtures as $rel=>$bytes){file_put_contents($dir.'/source/'.$rel,$bytes);}
            file_put_contents($dir.'/library/Math/Algebra/01 Existing.pdf','existing dummy PDF');
            $rows=[];$n=0;
            foreach($fixtures as $rel=>$bytes){$id='r'.str_pad((string)++$n,4,'0',STR_PAD_LEFT);$parts=explode('/',$rel);$ext=pathinfo($rel,PATHINFO_EXTENSION);
                $rows[$id]=['row_id'=>$id,'include'=>'0','source_relative_path'=>$rel,'content_type'=>['pdf'=>'pdf','jpg'=>'image','mp4'=>'video'][$ext],'category'=>$parts[0]==='Math'?'Math':'','subcategory'=>$parts[0]==='Math'?'Algebra':'','target_relative_path'=>$parts[0]==='Math'?$rel:'','action'=>'REVIEW','duplicate_decision'=>'REVIEW','warnings'=>$n===1?'LIKELY_EXISTING_COPY|HIGH_CONFIDENCE_CANDIDATE':''];}
            bi_json($dir.'/manifest.json',['inventory'=>bi_inventory($dir.'/source'),'rows'=>$rows]);
            $fp=fopen($dir.'/edited.csv','wb');$cols=['row_id','include','source_relative_path','content_type','category','subcategory','target_relative_path','action','duplicate_decision'];fputcsv($fp,$cols,',','"','');
            foreach($rows as $r){fputcsv($fp,array_map(fn($k)=>bi_csv($r[$k]),$cols),',','"','');}fclose($fp);
            $s['state']='awaiting_review';$s['rows']=array_values($rows);$s['message']='Four disposable source files analyzed. No real media was accessed.';
        }elseif($action==='validate'){
            $v=bi_validate($dir);$v['revision']=bin2hex(random_bytes(16));bi_json($dir.'/preview.json',$v);
            $s['state']=$v['errors']?'validation_failed':'preview_ready';$s['preview']=$v;$s['message']='Validation finished. Nothing copied.';
        }elseif($action==='execute'){
            $v=bi_read($dir.'/preview.json');
            if(!hash_equals($v['revision'],(string)$cmd['revision'])||$v['errors']||!hash_equals($v['csv_sha256'],hash_file('sha256',$dir.'/edited.csv'))){throw new RuntimeException('Approved revision is stale');}
            $fresh=bi_validate($dir);if($fresh['errors']){throw new RuntimeException(implode('; ',$fresh['errors']));}
            $s['results']=[];bi_save($dir,$s);
            foreach($v['selected'] as $r){
                $m=bi_read($dir.'/manifest.json');$rel=$m['rows'][$r['row_id']]['source_relative_path'];$src=bi_path($dir.'/source',$rel);$dst=bi_path($dir.'/library',$r['target_relative_path']);
                clearstatcache();if(file_exists($dst)){throw new RuntimeException('Destination appeared; overwrite blocked');}
                $before=stat($src);$record=$m['inventory'][$rel];if($before['size']!==$record['bytes']||$before['mtime']!==$record['mtime']){throw new RuntimeException('Source changed');}
                $tmp=$dir.'/stage/'.bin2hex(random_bytes(12)).'.part';$in=fopen($src,'rb');$to=fopen($tmp,'xb');
                if(!$in||!$to){throw new RuntimeException('Cannot open staging streams');}
                $copied=stream_copy_to_stream($in,$to);fflush($to);fclose($to);fclose($in);clearstatcache();
                if($copied!==$record['bytes']||filesize($tmp)!==$record['bytes']||filesize($src)!==$record['bytes']||filemtime($src)!==$record['mtime']){unlink($tmp);throw new RuntimeException('Size/source stability verification failed');}
                $parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true)){throw new RuntimeException('Cannot create sandbox destination folder');}
                /* Exclusive destination creation prevents overwriting, including races. */
                $output=@fopen($dst,'xb');if(!$output){throw new RuntimeException('Exclusive destination creation refused');}
                $input=fopen($tmp,'rb');$written=stream_copy_to_stream($input,$output);fflush($output);fclose($output);fclose($input);clearstatcache();
                if($written!==$record['bytes']||filesize($dst)!==$record['bytes']){throw new RuntimeException('Partial sandbox output; inspect report before retry');}
                unlink($tmp);$s['results'][]=['row_id'=>$r['row_id'],'destination'=>$r['target_relative_path'],'bytes'=>$written,'status'=>'copied_size_verified'];bi_save($dir,$s);
            }
            $s['state']='complete';$s['message']='Sandbox copy complete. Originals retained. No catalog indexing was run.';
        }else{throw new RuntimeException('Unknown command');}
        bi_save($dir,$s);
    }catch(Throwable $e){$s['state']='failed';$s['message']=$e->getMessage();bi_save($dir,$s);}
}
if(PHP_SAPI==='cli'){
    if(!is_dir(BI_ROOT)){mkdir(BI_ROOT,0770,true);}
    $lock=fopen(BI_ROOT.'/worker.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB)){exit(0);}
    file_put_contents(BI_ROOT.'/worker.pid',(string)getmypid());
    while(true){file_put_contents(BI_ROOT.'/heartbeat.txt',gmdate(DATE_ATOM));foreach(glob(BI_ROOT.'/*/command.json')?:[] as $path){$claimed=dirname($path).'/claimed.json';if(rename($path,$claimed)){try{bi_process(dirname($path),bi_read($claimed));}catch(Throwable $e){error_log($e->getMessage());}unlink($claimed);}}sleep(2);}
}
/* Independent sandbox access; no Edutek account dependency. */
/* Browser visits go to the guided importer. The worker runs this file as CLI and never leaves the loop above. */
if(PHP_SAPI!=='cli'){header('Location: /bulk-import-guided.php',true,302);exit;}
session_name('EDUTEK_BULK_FRESH_V05');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/bulk-import-sandbox.php',
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

        header('Location: /bulk-import-sandbox.php');
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
        . '<form method="post" action="/bulk-import-sandbox.php">'
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
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');

if(!isset($_SESSION['bi_sandbox_csrf'])){$_SESSION['bi_sandbox_csrf']=bin2hex(random_bytes(32));}
$token=$_SESSION['bi_sandbox_csrf'];$owner='sandbox-operator';$job=(string)($_POST['job']??$_GET['job']??'');$dir='';$s=[];$error='';
try{
    if(!is_dir(BI_ROOT)){mkdir(BI_ROOT,0770,true);}
    if($job!==''){$dir=bi_id($job);$s=bi_read($dir.'/status.json');if($s['owner']!==$owner){throw new RuntimeException('Job belongs to another user');}}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($token,(string)($_POST['csrf']??''))){throw new RuntimeException('CSRF verification failed');}
        $action=(string)($_POST['action']??'');
        if($action==='new'){$job=bin2hex(random_bytes(16));$dir=bi_id($job);mkdir($dir,0770);$s=['owner'=>$owner,'job'=>$job,'state'=>'queued','message'=>'Waiting for sandbox worker'];bi_save($dir,$s);bi_json($dir.'/command.json',['action'=>'analyze']);}
        else{
            if(!$dir){throw new RuntimeException('No job selected');}
            $lock=fopen($dir.'/web.lock','c');flock($lock,LOCK_EX);
            try{
                $s=bi_read($dir.'/status.json');
                if(in_array($s['state'],['queued','running','complete','failed'],true)||file_exists($dir.'/command.json')||file_exists($dir.'/claimed.json')){throw new RuntimeException('Job is busy or terminal. Create a new sandbox job if needed.');}
                if($action==='upload'){
                    $u=$_FILES['plan']??[];if(($u['error']??1)!==UPLOAD_ERR_OK||($u['size']??0)>1048576){throw new RuntimeException('Choose a CSV smaller than 1 MB');}
                    $bytes=file_get_contents($u['tmp_name']);if($bytes===false){throw new RuntimeException('Upload read failed');}
                    $tmp=$dir.'/upload.tmp';file_put_contents($tmp,$bytes);rename($tmp,$dir.'/edited.csv');unset($s['preview']);$s['state']='queued';bi_save($dir,$s);bi_json($dir.'/command.json',['action'=>'validate']);
                }elseif($action==='execute'){
                    if($s['state']!=='preview_ready'){throw new RuntimeException('A valid preview is required');}
                    $v=bi_read($dir.'/preview.json');if(!$v['selected']){throw new RuntimeException('Select at least one file');}
                    if(!hash_equals($v['revision'],(string)($_POST['revision']??''))||($_POST['confirm']??'')!=='COPY SANDBOX'){throw new RuntimeException('Revision or typed confirmation does not match');}
                    $s['state']='queued';bi_save($dir,$s);bi_json($dir.'/command.json',['action'=>'execute','revision'=>$v['revision']]);
                }else{throw new RuntimeException('Invalid action');}
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }
        header('Location: /bulk-import-sandbox.php?job='.rawurlencode($job));exit;
    }
    if(isset($_GET['download'])&&$dir){$name=$_GET['download']==='csv'?'edited.csv':'status.json';header('Content-Type: '.($name==='edited.csv'?'text/csv':'application/json'));header('Content-Disposition: attachment; filename="sandbox-'.$name.'"');readfile($dir.'/'.$name);exit;}
}catch(Throwable $e){$error=$e->getMessage();}
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edutek sandbox bulk importer</title>
<?php if(in_array($s['state']??'', ['queued','running'],true)): ?><meta http-equiv="refresh" content="3"><?php endif ?>
<style>body{font:16px system-ui;max-width:1100px;margin:30px auto;padding:16px;background:#f4f6f8;color:#17212b}section{background:white;padding:20px;margin:16px 0;border:1px solid #ccd3db;border-radius:8px}button,a.button{padding:10px 14px;display:inline-block;margin:6px;background:#17689b;color:white;border:0;border-radius:4px}table{border-collapse:collapse;width:100%;font-size:14px}td,th{padding:8px;text-align:left;border-bottom:1px solid #ddd;overflow-wrap:anywhere}.warning{background:#fff1cb;padding:16px}pre{white-space:pre-wrap;overflow-wrap:anywhere}input{padding:8px}</style>
<a href="/">Home</a><h1>Add learning materials — fresh sandbox</h1>
<p class="warning">Disposable fixtures only. This build cannot select a USB source, copy to the real library, or index content. Dummy PDF/video files are not playable. Approval, copy, and progress can be tested safely here.</p>
<?php if($error): ?><section><h2>Request blocked</h2><p><?=h($error)?></p></section><?php endif ?>
<section><h2>1. Analyze disposable content</h2><p>Creates four incoming files and one existing destination file in private sandbox storage.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($token)?>"><button name="action" value="new">Create and analyze sandbox job</button></form>
<?php $heartbeat=BI_ROOT.'/heartbeat.txt'; ?><p>Worker: <?=is_file($heartbeat)&&time()-filemtime($heartbeat)<15?'active':'not detected — run the worker start command'?></p></section>
<?php if($s): ?><section><h2>Job status: <?=h($s['state'])?></h2><p>Job: <?=h($job)?></p><p><?=h($s['message']??'')?></p><a href="?job=<?=h($job)?>">Refresh status</a> · <a href="?job=<?=h($job)?>&download=report">Download JSON report</a>
<?php if(!empty($s['rows'])): ?><table><tr><th>ID</th><th>Source</th><th>Suggested target</th><th>Warnings</th></tr><?php foreach($s['rows'] as $r): ?><tr><td><?=h($r['row_id'])?></td><td><?=h($r['source_relative_path'])?></td><td><?=h($r['target_relative_path'])?></td><td><?=h($r['warnings'])?></td></tr><?php endforeach ?></table><?php endif ?></section>
<?php if(is_file($dir.'/edited.csv')&&!in_array($s['state'],['queued','running','complete','failed'],true)): ?><section><h2>2. Edit and validate CSV</h2><a class="button" href="?job=<?=h($job)?>&download=csv">Download editable CSV</a><p>First test: change only r0002 and r0003 to include=1 and action=COPY. Leave the existing file and unresolved video excluded. Preserve every row and all columns.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($token)?>"><input type="hidden" name="job" value="<?=h($job)?>"><input type="file" name="plan" accept=".csv" required><button name="action" value="upload">Upload and validate CSV</button></form></section><?php endif ?>
<?php if(isset($s['preview'])): $v=$s['preview']; ?><section><h2>3. Validated preview</h2><p>Selected files: <?=count($v['selected'])?> · Errors: <?=count($v['errors'])?></p><pre><?=h(json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>
<?php if($s['state']==='preview_ready'&&$v['selected']): ?><form method="post"><input type="hidden" name="csrf" value="<?=h($token)?>"><input type="hidden" name="job" value="<?=h($job)?>"><input type="hidden" name="revision" value="<?=h($v['revision'])?>"><label>Type COPY SANDBOX <input name="confirm" autocomplete="off" required></label><button name="action" value="execute">Approve exact revision and copy sandbox files</button></form><?php endif ?></section><?php endif ?>
<?php if(isset($s['results'])): ?><section><h2>Per-file results</h2><pre><?=h(json_encode($s['results'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre></section><?php endif ?>
<?php endif ?><script src="/js/bulk-import-visual.js?v=04" defer></script></html>