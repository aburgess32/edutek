<?php
/* Patch 4p end-to-end test = the Patch 4o test (v3) with the 4p rules: a cut-short video is damaged and never ticked (HEVC truncated.mp4);
   the 4i fallback is still covered by HEVC zero-filled.mp4 (make-4p-clips.sh: complete file, broken picture data, conversion fails ->
   the original is copied); a book gets no shelf from its folder, so the test chooses "E2E Shelf" for each book; + section 19 (a copy of
   Import Big Test with the 4p extra files: left-out list, damaged video, shelf check, Next blocked without a shelf, new places, Done summary).
   Patch 4o end-to-end test (v3) = + section 18 (audio: MP3 conversion at import, damaged audio flagged and never published).
   the Patch 4n test (unchanged steps) + every complete importer page it loads is also checked for the 4o
   keyboard-first screens (4o marker, valid screen data, classic page still inside) + section 17 (screen data matches the import).
   Patch 4n end-to-end test = the Patch 4m test + section 16 (Recently imported follows the publish state and the library; uses fake
   publish-status files in the THROWAWAY data folder only, removed again at the end).
   Patch 4m end-to-end test = the Patch 4l test + section 15 (every page loads completely: start page, staged and published imports).
   P4M_MODE=nolegacy: a second throwaway importer WITHOUT the old Desktop Videos / Import Sources folders and with a COPY of import P4M_EC5
   (read from the live data, never written back): pages load completely, ?src=legacy and Scan explain, Browse (h:) still scans (P4M_HSRC).
   Optional P4M_OLD=<page file>: the previous page (4l) must show the line-1507 crash on the same copy (reproduction).
   Patch 4k end-to-end test (= the Patch 4j test, with categories from the library folders, New category…, and no automatic Unsorted).
   Library categories: the top-level folders of P4K_LIB (default /host/d/xampp/htdocs/Edutek/videos, a throwaway copy of the folder NAMES only);
   the expected list is P4K_LIB_NAMES (default /p4l/lib-names.txt, one name per line, written by the installer from the real library) if it exists. Drives a THROWAWAY importer over HTTP like the browser does, then checks the staged files.
   php e2e-p4l.php <base url> <REAL_ROOT of that importer> [Host header]
   Never point it at production: it creates and stages a batch (nothing is published). */
declare(strict_types=1);
[$self,$base,$real,$host]=$argv+[null,null,null,'localhost:8092'];if(!$base||!$real){fwrite(STDERR,"usage\n");exit(2);}
$fail=0;$pass=0;$cookie='';
function ok(bool $c,string $what,string $got=''){global $fail,$pass;if($c){$pass++;echo "ok   $what\n";}else{$fail++;echo "FAIL $what".($got!==''?"  got: $got":'')."\n";}}
function http(string $method,string $path,array $post=[],array $hdr=[]): array {global $base,$host,$cookie;
 $h=["Host: $host"];if($cookie!=='')$h[]="Cookie: $cookie";foreach($hdr as $x)$h[]=$x;$body='';if($method==='POST'){$body=http_build_query($post);$h[]='Content-Type: application/x-www-form-urlencoded';}
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$h),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>600]]);
 $b=@file_get_contents($base.$path,false,$ctx);$rh=$http_response_header??[];$code=0;$hd=[];
 foreach($rh as $l){if(preg_match('#^HTTP/\S+ (\d+)#',$l,$m))$code=(int)$m[1];elseif(($p=strpos($l,':'))!==false)$hd[strtolower(trim(substr($l,0,$p)))]=trim(substr($l,$p+1));
  if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$l,$m))$cookie=$m[1];}
 p4o_page((string)$b);return [$code,$hd,(string)$b];}
$P4O_N=0;$P4O_BAD=[];
function p4o_data(string $b): ?array {if(!preg_match('#<script type="application/json" id="ui4o-data">(.*?)</script>#s',$b,$m))return null;$j=json_decode($m[1],true);return is_array($j)?$j:null;}
function p4o_page(string $b): void {global $P4O_N,$P4O_BAD;$t=rtrim($b);if(!str_ends_with($t,'</html>')||!str_contains($b,'id="src-key"'))return;$P4O_N++;
 $j=p4o_data($b);$why=[];if(!str_contains($b,'/* Patch 4o */'))$why[]='4o styles';if(substr_count($b,'id="ui4o-data"')!==1)$why[]='data count';if(!$j||($j['v']??0)!==1)$why[]='data '.substr((string)($j['err']??'invalid'),0,80);
 if(!str_contains($b,'window.EdutekClassic={'))$why[]='classic export';if(!str_contains($b,'window.EDU4O_ON=true;'))$why[]='screen script';if($why)$P4O_BAD[]=implode(',',$why);}
function p4o_sum(): void {global $P4O_N,$P4O_BAD;ok($P4O_N>0&&!$P4O_BAD,"4o: all $P4O_N complete importer pages loaded in this test carry the keyboard-first screens with valid data",implode(' | ',array_slice($P4O_BAD,0,3)));}
function csrf(string $html): string {preg_match('/name="csrf" value="([^"]+)"/',$html,$m);return html_entity_decode($m[1]??'');}
function h4l(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function batch(string $id): array {global $real;return json_decode((string)file_get_contents("$real/$id/batch.json"),true);}
function probe(string $p): array {exec('ffprobe -v error -show_entries format=duration:stream=codec_type,codec_name,width,height,pix_fmt -of json '.escapeshellarg($p),$o,$c);return json_decode(implode("\n",$o),true)?:[];}
function atoms(string $p): array {$o=[];$fp=fopen($p,'rb');$size=filesize($p);$pos=0;while($pos+8<=$size&&count($o)<64){fseek($fp,$pos);$h=fread($fp,8);$len=unpack('N',substr($h,0,4))[1];$t=substr($h,4,4);if($len===1){$len=unpack('J',fread($fp,8))[1];}elseif($len===0)$len=$size-$pos;if($len<8)break;$o[]=$t;$pos+=$len;}fclose($fp);return $o;}
function moovFirst(string $p): bool {$a=atoms($p);$m=array_search('moov',$a,true);$d=array_search('mdat',$a,true);return $m!==false&&($d===false||$m<$d);}
function dur(array $j): float {return (float)($j['format']['duration']??0);}
function vstream(array $j): array {foreach($j['streams']??[] as $s)if(($s['codec_type']??'')==='video')return $s;return [];}
function astream(array $j): array {foreach($j['streams']??[] as $s)if(($s['codec_type']??'')==='audio')return $s;return [];}

function p4m_full(string $b): bool {$t=rtrim($b);return str_ends_with($t,'</html>')&&str_contains($b,'const batchNewCats=')&&str_contains($b,'id="src-key"');}
if(getenv('P4M_MODE')==='nolegacy'){
 $GONE='The old Desktop Videos folder is not connected to the importer any more';
 [$c,,$b]=http('GET','/bulk-import-guided.php');ok($c===200&&p4m_full($b),'4m: start page loads completely (ends with </html>)',$c.' '.substr(rtrim($b),-50));
 ok(str_contains($b,'const batchNewCats=[]'),'4m: start page: empty new-category list');ok(!str_contains($b,'Desktop Videos (existing)'),'4m: the removed Desktop Videos source is not offered');
 ok(!is_dir('/import-source')&&!is_dir('/import-sources'),'4m: this importer has no /import-source or /import-sources folder (like production after the mount fix)');
 $E=(string)getenv('P4M_EC5');
 if($E!==''){$f="$real/$E/batch.json";ok(is_file($f),"4m: copy of import $E present");$h0=@hash_file('sha256',$f);
  $st=(string)(batch($E)['state']??'');echo "copy of $E: state=$st\n";
  [$c,,$b]=http('GET','/bulk-import-guided.php?id='.$E);ok($c===200&&p4m_full($b),"4m: import $E (copy) loads completely - this was the line-1507 crash",$c.' '.substr(rtrim($b),-60));
  ok(str_contains($b,'const batchNewCats=['),'4m: its page script is complete');ok(@hash_file('sha256',$f)===$h0,'4m: opening it did not change the batch copy');
  $old=(string)getenv('P4M_OLD');
  if($old!==''){[$c,,$b]=http('GET','/'.$old.'?id='.$E);ok($c===200&&!str_ends_with(rtrim($b),'</html>')&&str_contains($b,'const batchNewCats='),'4m: reproduction: the previous page (Patch 4l) stops at "const batchNewCats=" on the same copy',$c.' '.substr(rtrim($b),-60));
   [$c,,$b]=http('GET','/'.$old);ok(!str_ends_with(rtrim($b),'</html>'),'4m: reproduction: the previous page start page is cut off too');}}
 [$c,,$b]=http('GET','/bulk-import-guided.php?view=published');ok($c===200&&str_ends_with(rtrim($b),'</html>'),'4m: published imports view loads completely');
 [$c,,$b]=http('GET','/bulk-import-guided.php?src=legacy');ok($c===200&&str_contains($b,$GONE)&&str_ends_with(rtrim($b),'</html>'),'4m: ?src=legacy explains (Use Browse...) instead of failing',$c.' '.substr(strip_tags($b),0,0));
 [$c,,$b]=http('GET','/bulk-import-guided.php');preg_match('/name="csrf" value="([^"]+)"/',$b,$m);$csrf=$m[1]??'';ok($csrf!=='','4m: form token');
 $before=glob("$real/*",GLOB_ONLYDIR)?:[];
 [$c,,$b]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'legacy']);
 ok(str_contains($b,$GONE),'4m: Scan of the old Desktop Videos source explains',(string)$c);ok((glob("$real/*",GLOB_ONLYDIR)?:[])==$before,'4m: and creates no import');
 $H=(string)getenv('P4M_HSRC');
 if($H!==''){[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>$H]);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$hid=$m[1]??'';
  ok($c===302&&$hid!=='','4m: Browse source still scans: '.$H,$c.' '.($hd['location']??''));
  if($hid!==''){$hb=batch($hid);ok(count($hb['rows']??[])>=5&&str_starts_with((string)($hb['src_root']??''),'/host/c/'),'4m: its items come from the browsed folder ('.count($hb['rows']??[]).')');
   [$c,,$b]=http('GET','/bulk-import-guided.php?id='.$hid);ok($c===200&&p4m_full($b)&&str_contains($b,'id="set-category-panel"'),'4m: that import opens with the item editor, complete');}}
 p4o_sum();
 echo "e2e-p4p (no legacy folders): $pass passed, $fail failed\n";if($fail===0)echo "E2E NOLEGACY PASSED\n";exit($fail===0?0:1);
}
/* 0. Library categories the page must offer */
$LIBDIR=getenv('P4K_LIB')?:'/host/d/xampp/htdocs/Edutek/videos';$NAMESF=getenv('P4K_LIB_NAMES')?:'/p4l/lib-names.txt';
$libDirs=[];foreach(scandir($LIBDIR)?:[] as $e){if($e==='.'||$e==='..'||$e[0]==='.'||$e[0]==='$'||strcasecmp($e,'System Volume Information')===0)continue;if(is_dir("$LIBDIR/$e")&&!is_link("$LIBDIR/$e"))$libDirs[]=$e;}sort($libDirs,SORT_STRING);
if(is_file($NAMESF)){$libNames=array_values(array_filter(array_map(fn($l)=>rtrim($l,"\r"),explode("\n",preg_replace('/^\xEF\xBB\xBF/','',(string)file_get_contents($NAMESF)))),fn($l)=>$l!==''));sort($libNames,SORT_STRING);
 ok($libNames===$libDirs,'4k: test library copy has exactly the real library top-level folder names ('.count($libNames).')',count($libDirs).' dirs');}else $libNames=$libDirs;
