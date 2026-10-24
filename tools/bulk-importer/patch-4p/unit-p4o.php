<?php
/* Patch 4o unit tests (v3) = + section 17 (audio: MP3 conversion at import, damaged audio) and the audio changes undone in section 16.
   Patch 4n unit tests + section 16: the keyboard-first screens are a layer on top of the unchanged Patch 4n page
   (removing the layer gives back the exact live 4n file, SHA-256 0CD7E9AE...), the layer has no PHP inside its script and keeps the classic page working.
   Patch 4n unit tests = Patch 4m unit tests + section 15: Recently imported only while the earlier copy is in the library or on its way there.
   Patch 4m unit tests = Patch 4l unit tests + section 14: pages without the item editor load completely (no array_values(null) crash), the old Desktop Videos source is optional.
   Patch 4l unit tests = Patch 4k unit tests + section 13: new categories shared by the whole import (kept with the batch), Set category for selected, confirm counts.
   Patch 4k unit tests = Patch 4j unit tests (section 5 now expects no automatic Unsorted) + section 12: categories from the library folders, New category, no Unsorted default.
   Patch 4j unit tests = Patch 4i unit tests with the Source UI section updated for 4j (Browse only, published imports at the bottom, tighter header).
   Patch 4i unit tests = all Patch 4h tests (unchanged) + Patch 4i media plan, fast-start detection, ffmpeg settings and the Source folder UI.
   Patch 4h unit tests (4g tests + books default Books/Books): the shared rule (edutek_safe_name v1) on a sample table with real survey names, and the importer's
   use of it (functions extracted from the patched bulk-import-guided.php, the 4f way). Read-only: touches only a temp folder.
   php unit-p4l.php <edutek-safe-name.php> <bulk-import-guided.php>   (sections 7-9 need ffmpeg/ffprobe; inside the web container they are present) */
declare(strict_types=1);
[$self,$mod,$imp]=$argv+[null,null,null];if(!$mod||!$imp){fwrite(STDERR,"usage\n");exit(2);}
require $mod;
$fail=0;$pass=0;
function ok(bool $c,string $what,string $got=''){global $fail,$pass;if($c){$pass++;}else{$fail++;echo "FAIL $what".($got!==''?"  got: $got":'')."\n";}}
function eq($got,$want,string $what){ok($got===$want,$what.' => '.json_encode($want,JSON_UNESCAPED_UNICODE),json_encode($got,JSON_UNESCAPED_UNICODE));}
/* 1. rule table: [input, kind, clean file/folder name, needs review] */
$T=[
 ["348 Elon Musk\u{A789} SpaceX, Mars, Tesla Autopilot, Self-Driving, Robotics, and AI _ Lex Fridman Podcast #252.mp4",'file',"348 Elon Musk - SpaceX, Mars, Tesla Autopilot, Self-Driving, Robotics, and AI _ Lex Fridman Podcast No. 252.mp4",false],
 ["392 Awesome Video Effect Stacking trick in Adobe Premiere Pro  #tutorial.mp4",'file',"392 Awesome Video Effect Stacking trick in Adobe Premiere Pro.mp4",false],
 ["Easy Text Animation in Premiere #premierepro #tutorial.mp4",'file',"Easy Text Animation in Premiere.mp4",false],
 ["Constantine\u{A789} Crash Course World History #11.mp4",'file',"Constantine - Crash Course World History No. 11.mp4",false],
 ["10% Happier.pdf",'file',"10 percent Happier.pdf",false],
 ["10\u{FFFD} Happier Part01.mp4",'file',"10 Happier Part01.mp4",true],
 ["Hotel Management & Catering",'folder',"Hotel Management and Catering",false],
 ["Izzy + Tristan",'folder',"Izzy plus Tristan",false],
 ["C++",'folder',"C plus plus",false],
 ["_Form_1_13.1_Pythagoras__theorem(0).mp4",'file',"_Form_1_13.1_Pythagoras__theorem(0).mp4",false],
 ["_Sequences,_Selections,_and_Loops F2.mp4",'file',"_Sequences,_Selections,_and_Loops F2.mp4",false],
 ["_A_ is for Alibi - Sue Grafton.pdf",'file',"_A_ is for Alibi - Sue Grafton.pdf",false],
 ["Bookkeeping 101\u{A789} What Is It",'folder',"Bookkeeping 101 - What Is It",false],
 ["Fairytale Nopala El Cactus .Pdf",'file',"Fairytale Nopala El Cactus.pdf",false],
 ["Sales Force  (Needs Work)",'folder',"Sales Force (Needs Work)",false],
 ["Homemade Drip Irrigation Trick Automatic Plant Watering System #howto #gardeningtips #tips #tricks.mp4",'file',"Homemade Drip Irrigation Trick Automatic Plant Watering System.mp4",false],
 ["Middle School Success? SEL Lesson on Friendship, Teamwork & Support.mp4",'file',"Middle School Success SEL Lesson on Friendship, Teamwork and Support.mp4",false],
 ["What? Why: How | When / Where \\ Now.mp4",'file',"What Why - How - When - Where - Now.mp4",false],
 ["\u{201C}Quoted\u{201D} \u{2018}it\u{2019}s\u{2019} \u{2013} done\u{2026}.mp4",'file',"Quoted 'it's' - done.mp4",false],
 ["a;b<c>d*e.mp4",'file',"a,bcde.mp4",false],
 ["Fun video \u{1F600}\u{2764}\u{FE0F}\u{200B}.mp4",'file',"Fun video.mp4",false],
 ["Kid President's 20 Things We Should Say More Often.mp4",'file',"Kid President's 20 Things We Should Say More Often.mp4",false],
 ["How 8,000 Food Forests Grew Africa's Great Green Wall.mp4",'file',"How 8,000 Food Forests Grew Africa's Great Green Wall.mp4",false],
 ["Price \$5 @home = ~fun~ {x}.mp4",'file',"Price 5 home fun x.mp4",false],
 ["CON.mp4",'file',"_CON.mp4",true],
 ["???.mp4",'file',"Untitled.mp4",true],
 ["...hidden start.mp4",'file',"hidden start.mp4",false],
 ["Ends with dash - .mp4",'file',"Ends with dash.mp4",false],
 [str_repeat('Long name ',20).'.mp4','file',null,true],
];
foreach($T as [$in,$kind,$want,$rev]){$i=sn_info($in,$kind==='file');if($want!==null)eq($i['clean'],$want,"rule($in)");ok(($i['review']!==[])===$rev,"review flag($in)",implode(';',$i['review']));
 ok(sn_info($i['clean'],$kind==='file')['clean']===$i['clean'],"idempotent($in)");ok(mb_strlen($i['clean'])<=120,"max 120($in)");}
/* 2. junk */
foreach([['._01 - to Grow Backyard Cantaloupes.mp4',false,10,true],['.DS_Store',false,10,true],['africa is not a country.pdf - shortcut.lnk',false,10,true],['Thumbs.db',false,10,true],['desktop.ini',false,10,true],['.hidden.mp4',false,10,true],
 ['empty.mp4',false,0,true],['empty.pdf',false,0,true],['notes.txt',false,0,false],['_Form_1.mp4',false,10,false],['_A_ is for Alibi.pdf',false,10,false],
 ['__MACOSX',true,-1,true],['.Spotlight-V100',true,-1,true],['.Trashes',true,-1,true],['.fseventsd',true,-1,true],['$RECYCLE.BIN',true,-1,true],['_Stuff',true,-1,false],['History',true,-1,false]] as [$n,$d,$b,$want])
 ok((sn_junk($n,$d,$b)!=='')===$want,"junk($n)",sn_junk($n,$d,$b));
/* 3. titles (display only) */
eq(sn_title("348 Elon Musk\u{A789} SpaceX _ Lex Fridman Podcast #252"),"Elon Musk: SpaceX Lex Fridman Podcast #252",'title strip number, keep #252');
eq(sn_title("348 Elon Musk\u{A789} SpaceX",false),"348 Elon Musk: SpaceX",'title keep number (importer)');
eq(sn_title("Easy Text Animation #premierepro #tutorial"),"Easy Text Animation",'title trailing hashtags');
eq(sn_title("10% Happier & More + Less?"),"10% Happier & More + Less?",'title keeps % & + ?');
eq(sn_title("africa is not a country"),"Africa Is Not A Country",'title case all lower');
eq(sn_title("Kid President's Mixed Case"),"Kid President's Mixed Case",'title mixed case kept');
eq(sn_title("1984 George Orwell"),"1984 George Orwell",'title 4-digit year kept');
eq(sn_unique('A and B.mp4',['a and b.mp4'=>1,'a and b (2).mp4'=>1]),'A and B (3).mp4','unique (3)');
/* 4. dedup key of the clean name */
eq(sn_key('X/Hotel Management & Catering Intro.mp4'),sn_key('Hotel Management and Catering Intro.mp4'),'sn_key & = and');
ok(sn_key_may_differ('A & B.mp4')&&!sn_key_may_differ('Plain name.mp4'),'key pre-check');

/* 5. importer functions, extracted from the patched page */
$code=(string)file_get_contents($imp);
ok(str_contains($code,"const GP_RULES=3;"),'GP_RULES=3');
ok(str_contains($code,"const GP_BOOKS_DEFAULT_FOLDER='Books';"),'4h: Books default is Books (one line)');ok(!str_contains($code,"GP_BOOKS_DEFAULT_FOLDER='Unsorted'"),'4h: Unsorted default gone');
ok(str_contains($code,'const booksDefaultFolder=<?=json_encode(GP_BOOKS_DEFAULT_FOLDER'),'4h: browser note uses the same setting');
ok(str_contains($code,"require_once dirname(__DIR__).'/lib/edutek-safe-name.php';"),'page loads the shared rule');
$want=['cleancomponent','bi_tidy_title','bi_dupkey','keyname','dx_key','dx_len','dx_ext','gp_lib_dir','gp_clean_part','gp_clean_path','gp_title_edited','gp_requested','gp_role','gp_component','gp_validate','gp_init','gp_folder','gp_target','gp_set','gp_decollide','needs_fix','needs_fix_why','gp_folder_auto','gp_folder_note','gp_lib_state','gp_lib_categories','gp_category_match','gp_no_default','gp_scan_place','gp_check_category','gp_new_categories','gp_group_label','gp_auto_category','learning','gp_used_newcats','gp_batch_newcats','gp_propose','gp_newcat_seed','gp_newcat_canon','gp_new_category_counts','gp_newcat_text'];
$tok=token_get_all($code);$fn=[];$cnt=count($tok);
for($i=0;$i<$cnt;$i++){if(!is_array($tok[$i])||$tok[$i][0]!==T_FUNCTION)continue;$j=$i+1;while(is_array($tok[$j])&&$tok[$j][0]===T_WHITESPACE)$j++;if(!is_array($tok[$j])||$tok[$j][0]!==T_STRING||!in_array($tok[$j][1],$want,true))continue;
 $name=$tok[$j][1];$src='';$depth=0;$started=false;for($k=$i;$k<$cnt;$k++){$t=$tok[$k];$s=is_array($t)?$t[1]:$t;$src.=$s;if($s==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}elseif($s==='}'){$depth--;if($started&&$depth===0)break;}}
 $fn[$name]=$src;}
