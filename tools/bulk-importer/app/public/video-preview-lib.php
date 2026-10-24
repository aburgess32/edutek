<?php
declare(strict_types=1);
const PV_ROOT='/sandbox-data/video-previews-v1';
// Patch 4d: any folder on the computer or an external drive, read-only. Docker Desktop's /run/desktop/mnt/host is bound read-only at /host.
const HV_ROOT='/host';const HV_MOUNTINFO='/proc/self/mountinfo';const HV_DRIVES_JSON='/sandbox-data/host-drives.json';const HD_QUEUE='/sandbox-data/drive-queue';
function hv_drives(): array {$d=[];foreach((@file(HV_MOUNTINFO,FILE_IGNORE_NEW_LINES)?:[]) as $l){$f=explode(' ',(string)$l);if(isset($f[4])&&preg_match('#^'.preg_quote(HV_ROOT,'#').'/([a-z])$#D',$f[4],$m)&&is_dir(HV_ROOT.'/'.$m[1])&&!is_link(HV_ROOT.'/'.$m[1]))$d[$m[1]]=true;}ksort($d);return array_keys($d);}
// data\host-drives.json is written by Watch-ImportDrives.ps1 (the drive watcher task) on the Beelink.
function hv_hostinfo_raw(): array {if(is_file(HV_DRIVES_JSON)&&!is_link(HV_DRIVES_JSON)&&filesize(HV_DRIVES_JSON)<65536){$j=json_decode((string)file_get_contents(HV_DRIVES_JSON),true);if(is_array($j))return $j;}return [];}
function hv_hostinfo(): array {$o=[];$j=hv_hostinfo_raw();foreach((is_array($j['drives']??null)?$j['drives']:[]) as $e){if(!is_array($e))continue;$l=strtolower((string)($e['letter']??''));if(preg_match('/^[a-z]$/D',$l))$o[$l]=$e;}return $o;}
// USB drives (removable, or external disks on the USB bus) are connected and ejected by the watcher; other drives count as fixed.
function hv_fixed(string $l): bool {if($l==='c'||$l==='d')return true;$i=hv_hostinfo();return empty($i[$l]['auto'])&&strcasecmp((string)($i[$l]['type']??''),'Removable')!==0;}
function hv_rel(string $p): string {if(!str_starts_with($p,HV_ROOT.'/'))throw new RuntimeException('Not a drive folder');return substr($p,strlen(HV_ROOT)+1);}
function hv_win(string $p): string {$r=hv_rel($p);$x=explode('/',$r,2);return strtoupper($x[0]).':'.chr(92).str_replace('/',chr(92),$x[1]??'');}
// Returns why a folder cannot be used or opened, or null. Name rules are used because Windows hidden/system attributes are not visible to the container.
function hv_blocked(string $p): ?string {
 $parts=explode('/',hv_rel($p));$l=strtolower(array_shift($parts));$c=array_map('strtolower',$parts);
 if(!$c)return hv_fixed($l)?'Choose a folder inside this drive, not the whole drive.':null;
 foreach($c as $x){if($x===''||$x[0]==='.'||$x[0]==='$')return 'Hidden or system folder.';if(in_array($x,['system volume information','recovery','config.msi','msocache','perflogs','windowsapps','found.000'],true))return 'Windows system folder.';}
 if($l==='c'){
  if(in_array($c[0],['windows','windows.old','program files','program files (x86)','programdata','boot','efi','intel','amd','nvidia','onedrivetemp','xampp'],true))return 'Windows or program folder.';
  if($c[0]==='users'&&isset($c[1])){if(in_array($c[1],['default','default user','all users'],true))return 'Windows system folder.';if(isset($c[2])&&$c[2]==='appdata')return 'Hidden app-data folder.';if(isset($c[3])&&$c[2]==='desktop'&&$c[3]==='edutek import test')return 'Importer test destination, not a source.';}
 }
 if($l==='d'){if($c[0]==='edutek-system')return 'Edutek system folder (importer data, backups and archives).';if($c[0]==='edutek')return 'Edutek app folder.';if($c[0]==='xampp')return 'Web server folder that holds the Edutek library (D:'.chr(92).'xampp'.chr(92).'htdocs'.chr(92).'Edutek'.chr(92).'videos).';}
 return null;
}
// Resolves "c/Users/Edutek/Desktop" to /host/c/Users/Edutek/Desktop. Rejects dot parts, backslashes, links/junctions and anything outside a connected drive.
function hv_resolve(string $rel): string {
 if($rel===''||strlen($rel)>1024||str_contains($rel,chr(92))||str_contains($rel,chr(0)))throw new RuntimeException('Invalid folder');
 $parts=explode('/',rtrim($rel,'/'));$l=strtolower(array_shift($parts));if(!in_array($l,hv_drives(),true))throw new RuntimeException('That drive is not connected to the importer. Run Refresh-ImportDrives.ps1, then choose Refresh drives');
 $p=HV_ROOT.'/'.$l;foreach($parts as $c){if($c===''||$c==='.'||$c==='..'||strlen($c)>255)throw new RuntimeException('Invalid folder');$p.='/'.$c;if(is_link($p))throw new RuntimeException('Shortcuts and links cannot be opened or used as a source');}
 $real=realpath($p);if($real===false||$real!==$p||!is_dir($real))throw new RuntimeException('Folder not found');return $real;
}
function hv_valid_root(string $root): string {$p=hv_resolve(hv_rel($root));$b=hv_blocked($p);if($b!==null)throw new RuntimeException('That folder cannot be used as a source: '.rtrim($b,'.'));return $p;}
// Lists subfolders: [folders (name, rel, blocked reason), hidden count, direct PDF/MP4 count, truncated].
function hv_list(string $p): array {$dirs=[];$hidden=0;$files=0;$trunc=false;$names=@scandir($p);if($names===false)throw new RuntimeException('This folder cannot be read');
 foreach($names as $n){if($n==='.'||$n==='..')continue;$q=$p.'/'.$n;if(is_link($q)){$hidden++;continue;}if(is_dir($q)){if($n[0]==='.'||$n[0]==='$'){$hidden++;continue;}if(count($dirs)>=400){$trunc=true;continue;}$dirs[]=['name'=>$n,'rel'=>hv_rel($q),'blocked'=>hv_blocked($q)];}elseif($n[0]!=='.'&&$n[0]!=='$'&&preg_match('/\.(pdf|mp4)$/iD',$n))$files++;}
 usort($dirs,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return [$dirs,$hidden,$files,$trunc];}
// Patch 4d drive requests: the page writes data\drive-queue\<action>-<drive>.request.json; the watcher handles it within a second or two.
function hd_dir(): string {if(is_link(HD_QUEUE))throw new RuntimeException('Drive queue link rejected');if(!is_dir(HD_QUEUE)&&!@mkdir(HD_QUEUE,0770,true)&&!is_dir(HD_QUEUE))throw new RuntimeException('Drive queue unavailable');return HD_QUEUE;}
function hd_request(string $act,string $l): void {
 if(!in_array($act,['refresh','eject','connect'],true))throw new RuntimeException('Unknown drive action');
 if($act==='refresh')$l='all';elseif(!preg_match('/^[a-z]$/D',$l)||hv_fixed($l))throw new RuntimeException('Only USB drives can be ejected or connected');
 $p=hd_dir().'/'.$act.'-'.$l.'.request.json';$t=$p.'.'.bin2hex(random_bytes(6)).'.tmp';
 if(file_put_contents($t,json_encode(['action'=>$act,'drive'=>$l,'requested_at'=>gmdate(DATE_ATOM)],JSON_UNESCAPED_SLASHES))===false||!rename($t,$p))throw new RuntimeException('Could not send the drive request');
}
function hd_status(): array {
 $hb=@filemtime(HD_QUEUE.'/heartbeat.txt');$pending=[];foreach(glob(HD_QUEUE.'/*.request.json')?:[] as $f)$pending[]=basename($f,'.request.json');
 $last=null;$lp=HD_QUEUE.'/last-result.json';if(is_file($lp)&&!is_link($lp)&&filesize($lp)<65536){$last=json_decode((string)file_get_contents($lp),true);if(!is_array($last))$last=null;}
 $j=hv_hostinfo_raw();return ['watcher_age'=>$hb?max(0,time()-$hb):null,'pending'=>$pending,'last'=>$last,'docker_ready'=>$j['docker_ready']??null,'written'=>$j['written']??null];
}
// While a scan reads a USB drive, a busy marker tells the watcher to refuse "Prepare to eject" for that drive.
function hd_busy(string $root): void {$l=explode('/',hv_rel($root))[0];if(hv_fixed($l))return;$p=hd_dir().'/busy-'.$l.'-'.bin2hex(random_bytes(6)).'.json';if(@file_put_contents($p,json_encode(['drive'=>$l,'at'=>gmdate(DATE_ATOM)]))!==false)register_shutdown_function(function()use($p){@unlink($p);});}
// Patch 4e: native Windows folder window. The page writes data/folder-pick/request.json (a random id and the current
// folder as drive/path text, never a command). The drive watcher starts Pick-ImportFolder.ps1 on the Beelink desktop,
// which shows the folder window and writes data/folder-pick/result.json. The chosen folder is checked here with the
// same rules as every other source (hv_valid_root), so a protected folder is refused even if the window allowed it.
const HP_DIR='/sandbox-data/folder-pick';
function hp_dir(): string {if(is_link(HP_DIR))throw new RuntimeException('Folder window queue link rejected');if(!is_dir(HP_DIR)&&!@mkdir(HP_DIR,0770,true)&&!is_dir(HP_DIR))throw new RuntimeException('Folder window queue unavailable');return HP_DIR;}
function hp_pending(): ?string {$d=hp_dir();foreach(['open.json'=>330,'request.json'=>20] as $n=>$max){$p=$d.'/'.$n;clearstatcache(true,$p);if(is_file($p)&&!is_link($p)&&time()-(int)@filemtime($p)<$max)return $n==='open.json'?'open':'waiting';}return null;}
function hp_request(string $start): string {
 $d=hp_dir();$lk=fopen($d.'/pick.lock','c');if(!$lk||!flock($lk,LOCK_EX))throw new RuntimeException('Folder window queue busy');
 try{
  $pend=hp_pending();if($pend==='open')throw new RuntimeException('A folder window is already open on this computer. Choose a folder there or press Esc to close it, then try again');
  if($pend==='waiting')throw new RuntimeException('The folder window is still opening. Wait a moment, then try again');
  if(strlen($start)>1024||!preg_match('#^[a-z](/[^/\x00-\x1f\\\\:*?"<>|]{1,255}){0,40}$#D',$start))$start='';
  $id=bin2hex(random_bytes(16));@unlink($d.'/result.json');$p=$d.'/request.json';$t=$p.'.'.bin2hex(random_bytes(6)).'.tmp';
  if(@file_put_contents($t,json_encode(['id'=>$id,'start'=>$start,'requested_at'=>gmdate(DATE_ATOM)],JSON_UNESCAPED_SLASHES))===false||!@rename($t,$p)){@unlink($t);throw new RuntimeException('Could not ask for the folder window');}
  return $id;
 }finally{flock($lk,LOCK_UN);fclose($lk);}
}
// Typed fallback and window answers: "D:\Media\Videos" -> "d/Media/Videos" (then hv_valid_root decides).
function hp_rel_from_win(string $w): string {
 $w=trim(trim($w),'"');if(strlen($w)>1024||!preg_match('#^([A-Za-z]):(?:[\\\\/](.*))?$#Ds',$w,$m))throw new RuntimeException('Type a folder path that starts with a drive letter, for example D:\\Media\\Videos');
 $rest=trim(str_replace(chr(92),'/',$m[2]??''),'/');return strtolower($m[1]).($rest!==''?'/'.$rest:'');
}
function hp_status(string $id): array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))return ['state'=>'error','message'=>'Invalid request'];
 $d=hp_dir();$r=$d.'/result.json';clearstatcache(true,$r);
 if(is_file($r)&&!is_link($r)&&filesize($r)<8192){$j=json_decode((string)file_get_contents($r),true);
  if(is_array($j)&&hash_equals($id,(string)($j['id']??''))){$s=(string)($j['state']??'');
   if($s==='picked'){$win=(string)($j['path']??'');try{$p=hv_valid_root(HV_ROOT.'/'.hp_rel_from_win($win));return ['state'=>'picked','src'=>'h:'.hv_rel($p),'label'=>hv_win($p)];}catch(Throwable $e){return ['state'=>'refused','path'=>$win,'message'=>rtrim($e->getMessage(),'.').'.'];}}
   if($s==='cancelled')return ['state'=>'cancelled'];
   if($s==='timeout'||$s==='expired')return ['state'=>'timeout'];
   return ['state'=>'error','message'=>rtrim((string)($j['message']??'The folder window could not be opened'),'.').'.'];}}
 $hb=@filemtime(HD_QUEUE.'/heartbeat.txt');return ['state'=>hp_pending()??'gone','watcher_age'=>$hb?max(0,time()-$hb):null];
}
function pv_dirs(): void {foreach(['','/jobs','/images','/status'] as $sub){$p=PV_ROOT.$sub;if(is_link($p))throw new RuntimeException('Preview storage link rejected');if(!is_dir($p)&&!@mkdir($p,0770,true)&&!is_dir($p))throw new RuntimeException('Preview storage unavailable');}}
function pv_json(string $p,array $v): void {$t=$p.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($t,json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))===false||!rename($t,$p))throw new RuntimeException('Preview state write failed');}
function pv_source(array $r): string {
 $rel=(string)$r['source'];if($rel===''||str_contains($rel,'\\')||str_starts_with($rel,'/'))throw new RuntimeException('Invalid video source');
 $root=(string)($r['src_root']??'/import-source');if(str_starts_with($root,HV_ROOT.'/'))$root=hv_valid_root($root);elseif($root!=='/import-source'&&!preg_match('#^/import-sources/(?!\.\.?$)[^/]+$#D',$root))throw new RuntimeException('Invalid source root');if(is_link($root))throw new RuntimeException('Video link rejected');$p=$root;foreach(explode('/',$rel) as $part){if($part===''||$part==='.'||$part==='..')throw new RuntimeException('Invalid video source');$p.='/'.$part;if(is_link($p))throw new RuntimeException('Video link rejected');}
 if(str_starts_with($root,HV_ROOT.'/')&&hv_blocked(dirname($p))!==null)throw new RuntimeException('Video is in a protected folder');
 $real=realpath($p);if($real===false||!str_starts_with($real,$root.'/')||!is_file($real))throw new RuntimeException('Video unavailable');
 clearstatcache(true,$real);if(filesize($real)!==$r['bytes']||filemtime($real)!==$r['mtime'])throw new RuntimeException('Source changed. Start a new import.');return $real;
}
function pv_key(array $r): string {return hash('sha256',json_encode(['v'=>1,'source'=>$r['source'],'bytes'=>$r['bytes'],'mtime'=>$r['mtime'],'seconds'=>5,'width'=>480]+(($r['src_root']??'/import-source')!=='/import-source'?['root'=>(string)$r['src_root']]:[])+(($r['type']??'')==='Books'?['kind'=>'pdf1']:[]),JSON_THROW_ON_ERROR));}
function pv_path(string $key): string {if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Invalid preview key');$p=PV_ROOT.'/images/'.$key.'.jpg';if(is_link($p))throw new RuntimeException('Preview link rejected');return $p;}
function pv_info(array $r,bool $queue=false): array {
 pv_dirs();$key=pv_key($r);$image=pv_path($key);$statusPath=PV_ROOT.'/status/'.$key.'.json';$status=[];
 if(is_file($statusPath)){$status=json_decode((string)file_get_contents($statusPath),true)?:[];}
 if(($status['state']??'')==='ready'&&is_file($image)&&filesize($image)>0)return $status+['key'=>$key];
 if(($status['state']??'')==='failed')return $status+['key'=>$key];
 if($queue){pv_source($r);$job=PV_ROOT.'/jobs/'.$key.'.json';$lock=fopen($job.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock preview queue');
  try{if(!is_file($job))pv_json($job,['key'=>$key,'source'=>$r['source'],'src_root'=>(string)($r['src_root']??'/import-source'),'type'=>(string)($r['type']??'Videos'),'bytes'=>$r['bytes'],'mtime'=>$r['mtime'],'queued'=>microtime(true)]);}
  finally{flock($lock,LOCK_UN);fclose($lock);}
 }

 return ['state'=>($status['state']??'queued'),'key'=>$key];
}
function pv_input(array $r): string {
 if(empty($r['generated_key']))return pv_source($r);
 $p=pv_path((string)$r['generated_key']);clearstatcache(true,$p);
 if(!is_file($p)||empty($r['asset_sha256'])||!hash_equals($r['asset_sha256'],hash_file('sha256',$p)))throw new RuntimeException('Generated artwork changed or missing. Review selection again.');return $p;
}