echo 'library categories expected: ',count($libNames),"\n";
$pickCat='';foreach($libNames as $n)if(!in_array(strtolower($n),['unsorted','books','music','audiobooks','audio books'],true)&&preg_match('/^[A-Za-z0-9 ]+$/',$n)){$pickCat=$n;break;}if($pickCat==='')foreach($libNames as $n)if(strcasecmp($n,'Unsorted')!==0&&strcasecmp($n,'Books')!==0){$pickCat=$n;break;}
$PICKF=getenv('P4K_PICK')?:'/p4l/pick-cat.txt';if(is_file($PICKF)){$pc=trim(preg_replace('/^\xEF\xBB\xBF/','',(string)file_get_contents($PICKF)));ok(in_array($pc,$libNames,true),'the installer picked an existing library category for the tests: '.$pc);$pickCat=$pc;}
ok($pickCat!=='','an existing category to choose in the tests: '.$pickCat);
$libKeys=array_map(fn($n)=>mb_strtolower($n),$libNames);
/* 1. Source UI (Patch 4j: Browse only) */
[$c,,$html]=http('GET','/bulk-import-guided.php');ok($c===200,'page opens',(string)$c);$csrf=csrf($html);ok($csrf!=='','csrf token');
ok(!str_contains($html,'id="src-select"')&&!str_contains($html,'Select import source')&&!str_contains($html,'<select'),'4j: no Source folder selector on the page');
ok(str_contains($html,'<input type="hidden" name="source_key" id="src-key" value="">'),'4j: no source chosen by default (empty hidden field)');
ok(str_contains($html,'<p id="src-chosen" class="src-chosen">No folder chosen yet.</p>'),'4j: page says no folder is chosen yet');
ok(str_contains($html,'<p id="src-msg" class="warning" role="alert" hidden>'),'blocked-start message present, hidden, role=alert');
ok(str_contains($html,'f.noValidate=true')&&str_contains($html,'if(key.value===""){ev.preventDefault();msg.hidden=false;if(pick)pick.focus();}'),'4j: Start new import blocked in the browser until Browse picks a folder; focus moves to Browse');
foreach(['Or type a folder path','Use typed folder','id="srcpath"','D:\\Media\\Videos','hp-typed','type a folder path','Source folder list'] as $gone)ok(!str_contains($html,$gone),"removed: $gone");
$fs=strpos($html,'<form method="post" id="src-form">');$fe=strpos($html,'</form>',$fs);$form=substr($html,$fs,$fe-$fs);
preg_match_all('/<(button|select|textarea|a |input(?![^>]*type="hidden"))[^>]*?(?:id="([^"]*)")?[^>]*>/',$form,$fm);
ok(count($fm[0])===2&&strpos($form,'id="pick-btn"')<strpos($form,'id="start"'),'4j: keyboard order in the source form: Browse -> Start new import',implode(' | ',$fm[0]));
ok(!preg_match('/tabindex="[1-9]/',$html),'no positive tabindex overrides the order');
$hEnd=strpos($html,'</header>');ok(!str_contains(substr($html,0,$hEnd),'Published imports'),'4j: header has no Published imports link');
$lp=strrpos($html,'Published imports and undo</a>');$lay=strpos($html,'<div class="layout">');$mEnd=strpos($html,'</main>');
ok(substr_count($html,'?view=published#published-imports')===1&&$lp>$lay&&$lp<$mEnd&&str_contains(substr($html,$lp,$mEnd+7-$lp),'</section></main>')&&!str_contains(substr($html,$lp,$mEnd-$lp),'<div'),'4j: Published imports and undo is the last section on the page');
ok(str_contains($html,'header{padding-bottom:6px}header>:last-child{margin-bottom:0}main{padding-top:10px}'),'4j: tighter header CSS served');
[$c,,$hp]=http('GET','/bulk-import-guided.php?view=published');ok($c===200&&str_contains($hp,'id="published-imports"'),'published imports page still opens from the bottom link');
/* 1b. ?src= backend (what Browse... returns to) */
[$c,,$hs]=http('GET','/bulk-import-guided.php?src=legacy');
ok(str_contains($hs,'<input type="hidden" name="source_key" id="src-key" value="legacy">')&&str_contains($hs,'Selected: <strong>C:\Users\Edutek\Desktop\Videos</strong>'),'4j: ?src=legacy -> Selected: C:\Users\Edutek\Desktop\Videos');
ok(str_contains($hs,'Source folder chosen. Choose Start new import to scan it.')&&preg_match('/id="start"[^>]*autofocus/',$hs)===1,'after a pick, focus goes to Start new import');
[$c,,$hs]=http('GET','/bulk-import-guided.php?src=s:dupcopy');
ok(str_contains($hs,'value="s:dupcopy">')&&str_contains($hs,'Selected: <strong>C:\Users\Edutek\Desktop\Import Sources\dupcopy</strong>'),'4j: ?src=s:<name> import source still accepted and shown');
[$c,,$hs]=http('GET','/bulk-import-guided.php?src=s:nope');ok(str_contains($hs,'That source is not available')&&str_contains($hs,'id="src-key" value=""'),'unknown ?src= refused, nothing selected');
[$c,,$hs]=http('GET','/bulk-import-guided.php?srcpath='.rawurlencode('Q:\nothing here'));ok($c===200&&str_contains($hs,'id="src-key" value=""'),'?srcpath= backend still answers (bad path refused, nothing selected)');
/* 2. Server side: a scan without a source is refused, nothing created */
$before=glob("$real/*",GLOB_ONLYDIR)?:[];
[$c,,$html2]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan']);
ok(str_contains($html2,'Choose an import source first'),'scan with no source refused with a clear message');
[$c,,$html2]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'']);
ok(str_contains($html2,'Choose an import source first'),'scan with the empty placeholder value refused');
ok(str_contains($html2,'<p id="src-msg" class="warning" role="alert">'),'message shown under Browse after a refused start');
ok(count(glob("$real/*",GLOB_ONLYDIR)?:[])===count($before),'no batch created');
/* 3. Scan the test source */
$t0=microtime(true);[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'legacy']);
$loc=$hd['location']??'';ok($c===302&&preg_match('/id=([a-f0-9]{32})/',$loc,$m)===1,'scan of the chosen source',"$c $loc");$id=$m[1]??'';
printf("scan + media plan: %.1f s\n",microtime(true)-$t0);
$b=batch($id);$byName=[];foreach($b['rows'] as $rid=>$r)if(empty($r['support']))$byName[basename($r['source'])]=$rid;
echo "rows: ",implode(' | ',array_map(fn($n)=>$n.' => '.($b['rows'][$byName[$n]]['media']['label']??'-').' -> '.$b['rows'][$byName[$n]]['target'],array_keys($byName))),"\n";
$want=['HEVC small 5s.mp4'=>['convert','HEVC 360p → H.264 360p'],'HEVC 1080p surround 8s.mp4'=>['convert','HEVC 1080p → H.264 720p'],'HEVC portrait 4s.mp4'=>['convert','HEVC 1080p → H.264 720p'],
 'H264 moov at end 6s.mp4'=>['faststart','Fast start fix (no re-encode)'],'H264 faststart 6s.mp4'=>['none','Ready for browsers (H.264, fast start)'],'HEVC truncated.mp4'=>['damaged','Can’t be played — file is damaged'],'HEVC zero-filled.mp4'=>['convert','HEVC 360p → H.264 360p']];