foreach($want as $w)ok(isset($fn[$w]),"found function $w");
const GP_RULES=3;const GP_BOOKS_DEFAULT_FOLDER='Books';const DX_NAME_MIN=12;const GP_NO_DEFAULT=['Unsorted'];const GP_NOCAT='(choose a category)';
/* a throwaway library: 7 category folders (odd spellings included) + things that are not categories */
$LIB=sys_get_temp_dir().'/p4k-unit-lib-'.getmypid();$LIBCATS=['History','Hotel Management & Catering','Sales Force  (Needs Work)','Unsorted','Books','Science','Earth\'s Wonders 2'];
foreach($LIBCATS as $d)@mkdir("$LIB/$d",0777,true);@mkdir("$LIB/.hidden");@mkdir("$LIB/\$RECYCLE.BIN");file_put_contents("$LIB/video-file-list.txt","x");@symlink("$LIB/History","$LIB/Link to History");
define('GP_LIB_DIR',$LIB);
function gp_config(): array {return ['categories'=>['Books','Music','Audiobooks','History','Hotel Management & Catering']];}
function dx_index(): array {return ['dirs'=>['hotel management & catering'=>'Hotel Management & Catering','hotel management & catering/hotel management & catering'=>'Hotel Management & Catering/Hotel Management & Catering','history'=>'History','books'=>'Books','books/fiction'=>'Books/Fiction']];}
function dx_apply(array &$r): void {$r['dx_rechecked']=($r['dx_rechecked']??0)+1;}
function rp_apply(array &$r,string $w): void {}
foreach($want as $w)if(isset($fn[$w]))eval($fn[$w]);
function row(string $rel,array $extra=[],string $root='/import-source/Some Folder'): array {$ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));$group=dirname($rel);if($group==='.')$group='Unsorted';
 $target=gp_clean_path($group).'/'.sn_filename(basename($rel));$t=sn_title(bi_tidy_title(pathinfo($rel,PATHINFO_FILENAME)),false);
 $r=['source'=>$rel,'group'=>$group,'type'=>$ext==='pdf'?'Books':'Videos','extension'=>$ext,'original_title'=>pathinfo($rel,PATHINFO_FILENAME),'title'=>$t,'title_auto'=>$t,'target'=>$target,'bytes'=>5]+$extra;gp_scan_place($r,$root);gp_init($r);return $r;}
$r=row('Hotel Management & Catering/Menu & Costs #101.mp4');eq($r['target'],'Hotel Management & Catering/Hotel Management & Catering/Menu and Costs No. 101.mp4','existing library folder kept, 4f auto-subfolder, file cleaned');ok(!needs_fix($r),'no decision for an automatic clean');
$r=row('Science & Tech/Intro + More.mp4');eq($r['target'],'Science and Tech/Science and Tech/Intro plus More.mp4','new folder cleaned');
$r=row('history/Rome: Rise.mp4');eq($r['target'],'History/History/Rome - Rise.mp4','library spelling of an existing folder');
$r=row('Fiction/10% Happier.pdf');eq($r['target'],'Books/Books/10 percent Happier.pdf','4h: book with no nested folder -> Books/Books');
ok(gp_folder_auto($r),'4h: default book subcategory shows the auto tag');ok(str_contains(gp_folder_note($r),'default for books with no subfolder'),'4h: note names the default',gp_folder_note($r));
$r=row('Loose Book & More.pdf');eq($r['target'],'Books/Books/Loose Book and More.pdf','4h: loose book at the source root -> Books/Books');ok(gp_folder_auto($r),'4h: loose book auto');
$r=row('Books/Sports/Dive Atlas.pdf');eq($r['target'],'Books/Sports/Dive Atlas.pdf','4h: book with a subfolder keeps it');ok(!str_contains(gp_folder_note($r),'default'),'4h: non-default note unchanged');
$r=row('Loose video.mp4');eq($r['target'],'(choose a category)/Loose video.mp4','4k: loose video -> no category (was Unsorted/Unsorted)');eq($r['placement_category'],'','4k: loose video has no category until one is chosen');ok(gp_folder_auto($r),'loose video auto');
$r=row('Fiction/x.pdf');gp_set($r,['category'=>'Books','folder'=>'Books','placement_override'=>true]);ok(!gp_folder_auto($r),'4h: typed Books is custom');ok(str_contains(gp_folder_note($r),'Custom'),'4h: custom note');
$r=['type'=>'Books','placement_category'=>'Books','placement_folder'=>'Unsorted','placement_override'=>false,'target'=>'Books/Unsorted/old.pdf','source'=>'Fiction/old.pdf','title'=>'old','title_auto'=>'old','extension'=>'pdf'];gp_init($r);eq($r['target'],'Books/Unsorted/old.pdf','4h: an already planned book keeps Books/Unsorted (no silent move)');
$r=row('Fiction/Classics & More/10% Happier.pdf');eq($r['target'],'Books/Classics and More/10 percent Happier.pdf','nested book folder cleaned');
$r=row('Videos/10'."\u{FFFD}".' Happier Part01.mp4');ok(needs_fix($r),'U+FFFD needs a decision');ok(needs_fix_why($r)!==[],'reason listed');
$r=row('Unsorted/_Form_1_13.1_Pythagoras__theorem(0).mp4');eq(basename($r['target']),'_Form_1_13.1_Pythagoras__theorem(0).mp4','underscore file kept as is');ok(!gp_title_edited($r),'auto title not edited');
$r=row('History/a.mp4');$r['title']='What? Now: yes';$r['target']=gp_target($r);eq(basename($r['target']),'What Now - yes.mp4','edited title -> clean filename');
$r=row('History/a.mp4');gp_set($r,['category'=>'Math & Science','folder'=>'Algebra: Part 1']);eq($r['target'],'Math and Science/Algebra - Part 1/a.mp4','typed placement cleaned');
$r=row('History/a.mp4');gp_set($r,['category'=>'Hotel Management & Catering','folder'=>'']);eq($r['target'],'Hotel Management & Catering/Hotel Management & Catering/a.mp4','typed existing library folder kept');
$e='';try{gp_component('Math & Science','Category');}catch(Throwable $x){$e=$x->getMessage();}ok(str_contains($e,'Suggested name: Math and Science'),'unclean typed name refused with suggestion',$e);
$e='';try{gp_component('Hotel Management & Catering','Category');}catch(Throwable $x){$e=$x->getMessage();}eq($e,'','existing library folder accepted');
$old=['title'=>'Old_Name & Co','original_title'=>'Old_Name & Co','source'=>'x/Old_Name & Co.mp4'];ok(!gp_title_edited(['title'=>bi_tidy_title('Old_Name & Co')]+$old),'4f batch tidy title counts as unedited');
eq(bi_dupkey('Lex Podcast #252 #ai'),bi_dupkey('Lex Podcast #252'),'title dup key ignores trailing hashtags');
$b=['rows'=>['r1'=>row('History/A & B.mp4'),'r2'=>row('History/A and B.mp4'),'r3'=>row('History/a & b.mp4'),'r4'=>row('History/C.mp4',['replace'=>['path'=>'History/History/C.mp4']])]];
gp_decollide($b);eq($b['rows']['r1']['target'],'History/History/A and B.mp4','collision first keeps name');eq($b['rows']['r2']['target'],'History/History/A and B (2).mp4','collision (2)');eq($b['rows']['r3']['target'],'History/History/a and b (3).mp4','collision (3), case-insensitive');ok(($b['rows']['r2']['dx_rechecked']??0)>=1,'renamed row duplicate-checked again');
gp_decollide($b);eq($b['rows']['r2']['target'],'History/History/A and B (2).mp4','collision stable on re-save');
/* 6. scan junk filter, extracted from the page, run on a temp folder */
$a=strpos($code,'$jf=function($c)');$z=strpos($code,"};\n",$a);ok($a!==false&&$z!==false,'scan junk filter found');
const HV_ROOT='/host';function hv_blocked(string $p): ?string {return null;}
$tmp=sys_get_temp_dir().'/p4h-unit-'.bin2hex(random_bytes(4));mkdir("$tmp/src/History/__MACOSX",0700,true);mkdir("$tmp/src/.Trashes",0700,true);
foreach(['History/Rome & Italy.mp4'=>'x','History/._Rome & Italy.mp4'=>'x','History/__MACOSX/._a.mp4'=>'x','History/.DS_Store'=>'x','History/Thumbs.db'=>'x','History/desktop.ini'=>'x','History/link.lnk'=>'x','History/empty.mp4'=>'','History/_Form_1.mp4'=>'x','.Trashes/x.mp4'=>'x','History/Book.pdf'=>'x'] as $p=>$c)file_put_contents("$tmp/src/$p",$c);
$root="$tmp/src";$junk=[];$junkN=0;$isHv=false;eval(substr($code,$a,$z-$a+2));
$found=[];foreach(new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),$jf)) as $f){if($f->isFile())$found[]=substr($f->getPathname(),strlen($root)+1);}sort($found);
eq($found,['History/Book.pdf','History/Rome & Italy.mp4','History/_Form_1.mp4'],'scan keeps real files, incl. _ files');
eq($junkN,8,'junk counted (._, __MACOSX, .DS_Store, Thumbs.db, desktop.ini, .lnk, empty, .Trashes)');
foreach(array_reverse(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST))) as $x){}
exec('rm -rf '.escapeshellarg($tmp));
/* 7. Patch 4i: media functions extracted from the page */
ok(str_contains($code,'Patch 4i'),'4i: header names Patch 4i');
preg_match('/^const MC_MAX_SIDE=.*$/m',$code,$m);ok(isset($m[0]),'4i: media settings line found');
eq(isset($m[0])?$m[0]:'',"const MC_MAX_SIDE=720;const MC_CRF=27;const MC_PRESET='veryfast';const MC_ABR='128k';const MC_THREADS=8;const MC_NICE=10;const MC_STALL=300;const MC_RETRIES=2;",'4i: settings 720p max, CRF 27, veryfast, AAC 128k');
const BI_ROOT='/nonexistent-p4i-unit';eval($m[0]);const MC_LEDGER=BI_ROOT.'/transcode-ledger';
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$want=['mc_atoms','mc_moov_first','mc_run','mc_probe','mc_codec_name','mc_plan','mc_is_video','mc_effective','mc_needs_job','mc_badge_html','mc_settings','mc_cmd','mc_packets','mc_verify','mc_ledger_path'];$fn=[];
for($i=0;$i<$cnt;$i++){if(!is_array($tok[$i])||$tok[$i][0]!==T_FUNCTION)continue;$j=$i+1;while(is_array($tok[$j])&&$tok[$j][0]===T_WHITESPACE)$j++;if(!is_array($tok[$j])||$tok[$j][0]!==T_STRING||!in_array($tok[$j][1],$want,true))continue;
 $name=$tok[$j][1];$src='';$depth=0;$started=false;for($k=$i;$k<$cnt;$k++){$t=$tok[$k];$s=is_array($t)?$t[1]:$t;$src.=$s;if($s==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}elseif($s==='}'){$depth--;if($started&&$depth===0)break;}}
 $fn[$name]=$src;}
