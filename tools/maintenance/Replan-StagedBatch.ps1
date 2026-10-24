# Replan-StagedBatch.ps1 - helps re-plan an old staged import (rules 1) under the rules-3 naming, without re-ticking items by hand.
#   Read only by default. It NEVER changes the old batch, never publishes, never clears staged copies, never replays requests.
#   Usage:
#     .\Replan-StagedBatch.ps1 -From 2ddb69b2                 list the old batch's selected items: Original name -> Clean name (rules 3)
#     .\Replan-StagedBatch.ps1 -From 2ddb69b2 -Into <new id>  show which rows of a NEW rules-3 draft match (source + size + date). Changes nothing.
#     add -Apply                                              tick those matching rows in the new draft (after a verified backup of its batch.json).
#   The new draft must be a fresh scan: state draft, placement_rules 3. Decisions (duplicates, name fixes) are left for you in the importer.
param([Parameter(Mandatory=$true)][string]$From,[string]$Into='',[switch]$Apply)
$ErrorActionPreference='Stop'
$Docker='C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$Web='edutek-bulk-fresh-v05-web-1'
$HostData='D:\edutek-system\bulk-loader-fresh-v05\data\guided-v08'
$BackupRoot='D:\edutek-system\backups\replan'
if($From -notmatch '^[0-9a-f]{8,32}$'){ throw '-From must be a batch id (8 to 32 hex characters).' }
if($Into -ne '' -and $Into -notmatch '^[0-9a-f]{8,32}$'){ throw '-Into must be a batch id (8 to 32 hex characters).' }
if($Apply -and $Into -eq ''){ throw '-Apply needs -Into <new draft id>.' }
function Full([string]$p){ $d=@(Get-ChildItem -LiteralPath $HostData -Directory | Where-Object { $_.Name -like "$p*" -and $_.Name -match '^[0-9a-f]{32}$' }); if($d.Count -ne 1){ throw "Batch '$p' matches $($d.Count) batches." }; $d[0].Name }
function Php([string]$code){
  $old=$ErrorActionPreference; $ErrorActionPreference='Continue'
  $out=$code | & $Docker --context desktop-linux exec -i $Web php 2>&1 | Out-String; $code2=$LASTEXITCODE; $ErrorActionPreference=$old
  if($code2 -ne 0){ throw "container PHP failed ($code2): $out" }
  $out.TrimEnd()
}
$fromId=Full $From
$intoId=if($Into){ Full $Into } else { '' }
if($intoId -eq $fromId){ throw '-Into must be a different (new) batch.' }
$mode=if($Apply){'apply'}elseif($intoId){'match'}else{'list'}
$expectHash=''
if($Apply){
  $bj=Join-Path (Join-Path $HostData $intoId) 'batch.json'
  $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'; $bdir=Join-Path $BackupRoot "$stamp-$($intoId.Substring(0,8))"
  New-Item -ItemType Directory -Path $bdir -Force | Out-Null
  Copy-Item -LiteralPath $bj -Destination (Join-Path $bdir 'batch.json')
  $expectHash=(Get-FileHash -LiteralPath (Join-Path $bdir 'batch.json') -Algorithm SHA256).Hash.ToLower()
  if((Get-FileHash -LiteralPath $bj -Algorithm SHA256).Hash.ToLower() -ne $expectHash){ throw 'Backup copy does not match; nothing changed.' }
  Set-Content -LiteralPath (Join-Path $bdir 'README.txt') -Encoding ASCII -Value @("Backup of $bj before Replan-StagedBatch.ps1 -From $fromId -Into $intoId -Apply","SHA256 $expectHash","To undo: close the importer tab, then copy this batch.json back over $bj")
  Write-Host "Backup: $bdir"
}
$code=@'
<?php
require '/sandbox/lib/edutek-safe-name.php';
$root='/sandbox-data/guided-v08';$from='@@FROM@@';$into='@@INTO@@';$mode='@@MODE@@';$want='@@HASH@@';
$rd=fn($id)=>json_decode((string)file_get_contents("$root/$id/batch.json"),true,512,JSON_THROW_ON_ERROR);
$fb=$rd($from);$k=fn($r)=>(string)$r['source'].'|'.(int)($r['bytes']??0).'|'.(int)($r['mtime']??0);
printf("Old batch %s: state %s, rules %s, created %s\n",substr($from,0,8),$fb['state']??'?',json_encode($fb['placement_rules']??null),$fb['created']??'?');
$sel=[];foreach($fb['rows']??[] as $rid=>$r){if(!empty($r['support'])||empty($r['include']))continue;$sel[$k($r)]=$r;}
if($mode==='list'){$n=0;$ch=0;foreach($sel as $r){$n++;$old=basename((string)$r['target']);$new=sn_filename(basename((string)$r['source']));
  $parts=explode('/',(string)$r['target']);$cf=array_map(fn($p)=>sn_component($p),array_slice($parts,0,-1));
  if($old!==$new||$cf!==array_slice($parts,0,-1)){$ch++;printf("  CHANGES  %s\n     Original name: %s\n     Clean name:    %s   (folders now: %s)\n",$r['source'],$old,$new,implode(' > ',$cf));}}
  printf("%d selected items, %d get a different name or folder spelling under rules 3 (videos without a subfolder also move to Category > Category).\n",$n,$ch);
  foreach(glob("$root/*/batch.json") as $p){$b=json_decode((string)file_get_contents($p),true);$id=basename(dirname($p));if($id===$from||($b['state']??'')!=='draft'||($b['placement_rules']??0)!==3)continue;$m=0;foreach($b['rows']??[] as $r)if(empty($r['support'])&&isset($sel[$k($r)]))$m++;if($m)printf("  Candidate new draft %s (rules 3, created %s): %d of %d match\n",$id,$b['created']??'?',$m,$n);}
  exit(0);}
