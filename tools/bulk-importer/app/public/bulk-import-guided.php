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
session_name('EDUTEK_BULK_GUIDED_V08');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/bulk-import-guided.php',
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

        header('Location: /bulk-import-guided.php');
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
        . '<form method="post" action="/bulk-import-guided.php">'
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

const REAL_ROOT='/sandbox-data/guided-v08';
require_once __DIR__.'/video-preview-lib.php';
function h($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function savebatch(array $b): void {bi_json(REAL_ROOT.'/'.$b['id'].'/batch.json',$b);}
function batchdir(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid batch');return REAL_ROOT.'/'.$id;}
function bi_valid_root(string $root): string {if($root!=='/import-source'&&!preg_match('#^/import-sources/(?!\.\.?$)[^/]+$#D',$root))throw new RuntimeException('Invalid source root');if(is_link($root))throw new RuntimeException('Source link rejected');return $root;}
function sourcefile(string $rel,string $root='/import-source'): string {
 if(str_contains($rel,'\\')||str_starts_with($rel,'/'))throw new RuntimeException('Invalid source');
 $p=bi_valid_root($root);foreach(explode('/',$rel) as $c){if($c===''||$c==='.'||$c==='..')throw new RuntimeException('Invalid source');$p.='/'.$c;if(is_link($p))throw new RuntimeException('Source link rejected');}
 $real=realpath($p);if($real===false||!str_starts_with($real,$root.'/')||!is_file($real))throw new RuntimeException('Source unavailable');return $real;
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

function bi_progress(string $phase,string $name,int $cur,int $curTotal,bool $force=false): void {
 static $last=0.0;$now=microtime(true);if(!$force&&$now-$last<0.4)return;$last=$now;
 $g=$GLOBALS['bi_prog']??null;if(!is_array($g))return;
 try{bi_json($g['dir'].'/progress.json',['phase'=>$phase,'file'=>$name,'file_done'=>$cur,'file_total'=>$curTotal,'base'=>$g['base'],'total'=>$g['total'],'files_done'=>$g['files_done'],'files_total'=>$g['files_total'],'updated'=>$now]);}catch(Throwable $e){}
}
function attach_art(array &$batch): void {
 $extra=[];
 foreach($batch['rows'] as $rid=>&$r){
  $rootp=$r['src_root']??'/import-source';$p=sourcefile($r['source'],$rootp);$matches=[];
  foreach(new DirectoryIterator(dirname($p)) as $image){
   if($image->isDot()||$image->isLink()||!$image->isFile())continue;
   $ext=strtolower($image->getExtension());
   if(!in_array($ext,['jpg','jpeg','png','webp'],true)||$image->getSize()>10*1024*1024)continue;
   if(pathinfo($image->getFilename(),PATHINFO_FILENAME)!==pathinfo($p,PATHINFO_FILENAME))continue;
   $info=@getimagesize($image->getPathname());if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true))continue;
   $matches[]=['source'=>substr($image->getPathname(),strlen($rootp)+1),'src_root'=>$rootp,'extension'=>$ext,'bytes'=>$image->getSize(),'mtime'=>$image->getMTime()];
  }
  $r['art_count']=count($matches);$r['art_options']=$matches;
  if(count($matches)!==1)continue;
  $image=$matches[0];$aid='art-'.$rid;$r['art']=$aid;
  $extra[$aid]=array_merge($image,['support'=>true,'parent'=>$rid,'group'=>$r['group'],'type'=>'Artwork','title'=>$r['title'].' artwork','include'=>false,'reviewed'=>false,'names_changed'=>false,'target'=>dirname($r['target']).'/'.pathinfo($r['target'],PATHINFO_FILENAME).'.'.$image['extension']]);
 }unset($r);
 $batch['rows']=array_merge($batch['rows'],$extra);
}

function bi_tidy_title(string $s): string {
 $t=preg_replace('/_+/u',' ',$s);$ch=preg_split('//u',(string)$t,-1,PREG_SPLIT_NO_EMPTY)?:[];$drop=[];$stack=[];
 foreach($ch as $i=>$c){if($c==='('){$stack[]=$i;}elseif($c===')'){if($stack)array_pop($stack);else $drop[$i]=true;}}
 foreach($stack as $i)$drop[$i]=true;
 $out='';foreach($ch as $i=>$c){if(!isset($drop[$i]))$out.=$c;}
 $out=preg_replace('/\s+/u',' ',$out);$out=trim((string)$out);return $out===''?$s:$out;
}
function bi_dup_where(array $ex): string {
 $w=trim(str_replace('/',' > ',(string)($ex['where']??'')),' >');
 if(($ex['kind']??'earlier')==='device')return 'On this device, '.($ex['lib']??'device library').': '.($w!==''?$w.' > ':'').($ex['file']??'');
 return 'Earlier import'.(!empty($ex['created'])?' ('.$ex['created'].')':'').': Edutek > videos'.($w!==''?' > '.$w:'');
}
function bi_dup_badges(array $existing,bool $srcSide): string {
 $dev=false;$ear=false;foreach($existing as $ex){if(($ex['kind']??'earlier')==='device')$dev=true;else $ear=true;}
 $o='';
 if($dev)$o.='<span class="badge" style="background:#f6d3d3">ALREADY ON THIS DEVICE</span>';
 if($ear)$o.='<span class="badge" style="background:#fde8c4">ALREADY ADDED IN AN EARLIER IMPORT</span>';
 if($srcSide)$o.='<span class="badge" style="background:#d6e4f2">DUPLICATE WITHIN THE SOURCE</span>';
 return $o;
}
function bi_dup_kinds(array $existing,bool $src): string {$k=[];foreach($existing as $e){$k[(($e['kind']??'earlier')==='device')?'device':'earlier']=true;}if($src)$k['source']=true;return implode(' ',array_keys($k));}
function bi_in_pool(array $r): bool {return empty($r['candidate'])&&empty($r['already_imported'])&&!(needs_fix($r)&&!hash_equals(fix_token($r),(string)($r['fix_accept']??'')));}
function bi_dup_rank(array $set): int {foreach(($set['existing']??[]) as $e)if(($e['kind']??'earlier')==='device')return 0;return empty($set['existing'])?2:1;}
function bi_dupkey(string $title): string {return keyname((string)preg_replace('/\s*\(\d+\)$/u','',bi_tidy_title($title)));}
function keyname(string $v): string {return mb_strtolower(Normalizer::normalize($v,Normalizer::FORM_C),'UTF-8');}

const GP_ROOT='D:/xampp/htdocs/Edutek/videos';
const GP_RULES=1;
function gp_config(): array {
 static $config=null;if($config!==null)return $config;
 $file=BI_ROOT.'/planner-categories.json';$config=is_file($file)?json_decode((string)file_get_contents($file),true):[];
 if(!is_array($config))$config=[];return $config;
}
function gp_role(string $type): string {
 $wanted=['Books'=>'Books','Music'=>'Music','Audiobooks'=>'Audiobooks'][$type]??'';if($wanted==='')return '';
 $matches=[];foreach(gp_config()['categories']??[] as $name){$norm=preg_replace('/[\s_-]+/u','',mb_strtolower($name));if($norm===mb_strtolower($wanted)||($type==='Audiobooks'&&$norm==='audiobook'))$matches[]=$name;}
 if(count($matches)>1)throw new RuntimeException('Multiple category folders match '.$wanted.'. Resolve the category naming before planning.');
 return $matches[0]??$wanted;
}
function gp_component(string $v,string $label): string {
 if($v===''||str_contains($v,'/')||str_contains($v,chr(92))||$v!==cleancomponent($v)||mb_strlen($v)>120)throw new RuntimeException($label.' must be a safe folder name, not a path. Suggested name: '.cleancomponent($v));return $v;
}
function gp_validate(string $type,string $category,string $folder): void {
 gp_component($category,'Category');if($folder!=='')foreach(explode('/',$folder) as $part)gp_component($part,'Subfolder');
 $role=gp_role($type);
 if($role!==''){
  if($category!==$role)throw new RuntimeException($type.' belong under '.$role.'. Change the '.($type==='Books'?'subcategory':($type==='Music'?'album folder':'audiobook folder')).' instead.');
  if($folder==='')throw new RuntimeException(($type==='Books'?'A book subcategory':($type==='Music'?'An album folder':'An audiobook folder')).' is required.');
  if(str_contains($folder,'/'))throw new RuntimeException('Use one '.($type==='Books'?'subcategory':($type==='Music'?'album':'audiobook')).' folder name, not a multi-level path.');
 }else foreach(['Books','Music','Audiobooks'] as $reserved)if($category===gp_role($reserved))throw new RuntimeException('Choose a general category for this video, not the reserved '.$category.' category.');
}
function gp_init(array &$r): void {
 if(!isset($r['placement_category'])){
  $parts=explode('/',$r['target']);array_pop($parts);$category=array_shift($parts)??'Unsorted';
  $r['placement_category']=gp_role($r['type'])?:$category;
  $r['placement_folder']=$r['type']==='Books'?(implode(' - ',$parts)?:'Unsorted'):implode('/',$parts);
  $r['placement_override']=false;
 }
 $r['target']=gp_target($r);
}
function gp_target(array $r): string {
 gp_validate($r['type'],$r['placement_category'],$r['placement_folder']);
 $originalTitle=$r['original_title']??pathinfo($r['source'],PATHINFO_FILENAME);
 $name=$r['title']===$originalTitle?cleancomponent(basename($r['source'])):cleancomponent($r['title']).'.'.$r['extension'];
 return $r['placement_category'].($r['placement_folder']!==''?'/'.$r['placement_folder']:'').'/'.$name;
}
function gp_set(array &$r,array $p): void {
 gp_init($r);$category=(string)($p['category']??$r['placement_category']);$folder=(string)($p['folder']??$r['placement_folder']);
 gp_validate($r['type'],$category,$folder);$r['placement_category']=$category;$r['placement_folder']=$folder;$r['placement_override']=($p['placement_override']??false)===true;$r['target']=gp_target($r);
}

function checkplan(array $b,string $dir): array {
 if(($b['placement_rules']??0)!==GP_RULES)throw new RuntimeException('Use Review selection to approve the updated folder rules before adding materials.');
 $issues=decision_issues($b);if($issues)throw new RuntimeException('Some selected items still need a decision. Return to Review selection.');
 $chosen=[];$seen=[];$bytes=0;
 foreach($b['rows'] as $id=>$r){if(!$r['include'])continue;
  $src=pv_input($r);clearstatcache(true,$src);
  if(filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime'])throw new RuntimeException('Source metadata changed: '.$r['source']);
  $dst=destfile($dir,$r['target']);
  if(strtolower(pathinfo($dst,PATHINFO_EXTENSION))!==$r['extension'])throw new RuntimeException('File extension cannot change');
  if(file_exists($dst))throw new RuntimeException('Destination exists; overwrite forbidden');
  $key=keyname($r['target']);if(isset($seen[$key]))throw new RuntimeException('Destination collision: '.$r['target']);$seen[$key]=true;
  $chosen[$id]=$r;$bytes+=$r['bytes'];
 }
 if(!$chosen)throw new RuntimeException('Select at least one learning item');

 foreach(array_keys($seen) as $k){$parent=dirname($k);while($parent!=='.'){if(isset($seen[$parent]))throw new RuntimeException('File/folder collision');$parent=dirname($parent);}}
 return $chosen;
}

function fix_token(array $r): string {return hash('sha256',$r['target']);}
function needs_fix(array $r): bool {
 $requested=$r['title']===($r['original_title']??pathinfo($r['source'],PATHINFO_FILENAME))?basename($r['source']):$r['title'].'.'.$r['extension'];
 return basename($r['target'])!==$requested;
}
function rebuild_support(array &$b): void {
 foreach(array_keys($b['rows']) as $rid)if(!empty($b['rows'][$rid]['support']))unset($b['rows'][$rid]);
 $extra=[];
 foreach($b['rows'] as $rid=>&$r){
  unset($r['art']);$options=$r['art_options']??[];$image=null;
  if(count($options)===1)$image=$options[0];
  elseif(count($options)>1&&ctype_digit((string)($r['art_choice']??''))&&isset($options[(int)$r['art_choice']]))$image=$options[(int)$r['art_choice']];
  if(!$image&&in_array($r['type'],['Videos','Books'],true)&&count($options)===0&&$r['include']){
   $pv=pv_info($r,true);
   if($pv['state']==='ready'){$path=pv_path($pv['key']);$image=['source'=>$r['source'],'generated_key'=>$pv['key'],'asset_sha256'=>$pv['sha256'],'extension'=>'jpg','bytes'=>filesize($path),'mtime'=>filemtime($path)];}
  }
  if(!$image)continue;$aid='art-'.$rid;$r['art']=$aid;
  $extra[$aid]=array_merge($image,['support'=>true,'parent'=>$rid,'group'=>$r['group'],'type'=>'Artwork','title'=>$r['title'].' artwork','include'=>$r['include'],'target'=>dirname($r['target']).'/'.pathinfo($r['target'],PATHINFO_FILENAME).'.'.$image['extension']]);
 }unset($r);$b['rows']=array_merge($b['rows'],$extra);
}
function decision_issues(array $b): array {
 $out=[];
 foreach($b['rows'] as $rid=>$r){if(!empty($r['support'])||!$r['include'])continue;
  if(in_array($r['type'],['Videos','Books'],true)&&empty($r['art'])&&($r['art_count']??0)===0){$preview=pv_info($r,false);if($preview['state']!=='failed')$out[]=['id'=>$rid,'kind'=>'preview','message'=>'A cover image is being generated; wait for it, then Review selection again'];}
  if(needs_fix($r)&&!hash_equals(fix_token($r),(string)($r['fix_accept']??'')))$out[]=['id'=>$rid,'kind'=>'name','message'=>'Accept the suggested safe filename'];
  if(!empty($r['candidate'])&&empty($r['duplicate_add']))$out[]=['id'=>$rid,'kind'=>'duplicate','message'=>'Decide whether to add a possible duplicate'];
  if(!empty($r['already_imported'])&&empty($r['duplicate_add']))$out[]=['id'=>$rid,'kind'=>'recent','message'=>'This was already imported earlier. Confirm you want to add it again'];
  if(($r['art_count']??0)>1&&($r['art_choice']??'')==='')$out[]=['id'=>$rid,'kind'=>'art','message'=>'Choose artwork or continue without artwork'];
 }
 return $out;
}
function bi_tree_add(array &$tree,array $parts,array $leaf): void {
 $n=&$tree;$last=count($parts)-1;
 foreach($parts as $i=>$p){if($i===$last){$n['files'][]=$leaf+['name'=>$p];}else{if(!isset($n['dirs'][$p]))$n['dirs'][$p]=[];$n=&$n['dirs'][$p];}}
}
function bi_tree_html(array $node,string $id,array $rows): string {
 $o='<ul>';
 $dirs=$node['dirs']??[];uksort($dirs,'strnatcasecmp');
 foreach($dirs as $name=>$child)$o.='<li><span class="folder">'.h($name).'</span>'.bi_tree_html($child,$id,$rows).'</li>';
 $files=$node['files']??[];usort($files,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
 foreach($files as $f){
  $r=$f['row'];
  if(!empty($r['support'])){
   $parent=$rows[$r['parent']]??[];
   $how=!empty($r['generated_key'])?((($parent['type']??'')==='Books')?'generated from page 1':'generated frame'):'supplied with the content';
   $o.='<li class="file"><img src="?id='.h($id).'&amp;art='.h($r['parent']).'" alt="Cover"><div><div>'.h($f['name']).'</div><div class="muted">Cover image, '.h($how).'</div></div></li>';
  }else{
   $o.='<li class="file"><div><div><strong>'.h($f['name']).'</strong></div><div class="muted">'.h($r['type']==='Books'?'Book':'Video').', '.number_format($r['bytes']/1048576,1).' MiB';
   if(basename($r['source'])!==$f['name'])$o.=' - renamed from '.h(basename($r['source']));
   if(!empty($r['candidate']))$o.=' - possible duplicate, added on purpose';
   if(!empty($r['already_imported']))$o.=' - already imported earlier, added again on purpose';
   $o.='</div></div></li>';
  }
 }
 return $o.'</ul>';
}
function learning(array $rows): array {return array_filter($rows,fn($r)=>empty($r['support']));}
function hidden_fields(string $csrf,string $id=''): void {echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';if($id!=='')echo '<input type="hidden" name="id" value="'.h($id).'">';}

if(!is_dir(REAL_ROOT)&&!mkdir(REAL_ROOT,0770,true))throw new RuntimeException('Cannot create durable test storage');
if(empty($_SESSION['real_csrf']))$_SESSION['real_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['real_csrf'];$error='';$id=(string)($_POST['id']??$_GET['id']??'');$batch=null;
try{
 if($id!=='')$batch=bi_read(batchdir($id).'/batch.json');

 if((isset($_GET['preview_status'])||isset($_GET['preview_image']))&&$batch){
  $rid=(string)($_GET['preview_status']??$_GET['preview_image']);$r=$batch['rows'][$rid]??null;
  if(!$r||!empty($r['support'])||!in_array($r['type'],['Videos','Books'],true)||($r['art_count']??0)!==0)throw new RuntimeException('No generated preview for this item');
  if(isset($_GET['preview_status'])&&!hash_equals($csrf,(string)($_GET['token']??'')))throw new RuntimeException('Preview session expired');
  $info=pv_info($r,isset($_GET['preview_status']));
  if(isset($_GET['preview_image'])){
   if($info['state']!=='ready'){http_response_code(404);exit;}$p=pv_path($info['key']);
   if(!hash_equals($info['sha256'],hash_file('sha256',$p)))throw new RuntimeException('Preview changed');
   header('Content-Type: image/jpeg');header('Content-Length: '.filesize($p));session_write_close();readfile($p);exit;
  }
  header('Content-Type: application/json');session_write_close();echo json_encode(['state'=>$info['state'],'seconds'=>$info['seconds']??null,'url'=>$info['state']==='ready'?'/bulk-import-guided.php?id='.rawurlencode($id).'&preview_image='.rawurlencode($rid):null,'message'=>$info['message']??null],JSON_THROW_ON_ERROR);exit;
 }

 if(isset($_GET['progress'])&&$batch){
  if(!hash_equals($csrf,(string)($_GET['token']??''))){http_response_code(403);exit;}
  $pp=batchdir($id).'/progress.json';$pr=is_file($pp)?json_decode((string)@file_get_contents($pp),true):null;
  header('Content-Type: application/json');session_write_close();echo json_encode(['state'=>$batch['state'],'progress'=>is_array($pr)?$pr:null]);exit;
 }
 if(isset($_GET['art'])&&$batch){
  $parent=(string)$_GET['art'];$r=$batch['rows'][$parent]??null;
  if(!$r||(empty($r['art'])&&!isset($_GET['choice'])))throw new RuntimeException('Artwork unavailable');
  $options=$r['art_options']??[];
  if(isset($_GET['choice'])){$choice=(string)$_GET['choice'];if(!ctype_digit($choice)||!isset($options[(int)$choice]))throw new RuntimeException('Artwork choice unavailable');$ar=$options[(int)$choice];}
  else {$ar=$batch['rows'][$r['art']];}
  $p=pv_input($ar);$info=@getimagesize($p);
  if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||filesize($p)>10*1024*1024)throw new RuntimeException('Unsupported artwork');
  header('Content-Type: '.$info['mime']);header('Content-Length: '.filesize($p));session_write_close();readfile($p);exit;
 }
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
   set_time_limit(0);ignore_user_abort(true);session_write_close();$action=(string)($_POST['action']??'');
   if($action==='scan'){
    $srcKey=(string)($_POST['source_key']??'legacy');
    if($srcKey==='legacy'){$root='/import-source';$srcLabel='Desktop Videos folder (read-only)';$winBase='C:/Users/Edutek/Desktop/Videos/';}
    elseif(str_starts_with($srcKey,'s:')){$srcName=substr($srcKey,2);if($srcName===''||$srcName==='.'||$srcName==='..'||str_contains($srcName,'/')||str_contains($srcName,chr(92))||!is_dir('/import-sources/'.$srcName)||is_link('/import-sources/'.$srcName))throw new RuntimeException('That import source is not available');$root='/import-sources/'.$srcName;$srcLabel=$srcName.' (read-only copy)';$winBase='C:/Users/Edutek/Desktop/Import Sources/'.$srcName.'/';}
    else throw new RuntimeException('Unknown import source');
    if(!is_dir($root))throw new RuntimeException('Read-only source is unavailable');
    $id=bin2hex(random_bytes(16));$dir=batchdir($id);mkdir($dir,0770);mkdir($dir.'/files',0770);mkdir($dir.'/recovery',0770);
    $batch=['id'=>$id,'created'=>gmdate(DATE_ATOM),'state'=>'draft','source'=>$srcLabel,'src_root'=>$root,'source_key'=>$srcKey,'win_base'=>$winBase,'rows'=>[],'operations'=>[],'events'=>[]];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));$n=0;
    foreach($it as $f){if($f->isLink())throw new RuntimeException('Source link rejected');if(!$f->isFile())continue;$ext=strtolower($f->getExtension());if(!in_array($ext,['pdf','mp4'],true))continue;
     if(++$n>500)throw new RuntimeException('Demo scan limit: 500 files');
     $rel=substr($f->getPathname(),strlen($root)+1);sourcefile($rel,$root);$parts=explode('/',$rel);$group=dirname($rel);if($group==='.')$group='Unsorted';
     $target=implode('/',array_map('cleancomponent',explode('/',$group))).'/'.cleancomponent(basename($rel));
     $rid='r'.$n;$batch['rows'][$rid]=['source'=>$rel,'src_root'=>$root,'group'=>$group,'type'=>$ext==='pdf'?'Books':'Videos','extension'=>$ext,'original_title'=>pathinfo($rel,PATHINFO_FILENAME),'title'=>bi_tidy_title(pathinfo($rel,PATHINFO_FILENAME)),'target'=>$target,'include'=>false,'reviewed'=>false,'bytes'=>$f->getSize(),'mtime'=>$f->getMTime(),'names_changed'=>$target!==$rel];
    }
    foreach($batch['rows'] as &$r){gp_init($r);}unset($r);$batch['placement_rules']=GP_RULES;$batch['planned_root']=GP_ROOT;
    attach_art($batch);
    // Metadata-only duplicate candidates, not identity claims.
    $signals=[];foreach($batch['rows'] as $rid=>$r){if(!empty($r['support']))continue;$k=$r['type'].'|'.bi_dupkey((string)$r['title']).'|'.$r['bytes'];$signals[$k][]=$rid;}
    foreach($signals as $ids)if(count($ids)>1)foreach($ids as $rid){$batch['rows'][$rid]['candidate']=true;$batch['rows'][$rid]['dup_source']=true;}
    $doneSrc=[];$sk=[];$existing=[];foreach(glob(REAL_ROOT.'/*/batch.json')?:[] as $bp){try{$ob=bi_read($bp);}catch(Throwable $e){continue;}foreach(($ob['operations']??[]) as $orid=>$op){if(($op['state']??'')!=='copied')continue;$orow=$ob['rows'][$orid]??null;if(!is_array($orow)||!empty($orow['support']))continue;$existing[$orow['type'].'|'.bi_dupkey((string)$orow['title']).'|'.$orow['bytes']][]=['created'=>(string)($ob['created']??''),'kind'=>'earlier','where'=>dirname((string)$orow['target'])];$doneSrc[(string)($orow['src_root']??'/import-source').'|'.(string)$orow['source'].'|'.(int)$orow['bytes'].'|'.(int)$orow['mtime']]=true;}}
    $dix=BI_ROOT.'/device-index.json';$deviceBuilt='';
    if(is_file($dix)){$dj=json_decode((string)@file_get_contents($dix),true);if(is_array($dj)){$deviceBuilt=(string)($dj['built']??'');$dl=[];foreach(($dj['libraries']??[]) as $lb)$dl[(string)($lb['key']??'')]=(string)($lb['label']??'');foreach(($dj['items']??[]) as $di){$dp=(string)($di['path']??'');$dext=strtolower(pathinfo($dp,PATHINFO_EXTENSION));$dty=$dext==='pdf'?'Books':($dext==='mp4'?'Videos':'');if($dty==='')continue;$existing[$dty.'|'.bi_dupkey(pathinfo($dp,PATHINFO_FILENAME)).'|'.(int)($di['bytes']??0)][]=['kind'=>'device','lib'=>($dl[(string)($di['lib']??'')]??'device library'),'created'=>'','where'=>(dirname($dp)==='.'?'':dirname($dp)),'file'=>basename($dp)];}}}
    $batch['device_index_built']=$deviceBuilt;
    foreach($batch['rows'] as $rid=>$r){if(!empty($r['support']))continue;$ek=$r['type'].'|'.bi_dupkey((string)$r['title']).'|'.$r['bytes'];$dsk=(string)($r['src_root']??'/import-source').'|'.(string)$r['source'].'|'.(int)$r['bytes'].'|'.(int)$r['mtime'];$ents=$existing[$ek]??[];$dev=array_values(array_filter($ents,fn($z)=>($z['kind']??'')==='device'));$ear=array_values(array_filter($ents,fn($z)=>($z['kind']??'')==='earlier'));unset($batch['rows'][$rid]['candidate'],$batch['rows'][$rid]['dup_source'],$batch['rows'][$rid]['existing'],$batch['rows'][$rid]['already_imported'],$batch['rows'][$rid]['recent_kind'],$batch['rows'][$rid]['recent_where']);
      if($dev){$sk[$ek][]=$rid;$batch['rows'][$rid]['candidate']=true;$batch['rows'][$rid]['existing']=[$dev[0]];}
      elseif(isset($doneSrc[$dsk])){$batch['rows'][$rid]['already_imported']=true;$batch['rows'][$rid]['recent_kind']='same';$batch['rows'][$rid]['recent_where']=$ear?$ear[0]:null;}
      elseif($ear){$batch['rows'][$rid]['already_imported']=true;$batch['rows'][$rid]['recent_kind']='similar';$batch['rows'][$rid]['recent_where']=$ear[0];}
      else{$sk[$ek][]=$rid;}}
    foreach($sk as $ids2)if(count($ids2)>1)foreach($ids2 as $rid2){$batch['rows'][$rid2]['candidate']=true;$batch['rows'][$rid2]['dup_source']=true;}
    savebatch($batch);
   }else{
    if(!$batch)throw new RuntimeException('Choose a batch');$dir=batchdir($id);$batch=bi_read($dir.'/batch.json');
    if($action==='save'||$action==='preview'){
     if(!in_array($batch['state'],['draft','approved'],true))throw new RuntimeException('This batch is no longer editable');
     $raw=(string)($_POST['draft']??'');if(strlen($raw)>524288)throw new RuntimeException('Draft too large');
     $posted=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($posted))throw new RuntimeException('Draft was not received; JavaScript must be enabled');
     foreach($batch['rows'] as $rid=>&$r){
      if(!empty($r['support']))continue;
      if(!isset($posted[$rid])||!is_array($posted[$rid]))throw new RuntimeException('Incomplete draft. Reload and try again.');
      $p=$posted[$rid];$r['include']=($p['include']??false)===true;
      $title=(string)($p['title']??$r['title']);if(mb_strlen($title)>300)throw new RuntimeException('Title too long');
      $r['title']=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',$title));if($r['title']==='')throw new RuntimeException('A title cannot be empty');
      gp_set($r,$p);
      $r['fix_accept']=(string)($p['fix_accept']??'');
      $r['duplicate_add']=($p['duplicate_add']??false)===true;$dd=(string)($p['dup_decision']??'');$r['dup_decision']=in_array($dd,['reject','add','replace'],true)?$dd:'';if($r['dup_decision']==='reject'||$r['dup_decision']==='replace')$r['include']=false;
      if(($r['art_count']??0)>1){$choice=(string)($p['art_choice']??'');$r['art_choice']=($choice==='none'||(ctype_digit($choice)&&isset($r['art_options'][(int)$choice])))?$choice:'';}
     }unset($r);
     rebuild_support($batch);$batch['placement_rules']=GP_RULES;$batch['planned_root']=GP_ROOT;
     $batch['state']='draft';$batch['screen']='select';unset($batch['approval'],$batch['issues']);savebatch($batch);
     if($action==='preview'){
      $issues=decision_issues($batch);$batch['issues']=$issues;
      if($issues){$batch['screen']='fix';savebatch($batch);header('Location: /bulk-import-guided.php?id='.rawurlencode($id));exit;}
      $chosen=checkplan($batch,$dir);unset($batch['issues']);$batch['screen']='confirm';
      $batch['approval']=hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR));$batch['state']='approved';savebatch($batch);
     }
    }elseif($action==='execute'){
     if($batch['state']!=='approved'||!hash_equals((string)$batch['approval'],(string)($_POST['revision']??''))||($_POST['confirm']??'')!=='ADD')throw new RuntimeException('Open the final summary again before adding materials');
     if(!hash_equals($batch['approval'],hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR))))throw new RuntimeException('Plan changed');
     $chosen=checkplan($batch,$dir);$total=array_sum(array_column($chosen,'bytes'));if(disk_free_space($dir)<$total+64*1024*1024)throw new RuntimeException('Insufficient destination space');
     $batch['state']='importing';savebatch($batch);
     foreach($chosen as $rid=>$r){
      $src=pv_input($r);$dst=destfile($dir,$r['target']);$parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true))throw new RuntimeException('Cannot create destination');
      $GLOBALS['bi_prog']=['dir'=>$dir,'total'=>array_sum(array_column($chosen,'bytes')),'base'=>array_sum(array_map(fn($o)=>($o['state']??'')==='copied'?(int)$o['bytes']:0,$batch['operations'])),'files_done'=>count(array_filter($batch['operations'],fn($o)=>($o['state']??'')==='copied')),'files_total'=>count($chosen)];bi_progress('copying',basename($r['target']),0,(int)$r['bytes'],true);
      $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'copying','bytes'=>$r['bytes']];savebatch($batch);
      $in=fopen($src,'rb');$to=@fopen($dst,'xb');if(!$in||!$to)throw new RuntimeException('Cannot create exclusive output');
      $hash=hash_init('sha256');$count=0;
      while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false)throw new RuntimeException('Source read failed');if($chunk==='')break;hash_update($hash,$chunk);$len=strlen($chunk);$off=0;while($off<$len){$w=fwrite($to,substr($chunk,$off));if($w===false||$w===0)throw new RuntimeException('Output write failed');$off+=$w;}$count+=$len;bi_progress('copying',basename($r['target']),$count,(int)$r['bytes']);}
      fflush($to);fclose($to);fclose($in);$sha=hash_final($hash);bi_progress('verifying',basename($r['target']),(int)$r['bytes'],(int)$r['bytes'],true);clearstatcache(true,$src);
      if($count!==$r['bytes']||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime']||!hash_equals($sha,hash_file('sha256',$dst)))throw new RuntimeException('Copy verification failed; partial output preserved');
      $batch['operations'][$rid]['state']='copied';$batch['operations'][$rid]['sha256']=$sha;savebatch($batch);
     }
     $batch['state']='complete';$batch['events'][]=gmdate(DATE_ATOM).' Copy complete; no production indexing';savebatch($batch);
    }elseif($action==='reverse'){
     if(!in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)||($_POST['confirm']??'')!=='REVERSE')throw new RuntimeException('Confirm reversal of the entire batch');
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
  header('Location: /bulk-import-guided.php?id='.rawurlencode($id));exit;
 }
}catch(Throwable $e){$error=$e->getMessage();if($id!==''&&is_file(batchdir($id).'/batch.json')){$batch=bi_read(batchdir($id).'/batch.json');if(in_array($batch['state'],['importing','reversing'],true)){$batch['state']='interrupted';$batch['events'][]=gmdate(DATE_ATOM).' '.$error;savebatch($batch);}}}
if($batch&&in_array($batch['state'],['draft','approved'],true)&&($batch['placement_rules']??0)!==GP_RULES){
 foreach($batch['rows'] as &$r)if(empty($r['support']))gp_init($r);unset($r);
 $batch['state']='draft';unset($batch['approval']);$batch['placement_notice']=true;
}
$sourceChoices=['legacy'=>'Desktop Videos (existing)'];foreach(glob('/import-sources/*',GLOB_ONLYDIR)?:[] as $sp){if(!is_link($sp))$sourceChoices['s:'.basename($sp)]=basename($sp);}
$currentSource=(string)($batch['source_key']??'legacy');if(!isset($sourceChoices[$currentSource]))$currentSource='legacy';
$history=[];foreach(glob(REAL_ROOT.'/*/batch.json')?:[] as $p){try{$history[]=bi_read($p);}catch(Throwable $e){}}usort($history,fn($x,$y)=>strcmp($y['created'],$x['created']));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edutek — Add learning materials</title>
<style>
*{box-sizing:border-box}body{margin:0;font:16px system-ui,sans-serif;color:#173047;background:#f1f5f9}header,main{max-width:1320px;margin:auto;padding:22px}h1{margin:0 0 7px;font-size:29px}h2{font-size:21px;margin:0 0 12px}h3{font-size:17px;margin:0 0 7px}p{line-height:1.5}button,input,select{font:inherit}button{padding:10px 14px;border:1px solid #9bafc2;border-radius:7px;background:white;cursor:pointer}button:hover{background:#e4edf6}button:disabled{opacity:.55;cursor:default}.primary{background:#155ea6;color:white;border-color:#155ea6}.primary:hover{background:#124d86}.layout{display:grid;grid-template-columns:270px 1fr;gap:20px}aside,.panel,.item{background:white;border:1px solid #d5e0eb;border-radius:10px;padding:17px;margin-bottom:14px}.item{display:grid;grid-template-columns:86px 1fr;gap:16px}.art{width:86px;height:112px;object-fit:contain;background:#edf2f7;border-radius:6px}.placeholder{display:flex;align-items:center;justify-content:center;color:#5c7287;font-size:12px}.muted{font-size:13px;color:#526a80;overflow-wrap:anywhere}.badge{display:inline-block;font-size:12px;padding:3px 7px;border-radius:5px;background:#e8f1fa;margin:3px}.warning{background:#fff4df;border:1px solid #e9c57d;padding:12px;border-radius:7px;margin:10px 0}.error{background:#ffe9e9;color:#8b1515;padding:16px;border:1px solid #d99;border-radius:8px}input[type=checkbox]{width:22px;height:22px;vertical-align:middle;margin-right:7px}input[type=text],input[type=search],select{max-width:100%;padding:8px;border:1px solid #9caec0;border-radius:5px}input[type=text]{width:100%}.select-label{display:inline-flex;align-items:center;gap:4px;margin:9px 0}.toolbar,.filters,.actions,.bulk{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:12px 0}.filters button.active,.collections button.active{background:#173f68;color:white}.collections{max-height:340px;overflow:auto}.collections button{display:block;width:100%;text-align:left;margin:6px 0;font-size:14px}.history a{display:block;margin:12px 0;color:#17578f}.actions{position:sticky;bottom:0;background:#dfebf6;border:1px solid #afc5da;border-radius:8px;padding:14px;z-index:2}.step{font-size:13px;letter-spacing:.3px;color:#365b80}.name-pair{font-size:14px;overflow-wrap:anywhere}details{margin:10px 0;font-size:14px}summary{cursor:pointer;color:#245d90}.tiny-art{width:50px;height:65px;object-fit:contain}.choices{display:flex;gap:10px;flex-wrap:wrap}.notice{padding:10px;background:#e8f3ed;border-radius:6px}.selected{border-color:#4088c8;box-shadow:0 0 0 1px #4088c8}.report{overflow-wrap:anywhere;font-size:13px}.collection-heading{margin:10px 0;font-size:19px}[hidden]{display:none!important}aside .filters{flex-direction:column;align-items:stretch;margin:6px 0 18px}aside .filters button{text-align:left}.dup-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:12px 0}.dup-col{border:1px solid #d5e0eb;border-radius:8px;padding:12px;background:#fbfdff;overflow-wrap:anywhere}.dup-col.existing{background:#eaf5ec;border-color:#9fcaa6}.dup-col .art{width:120px;height:160px}.dup-col h4{margin:8px 0 6px;font-size:16px}.dup-tag{font-size:12px;font-weight:600;letter-spacing:.3px;color:#365b80;margin-bottom:8px}.dup-choice{margin:12px 0;border:1px solid #afc5da;border-radius:8px;padding:10px 14px}.dup-choice label{display:block;margin:8px 0}.dup-choice input{width:18px;height:18px;vertical-align:middle;margin-right:8px}.dup-col{cursor:pointer;position:relative}.dup-col:hover{border-color:#4088c8}.dup-col:focus{outline:2px solid #155ea6}.dup-col.winner{border:3px solid #2e8b57;background:#eef9f1}.dup-col.rejected{opacity:.55;border-style:dashed}.dup-col.replaced{opacity:.7;border-color:#c0392b;border-style:dashed}.dup-state{font-weight:700;font-size:13px;margin:4px 0 8px;color:#365b80}.dup-col.winner .dup-state{color:#1b6b3a}.dup-col.rejected .dup-state,.dup-col.replaced .dup-state{color:#a03030}.tree{list-style:none;margin:8px 0;padding:0}.tree ul{list-style:none;margin:4px 0 4px 10px;padding-left:20px;border-left:2px solid #c7d6e5}.tree li{margin:7px 0}.tree .folder{font-weight:700;color:#173f68}.tree li.file{display:flex;gap:10px;align-items:flex-start}.tree li.file img{width:42px;height:56px;object-fit:contain;background:#edf2f7;border-radius:4px}.checks{margin:8px 0 14px;padding-left:20px}.checks li{margin:4px 0}#dup-chips button.active{background:#173f68;color:#fff}#choose-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}#choose-row button{padding:8px 10px;font-size:14px;white-space:nowrap}#choose-row input[type=search]{flex:1 1 150px;min-width:130px;max-width:220px}#more-select{margin:10px 0 0;font-size:14px}#more-select .toolbar{margin:8px 0}@media(max-width:800px){.layout{display:block}aside .collections{max-height:180px}.item{grid-template-columns:65px 1fr;gap:10px}.art{width:65px;height:90px}.actions{position:static}header,main{padding:14px}}
</style></head><body><header><h1>Add learning materials</h1><div>1. Choose materials → 2. Fix anything that needs attention → 3. Add to test library</div><p class="muted">Source: <?=h($batch['source']??'choose an import source in the sidebar')?>. Planned Edutek root: D:\xampp\htdocs\Edutek\videos. Test copies remain separate; nothing is added until you approve.</p></header><main>
<?php if($error):?><p class="error">Could not finish: <?=h($error)?>. Your source files have not been changed.</p><?php endif;?>
<?php $primary=$batch?learning($batch['rows']):[];$editable=$batch&&in_array($batch['state'],['draft','approved'],true);$groups=[];foreach($primary as $rid=>$r)$groups[$r['group']][$rid]=$r;ksort($groups);?>
<div class="layout"><aside><form method="post"><?php hidden_fields($csrf);?><h2>Import source</h2><select name="source_key" aria-label="Import source" style="width:100%;margin-bottom:10px"><?php foreach($sourceChoices as $skey=>$slabel):?><option value="<?=h($skey)?>" <?=$skey===$currentSource?'selected':''?>><?=h($slabel)?></option><?php endforeach;?></select><button class="primary" name="action" value="scan">Start new import</button></form><p class="muted">Scans the connected folder. Does not copy materials into the test library.</p>
<?php if($batch&&$editable):?><h2>Content type</h2><div class="filters"><?php foreach(['All content','Books','Videos','Music','Audiobooks'] as $type):?><button type="button" data-filter="<?=h($type)?>"><?=h($type)?> (<?=$type==='All content'?count(array_filter($primary,'bi_in_pool')):count(array_filter($primary,fn($r)=>$r['type']===$type&&bi_in_pool($r)))?>)</button><?php endforeach;?></div><?php endif;?><?php if($batch&&$editable):?><h2>Needs review</h2><div class="filters"><button type="button" data-filter="Duplicates">Possible duplicates (<?=count(array_filter($primary,fn($r)=>!empty($r['candidate'])))?>)</button><button type="button" data-filter="Names">Title and filename issues (<?=count(array_filter($primary,fn($r)=>needs_fix($r)&&!hash_equals(fix_token($r),(string)($r['fix_accept']??''))))?>)</button></div><?php endif;?><?php if($batch&&$editable):?><h2>Recently imported</h2><div class="filters"><button type="button" data-filter="Imported">Recently imported (<?=count(array_filter($primary,fn($r)=>!empty($r['already_imported'])))?>)</button></div><?php endif;?><?php if($batch):?><h2>Source collections</h2><div class="collections"><button type="button" data-group="all">All collections (<?=count(array_filter($primary,'bi_in_pool'))?>)</button><?php foreach($groups as $group=>$rows):?><button type="button" data-group="<?=h($group)?>"><?=h(str_replace('/',' › ',$group))?> (<?=count(array_filter($rows,'bi_in_pool'))?>)</button><?php endforeach;?></div><?php endif;?>
<details class="history"><summary>Saved imports & history (<?=count($history)?>)</summary><?php foreach($history as $entry):?><a class="leave-page" href="?id=<?=h($entry['id'])?>"><?=h($entry['created'])?><br><?=h($entry['state'])?> · <?=count(learning($entry['rows']))?> materials</a><?php endforeach;?></details>
<p class="muted">This page has separate saved imports. Earlier demos and their history are unchanged.</p></aside><section>
<?php if(!$batch):?><div class="panel"><h2>Ready to look at your materials?</h2><p>Click Start new import to find books, videos and matching artwork in the Desktop folder.</p><p>We will keep the originals untouched. You can browse the full collection and add as many materials as you like.</p></div>
<?php else:$selected=array_filter($batch['rows'],fn($r)=>$r['include']);$selectedPrimary=learning($selected);$selectedArt=count($selected)-count($selectedPrimary);$selectedBytes=array_sum(array_column($selected,'bytes'));?>
<div class="panel"><div class="step">TEST LIBRARY ONLY · Import <?=h(substr($id,0,8))?></div><h2><?=count(array_filter($primary,'bi_in_pool'))?> materials ready to choose</h2><p class="muted"><?=count(array_filter($primary,fn($r)=>!bi_in_pool($r)))?> more are in Needs review or Recently imported.</p><p><?=count(array_filter($primary,fn($r)=>!empty($r['art'])))?> have attached artwork. Artwork is not counted as a separate material.</p><p class="muted">Browse all content and choose what to add. Large videos take longer to copy.</p><?php if($editable):?><?php if(!empty($batch['placement_notice'])):?><p class="notice">Updated folder rules are previewed below. Previous approval is cleared. Save progress or Review selection before adding anything. Nested book-source folders are suggested as one subcategory name joined with a dash; originals stay unchanged.</p><?php endif;?><span id="saved" class="notice">Saved on device. Use Save progress before leaving.</span><?php else:?><p class="notice">Status: <?=h($batch['state'])?></p><?php endif;?></div>
<?php if($editable):?>
<?php if($batch['state']==='approved'):?><div class="panel" id="approval"><div class="step">STEP 3 — FINAL CHECK</div><h2>Ready to add <?=count($selectedPrimary)?> materials</h2><p><?=count($selectedPrimary)?> materials + <?=$selectedArt?> artwork files · <?=number_format($selectedBytes/1048576,1)?> MiB</p><?php $cs=0;$cg=0;foreach($selected as $ar0){if(empty($ar0['support']))continue;if(!empty($ar0['generated_key']))$cg++;else $cs++;}$cn=count($selectedPrimary)-$cs-$cg;$rn=0;foreach($selectedPrimary as $pr0){if(basename($pr0['target'])!==basename($pr0['source']))$rn++;}$dn=count(array_filter($selectedPrimary,fn($pr0)=>!empty($pr0['candidate'])||!empty($pr0['already_imported'])));$tree=[];foreach($selected as $trid=>$tr0){bi_tree_add($tree,explode('/',$tr0['target']),['row'=>$tr0,'rid'=>$trid]);}?>
<h3>Quick checks</h3><ul class="checks"><li>Covers: <?=$cs?> supplied, <?=$cg?> generated, <?=max(0,$cn)?> without a cover</li><li>Renamed files: <?=$rn?></li><li>Possible duplicates added on purpose: <?=$dn?></li></ul>
<h3>What will be added</h3><ul class="tree"><li><span class="folder">videos</span><?=bi_tree_html($tree,$id,$batch['rows'])?></li></ul><p class="muted">For now, test copies are saved on the Beelink in data\guided-v08\<?=h(substr($id,0,8))?>...\files. Nothing is written to the Edutek library or your USB.</p><?php $repl=array_filter($primary,fn($rr)=>($rr['dup_decision']??'')==='replace');$rej=array_filter($primary,fn($rr)=>($rr['dup_decision']??'')==='reject');?><?php if($repl):?><div class="warning"><h3>Replace choices recorded, not applied yet</h3><ul><?php foreach($repl as $rr):?><li><?=h($rr['title'])?> - nothing will be copied for this item in this import.</li><?php endforeach;?></ul></div><?php endif;?><?php if($rej):?><p class="muted"><?=count($rej)?> duplicate cop<?=count($rej)>1?'ies were':'y was'?> rejected and will not be added.</p><?php endif;?><p>Your original files stay unchanged. No existing files will be overwritten. You can inspect and reverse this import later.</p><form method="post" id="execute-form"><?php hidden_fields($csrf,$id);?><input type="hidden" name="revision" value="<?=h($batch['approval'])?>"><input type="hidden" name="confirm" value="ADD"><button class="primary" id="add-button" name="action" value="execute">Add <?=count($selectedPrimary)?> materials to test library</button></form><p class="muted" id="approval-note">Any edits below require a new Review selection before adding.</p><div id="copy-progress" hidden style="margin-top:14px"><div style="background:#dbe6f1;border-radius:8px;height:24px;overflow:hidden"><div id="copy-bar" style="background:#155ea6;height:100%;width:0%;transition:width .4s"></div></div><p id="copy-text" class="muted">Starting...</p></div></div><?php endif;?>
<?php if(!empty($batch['issues'])):?><div class="notice"><?php foreach($batch['issues'] as $issue)if($issue['kind']==='preview'):?><p>Creating preview: <?=h($batch['rows'][$issue['id']]['title'])?>. Wait for the image, then click Review selection again.</p><?php endif;?></div><?php endif;?>
<div class="panel" id="bulk-select"><div id="choose-row"><input type="search" id="search" placeholder="Find a title or collection" aria-label="Search materials"><button type="button" id="show-all">Show all</button><button type="button" id="select-page">Select this page</button><button type="button" id="clear-page">Clear this page</button></div><details id="more-select"><summary>Select all and unselect all options</summary><div class="toolbar"><button type="button" data-sel="all">Select all (<span class="n">0</span>)</button><button type="button" data-sel="Books">Select all books (<span class="n">0</span>)</button><button type="button" data-sel="Videos">Select all videos (<span class="n">0</span>)</button><button type="button" data-sel="Music">Select all music (<span class="n">0</span>)</button><button type="button" data-sel="Audiobooks">Select all audiobooks (<span class="n">0</span>)</button><button type="button" data-sel="none">Unselect all</button></div><div class="toolbar"><label><input type="checkbox" id="sel-include-review"> Also include Needs review items</label><label><input type="checkbox" id="sel-include-recent"> Also include Recently imported items</label></div></details><p class="muted" id="sel-status">By default the Select all buttons skip Needs review and Recently imported items. They only tick the boxes; nothing is added until you click Review selection.</p></div>
<h2 id="step-title"><?=($batch['screen']??'select')==='fix'?'Step 2 — Fix these items':'Step 1 — Choose materials'?></h2>
<p id="step-help"><?=($batch['screen']??'select')==='fix'?'Only selected items needing a decision are shown. Fix them or unselect them, then Review selection again.':'Select the books or videos you want to add. Their attached artwork follows automatically.'?></p>



<p class="muted" id="scope"></p><div class="warning" id="dup-help" hidden><h3>Possible duplicates</h3><p>These items have the same title, type and size as other content. Compare each one with the locations listed on its card. Click the winner (or winners) in each match below. Click only the original: nothing is done and the source copy is rejected. Click only the source copy: it replaces the original. Click both: both are kept. Nothing is deleted.</p></div><div class="notice" id="names-help" hidden>These items have a title or file name the page had to correct, for example reserved characters or a trailing period. Check the suggested name on each card and click Use suggested filename. Once accepted, the item moves back to the main list.</div><div class="notice" id="imp-help" hidden>These source files match something you already imported earlier, so they are set aside and are not part of the review. Tick one only if you really want to add it again.</div>
<div id="dup-compare" hidden>
<div id="dup-chips" class="toolbar"><button type="button" data-dupfilter="all">All matches (<span class="n">0</span>)</button><button type="button" data-dupfilter="device">Already on this device (<span class="n">0</span>)</button><button type="button" data-dupfilter="source">Within the source (<span class="n">0</span>)</button></div>
<?php $dupSets=[];foreach($primary as $drid=>$dr){if(empty($dr['candidate']))continue;$dk=$dr['type'].'|'.bi_dupkey((string)($dr['original_title']??$dr['title'])).'|'.$dr['bytes'];$dupSets[$dk]['members'][$drid]=$dr;if(!empty($dr['existing']))$dupSets[$dk]['existing']=$dr['existing'];}
uasort($dupSets,fn($a,$b)=>bi_dup_rank($a)<=>bi_dup_rank($b));$cd=0;$ce=0;$cs=0;foreach($dupSets as $s0){$hasdev=false;foreach(($s0['existing']??[]) as $e0)if(($e0['kind']??'earlier')==='device')$hasdev=true;if($hasdev)$cd++;elseif(!empty($s0['existing']))$ce++;if(count($s0['members'])>1)$cs++;}?>
<?php if($dupSets):?><div class="notice"><?=count($dupSets)?> matches: <?=$cd?> already on this device, <?=$cs?> duplicated within the source. A match can fall in more than one group. Device list built: <?=h($batch['device_index_built']??'')?:'never - run Build-DeviceIndex.ps1'?></div><?php endif;?>
<?php $gi=0;foreach($dupSets as $set):$gi++;$members=$set['members'];$exs=$set['existing']??[];$first=reset($members);?>
<section class="panel dup-set" data-members="<?=h(implode(',',array_keys($members)))?>" data-decided="0" data-kinds="<?=h(bi_dup_kinds($exs,count($members)>1))?>" data-has-orig="<?=$exs?'1':'0'?>">
<h3>Match <?=$gi?> of <?=count($dupSets)?>: <?=h($first['title'])?></h3><p><?=bi_dup_badges($exs,count($members)>1)?></p>
<div class="dup-cols">
<?php foreach($exs as $x):?><div class="dup-col existing" data-key="orig" tabindex="0" role="button" aria-pressed="false"><div class="dup-tag"><?=h(($x['kind']??'earlier')==='device'?'ALREADY ON THIS DEVICE':'ALREADY ADDED IN AN EARLIER IMPORT')?></div><div class="dup-state"></div><div class="art placeholder">EXISTING</div><h4><?=h($first['title'])?></h4><p class="muted"><?=h(bi_dup_where($x))?></p><p class="muted">This copy stays where it is whatever you choose.</p></div><?php endforeach;?>
<?php $ci=0;foreach($members as $mrid=>$m):$ci++;?>
<div class="dup-col" data-key="<?=h($mrid)?>" tabindex="0" role="button" aria-pressed="false"><div class="dup-tag">COPY <?=$ci?> (IN THE SOURCE)</div><div class="dup-state"></div>
<?php if(!empty($m['art'])):?><img class="art" alt="Artwork for copy <?=$ci?>" src="?id=<?=h($id)?>&amp;art=<?=h($mrid)?>"><?php else:?><div class="art placeholder">NO ARTWORK</div><?php endif;?>
<h4><?=h($m['title'])?></h4>
<p class="muted"><?=h($m['type']==='Books'?'Book':'Video')?> - <?=number_format($m['bytes']/1048576,1)?> MiB (<?=number_format($m['bytes'])?> bytes)</p>
<p class="muted">File date: <?=h(gmdate('Y-m-d H:i',(int)$m['mtime']))?> UTC</p>
<p class="muted">Found in: <?=h(str_replace('/',' > ',dirname($m['source'])))?></p>
<p class="muted">File name: <?=h(basename($m['source']))?></p>
<p class="muted">Would be added to: Edutek &gt; videos &gt; <?=h(str_replace('/',' > ',dirname($m['target'])))?></p>
</div>
<?php endforeach;?>
</div>
<?php $diff=[];if(count($members)>1){foreach($members as $m2){if(dirname($m2['source'])!==dirname($first['source']))$diff['folder']='different folders';if(basename($m2['source'])!==basename($first['source']))$diff['name']='different file names';if((int)$m2['mtime']!==(int)$first['mtime'])$diff['date']='different file dates';if(empty($m2['art'])!==empty($first['art']))$diff['art']='different artwork';}}?>
<p class="muted"><?php if(count($members)>1):?>Same title and size. <?=$diff?h('Differences: '.implode(', ',$diff).'.'):'No differences in the details shown.'?> Only an exact-content check can prove two files are identical.<?php else:?>Same title and size as the existing copy. Only an exact-content check can prove they are identical.<?php endif;?></p>
<p class="muted">Click the copy (or copies) you want to keep.</p><p class="dup-status notice">Not decided yet. Click the winner or winners.</p>
</section>
<?php endforeach;?>
<?php if(!$dupSets):?><p class="notice">No possible duplicates were found in this import.</p><?php endif;?>
</div>
<form method="post" id="editor"><?php hidden_fields($csrf,$id);?><input type="hidden" name="draft" id="draft-json"><input type="hidden" name="action" id="draft-action" value="save">
<?php
$categoryChoices=gp_config()['categories']??[];
foreach($primary as $pr)$categoryChoices[]=$pr['placement_category'];
foreach(['Books','Music','Audiobooks'] as $role)$categoryChoices[]=gp_role($role);
$categoryChoices=array_values(array_unique($categoryChoices));natcasesort($categoryChoices);
?>
<datalist id="category-options"><?php foreach($categoryChoices as $category):?><option value="<?=h($category)?>"><?php endforeach;?></datalist>
<details class="panel" id="bulk-placement-panel">
<summary>Change placement for several materials at once</summary>
<p class="muted">Final root: <code>D:\xampp\htdocs\Edutek\videos</code>. This edits the plan only; test copies remain separate.</p>
<label>Apply to <select id="placement-scope"><option value="collection">Current collection — all its materials and artwork</option><option value="category">Source category — this collection and all its sibling subfolders</option><option value="selected">Selected materials — across all pages and filters</option></select></label>
<p id="placement-scope-summary" class="muted"></p>
<label><input type="checkbox" id="change-category" checked> Change category</label>
<label>Category <input type="text" id="bulk-category" list="category-options" placeholder="Choose or enter a category"></label>
<label><input type="checkbox" id="keep-subfolders" checked> Keep each material’s current subfolder</label>
<label>New subfolder / book subcategory / album / audiobook folder <input type="text" id="bulk-folder" disabled placeholder="Used only when Keep current subfolder is unchecked"></label>
<label><input type="checkbox" id="replace-custom"> Also replace custom item locations</label>
<p class="muted">Books stay under Books with a required subcategory. Music uses an album folder; audiobooks use an audiobook folder. No materials are selected or imported by changing placement.</p>
<button type="button" id="preview-placement">Preview placement changes</button>
</details>
<dialog id="placement-dialog" style="width:min(1000px,94vw);max-height:85vh;border:1px solid #9bafc2;border-radius:10px">
<h2>Review collection placement changes</h2><p id="placement-summary"></p>
<p class="muted">Root for all planned destinations: D:\xampp\htdocs\Edutek\videos</p>
<div style="max-height:45vh;overflow:auto"><table style="width:100%;font-size:14px;border-collapse:collapse"><thead><tr><th style="text-align:left">Material</th><th style="text-align:left">Current folder</th><th style="text-align:left">Proposed folder</th></tr></thead><tbody id="placement-changes"></tbody></table></div>
<p>No media will be copied. Selection, titles and artwork links stay unchanged.</p>
<div class="toolbar"><button type="button" id="cancel-placement">Cancel</button><button type="button" class="primary" id="apply-placement">Apply these placement changes</button></div>
</dialog>

<?php foreach($groups as $group=>$rows):?><div class="collection-heading" data-heading="<?=h($group)?>"><?=h(str_replace('/',' › ',$group))?></div>
<?php foreach($rows as $rid=>$r):$fix=needs_fix($r);$fixAccepted=$fix&&hash_equals(fix_token($r),(string)($r['fix_accept']??''));$pending=false;foreach($batch['issues']??[] as $issue)if($issue['id']===$rid)$pending=true;?>
<article class="item" data-category="<?=h($r['placement_category'])?>" data-folder="<?=h($r['placement_folder'])?>" data-placement-override="<?=!empty($r['placement_override'])?'1':'0'?>" data-source-category="<?=h(explode('/',$r['source'])[0])?>" data-file="<?=h(basename($r['target']))?>" data-id="<?=h($rid)?>" data-group="<?=h($group)?>" data-type="<?=h($r['type'])?>" data-pending="<?=$pending?'1':'0'?>" data-fix="<?=h($r['fix_accept']??'')?>" data-token="<?=h(fix_token($r))?>" data-duplicate="<?=!empty($r['duplicate_add'])?'1':'0'?>" data-candidate="<?=!empty($r['candidate'])?'1':'0'?>" data-imported="<?=!empty($r['already_imported'])?'1':'0'?>" data-needfix="<?=$fix?'1':'0'?>" data-bytes="<?=(int)$r['bytes']?>" data-dupkind="<?=h(bi_dup_kinds($r['existing']??[],!empty($r['dup_source'])))?>" data-dup-decision="<?=h($r['dup_decision']??'')?>" data-search="<?=h(mb_strtolower($r['title'].' '.$group))?>">
<div><?php if(!empty($r['art'])):?><img class="art" loading="lazy" alt="Artwork for <?=h($r['title'])?>" src="?id=<?=h($id)?>&amp;art=<?=h($rid)?>"><?php else:?><?php if(in_array($r['type'],['Videos','Books'],true)&&($r['art_count']??0)===0):?><div class="art placeholder generated-preview" data-preview-id="<?=h($rid)?>"><?=h($r['type']==='Books'?'BOOK':'VIDEO')?></div><?php else:?><div class="art placeholder"><?=h($r['type']==='Books'?'BOOK':'VIDEO')?></div><?php endif;?><?php endif;?></div><div><h3 class="display-title"><?=h($r['title'])?></h3><span class="badge"><?=h($r['type']==='Books'?'Book':'Video')?></span><span class="badge"><?=number_format($r['bytes']/1048576,1)?> MiB</span><span class="badge dup-badge" hidden></span><?php if(!empty($r['already_imported'])):?><span class="badge" style="background:#e2e8f0"><?=h(($r['recent_kind']??'')==='same'?'RECENTLY IMPORTED - SAME FILE':'RECENTLY IMPORTED - SIMILAR FILE')?><?=!empty($r['recent_where']['created'])?' ('.h(substr((string)$r['recent_where']['created'],0,10)).')':''?></span><?php endif;?><span class="muted"><span class="art-status"><?=!empty($r['art'])?(!empty($batch['rows'][$r['art']]['generated_key'])?'Generated preview':'Artwork attached'):(($r['art_count']??0)>1?'Choose artwork':($r['type']==='Videos'?'Preview will be generated':'Cover will be generated from page 1'))?></span></span><br>
<p class="muted">Final location: <span class="placement-label">Edutek › videos › <?=h(str_replace('/',' › ',dirname($r['target'])))?></span> <span class="custom-placement"><?=!empty($r['placement_override'])?'(custom location)':''?></span></p><label class="select-label"><input type="checkbox" data-role="include" <?=$r['include']?'checked':''?>> Add this <?=$r['type']==='Books'?'book':'video'?></label>

<details class="individual-placement"><summary>Change this item’s placement</summary><label>Category <input type="text" data-role="category" list="category-options" value="<?=h($r['placement_category'])?>"></label><label><?=h($r['type']==='Books'?'Book subcategory (required)':'Subfolder (optional)')?> <input type="text" data-role="folder" value="<?=h($r['placement_folder'])?>"></label><p class="muted">Changing these marks this item as a custom location, protected from later collection changes unless you choose to replace custom locations.</p></details><details><summary>Change displayed title</summary><label>Title<input type="text" data-role="title" value="<?=h($r['title'])?>" maxlength="300"></label><p class="muted">We generate a safe filename and keep the artwork linked. Your original stays unchanged. Save progress to preview a changed filename.</p></details>
<?php if($fix):?><div class="warning decision name-decision"><h3>Filename needs a correction</h3><div class="name-pair">Original: <?=h($r['source'])?><br>Suggested: <?=h($r['target'])?></div><p class="muted">Reserved characters, trailing spaces/periods or Unicode normalization require a safer filename/folder. The displayed title can keep readable punctuation.</p><button type="button" class="accept-fix" <?=$fixAccepted?'disabled':''?>><?=$fixAccepted?'Suggested filename accepted':'Use suggested filename'?></button></div><?php endif;?>
<?php if(!empty($r['candidate'])):?><div class="warning decision"><h3>Possible duplicate</h3><p>Another file has the same title, type and size. This does not prove they are identical. Compare the locations below before deciding.</p><p><?=bi_dup_badges($r['existing']??[],!empty($r['dup_source']))?></p><?php foreach($r['existing']??[] as $ex):?><p class="muted"><?=h(bi_dup_where($ex))?></p><?php endforeach;?><ul><?php foreach($primary as $otherId=>$other)if($otherId!==$rid&&$other['type']===$r['type']&&$other['bytes']===$r['bytes']&&bi_dupkey((string)($other['original_title']??pathinfo($other['source'],PATHINFO_FILENAME)))===bi_dupkey((string)($r['original_title']??pathinfo($r['source'],PATHINFO_FILENAME)))):?><li class="muted"><?=h($other['source'])?></li><?php endif;?></ul><button type="button" class="accept-duplicate" <?=!empty($r['duplicate_add'])?'disabled':''?>><?=!empty($r['duplicate_add'])?'Adding this copy confirmed':'Add this copy anyway'?></button><p class="muted">Or unselect this material above. No originals will be removed.</p></div><?php endif;?>
<?php if(!empty($r['already_imported'])):?><div class="warning decision"><h3>Already imported earlier</h3><p><?=h(($r['recent_kind']??'')==='same'?'This exact file was imported before.':'A similar file (same title and size) was imported before.')?> Adding it again creates another copy.</p><button type="button" class="accept-duplicate" <?=!empty($r['duplicate_add'])?'disabled':''?>><?=!empty($r['duplicate_add'])?'Adding this copy confirmed':'Add this copy anyway'?></button><p class="muted">Or untick it above to skip it.</p></div><?php endif;?>
<?php if(($r['art_count']??0)>1):?><div class="warning decision"><h3>Choose artwork</h3><div class="choices"><?php foreach($r['art_options'] as $i=>$image):?><div><img class="tiny-art" loading="lazy" alt="Artwork choice <?=$i+1?>" src="?id=<?=h($id)?>&amp;art=<?=h($rid)?>&amp;choice=<?=$i?>"><div class="muted">Choice <?=$i+1?> · <?=h($image['extension'])?></div></div><?php endforeach;?></div><label>Use <select data-role="art-choice"><option value="">Choose an image…</option><?php foreach($r['art_options'] as $i=>$image):?><option value="<?=$i?>" <?=($r['art_choice']??'')===(string)$i?'selected':''?>>Choice <?=$i+1?> (<?=h($image['extension'])?>)</option><?php endforeach;?><option value="none" <?=($r['art_choice']??'')==='none'?'selected':''?>>Continue without artwork</option></select></label></div><?php endif;?>
<details>
<summary>Where the files are located</summary>
<?php
$sourceFolder = dirname($r['source']);
$targetFolder = dirname($r['target']);
$sourceWindows = str_replace('/', chr(92),
    ($batch['win_base']??'C:/Users/Edutek/Desktop/Videos/').$r['source']);
$finalWindows = str_replace('/', chr(92), GP_ROOT.'/'.$r['target']);
$targetWindows = str_replace('/', chr(92),
    'D:/edutek-system/bulk-loader-fresh-v05/data/guided-v08/'.$id.'/files/'.$r['target']);
?>
<div style="background:#f1f5f9;padding:12px;border-radius:7px;margin:10px 0">
<div>Original — stays unchanged</div>
<p style="margin:6px 0;overflow-wrap:anywhere">
<?php if(($batch['source_key']??'legacy')==='legacy'):?>Desktop &rsaquo; Videos<?php else:?>Import Sources &rsaquo; <?=h(substr((string)$batch['source_key'],2))?><?php endif;?><?php if($sourceFolder!=='.'):?>
 &rsaquo; <?=h(str_replace('/', ' › ', $sourceFolder))?>
<?php endif;?>
</p>
<div><?=h(basename($r['source']))?></div>
</div>
<div style="background:#e8f3ed;padding:12px;border-radius:7px;margin:10px 0">
<div>Final Edutek destination — planned, not yet copied there</div>
<p style="margin:6px 0;overflow-wrap:anywhere">
Edutek &rsaquo; videos
<?php if($targetFolder!=='.'):?>
 &rsaquo; <?=h(str_replace('/', ' › ', $targetFolder))?>
<?php endif;?>
</p>
<div><?=h(basename($r['target']))?></div>
</div>
<p class="muted">The demo writes only to separate test storage. After editing a title or placement, Save progress refreshes the exact paths.</p>
<details>
<summary>Show full Windows paths</summary>
<p>Original:</p>
<code style="display:block;overflow-wrap:anywhere;white-space:normal;user-select:all"><?=h($sourceWindows)?></code>
<p>Final Edutek path — planned:</p><code style="display:block;overflow-wrap:anywhere;white-space:normal;user-select:all"><?=h($finalWindows)?></code><p>Actual test-copy path:</p>
<code style="display:block;overflow-wrap:anywhere;white-space:normal;user-select:all"><?=h($targetWindows)?></code>
</details>
</details>
</div></article>
<?php endforeach;endforeach;?>
<p id="empty" hidden>No materials match this view. Your selections are unchanged. Use Show all materials to reset the view.</p>
<div class="toolbar"><button type="button" id="previous">Previous</button><span id="page-label"></span><button type="button" id="next">Next</button></div>
<div class="actions"><span id="selection-count"></span><button type="submit" data-action="save">Save progress</button><button type="submit" class="primary" data-action="preview">Review selection →</button></div></form>
<noscript><p class="error">This demo needs JavaScript enabled to save selections safely.</p></noscript>
<?php else:?>
<div class="panel"><h2><?=h($batch['state']==='complete'?'Materials added to the test library':($batch['state']==='reversed'?'Import reversed':'Import needs attention'))?></h2>
<?php foreach($selectedPrimary as $rid=>$r):$op=$batch['operations'][$rid]??null;?><p><?=h($r['title'])?> — <?=h($op['state']??'not copied')?><?php if(($op['state']??'')==='copied'):?> · <a target="_blank" rel="noopener" href="?id=<?=h($id)?>&amp;open=<?=h($rid)?>">Open test-library copy</a><?php endif;?></p><?php endforeach;?></div>
<?php if(in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)):?><div class="panel"><h2>Reverse this import</h2><p>This checks the entire batch: <?=count($batch['operations'])?> recorded file operations, including artwork. Unchanged verified copies move to recovery storage. Changed or uncertain files are kept and reported.</p><p>Your Desktop source and all earlier imports stay untouched. Empty test folders remain.</p><form method="post" id="reverse-form"><?php hidden_fields($csrf,$id);?><input type="hidden" name="confirm" value="REVERSE"><button name="action" value="reverse">Check & reverse this entire import</button></form></div><?php endif;?>
<?php endif;?>
<?php if(!empty($batch['conflicts'])):?><div class="error"><?=h(implode(' | ',$batch['conflicts']))?></div><?php endif;?>
<?php if(!empty($batch['operations'])):?><details class="panel"><summary>File-operation report — includes artwork</summary><?php foreach($batch['operations'] as $op):?><p class="report"><?=h($op['target'])?> — <?=h($op['state'])?></p><?php endforeach;?></details><?php endif;?>
<?php if(!empty($batch['events'])):?><details class="panel" open><summary>Saved activity</summary><?php foreach($batch['events'] as $event):?><p class="muted"><?=h($event)?></p><?php endforeach;?></details><?php endif;?>
<?php endif;?></section></div></main>
<script>
(()=>{
const editor=document.getElementById('editor');if(!editor)return;
const items=[...document.querySelectorAll('.item')],key='edutek-guided-view-'+<?=json_encode($id,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
let view={group:'all',type:'All content',search:'',page:0},dirty=false,submitting=false,fixMode=<?=json_encode(($batch['screen']??'select')==='fix')?>;
try{view={...view,...JSON.parse(localStorage.getItem(key)||'{}')}}catch(e){}
if(fixMode)view={group:'all',type:'All content',search:'',page:0};
const get=(r,role)=>r.querySelector('[data-role="'+role+'"]');
function persist(){try{localStorage.setItem(key,JSON.stringify(view))}catch(e){}}
function changed(){dirty=true;document.getElementById('saved').textContent='Changes not saved — use Save progress or Review selection';const add=document.getElementById('add-button');if(add){add.disabled=true;document.getElementById('approval-note').textContent='Selection changed. Use Review selection again.'}}
function render(){
 const match=items.filter(r=>(view.group==='all'||r.dataset.group===view.group)&&(view.type==='Duplicates'?(r.dataset.candidate==='1'&&dupKindOk(r)):view.type==='Names'?isNameIssue(r):view.type==='Imported'?r.dataset.imported==='1':((fixMode||(r.dataset.candidate!=='1'&&r.dataset.imported!=='1'&&!isNameIssue(r)))&&(view.type==='All content'||r.dataset.type===view.type)))&&r.dataset.search.includes(view.search.toLowerCase())&&(!fixMode||r.dataset.pending==='1'));
 const pages=Math.max(1,Math.ceil(match.length/12));view.page=Math.max(0,Math.min(view.page,pages-1));const shown=match.slice(view.page*12,view.page*12+12);const visible=new Set(shown);
 items.forEach(r=>{r.hidden=!visible.has(r);const checked=get(r,'include').checked;r.classList.toggle('selected',checked);r.querySelectorAll('.decision').forEach(d=>d.hidden=!(checked||(view.type==='Names'&&d.classList.contains('name-decision'))))});
 document.querySelectorAll('[data-heading]').forEach(h=>h.hidden=!shown.some(r=>r.dataset.group===h.dataset.heading));
 const selected=items.filter(r=>get(r,'include').checked),hidden=selected.filter(r=>!visible.has(r));
 document.getElementById('selection-count').textContent=selected.length+' selected in total'+(hidden.length?' · '+hidden.length+' outside this page':'');
 document.getElementById('dup-help').hidden=view.type!=='Duplicates';document.getElementById('names-help').hidden=view.type!=='Names';document.getElementById('imp-help').hidden=view.type!=='Imported';document.getElementById('scope').textContent='Showing '+shown.length+' of '+match.length+' matching materials. Page actions affect only these '+shown.length+' visible materials—not hidden selections.';
 document.getElementById('page-label').textContent='Page '+(view.page+1)+' of '+pages;
 document.getElementById('previous').disabled=view.page===0;document.getElementById('next').disabled=view.page+1>=pages;document.getElementById('empty').hidden=match.length!==0;
 document.querySelectorAll('[data-group]').forEach(b=>{if(b.tagName==='BUTTON')b.classList.toggle('active',b.dataset.group===view.group)});
 document.querySelectorAll('[data-filter]').forEach(b=>b.classList.toggle('active',b.dataset.filter===view.type));persist();document.dispatchEvent(new Event('edutek-preview-view'));
}
document.getElementById('search').value=view.search;
document.querySelectorAll('button[data-group]').forEach(b=>b.onclick=()=>{view.group=b.dataset.group;view.page=0;fixMode=false;render()});
document.querySelectorAll('[data-filter]').forEach(b=>b.onclick=()=>{view.type=b.dataset.filter;view.page=0;render()});
document.getElementById('search').oninput=e=>{view.search=e.target.value;view.page=0;render()};
document.getElementById('show-all').onclick=()=>{view={group:'all',type:'All content',search:'',page:0};fixMode=false;document.getElementById('search').value='';document.getElementById('step-title').textContent='Step 1 — Choose materials';render()};
function visible(){return items.filter(r=>!r.hidden)}
function selPool(type,wide){
 const incR=wide&&document.getElementById('sel-include-review').checked,incI=wide&&document.getElementById('sel-include-recent').checked;
 return items.filter(r=>(type==='all'||r.dataset.type===type)&&(incR||(r.dataset.candidate!=='1'&&!(typeof isNameIssue==='function'&&isNameIssue(r))))&&(incI||r.dataset.imported!=='1'));
}
function selCounts(){document.querySelectorAll('[data-sel]').forEach(b=>{const k=b.dataset.sel,n=b.querySelector('.n');if(n&&k!=='none')n.textContent=selPool(k,true).filter(r=>!get(r,'include').checked).length})}
document.querySelectorAll('[data-sel]').forEach(b=>{b.onclick=()=>{
 const k=b.dataset.sel,st=document.getElementById('sel-status');
 if(k==='none'){const ts=selPool('all',false).filter(r=>get(r,'include').checked);ts.forEach(r=>{get(r,'include').checked=false});changed();render();st.textContent='Unselected '+ts.length+' items. Items in Needs review and Recently imported were not touched.';return}
 const ts=selPool(k,true),add=ts.filter(r=>!get(r,'include').checked),all=items.filter(r=>k==='all'||r.dataset.type===k),skipped=all.length-ts.length;
 const rec=add.filter(r=>r.dataset.imported==='1');if(rec.length&&!confirm(rec.length+' of these were already imported earlier. Add them again anyway?'))return;
 add.forEach(r=>{get(r,'include').checked=true});changed();render();
 st.textContent='Ticked '+add.length+' items'+(skipped>0?'; skipped '+skipped+' in Needs review or Recently imported':'')+'. Nothing is added until you click Review selection.';
}});
document.addEventListener('edutek-preview-view',selCounts);
function isNameIssue(r){return r.dataset.needfix==='1'&&r.dataset.fix!==r.dataset.token}
function dupKindOk(r){const f=view.dupFilter||'all';return f==='all'||(r.dataset.dupkind||'').split(' ').includes(f)}
function dupFilterApply(){
 const f=view.dupFilter||'all',cnt={all:0,device:0,earlier:0,source:0};
 document.querySelectorAll('.dup-set').forEach(p=>{const ks=(p.dataset.kinds||'').split(' ');cnt.all++;ks.forEach(k=>{if(k in cnt)cnt[k]++});p.hidden=!(f==='all'||ks.includes(f))});
 document.querySelectorAll('[data-dupfilter]').forEach(b=>{const k=b.dataset.dupfilter,n=b.querySelector('.n');if(n)n.textContent=cnt[k];b.classList.toggle('active',k===f)});
}
document.querySelectorAll('[data-dupfilter]').forEach(b=>{b.onclick=()=>{view.dupFilter=b.dataset.dupfilter;view.page=0;render()}});
document.addEventListener('edutek-preview-view',dupFilterApply);
document.getElementById('previous').onclick=()=>{view.page=Math.max(0,view.page-1);render();document.getElementById('step-title').scrollIntoView({behavior:'smooth'})};
document.getElementById('next').onclick=()=>{view.page=view.page+1;render();document.getElementById('step-title').scrollIntoView({behavior:'smooth'})};
const dupPanels=[...document.querySelectorAll('.dup-set')];
const dupWin=new Map();
function dupMembers(p){return p.dataset.members.split(',').map(id=>items.find(r=>r.dataset.id===id)).filter(Boolean)}
function dupCompute(p){const hasOrig=p.dataset.hasOrig==='1',W=dupWin.get(p)||new Set(),res={};dupMembers(p).forEach(r=>{const id=r.dataset.id;if(W.size===0){res[id]='';return}res[id]=W.has(id)?((hasOrig&&!W.has('orig'))?'replace':'add'):'reject'});return res}
function dupToggle(p,key){
 const hasOrig=p.dataset.hasOrig==='1',W=dupWin.get(p)||new Set();
 if(W.has(key)){W.delete(key);if(key==='orig'&&hasOrig){const s=[...W];W.clear();if(s.length)W.add(s[0])}}
 else{if(key!=='orig'&&hasOrig&&!W.has('orig')){[...W].forEach(k=>{if(k!=='orig')W.delete(k)})}W.add(key)}
 dupWin.set(p,W);dupApply(p);
}
function dupApply(p){
 const res=dupCompute(p),W=dupWin.get(p)||new Set();
 dupMembers(p).forEach(r=>{const d=res[r.dataset.id]||'',add=d==='add';r.dataset.dupDecision=d;get(r,'include').checked=add;r.dataset.duplicate=add?'1':'0';const b=r.querySelector('.accept-duplicate');if(b){b.disabled=add;b.textContent=add?'Adding this copy confirmed':'Add this copy anyway'}});
 p.dataset.decided=W.size?'1':'0';changed();render();
}
function dupPaint(){dupPanels.forEach(p=>{
 const hasOrig=p.dataset.hasOrig==='1',W=dupWin.get(p)||new Set(),res=dupCompute(p),ms=dupMembers(p);
 p.querySelectorAll('.dup-col').forEach(c=>{const key=c.dataset.key,st=c.querySelector('.dup-state');let txt='Click to choose as a winner',cls='';
  if(key==='orig'){if(W.has('orig')){txt='WINNER - the original stays';cls='winner'}else if(W.size){txt='REPLACED - not applied yet';cls='replaced'}}
  else{const d=res[key]||'';if(d==='add'){txt=hasOrig?'WINNER - added as another copy':'WINNER - will be added';cls='winner'}else if(d==='replace'){txt='WINNER - replaces the original (not applied yet)';cls='winner'}else if(d==='reject'){txt='REJECTED';cls='rejected'}}
  c.classList.remove('winner','rejected','replaced');if(cls)c.classList.add(cls);c.setAttribute('aria-pressed',cls==='winner'?'true':'false');if(st)st.textContent=txt});
 let msg='Not decided yet. Click the winner or winners.';
 if(W.size){const srcW=ms.filter(r=>W.has(r.dataset.id)).length;
  if(hasOrig&&W.has('orig')&&srcW===0)msg='The original is kept and nothing is done. The source copy is rejected.';
  else if(hasOrig&&W.has('orig'))msg='The original is kept and '+srcW+' source cop'+(srcW>1?'ies are':'y is')+' added as extra cop'+(srcW>1?'ies':'y')+'. The others are rejected.';
  else if(hasOrig)msg='The source copy will replace the original. This choice is recorded now; nothing is copied for it until the replace step is installed.';
  else msg=srcW+' cop'+(srcW>1?'ies':'y')+' will be added. The others are rejected.'}
 const s=p.querySelector('.dup-status');if(s)s.textContent=msg;
 ms.forEach(r=>{const bd=r.querySelector('.dup-badge');if(!bd)return;const d=r.dataset.dupDecision||'';bd.hidden=d==='';bd.textContent=d==='add'?'WILL BE ADDED':(d==='replace'?'REPLACES ORIGINAL (NOT APPLIED YET)':(d==='reject'?'REJECTED':''));bd.style.background=d==='reject'?'#f6d3d3':(d==='replace'?'#fde8c4':'#d4efdc')});
})}
dupPanels.forEach(p=>{
 const hasOrig=p.dataset.hasOrig==='1',ms=dupMembers(p),W=new Set();
 const adds=ms.filter(r=>r.dataset.dupDecision==='add'),reps=ms.filter(r=>r.dataset.dupDecision==='replace'),rejs=ms.filter(r=>r.dataset.dupDecision==='reject');
 if(reps.length)W.add(reps[0].dataset.id);else if(adds.length){adds.forEach(r=>W.add(r.dataset.id));if(hasOrig)W.add('orig')}else if(rejs.length&&hasOrig)W.add('orig');
 dupWin.set(p,W);p.dataset.decided=W.size?'1':'0';
 p.querySelectorAll('.dup-col').forEach(c=>{c.onclick=()=>dupToggle(p,c.dataset.key);c.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();dupToggle(p,c.dataset.key)}}});
});
document.addEventListener('edutek-preview-view',()=>{const c=document.getElementById('dup-compare');if(c)c.hidden=view.type!=='Duplicates';dupPaint()});
document.getElementById('select-page').onclick=()=>{visible().forEach(r=>get(r,'include').checked=true);changed();render()};
document.getElementById('clear-page').onclick=()=>{visible().forEach(r=>get(r,'include').checked=false);changed();render()};
function acceptFix(r){r.dataset.fix=r.dataset.token;const b=r.querySelector('.accept-fix');if(b){b.disabled=true;b.textContent='Suggested filename accepted'}}

items.forEach(r=>{get(r,'include').onchange=()=>{if(get(r,'include').checked&&r.dataset.imported==='1'&&!confirm('This file was already imported earlier. Add it again?')){get(r,'include').checked=false;return}changed();render()};get(r,'title').oninput=e=>{r.querySelector('.display-title').textContent=e.target.value;r.dataset.fix='';r.dataset.search=(e.target.value+' '+r.dataset.group).toLowerCase();const f=r.querySelector('.accept-fix');if(f){f.disabled=true;f.textContent='Save progress to refresh filename suggestion'}changed()};const fix=r.querySelector('.accept-fix');if(fix)fix.onclick=()=>{acceptFix(r);changed();render()};const dup=r.querySelector('.accept-duplicate');if(dup)dup.onclick=()=>{r.dataset.duplicate='1';dup.disabled=true;dup.textContent='Adding this copy confirmed';changed()};const art=get(r,'art-choice');if(art)art.onchange=changed;});
editor.addEventListener('submit',e=>{const action=e.submitter?.dataset.action||'save';document.getElementById('draft-action').value=action;const draft={};items.forEach(r=>{draft[r.dataset.id]={include:get(r,'include').checked,title:get(r,'title').value,fix_accept:r.dataset.fix||'',duplicate_add:r.dataset.duplicate==='1',art_choice:get(r,'art-choice')?.value||'',category:r.dataset.category,folder:r.dataset.folder,placement_override:r.dataset.placementOverride==='1',dup_decision:r.dataset.dupDecision||''}});document.getElementById('draft-json').value=JSON.stringify(draft);document.getElementById('saved').textContent='Saving…';submitting=true;});

const placementRoles=<?=json_encode(['Books'=>gp_role('Books'),'Music'=>gp_role('Music'),'Audiobooks'=>gp_role('Audiobooks')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const scopeSelect=document.getElementById('placement-scope'),categoryInput=document.getElementById('bulk-category'),folderInput=document.getElementById('bulk-folder'),changeCategory=document.getElementById('change-category'),keepFolders=document.getElementById('keep-subfolders'),replaceCustom=document.getElementById('replace-custom');
let placementSignature='',placementPending=[];
function placementScope(){
 if(scopeSelect.value==='selected')return items.filter(r=>get(r,'include').checked);
 if(view.group==='all')return [];
 if(scopeSelect.value==='collection')return items.filter(r=>r.dataset.group===view.group);
 const first=items.find(r=>r.dataset.group===view.group);return first?items.filter(r=>r.dataset.sourceCategory===first.dataset.sourceCategory):[];
}
function refreshPlacementScope(){
 const targets=placementScope(),hidden=targets.filter(r=>r.hidden).length,custom=targets.filter(r=>r.dataset.placementOverride==='1').length;
 document.getElementById('placement-scope-summary').textContent=targets.length?targets.length+' materials in this scope, including '+hidden+' outside the current page and '+custom+' custom locations. All attached artwork follows its material.':(scopeSelect.value==='selected'?'Select materials first.':'Choose a source collection on the left first.');
 const signature=scopeSelect.value+'|'+view.group;
 if(signature!==placementSignature){const cats=[...new Set(targets.map(r=>r.dataset.category))];categoryInput.value=cats.length===1?cats[0]:'';placementSignature=signature;}
 categoryInput.disabled=!changeCategory.checked;folderInput.disabled=keepFolders.checked;
}
function safePart(value){return value.length>0&&[...value].length<=120&&value===value.normalize('NFC')&&!/[<>:"/\\|?*\u0000-\u001f\u007f]/.test(value)&&!/[. ]$/.test(value)&&value.trim()===value&&!/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³]|CONIN\$|CONOUT\$)(\.|$)/i.test(value)&&value!=='.'&&value!=='..'}
function validatePlacement(type,category,folder){
 if(!safePart(category))throw new Error('Enter a safe category folder name—not a full path.');
 if(folder&&!folder.split('/').every(safePart))throw new Error('Enter safe subfolder names without reserved characters or leading/trailing slashes.');
 const role=placementRoles[type];
 if(role){if(category!==role)throw new Error(type+' must stay under '+role+'. Change its '+(type==='Books'?'subcategory':type==='Music'?'album folder':'audiobook folder')+' instead.');if(!folder||folder.includes('/'))throw new Error(type+' needs one '+(type==='Books'?'subcategory':type==='Music'?'album':'audiobook')+' folder name.');}
 else if(Object.values(placementRoles).includes(category))throw new Error('This selection includes videos. Choose a general category, not '+category+'. For mixed collections, use Selected materials to change one content type at a time.');
}
function syncLocation(row){
 row.querySelector('.placement-label').textContent='Edutek › videos › '+[row.dataset.category,row.dataset.folder].filter(Boolean).join(' › ').replaceAll('/',' › ');
 row.querySelector('.custom-placement').textContent=row.dataset.placementOverride==='1'?'(custom location)':'';
 get(row,'category').value=row.dataset.category;get(row,'folder').value=row.dataset.folder;
 row.dataset.search=(get(row,'title').value+' '+row.dataset.group+' '+row.dataset.category+' '+row.dataset.folder).toLowerCase();
}
const dialog=document.getElementById('placement-dialog');
document.getElementById('preview-placement').onclick=()=>{
 try{
  const all=placementScope();if(!all.length)throw new Error(scopeSelect.value==='selected'?'Select materials first.':'Choose a source collection on the left first.');
  const skipped=replaceCustom.checked?[]:all.filter(r=>r.dataset.placementOverride==='1');
  const eligible=all.filter(r=>!skipped.includes(r));placementPending=[];
  for(const row of eligible){const category=changeCategory.checked?categoryInput.value:row.dataset.category,folder=keepFolders.checked?row.dataset.folder:folderInput.value;validatePlacement(row.dataset.type,category,folder);if(category!==row.dataset.category||folder!==row.dataset.folder)placementPending.push({row,category,folder});}
  if(!placementPending.length)throw new Error('No locations would change.'+(skipped.length?' '+skipped.length+' custom locations were preserved.':''));
  document.getElementById('placement-summary').textContent=placementPending.length+' materials will change placement, including '+placementPending.filter(p=>p.row.hidden).length+' outside the current page. '+skipped.length+' custom locations will be preserved. Attached artwork follows automatically. Changes are not saved until Save progress or Review selection.';
  const body=document.getElementById('placement-changes');body.replaceChildren();for(const p of placementPending){const tr=document.createElement('tr');for(const value of [get(p.row,'title').value,[p.row.dataset.category,p.row.dataset.folder].filter(Boolean).join(' › '),[p.category,p.folder].filter(Boolean).join(' › ')]){const td=document.createElement('td');td.textContent=value;td.style.cssText='padding:9px;border-top:1px solid #ddd;overflow-wrap:anywhere';tr.append(td);}body.append(tr);}
  dialog.showModal();
 }catch(error){alert(error.message);}
};
document.getElementById('cancel-placement').onclick=()=>dialog.close();
document.getElementById('apply-placement').onclick=()=>{
 for(const p of placementPending){p.row.dataset.category=p.category;p.row.dataset.folder=p.folder;p.row.dataset.placementOverride=scopeSelect.value==='selected'?'1':'0';syncLocation(p.row);}
 dialog.close();changed();render();refreshPlacementScope();
};
items.forEach(row=>{for(const role of ['category','folder'])get(row,role).addEventListener('input',()=>{row.dataset.category=get(row,'category').value;row.dataset.folder=get(row,'folder').value;row.dataset.placementOverride='1';syncLocation(row);changed();refreshPlacementScope();});});
scopeSelect.addEventListener('change',()=>{placementSignature='';refreshPlacementScope();});for(const control of [changeCategory,keepFolders,replaceCustom])control.addEventListener('change',refreshPlacementScope);
editor.addEventListener('submit',event=>{try{for(const row of items)validatePlacement(row.dataset.type,row.dataset.category,row.dataset.folder);}catch(error){event.preventDefault();event.stopImmediatePropagation();alert(error.message+' Your edits are still on this page.');}},true);
document.addEventListener('edutek-preview-view',refreshPlacementScope);refreshPlacementScope();

function startProgress(){
 const f=document.getElementById('execute-form'),box=document.getElementById('copy-progress'),bar=document.getElementById('copy-bar'),txt=document.getElementById('copy-text');
 if(!f||!box)return;box.hidden=false;const t0=Date.now(),mib=n=>(n/1048576).toFixed(1);
 const csrfv=f.querySelector('[name=csrf]').value,bid=f.querySelector('[name=id]').value;
 const tick=()=>{
  const u=new URL(location.href);u.search='';u.searchParams.set('id',bid);u.searchParams.set('progress','1');u.searchParams.set('token',csrfv);
  const el=Math.round((Date.now()-t0)/1000);
  fetch(u,{credentials:'same-origin',cache:'no-store'}).then(r=>r.ok?r.json():null).then(info=>{
   if(!info||!info.progress){txt.textContent='Working... '+el+' s';return}
   const p=info.progress,done=p.base+p.file_done,pct=p.total?Math.min(100,done/p.total*100):0;
   bar.style.width=pct.toFixed(1)+'%';
   txt.textContent=(p.phase==='verifying'?'Checking: ':'Copying: ')+p.file+' - file '+Math.min(p.files_total,p.files_done+1)+' of '+p.files_total+' - '+mib(done)+' of '+mib(p.total)+' MiB ('+pct.toFixed(0)+'%) - '+el+' s';
  }).catch(()=>{});
 };
 setInterval(tick,1000);tick();
}
const execute=document.getElementById('execute-form');if(execute)execute.onsubmit=()=>{if(dirty){alert('Use Review selection again after making changes.');return false}if(!confirm('Add the materials in the final summary to the test library now? Originals will stay unchanged.'))return false;submitting=true;startProgress();document.getElementById('add-button').textContent='Adding… please wait';return true;};
window.addEventListener('beforeunload',e=>{if(dirty&&!submitting){e.preventDefault();e.returnValue=''}});render();
})();
const reverse=document.getElementById('reverse-form');if(reverse)reverse.onsubmit=()=>confirm('Check and reverse this entire import, including its artwork? Only unchanged verified copies will move to recovery.');

(()=>{
 const batchId=<?=json_encode($id,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,token=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const active=new Set(),done=new Set(),last=new Map();let busy=false;
 function candidates(){return [...document.querySelectorAll('.generated-preview[data-preview-id]')].filter(el=>!done.has(el.dataset.previewId)&&!active.has(el.dataset.previewId)&&!el.closest('.item')?.hidden).sort((a,b)=>(last.get(a.dataset.previewId)||0)-(last.get(b.dataset.previewId)||0))}
 function pump(){if(busy||document.hidden)return;const element=candidates()[0];if(!element)return;busy=true;const rid=element.dataset.previewId;active.add(rid);last.set(rid,Date.now());
  const card=element.closest('.item'),label=card?.querySelector('.art-status');if(label)label.textContent='Creating preview…';
  const url=new URL(location.href);url.search='';url.searchParams.set('id',batchId);url.searchParams.set('preview_status',rid);url.searchParams.set('token',token);
  fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}}).then(res=>{if(!res.ok)throw new Error('Preview request failed');return res.json()}).then(info=>{
   if(info.state==='ready'){
    const img=document.createElement('img');img.className='art';img.alt='Generated video preview';img.src=info.url;
    img.onload=()=>{element.replaceWith(img);if(label)label.textContent=(card&&card.dataset.type==='Books')?'Generated cover from page 1':info.seconds===5?'Generated preview · 5 seconds':'Generated preview · first available frame';};
    img.onerror=()=>{if(label)label.textContent='Preview unavailable';};done.add(rid);
   }else if(info.state==='failed'){done.add(rid);element.textContent=(card&&card.dataset.type==='Books')?'BOOK':'VIDEO';if(label)label.textContent='Preview unavailable — you can still add this video';}
   else {if(label)label.textContent=info.state==='working'?'Creating preview…':'Preview queued…';}
  }).catch(()=>{done.add(rid);if(label)label.textContent='Preview request failed — reload to retry';}).finally(()=>{active.delete(rid);busy=false});
 }
 setInterval(pump,1500);
 document.addEventListener('edutek-preview-view',pump);document.addEventListener('visibilitychange',pump);pump();
})();

</script></body></html>
