<?php
/* Patch 4p unit tests.
   php unit-p4p.php <edutek-safe-name.php> <bulk-import-guided.php (4p)> <pairs-4p.json> [unit-p4o.php]
   1. Undo: the 4p page with its 43 listed changes undone is byte-for-byte the live Patch 4o page (SHA-256 9B894605...A8E6);
      with unit-p4o.php given, the whole 4o unit suite then runs on that rebuilt page (everything 4o did still holds).
   2. 4p functions extracted from the page (the 4f way) and run on a throwaway library + throwaway files:
      damaged/truncated videos, left-out files with reasons, Books never shelved by folder name, the shelf name check,
      new categories/shelves before publishing, site links (parsed from the site's own pages), the import summary.
   Read-only: touches only a temp folder. Needs ffmpeg/ffprobe for section 3 (present in the web container). */
declare(strict_types=1);
[$self,$mod,$imp,$pairsF,$u4o]=$argv+[null,null,null,null,null];if(!$mod||!$imp||!$pairsF){fwrite(STDERR,"usage: php unit-p4p.php <edutek-safe-name.php> <bulk-import-guided.php> <pairs-4p.json> [unit-p4o.php]\n");exit(2);}
require $mod;
$fail=0;$pass=0;
function ok(bool $c,string $what,string $got=''){global $fail,$pass;if($c){$pass++;}else{$fail++;echo "FAIL $what".($got!==''?"  got: $got":'')."\n";}}
function eq($got,$want,string $what){ok($got===$want,$what.' => '.json_encode($want,JSON_UNESCAPED_UNICODE),json_encode($got,JSON_UNESCAPED_UNICODE));}
$code=(string)file_get_contents($imp);
/* 1. undo */
$P=json_decode((string)file_get_contents($pairsF),true);ok(is_array($P)&&count($P)===43,'4p: 43 changes listed',is_array($P)?(string)count($P):'not JSON');
$c4=$code;$okRev=true;foreach(array_reverse((array)$P) as $i=>[$a,$b]){if(substr_count($c4,$b)!==1){$okRev=false;echo "     change ".(count($P)-$i)." found ".substr_count($c4,$b)." times\n";continue;}$c4=str_replace($b,$a,$c4);}
ok($okRev,'4p: every 4p change is found exactly once');
$H4O='9B894605F4244DE79CD7E323ED9CD7F62056769A836FF76B406789C9D09AA8E6';
ok(strtoupper(hash('sha256',$c4))===$H4O,'4p: without the 4p changes the page is byte-for-byte the live Patch 4o page (Rollback = 4o)',strtoupper(hash('sha256',$c4)));
ok(substr_count($code,'Patch 4p (damaged or unreadable videos')===1&&str_contains($code,'Patch 4o (keyboard-first'),'4p: header marker (once), 4o marker kept');
if($u4o){$t=sys_get_temp_dir().'/p4p-rebuilt-'.bin2hex(random_bytes(4));mkdir($t);file_put_contents("$t/bulk-import-guided.php",$c4);
 exec('php '.escapeshellarg($u4o).' '.escapeshellarg($mod).' '.escapeshellarg("$t/bulk-import-guided.php").' 2>&1',$o4,$rc4);exec('rm -rf '.escapeshellarg($t));
 $last=''; foreach($o4 as $l)if(str_starts_with($l,'unit-p4o:'))$last=$l;
 ok($rc4===0,'4p: the 4o unit suite passes on the rebuilt 4o page ('.$last.')',implode(' | ',array_slice($o4,-6)));echo "     (4o suite on the rebuilt page: $last)\n";}
