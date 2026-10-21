<?php
declare(strict_types=1);
const PV_ROOT='/sandbox-data/video-previews-v1';
function pv_dirs(): void {foreach(['','/jobs','/images','/status'] as $sub){$p=PV_ROOT.$sub;if(is_link($p))throw new RuntimeException('Preview storage link rejected');if(!is_dir($p)&&!@mkdir($p,0770,true)&&!is_dir($p))throw new RuntimeException('Preview storage unavailable');}}
function pv_json(string $p,array $v): void {$t=$p.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($t,json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))===false||!rename($t,$p))throw new RuntimeException('Preview state write failed');}
function pv_source(array $r): string {
 $rel=(string)$r['source'];if($rel===''||str_contains($rel,'\\')||str_starts_with($rel,'/'))throw new RuntimeException('Invalid video source');
 $root=(string)($r['src_root']??'/import-source');if($root!=='/import-source'&&!preg_match('#^/import-sources/(?!\.\.?$)[^/]+$#D',$root))throw new RuntimeException('Invalid source root');if(is_link($root))throw new RuntimeException('Video link rejected');$p=$root;foreach(explode('/',$rel) as $part){if($part===''||$part==='.'||$part==='..')throw new RuntimeException('Invalid video source');$p.='/'.$part;if(is_link($p))throw new RuntimeException('Video link rejected');}
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