foreach($want as $n=>[$a,$l]){$r=$b['rows'][$byName[$n]??'']??[];ok(($r['media']['action']??'')===$a&&($r['media']['label']??'')===$l,"plan $n: $l",json_encode($r['media']??null,JSON_UNESCAPED_UNICODE));}
$tr4=$b['rows'][$byName['HEVC truncated.mp4']??'']??[];ok(!empty($tr4['damaged'])&&empty($tr4['include']),'4p: the cut-short HEVC is damaged and not ticked',json_encode([$tr4['damaged']??null,$tr4['include']??null]));
ok(isset($byName['Loose Book Test.pdf'])&&$b['rows'][$byName['Loose Book Test.pdf']]['target']==='Books/(choose a shelf)/Loose Book Test.pdf'&&$b['rows'][$byName['Loose Book Test.pdf']]['placement_folder']==='','4p: a book gets no shelf by itself (it waits for you to choose one)',$b['rows'][$byName['Loose Book Test.pdf']??'']['target']??'');
ok(!isset($b['rows'][$byName['Loose Book Test.pdf']]['media']),'books get no media plan');
[$c,,$html]=http('GET','/bulk-import-guided.php?id='.$id);
ok(str_contains($html,'Will convert: HEVC 360p → H.264 360p'),'review card shows the planned conversion');ok(str_contains($html,'Will fix: Fast start fix (no re-encode)'),'review card shows the fast start fix');ok(str_contains($html,'Ready for browsers (H.264, fast start)'),'review card shows ready files');
/* 4. Select all, review, approve */
$draft=[];foreach($b['rows'] as $rid=>$r)if(empty($r['support']))$draft[$rid]=['include'=>true,'title'=>$r['title']];
for($i=0;$i<90;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$id,'action'=>'preview','draft'=>json_encode($draft)]);$b=batch($id);if($b['state']==='approved'||array_filter((array)($b['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
ok($b['state']!=='approved'&&str_contains(json_encode($b['issues']??[],JSON_UNESCAPED_UNICODE),'press M on it'),'4p: Next is blocked while a book has no shelf',$b['state'].' '.substr(json_encode($b['issues']??null,JSON_UNESCAPED_UNICODE),0,300));
foreach($b['rows'] as $rid=>$r)if(empty($r['support'])&&($r['type']??'')==='Books')$draft[$rid]+=['category'=>'Books','folder'=>'E2E Shelf','placement_override'=>true];
// cover images are made by the preview worker; Review selection again until they are ready (as a user would)
for($i=0;$i<90;$i++){[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$id,'action'=>'preview','draft'=>json_encode($draft)]);$b=batch($id);
 if($b['state']==='approved'||array_filter((array)($b['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
ok($b['state']==='approved','review selection -> approved',$b['state'].' '.json_encode($b['issues']??null));if($b['state']!=='approved'){echo "e2e-p4l: stopped\n";exit(1);}
[$c,,$html]=http('GET','/bulk-import-guided.php?id='.$id);
ok(preg_match('/Videos: (\d+) converted to H\.264 \(720p max\), (\d+) fixed without re-encoding, (\d+) copied as they are/',$html,$m)===1,'final summary counts the video work',$m[0]??'');echo "summary: ",$m[0]??'',"\n";
/* 5. Stage */
$t0=microtime(true);[$c,$hd,$body]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$id,'action'=>'execute','revision'=>$b['approval'],'confirm'=>'ADD']);$tx=microtime(true)-$t0;
printf("execute request returned in %.1f s (HTTP %d)\n",$tx,$c);ok($tx<10,'page answers at once; conversion runs in the background');
$b=batch($id);ok($b['state']==='converting','state converting',$b['state']);
[$c,,$html]=http('GET','/bulk-import-guided.php?id='.$id);ok(str_contains($html,'id="convert-panel"')&&str_contains($html,'role="progressbar"'),'converting page with progress bar');
$seen=[];$lastP=null;$t1=microtime(true);
while(microtime(true)-$t1<900){[$c,,$j]=http('GET','/bulk-import-guided.php?id='.$id.'&progress=1&token='.rawurlencode($csrf));$j=json_decode($j,true);if(!is_array($j)){usleep(300000);continue;}
 if(($j['state']??'')!=='converting')break;$p=$j['progress']??null;if($p&&($p['phase']??'')==='converting'){$k=($p['file']??'').'|'.($p['label']??'').'|'.(int)($p['pct']??0);if(!isset($seen[$k])){$seen[$k]=1;printf("  progress %5.1fs %s: %s %s%% speed %sx\n",microtime(true)-$t1,$p['file'],$p['label'],$p['pct'],$p['speed']??'');}}
 // the page keeps answering while converting
 usleep(300000);}
printf("background staging finished in %.1f s\n",microtime(true)-$t0);
ok(count(array_filter(array_keys($seen),fn($k)=>preg_match('/\|[1-9]\d*$/',$k)))>0,'progress reported with a percentage');
$b=batch($id);ok($b['state']==='complete','state complete',$b['state'].' '.end($b['events']));if($b['state']!=='complete'){echo "e2e-p4l: stopped\n";exit(1);}
echo "events:\n  ",implode("\n  ",array_slice($b['events'],-10)),"\n";
/* 6. Staged files */
$srcRoot=(string)$b['src_root'];
foreach($b['rows'] as $rid=>$r){if(!empty($r['support'])||empty($r['include']))continue;$op=$b['operations'][$rid]??[];$n=basename($r['source']);
 $dst="$real/$id/files/".$op['target'];ok(($op['state']??'')==='copied'&&is_file($dst),"$n staged");
 ok(hash_file('sha256',$dst)===$op['sha256']&&filesize($dst)===$op['bytes'],"$n: op.sha256/op.bytes describe the staged file");
 $src="$srcRoot/".$r['source'];ok(filesize($src)===$r['bytes']&&filemtime($src)===$r['mtime'],"$n: original untouched");
 if(($r['extension']??'')!=='mp4')continue;
 $pj=probe($dst);$sj=probe($src);$v=vstream($pj);$ma=$op['media']['action']??'';
 printf("  %-28s %-9s %s %sx%s %s %.2fs -> %s %sx%s %s %.2fs moov-first=%s %s KiB -> %s KiB\n",$n,$ma,vstream($sj)['codec_name']??'',vstream($sj)['width']??'',vstream($sj)['height']??'',astream($sj)['codec_name']??'-',dur($sj),$v['codec_name']??'',$v['width']??'',$v['height']??'',astream($pj)['codec_name']??'-',dur($pj),moovFirst($dst)?'yes':'no',intdiv($r['bytes'],1024),intdiv(filesize($dst),1024));
 if($n==='HEVC zero-filled.mp4'){ok($ma==='fallback','zero-filled HEVC: conversion failure falls back to the verified original',json_encode($op['media']??null));ok(hash_file('sha256',$dst)===hash_file('sha256',$src),'fallback file is the original byte for byte');continue;}
 ok(($v['codec_name']??'')==='h264'&&($v['pix_fmt']??'')==='yuv420p',"$n: H.264 yuv420p");ok(moovFirst($dst),"$n: moov before mdat");
 ok(abs(dur($pj)-dur($sj))<=max(0.5,dur($sj)*0.01),"$n: duration matches",sprintf('%.3f vs %.3f',dur($pj),dur($sj)));
 ok((astream($pj)['codec_name']??'')==='aac',"$n: AAC audio");
 $ss=min((int)vstream($sj)['width'],(int)vstream($sj)['height']);$os=min((int)$v['width'],(int)$v['height']);
 if($ma==='convert'){ok($os===min($ss,720),"$n: short side ".min($ss,720)." (no upscale)","$os");ok(abs($v['width']/$v['height']-vstream($sj)['width']/vstream($sj)['height'])<0.01,"$n: aspect kept");ok(($op['source_sha256']??'')===hash_file('sha256',$src),"$n: op.source_sha256 = original");}
 if($ma==='faststart'){ok(!moovFirst($src),"$n: source had moov at the end");ok($v['width']===vstream($sj)['width'],"$n: no re-encode, same size");}
 if($ma==='none')ok(hash_file('sha256',$dst)===hash_file('sha256',$src),"$n: already fast start -> copied byte for byte");
 /* HTTP Range playback of the staged copy */
 $sz=filesize($dst);[$c,$hd,$body]=http('GET','/bulk-import-guided.php?id='.$id.'&open='.$rid,[],['Range: bytes=0-1023']);
 ok($c===206&&($hd['content-range']??'')==="bytes 0-1023/$sz"&&strlen($body)===1024&&$body===file_get_contents($dst,false,null,0,1024)&&($hd['content-type']??'')==='video/mp4',"$n: Range 0-1023 -> 206",$c.' '.($hd['content-range']??''));
 $mid=intdiv($sz,2);[$c,$hd,$body]=http('GET','/bulk-import-guided.php?id='.$id.'&open='.$rid,[],["Range: bytes=$mid-"]);
 ok($c===206&&strlen($body)===$sz-$mid&&$body===file_get_contents($dst,false,null,$mid),"$n: Range from the middle -> 206 (seek)");
 if($ma!=='none'&&$ma!=='fallback'){$tmp=tempnam(sys_get_temp_dir(),'p4i');file_put_contents($tmp,$body=http('GET','/bulk-import-guided.php?id='.$id.'&open='.$rid,[],['Range: bytes=0-'.(min($sz,65535)) ])[2]);ok(in_array('moov',atoms($tmp),true),"$n: index (moov) is in the first 64 KiB served");unlink($tmp);}
}
ok(!empty($b['media_warnings'])&&str_contains(json_encode($b['media_warnings']),'HEVC zero-filled'),'warning recorded for the failed conversion');
$tr4=$byName['HEVC truncated.mp4']??'';ok($tr4!==''&&empty($b['rows'][$tr4]['include'])&&!isset($b['operations'][$tr4]),'4p: the damaged video was refused when ticked and never staged');
[$c,,$html]=http('GET','/bulk-import-guided.php?id='.$id);ok(str_contains($html,'Some videos could not be converted'),'warning shown on the page');
ok(!is_dir("$real/$id/work"),'work folder removed');
/* 7. Re-import of the same sources after conversion is caught */
[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'legacy']);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$id2=$m[1]??'';$b2=batch($id2);
foreach($b2['rows'] as $rid=>$r){if(!empty($r['support']))continue;$n=basename($r['source']);
 if(!empty($r['damaged'])){ok(empty($r['already_imported']),"damaged $n was never imported, so it is not flagged");continue;}
 ok(!empty($r['already_imported'])&&($r['recent_kind']??'')==='same',"re-import flagged as already imported, same file: $n",json_encode(['ai'=>$r['already_imported']??null,'k'=>$r['recent_kind']??null]));
 if(in_array($n,['HEVC small 5s.mp4','H264 moov at end 6s.mp4'],true))ok(($r['converted_before']??'')!=='',"$n: ledger match by original SHA-256 (converted earlier)");}
[$c,,$html]=http('GET','/bulk-import-guided.php?id='.$id2);ok(str_contains($html,'RECENTLY IMPORTED - SAME FILE (converted earlier)'),'card says SAME FILE (converted earlier)');
/* 7b. The same original under another name in another source: only the transcode ledger (SHA-256 of the original) can catch it */
if(is_dir(dirname($real).'/../import-sources/dupcopy')||isset($argv[4])){
 [$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'s:dupcopy']);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$id3=$m[1]??'';ok($id3!=='','scan of the second source (renamed copy)',($hd['location']??'').$c);
 if($id3!==''){$b3=batch($id3);foreach($b3['rows'] as $r){if(!empty($r['support']))continue;
  ok(!empty($r['already_imported'])&&($r['recent_kind']??'')==='same'&&($r['converted_before']??'')==='HEVC 360p → H.264 360p','renamed copy of a converted original flagged SAME FILE via the ledger: '.basename($r['source']),json_encode(['ai'=>$r['already_imported']??null,'k'=>$r['recent_kind']??null,'cb'=>$r['converted_before']??null],JSON_UNESCAPED_UNICODE));}}
}
/* 8. Benchmark + interruption: a long HEVC 1080p clip; the runner is killed part way and must resume when the page is opened */
function stage(string $key,bool $kill): array {global $csrf;
 [$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>$key]);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$id=$m[1]??'';if($id==='')return ['',0.0,[]];$b=batch($id);
 $draft=[];foreach($b['rows'] as $rid=>$r)if(empty($r['support'])){$draft[$rid]=['include'=>true,'title'=>$r['title'],'dup_decision'=>'add','duplicate_add'=>true];if(($r['placement_category']??'')==='')$draft[$rid]['category']=$GLOBALS['pickCat'];if(($r['type']??'')==='Books'&&($r['placement_folder']??'')==='')$draft[$rid]=['category'=>'Books','folder'=>'E2E Shelf','placement_override'=>true]+$draft[$rid];}
 for($i=0;$i<90;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$id,'action'=>'preview','draft'=>json_encode($draft)]);$b=batch($id);if($b['state']==='approved'||array_filter((array)($b['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
 if($b['state']!=='approved'){echo 'not approved: ',json_encode($b['issues']??null),"\n";return [$id,0.0,[]];}
 $t0=microtime(true);http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$id,'action'=>'execute','revision'=>$b['approval'],'confirm'=>'ADD']);$killed=false;$speeds=[];$pageMax=0.0;
 while(microtime(true)-$t0<1800){$q0=microtime(true);[$c,,$j]=http('GET','/bulk-import-guided.php?id='.$id.'&progress=1&token='.rawurlencode($csrf));$pageMax=max($pageMax,microtime(true)-$q0);$j=json_decode($j,true);
  if(($j['state']??'')!=='converting')break;$p=$j['progress']??[];if(($p['speed']??'')!=='')$speeds[]=(float)$p['speed'];
  if($kill&&!$killed&&($p['pct']??0)>=25){foreach(glob('/proc/[0-9]*/cmdline') as $f){$cl=(string)@file_get_contents($f);if(str_contains($cl,'--media-runner')||(str_contains($cl,'ffmpeg')&&str_contains($cl,'/work/'))){$pid=(int)basename(dirname($f));posix_kill($pid,9);echo "  killed pid $pid\n";}}
   $killed=true;sleep(2);echo "  runner killed at ",$p['pct'],"%\n";
   [$c,,$html]=http('GET','/bulk-import-guided.php?id='.$id);echo "  page opened again (HTTP $c)\n";}
  usleep(500000);}
 return [$id,microtime(true)-$t0,['speeds'=>$speeds,'page_max'=>$pageMax]];}
if(isset($argv[5])||is_dir(dirname($real).'/../import-sources/bench')){
 [$bid,$secs,$st]=stage('s:bench',false);$bb=batch($bid);ok($bb['state']==='complete','benchmark batch complete',$bb['state']);
 foreach($bb['operations'] as $rid=>$op){if(($op['media']['action']??'')!=='convert')continue;$d=(float)$op['media']['duration'];
  printf("BENCH %s: %s, %.0f s video converted in %.1f s (%.1fx real time), %d MiB -> %.1f MiB, page answered in <= %.2f s while converting\n",basename($op['target']),$op['media']['label'],$d,$op['media']['seconds'],$d/max(0.1,$op['media']['seconds']),intdiv((int)$op['source_bytes'],1048576),$op['bytes']/1048576,$st['page_max']);
  ok($st['page_max']<3,'page stays responsive while converting',(string)$st['page_max']);}
 [$kid,$secs,$st]=stage('s:bench',true);$kb=batch($kid);ok($kb['state']==='complete','after the runner was killed, opening the page resumed it and it completed',$kb['state'].' '.end($kb['events']));
 foreach($kb['operations'] as $rid=>$op){if(!isset($op['media']))continue;ok(($op['media_tries']??0)===2&&($op['media']['action']??'')==='convert','resumed conversion (try 2) produced a converted file',json_encode($op));
  $dst="$real/$kid/files/".$op['target'];ok(hash_file('sha256',$dst)===$op['sha256']&&moovFirst($dst),'resumed file verified');
  $o1=null;foreach($bb['operations'] as $x)if(isset($x['media']))$o1=$x;ok($o1&&$o1['sha256']===$op['sha256'],'deterministic: the second conversion of the same original has the same SHA-256 as the first',($o1['sha256']??'').' vs '.$op['sha256']);}
 ok(!glob("$real/$kid/work/*"),'no leftover partial files after the resume');
}
/* 9. The real acceptance clips (a copy of Desktop\Import Acceptance Test in the throwaway source "acceptance") */
$accAudio=[]; /* audio file names staged from the acceptance copy (Patch 4o) */
if(is_dir(dirname($real).'/../import-sources/acceptance')){
 [$aid,$secs]=stage('s:acceptance',false);ok($aid!=='','acceptance batch scanned');
 if($aid!==''){$ab=batch($aid);ok($ab['state']==='complete','acceptance batch staged',$ab['state'].' '.json_encode($ab['issues']??null).' '.end($ab['events']));printf("acceptance staged in %.1f s\n",$secs);
  foreach($ab['rows'] as $rid=>$r){if(!empty($r['support'])||empty($r['include']))continue;$n=basename($r['source']);$op=$ab['operations'][$rid]??[];
   if(($r['extension']??'')==='pdf'){echo "  $n -> {$r['target']}\n";if($n==='Loose Book Acceptance.pdf')ok($r['target']==='Books/E2E Shelf/Loose Book Acceptance.pdf','4p: acceptance loose book -> the shelf chosen for it (E2E Shelf)',$r['target']);continue;}
   if(($op['state']??'')!=='copied'){ok(false,"$n staged",json_encode($op));continue;}
   $dst="$real/$aid/files/".$op['target'];$src=$ab['src_root'].'/'.$r['source'];$pj=probe($dst);$sj=probe($src);$v=vstream($pj);$sv=vstream($sj);$ma=$op['media']['action']??'';
   printf("  %-44s plan: %-40s did: %-9s %s %sx%s %s %.2fs moov-first=%s -> %s %sx%s %s %.2fs moov-first=%s  %.1f s\n",$n,$r['media']['label']??'-',$ma,$sv['codec_name']??'',$sv['width']??'',$sv['height']??'',astream($sj)['codec_name']??'-',dur($sj),moovFirst($src)?'yes':'no',$v['codec_name']??'',$v['width']??'',$v['height']??'',astream($pj)['codec_name']??'-',dur($pj),moovFirst($dst)?'yes':'no',(float)($op['media']['seconds']??0));
   ok(hash_file('sha256',$dst)===$op['sha256'],"$n: staged hash recorded");
   if($ma==='fallback'){ok(hash_file('sha256',$dst)===hash_file('sha256',$src),"$n: fallback is the original");continue;}
   if(preg_match('/\.(mp3|wav|wave|ogg|oga|wma|aiff?|aifc|m4a|m4b|aac|flac|opus|ra|rm|ape|amr|ac3|mka)$/i',(string)$r['source'])){ /* Patch 4o: an audio file in the acceptance folder gets the audio rules */
    $da=astream($pj);$sa=astream($sj);
    ok(preg_match('/\.(mp3|wav|ogg)$/i',$dst)===1&&in_array(preg_replace('/^pcm_.*/','pcm',(string)($da['codec_name']??'')),['mp3','pcm','vorbis'],true),"$n: staged as browser-playable audio (".basename($dst).')',json_encode([$da['codec_name']??null,$ma]));
    if($ma==='mp3')ok(str_ends_with($dst,'.mp3')&&pathinfo($dst,PATHINFO_FILENAME)===pathinfo($src,PATHINFO_FILENAME),"$n: converted to MP3 with the same name");
    else ok(hash_file('sha256',$dst)===hash_file('sha256',$src),"$n: already playable -> copied byte for byte",$ma);
    ok(abs(dur($pj)-dur($sj))<=1.0,"$n: audio duration within 1 s",sprintf('%.3f vs %.3f',dur($pj),dur($sj)));
    exec('ffmpeg -nostdin -v error -xerror -i '.escapeshellarg($dst).' -f null - 2>&1',$aeo,$aec);ok($aec===0,"$n: plays from start to end");
    $accAudio[basename((string)$r['source'])]=1;continue;}
   ok(in_array($v['codec_name']??'',['h264'],true)&&($v['pix_fmt']??'')==='yuv420p',"$n: browser-safe H.264",json_encode($v));ok(moovFirst($dst),"$n: moov first");
   ok(abs(dur($pj)-dur($sj))<=max(0.5,dur($sj)*0.01),"$n: duration kept",sprintf('%.3f vs %.3f',dur($pj),dur($sj)));
   if($n==='Acceptance HEVC 5s.mp4')ok($ma==='convert'&&($sv['codec_name']??'')==='hevc','Acceptance HEVC 5s: converted from HEVC',$ma);
   $sz=filesize($dst);[$c,$hd,$body]=http('GET','/bulk-import-guided.php?id='.$aid.'&open='.$rid,[],['Range: bytes=100-1123']);ok($c===206&&($hd['content-range']??'')==="bytes 100-1123/$sz"&&$body===file_get_contents($dst,false,null,100,1024),"$n: HTTP Range 206",$c.' '.($hd['content-range']??''));}
  ok(!is_dir("$real/$aid/work"),'acceptance work folder removed');}
}
/* 10. Patch 4k: categories (dropdown = library folders, choose existing, New category…, bad names, subfolder rule, no automatic Unsorted) */
$NEWSRC='NEW Edutek Test Videos';
if(is_dir(dirname($real).'/../import-sources/'.$NEWSRC)){
 [$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'s:'.$NEWSRC]);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$cid=$m[1]??'';ok($cid!=='','4k: scan of "'.$NEWSRC.'"',($hd['location']??'').$c);
 $cb=batch($cid);$cn=[];foreach($cb['rows'] as $rid=>$r)if(empty($r['support']))$cn[$r['source']]=$rid;
 echo "rows: ",implode(' | ',array_map(fn($s)=>$s.' -> '.$cb['rows'][$cn[$s]]['target'],array_keys($cn))),"\n";
 $r1=$cn['Test Video 01 - Color Bars 720p.mp4']??'';$r2=$cn['Test Video 02 - Tone.mp4']??'';$r3=$cn['Robotics & AI/Arm demo.mp4']??'';$r4=$cn['Unsorted/Old clip.mp4']??'';$r5=$cn[$pickCat.'/Existing cat clip.mp4']??'';
 ok($r1!==''&&$r2!==''&&$r3!==''&&$r4!=='','4k: test rows found',implode(',',array_keys($cn)));
 foreach([$r1,$r2] as $rr){$r=$cb['rows'][$rr]??[];ok(($r['placement_category']??'x')===''&&!empty($r['root_file'])&&str_starts_with((string)($r['target']??''),'(choose a category)/'),'4k: file directly in the chosen folder gets NO category (never Unsorted): '.($r['title']??''),json_encode([$r['placement_category']??null,$r['target']??null],JSON_UNESCAPED_UNICODE));}
 $r=$cb['rows'][$r3]??[];$rc=(string)($r['placement_category']??'');ok(str_starts_with($rc,'Robotics')&&($r['target']??'')===$rc.'/'.$rc.'/Arm demo.mp4','4k: subfolder rule: "Robotics & AI/Arm demo.mp4" -> category from the subfolder name, folder = category name',(string)($r['target']??''));
 $r=$cb['rows'][$r4]??[];ok(($r['placement_category']??'x')==='','4k: a source subfolder called Unsorted is not taken as the category (choice required)',(string)($r['target']??''));
 if($r5!==''){$r=$cb['rows'][$r5];ok(($r['target']??'')===$pickCat.'/'.$pickCat.'/Existing cat clip.mp4','4k: subfolder named like an existing category -> that category, library spelling',(string)($r['target']??''));}
 /* the page: dropdown options = library folders */
 [$c,,$ch]=http('GET','/bulk-import-guided.php?id='.$cid);ok($c===200&&!str_contains($ch,'Fatal error'),'4k: review page opens');
 ok(preg_match('#<template id="category-options">(.*?)</template>#s',$ch,$tm)===1,'4k: category list template present');preg_match_all('#<option value="([^"]*)">#',$tm[1]??'',$om);
 $opts=array_map(fn($v)=>html_entity_decode($v,ENT_QUOTES|ENT_HTML5,'UTF-8'),$om[1]);$so=$opts;sort($so,SORT_STRING);
 ok(count($opts)===count($libNames),'4k: Category list offers '.count($opts).' categories = '.count($libNames).' top-level library folders',count($opts).' vs '.count($libNames));
 ok($so===$libNames,'4k: Category list = exactly the library folder names',json_encode(array_values(array_diff($libNames,$so)),JSON_UNESCAPED_UNICODE).' missing / extra '.json_encode(array_values(array_diff($so,$libNames)),JSON_UNESCAPED_UNICODE));
 ok(preg_match('#<span id="category-count">(\d+)</span> library categories#',$ch,$cm)===1&&(int)$cm[1]===count($libNames),'4k: page says "'.count($libNames).' library categories"',$cm[0]??'');
 ok(!str_contains($ch,'list="category-options"')&&!str_contains($ch,'<datalist'),'4k: no datalist (the cause of the single "Unsorted" suggestion)');
 ok(preg_match('#<select id="bulk-category"[^>]*>(.*?)</select>#s',$ch,$bm)===1&&str_contains($bm[1],'<option value="__new__">New category…</option>'),'4k: bulk Category list has New category…');
 ok(preg_match('#<select id="bulk-category"[^>]*>(.*?)</select>#s',$ch,$bm)===1&&substr_count($bm[1],'<option')>=count($libNames)+2,'4k: bulk Category list is filled with all library categories in the page',(string)substr_count($bm[1]??'','<option'));
 foreach([$r1,$r2] as $rr){ok(preg_match('#<select id="cat-'.$rr.'" data-role="category-pick"[^>]*><option value="" selected>Choose a category…</option><option value="__new__">New category…</option></select>#',$ch)===1,'4k: row Category list starts at "Choose a category…" (not Unsorted) and has New category…: '.$cb['rows'][$rr]['title']);}
 ok(preg_match('#<details class="individual-placement" open><summary>Change this item’s placement</summary><label for="cat-'.$r1.'">#',$ch)===1,'4k: placement opens by itself when a category is needed');
 ok(str_contains($ch,'Files directly in “'.$NEWSRC.'”'),'4k: group heading says Files directly in “'.$NEWSRC.'” (not Unsorted)');
 ok(!preg_match('#Unsorted/Unsorted/Test Video 0#',$ch),'4k: no Unsorted/Unsorted target on the page');
 /* New category… name check (same rule as saving) */
 $cc=function(string $v,bool $tok=true){global $csrf,$cid;[$c,,$j]=http('GET','/bulk-import-guided.php?id='.$cid.'&catcheck='.rawurlencode($v).($tok?'&token='.rawurlencode($csrf):''));return [$c,json_decode($j,true)];};
 [$c,$j]=$cc('E2E New Category 4k');ok($c===200&&($j['ok']??null)===true&&empty($j['exists'])&&($j['name']??'')==='E2E New Category 4k'&&str_contains((string)($j['message']??''),'only when you publish'),'4k: New category good name accepted',json_encode($j,JSON_UNESCAPED_UNICODE));
 [$c,$j]=$cc(mb_strtolower($pickCat));ok(($j['ok']??null)===true&&!empty($j['exists'])&&($j['name']??'')===$pickCat,'4k: typing an existing category (any case) uses the library spelling',json_encode($j,JSON_UNESCAPED_UNICODE));
 foreach(['Robotics: Intro'=>'naming rule','a/b'=>'','Books'=>'','Audio Books'=>'','Unsorted '=>'','   '=>''] as $bad=>$why){[$c,$j]=$cc($bad);
  if(strcasecmp(trim($bad),'Unsorted')===0&&in_array('unsorted',$libKeys,true)){ok(($j['ok']??null)===true&&!empty($j['exists']),'4k: Unsorted can still be chosen on purpose (existing folder)',json_encode($j,JSON_UNESCAPED_UNICODE));continue;}
  ok(($j['ok']??null)===false&&($j['message']??'')!=='','4k: bad name rejected: "'.$bad.'"',json_encode($j,JSON_UNESCAPED_UNICODE));
  if($why!=='')ok(str_contains((string)$j['message'],$why)&&($j['suggest']??'')!=='','4k: rejection explains the rule and suggests a name: '.($j['suggest']??''));}
 [$c]=$cc('E2E New Category 4k',false);ok($c===403,'4k: name check refuses requests without the page token',(string)$c);
 /* nothing chosen -> Review selection stops with a clear message */
 $cd=[];foreach($cb['rows'] as $rid=>$r)if(empty($r['support']))$cd[$rid]=['include'=>true,'title'=>$r['title']];
 $cd[$r4]['include']=false;if($r5!=='')$cd[$r5]['include']=false;
 for($i=0;$i<90;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$cid,'action'=>'preview','draft'=>json_encode($cd)]);$cb=batch($cid);if($cb['state']==='approved'||array_filter((array)($cb['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
 $ci=array_values(array_filter((array)($cb['issues']??[]),fn($x)=>($x['kind']??'')==='category'));
 ok($cb['state']==='draft'&&count($ci)===2,'4k: Review selection refused while 2 items have no category',$cb['state'].' '.json_encode($cb['issues']??null,JSON_UNESCAPED_UNICODE));
 ok(str_contains((string)($ci[0]['message']??''),'Choose a category for “Test Video 0')&&str_contains((string)($ci[0]['message']??''),'none could be worked out from the source folder name'),'4k: clear message names the item',(string)($ci[0]['message']??''));
 [$c,,$ch]=http('GET','/bulk-import-guided.php?id='.$cid);ok(str_contains($ch,'<div class="warning" id="category-needed" role="alert">')&&str_contains($ch,'Unsorted is never chosen automatically'),'4k: page shows the Choose a category warning');
 /* a path typed as a category is refused by the server (nothing saved) */
 $bad=$cd;$bad[$r1]['category']='a/b';$bbefore=batch($cid)['rows'][$r1]['target'];[$c,$hd,$bh]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$cid,'action'=>'save','draft'=>json_encode($bad)]);
 ok(batch($cid)['rows'][$r1]['target']===$bbefore&&$c!==302,'4k: server refuses a path typed as a category; nothing saved',$c.' '.batch($cid)['rows'][$r1]['target']);
 /* typed name with forbidden characters is cleaned by the same rule on save */
 $t=$cd;$t[$r1]['category']=$pickCat;$t[$r2]['category']='E2E New Category 4k';
 for($i=0;$i<90;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$cid,'action'=>'preview','draft'=>json_encode($t)]);$cb=batch($cid);if($cb['state']==='approved'||array_filter((array)($cb['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
 ok($cb['state']==='approved','4k: with categories chosen, Review selection -> approved',$cb['state'].' '.json_encode($cb['issues']??null,JSON_UNESCAPED_UNICODE));
 ok($cb['rows'][$r1]['target']===$pickCat.'/'.$pickCat.'/Test Video 01 - Color Bars 720p.mp4','4k: choosing an existing category -> '.$pickCat.'/'.$pickCat.'/Test Video 01 - Color Bars 720p.mp4',$cb['rows'][$r1]['target']);
 ok($cb['rows'][$r2]['target']==='E2E New Category 4k/E2E New Category 4k/Test Video 02 - Tone.mp4','4k: New category -> E2E New Category 4k/E2E New Category 4k/Test Video 02 - Tone.mp4',$cb['rows'][$r2]['target']);
 ok(!is_dir($LIBDIR.'/E2E New Category 4k'),'4k: the new category folder is NOT created before publishing');
 [$c,,$ch]=http('GET','/bulk-import-guided.php?id='.$cid);preg_match('#<li id="new-categories">(.*?)</li>#s',$ch,$nm);$nl=$nm[1]??'';
 ok(str_contains($nl,'created only when you publish')&&str_contains($nl,'E2E New Category 4k')&&!str_contains($nl,'>'.$pickCat.'<')&&!preg_match('#[>,] ?'.preg_quote($pickCat,'#').'(,|<)#',$nl),'4k: confirm Quick checks lists the new category (and not the existing one)',strip_tags($nl));
 if(!in_array(mb_strtolower($rc),$libKeys,true))ok(str_contains($nl,$rc),'4k: confirm lists the auto category from the subfolder as new: '.$rc,strip_tags($nl));
 /* stage it; the publish step tells what folders publishing creates */
 http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$cid,'action'=>'execute','revision'=>$cb['approval'],'confirm'=>'ADD']);
 for($i=0;$i<300;$i++){$cb=batch($cid);if(!in_array($cb['state'],['approved','converting','copying','importing'],true))break;http('GET','/bulk-import-guided.php?id='.$cid.'&progress=1&token='.rawurlencode($csrf));usleep(500000);}
 ok($cb['state']==='complete','4k: category batch staged',$cb['state'].' '.end($cb['events']));
 ok(is_file("$real/$cid/files/E2E New Category 4k/E2E New Category 4k/Test Video 02 - Tone.mp4")&&is_file("$real/$cid/files/$pickCat/$pickCat/Test Video 01 - Color Bars 720p.mp4"),'4k: staged files are under the chosen categories');
 [$c,,$ch]=http('GET','/bulk-import-guided.php?id='.$cid);
 ok(preg_match('#<p class="notice" id="publish-new-categories">New categories: <strong>([^<]*)</strong>#',$ch,$pm)===1&&str_contains($pm[1],'E2E New Category 4k')&&!in_array($pickCat,explode(', ',html_entity_decode($pm[1])),true),'4k: publish step says which new category folders publishing creates',$pm[1]??'');
 ok(!is_dir($LIBDIR.'/E2E New Category 4k'),'4k: still no library folder after staging (only Publish-Batch creates it)');
 echo "BATCH4K $cid\n";
}else ok(false,'4k: category test source "'.$NEWSRC.'" missing');
/* ===== Patch 4l: a new category is shared by every item in the import, kept with it, counted once at confirm ===== */
$NC='E2E Shared Cat 4l';
if(is_dir(dirname($real).'/../import-sources/'.$NEWSRC)){
 [$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'s:'.$NEWSRC]);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$lid=$m[1]??'';ok($lid!=='','4l: scan of "'.$NEWSRC.'" for the shared category test');
 $lb=batch($lid);$ln=[];foreach($lb['rows'] as $rid=>$r)if(empty($r['support']))$ln[basename($r['source'])]=$rid;
 $order=['Test Video 01 - Color Bars 720p.mp4','Test Video 02 - Tone.mp4','Old clip.mp4','Arm demo.mp4','Existing cat clip.mp4'];$L=[];foreach($order as $n)$L[]=$ln[$n]??'';
 ok(!in_array('',$L,true),'4l: the 5 test items are there',json_encode(array_keys($ln),JSON_UNESCAPED_UNICODE));
 $targets0=array_map(fn($r)=>$r['target'],$lb['rows']);
 [$c,,$lh]=http('GET','/bulk-import-guided.php?id='.$lid);
 ok(str_contains($lh,'id="set-category-panel"')&&str_contains($lh,'<select id="setcat-scope">')&&str_contains($lh,'<button type="button" id="setcat-apply">Set category for selected items</button>'),'4l: "Set category for several items" panel on the page');
 ok(preg_match('#<select id="setcat-pick"[^>]*>(.*?)</select>#s',$lh,$sm)===1&&str_contains($sm[1],'<option value="__new__">New category…</option>')&&substr_count($sm[1],'<option')>=count($libNames)+2,'4l: its Category list has every library category and New category…');
 ok(substr_count($lh,'class="cat-to-selected"')>=4&&substr_count($lh,'class="cat-to-folder"')>=4,'4l: each video card has "Use this category for all selected items / all items in this folder"');
 $pp=function(string $name,bool $tok=true) use ($lid){global $csrf;[$c,,$j]=http('POST','/bulk-import-guided.php',['csrf'=>$tok?$csrf:'x','id'=>$lid,'action'=>'propose_category','name'=>$name]);return [$c,json_decode($j,true)];};
 /* create on item 1 */
 [$c,$j]=$pp($NC);ok($c===200&&($j['ok']??null)===true&&empty($j['exists'])&&($j['name']??'')===$NC&&($j['saved']??null)===true,'4l: New category "'.$NC.'" typed on item 1 is accepted and saved with the import',json_encode($j,JSON_UNESCAPED_UNICODE));
 ok(in_array($NC,(array)($j['new_categories']??[]),true),'4l: answer lists it among the new categories of this import',json_encode($j['new_categories']??null,JSON_UNESCAPED_UNICODE));
 $lb=batch($lid);ok(in_array($NC,(array)($lb['proposed_categories']??[]),true),'4l: batch.json keeps it (proposed_categories)',json_encode($lb['proposed_categories']??null,JSON_UNESCAPED_UNICODE));
 ok(array_map(fn($r)=>$r['target'],$lb['rows'])===$targets0&&$lb['state']==='draft','4l: proposing changes no item (rows untouched)');
 [$c,$j]=$pp(mb_strtolower($NC));ok(($j['ok']??null)===true&&($j['name']??'')===$NC&&str_contains((string)($j['message']??''),'already a new category in this import'),'4l: same name in other letter case -> the import\'s spelling, not a second category',json_encode($j,JSON_UNESCAPED_UNICODE));
 ok(count(array_filter((array)batch($lid)['proposed_categories'],fn($x)=>mb_strtolower($x)===mb_strtolower($NC)))===1,'4l: still one entry');
 [$c,$j]=$pp(mb_strtoupper($pickCat));ok(($j['ok']??null)===true&&!empty($j['exists'])&&($j['name']??'')===$pickCat&&!in_array($pickCat,(array)batch($lid)['proposed_categories'],true),'4l: an existing library name in any letter case -> library spelling '.$pickCat.' (not added as new)',json_encode($j,JSON_UNESCAPED_UNICODE));
 foreach(['a/b','Books','Audio Books','Robotics: Intro','   ','CON'] as $bad){[$c,$j]=$pp($bad);ok(($j['ok']??null)===false&&($j['message']??'')!=='','4l: bad name rejected and not kept: "'.$bad.'"',json_encode($j,JSON_UNESCAPED_UNICODE));}
 ok(count((array)batch($lid)['proposed_categories'])===1,'4l: no rejected name was kept',json_encode(batch($lid)['proposed_categories'],JSON_UNESCAPED_UNICODE));
 [$c]=$pp('E2E Sneaky 4l',false);ok($c===403&&!in_array('E2E Sneaky 4l',(array)batch($lid)['proposed_categories'],true),'4l: refused without the page token',(string)$c);
 /* reload (F5): it is in every Category list */
 [$c,,$lh]=http('GET','/bulk-import-guided.php?id='.$lid);
 foreach(['bulk-category','setcat-pick'] as $sid){ok(preg_match('#<select id="'.$sid.'"[^>]*><option value="">Choose a category…</option><optgroup label="New in this import \(created when you publish\)" data-new="1">(.*?)</optgroup><optgroup label="Library categories \((\d+)\)" data-lib="1">#s',$lh,$gm)===1&&str_contains($gm[1],'<option value="'.h4l($NC).'">'.h4l($NC).' (new)</option>')&&(int)$gm[2]===count($libNames),'4l: after reload "'.$NC.' (new)" is in the "New in this import" group at the top of #'.$sid,substr($gm[1]??'',0,300));}
 ok(preg_match('#const batchNewCats=(\[[^\]]*\])#',$lh,$bm)===1&&in_array($NC,(array)json_decode($bm[1],true),true),'4l: every card\'s Category list gets it (batchNewCats on the page)',$bm[1]??'');
 ok(str_contains($lh,'New categories in this import: ')&&str_contains($lh,h4l($NC)),'4l: page lists the new categories of this import');
 /* items 2-5 pick it from the list (two typed in other letter case), saved with Save progress */
 $d=[];foreach($lb['rows'] as $rid=>$r)if(empty($r['support']))$d[$rid]=['include'=>true,'title'=>$r['title'],'category'=>$r['placement_category'],'folder'=>$r['placement_folder'],'placement_override'=>!empty($r['placement_override']),'duplicate_add'=>true];
 foreach($L as $i=>$rid){$d[$rid]['category']=$i>=3?mb_strtolower($NC):$NC;$d[$rid]['folder']='';$d[$rid]['placement_override']=true;}
 [$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$lid,'action'=>'save','draft'=>json_encode($d),'new_categories'=>json_encode([$NC])]);$lb=batch($lid);
 ok($c===302,'4l: Save progress with 5 items in the new category',(string)$c);
 foreach($L as $i=>$rid)ok(($lb['rows'][$rid]['placement_category']??'')===$NC&&str_starts_with($lb['rows'][$rid]['target'],$NC.'/'.$NC.'/'),'4l: item '.($i+1).' -> '.$lb['rows'][$rid]['target'].($i>=3?' (typed in lower case -> one spelling)':''),(string)$lb['rows'][$rid]['target']);
 ok(($lb['proposed_categories']??null)===[$NC],'4l: after saving, the import keeps exactly the new categories in use (Robotics and AI no longer used -> dropped)',json_encode($lb['proposed_categories']??null,JSON_UNESCAPED_UNICODE));
 ok(!is_dir($LIBDIR.'/'.$NC),'4l: no library folder is created before publishing');
 /* an unused proposal is not on the confirm list */
 [$c,$j]=$pp('E2E Unused 4l');ok(($j['saved']??null)===true,'4l: second new category proposed (left unused)');
 $prev=function(array $dd,array $extra=[]) use ($lid){global $csrf;for($i=0;$i<90;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$lid,'action'=>'preview','draft'=>json_encode($dd)]+$extra);$b=batch($lid);if($b['state']==='approved'||array_filter((array)($b['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}return $b;};
 $lb=$prev($d,['new_categories'=>json_encode([$NC,'E2E Unused 4l'])]);ok($lb['state']==='approved','4l: Review selection -> approved',$lb['state'].' '.json_encode($lb['issues']??null,JSON_UNESCAPED_UNICODE));
 [$c,,$lh]=http('GET','/bulk-import-guided.php?id='.$lid);preg_match('#<li id="new-categories">(.*?)</li>#s',$lh,$nm);$nl=html_entity_decode(strip_tags($nm[1]??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
 ok(str_contains($nl,$NC.' (5 items)')&&substr_count($nl,$NC)===1,'4l: confirm lists "'.$NC.' (5 items)" once',$nl);
 ok(!str_contains($nl,'E2E Unused 4l')&&!str_contains($nl,'Robotics'),'4l: unused new categories are not on the confirm list',$nl);
 ok(!in_array('E2E Unused 4l',(array)batch($lid)['proposed_categories'],true),'4l: unused proposal dropped from the import when saved');
 /* proposing while approved keeps the approval valid */
 $ap=$lb['approval'];$raw4l=(string)file_get_contents("$real/$lid/batch.json");[$c,$j]=$pp('E2E Later 4l');$lb=batch($lid);
 ok(($j['ok']??null)===true&&($j['saved']??null)===false&&$raw4l===(string)file_get_contents("$real/$lid/batch.json")&&$lb['state']==='approved'&&$lb['approval']===$ap,'4l: a name typed on a reviewed (approved) import is checked but the approved batch file is not changed at all',json_encode($j,JSON_UNESCAPED_UNICODE));
 /* remove its usage from 2 items -> count drops; from all -> gone */
 $d[$L[3]]['category']=$pickCat;$d[$L[4]]['category']=$pickCat;$lb=$prev($d);ok($lb['state']==='approved','4l: re-approved with 2 items moved to '.$pickCat);
 [$c,,$lh]=http('GET','/bulk-import-guided.php?id='.$lid);preg_match('#<li id="new-categories">(.*?)</li>#s',$lh,$nm);$nl=html_entity_decode(strip_tags($nm[1]??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
 ok(str_contains($nl,$NC.' (3 items)'),'4l: confirm count follows the items: "'.$NC.' (3 items)"',$nl);
 foreach($L as $rid)$d[$rid]['category']=$pickCat;$lb=$prev($d);
 [$c,,$lh]=http('GET','/bulk-import-guided.php?id='.$lid);preg_match('#<li id="new-categories">(.*?)</li>#s',$lh,$nm);$nl=strip_tags($nm[1]??'');
 ok($lb['state']==='approved'&&str_contains($nl,'New categories: none')&&!str_contains($nl,$NC),'4l: when no item uses it any more it drops from confirm',$nl);
 ok(($lb['proposed_categories']??null)===[],'4l: and from the import\'s list (no longer offered after a reload)',json_encode($lb['proposed_categories']??null));
 ok(!is_dir($LIBDIR.'/'.$NC)&&!is_dir($LIBDIR.'/E2E Unused 4l')&&!is_dir($LIBDIR.'/E2E Later 4l'),'4l: library untouched');
 /* the approved batch of the user is never touched by these tests */
 echo "BATCH4L $lid\n";
}else ok(false,'4l: category test source "'.$NEWSRC.'" missing');
/* 15. Patch 4m: every page loads completely (start page, each import whatever its state, published view) */
[$c,,$b]=http('GET','/bulk-import-guided.php');ok($c===200&&p4m_full($b),'4m: start page complete',$c.' '.substr(rtrim($b),-50));
foreach(glob("$real/*/batch.json")?:[] as $bf){$bid=basename(dirname($bf));$bs=(string)(json_decode((string)file_get_contents($bf),true)['state']??'');
 [$c,,$b]=http('GET','/bulk-import-guided.php?id='.$bid);ok($c===200&&p4m_full($b),"4m: import ".substr($bid,0,8)." ($bs) page complete",$c.' '.substr(rtrim($b),-50));}
[$c,,$b]=http('GET','/bulk-import-guided.php?view=published');ok($c===200&&str_ends_with(rtrim($b),'</html>'),'4m: published view complete');
/* 16. Patch 4n: Recently imported follows the publish state and the library (fake publish-status files, throwaway data only) */
$PS=dirname($real).'/publish-status';if(!is_dir($PS))@mkdir($PS,0770,true);$mine=[];
foreach(glob("$real/*/batch.json")?:[] as $bf){$bb=json_decode((string)file_get_contents($bf),true);$hit=false;foreach(($bb['operations']??[]) as $orid=>$op)if(($op['state']??'')==='copied'&&(($bb['rows'][$orid]['src_root']??'')===(getenv('P4N_LEGACY_ROOT')?:'/import-source')))$hit=true;
 if($hit){$bid=basename(dirname($bf));if(!is_file("$PS/$bid.json"))$mine[]=$bid;}}
ok(count($mine)>=1,'4n: staged imports of the test source found ('.count($mine).')');
$rescan=function(){global $csrf;[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>'legacy']);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$nid=$m[1]??'';
 $nb=$nid!==''?batch($nid):['rows'=>[]];$n=0;$cv=0;foreach($nb['rows'] as $r){if(!empty($r['support']))continue;if(!empty($r['already_imported']))$n++;if(($r['converted_before']??'')!=='')$cv++;}
 $h='';if($nid!==''){[,,$h]=http('GET','/bulk-import-guided.php?id='.$nid);}return [$nid,$n,$cv,$h];};
$setAll=function(?string $st) use($PS,$mine){foreach($mine as $bid){$f="$PS/$bid.json";if($st===null){if(is_file($f))unlink($f);continue;}file_put_contents($f,json_encode(['batch'=>$bid,'state'=>$st,'updated'=>date('c'),'note'=>'e2e 4n fake status (throwaway)']));}};
[$nid,$n0,$cv0,$h0]=$rescan();ok($nid!==''&&$n0>=5&&str_contains($h0,'RECENTLY IMPORTED - SAME FILE'),"4n: staged, not sent yet -> a new import of the same folder still flags them ($n0 flagged, $cv0 converted earlier)");
foreach(['undone','blocked','failed','reversed','published'] as $st){$setAll($st);[$nid,$n,$cv,$h]=$rescan();
 ok($nid!==''&&$n===0&&$cv===0,"4n: publish $st".($st==='published'?' but the files are no longer in the library':'')." -> nothing flagged as recently imported",json_encode([$nid,$n,$cv]));
 ok(!str_contains($h,'RECENTLY IMPORTED')&&str_contains($h,'Recently imported (0)')&&p4m_full($h),"4n: $st: no RECENTLY IMPORTED badge, sidebar Recently imported (0), page complete");}
foreach(['publishing','attention'] as $st){$setAll($st);[$nid,$n,,]=$rescan();ok($n===$n0,"4n: publish $st (on its way to the library) -> still flagged ($n)");}
$setAll(null);[$nid,$n,,]=$rescan();ok($n===$n0,"4n: fake statuses removed -> flagged again ($n)");
/* 17. Patch 4o: the screen data matches the import */
foreach(array_filter([$id??'',$id2??'']) as $bid4){[$c,,$b]=http('GET','/bulk-import-guided.php?id='.$bid4);$j=p4o_data($b)?:[];$bb=batch($bid4);
 $n=0;foreach(($bb['rows']??[]) as $r)if(empty($r['support'])&&!empty($r['include']))$n++;
 ok(($j['v']??0)===1&&($j['id']??'')===$bid4&&($j['state']??'')===(string)($bb['state']??'')&&count($j['items']??[])===min($n,400),'4o: import '.substr($bid4,0,8).' ('.($bb['state']??'').'): the screens show its state and every included item ('.count($j['items']??[]).' of '.$n.')');
 $miss=0;foreach(($j['items']??[]) as $it)if(!isset($it['t'],$it['to']))$miss++;ok($miss===0,'4o: each item has its title and library place');}
[$c,,$b]=http('GET','/bulk-import-guided.php');$j=p4o_data($b)?:[];ok(($j['v']??0)===1&&is_array($j['history']??null),'4o: start page has the past-imports list ('.count($j['history']??[]).')');
$bad=0;foreach(($j['history']??[]) as $h)if(!preg_match('/^[a-f0-9]{32}$/',(string)($h['id']??''))||!isset($h['iso']))$bad++;ok($bad===0,'4o: every past import has an id and a time');
[$c,,$b]=http('GET','/bulk-import-guided.php?view=published');ok($c===200&&(p4o_data($b)['v']??0)===1,'4o: published imports view has the screen data');
/* 18. Patch 4o audio: audio a browser can't play is converted to MP3 at import, damaged audio is flagged and never published.
   Source "Audio Test" (made by make-audio-clips.sh): s:Audio Test in the throwaway importer, or P4O_AUDIO_KEY (e.g. h:Users/.../Audio Test). */
$AK=(string)(getenv('P4O_AUDIO_KEY')?:(is_dir('/import-sources/Audio Test')?'s:Audio Test':''));
if($AK!==''){
 $atag=function(string $p): array {exec('ffprobe -v error -show_entries format=duration:format_tags:stream=codec_name,codec_type -of json '.escapeshellarg($p),$o);$j=json_decode(implode("\n",$o),true)?:[];return $j;};
 $t0=microtime(true);[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>$AK]);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$aid=$m[1]??'';
 ok($c===302&&$aid!=='','audio: scan of the Audio Test folder',$c.' '.($hd['location']??''));printf("audio scan + plan: %.1f s\n",microtime(true)-$t0);
 if($aid!==''){
  $ab=batch($aid);$an=[];foreach($ab['rows'] as $rid=>$r)if(empty($r['support']))$an[basename($r['source'])]=$rid;
  echo "audio rows: ",implode(' | ',array_map(fn($n)=>$n.' => '.($ab['rows'][$an[$n]]['type']).' '.($ab['rows'][$an[$n]]['media']['action']??'-').' -> '.$ab['rows'][$an[$n]]['target'],array_keys($an))),"\n";
  $W=['01 Sunrise.wma'=>['Music','mp3','/Road Trip Mix/01 Sunrise.mp3'],'02 Good song.mp3'=>['Music','none','/Road Trip Mix/02 Good song.mp3'],'03 Studio take.aiff'=>['Music','mp3','/Road Trip Mix/03 Studio take.mp3'],
   '04 Voice memo.wav'=>['Music','none','/Road Trip Mix/04 Voice memo.wav'],'05 Long cut.wma'=>['Music','mp3','/Road Trip Mix/05 Long cut.mp3'],'Broken download.mp3'=>['Music','damaged',''],'Guns Germs and Steel.mp3'=>['Audiobooks','damaged','']];
  foreach($W as $n=>[$ty,$ac,$tg]){$r=$ab['rows'][$an[$n]??'']??[];
   ok(($r['type']??'')===$ty&&($r['media']['action']??'')===$ac&&($tg===''||str_ends_with((string)($r['target']??''),$tg)),"audio plan $n: $ty, $ac".($tg!==''?", -> ...$tg":''),json_encode([$r['type']??null,$r['media']??null,$r['target']??null],JSON_UNESCAPED_UNICODE));
   if($ac==='damaged')ok(!empty($r['damaged'])&&empty($r['include']),"audio: $n flagged damaged and not ticked",json_encode([$r['damaged']??null,$r['include']??null]));}
  ok(str_contains((string)($ab['rows'][$an['Guns Germs and Steel.mp3']??'']['media']['why']??''),'cut short'),'audio: the 4 KB "Guns Germs and Steel.mp3" is caught as cut short (ffprobe alone accepts it)',json_encode($ab['rows'][$an['Guns Germs and Steel.mp3']??'']['media']??null,JSON_UNESCAPED_UNICODE));
  [$c,,$ah]=http('GET','/bulk-import-guided.php?id='.$aid);
  ok(substr_count($ah,'Will be converted to MP3: ')===3,'audio: 3 cards say "Will be converted to MP3"',(string)substr_count($ah,'Will be converted to MP3: '));
  ok(substr_count($ah,'data-damaged="1"')===2&&str_contains($ah,'Can’t be played — file is damaged'),'audio: 2 cards say "Can’t be played — file is damaged"');
  ok(preg_match_all('/data-role="include" [^>]*disabled/',$ah)===2,'audio: their include box is disabled');
  ok(str_contains($ah,'.chip4o.bad')&&str_contains($ah,'Will be converted to MP3'),'audio: keyboard screens have the MP3 and damaged chips');
  /* try to include everything, damaged ones too: the server leaves them out */
  $d=[];foreach($ab['rows'] as $rid=>$r)if(empty($r['support']))$d[$rid]=['include'=>true,'title'=>$r['title']];
  for($i=0;$i<60;$i++){[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$aid,'action'=>'preview','draft'=>json_encode($d)]);$ab=batch($aid);
   if($ab['state']==='approved'||array_filter((array)($ab['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
  $rec=array_values(array_filter((array)($ab['issues']??[]),fn($x)=>($x['kind']??'')==='recent'));
  if($ab['state']!=='approved'&&$rec){
   /* The same clips were staged a minute ago from the acceptance folder copy (Desktop\Import Acceptance Test\Audio Acceptance) in this
      throwaway importer, so the page rightly asks to confirm adding them again. Check that only those are flagged, then confirm. */
   $recN=[];foreach($rec as $x)$recN[]=basename((string)($ab['rows'][$x['id']??'']['source']??''));sort($recN);$expN=array_keys(array_intersect_key($accAudio,$an));sort($expN);
   ok($recN===$expN,'audio: only the files staged earlier in this test (acceptance copy) are flagged "already imported"',implode(',',$recN).' vs '.implode(',',$expN));
   foreach($rec as $x){$rid=$x['id']??'';if(isset($d[$rid]))$d[$rid]+=['dup_decision'=>'add','duplicate_add'=>true];}
   for($i=0;$i<60;$i++){[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$aid,'action'=>'preview','draft'=>json_encode($d)]);$ab=batch($aid);
    if($ab['state']==='approved'||array_filter((array)($ab['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}}
  elseif(!$accAudio)ok(!$rec,'audio: nothing flagged "already imported" in a fresh importer');
  ok($ab['state']==='approved','audio: review -> approved',$ab['state'].' '.json_encode($ab['issues']??null,JSON_UNESCAPED_UNICODE));
  $inc=array_keys(array_filter($an,fn($rid)=>!empty($ab['rows'][$rid]['include'])));sort($inc);
  ok($inc===['01 Sunrise.wma','02 Good song.mp3','03 Studio take.aiff','04 Voice memo.wav','05 Long cut.wma'],'audio: ticking a damaged file is refused by the server (5 of 7 included)',implode(',',$inc));
  if($ab['state']==='approved'){
   [$c,,$ah]=http('GET','/bulk-import-guided.php?id='.$aid);$aj=p4o_data($ah)?:[];ok(($aj['aconv']??-1)===3&&($aj['damaged']??-1)===2,'audio: screen data: 3 to convert to MP3, 2 damaged',json_encode([$aj['aconv']??null,$aj['damaged']??null]));
   $t0=microtime(true);[$c]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$aid,'action'=>'execute','revision'=>$ab['approval'],'confirm'=>'ADD']);
   ok(microtime(true)-$t0<10&&batch($aid)['state']==='converting','audio: publish starts converting in the background',batch($aid)['state']);
   $seen=[];$t1=microtime(true);while(microtime(true)-$t1<600){[$c,,$j]=http('GET','/bulk-import-guided.php?id='.$aid.'&progress=1&token='.rawurlencode($csrf));$j=json_decode($j,true);if(!is_array($j)){usleep(300000);continue;}
    if(($j['state']??'')!=='converting')break;$p=$j['progress']??null;if($p&&($p['phase']??'')==='converting'){$k=($p['file']??'').'|'.($p['label']??'');if(!isset($seen[$k])){$seen[$k]=1;printf("  audio progress %5.1fs %s: %s %s%%\n",microtime(true)-$t1,$p['file'],$p['label'],$p['pct']??'');}}usleep(250000);}
   ok(count(array_filter(array_keys($seen),fn($k)=>str_contains($k,'→ MP3')))>=1,'audio: the progress panel shows the MP3 conversion',implode(' ; ',array_keys($seen)));
   $ab=batch($aid);ok($ab['state']==='complete','audio: staging complete',$ab['state'].' '.end($ab['events']));echo "audio events:\n  ",implode("\n  ",array_slice($ab['events'],-4)),"\n";
   foreach(['01 Sunrise.wma','03 Studio take.aiff'] as $n){$rid=$an[$n];$op=$ab['operations'][$rid]??[];$dst="$real/$aid/files/".($op['target']??'x');$src=$ab['src_root'].'/'.$ab['rows'][$rid]['source'];
    $o=$atag($dst);$s=$atag($src);$oc=($o['streams'][0]['codec_name']??'');
    ok(($op['state']??'')==='copied'&&is_file($dst)&&str_ends_with($dst,'.mp3')&&$oc==='mp3'&&($op['media']['action']??'')==='mp3',"audio: $n staged as MP3",json_encode([$op['state']??null,$oc]));
    ok(abs(dur($o)-dur($s))<=1.0,"audio: $n: duration within 1 s",sprintf('%.3f vs %.3f',dur($o),dur($s)));
    ok(hash_file('sha256',$dst)===$op['sha256']&&filesize($dst)===$op['bytes'],"audio: $n: op.sha256/op.bytes describe the staged MP3");
    ok(filesize($src)===$ab['rows'][$rid]['bytes']&&filemtime($src)===$ab['rows'][$rid]['mtime'],"audio: $n: original untouched");
    exec('ffmpeg -nostdin -v error -xerror -i '.escapeshellarg($dst).' -f null - 2>&1',$eo,$ec);ok($ec===0,"audio: $n: the MP3 decodes from start to end");}
   $t=array_change_key_case((array)($atag("$real/$aid/files/".$ab['operations'][$an['01 Sunrise.wma']]['target'])['format']['tags']??[]));
   ok(($t['title']??'')==='Sunrise'&&($t['album']??'')==='Road Trip Mix'&&($t['artist']??'')==='Test Band'&&(int)($t['track']??0)===1,'audio: tags kept (title, artist, album, track number)',json_encode($t));
   foreach(['02 Good song.mp3','04 Voice memo.wav'] as $n){$rid=$an[$n];$op=$ab['operations'][$rid]??[];$dst="$real/$aid/files/".($op['target']??'x');
    ok(($op['state']??'')==='copied'&&is_file($dst)&&hash_file('sha256',$dst)===hash_file('sha256',$ab['src_root'].'/'.$ab['rows'][$rid]['source']),"audio: $n already plays in browsers -> copied byte for byte");}
   $lr=$an['05 Long cut.wma'];
   ok(!isset($ab['operations'][$lr])&&empty($ab['rows'][$lr]['include'])&&!is_file("$real/$aid/files/".preg_replace('/\.wma$/','.mp3',(string)$ab['rows'][$lr]['target'])),'audio: forced failure (WMA cut in half: the MP3 check finds it short) -> left out, nothing staged for it',json_encode([$ab['operations'][$lr]??null,$ab['rows'][$lr]['audio_skipped']??null],JSON_UNESCAPED_UNICODE));
   ok(str_contains(json_encode($ab['audio_skipped']??[],JSON_UNESCAPED_UNICODE),'05 Long cut.wma'),'audio: recorded in audio_skipped');
   foreach($an as $n=>$rid)if(!empty($ab['rows'][$rid]['damaged']))ok(!isset($ab['operations'][$rid]),"audio: damaged $n not staged");
   $staged=[];foreach($ab['operations'] as $op)if(($op['state']??'')==='copied')$staged[]=basename((string)$op['target']);sort($staged);echo "audio staged: ",implode(', ',$staged),"\n";
   $bad=[];foreach($ab['operations'] as $op){if(($op['state']??'')!=='copied'||!preg_match('/\.(mp3|wav)$/',(string)$op['target']))continue;exec('ffmpeg -nostdin -v error -xerror -i '.escapeshellarg("$real/$aid/files/".$op['target']).' -f null - 2>&1',$eo2,$ec2);if($ec2!==0)$bad[]=$op['target'];}
   ok(count($staged)>=4&&!$bad,'audio: every staged audio file plays (nothing unplayable reaches publishing)',implode(',',$bad));
   ok(!is_dir("$real/$aid/work"),'audio: work folder removed');
   [$c,,$ah]=http('GET','/bulk-import-guided.php?id='.$aid);ok(str_contains($ah,'Some files could not be converted')&&str_contains($ah,'can’t be converted to MP3')&&str_contains($ah,'nothing unplayable is published'),'audio: the page explains the left-out file in plain words');
   $aj=p4o_data($ah)?:[];ok(count($aj['auSkip']??[])===1&&str_contains((string)($aj['auSkip'][0]??''),'05 Long cut.wma'),'audio: keyboard screens list it under "Left out of this import"',json_encode($aj['auSkip']??null,JSON_UNESCAPED_UNICODE));
   [$c,$hd,$body]=http('GET','/bulk-import-guided.php?id='.$aid.'&open='.$an['01 Sunrise.wma'],[],['Range: bytes=0-99']);ok($c===206&&($hd['content-type']??'')==='audio/mpeg','audio: Open serves the MP3 as audio/mpeg',$c.' '.($hd['content-type']??''));
  }
  echo "BATCHAUDIO $aid\n";}
}else ok(false,'audio: test source "Audio Test" missing (make-audio-clips.sh)');
/* 19. Patch 4p: a copy of Import Big Test with the 4p extra files (make-4p-clips.sh): left-out list, damaged video, books waiting for a
   shelf chosen by you, shelf name check, new places for the confirm, staging, and the Done summary (fake publish status, throwaway data only). */
$BIG=getenv('P4P_BIG')?:'h:c/Users/Edutek/Desktop/Import Big Test 4p';$bigDir='/host/'.substr($BIG,2);$bigName=basename($bigDir);
if(is_dir($bigDir)){
 $disk=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bigDir,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$disk[]=substr($f->getPathname(),strlen($bigDir)+1);sort($disk);
 $pdfs=array_values(array_filter($disk,fn($p)=>preg_match('/\.pdf$/i',$p)===1));
 echo "big test copy: ",count($disk)," files, ",count($pdfs)," PDF\n";
 [$c,,$h19]=http('GET','/bulk-import-guided.php');$csrf=csrf($h19)?:$csrf;
 $t0=microtime(true);[$c,$hd]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'action'=>'scan','source_key'=>$BIG]);preg_match('/id=([a-f0-9]{32})/',$hd['location']??'',$m);$gid=$m[1]??'';
 ok($c===302&&$gid!=='','4p: scan of the Big Test copy',$c.' '.($hd['location']??''));printf("big test scan: %.1f s\n",microtime(true)-$t0);
 if($gid!==''){$g=batch($gid);$rows=array_filter($g['rows'],fn($r)=>empty($r['support']));$by=[];foreach($rows as $rid=>$r)$by[(string)$r['source']]=$rid;
  $lo=[];foreach((array)($g['left_out']??[]) as $x)$lo[(string)$x['source']]=(string)$x['why'];ksort($lo);
  $art=[];foreach($g['rows'] as $r)foreach((array)($r['art_options']??[]) as $o)$art[(string)($o['source']??'')]=1;
  $miss=[];foreach($disk as $p)if(!isset($by[$p])&&!isset($lo[$p])&&!isset($art[$p]))$miss[]=$p;
  ok(!$miss,'4p: every file in the folder is imported, used as cover art, or listed as left out (nothing silently skipped: '.count($disk).' files)',implode(' | ',$miss));
  $wantLo=['Geography/Volcanos/Lava Flow Camera.mkv','Physics/Simple Machines/Gears Phone Clip.mov','Stray Photo.png','Test Health/First Aid/notes.txt'];
  ok(array_keys($lo)===$wantLo&&(int)($g['left_out_n']??-1)===4,'4p: left out with a reason: the .mkv, the .mov, the stray picture and the text file',json_encode($lo,JSON_UNESCAPED_UNICODE));
  ok(str_contains($lo['Geography/Volcanos/Lava Flow Camera.mkv']??'','only .mp4 videos are imported')&&str_contains($lo['Physics/Simple Machines/Gears Phone Clip.mov']??'','4i conversion'),'4p: .mov/.mkv reason says only .mp4 is imported and mentions the 4i conversion as a later option');
  ok(str_contains($lo['Stray Photo.png']??'','picture')&&str_contains($lo['Test Health/First Aid/notes.txt']??'','not a video'),'4p: picture and text file reasons in plain words');
  ok(isset($art['Welcome to the Big Test.jpg'])&&!isset($lo['Welcome to the Big Test.jpg']),'4p: a picture with the same name as a video is its cover art, not left out');
  $er=$rows[$by['Geography/Solar System/Eclipse Half Download.mp4']??'']??[];
  ok(!empty($er['damaged'])&&empty($er['include'])&&($er['media']['action']??'')==='damaged'&&str_contains((string)($er['media']['why']??''),'cut short'),'4p: the cut-short video is damaged, not ticked, with the reason',json_encode([$er['damaged']??null,$er['include']??null,$er['media']??null],JSON_UNESCAPED_UNICODE));
  $books=array_filter($rows,fn($r)=>($r['type']??'')==='Books');$bad=[];foreach($books as $r)if((string)$r['placement_folder']!==''||!str_contains((string)$r['target'],'/(choose a shelf)/'))$bad[]=$r['source'].' -> '.$r['target'];
  ok(count($books)===count($pdfs)&&!$bad,'4p: all '.count($pdfs).' books wait for a shelf (none shelved by folder name, e.g. Books/Science Readers)',implode(' | ',$bad));
  $vm=$rows[$by['Big Test Voice Memo.wav']??'']??[];ok(($vm['type']??'')==='Music'&&str_starts_with((string)($vm['target']??''),'Music/'.$bigName.'/'),'4p: loose audio still goes to Music › '.$bigName,(string)($vm['target']??''));
  [$c,,$gh]=http('GET','/bulk-import-guided.php?id='.$gid);$gj=p4o_data($gh)?:[];$p4=$gj['p4']??[];
  ok(($p4['v']??0)===1,'4p: screen data has the 4p part',json_encode(array_slice($p4,0,3)));
  $k=[];foreach((array)($p4['left']??[]) as $x)$k[(string)$x['f']]=(string)$x['k'];
  $nd=0;foreach($rows as $r)if(!empty($r['damaged'])||!empty($r['audio_skipped']))$nd++;$LN=$nd+4;
  ok(($p4['leftN']??0)===$LN&&($k['Geography/Solar System/Eclipse Half Download.mp4']??'')==='damaged'&&($k['Geography/Volcanos/Lava Flow Camera.mkv']??'')==='video'&&($k['Physics/Simple Machines/Gears Phone Clip.mov']??'')==='video'&&($k['Stray Photo.png']??'')==='other'&&($k['Test Health/First Aid/notes.txt']??'')==='other','4p: first screen data lists all '.$LN.' left out ('.$nd.' damaged, 2 videos of another type, 2 other files)',json_encode($k,JSON_UNESCAPED_UNICODE));
  ok(($p4['books']??0)===count($pdfs),'4p: screen data counts the books ('.count($pdfs).')');
  ok(in_array('Music › '.$bigName,array_column((array)($p4['loose']??[]),'to'),true),'4p: screen data says where the loose audio goes',json_encode($p4['loose']??null,JSON_UNESCAPED_UNICODE));
  $SHF=getenv('P4P_SHELVES')?:'/p4l/shelf-names.txt';$shelves=is_file($SHF)?array_values(array_filter(array_map(fn($l)=>rtrim($l,"\r"),explode("\n",preg_replace('/^\xEF\xBB\xBF/','',(string)file_get_contents($SHF)))),fn($l)=>$l!=='')):[];
  $got=(array)($p4['shelves']??[]);$a=$got;$b2s=$shelves;sort($a,SORT_STRING);sort($b2s,SORT_STRING);
  ok(!$shelves||$a===$b2s,'4p: the shelf picker offers exactly the library Books shelves ('.count($got).')',count($shelves).' expected');
  $ex=$got[0]??'';
  $cs=function(string $n)use($csrf,$gid){[$c,,$b]=http('POST','/bulk-import-guided.php?id='.$gid,['csrf'=>$csrf,'action'=>'check_shelf','name'=>$n]);return [$c,json_decode($b,true)?:[]];};
  if($ex!==''){[$c,$j]=$cs(mb_strtoupper($ex));ok(($j['ok']??null)===true&&($j['exists']??null)===true&&($j['name']??'')===$ex,'4p: a typed shelf name in other capitals picks the existing shelf "'.$ex.'"',json_encode($j,JSON_UNESCAPED_UNICODE));}
  [$c,$j]=$cs('E2E New Shelf');ok(($j['ok']??null)===true&&($j['exists']??null)===false&&($j['name']??'')==='E2E New Shelf','4p: C: a new shelf name is accepted (created only when publishing)',json_encode($j,JSON_UNESCAPED_UNICODE));
  [$c,$j]=$cs('Science/Readers');ok(($j['ok']??null)===false&&($j['message']??'')!=='','4p: a shelf name with / is refused with a suggestion',json_encode($j,JSON_UNESCAPED_UNICODE));
  [$c,$j]=$cs('   ');ok(($j['ok']??null)===false,'4p: an empty shelf name is refused');
  [$c,,$x]=http('POST','/bulk-import-guided.php?id='.$gid,['csrf'=>'nope','action'=>'check_shelf','name'=>'x']);ok($c===403,'4p: shelf check needs the page token',(string)$c);
  /* Next is blocked while a book has no shelf */
  [,,$tkh]=http('GET','/bulk-import-guided.php?id='.$gid);$tok=[];if(preg_match_all('/data-id="([^"]+)"[^>]*?data-token="([^"]*)"/',$tkh,$mm,PREG_SET_ORDER))foreach($mm as $m1)$tok[html_entity_decode($m1[1])]=html_entity_decode($m1[2]);
  ok(count($tok)>=40,'4p: Big Test copy review rows carry their safe-name tokens',(string)count($tok));
  $draft=[];foreach($rows as $rid=>$r){$draft[$rid]=['include'=>true,'title'=>$r['title'],'dup_decision'=>'add','duplicate_add'=>true,'fix_accept'=>$tok[$rid]??''];if(($r['placement_category']??'')==='')$draft[$rid]['category']=$pickCat;}
  $np4=fn($g)=>array_values(array_filter((array)($g['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'));
  for($i=0;$i<300;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$gid,'action'=>'preview','draft'=>json_encode($draft)]);$g=batch($gid);if($g['state']==='approved'||$np4($g))break;sleep(2);}
  ok($g['state']!=='approved'&&str_contains(json_encode($np4($g),JSON_UNESCAPED_UNICODE),'"kind":"shelf"'),'4p: a book with no shelf blocks Next (the review says to choose a shelf)',$g['state'].' '.substr(json_encode($np4($g),JSON_UNESCAPED_UNICODE),0,400));
  $oth=array_filter($np4($g),fn($x)=>!str_contains((string)($x['message']??''),'shelf'));ok(!$oth,'4p: nothing else blocks the Big Test copy',substr(json_encode(array_values($oth),JSON_UNESCAPED_UNICODE),0,400));
  /* choose shelves: the first book is skipped, Science Readers books go on an existing shelf, the rest on a new shelf */
  $skipB='';$shelfOf=[];foreach($books as $rid=>$r){if($skipB===''){$skipB=$rid;$draft[$rid]['include']=false;continue;}$s=($ex!==''&&str_starts_with((string)$r['source'],'Books/Science Readers/'))?$ex:'E2E New Shelf';$shelfOf[$rid]=$s;$draft[$rid]+=['category'=>'Books','folder'=>$s,'placement_override'=>true];}
  for($i=0;$i<300;$i++){http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$gid,'action'=>'preview','draft'=>json_encode($draft)]);$g=batch($gid);if($g['state']==='approved'||array_filter((array)($g['issues']??[]),fn($x)=>($x['kind']??'')!=='preview'))break;sleep(2);}
  ok($g['state']==='approved','4p: with a shelf for every book (one skipped) the import can go on',$g['state'].' '.substr(json_encode($np4($g)?:($g['issues']??null),JSON_UNESCAPED_UNICODE),0,400));
  $er=$g['rows'][$by['Geography/Solar System/Eclipse Half Download.mp4']]??[];ok(empty($er['include']),'4p: ticking the damaged video is refused by the server');
  $bad=[];foreach($shelfOf as $rid=>$s){$t=(string)$g['rows'][$rid]['target'];if(!str_starts_with($t,'Books/'.$s.'/'))$bad[]=$t;}ok($shelfOf&&!$bad,'4p: each book goes to the shelf you chose',implode(' | ',$bad));
  [$c,,$gh]=http('GET','/bulk-import-guided.php?id='.$gid);$np=(p4o_data($gh)['p4']['newPlaces']??null);
  $wc=[];$ws=[];foreach($g['rows'] as $r){if(!empty($r['support'])||empty($r['include']))continue;if(($r['type']??'')==='Books'){if(!in_array(mb_strtolower((string)$r['placement_folder']),array_map('mb_strtolower',$got),true))$ws[(string)$r['placement_folder']]=1;continue;}
   if(($r['type']??'')==='Videos'&&!in_array(mb_strtolower((string)$r['placement_category']),$libKeys,true))$wc[(string)$r['placement_category']]=1;}
  $nc=(array)($np['cats']??[]);sort($nc);$wc=array_keys($wc);sort($wc);
  ok(is_array($np)&&$nc===$wc&&count($wc)>=1,'4p: the confirm before publishing lists the new categories ('.implode(', ',$wc).')',json_encode($np,JSON_UNESCAPED_UNICODE));
  ok((array)($np['shelves']??[])===array_keys($ws)&&in_array('E2E New Shelf',(array)($np['shelves']??[]),true)&&!in_array($ex,(array)($np['shelves']??[]),true),'4p: and the new shelves only (an existing shelf is not new)',json_encode($np['shelves']??null,JSON_UNESCAPED_UNICODE));
  $sb=(array)($np['subs']??[]);$okS=true;foreach($sb as $s)if(!in_array(explode(' › ',$s)[0],$wc,true))$okS=false;ok($sb&&$okS,'4p: and the subfolders under the new categories ('.count($sb).')',json_encode($sb,JSON_UNESCAPED_UNICODE));
  /* stage */
  [$c]=http('POST','/bulk-import-guided.php',['csrf'=>$csrf,'id'=>$gid,'action'=>'execute','revision'=>$g['approval'],'confirm'=>'ADD']);$t1=microtime(true);
  while(microtime(true)-$t1<1500){$g=batch($gid);if(!in_array($g['state'],['approved','converting','copying'],true))break;http('GET','/bulk-import-guided.php?id='.$gid.'&progress=1&token='.rawurlencode($csrf));sleep(2);}
  $g=batch($gid);ok($g['state']==='complete','4p: Big Test copy staged',$g['state'].' '.end($g['events']));printf("big test staged in %.1f s\n",microtime(true)-$t1);
  $inc=0;$stg=0;foreach($g['rows'] as $rid=>$r){if(!empty($r['support'])||empty($r['include']))continue;$inc++;if(($g['operations'][$rid]['state']??'')==='copied'&&is_file("$real/$gid/files/".$g['operations'][$rid]['target']))$stg++;}
  ok($inc>0&&$stg===$inc,"4p: every included item staged ($stg of $inc)");
  ok(!isset($g['operations'][$by['Geography/Solar System/Eclipse Half Download.mp4']])&&!isset($g['operations'][$skipB]),'4p: the damaged video and the skipped book are not staged');
  $tg=[];foreach($g['operations'] as $op)$tg[]=(string)($op['target']??'');$leak=array_filter($tg,fn($t)=>preg_match('/\.(mkv|mov|png|txt)$/i',$t)===1||str_contains($t,'(choose a shelf)'));
  ok(!$leak,'4p: no left-out file and no shelf-less book reaches staging',implode(' | ',$leak));
  /* Done summary (fake "published" status in the THROWAWAY data folder, removed again) */
  $PS4=dirname($real).'/publish-status';if(!is_dir($PS4))@mkdir($PS4,0770,true);$sf="$PS4/$gid.json";$had=is_file($sf);
  if(!$had){$st0=gmdate('Y-m-d\TH:i:s\Z',time()-125);$fi0=gmdate('Y-m-d\TH:i:s\Z',time()-3);file_put_contents($sf,json_encode(['batch'=>$gid,'state'=>'published','started'=>$st0,'finished'=>$fi0,'updated'=>date('c'),'note'=>'e2e 4p fake status (throwaway)']));
   [$c,,$gh]=http('GET','/bulk-import-guided.php?id='.$gid);$gj=p4o_data($gh)?:[];$d=$gj['p4']['done']??null;
   ok(is_array($d),'4p: Done screen data present once published',substr(json_encode($gj['p4']??null,JSON_UNESCAPED_UNICODE),0,300));
   if(is_array($d)){$tot=$d['tot']??[];
    ok(($tot['books']??-1)===count($shelfOf)&&count($d['docs']??[])===count($shelfOf),'4p: Done: books counted and one Open PDF entry per book ('.count($shelfOf).')',json_encode([$tot['books']??null,count($d['docs']??[])]));
    ok(($tot['videos']??0)+($tot['books']??0)+($tot['music']??0)+($tot['audiobooks']??0)===$inc,'4p: Done: totals add up to what was staged ('.$inc.')',json_encode($tot));
    ok(is_int($d['secs']??null)&&$d['secs']>=0,'4p: Done: time taken is worked out from the publish times',json_encode($d['secs']??null));
    $gc=[];foreach((array)($d['groups']??[]) as $x)if(($x['type']??'')==='Videos')$gc[]=$x['cat'];$wv=[];foreach($g['rows'] as $rid=>$r)if(empty($r['support'])&&!empty($r['include'])&&($r['type']??'')==='Videos')$wv[(string)$r['placement_category']]=1;$wv=array_keys($wv);sort($wv,SORT_FLAG_CASE|SORT_STRING);$gs=$gc;sort($gs,SORT_FLAG_CASE|SORT_STRING);
    ok($gs===$wv,'4p: Done: one group per category ('.count($gc).'), each with its subcategory counts',json_encode($gc,JSON_UNESCAPED_UNICODE));
    $sum=0;foreach((array)($d['groups']??[]) as $x){$n=0;foreach($x['subs'] as $s)$n+=$s['n'];if($n!==$x['n'])$sum++;}ok($sum===0,'4p: Done: subcategory counts add up to each category');
    ok(($d['leftN']??0)===$LN&&count($d['left']??[])===$LN,'4p: Done: the left-out list ('.$LN.') is shown again',json_encode($d['leftN']??null));
    ok(in_array('E2E New Shelf',(array)($d['newShelves']??[]),true)&&count((array)($d['newCats']??[]))===count($wc),'4p: Done: new categories and shelves are marked new');
    $L=$d['links']??null;ok(is_array($L)&&(($L['ok']??false)===true||($L['note']??'')!==''),'4p: Done: category links read from the site, or a note says the fallback link is used',json_encode(['ok'=>$L['ok']??null,'note'=>$L['note']??null],JSON_UNESCAPED_UNICODE));
    echo '  links: ',(($L['ok']??false)?'site reachable':'site not reachable -> fallback: '.($L['note']??'')),"\n";}
   [$c,,$x]=http('GET','/bulk-import-guided.php?id='.$gid.'&p4links=1&token='.rawurlencode($csrf));$lj=json_decode($x,true);ok($c===200&&is_array($lj)&&array_key_exists('ok',$lj)&&isset($lj['cats']),'4p: the "updating…" link refresh answers with JSON',$c.' '.substr($x,0,200));
   [$c]=http('GET','/bulk-import-guided.php?id='.$gid.'&p4links=1&token=nope');ok($c===403,'4p: link refresh needs the page token',(string)$c);
   ok(str_contains($gh,"location.protocol+'//'+location.hostname+':8080/'")&&!preg_match('#https?://(\d{1,3}\.){3}\d{1,3}:8080#',$gh),'4p: links use the address this page was opened with, port 8080 (no fixed IP in the page)');
   unlink($sf);ok(!is_file($sf),'4p: fake publish status removed again');}
  else ok(false,'4p: a publish status for the Big Test batch already exists (unexpected in a throwaway folder)');
  echo "BATCHBIG $gid\n";}
}else ok(false,'4p: Big Test copy missing: '.$bigDir.' (the installer copies Desktop\Import Big Test and runs make-4p-clips.sh)');
p4o_sum();
echo "BATCH $id\nBATCH2 $id2\n";
echo "e2e-p4p: $pass passed, $fail failed\n";if($fail===0)echo "E2E TESTS PASSED\n";exit($fail===0?0:1);