/* 2. functions from the page */
$want=['cleancomponent','bi_tidy_title','bi_dupkey','keyname','dx_key','dx_len','dx_ext','gp_lib_dir','gp_clean_part','gp_clean_path','gp_title_edited','gp_requested','gp_role','gp_component','gp_validate','gp_init','gp_folder','gp_target','gp_set','gp_decollide','needs_fix','needs_fix_why','gp_folder_auto','gp_folder_note','gp_lib_state','gp_lib_categories','gp_category_match','gp_no_default','gp_scan_place','gp_check_category','gp_new_categories','gp_group_label','gp_auto_category','learning','gp_used_newcats','gp_batch_newcats','gp_propose','gp_newcat_seed','gp_newcat_canon','gp_new_category_counts','gp_newcat_text'];
$want=array_merge($want,['p4_left_reason','p4_left_add','p4_left_finish','p4_mp4_integrity','p4_probe_works','p4_shelves','p4_shelf_match','p4_check_shelf','p4_new_places','p4_site','p4_parse_links','p4_slug','p4_summary','p4_links_for','p4_left_list',
 'mc_atoms','mc_moov_first','mc_run','mc_probe','mc_codec_name','mc_plan','mc_is_video','mc_effective','mc_needs_job','mc_badge_html','mc_row_plan']);
$tok=token_get_all($code);$fn=[];$cnt=count($tok);
for($i=0;$i<$cnt;$i++){if(!is_array($tok[$i])||$tok[$i][0]!==T_FUNCTION)continue;$j=$i+1;while(is_array($tok[$j])&&$tok[$j][0]===T_WHITESPACE)$j++;if(!is_array($tok[$j])||$tok[$j][0]!==T_STRING||!in_array($tok[$j][1],$want,true))continue;
 $name=$tok[$j][1];$src='';$depth=0;$started=false;for($k=$i;$k<$cnt;$k++){$t=$tok[$k];$s=is_array($t)?$t[1]:$t;$src.=$s;if($s==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}elseif($s==='}'){$depth--;if($started&&$depth===0)break;}}
 $fn[$name]=$src;}
foreach($want as $w)ok(isset($fn[$w]),"4p: found function $w");
ok(preg_match('/^const GP_BOOKS_DEFAULT_FOLDER=\'\'; const GP_NOSHELF=\'\(choose a shelf\)\';/m',$code)===1,'4p: books have no default shelf (GP_BOOKS_DEFAULT_FOLDER is empty)');
ok(!str_contains($code,"const GP_BOOKS_DEFAULT_FOLDER='Books';"),'4p: the 4h Books/Books default is gone');
const GP_RULES=3;const GP_BOOKS_DEFAULT_FOLDER='';const GP_NOSHELF='(choose a shelf)';const DX_NAME_MIN=12;const GP_NO_DEFAULT=['Unsorted'];const GP_NOCAT='(choose a category)';
preg_match('/^const MC_MAX_SIDE=.*$/m',$code,$m);eval($m[0]);const BI_ROOT='/nonexistent-p4p-unit';const MC_LEDGER=BI_ROOT.'/transcode-ledger';
preg_match_all('/^const P4_[A-Z_]+=.*$/m',$code,$m);ok(count($m[0])===4,'4p: 4 settings lines');foreach($m[0] as $l)eval($l);
eq(P4_SITE_DEFAULT,'http://host.docker.internal:8080/','4p: server-side site address is the Docker host name (no hard-coded IP)');
ok(!preg_match('/https?:\/\/(\d{1,3}\.){3}\d{1,3}/',substr($code,(int)strpos($code,'Patch 4p: damaged videos'))),'4p: no IP address written anywhere in the 4p code');
$LIB=sys_get_temp_dir().'/p4p-unit-lib-'.getmypid();$LIBCATS=['History','Science','Agriculture','Unsorted','Books','Music'];
foreach($LIBCATS as $d)@mkdir("$LIB/$d",0777,true);foreach(['Science','Agriculture','Earth Science','Cooking Food and drink','.hidden'] as $s)@mkdir("$LIB/Books/$s",0777,true);file_put_contents("$LIB/Books/loose.pdf",'x');
define('GP_LIB_DIR',$LIB);
function gp_config(): array {return ['categories'=>['Books','Music','Audiobooks','History']];}
function dx_index(): array {return ['dirs'=>['history'=>'History','books'=>'Books','books/science'=>'Books/Science']];}
function dx_apply(array &$r): void {}
function rp_apply(array &$r,string $w): void {}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function pv_input(array $r): string {return (string)$r['_p'];}
$a1=strpos($code,'/* Patch 4o audio: begin */');$a2=strpos($code,'/* Patch 4o audio: end */');if($a1!==false&&$a2!==false)eval(substr($code,$a1,$a2-$a1));
foreach($want as $w)if(isset($fn[$w])&&!function_exists($w))eval($fn[$w]);
function row(string $rel,array $extra=[],string $root='/import-source/Big Test'): array {$ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));$group=dirname($rel);if($group==='.')$group='Unsorted';
 $target=gp_clean_path($group).'/'.sn_filename(basename($rel));$t=sn_title(bi_tidy_title(pathinfo($rel,PATHINFO_FILENAME)),false);
 $r=['source'=>$rel,'group'=>$group,'type'=>$ext==='pdf'?'Books':'Videos','extension'=>$ext,'original_title'=>pathinfo($rel,PATHINFO_FILENAME),'title'=>$t,'title_auto'=>$t,'target'=>$target,'bytes'=>5,'include'=>true]+$extra;gp_scan_place($r,$root);gp_init($r);return $r;}