foreach($want as $w){ok(isset($fn[$w]),"4i: found function $w");if(isset($fn[$w]))eval($fn[$w]);}
/* Patch 4o audio block (needed by mc_needs_job / mc_badge_html) */
$a1=strpos($code,'/* Patch 4o audio: begin */');$a2=strpos($code,'/* Patch 4o audio: end */');$AU_OK=$a1!==false&&$a2!==false&&substr_count($code,'/* Patch 4o audio: begin */')===1;
if($AU_OK)eval(substr($code,$a1,$a2-$a1));
$P=fn(array $x)=>$x+['v'=>'h264','vi'=>0,'w'=>1280,'h'=>720,'pix'=>'yuv420p','a'=>'aac','ai'=>1,'ch'=>2,'dur'=>6.0,'moov'=>true];
$t=mc_plan($P(['v'=>'hevc','w'=>1920,'h'=>1080]));eq([$t['action'],$t['label'],$t['to_short']],['convert','HEVC 1080p → H.264 720p',720],'4i: HEVC 1080p -> convert to 720p');
$t=mc_plan($P(['v'=>'hevc','w'=>640,'h'=>360]));eq([$t['action'],$t['to_short']],['convert',360],'4i: HEVC 360p -> 360p (never upscaled)');
$t=mc_plan($P(['v'=>'hevc','w'=>1080,'h'=>1920]));eq($t['to_short'],720,'4i: portrait 1080x1920 -> short side 720');
$t=mc_plan($P(['v'=>'hevc','w'=>853,'h'=>481]));eq($t['to_short'],480,'4i: odd short side rounded down to even');
foreach(['av1','vp9','mpeg4','mpeg2video','prores'] as $c)eq(mc_plan($P(['v'=>$c]))['action'],'convert',"4i: $c -> convert");
$t=mc_plan($P(['pix'=>'yuv420p10le']));eq([$t['action'],$t['label']],['convert','H.264 (yuv420p10le) 720p → H.264 720p'],'4i: 10-bit H.264 is not browser-safe -> convert');
eq(mc_plan($P(['a'=>'ac3']))['action'],'audio','4i: H.264 + AC-3 -> audio only (video copied)');
eq(mc_plan($P(['a'=>'opus']))['action'],'audio','4i: H.264 + Opus -> audio only');
eq(mc_plan($P(['moov'=>false]))['action'],'faststart','4i: H.264, moov at end -> faststart (stream copy)');
eq(mc_plan($P(['moov'=>null]))['action'],'faststart','4i: H.264, moov position unknown -> faststart');
eq(mc_plan($P([]))['action'],'none','4i: H.264 + AAC + fast start -> copied byte for byte');
eq(mc_plan($P(['a'=>'','ai'=>-1,'ch'=>0]))['action'],'none','4i: H.264 with no audio -> unchanged');
eq(mc_plan($P(['a'=>'mp3']))['action'],'none','4i: H.264 + MP3 is browser-safe');
$t=mc_plan(null);eq([$t['action'],$t['warn']],['none','unreadable'],'4i: unreadable video copied unchanged with a warning');
$vr=['type'=>'Videos','extension'=>'mp4','media'=>mc_plan($P(['v'=>'hevc','w'=>1920,'h'=>1080]))];
ok(mc_is_video($vr)&&!mc_is_video(['type'=>'Books','extension'=>'pdf'])&&!mc_is_video($vr+['support'=>true]),'4i: only main mp4 videos are planned');
ok(mc_needs_job($vr),'4i: convert needs a background job');eq(mc_effective($vr+['lib_size_n'=>1])['action'],'kept','4i: same-size library file -> kept unchanged');ok(!mc_needs_job($vr+['lib_size_n'=>1]),'4i: kept needs no job');
ok(str_contains(mc_badge_html($vr),'Will convert: HEVC 1080p → H.264 720p'),'4i: review card badge shows the planned conversion',mc_badge_html($vr));
ok(str_contains(mc_badge_html(['type'=>'Videos','extension'=>'mp4','media'=>mc_plan($P(['moov'=>false]))]),'Will fix: Fast start fix (no re-encode)'),'4i: badge for faststart');
eq(mc_badge_html(['type'=>'Books','extension'=>'pdf']),'','4i: no badge on books');
/* ffmpeg command lines */
$c=mc_cmd('/in.mp4','/out.part',$P(['v'=>'hevc','ch'=>6]),'convert');$j=implode(' ',$c);
foreach(['-c:v libx264','-preset veryfast','-crf 27','-pix_fmt yuv420p','-threads 8','-c:a aac','-b:a 128k','-ac 2','-movflags +faststart','-n -i /in.mp4','-map 0:0','-map 0:1'] as $f)ok(str_contains($j,$f),"4i: convert uses $f",$j);
ok(str_contains($j,"min(720,iw)")&&str_contains($j,"min(720,ih)"),'4i: scale caps the short side at 720 and never upscales');eq(end($c),'/out.part','4i: output is the staged part file');eq($c[array_search('-f',$c)+1],'mp4','4i: mp4 container forced');
$j=implode(' ',mc_cmd('/in.mp4','/o',$P([]),'faststart'));ok(str_contains($j,'-c copy')&&str_contains($j,'+faststart')&&!str_contains($j,'libx264'),'4i: faststart is a lossless stream copy',$j);
$j=implode(' ',mc_cmd('/in.mp4','/o',$P(['a'=>'ac3']),'audio'));ok(str_contains($j,'-c:v copy')&&str_contains($j,'-c:a aac'),'4i: audio fix copies video',$j);
$j=implode(' ',mc_cmd('/in.mp4','/o',$P(['ai'=>-1,'a'=>'']),'convert'));ok(!str_contains($j,'-c:a')&&substr_count($j,'-map')===2,'4i: no audio stream -> no audio options (-map 0:v and -map_metadata only)',$j);
ok(str_contains(mc_settings('convert'),'no upscale')&&str_contains(mc_settings('faststart'),'stream copy'),'4i: settings text');
eq(mc_ledger_path(123,str_repeat('a',64)),'/nonexistent-p4i-unit/transcode-ledger/123-'.str_repeat('a',64).'.json','4i: ledger keyed by original bytes + SHA-256');
/* 8. atoms on synthetic files */
$tmp=sys_get_temp_dir().'/p4i-unit-'.bin2hex(random_bytes(4));mkdir($tmp,0700,true);
$box=fn(string $t,int $n)=>pack('N',8+$n).$t.str_repeat("\0",$n);
file_put_contents("$tmp/a.mp4",$box('ftyp',8).$box('moov',20).$box('mdat',100));file_put_contents("$tmp/b.mp4",$box('ftyp',8).$box('mdat',100).$box('moov',20));
file_put_contents("$tmp/c.mp4",$box('ftyp',8).$box('mdat',100));file_put_contents("$tmp/d.mp4",$box('ftyp',8).$box('free',4).pack('N',1).'mdat'.pack('J',16+50).str_repeat("\0",50).$box('moov',4));file_put_contents("$tmp/e.mp4",'not an mp4 at all');
eq(mc_atoms("$tmp/a.mp4"),['ftyp','moov','mdat'],'4i: atoms read');eq(mc_moov_first("$tmp/a.mp4"),true,'4i: moov first');eq(mc_moov_first("$tmp/b.mp4"),false,'4i: moov at end');
eq(mc_moov_first("$tmp/c.mp4"),null,'4i: no moov -> unknown');eq(mc_atoms("$tmp/d.mp4"),['ftyp','free','mdat','moov'],'4i: 64-bit atom size');eq(mc_moov_first("$tmp/d.mp4"),false,'4i: moov after 64-bit mdat');eq(mc_moov_first("$tmp/e.mp4"),null,'4i: garbage -> unknown');
/* 9. real conversion, twice: probe, verify and determinism (needs ffmpeg) */
[$fc]=mc_run(['ffmpeg','-version'],20);
if($fc===0){
 $src="$tmp/hevc.mp4";[$c,,$e]=mc_run(['ffmpeg','-v','error','-f','lavfi','-i','testsrc2=s=1280x720:r=25:d=3','-f','lavfi','-i','sine=d=3','-c:v','libx265','-preset','ultrafast','-x265-params','log-level=error','-tag:v','hvc1','-c:a','aac','-shortest',$src],300);ok($c===0,'4i: test clip made',$e);
 $pr=mc_probe($src);eq([$pr['v']??null,$pr['w']??null,$pr['h']??null,$pr['a']??null],['hevc',1280,720,'aac'],'4i: probe reads codec and size');
 $sha=[];foreach([1,2] as $n){$o="$tmp/out$n.mp4";$cmd=mc_cmd($src,$o,$pr,'convert');$k=array_search('-progress',$cmd);array_splice($cmd,$k,2);[$c,,$e]=mc_run($cmd,300);ok($c===0,"4i: convert run $n",$e);$sha[]=@hash_file('sha256',$o);}
 eq($sha[0],$sha[1],'4i: same original -> identical converted file (deterministic, '.MC_THREADS.' threads)');
 $op=mc_probe("$tmp/out1.mp4");eq([$op['v']??null,$op['w']??null,$op['h']??null,$op['pix']??null,$op['moov']??null],['h264',1280,720,'yuv420p',true],'4i: output H.264 720p yuv420p, moov first');
 eq(mc_verify("$tmp/out1.mp4",$src,$pr,'convert'),'','4i: verify accepts a good conversion');
 $bad="$tmp/bad.mp4";$fp=fopen("$tmp/out1.mp4",'rb');file_put_contents($bad,fread($fp,(int)(filesize("$tmp/out1.mp4")*0.5)));fclose($fp);ok(mc_verify($bad,$src,$pr,'convert')!=='','4i: verify rejects a truncated output',mc_verify($bad,$src,$pr,'convert'));
}else echo "note: ffmpeg not found, sections 9 skipped\n";
exec('rm -rf '.escapeshellarg($tmp));
/* 10. Source UI (Patch 4j: Browse is the only picker; no selector, no typed box) */
ok(str_contains($code,"const SRC_NONE_MSG='Choose an import source first: choose Browse… and pick a folder, then Start new import';"),'UI: clear message when no source is chosen');
ok(!str_contains($code,'<select id="src-select"')&&!str_contains($code,'Select import source</option>')&&!str_contains($code,'<label for="src-select">'),'4j: Source folder selector removed');
ok(!str_contains($code,'Source folder list'),'4j: no text refers to the Source folder list any more');
ok(str_contains($code,'<input type="hidden" name="source_key" id="src-key" value="<?=h($currentSource)?>">'),'4j: chosen source travels in a hidden field (empty until Browse)');
ok(str_contains($code,"Selected: <strong><?=h(\$srcShown)?></strong>")&&str_contains($code,'No folder chosen yet.'),'4j: chosen folder shown as plain read-only text');
ok(str_contains($code,"\$currentSource='';"),'UI: no current source by default');
ok(str_contains($code,'id="src-msg" class="warning" role="alert"'),'UI: message is announced (role=alert)');
ok(str_contains($code,"if(trim(\$srcKey)==='')throw new RuntimeException(SRC_NONE_MSG);"),'UI: server refuses a scan with no source');
ok(str_contains($code,'if(key.value===""){ev.preventDefault();msg.hidden=false;if(pick)pick.focus();}'),'4j: browser guard blocks Start new import and moves focus to Browse');
ok(!str_contains($code,'Or type a folder path')&&!str_contains($code,'Use typed folder')&&!str_contains($code,'D:\Media\Videos')&&!str_contains($code,'hp-typed'),'UI: typed folder label, box and button removed');
ok(!preg_match('/<input[^>]*name="srcpath"/',$code),'UI: no srcpath text box');
ok(str_contains($code,"\$_GET['srcpath']")&&str_contains($code,"\$_GET['src']"),'4j: ?src= and ?srcpath= backend kept');
ok(str_contains($code,"elseif(isset(\$sourceChoices[\$srcPick]))"),'4j: ?src=legacy and ?src=s:<name> still accepted by the backend');
$b2=strpos($code,'id="pick-btn"');$c2=strpos($code,'id="start"');ok($b2!==false&&$c2!==false&&$b2<$c2,'4j: tab order Browse -> Start new import (document order)');
$fs=strpos($code,'<form method="post" id="src-form">');$fe=strpos($code,'</form>',$fs);$form=substr($code,$fs,$fe-$fs);
ok(preg_match_all('/<(button|select|a |input(?![^>]*type="hidden"))/',$form)===2,'4j: only Browse and Start new import are focusable in the source form');
ok(!preg_match('/tabindex="[1-9]/',$code),'UI: no positive tabindex overrides');
/* 11. Published imports at the bottom, header spacing (Patch 4j) */
ok(!str_contains($code,'Published imports and undo</a></p></header>')&&!str_contains($code,'class="pub-link"'),'4j: published link removed from the header and the sidebar');
$hp=strpos($code,'</header>');$lp=strpos($code,'href="?view=published#published-imports">Published imports and undo</a>');$mp=strpos($code,'</main>');
ok($lp!==false&&$lp>strpos($code,'</section></div><section class="panel pub-foot"')-1&&$lp<$mp&&$lp>$hp,'4j: Published imports and undo is the last section of the page');
ok(substr_count($code,'href="?view=published#published-imports"')===1,'4j: one published link only');
ok(str_contains($code,'header{padding-bottom:6px}header>:last-child{margin-bottom:0}main{padding-top:10px}'),'4j: tighter header spacing');
ok(str_contains($code,'role="progressbar"')&&str_contains($code,'aria-valuenow'),'4i: converting progress bar is accessible');
/* 12. Patch 4k: categories */
$cats=gp_lib_categories();$exp=$LIBCATS;usort($exp,'strnatcasecmp');
eq(count($cats),count($LIBCATS),'4k: category count = the library\'s top-level folders');eq($cats,$exp,'4k: every library folder listed, natural order; hidden, $RECYCLE.BIN, files and links skipped');
$fb=gp_lib_state('/nonexistent/p4k');eq($fb['source'],'saved list','4k: unreadable library -> saved list, said on the page');ok(in_array('History',$fb['names'],true)&&in_array('Hotel Management & Catering',$fb['names'],true),'4k: fallback = saved list + library list top folders');
eq(gp_category_match('history'),'History','4k: match ignores case, library spelling');eq(gp_category_match('Sales Force (Needs Work)'),'Sales Force  (Needs Work)','4k: rule-equal name finds the odd library spelling');
eq(gp_category_match('Sales Force (Needs Work)',true),null,'4k: exact match only when asked');eq(gp_category_match('Nope'),null,'4k: unknown name');
$r=row('Test Video 01 - Color Bars 720p.mp4',[],'/host/c/Users/Edutek/Desktop/NEW Edutek Test Videos');
eq($r['placement_category'],'','4k: NEW Edutek Test Videos root file: no category (no library category of that name)');ok($r['root_file']===true,'4k: root file flagged');
eq($r['target'],'(choose a category)/Test Video 01 - Color Bars 720p.mp4','4k: planned path says (choose a category)');eq(gp_auto_category($r),'','4k: auto category empty');
ok(str_contains(gp_folder_note($r),'the category you choose'),'4k: folder note asks for a category',gp_folder_note($r));
$r=row('Rome.mp4',[],'/import-sources/history');eq($r['target'],'History/History/Rome.mp4','4k: root file of a folder named like a library category -> that category (auto subfolder)');
$r=row('x.mp4',[],'/import-sources/Unsorted');eq($r['placement_category'],'','4k: Unsorted is never chosen automatically (root)');
$r=row('Unsorted/x.mp4');eq($r['placement_category'],'','4k: Unsorted subfolder -> no automatic category');
$r=row('unsorted/Deep/x.mp4');eq([$r['placement_category'],$r['placement_folder']],['','Deep'],'4k: Unsorted/Deep -> no category, subfolder Deep kept');
$r=row('Science/Cells.mp4');eq($r['target'],'Science/Science/Cells.mp4','4k: subfolder rule: category = subfolder name, auto subfolder = category name');ok(gp_folder_auto($r)&&!$r['placement_override'],'4k: auto subfolder');eq(gp_new_categories([$r['target']]),[],'4k: existing category is not new');
$r=row('Science/Cells/Part 1.mp4');eq($r['target'],'Science/Cells/Part 1.mp4','4k: nested subfolder kept');
$r=row('Robotics & AI/Arm.mp4');eq($r['target'],'Robotics and AI/Robotics and AI/Arm.mp4','4k: subfolder that is not a category -> new category named after it (cleaned)');eq(gp_new_categories([$r['target']]),['Robotics and AI'],'4k: listed as a new category');
$r=row('Sales Force (Needs Work)/a.mp4');eq($r['target'],'Sales Force  (Needs Work)/Sales Force  (Needs Work)/a.mp4','4k: subfolder maps to the odd library spelling, no sibling folder');
$e='';try{gp_component('Sales Force  (Needs Work)','Category');}catch(Throwable $x){$e=$x->getMessage();}eq($e,'','4k: existing odd library category validates (live list)');
$r=row('Book One.pdf',[],'/import-sources/NEW Edutek Test Videos');eq($r['target'],'Books/Books/Book One.pdf','4k: books unchanged (Books/Books)');
$r=row('Test Video 02.mp4',[],'/x/NEW Edutek Test Videos');gp_set($r,['category'=>'History','folder'=>'','placement_override'=>true]);eq($r['target'],'History/History/Test Video 02.mp4','4k: choose an existing category -> auto subfolder = category name');
$r=row('Test Video 03.mp4',[],'/x/NEW Edutek Test Videos');gp_set($r,['category'=>'hotel management & catering','folder'=>'']);eq($r['target'],'Hotel Management & Catering/Hotel Management & Catering/Test Video 03.mp4','4k: chosen category keeps the library spelling');
$r=row('Test Video 04.mp4',[],'/x/NEW Edutek Test Videos');gp_set($r,['category'=>'Space Science','folder'=>'']);eq($r['target'],'Space Science/Space Science/Test Video 04.mp4','4k: new category');eq(gp_new_categories([$r['target'],'History/History/a.mp4','(choose a category)/b.mp4']),['Space Science'],'4k: new categories list (existing and unchosen left out)');
$r=row('Test Video 05.mp4',[],'/x/NEW Edutek Test Videos');gp_set($r,['category'=>'Space Science','folder'=>'Planets']);eq($r['target'],'Space Science/Planets/Test Video 05.mp4','4k: new category with a typed subfolder');
$e='';try{gp_validate('Videos','','');}catch(Throwable $x){$e=$x->getMessage();}eq($e,'','4k: no category is allowed while editing (blocked at Review selection)');
$e='';try{gp_validate('Books','','Books');}catch(Throwable $x){$e=$x->getMessage();}ok($e!=='','4k: books still need Books');
$k=gp_check_category('Space Science');ok($k['ok']&&!$k['exists']&&str_contains($k['message'],'only when you publish'),'4k: New category: good name accepted',json_encode($k,JSON_UNESCAPED_UNICODE));
$k=gp_check_category('  history ');ok($k['ok']&&$k['exists']&&$k['name']==='History','4k: New category with an existing name -> that category',json_encode($k));
$k=gp_check_category('Sales Force (Needs Work)');ok($k['ok']&&$k['name']==='Sales Force  (Needs Work)','4k: rule-equal existing name -> library spelling');
foreach(['Robotics: Intro'=>'Robotics - Intro','Math & Science'=>'Math and Science','CON'=>'_CON','a/b'=>'a b','x\\y'=>'x y'] as $bad=>$sug){$k=gp_check_category($bad);ok(!$k['ok']&&($k['suggest']??'')===$sug&&str_contains($k['message'],'Suggested name: '.$sug),"4k: bad name rejected with suggestion: $bad",json_encode($k,JSON_UNESCAPED_UNICODE));}
foreach(['','   ','???',str_repeat('x',130),'Books','music','Audio Books'] as $bad){$k=gp_check_category($bad);ok(!$k['ok'],'4k: bad name rejected: '.json_encode($bad),json_encode($k,JSON_UNESCAPED_UNICODE));}
$k=gp_check_category('Books');ok(str_contains($k['message'],'reserved'),'4k: reserved name message');
$k=gp_check_category('Unsorted');ok($k['ok']&&$k['exists'],'4k: Unsorted can still be picked by hand while it exists');
eq(gp_group_label('Unsorted',['r1'=>['root_file'=>true]],['src_root'=>'/host/c/Users/Edutek/Desktop/NEW Edutek Test Videos']),'Files directly in “NEW Edutek Test Videos”','4k: root files heading names the folder, not Unsorted');
eq(gp_group_label('Science/Cells',['r1'=>['root_file'=>false]],[]),'Science › Cells','4k: other headings unchanged');
/* page markup and checks */
ok(str_contains($code,'<template id="category-options"><?php foreach($libCats as $category):'),'4k: list = library folders (template)');ok(!str_contains($code,'list="category-options"')&&!str_contains($code,'<datalist'),'4k: no typing-suggestion list (datalist) any more');
ok(!str_contains($code,"\$categoryChoices=gp_config()['categories']"),'4k: no snapshot list for the dropdown');
ok(str_contains($code,'data-role="category-pick"')&&str_contains($code,'<option value="__new__">New category…</option>'),'4k: per-item select with New category…');
ok(str_contains($code,'<select id="bulk-category" data-filled="1"'),'4k: bulk category is a select too');
ok(str_contains($code,"if(isset(\$_GET['catcheck'])){")&&str_contains($code,"echo json_encode(gp_check_category((string)\$_GET['catcheck'])"),'4k: name check endpoint uses the same PHP rule');
ok(str_contains($code,"'kind'=>'category','message'=>'Choose a category for “'"),'4k: Review selection asks for a category');ok(str_contains($code,"throw new RuntimeException('Choose a category for “'.\$r['title'].'” before it can be added.')"),'4k: staging refuses an item with no category');
ok(str_contains($code,'id="new-categories"')&&str_contains($code,'id="publish-new-categories"'),'4k: confirm and publish show new categories');
ok(str_contains($code,"if(e.key==='Enter'){e.preventDefault();go();}"),'4k: Enter in the new-name box checks the name (no form submit)');
ok(str_contains($code,"Patch 4k ("),'4k: header marker');
/* 13. Patch 4l: a new category belongs to the whole import */
$fn4l=[];for($i=0;$i<$cnt;$i++){if(!is_array($tok[$i])||$tok[$i][0]!==T_FUNCTION)continue;$j=$i+1;while(is_array($tok[$j])&&$tok[$j][0]===T_WHITESPACE)$j++;if(!is_array($tok[$j])||$tok[$j][0]!==T_STRING||$tok[$j][1]!=='gp_cat_options')continue;
 $src='';$depth=0;$started=false;for($k=$i;$k<$cnt;$k++){$t=$tok[$k];$s=is_array($t)?$t[1]:$t;$src.=$s;if($s==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}elseif($s==='}'){$depth--;if($started&&$depth===0)break;}}$fn4l['gp_cat_options']=$src;}
