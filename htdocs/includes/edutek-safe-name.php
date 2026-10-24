<?php
/* edutek_safe_name v1 - the ONE shared naming rule of the Edutek importer (Patch 4g, 2026-10-22).
   Source: "Library cleanup survey.txt", section 3. Used by bulk-import-guided.php (review preview, staging, dedup)
   and, through edutek-safe-name-cli.php, by Publish-Batch.ps1. A later copy in the app (includes/safe-name.php)
   must stay identical: change both together.
   - sn_junk(name, isDir, bytes)  -> '' or the reason a scanned entry is junk (skipped, never imported)
   - sn_component(part)           -> safe folder name / file stem       (steps 1-4)
   - sn_filename(name)            -> safe file name, extension kept and lower-cased
   - sn_info(name, isFile)        -> ['clean'=>..., 'review'=>[reasons]]  (U+FFFD, shortened, emptied, reserved)
   - sn_unique(name, taken)       -> name, or "name (2)", "name (3)"...  (step 5, after the duplicate check)
   - sn_title(name, stripNumber)  -> display title (title rule)
   - sn_key(name)                 -> letters+digits key of the clean stem (duplicate matching) */
const SN_RULE='edutek_safe_name v1';
const SN_MAX=120;
const SN_JUNK_DIRS=['__macosx','.spotlight-v100','.trashes','.fseventsd','$recycle.bin'];
const SN_JUNK_FILES=['.ds_store','thumbs.db','desktop.ini'];
const SN_MEDIA_EXT=['mp4','webm','mkv','avi','mov','m4v','mp3','ogg','wav','m4a','pdf','epub','jpg','jpeg','png','webp'];

function sn_junk(string $name,bool $isDir,int $bytes=-1): string {
 $l=mb_strtolower($name,'UTF-8');
 if($isDir){if(in_array($l,SN_JUNK_DIRS,true))return 'system folder';if(str_starts_with($name,'.'))return 'hidden folder';return '';}
 if(str_starts_with($name,'._'))return 'Mac resource file (._)';
 if(in_array($l,SN_JUNK_FILES,true))return 'system file';
 if(str_starts_with($name,'.'))return 'hidden dot-file';
 if(str_ends_with($l,'.lnk'))return 'shortcut (.lnk)';
 $d=strrpos($l,'.');$e=$d===false?'':substr($l,$d+1);
 if($bytes===0&&in_array($e,SN_MEDIA_EXT,true))return 'empty file (0 bytes)';
 return '';
}
function sn_nfc(string $s): string {
 if(!preg_match('//u',$s))throw new RuntimeException('Invalid UTF-8 in a name');
 $n=class_exists('Normalizer')?Normalizer::normalize($s,Normalizer::FORM_C):$s;if(!is_string($n))throw new RuntimeException('Invalid Unicode in a name');return $n;
}
/* Step 1 + 2 (shared by file and title mode). $title=true: ':' and '?' kept, '#<digit>' kept, '& + %' kept. */
function sn_map(string $s,bool $title,array &$review): string {
 $s=sn_nfc($s);
 if(str_contains($s,"\u{FFFD}")){$review[]='unreadable character (U+FFFD) removed';$s=str_replace("\u{FFFD}",'',$s);}
 $s=preg_replace('/[\x{0009}\x{000A}\x{000D}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u',' ',$s);
 $s=preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200D}\x{FEFF}\x{2060}\x{E000}-\x{F8FF}\x{2600}-\x{27BF}\x{FE0F}\x{1F000}-\x{1FFFF}]/u','',$s);
 // hashtags: a trailing run of #word tags is dropped (unless that would leave nothing), other #word keeps the word
 $t=preg_replace('/(?:\s*#\p{L}[\p{L}\p{N}_]*)+\s*$/u','',$s);if(trim((string)$t)!=='')$s=$t;
 $s=preg_replace('/#(?=\p{L})/u','',$s);
 if($title){
  $s=preg_replace('/[\x{A789}\x{FF1A}]/u',':',$s);$s=str_replace("\u{FF1F}",'?',$s);
  $s=preg_replace('/#(?!\d)/u','',$s);
 }else{
  $s=preg_replace('/[:\x{A789}\x{FF1A}]/u',' - ',$s);
  $s=preg_replace('/[?\x{FF1F}]/u','',$s);
  $s=preg_replace('/#\s*(?=\d)/u','No. ',$s);$s=str_replace('#','',$s);
  $s=preg_replace('/\s*%/u',' percent',$s);
  $s=str_replace(['&','+'],[' and ',' plus '],$s);
 }
 $s=preg_replace('/[|\x{FF5C}\x{29F8}\/\\\\]/u',' - ',$s);
 $s=str_replace([';',"\u{2018}","\u{2019}","\u{2013}","\u{2014}","\u{2026}"],[',',"'","'",'-','-','...'],$s);
 $s=preg_replace('/["\x{201C}\x{201D}<>*]/u','',$s);
 return (string)$s;
}
/* Step 4 tidy: whitespace and repeated " - " collapse, ends trimmed. */
function sn_tidy(string $s,string $trim): string {
 $s=preg_replace('/\s+/u',' ',$s);
 do{$o=$s;$s=preg_replace('/ -(?: -)+ /u',' - ',$s);$s=preg_replace('/^(?:- )+|(?: -)+$/u','',$s);}while($s!==$o);
 return trim((string)$s,$trim);
}
function sn_reserved(string $s): bool {return (bool)preg_match('/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³]|CONIN\$|CONOUT\$)(?:\.|$)/iu',$s);}
function sn_cut(string $s,int $max): string {
 if(mb_strlen($s,'UTF-8')<=$max)return $s;$c=mb_substr($s,0,$max,'UTF-8');$sp=mb_strrpos($c,' ',0,'UTF-8');
 if($sp!==false&&$sp>=intdiv($max,2))$c=mb_substr($c,0,$sp,'UTF-8');return rtrim($c,' .-');
}
/* Steps 1-4 for a folder name or a file stem. $max: room left for the extension. */
function sn_part(string $s,array &$review,int $max=SN_MAX): string {
 $s=sn_map($s,false,$review);
 $s=preg_replace('/[^\p{L}\p{M}\p{N} .,\-_()\[\]\'!]/u','',$s);  // step 3: allowed characters only
 $s=sn_tidy((string)$s,' .-');
 if(mb_strlen($s,'UTF-8')>$max){$s=sn_cut($s,$max);$review[]='shortened to '.$max.' characters';}
 if($s===''){$s='Untitled';$review[]='no usable characters left';}
 if(sn_reserved($s)){$s='_'.$s;$review[]='reserved Windows name';}
 return $s;
}
function sn_info(string $name,bool $isFile=true): array {
 $review=[];
 if($isFile){$d=strrpos($name,'.');$ext=$d===false?'':strtolower(substr($name,$d+1));
  if($d===false||$d===0||!preg_match('/^[a-z0-9]{1,10}$/',$ext)){$clean=sn_part($name,$review);}
  else{$clean=sn_part(substr($name,0,$d),$review,SN_MAX-strlen($ext)-1).'.'.$ext;}}
 else $clean=sn_part($name,$review);
 return ['clean'=>$clean,'review'=>array_values(array_unique($review))];
}
function sn_component(string $s): string {return sn_info($s,false)['clean'];}
function sn_filename(string $s): string {return sn_info($s,true)['clean'];}
function sn_is_clean(string $s,bool $isFile): bool {return sn_info($s,$isFile)['clean']===$s;}
/* Step 5: collision suffix, compared case-insensitively (Windows). $taken: lower-cased names already used. */
function sn_unique(string $name,array $taken): string {
 $lk=fn($v)=>mb_strtolower($v,'UTF-8');if(!isset($taken[$lk($name)]))return $name;
 $d=strrpos($name,'.');$stem=$d===false||$d===0?$name:substr($name,0,$d);$ext=$d===false||$d===0?'':substr($name,$d);
 for($i=2;$i<1000;$i++){$c=sn_cut($stem,SN_MAX-strlen($ext)-strlen(" ($i)"))." ($i)".$ext;if(!isset($taken[$lk($c)]))return $c;}
 throw new RuntimeException('Too many files with the same name: '.$name);
}
/* Title rule (display only): steps 1, 2, 4 with ':' '?' '#252' '& + %' kept, trailing hashtags dropped,
   underscores read as spaces, leading numbering stripped (unless $stripNumber is false),
   title case only when the name is all lower or all upper case. */