/* Books: never shelved by folder name */
foreach(['Science/Cells Book.pdf','Books/Sports/Dive Atlas.pdf','Loose Book.pdf','Test Health/First Aid/Bandages.pdf'] as $p){$r=row($p);
 eq($r['placement_folder'],'',"4p: book $p gets no shelf from its folder");ok(str_starts_with($r['target'],'Books/'.GP_NOSHELF.'/'),"4p: planned location of $p says (choose a shelf)",$r['target']);}
$e='';try{gp_validate('Books','Books','');}catch(Throwable $x){$e=$x->getMessage();}eq($e,'','4p: a book with no shelf yet can be saved while editing (blocked at the check)');
$r=row('Science/Cells Book.pdf');gp_set($r,['category'=>'Books','folder'=>'Science']);eq($r['target'],'Books/Science/Cells Book.pdf','4p: the shelf you choose is used');
$r=row('Science/Cells Book.pdf');gp_set($r,['category'=>'Books','folder'=>'Walk Shelf']);eq($r['target'],'Books/Walk Shelf/Cells Book.pdf','4p: a new shelf you typed is used');
$r=row('Science/Cells.mp4');eq($r['target'],'Science/Science/Cells.mp4','4p: videos still take the category from the folder (unchanged 4k rule)');
/* shelves and the shelf name check */
eq(p4_shelves(),['Agriculture','Cooking Food and drink','Earth Science','Science'],'4p: shelves = the Books folders (no files, no hidden folders), natural order');
$k=p4_check_shelf('  science ');ok($k['ok']&&$k['exists']&&$k['name']==='Science','4p: existing shelf in any case -> that shelf',json_encode($k,JSON_UNESCAPED_UNICODE));
$k=p4_check_shelf('Walk Shelf');ok($k['ok']&&!$k['exists']&&str_contains($k['message'],'only when you publish'),'4p: new shelf name accepted, made only at publish',json_encode($k,JSON_UNESCAPED_UNICODE));
$k=p4_check_shelf('Maths & Logic');ok(!$k['ok']&&($k['suggest']??'')==='Maths and Logic','4p: unclean shelf name -> suggestion',json_encode($k,JSON_UNESCAPED_UNICODE));
$k=p4_check_shelf('a/b');ok(!$k['ok']&&($k['suggest']??'')==='a b','4p: a shelf is one folder: / refused with suggestion');
foreach(['','   ',str_repeat('x',121)] as $bad)ok(!p4_check_shelf($bad)['ok'],'4p: bad shelf name refused: '.json_encode(mb_substr($bad,0,10)));
/* damaged videos */
$T=sys_get_temp_dir().'/p4p-unit-media-'.bin2hex(random_bytes(4));mkdir($T);
exec('ffmpeg -nostdin -v error -f lavfi -i testsrc=d=3:s=320x240:r=25 -f lavfi -i sine=d=3 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest '.escapeshellarg("$T/good.mp4").' 2>&1',$o,$rc);ok($rc===0,'4p: test video made');
exec('ffmpeg -nostdin -v error -f lavfi -i testsrc=d=3:s=320x240:r=25 -c:v libx264 -pix_fmt yuv420p -movflags +faststart '.escapeshellarg("$T/fast.mp4").' 2>&1',$o,$rc);
$g=(string)file_get_contents("$T/good.mp4");file_put_contents("$T/half.mp4",substr($g,0,intdiv(strlen($g),2)));file_put_contents("$T/junk.mp4",random_bytes(5000));file_put_contents("$T/empty.mp4",'');
$f=(string)file_get_contents("$T/fast.mp4");file_put_contents("$T/fast-cut.mp4",substr($f,0,strlen($f)-1000));
eq(p4_mp4_integrity("$T/good.mp4"),'','4p: a complete MP4 (index at the end) is fine');eq(p4_mp4_integrity("$T/fast.mp4"),'','4p: a complete fast-start MP4 is fine');
ok(str_contains(p4_mp4_integrity("$T/half.mp4"),'cut short (truncated)'),'4p: half a download -> cut short (truncated)',p4_mp4_integrity("$T/half.mp4"));
ok(str_contains(p4_mp4_integrity("$T/fast-cut.mp4"),'cut short'),'4p: fast-start MP4 missing its last 1000 bytes -> cut short',p4_mp4_integrity("$T/fast-cut.mp4"));
ok(p4_mp4_integrity("$T/junk.mp4")!=='','4p: random bytes -> damaged');ok(str_contains(p4_mp4_integrity("$T/empty.mp4"),'empty'),'4p: empty file -> damaged');ok(str_contains(p4_mp4_integrity("$T/none.mp4"),'could not be opened'),'4p: missing file -> damaged');
$vr=fn(string $f)=>['type'=>'Videos','extension'=>'mp4','source'=>"Geo/$f",'_p'=>"$T/$f",'bytes'=>(int)@filesize("$T/$f"),'mtime'=>1,'include'=>true];
$r=$vr('half.mp4');mc_row_plan($r);ok(($r['media']['action']??'')==='damaged'&&!empty($r['damaged'])&&$r['include']===false,'4p: truncated video -> damaged, not ticked',json_encode($r['media']??null,JSON_UNESCAPED_UNICODE));
eq(mc_effective($r),null,'4p: a damaged video is never converted or staged');ok(str_contains(mc_badge_html($r),'damaged'),'4p: card says damaged',mc_badge_html($r));ok(!mc_needs_job($r),'4p: no conversion job for a damaged video');
$r['include']=true;mc_row_plan($r);ok($r['include']===false,'4p: re-planning keeps a damaged video unticked (cached plan)');
$r=$vr('good.mp4');mc_row_plan($r);ok(($r['media']['action']??'')!=='damaged'&&empty($r['damaged'])&&$r['include']===true,'4p: a good video is planned as before',json_encode($r['media']??null));
$r=$vr('junk.mp4');mc_row_plan($r);ok(!empty($r['damaged']),'4p: junk .mp4 -> damaged');
ok(p4_probe_works(),'4p: ffprobe present (a missing ffprobe never marks videos damaged)');
exec('rm -rf '.escapeshellarg($T));
/* left out */
ok(str_contains(p4_left_reason('mov'),'only .mp4')&&str_contains(p4_left_reason('MKV'),'4i conversion'),'4p: .mov/.mkv reason mentions converting (4i)');ok(!str_contains(p4_left_reason('avi'),'4i'),'4p: other videos: convert to .mp4 first');
ok(str_contains(p4_left_reason('png'),'cover art'),'4p: picture reason');ok(str_contains(p4_left_reason('txt'),'not a video'),'4p: other files reason');
$lo=[];$n=0;foreach(['a/Clip.mov'=>'mov','b/Lava.mkv'=>'mkv','Cover.jpg'=>'jpg','Stray.png'=>'png','notes.txt'=>'txt'] as $p=>$x)p4_left_add($lo,$n,$p,$x,'/src');
$b=['rows'=>['r1'=>['source'=>'Welcome.mp4','art_options'=>[['src_root'=>'/src','source'=>'Cover.jpg']],'include'=>true],'r2'=>['source'=>'Geo/half.mp4','damaged'=>true,'include'=>false,'media'=>['why'=>'the file is cut short (truncated).']],
 'r3'=>['source'=>'x.wma','audio_skipped'=>'conversion failed','include'=>false],'r4'=>['source'=>'cover.jpg','support'=>true]]];