ok(isset($fn4l['gp_cat_options']),'4l: found function gp_cat_options');if(isset($fn4l['gp_cat_options']))eval($fn4l['gp_cat_options']);
$B=['rows'=>[]];
$k=gp_propose($B,'Space Science');ok($k['ok']&&!$k['exists']&&$k['added']===true&&$k['name']==='Space Science'&&str_contains($k['message'],'every item’s Category list'),'4l: create a new category on item 1 -> added to the import',json_encode($k,JSON_UNESCAPED_UNICODE));
eq($B['proposed_categories'],['Space Science'],'4l: kept with the batch (proposed_categories)');
eq(gp_batch_newcats($B),['Space Science'],'4l: offered to every item (no item uses it yet)');
$k=gp_propose($B,'  space science ');ok($k['ok']&&$k['name']==='Space Science'&&$k['added']===false&&str_contains($k['message'],'already a new category in this import'),'4l: same name in another case on item 3 -> the import spelling, not a second category',json_encode($k,JSON_UNESCAPED_UNICODE));
eq(count($B['proposed_categories']),1,'4l: no duplicate kept');
$k=gp_propose($B,'history');ok($k['ok']&&$k['exists']&&$k['name']==='History','4l: existing library name in any case -> library spelling',json_encode($k));
$k=gp_propose($B,'SALES FORCE (NEEDS WORK)');ok($k['ok']&&$k['exists']&&$k['name']==='Sales Force  (Needs Work)','4l: rule-equal library name -> odd library spelling',json_encode($k));
eq($B['proposed_categories'],['Space Science'],'4l: library names are never added as new');
foreach(['Books','music','Robotics: Intro','CON','a/b','','   ',str_repeat('x',130)] as $bad){$c=$B;$k=gp_propose($c,$bad);ok(!$k['ok']&&$c===$B,'4l: bad name rejected and nothing added: '.json_encode($bad),json_encode($k,JSON_UNESCAPED_UNICODE));}
$k=gp_propose($B,'Ocean Life');eq(gp_batch_newcats($B),['Ocean Life','Space Science'],'4l: two new categories, sorted');
$C=['proposed_categories'=>array_map(fn($i)=>"Cat $i",range(1,300)),'rows'=>[]];$k=gp_propose($C,'One More');ok(!$k['ok']&&str_contains($k['message'],'300'),'4l: limit of 300 new categories per import');
/* items using it: create on item 1, pick on items 2-5 */
$rows=[];foreach([1,2,3,4,5] as $n){$r=row("Test Video 0$n.mp4",[],'/x/NEW Edutek Test Videos');gp_set($r,['category'=>'Space Science','folder'=>'']);$rows["r$n"]=$r;}
foreach($rows as $id=>$r)eq($r['target'],'Space Science/Space Science/'.basename($r['source']),"4l: $id placed in the shared new category (auto subfolder rule kept)");
$B['rows']=$rows;eq(gp_used_newcats($B),['Space Science'],'4l: used by the items');
$B['rows']['support1']=['type'=>'Videos','placement_category'=>'Support Only','support'=>true];eq(gp_used_newcats($B),['Space Science'],'4l: support files do not count');unset($B['rows']['support1']);
$bk=row('Book One.pdf',[],'/x/NEW Edutek Test Videos');$B['rows']['b1']=$bk;eq(gp_used_newcats($B),['Space Science'],'4l: books keep Books and do not add a category');unset($B['rows']['b1']);
/* prune when unused (what Save does) */
$B['proposed_categories']=gp_used_newcats($B);eq($B['proposed_categories'],['Space Science'],'4l: Save keeps a used new category and drops Ocean Life (unused)');
foreach($B['rows'] as &$r)gp_set($r,['category'=>'History','folder'=>'']);unset($r);
$B['proposed_categories']=gp_used_newcats($B);eq($B['proposed_categories'],[],'4l: removing every use drops it from the import');eq(gp_batch_newcats($B),[],'4l: and from the lists');
/* save: posted names map to one spelling */
$S=['proposed_categories'=>['Space Science'],'rows'=>[]];$map=gp_newcat_seed($S,['Ocean Life','history','Books','',str_repeat('y',300),'Ocean life']);
eq($map,[keyname('Ocean Life')=>'Ocean Life',keyname('Space Science')=>'Space Science'],'4l: seed keeps valid new names once, skips library/bad names');
$vr=['type'=>'Videos'];eq(gp_newcat_canon(['category'=>'space SCIENCE','folder'=>''],$vr,$map)['category'],'Space Science','4l: a typed case variant saves as the import spelling');
eq(gp_newcat_canon(['category'=>'History','folder'=>''],$vr,$map)['category'],'History','4l: library names untouched (gp_set gives library spelling)');
eq(gp_newcat_canon(['category'=>'Brand New','folder'=>''],$vr,$map)['category'],'Brand New','4l: first spelling of an unseen name is kept');eq(gp_newcat_canon(['category'=>'BRAND NEW','folder'=>''],$vr,$map)['category'],'Brand New','4l: ...and reused for the next item');
eq(gp_newcat_canon(['category'=>'space science'],['type'=>'Books'],$map)['category'],'space science','4l: book rows are left to the Books rule');
/* confirm: each new category once with its count */
$cnt4l=gp_new_category_counts(['Space Science/Space Science/a.mp4','Space Science/Space Science/b.mp4','Space Science/Planets/c.mp4','Ocean Life/Ocean Life/d.mp4','History/History/e.mp4','(choose a category)/f.mp4','Books/Books/g.pdf','loose.mp4']);
eq($cnt4l,[['Ocean Life',1],['Space Science',3]],'4l: confirm counts: one line per new category, library and unchosen left out');
eq(gp_newcat_text($cnt4l),'Ocean Life (1 item), Space Science (3 items)','4l: confirm text');eq(gp_new_category_counts([]),[],'4l: no new categories -> nothing listed');
/* markup */
$o=gp_cat_options(['History','Science'],['Space Science']);
ok(str_starts_with($o,'<optgroup label="New in this import (created when you publish)" data-new="1"><option value="Space Science">Space Science (new)</option></optgroup><optgroup label="Library categories (2)" data-lib="1">'),'4l: list shows new categories first, marked (new)',$o);
ok(!str_contains(gp_cat_options(['History'],[]),'data-new'),'4l: no empty New group');
ok(str_contains(gp_cat_options(['A&B'],['<x>']),'value="&lt;x&gt;"')&&str_contains(gp_cat_options(['A&B'],[]),'A&amp;B'),'4l: names escaped');
ok(str_contains($code,"(string)(\$_POST['action']??'')==='propose_category'"),'4l: propose endpoint present');ok(str_contains($code,'if($batch&&($batch[\'state\']??\'\')===\'draft\'){')&&!str_contains($code,'if(in_array($pb[\'state\']??\'\',[\'draft\',\'approved\'],true)){$res=gp_propose'),'4l: only a draft batch is written by the name check (approved batches untouched)');
ok(str_contains($code,'id="set-category-panel"')&&str_contains($code,'id="setcat-scope"')&&str_contains($code,'id="setcat-pick"')&&str_contains($code,'id="setcat-apply"'),'4l: Set category for selected panel');
ok(str_contains($code,'class="cat-to-selected"')&&str_contains($code,'class="cat-to-folder"'),'4l: per-item buttons: use for selected / this folder');
ok(str_contains($code,'name="new_categories" id="newcats-json"'),'4l: page sends the import’s new categories on Save');
ok(str_contains($code,"\$batch['proposed_categories']=gp_used_newcats(\$batch);"),'4l: Save drops unused new categories');
ok(str_contains($code,'each listed once with its number of selected items'),'4l: confirm wording');
ok(str_contains($code,'Patch 4l ('),'4l: header marker');
/* 14. Patch 4m */
$fn4m=[];for($i=0;$i<$cnt;$i++){if(!is_array($tok[$i])||$tok[$i][0]!==T_FUNCTION)continue;$j=$i+1;while(is_array($tok[$j])&&$tok[$j][0]===T_WHITESPACE)$j++;if(!is_array($tok[$j])||$tok[$j][0]!==T_STRING||$tok[$j][1]!=='gp_legacy_ok')continue;
 $src='';$depth=0;$started=false;for($k=$i;$k<$cnt;$k++){$t=$tok[$k];$s=is_array($t)?$t[1]:$t;$src.=$s;if($s==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}elseif($s==='}'){$depth--;if($started&&$depth===0)break;}}$fn4m['gp_legacy_ok']=$src;}