$lock=fopen("$root/actions.lock",'c');if($mode==='apply'&&!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"The importer is busy (another action is running). Try again.\n");exit(3);}
$path="$root/$into/batch.json";if($mode==='apply'&&hash_file('sha256',$path)!==$want){fwrite(STDERR,"The new draft changed after the backup was taken. Nothing changed; run again.\n");exit(4);}
$ib=$rd($into);
if(($ib['state']??'')!=='draft'||($ib['placement_rules']??0)!==3){fwrite(STDERR,"Batch $into is not a rules-3 draft (state ".($ib['state']??'?').", rules ".json_encode($ib['placement_rules']??null)."). Nothing changed.\n");exit(5);}
$hit=[];$tick=0;foreach($ib['rows'] as $rid=>&$r){if(!empty($r['support']))continue;if(isset($sel[$k($r)])){$hit[$k($r)]=1;if(empty($r['include'])){$tick++;if($mode==='apply')$r['include']=true;}
  $old=basename((string)$sel[$k($r)]['target']);$new=basename((string)$r['target']);if($old!==$new)printf("  Original name: %s\n  Clean name:    %s\n",$old,$new);}}unset($r);
foreach($sel as $kk=>$r)if(!isset($hit[$kk]))printf("  NOT FOUND in the new scan: %s\n",$r['source']);
printf("New draft %s: %d of %d old items found, %d to tick%s.\n",substr($into,0,8),count($hit),count($sel),$tick,$mode==='apply'?' (ticked)':' (check only - nothing changed)');
if($mode==='apply'&&$tick>0){$tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($tmp,json_encode($ib,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false||!rename($tmp,$path)){fwrite(STDERR,"Write failed\n");exit(6);}
  echo "Saved. Reload the importer tab (F5), then Save progress and Review selection; decide any duplicates or name fixes there.\n";}
flock($lock,LOCK_UN);
'@
$code=$code.Replace('@@FROM@@',$fromId).Replace('@@INTO@@',$intoId).Replace('@@MODE@@',$mode).Replace('@@HASH@@',$expectHash)
Php $code