p4_left_finish($b,$lo,$n);eq(array_column($b['left_out'],'source'),['a/Clip.mov','b/Lava.mkv','Stray.png','notes.txt'],'4p: a picture used as cover art is not left out; the rest are listed');eq($b['left_out_n'],4,'4p: left-out count');
$L=p4_left_list($b);eq(count($L),6,'4p: left-out list = damaged + audio not converted + files not imported');
ok($L[0]['k']==='damaged'&&str_contains($L[0]['why'],'cut short')&&str_contains($L[0]['why'],'never ticked'),'4p: damaged entry says why',$L[0]['why']);
eq(array_column($L,'k'),['damaged','audio','video','video','other','other'],'4p: kinds');
$lo=[];$n=0;for($i=0;$i<1005;$i++)p4_left_add($lo,$n,"f$i.txt",'txt','/s');eq([count($lo),$n],[1000,1005],'4p: the list keeps 1000 entries but counts all');
/* new categories and shelves before publishing */
$b=['rows'=>['v1'=>row('Space Science/Planets/Mars.mp4'),'v2'=>row('History/Rome.mp4'),'v3'=>row('Space Science/Moons/Io.mp4'),'b1'=>row('x/A.pdf'),'b2'=>row('x/B.pdf'),'b3'=>row('x/C.pdf'),'v4'=>['include'=>false]+row('Robots/Arm.mp4')]];
gp_set($b['rows']['b1'],['category'=>'Books','folder'=>'Science']);gp_set($b['rows']['b2'],['category'=>'Books','folder'=>'Walk Shelf']);
$np=p4_new_places($b);eq($np['cats'],['Space Science'],'4p: new categories (skipped items do not count)');eq($np['shelves'],['Walk Shelf'],'4p: new shelves (existing shelf and no shelf do not count)');
eq($np['subs'],['Space Science › Planets','Space Science › Moons'],'4p: subfolders under new categories');
/* site links */
$dir='<ul><li class="dir-item" data-label="History">'."\n".' <a href="directory.php?cat=History">History</a></li><li class="dir-item" data-label="Space &amp; Science">'."\n".' <a href="directory.php?cat=Space%20%26%20Science">x</a></li></ul>';
$bk='<a href="listofbooks.php?folder=Walk%20Shelf"><img class="book-category-card__image" src="book_thumb.php?folder=Walk%20Shelf" alt=""></a>';
$mu='<a class="music-thumb-link" href="listen.php?album=Import%20Big%20Test">'."\n".' <img src="videos/Music/Import%20Big%20Test/cover.jpg"></a>';
$pl=p4_parse_links($dir,$bk,$mu);eq($pl['cats'],['history'=>'directory.php?cat=History','space & science'=>'directory.php?cat=Space%20%26%20Science'],'4p: category links read from the site\'s Directory page (HTML entities decoded)');
eq($pl['shelves'],['walk shelf'=>'listofbooks.php?folder=Walk%20Shelf'],'4p: shelf links read from the Books page');eq($pl['albums'],['import big test'=>'listen.php?album=Import%20Big%20Test'],'4p: album links read from the Music page');
eq(p4_parse_links('','',''),['cats'=>[],'shelves'=>[],'albums'=>[]],'4p: an unreadable site gives no links (the page then says it uses the fallback)');
eq(p4_slug('Cooking Food and drink'),'cooking-food-and-drink','4p: slug');
putenv('P4P_SITE=http://example.invalid:8080');eq(p4_site(),'http://example.invalid:8080/','4p: P4P_SITE overrides the site address');putenv('P4P_SITE');eq(p4_site(),P4_SITE_DEFAULT,'4p: default site address');
/* summary */
$b=['rows'=>['v1'=>row('Space Science/Planets/Mars.mp4'),'v2'=>row('History/Rome.mp4'),'b1'=>row('x/A.pdf'),'d1'=>['source'=>'Geo/half.mp4','type'=>'Videos','damaged'=>true,'include'=>false,'media'=>['why'=>'cut short']]],
 'left_out'=>[['source'=>'a.mov','why'=>'only .mp4','ext'=>'mov']],'left_out_n'=>3,'publish_started'=>'2026-10-24T20:00:00+00:00','source'=>'C:\\x'];