ok(isset($fn4m['gp_legacy_ok']),'4m: found function gp_legacy_ok');
if(isset($fn4m['gp_legacy_ok'])){eval($fn4m['gp_legacy_ok']);
 $LT=sys_get_temp_dir().'/p4m-unit-legacy-'.getmypid();@mkdir("$LT/empty",0777,true);@mkdir("$LT/full",0777,true);file_put_contents("$LT/full/a.mp4",'x');file_put_contents("$LT/file.txt",'x');@symlink("$LT/full","$LT/link");
 ok(gp_legacy_ok("$LT/missing")===false,'4m: legacy source missing -> not offered');ok(gp_legacy_ok("$LT/empty")===false,'4m: legacy source empty -> not offered');
 ok(gp_legacy_ok("$LT/full")===true,'4m: legacy source with files -> offered');ok(gp_legacy_ok("$LT/file.txt")===false,'4m: a file is not a source');ok(gp_legacy_ok("$LT/link")===false,'4m: a link is not a source');
 exec('rm -rf '.escapeshellarg($LT));}
ok(preg_match("/const LEGACY_GONE_MSG='The old Desktop Videos folder is not connected to the importer any more\. Use Browse\.\.\. to choose the folder to import';/",$code)===1,'4m: clear message for the old Desktop Videos source');
ok(str_contains($code,"if(\$srcKey==='legacy'){if(!gp_legacy_ok())throw new RuntimeException(LEGACY_GONE_MSG);"),'4m: scan of a missing legacy source gives the message (no batch made)');
ok(str_contains($code,"\$sourceChoices=gp_legacy_ok()?['legacy'=>'Desktop Videos (existing)']:[];"),'4m: legacy source offered only when present');
ok(str_contains($code,"elseif(\$srcPick==='legacy')throw new RuntimeException(LEGACY_GONE_MSG);"),'4m: ?src=legacy without the folder gives the message');
ok(!str_contains($code,'json_encode(array_values($newCats),'),'4m: no unguarded array_values($newCats)');
ok(str_contains($code,"if(!isset(\$newCats)||!is_array(\$newCats))\$newCats=[];if(!isset(\$libCats)||!is_array(\$libCats))\$libCats=[];?>\n<script>"),'4m: start/staged/published pages get empty lists before the script');
/* the exact expression the page prints, evaluated with no editor (variable unset), with null and with a list */
if(preg_match('/const batchNewCats=<\?=(.*?)\?>,sessionNewCats/s',$code,$mm)){$expr=$mm[1];
 $run=function($set,$val) use($expr){if($set)$newCats=$val;try{return eval('return '.$expr.';');}catch(Throwable $e){return 'THROW '.$e->getMessage();}};
 eq(@$run(false,null),'[]','4m: no item editor (start page, staged ec5de67d-like import) -> []');eq($run(true,null),'[]','4m: null list -> []');
 eq($run(true,['Space Science','Ocean Life']),'["Space Science","Ocean Life"]','4m: list kept');}else ok(false,'4m: batchNewCats expression found');
