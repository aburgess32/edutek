<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/video-preview-lib.php';
pv_dirs();$lock=fopen(PV_ROOT.'/worker.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))exit;
function run_frame(string $source,string $output,float $seconds): array {
 $cmd=strtolower(pathinfo($source,PATHINFO_EXTENSION))==='pdf'?['/usr/bin/pdftoppm','-f','1','-l','1','-jpeg','-scale-to-x','480','-scale-to-y','-1','-singlefile',$source,substr($output,0,-4)]:['ffmpeg','-nostdin','-hide_banner','-loglevel','error','-ss',(string)$seconds,'-threads','1','-protocol_whitelist','file','-i',$source,'-map','0:v:0','-an','-sn','-dn','-frames:v','1','-vf','scale=480:-2','-filter_threads','1','-threads','1','-q:v','3','-f','image2','-update','1','-y',$output];
 $pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot launch FFmpeg');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$start=microtime(true);$error='';$code=-1;
 while(true){$error=substr($error.(string)stream_get_contents($pipes[2]),-8192);stream_get_contents($pipes[1]);$status=proc_get_status($process);file_put_contents(PV_ROOT.'/heartbeat.txt',gmdate(DATE_ATOM));if(!$status['running']){$code=$status['exitcode'];break;}if(microtime(true)-$start>25){proc_terminate($process);usleep(200000);$status=proc_get_status($process);if($status['running'])proc_terminate($process,9);$error='Preview extraction timed out';break;}usleep(100000);}
 $error=substr($error.(string)stream_get_contents($pipes[2]),-8192);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);if($code<0&&$closed>=0)$code=$closed;
 return ['code'=>$code,'error'=>$error];
}
while(true){
 file_put_contents(PV_ROOT.'/heartbeat.txt',gmdate(DATE_ATOM));$jobs=glob(PV_ROOT.'/jobs/*.json')?:[];usort($jobs,fn($a,$b)=>filemtime($a)<=>filemtime($b));
 foreach($jobs as $job){$key=basename($job,'.json');if(!preg_match('/^[a-f0-9]{64}$/D',$key))continue;$temp=null;
  try{
   $r=json_decode((string)file_get_contents($job),true,512,JSON_THROW_ON_ERROR);if(!hash_equals($key,pv_key($r)))throw new RuntimeException('Preview job mismatch');
   $source=pv_source($r);$final=pv_path($key);$info=pv_info($r,false);if($info['state']==='ready'){@unlink($job);continue;}
   pv_json(PV_ROOT.'/status/'.$key.'.json',['state'=>'working','key'=>$key]);$error='';$captured=null;
   foreach([5.0,0.0] as $seconds){
    $temp=PV_ROOT.'/images/'.$key.'.'.bin2hex(random_bytes(6)).'.part.jpg';$result=run_frame($source,$temp,$seconds);$size=@getimagesize($temp);
    if($result['code']===0&&$size&&($size['mime']??'')==='image/jpeg'&&filesize($temp)>0){$captured=$seconds;break;}
    $error=$result['error']?:'No readable frame at this timestamp';@unlink($temp);$temp=null;
   }
   if($captured===null)throw new RuntimeException($error);pv_source($r);
   if(!rename($temp,$final))throw new RuntimeException('Cannot publish generated preview');$temp=null;
   pv_json(PV_ROOT.'/status/'.$key.'.json',['state'=>'ready','key'=>$key,'seconds'=>$captured,'sha256'=>hash_file('sha256',$final),'created'=>gmdate(DATE_ATOM)]);
  }catch(Throwable $e){if($temp&&is_file($temp))@unlink($temp);pv_json(PV_ROOT.'/status/'.$key.'.json',['state'=>'failed','key'=>$key,'message'=>'Could not create a preview. The original is unchanged.','detail'=>substr($e->getMessage(),0,2000),'created'=>gmdate(DATE_ATOM)]);error_log('Preview '.$key.': '.$e->getMessage());}
  @unlink($job);
 }
 sleep(1);
}