gp_set($b['rows']['b1'],['category'=>'Books','folder'=>'Walk Shelf']);$b['p4_new']=p4_new_places($b);
foreach(['v1'=>'convert','v2'=>'none','b1'=>'none'] as $rid=>$a)$b['operations'][$rid]=['state'=>'copied','media'=>['action'=>$a]];
$S=p4_summary($b,['finished'=>'2026-10-24T20:03:05+00:00'],['ok'=>true,'cats'=>['history'=>'directory.php?cat=History'],'shelves'=>[],'albums'=>[]]);
eq([$S['tot']['videos'],$S['tot']['books'],$S['tot']['converted'],$S['secs']],[2,1,1,185],'4p: totals and time taken');
eq(array_map(fn($g)=>$g['type'].'|'.$g['cat'].'|'.$g['n'],$S['groups']),['Videos|History|1','Videos|Space Science|1','Books|Books|1'],'4p: per category counts, videos first');
ok($S['groups'][1]['new']&&$S['groups'][2]['subs'][0]['new'],'4p: new category and new shelf flagged');eq($S['groups'][1]['subs'][0]['sub'],'Planets','4p: subcategory counts');
eq($S['docs'],[['t'=>'A','shelf'=>'Walk Shelf','file'=>'A.pdf']],'4p: one Open PDF entry per book');
eq($S['leftN'],4,'4p: left-out count = 1 damaged + 3 files not imported (2 of them beyond the stored list)');eq(count($S['left']),2,'4p: left-out list in the summary');
eq($S['links']['cats'],['History'=>'directory.php?cat=History','Space Science'=>null],'4p: a category the site does not list yet has no link (page shows "updating…" + fallback)');
/* page wiring */
foreach(["\$r['placement_folder']=\$r['type']==='Books'?'':implode('/',\$parts); // Patch 4p","attach_art(\$batch);p4_left_finish(\$batch,\$leftOut4p,\$leftN4p); // Patch 4p","if(isset(\$_GET['p4links'])){","(string)(\$_POST['action']??'')==='check_shelf'","function p4Confirm(go)","function openShelfPicker(","const p4Base=location.protocol+'//'+location.hostname+':8080/';"] as $w)ok(substr_count($code,$w)===1,'4p: page wiring: '.mb_substr($w,0,70));
ok(substr_count($code,'mc_fallback(')>=2,'4p: a failed conversion still falls back to copying the original (mc_fallback is kept and called)');
ok(str_contains($code,"data-k':'no'")||str_contains($code,"'data-k':'no'")||str_contains($code,'data-k=no'),'4p: the publish confirm has a No button');
$p4b=substr($code,(int)strpos($code,'/* ---------- Patch 4p: damaged videos'));$p4b=substr($p4b,0,(int)strpos($p4b,'function mc_cli('));
ok(!preg_match('/guide_config|\.env\b|unlink\(|rename\(\$src|exec\(|shell_exec|proc_open/',$p4b),'4p: the 4p functions never delete, never run shell commands, never read secrets');
exec('rm -rf '.escapeshellarg($LIB));
echo "unit-p4p: $pass passed, $fail failed\n";if($fail===0)echo "UNIT TESTS PASSED\n";exit($fail===0?0:1);