ok(str_contains($code,'Patch 4m ('),'4m: header marker');
/* 15. Patch 4n: Recently imported = still in the library (or on its way there) */
$fn4n=[];foreach(['ri_live_state'] as $want)for($i=0;$i<$cnt;$i++){if(!is_array($tok[$i])||$tok[$i][0]!==T_FUNCTION)continue;$j=$i+1;while(is_array($tok[$j])&&$tok[$j][0]===T_WHITESPACE)$j++;if(!is_array($tok[$j])||$tok[$j][0]!==T_STRING||$tok[$j][1]!==$want)continue;
 $src='';$depth=0;$started=false;for($k=$i;$k<$cnt;$k++){$t=$tok[$k];$s=is_array($t)?$t[1]:$t;$src.=$s;if($s==='{'||(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}elseif($s==='}'){$depth--;if($started&&$depth===0)break;}}$fn4n[$want]=$src;}
ok(isset($fn4n['ri_live_state']),'4n: found function ri_live_state');
if(isset($fn4n['ri_live_state'])){eval($fn4n['ri_live_state']);
 $RT=sys_get_temp_dir().'/p4n-unit-lib-'.getmypid();@mkdir("$RT/lib/Edutek Test Videos/Test 2",0777,true);file_put_contents("$RT/lib/Edutek Test Videos/Test 2/Kept.mp4",'x');
 $live='Edutek Test Videos/Test 2/Kept.mp4';$gone='Edutek Test Videos/Edutek Test Videos/Test Video 01 - Color Bars 720p.mp4';
 foreach(['undone','reversed','blocked','failed'] as $st){ok(ri_live_state($st,$gone,"$RT/lib")===false,"4n: publish $st, file not in library -> not recently imported");ok(ri_live_state($st,$live,"$RT/lib")===false,"4n: publish $st -> not recently imported (the library duplicate check covers real library files)");}
 foreach(['','requested','checking','publishing','attention','undoing'] as $st)ok(ri_live_state($st,$gone,"$RT/lib")===true,'4n: '.($st===''?'staged, not sent yet':$st).' -> still recently imported (pending)');
 ok(ri_live_state('published',$live,"$RT/lib")===true,'4n: published and the library file is there -> recently imported');
 ok(ri_live_state('published',$gone,"$RT/lib")===false,'4n: published but the library file is gone -> not recently imported (self-healing)');
 ok(ri_live_state('published',str_replace('/','\\',$live),"$RT/lib")===true,'4n: Windows-style target path works');
 ok(ri_live_state('published','Edutek Test Videos/../../etc/passwd',"$RT/lib")===false,'4n: .. in a target is never trusted');
 ok(ri_live_state('published','',"$RT/lib")===false,'4n: empty target -> not recently imported');
 ok(ri_live_state('published',$gone,"$RT/no-library")===true,'4n: library folder not visible -> old cautious behaviour (still flagged)');
 exec('rm -rf '.escapeshellarg($RT));}
ok(str_contains($code,"\$obid=basename(dirname(\$bp));foreach((\$ob['operations']??[]) as \$orid=>\$op){if((\$op['state']??'')!=='copied')continue;\$orow=\$ob['rows'][\$orid]??null;if(!is_array(\$orow)||!empty(\$orow['support']))continue;if(!ri_live(\$obid,(string)(\$orow['target']??'')))continue;"),'4n: scan uses only earlier imports that are still live');
ok(str_contains($code,"if(ri_state(\$bid)==='published')return \$tgt===''?true:ri_live(\$bid,\$tgt);")&&str_contains($code,"===(\$e['source_sha256']??'-')&&ri_live(\$bid,\$tgt);"),'4n: converted-earlier ledger uses the same rule');
ok(!str_contains($code,"if(defined('PS_DIR')&&(pq_read(PS_DIR.'/'.\$bid.'.json')['state']??'')==='published')return true;"),'4n: a published ledger entry is no longer trusted without the library file');
ok(str_contains($code,'Patch 4n (')&&str_contains($code,'Patch 4m ('),'4n: header marker');
/* 16. Patch 4o: keyboard-first screens = a layer on the unchanged 4n page */
$P4O_HDR='Patch 4o (keyboard-first 3-step screens on top of the classic page, which stays under Details; same forms, checks, publish and undo). ';
ok(substr_count($code,$P4O_HDR)===1&&str_contains($code,'Patch 4n ('),'4o: header marker (once), 4n marker kept');
$c4=$code;$okRev=true;$rev=function(string $a,string $b) use(&$c4,&$okRev){if(substr_count($c4,$a)!==1){$okRev=false;return;}$c4=str_replace($a,$b,$c4);};
$AUP=json_decode(<<<'AUPAIRS4O'
[["Patch 4o (keyboard-first 3-step screens", "Patch 4o audio (audio a browser can’t play is converted to MP3 at import; damaged audio is flagged, never ticked, never published). Patch 4o (keyboard-first 3-step screens"], ["function gp_requested(array $r): string {return gp_title_edited($r)?$r['title'].'.'.$r['extension']:basename($r['source']);}", "function gp_requested(array $r): string {$e=(string)($r['out_ext']??$r['extension']);return gp_title_edited($r)?$r['title'].'.'.$e:($e!==$r['extension']?pathinfo($r['source'],PATHINFO_FILENAME).'.'.$e:basename($r['source']));} // Patch 4o audio: a converted file gets .mp3"], ["  if(strtolower(pathinfo($dst,PATHINFO_EXTENSION))!==$r['extension'])throw new RuntimeException('File extension cannot change');", "  if(strtolower(pathinfo($dst,PATHINFO_EXTENSION))!==(string)($r['out_ext']??$r['extension']))throw new RuntimeException('File extension cannot change');if(!empty($r['damaged']))throw new RuntimeException('“'.$r['title'].'” can’t be played — the file is damaged. Untick it to continue.'); // Patch 4o audio"], ["if(!in_array($ext,['pdf','mp4'],true))continue;", "if(!in_array($ext,['pdf','mp4'],true)&&!in_array($ext,AU_EXT,true))continue; // Patch 4o audio\n"], ["'type'=>$ext==='pdf'?'Books':'Videos',", "'type'=>$ext==='pdf'?'Books':($ext==='mp4'?'Videos':au_type($rel,$root,$ext)),"], ["gp_scan_place($batch['rows'][$rid],$root); // Patch 4k", "gp_scan_place($batch['rows'][$rid],$root);au_scan_place($batch['rows'][$rid],$root);au_row_plan($batch['rows'][$rid]); // Patch 4k, Patch 4o audio"], ["$p=gp_newcat_canon($posted[$rid],$r,$ncMap4l);$r['include']=($p['include']??false)===true;", "$p=gp_newcat_canon($posted[$rid],$r,$ncMap4l);$r['include']=($p['include']??false)===true;if(!empty($r['damaged']))$r['include']=false; // Patch 4o audio: a damaged file is never included"], ["function mc_rows_plan(array &$b): void {foreach($b['rows'] as &$r)mc_row_plan($r);unset($r);}", "function mc_rows_plan(array &$b): void {$ch=false;foreach($b['rows'] as &$r){mc_row_plan($r);$ch=au_row_plan($r)||$ch;}unset($r);if($ch)gp_decollide($b);} // Patch 4o audio"], ["function mc_needs_job(array $r): bool {$m=mc_effective($r);", "function mc_needs_job(array $r): bool {if(au_is_audio($r))return ($r['media']['action']??'')==='mp3'; /* Patch 4o audio */$m=mc_effective($r);"], ["function mc_badge_html(array $r): string {\n $m=mc_effective($r);", "function mc_badge_html(array $r): string {\n if(au_is_audio($r))return au_badge_html($r); // Patch 4o audio\n $m=mc_effective($r);"], ["  mc_convert_one($batch,$dir,$rid,$r);\n }", "  if(au_is_audio($r))au_convert_one($batch,$dir,$rid,$r);else mc_convert_one($batch,$dir,$rid,$r); // Patch 4o audio\n }"], ["foreach($chosen as $rid=>$r)if(($batch['operations'][$rid]['state']??'')!=='copied')throw new RuntimeException('Not every file was staged: '.$r['target']);", "foreach($chosen as $rid=>$r)if(!empty($batch['rows'][$rid]['include'])&&($batch['operations'][$rid]['state']??'')!=='copied')throw new RuntimeException('Not every file was staged: '.$r['target']); // Patch 4o audio: a left-out audio file is no longer included"], ["$n=['convert'=>0,'audio'=>0,'faststart'=>0,'fallback'=>0];", "$n=['convert'=>0,'audio'=>0,'faststart'=>0,'fallback'=>0,'mp3'=>0];"], ["' copied unchanged after a failed conversion. Staged copy complete", "' copied unchanged after a failed conversion. Audio: '.$n['mp3'].' converted to MP3, '.count((array)($batch['audio_skipped']??[])).' left out (could not be made playable). Staged copy complete"], ["header('Content-Type: '.($ext==='pdf'?'application/pdf':'video/mp4'));header('Accept-Ranges: bytes');", "header('Content-Type: '.($ext==='pdf'?'application/pdf':(au_mime($ext)??'video/mp4')));header('Accept-Ranges: bytes');"], ["<h3>Some videos could not be converted</h3>", "<h3><?=array_filter((array)$batch['media_warnings'],fn($w)=>!empty($w['audio_skip']))?'Some files could not be converted':'Some videos could not be converted'?></h3>"], ["data-type=\"<?=h($r['type'])?>\" data-pending=", "data-type=\"<?=h($r['type'])?>\" data-damaged=\"<?=!empty($r['damaged'])?'1':'0'?>\" data-pending="], ["<span class=\"badge\"><?=h($r['type']==='Books'?'Book':'Video')?></span>", "<span class=\"badge\"><?=h(['Books'=>'Book','Music'=>'Music','Audiobooks'=>'Audiobook'][$r['type']]??'Video')?></span>"], ["<div class=\"art placeholder\"><?=h($r['type']==='Books'?'BOOK':'VIDEO')?></div>", "<div class=\"art placeholder\"><?=h($r['type']==='Books'?'BOOK':(au_is_audio($r)?'AUDIO':'VIDEO'))?></div>"], ["($r['type']==='Videos'?'Preview will be generated':'Cover will be generated from page 1')", "($r['type']==='Videos'?'Preview will be generated':(au_is_audio($r)?'No cover picture':'Cover will be generated from page 1'))"], ["<input type=\"checkbox\" data-role=\"include\" <?=$r['include']?'checked':''?>> Add this <?=$r['type']==='Books'?'book':'video'?>", "<input type=\"checkbox\" data-role=\"include\" <?=$r['include']?'checked':''?><?=!empty($r['damaged'])?' disabled':''?>> Add this <?=$r['type']==='Books'?'book':(au_is_audio($r)?'audio file':'video')?><?=!empty($r['damaged'])?' (can’t be played — file is damaged: '.h((string)($r['media']['why']??'')).')':''?>"], ["function mc_cli(array $argv): int {", "/* Patch 4o audio: begin */\n/* Patch 4o audio: audio files are scanned as Music or Audiobooks. Audio a browser can't play is converted to MP3 at import\n   (libmp3lame VBR -q:a 4, tags kept, same base name .mp3, single-thread ffmpeg, like the WmaToMp3 step of Finish-LibraryCleanup-r1b).\n   A file that can't be read or decoded is \"damaged\": never ticked, never staged. A failed conversion leaves the item out (never published unplayable). */\nconst AU_EXT=['mp3','wav','ogg','oga','wma','ra','rm','ape','aiff','aif','aifc','amr','ac3','m4a','m4b','flac','opus','aac','mka','wv','mpc','au'];\nconst AU_Q='4';const AU_MIN_SEC=0.5;const AU_DECODE_SEC=30;const AU_DUR_TOL=1.0;\nconst AU_SETTINGS='libmp3lame VBR -q:a '.AU_Q.' (about 128-165 kbit/s), tags kept (-map_metadata 0, ID3v2.3), first audio stream, 1 thread';\nfunction au_is_audio(array $r): bool {return empty($r['support'])&&in_array($r['type']??'',['Music','Audiobooks'],true)&&in_array((string)($r['extension']??''),AU_EXT,true);}\nfunction au_mime(string $ext): ?string {return ['mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg'][$ext]??null;}\nfunction au_type(string $rel,string $root,string $ext): string {\n if($ext==='m4b')return 'Audiobooks';\n foreach(array_merge([basename($root)],explode('/',dirname($rel))) as $p)if(str_contains(preg_replace('/[^a-z0-9]+/','',mb_strtolower($p)),'audiobook'))return 'Audiobooks';\n return 'Music';\n}\n/* Album / audiobook folder: the first source subfolder (skipping one named Music/Audiobooks), or the chosen folder's name for loose files. */\nfunction au_scan_place(array &$r,string $root): void {\n if(!au_is_audio($r))return;$role=gp_role((string)$r['type']);\n $dirs=array_values(array_filter(explode('/',dirname((string)$r['source'])),fn($p)=>$p!=='.'&&$p!==''));\n while($dirs&&in_array(preg_replace('/[^a-z]+/','',mb_strtolower($dirs[0])),['music','audiobooks','audiobook'],true))array_shift($dirs);\n $name=$dirs[0]??basename($root);if(in_array(preg_replace('/[^a-z]+/','',mb_strtolower($name)),['music','audiobooks','audiobook',''],true))$name=$r['type']==='Music'?'Imported Music':'Imported Audiobooks';\n $f=gp_clean_part($name,$role.'/');if($f===''||$f==='.'||$f==='..')$f=$r['type']==='Music'?'Imported Music':'Imported Audiobooks';\n $r['placement_category']=$role;$r['placement_folder']=$f;$r['placement_override']=false;$r['auto_category']=$role;\n}\nfunction au_codec_name(string $c): string {\n return ['wmav1'=>'WMA','wmav2'=>'WMA','wmapro'=>'WMA Pro','wmalossless'=>'WMA Lossless','wmavoice'=>'WMA Voice','cook'=>'RealAudio','ra_144'=>'RealAudio','ra_288'=>'RealAudio','sipr'=>'RealAudio','ape'=>\"Monkey's Audio (APE)\",'amr_nb'=>'AMR','amr_wb'=>'AMR-WB','ac3'=>'AC-3','eac3'=>'E-AC-3','aac'=>'AAC','alac'=>'ALAC','flac'=>'FLAC','opus'=>'Opus','vorbis'=>'Vorbis','mp3'=>'MP3','mp2'=>'MP2','wavpack'=>'WavPack','musepack7'=>'Musepack','musepack8'=>'Musepack'][$c]??(str_starts_with($c,'pcm_')?'PCM':($c===''?'unknown':strtoupper($c)));\n}\nfunction au_probe(string $p): ?array {\n [$c,$o]=mc_run(['ffprobe','-v','error','-show_entries','format=duration,format_name:stream=index,codec_type,codec_name,channels,sample_rate,bit_rate:stream_disposition=attached_pic','-of','json',$p],120);\n if($c!==0)return null;$j=json_decode($o,true);if(!is_array($j))return null;$a=null;\n foreach($j['streams']??[] as $s)if(($s['codec_type']??'')==='audio'&&empty($s['disposition']['attached_pic'])){$a=$s;break;}\n if(!$a)return null;\n return ['a'=>(string)($a['codec_name']??''),'ai'=>(int)$a['index'],'ch'=>(int)($a['channels']??0),'rate'=>(int)($a['sample_rate']??0),'dur'=>(float)($j['format']['duration']??0),'fmt'=>(string)($j['format']['format_name']??'')];\n}\n/* Decodes the first AU_DECODE_SEC seconds; returns [error or '', seconds decoded]. A truncated file stops early. */\nfunction au_decode(string $p,int $ai,float $limit=AU_DECODE_SEC): array {\n [$c,$o,$e]=mc_run(['ffmpeg','-nostdin','-hide_banner','-v','error','-xerror','-threads','1','-i',$p,'-map','0:'.$ai,'-t',(string)$limit,'-progress','pipe:1','-nostats','-f','null','-'],max(300,(int)($limit*4)));\n $us=0;if(preg_match_all('/^out_time_us=(\\d+)/m',(string)$o,$m))$us=(int)end($m[1]);\n return [$c===0?'':'it does not decode'.(trim((string)$e)!==''?' ('.trim(substr(preg_replace('/\\s+/',' ',(string)$e),0,160)).')':''),$us/1e6];\n}\n/* keep = plays in browsers and the publisher accepts the extension (mp3, wav, ogg) */\nfunction au_keep(string $ext,string $codec): bool {\n if($ext==='mp3')return $codec==='mp3';\n if($ext==='wav')return str_starts_with($codec,'pcm_')&&!str_ends_with($codec,'be')&&$codec!=='pcm_s8';\n if($ext==='ogg')return in_array($codec,['vorbis','opus'],true);\n return false;\n}\nfunction au_plan(?array $pr,string $ext,string $decErr='',float $decSec=-1.0): array {\n $dmg=['action'=>'damaged','label'=>'Can’t be played — file is damaged'];\n if(!$pr)return $dmg+['why'=>'the audio details could not be read (ffprobe found no playable audio)'];\n if($decErr!=='')return $dmg+['why'=>$decErr,'acodec'=>$pr['a']];\n $want=min(AU_DECODE_SEC,$pr['dur']);\n if($pr['dur']>=AU_MIN_SEC&&$decSec>=0&&$decSec<$want-1.5)return $dmg+['why'=>sprintf('it is cut short: it should last %.1f s but only %.1f s of sound could be read',$pr['dur'],$decSec),'acodec'=>$pr['a']];\n if($pr['dur']<AU_MIN_SEC||($decSec>=0&&$decSec<AU_MIN_SEC))return $dmg+['why'=>sprintf('it holds only %.2f s of sound',max(0,$decSec>=0?$decSec:$pr['dur'])),'acodec'=>$pr['a']];\n if($decSec>=0&&$decSec<$want-1.5)return $dmg+['why'=>sprintf('it is cut short: it should last %.1f s but only %.1f s of sound could be read',$pr['dur'],$decSec),'acodec'=>$pr['a']];\n $base=['acodec'=>$pr['a'],'channels'=>$pr['ch'],'sample_rate'=>$pr['rate'],'duration'=>round($pr['dur'],3)];\n if(au_keep($ext,$pr['a']))return $base+['action'=>'none','label'=>'Ready for browsers ('.au_codec_name($pr['a']).')'];\n return $base+['action'=>'mp3','label'=>strtoupper($ext).(strcasecmp(au_codec_name($pr['a']),$ext)===0?'':' ('.au_codec_name($pr['a']).')').' → MP3','from'=>au_codec_name($pr['a'])];\n}\n/* Planned once per source version (bytes+mtime) at scan and on save. Returns true when the planned file name changed. */\nfunction au_row_plan(array &$r): bool {\n if(!au_is_audio($r))return false;$m=$r['media']??null;\n if(is_array($m)&&($m['src_bytes']??null)===$r['bytes']&&($m['src_mtime']??null)===$r['mtime'])return false;\n $old=(string)($r['out_ext']??'');\n try{$p=pv_input($r);$pr=au_probe($p);[$de,$ds]=$pr?au_decode($p,$pr['ai']):['',-1.0];}catch(Throwable $e){$pr=null;$de='';$ds=-1.0;}\n $r['media']=au_plan($pr,(string)$r['extension'],$de,$ds)+['src_bytes'=>$r['bytes'],'src_mtime'=>$r['mtime'],'kind'=>'audio'];\n if($r['media']['action']==='mp3')$r['out_ext']='mp3';else unset($r['out_ext']);\n if($r['media']['action']==='damaged'){$r['damaged']=true;$r['include']=false;}else unset($r['damaged']);\n return $old!==(string)($r['out_ext']??'');\n}\nfunction au_badge_html(array $r): string {\n $m=$r['media']??null;if(!is_array($m))return '';$a=(string)($m['action']??'');\n if($a==='damaged')return '<span class=\"badge media-badge audio-badge damaged-badge\" style=\"background:#fde2e2;color:#7f1d1d\">'.h('Can’t be played — file is damaged').'</span>';\n if($a==='mp3')return '<span class=\"badge media-badge audio-badge\" style=\"background:#fde8c4;color:#7a4300\">'.h('Will be converted to MP3: '.$m['label']).'</span>';\n return '<span class=\"badge media-badge audio-badge\" style=\"background:#d4efdc;color:#14532d\">'.h((string)$m['label']).'</span>';\n}\nfunction au_cmd(string $src,string $out,array $pr): array {\n $c=['ffmpeg','-nostdin','-hide_banner','-v','error','-threads','1','-n','-i',$src,'-map','0:'.$pr['ai'],'-map_metadata','0','-c:a','libmp3lame','-q:a',AU_Q];\n if($pr['ch']>2)array_push($c,'-ac','2');\n array_push($c,'-id3v2_version','3','-threads','1','-progress','pipe:1','-nostats','-f','mp3',$out);return $c;\n}\nfunction au_verify(string $out,array $src): string {\n $o=au_probe($out);if(!$o)return 'the new MP3 could not be read';\n if($o['a']!=='mp3')return 'the new file is '.au_codec_name($o['a']).', not MP3';\n if(abs($o['dur']-$src['dur'])>AU_DUR_TOL)return sprintf('the new file lasts %.2f s instead of %.2f s',$o['dur'],$src['dur']);\n [$de,$ds]=au_decode($out,$o['ai'],max(1.0,$src['dur']+5));if($de!=='')return 'the new MP3 '.$de;\n if(abs($ds-$src['dur'])>AU_DUR_TOL)return sprintf('the new MP3 plays %.2f s instead of %.2f s',$ds,$src['dur']);\n return '';\n}\n/* Leaves a row out of the import (its artwork too) after it could not be made playable. Nothing unplayable is staged. */\nfunction au_skip(array &$batch,string $dir,string $rid,array $r,string $why): void {\n unset($GLOBALS['bi_prog']['extra'],$batch['operations'][$rid]);$batch['rows'][$rid]['include']=false;$batch['rows'][$rid]['audio_skipped']=$why;\n foreach($batch['rows'] as $sid=>$s)if(!empty($s['support'])&&($s['parent']??'')===$rid){$batch['rows'][$sid]['include']=false;\n  if(isset($batch['operations'][$sid])){$sp=destfile($dir,(string)$batch['operations'][$sid]['target']);if(is_file($sp)){@mkdir($dir.'/recovery',0770,true);@rename($sp,$dir.'/recovery/skipped-'.$sid.'-'.basename($sp));}unset($batch['operations'][$sid]);}}\n $batch['approval']=hash('sha256',json_encode($batch['rows'],JSON_THROW_ON_ERROR));\n $msg=$r['title'].' ('.basename((string)$r['source']).'): can’t be converted to MP3 ('.$why.'). It was left out of this import, so nothing unplayable is published. Your source file is unchanged.';\n $batch['media_warnings'][]=['rid'=>$rid,'target'=>$r['target'],'message'=>$msg,'audio_skip'=>true];$batch['audio_skipped'][]=['rid'=>$rid,'title'=>$r['title'],'source'=>$r['source'],'why'=>$why];\n $batch['events'][]=gmdate(DATE_ATOM).' Audio left out: '.$r['source'].' ('.$why.'); nothing staged for it';savebatch($batch);\n}\nfunction au_convert_one(array &$batch,string $dir,string $rid,array $r): void {\n $src=pv_input($r);clearstatcache(true,$src);\n if(!is_file($src)||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime'])throw new RuntimeException('Source metadata changed: '.$r['source']);\n $dst=destfile($dir,$r['target']);$parent=dirname($dst);if(!is_dir($parent)&&!mkdir($parent,0770,true))throw new RuntimeException('Cannot create destination');\n $work=$dir.'/work';if(!is_dir($work)&&!mkdir($work,0770))throw new RuntimeException('Cannot create the conversion work folder');$part=$work.'/'.$rid.'.mp3';\n $op=$batch['operations'][$rid]??[];\n if(file_exists($dst)){if(($op['state']??'')==='converting'&&!empty($op['out_sha256'])&&is_file($dst)&&hash_equals((string)$op['out_sha256'],(string)hash_file('sha256',$dst))){$op['state']='copied';$op['bytes']=(int)$op['out_bytes'];$op['sha256']=$op['out_sha256'];unset($op['out_sha256'],$op['out_bytes']);$batch['operations'][$rid]=$op;savebatch($batch);return;}\n  throw new RuntimeException('Destination exists; overwrite forbidden');}\n if(is_file($part))@unlink($part);@unlink($part.'.log');\n $tries=(int)($op['media_tries']??0);\n $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'converting','bytes'=>$r['bytes'],'source_bytes'=>$r['bytes'],'media_tries'=>$tries+1];savebatch($batch);\n if($tries>=MC_RETRIES){au_skip($batch,$dir,$rid,$r,'it was interrupted '.$tries.' times');return;}\n $GLOBALS['bi_prog']['extra']=['label'=>'Checking the original','pct'=>0,'speed'=>'','elapsed'=>0];bi_progress('converting',basename($r['target']),0,(int)$r['bytes'],true);\n $srcSha=(string)hash_file('sha256',$src);$pr=au_probe($src);\n if(!$pr){au_skip($batch,$dir,$rid,$r,'the audio details could not be read');return;}\n [$de,$ds]=au_decode($src,$pr['ai']);$plan=au_plan($pr,(string)$r['extension'],$de,$ds);\n if($plan['action']!=='mp3'){au_skip($batch,$dir,$rid,$r,$plan['action']==='damaged'?'the file is damaged: '.($plan['why']??''):'the file changed since it was checked');return;}\n $t0=microtime(true);\n $err=mc_ffmpeg(au_cmd($src,$part,$pr),['dur'=>$pr['dur']],basename($r['target']),(int)$r['bytes'],$plan['label']);\n if($err===''){$GLOBALS['bi_prog']['extra']=['label'=>'Checking the new MP3','pct'=>100,'speed'=>'','elapsed'=>(int)(microtime(true)-$t0)];bi_progress('converting',basename($r['target']),(int)$r['bytes'],(int)$r['bytes'],true);$err=au_verify($part,$pr);}\n clearstatcache(true,$src);if(!is_file($src)||filesize($src)!==$r['bytes']||filemtime($src)!==$r['mtime']){@unlink($part);@unlink($part.'.log');throw new RuntimeException('Source changed during conversion: '.$r['source']);}\n if($err!==''){@unlink($part);@unlink($part.'.log');au_skip($batch,$dir,$rid,$r,$err);return;}\n $osha=(string)hash_file('sha256',$part);clearstatcache(true,$part);$obytes=(int)filesize($part);$o=au_probe($part);\n $batch['operations'][$rid]['out_sha256']=$osha;$batch['operations'][$rid]['out_bytes']=$obytes;savebatch($batch);\n if(file_exists($dst)||!rename($part,$dst))throw new RuntimeException('Cannot move the converted file into staging');\n if(!hash_equals($osha,(string)hash_file('sha256',$dst)))throw new RuntimeException('Converted file changed while moving it into staging');\n @unlink($part.'.log');$secs=round(microtime(true)-$t0,1);\n $media=['action'=>'mp3','label'=>$plan['label'],'from'=>au_codec_name($pr['a']).' '.$pr['rate'].' Hz '.$pr['ch'].' ch','to'=>'MP3'.($o?' '.round($o['dur'],2).' s':''),'seconds'=>$secs,'duration'=>round($pr['dur'],3),'out_duration'=>$o?round($o['dur'],3):null,'settings'=>AU_SETTINGS];\n $batch['operations'][$rid]=['target'=>$r['target'],'state'=>'copied','bytes'=>$obytes,'sha256'=>$osha,'source_bytes'=>$r['bytes'],'source_sha256'=>$srcSha,'media'=>$media,'media_tries'=>$tries+1];\n $batch['events'][]=gmdate(DATE_ATOM).' '.$r['target'].': '.$plan['label'].' in '.$secs.' s';savebatch($batch);\n}\n/* Patch 4o audio: end */\nfunction mc_cli(array $argv): int {"]]
AUPAIRS4O,true);ok(is_array($AUP)&&count($AUP)===22,'audio: 22 audio changes listed');
foreach(array_reverse((array)$AUP) as [$a,$b])$rev($b,$a);
$rev($P4O_HDR,'');
$i1=strpos($c4,"/* Patch 4o */\n");$i2=$i1===false?false:strpos($c4,'</style></head><body>',$i1);if($i1===false||$i2===false)$okRev=false;else $c4=substr($c4,0,$i1).substr($c4,$i2);
$rev("&&(window.EDU4O_ON||!el.closest('.item')?.hidden))","&&!el.closest('.item')?.hidden)");
$rev("if(!window.EDU4O_OK&&!confirm('Copy the materials","if(!confirm('Copy the materials");
$rev("window.EdutekClassic={items,get,changed,render,setCategoryFor,newCatList,isLibCat,catKey,proposeCat,sessionNewCats,refreshNew,acceptFix,isNameIssue,placementRoles,validatePlacement,syncLocation,isDirty:()=>dirty}; // Patch 4o\n",'');
$j1=strpos($c4,"</script>\n<?php /* Patch 4o: data");$j2=strrpos($c4,'</script></body></html>');
if($j1===false||$j2===false||$j2<$j1)$okRev=false;else{$layer=substr($c4,$j1+9,$j2-$j1-9);$c4=substr($c4,0,$j1).substr($c4,$j2);}
ok($okRev,'4o: every 4o change is found exactly once');
ok(strtoupper(hash('sha256',$c4))==='0CD7E9AED0DD68CE281D9F2BCA5BECE9D57337805702C1495C69C9F54E875061','4o: without the 4o layer and the 4o audio changes the page is byte-for-byte the live Patch 4n page (all import, staging, conversion, category, publish and undo code unchanged)',strtoupper(hash('sha256',$c4)));
$layer=$layer??'';$k=strpos($layer,'?><script type="application/json" id="ui4o-data">');$js=$k===false?'':substr($layer,strpos($layer,"<script>\n",$k)+9);
ok($k!==false&&substr_count($layer,'<?php')===1&&substr_count($layer,'<?=')===1&&substr_count($layer,'?>')===2,'4o: one PHP block (read-only data for the screens), ending before its JSON script');
ok($js!==''&&!str_contains($js,'<?')&&!str_contains($js,'?>')&&stripos($js,'</script')===false,'4o: the screen script contains no PHP and no </script>');
ok(str_contains($layer,'}catch(Throwable $e4){$u4=')&&str_contains($layer,'JSON_HEX_TAG|JSON_HEX_AMP'),'4o: data block cannot break the page (try/catch, JSON escaped for HTML)');
ok(!preg_match('/file_put_contents|unlink|rename\(|mkdir|fopen|exec\(|shell_exec|proc_open|guide_config|\.env/',$layer),'4o: data block only reads (no writes, no commands, no secrets)');
ok(str_contains($js,"if(!D||D.v!==1)")&&str_contains($js,'window.EDU4O_ON=true;'),'4o: screens start only with valid data; otherwise the classic page is shown as before');
ok(str_contains($js,"removeAttribute('autofocus')")&&!preg_match('/[^-]autofocus[\'"]?\s*:/',$js),'4o: the screens set no autofocus (the classic autofocus is removed; Publish never gets the focus by itself)');
ok(str_contains($js,"location.pathname+location.search")&&str_contains($js,"'#start'"),'4o: #start / #undo links are cleared after use');
ok(rtrim($code)!==''&&str_ends_with(rtrim($code),'</html>'),'4o: file ends with </html>');
/* 17. Patch 4o audio: MP3 conversion at import, damaged audio */
ok(isset($AU_OK)&&$AU_OK,'audio: the audio block is in the page once and loads');
foreach(['wma','ra','rm','ape','aiff','aif','amr','ac3','m4b','mp3','wav','ogg','m4a','flac'] as $e)ok(in_array($e,AU_EXT,true),"audio: .$e files are picked up by the scan");
eq([au_keep('mp3','mp3'),au_keep('mp3','mp2'),au_keep('wav','pcm_s16le'),au_keep('wav','pcm_s24le'),au_keep('wav','pcm_s16be'),au_keep('wav','adpcm_ms'),au_keep('ogg','vorbis'),au_keep('ogg','opus'),au_keep('m4a','aac'),au_keep('flac','flac'),au_keep('wma','wmav2'),au_keep('m4b','aac')],
 [true,false,true,true,false,false,true,true,false,false,false,false],'audio: kept as is only when browsers play it AND the publisher accepts it (mp3, wav PCM, ogg)');
eq([au_type('Road Trip/a.mp3','/host/c/Music','mp3'),au_type('Audiobooks/GGS/a.mp3','/host/c/x','mp3'),au_type('a.mp3','/host/c/My Audio Books','mp3'),au_type('x/a.m4b','/host/c/x','m4b')],['Music','Audiobooks','Audiobooks','Audiobooks'],'audio: Music vs Audiobooks');
eq([au_mime('mp3'),au_mime('wav'),au_mime('ogg'),au_mime('pdf')],['audio/mpeg','audio/wav','audio/ogg',null],'audio: Open serves audio types');
$PR=fn(array $x)=>$x+['a'=>'wmav2','ai'=>0,'ch'=>2,'rate'=>44100,'dur'=>4.0,'fmt'=>'asf'];
eq(au_plan(null,'mp3')['action'],'damaged','audio: no audio found -> damaged');
eq(au_plan($PR(['a'=>'mp3']),'mp3','it does not decode',0)['action'],'damaged','audio: decode error -> damaged');
$p=au_plan($PR(['a'=>'mp3','dur'=>60.0]),'mp3','',0.21);ok($p['action']==='damaged'&&str_contains($p['why'],'cut short'),'audio: 4 KB slice of a 60 s MP3 -> damaged, cut short',json_encode($p,JSON_UNESCAPED_UNICODE));
$p=au_plan($PR(['a'=>'mp3','dur'=>0.3]),'mp3','',0.3);ok($p['action']==='damaged'&&str_contains($p['why'],'only 0.30 s'),'audio: tiny file (0.3 s) -> damaged',json_encode($p,JSON_UNESCAPED_UNICODE));
eq(au_plan($PR([]),'wma','',4.0)['label'],'WMA → MP3','audio: WMA -> MP3 label');
eq(au_plan($PR(['a'=>'pcm_s16be','dur'=>3.0]),'aiff','',3.0)['label'],'AIFF (PCM) → MP3','audio: AIFF -> MP3 label');
eq(au_plan($PR(['a'=>'cook']),'rm','',4.0)['label'],'RM (RealAudio) → MP3','audio: RealAudio -> MP3 label');
eq(au_plan($PR(['a'=>'mp3']),'mp3','',4.0)['action'],'none','audio: healthy MP3 kept');
eq(au_plan($PR(['a'=>'pcm_s16le']),'wav','',4.0)['action'],'none','audio: WAV kept');
eq(au_plan($PR(['a'=>'aac']),'m4b','',4.0)['action'],'mp3','audio: m4b (AAC) -> MP3 (the unchanged publisher accepts only mp3/ogg/wav audio)');
$cm=au_cmd('/s.wma','/o.mp3',$PR(['ch'=>6,'ai'=>1]));$cs=implode(' ',$cm);
ok(substr_count($cs,'-threads 1')===2&&str_contains($cs,'-c:a libmp3lame -q:a 4')&&str_contains($cs,'-map_metadata 0')&&str_contains($cs,'-map 0:1')&&str_contains($cs,'-ac 2')&&str_contains($cs,'-id3v2_version 3')&&in_array('-n',$cm,true)&&!in_array('-y',$cm,true),'audio: ffmpeg: single thread, libmp3lame VBR q4, tags kept, first audio stream, 5.1 -> stereo, never overwrites',$cs);
ok(!str_contains(implode(' ',au_cmd('/s.wma','/o.mp3',$PR([]))),'-ac 2'),'audio: stereo stays as it is');
$bd=au_badge_html(['media'=>['action'=>'damaged']]);$bm=au_badge_html(['media'=>['action'=>'mp3','label'=>'WMA → MP3']]);
ok(str_contains($bd,'Can’t be played — file is damaged')&&str_contains($bd,'media-badge')&&str_contains($bm,'Will be converted to MP3: WMA → MP3')&&str_contains($bm,'audio-badge'),'audio: card tags in plain words',$bd.' | '.$bm);
ok(mc_needs_job(['type'=>'Music','extension'=>'wma','media'=>['action'=>'mp3']])&&!mc_needs_job(['type'=>'Music','extension'=>'mp3','media'=>['action'=>'none']])&&!mc_needs_job(['type'=>'Music','extension'=>'mp3','media'=>['action'=>'damaged']]),'audio: only MP3 conversions go to the background runner');
eq(gp_requested(['title'=>'01 Sunrise','extension'=>'wma','out_ext'=>'mp3','source'=>'Road Trip/01 Sunrise.wma','title_auto'=>'01 Sunrise']+(isset($fn['gp_title_edited'])?[]:[])),'01 Sunrise.mp3','audio: the library name keeps the base name with .mp3');
/* real files (needs ffmpeg) */
$at=sys_get_temp_dir().'/p4o-au-'.bin2hex(random_bytes(4));mkdir($at);
exec('ffmpeg -nostdin -v error -f lavfi -i sine=f=440:d=4 -c:a wmav2 -b:a 96k -metadata title=Sunrise -metadata album="Road Trip Mix" -metadata track=1 '.escapeshellarg("$at/a.wma").' 2>&1',$o,$c);ok($c===0,'audio: test WMA made');
$pr=au_probe("$at/a.wma");ok(($pr['a']??'')==='wmav2'&&abs(($pr['dur']??0)-4)<0.1,'audio: probe WMA',json_encode($pr));
[$de,$ds]=au_decode("$at/a.wma",(int)($pr['ai']??0));$pl=au_plan($pr,'wma',$de,$ds);eq($pl['action'],'mp3','audio: WMA planned for MP3');
[$rc]=mc_run(au_cmd("$at/a.wma","$at/a.mp3",$pr),120);ok($rc===0&&is_file("$at/a.mp3"),'audio: conversion ran');
eq(au_verify("$at/a.mp3",$pr),'','audio: the new MP3 passes the check (MP3, plays to the end, duration within 1 s)');
exec('ffprobe -v error -show_entries format_tags=title,album,track -of default=nw=1 '.escapeshellarg("$at/a.mp3"),$tg);$tg=strtolower(implode('|',$tg));
ok(str_contains($tg,'title=sunrise')&&str_contains($tg,'album=road trip mix')&&str_contains($tg,'track=1'),'audio: tags kept',$tg);
eq(au_verify("$at/a.mp3",['dur'=>6.0]+$pr)!=='','true'!=='','audio: a wrong duration fails the check');
file_put_contents("$at/b.mp3",random_bytes(4096));eq(au_probe("$at/b.mp3"),null,'audio: 4 KB of junk -> no audio found');
exec('ffmpeg -nostdin -v error -f lavfi -i sine=d=60 -c:a libmp3lame -b:a 128k '.escapeshellarg("$at/full.mp3"));file_put_contents("$at/t.mp3",file_get_contents("$at/full.mp3",false,null,0,4096));
$tp=au_probe("$at/t.mp3");[$de,$ds]=$tp?au_decode("$at/t.mp3",$tp['ai']):['x',0];$pl=au_plan($tp,'mp3',$de,$ds);ok($tp!==null&&$pl['action']==='damaged','audio: 4 KB slice of a 60 s MP3: ffprobe accepts it, the decode check catches it',json_encode($pl,JSON_UNESCAPED_UNICODE));
exec('ffmpeg -nostdin -v error -f lavfi -i sine=d=3 -ac 6 -c:a ac3 '.escapeshellarg("$at/s.ac3"));$sp=au_probe("$at/s.ac3");[$rc]=mc_run(au_cmd("$at/s.ac3","$at/s.mp3",$sp),120);
exec('ffprobe -v error -show_entries stream=channels -of csv=p=0 '.escapeshellarg("$at/s.mp3"),$ch);ok($rc===0&&trim($ch[0]??'')==='2'&&au_verify("$at/s.mp3",$sp)==='','audio: 5.1 AC-3 -> stereo MP3, verified',json_encode([$rc,$ch]));
exec('rm -rf '.escapeshellarg($at));
/* page wiring */
foreach(["if(!empty(\$r['damaged']))\$r['include']=false; // Patch 4o audio","if(!empty(\$r['damaged']))throw new RuntimeException(","if(au_is_audio(\$r))au_convert_one(\$batch,\$dir,\$rid,\$r);else mc_convert_one(","if(!empty(\$batch['rows'][\$rid]['include'])&&(\$batch['operations'][\$rid]['state']??'')!=='copied')",
 'data-damaged="<?=','<?=!empty($r[\'damaged\'])?\' disabled\':\'\'?>',"au_scan_place(\$batch['rows'][\$rid],\$root);au_row_plan(\$batch['rows'][\$rid]);","'Some files could not be converted'"] as $w)ok(substr_count($code,$w)===1,'audio: page wiring: '.substr($w,0,70));
$ab=substr($code,(int)strpos($code,'/* Patch 4o audio: begin */'),(int)strpos($code,'/* Patch 4o audio: end */')-(int)strpos($code,'/* Patch 4o audio: begin */'));
ok(!preg_match('/guide_config|\.env|unlink\(\$src|rename\(\$src|-y\'/',$ab),'audio: never touches the source file, never overwrites, no secrets');
exec('rm -rf '.escapeshellarg($LIB));
echo "unit-p4o: $pass passed, $fail failed\n";if($fail===0)echo "UNIT TESTS PASSED\n";exit($fail===0?0:1);
