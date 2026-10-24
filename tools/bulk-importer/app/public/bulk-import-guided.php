<?php
/* Edutek sandbox importer. Never reads/writes /content or USB paths. Patch 4p (damaged or unreadable videos are left out like damaged audio; every file the importer does not take is listed with its reason; books get the shelf you choose, never one from the folder name; one check of new categories and shelves before publishing; an import summary with links to the Edutek site). Patch 4o audio (audio a browser can’t play is converted to MP3 at import; damaged audio is flagged, never ticked, never published). Patch 4o (keyboard-first 3-step screens on top of the classic page, which stays under Details; same forms, checks, publish and undo). Patch 4i (browser-ready videos, source must be chosen). Patch 4j (Browse is the only source picker; published imports at the bottom; tighter header). Patch 4k (categories listed from the library folders, New category, Unsorted never chosen automatically). Patch 4l (new categories shared by every item in the import and kept with it; Set category for selected items; confirm lists each new category once with its item count). Patch 4n (Recently imported means still in the Edutek library or on its way there: undone, blocked or failed publishes and library files that are gone no longer count). Patch 4m (pages without the item editor - start page, staged and published imports - load completely again; the old Desktop Videos source is optional). */
declare(strict_types=1);
const BI_ROOT = '/sandbox-data';
// Patch 4g: the ONE shared naming rule (edutek_safe_name v1); Publish-Batch.ps1 uses the same file through edutek-safe-name-cli.php.
require_once dirname(__DIR__).'/lib/edutek-safe-name.php';
function bi_json(string $path, array $data): void {
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if (file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false || !rename($tmp,$path)) {throw new RuntimeException('State write failed');}
}
function bi_read(string $path): array {return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
/* Independent sandbox access; no Edutek account dependency. */
// Patch 4i: the same file also runs as the detached video runner (php bulk-import-guided.php --media-runner <id>); CLI has no session or Host header.
if(PHP_SAPI!=='cli'){
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

/* Maintenance-key gate removed. Access is limited by the 127.0.0.1-only port binding and this Host check (DNS-rebinding guard). */
if (!in_array(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), ['localhost:8092', '127.0.0.1:8092'], true)) {
    http_response_code(421);
    exit('Open the importer at http://localhost:8092/bulk-import-guided.php');
}
}

const REAL_ROOT='/sandbox-data/guided-v08';
const SRC_NONE_MSG='Choose an import source first: choose Browse… and pick a folder, then Start new import';
require_once __DIR__.'/video-preview-lib.php';
function h($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function savebatch(array $b): void {bi_json(REAL_ROOT.'/'.$b['id'].'/batch.json',$b);}
function batchdir(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid batch');return REAL_ROOT.'/'.$id;}
function bi_valid_root(string $root): string {if(str_starts_with($root,HV_ROOT.'/'))return hv_valid_root($root);if($root!=='/import-source'&&!preg_match('#^/import-sources/(?!\.\.?$)[^/]+$#D',$root))throw new RuntimeException('Invalid source root');if(is_link($root))throw new RuntimeException('Source link rejected');return $root;}
function sourcefile(string $rel,string $root='/import-source'): string {
 if(str_contains($rel,'\\')||str_starts_with($rel,'/'))throw new RuntimeException('Invalid source');
 $p=bi_valid_root($root);foreach(explode('/',$rel) as $c){if($c===''||$c==='.'||$c==='..')throw new RuntimeException('Invalid source');$p.='/'.$c;if(is_link($p))throw new RuntimeException('Source link rejected');}
 if(str_starts_with($root,HV_ROOT.'/')&&hv_blocked(dirname($p))!==null)throw new RuntimeException('Source is in a protected folder');
 $real=realpath($p);if($real===false||!str_starts_with($real,$root.'/')||!is_file($real))throw new RuntimeException('Source unavailable');return $real;
}
/* Structural Windows-safety check (destfile, replace targets). New names come from the shared rule: sn_component / sn_filename. */
function cleancomponent(string $v): string {
 $v=Normalizer::normalize($v,Normalizer::FORM_C);if($v===false)throw new RuntimeException('Invalid Unicode');
 $v=preg_replace('/[<>:"\/\\\\|?*\x00-\x1f\x7f]/u','_',$v);
 $v=rtrim(trim($v),'. ');
 if($v===''||$v==='.'||$v==='..')$v='Untitled';
 if(preg_match('/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³]|CONIN\$|CONOUT\$)(?:\.|$)/iu',$v))$v='_'.$v;
 return $v;
}
function destfile(string $dir,string $rel): string {
 if(strlen($rel)>200||substr_count($rel,'/')<1)throw new RuntimeException('Destination path limit: 200 UTF-8 bytes; use collection/filename');
 $p=$dir.'/files';if(is_link($p))throw new RuntimeException('Destination link rejected');
 foreach(explode('/',$rel) as $c){if($c!==cleancomponent($c)||mb_strlen($c,'UTF-8')>120)throw new RuntimeException('Unsafe or overlong destination component');$p.='/'.$c;if(is_link($p))throw new RuntimeException('Destination link rejected');}
 return $p;
}

function bi_progress(string $phase,string $name,int $cur,int $curTotal,bool $force=false): void {
 static $last=0.0;$now=microtime(true);if(!$force&&$now-$last<0.4)return;$last=$now;
 $g=$GLOBALS['bi_prog']??null;if(!is_array($g))return;
 try{bi_json($g['dir'].'/progress.json',['phase'=>$phase,'file'=>$name,'file_done'=>$cur,'file_total'=>$curTotal,'base'=>$g['base'],'total'=>$g['total'],'files_done'=>$g['files_done'],'files_total'=>$g['files_total'],'updated'=>$now]+(array)($g['extra']??[]));}catch(Throwable $e){}
}
function attach_art(array &$batch): void {
 $extra=[];
 foreach($batch['rows'] as $rid=>&$r){
  $rootp=$r['src_root']??'/import-source';$p=sourcefile($r['source'],$rootp);$matches=[];
  foreach(new DirectoryIterator(dirname($p)) as $image){
   if($image->isDot()||$image->isLink()||!$image->isFile()||sn_junk($image->getFilename(),false,(int)$image->getSize())!=='')continue;
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
 if(($ex['kind']??'earlier')==='device')return 'On this device, '.($ex['lib']??'device library').': '.($w!==''?$w.' > ':'').($ex['file']??'').(!empty($ex['why'])?' ('.$ex['why'].')':'');
 return 'Staged in an earlier import'.(!empty($ex['created'])?' ('.$ex['created'].')':'').', planned for Edutek > videos'.($w!==''?' > '.$w:'');
}
function bi_dup_badges(array $existing,bool $srcSide): string {
 $dev=false;$ear=false;foreach($existing as $ex){if(($ex['kind']??'earlier')==='device')$dev=true;else $ear=true;}
 $o='';
 if($dev)$o.='<span class="badge" style="background:#f6d3d3">ALREADY ON THIS DEVICE</span>';
 if($ear)$o.='<span class="badge" style="background:#fde8c4">ALREADY STAGED IN AN EARLIER IMPORT</span>';
 if($srcSide)$o.='<span class="badge" style="background:#d6e4f2">DUPLICATE WITHIN THE SOURCE</span>';
 return $o;
}
function bi_dup_kinds(array $existing,bool $src): string {$k=[];foreach($existing as $e){$k[(($e['kind']??'earlier')==='device')?'device':'earlier']=true;}if($src)$k['source']=true;return implode(' ',array_keys($k));}
function bi_in_pool(array $r): bool {return empty($r['candidate'])&&empty($r['already_imported'])&&!(needs_fix($r)&&!hash_equals(fix_token($r),(string)($r['fix_accept']??'')));}
function bi_dup_rank(array $set): int {foreach(($set['existing']??[]) as $e)if(($e['kind']??'earlier')==='device')return 0;return empty($set['existing'])?2:1;}
function bi_dupkey(string $title): string {return keyname((string)preg_replace('/\s*\(\d+\)$/u','',sn_title(bi_tidy_title($title),false)));}
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
 $c=['meta'=>['present'=>false,'checked'=>time()],'size'=>[],'name'=>[],'files'=>[],'dirs'=>[]];$f=BI_ROOT.'/device-index.json';if(!is_file($f))return $c;
 $j=json_decode((string)@file_get_contents($f),true);if(!is_array($j)){$c['meta']+=['present'=>false,'unreadable'=>true];return $c;}
 $n=0;foreach(($j['items']??[]) as $it){if(!is_array($it)||($it['lib']??'')!=='production')continue;$p=(string)($it['path']??'');$e=dx_ext($p);if($e!=='pdf'&&$e!=='mp4')continue;$n++;
  $c['files'][$p]=[(int)($it['bytes']??-1),isset($it['mtime'])&&is_numeric($it['mtime'])?(int)$it['mtime']:null];$c['size'][$e.'|'.(int)($it['bytes']??-1)][]=$p;$k=dx_key($p);if(dx_len($k)>=DX_NAME_MIN)$c['name'][$e.'|'.$k][]=$p;
  // Patch 4g: also index the key of the library name after the shared rule ("A & B" is found by "A and B"), so the page flags at least all the publisher flags.
  if(sn_key_may_differ(basename($p))){try{$k2=sn_key($p);}catch(Throwable $x){$k2=$k;}if($k2!==$k&&dx_len($k2)>=DX_NAME_MIN)$c['name'][$e.'|'.$k2][]=$p;}
  for($dd=dirname($p);$dd!=='.'&&$dd!==''&&$dd!=='/';$dd=dirname($dd)){$dk=keyname($dd);if(isset($c['dirs'][$dk]))break;$c['dirs'][$dk]=$dd;}}
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
/* Patch 4b: side-by-side duplicate comparison. Every card in a match shows the same rows in the same order
   (title, file name, type, size, file date, location, outcome). Compared rows carry a SAME / SIMILAR / DIFFERENT marker
   (text, not only colour) against the reference card: the existing copy, or copy 1 when nothing exists yet.
   File names use the shared name key (dx_key) and also report an exact match. Device index v3 mtime is a FileTime. */
function rc_unix($t): ?int {if($t===null||!is_numeric($t))return null;$t=(int)$t;return $t>100000000000000?intdiv($t,10000000)-11644473600:$t;}
function rc_srcname(array $r): string {$root=(string)($r['src_root']??'/import-source');return $root==='/import-source'?'Desktop Videos folder':(str_starts_with($root,HV_ROOT.'/')?hv_win($root):basename($root));}
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
function pq_phrase(int $learn,int $rep): string {return $rep>0?'PUBLISH '.($learn-$rep).' NEW REPLACE '.$rep:'PUBLISH '.$learn;}
function dx_ago(int $s): string {if($s<60)return 'less than a minute';if($s<3600)return intdiv($s,60).' min';if($s<172800)return intdiv($s,3600).' h '.intdiv($s%3600,60).' min';return intdiv($s,86400).' days';}
function dx_age_html(array $b): string {
 $m=$b['device_index']??null;if(!is_array($m))return '';
 if(empty($m['present']))return '<p class="warning">'.(!empty($m['unreadable'])?'The Edutek library list (device-index.json) could not be read':'No Edutek library list (device-index.json) was found').', so this page could not check the library for duplicates. The publisher still checks the library before copying anything. The &ldquo;Edutek Import Publisher&rdquo; task rebuilds the list automatically.</p>';
 $age=$m['built_epoch']!==null?max(0,(int)$m['checked']-(int)$m['built_epoch']):null;
 $o='<p class="'.($age!==null&&$age<=86400?'notice':'warning').'">Edutek library list used for this check: built '.h($m['built']).' (Beelink time), '.($age===null?'age unknown (old list format)':h(dx_ago($age)).' before this check').', '.number_format((int)$m['count']).' files. It is rebuilt automatically after each publish and every 6 hours.'.($age!==null&&$age>86400?' It is more than a day old, so recent library changes may be missing; the publisher re-checks the library before copying.':'').'</p>';
 if((int)$m['error_count']>0)$o.='<p class="warning">The last library scan reported '.(int)$m['error_count'].' error(s), so some library files may be missing from this check (first: '.h($m['errors'][0]??'').'). The publisher re-scans the library before copying and stops if its own scan has errors.</p>';
 return $o;
}

const GP_ROOT='D:/xampp/htdocs/Edutek/videos';
const GP_RULES=3; // Patch 4f: rules v2 - a video with no subfolder goes into a subfolder named like its category (History/History/<file>).
                  // Patch 4g: rules v3 - names follow the shared rule edutek_safe_name v1 (lib/edutek-safe-name.php); junk is skipped for every source.
const GP_BOOKS_DEFAULT_FOLDER=''; const GP_NOSHELF='(choose a shelf)'; // Patch 4p: books get no automatic shelf; you choose one (4h used Books/Books). Patch 4h was: book subcategory used when the source has no nested folder (Books/Books; was Books/Unsorted in 4g). One-line setting.
                                       // Only newly scanned books get it: rows already planned keep their subcategory. GP_RULES is unchanged (3).
/* Patch 4k: categories are the top-level folders of the Edutek library, read live (read-only) through the /host mount, so the list always
   matches D:\xampp\htdocs\Edutek\videos. Only if that folder cannot be read does the page fall back to the saved planner-categories.json list plus
   the top-level folders in the library list (device-index.json), and it says so on the page. */
const GP_LIB_DIR='/host/d/xampp/htdocs/Edutek/videos'; // GP_ROOT as the importer container sees it (read-only)
const GP_NO_DEFAULT=['Unsorted'];                       // never chosen automatically (a test category); it can still be picked by hand while it exists
const GP_NOCAT='(choose a category)';                   // shown in the planned location until a category is chosen; such items cannot be added
function gp_lib_state(?string $dir=null): array {
 static $cache=[];$dir=$dir??GP_LIB_DIR;if(isset($cache[$dir]))return $cache[$dir];
 $names=[];$src='library';$h=is_dir($dir)?@opendir($dir):false;
 if($h){while(($e=readdir($h))!==false){if($e==='.'||$e==='..'||$e[0]==='.'||$e[0]==='$'||strcasecmp($e,'System Volume Information')===0)continue;$p=$dir.'/'.$e;if(is_link($p)||!is_dir($p))continue;$names[]=$e;}closedir($h);}
 else{$src='saved list';foreach((gp_config()['categories']??[]) as $c)if(is_string($c)&&$c!==''&&!str_contains($c,'/'))$names[]=$c;foreach((dx_index()['dirs']??[]) as $d)if(is_string($d)&&$d!==''&&!str_contains($d,'/'))$names[]=$d;}
 $u=[];foreach($names as $n){$k=keyname($n);if(!isset($u[$k]))$u[$k]=$n;}$names=array_values($u);usort($names,'strnatcasecmp');
 return $cache[$dir]=['names'=>$names,'source'=>$src,'dir'=>$dir];
}
function gp_lib_categories(?string $dir=null): array {return gp_lib_state($dir)['names'];}
/* An existing library category with this name: same letters ignoring case, or (unless $exact) the same name after the naming rule
   ("Sales Force (Needs Work)" finds "Sales Force  (Needs Work)"), when exactly one category matches. Returns the library spelling. */
function gp_category_match(string $v,bool $exact=false): ?string {
 $v=trim($v);if($v==='')return null;$k=keyname($v);$all=gp_lib_categories();
 foreach($all as $c)if(keyname($c)===$k)return $c;
 if($exact)return null;
 $ck=keyname(sn_component($v));$hit=[];foreach($all as $c)if(keyname(sn_component($c))===$ck)$hit[]=$c;
 return count($hit)===1?$hit[0]:null;
}
function gp_no_default(string $c): bool {foreach(GP_NO_DEFAULT as $n)if(keyname($n)===keyname($c))return true;return false;}
/* Patch 4k: the category chosen automatically when a folder is scanned (books, music and audiobooks are unchanged):
   - a file inside a subfolder of the chosen folder: the subfolder's name (the library spelling when it is an existing category; otherwise a new category with that name);
   - a file directly in the chosen folder: the existing library category with the same name as the chosen folder, if there is one; otherwise none;
   - never Unsorted. With no category the item says "Choose a category" and cannot be added until one is chosen. */
function gp_scan_place(array &$r,string $root): void {
 $r['root_file']=!str_contains((string)$r['source'],'/');
 if(gp_role((string)$r['type'])!=='')return;
 if($r['root_file']){$m=gp_category_match(basename($root));$cat=($m!==null&&!gp_no_default($m))?$m:'';$folder='';}
 else{$raw=explode('/',dirname((string)$r['source']));$first=array_shift($raw);$m=gp_category_match($first);$cat=$m??gp_clean_part($first,'');
  $pre=$cat.'/';$parts=[];foreach($raw as $p){$c=gp_clean_part($p,$pre);$parts[]=$c;$pre.=$c.'/';}$folder=implode('/',$parts);if(gp_no_default($cat))$cat='';}
 $r['auto_category']=$cat;$r['placement_category']=$cat;$r['placement_folder']=$folder;$r['placement_override']=false;
}
/* Patch 4k: the check behind New category… (the browser asks the page, so it is the same rule as saving). */
function gp_check_category(string $v): array {
 $v=trim($v);
 if($v==='')return ['ok'=>false,'message'=>'Type a name for the new category first.'];
 if(str_contains($v,'/')||str_contains($v,chr(92))){$s=sn_component(str_replace(['/',chr(92)],' ',$v));return ['ok'=>false,'message'=>'A category is one folder name, not a path: / and \ are not allowed. Suggested name: '.$s,'suggest'=>$s];}
 $nv=preg_replace('/[\s_-]+/u','',mb_strtolower($v));foreach(['Books','Music','Audiobooks'] as $t){$role=gp_role($t);if(keyname($v)===keyname($role)||keyname(sn_component($v))===keyname($role)||$nv===mb_strtolower($t)||($t==='Audiobooks'&&$nv==='audiobook'))return ['ok'=>false,'message'=>$role.' is reserved for '.strtolower($t).'. Choose another name for a video category.'];}
 $have=gp_category_match($v);
 if($have!==null){if(gp_no_default($have))return ['ok'=>true,'name'=>$have,'exists'=>true,'message'=>'“'.$have.'” is an existing library category (a test category), so it was chosen. No new folder.'];return ['ok'=>true,'name'=>$have,'exists'=>true,'message'=>'“'.$have.'” is already a library category, so it was chosen. No new folder.'];}
 $i=sn_info($v,false);$s=$i['clean'];
 if($s!==$v){$why=$i['review']?' ('.implode('; ',$i['review']).')':'';return ['ok'=>false,'message'=>'“'.$v.'” does not follow the Edutek naming rule'.$why.'. Suggested name: '.$s.'. Choose Use suggested name, or type another name.','suggest'=>$s];}
 if($i['review'])return ['ok'=>false,'message'=>'“'.$v.'” cannot be used ('.implode('; ',$i['review']).'). Type another name.'];
 try{gp_validate('Videos',$v,'');}catch(RuntimeException $e){return ['ok'=>false,'message'=>$e->getMessage()];}
 return ['ok'=>true,'name'=>$v,'exists'=>false,'message'=>'New category “'.$v.'”: the folder is created in the library only when you publish.'];
}
/* Patch 4k: top-level folders in a list of planned paths that are not library categories yet (created only by Publish). */
function gp_new_categories(array $targets): array {
 $o=[];foreach($targets as $t){$c=explode('/',(string)$t)[0];if($c===''||$c===GP_NOCAT||!str_contains((string)$t,'/'))continue;if(gp_category_match($c,true)===null)$o[keyname($c)]=$c;}
 $o=array_values($o);usort($o,'strnatcasecmp');return $o;
}
/* Patch 4l: a new category typed on any card belongs to the whole import. $batch['proposed_categories'] keeps them (saved at once, so they survive a reload);
   every card's Category list shows them first under "New in this import". Unused ones are dropped when the import is saved. Only Publish-Batch creates folders. */
function gp_used_newcats(array $b): array {$o=[];foreach(learning((array)($b['rows']??[])) as $r){$pc=(string)($r['placement_category']??'');if($pc!==''&&gp_role((string)($r['type']??''))===''&&gp_category_match($pc,true)===null)$o[keyname($pc)]??=$pc;}return array_values($o);}
/* Patch 4m: the old Desktop Videos source (/import-source) is optional. It is offered only when it is mounted, is a real folder and has
   at least one entry; otherwise ?src=legacy and a scan of it give a clear message instead of an error. */
const LEGACY_GONE_MSG='The old Desktop Videos folder is not connected to the importer any more. Use Browse... to choose the folder to import';
function gp_legacy_ok(string $d='/import-source'): bool {if(!is_dir($d)||is_link($d))return false;try{$it=new FilesystemIterator($d,FilesystemIterator::SKIP_DOTS);return $it->valid();}catch(Throwable $e){return false;}}
function gp_batch_newcats(array $b): array {
 $o=[];foreach((array)($b['proposed_categories']??[]) as $c){if(!is_string($c))continue;$c=trim($c);if($c===''||gp_category_match($c,true)!==null)continue;$o[keyname($c)]??=$c;}
 foreach(gp_used_newcats($b) as $c)$o[keyname($c)]??=$c;
 $o=array_values($o);usort($o,'strnatcasecmp');return $o;
}
function gp_propose(array &$b,string $v): array {
 $res=gp_check_category($v);if(!$res['ok']||!empty($res['exists']))return $res;
 foreach(gp_batch_newcats($b) as $c)if(keyname($c)===keyname($res['name']))return ['ok'=>true,'name'=>$c,'exists'=>false,'added'=>false,'message'=>'“'.$c.'” is already a new category in this import, so it was chosen. The folder is created in the library only when you publish.'];
 $p=array_values(array_filter((array)($b['proposed_categories']??[]),'is_string'));if(count($p)>=300)return ['ok'=>false,'name'=>'','exists'=>false,'message'=>'This import already has 300 new categories. Use one of them or an existing category.'];
 $p[]=$res['name'];$b['proposed_categories']=$p;$res['added']=true;$res['message']=rtrim((string)$res['message']).' It is now in every item’s Category list in this import.';return $res;
}
function gp_newcat_seed(array $b,array $posted): array {
 $m=[];foreach(array_merge($posted,(array)($b['proposed_categories']??[])) as $c){if(!is_string($c)||trim($c)===''||mb_strlen($c)>200)continue;$k=gp_check_category($c);if(!$k['ok']||!empty($k['exists']))continue;$m[keyname($k['name'])]??=$k['name'];}
 return $m;
}
function gp_newcat_canon(array $p,array $r,array &$map): array {
 if(gp_role((string)($r['type']??''))!==''||!is_string($p['category']??null))return $p;$c=trim($p['category']);
 if($c===''||str_contains($c,'/')||str_contains($c,chr(92))||gp_category_match($c,true)!==null)return $p;
 $k=keyname($c);if(isset($map[$k]))$p['category']=$map[$k];else $map[$k]=$c;return $p;
}
function gp_new_category_counts(array $targets): array {
 $o=[];$n=[];foreach($targets as $t){$t=(string)$t;if(!str_contains($t,'/'))continue;$c=explode('/',$t)[0];if($c===''||$c===GP_NOCAT||gp_category_match($c,true)!==null)continue;$k=keyname($c);$o[$k]??=$c;$n[$k]=($n[$k]??0)+1;}
 $l=[];foreach($o as $k=>$c)$l[]=[$c,$n[$k]];usort($l,fn($a,$b)=>strnatcasecmp($a[0],$b[0]));return $l;
}
function gp_newcat_text(array $counts): string {$o=[];foreach($counts as [$c,$n])$o[]=$c.' ('.$n.' item'.($n===1?'':'s').')';return implode(', ',$o);}
function gp_cat_options(array $lib,array $new): string {
 $o='';if($new){$o.='<optgroup label="New in this import (created when you publish)" data-new="1">';foreach($new as $c)$o.='<option value="'.h($c).'">'.h($c).' (new)</option>';$o.='</optgroup>';}
 $o.='<optgroup label="Library categories ('.count($lib).')" data-lib="1">';foreach($lib as $c)$o.='<option value="'.h($c).'">'.h($c).'</option>';return $o.'</optgroup>';
}
function gp_group_label(string $group,array $rows,array $batch): string {
 if($rows&&!array_filter($rows,fn($x)=>empty($x['root_file'])))return 'Files directly in “'.basename((string)($batch['src_root']??'')).'”';
 return str_replace('/',' › ',$group);
}
/* Patch 4g: library folders that already exist keep their exact spelling, even when the rule would change it ("Hotel Management & Catering"),
   so no sibling "... and Catering" folder is created. A folder name matching an existing library folder case-insensitively takes the library spelling. */
function gp_lib_dir(string $path): ?string {$d=dx_index()['dirs'][keyname($path)]??null;if($d===null&&$path!==''&&!str_contains($path,'/'))$d=gp_category_match($path,true);return $d;} // Patch 4k: + live library categories
function gp_clean_part(string $v,string $prefix): string {
 $have=gp_lib_dir($prefix.$v);if($have!==null&&cleancomponent(basename($have))===basename($have))return basename($have);
 return sn_component($v);
}
function gp_clean_path(string $path): string {$out='';foreach(explode('/',$path) as $part){$out.=($out===''?'':'/').gp_clean_part($part,$out===''?'':$out.'/');}return $out;}
function gp_title_edited(array $r): bool {
 if(isset($r['title_auto']))return $r['title']!==$r['title_auto'];
 $o=(string)($r['original_title']??pathinfo($r['source'],PATHINFO_FILENAME));return $r['title']!==$o&&$r['title']!==bi_tidy_title($o);
}
/* The file name asked for: the source file name, or the edited title plus the extension. */
function gp_requested(array $r): string {$e=(string)($r['out_ext']??$r['extension']);return gp_title_edited($r)?$r['title'].'.'.$e:($e!==$r['extension']?pathinfo($r['source'],PATHINFO_FILENAME).'.'.$e:basename($r['source']));} // Patch 4o audio: a converted file gets .mp3
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
function gp_component(string $v,string $label,string $prefix=''): string {
 if($v===''||str_contains($v,'/')||str_contains($v,chr(92))||$v!==cleancomponent($v)||mb_strlen($v)>120)throw new RuntimeException($label.' must be a safe folder name, not a path. Suggested name: '.sn_component(str_replace(['/',chr(92)],' ',$v)));
 if($v!==sn_component($v)&&gp_lib_dir($prefix.$v)!==$prefix.$v)throw new RuntimeException($label.' name does not follow the Edutek naming rule. Suggested name: '.sn_component($v));return $v;
}
function gp_validate(string $type,string $category,string $folder): void {
 if($category===''&&gp_role($type)===''){if($folder!=='')foreach(explode('/',$folder) as $part)gp_component($part,'Subfolder');return;} // Patch 4k: no category yet (blocked at Review selection)
 gp_component($category,'Category');if($folder!==''){$pre=$category.'/';foreach(explode('/',$folder) as $part){gp_component($part,'Subfolder',$pre);$pre.=$part.'/';}}
 $role=gp_role($type);
 if($role!==''){
  if($category!==$role)throw new RuntimeException($type.' belong under '.$role.'. Change the '.($type==='Books'?'subcategory':($type==='Music'?'album folder':'audiobook folder')).' instead.');
  if($folder===''&&$type!=='Books')throw new RuntimeException(($type==='Books'?'A book subcategory':($type==='Music'?'An album folder':'An audiobook folder')).' is required.');
  if(str_contains($folder,'/'))throw new RuntimeException('Use one '.($type==='Books'?'subcategory':($type==='Music'?'album':'audiobook')).' folder name, not a multi-level path.');
 }else foreach(['Books','Music','Audiobooks'] as $reserved)if($category===gp_role($reserved))throw new RuntimeException('Choose a general category for this video, not the reserved '.$category.' category.');
}
function gp_init(array &$r): void {
 if(!isset($r['placement_category'])){
  $parts=explode('/',$r['target']);array_pop($parts);$category=array_shift($parts)??'Unsorted';
  $r['placement_category']=gp_role($r['type'])?:$category;
  $r['placement_folder']=$r['type']==='Books'?'':implode('/',$parts); // Patch 4p: never a shelf from the folder name
  $r['placement_override']=false;
 }
 $r['target']=gp_target($r);
}
/* Patch 4f: the subfolder a row lands in. An empty subfolder box means automatic: a video then goes into a subfolder with the
   same name as its category (History -> History/History/<file>); books, music and audiobooks keep their required folder.
   gp_target uses gp_folder, so the page, the final check, staging, artwork and publishing all use the same folder. */
function gp_folder(array $r): string {
 $f=(string)$r['placement_folder'];
 return ($f===''&&gp_role((string)$r['type'])==='')?(string)$r['placement_category']:$f;
}
function gp_folder_auto(array $r): bool {return (string)$r['placement_folder']===''||empty($r['placement_override']);}
function gp_auto_category(array $r): string {
 if(empty($r['placement_override']))return (string)$r['placement_category'];
 if(gp_role((string)$r['type'])!=='')return gp_role((string)$r['type']);
 if(array_key_exists('auto_category',$r))return (string)$r['auto_category']; // Patch 4k
 return gp_clean_part(explode('/',(string)($r['group']??'Unsorted'))[0],'');
}
function gp_folder_note(array $r): string {
 $t=(string)$r['type'];$c=(string)$r['placement_category'];$f=(string)$r['placement_folder'];$custom=!empty($r['placement_override']);
 if(gp_role($t)!==''){if($f==='')return $t==='Books'?'Choose a shelf for this book. Books are never put on a shelf by folder name.':'A folder is required.';if($t==='Books')return 'Shelf: '.$f.'.'; // Patch 4p
return ($custom?'Custom':'Automatic').' '.($t==='Books'?'subcategory':'folder').': '.$f.'.';}
 if($c==='')return $f===''?'Automatic subfolder: the same name as the category you choose.':'Subfolder: '.$f.'. Choose a category first.'; // Patch 4k
 if($f==='')return 'Automatic subfolder: '.$c.' (same name as the category). The file goes to '.$c.' › '.$c.'.';
 if(!$custom)return 'Automatic subfolder: '.$f.'.';
 return 'Custom subfolder you typed: '.$f.'. Clear the box, or choose Use automatic subfolder, to go back to the automatic subfolder: '.$c.'.';
}
function gp_target(array $r): string {
 gp_validate($r['type'],$r['placement_category'],$r['placement_folder']);
 $name=sn_filename(gp_requested($r));
 $n=(int)($r['name_n']??0);if($n>1){$d=strrpos($name,'.');$name=sn_cut(substr($name,0,$d),SN_MAX-strlen($name)+$d-strlen(" ($n)"))." ($n)".substr($name,$d);}
 $folder=gp_folder($r);if($folder===''&&(string)$r['type']==='Books')$folder=GP_NOSHELF; // Patch 4p: a book is never put directly in Books
 return ($r['placement_category']===''?GP_NOCAT:$r['placement_category']).($folder!==''?'/'.$folder:'').'/'.$name;
}
function gp_set(array &$r,array $p): void {
 gp_init($r);$category=(string)($p['category']??$r['placement_category']);$folder=(string)($p['folder']??$r['placement_folder']);
 // Patch 4g: what is typed is cleaned with the shared rule (an existing library folder keeps its spelling).
 $category=trim($category);if($category!==''&&!str_contains($category,'/')&&!str_contains($category,chr(92)))$category=gp_clean_part($category,'');
 $folder=trim($folder);if($folder!==''&&!str_contains($folder,chr(92))&&!in_array('',explode('/',$folder),true)){$pre=$category.'/';$fo=[];foreach(explode('/',$folder) as $part){$part=gp_clean_part(trim($part),$pre);$fo[]=$part;$pre.=$part.'/';}$folder=implode('/',$fo);}
 gp_validate($r['type'],$category,$folder);$r['placement_category']=$category;$r['placement_folder']=$folder;$r['placement_override']=($p['placement_override']??false)===true;$r['target']=gp_target($r);
}

/* Patch 4g, rule step 5: two items that end with the same clean name in the same folder get " (2)", " (3)"... (rows in scan order).
   Runs after the duplicate check of each row; rows that replace a library file keep the library path. */
function gp_decollide(array &$b): void {
 $seen=[];
 foreach($b['rows'] as $rid=>&$r){if(!empty($r['support'])||!isset($r['placement_category']))continue;$old=$r['target'];$oldN=(int)($r['name_n']??0);unset($r['name_n']);
  if(!empty($r['replace'])){$seen[keyname($r['target'])]=true;continue;}
  $t=gp_target($r);$n=1;while(isset($seen[keyname($t)])){$n++;$r['name_n']=$n;$t=gp_target($r);if($n>999)throw new RuntimeException('Too many files with the same name: '.$t);}
  $seen[keyname($t)]=true;$r['target']=$t;
  if($t!==$old||$oldN!==(int)($r['name_n']??0)){if(function_exists('dx_apply')&&isset($r['bytes'])){dx_apply($r);rp_apply($r,'');}}
 }unset($r);
}
function checkplan(array $b,string $dir): array {
 if(($b['placement_rules']??0)!==GP_RULES)throw new RuntimeException('Use Review selection to approve the updated folder rules before adding materials.');
 $issues=decision_issues($b);if($issues)throw new RuntimeException('Some selected items still need a decision. Return to Review selection.');
 $chosen=[];$seen=[];$bytes=0;
 foreach($b['rows'] as $id=>$r){if(!$r['include'])continue;if(empty($r['support'])&&($r['placement_category']??null)==='')throw new RuntimeException('Choose a category for “'.$r['title'].'” before it can be added.'); // Patch 4k
  if(($r['type']??'')==='Books'&&(string)($r['placement_folder']??'')==='')throw new RuntimeException('Choose a shelf for “'.$r['title'].'” before it can be added.'); // Patch 4p
  $src=pv_input($r);clearstatcache(true,$src);
  if(filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime'])throw new RuntimeException('Source metadata changed: '.$r['source']);
  $dst=destfile($dir,$r['target']);
  if(strtolower(pathinfo($dst,PATHINFO_EXTENSION))!==(string)($r['out_ext']??$r['extension']))throw new RuntimeException('File extension cannot change');if(!empty($r['damaged']))throw new RuntimeException('“'.$r['title'].'” can’t be played — the file is damaged. Untick it to continue.'); // Patch 4o audio
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
 if(!empty($r['replace']))return false;
 // Patch 4g: the shared rule cleans names automatically (shown on the card as Original name -> Clean name).
 // A decision is needed only when it had to guess: an unreadable character (U+FFFD), a shortened, empty or reserved name.
 return sn_info(gp_requested($r),true)['review']!==[];
}
function needs_fix_why(array $r): array {return sn_info(gp_requested($r),true)['review'];}
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
  if(($r['placement_category']??null)==='')$out[]=['id'=>$rid,'kind'=>'category','message'=>'Choose a category for “'.$r['title'].'”: none could be worked out from the source folder name. Open “Change this item’s placement” and pick a category, or New category….']; // Patch 4k
  if(($r['type']??'')==='Books'&&(string)($r['placement_folder']??'')==='')$out[]=['id'=>$rid,'kind'=>'shelf','message'=>'Choose a Books shelf for “'.$r['title'].'” (press M on it, or C for a new shelf).']; // Patch 4p
  if(in_array($r['type'],['Videos','Books'],true)&&empty($r['art'])&&($r['art_count']??0)===0){$preview=pv_info($r,false);if($preview['state']!=='failed')$out[]=['id'=>$rid,'kind'=>'preview','message'=>'A cover image is being generated; wait for it, then Review selection again'];}
  if(needs_fix($r)&&!hash_equals(fix_token($r),(string)($r['fix_accept']??'')))$out[]=['id'=>$rid,'kind'=>'name','message'=>'Accept the suggested safe filename'];
  if(!empty($r['candidate'])&&empty($r['duplicate_add'])&&empty($r['replace'])&&empty($r['lib_exact']))$out[]=['id'=>$rid,'kind'=>'duplicate','message'=>'Decide whether to add a possible duplicate'];
  if(!empty($r['lib_exact'])&&empty($r['replace']))$out[]=['id'=>$rid,'kind'=>'duplicate','message'=>'A file with this name is already in that library folder: choose Replace the library copy, or change the title or folder'];
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
   $mcE=mc_effective($r);if($mcE&&$mcE['action']!=='none')$o.=' - '.h($mcE['label']);
   $o.='</div></div></li>';
  }
 }
 return $o.'</ul>';
}
const PQ_DIR=BI_ROOT.'/publish-queue';const PS_DIR=BI_ROOT.'/publish-status';
/* Browser publishing: this page only writes publish-queue/<batch>.request.json. The host publisher (Run-PublishQueue.ps1) owns publish-status/ and index-trigger/. */
function pq_read(string $p): ?array {if(!is_file($p))return null;$j=json_decode((string)@file_get_contents($p),true);return is_array($j)?$j:null;}
/* Patch 4n: an earlier import counts as "Recently imported" only while its copy is in the Edutek library or on its way there.
   Not sent yet / requested / publishing / needs attention / undo running: still counts (its staged copy is pending).
   Undone, cleared, blocked or failed: does not count. Published: counts only while the library file is still there (self-healing
   when a file was removed later). If the library folder is not visible to this container, the old cautious behaviour is kept.
   Nothing is written: publish records, audits, batches and the recovery archive stay exactly as they are. */
function ri_state(string $bid): string {static $c=[];if(isset($c[$bid]))return $c[$bid];
 $s=(string)(pq_read(PS_DIR.'/'.$bid.'.json')['state']??'');
 if(is_file(PQ_DIR.'/'.$bid.'.undo.request.json'))$s='undoing';elseif($s===''&&is_file(PQ_DIR.'/'.$bid.'.request.json'))$s='requested';
 return $c[$bid]=$s;}
function ri_live_state(string $s,string $target,string $lib=GP_LIB_DIR): bool {
 if(in_array($s,['undone','reversed','blocked','failed'],true))return false;
 if($s!=='published')return true;
 if(!is_dir($lib))return true;
 $t=trim(str_replace('\\','/',$target),'/');if($t===''||in_array('..',explode('/',$t),true))return false;
 return is_file($lib.'/'.$t);}
function ri_live(string $bid,string $target): bool {return ri_live_state(ri_state($bid),$target);}
function pq_info(string $id): array {
 batchdir($id);$st=pq_read(PS_DIR.'/'.$id.'.json');$idx=null;
 if($st&&($st['state']??'')==='published'&&preg_match('/^publish-[a-f0-9]{8}$/D',(string)($st['index_request']??''))){$ix=pq_read(BI_ROOT.'/index-trigger/'.$st['index_request'].'.result.json');if($ix&&strcmp((string)($ix['started']??''),(string)($st['finished']??''))>=0)$idx=$ix;}
 $hb=@filemtime(PQ_DIR.'/heartbeat.txt');
 return ['undo'=>pq_read(PS_DIR.'/'.$id.'.undo.json'),'undo_req'=>is_file(PQ_DIR.'/'.$id.'.undo.request.json'),'undo_plan_req'=>is_file(PQ_DIR.'/'.$id.'.undo-plan.request.json'),'status'=>$st,'state'=>(string)($st['state']??''),'requested'=>is_file(PQ_DIR.'/'.$id.'.request.json'),'index'=>$idx,'watcher_age'=>$hb?time()-$hb:null];
}
/* Patch 4b: browser undo. The page only writes publish-queue/<batch>.undo-plan.request.json (dry run) and <batch>.undo.request.json
   (execute the plan that was shown). Run-PublishQueue.ps1 runs Undo-Publish.ps1 on the Beelink and writes publish-status/<batch>.undo.json. */
const UQ_COUNTS=['move','restore','gen','db_rows'];
function uq_write(string $id,string $kind,array $data): void {if(!is_dir(PQ_DIR)&&!mkdir(PQ_DIR,0770,true))throw new RuntimeException('Cannot create the publish queue');bi_json(PQ_DIR.'/'.$id.'.'.$kind.'.request.json',$data);}
function uq_busy(array $pi): bool {return $pi['undo_req']||$pi['undo_plan_req']||in_array((string)($pi['undo']['state']??''),['planning','undoing'],true);}
function uq_plan_ok(?array $u): bool {$pl=$u['plan']??null;return is_array($u)&&($u['state']??'')==='planned'&&is_array($pl)&&($pl['outcome']??'')==='planned'&&empty($pl['refuse'])&&(int)($pl['changed']??0)===0&&is_string($pl['fingerprint']??null)&&preg_match('/^[a-f0-9]{64}$/D',$pl['fingerprint']);}
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
 return $o.'<p>Removes this import&rsquo;s new files from the library (kept in recovery) and puts back any files it replaced. You see the plan before anything changes.</p>'.$planBtn('Undo this publish&hellip;').'</div>';
}
/* Patch 4c: plain-word import states and the "Published imports" list (links only; undo itself keeps all 4b checks). */
function ux_state(array $e): string {
 $id=(string)($e['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))return 'Unknown';
 $st=pq_read(PS_DIR.'/'.$id.'.json');$s=(string)($st['state']??'');$u=pq_read(PS_DIR.'/'.$id.'.undo.json');
 if(is_file(PQ_DIR.'/'.$id.'.undo.request.json')||($u['state']??'')==='undoing')return 'Undo in progress';
 $m=['published'=>'Published','undone'=>'Undone','reversed'=>'Undone','failed'=>'Publish failed','checking'=>'Publishing','publishing'=>'Publishing','attention'=>'Publish needs attention'];
 if(isset($m[$s]))return $m[$s];
 if(is_file(PQ_DIR.'/'.$id.'.request.json'))return 'Publish requested';
 $b=['draft'=>'Draft (not staged)','approved'=>'Reviewed (not staged)','importing'=>'Staging','converting'=>'Converting videos for browsers','interrupted'=>'Staging interrupted','complete'=>'Staged (not published)','attention'=>'Needs attention','reversing'=>'Clearing staged copies','reversed'=>'Staged copies cleared'];
 $o=$b[(string)($e['state']??'')]??(string)($e['state']??'');return $s==='blocked'?'Staged (publish blocked)':$o;
}
function ux_when(string $t): string {return preg_match('/^(\d{4}-\d\d-\d\d)T(\d\d:\d\d)/',$t,$m)?$m[1].' '.$m[2]:$t;}
function ux_published(int $limit=0): string {
 $rows=[];foreach(glob(PS_DIR.'/*.json')?:[] as $f){if(!preg_match('/\/([a-f0-9]{32})\.json$/D',$f,$m))continue;$st=pq_read($f);if(!$st)continue;
  $s=ux_state(['id'=>$m[1],'state'=>'complete']);if(!in_array($s,['Published','Undone','Undo in progress','Publish failed','Publish needs attention'],true))continue;
  $rows[]=['id'=>$m[1],'st'=>$st,'s'=>$s,'when'=>(string)($st['finished']??$st['started']??'')];}
 usort($rows,fn($a,$b)=>strcmp($b['when'],$a['when']));if($limit>0)$rows=array_slice($rows,0,$limit);
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
function pq_can_request(array $pi): bool {return !$pi['requested']&&in_array($pi['state'],['','blocked'],true);}
function pq_can_reverse(array $pi): bool {return !$pi['requested']&&in_array($pi['state'],['','blocked','reversed','undone'],true);}
function pq_counts(array $b): array {$l=0;$f=0;$by=0;$rp=0;foreach($b['operations'] as $rid=>$op){if(($op['state']??'')!=='copied')continue;$f++;$by+=(int)$op['bytes'];if(empty($b['rows'][$rid]['support'])){$l++;if(!empty($b['rows'][$rid]['replace']))$rp++;}}return [$l,$f,$by,$rp];}
function pq_panel(array $b,string $id,string $csrf,array $pi): string {
 if($b['state']!=='complete')return '';
 [$learn,$files,$bytes,$rep]=pq_counts($b);$new=$learn-$rep;$phr=pq_phrase($learn,$rep);$what=$rep>0?$new.' new, '.$rep.' replacement'.($rep>1?'s':''):$learn.' materials';$st=$pi['status']??[];$s=$pi['state'];$mib=number_format($bytes/1048576,1);
 $o='<div class="panel" id="publish"><div class="step">STEP 4 &mdash; PUBLISH TO THE EDUTEK LIBRARY</div>';
 $nt=[];foreach(($b['operations']??[]) as $nrid=>$nop){if(($nop['state']??'')==='copied'&&empty($b['rows'][$nrid]['support']))$nt[]=(string)($nop['target']??'');}$nc=gp_new_category_counts($nt); // Patch 4k
 if($nc)$o.='<p class="notice" id="publish-new-categories">New categories: <strong>'.h(gp_newcat_text($nc)).'</strong>. Publishing creates '.(count($nc)===1?'this new top-level folder':'these new top-level folders').' in D:\\xampp\\htdocs\\Edutek\\videos.</p>';
 $again='<script>setTimeout(()=>location.reload(),6000)</script>';
 $hb=($pi['watcher_age']===null||$pi['watcher_age']>60)?'<p class="warning">The publisher on the Beelink is not running (no recent heartbeat). Requests wait until the &ldquo;Edutek Import Publisher&rdquo; task is running.</p>':'';
 $msg=!empty($st['message'])?'<pre class="report" style="white-space:pre-wrap">'.h($st['message']).'</pre>':'';
 if($s==='checking'||$s==='publishing')return $o.'<h2>'.($s==='checking'?'Checking the publish request&hellip;':'Publishing&hellip;').'</h2><p>'.($s==='checking'?'The publisher is re-checking this import. Nothing has been copied to the Edutek library yet.':'The publisher is copying the verified files into the Edutek library (started '.h($st['started']??'').').').'</p>'.$again.'</div>';
 if($pi['requested'])return $o.'<h2>Publish requested</h2><p>Waiting for the publisher on the Beelink to check and publish '.$learn.' materials. Nothing has been copied to the Edutek library yet.</p>'.$hb.$again.'</div>';
 if($s==='published'){
  $o.='<h2>Published to the Edutek library</h2><p>'.(int)($st['learning']??0).' learning items + '.(int)($st['covers']??0).' cover files ('.h($st['mib']??'').' MiB) were copied into D:\xampp\htdocs\Edutek\videos. Finished '.h($st['finished']??'').'.</p>'.((int)($st['replaced_learning']??0)>0?'<p class="notice">'.(int)$st['replaced_learning'].' of them replaced library files; the old files are archived ('.h($st['archive_manifest']??'').') and Undo puts them back until the archive is cleared.</p>':'');$o.=uq_panel($id,$csrf,$pi);
  $ix=$pi['index'];$rn=h($st['index_request']??'');
  if(empty($st['index_request']))$o.='<p class="warning">No indexing request is recorded for this publish.</p>';
  elseif(!$ix&&!empty($st['synced']))$o.='<p class="notice">No indexing result for '.$rn.' is available here. This publish was recorded outside the browser or before it; its indexing results may have been archived (see D:\\edutek-system\\index-status on the Beelink).</p>';
  elseif(!$ix)$o.='<p class="notice">Indexing: queued as '.$rn.'. Waiting for the &ldquo;Edutek Import Partial Indexer&rdquo; task. If this does not change, check index-trigger\\'.$rn.'.result.json on the Beelink.</p>'.$again;
  elseif(($ix['state']??'')==='complete'&&(int)($ix['exit_code']??-1)===0)$o.='<p class="notice">Indexing complete for '.$rn.' (finished '.h($ix['finished']??'').').</p>'.cc_line((string)$st['index_request']);
  else $o.='<p class="error">Indexing did not complete for '.$rn.': state '.h($ix['state']??'?').', exit code '.h((string)($ix['exit_code']??'?')).'. Check index-trigger\\'.$rn.'.result.json on the Beelink. Do not run a full index.</p>';
  return $o.'<script>(()=>{document.querySelectorAll(".undo-form").forEach(f=>{let sent=false;f.addEventListener("submit",e=>{if(sent){e.preventDefault();return}const a=f.querySelector("#undo-ack");if(a&&!a.checked){e.preventDefault();return}sent=true;const b=f.querySelector("button");if(b){b.disabled=true;b.textContent="Sending\\u2026"}})});const a=document.getElementById("undo-ack"),b=document.getElementById("undo-btn"),hi=document.getElementById("undo-hint");if(a&&b){const u=()=>{b.disabled=!a.checked;if(hi)hi.hidden=a.checked};a.addEventListener("change",u);u()}if(location.hash==="#undo")window.addEventListener("load",()=>setTimeout(()=>{const x=document.getElementById("undo"),y=x&&x.querySelector("input[type=checkbox],button");if(y){x.scrollIntoView();y.focus()}},0))})();</script></div>';
 }
 $uix='';$un=(string)($st['undo_index_request']??'');if(preg_match('/^undo-[a-f0-9]{8}-\\d{6}$/D',$un)){$ux=pq_read(BI_ROOT.'/index-trigger/'.$un.'.result.json');$uix=!$ux?'<p class="notice">Indexing of the restored files: queued as '.h($un).'.</p><script>setTimeout(()=>location.reload(),8000)</script>':((($ux['state']??'')==='complete'&&(int)($ux['exit_code']??-1)===0)?'<p class="notice">Indexing complete for '.h($un).'.</p>'.cc_line($un):'<p class="error">Indexing did not complete for '.h($un).'. Check index-trigger\\'.h($un).'.result.json on the Beelink. Do not run a full index.</p>');}
 if($s==='undone'||$s==='reversed')return $o.'<h2>Publish undone</h2>'.$uix.'<p>Undone'.(!empty($st['undone'])?' on '.h(ux_when((string)$st['undone'])):'').': the new files were removed from the library (kept in recovery) and replaced files were put back.</p><p>This import can&rsquo;t be published again. To start over, clear its staged copies below and run a new import.</p></div>';
 if($s==='failed')return $o.'<h2>Publishing failed</h2>'.$msg.'<p>Some files may already be in the Edutek library. On the Beelink, run Undo-Publish.ps1 -BatchId '.h(substr($id,0,8)).' (dry run first) to review. This page will not publish this import again.</p></div>';
 if($s!==''&&$s!=='blocked')return $o.'<h2>Publish record: '.h($st['record_state']??$s).'</h2>'.$msg.'<p>This import is not offered for publishing again. Check the publish record on the Beelink.</p></div>';
 $o.=($s==='blocked'?'<div class="warning"><h3>Not published: the checks stopped it</h3><p>Nothing was copied to the Edutek library.</p>'.$msg.'</div>':'');
 return $o.'<h2>Publish '.$what.' to the Edutek library</h2><p>The publisher re-checks each staged file, backs up the database, copies the files into the Edutek library (D:\xampp\htdocs\Edutek\videos) and indexes only these files. Nothing is overwritten except the replacements you chose. You can undo the publish afterwards.</p>'.($rep>0?'<p class="notice">'.$rep.' replacement'.($rep>1?'s':'').': each old file is archived first (undo restores it until the archive is cleared). Publishing stops if a library file changed since review or is identical to its replacement.</p>':'').$hb
 .'<form method="post" id="publish-form"><input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="revision" value="'.h($b['approval']??'').'"><input type="hidden" name="action" value="publish"><input type="hidden" name="new_count" value="'.$new.'"><input type="hidden" name="replace_count" value="'.$rep.'"><p class="publish-summary"><strong>Ready to publish: '.$new.' new file'.($new===1?'':'s').($rep>0?', '.$rep.' replacement'.($rep===1?'':'s'):'').'</strong> ('.$files.' files including covers, '.$mib.' MiB)</p>'.($rep>0?'<p><label for="replace-ack"><input type="checkbox" name="replace_ack" value="1" id="replace-ack"> I understand '.$rep.' library file'.($rep>1?'s':'').' will be replaced; the old file'.($rep>1?'s are':' is').' archived and undoing the publish restores it</label></p>':'').'<button class="primary" type="submit" id="publish-btn"'.($rep>0?' disabled':'').'>Publish to the Edutek library</button>'.($rep>0?' <span class="muted" id="publish-hint">Tick the box above first (Tab to it, then press Space).</span>':'').'</form>'
 .'<script>(()=>{const f=document.getElementById("publish-form"),b=document.getElementById("publish-btn"),a=document.getElementById("replace-ack"),hi=document.getElementById("publish-hint");let sent=false;const u=()=>{b.disabled=sent||(!!a&&!a.checked);if(hi)hi.hidden=!a||a.checked};if(a)a.addEventListener("change",u);u();f.addEventListener("submit",e=>{if(sent||(a&&!a.checked)){e.preventDefault();return}sent=true;b.disabled=true;b.textContent="Sending the publish request\u2026"});window.addEventListener("pageshow",e=>{if(e.persisted){sent=false;b.textContent="Publish to the Edutek library";u()}})})();</script></div>';
}
function learning(array $rows): array {return array_filter($rows,fn($r)=>empty($r['support']));}
function hv_gb($b): string {return is_numeric($b)&&$b>0?number_format($b/1073741824,1).' GB free':'';}
function hv_link(string $rel,string $text,string $label=''): string {return '<a class="hv-go" href="?browse='.rawurlencode($rel).'#browse"'.($label!==''?' aria-label="'.h($label).'"':'').'>'.h($text).'</a>';}
function hv_btn(string $csrf,string $rel,string $action,string $drive,string $text,string $label): string {return '<form method="post" class="hv-inline"><input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="browse" value="'.h($rel).'"><input type="hidden" name="drive" value="'.h($drive).'"><button type="submit" name="action" value="'.h($action).'" aria-label="'.h($label).'">'.h($text).'</button></form>';}
// Patch 4e: Browse... opens the native Windows folder window (see hp_* in video-preview-lib.php); the in-page folder
// browser is gone. This small Drives box stays for USB drives: Prepare to eject, Connect again, Refresh drives.
// USB drives are connected and ejected by the drive watcher task (Watch-ImportDrives.ps1) through data/drive-queue.
function hv_drives_panel(string $csrf): string {
 $drives=hv_drives();$info=hv_hostinfo();$st=hd_status();
 $o='<div class="hv" id="drives"><h2 id="drives-title" tabindex="-1">Drives</h2>';
 $last=$st['last'];if(is_array($last)&&isset($last['at'])&&strtotime((string)$last['at'])>time()-180)$o.='<p class="'.(!empty($last['ok'])?'hd-ok':'warning').'" role="status">'.h((string)($last['message']??'')).'</p>';
 $o.='<p id="hd-live" role="status" aria-live="polite" class="muted"></p>';
 if($st['watcher_age']===null||$st['watcher_age']>60)$o.='<p class="warning">Drive watcher not running: Browse&hellip; cannot open the folder window, and USB drives are not connected or ejected automatically. Start the &ldquo;Edutek Import Drive Watcher&rdquo; task on this computer (or sign out and back in). Until it runs, Browse&hellip; cannot choose a source.</p>';
 elseif($st['docker_ready']===false)$o.='<p class="warning">Docker is still starting. USB drives connect automatically when it is ready.</p>';
 $o.='<ul class="hv-list">';
 foreach($drives as $l){$e=$info[$l]??[];$lab=trim((string)($e['label']??''));$usb=!hv_fixed($l);$free=hv_gb(@disk_free_space(HV_ROOT.'/'.$l));$name=strtoupper($l).':'.($lab!==''?' '.$lab:'');
  $o.='<li>'.h($name.' ('.($usb?'USB drive':'drive').($free!==''?', '.$free:'').')').($usb?' '.hv_btn($csrf,'','drive_eject',$l,'Prepare to eject','Prepare to eject '.$name):'').'</li>';}
 foreach($info as $l=>$e){if(in_array($l,$drives,true)||hv_fixed($l))continue;$name=strtoupper($l).':'.(trim((string)($e['label']??''))!==''?' '.trim((string)$e['label']):'');$s=(string)($e['state']??'');
  if($s==='safe_to_remove')$o.='<li><strong>'.h($name).': safe to remove.</strong> '.hv_btn($csrf,'','drive_connect',$l,'Connect again','Connect '.$name.' again').'</li>';
  elseif($s==='connected')$o.='<li class="muted">'.h($name.' is connected, but the importer cannot see it yet. Choose Refresh drives; if that does not help, run the importer restart block again.').'</li>';
  elseif($s==='error')$o.='<li class="muted">'.h($name.' could not be connected: '.(string)($e['message']??'unknown error')).' '.hv_btn($csrf,'','drive_connect',$l,'Try again','Try connecting '.$name.' again').'</li>';
  elseif($s==='waiting')$o.='<li class="muted">'.h($name.' is plugged in and waits for Docker.').'</li>';
  elseif($s==='connecting'||$s==='')$o.='<li class="muted">'.h($name.' is plugged in and connecting.').'</li>';}
 if(!$drives)$o.='<li class="muted">No drives are connected to the importer. Run the importer restart block (patch 4e).</li>';
 $o.='</ul><div>'.hv_btn($csrf,'','drive_refresh','','Refresh drives','Refresh drives list').'</div><p class="muted">Files are only read, never changed. USB drives connect by themselves when plugged in.</p></div>';
 $o.='<script>window.addEventListener("load",function(){if(location.hash==="#drives"){var t=document.getElementById("drives-title");if(t){t.scrollIntoView();t.focus();}}});</script>';
 // After a drive request: wait for the watcher (up to about 30 s), then reload the list.
 if(isset($_GET['drivewait']))$o.='<script>(function(){var live=document.getElementById("hd-live"),n=0,tok='.json_encode($csrf).';live.textContent="Checking drives\u2026";'
  .'function done(){var u=new URL(location.href);u.searchParams.delete("drivewait");location.replace(u.toString());}'
  .'function tick(){fetch("?drivestatus=1&token="+encodeURIComponent(tok),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(s){'
  .'if(!s.pending.length){live.textContent="Drive list updated.";done();return;}'
  .'if(s.watcher_age===null||s.watcher_age>60){live.textContent="The drive watcher is not running, so the request is waiting.";return;}'
  .'if(++n>30){live.textContent="Still waiting for the drive watcher. Choose Refresh drives to try again.";return;}setTimeout(tick,1000);}).catch(function(){if(++n<=30)setTimeout(tick,2000);});}'
  .'setTimeout(tick,700);})();</script>';
 return $o;
}
function hidden_fields(string $csrf,string $id=''): void {echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';if($id!=='')echo '<input type="hidden" name="id" value="'.h($id).'">';}

/* Patch 4i: browser-ready videos. At scan every mp4 gets a media plan (ffprobe + order of the top-level MP4 boxes):
   none      - H.264 (8-bit 4:2:0) with AAC/MP3 or no audio, index (moov) already at the start: verified plain copy, as before;
   faststart - same codecs, index at the end: lossless remux (-c copy -movflags +faststart), no re-encode;
   audio     - H.264 video with another audio codec: video copied, audio to AAC;
   convert   - HEVC and every other non-browser video: H.264 High, short side at most 720 px (never upscaled), CRF 27,
               preset veryfast, AAC 128k, +faststart.
   Only the STAGED copy is written; the source is only read. The work runs in a detached runner (php <this file> --media-runner <id>,
   nice 10) so the page stays usable and shows progress. A failed or unverifiable output is never staged: it is deleted and the
   original is copied unchanged (verified like every copy) with a warning on the page.
   op.bytes/op.sha256 describe the staged file (what the publisher checks); op.source_bytes/op.source_sha256 the original.
   Rows keep the SOURCE bytes/mtime, so the existing re-import checks still match; the transcode ledger
   (BI_ROOT/transcode-ledger/<source bytes>-<source sha256>.json) also flags a re-import of the same source by content. */
const MC_MAX_SIDE=720;const MC_CRF=27;const MC_PRESET='veryfast';const MC_ABR='128k';const MC_THREADS=8;const MC_NICE=10;const MC_STALL=300;const MC_RETRIES=2;
const MC_LEDGER=BI_ROOT.'/transcode-ledger';
function mc_atoms(string $p): array {
 $o=[];$fp=@fopen($p,'rb');if(!$fp)return $o;$size=(int)@filesize($p);$pos=0;
 while($pos+8<=$size&&count($o)<64){
  if(fseek($fp,$pos)!==0)break;$h=fread($fp,8);if(!is_string($h)||strlen($h)<8)break;
  $len=unpack('N',substr($h,0,4))[1];$type=substr($h,4,4);
  if($len===1){$x=fread($fp,8);if(!is_string($x)||strlen($x)<8)break;$len=unpack('J',$x)[1];}elseif($len===0){$len=$size-$pos;}
  if($len<8||!preg_match('/^[\x20-\x7e]{4}$/D',$type))break;$o[]=$type;$pos+=$len;}
 fclose($fp);return $o;
}
/* true: moov before mdat (fast start), false: moov after mdat, null: no moov found. */
function mc_moov_first(string $p): ?bool {$a=mc_atoms($p);$m=array_search('moov',$a,true);$d=array_search('mdat',$a,true);if($m===false)return null;if($d===false)return true;return $m<$d;}
function mc_run(array $cmd,int $timeout): array {
 $p=@proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pp);if(!is_resource($p))return [-1,'','cannot start '.$cmd[0]];
 stream_set_blocking($pp[1],false);stream_set_blocking($pp[2],false);$out='';$err='';$t0=microtime(true);$open=[1=>$pp[1],2=>$pp[2]];
 while($open){$r=array_values($open);$w=null;$e=null;if(@stream_select($r,$w,$e,1)===false)break;
  foreach($open as $k=>$s){$c=fread($s,65536);if(is_string($c)&&$c!==''){if($k===1)$out.=$c;elseif(strlen($err)<65536)$err.=$c;}if(feof($s)){fclose($s);unset($open[$k]);}}
  if(microtime(true)-$t0>$timeout){proc_terminate($p,9);foreach($open as $s)fclose($s);$open=[];$err.=' (stopped after '.$timeout.' s)';}}
 $c=proc_close($p);return [$c,$out,$err];
}
function mc_probe(string $p): ?array {
 [$c,$o]=mc_run(['ffprobe','-v','error','-show_entries','format=duration:stream=index,codec_type,codec_name,width,height,pix_fmt,channels:stream_disposition=attached_pic','-of','json',$p],120);
 if($c!==0)return null;$j=json_decode($o,true);if(!is_array($j))return null;$v=null;$a=null;
 foreach((array)($j['streams']??[]) as $s){$t=$s['codec_type']??'';if($t==='video'&&!$v&&empty($s['disposition']['attached_pic']))$v=$s;elseif($t==='audio'&&!$a)$a=$s;}
 if(!$v)return null;
 return ['v'=>(string)($v['codec_name']??''),'vi'=>(int)($v['index']??0),'w'=>(int)($v['width']??0),'h'=>(int)($v['height']??0),'pix'=>(string)($v['pix_fmt']??''),
  'a'=>$a?(string)($a['codec_name']??''):'','ai'=>$a?(int)($a['index']??-1):-1,'ch'=>$a?(int)($a['channels']??0):0,'dur'=>(float)($j['format']['duration']??0),'moov'=>mc_moov_first($p)];
}
function mc_codec_name(string $c): string {return ['hevc'=>'HEVC','h264'=>'H.264','mpeg4'=>'MPEG-4','av1'=>'AV1','vp9'=>'VP9','vp8'=>'VP8','mpeg2video'=>'MPEG-2','mpeg1video'=>'MPEG-1','mjpeg'=>'Motion JPEG','prores'=>'ProRes','h263'=>'H.263','wmv3'=>'WMV','vc1'=>'VC-1','aac'=>'AAC','mp3'=>'MP3','ac3'=>'AC-3','eac3'=>'E-AC-3','opus'=>'Opus','flac'=>'FLAC','alac'=>'ALAC','pcm_s16le'=>'PCM'][$c]??strtoupper($c);}
function mc_plan(?array $pr): array {
 if(!$pr)return ['action'=>'none','label'=>'Copied unchanged: the video details could not be read','warn'=>'unreadable'];
 $short=min($pr['w'],$pr['h']);$p8=in_array($pr['pix'],['yuv420p','yuvj420p'],true);
 $vOk=$pr['v']==='h264'&&$p8;$aOk=$pr['a']===''||in_array($pr['a'],['aac','mp3'],true);
 $base=['vcodec'=>$pr['v'],'acodec'=>$pr['a'],'width'=>$pr['w'],'height'=>$pr['h'],'duration'=>round($pr['dur'],3),'moov_first'=>$pr['moov']];
 if(!$vOk){$to=min($short>0?$short:MC_MAX_SIDE,MC_MAX_SIDE);$to-=$to%2;$from=mc_codec_name($pr['v']).($pr['v']==='h264'?' ('.$pr['pix'].')':'').($short>0?' '.$short.'p':'');
  return $base+['action'=>'convert','label'=>$from.' → H.264 '.$to.'p','to_short'=>$to];}
 if(!$aOk)return $base+['action'=>'audio','label'=>'Audio '.mc_codec_name($pr['a']).' → AAC (video kept, no re-encode)'];
 if($pr['moov']!==true)return $base+['action'=>'faststart','label'=>'Fast start fix (no re-encode)'];
 return $base+['action'=>'none','label'=>'Ready for browsers (H.264, fast start)'];
}
function mc_is_video(array $r): bool {return empty($r['support'])&&($r['type']??'')==='Videos'&&($r['extension']??'')==='mp4';}
/* Plans a row once per source version (bytes+mtime); called at scan and on save, so the plan is part of the approved rows. */
function mc_row_plan(array &$r): void {
 if(!mc_is_video($r))return;$m=$r['media']??null;
 if(is_array($m)&&($m['src_bytes']??null)===$r['bytes']&&($m['src_mtime']??null)===$r['mtime']&&($m['v4p']??0)===1){if(($m['action']??'')==='damaged'){$r['damaged']=true;$r['include']=false;}return;}
 $why=p4_mp4_integrity(pv_input($r));$pr=null; // Patch 4p: a cut-short or unreadable video is damaged (never ticked, never published)
 if($why===''){try{$pr=mc_probe(pv_input($r));}catch(Throwable $e){$pr=null;}if($pr===null&&p4_probe_works())$why='the video details could not be read.';}
 if($why!==''){$r['media']=['action'=>'damaged','label'=>'Can’t be played — file is damaged','why'=>$why,'warn'=>'damaged','src_bytes'=>$r['bytes'],'src_mtime'=>$r['mtime'],'v4p'=>1];$r['damaged']=true;$r['include']=false;return;}
 $r['media']=mc_plan($pr)+['src_bytes'=>$r['bytes'],'src_mtime'=>$r['mtime'],'v4p'=>1];unset($r['damaged']);
}
function mc_rows_plan(array &$b): void {$ch=false;foreach($b['rows'] as &$r){mc_row_plan($r);$ch=au_row_plan($r)||$ch;}unset($r);if($ch)gp_decollide($b);} // Patch 4o audio
/* What staging will do. A same-size library file keeps the original unchanged, so the publisher can still find an identical copy. */
function mc_effective(array $r): ?array {
 if(!mc_is_video($r)||!is_array($r['media']??null))return null;$m=$r['media'];if(($m['action']??'')==='damaged')return null; // Patch 4p: never converted or staged
 if(($m['action']??'none')!=='none'&&(int)($r['lib_size_n']??0)>0)return ['action'=>'kept','planned'=>$m['label'],'label'=>'Kept as is (not converted): a library file has the same size, so publishing can check for an identical copy'];
 return $m;
}
function mc_needs_job(array $r): bool {if(au_is_audio($r))return ($r['media']['action']??'')==='mp3'; /* Patch 4o audio */$m=mc_effective($r);return $m!==null&&in_array($m['action'],['convert','audio','faststart'],true);}
function mc_badge_html(array $r): string {
 if(au_is_audio($r))return au_badge_html($r); // Patch 4o audio
 if(mc_is_video($r)&&(($r['media']['action']??'')==='damaged'))return '<span class="badge media-badge damaged-badge" style="background:#fde2e2;color:#7f1d1d">'.h('Can’t be played — file is damaged: '.(string)($r['media']['why']??'')).'</span>'; // Patch 4p
 $m=mc_effective($r);if(!$m)return '';$a=$m['action'];
 $st=$a==='convert'||$a==='audio'?'background:#fde8c4;color:#7a4300':($a==='faststart'?'background:#dff3ef;color:#134e4a':($a==='none'&&empty($m['warn'])?'background:#d4efdc;color:#14532d':'background:#fff4df;color:#6b4300'));
 $t=$a==='convert'?'Will convert: '.$m['label']:($a==='audio'?'Will fix: '.$m['label']:($a==='faststart'?'Will fix: '.$m['label']:$m['label']));
 return '<span class="badge media-badge" style="'.$st.'">'.h($t).'</span>';
}
function mc_settings(string $a): string {
 if($a==='convert')return 'libx264 preset '.MC_PRESET.' CRF '.MC_CRF.', short side max '.MC_MAX_SIDE.' (no upscale), yuv420p, AAC '.MC_ABR.', +faststart, '.MC_THREADS.' threads';
 if($a==='audio')return 'video stream copy, AAC '.MC_ABR.', +faststart';
 return 'stream copy, +faststart';
}
function mc_cmd(string $src,string $out,array $pr,string $a): array {
 $c=['ffmpeg','-nostdin','-hide_banner','-v','error','-n','-i',$src,'-map','0:'.$pr['vi']];if($pr['ai']>=0)array_push($c,'-map','0:'.$pr['ai']);
 $aac=['-c:a','aac','-b:a',MC_ABR];if($pr['ch']>2)array_push($aac,'-ac','2');
 if($a==='convert'){$s=MC_MAX_SIDE;
  array_push($c,'-c:v','libx264','-preset',MC_PRESET,'-crf',(string)MC_CRF,'-profile:v','high','-pix_fmt','yuv420p','-threads',(string)MC_THREADS,
   '-vf',"scale=w='if(gte(iw,ih),-2,trunc(min($s,iw)/2)*2)':h='if(gte(iw,ih),trunc(min($s,ih)/2)*2,-2)'");
  if($pr['ai']>=0)array_push($c,...$aac);}
 elseif($a==='audio'){array_push($c,'-c:v','copy',...$aac);}
 else{array_push($c,'-c','copy');}
 array_push($c,'-map_metadata','0','-movflags','+faststart','-progress','pipe:1','-nostats','-f','mp4',$out);return $c;
}
function mc_packets(string $p,int $si): int {[$c,$o]=mc_run(['ffprobe','-v','error','-count_packets','-select_streams',(string)$si,'-show_entries','stream=nb_read_packets','-of','csv=p=0',$p],900);return $c===0&&ctype_digit(trim($o))?(int)trim($o):-1;}
/* Checks the output before it can be staged. Returns '' when it is good, else the reason in plain words. */
function mc_verify(string $out,string $srcPath,array $src,string $a): string {
 $o=mc_probe($out);if(!$o)return 'the new file could not be read';
 if($o['v']!=='h264')return 'the new video is '.mc_codec_name($o['v']).', not H.264';
 if(!in_array($o['pix'],['yuv420p','yuvj420p'],true))return 'the new video has pixel format '.$o['pix'];
 if($o['moov']!==true)return 'the new file does not start with its index (fast start)';
 if($src['ai']>=0&&$o['a']==='')return 'the sound track is missing';
 if($o['a']!==''&&!in_array($o['a'],['aac','mp3'],true))return 'the new sound track is '.mc_codec_name($o['a']);
 $tol=max(1.0,$src['dur']*0.01);if($src['dur']>0&&abs($o['dur']-$src['dur'])>$tol)return sprintf('the new file lasts %.2f s instead of %.2f s',$o['dur'],$src['dur']);
 $ss=min($src['w'],$src['h']);$os=min($o['w'],$o['h']);
 if($a==='convert'){if($os<2||$os>MC_MAX_SIDE||$os>$ss)return 'the new picture size '.$o['w'].'x'.$o['h'].' is not as planned';
  [$c,,$e]=mc_run(['ffmpeg','-nostdin','-v','error','-xerror','-i',$out,'-map','0','-f','null','-'],max(900,(int)($src['dur']*2)));
  if($c!==0)return 'the new file does not decode cleanly'.(trim($e)!==''?' ('.trim(substr($e,0,200)).')':'');}
 else{if($o['w']!==$src['w']||$o['h']!==$src['h'])return 'the picture size changed';
  $n1=mc_packets($srcPath,$src['vi']);$n2=mc_packets($out,$o['vi']);if($n1<1||$n1!==$n2)return 'the new file has '.$n2.' video frames instead of '.$n1;}
 return '';
}
/* Runs ffmpeg with live progress; kills it on a stall or after the time cap. Returns '' or the error. */
function mc_ffmpeg(array $cmd,array $pr,string $name,int $srcBytes,string $label): string {
 $cap=max(1800,(int)($pr['dur']*4));$log=dirname($cmd[count($cmd)-1]).'/'.basename($cmd[count($cmd)-1]).'.log';
 $p=@proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$log,'w']],$pp);if(!is_resource($p))return 'ffmpeg could not start';
 stream_set_blocking($pp[1],false);$t0=microtime(true);$last=$t0;$buf='';$us=0;$speed='';$end=false;
 $GLOBALS['bi_prog']['extra']=['label'=>$label,'pct'=>0,'speed'=>'','elapsed'=>0];bi_progress('converting',$name,0,$srcBytes,true);
 while(true){$r=[$pp[1]];$w=null;$e=null;$n=@stream_select($r,$w,$e,1);
  if($n){$c=fread($pp[1],65536);if(is_string($c)&&$c!==''){$buf.=$c;$last=microtime(true);
    while(($i=strpos($buf,"\n"))!==false){$ln=trim(substr($buf,0,$i));$buf=substr($buf,$i+1);
     if(preg_match('/^out_time_us=(\d+)/',$ln,$m))$us=(int)$m[1];elseif(preg_match('/^speed=\s*([\d.]+)x/',$ln,$m))$speed=$m[1];elseif($ln==='progress=end')$end=true;}
    $pct=$pr['dur']>0?min(1.0,$us/1e6/$pr['dur']):0.0;$GLOBALS['bi_prog']['extra']=['label'=>$label,'pct'=>round($pct*100,1),'speed'=>$speed,'elapsed'=>(int)(microtime(true)-$t0)];
    bi_progress('converting',$name,(int)($pct*$srcBytes),$srcBytes);}
   if(feof($pp[1]))break;}
  $now=microtime(true);
  if($now-$last>MC_STALL||$now-$t0>$cap){proc_terminate($p,9);fclose($pp[1]);proc_close($p);return $now-$t0>$cap?'it took longer than '.intdiv($cap,60).' minutes':'ffmpeg stopped making progress';}}
 fclose($pp[1]);$code=proc_close($p);$err=trim((string)@file_get_contents($log,false,null,0,4000));
 if($code!==0||!$end)return 'ffmpeg failed'.($err!==''?': '.substr(preg_replace('/\s+/',' ',$err),0,240):' (exit '.$code.')');
 return '';
}
function mc_ledger_path(int $bytes,string $sha): string {return MC_LEDGER.'/'.$bytes.'-'.$sha.'.json';}
function mc_ledger_live(array $e): bool {
 $bid=(string)($e['batch']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$bid))return false;
 $bp=batchdir($bid).'/batch.json';$b=null;if(is_file($bp)){try{$b=bi_read($bp);}catch(Throwable $x){$b=null;}}
 $rid=(string)($e['rid']??'');$tgt=(string)($b['rows'][$rid]['target']??'');
 if(ri_state($bid)==='published')return $tgt===''?true:ri_live($bid,$tgt); /* Patch 4n: only while the library file is still there */
 if(!is_array($b))return false;
 $op=$b['operations'][$rid]??null;return is_array($op)&&($op['state']??'')==='copied'&&($op['source_sha256']??'')===($e['source_sha256']??'-')&&ri_live($bid,$tgt);
}
/* Scan: plan every video, then flag sources that an earlier import already converted (same bytes and SHA-256 of the ORIGINAL). */
function mc_scan(array &$b): void {
 mc_rows_plan($b);
 foreach($b['rows'] as $rid=>&$r){if(!mc_is_video($r))continue;
  if(!glob(MC_LEDGER.'/'.(int)$r['bytes'].'-*.json'))continue;
  try{$sha=hash_file('sha256',pv_input($r));$lp=mc_ledger_path((int)$r['bytes'],(string)$sha);if(!is_file($lp))continue;$e=bi_read($lp);}catch(Throwable $x){continue;}
  if(!mc_ledger_live($e))continue;
  $r['converted_before']=(string)($e['label']??'converted');
  // An earlier import of this exact original (found by its SHA-256) is flagged even when its staged file is a different, converted file.
  $r['already_imported']=true;$r['recent_kind']='same';if(empty($r['recent_where']))$r['recent_where']=['created'=>(string)($e['when']??''),'kind'=>'earlier','where'=>dirname((string)($e['target']??''))];
 }unset($r);
}
function mc_done_bytes(array $b): int {$n=0;foreach($b['operations'] as $o)if(($o['state']??'')==='copied')$n+=(int)($o['source_bytes']??$o['bytes']);return $n;}
function mc_running(string $dir): bool {$f=@fopen($dir.'/convert.lock','c');if(!$f)return false;$free=flock($f,LOCK_EX|LOCK_NB);if($free)flock($f,LOCK_UN);fclose($f);return !$free;}
function mc_spawn(string $id): bool {
 $dir=batchdir($id);
 $p=@proc_open(['setsid','sh','-c','"$0" "$1" --media-runner "$2" </dev/null >>"$3" 2>&1 &',PHP_BINARY,__FILE__,$id,$dir.'/convert.log'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pp);
 if(!is_resource($p))return false;proc_close($p);return true;
}
/* Verified plain copy of one row into staging (the pre-4i copy loop, unchanged). Returns the SHA-256. */
function bi_copy_one(array &$batch,string $dir,string $rid,array $r): string {
 $src=pv_input($r);$dst=destfile($dir,$r['target']);$parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true))throw new RuntimeException('Cannot create destination');
 bi_progress('copying',basename($r['target']),0,(int)$r['bytes'],true);
 $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'copying','bytes'=>$r['bytes']];savebatch($batch);
 $in=fopen($src,'rb');$to=@fopen($dst,'xb');if(!$in||!$to)throw new RuntimeException('Cannot create exclusive output');
 $hash=hash_init('sha256');$count=0;
 while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false)throw new RuntimeException('Source read failed');if($chunk==='')break;hash_update($hash,$chunk);$len=strlen($chunk);$off=0;while($off<$len){$w=fwrite($to,substr($chunk,$off));if($w===false||$w===0)throw new RuntimeException('Output write failed');$off+=$w;}$count+=$len;bi_progress('copying',basename($r['target']),$count,(int)$r['bytes']);}
 fflush($to);fclose($to);fclose($in);$sha=hash_final($hash);bi_progress('verifying',basename($r['target']),(int)$r['bytes'],(int)$r['bytes'],true);clearstatcache(true,$src);
 if($count!==$r['bytes']||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime']||!hash_equals($sha,hash_file('sha256',$dst)))throw new RuntimeException('Copy verification failed; partial output preserved');
 $batch['operations'][$rid]['state']='copied';$batch['operations'][$rid]['sha256']=$sha;savebatch($batch);return $sha;
}
function mc_fallback(array &$batch,string $dir,string $rid,array $r,string $planned,string $why): void {
 unset($GLOBALS['bi_prog']['extra']);bi_copy_one($batch,$dir,$rid,$r);
 $batch['operations'][$rid]['source_bytes']=$r['bytes'];$batch['operations'][$rid]['source_sha256']=$batch['operations'][$rid]['sha256'];
 $batch['operations'][$rid]['media']=['action'=>'fallback','planned'=>$planned,'error'=>$why,'label'=>'Copied unchanged: conversion failed ('.$why.')'];
 $batch['media_warnings'][]=['rid'=>$rid,'target'=>$r['target'],'message'=>$r['title'].': '.$planned.' failed ('.$why.'). The original was staged unchanged; it may not play in every browser.'];
 $batch['events'][]=gmdate(DATE_ATOM).' Conversion failed for '.$r['target'].' ('.$why.'); original staged unchanged';savebatch($batch);
}
function mc_convert_one(array &$batch,string $dir,string $rid,array $r): void {
 $src=pv_input($r);clearstatcache(true,$src);$planned=(string)(mc_effective($r)['label']??'conversion');
 if(!is_file($src)||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime'])throw new RuntimeException('Source metadata changed: '.$r['source']);
 $dst=destfile($dir,$r['target']);$parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true))throw new RuntimeException('Cannot create destination');
 $work=$dir.'/work';if(!is_dir($work)&&!mkdir($work,0770))throw new RuntimeException('Cannot create the conversion work folder');$part=$work.'/'.$rid.'.mp4';
 $op=$batch['operations'][$rid]??[];
 // A runner stopped after moving the new file in but before saving: finish that step.
 if(file_exists($dst)){if(($op['state']??'')==='converting'&&!empty($op['out_sha256'])&&is_file($dst)&&hash_equals((string)$op['out_sha256'],(string)hash_file('sha256',$dst))){$op['state']='copied';$op['bytes']=(int)$op['out_bytes'];$op['sha256']=$op['out_sha256'];unset($op['out_sha256'],$op['out_bytes']);$batch['operations'][$rid]=$op;savebatch($batch);return;}
  throw new RuntimeException('Destination exists; overwrite forbidden');}
 if(is_file($part))@unlink($part);@unlink($part.'.log');
 $tries=(int)($op['media_tries']??0);
 $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'converting','bytes'=>$r['bytes'],'source_bytes'=>$r['bytes'],'media_tries'=>$tries+1];savebatch($batch);
 if($tries>=MC_RETRIES){mc_fallback($batch,$dir,$rid,$r,$planned,'it was interrupted '.$tries.' times');return;}
 $GLOBALS['bi_prog']['extra']=['label'=>'Checking the original','pct'=>0,'speed'=>'','elapsed'=>0];bi_progress('converting',basename($r['target']),0,(int)$r['bytes'],true);
 $srcSha=(string)hash_file('sha256',$src);$pr=mc_probe($src);$plan=mc_plan($pr);$a=$plan['action'];
 if(!$pr||$a==='none'){mc_fallback($batch,$dir,$rid,$r,$planned,!$pr?'the video details could not be read':'the file no longer needs it');if($pr){$batch['operations'][$rid]['media']=['action'=>'none','label'=>$plan['label']];array_pop($batch['media_warnings']);array_pop($batch['events']);savebatch($batch);}return;}
 $t0=microtime(true);
 $err=mc_ffmpeg(mc_cmd($src,$part,$pr,$a),$pr,basename($r['target']),(int)$r['bytes'],$plan['label']);
 if($err===''){$GLOBALS['bi_prog']['extra']=['label'=>'Checking the new file','pct'=>100,'speed'=>'','elapsed'=>(int)(microtime(true)-$t0)];bi_progress('converting',basename($r['target']),(int)$r['bytes'],(int)$r['bytes'],true);$err=mc_verify($part,$src,$pr,$a);}
 clearstatcache(true,$src);if(!is_file($src)||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime']){@unlink($part);@unlink($part.'.log');throw new RuntimeException('Source changed during conversion: '.$r['source']);}
 if($err!==''){@unlink($part);@unlink($part.'.log');mc_fallback($batch,$dir,$rid,$r,$plan['label'],$err);return;}
 $osha=(string)hash_file('sha256',$part);clearstatcache(true,$part);$obytes=(int)filesize($part);$o=mc_probe($part);
 $batch['operations'][$rid]['out_sha256']=$osha;$batch['operations'][$rid]['out_bytes']=$obytes;savebatch($batch);
 if(file_exists($dst)||!rename($part,$dst))throw new RuntimeException('Cannot move the converted file into staging');
 if(!hash_equals($osha,(string)hash_file('sha256',$dst)))throw new RuntimeException('Converted file changed while moving it into staging');
 @unlink($part.'.log');$secs=round(microtime(true)-$t0,1);
 $media=['action'=>$a,'label'=>$plan['label'],'from'=>mc_codec_name($pr['v']).' '.$pr['w'].'x'.$pr['h'].($pr['a']!==''?' + '.mc_codec_name($pr['a']):''),'to'=>'H.264 '.($o?$o['w'].'x'.$o['h']:'').($o&&$o['a']!==''?' + '.mc_codec_name($o['a']):''),'seconds'=>$secs,'duration'=>round($pr['dur'],3),'settings'=>mc_settings($a)];
 $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'copied','bytes'=>$obytes,'sha256'=>$osha,'source_bytes'=>$r['bytes'],'source_sha256'=>$srcSha,'media'=>$media,'media_tries'=>$tries+1];
 $batch['events'][]=gmdate(DATE_ATOM).' '.$r['target'].': '.$plan['label'].' in '.$secs.' s';savebatch($batch);
 try{if(!is_dir(MC_LEDGER))@mkdir(MC_LEDGER,0770,true);bi_json(mc_ledger_path((int)$r['bytes'],$srcSha),['source_bytes'=>$r['bytes'],'source_sha256'=>$srcSha,'source'=>$r['source'],'batch'=>$batch['id'],'rid'=>$rid,'target'=>$r['target'],'label'=>$plan['label'],'action'=>$a,'out_bytes'=>$obytes,'out_sha256'=>$osha,'settings'=>mc_settings($a),'when'=>gmdate(DATE_ATOM)]);}catch(Throwable $x){}
}
function mc_runner(string $id,string $dir): void {
 $batch=bi_read($dir.'/batch.json');if(($batch['state']??'')!=='converting')return;
 $chosen=array_filter($batch['rows'],fn($r)=>!empty($r['include']));$total=array_sum(array_column($chosen,'bytes'));
 foreach((array)($batch['media_jobs']??[]) as $rid){
  $batch=bi_read($dir.'/batch.json');if(($batch['operations'][$rid]['state']??'')==='copied')continue;$r=$batch['rows'][$rid]??null;if(!is_array($r))throw new RuntimeException('Conversion list does not match the import');
  $GLOBALS['bi_prog']=['dir'=>$dir,'total'=>$total,'base'=>mc_done_bytes($batch),'files_done'=>count(array_filter($batch['operations'],fn($o)=>($o['state']??'')==='copied')),'files_total'=>count($chosen)];
  if(au_is_audio($r))au_convert_one($batch,$dir,$rid,$r);else mc_convert_one($batch,$dir,$rid,$r); // Patch 4o audio
 }
 $batch=bi_read($dir.'/batch.json');foreach($chosen as $rid=>$r)if(!empty($batch['rows'][$rid]['include'])&&($batch['operations'][$rid]['state']??'')!=='copied')throw new RuntimeException('Not every file was staged: '.$r['target']); // Patch 4o audio: a left-out audio file is no longer included
 $n=['convert'=>0,'audio'=>0,'faststart'=>0,'fallback'=>0,'mp3'=>0];foreach($batch['operations'] as $o){$a=(string)($o['media']['action']??'');if(isset($n[$a]))$n[$a]++;}
 @rmdir($dir.'/work');unset($GLOBALS['bi_prog']['extra']);$GLOBALS['bi_prog']['files_done']=count($chosen);bi_progress('done','',0,0,true);
 $batch['state']='complete';$batch['events'][]=gmdate(DATE_ATOM).' Videos ready: '.$n['convert'].' converted, '.$n['audio'].' audio fixed, '.$n['faststart'].' fast start fixed, '.$n['fallback'].' copied unchanged after a failed conversion. Audio: '.$n['mp3'].' converted to MP3, '.count((array)($batch['audio_skipped']??[])).' left out (could not be made playable). Staged copy complete; not yet published to the Edutek library';savebatch($batch);
}
/* Patch 4o audio: begin */
/* Patch 4o audio: audio files are scanned as Music or Audiobooks. Audio a browser can't play is converted to MP3 at import
   (libmp3lame VBR -q:a 4, tags kept, same base name .mp3, single-thread ffmpeg, like the WmaToMp3 step of Finish-LibraryCleanup-r1b).
   A file that can't be read or decoded is "damaged": never ticked, never staged. A failed conversion leaves the item out (never published unplayable). */
const AU_EXT=['mp3','wav','ogg','oga','wma','ra','rm','ape','aiff','aif','aifc','amr','ac3','m4a','m4b','flac','opus','aac','mka','wv','mpc','au'];
const AU_Q='4';const AU_MIN_SEC=0.5;const AU_DECODE_SEC=30;const AU_DUR_TOL=1.0;
const AU_SETTINGS='libmp3lame VBR -q:a '.AU_Q.' (about 128-165 kbit/s), tags kept (-map_metadata 0, ID3v2.3), first audio stream, 1 thread';
function au_is_audio(array $r): bool {return empty($r['support'])&&in_array($r['type']??'',['Music','Audiobooks'],true)&&in_array((string)($r['extension']??''),AU_EXT,true);}
function au_mime(string $ext): ?string {return ['mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg'][$ext]??null;}
function au_type(string $rel,string $root,string $ext): string {
 if($ext==='m4b')return 'Audiobooks';
 foreach(array_merge([basename($root)],explode('/',dirname($rel))) as $p)if(str_contains(preg_replace('/[^a-z0-9]+/','',mb_strtolower($p)),'audiobook'))return 'Audiobooks';
 return 'Music';
}
/* Album / audiobook folder: the first source subfolder (skipping one named Music/Audiobooks), or the chosen folder's name for loose files. */
function au_scan_place(array &$r,string $root): void {
 if(!au_is_audio($r))return;$role=gp_role((string)$r['type']);
 $dirs=array_values(array_filter(explode('/',dirname((string)$r['source'])),fn($p)=>$p!=='.'&&$p!==''));
 while($dirs&&in_array(preg_replace('/[^a-z]+/','',mb_strtolower($dirs[0])),['music','audiobooks','audiobook'],true))array_shift($dirs);
 $name=$dirs[0]??basename($root);if(in_array(preg_replace('/[^a-z]+/','',mb_strtolower($name)),['music','audiobooks','audiobook',''],true))$name=$r['type']==='Music'?'Imported Music':'Imported Audiobooks';
 $f=gp_clean_part($name,$role.'/');if($f===''||$f==='.'||$f==='..')$f=$r['type']==='Music'?'Imported Music':'Imported Audiobooks';
 $r['placement_category']=$role;$r['placement_folder']=$f;$r['placement_override']=false;$r['auto_category']=$role;
}
function au_codec_name(string $c): string {
 return ['wmav1'=>'WMA','wmav2'=>'WMA','wmapro'=>'WMA Pro','wmalossless'=>'WMA Lossless','wmavoice'=>'WMA Voice','cook'=>'RealAudio','ra_144'=>'RealAudio','ra_288'=>'RealAudio','sipr'=>'RealAudio','ape'=>"Monkey's Audio (APE)",'amr_nb'=>'AMR','amr_wb'=>'AMR-WB','ac3'=>'AC-3','eac3'=>'E-AC-3','aac'=>'AAC','alac'=>'ALAC','flac'=>'FLAC','opus'=>'Opus','vorbis'=>'Vorbis','mp3'=>'MP3','mp2'=>'MP2','wavpack'=>'WavPack','musepack7'=>'Musepack','musepack8'=>'Musepack'][$c]??(str_starts_with($c,'pcm_')?'PCM':($c===''?'unknown':strtoupper($c)));
}
function au_probe(string $p): ?array {
 [$c,$o]=mc_run(['ffprobe','-v','error','-show_entries','format=duration,format_name:stream=index,codec_type,codec_name,channels,sample_rate,bit_rate:stream_disposition=attached_pic','-of','json',$p],120);
 if($c!==0)return null;$j=json_decode($o,true);if(!is_array($j))return null;$a=null;
 foreach($j['streams']??[] as $s)if(($s['codec_type']??'')==='audio'&&empty($s['disposition']['attached_pic'])){$a=$s;break;}
 if(!$a)return null;
 return ['a'=>(string)($a['codec_name']??''),'ai'=>(int)$a['index'],'ch'=>(int)($a['channels']??0),'rate'=>(int)($a['sample_rate']??0),'dur'=>(float)($j['format']['duration']??0),'fmt'=>(string)($j['format']['format_name']??'')];
}
/* Decodes the first AU_DECODE_SEC seconds; returns [error or '', seconds decoded]. A truncated file stops early. */
function au_decode(string $p,int $ai,float $limit=AU_DECODE_SEC): array {
 [$c,$o,$e]=mc_run(['ffmpeg','-nostdin','-hide_banner','-v','error','-xerror','-threads','1','-i',$p,'-map','0:'.$ai,'-t',(string)$limit,'-progress','pipe:1','-nostats','-f','null','-'],max(300,(int)($limit*4)));
 $us=0;if(preg_match_all('/^out_time_us=(\d+)/m',(string)$o,$m))$us=(int)end($m[1]);
 return [$c===0?'':'it does not decode'.(trim((string)$e)!==''?' ('.trim(substr(preg_replace('/\s+/',' ',(string)$e),0,160)).')':''),$us/1e6];
}
/* keep = plays in browsers and the publisher accepts the extension (mp3, wav, ogg) */
function au_keep(string $ext,string $codec): bool {
 if($ext==='mp3')return $codec==='mp3';
 if($ext==='wav')return str_starts_with($codec,'pcm_')&&!str_ends_with($codec,'be')&&$codec!=='pcm_s8';
 if($ext==='ogg')return in_array($codec,['vorbis','opus'],true);
 return false;
}
function au_plan(?array $pr,string $ext,string $decErr='',float $decSec=-1.0): array {
 $dmg=['action'=>'damaged','label'=>'Can’t be played — file is damaged'];
 if(!$pr)return $dmg+['why'=>'the audio details could not be read (ffprobe found no playable audio)'];
 if($decErr!=='')return $dmg+['why'=>$decErr,'acodec'=>$pr['a']];
 $want=min(AU_DECODE_SEC,$pr['dur']);
 if($pr['dur']>=AU_MIN_SEC&&$decSec>=0&&$decSec<$want-1.5)return $dmg+['why'=>sprintf('it is cut short: it should last %.1f s but only %.1f s of sound could be read',$pr['dur'],$decSec),'acodec'=>$pr['a']];
 if($pr['dur']<AU_MIN_SEC||($decSec>=0&&$decSec<AU_MIN_SEC))return $dmg+['why'=>sprintf('it holds only %.2f s of sound',max(0,$decSec>=0?$decSec:$pr['dur'])),'acodec'=>$pr['a']];
 if($decSec>=0&&$decSec<$want-1.5)return $dmg+['why'=>sprintf('it is cut short: it should last %.1f s but only %.1f s of sound could be read',$pr['dur'],$decSec),'acodec'=>$pr['a']];
 $base=['acodec'=>$pr['a'],'channels'=>$pr['ch'],'sample_rate'=>$pr['rate'],'duration'=>round($pr['dur'],3)];
 if(au_keep($ext,$pr['a']))return $base+['action'=>'none','label'=>'Ready for browsers ('.au_codec_name($pr['a']).')'];
 return $base+['action'=>'mp3','label'=>strtoupper($ext).(strcasecmp(au_codec_name($pr['a']),$ext)===0?'':' ('.au_codec_name($pr['a']).')').' → MP3','from'=>au_codec_name($pr['a'])];
}
/* Planned once per source version (bytes+mtime) at scan and on save. Returns true when the planned file name changed. */
function au_row_plan(array &$r): bool {
 if(!au_is_audio($r))return false;$m=$r['media']??null;
 if(is_array($m)&&($m['src_bytes']??null)===$r['bytes']&&($m['src_mtime']??null)===$r['mtime'])return false;
 $old=(string)($r['out_ext']??'');
 try{$p=pv_input($r);$pr=au_probe($p);[$de,$ds]=$pr?au_decode($p,$pr['ai']):['',-1.0];}catch(Throwable $e){$pr=null;$de='';$ds=-1.0;}
 $r['media']=au_plan($pr,(string)$r['extension'],$de,$ds)+['src_bytes'=>$r['bytes'],'src_mtime'=>$r['mtime'],'kind'=>'audio'];
 if($r['media']['action']==='mp3')$r['out_ext']='mp3';else unset($r['out_ext']);
 if($r['media']['action']==='damaged'){$r['damaged']=true;$r['include']=false;}else unset($r['damaged']);
 return $old!==(string)($r['out_ext']??'');
}
function au_badge_html(array $r): string {
 $m=$r['media']??null;if(!is_array($m))return '';$a=(string)($m['action']??'');
 if($a==='damaged')return '<span class="badge media-badge audio-badge damaged-badge" style="background:#fde2e2;color:#7f1d1d">'.h('Can’t be played — file is damaged').'</span>';
 if($a==='mp3')return '<span class="badge media-badge audio-badge" style="background:#fde8c4;color:#7a4300">'.h('Will be converted to MP3: '.$m['label']).'</span>';
 return '<span class="badge media-badge audio-badge" style="background:#d4efdc;color:#14532d">'.h((string)$m['label']).'</span>';
}
function au_cmd(string $src,string $out,array $pr): array {
 $c=['ffmpeg','-nostdin','-hide_banner','-v','error','-threads','1','-n','-i',$src,'-map','0:'.$pr['ai'],'-map_metadata','0','-c:a','libmp3lame','-q:a',AU_Q];
 if($pr['ch']>2)array_push($c,'-ac','2');
 array_push($c,'-id3v2_version','3','-threads','1','-progress','pipe:1','-nostats','-f','mp3',$out);return $c;
}
function au_verify(string $out,array $src): string {
 $o=au_probe($out);if(!$o)return 'the new MP3 could not be read';
 if($o['a']!=='mp3')return 'the new file is '.au_codec_name($o['a']).', not MP3';
 if(abs($o['dur']-$src['dur'])>AU_DUR_TOL)return sprintf('the new file lasts %.2f s instead of %.2f s',$o['dur'],$src['dur']);
 [$de,$ds]=au_decode($out,$o['ai'],max(1.0,$src['dur']+5));if($de!=='')return 'the new MP3 '.$de;
 if(abs($ds-$src['dur'])>AU_DUR_TOL)return sprintf('the new MP3 plays %.2f s instead of %.2f s',$ds,$src['dur']);
 return '';
}
/* Leaves a row out of the import (its artwork too) after it could not be made playable. Nothing unplayable is staged. */
function au_skip(array &$batch,string $dir,string $rid,array $r,string $why): void {
 unset($GLOBALS['bi_prog']['extra'],$batch['operations'][$rid]);$batch['rows'][$rid]['include']=false;$batch['rows'][$rid]['audio_skipped']=$why;
 foreach($batch['rows'] as $sid=>$s)if(!empty($s['support'])&&($s['parent']??'')===$rid){$batch['rows'][$sid]['include']=false;
  if(isset($batch['operations'][$sid])){$sp=destfile($dir,(string)$batch['operations'][$sid]['target']);if(is_file($sp)){@mkdir($dir.'/recovery',0770,true);@rename($sp,$dir.'/recovery/skipped-'.$sid.'-'.basename($sp));}unset($batch['operations'][$sid]);}}
 $batch['approval']=hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR));
 $msg=$r['title'].' ('.basename((string)$r['source']).'): can’t be converted to MP3 ('.$why.'). It was left out of this import, so nothing unplayable is published. Your source file is unchanged.';
 $batch['media_warnings'][]=['rid'=>$rid,'target'=>$r['target'],'message'=>$msg,'audio_skip'=>true];$batch['audio_skipped'][]=['rid'=>$rid,'title'=>$r['title'],'source'=>$r['source'],'why'=>$why];
 $batch['events'][]=gmdate(DATE_ATOM).' Audio left out: '.$r['source'].' ('.$why.'); nothing staged for it';savebatch($batch);
}
function au_convert_one(array &$batch,string $dir,string $rid,array $r): void {
 $src=pv_input($r);clearstatcache(true,$src);
 if(!is_file($src)||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime'])throw new RuntimeException('Source metadata changed: '.$r['source']);
 $dst=destfile($dir,$r['target']);$parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true))throw new RuntimeException('Cannot create destination');
 $work=$dir.'/work';if(!is_dir($work)&&!mkdir($work,0770))throw new RuntimeException('Cannot create the conversion work folder');$part=$work.'/'.$rid.'.mp3';
 $op=$batch['operations'][$rid]??[];
 if(file_exists($dst)){if(($op['state']??'')==='converting'&&!empty($op['out_sha256'])&&is_file($dst)&&hash_equals((string)$op['out_sha256'],(string)hash_file('sha256',$dst))){$op['state']='copied';$op['bytes']=(int)$op['out_bytes'];$op['sha256']=$op['out_sha256'];unset($op['out_sha256'],$op['out_bytes']);$batch['operations'][$rid]=$op;savebatch($batch);return;}
  throw new RuntimeException('Destination exists; overwrite forbidden');}
 if(is_file($part))@unlink($part);@unlink($part.'.log');
 $tries=(int)($op['media_tries']??0);
 $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'converting','bytes'=>$r['bytes'],'source_bytes'=>$r['bytes'],'media_tries'=>$tries+1];savebatch($batch);
 if($tries>=MC_RETRIES){au_skip($batch,$dir,$rid,$r,'it was interrupted '.$tries.' times');return;}
 $GLOBALS['bi_prog']['extra']=['label'=>'Checking the original','pct'=>0,'speed'=>'','elapsed'=>0];bi_progress('converting',basename($r['target']),0,(int)$r['bytes'],true);
 $srcSha=(string)hash_file('sha256',$src);$pr=au_probe($src);
 if(!$pr){au_skip($batch,$dir,$rid,$r,'the audio details could not be read');return;}
 [$de,$ds]=au_decode($src,$pr['ai']);$plan=au_plan($pr,(string)$r['extension'],$de,$ds);
 if($plan['action']!=='mp3'){au_skip($batch,$dir,$rid,$r,$plan['action']==='damaged'?'the file is damaged: '.($plan['why']??''):'the file changed since it was checked');return;}
 $t0=microtime(true);
 $err=mc_ffmpeg(au_cmd($src,$part,$pr),['dur'=>$pr['dur']],basename($r['target']),(int)$r['bytes'],$plan['label']);
 if($err===''){$GLOBALS['bi_prog']['extra']=['label'=>'Checking the new MP3','pct'=>100,'speed'=>'','elapsed'=>(int)(microtime(true)-$t0)];bi_progress('converting',basename($r['target']),(int)$r['bytes'],(int)$r['bytes'],true);$err=au_verify($part,$pr);}
 clearstatcache(true,$src);if(!is_file($src)||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime']){@unlink($part);@unlink($part.'.log');throw new RuntimeException('Source changed during conversion: '.$r['source']);}
 if($err!==''){@unlink($part);@unlink($part.'.log');au_skip($batch,$dir,$rid,$r,$err);return;}
 $osha=(string)hash_file('sha256',$part);clearstatcache(true,$part);$obytes=(int)filesize($part);$o=au_probe($part);
 $batch['operations'][$rid]['out_sha256']=$osha;$batch['operations'][$rid]['out_bytes']=$obytes;savebatch($batch);
 if(file_exists($dst)||!rename($part,$dst))throw new RuntimeException('Cannot move the converted file into staging');
 if(!hash_equals($osha,(string)hash_file('sha256',$dst)))throw new RuntimeException('Converted file changed while moving it into staging');
 @unlink($part.'.log');$secs=round(microtime(true)-$t0,1);
 $media=['action'=>'mp3','label'=>$plan['label'],'from'=>au_codec_name($pr['a']).' '.$pr['rate'].' Hz '.$pr['ch'].' ch','to'=>'MP3'.($o?' '.round($o['dur'],2).' s':''),'seconds'=>$secs,'duration'=>round($pr['dur'],3),'out_duration'=>$o?round($o['dur'],3):null,'settings'=>AU_SETTINGS];
 $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'copied','bytes'=>$obytes,'sha256'=>$osha,'source_bytes'=>$r['bytes'],'source_sha256'=>$srcSha,'media'=>$media,'media_tries'=>$tries+1];
 $batch['events'][]=gmdate(DATE_ATOM).' '.$r['target'].': '.$plan['label'].' in '.$secs.' s';savebatch($batch);
}
/* Patch 4o audio: end */
/* ---------- Patch 4p: damaged videos, left-out files, Books shelves chosen by the user, import summary links ---------- */
const P4_VIDEO_EXT=['mov','mkv','avi','wmv','m4v','webm','flv','3gp','mpg','mpeg','ts','vob','ogv','mts','m2ts','divx','asf','rm','rmvb'];
const P4_IMAGE_EXT=['jpg','jpeg','png','webp','gif','bmp'];
const P4_SITE_DEFAULT='http://host.docker.internal:8080/'; // the Edutek site as the importer container reaches it (read-only page fetches, 3 s timeout)
const P4_LINK_TTL=90;
/* Why a file the scan found is not imported. Every such file is listed (never silently skipped). */
function p4_left_reason(string $ext): string {
 $ext=strtolower($ext);
 if(in_array($ext,P4_VIDEO_EXT,true))return 'only .mp4 videos are imported. Convert it to .mp4 first'.(in_array($ext,['mov','mkv'],true)?' (a later patch could convert .mov/.mkv with the 4i conversion)':'').'.';
 if(in_array($ext,P4_IMAGE_EXT,true))return 'a picture with no video or book of the same name (pictures are only used as cover art).';
 return 'not a video (.mp4), PDF book or audio file.';
}
function p4_left_add(array &$lo,int &$n,string $rel,string $ext,string $root): void {$n++;if(count($lo)<1000)$lo[]=['source'=>$rel,'src_root'=>$root,'ext'=>strtolower($ext),'why'=>p4_left_reason($ext)];}
/* After attach_art: a picture used as cover art is not "left out". */
function p4_left_finish(array &$b,array $lo,int $n): void {
 $used=[];foreach($b['rows'] as $r)foreach((array)($r['art_options']??[]) as $o)$used[(string)($o['src_root']??'').'|'.(string)($o['source']??'')]=true;
 $out=[];foreach($lo as $x){if(isset($used[$x['src_root'].'|'.$x['source']])){$n--;continue;}$out[]=['source'=>$x['source'],'why'=>$x['why'],'ext'=>$x['ext']];}
 $b['left_out']=$out;$b['left_out_n']=max($n,count($out));
}
/* A video whose details can't be read is damaged: never ticked, never published. Returns '' when the file looks complete. */
function p4_mp4_integrity(string $p): string {
 $size=@filesize($p);if($size===false)return 'the file could not be opened.';if($size<16)return 'the file is empty or cut short.';
 $fp=@fopen($p,'rb');if(!$fp)return 'the file could not be opened.';$pos=0;$moov=false;$n=0;$why='';
 while($pos+8<=$size&&$n<4096){$n++;
  if(fseek($fp,$pos)!==0)break;$hd=fread($fp,8);if(!is_string($hd)||strlen($hd)<8)break;
  $len=unpack('N',substr($hd,0,4))[1];$type=substr($hd,4,4);
  if($len===1){$x=fread($fp,8);if(!is_string($x)||strlen($x)<8){$why='the file is cut short (truncated).';break;}$len=unpack('J',$x)[1];}elseif($len===0)$len=$size-$pos;
  if(!preg_match('/^[\x20-\x7e]{4}$/D',$type)||$len<8){if($n===1)$why='it is not a valid MP4 video file.';break;}
  if($type==='moov')$moov=true;
  if($pos+$len>$size){$why='the file is cut short (truncated)'.($type==='mdat'?': part of the picture and sound data is missing.':'.');break;}
  $pos+=$len;}
 fclose($fp);
 if($why==='' && !$moov)$why='the video index (moov) is missing: the file is incomplete.';
 return $why;
}
function p4_probe_works(): bool {static $ok=null;if($ok===null){[$c]=mc_run(['ffprobe','-version'],15);$ok=$c===0;}return $ok;} // a missing ffprobe never marks videos damaged
function p4_shelves(): array {$d=GP_LIB_DIR.'/'.gp_role('Books');$o=[];$h=is_dir($d)?@opendir($d):false;if($h){while(($e=readdir($h))!==false){if($e==='.'||$e==='..'||$e[0]==='.'||$e[0]==='$')continue;if(!is_link($d.'/'.$e)&&is_dir($d.'/'.$e))$o[]=$e;}closedir($h);}usort($o,'strnatcasecmp');return $o;}
function p4_shelf_match(string $v): ?string {foreach(p4_shelves() as $s)if(keyname($s)===keyname($v))return $s;return null;}
function p4_check_shelf(string $v): array {
 $v=trim(preg_replace('/\s+/u',' ',$v));
 if($v==='')return ['ok'=>false,'message'=>'Type a name for the shelf first.'];
 if(mb_strlen($v)>120)return ['ok'=>false,'message'=>'That name is too long (120 characters at most).'];
 if(str_contains($v,'/')||str_contains($v,chr(92))){$s=sn_component(str_replace(['/',chr(92)],' ',$v));return ['ok'=>false,'message'=>'A shelf is one folder name: / and \ are not allowed. Suggested name: '.$s,'suggest'=>$s];}
 $have=p4_shelf_match($v);if($have!==null)return ['ok'=>true,'name'=>$have,'exists'=>true,'message'=>'“'.$have.'” is already a Books shelf, so it was chosen.'];
 $i=sn_info($v,false);$s=$i['clean'];
 if($s!==$v){$have=p4_shelf_match($s);if($have!==null)return ['ok'=>true,'name'=>$have,'exists'=>true,'message'=>'“'.$have.'” is already a Books shelf, so it was chosen.'];
  return ['ok'=>false,'message'=>'“'.$v.'” does not follow the Edutek naming rule'.($i['review']?' ('.implode('; ',$i['review']).')':'').'. Suggested name: '.$s.'. Press Enter to use it, or type another name.','suggest'=>$s];}
 if($i['review'])return ['ok'=>false,'message'=>'“'.$v.'” cannot be used ('.implode('; ',$i['review']).'). Type another name.'];
 try{gp_validate('Books',gp_role('Books'),$v);}catch(RuntimeException $e){return ['ok'=>false,'message'=>$e->getMessage()];}
 return ['ok'=>true,'name'=>$v,'exists'=>false,'message'=>'New shelf “'.$v.'”. The folder is created only when you publish.'];
}
/* What publishing will create that does not exist yet (recorded at "execute", shown before and after publishing). */
function p4_new_places(array $b): array {
 $cats=[];$shelves=[];$subs=[];$broot=GP_LIB_DIR.'/'.gp_role('Books');
 foreach(learning((array)($b['rows']??[])) as $r){if(empty($r['include']))continue;$t=(string)($r['type']??'');$c=(string)($r['placement_category']??'');$f=(string)($r['placement_folder']??'');
  if($t==='Books'){if($f!==''&&p4_shelf_match($f)===null)$shelves[keyname($f)]??=$f;continue;}
  if(gp_role($t)!==''||$c==='')continue;
  if(gp_category_match($c,true)===null){$cats[keyname($c)]??=$c;$sf=explode('/',gp_folder($r))[0];if($sf!=='')$subs[keyname($c.'/'.$sf)]??=$c.' › '.$sf;}}
 return ['cats'=>array_values($cats),'shelves'=>array_values($shelves),'subs'=>array_values($subs)];
}
/* ---- links to the Edutek site, read from the site's own pages (no hard-coded IP: the browser uses the host it opened this page with, port 8080) ---- */
function p4_site(): string {$s=(string)(getenv('P4P_SITE')?:P4_SITE_DEFAULT);return rtrim($s,'/').'/';}
function p4_get(string $url): ?string {$ctx=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>false,'follow_location'=>0,'header'=>"User-Agent: edutek-importer-4p\r\n"]]);$t=@file_get_contents($url,false,$ctx,0,4*1024*1024);return is_string($t)?$t:null;}
function p4_parse_links(string $dir,string $books,string $music): array {
 $o=['cats'=>[],'shelves'=>[],'albums'=>[]];$dec=fn($x)=>html_entity_decode($x,ENT_QUOTES|ENT_HTML5,'UTF-8');
 if(preg_match_all('/<li class="dir-item"\s+data-label="([^"]*)">\s*<a href="([^"]+)"/u',$dir,$m,PREG_SET_ORDER))foreach($m as $x){$k=keyname($dec($x[1]));if($k!=='')$o['cats'][$k]??=$dec($x[2]);}
 if(preg_match_all('/href="(listofbooks\.php\?[^"]+)"><img class="book-category-card__image" src="book_thumb\.php\?folder=([^"]+)"/u',$books,$m,PREG_SET_ORDER))foreach($m as $x){$k=keyname(rawurldecode($dec($x[2])));if($k!=='')$o['shelves'][$k]??=$dec($x[1]);}
 if(preg_match_all('/<a class="music-thumb-link" href="(listen\.php\?[^"]+)">\s*<img src="videos\/Music\/([^\/"]+)\//u',$music,$m,PREG_SET_ORDER))foreach($m as $x){$k=keyname(rawurldecode($dec($x[2])));if($k!=='')$o['albums'][$k]??=$dec($x[1]);}
 return $o;
}
function p4_links(bool $fresh=false): array {
 $cf=BI_ROOT.'/p4p-links-cache.json';$c=null;if(is_file($cf)){$c=json_decode((string)@file_get_contents($cf),true);if(!is_array($c))$c=null;}
 $age=$c?time()-(int)($c['at']??0):PHP_INT_MAX;
 if($c&&($age<P4_LINK_TTL&&!($fresh&&$age>=10)))return $c;
 $site=p4_site();$d=p4_get($site.'directory.php');$bk=p4_get($site.'books.php');$mu=p4_get($site.'music.php');
 if($d===null&&$bk===null&&$mu===null){$o=['ok'=>false,'at'=>time(),'cats'=>[],'shelves'=>[],'albums'=>[],'note'=>'The Edutek site could not be reached from the importer, so links open the site’s Directory page.'];}
 else{$o=p4_parse_links((string)$d,(string)$bk,(string)$mu)+['ok'=>$d!==null,'at'=>time()];$o['note']=$d===null?'The Directory page could not be read, so category links open the Directory page.':'';}
 $t=$cf.'.'.bin2hex(random_bytes(4)).'.tmp';if(@file_put_contents($t,json_encode($o,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))!==false)@rename($t,$cf);
 return $o;
}
function p4_slug(string $s): string {return (string)preg_replace('/[^a-z0-9]+/','-',mb_strtolower($s));} // same as the site's section anchors (#tut-sec-<slug>)
/* The import summary shown on the Done screen (read-only; built from this import's own batch and publish status). */
function p4_summary(array $b,array $status,?array $links): array {
 $lc=fn($x)=>keyname((string)$x);$groups=[];$docs=[];$tot=['videos'=>0,'books'=>0,'music'=>0,'audiobooks'=>0,'converted'=>0,'faststart'=>0,'mp3'=>0,'kept'=>0,'replaced'=>0];
 $new=is_array($b['p4_new']??null)?$b['p4_new']:p4_new_places($b);$newCats=array_map($lc,(array)($new['cats']??[]));$newShelves=array_map($lc,(array)($new['shelves']??[]));
 foreach(learning((array)($b['rows']??[])) as $rid=>$r){if(empty($r['include']))continue;$op=$b['operations'][$rid]??null;if(!is_array($op)||($op['state']??'')!=='copied')continue;
  $t=(string)$r['type'];$c=(string)$r['placement_category'];$f=(string)$r['placement_folder'];$a=(string)($op['media']['action']??($r['media']['action']??'none'));
  $sub=$t==='Videos'?explode('/',gp_folder($r))[0]:$f;$key=$t.'|'.$c;
  if(!isset($groups[$key]))$groups[$key]=['type'=>$t,'cat'=>$c,'new'=>$t==='Videos'?in_array($lc($c),$newCats,true):false,'n'=>0,'subs'=>[]];
  $g=&$groups[$key];$g['n']++;if(!isset($g['subs'][$sub]))$g['subs'][$sub]=['sub'=>$sub,'n'=>0,'cv'=>0,'fs'=>0,'mp3'=>0,'new'=>$t==='Books'&&in_array($lc($sub),$newShelves,true),'slug'=>p4_slug($sub)];
  $s=&$g['subs'][$sub];$s['n']++;
  if(in_array($a,['convert','audio'],true)){$s['cv']++;$tot['converted']++;}elseif($a==='faststart'){$s['fs']++;$tot['faststart']++;}elseif($a==='mp3'){$s['mp3']++;$tot['mp3']++;}else $tot['kept']++;
  unset($s,$g);
  $tot[['Videos'=>'videos','Books'=>'books','Music'=>'music','Audiobooks'=>'audiobooks'][$t]??'videos']++;if(!empty($r['replace']))$tot['replaced']++;
  if($t==='Books'&&count($docs)<300)$docs[]=['t'=>(string)$r['title'],'shelf'=>$f,'file'=>basename((string)$r['target'])];
 }
 $order=['Videos'=>0,'Books'=>1,'Music'=>2,'Audiobooks'=>3];uasort($groups,fn($x,$y)=>[$order[$x['type']]??0,mb_strtolower($x['cat'])]<=>[$order[$y['type']]??0,mb_strtolower($y['cat'])]);
 $out=[];foreach($groups as $g){$g['subs']=array_values($g['subs']);usort($g['subs'],fn($x,$y)=>strnatcasecmp($x['sub'],$y['sub']));$out[]=$g;}
 $left=p4_left_list($b);$skippedByYou=0;foreach(learning((array)($b['rows']??[])) as $r)if(empty($r['include'])&&empty($r['damaged'])&&empty($r['audio_skipped']))$skippedByYou++;
 $started=(string)($b['publish_started']??($status['started']??''));$finished=(string)($status['finished']??'');$secs=null;
 if($started!==''&&$finished!==''){$x=strtotime($started);$y=strtotime($finished);if($x&&$y&&$y>=$x)$secs=$y-$x;}
 return ['groups'=>$out,'docs'=>$docs,'tot'=>$tot,'left'=>$left,'leftN'=>count($left)+max(0,(int)($b['left_out_n']??0)-count((array)($b['left_out']??[]))),'skippedByYou'=>$skippedByYou,
  'started'=>$started,'finished'=>$finished,'secs'=>$secs,'startedFrom'=>isset($b['publish_started'])?'add':'publish','newCats'=>array_values((array)($new['cats']??[])),'newShelves'=>array_values((array)($new['shelves']??[])),
  'source'=>(string)($b['source']??''),'links'=>$links?p4_links_for($links,$out):null];
}
/* Only the links this summary needs (category, shelf and album pages; relative to the site root). */
function p4_links_for(array $L,array $groups): array {
 $o=['ok'=>(bool)($L['ok']??false),'note'=>(string)($L['note']??''),'cats'=>[],'shelves'=>[],'albums'=>[]];
 foreach($groups as $g){if($g['type']==='Videos'){$o['cats'][$g['cat']]=$L['cats'][keyname($g['cat'])]??null;}
  elseif($g['type']==='Books'){foreach($g['subs'] as $s)$o['shelves'][$s['sub']]=$L['shelves'][keyname($s['sub'])]??null;}
  elseif($g['type']==='Music'){foreach($g['subs'] as $s)$o['albums'][$s['sub']]=$L['albums'][keyname($s['sub'])]??null;}}
 return $o;
}
/* Everything this import did not add, with the reason: files the importer does not import, damaged files, audio that could not be converted. */
function p4_left_list(array $b): array {
 $o=[];foreach(learning((array)($b['rows']??[])) as $r){if(!empty($r['damaged']))$o[]=['f'=>(string)$r['source'],'why'=>'Can’t be played — file is damaged: '.rtrim((string)($r['media']['why']??'the file could not be read'),'.').'. It was never ticked.','k'=>'damaged'];
  elseif(!empty($r['audio_skipped']))$o[]=['f'=>(string)$r['source'],'why'=>'Could not be made playable: '.(string)$r['audio_skipped'],'k'=>'audio'];}
 foreach((array)($b['left_out']??[]) as $x)if(is_array($x))$o[]=['f'=>(string)($x['source']??''),'why'=>'Not imported: '.(string)($x['why']??''),'k'=>in_array((string)($x['ext']??''),P4_VIDEO_EXT,true)?'video':'other'];
 return $o;
}
function mc_cli(array $argv): int {
 if(($argv[1]??'')!=='--media-runner'||!preg_match('/^[a-f0-9]{32}$/D',(string)($argv[2]??''))){fwrite(STDERR,"usage: php bulk-import-guided.php --media-runner <batch id>\n");return 2;}
 $id=(string)$argv[2];$dir=batchdir($id);if(!is_file($dir.'/batch.json'))return 2;
 $lk=fopen($dir.'/convert.lock','c');if(!$lk||!flock($lk,LOCK_EX|LOCK_NB))return 0;
 if(function_exists('proc_nice'))@proc_nice(MC_NICE);
 echo gmdate(DATE_ATOM)," runner start ",$id,"\n";
 try{mc_runner($id,$dir);}
 catch(Throwable $e){echo gmdate(DATE_ATOM)," runner error: ",$e->getMessage(),"\n";
  try{$b=bi_read($dir.'/batch.json');if(($b['state']??'')==='converting'){$b['state']='interrupted';$b['events'][]=gmdate(DATE_ATOM).' '.$e->getMessage();savebatch($b);}}catch(Throwable $x){}}
 finally{flock($lk,LOCK_UN);fclose($lk);}
 echo gmdate(DATE_ATOM)," runner end\n";return 0;
}
if(PHP_SAPI==='cli'){exit(mc_cli($argv??[]));}
if(!is_dir(REAL_ROOT)&&!mkdir(REAL_ROOT,0770,true))throw new RuntimeException('Cannot create import staging storage');
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

 if(isset($_GET['catcheck'])){ // Patch 4k: New category… name check (read-only)
  if(!hash_equals($csrf,(string)($_GET['token']??''))){http_response_code(403);exit;}
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(gp_check_category((string)$_GET['catcheck']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
 }
 if(isset($_GET['progress'])&&$batch){
  if(!hash_equals($csrf,(string)($_GET['token']??''))){http_response_code(403);exit;}
  if(($batch['state']??'')==='converting'){try{if(!mc_running(batchdir($id)))mc_spawn($id);}catch(Throwable $e){}}
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
  $ext=strtolower(pathinfo($p,PATHINFO_EXTENSION));header('Content-Type: '.($ext==='pdf'?'application/pdf':(au_mime($ext)??'video/mp4')));header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));session_write_close();
  $fp=fopen($p,'rb');fseek($fp,$start);$left=$end-$start+1;while($left>0&&!feof($fp)){$chunk=fread($fp,min(1048576,$left));if($chunk===false||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}fclose($fp);exit;
 }
 // Patch 4d: drive watcher requests (refresh, prepare to eject, connect again) and their status for the folder browser.
 // Patch 4e: Browse... asks the drive watcher to open the Windows folder window (one at a time) and polls for the answer.
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&(string)($_POST['action']??'')==='pick_folder'){
  header('Content-Type: application/json');header('Cache-Control: no-store');
  if(!hash_equals($csrf,(string)($_POST['csrf']??''))){http_response_code(403);echo json_encode(['error'=>'Session expired. Reload the page.']);exit;}
  session_write_close();
  try{$hs=hd_status();if($hs['watcher_age']===null||$hs['watcher_age']>60)throw new RuntimeException('The folder window cannot open because the drive watcher task is not running. Start the Edutek Import Drive Watcher task (or sign out and back in), then choose Browse… again');
   echo json_encode(['id'=>hp_request(trim(is_string($_POST['start']??null)?$_POST['start']:'','/'))]);}
  catch(Throwable $e){http_response_code(409);echo json_encode(['error'=>rtrim($e->getMessage(),'.').'.']);}
  exit;
 }
 // Patch 4l: a new category typed on any card is saved with the import at once (shared by every card, kept after a reload). Same name check as saving.
 // Only a draft is written here; a reviewed (approved) or staged batch is never changed by this check (its page keeps the name until Save progress).
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&(string)($_POST['action']??'')==='propose_category'){
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
  if(!hash_equals($csrf,(string)($_POST['csrf']??''))){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Session expired. Reload the page.']);exit;}
  session_write_close();$pname=substr((string)($_POST['name']??''),0,1000);
  try{
   $res=null;$saved=false;
   if($batch&&($batch['state']??'')==='draft'){
    $plock=fopen(REAL_ROOT.'/actions.lock','c');$got=false;for($pi=0;$plock&&$pi<20;$pi++){if(flock($plock,LOCK_EX|LOCK_NB)){$got=true;break;}usleep(250000);}
    try{if($got){$pb=bi_read(batchdir($id).'/batch.json');if(($pb['state']??'')==='draft'){$res=gp_propose($pb,$pname);if(!empty($res['added']))savebatch($pb);$saved=$res['ok']&&empty($res['exists']);$batch=$pb;}}}
    finally{if($plock){if($got)flock($plock,LOCK_UN);fclose($plock);}}
   }
   if($res===null){$res=gp_check_category($pname);if($res['ok']&&empty($res['exists'])&&$batch)foreach(gp_batch_newcats($batch) as $pc)if(keyname($pc)===keyname($res['name'])){$res['name']=$pc;break;}}
   unset($res['added']);$res['saved']=$saved;$res['new_categories']=$batch?gp_batch_newcats($batch):[];
   echo json_encode($res,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  }catch(Throwable $e){http_response_code(409);echo json_encode(['ok'=>false,'message'=>rtrim($e->getMessage(),'.').'.'],JSON_UNESCAPED_UNICODE);}
  exit;
 }
 // Patch 4p: a new Books shelf name is checked with the same naming rule (nothing is saved or created here).
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&(string)($_POST['action']??'')==='check_shelf'){
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
  if(!hash_equals($csrf,(string)($_POST['csrf']??''))){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Session expired. Reload the page.']);exit;}
  session_write_close();
  try{echo json_encode(p4_check_shelf(substr((string)($_POST['name']??''),0,1000)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
  catch(Throwable $e){http_response_code(409);echo json_encode(['ok'=>false,'message'=>rtrim($e->getMessage(),'.').'.'],JSON_UNESCAPED_UNICODE);}
  exit;
 }
 // Patch 4p: links to the Edutek site for the Done screen (read from the site's Directory, Books and Music pages; read-only).
 if(isset($_GET['p4links'])){if(!hash_equals($csrf,(string)($_GET['token']??''))){http_response_code(403);exit;}header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');session_write_close();
  try{$L4=p4_links(true);$g4=$batch?p4_summary($batch,[],null)['groups']:[];echo json_encode(p4_links_for($L4,$g4),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false]);}
  exit;}
 if(isset($_GET['pickstatus'])){if(!hash_equals($csrf,(string)($_GET['token']??''))){http_response_code(403);exit;}header('Content-Type: application/json');header('Cache-Control: no-store');session_write_close();echo json_encode(hp_status(is_string($_GET['req']??null)?$_GET['req']:''));exit;}
 if(isset($_GET['drivestatus'])){if(!hash_equals($csrf,(string)($_GET['token']??''))){http_response_code(403);exit;}header('Content-Type: application/json');session_write_close();echo json_encode(hd_status());exit;}
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&str_starts_with((string)($_POST['action']??''),'drive_')){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Reload.');
  $hdAct=substr((string)$_POST['action'],6);$hdL=strtolower((string)($_POST['drive']??''));
  hd_request($hdAct,$hdL);
  session_write_close();header('Location: /bulk-import-guided.php?drivewait=1#drives');exit;
 }
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Session expired. Reload.');
  $lock=fopen(REAL_ROOT.'/actions.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another action is running. Wait and reload.');
  try{
   set_time_limit(0);ignore_user_abort(true);session_write_close();$action=(string)($_POST['action']??'');
   if($action==='scan'){
    $srcKey=(string)($_POST['source_key']??'');
    // Patch 4i/4j: nothing is preselected; a scan needs an explicit choice made with Browse... (or ?src=/?srcpath=).
    if(trim($srcKey)==='')throw new RuntimeException(SRC_NONE_MSG);
    if($srcKey==='legacy'){if(!gp_legacy_ok())throw new RuntimeException(LEGACY_GONE_MSG);$root='/import-source';$srcLabel='Desktop Videos folder (read-only)';$winBase='C:/Users/Edutek/Desktop/Videos/';}
    elseif(str_starts_with($srcKey,'s:')){$srcName=substr($srcKey,2);if($srcName===''||$srcName==='.'||$srcName==='..'||str_contains($srcName,'/')||str_contains($srcName,chr(92))||!is_dir('/import-sources/'.$srcName)||is_link('/import-sources/'.$srcName))throw new RuntimeException('That import source is not available');$root='/import-sources/'.$srcName;$srcLabel=$srcName.' (read-only copy)';$winBase='C:/Users/Edutek/Desktop/Import Sources/'.$srcName.'/';}
    elseif(str_starts_with($srcKey,'h:')){$root=hv_valid_root(HV_ROOT.'/'.substr($srcKey,2));$srcKey='h:'.hv_rel($root);$hw=hv_win($root);$srcLabel=$hw.' (read-only)';hd_busy($root);$winBase=str_replace(chr(92),'/',rtrim($hw,chr(92))).'/';}
    else throw new RuntimeException('Unknown import source');
    if(!is_dir($root))throw new RuntimeException('Read-only source is unavailable');
    $id=bin2hex(random_bytes(16));$dir=batchdir($id);mkdir($dir,0770);mkdir($dir.'/files',0770);mkdir($dir.'/recovery',0770);
    $batch=['id'=>$id,'created'=>gmdate(DATE_ATOM),'state'=>'draft','source'=>$srcLabel,'src_root'=>$root,'source_key'=>$srcKey,'win_base'=>$winBase,'rows'=>[],'operations'=>[],'events'=>[]];
    $rdi=new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS);
    // Drive folders: skip links, hidden files and protected subfolders instead of failing the whole scan.
    // Patch 4g: junk (._ files, dot-files, .DS_Store, Thumbs.db, desktop.ini, .lnk, __MACOSX/.Spotlight-V100/.Trashes/.fseventsd/$RECYCLE.BIN, empty media)
    // is skipped for EVERY source and listed as skipped junk. Names starting with _ are real content and are kept.
    $junk=[];$junkN=0;$isHv=str_starts_with($root,HV_ROOT.'/');
    $jf=function($c)use(&$junk,&$junkN,$isHv,$root){$fn=$c->getFilename();$lnk=$c->isLink();if($isHv&&($lnk||$fn===''))return false;$dir=!$lnk&&$c->isDir();
     $why=$fn===''?'':sn_junk($fn,$dir,($dir||$lnk)?-1:(int)$c->getSize());if($why===''&&$isHv&&$fn[0]==='$')$why='system item';
     if($why!==''){$junkN++;if(count($junk)<1000)$junk[]=['path'=>substr($c->getPathname(),strlen($root)+1),'why'=>$why,'dir'=>$dir];return false;}
     return !$dir||!$isHv||hv_blocked($c->getPathname())===null;};
    $leftOut4p=[];$leftN4p=0; // Patch 4p: files the importer does not take are listed, never silently skipped
    $it=$isHv?new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator($rdi,$jf),RecursiveIteratorIterator::LEAVES_ONLY,RecursiveIteratorIterator::CATCH_GET_CHILD):new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator($rdi,$jf));$n=0;
    foreach($it as $f){if($f->isLink())throw new RuntimeException('Source link rejected');if(!$f->isFile())continue;$ext=strtolower($f->getExtension());if(!in_array($ext,['pdf','mp4'],true)&&!in_array($ext,AU_EXT,true)){p4_left_add($leftOut4p,$leftN4p,substr($f->getPathname(),strlen($root)+1),$ext,$root);continue;} // Patch 4o audio, Patch 4p

     if(++$n>500)throw new RuntimeException('Scan limit: 500 files per import. Split the source into smaller folders.');
     $rel=substr($f->getPathname(),strlen($root)+1);sourcefile($rel,$root);$parts=explode('/',$rel);$group=dirname($rel);if($group==='.')$group='Unsorted';
     $target=gp_clean_path($group).'/'.sn_filename(basename($rel));$autoTitle=sn_title(bi_tidy_title(pathinfo($rel,PATHINFO_FILENAME)),false);
     $rid='r'.$n;$batch['rows'][$rid]=['source'=>$rel,'src_root'=>$root,'group'=>$group,'type'=>$ext==='pdf'?'Books':($ext==='mp4'?'Videos':au_type($rel,$root,$ext)),'extension'=>$ext,'original_title'=>pathinfo($rel,PATHINFO_FILENAME),'title'=>$autoTitle,'title_auto'=>$autoTitle,'target'=>$target,'include'=>false,'reviewed'=>false,'bytes'=>$f->getSize(),'mtime'=>$f->getMTime(),'names_changed'=>$target!==$rel];gp_scan_place($batch['rows'][$rid],$root);au_scan_place($batch['rows'][$rid],$root);au_row_plan($batch['rows'][$rid]); // Patch 4k, Patch 4o audio
    }
    $batch['skipped_junk']=$junk;$batch['skipped_junk_n']=$junkN;$batch['name_rule']=SN_RULE;
    foreach($batch['rows'] as &$r){gp_init($r);}unset($r);gp_decollide($batch);$batch['placement_rules']=GP_RULES;$batch['planned_root']=GP_ROOT;
    attach_art($batch);p4_left_finish($batch,$leftOut4p,$leftN4p); // Patch 4p
    // Metadata-only duplicate candidates, not identity claims.
    $signals=[];foreach($batch['rows'] as $rid=>$r){if(!empty($r['support']))continue;$k=$r['type'].'|'.bi_dupkey((string)$r['title']).'|'.$r['bytes'];$signals[$k][]=$rid;}
    foreach($signals as $ids)if(count($ids)>1)foreach($ids as $rid){$batch['rows'][$rid]['candidate']=true;$batch['rows'][$rid]['dup_source']=true;}
    $doneSrc=[];$sk=[];$existing=[];foreach(glob(REAL_ROOT.'/*/batch.json')?:[] as $bp){try{$ob=bi_read($bp);}catch(Throwable $e){continue;}$obid=basename(dirname($bp));foreach(($ob['operations']??[]) as $orid=>$op){if(($op['state']??'')!=='copied')continue;$orow=$ob['rows'][$orid]??null;if(!is_array($orow)||!empty($orow['support']))continue;if(!ri_live($obid,(string)($orow['target']??'')))continue;/* Patch 4n */$existing[$orow['type'].'|'.bi_dupkey((string)$orow['title']).'|'.$orow['bytes']][]=['created'=>(string)($ob['created']??''),'kind'=>'earlier','where'=>dirname((string)$orow['target'])];$doneSrc[(string)($orow['src_root']??'/import-source').'|'.(string)$orow['source'].'|'.(int)$orow['bytes'].'|'.(int)$orow['mtime']]=true;}}
    $batch['device_index']=dx_meta();$deviceBuilt=(string)($batch['device_index']['built']??'');
    $batch['device_index_built']=$deviceBuilt;
    foreach($batch['rows'] as $rid=>$r){if(!empty($r['support']))continue;$ek=$r['type'].'|'.bi_dupkey((string)$r['title']).'|'.$r['bytes'];$dsk=(string)($r['src_root']??'/import-source').'|'.(string)$r['source'].'|'.(int)$r['bytes'].'|'.(int)$r['mtime'];$ents=$existing[$ek]??[];$dev=dx_lib_entries($r);$ear=array_values(array_filter($ents,fn($z)=>($z['kind']??'')==='earlier'));unset($batch['rows'][$rid]['candidate'],$batch['rows'][$rid]['dup_source'],$batch['rows'][$rid]['existing'],$batch['rows'][$rid]['already_imported'],$batch['rows'][$rid]['recent_kind'],$batch['rows'][$rid]['recent_where']);
      dx_store($batch['rows'][$rid]);rp_apply($batch['rows'][$rid],'');if($dev){$sk[$ek][]=$rid;$batch['rows'][$rid]['candidate']=true;$batch['rows'][$rid]['dup_lib']=true;$batch['rows'][$rid]['existing']=[$dev[0]];}
      elseif(isset($doneSrc[$dsk])){$batch['rows'][$rid]['already_imported']=true;$batch['rows'][$rid]['recent_kind']='same';$batch['rows'][$rid]['recent_where']=$ear?$ear[0]:null;}
      elseif($ear){$batch['rows'][$rid]['already_imported']=true;$batch['rows'][$rid]['recent_kind']='similar';$batch['rows'][$rid]['recent_where']=$ear[0];}
      else{$sk[$ek][]=$rid;}}
    foreach($sk as $ids2)if(count($ids2)>1)foreach($ids2 as $rid2){$batch['rows'][$rid2]['candidate']=true;$batch['rows'][$rid2]['dup_source']=true;}
    mc_scan($batch);
    savebatch($batch);
   }else{
    if(!$batch)throw new RuntimeException('Choose a batch');$dir=batchdir($id);$batch=bi_read($dir.'/batch.json');
    if($action==='save'||$action==='preview'){
     if(!in_array($batch['state'],['draft','approved'],true))throw new RuntimeException('This batch is no longer editable');
     $raw=(string)($_POST['draft']??'');if(strlen($raw)>524288)throw new RuntimeException('Draft too large');
     $posted=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($posted))throw new RuntimeException('Draft was not received; JavaScript must be enabled');
     $ncRaw=(string)($_POST['new_categories']??'[]');if(strlen($ncRaw)>65536)throw new RuntimeException('New category list too large');$ncPosted=json_decode($ncRaw!==''?$ncRaw:'[]',true);if(!is_array($ncPosted)||count($ncPosted)>300)$ncPosted=[];$ncMap4l=gp_newcat_seed($batch,array_values(array_filter($ncPosted,'is_string')));
     foreach($batch['rows'] as $rid=>&$r){
      if(!empty($r['support']))continue;
      if(!isset($posted[$rid])||!is_array($posted[$rid]))throw new RuntimeException('Incomplete draft. Reload and try again.');
      $p=gp_newcat_canon($posted[$rid],$r,$ncMap4l);$r['include']=($p['include']??false)===true;if(!empty($r['damaged']))$r['include']=false; // Patch 4o audio: a damaged file is never included
      $title=(string)($p['title']??$r['title']);if(mb_strlen($title)>300)throw new RuntimeException('Title too long');
      $r['title']=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',$title));if($r['title']==='')throw new RuntimeException('A title cannot be empty');
      gp_set($r,$p);dx_apply($r);
      $r['fix_accept']=(string)($p['fix_accept']??'');
      $r['duplicate_add']=($p['duplicate_add']??false)===true;$dd=(string)($p['dup_decision']??'');$r['dup_decision']=in_array($dd,['reject','add','replace'],true)?$dd:'';$rpw=(string)($p['replace_path']??'');if($r['dup_decision']==='reject'||($r['dup_decision']==='replace'&&$rpw===''))$r['include']=false;rp_apply($r,$rpw);
      if(($r['art_count']??0)>1){$choice=(string)($p['art_choice']??'');$r['art_choice']=($choice==='none'||(ctype_digit($choice)&&isset($r['art_options'][(int)$choice])))?$choice:'';}
     }unset($r);
     $batch['proposed_categories']=gp_used_newcats($batch);
     gp_decollide($batch);mc_rows_plan($batch);$batch['device_index']=dx_meta();rebuild_support($batch);$batch['placement_rules']=GP_RULES;$batch['planned_root']=GP_ROOT;
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
     $batch['publish_started']=gmdate('c');$batch['p4_new']=p4_new_places($batch); // Patch 4p (not part of the approval: rows only)
     $batch['state']='importing';savebatch($batch);
     // Patch 4i: plain copies run here as before; videos that need converting or a fast start fix go to the detached runner.
     $jobs=[];
     foreach($chosen as $rid=>$r){
      if(mc_needs_job($r)){$jobs[]=$rid;continue;}
      $GLOBALS['bi_prog']=['dir'=>$dir,'total'=>array_sum(array_column($chosen,'bytes')),'base'=>mc_done_bytes($batch),'files_done'=>count(array_filter($batch['operations'],fn($o)=>($o['state']??'')==='copied')),'files_total'=>count($chosen)];
      bi_copy_one($batch,$dir,$rid,$r);
      $mcE=mc_effective($r);if($mcE){$batch['operations'][$rid]['media']=['action'=>$mcE['action'],'label'=>$mcE['label']]+(isset($mcE['planned'])?['planned'=>$mcE['planned']]:[]);savebatch($batch);}
     }
     if($jobs){$batch['state']='converting';$batch['media_jobs']=$jobs;$batch['events'][]=gmdate(DATE_ATOM).' '.(count($chosen)-count($jobs)).' file(s) copied; '.count($jobs).' video(s) being made browser-ready in the background';savebatch($batch);
      if(!mc_spawn($id))$batch['events'][]=gmdate(DATE_ATOM).' The video runner could not start yet; it starts again when this page is opened';}
     else{$batch['state']='complete';$batch['events'][]=gmdate(DATE_ATOM).' Staged copy complete; not yet published to the Edutek library';savebatch($batch);}
    }elseif($action==='publish'){
     $pi=pq_info($id);
     // Patch 4b: a repeated submit of the same approved plan (double click, reload) changes nothing; the first request stands.
     if(!(($pi['requested']||in_array($pi['state'],['checking','publishing','published'],true))&&!empty($batch['approval'])&&hash_equals((string)$batch['approval'],(string)($_POST['revision']??'')))){
     if($batch['state']!=='complete')throw new RuntimeException('Only a fully staged import can be published');
     if(!pq_can_request($pi))throw new RuntimeException('This import already has a publish request or result');
     if(empty($batch['approval'])||!hash_equals((string)$batch['approval'],(string)($_POST['revision']??'')))throw new RuntimeException('Open this import again before publishing');
     if(!hash_equals((string)$batch['approval'],hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR))))throw new RuntimeException('Plan changed');
     $targets=[];$ack=[];$learnN=0;$bytes=0;$repN=0;$repL=[];
     foreach($batch['rows'] as $rid=>$r){if(empty($r['include']))continue;$op=$batch['operations'][$rid]??null;
      if(!is_array($op)||($op['state']??'')!=='copied'||!preg_match('/^[a-f0-9]{64}$/D',(string)($op['sha256']??''))||($op['target']??null)!==$r['target'])throw new RuntimeException('Not every approved file has a verified staged copy');
      $targets[]=['target'=>$op['target'],'sha256'=>$op['sha256'],'bytes'=>(int)$op['bytes'],'support'=>!empty($r['support'])];$bytes+=(int)$op['bytes'];
      if(empty($r['support'])){$learnN++;if(!empty($r['duplicate_add']))$ack[]=$op['target'];
       if(!empty($r['replace'])){if(($r['replace']['path']??null)!==$r['target']||!is_int($r['replace']['mtime']??null))throw new RuntimeException('A replacement choice is incomplete; review that item again');$repN++;$repL[]=['target'=>$r['target'],'bytes'=>(int)$r['replace']['bytes'],'mtime'=>(int)$r['replace']['mtime']];}}}
     if(!$targets||$learnN<1||count($targets)!==count($batch['operations']))throw new RuntimeException('Staged files do not match the approved plan');
     $pn=$_POST['new_count']??null;$pr=$_POST['replace_count']??null;
     if(!is_string($pn)||!is_string($pr)||!ctype_digit($pn)||!ctype_digit($pr)||(int)$pn!==$learnN-$repN||(int)$pr!==$repN)throw new RuntimeException('The publish summary on this page no longer matches the staged import ('.($learnN-$repN).' new, '.$repN.' replacements). Reload the page and check it again. Nothing was requested.');
     if($repN>0&&($_POST['replace_ack']??'')!=='1')throw new RuntimeException('Tick "I understand '.$repN.' library file'.($repN>1?'s':'').' will be replaced" before publishing. Nothing was requested.');
     if(!is_dir(PQ_DIR)&&!mkdir(PQ_DIR,0770,true))throw new RuntimeException('Cannot create the publish queue');
     bi_json(PQ_DIR.'/'.$id.'.request.json',['batch'=>$id,'revision'=>$batch['approval'],'confirm'=>pq_phrase($learnN,$repN),'confirm_method'=>($repN>0?'summary+replace-checkbox':'summary'),'new_count'=>$learnN-$repN,'learning_count'=>$learnN,'replace_count'=>$repN,'replace'=>$repL,'file_count'=>count($targets),'bytes'=>$bytes,'targets'=>$targets,'allow_same_size'=>$ack,'requested_at'=>gmdate(DATE_ATOM)]);
     }
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
     if(!pq_can_reverse(pq_info($id)))throw new RuntimeException('This import was sent for publishing, so its staged copies stay in place. Published files are reversed with Undo-Publish.ps1 on the Beelink');
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
 foreach($batch['rows'] as &$r)if(empty($r['support']))gp_init($r);unset($r);gp_decollide($batch);foreach($batch['rows'] as $aid=>$a)if(!empty($a['support'])&&isset($batch['rows'][$a['parent']??'']['target'])){$pt=$batch['rows'][$a['parent']]['target'];$batch['rows'][$aid]['target']=dirname($pt).'/'.pathinfo($pt,PATHINFO_FILENAME).'.'.$a['extension'];}
 $batch['state']='draft';unset($batch['approval']);$batch['placement_notice']=true;
}
// Patch 4i: a batch that is still converting restarts its runner when the page is opened (after a restart of the importer).
if($batch&&($batch['state']??'')==='converting'&&PHP_SAPI!=='cli'){try{if(!mc_running(batchdir($id)))mc_spawn($id);}catch(Throwable $e){}}
$sourceChoices=gp_legacy_ok()?['legacy'=>'Desktop Videos (existing)']:[];if(str_starts_with((string)($batch['source_key']??''),'h:'))$sourceChoices[(string)$batch['source_key']]=(string)($batch['source']??'Chosen folder');foreach(glob('/import-sources/*',GLOB_ONLYDIR)?:[] as $sp){if(!is_link($sp))$sourceChoices['s:'.basename($sp)]=basename($sp);}
// Patch 4i: nothing is preselected ("Select import source"); only an explicit Browse... or ?src= pick selects a source.
$currentSource='';
// Patch 4d/4e: the folder window (or the typed path, ?srcpath=D:\...) picks the source (?src=h:<drive/path>, also legacy and s:<name>); it is re-checked here and again on scan.
$pubView=(($_GET['view']??'')==='published');$srcPicked=false;$srcPick=(string)($_GET['src']??'');
$srcPathTyped=is_string($_GET['srcpath']??null)?(string)$_GET['srcpath']:'';if(trim($srcPathTyped)!==''){try{$srcPick='h:'.hp_rel_from_win($srcPathTyped);}catch(Throwable $e){$error=$e->getMessage();$srcPick='';}}
if($srcPick!==''){try{if(str_starts_with($srcPick,'h:')){$pp=hv_valid_root(HV_ROOT.'/'.substr($srcPick,2));$currentSource='h:'.hv_rel($pp);$sourceChoices[$currentSource]=hv_win($pp).' (read-only)';$srcPicked=true;}elseif(isset($sourceChoices[$srcPick])){$currentSource=$srcPick;$srcPicked=true;}elseif($srcPick==='legacy')throw new RuntimeException(LEGACY_GONE_MSG);else throw new RuntimeException('That source is not available');}catch(Throwable $e){$error=$e->getMessage();}}
// Patch 4j: the chosen source is shown as plain text (Selected: ...); ?src=legacy and ?src=s:<name> keep working.
$srcShown=$currentSource===''?'':($currentSource==='legacy'?'C:\\Users\\Edutek\\Desktop\\Videos':(str_starts_with($currentSource,'s:')?'C:\\Users\\Edutek\\Desktop\\Import Sources\\'.substr($currentSource,2):preg_replace('/ \(read-only\)$/','',(string)($sourceChoices[$currentSource]??''))));
$browseStart=str_starts_with($currentSource,'h:')?substr($currentSource,2):(str_starts_with((string)($batch['source_key']??''),'h:')?substr((string)$batch['source_key'],2):'');
$history=[];foreach(glob(REAL_ROOT.'/*/batch.json')?:[] as $p){try{$history[]=bi_read($p);}catch(Throwable $e){}}usort($history,fn($x,$y)=>strcmp($y['created'],$x['created']));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edutek — Add learning materials</title>
<style>
*{box-sizing:border-box}body{margin:0;font:16px system-ui,sans-serif;color:#173047;background:#f1f5f9}header,main{max-width:1320px;margin:auto;padding:22px}h1{margin:0 0 7px;font-size:29px}h2{font-size:21px;margin:0 0 12px}h3{font-size:17px;margin:0 0 7px}p{line-height:1.5}button,input,select{font:inherit}button{padding:10px 14px;border:1px solid #9bafc2;border-radius:7px;background:white;cursor:pointer}button:hover{background:#e4edf6}button:disabled{opacity:.55;cursor:default}.primary{background:#155ea6;color:white;border-color:#155ea6}.primary:hover{background:#124d86}.layout{display:grid;grid-template-columns:270px 1fr;gap:20px}aside,.panel,.item{background:white;border:1px solid #d5e0eb;border-radius:10px;padding:17px;margin-bottom:14px}.item{display:grid;grid-template-columns:86px 1fr;gap:16px}.art{width:86px;height:112px;object-fit:contain;background:#edf2f7;border-radius:6px}.placeholder{display:flex;align-items:center;justify-content:center;color:#5c7287;font-size:12px}.muted{font-size:13px;color:#526a80;overflow-wrap:anywhere}.badge{display:inline-block;font-size:12px;padding:3px 7px;border-radius:5px;background:#e8f1fa;margin:3px}.warning{background:#fff4df;border:1px solid #e9c57d;padding:12px;border-radius:7px;margin:10px 0}.error{background:#ffe9e9;color:#8b1515;padding:16px;border:1px solid #d99;border-radius:8px}input[type=checkbox]{width:22px;height:22px;vertical-align:middle;margin-right:7px}input[type=text],input[type=search],select{max-width:100%;padding:8px;border:1px solid #9caec0;border-radius:5px}input[type=text]{width:100%}.select-label{display:inline-flex;align-items:center;gap:4px;margin:9px 0}.toolbar,.filters,.actions,.bulk{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:12px 0}.filters button.active,.collections button.active{background:#173f68;color:white}.collections{max-height:340px;overflow:auto}.collections button{display:block;width:100%;text-align:left;margin:6px 0;font-size:14px}.history a{display:block;margin:12px 0;color:#17578f}.published{margin:18px 0}.pub-list{list-style:none;padding:0;margin:0}.pub-list li{margin:0 0 12px;line-height:1.4}.btn-link{display:inline-block;margin-top:4px;padding:6px 10px;border:1px solid #155ea6;border-radius:6px;color:#155ea6;font-weight:600;text-decoration:none}.btn-link:focus,.btn-link:hover{background:#155ea6;color:#fff;outline:3px solid #f0b429;outline-offset:2px}.undo-box button:focus{outline:3px solid #f0b429;outline-offset:2px}.actions{position:sticky;bottom:0;background:#dfebf6;border:1px solid #afc5da;border-radius:8px;padding:14px;z-index:2}.step{font-size:13px;letter-spacing:.3px;color:#365b80}.name-pair{font-size:14px;overflow-wrap:anywhere}details{margin:10px 0;font-size:14px}summary{cursor:pointer;color:#245d90}.tiny-art{width:50px;height:65px;object-fit:contain}.choices{display:flex;gap:10px;flex-wrap:wrap}.notice{padding:10px;background:#e8f3ed;border-radius:6px}.selected{border-color:#4088c8;box-shadow:0 0 0 1px #4088c8}.report{overflow-wrap:anywhere;font-size:13px}.collection-heading{margin:10px 0;font-size:19px}[hidden]{display:none!important}aside .filters{flex-direction:column;align-items:stretch;margin:6px 0 18px}aside .filters button{text-align:left}.dup-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:12px 0}.dup-col{border:1px solid #d5e0eb;border-radius:8px;padding:12px;background:#fbfdff;overflow-wrap:anywhere}.dup-col.existing{background:#eaf5ec;border-color:#9fcaa6}.dup-col .art{width:120px;height:160px}.dup-col h4{margin:8px 0 6px;font-size:16px}.dup-tag{font-size:12px;font-weight:600;letter-spacing:.3px;color:#365b80;margin-bottom:8px}.cmp-row{margin:5px 0;font-size:14px;color:#4a6075}.cmp-k{font-weight:600;color:#365b80}.cmp{display:inline-block;margin-left:4px;padding:0 6px;border-radius:10px;font-size:11px;font-weight:700;border:1px solid;white-space:nowrap}.cmp-same{background:#d4efdc;color:#14532d;border-color:#6fbf8e}.cmp-similar{background:#dff3ef;color:#134e4a;border-color:#6fb8ad}.cmp-diff{background:#fde8c4;color:#7a4300;border-color:#d99a3a}.cmp-na{background:#eef2f6;color:#4a6075;border-color:#b8c6d4}.dup-why{font-size:12px;color:#4a6075;margin:0 0 4px}.cmp-legend{font-size:13px;color:#4a6075;margin:6px 0 0}.dup-choice{margin:12px 0;border:1px solid #afc5da;border-radius:8px;padding:10px 14px}.dup-choice label{display:block;margin:8px 0}.dup-choice input{width:18px;height:18px;vertical-align:middle;margin-right:8px}.dup-col{cursor:pointer;position:relative}.dup-col:hover{border-color:#4088c8}.dup-col:focus{outline:2px solid #155ea6}.dup-col.winner{border:3px solid #2e8b57;background:#eef9f1}.dup-col.rejected{opacity:.55;border-style:dashed}.dup-col.replaced{opacity:.7;border-color:#c0392b;border-style:dashed}.dup-state{font-weight:700;font-size:13px;margin:4px 0 8px;color:#365b80}.dup-col.winner .dup-state{color:#1b6b3a}.dup-col.rejected .dup-state,.dup-col.replaced .dup-state{color:#a03030}.tree{list-style:none;margin:8px 0;padding:0}.tree ul{list-style:none;margin:4px 0 4px 10px;padding-left:20px;border-left:2px solid #c7d6e5}.tree li{margin:7px 0}.tree .folder{font-weight:700;color:#173f68}.tree li.file{display:flex;gap:10px;align-items:flex-start}.tree li.file img{width:42px;height:56px;object-fit:contain;background:#edf2f7;border-radius:4px}.checks{margin:8px 0 14px;padding-left:20px}.checks li{margin:4px 0}#dup-chips button.active{background:#173f68;color:#fff}#choose-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}#choose-row button{padding:8px 10px;font-size:14px;white-space:nowrap}#choose-row input[type=search]{flex:1 1 150px;min-width:130px;max-width:220px}#more-select{margin:10px 0 0;font-size:14px}#more-select .toolbar{margin:8px 0}@media(max-width:800px){.layout{display:block}aside .collections{max-height:180px}.item{grid-template-columns:65px 1fr;gap:10px}.art{width:65px;height:90px}.actions{position:static}header,main{padding:14px}}
.hv{background:#fff;border:1px solid #c9d6e2;border-radius:8px;padding:14px;margin-bottom:14px}.hv-list{list-style:none;padding:0;margin:6px 0}.hv-list li{margin:4px 0}.hv-go{display:inline-block;padding:3px 6px;color:#155ea6;font-weight:600}.hv-go:focus,.hv-go:hover{background:#155ea6;color:#fff;outline:3px solid #f0b429;outline-offset:2px}#browse-title:focus,#hv-here:focus{outline:3px solid #f0b429}.hv-inline{display:inline;margin-left:6px}.hv-inline button{padding:3px 8px}.hv-inline button:focus{outline:3px solid #f0b429;outline-offset:2px}.hd-ok{background:#e8f6ec;border:1px solid #9fd3ae;padding:10px;border-radius:7px}
aside .hv{padding:10px;font-size:14px}aside .hv h2{font-size:17px}.src-select{width:100%;margin:4px 0}#pick-btn:focus,#start:focus,#src-select:focus{outline:3px solid #f0b429;outline-offset:2px}#src-msg{margin:6px 0}.src-chosen{margin:4px 0 8px;font-size:14px;overflow-wrap:anywhere}.pub-foot{margin-top:6px}
/* Patch 4l */#set-category-panel select{max-width:100%}#set-category-panel label{margin-right:4px}.cat-share{margin:6px 0}optgroup[data-new]{font-weight:bold}
/* Patch 4j: panels sit directly under the header (was 22px + 16px + 22px of empty space) */header{padding-bottom:6px}header>:last-child{margin-bottom:0}main{padding-top:10px}.newcat-badge{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;background:#fff3cd;color:#6b4e00;border:1px solid #d9b54a;font-size:12px;font-weight:700}.new-cat{display:inline-block;margin:6px 0}.cat-needed{margin:6px 0}.category-msg{display:inline-block;margin:4px 0}#drives-title:focus{outline:3px solid #f0b429}
.folder-tag{display:inline-block;margin:0 4px;padding:0 7px;border-radius:5px;font-size:13px;font-weight:600;border:1px solid}.folder-tag.is-auto{background:#e3f0fc;color:#0b3d6b;border-color:#7aa7d6}.folder-tag.is-custom{background:#fff4dc;color:#6b4300;border-color:#d9a640}.folder-box.auto-folder{font-style:italic;background:#f3f8fd}.folder-box::placeholder{color:#3d5870;opacity:1;font-style:italic}.folder-box:focus,.use-auto:focus,.individual-placement input:focus,.individual-placement summary:focus{outline:3px solid #f0b429;outline-offset:2px}.folder-note{margin:4px 0}
/* Patch 4o */
body.ui4o{padding-bottom:78px}
body.ui4o>header>div:not(.tabs4o),body.ui4o>header>p{display:none}
body.ui4o :focus{outline:4px solid #f0b429 !important;outline-offset:2px}
.u4,.u4 button,.u4 input,.u4 select{font-size:17px}
.u4 button,.u4 .btn4o{padding:11px 16px;border:1px solid #9bafc2;border-radius:7px;background:#fff;cursor:pointer;color:#173047;min-height:46px;display:inline-block;text-decoration:none;font-weight:500;line-height:1.3}
.u4 button:hover,.u4 .btn4o:hover{background:#e4edf6}
.u4 button.primary,.u4 .btn4o.primary{background:#155ea6;color:#fff;border-color:#155ea6;font-weight:600}
.u4 button.primary:hover{background:#124d86}
.u4 button.big,.u4 .btn4o.big{font-size:21px;padding:16px 30px;min-height:60px}
.u4 button[disabled]{opacity:.65;cursor:default}
.u4 input[type=text]{padding:10px;border:1px solid #9caec0;border-radius:6px;min-height:46px;font-size:18px}
.u4 kbd,.u4classic kbd{display:inline-block;min-width:22px;padding:1px 6px;border:1px solid #9bafc2;border-bottom-width:2px;border-radius:4px;background:#fff;font:600 13px system-ui,sans-serif;color:#173047;text-align:center}
.u4 button.primary kbd{color:#173047}
.u4 h2{font-size:24px;margin:0 0 12px}
.u4 h2:focus{outline-offset:6px}
.u4 .muted{font-size:14px;color:#526a80}
.tabs4o{display:flex;gap:6px;margin:10px 0 0;border-bottom:2px solid #c9d6e2}
.tabs4o button{border:none;border-radius:8px 8px 0 0;background:#e3ebf3;margin-bottom:-2px;border-bottom:2px solid #c9d6e2}
.tabs4o button[aria-selected=true]{background:#fff;border:2px solid #c9d6e2;border-bottom-color:#fff;font-weight:700}
.u4 .panel4o{background:#fff;border:1px solid #d5e0eb;border-radius:10px;padding:20px;margin-bottom:14px}
.u4 .steps4o{display:flex;list-style:none;padding:0;margin:14px 0}
.u4 .steps4o li{flex:1;padding:10px 14px;background:#e3ebf3;color:#365b80;border-right:3px solid #f1f5f9;font-size:16px}
.u4 .steps4o li:first-child{border-radius:8px 0 0 8px}.u4 .steps4o li:last-child{border-radius:0 8px 8px 0;border-right:none}
.u4 .steps4o li b{display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;border-radius:50%;background:#fff;margin-right:8px}
.u4 .steps4o li.done{background:#d5e8d9;color:#1d5130}.u4 .steps4o li.done b{background:#2e7d4a;color:#fff}
.u4 .steps4o li.now{background:#155ea6;color:#fff;font-weight:700}.u4 .steps4o li.now b{background:#fff;color:#155ea6}
.u4 .found4o{font-size:20px;margin:14px 0 6px}
.u4 .kinds4o{margin:6px 0 16px;padding-left:22px;font-size:18px;line-height:1.6}
.u4 .src4o h2,.u4 .src4o+p.muted{display:none}
.u4 .src4o{margin:10px 0}
.u4 .question4o{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.u4 .question4o label{font-size:20px;font-weight:700;margin-right:6px}
.u4 .progress4o{font-size:18px;margin:14px 0 4px;font-weight:600}
.u4 .progress4o.ok{color:#1d5130}.u4 .progress4o.todo{color:#7a4b00}
.u4 .listtools4o{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:8px 0}
.u4 ul.items4o{list-style:none;padding:0;margin:8px 0}
.u4 ul.items4o>li.group4o{font-weight:700;color:#365b80;margin:14px 0 6px;font-size:16px}
.u4 ul.items4o>li.item4o{display:grid;grid-template-columns:44px 96px 1fr auto;gap:14px;align-items:center;background:#fff;border:2px solid #d5e0eb;border-radius:10px;padding:10px 14px;margin-bottom:8px}
.u4 ul.items4o>li.sel{border-color:#155ea6;background:#eaf3fc}
.u4 ul.items4o>li.needs{border-color:#e9c57d}
.u4 ul.items4o>li.skipped{opacity:.62}
.u4 ul.items4o>li.skipped .title4o{text-decoration:line-through}
.u4 .selmark4o{width:30px;height:30px;border:2px solid #9bafc2;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff}
.u4 li.sel .selmark4o{background:#155ea6;border-color:#155ea6}
.u4 .thumb4o{width:96px;height:58px;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;overflow:hidden;background:#24527a}
.u4 .thumb4o img{width:100%;height:100%;object-fit:cover}
.u4 .title4o{font-size:18px;font-weight:600;overflow-wrap:anywhere}
.u4 .meta4o{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px;align-items:center}
.u4 .chip4o{display:inline-block;font-size:13px;padding:3px 9px;border-radius:12px;background:#e8f1fa;border:1px solid #b9d0e6}
.u4 .chip4o.need{background:#fff4df;border-color:#e9c57d;color:#6b4300;font-weight:700}
.u4 .chip4o.cat{background:#e3f0fc;border-color:#7aa7d6;color:#0b3d6b;font-weight:600}
.u4 .chip4o.phone{background:#eef7ee;border-color:#9fd3ae;color:#1d5130}
.u4 .chip4o.have{background:#f0eaf8;border-color:#bba4dc;color:#4a2a7a}
.u4 .rowbtns4o{display:flex;gap:6px;align-items:center}
.u4 .rowbtns4o button{padding:6px 10px;min-height:38px;font-size:14px}
.u4 .catbtn4o{min-width:300px;text-align:left;font-weight:600}.u4 .rowbtns4o .catbtn4o{min-width:200px}
.u4 .catbtn4o.empty{color:#7a4b00;border-color:#e9c57d;background:#fffaf0}
.u4 .open4o{grid-column:2 / 5;font-size:14px;background:#f6f9fc;border:1px dashed #c9d6e2;border-radius:6px;padding:8px 12px;color:#365b80}
.u4 .open4o dt{font-weight:700;float:left;clear:left;width:150px}.u4 .open4o dd{margin:0 0 4px 160px}
.u4 .open4o article.item{margin-top:10px;font-size:15px;color:#173047}
.u4 .rename4o{width:min(560px,100%)}
.u4 .bar4o{position:sticky;bottom:64px;z-index:5;background:#173f68;color:#fff;border-radius:10px;padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 4px 14px rgba(0,0,0,.25)}
.u4 .bar4o b{font-size:18px}
.u4 .nav4o{display:flex;justify-content:space-between;align-items:center;margin-top:16px;gap:10px;flex-wrap:wrap}
.u4 .summary4o{font-size:20px;line-height:1.7;padding-left:24px}
.u4 .summary4o small{font-size:15px;color:#526a80}
.u4 .pub4o{margin:12px 0;padding:0;list-style:none;font-size:18px}
.u4 .pub4o li{padding:6px 0;border-bottom:1px solid #e3ebf3}
.u4 .donebox4o{background:#e8f6ec;border:1px solid #9fd3ae;border-radius:10px;padding:18px}
.u4 .undonebox4o,.u4 .warnbox4o{background:#fff4df;border:1px solid #e9c57d;border-radius:10px;padding:18px;margin-bottom:10px}
.u4 .errbox4o{background:#fdecec;border:1px solid #e6a3a3;border-radius:10px;padding:14px 18px;margin-bottom:12px;color:#5c1010}
.u4 .warn4o{color:#7a4b00;font-weight:600}
.u4 pre{white-space:pre-wrap;font-size:13px;background:#f6f9fc;padding:8px;border-radius:6px}
.hints4o{position:fixed;left:0;right:0;bottom:0;z-index:10;background:#fff;border-top:2px solid #c9d6e2;padding:10px 18px;font-size:15px;display:flex;gap:16px;flex-wrap:wrap;justify-content:center}
.toast4o{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#173047;color:#fff;padding:10px 18px;border-radius:8px;z-index:30;font-weight:600;display:none;max-width:90vw}
.u4 .overlay4o{position:fixed;inset:0;background:rgba(10,25,40,.55);z-index:40;display:flex;align-items:center;justify-content:center}
.u4 .dialog4o{background:#fff;border-radius:12px;padding:22px;width:min(660px,94vw);max-height:92vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.4);color:#173047}
.u4 .dialog4o p{font-size:18px}
.u4 .pick4o{list-style:none;padding:0;margin:10px 0}
.u4 .pick4o li{padding:9px 12px;border:2px solid #d5e0eb;border-radius:8px;margin-bottom:4px;font-size:17px;cursor:pointer}
.u4 .pick4o li[aria-selected=true]{border-color:#155ea6;background:#eaf3fc}
.u4 .catlist4o{max-height:46vh;overflow:auto}.u4 .catlist4o li.create{font-weight:700;color:#155ea6}
.u4 .filters4o{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 14px}
.u4 .filters4o button[aria-checked=true]{background:#173f68;color:#fff}
.u4 ul.past4o{list-style:none;padding:0;margin:0}
.u4 ul.past4o li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px;border:2px solid #d5e0eb;border-radius:10px;margin-bottom:8px;background:#fff}
.u4 .state4o{font-weight:700}.u4 .state4o.lib{color:#1d5130}.u4 .state4o.undone{color:#7a4b00}.u4 .state4o.fail{color:#8b1515}
.u4classic{margin-top:18px;background:#fff;border:1px solid #d5e0eb;border-radius:10px;padding:10px 16px}
.u4classic>summary{font-weight:600;cursor:pointer;padding:6px 0;color:#17578f}
.u4 .chip4o.bad{background:#fde2e2;border-color:#e6a3a3;color:#7f1d1d;font-weight:700}
.u4 #audio-skipped4o ul{margin:6px 0 6px 20px}
/* Patch 4p */
.u4 .note4p{margin:8px 0;padding:8px 12px;border-left:4px solid #64748b;background:#f8fafc}
.u4 .left4p ul.leftlist4p{margin:6px 0 6px 18px;padding:0;max-height:320px;overflow:auto}
.u4 .left4p li{margin:3px 0}.u4 .f4p{font-family:ui-monospace,Consolas,monospace;font-weight:600}
.u4 .tiles4p{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:8px;margin:12px 0}
.u4 .tile4p{background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;display:flex;flex-direction:column}
.u4 .tile4p b{font-size:24px}.u4 .tile4p.warn{border-color:#d97706;background:#fff7ed}
.u4 .sec4p{margin-top:18px}.u4 .sec4p h2{font-size:20px}
.u4 table.sum4p{border-collapse:collapse;width:100%}.u4 table.sum4p th,.u4 table.sum4p td{text-align:left;padding:5px 8px;border-bottom:1px solid #e2e8f0}
.u4 table.sum4p tr.g4p td{background:#f1f5f9}.u4 td.sub4p{padding-left:26px}
.u4 .new4p{display:inline-block;margin-left:8px;padding:0 6px;border-radius:4px;background:#1d4ed8;color:#fff;font-size:12px;font-weight:700}
.u4 a.link4p{font-weight:600}.u4 a.link4p:focus{outline:3px solid #2563eb;outline-offset:2px}
.u4 .wait4p{color:#92400e;background:#fffbeb;padding:6px 10px;border-radius:6px}
.u4 ul.docs4p li{margin:4px 0}.u4 .small4p{font-size:14px}
.dialog4o ul.p4new{margin:4px 0 10px 18px}.dialog4o ul.p4new ul{margin:2px 0 2px 18px;color:#475569}
.u4 .chip4o.shelf{background:#e0e7ff;color:#312e81}
</style></head><body><header><h1>Add learning materials</h1><div>1. Choose materials → 2. Fix anything that needs attention → 3. Stage for publishing</div><p class="muted">Source: <?=h($batch['source']??'choose Browse… under Import source')?>. Edutek library: D:\xampp\htdocs\Edutek\videos. Approved materials are copied to staging on this Beelink and verified; the library changes only when the batch is published.</p></header><main>
<?php if($error):?><p class="error">Could not finish: <?=h($error)?>. Your source files have not been changed.</p><?php endif;?>
<?php $primary=$batch?learning($batch['rows']):[];$editable=$batch&&in_array($batch['state'],['draft','approved'],true);$groups=[];foreach($primary as $rid=>$r)$groups[!empty($r['root_file'])?':root':$r['group']][$rid]=$r;ksort($groups);/* Patch 4k: files directly in the chosen folder are their own group */?>
<div class="layout"><aside><form method="post" id="src-form"><?php hidden_fields($csrf);?><h2>Import source</h2><input type="hidden" name="source_key" id="src-key" value="<?=h($currentSource)?>"><p style="margin:4px 0 6px"><button type="button" id="pick-btn" data-start="<?=h($browseStart)?>" aria-describedby="pick-live src-chosen">Browse&hellip;</button></p><p id="src-chosen" class="src-chosen"><?php if($currentSource!==''):?>Selected: <strong><?=h($srcShown)?></strong><?php else:?>No folder chosen yet.<?php endif;?></p><p id="pick-live" class="muted" role="status" aria-live="polite">Opens a Windows folder window on this computer (C:, D: or a USB drive).</p><?php if($srcPicked):?><p class="muted" role="status">Source folder chosen. Choose Start new import to scan it.</p><?php endif;?><p id="src-msg" class="warning" role="alert"<?=$error===SRC_NONE_MSG?'':' hidden'?>><?=h(SRC_NONE_MSG)?></p><button class="primary" id="start" name="action" value="scan" aria-describedby="src-chosen" <?=$srcPicked?'autofocus':''?>>Start new import</button></form><p class="muted">Scans the selected folder. Nothing is copied.</p><?=hv_drives_panel($csrf)?><script>(()=>{const f=document.getElementById("src-form"),key=document.getElementById("src-key"),pick=document.getElementById("pick-btn"),msg=document.getElementById("src-msg");if(!f||!key||!msg)return;f.noValidate=true;f.addEventListener("submit",ev=>{if(key.value===""){ev.preventDefault();msg.hidden=false;if(pick)pick.focus();}});})();</script><script>(()=>{const b=document.getElementById("pick-btn"),live=document.getElementById("pick-live"),typed=null,tok=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;if(!b||!live)return;let busy=false;const say=(t,c)=>{live.textContent=t;live.className=c||"muted";};const finish=(t,c,el)=>{busy=false;b.disabled=false;say(t,c);(el||b).focus();};b.addEventListener("click",()=>{if(busy)return;busy=true;b.disabled=true;say("Opening the folder window\u2026");const fd=new FormData();fd.append("csrf",tok);fd.append("action","pick_folder");fd.append("start",b.dataset.start||"");fetch("/bulk-import-guided.php",{method:"POST",body:fd,credentials:"same-origin",cache:"no-store"}).then(r=>r.json().then(j=>[r.ok,j])).then(([ok,j])=>{if(!ok||!j||!j.id){finish((j&&j.error)||"The folder window could not be opened.","warning",typed);return;}say("Choose a folder in the window that just opened\u2026 Press Enter or choose Use this folder; press Esc there to cancel.");const t0=Date.now();const tick=()=>{fetch("/bulk-import-guided.php?pickstatus=1&token="+encodeURIComponent(tok)+"&req="+encodeURIComponent(j.id),{credentials:"same-origin",cache:"no-store"}).then(r=>r.json()).then(s=>{if(s.state==="picked"){say("Folder chosen: "+s.label+". Loading\u2026");location.href="/bulk-import-guided.php?src="+encodeURIComponent(s.src)+"#start";return;}if(s.state==="cancelled"){finish("No folder was chosen. Choose Browse\u2026 to try again.");return;}if(s.state==="refused"){finish("That folder cannot be used. "+s.message+" Choose Browse\u2026 to pick another folder.","warning");return;}if(s.state==="timeout"){finish("The folder window closed after 5 minutes without a choice. Choose Browse\u2026 to try again.","warning");return;}if(s.state==="error"){finish(s.message+" Choose Browse\u2026 to try again.","warning",typed);return;}if((s.state==="gone"||(s.state==="waiting"&&(s.watcher_age===null||s.watcher_age>60)))&&Date.now()-t0>15000){finish("The folder window did not open. The drive watcher task may be stopped or need restarting: start the Edutek Import Drive Watcher task (or sign out and back in), then choose Browse\u2026 again.","warning",typed);return;}if(Date.now()-t0>360000){finish("No answer from the folder window. Choose Browse\u2026 to try again.","warning");return;}setTimeout(tick,1000);}).catch(()=>{if(Date.now()-t0>360000){finish("Lost contact with the importer. Reload the page.","warning");return;}setTimeout(tick,2000);});};setTimeout(tick,800);}).catch(()=>finish("The folder window could not be opened. Check that the Edutek Import Drive Watcher task is running, then choose Browse\u2026 again.","warning",typed));});})();</script>
<?php if($batch&&$editable):?><h2>Content type</h2><div class="filters"><?php foreach(['All content','Books','Videos','Music','Audiobooks'] as $type):?><button type="button" data-filter="<?=h($type)?>"><?=h($type)?> (<?=$type==='All content'?count(array_filter($primary,'bi_in_pool')):count(array_filter($primary,fn($r)=>$r['type']===$type&&bi_in_pool($r)))?>)</button><?php endforeach;?></div><?php endif;?><?php if($batch&&$editable):?><h2>Needs review</h2><div class="filters"><button type="button" data-filter="Duplicates">Possible duplicates (<?=count(array_filter($primary,fn($r)=>!empty($r['candidate'])))?>)</button><button type="button" data-filter="Names">Title and filename issues (<?=count(array_filter($primary,fn($r)=>needs_fix($r)&&!hash_equals(fix_token($r),(string)($r['fix_accept']??''))))?>)</button></div><?php endif;?><?php if($batch&&$editable):?><h2>Recently imported</h2><div class="filters"><button type="button" data-filter="Imported">Recently imported (<?=count(array_filter($primary,fn($r)=>!empty($r['already_imported'])))?>)</button></div><?php endif;?><?php if($batch):?><h2>Source collections</h2><div class="collections"><button type="button" data-group="all">All collections (<?=count(array_filter($primary,'bi_in_pool'))?>)</button><?php foreach($groups as $group=>$rows):?><button type="button" data-group="<?=h($group)?>"><?=h(gp_group_label($group,$rows,$batch))?> (<?=count(array_filter($rows,'bi_in_pool'))?>)</button><?php endforeach;?></div><?php endif;?>
<details class="history"><summary>Saved imports & history (<?=count($history)?>)</summary><?php foreach($history as $entry):$hs=ux_state($entry);$hn=count(learning($entry['rows']));?><a class="leave-page" href="?id=<?=h($entry['id'])?>" aria-label="<?=h('Import '.substr((string)$entry['id'],0,8).', '.ux_when((string)$entry['created']).': '.$hs.', '.$hn.' materials')?>"><?=h(ux_when((string)$entry['created']))?><br><strong><?=h($hs)?></strong> · <?=count(learning($entry['rows']))?> materials</a><?php endforeach;?></details>
<p class="muted">Imports made with older importer pages are not listed.</p></aside><section>
<?php if($pubView&&!$batch):?><div class="panel"><p><a class="leave-page" href="?">&larr; Back to the importer</a></p><?=ux_published()?><p class="muted">Undo puts back any library files the publish replaced and removes the files it added. Undone imports show &ldquo;Undone&rdquo; and have no button.</p></div><?php elseif(!$batch):?><div class="panel"><h2>Ready to look at your materials?</h2><p>Choose Browse&hellip; to pick a source folder on this computer or a USB drive, then Start new import. Books, videos and matching artwork are found automatically.</p><p>We will keep the originals untouched. You can browse the full collection and add as many materials as you like.</p></div>
<?php else:$selected=array_filter($batch['rows'],fn($r)=>$r['include']);$selectedPrimary=learning($selected);$selectedArt=count($selected)-count($selectedPrimary);$selectedBytes=array_sum(array_column($selected,'bytes'));?>
<div class="panel"><div class="step">STAGING · Import <?=h(substr($id,0,8))?></div><h2><?=count(array_filter($primary,'bi_in_pool'))?> materials ready to choose</h2><p class="muted"><?=count(array_filter($primary,fn($r)=>!bi_in_pool($r)))?> more are in Needs review or Recently imported.</p><p><?=count(array_filter($primary,fn($r)=>!empty($r['art'])))?> have attached artwork. Artwork is not counted as a separate material.</p><p class="muted">Browse all content and choose what to add. Large videos take longer to copy.</p><?php if($editable):?><?php if(!empty($batch['placement_notice'])):?><p class="notice">Updated folder rules are previewed below. Previous approval is cleared. Save progress or Review selection before adding anything. Nested book-source folders are suggested as one subcategory name joined with a dash; originals stay unchanged.</p><?php endif;?><span id="saved" class="notice">Saved on device. Use Save progress before leaving.</span><?php else:?><p class="notice">Status: <?=h($batch['state'])?></p><?php endif;?></div>
<?php if(isset($batch['skipped_junk_n'])):$sj=(array)($batch['skipped_junk']??[]);$sjn=(int)$batch['skipped_junk_n'];?><div class="panel" id="skipped-junk"><?php if($sjn===0):?><p class="muted">Skipped junk: none found.</p><?php else:?><details><summary>Skipped junk: <?=$sjn?> item<?=$sjn===1?'':'s'?> not imported</summary><p class="muted">Mac resource files (._), hidden and system files, shortcuts (.lnk), system folders and empty files are skipped for every source. Your originals are not changed. Files starting with _ are real content and are kept.</p><ul><?php foreach(array_slice($sj,0,300) as $sji):?><li><?=h((string)$sji['path'])?><?=!empty($sji['dir'])?'/':''?> — <?=h((string)$sji['why'])?></li><?php endforeach;?></ul><?php if($sjn>300):?><p class="muted">… and <?=$sjn-300?> more.</p><?php endif;?></details><?php endif;?></div><?php endif;?>
<?php if($editable):?>
<?php if($batch['state']==='approved'):?><div class="panel" id="approval"><div class="step">STEP 3 — FINAL CHECK</div><h2>Ready to stage <?=count($selectedPrimary)?> materials</h2><p><?=count($selectedPrimary)?> materials + <?=$selectedArt?> artwork files · <?=number_format($selectedBytes/1048576,1)?> MiB</p><?php $cs=0;$cg=0;foreach($selected as $ar0){if(empty($ar0['support']))continue;if(!empty($ar0['generated_key']))$cg++;else $cs++;}$cn=count($selectedPrimary)-$cs-$cg;$rn=0;foreach($selectedPrimary as $pr0){if(basename($pr0['target'])!==basename($pr0['source']))$rn++;}$dn=count(array_filter($selectedPrimary,fn($pr0)=>!empty($pr0['candidate'])||!empty($pr0['already_imported'])));$tree=[];foreach($selected as $trid=>$tr0){bi_tree_add($tree,explode('/',$tr0['target']),['row'=>$tr0,'rid'=>$trid]);}?>
<h3>Quick checks</h3><ul class="checks"><?php $nc4k=gp_new_category_counts(array_map(fn($x)=>(string)$x['target'],$selectedPrimary));?><li id="new-categories"><?=$nc4k?'New categories (new top-level folders in D:\xampp\htdocs\Edutek\videos, created only when you publish; each listed once with its number of selected items): <strong>'.h(gp_newcat_text($nc4k)).'</strong>':'New categories: none (everything goes into existing library categories)'?></li><li>Covers: <?=$cs?> supplied, <?=$cg?> generated, <?=max(0,$cn)?> without a cover</li><li>Renamed files: <?=$rn?></li><li>Possible duplicates added on purpose: <?=$dn?></li><?php $mcN=['convert'=>0,'audio'=>0,'faststart'=>0,'none'=>0,'kept'=>0];foreach($selectedPrimary as $mr0){$me0=mc_effective($mr0);if($me0)$mcN[$me0['action']]=($mcN[$me0['action']]??0)+1;}if(array_sum($mcN)>0):?><li>Videos: <?=$mcN['convert']?> converted to H.264 (720p max), <?=$mcN['faststart']+$mcN['audio']?> fixed without re-encoding, <?=$mcN['none']+$mcN['kept']?> copied as they are. Conversion runs in the background after you stage; the originals are not changed.</li><?php endif;?></ul>
<h3>What will be staged (planned library layout)</h3><ul class="tree"><li><span class="folder">videos</span><?=bi_tree_html($tree,$id,$batch['rows'])?></li></ul><p class="muted">Staged copies are saved on the Beelink in data\guided-v08\<?=h(substr($id,0,8))?>...\files. This step writes nothing to the Edutek library or your USB; publishing is a separate step.</p><?php $repl=array_filter($primary,fn($rr)=>($rr['dup_decision']??'')==='replace'&&empty($rr['replace']));$repOk=array_filter($primary,fn($rr)=>!empty($rr['include'])&&!empty($rr['replace']));$rej=array_filter($primary,fn($rr)=>($rr['dup_decision']??'')==='reject');?><?php if($repOk):?><p class="notice"><?=count($repOk)?> item<?=count($repOk)>1?'s':''?> will replace a library file when published (the old file is archived; undo restores it).</p><?php endif;?><?php if($repl):?><div class="warning"><h3>Skipped: these can&rsquo;t replace anything</h3><ul><?php foreach($repl as $rr):?><li><?=h($rr['title'])?> (no library file to replace; nothing is copied)</li><?php endforeach;?></ul></div><?php endif;?><?php if($rej):?><p class="muted"><?=count($rej)?> duplicate cop<?=count($rej)>1?'ies were':'y was'?> rejected and will not be added.</p><?php endif;?><p>Your source files stay unchanged. You can clear the staged copies later.</p><form method="post" id="execute-form"><?php hidden_fields($csrf,$id);?><input type="hidden" name="revision" value="<?=h($batch['approval'])?>"><input type="hidden" name="confirm" value="ADD"><button class="primary" id="add-button" name="action" value="execute">Stage <?=count($selectedPrimary)?> materials for publishing</button></form><p class="muted" id="approval-note">Any edits below require a new Review selection before adding.</p><div id="copy-progress" hidden style="margin-top:14px"><div style="background:#dbe6f1;border-radius:8px;height:24px;overflow:hidden"><div id="copy-bar" style="background:#155ea6;height:100%;width:0%;transition:width .4s"></div></div><p id="copy-text" class="muted">Starting...</p></div></div><?php endif;?>
<?php if(!empty($batch['issues'])):?><div class="notice"><?php foreach($batch['issues'] as $issue)if($issue['kind']==='preview'):?><p>Creating preview: <?=h($batch['rows'][$issue['id']]['title'])?>. Wait for the image, then click Review selection again.</p><?php endif;?></div><?php endif;?>
<?php $catIss=array_filter($batch['issues']??[],fn($x)=>($x['kind']??'')==='category');if($catIss):?><div class="warning" id="category-needed" role="alert"><p><strong>Choose a category</strong> for <?=count($catIss)?> item<?=count($catIss)===1?'':'s'?> before <?=count($catIss)===1?'it':'they'?> can be added: no category could be worked out from the source folder name, and Unsorted is never chosen automatically.</p><ul><?php foreach($catIss as $ci):?><li><?=h($ci['message'])?></li><?php endforeach;?></ul></div><?php endif;?>
<div class="panel" id="bulk-select"><div id="choose-row"><input type="search" id="search" placeholder="Find a title or collection" aria-label="Search materials"><button type="button" id="show-all">Show all</button><button type="button" id="select-page">Select this page</button><button type="button" id="clear-page">Clear this page</button></div><details id="more-select"><summary>Select all and unselect all options</summary><div class="toolbar"><button type="button" data-sel="all">Select all (<span class="n">0</span>)</button><button type="button" data-sel="Books">Select all books (<span class="n">0</span>)</button><button type="button" data-sel="Videos">Select all videos (<span class="n">0</span>)</button><button type="button" data-sel="Music">Select all music (<span class="n">0</span>)</button><button type="button" data-sel="Audiobooks">Select all audiobooks (<span class="n">0</span>)</button><button type="button" data-sel="none">Unselect all</button></div><div class="toolbar"><label><input type="checkbox" id="sel-include-review"> Also include Needs review items</label><label><input type="checkbox" id="sel-include-recent"> Also include Recently imported items</label></div></details><p class="muted" id="sel-status">By default the Select all buttons skip Needs review and Recently imported items. They only tick the boxes; nothing is added until you click Review selection.</p></div>
<h2 id="step-title"><?=($batch['screen']??'select')==='fix'?'Step 2 — Fix these items':'Step 1 — Choose materials'?></h2>
<p id="step-help"><?=($batch['screen']??'select')==='fix'?'Only selected items needing a decision are shown. Fix them or unselect them, then Review selection again.':'Select the books or videos you want to add. Their attached artwork follows automatically.'?></p>



<p class="muted" id="scope"></p><div class="warning" id="dup-help" hidden><h3>Possible duplicates</h3><p>These files look like ones already in the library, in an earlier import, or elsewhere in this import. Choose the card(s) to keep (Tab to a card, then Enter or Space); each card&rsquo;s Outcome line shows the result.</p><ul><li>Only the existing copy: the new copy is not added.</li><li>Only the new copy: it replaces the library file; the old file is archived and undoing the publish restores it. (Copies staged in an earlier import can&rsquo;t be replaced, so the new copy is skipped.)</li><li>Both: both are kept.</li></ul></div><div class="notice" id="names-help" hidden>These items have a title or file name the page had to correct, for example reserved characters or a trailing period. Check the suggested name on each card and click Use suggested filename. Once accepted, the item moves back to the main list.</div><div class="notice" id="imp-help" hidden>These source files match something you already imported earlier, so they are set aside and are not part of the review. Tick one only if you really want to add it again.</div>
<div id="dup-compare" hidden>
<div id="dup-chips" class="toolbar"><button type="button" data-dupfilter="all">All matches (<span class="n">0</span>)</button><button type="button" data-dupfilter="device">Already on this device (<span class="n">0</span>)</button><button type="button" data-dupfilter="source">Within the source (<span class="n">0</span>)</button></div>
<?php $dupSets=[];foreach($primary as $drid=>$dr){if(empty($dr['candidate']))continue;$dk=$dr['type'].'|'.bi_dupkey((string)($dr['original_title']??$dr['title'])).'|'.$dr['bytes'];$dupSets[$dk]['members'][$drid]=$dr;if(!empty($dr['existing']))$dupSets[$dk]['existing']=$dr['existing'];}
uasort($dupSets,fn($a,$b)=>bi_dup_rank($a)<=>bi_dup_rank($b));$cd=0;$ce=0;$cs=0;foreach($dupSets as $s0){$hasdev=false;foreach(($s0['existing']??[]) as $e0)if(($e0['kind']??'earlier')==='device')$hasdev=true;if($hasdev)$cd++;elseif(!empty($s0['existing']))$ce++;if(count($s0['members'])>1)$cs++;}?>
<?php if($dupSets):?><div class="notice"><?=count($dupSets)?> matches (<?=$cd?> with a library file, <?=$cs?> within this import). Device list built: <?=h($batch['device_index_built']??'')?:'never - run Build-DeviceIndex.ps1'?></div><?php endif;?>
<?php $gi=0;foreach($dupSets as $set):$gi++;$members=$set['members'];$exs=$set['existing']??[];$first=reset($members);?>
<section class="panel dup-set" data-members="<?=h(implode(',',array_keys($members)))?>" data-decided="0" data-kinds="<?=h(bi_dup_kinds($exs,count($members)>1))?>" data-has-orig="<?=$exs?'1':'0'?>" data-lib-path="<?=h(rp_dev_path($exs))?>">
<h3>Match <?=$gi?> of <?=count($dupSets)?>: <?=h($first['title'])?></h3><p><?=bi_dup_badges($exs,count($members)>1)?></p>
<div class="dup-cols">
<?php $rcC=rc_cards($members,$exs);$rcM=rc_marks($rcC);$rcVs=$exs?((($exs[0]['kind']??'earlier')==='device')?'the copy already on this device':'the copy staged in an earlier import'):'copy 1';$rcI=0;$rcDev=false;foreach($exs as $x)if(($x['kind']??'')==='device')$rcDev=true;?>
<?php foreach($exs as $x):$rc=$rcC[$rcI];$rcK=$rcI;$rcI++;$rcLp='Edutek > videos > '.rc_path($rc['path']);?><div class="dup-col existing" data-key="orig" tabindex="0" role="button" aria-pressed="false" data-o-none="<?=h($rc['dev']?'Stays at '.$rcLp.' unless you keep only the new copy':'Stays staged in that earlier import')?>" data-o-win="<?=h($rc['dev']?'Stays at '.$rcLp:'Stays staged in that earlier import')?>" data-o-replaced="<?=h($rc['dev']?'Archived when publishing (undo restores it); the new copy takes its place at '.$rcLp:'Stays staged in that earlier import (it cannot be replaced)')?>"><div class="dup-tag"><?=h(($x['kind']??'earlier')==='device'?'ALREADY ON THIS DEVICE':'ALREADY STAGED IN AN EARLIER IMPORT')?></div><?php if($rc['why']!==''):?><p class="dup-why">Matched by: <?=h($rc['why'])?></p><?php endif;?><div class="dup-state"></div><div class="art placeholder">EXISTING</div><h4><?=h($rc['title']??$first['title'])?></h4><?=rc_rows($rc,$rcM[$rcK]??[],$rcK===0?'the other cards':$rcVs)?><p class="cmp-row"><span class="cmp-k">Outcome:</span> <span class="dup-outcome"><?=h($rc['dev']?'Stays at '.$rcLp.' unless you keep only the new copy':'Stays staged in that earlier import')?></span></p></div><?php endforeach;?>
<?php $ci=0;foreach($members as $mrid=>$m):$ci++;?>
<?php $rc=$rcC[$rcI];$rcK=$rcI;$rcI++;?><div class="dup-col" data-key="<?=h($mrid)?>" data-n="<?=$ci?>" tabindex="0" role="button" aria-pressed="false" data-o-add="<?=h('Would be added to: Edutek > videos > '.rc_path($rc['plan']))?>" data-o-reject="Not added (rejected)" data-o-skip="Skipped: only library files can be replaced"><div class="dup-tag">COPY <?=$ci?> (IN THE SOURCE)</div><?php if($rcDev):?><p class="dup-why">&nbsp;</p><?php endif;?><div class="dup-state"></div>
<?php if(!empty($m['art'])):?><img class="art" alt="Artwork for copy <?=$ci?>" src="?id=<?=h($id)?>&amp;art=<?=h($mrid)?>"><?php else:?><div class="art placeholder">NO ARTWORK</div><?php endif;?>
<h4><?=h($m['title'])?></h4>
<?=rc_rows($rc,$rcM[$rcK]??[],$rcK===0?'the other cards':$rcVs)?>
<p class="cmp-row"><span class="cmp-k">Outcome:</span> <span class="dup-outcome"><?=h($rc['rep']!==''?'Replaces the library file: Edutek > videos > '.rc_path($rc['rep']).' (the old file is archived first)':'Would be added to: Edutek > videos > '.rc_path($rc['plan']))?></span></p>
</div>
<?php endforeach;?>
</div>
<p class="cmp-legend">Marks compare each row with <?=h($rcVs)?><?=$exs?'':' (copy 1 with the others)'?>; locations are compared between source copies only.</p>
<p class="muted rc-note"><?=h(rc_note($rcC,$members,$rcDev))?></p>
<p class="dup-status notice" aria-live="polite">Not decided yet: choose the card(s) to keep.</p>
</section>
<?php endforeach;?>
<?php if(!$dupSets):?><p class="notice">No possible duplicates were found in this import.</p><?php endif;?><?=dx_age_html($batch)?>
</div>
<form method="post" id="editor"><?php hidden_fields($csrf,$id);?><input type="hidden" name="draft" id="draft-json"><input type="hidden" name="new_categories" id="newcats-json" value=""><input type="hidden" name="action" id="draft-action" value="save">
<?php
// Patch 4k: the category list is every top-level folder of the library (read now), not a saved snapshot, and it is a real list (select), not typing suggestions.
$libState=gp_lib_state();$libCats=$libState['names'];$newCats=gp_batch_newcats($batch);
?>
<template id="category-options"><?php foreach($libCats as $category):?><option value="<?=h($category)?>"><?=h($category)?></option><?php endforeach;?></template>
<details class="panel" id="bulk-placement-panel">
<summary>Change placement for several materials at once</summary>
<p class="muted">Final root: <code>D:\xampp\htdocs\Edutek\videos</code>. This edits the plan only; nothing is copied.</p>
<label>Apply to <select id="placement-scope"><option value="collection">Current collection — all its materials and artwork</option><option value="category">Source category — this collection and all its sibling subfolders</option><option value="selected">Selected materials — across all pages and filters</option></select></label>
<p id="placement-scope-summary" class="muted"></p>
<label><input type="checkbox" id="change-category" checked> Change category</label>
<label for="bulk-category">Category</label> <select id="bulk-category" data-filled="1" aria-describedby="bulk-category-msg"><option value="">Choose a category…</option><?=gp_cat_options($libCats,$newCats)?><option value="__new__">New category…</option></select>
<span class="new-cat" id="bulk-new-wrap" hidden><label>New category name <input type="text" id="bulk-category-new" data-role="category-new" maxlength="120" autocomplete="off" aria-describedby="bulk-category-msg"></label> <button type="button" class="use-new-cat">Use this name</button> <button type="button" class="use-suggested" hidden>Use suggested name</button></span>
<p class="muted category-msg" id="bulk-category-msg" role="status" aria-live="polite"><span id="category-count"><?=count($libCats)?></span> library categories (the top-level folders in D:\xampp\htdocs\Edutek\videos<?=$libState['source']==='library'?', read just now':''?>), or New category… to type a new one.<?php if($libState['source']!=='library'):?> <strong>The library folder could not be read, so this list comes from the saved category list and may be out of date.</strong><?php endif;?></p>
<label><input type="checkbox" id="keep-subfolders" checked> Keep each material’s current subfolder</label>
<label>New subfolder / book subcategory / album / audiobook folder <input type="text" id="bulk-folder" disabled placeholder="Used only when Keep current subfolder is unchecked. Empty = same name as the category"></label>
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

<section class="panel" id="set-category-panel" aria-labelledby="setcat-title"><h3 id="setcat-title">Set category for several items</h3>
<p class="muted">Tick “Add this video” on the items you want (or choose “All items in this folder”), pick a category, then choose the Set category button. Type letters in the list to jump to a category; new categories of this import are at the top, marked (new). Subfolders follow the usual rule: empty means a subfolder with the same name as the category.</p>
<label for="setcat-scope">Apply to</label> <select id="setcat-scope"><option value="selected">Selected items (ticked “Add this video”)</option><option value="folder">All items in this folder (the source folder chosen on the left)</option></select>
<label for="setcat-pick">Category</label> <select id="setcat-pick" data-filled="1" aria-describedby="setcat-msg"><option value="">Choose a category…</option><?=gp_cat_options($libCats,$newCats)?><option value="__new__">New category…</option></select>
<span class="new-cat" id="setcat-new-wrap" hidden><label>New category name <input type="text" id="setcat-new" data-role="category-new" maxlength="120" autocomplete="off" aria-describedby="setcat-msg"></label> <button type="button" class="use-new-cat">Use this name</button> <button type="button" class="use-suggested" hidden>Use suggested name</button></span>
<button type="button" id="setcat-apply">Set category for selected items</button>
<p class="muted category-msg" id="setcat-msg" role="status" aria-live="polite"></p>
<p class="muted" id="newcat-summary" aria-live="polite"><?=$newCats?h('New categories in this import: '.implode(', ',$newCats).'.'):'No new categories in this import yet.'?></p>
</section>
<?php foreach($groups as $group=>$rows):?><div class="collection-heading" data-heading="<?=h($group)?>"><?=h(gp_group_label($group,$rows,$batch))?></div>
<?php foreach($rows as $rid=>$r):$fix=needs_fix($r);$fixAccepted=$fix&&hash_equals(fix_token($r),(string)($r['fix_accept']??''));$pending=false;foreach($batch['issues']??[] as $issue)if($issue['id']===$rid)$pending=true;?>
<article class="item" data-category="<?=h($r['placement_category'])?>" data-folder="<?=h($r['placement_folder'])?>" data-placement-override="<?=!empty($r['placement_override'])?'1':'0'?>" data-auto-category="<?=h(gp_auto_category($r))?>" data-source-category="<?=h(explode('/',$r['source'])[0])?>" data-file="<?=h(basename($r['target']))?>" data-id="<?=h($rid)?>" data-group="<?=h($group)?>" data-type="<?=h($r['type'])?>" data-damaged="<?=!empty($r['damaged'])?'1':'0'?>" data-pending="<?=$pending?'1':'0'?>" data-fix="<?=h($r['fix_accept']??'')?>" data-token="<?=h(fix_token($r))?>" data-duplicate="<?=!empty($r['duplicate_add'])?'1':'0'?>" data-candidate="<?=!empty($r['candidate'])?'1':'0'?>" data-imported="<?=!empty($r['already_imported'])?'1':'0'?>" data-needfix="<?=$fix?'1':'0'?>" data-bytes="<?=(int)$r['bytes']?>" data-dupkind="<?=h(bi_dup_kinds($r['existing']??[],!empty($r['dup_source'])))?>" data-dup-decision="<?=h($r['dup_decision']??'')?>" data-search="<?=h(mb_strtolower($r['title'].' '.$group))?>">
<div><?php if(!empty($r['art'])):?><img class="art" loading="lazy" alt="Artwork for <?=h($r['title'])?>" src="?id=<?=h($id)?>&amp;art=<?=h($rid)?>"><?php else:?><?php if(in_array($r['type'],['Videos','Books'],true)&&($r['art_count']??0)===0):?><div class="art placeholder generated-preview" data-preview-id="<?=h($rid)?>"><?=h($r['type']==='Books'?'BOOK':'VIDEO')?></div><?php else:?><div class="art placeholder"><?=h($r['type']==='Books'?'BOOK':(au_is_audio($r)?'AUDIO':'VIDEO'))?></div><?php endif;?><?php endif;?></div><div><h3 class="display-title"><?=h($r['title'])?></h3><span class="badge"><?=h(['Books'=>'Book','Music'=>'Music','Audiobooks'=>'Audiobook'][$r['type']]??'Video')?></span><span class="badge"><?=number_format($r['bytes']/1048576,1)?> MiB</span><?=mc_badge_html($r)?><span class="badge dup-badge" hidden></span><?php if(!empty($r['already_imported'])):?><span class="badge" style="background:#e2e8f0"><?=h(($r['recent_kind']??'')==='same'?(!empty($r['converted_before'])?'RECENTLY IMPORTED - SAME FILE (converted earlier)':'RECENTLY IMPORTED - SAME FILE'):'RECENTLY IMPORTED - SIMILAR FILE')?><?=!empty($r['recent_where']['created'])?' ('.h(substr((string)$r['recent_where']['created'],0,10)).')':''?></span><?php endif;?><span class="muted"><span class="art-status"><?=!empty($r['art'])?(!empty($batch['rows'][$r['art']]['generated_key'])?'Generated preview':'Artwork attached'):(($r['art_count']??0)>1?'Choose artwork':($r['type']==='Videos'?'Preview will be generated':(au_is_audio($r)?'No cover picture':'Cover will be generated from page 1')))?></span></span><br>
<?php $pc4k=(string)($r['placement_category']??'');$prole4k=gp_role((string)$r['type']);?><p class="muted">Planned library location: <span class="placement-label">Edutek › videos › <?=h(str_replace('/',' › ',dirname($r['target'])))?></span> <span class="custom-placement"><?=!empty($r['placement_override'])?'(custom location)':''?></span> <span class="newcat-badge"<?=($pc4k!==''&&$prole4k===''&&gp_category_match($pc4k,true)===null)?'':' hidden'?>>NEW CATEGORY</span></p><p class="warning cat-needed"<?=($pc4k===''&&$prole4k==='')?'':' hidden'?>>No category yet: none could be worked out from the source folder name (Unsorted is never chosen automatically). Choose one under “Change this item’s placement” before this item can be added.</p><?php $snReq=gp_requested($r);if(empty($r['replace'])&&basename($r['target'])!==$snReq):?><p class="muted name-clean">Original name: <?=h($snReq)?> → Clean name: <?=h(basename($r['target']))?></p><?php endif;?><label class="select-label"><input type="checkbox" data-role="include" <?=$r['include']?'checked':''?><?=!empty($r['damaged'])?' disabled':''?>> Add this <?=$r['type']==='Books'?'book':(au_is_audio($r)?'audio file':'video')?><?=!empty($r['damaged'])?' (can’t be played — file is damaged: '.h((string)($r['media']['why']??'')).')':''?></label>

<details class="individual-placement"<?=($pc4k===''&&$prole4k==='')?' open':''?>><summary>Change this item’s placement</summary><label for="cat-<?=h($rid)?>">Category</label> <select id="cat-<?=h($rid)?>" data-role="category-pick" aria-describedby="cat-msg-<?=h($rid)?>"<?=$prole4k!==''?' data-fixed="1"':''?>><?php if($prole4k!==''):?><option value="<?=h($pc4k)?>" selected><?=h($pc4k)?></option><?php else:?><option value=""<?=$pc4k===''?' selected':''?>>Choose a category…</option><?php if($pc4k!==''):?><option value="<?=h($pc4k)?>" selected><?=h($pc4k)?><?=gp_category_match($pc4k,true)===null?' (new)':''?></option><?php endif;?><option value="__new__">New category…</option><?php endif;?></select><input type="hidden" data-role="category" value="<?=h($pc4k)?>"><?php if($prole4k===''):?><span class="new-cat" hidden><label>New category name <input type="text" data-role="category-new" maxlength="120" autocomplete="off" aria-describedby="cat-msg-<?=h($rid)?>"></label> <button type="button" class="use-new-cat">Use this name</button> <button type="button" class="use-suggested" hidden>Use suggested name</button></span><?php endif;?><span class="muted category-msg" id="cat-msg-<?=h($rid)?>" role="status" aria-live="polite"><?=$prole4k!==''?h($r['type'].' always go under '.$pc4k.'.'):'Choose from the '.count($libCats).' library categories, or New category… to type a new one (created only when you publish).'?></span><br><?php $fa=gp_folder_auto($r);$frole=gp_role((string)$r['type'])!=='';?><label><?=h($r['type']==='Books'?'Book subcategory (required)':'Subfolder')?> <span class="folder-tag <?=$fa?'is-auto':'is-custom'?>"><?=$fa?'auto':'custom'?></span> <input type="text" data-role="folder" id="fold-<?=h($rid)?>" class="folder-box<?=$fa?' auto-folder':''?>" value="<?=h($r['placement_folder'])?>" placeholder="<?=h($frole?'':$r['placement_category'].' (automatic: same name as the category)')?>" aria-describedby="fold-note-<?=h($rid)?>"></label><?php if(!$frole):?> <button type="button" class="use-auto">Use automatic subfolder</button><?php endif;?><p class="muted folder-note" id="fold-note-<?=h($rid)?>"><?=h(gp_folder_note($r))?></p><?php if(!$frole):?><p class="cat-share"><button type="button" class="cat-to-selected">Use this category for all selected items</button> <button type="button" class="cat-to-folder">Use this category for all items in this folder</button></p><?php endif;?><p class="muted">Typing a category or subfolder marks this item as a custom location, protected from later collection changes unless you choose to replace custom locations.<?=$frole?'':' An empty subfolder box means automatic: a subfolder with the same name as the category.'?></p></details><details><summary>Change displayed title</summary><label>Title<input type="text" data-role="title" value="<?=h($r['title'])?>" maxlength="300"></label><p class="muted">We generate a safe filename and keep the artwork linked. Your original stays unchanged. Save progress to preview a changed filename.</p></details>
<?php if($fix):?><div class="warning decision name-decision"><h3>Filename needs review</h3><div class="name-pair">Original: <?=h($r['source'])?><br>Suggested: <?=h($r['target'])?></div><p class="muted">The Edutek naming rule had to guess: <?=h(implode('; ',needs_fix_why($r)))?>. Check the suggested name, or change the displayed title. The displayed title can keep readable punctuation.</p><button type="button" class="accept-fix" <?=$fixAccepted?'disabled':''?>><?=$fixAccepted?'Suggested filename accepted':'Use suggested filename'?></button></div><?php endif;?>
<?php if(!empty($r['lib_size'])&&empty($r['dup_lib'])):?><p class="notice">Same size as <?=(int)($r['lib_size_n']??count($r['lib_size']))?> file(s) in the Edutek library, for example <?=h($r['lib_size'][0])?>. When publishing, the contents are compared: if they are identical, publishing stops and nothing is copied.</p><?php endif;?><?php if(!empty($r['candidate'])):?><div class="warning decision"><h3>Possible duplicate</h3><p><?=!empty($r['dup_lib'])?'A library file has a similar name (same letters and digits).':'Another file has the same title, type and size.'?> Publishing compares the contents and stops if they are identical.</p><p><?=bi_dup_badges($r['existing']??[],!empty($r['dup_source']))?></p><?php foreach($r['existing']??[] as $ex):?><p class="muted"><?=h(bi_dup_where($ex))?></p><?php endforeach;?><ul><?php foreach($primary as $otherId=>$other)if($otherId!==$rid&&$other['type']===$r['type']&&$other['bytes']===$r['bytes']&&bi_dupkey((string)($other['original_title']??pathinfo($other['source'],PATHINFO_FILENAME)))===bi_dupkey((string)($r['original_title']??pathinfo($r['source'],PATHINFO_FILENAME)))):?><li class="muted"><?=h($other['source'])?></li><?php endif;?></ul><button type="button" class="accept-duplicate" <?=!empty($r['duplicate_add'])?'disabled':''?>><?=!empty($r['duplicate_add'])?'Adding this copy confirmed':'Add this copy anyway'?></button><p class="muted">Or untick this material. Nothing is deleted.</p></div><?php endif;?>
<?=rp_card($r)?>
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
<?php if(($batch['source_key']??'legacy')==='legacy'):?>Desktop &rsaquo; Videos<?php elseif(str_starts_with((string)($batch['source_key']??''),'h:')):?><?=implode(' &rsaquo; ',array_map('h',array_filter(explode(chr(92),hv_win((string)$batch['src_root'])),'strlen')))?><?php else:?>Import Sources &rsaquo; <?=h(substr((string)$batch['source_key'],2))?><?php endif;?><?php if($sourceFolder!=='.'):?>
 &rsaquo; <?=h(str_replace('/', ' › ', $sourceFolder))?>
<?php endif;?>
</p>
<div><?=h(basename($r['source']))?></div>
</div>
<div style="background:#e8f3ed;padding:12px;border-radius:7px;margin:10px 0">
<div>Final Edutek destination — planned; copied there only when the batch is published</div>
<p style="margin:6px 0;overflow-wrap:anywhere">
Edutek &rsaquo; videos
<?php if($targetFolder!=='.'):?>
 &rsaquo; <?=h(str_replace('/', ' › ', $targetFolder))?>
<?php endif;?>
</p>
<div><?=h(basename($r['target']))?></div>
</div>
<p class="muted">This importer writes only to its staging folder. After editing a title or placement, Save progress refreshes the exact paths.</p>
<details>
<summary>Show full Windows paths</summary>
<p>Original:</p>
<code style="display:block;overflow-wrap:anywhere;white-space:normal;user-select:all"><?=h($sourceWindows)?></code>
<p>Final Edutek path — planned (after publishing):</p><code style="display:block;overflow-wrap:anywhere;white-space:normal;user-select:all"><?=h($finalWindows)?></code><p>Staging path:</p>
<code style="display:block;overflow-wrap:anywhere;white-space:normal;user-select:all"><?=h($targetWindows)?></code>
</details>
</details>
</div></article>
<?php endforeach;endforeach;?>
<p id="empty" hidden>No materials match this view. Your selections are unchanged. Use Show all materials to reset the view.</p>
<div class="toolbar"><button type="button" id="previous">Previous</button><span id="page-label"></span><button type="button" id="next">Next</button></div>
<div class="actions"><span id="selection-count"></span><button type="submit" data-action="save">Save progress</button><button type="submit" class="primary" data-action="preview">Review selection →</button></div></form>
<noscript><p class="error">This page needs JavaScript enabled to save selections safely.</p></noscript>
<?php else:?>
<?php $pi=pq_info($id);$pqFirst=$pi['undo_req']||in_array($pi['state'],['published','undone','reversed','failed'],true);if($pqFirst)echo pq_panel($batch,$id,$csrf,$pi);?><?php if($batch['state']==='converting'):$mjN=count((array)($batch['media_jobs']??[]));?><div class="panel" id="convert-panel"><div class="step">STAGING · MAKING VIDEOS PLAY IN EVERY BROWSER</div><h2 id="convert-title" tabindex="-1">Converting <?=$mjN?> video<?=$mjN===1?'':'s'?> for browsers</h2><p>This runs in the background on the Beelink. You can leave this page open or come back later; the page updates by itself. Your originals are not changed, and nothing is published.</p><div style="background:#dbe6f1;border-radius:8px;height:24px;overflow:hidden" role="progressbar" aria-labelledby="convert-title" aria-valuemin="0" aria-valuemax="100" id="conv-bar-wrap"><div id="conv-bar" style="background:#155ea6;height:100%;width:0%;transition:width .4s"></div></div><p id="conv-text" class="muted" role="status" aria-live="polite">Starting&hellip;</p><script>(()=>{const tok=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,bid=<?=json_encode($id)?>,bar=document.getElementById("conv-bar"),wrap=document.getElementById("conv-bar-wrap"),txt=document.getElementById("conv-text"),mib=n=>(n/1048576).toFixed(1);let lastSay="";const tick=()=>{fetch("/bulk-import-guided.php?id="+encodeURIComponent(bid)+"&progress=1&token="+encodeURIComponent(tok),{credentials:"same-origin",cache:"no-store"}).then(r=>r.ok?r.json():null).then(info=>{if(!info)return;if(info.state!=="converting"){txt.textContent="Finished. Loading the result\u2026";location.reload();return;}const p=info.progress;if(!p)return;const done=p.base+p.file_done,pct=p.total?Math.min(100,done/p.total*100):0;bar.style.width=pct.toFixed(1)+"%";wrap.setAttribute("aria-valuenow",pct.toFixed(0));let t;if(p.phase==="converting"){t=(p.label||"Converting")+": "+p.file+" \u2013 file "+Math.min(p.files_total,p.files_done+1)+" of "+p.files_total+" \u2013 "+(p.pct||0).toFixed(0)+"% of this file"+(p.speed?" \u2013 "+p.speed+"\u00d7 real time":"")+(p.elapsed?" \u2013 "+p.elapsed+" s":"");}else{t=(p.phase==="verifying"?"Checking: ":"Copying: ")+p.file+" \u2013 "+mib(done)+" of "+mib(p.total)+" MiB";}t+=" \u2013 overall "+pct.toFixed(0)+"%";if(t!==lastSay){txt.textContent=t;lastSay=t;}}).catch(()=>{});};setInterval(tick,1500);tick();})();</script></div><?php endif;?>
<?php if(!empty($batch['media_warnings'])):?><div class="warning" role="alert"><h3><?=array_filter((array)$batch['media_warnings'],fn($w)=>!empty($w['audio_skip']))?'Some files could not be converted':'Some videos could not be converted'?></h3><ul><?php foreach($batch['media_warnings'] as $mw):?><li><?=h((string)$mw['message'])?></li><?php endforeach;?></ul></div><?php endif;?>
<div class="panel"><h2><?=h($batch['state']==='complete'?($pqFirst?'Files in this import':'Materials staged for publishing'):($batch['state']==='reversed'?'Import reversed':($batch['state']==='converting'?'Files in this import':'Import needs attention')))?></h2>
<?php foreach($selectedPrimary as $rid=>$r):$op=$batch['operations'][$rid]??null;$opm=(array)($op['media']??[]);?><p><?=h($r['title'])?> — <?=h(($op['state']??'')==='converting'?'converting':(in_array($rid,(array)($batch['media_jobs']??[]),true)&&!$op&&$batch['state']==='converting'?'waiting to convert':($op['state']??'not copied')))?><?php if(!empty($opm['label'])):?> · <span class="<?=($opm['action']??'')==='fallback'?'warning':'muted'?>"><?=h((string)$opm['label'])?><?=isset($opm['seconds'])?' ('.h((string)$opm['seconds']).' s)':''?></span><?php endif;?><?php if(($op['state']??'')==='copied'):?> · <a target="_blank" rel="noopener" href="?id=<?=h($id)?>&amp;open=<?=h($rid)?>">Open staged copy</a><?php endif;?></p><?php endforeach;?></div>
<?php if(!$pqFirst)echo pq_panel($batch,$id,$csrf,$pi);?><?php if(in_array($batch['state'],['complete','importing','interrupted','attention','reversing'],true)&&pq_can_reverse($pi)):?><div class="panel"><h2>Clear the staged copies of this import (does not touch the Edutek library)</h2><p>Checks all <?=count($batch['operations'])?> recorded file operations (artwork included). Unchanged copies move to recovery storage; changed or uncertain files are kept and reported.</p><p>Your source, earlier imports and the Edutek library are not touched. To remove published files, use &ldquo;Undo this publish&rdquo;.</p><form method="post" id="reverse-form"><?php hidden_fields($csrf,$id);?><input type="hidden" name="confirm" value="REVERSE"><button name="action" value="reverse">Clear staged copies of this import (does not touch the library)</button></form></div><?php endif;?>
<?php endif;?>
<?php if(!empty($batch['conflicts'])):?><div class="error"><?=h(implode(' | ',$batch['conflicts']))?></div><?php endif;?>
<?php if(!empty($batch['operations'])):?><details class="panel"><summary>File-operation report — includes artwork</summary><?php foreach($batch['operations'] as $op):?><p class="report"><?=h($op['target'])?> — <?=h($op['state'])?><?=!empty($op['media']['label'])?' — '.h((string)$op['media']['label']).(isset($op['source_sha256'],$op['sha256'])&&$op['source_sha256']!==$op['sha256']?' (original SHA-256 '.h(substr((string)$op['source_sha256'],0,12)).'…, staged '.h(substr((string)$op['sha256'],0,12)).'…)':''):''?></p><?php endforeach;?></details><?php endif;?>
<?php if(!empty($batch['events'])):?><details class="panel" open><summary>Saved activity</summary><?php foreach($batch['events'] as $event):?><p class="muted"><?=h($event)?></p><?php endforeach;?></details><?php endif;?>
<?php endif;?></section></div><section class="panel pub-foot" id="pub-foot" aria-labelledby="pub-foot-title"><h2 id="pub-foot-title">Published imports and undo</h2><p><a class="leave-page" href="?view=published#published-imports">Published imports and undo</a></p><p class="muted">Lists the imports that were published to the Edutek library, with Undo for each one that can still be undone.</p></section></main>
<?php // Patch 4m: $newCats/$libCats are set only where the item editor is shown; every other page (start page, staged, published) gets empty lists here.
if(!isset($newCats)||!is_array($newCats))$newCats=[];if(!isset($libCats)||!is_array($libCats))$libCats=[];?>
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
 dupMembers(p).forEach(r=>{const d=res[r.dataset.id]||'',add=d==='add';r.dataset.dupDecision=d;const lp=p.dataset.libPath||'',sel=get(r,'replace-path'),rep=d==='replace'&&lp!==''&&!!sel&&[...sel.options].some(o=>o.value===lp&&!o.disabled);if(sel){if(rep)sel.value=lp;else if(sel.value===lp)sel.value=''}get(r,'include').checked=add||rep;r.dataset.duplicate=add?'1':'0';const b=r.querySelector('.accept-duplicate');if(b){b.disabled=add;b.textContent=add?'Adding this copy confirmed':'Add this copy anyway'}});
 p.dataset.decided=W.size?'1':'0';changed();render();
}
function dupPaint(){dupPanels.forEach(p=>{
 const hasOrig=p.dataset.hasOrig==='1',W=dupWin.get(p)||new Set(),res=dupCompute(p),ms=dupMembers(p);
 p.querySelectorAll('.dup-col').forEach(c=>{const key=c.dataset.key,st=c.querySelector('.dup-state');let txt='Not chosen (Enter or Space to keep)',cls='';
  if(key==='orig'){if(W.has('orig')){txt='WINNER - the original stays';cls='winner'}else if(W.size){txt=p.dataset.libPath?'REPLACED - moved to the archive when publishing':'KEPT - only library files can be replaced';cls='replaced'}}
  else{const d=res[key]||'';if(d==='add'){txt=hasOrig?'WINNER - added as another copy':'WINNER - will be added';cls='winner'}else if(d==='replace'){txt=p.dataset.libPath?'WINNER - replaces the library file':'CHOSEN - skipped, only library files can be replaced';cls='winner'}else if(d==='reject'){txt='REJECTED';cls='rejected'}}
  const oc=c.querySelector('.dup-outcome');if(oc){let ot='';if(key==='orig'){ot=W.has('orig')?c.dataset.oWin:(W.size?c.dataset.oReplaced:c.dataset.oNone)}else{const d=res[key]||'',rr=ms.find(z=>z.dataset.id===key),sv=rr?(get(rr,'replace-path')?.value||''):'',lp=sv||(d==='replace'?(p.dataset.libPath||''):'');if(d==='reject')ot=c.dataset.oReject;else if(d==='add')ot=c.dataset.oAdd;else if(d==='replace')ot=lp?'Replaces the library file: Edutek > videos > '+lp.split('/').join(' > ')+' (the old file is archived first)':c.dataset.oSkip;else ot=sv?'Replaces the library file: Edutek > videos > '+sv.split('/').join(' > ')+' (the old file is archived first)':c.dataset.oAdd}if(ot)oc.textContent=ot}
  c.classList.remove('winner','rejected','replaced');if(cls)c.classList.add(cls);c.setAttribute('aria-pressed',cls==='winner'?'true':'false');if(st)st.textContent=txt});
 let msg='Not decided yet: choose the card(s) to keep.';
 if(W.size){const srcW=ms.filter(r=>W.has(r.dataset.id)).length;
  if(hasOrig&&W.has('orig')&&srcW===0)msg='Existing copy kept; the new copy is not added.';
  else if(hasOrig&&W.has('orig'))msg='Existing copy kept; '+srcW+' new cop'+(srcW>1?'ies are':'y is')+' added as well. Any others are not added.';
  else if(hasOrig)msg=p.dataset.libPath?'The new copy replaces the library file when publishing; the old file is archived and undoing the publish restores it.':'A copy staged in an earlier import can\'t be replaced, so this new copy is skipped.';
  else msg=srcW+' cop'+(srcW>1?'ies':'y')+' will be added; any others are not added.'}
 const s=p.querySelector('.dup-status');if(s)s.textContent=msg;
 ms.forEach(r=>{const bd=r.querySelector('.dup-badge');if(!bd)return;const d=r.dataset.dupDecision||'';bd.hidden=d==='';bd.textContent=d==='add'?'WILL BE ADDED':(d==='replace'?(get(r,'replace-path')?.value?'WILL REPLACE THE LIBRARY FILE':'SKIPPED - ONLY LIBRARY FILES CAN BE REPLACED'):(d==='reject'?'REJECTED':''));bd.style.background=d==='reject'?'#f6d3d3':(d==='replace'?'#fde8c4':'#d4efdc')});
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

items.forEach(r=>{get(r,'include').onchange=()=>{if(get(r,'include').checked&&r.dataset.imported==='1'&&!confirm('This file was already imported earlier. Add it again?')){get(r,'include').checked=false;return}changed();render()};get(r,'title').oninput=e=>{r.querySelector('.display-title').textContent=e.target.value;r.dataset.fix='';r.dataset.search=(e.target.value+' '+r.dataset.group).toLowerCase();const f=r.querySelector('.accept-fix');if(f){f.disabled=true;f.textContent='Save progress to refresh filename suggestion'}changed()};const fix=r.querySelector('.accept-fix');if(fix)fix.onclick=()=>{acceptFix(r);changed();render()};const dup=r.querySelector('.accept-duplicate');if(dup)dup.onclick=()=>{r.dataset.duplicate='1';dup.disabled=true;dup.textContent='Adding this copy confirmed';changed()};const art=get(r,'art-choice');if(art)art.onchange=changed;const rps=get(r,'replace-path');if(rps)rps.onchange=()=>{if(rps.value)get(r,'include').checked=true;changed();render()};});
editor.addEventListener('submit',e=>{const action=e.submitter?.dataset.action||'save';document.getElementById('draft-action').value=action;const draft={};items.forEach(r=>{draft[r.dataset.id]={include:get(r,'include').checked,title:get(r,'title').value,fix_accept:r.dataset.fix||'',duplicate_add:r.dataset.duplicate==='1',art_choice:get(r,'art-choice')?.value||'',category:r.dataset.category,folder:r.dataset.folder,placement_override:r.dataset.placementOverride==='1',dup_decision:r.dataset.dupDecision||'',replace_path:get(r,'replace-path')?.value||''}});document.getElementById('draft-json').value=JSON.stringify(draft);document.getElementById('saved').textContent='Saving…';submitting=true;});

const placementRoles=<?=json_encode(['Books'=>gp_role('Books'),'Music'=>gp_role('Music'),'Audiobooks'=>gp_role('Audiobooks')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const booksDefaultFolder=<?=json_encode(GP_BOOKS_DEFAULT_FOLDER,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>; // Patch 4h
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
 if(signature!==placementSignature){const cats=[...new Set(targets.map(r=>r.dataset.category))];setPick(categoryInput,cats.length===1?cats[0]:'');placementSignature=signature;}
 categoryInput.disabled=!changeCategory.checked;document.getElementById('bulk-category-new').disabled=!changeCategory.checked;folderInput.disabled=keepFolders.checked;
}
function safePart(value){return value.length>0&&[...value].length<=120&&value===value.normalize('NFC')&&!/[<>:"/\\|?*\u0000-\u001f\u007f]/.test(value)&&!/[. ]$/.test(value)&&value.trim()===value&&!/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³]|CONIN\$|CONOUT\$)(\.|$)/i.test(value)&&value!=='.'&&value!=='..'}
function validatePlacement(type,category,folder){
 if(category===''&&!placementRoles[type]){if(folder&&!folder.split('/').every(safePart))throw new Error('Enter safe subfolder names without reserved characters or leading/trailing slashes.');return;} // Patch 4k: no category yet (Review selection asks for one)
 if(!safePart(category))throw new Error('Enter a safe category folder name—not a full path.');
 if(folder&&!folder.split('/').every(safePart))throw new Error('Enter safe subfolder names without reserved characters or leading/trailing slashes.');
 const role=placementRoles[type];
 if(role){if(category!==role)throw new Error(type+' must stay under '+role+'. Change its '+(type==='Books'?'subcategory':type==='Music'?'album folder':'audiobook folder')+' instead.');if((!folder&&type!=='Books')||folder.includes('/'))throw new Error(type+' needs one '+(type==='Books'?'subcategory':type==='Music'?'album':'audiobook')+' folder name.');}
 else if(Object.values(placementRoles).includes(category))throw new Error('This selection includes videos. Choose a general category, not '+category+'. For mixed collections, use Selected materials to change one content type at a time.');
}
function effFolder(type,category,folder){return folder||(type==='Books'?'(choose a shelf)':placementRoles[type]?'':category);} // Patch 4p
function folderAuto(row){return !row.dataset.folder||row.dataset.placementOverride!=='1';}
function folderNote(row){const t=row.dataset.type,c=row.dataset.category,f=row.dataset.folder,custom=row.dataset.placementOverride==='1';
 if(placementRoles[t]){if(!f)return t==='Books'?'Choose a shelf for this book. Books are never put on a shelf by folder name.':'A folder is required.';if(t==='Books')return 'Shelf: '+f+'.'; // Patch 4p
return (custom?'Custom':'Automatic')+' '+(t==='Books'?'subcategory':'folder')+': '+f+'.';}
 if(!c)return f?'Subfolder: '+f+'. Choose a category first.':'Automatic subfolder: the same name as the category you choose.';
 if(!f)return 'Automatic subfolder: '+c+' (same name as the category). The file goes to '+c+' › '+c+'.';
 if(!custom)return 'Automatic subfolder: '+f+'.';
 return 'Custom subfolder you typed: '+f+'. Clear the box, or choose Use automatic subfolder, to go back to the automatic subfolder: '+c+'.';}
function syncLocation(row){
 syncPick(row);row.querySelector('.placement-label').textContent='Edutek › videos › '+[row.dataset.category||'(choose a category)',effFolder(row.dataset.type,row.dataset.category,row.dataset.folder)].filter(Boolean).join(' › ').replaceAll('/',' › ');
 row.querySelector('.custom-placement').textContent=row.dataset.placementOverride==='1'?'(custom location)':'';
 get(row,'category').value=row.dataset.category;const fb=get(row,'folder');if(fb.value!==row.dataset.folder)fb.value=row.dataset.folder;const fa=folderAuto(row);fb.classList.toggle('auto-folder',fa);fb.placeholder=placementRoles[row.dataset.type]?'':row.dataset.category+' (automatic: same name as the category)';const ft=row.querySelector('.folder-tag');if(ft){ft.textContent=fa?'auto':'custom';ft.className='folder-tag '+(fa?'is-auto':'is-custom');}const fn=row.querySelector('.folder-note');if(fn){const n=folderNote(row);if(fn.textContent!==n)fn.textContent=n;}
 row.dataset.search=(get(row,'title').value+' '+row.dataset.group+' '+row.dataset.category+' '+row.dataset.folder).toLowerCase();
}
const dialog=document.getElementById('placement-dialog');
document.getElementById('preview-placement').onclick=()=>{
 try{
  const all=placementScope();if(!all.length)throw new Error(scopeSelect.value==='selected'?'Select materials first.':'Choose a source collection on the left first.');
  const skipped=replaceCustom.checked?[]:all.filter(r=>r.dataset.placementOverride==='1');
  const eligible=all.filter(r=>!skipped.includes(r));placementPending=[];
  if(changeCategory.checked&&(categoryInput.value===''||categoryInput.value==='__new__'))throw new Error(categoryInput.value==='__new__'?'Type the new category name and choose Use this name first.':'Choose a category in the list first (or New category… to type a new one).');
  for(const row of eligible){const category=changeCategory.checked?categoryInput.value:row.dataset.category,folder=keepFolders.checked?row.dataset.folder:folderInput.value;validatePlacement(row.dataset.type,category,folder);if(category!==row.dataset.category||folder!==row.dataset.folder)placementPending.push({row,category,folder});}
  if(!placementPending.length)throw new Error('No locations would change.'+(skipped.length?' '+skipped.length+' custom locations were preserved.':''));
  document.getElementById('placement-summary').textContent=placementPending.length+' materials will change placement, including '+placementPending.filter(p=>p.row.hidden).length+' outside the current page. '+skipped.length+' custom locations will be preserved. Attached artwork follows automatically. Changes are not saved until Save progress or Review selection.';
  const body=document.getElementById('placement-changes');body.replaceChildren();for(const p of placementPending){const tr=document.createElement('tr');for(const value of [get(p.row,'title').value,[p.row.dataset.category,effFolder(p.row.dataset.type,p.row.dataset.category,p.row.dataset.folder)].filter(Boolean).join(' › '),[p.category,effFolder(p.row.dataset.type,p.category,p.folder)].filter(Boolean).join(' › ')]){const td=document.createElement('td');td.textContent=value;td.style.cssText='padding:9px;border-top:1px solid #ddd;overflow-wrap:anywhere';tr.append(td);}body.append(tr);}
  dialog.showModal();
 }catch(error){alert(error.message);}
};
document.getElementById('cancel-placement').onclick=()=>dialog.close();
document.getElementById('apply-placement').onclick=()=>{
 for(const p of placementPending){p.row.dataset.category=p.category;p.row.dataset.folder=p.folder;p.row.dataset.placementOverride=scopeSelect.value==='selected'?'1':'0';if(p.row.dataset.placementOverride==='0')p.row.dataset.autoCategory=p.category;syncLocation(p.row);}
 dialog.close();changed();render();refreshPlacementScope();refreshNew();
};
// Patch 4k: the category list is every library folder (template #category-options), filled into each row's list when it is opened, plus New category….
let libCatKeys=null;function catTpl(){return document.getElementById('category-options');}
function isLibCat(v){if(!libCatKeys)libCatKeys=new Set([...catTpl().content.querySelectorAll('option')].map(o=>o.value.toLowerCase()));return libCatKeys.has(String(v).toLowerCase());}
// Patch 4l: new categories belong to the whole import: every Category list shows them first ("New in this import", marked (new)).
const batchNewCats=<?=json_encode(array_values(is_array($newCats??null)?$newCats:[]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>,sessionNewCats=new Map();
function catKey(v){return String(v).normalize('NFC').toLowerCase();}
function newCatList(){const m=new Map(),add=v=>{if(v&&!isLibCat(v)&&!m.has(catKey(v)))m.set(catKey(v),v);};batchNewCats.forEach(add);sessionNewCats.forEach(add);items.forEach(r=>{if(!placementRoles[r.dataset.type])add(r.dataset.category);});return [...m.values()].sort((a,b)=>a.localeCompare(b,undefined,{numeric:true,sensitivity:'base'}));}
function newCatUse(){const n=new Map();items.forEach(r=>{const c=r.dataset.category;if(c&&!placementRoles[r.dataset.type]&&!isLibCat(c)&&get(r,'include').checked)n.set(catKey(c),(n.get(catKey(c))||0)+1);});return n;}
function newGroup(sel,list){if(!sel||sel.dataset.fixed==='1')return;list=list||newCatList();let g=sel.querySelector('optgroup[data-new]');
 [...sel.children].filter(o=>o.tagName==='OPTION'&&o.value!==''&&o.value!=='__new__'&&!isLibCat(o.value)&&list.includes(o.value)).forEach(o=>o.remove());
 if(!list.length){if(g)g.remove();return;}
 if(!g){g=document.createElement('optgroup');g.dataset.new='1';g.label='New in this import (created when you publish)';const first=[...sel.children].find(o=>o.tagName==='OPTION'&&o.value==='');sel.insertBefore(g,first?first.nextSibling:sel.firstChild);}
 if([...g.children].map(o=>o.value).join('\n')!==list.join('\n'))g.replaceChildren(...list.map(v=>new Option(v+' (new)',v)));}
function setPick(sel,v){if(!sel)return;if(v==='__new__'||v===''){sel.value=v;return;}if(sel.dataset.filled==='1'&&!isLibCat(v))newGroup(sel);if(![...sel.options].some(o=>o.value===v)){const o=new Option(v+(isLibCat(v)?'':' (new)'),v);sel.insertBefore(o,[...sel.children].find(x=>x.value==='__new__')||null);}sel.value=v;}
function fillPick(sel){if(!sel||sel.dataset.filled==='1'||sel.dataset.fixed==='1')return;const cur=sel.value;const lib=document.createElement('optgroup');lib.dataset.lib='1';lib.label='Library categories ('+catTpl().content.children.length+')';lib.append(...[...catTpl().content.children].map(o=>o.cloneNode(true)));sel.replaceChildren(new Option('Choose a category…',''),lib,new Option('New category…','__new__'));sel.dataset.filled='1';newGroup(sel);setPick(sel,cur);}
function refreshNew(){const list=newCatList();document.querySelectorAll('select[data-role="category-pick"][data-filled="1"],#bulk-category,#setcat-pick').forEach(sel=>{const cur=sel.value;newGroup(sel,list);if([...sel.options].some(o=>o.value===cur))sel.value=cur;else if(cur!==''&&cur!=='__new__')setPick(sel,cur);});
 const s=document.getElementById('newcat-summary');if(s){const u=newCatUse();s.textContent=list.length?'New categories in this import: '+list.map(c=>{const n=u.get(catKey(c))||0;return c+' ('+n+' selected item'+(n===1?'':'s')+')';}).join(', ')+'. A new category with no selected items is left out of the final check.':'No new categories in this import yet.';}
 if(typeof setcatLabel==='function')setcatLabel();}
async function proposeCat(name){const fd=new FormData();fd.append('csrf',document.querySelector('#editor [name=csrf]').value);fd.append('id',document.querySelector('#editor [name=id]').value);fd.append('action','propose_category');fd.append('name',name);
 let r;try{r=await fetch('/bulk-import-guided.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});}catch(e){return checkCat(name);}
 if(r.status===403)throw new Error('Session expired. Reload the page (your saved work is kept).');let j=null;try{j=await r.json();}catch(e){j=null;}if(!j||typeof j.ok!=='boolean')return checkCat(name);
 if(Array.isArray(j.new_categories))j.new_categories.forEach(c=>{if(!batchNewCats.some(x=>catKey(x)===catKey(c)))batchNewCats.push(c);});return j;}
function syncPick(row){const p=get(row,'category-pick'),c=row.dataset.category,role=!!placementRoles[row.dataset.type];if(p&&p.value!=='__new__')setPick(p,c);
 const nb=row.querySelector('.newcat-badge');if(nb)nb.hidden=!(c!==''&&!role&&!isLibCat(c));const w=row.querySelector('.cat-needed');if(w)w.hidden=c!==''||role;}
async function checkCat(name){const r=await fetch('?catcheck='+encodeURIComponent(name)+'&token='+encodeURIComponent(document.querySelector('#editor [name=csrf]').value),{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error('Could not check the name (HTTP '+r.status+').');return r.json();}
function wireCatPick(sel,wrap,msg,apply){
 if(!sel||!wrap)return;const box=wrap.querySelector('[data-role="category-new"]'),use=wrap.querySelector('.use-new-cat'),sug=wrap.querySelector('.use-suggested');
 sel.addEventListener('focus',()=>fillPick(sel));sel.addEventListener('mousedown',()=>fillPick(sel));
 sel.addEventListener('change',()=>{if(sel.value==='__new__'){wrap.hidden=false;msg.className='muted category-msg';msg.textContent='New category: press Tab, type the name, then press Enter (or choose Use this name). The folder is created only when you publish.';return;}wrap.hidden=true;apply(sel.value);});
 const go=async()=>{try{const res=await proposeCat(box.value);if(res.ok&&!res.exists){const same=newCatList().find(c=>catKey(c)===catKey(res.name));if(same&&same!==res.name){res.name=same;res.message='“'+same+'” is already a new category in this import, so it was chosen.';}sessionNewCats.set(catKey(res.name),res.name);if(res.saved===false)res.message+=' It is kept on this page; Save progress keeps it with the import.';}msg.textContent=res.message;msg.className='category-msg '+(res.ok?'notice':'warning');sug.hidden=!res.suggest;sug.dataset.name=res.suggest||'';if(res.suggest)sug.textContent='Use suggested name: '+res.suggest;
  if(res.ok){refreshNew();setPick(sel,res.name);wrap.hidden=true;box.value='';apply(res.name);refreshNew();sel.focus();}else box.focus();}catch(e){msg.textContent=e.message;msg.className='category-msg warning';}};
 use.addEventListener('click',go);box.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();go();}});
 sug.addEventListener('click',()=>{box.value=sug.dataset.name||'';sug.hidden=true;go();});
}
items.forEach(row=>{const sel=get(row,'category-pick');if(!sel)return;const d=row.querySelector('details.individual-placement');
 wireCatPick(sel,row.querySelector('.new-cat'),row.querySelector('.category-msg'),v=>{const hb=get(row,'category');hb.value=v;hb.dispatchEvent(new Event('input'));});
 if(d){if(d.open)fillPick(sel);d.addEventListener('toggle',()=>{if(d.open)fillPick(sel);});}});
wireCatPick(categoryInput,document.getElementById('bulk-new-wrap'),document.getElementById('bulk-category-msg'),()=>{});
items.forEach(row=>{for(const role of ['category','folder'])get(row,role).addEventListener('input',()=>{row.dataset.category=get(row,'category').value;row.dataset.folder=get(row,'folder').value;row.dataset.placementOverride=(row.dataset.folder!==''||row.dataset.category!==row.dataset.autoCategory)?'1':'0';syncLocation(row);changed();refreshPlacementScope();refreshNew();});const ua=row.querySelector('.use-auto');if(ua)ua.onclick=()=>{const fb=get(row,'folder');fb.value='';fb.dispatchEvent(new Event('input'));fb.focus();};});
scopeSelect.addEventListener('change',()=>{placementSignature='';refreshPlacementScope();});for(const control of [changeCategory,keepFolders,replaceCustom])control.addEventListener('change',refreshPlacementScope);
editor.addEventListener('submit',event=>{try{for(const row of items)validatePlacement(row.dataset.type,row.dataset.category,row.dataset.folder);}catch(error){event.preventDefault();event.stopImmediatePropagation();alert(error.message+' Your edits are still on this page.');}},true);
document.addEventListener('edutek-preview-view',refreshPlacementScope);refreshPlacementScope();
// Patch 4l: Set category for several items (selected, or all items in the source folder shown), and per-item "use for selected / this folder".
function setCategoryFor(rows,cat){const use=rows.filter(r=>!placementRoles[r.dataset.type]);for(const row of use)validatePlacement(row.dataset.type,cat,row.dataset.folder);
 for(const row of use){row.dataset.category=cat;get(row,'category').value=cat;row.dataset.placementOverride=(row.dataset.folder!==''||cat!==row.dataset.autoCategory)?'1':'0';syncLocation(row);}
 if(use.length){changed();render();refreshPlacementScope();refreshNew();}return [use.length,rows.length-use.length,use.filter(r=>r.hidden).length];}
function setCatReport(cat,[done,skipped,off],what){return 'Category “'+cat+'”'+(isLibCat(cat)?'':' (new)')+' set on '+done+' item'+(done===1?'':'s')+' '+what+(off?' ('+off+' of them not shown on this page)':'')+(skipped?'; '+skipped+' book, music or audiobook item'+(skipped===1?'':'s')+' kept in their own category':'')+'. Subfolders follow the usual rule. Save progress to keep it.';}
const scScope=document.getElementById('setcat-scope'),scPick=document.getElementById('setcat-pick'),scApply=document.getElementById('setcat-apply'),scMsg=document.getElementById('setcat-msg');
function setcatRows(){if(!scScope)return [];if(scScope.value==='folder')return view.group==='all'?[]:items.filter(r=>r.dataset.group===view.group);return items.filter(r=>get(r,'include').checked);}
function setcatLabel(){if(!scApply||!scScope)return;const n=setcatRows().length;scApply.textContent=scScope.value==='folder'?(view.group==='all'?'Set category for all items in this folder (choose a folder on the left first)':'Set category for all '+n+' item'+(n===1?'':'s')+' in this folder'):'Set category for selected items ('+n+')';}
if(scApply&&scPick&&scScope){
 wireCatPick(scPick,document.getElementById('setcat-new-wrap'),scMsg,()=>setcatLabel());
 scScope.addEventListener('change',setcatLabel);document.addEventListener('edutek-preview-view',refreshNew);document.addEventListener('change',e=>{if(e.target&&e.target.matches&&e.target.matches('[data-role=include]'))refreshNew();});
 scApply.addEventListener('click',()=>{const cat=scPick.value;if(cat===''||cat==='__new__'){scMsg.className='category-msg warning';scMsg.textContent=cat==='__new__'?'Type the new category name and press Enter (or Use this name) first.':'Choose a category in the list first.';scPick.focus();return;}
  const rows=setcatRows();if(!rows.length){scMsg.className='category-msg warning';scMsg.textContent=scScope.value==='folder'?'Choose a source folder on the left first, or switch Apply to Selected items.':'No items are selected. Tick “Add this video” on the items first.';return;}
  try{scMsg.textContent=setCatReport(cat,setCategoryFor(rows,cat),scScope.value==='folder'?'in this folder':'(selected)');scMsg.className='category-msg notice';}catch(e){scMsg.className='category-msg warning';scMsg.textContent=e.message;}});
 setcatLabel();}
items.forEach(row=>{const a=row.querySelector('.cat-to-selected'),b=row.querySelector('.cat-to-folder'),msg=row.querySelector('.category-msg');if(!msg)return;
 const run=(rows,what)=>{const cat=row.dataset.category;if(!cat){msg.className='category-msg warning';msg.textContent='Choose a category for this item first.';return;}if(!rows.length){msg.className='category-msg warning';msg.textContent='No items are selected. Tick “Add this video” on the items first.';return;}
  try{msg.textContent=setCatReport(cat,setCategoryFor(rows,cat),what);msg.className='category-msg notice';}catch(e){msg.className='category-msg warning';msg.textContent=e.message;}};
 if(a)a.addEventListener('click',()=>run(items.filter(r=>get(r,'include').checked),'(selected)'));if(b)b.addEventListener('click',()=>run(items.filter(r=>r.dataset.group===row.dataset.group),'in this folder'));});
editor.addEventListener('submit',()=>{const f=document.getElementById('newcats-json');if(f)f.value=JSON.stringify(newCatList());});
refreshNew();


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
const execute=document.getElementById('execute-form');if(execute)execute.onsubmit=()=>{if(dirty){alert('Use Review selection again after making changes.');return false}if(!window.EDU4O_OK&&!confirm('Copy the materials in the final summary to staging now? Originals stay unchanged. The Edutek library is not changed by this step.'))return false;submitting=true;startProgress();document.getElementById('add-button').textContent='Staging… please wait';return true;};
window.EdutekClassic={items,get,changed,render,setCategoryFor,newCatList,isLibCat,catKey,proposeCat,sessionNewCats,refreshNew,acceptFix,isNameIssue,placementRoles,validatePlacement,syncLocation,isDirty:()=>dirty}; // Patch 4o
window.addEventListener('beforeunload',e=>{if(dirty&&!submitting){e.preventDefault();e.returnValue=''}});render();
})();
const reverse=document.getElementById('reverse-form');if(reverse)reverse.onsubmit=()=>confirm('Check and reverse this entire import, including its artwork? Only unchanged verified staged copies will move to recovery. Files already published to the Edutek library are not affected.');

(()=>{
 const batchId=<?=json_encode($id,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,token=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const active=new Set(),done=new Set(),last=new Map();let busy=false;
 function candidates(){return [...document.querySelectorAll('.generated-preview[data-preview-id]')].filter(el=>!done.has(el.dataset.previewId)&&!active.has(el.dataset.previewId)&&(window.EDU4O_ON||!el.closest('.item')?.hidden)).sort((a,b)=>(last.get(a.dataset.previewId)||0)-(last.get(b.dataset.previewId)||0))}
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

</script>
<?php /* Patch 4o: data for the keyboard-first screens (read-only summary of this page's own state; never secrets). If anything here fails, the classic page still works. */
try{
 $u4=['v'=>1,'id'=>$batch?$id:'','state'=>(string)($batch['state']??''),'screen'=>(string)($batch['screen']??'select'),'editable'=>(bool)$editable,'source'=>(string)($batch['source']??''),'srcPicked'=>(bool)$srcPicked,'srcShown'=>(string)$srcShown,'pubView'=>(bool)$pubView,'error'=>(string)$error,'approval'=>(string)($batch['approval']??''),'issues'=>[],'items'=>[],'pq'=>null,'past'=>[],'history'=>[],'libCount'=>count($libCats??[])];
 if($batch){
  foreach(($batch['issues']??[]) as $is){if(is_array($is))$u4['issues'][]=['id'=>(string)($is['id']??''),'kind'=>(string)($is['kind']??''),'message'=>(string)($is['message']??$is['why']??'')];}
  $mc4=0;$n4=0;$au4=0;$dm4=0;foreach(learning($batch['rows']) as $rid4=>$r4){if(!empty($r4['damaged']))$dm4++;if(empty($r4['include']))continue;$n4++;if(function_exists('au_is_audio')&&au_is_audio($r4)&&(($r4['media']['action']??'')==='mp3'))$au4++;$m4=mc_effective($r4);if($m4&&in_array($m4['action']??'',['convert','audio','faststart'],true))$mc4++;if(count($u4['items'])<400)$u4['items'][]=['t'=>(string)$r4['title'],'ty'=>(string)$r4['type'],'to'=>str_replace('/',' › ',dirname((string)$r4['target'])),'rep'=>!empty($r4['replace'])];}
  $u4['selected']=$n4;$u4['convert']=$mc4;$u4['aconv']=$au4;$u4['damaged']=$dm4;$u4['auSkip']=[];foreach(array_slice((array)($batch['audio_skipped']??[]),0,50) as $s4)if(is_array($s4))$u4['auSkip'][]=(string)($s4['title']??'').' ('.basename((string)($s4['source']??'')).'): '.(string)($s4['why']??'');$u4['jobs']=count((array)($batch['media_jobs']??[]));
  if(!$editable){$pi4=pq_info($id);$st4=is_array($pi4['status']??null)?$pi4['status']:[];$un4=is_array($pi4['undo']??null)?$pi4['undo']:[];$pl4=is_array($un4['plan']??null)?$un4['plan']:[];$c4=is_array($pl4['counts']??null)?$pl4['counts']:[];
   [$l4,$f4,$b4,$rp4]=pq_counts($batch);
   $u4['pq']=['state'=>(string)$pi4['state'],'requested'=>(bool)$pi4['requested'],'canRequest'=>pq_can_request($pi4),'message'=>mb_substr((string)($st4['message']??''),0,600),'learning'=>(int)($st4['learning']??0),'replaced'=>(int)($st4['replaced_learning']??0),'finished'=>ux_when((string)($st4['finished']??'')),'staged'=>$l4,'stagedReplace'=>$rp4,'watcherOk'=>!($pi4['watcher_age']===null||$pi4['watcher_age']>60),
    'undo'=>(string)($un4['state']??''),'undoReq'=>(bool)$pi4['undo_req'],'planReq'=>(bool)$pi4['undo_plan_req'],'planOk'=>uq_plan_ok($pi4['undo']??null),'counts'=>['move'=>(int)($c4['move']??0),'restore'=>(int)($c4['restore']??0)],'undoMessage'=>mb_substr((string)($un4['message']??''),0,600)];}
 }
 if(!$batch){
  foreach(glob(PS_DIR.'/*.json')?:[] as $f4){if(!preg_match('/\/([a-f0-9]{32})\.json$/D',$f4,$m4))continue;$st4=pq_read($f4);if(!$st4)continue;$s4=ux_state(['id'=>$m4[1],'state'=>'complete']);if(!in_array($s4,['Published','Undone','Undo in progress','Publish failed','Publish needs attention'],true))continue;
   $u4['past'][]=['id'=>$m4[1],'s'=>$s4,'when'=>ux_when((string)($st4['finished']??$st4['started']??'')),'n'=>(int)($st4['learning']??0),'rp'=>(int)($st4['replaced_learning']??0),'has'=>is_file(batchdir($m4[1]).'/batch.json')];}
  usort($u4['past'],fn($a,$b)=>strcmp($b['when'],$a['when']));
  foreach(array_slice($history,0,200) as $e4){$i4=(string)($e4['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$i4))continue;$n4=0;foreach(($e4['rows']??[]) as $r4)if(!empty($r4['include'])&&empty($r4['support']))$n4++;
   $u4['history'][]=['id'=>$i4,'s'=>ux_state($e4),'when'=>ux_when((string)($e4['created']??'')),'iso'=>(string)($e4['created']??''),'n'=>$n4,'total'=>count(array_filter((array)($e4['rows']??[]),fn($x)=>empty($x['support']))),'src'=>(string)($e4['source']??'')];}
 }
 try{ /* Patch 4p: shelves, left-out files, new places and the import summary (read-only) */
  $p4=['v'=>1,'shelves'=>p4_shelves(),'left'=>[],'leftN'=>0,'loose'=>[],'books'=>0,'newPlaces'=>null,'done'=>null];
  if($batch){
   $p4['left']=array_slice(p4_left_list($batch),0,300);$p4['leftN']=count(p4_left_list($batch))+max(0,(int)($batch['left_out_n']??0)-count((array)($batch['left_out']??[])));
   $lo4=[];foreach(learning($batch['rows']) as $r4){if(($r4['type']??'')==='Books')$p4['books']++;if(($r4['type']??'')==='Music'&&!str_contains((string)$r4['source'],'/')){$k4=(string)$r4['placement_folder'];$lo4[$k4]=($lo4[$k4]??0)+1;}}
   foreach($lo4 as $k4=>$n4)$p4['loose'][]=['to'=>gp_role('Music').' › '.$k4,'n'=>$n4];
   $p4['newPlaces']=p4_new_places($batch);
   if(!$editable&&is_array($u4['pq']??null)&&($u4['pq']['state']??'')==='published'){$pi4=$pi4??pq_info($id);$st4=is_array($pi4['status']??null)?$pi4['status']:[];
    $p4['done']=p4_summary($batch,$st4,p4_links(false));}
  }
  $u4['p4']=$p4;
 }catch(Throwable $e5){$u4['p4']=['v'=>0,'err'=>'4p data: '.$e5->getMessage()];}
}catch(Throwable $e4){$u4=['v'=>0,'err'=>'4o data: '.$e4->getMessage()];}
?><script type="application/json" id="ui4o-data"><?=json_encode($u4,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<script>
/* Patch 4o: keyboard-first screens on top of the classic page. Uses only the classic page's own forms and
   functions (window.EdutekClassic), so every server check, save/resume, publish and undo rule is unchanged.
   If this script fails, the classic page below still works. */
(()=>{"use strict";
let D;try{D=JSON.parse(document.getElementById('ui4o-data').textContent||'{}');}catch(e){return;}
if(!D||D.v!==1)return;
window.EDU4O_ON=true;
const C=window.EdutekClassic||null,ID=D.id||'',LS=(k,v)=>{try{if(v===undefined)return localStorage.getItem(k);if(v===null)localStorage.removeItem(k);else localStorage.setItem(k,v);}catch(e){return null;}};
const SS=(k,v)=>{try{if(v===undefined)return sessionStorage.getItem(k);if(v===null)sessionStorage.removeItem(k);else sessionStorage.setItem(k,v);}catch(e){return null;}};
const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
function el(t,a,...kids){const e=document.createElement(t);for(const[k,v]of Object.entries(a||{})){if(v===false||v==null)continue;if(k.startsWith('on')&&typeof v==='function')e.addEventListener(k.slice(2),v);else if(k==='class')e.className=v;else e.setAttribute(k,v===true?'':String(v));}
 for(const k of kids.flat(9)){if(k==null||k===false)continue;e.append(k.nodeType?k:document.createTextNode(String(k)));}return e;}
const kbd=k=>el('kbd',{},k),plural=(n,a,b)=>n+' '+(n===1?a:(b||a+'s'));
const typeWord=(t,n)=>({Videos:['video','videos'],Books:['book','books'],Music:['music file','music files'],Audiobooks:['audiobook','audiobooks']}[t]||['item','items'])[n===1?0:1];
document.body.classList.add('ui4o');
/* ---------- skeleton ---------- */
const main=$('main'),header=$('body>header');
const root=el('div',{id:'ui4o',class:'u4'});main.prepend(root);
const classic=el('details',{id:'classic4o',class:'u4classic'},el('summary',{},'Details: the full page with every option and all technical information'));
const layout=$('main>.layout'),foot=$('#pub-foot'),err=$('main>p.error');
if(layout){$$('dialog',layout).forEach(d=>document.body.append(d));$$('[autofocus]',layout).forEach(x=>x.removeAttribute('autofocus'));classic.append(layout);}if(foot)classic.append(foot);
main.append(classic);
const hintsBar=el('div',{class:'u4 hints4o',id:'hints4o','aria-label':'Keyboard shortcuts'});document.body.append(hintsBar);
const modal=el('div',{class:'u4',id:'modal4o'});document.body.append(modal);
const toastEl=el('div',{class:'u4 toast4o',role:'status','aria-live':'polite'});document.body.append(toastEl);
let toastT=0;function toast(m){toastEl.textContent=m;toastEl.style.display='block';clearTimeout(toastT);toastT=setTimeout(()=>toastEl.style.display='none',3200);}
const pastView=D.pubView&&!ID;
if(header){const tabs=el('div',{class:'u4 tabs4o',role:'tablist','aria-label':'Pages'},
  el('button',{role:'tab','aria-selected':String(!pastView),id:'tab4o-current',onclick:()=>goCurrent()},'Current import'),
  el('button',{role:'tab','aria-selected':String(pastView),id:'tab4o-past',onclick:()=>goPast()},'Past imports & undo ',kbd('H')));header.append(tabs);}
function goPast(){if(!pastView){SS('edutek-4o-back',location.pathname+location.search);location.href='?view=published';}}
function goCurrent(){if(pastView){const b=SS('edutek-4o-back');location.href=b&&b.indexOf('view=published')<0?b:'/bulk-import-guided.php';}}
/* ---------- dialogs ---------- */
function openDialog(label,build,onEsc){const prev=document.activeElement;const d=el('div',{class:'dialog4o',role:'dialog','aria-modal':'true','aria-label':label});
 const ov=el('div',{class:'overlay4o'},d);const close=(refocus=true)=>{modal.replaceChildren();if(refocus){const f=prev&&prev.isConnected&&prev!==document.body?prev:$('h2[tabindex]',root);if(f)f.focus();}};
 ov.addEventListener('keydown',e=>{e.stopPropagation();if(e.key==='Escape'){e.preventDefault();close();onEsc&&onEsc();}
  if(e.key==='Tab'){const f=$$('button:not([disabled]),input,select,[tabindex="0"]',d).filter(x=>!x.closest('[hidden]'));if(!f.length)return;const i=f.indexOf(document.activeElement);if(e.shiftKey&&i<=0){f[f.length-1].focus();e.preventDefault();}else if(!e.shiftKey&&i===f.length-1){f[0].focus();e.preventDefault();}}});
 build(d,close);modal.replaceChildren(ov);const af=$('[data-autofocus]',d)||$('button',d);if(af)af.focus();return close;}
function yesNo(title,lines,yesLabel,noLabel,onYes,onNo){openDialog(title,(d,close)=>{
 d.append(el('h2',{},title),...lines.map(t=>el('p',{},t)),el('div',{class:'nav4o'},
  el('button',{class:'primary big','data-k':'yes',onclick:()=>{close(false);onYes();}},yesLabel||'Yes'),
  el('button',{class:'big','data-k':'no','data-autofocus':'1',onclick:()=>{close();onNo&&onNo();}},(noLabel||'No')+' ',kbd('Esc'))));
 d.addEventListener('keydown',e=>{const k=e.key.toLowerCase();if(k==='y'){e.preventDefault();$('[data-k=yes]',d).click();}else if(k==='n'){e.preventDefault();$('[data-k=no]',d).click();}});},()=>{onNo&&onNo();});}
function help(){const rows=[['Tab / Shift+Tab','next / previous button'],['Enter','press the button, choose, save'],['Space','select an item (to move several at once)'],['\u2191 \u2193 Home End','move in a list'],['Esc','cancel / close / clear the selection'],['B','Browse for a folder (first step) / go back one step'],['N','next step'],['A','select all / none'],['M','move the selected items (or this item) to a category; for books: choose a shelf'],['C','new category (for books: new shelf)'],['Y','yes, in a question (No is the safe choice)'],['R','rename this item'],['X','include or skip this item'],['D','details and choices for this item'],['S','save and finish later'],['U','undo (after publishing)'],['N / C / B / L','after publishing: new import / category links / PDF books / left out'],['H','past imports & undo'],['?','this list']];
 openDialog('Keyboard shortcuts',(d,close)=>{d.append(el('h2',{},'Keyboard shortcuts'),el('table',{},rows.map(([k,t])=>el('tr',{},el('td',{style:'padding:4px 16px 4px 0'},kbd(k)),el('td',{},t)))),
  el('div',{class:'nav4o'},el('span'),el('button',{class:'primary','data-autofocus':'1',onclick:()=>close()},'Close ',kbd('Esc'))));});}
/* ---------- categories ---------- */
const libCats=(()=>{const t=$('template#category-options');return t?[...t.content.querySelectorAll('option')].map(o=>o.value).filter(Boolean):[];})();
function lw(x){const r=String(x.iso||'');if(/(Z|[+-]\d\d:?\d\d)$/.test(r)){const d=new Date(r);if(!isNaN(d)){const p=n=>String(n).padStart(2,'0');return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}}return x.when||'';}
function allCats(){const n=C?C.newCatList():[];return [...n,...libCats.filter(c=>!n.some(x=>x.toLowerCase()===c.toLowerCase()))];}
const isNew=c=>C&&c&&!C.isLibCat(c);
const catLabel=c=>c+(isNew(c)?' (new)':'');
function openCatPicker(title,current,onchoose,createFirst){let idx=0,list=[];
 openDialog(title,(d,close)=>{
  const inp=el('input',{type:'text','data-autofocus':'1','aria-label':'Type to find a category, or type a new name',placeholder:'Type to find a category, or a new name\u2026',maxlength:'120',autocomplete:'off',style:'width:100%'});
  const ul=el('ul',{class:'pick4o catlist4o',role:'listbox','aria-label':'Categories'}),count=el('p',{class:'muted',role:'status','aria-live':'polite'}),msg=el('p',{class:'warn4o',role:'alert',hidden:true});
  const build=()=>{const raw=inp.value.trim().replace(/\s+/g,' '),q=raw.toLowerCase();const cats=allCats();
   list=cats.filter(c=>!q||c.toLowerCase().includes(q)).map(c=>({c,label:catLabel(c)}));
   if(q&&!cats.some(c=>c.toLowerCase()===q))list[createFirst?'unshift':'push']({create:raw,label:'+ New category \u201C'+raw+'\u201D'});
   if(idx>=list.length)idx=Math.max(0,list.length-1);
   ul.replaceChildren(...list.map((o,i)=>el('li',{role:'option','aria-selected':String(i===idx),class:o.create?'create':'',onclick:()=>{idx=i;pick();}},o.label)));
   const s=ul.children[idx];if(s)s.scrollIntoView({block:'nearest'});
   const nm=list.filter(o=>!o.create).length;count.textContent=(createFirst&&!q?'Type the name of the new category. ':'')+plural(nm,'category','categories')+(q?' match \u201C'+raw+'\u201D':'')+'.';};
  const pick=async()=>{const o=list[idx];if(!o)return;if(!o.create){close(false);onchoose(o.c);return;}
   if(!C){msg.hidden=false;msg.textContent='New categories need the full page (Details).';return;}
   msg.hidden=false;msg.className='muted';msg.textContent='Checking the name\u2026';
   try{const res=await C.proposeCat(o.create);
    if(res.ok){let name=res.name;if(!res.exists){const same=C.newCatList().find(c=>C.catKey(c)===C.catKey(name));if(same)name=same;C.sessionNewCats.set(C.catKey(name),name);}C.refreshNew();close(false);onchoose(name);toast(res.exists?'\u201C'+name+'\u201D is already a library category, so it was chosen.':'New category \u201C'+name+'\u201D. The folder is created only when you publish.');return;}
    msg.className='warn4o';msg.textContent=res.message||'That name cannot be used.';
    if(res.suggest){inp.value=res.suggest;idx=0;build();msg.textContent+=' Press Enter to use \u201C'+res.suggest+'\u201D instead.';}inp.focus();}
   catch(e){msg.className='warn4o';msg.textContent=e.message;}};
  inp.addEventListener('input',()=>{idx=0;msg.hidden=true;build();});
  inp.addEventListener('keydown',e=>{const n=list.length;if(e.key==='ArrowDown'){idx=Math.min(idx+1,n-1);build();e.preventDefault();}else if(e.key==='ArrowUp'){idx=Math.max(idx-1,0);build();e.preventDefault();}
   else if(e.key==='PageDown'){idx=Math.min(idx+8,n-1);build();e.preventDefault();}else if(e.key==='PageUp'){idx=Math.max(idx-8,0);build();e.preventDefault();}
   else if(e.key==='Enter'){e.preventDefault();pick();}else if(e.key==='Tab'){e.preventDefault();}});
  d.append(el('h2',{},title),el('p',{class:'muted'},'Type a few letters to find one of the '+libCats.length+' library categories, or type a new name to create one. ',kbd('\u2191'),' ',kbd('\u2193'),' move \u00B7 ',kbd('Enter'),' choose \u00B7 ',kbd('Esc'),' cancel'),inp,count,msg,ul);
  if(current&&!createFirst){const i=allCats().indexOf(current);if(i>=0)idx=i;}build();});}
/* ---------- items (classic <article class="item">) ---------- */
const S={cur:0,sel:new Set(),open:new Set(),renaming:null,step:0};
const items=C?C.items:[];const byId=new Map(items.map(r=>[r.dataset.id,r]));
const manualKey='edutek-4o-manual-'+ID;let manual=new Set();try{manual=new Set(JSON.parse(LS(manualKey)||'[]'));}catch(e){}
const saveManual=()=>LS(manualKey,JSON.stringify([...manual]));
const inc=r=>C.get(r,'include').checked,role=r=>!!(C&&C.placementRoles[r.dataset.type]);
const title=r=>(C.get(r,'title')?.value||$('.display-title',r)?.textContent||'').trim();
const included=()=>items.filter(inc),needCat=()=>included().filter(r=>!role(r)&&!r.dataset.category);
const issueById=new Map();(D.issues||[]).forEach(i=>{if(!issueById.has(i.id))issueById.set(i.id,[]);issueById.get(i.id).push(i);});
function decision(r){if(!inc(r))return '';const rp=C.get(r,'replace-path');const ac=C.get(r,'art-choice');
 if(C.isNameIssue(r))return 'The file name needs a check';
 if(r.dataset.candidate==='1'&&r.dataset.duplicate!=='1'&&!(rp&&rp.value))return 'Possible duplicate: choose add or skip';
 if(r.dataset.imported==='1'&&r.dataset.duplicate!=='1')return 'Imported before: confirm adding it again';
 if(ac&&ac.value==='')return 'Choose a picture';return '';}
const blockers=()=>included().filter(r=>(r.dataset.type==='Books'&&!r.dataset.folder)||(!role(r)&&!r.dataset.category)||decision(r)); // Patch 4p: a book needs a shelf
const groupLabel=g=>{const h=$$('[data-heading]').find(x=>x.dataset.heading===g);return h?h.textContent.trim():g;};
function where(r){if(r.dataset.type==='Books')return r.dataset.folder?'Books \u203A '+r.dataset.folder:'Books (no shelf yet)'; /* Patch 4p */if(role(r)){const l=$('.placement-label',r);return l?l.textContent.replace(/^Edutek \u203A videos \u203A /,'').split(' \u203A ')[0]:r.dataset.type;}return r.dataset.category;}
/* all-items category: items without a folder-name category that were not moved by hand */
const allTargets=()=>items.filter(r=>!role(r)&&!manual.has(r.dataset.id)&&r.dataset.autoCategory==='');
function allCat(){const t=allTargets().filter(inc);if(!t.length)return '';const c=t[0].dataset.category;return t.every(r=>r.dataset.category===c)?c:'';}
function setCat(rows,cat,byHand){if(!C)return;try{const [n,skipped]=C.setCategoryFor(rows,cat);if(byHand)rows.forEach(r=>{if(!role(r))manual.add(r.dataset.id);});saveManual();
  toast(n?plural(n,'item')+' \u2192 '+catLabel(cat)+(skipped?' ('+skipped+' book/music/audiobook items keep their own place)':''):'Books, music and audiobooks always keep their own place.');}
 catch(e){toast(e.message);}}
/* ---------- Patch 4p: Books shelves chosen by you, new-category check, left-out list, import summary ---------- */
const P4=(D.p4&&D.p4.v===1)?D.p4:{v:0,shelves:[],left:[],leftN:0,loose:[],books:0,done:null};
const isBook=r=>r.dataset.type==='Books';
const shelfKey=s=>String(s||'').trim().toLowerCase();
const isNewShelf=s=>!!s&&!(P4.shelves||[]).some(x=>shelfKey(x)===shelfKey(s));
const shelfLabel=s=>s+(isNewShelf(s)?' (new shelf)':'');
const needShelf=()=>included().filter(r=>isBook(r)&&!r.dataset.folder);
const sessionShelves=[];
function allShelves(){const out=[...(P4.shelves||[])];for(const s of [...sessionShelves,...items.filter(isBook).map(r=>r.dataset.folder).filter(Boolean)])if(!out.some(x=>shelfKey(x)===shelfKey(s)))out.push(s);
 return out.sort((a,b)=>a.localeCompare(b,undefined,{numeric:true,sensitivity:'base'}));}
const csrf4=()=>{const c=$('[name=csrf]');return c?c.value:'';};
async function checkShelf(name){const fd=new FormData();fd.append('csrf',csrf4());fd.append('action','check_shelf');fd.append('name',name);const idf=$('#editor [name=id]');if(idf)fd.append('id',idf.value);
 let r;try{r=await fetch('/bulk-import-guided.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});}catch(e){throw new Error('Could not check the name (no connection).');}
 if(r.status===403)throw new Error('Session expired. Reload the page (your saved work is kept).');let j=null;try{j=await r.json();}catch(e){j=null;}if(!j||typeof j.ok!=='boolean')throw new Error('Could not check the name (HTTP '+r.status+').');return j;}
function setShelf(rows,shelf){let n=0;for(const r of rows){if(!isBook(r))continue;try{C.validatePlacement('Books',r.dataset.category,shelf);}catch(e){toast(e.message);return 0;}
  r.dataset.folder=shelf;r.dataset.placementOverride='1';C.syncLocation(r);n++;}
 if(n){C.changed();C.render();}toast(plural(n,'book')+' \u2192 Books \u203A '+shelfLabel(shelf)+(isNewShelf(shelf)?'. The shelf folder is made only when you publish.':''));return n;}
function openShelfPicker(rows,createFirst,after){const bk=rows.filter(isBook);if(!bk.length){after&&after();return;}let idx=0,list=[];
 const cur=bk.length===1?bk[0].dataset.folder:'';const head=bk.length===1?'Shelf for \u201C'+title(bk[0])+'\u201D':'Shelf for '+plural(bk.length,'book');
 openDialog(head,(d,close)=>{
  const inp=el('input',{type:'text','data-autofocus':'1','data-k':'shelf-input','aria-label':'Type to find a Books shelf, or type a new shelf name',placeholder:'Type to find a shelf, or a new name\u2026',maxlength:'120',autocomplete:'off',style:'width:100%'});
  const ul=el('ul',{class:'pick4o catlist4o',role:'listbox','aria-label':'Books shelves'}),count=el('p',{class:'muted',role:'status','aria-live':'polite'}),msg=el('p',{class:'warn4o',role:'alert',hidden:true});
  const build=()=>{const raw=inp.value.trim().replace(/\s+/g,' '),q=raw.toLowerCase();const sh=allShelves();
   list=sh.filter(s=>!q||s.toLowerCase().includes(q)).map((s,i)=>({s,label:shelfLabel(s),r:!q?0:s.toLowerCase()===q?0:s.toLowerCase().startsWith(q)?1:2,i})).sort((x,y)=>x.r-y.r||x.i-y.i); /* 4p: exact name first, then names starting with it */
   if(q&&!sh.some(s=>s.toLowerCase()===q))list[createFirst?'unshift':'push']({create:raw,label:'+ New shelf \u201C'+raw+'\u201D'});
   if(idx>=list.length)idx=Math.max(0,list.length-1);
   ul.replaceChildren(...list.map((o,i)=>el('li',{role:'option',id:'shelfopt4p-'+i,'aria-selected':String(i===idx),class:(i===idx?'cur ':'')+(o.create?'create':''),onclick:()=>{idx=i;choose();}},o.label,!o.create&&shelfKey(o.s)===shelfKey(cur)?el('span',{class:'muted'},'  (now)'):null)));
   if(list[idx])inp.setAttribute('aria-activedescendant','shelfopt4p-'+idx);else inp.removeAttribute('aria-activedescendant');
   count.textContent=list.length?(list.filter(o=>!o.create).length+' of '+sh.length+' shelves'+(list.some(o=>o.create)?' \u00B7 Enter on \u201C+ New shelf\u201D makes a new one':'')):(q?'':'No shelves yet: type a name for the first shelf.');
   const c=ul.children[idx];if(c&&c.scrollIntoView)c.scrollIntoView({block:'nearest'});};
  const finish=s=>{close(false);const n=setShelf(bk,s);if(n)after&&setTimeout(after,0);else rerender('row-'+S.cur);};
  const choose=async()=>{const o=list[idx];msg.hidden=true;
   if(!o){const raw=inp.value.trim();if(!raw){msg.textContent='Type a few letters of a shelf name, or a new name.';msg.hidden=false;}return;}
   if(!o.create){finish(o.s);return;}
   let j;try{j=await checkShelf(o.create);}catch(e){msg.textContent=e.message;msg.hidden=false;return;}
   if(!j.ok){msg.textContent=j.message;msg.hidden=false;if(j.suggest){inp.value=j.suggest;idx=0;build();}return;}
   if(!j.exists&&!sessionShelves.some(x=>shelfKey(x)===shelfKey(j.name)))sessionShelves.push(j.name);finish(j.name);};
  inp.addEventListener('input',()=>{idx=0;msg.hidden=true;build();});
  inp.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){idx=Math.min(list.length-1,idx+1);build();e.preventDefault();}else if(e.key==='ArrowUp'){idx=Math.max(0,idx-1);build();e.preventDefault();}
   else if(e.key==='Home'&&!inp.value){idx=0;build();e.preventDefault();}else if(e.key==='End'&&!inp.value){idx=list.length-1;build();e.preventDefault();}else if(e.key==='Enter'){e.preventDefault();choose();}});
  d.append(el('h2',{},createFirst?'New Books shelf'+(bk.length>1?' for '+plural(bk.length,'book'):''):head),
   el('p',{},'Books are never put on a shelf by their folder name: you choose the shelf. '+(createFirst?'Type the name of the new shelf, then press Enter.':'Type a few letters to find a shelf, \u2191 \u2193 to choose, Enter to put '+(bk.length===1?'the book':'the books')+' there. Type a name that is not in the list to make a new shelf.')),
   inp,count,msg,ul,el('div',{class:'nav4o'},el('span',{class:'muted'},kbd('Enter'),' choose \u00B7 ',kbd('Esc'),' cancel (nothing changes)'),el('button',{onclick:()=>close()},'Cancel ',kbd('Esc'))));build();},
  ()=>{});}
/* Before publishing: one light check of the new places this import makes. No is the safe choice (focused); Y confirms. */
function p4NewPlaces(){const rows=included();const cats=[],subs=new Map(),shelves=[];
 rows.forEach(r=>{if(isBook(r)){const s=r.dataset.folder;if(s&&isNewShelf(s)&&!shelves.some(x=>shelfKey(x)===shelfKey(s)))shelves.push(s);return;}
  if(role(r))return;const c=r.dataset.category;if(!c||!isNew(c))return;if(!cats.some(x=>x.toLowerCase()===c.toLowerCase()))cats.push(c);
  const sub=String(r.dataset.folder||'').split('/')[0];if(sub){const k=c.toLowerCase();if(!subs.has(k))subs.set(k,[]);const l=subs.get(k);if(!l.some(x=>x.toLowerCase()===sub.toLowerCase()))l.push(sub);}});
 return {cats,subs,shelves};}
function p4Confirm(go){const P=p4NewPlaces();if(!P.cats.length&&!P.shelves.length){go();return;}
 const parts=[];if(P.cats.length)parts.push(plural(P.cats.length,'new category','new categories'));if(P.shelves.length)parts.push(plural(P.shelves.length,'new Books shelf','new Books shelves'));
 openDialog('Make new places?',(d,close)=>{
  d.append(el('h2',{},'Publishing makes '+parts.join(' and ')),el('p',{},'Check the spelling: learners see these names in Edutek. Everything else goes into places that already exist.'));
  if(P.cats.length)d.append(el('h3',{},'New categories'),el('ul',{class:'p4new'},P.cats.map(c=>{const s=P.subs.get(c.toLowerCase())||[];return el('li',{},el('b',{},c),s.length?el('ul',{},s.map(x=>el('li',{},'subfolder: '+x))):null);})));
  if(P.shelves.length)d.append(el('h3',{},'New Books shelves'),el('ul',{class:'p4new'},P.shelves.map(s=>el('li',{},el('b',{},'Books \u203A '+s)))));
  d.append(el('div',{class:'nav4o'},el('button',{class:'primary big','data-k':'yes',onclick:()=>{close(false);go();}},'Yes, make them and publish ',kbd('Y')),
   el('button',{class:'big','data-k':'no','data-autofocus':'1',onclick:()=>{close();}},'No, go back and check ',kbd('Esc'))));
  d.addEventListener('keydown',e=>{const k=e.key.toLowerCase();if(k==='y'){e.preventDefault();$('[data-k=yes]',d).click();}else if(k==='n'){e.preventDefault();$('[data-k=no]',d).click();}});});}
/* Found screen: what is left out and why; how books and loose audio are placed */
function p4FoundExtra(){const f=el('div',{});
 if(P4.leftN)f.append(p4LeftBox(P4.left,P4.leftN,'Left out of this import ('+P4.leftN+'): not imported','left4p'));
 const nb=items.filter(isBook).length;if(nb)f.append(el('p',{class:'note4p'},el('b',{},plural(nb,'PDF book')+': '),'you choose the Books shelf for each book in the next step (books are never put on a shelf by folder name).'));
 (P4.loose||[]).forEach(x=>f.append(el('p',{class:'note4p'},el('b',{},plural(x.n,'loose audio file')+' '),'(not in a subfolder) go'+(x.n===1?'es':'')+' to ',el('b',{},x.to),', named after the folder you chose. This is the same as before.')));
 return f.childNodes.length?f:'';}
function p4LeftBox(list,n,head,id){const conv=list.some(x=>x.k==='video');
 return el('div',{class:'warnbox4o left4p',id},el('b',{},head),el('ul',{class:'leftlist4p'},list.map(x=>el('li',{},el('span',{class:'f4p'},x.f),' \u2014 ',x.why))),
  n>list.length?el('div',{class:'muted'},'\u2026 and '+(n-list.length)+' more.'):null,
  conv?el('div',{class:'muted'},'Videos that are not .mp4 (for example .mov or .mkv) are not converted yet. Converting them (the way the 4i conversion makes videos ready for phones) could be added later; for now, convert them to .mp4 first.'):null,
  el('div',{class:'muted'},'Nothing is deleted or changed in your source folder.'));}
function p4SummaryExtra(ul){const P=p4NewPlaces();const other=Math.max(0,(P4.leftN||0)-(P4.left||[]).filter(x=>x.k==='damaged'||x.k==='audio').length);
 if(other)ul.append(el('li',{},plural(other,'other file')+' left out: not a type the importer takes (listed on the first screen and on the Done screen)'));return ul;}
/* Done screen: the import summary */
const p4Base=location.protocol+'//'+location.hostname+':8080/';
function p4Dur(s){if(s==null)return '';if(s<60)return s+' s';const m=Math.floor(s/60),r=s%60;return m<60?m+' min'+(r?' '+r+' s':''):Math.floor(m/60)+' h '+(m%60)+' min';}
function p4Time(iso){const d=new Date(iso);if(!iso||isNaN(d))return '';return d.toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});}
function p4Link(kind,name,rel,sub){const fb={cat:'directory.php',shelf:'books.php',album:'music.php'}[kind];const ok=!!rel;
 const href=p4Base+(ok?rel:fb)+(ok&&sub?'#tut-sec-'+sub:'');
 return el('a',{href,target:'_blank',rel:'noopener',class:'link4p','data-p4kind':kind,'data-p4name':name,'data-p4sub':sub||'','data-p4ok':ok?'1':'0'},ok?({cat:sub?'Open section \u2197':'Open category \u2197',shelf:'Open shelf \u2197',album:'Open album \u2197'}[kind]):({cat:'Open Directory \u2197',shelf:'Open Books \u2197',album:'Open Music \u2197'}[kind]));}
function p4Done(p){const S4=P4.done;if(!S4||!S4.tot)return false;const t=S4.tot,L=S4.links||{ok:false,cats:{},shelves:{},albums:{},note:'The links could not be read yet.'};
 const n=t.videos+t.books+t.music+t.audiobooks,q=D.pq||{};const waiting=[];
 const box=el('div',{class:'donebox4o done4p'},el('h2',{tabindex:'-1','data-k':'h-done'},'\u2713 Done \u2014 '+plural(n,'item')+' '+(n===1?'is':'are')+' in the library'));
 const when=[S4.source?'From \u201C'+S4.source+'\u201D':'',S4.finished?'finished '+p4Time(S4.finished):'',S4.secs!=null?'took '+p4Dur(S4.secs)+(S4.startedFrom==='add'?' (from pressing Publish)':''):''].filter(Boolean).join(' \u00B7 ');
 if(when)box.append(el('p',{class:'muted'},when));
 const tile=(num,lab,k)=>el('div',{class:'tile4p'+(k?' '+k:'')},el('b',{},String(num)),el('span',{},lab));
 box.append(el('div',{class:'tiles4p'},tile(t.videos,'videos'),tile(t.books,'PDF books'),tile(t.music,'music files'),tile(t.audiobooks,'audiobook files'),
  tile(t.converted,'converted for phones'),tile(t.faststart,'fast-start fixed'),tile(t.mp3,'audio to MP3'),tile(S4.leftN,'left out',S4.leftN?'warn':'')));
 if(t.replaced)box.append(el('p',{},plural(t.replaced,'library file')+' replaced (the old files are kept; undo puts them back).'));
 if((S4.newCats||[]).length||(S4.newShelves||[]).length)box.append(el('p',{},(S4.newCats||[]).length?['New categories: ',el('b',{},S4.newCats.join(', ')),(S4.newShelves||[]).length?' \u00B7 ':'']:'',(S4.newShelves||[]).length?['New Books shelves: ',el('b',{},S4.newShelves.join(', '))]:''));
 box.append(el('div',{class:'nav4o'},el('span',{},el('a',{class:'btn4o primary big leave-page',href:'/bulk-import-guided.php','data-k':'again'},'Start a new import ',kbd('N')),' ',el('button',{'data-k':'seepast',onclick:goPast},'See past imports ',kbd('H'))),
  el('button',{class:'small4p','data-k':'undo',onclick:askUndo},'Undo this import\u2026 ',kbd('U'))));
 p.append(box);
 /* links */
 const sec=el('div',{class:'sec4p'},el('h2',{tabindex:'-1','data-k':'h-cats'},'Check it in Edutek ',kbd('C')),
  el('p',{class:'muted'},'Links open the Edutek site at ',el('code',{},p4Base),' (the address of this page, port 8080) in a new tab. '+(L.note||'')));
 const tb=el('table',{class:'sum4p'},el('tr',{},el('th',{},'Where'),el('th',{},'Items'),el('th',{},'Changes'),el('th',{},'')));
 const tw={Videos:'Videos',Books:'Books',Music:'Music',Audiobooks:'Audiobooks'};
 (S4.groups||[]).forEach(g=>{
  let top='';if(g.type==='Videos'){const rel=(L.cats||{})[g.cat];top=p4Link('cat',g.cat,rel,'');if(!rel)waiting.push(g.cat);}
  tb.append(el('tr',{class:'g4p'},el('td',{},el('b',{},g.type==='Videos'?g.cat:tw[g.type]||g.type),g.new?el('span',{class:'new4p'},'NEW'):null),el('td',{},String(g.n)),el('td',{}),el('td',{},top||'')));
  g.subs.forEach(s=>{const ch=[s.cv?s.cv+' converted':'',s.fs?s.fs+' fast-start':'',s.mp3?s.mp3+' to MP3':''].filter(Boolean).join(', ');let a='';
   if(g.type==='Videos'){const rel=(L.cats||{})[g.cat];a=s.sub?p4Link('cat',g.cat,rel,s.slug):'';if(a&&!rel)a='';}
   else if(g.type==='Books'){const rel=(L.shelves||{})[s.sub];a=p4Link('shelf',s.sub,rel,'');if(!rel)waiting.push('Books \u203A '+s.sub);}
   else if(g.type==='Music'){const rel=(L.albums||{})[s.sub];a=p4Link('album',s.sub,rel,'');if(!rel)waiting.push('Music \u203A '+s.sub);}
   else if(g.type==='Audiobooks')a=el('a',{href:p4Base+'audiobooks.php?q='+encodeURIComponent(s.sub),target:'_blank',rel:'noopener',class:'link4p'},'Open audiobook \u2197');
   tb.append(el('tr',{},el('td',{class:'sub4p'},'\u203A '+(s.sub||'(directly in '+g.cat+')'),s.new?el('span',{class:'new4p'},'NEW'):null),el('td',{},String(s.n)),el('td',{class:'muted'},ch),el('td',{},a)));});});
 sec.append(tb);
 if(waiting.length)sec.append(el('p',{class:'wait4p',role:'status','aria-live':'polite'},'Updating\u2026 New: '+waiting.join(', ')+'. The direct link appears when the library list update finishes; until then the link opens the Directory (or the Books / Music page).'));
 p.append(sec);
 /* PDFs */
 const docs=S4.docs||[];const ds=el('div',{class:'sec4p'},el('h2',{tabindex:'-1','data-k':'h-books'},'PDF books to check ('+docs.length+') ',kbd('B')));
 if(docs.length)ds.append(el('ul',{class:'docs4p'},docs.map(x=>el('li',{},el('a',{href:p4Base+'readpdf.php?id='+['content','Books',x.shelf,x.file].map(encodeURIComponent).join('/'),target:'_blank',rel:'noopener',class:'link4p'},'Open PDF \u2197'),' ',x.t,el('span',{class:'muted'},'  Books \u203A '+x.shelf)))));
 else ds.append(el('p',{class:'muted'},'No PDF books in this import.'));p.append(ds);
 /* left out */
 const ls=el('div',{class:'sec4p'},el('h2',{tabindex:'-1','data-k':'h-left'},'Left out ('+S4.leftN+') ',kbd('L')));
 if(S4.leftN)ls.append(p4LeftBox(S4.left||[],S4.leftN,'These files were not imported:','left4p-done'));else ls.append(el('p',{class:'muted'},'Nothing was left out.'));
 if(S4.skippedByYou)ls.append(el('p',{class:'muted'},plural(S4.skippedByYou,'item')+' you skipped (X) '+(S4.skippedByYou===1?'is':'are')+' not in the library either.'));
 p.append(ls);
 if(waiting.length)p4Poll();
 return true;}
let p4PollN=0;
function p4Poll(){if(p4PollN++>40)return;setTimeout(async()=>{let j=null;try{const r=await fetch('?id='+encodeURIComponent(ID)+'&p4links=1&token='+encodeURIComponent(csrf4()),{credentials:'same-origin',cache:'no-store'});if(r.ok)j=await r.json();}catch(e){j=null;}
 if(!j){p4Poll();return;}let left=0;
 $$('a.link4p[data-p4ok="0"]',root).forEach(a=>{const k=a.dataset.p4kind,nm=a.dataset.p4name;const rel=(j[{cat:'cats',shelf:'shelves',album:'albums'}[k]]||{})[nm];
  if(rel){a.href=p4Base+rel+(a.dataset.p4sub?'#tut-sec-'+a.dataset.p4sub:'');a.textContent={cat:'Open category \u2197',shelf:'Open shelf \u2197',album:'Open album \u2197'}[k];a.dataset.p4ok='1';}else left++;});
 if(P4.done&&P4.done.links){Object.assign(P4.done.links.cats,j.cats||{});Object.assign(P4.done.links.shelves,j.shelves||{});Object.assign(P4.done.links.albums,j.albums||{});}
 const w=$('.wait4p',root);if(!left){if(w)w.textContent='All direct links are ready.';}else p4Poll();},p4PollN<4?5000:15000);}
function p4Keys(k){if(!P4.done||(D.pq||{}).state!=='published')return false;
 if(k==='n'){location.href='/bulk-import-guided.php';return true;}
 const m={c:'h-cats',b:'h-books',l:'h-left'}[k];if(m){const h=$('[data-k="'+m+'"]',root);if(h){h.focus();h.scrollIntoView({block:'start'});}return true;}return false;}
/* ---------- render ---------- */
const STEPS=['Choose what to import','Put them in the library','Check and publish'];
function stepper(n){return el('ol',{class:'steps4o','aria-label':'Steps'},STEPS.map((t,i)=>el('li',{class:i+1<n?'done':i+1===n?'now':'','aria-current':i+1===n?'step':false},el('b',{},i+1<n?'\u2713':String(i+1)),t)));}
function screen(){ if(pastView)return 'past';if(!ID)return 'start';
 if(D.editable){if(!C)return 'classic';if(D.state==='approved'&&SS('edutek-4o-edit-'+ID)!=='1')return 'check';
  if(D.state==='draft'&&D.screen==='select'&&!included().length&&!LS('edutek-4o-init-'+ID)&&S.step!==2)return 'found';return 'list';}
 return 'status';}
let curScreen='';
function render(focusKey){restoreArticles();const a=document.activeElement;const k=focusKey||(a&&root.contains(a)&&a.getAttribute('data-k'));
 curScreen=screen();root.replaceChildren();
 ({past:rPast,start:rStart,found:rFound,list:rList,check:rCheck,status:rStatus,classic:rClassic})[curScreen](root);
 hints();let f=k&&$('[data-k="'+CSS.escape(k)+'"]',root);if(!f)f=$('h2[tabindex]',root);if(f)f.focus({preventScroll:false});}
function errBox(){const e=D.error?el('div',{class:'errbox4o',role:'alert'},el('b',{},'That did not work: '),D.error,el('div',{class:'muted'},'Your source files have not been changed.')):null;
 const sk=(D.auSkip||[]),w=sk.length?el('div',{class:'warnbox4o',role:'alert',id:'audio-skipped4o'},el('b',{},'Left out of this import (can\u2019t be played): '),el('ul',{},sk.map(x=>el('li',{},x))),el('div',{class:'muted'},'Nothing unplayable is published. Your source files have not been changed.')):null;
 if(!e&&!w)return '';if(!w)return e;if(!e)return w;const f=el('div',{});f.append(e,w);return f;}
function rClassic(m){classic.open=true;m.append(el('p',{class:'muted'},'This import is shown in the full page below.'));}
/* Step 1 */
function rStart(m){m.append(stepper(1),errBox());const p=el('section',{class:'panel4o'});
 p.append(el('h2',{tabindex:'-1','data-k':'h-start'},'What do you want to import?'),el('p',{},'Choose the folder that holds the new videos, audiobooks or books. Nothing in it is changed.'));
 const f=$('#src-form');
 if(f){const pick=$('#pick-btn',f),start=$('#start',f);p.append(f);f.classList.add('src4o');
  if(pick){pick.classList.add('big');if(!D.srcPicked)pick.classList.add('primary');pick.dataset.k='browse';if(!pick.dataset.u4){pick.dataset.u4='1';pick.append(' ',kbd('B'));}}
  if(start){start.dataset.k='scan';start.classList.add('primary','big');if(!start.dataset.u4){start.dataset.u4='1';start.textContent='Look inside this folder ';start.append(kbd('Enter'));}}
  if(!D.srcPicked&&start)start.hidden=true;}
 if(D.srcPicked)p.append(el('p',{class:'found4o'},'Folder: ',el('b',{},D.srcShown)));
 m.append(p);
 const open=(D.history||[]).filter(h=>!['Published','Undone','Undo in progress','Staged copies cleared'].includes(h.s)).slice(0,8);
 if(open.length){const q=el('section',{class:'panel4o'},el('h2',{style:'font-size:20px'},'Not finished yet'),el('p',{class:'muted'},'Imports you started earlier. ',kbd('Tab'),' to one, ',kbd('Enter'),' to continue it.'));
  const ul=el('ul',{class:'past4o'});open.forEach((h,i)=>ul.append(el('li',{},el('div',{},el('div',{style:'font-weight:600'},h.src||'Import '+h.id.slice(0,8)),el('div',{class:'muted'},lw(h)+' \u00B7 '+h.s+' \u00B7 '+h.n+' of '+h.total+' chosen')),
   el('a',{class:'btn4o leave-page',href:'?id='+h.id,'data-k':'open-'+i},'Continue'))));q.append(ul);
  q.append(el('p',{},el('a',{href:'?view=published',class:'leave-page'},'All past imports and undo (H)')));m.append(q);}}
/* Step 1b: what was found */
function rFound(m){m.append(stepper(1),errBox());const p=el('section',{class:'panel4o'});
 const kinds={};items.forEach(r=>kinds[r.dataset.type]=(kinds[r.dataset.type]||0)+1);
 const imp=items.filter(r=>r.dataset.imported==='1').length,cand=items.filter(r=>r.dataset.candidate==='1').length,nm=items.filter(r=>C.isNameIssue(r)).length;
 p.append(el('h2',{tabindex:'-1','data-k':'h-found'},'Found ',plural(items.length,'item'),' in ',D.source),
  el('ul',{class:'kinds4o'},Object.entries(kinds).map(([t,n])=>el('li',{},n+' '+typeWord(t,n))),
   imp?el('li',{},plural(imp,'item')+' already in the library: left out (you can add one again in the next step)'):null,
   cand?el('li',{},plural(cand,'possible duplicate')+': left out until you decide'):null,
   nm?el('li',{},plural(nm,'file name')+' to check: left out until you check them'):null),
  el('p',{class:'muted'},'Items in a subfolder get the category with the folder\u2019s name (a new category if the library does not have it yet). Unsorted is never chosen automatically.'),p4FoundExtra(), // Patch 4p

  el('div',{class:'nav4o'},el('button',{'data-k':'back1',onclick:backToStart},'\u2190 Choose another folder ',kbd('B')),
   el('button',{class:'primary big','data-k':'continue1',onclick:startList},'Continue ',kbd('N'))));m.append(p);}
function startList(){LS('edutek-4o-init-'+ID,'1');let n=0;items.forEach(r=>{if(!inc(r)&&r.dataset.candidate!=='1'&&r.dataset.imported!=='1'&&r.dataset.damaged!=='1'&&!C.isNameIssue(r)){C.get(r,'include').checked=true;n++;}});
 if(n){C.changed();C.render();C.refreshNew();}S.step=2;S.cur=0;render('row-0');}
function backToStart(){const go=()=>{location.href='/bulk-import-guided.php';};if(C&&C.isDirty()){yesNo('Leave this import?',['Your changes are saved first, so you can come back to this import later.'],'Save and leave','Stay',()=>{SS('edutek-4o-after-save','start');saveNow();});}else go();}
function saveNow(){const ed=$('#editor');if(!ed)return;const b=$$('button',ed).find(x=>x.dataset.action==='save')||$('#save-button');ed.requestSubmit(b||undefined);}
/* Step 2 */
function rList(m){m.append(stepper(2),errBox());
 const p=el('section',{class:'panel4o'});const ac=allCat(),tN=allTargets().length;
 p.append(el('h2',{tabindex:'-1','data-k':'h-list'},'Where should these go?'));
 if(D.screen==='fix'&&(D.issues||[]).length){const shown=(D.issues||[]).filter(i=>i.kind!=='preview');const pv=(D.issues||[]).filter(i=>i.kind==='preview').length;
  p.append(el('div',{class:'warnbox4o',role:'alert'},el('b',{},'Some items still need something before they can be published. '),'They are marked below.',pv?el('div',{},plural(pv,'cover picture')+' still being made. Wait a moment, then press N again.'):null,
   shown.length?el('ul',{},shown.slice(0,8).map(i=>el('li',{},(byId.get(i.id)?title(byId.get(i.id))+': ':'')+i.message))):null));}
 if(tN)p.append(el('div',{class:'question4o'},el('label',{},'Put them in:'),
  el('button',{class:'catbtn4o'+(ac?'':' empty'),'data-k':'allcat',onclick:()=>openCatPicker('Put '+plural(tN,'item')+' in\u2026',ac,c=>{setCat(allTargets(),c,false);render('allcat');})},ac?catLabel(ac):'Choose a category\u2026'),
  el('button',{'data-k':'newcat-all',onclick:()=>newCat(false)},'New category\u2026 ',kbd('C'))),
  el('p',{class:'muted'},'This goes for the '+plural(tN,'item')+' that are not in a subfolder'+(items.length>tN?'. Items in a subfolder already have a place from the folder name; items you move yourself keep their place.':'.')));
 const shN=needShelf().length,need=needCat().length,dec=included().filter(r=>decision(r)).length,n=included().length,skip=items.length-n; // Patch 4p: shN
 p.append(el('p',{class:'progress4o '+(shN||need||dec?'todo':'ok'),role:'status','aria-live':'polite'},shN?plural(shN,'book')+' still need'+(shN===1?'s':'')+' a shelf (press M on it, C for a new shelf)':need?plural(need,'item')+' still need'+(need===1?'s':'')+' a category':dec?plural(dec,'item')+' need'+(dec===1?'s':'')+' a decision (press D on it)':n+' of '+n+' ready',skip?' \u00B7 '+skip+' skipped':''));
 m.append(p);
 const l=el('section',{class:'panel4o'});
 l.append(el('div',{class:'listtools4o'},el('h2',{style:'margin:0;font-size:20px'},'Items (',String(items.length),')'),
  el('button',{'data-k':'selall',onclick:toggleAll},S.sel.size===items.length&&items.length?'Select none ':'Select all ',kbd('A')),
  el('span',{class:'muted'},kbd('\u2191'),' ',kbd('\u2193'),' move \u00B7 ',kbd('Space'),' select \u00B7 ',kbd('M'),' move to\u2026 \u00B7 ',kbd('R'),' rename \u00B7 ',kbd('X'),' include/skip \u00B7 ',kbd('D'),' details')));
 const ul=el('ul',{class:'items4o',role:'listbox','aria-multiselectable':'true','aria-label':'Items to import'});let lastG=null;
 if(S.cur>=items.length)S.cur=Math.max(0,items.length-1);
 items.forEach((r,i)=>{if(r.dataset.group!==lastG){lastG=r.dataset.group;ul.append(el('li',{class:'group4o',role:'presentation'},groupLabel(lastG)));}ul.append(row(r,i));});
 l.append(ul);m.append(l);
 if(S.sel.size)m.append(el('div',{class:'bar4o',role:'region','aria-label':'Move selected'},el('b',{},'Move '+S.sel.size+' selected to:'),
  el('button',{class:'primary','data-k':'movebtn',onclick:startMove},'Choose a category\u2026 ',kbd('M')),
  el('button',{'data-k':'newcat-bar',onclick:()=>newCat(true)},'New category\u2026 ',kbd('C')),
  el('button',{'data-k':'clearsel',onclick:()=>{S.sel.clear();render('row-'+S.cur);}},'Clear selection ',kbd('Esc'))));
 m.append(el('div',{class:'nav4o'},el('span',{},el('button',{'data-k':'back2',onclick:backToStart},'\u2190 Back ',kbd('B')),' ',el('button',{'data-k':'save2',onclick:()=>{saveNow();}},'Save and finish later ',kbd('S'))),
  el('button',{class:'primary big','data-k':'next2',onclick:next},'Next: Check and publish ',kbd('N'))));}
function row(r,i){const id=r.dataset.id,on=inc(r),sel=S.sel.has(id),cat=r.dataset.category,dc=decision(r),iss=issueById.get(id)||[];
 const label=title(r)+', '+typeWord(r.dataset.type,1)+', '+(on?(role(r)?'goes to '+where(r):cat?'goes to '+catLabel(cat):'needs a category'):'skipped')+(r.dataset.damaged==='1'?', can\u2019t be played, file is damaged':'')+(dc?', '+dc:'');
 const li=el('li',{role:'option','aria-selected':String(sel),tabindex:i===S.cur?'0':'-1','data-k':'row-'+i,'data-id':id,class:'item4o'+(sel?' sel':'')+(on?'':' skipped')+((on&&((!role(r)&&!cat)||(r.dataset.type==='Books'&&!r.dataset.folder)||dc))?' needs':''),'aria-label':label,
  onclick:e=>{if(e.target.closest('button,input,select,a,details,.open4o'))return;S.cur=i;toggleSel(r);},
  onfocus:e=>{if(e.target!==li)return;if(S.cur!==i){S.cur=i;$$('li.item4o',root).forEach((x,j)=>x.tabIndex=j===i?0:-1);}}});
 li.append(el('div',{class:'selmark4o','aria-hidden':'true'},sel?'\u2713':''));
 const img=$('img.art',r);li.append(img&&img.getAttribute('src')?el('div',{class:'thumb4o'},el('img',{src:img.getAttribute('src'),alt:''})):el('div',{class:'thumb4o ph'},r.dataset.type==='Books'?'BOOK':r.dataset.type==='Videos'?'\u25B6 VIDEO':'\u266B AUDIO'));
 const mid=el('div',{class:'mid4o'});
 if(S.renaming===id){const t=el('input',{type:'text',class:'rename4o',value:title(r),'data-k':'rename-'+i,'aria-label':'New title for this item (Enter to keep, Esc to cancel)',maxlength:'240'});
  t.addEventListener('keydown',e=>{e.stopPropagation();if(e.key==='Enter'){e.preventDefault();const v=t.value.trim();if(v&&v!==title(r)){const ti=C.get(r,'title');ti.value=v;ti.dispatchEvent(new Event('input'));toast('Renamed. The file name follows the new title.');}S.renaming=null;render('row-'+i);}
   else if(e.key==='Escape'){e.preventDefault();S.renaming=null;render('row-'+i);}});mid.append(t,el('div',{class:'muted'},kbd('Enter'),' keep \u00B7 ',kbd('Esc'),' cancel'));}
 else mid.append(el('div',{class:'title4o'},title(r)));
 const meta=el('div',{class:'meta4o'},el('span',{class:'chip4o'},typeWord(r.dataset.type,1)));
 if(!on)meta.append(el('span',{class:'chip4o'},'Skipped'));
 else if(r.dataset.type==='Books')meta.append(r.dataset.folder?el('span',{class:'chip4o cat shelf'},'\u2192 Books \u203A '+r.dataset.folder+(isNewShelf(r.dataset.folder)?' (new shelf)':'')):el('span',{class:'chip4o need'},'Needs a shelf \u2014 press M')); // Patch 4p
 else if(role(r))meta.append(el('span',{class:'chip4o cat'},'\u2192 '+where(r)));
 else if(cat)meta.append(el('span',{class:'chip4o cat'},'\u2192 '+catLabel(cat)));else meta.append(el('span',{class:'chip4o need'},'Needs a category'));
 if(r.dataset.imported==='1')meta.append(el('span',{class:'chip4o have'},'Already in the library'));
 if(r.dataset.candidate==='1')meta.append(el('span',{class:'chip4o have'},'Possible duplicate'));
 const mb=$('.media-badge',r);if(r.dataset.damaged==='1')meta.append(el('span',{class:'chip4o bad'},'Can\u2019t be played \u2014 file is damaged'));else if(mb&&mb.classList.contains('audio-badge')){if(/MP3:/.test(mb.textContent))meta.append(el('span',{class:'chip4o phone'},'Will be converted to MP3'));}else if(mb&&/convert|fix/i.test(mb.textContent))meta.append(el('span',{class:'chip4o phone'},'Will be made ready for phones'));
 if(dc)meta.append(el('span',{class:'chip4o need'},dc+' \u2014 press D'));
 iss.filter(x=>x.kind==='preview').forEach(()=>meta.append(el('span',{class:'chip4o'},'Making the cover picture\u2026')));
 mid.append(meta);li.append(mid);
 const btns=el('div',{class:'rowbtns4o'});
 if(r.dataset.type==='Books')btns.append(el('button',{tabindex:'-1',class:'catbtn4o'+(r.dataset.folder?'':' empty'),onclick:()=>{S.cur=i;moveRows([r]);}},r.dataset.folder?'Shelf: '+r.dataset.folder:'Choose a shelf\u2026')); // Patch 4p
 if(!role(r))btns.append(el('button',{tabindex:'-1',class:'catbtn4o'+(cat?'':' empty'),onclick:()=>{S.cur=i;moveRows([r]);}},cat?catLabel(cat):'Choose\u2026'));
 btns.append(el('button',{tabindex:'-1',onclick:()=>{S.cur=i;toggleInc(r);}},on?'Skip ':'Include ',kbd('X')));li.append(btns);
 if(S.open.has(id)){const box=el('div',{class:'open4o'});const dl=el('dl',{});
  dl.append(el('dt',{},'Goes to'),el('dd',{},($('.placement-label',r)?.textContent||'')),el('dt',{},'Size'),el('dd',{},(Number(r.dataset.bytes||0)/1048576).toFixed(1)+' MiB'));
  if(mb)dl.append(el('dt',{},'For phones'),el('dd',{},mb.textContent));
  box.append(dl);
  r.hidden=false;$$('.decision',r).forEach(x=>x.hidden=false);r.classList.add('inline4o');box.append(el('div',{class:'muted'},'All choices for this item (',kbd('Tab'),' to them, ',kbd('D'),' or ',kbd('Esc'),' to close):'),r);li.append(box);}
 return li;}
function restoreArticles(){items.forEach(r=>{if(r.classList.contains('inline4o')){r.classList.remove('inline4o');const g=$$('[data-heading]').find(h=>h.dataset.heading===r.dataset.group);
  /* put it back after the last article of its group in the classic list */
  const peers=items.filter(x=>x.dataset.group===r.dataset.group&&!x.classList.contains('inline4o')&&x!==r);const idx=items.indexOf(r);
  const after=[...peers].reverse().find(x=>items.indexOf(x)<idx);if(after)after.after(r);else if(g)g.after(r);}});}
const rerender=render;
function toggleSel(r){const id=r.dataset.id;S.sel.has(id)?S.sel.delete(id):S.sel.add(id);rerender('row-'+S.cur);}
function toggleAll(){if(S.sel.size===items.length)S.sel.clear();else items.forEach(r=>S.sel.add(r.dataset.id));rerender(document.activeElement?.dataset?.k==='selall'?'selall':'row-'+S.cur);}
function toggleInc(r){const c=C.get(r,'include');const was=c.checked;if(!was&&r.dataset.damaged==='1'){toast('Can\u2019t include '+title(r)+': the file is damaged and can\u2019t be played. Your source file is unchanged.');return;}c.checked=!c.checked;c.dispatchEvent(new Event('change',{bubbles:true}));
 if(c.checked!==was){toast(c.checked?'Included: '+title(r):'Skipped: '+title(r)+' (nothing is deleted)');}rerender('row-'+S.cur);}
function targets(){return S.sel.size?items.filter(r=>S.sel.has(r.dataset.id)):(items[S.cur]?[items[S.cur]]:[]);}
function moveRows(rows){if(!rows.length)return;const bk4=rows.filter(r=>r.dataset.type==='Books');if(bk4.length){const vid4=rows.filter(r=>!role(r));openShelfPicker(bk4,false,()=>{if(vid4.length)moveRows(vid4);else{S.sel.clear();rerender('row-'+S.cur);}});return;} /* Patch 4p: books get a shelf */const fixed=rows.filter(role).length;if(fixed===rows.length){toast('Books, music and audiobooks always keep their own place.');return;}
 openCatPicker(rows.length===1?'Move \u201C'+title(rows[0])+'\u201D to\u2026':'Move '+rows.length+' items to\u2026',rows.length===1?rows[0].dataset.category:'',c=>{setCat(rows,c,true);S.sel.clear();rerender('row-'+S.cur);});}
function startMove(){moveRows(targets());}
function newCat(forSel){const rows=forSel||S.sel.size?targets():allTargets();if((forSel||S.sel.size)&&rows.length&&rows.every(r=>r.dataset.type==='Books')){openShelfPicker(rows,true,()=>{S.sel.clear();rerender('row-'+S.cur);});return;} // Patch 4p: C on books makes a new shelf

 openCatPicker('New category'+(rows.length?' for '+plural(rows.length,'item'):''),'',c=>{if(rows.length)setCat(rows,c,!!(forSel||S.sel.size));S.sel.clear();rerender(forSel?'row-'+S.cur:'allcat');},true);}
function next(){const b=blockers();if(b.length){const i=items.indexOf(b[0]);S.cur=i;const r=b[0];rerender('row-'+i);
  toast((r.dataset.type==='Books'&&!r.dataset.folder)?plural(needShelf().length,'book')+' still need'+(needShelf().length===1?'s':'')+' a shelf. Press M to choose one (C for a new shelf), or X to skip it.':(!role(r)&&!r.dataset.category)?plural(needCat().length,'item')+' still need'+(needCat().length===1?'s':'')+' a category. Press M to choose one, or X to skip it.':decision(r)+'. Press D to decide, or X to skip it.');return;}
 if(!included().length){toast('Nothing is included yet. Press X on an item to include it.');return;}
 const ed=$('#editor');const b2=$$('button',ed).find(x=>x.dataset.action==='preview');if(!b2){toast('Use the full page (Details) for this import.');return;}
 SS('edutek-4o-edit-'+ID,null);toast('Checking\u2026');ed.requestSubmit(b2);}
/* Step 3: check and publish */
function summaryList(){const by=new Map();const rows=included();rows.forEach(r=>{const w=role(r)?where(r):r.dataset.category;if(!by.has(w))by.set(w,[]);by.get(w).push(r);});
 const ul=el('ul',{class:'summary4o'});for(const[w,list]of by){const t={};list.forEach(r=>t[r.dataset.type]=(t[r.dataset.type]||0)+1);
  ul.append(el('li',{},Object.entries(t).map(([k,n])=>n+' '+typeWord(k,n)).join(' + ')+' \u2192 ',el('b',{},w),list.some(r=>!role(r)&&isNew(r.dataset.category))?' (new category)':''));}
 if(D.convert)ul.append(el('li',{},plural(D.convert,'video')+' will be made ready for phones ',el('small',{},'(takes a few minutes, done before anything is copied)')));
 if(D.aconv)ul.append(el('li',{},plural(D.aconv,'audio file')+' will be converted to MP3 ',el('small',{},'(done before anything is copied)')));
 if(D.damaged)ul.append(el('li',{},plural(D.damaged,'damaged file')+' left out ',el('small',{},'(can\u2019t be played)')));
 const rep=rows.filter(r=>C.get(r,'replace-path')?.value).length;if(rep)ul.append(el('li',{},plural(rep,'item')+' will replace a library file ',el('small',{},'(the old file is kept; undo puts it back)')));
 const sk=Math.max(0,items.length-rows.length-(D.damaged||0));if(sk)ul.append(el('li',{},sk+' skipped'));return p4SummaryExtra(ul);} // Patch 4p
function rCheck(m){m.append(stepper(3),errBox());const p=el('section',{class:'panel4o'});const n=included().length;
 p.append(el('h2',{tabindex:'-1','data-k':'h-check'},'Check and publish'),summaryList(),
  el('p',{class:'muted'},'First a backup of the library list is made. You can undo the whole import afterwards.'));
 const prog=$('#copy-progress');if(prog)p.append(prog);
 p.append(el('div',{class:'nav4o'},el('button',{'data-k':'back3',onclick:()=>{SS('edutek-4o-edit-'+ID,'1');S.step=2;rerender('row-'+S.cur);}},'\u2190 Back to change ',kbd('B')),
  el('button',{class:'primary big','data-k':'publish',id:'publish4o',onclick:publish},'Publish '+plural(n,'item')+' to the library')));m.append(p);}
function publish(){p4Confirm(publishNow);} // Patch 4p: one check of new categories and shelves first
function publishNow(){const f=$('#execute-form'),b=$('#add-button');if(!f||!b){toast('Use the full page (Details) for this import.');return;}
 if(C.isDirty()){toast('You changed something. Press B, then N to check again.');return;}
 LS('edutek-4o-pub-'+ID,JSON.stringify({a:D.approval,t:Date.now()}));window.EDU4O_OK=true;
 const pb=$('#publish4o');if(pb){pb.disabled=true;pb.textContent='Publishing\u2026 step 1 of 3: copying to the Beelink\u2019s staging area';}
 const prog=$('#copy-progress');if(prog)prog.hidden=false;f.requestSubmit(b);}
function intent(){try{const j=JSON.parse(LS('edutek-4o-pub-'+ID)||'null');return j&&j.a===D.approval&&Date.now()-j.t<12*3600e3;}catch(e){return false;}}
function rStatus(m){const q=D.pq||{},st=D.state,p=el('section',{class:'panel4o'});m.append(stepper(3),errBox());
 const n=D.selected||0,h2=(t,k)=>el('h2',{tabindex:'-1','data-k':k||'h-status'},t);
 const pubBtn=(label)=>el('button',{class:'primary big','data-k':'publish-again',onclick:()=>doPublish(false)},label);
 if(st==='converting'){p.append(h2('Publishing\u2026 step 2 of 3: '+(D.aconv?'converting '+plural(D.jobs||((D.convert||0)+D.aconv),'file')+(D.convert?' (videos for phones, audio to MP3)':' to MP3'):'making '+plural(D.jobs||D.convert||0,'video')+' ready for phones and browsers')),el('p',{},'This runs on the Beelink and can take a few minutes. You can leave this page open; it continues by itself.'));
  const cp=$('#convert-panel');if(cp){const bar=$('#conv-bar-wrap',cp),txt=$('#conv-text',cp);if(bar)p.append(bar);if(txt)p.append(txt);}}
 else if(st==='importing'){p.append(h2('Publishing\u2026 copying to the staging area'),el('p',{},'Please wait.'));}
 else if(st==='complete'){
  if(q.undoReq||q.undo==='undoing'){p.append(h2('Undoing\u2026'),el('p',{},'The Beelink is taking this import out of the library. The files are kept in the recovery folder. This page updates by itself.'));}
  else if(q.planReq||q.undo==='planning'){p.append(h2('Undo: checking what would change\u2026'),el('p',{},'Nothing is changed yet. This page updates by itself.'));}
  else if(q.state==='published'){done(p);}
  else if(q.state==='undone'||q.state==='reversed'){p.append(el('div',{class:'undonebox4o'},h2('Import undone','h-undone'),el('p',{},'Nothing from this import is in the library any more. The files are kept in the recovery folder. If you choose this folder again, its items are not marked \u201Calready in the library\u201D.'),
   el('div',{class:'nav4o'},el('a',{class:'btn4o primary big leave-page',href:'/bulk-import-guided.php','data-k':'again'},'Start another import'),el('button',{'data-k':'seepast',onclick:goPast},'See past imports ',kbd('H')))));}
  else if(q.requested||q.state==='checking'||q.state==='publishing'){p.append(h2('Publishing\u2026 step 3 of 3: copying into the library'),el('p',{},'The Beelink checks the files once more and copies them into the library. This page updates by itself.'),
   q.watcherOk?null:el('p',{class:'warn4o'},'The publisher on the Beelink is not running right now. The import waits until the \u201CEdutek Import Publisher\u201D task runs again (for example after the Beelink has fully started).'));}
  else if(q.state==='failed'){p.append(el('div',{class:'errbox4o'},h2('Publishing failed'),el('p',{},'Some files may already be in the library. Open Details below for the exact message, or undo the import.'),q.message?el('details',{},el('summary',{},'Details'),el('pre',{},q.message)):null));}
  else if(q.state==='attention'){p.append(el('div',{class:'errbox4o'},h2('Publishing needs attention'),el('p',{},'Open Details below for the exact message.'),q.message?el('details',{},el('summary',{},'Details'),el('pre',{},q.message)):null));}
  else if(q.canRequest){const blocked=q.state==='blocked',ack=$('#replace-ack');
   if(!blocked&&!ack&&intent()){p.append(h2('Publishing\u2026 sending to the library'),el('p',{},'Please wait.'));setTimeout(()=>doPublish(true),300);}
   else{p.append(blocked?el('div',{class:'warnbox4o'},h2('Not published yet: the final check stopped it'),el('p',{},firstLine(q.message)||'Nothing was copied to the library.'),el('p',{},'Nothing was copied to the library. You can press Publish again.'),q.message?el('details',{},el('summary',{},'Details'),el('pre',{},q.message)):null)
     :h2('Ready to publish '+plural(q.staged||n,'item')));
    if(ack){ack.setAttribute('form','publish-form');const lab=ack.closest('label')||ack;p.append(el('p',{},'This import replaces '+plural(q.stagedReplace,'library file')+'. The old files are kept and undo puts them back.'),lab);}
    p.append(el('div',{class:'nav4o'},el('span'),pubBtn(blocked?'Publish again':'Publish '+plural(q.staged||n,'item')+' to the library')));}}
  else p.append(h2('Status: '+(q.state||st)),el('p',{},'Open Details below.'));}
 else if(st==='interrupted'||st==='attention'){p.append(el('div',{class:'warnbox4o'},h2('Copying to the staging area did not finish'),el('p',{},'Nothing was published. Open Details below to clear the staged copies and start again.')));}
 else if(st==='reversing'||st==='reversed'){p.append(h2(st==='reversed'?'Staged copies cleared':'Clearing the staged copies\u2026'),el('p',{},'The library was not changed.'),el('a',{class:'btn4o leave-page',href:'/bulk-import-guided.php','data-k':'again'},'Start another import'));}
 else p.append(h2('Status: '+st),el('p',{},'Open Details below.'));
 m.append(p);
 const u=q.undo||'';if(q.state==='published'&&['stopped','refused','mismatch','failed'].includes(u))m.append(el('section',{class:'panel4o'},el('div',{class:'warnbox4o'},el('b',{},{stopped:'The undo was stopped. Nothing was changed.',refused:'The undo was refused. Nothing was changed.',mismatch:'The library changed since the undo was checked, so nothing was undone.',failed:'The undo failed. Some files may already have moved.'}[u]),q.undoMessage?el('details',{},el('summary',{},'Details'),el('pre',{},q.undoMessage)):null)));}
const firstLine=s=>String(s||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean)[0]||'';
function doPublish(auto){const f=$('#publish-form'),b=$('#publish-btn'),a=$('#replace-ack');if(!f||!b){toast('Use the full page (Details) for this import.');return;}
 if(a&&!a.checked){toast('Tick the box first (Tab to it, then Space).');a.focus();return;}
 LS('edutek-4o-pub-'+ID,null);if(!auto){const x=$('[data-k=publish-again]',root);if(x){x.disabled=true;x.textContent='Sending\u2026';}}
 b.disabled=false;f.requestSubmit(b);}
function done(p){try{if(p4Done(p))return;}catch(e){console.error(e);p.replaceChildren();} /* Patch 4p summary; the 4o box below is the fallback */const q=D.pq||{},its=D.items||[];
 const box=el('div',{class:'donebox4o'},el('h2',{tabindex:'-1','data-k':'h-done'},'\u2713 Done \u2014 '+plural(q.learning||its.length,'item')+' '+((q.learning||its.length)===1?'is':'are')+' in the library'));
 const by=new Map();its.forEach(i=>{const w=String(i.to||'').split(' \u203A ')[0];if(!by.has(w))by.set(w,0);by.set(w,by.get(w)+1);});
 box.append(el('ul',{class:'pub4o'},[...by].map(([w,c])=>el('li',{},plural(c,'item')+' \u2192 '+w)),q.replaced?el('li',{},q.replaced+' replaced a library file (the old files are kept)'):null,q.finished?el('li',{class:'muted'},'Finished '+q.finished):null));
 box.append(el('div',{class:'nav4o'},el('button',{class:'big','data-k':'undo',onclick:askUndo},'Undo this import ',kbd('U')),
  el('span',{},el('a',{class:'btn4o leave-page',href:'/bulk-import-guided.php','data-k':'again'},'Start another import'),' ',el('button',{'data-k':'seepast',onclick:goPast},'See past imports ',kbd('H')))));p.append(box);}
function askUndo(){const q=D.pq||{};if(q.state!=='published'||q.undoReq||q.planReq||['planning','undoing'].includes(q.undo))return;
 if(q.planOk)return confirmUndo();
 yesNo('Undo this import?',['This takes the '+plural(q.learning||0,'item')+' of this import out of the library again. The files are kept in the recovery folder, and any library files it replaced are put back.','First the Beelink checks exactly what would change. Nothing is changed until you say Yes a second time.'],'Yes, check what undo would change','No, keep the import',()=>{
  const f=$$('form.undo-form').find(x=>$('[name=action]',x)?.value==='undo_plan');if(!f){toast('Use the full page (Details) for undo.');return;}SS('edutek-4o-undo-'+ID,'1');f.requestSubmit();});}
function confirmUndo(){const q=D.pq||{},c=q.counts||{};const f=$('#undo-form'),a=$('#undo-ack');if(!f||!a){toast('Use the full page (Details) for undo.');return;}
 yesNo('Undo now?',['The check is done. Undo will:','\u2022 take '+plural(c.move||0,'new file')+' out of the library (kept in the recovery folder)','\u2022 put back '+plural(c.restore||0,'replaced library file'),'The library list is updated afterwards.'],'Yes, undo this import','No, keep it',()=>{SS('edutek-4o-undo-'+ID,null);a.checked=true;a.dispatchEvent(new Event('change',{bubbles:true}));const b=$('#undo-btn');if(b)b.disabled=false;f.requestSubmit(b||undefined);},()=>{SS('edutek-4o-undo-'+ID,null);});}
/* Past imports & undo */
let pastFilter='all',pastCur=0,pastNav=null;
function rPast(m){const p=el('section',{class:'panel4o'});p.append(el('h2',{tabindex:'-1','data-k':'h-past'},'Past imports & undo'));
 const kind=x=>x.s==='Published'?'lib':x.s==='Undone'?'undone':/failed|attention/.test(x.s)?'fail':'busy';
 const rows=[...(D.past||[]).map(x=>({...x,k:kind(x),what:plural(x.n,'item')+(x.rp?', '+x.rp+' replaced':'')})),
  ...(D.history||[]).filter(h=>!(D.past||[]).some(x=>x.id===h.id)&&!['Published','Undone'].includes(h.s)).map(h=>({id:h.id,s:h.s,when:lw(h),has:true,k:'open',what:(h.src||'Import '+h.id.slice(0,8))+' \u00B7 '+h.n+' of '+h.total+' chosen'}))];
 const fs=[['all','All'],['lib','In the library'],['undone','Undone'],['fail','Did not publish'],['open','Not finished']];
 const fr=el('div',{class:'filters4o',role:'radiogroup','aria-label':'Show'});
 fs.forEach(([k,t],i)=>{const b=el('button',{role:'radio','aria-checked':String(pastFilter===k),'data-k':'filter-'+k,tabindex:pastFilter===k?'0':'-1',onclick:()=>{pastFilter=k;pastCur=0;render('filter-'+k);}},t);
  b.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowLeft'){const j=(i+(e.key==='ArrowRight'?1:fs.length-1))%fs.length;pastFilter=fs[j][0];pastCur=0;render('filter-'+fs[j][0]);e.preventDefault();e.stopPropagation();}});fr.append(b);});
 p.append(fr);const list=rows.filter(x=>pastFilter==='all'||x.k===pastFilter);if(pastCur>=list.length)pastCur=0;
 if(!list.length)p.append(el('p',{class:'muted'},'Nothing here.'));
 const ul=el('ul',{class:'past4o','aria-label':'Past imports'});
 list.forEach((x,i)=>{const open=()=>{if(x.has)location.href='?id='+x.id;};const undo=()=>{if(x.k==='lib'&&x.has)location.href='?id='+x.id+'#undo';};
  const li=el('li',{tabindex:i===pastCur?'0':'-1','data-k':'past-'+i,'aria-label':x.when+', '+x.what+', '+x.s},
   el('div',{},el('div',{style:'font-weight:600'},x.what),el('div',{class:'muted'},x.when),el('div',{class:'state4o '+x.k},x.s)),
   x.k==='lib'&&x.has?el('button',{tabindex:'-1',onclick:undo},'Undo ',kbd('U')):x.k==='lib'?el('span',{class:'muted'},'Undo on the Beelink: Undo-Publish.ps1 -BatchId '+x.id.slice(0,8)):x.has?el('button',{tabindex:'-1',onclick:open},'Open ',kbd('Enter')):el('span'));
  if(i===pastCur)pastNav={fs,n:list.length,open,undo};
  li.addEventListener('keydown',e=>{const k=e.key;if(k==='ArrowDown'||k==='ArrowUp'||k==='Home'||k==='End'){const j=k==='Home'?0:k==='End'?list.length-1:Math.max(0,Math.min(list.length-1,i+(k==='ArrowDown'?1:-1)));pastCur=j;render('past-'+j);e.preventDefault();e.stopPropagation();}
   else if(k==='u'||k==='U'){e.preventDefault();e.stopPropagation();undo();}else if(k==='Enter'){e.preventDefault();e.stopPropagation();open();}});ul.append(li);});
 if(!list.length)pastNav={fs,n:0,open:()=>{},undo:()=>{}};
 p.append(ul,el('p',{class:'muted'},'Undo takes an import out of the library and keeps the files in the recovery folder. Undone imports are not counted as \u201Calready in the library\u201D.'),
  el('button',{'data-k':'backcur',onclick:goCurrent},'\u2190 Back to the current import ',kbd('Esc')));m.append(p);}
/* ---------- hints ---------- */
function hints(){let h=[];const s=curScreen;
 if(s==='past')h=[['\u2190 \u2192','filter'],['Tab','to the list'],['\u2191 \u2193','move in the list'],['U','undo the import'],['Enter','open'],['Esc','back']];
 else if(s==='start')h=[['B','browse for a folder'],D.srcPicked?['Enter','look inside it']:null,['Tab','next button'],['H','past imports'],['?','all shortcuts']];
 else if(s==='found')h=[['N','continue'],['B','choose another folder'],['H','past imports'],['?','all shortcuts']];
 else if(s==='list')h=[['\u2191 \u2193','move in list'],['Space','select'],['A','select all/none'],['M','move to\u2026'],['C','new category'],['X','include/skip'],['R','rename'],['D','details'],['N','next step'],['S','save'],['?','more']];
 else if(s==='check')h=[['Tab','next button'],['Enter','press it'],['B','back to change'],['H','past imports']];
 else h=(D.pq||{}).state==='published'?(P4.done?[['N','start a new import'],['C','category links'],['B','PDF books'],['L','left out'],['U','undo this import'],['H','past imports']]:[['U','undo this import'],['H','past imports'],['Tab','next button']]):[['Tab','next button'],['Enter','press it'],['H','past imports']];
 hintsBar.replaceChildren(...h.filter(Boolean).map(([k,t])=>el('span',{},kbd(k),' ',t)));}
/* ---------- keyboard ---------- */
document.addEventListener('keydown',e=>{
 if(modal.firstChild)return;const t=e.target,tag=t.tagName||'';const typing=tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT'||t.isContentEditable;
 if((classic.open&&classic.contains(t))||$$('.open4o',root).some(b=>b.contains(t)&&t!==b)){if(e.key==='Escape'&&!typing){const li=t.closest&&t.closest('li.item4o');if(li){S.open.delete(li.dataset.id);rerender(li.dataset.k);e.preventDefault();}}return;}
 if(e.key==='Escape'){if(curScreen==='past'){goCurrent();return;}if(curScreen==='list'&&S.open.size){S.open.clear();rerender('row-'+S.cur);e.preventDefault();return;}if(S.sel.size){S.sel.clear();rerender('row-'+S.cur);toast('Selection cleared');return;}if(typing)t.blur();return;}
 if(typing||e.ctrlKey||e.altKey||e.metaKey)return;
 const k=e.key.length===1?e.key.toLowerCase():e.key;const s=curScreen;
 if(k==='?'){help();e.preventDefault();return;}
 if(k==='h'){pastView?goCurrent():goPast();e.preventDefault();return;}
 if(s==='past'&&pastNav){const P=pastNav;
  if(k==='ArrowRight'||k==='ArrowLeft'){const i=P.fs.findIndex(f=>f[0]===pastFilter);const j=(i+(k==='ArrowRight'?1:P.fs.length-1))%P.fs.length;pastFilter=P.fs[j][0];pastCur=0;render('filter-'+pastFilter);e.preventDefault();return;}
  if(['ArrowDown','ArrowUp','Home','End'].includes(k)&&P.n){render('past-'+pastCur);e.preventDefault();return;}
  if(k==='u'&&P.n){P.undo();e.preventDefault();return;}
  if(k==='Enter'&&P.n&&!(t.closest&&t.closest('button,a,summary'))){P.open();e.preventDefault();return;}
  return;}
 if(s==='start'){if(k==='b'){const pb=$('#pick-btn');if(pb){pb.click();e.preventDefault();}return;}
  if(k==='Enter'&&D.srcPicked&&!(t.closest&&t.closest('button,a,summary'))){const st=$('#start');if(st){st.click();e.preventDefault();}}return;}
 if(s==='found'){if(k==='n'){startList();e.preventDefault();}else if(k==='b'){backToStart();e.preventDefault();}return;}
 if(s==='check'){if(k==='b'){$('[data-k=back3]',root)?.click();e.preventDefault();}return;}
 if(s==='status'){if(k==='u'){askUndo();e.preventDefault();}else if(p4Keys(k))e.preventDefault();return;}
 if(s!=='list')return;
 if(k==='n'){next();e.preventDefault();return;}
 if(k==='b'){backToStart();e.preventDefault();return;}
 if(k==='s'){saveNow();e.preventDefault();return;}
 if(k==='a'){toggleAll();e.preventDefault();return;}
 if(k==='c'){newCat(S.sel.size>0||!!(t.closest&&t.closest('li.item4o')));e.preventDefault();return;}
 if(k==='m'){startMove();e.preventDefault();return;}
 const inRow=t.closest&&t.closest('li.item4o');const r=items[S.cur];if(!r)return;
 if(inRow){
  if(['ArrowDown','ArrowUp','Home','End','PageDown','PageUp'].includes(k)){const n=items.length;S.cur=k==='Home'?0:k==='End'?n-1:Math.max(0,Math.min(n-1,S.cur+({ArrowDown:1,ArrowUp:-1,PageDown:8,PageUp:-8}[k])));rerender('row-'+S.cur);e.preventDefault();return;}
  if(k===' '){toggleSel(r);e.preventDefault();return;}
  if(k==='x'){toggleInc(r);e.preventDefault();return;}
  if(k==='r'){S.renaming=r.dataset.id;rerender('rename-'+S.cur);const ti=$('.rename4o',root);if(ti)ti.select();e.preventDefault();return;}
  if(k==='d'||k==='Enter'){const id=r.dataset.id;S.open.has(id)?S.open.delete(id):S.open.add(id);rerender('row-'+S.cur);e.preventDefault();return;}
 } else if(['r','x','d',' ','ArrowDown','ArrowUp'].includes(k)&&!(t.closest&&t.closest('button,a,summary'))){const li=$('[data-k="row-'+S.cur+'"]',root);if(li){li.focus();if(k.length===1&&k!==' ')toast('Use '+k.toUpperCase()+' on an item in the list');}e.preventDefault();}
});
if(SS('edutek-4o-after-save')==='start'&&C&&!C.isDirty()&&D.state==='draft'){SS('edutek-4o-after-save',null);location.href='/bulk-import-guided.php';return;}
const H=location.hash;
if(H==='#undo')history.replaceState(history.state,'',location.pathname+location.search);
if(location.hash==='#start'&&curScreen!=='start')history.replaceState(history.state,'',location.pathname+location.search);
curScreen=screen();
render(curScreen==='list'?'row-0':curScreen==='past'?(pastNav&&pastNav.n?'past-0':'filter-all'):(curScreen==='status'&&(D.pq||{}).state==='published'&&P4.done?'again':(curScreen==='start'&&D.srcPicked?'scan':undefined)));
const fixFocus=()=>{const a=document.activeElement;if(a&&a!==document.body&&classic.contains(a)&&!classic.open){const f=$('[data-k="row-'+(S.cur||0)+'"]',root)||$('h2',root);if(f)f.focus();}};
window.addEventListener('load',()=>{fixFocus();setTimeout(fixFocus,50);});setTimeout(fixFocus,0);
if(curScreen==='list'&&D.screen==='fix'){const b=blockers();const ids=new Set((D.issues||[]).map(i=>i.id));const f=items.findIndex(r=>ids.has(r.dataset.id))>=0?items.findIndex(r=>ids.has(r.dataset.id)):b.length?items.indexOf(b[0]):0;S.cur=f;rerender('row-'+f);}
const q=D.pq||{};
if(curScreen==='status'&&q.state==='published'){if(q.planOk&&(SS('edutek-4o-undo-'+ID)==='1'||H==='#undo'))setTimeout(confirmUndo,60);else if(H==='#undo'&&!q.planReq&&q.undo!=='planning')setTimeout(askUndo,60);}
})();
</script></body></html>
