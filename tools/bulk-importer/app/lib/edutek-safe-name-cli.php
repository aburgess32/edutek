<?php
/* edutek_safe_name v1 command line, for Publish-Batch.ps1 (runs in the importer web container).
   php edutek-safe-name-cli.php check <file.json>
     input  {"names":[{"name":"...","kind":"file"|"folder"}, ...]}  (UTF-8)
     output {"rule":"edutek_safe_name v1","results":[{"name":..,"clean":..,"ok":true|false,"review":[..]}]}  ASCII-only JSON
   Exit code 0 when the input was read, 2 on bad input. It never writes or changes files. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/edutek-safe-name.php';
$cmd=$argv[1]??'';$file=$argv[2]??'';
if($cmd!=='check'||$file===''||!is_file($file)){fwrite(STDERR,"usage: check <file.json>\n");exit(2);}
$raw=(string)file_get_contents($file);if(str_starts_with($raw,"\xEF\xBB\xBF"))$raw=substr($raw,3);
$j=json_decode($raw,true);if(!is_array($j)||!is_array($j['names']??null)){fwrite(STDERR,"bad json\n");exit(2);}
$out=[];
foreach($j['names'] as $n){$name=(string)($n['name']??'');$isFile=($n['kind']??'file')!=='folder';
 try{$i=sn_info($name,$isFile);$out[]=['name'=>$name,'clean'=>$i['clean'],'ok'=>$i['clean']===$name,'review'=>$i['review']];}
 catch(Throwable $e){$out[]=['name'=>$name,'clean'=>'','ok'=>false,'review'=>[$e->getMessage()]];}}
echo json_encode(['rule'=>SN_RULE,'results'=>$out],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),"\n";