function sn_title(string $s,bool $stripNumber=true): string {
 $review=[];$s=str_replace('_',' ',$s);$s=sn_map($s,true,$review);$s=sn_tidy($s,' -');$s=ltrim($s,'. ');
 if($stripNumber){$t=preg_replace('/^\d{1,3}(?:\s*[-.)]\s*|\s+)(?=\p{L})/u','',$s);if($t!=='')$s=(string)$t;}
 if(preg_match('/\p{L}/u',$s)&&($s===mb_strtolower($s,'UTF-8')||$s===mb_strtoupper($s,'UTF-8'))){
  $s=preg_replace_callback('/(^|[\s(\[-])(\p{L})(\p{L}*)/u',fn($m)=>$m[1].mb_strtoupper($m[2],'UTF-8').mb_strtolower($m[3],'UTF-8'),mb_strtolower($s,'UTF-8'));}
 return $s===''?'Untitled':$s;
}
/* Duplicate key of the CLEAN stem (same letters+digits key as dx_key / Get-DupKey, applied after the rule). */
function sn_key(string $file): string {
 $f=str_replace(chr(92),'/',$file);$p=strrpos($f,'/');if($p!==false)$f=substr($f,$p+1);
 $s=sn_filename($f);$d=strrpos($s,'.');if($d!==false&&$d>0)$s=substr($s,0,$d);$s=str_replace("\u{130}",'i',$s);
 return (string)preg_replace('/(?:[^\p{L}\p{N}]|[\x{10000}-\x{10FFFF}])+/u','',mb_strtolower($s,'UTF-8'));
}
/* Fast pre-check: true when the rule could change the letters+digits key of this name (used to keep the dedup index fast). */
function sn_key_may_differ(string $name): bool {return (bool)preg_match('/[#%&+\x{0080}-\x{10FFFF}]/u',$name);}
