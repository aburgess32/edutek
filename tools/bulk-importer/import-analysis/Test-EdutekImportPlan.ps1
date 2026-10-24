[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][string]$ManifestPath,
 [Parameter(Mandatory=$true)][string]$PlanPath
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName Microsoft.VisualBasic
function Fail([string]$Message) { throw $Message }
function Inside([string]$Child,[string]$Parent) {
 return $Child.Equals($Parent,[StringComparison]::OrdinalIgnoreCase) -or $Child.StartsWith($Parent+'\',[StringComparison]::OrdinalIgnoreCase)
}
function NoLinks([string]$Path) {
 $full=[IO.Path]::GetFullPath($Path)
 $root=[IO.Path]::GetPathRoot($full)
 $current=$root
 foreach ($part in $full.Substring($root.Length).Split('\')) {
  if (-not $part) {continue}
  $current=Join-Path $current $part
  if (Test-Path -LiteralPath $current) {
   $item=Get-Item -LiteralPath $current -Force
   if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {Fail "Link/junction rejected: $current"}
  }
 }
}
function Root([string]$Path) {
 NoLinks $Path
 $item=Get-Item -LiteralPath $Path -Force
 if (-not $item.PSIsContainer) {Fail "Not a directory: $Path"}
 return $item.FullName.TrimEnd('\')
}
function Inventory([string]$RootPath) {
 $pending=New-Object 'System.Collections.Generic.Stack[string]';$pending.Push($RootPath)
 while ($pending.Count) {
  foreach ($item in Get-ChildItem -LiteralPath $pending.Pop() -Force) {
   if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {Fail "Source link/junction rejected: $($item.FullName)"}
   if ($item.PSIsContainer) {$pending.Push($item.FullName);continue}
   [pscustomobject]@{Path=$item.FullName.Substring($RootPath.Length+1).Replace('\','/');Bytes=$item.Length;Mtime=$item.LastWriteTimeUtc.Ticks}
  }
 }
}
function EscapeCell([string]$Value) {
 if ($Value -match '^[\s]*[=+@-]' -or $Value.StartsWith("'")) {return "'"+$Value}
 return $Value
}
function DecodeCell([string]$Value) {
 if ($Value.StartsWith("'")) {
  $tail=$Value.Substring(1)
  if ($tail.StartsWith("'") -or $tail -match '^[\s]*[=+@-]') {return $tail}
 }
 return $Value
}
function Target([string]$Relative,[string]$Library) {
 if (-not $Relative -or $Relative.Contains('\') -or $Relative.StartsWith('/') -or $Relative.Length -gt 230) {Fail 'Target must be a nonempty forward-slash relative path of at most 230 characters.'}
 $parts=$Relative.Split('/')
 if ($parts.Length -lt 2) {Fail 'Target needs at least category/filename.'}
 foreach ($part in $parts) {
  if (-not $part -or $part -eq '.' -or $part -eq '..' -or $part -match '[<>:"\\|?*\x00-\x1f]' -or $part.EndsWith(' ') -or $part.EndsWith('.') -or $part.Length -gt 255 -or $part -match '^(?i:CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)') {Fail "Invalid Windows path component: $part"}
 }
 $full=[IO.Path]::GetFullPath((Join-Path $Library ($Relative.Replace('/','\'))))
 if (-not (Inside $full $Library) -or $full.Length -gt 250) {Fail 'Target escapes root or exceeds conservative Windows path limit.'}
 NoLinks $full
 $current=$Library
 for ($i=0;$i -lt $parts.Length;$i++) {
  $current=Join-Path $current $parts[$i]
  if (Test-Path -LiteralPath $current) {
   $item=Get-Item -LiteralPath $current -Force
   if ($i -eq $parts.Length-1) {Fail "Destination occupied; overwrite forbidden: $Relative"}
   if (-not $item.PSIsContainer) {Fail "Destination parent is a file: $current"}
  }
 }
 return $full
}
$manifestFull=(Get-Item -LiteralPath $ManifestPath).FullName
$planFull=(Get-Item -LiteralPath $PlanPath).FullName
$manifestHash=(Get-FileHash -LiteralPath $manifestFull -Algorithm SHA256).Hash
$planHash=(Get-FileHash -LiteralPath $planFull -Algorithm SHA256).Hash
$m=Get-Content -LiteralPath $manifestFull -Raw -Encoding UTF8 | ConvertFrom-Json
if ($m.schema_version -ne 1 -or $m.state -ne 'analysis_only') {Fail 'Unsupported manifest schema/state.'}
$source=Root ([string]$m.source_root);$library=Root ([string]$m.library_root)
if ((Inside $source $library) -or (Inside $library $source)) {Fail 'Source/library roots overlap.'}
$reportParent=Split-Path -Parent $manifestFull
if ((Inside $reportParent $source) -or (Inside $reportParent $library)) {Fail 'Manifest/report folder must be outside source and library.'}
NoLinks $reportParent
$run=Join-Path $reportParent ('validation-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8))
New-Item -ItemType Directory -Path $run | Out-Null
$errors=New-Object 'System.Collections.Generic.List[object]'
$preview=New-Object 'System.Collections.Generic.List[object]'
$folders=New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
function AddError([string]$Id,[string]$Message) {$errors.Add([pscustomobject]@{row_id=$Id;error=$Message})}
try {
 $original=@{};$inventory=@{}
 foreach ($row in @($m.plan)) {
  if ($original.ContainsKey([string]$row.row_id)) {Fail 'Duplicate manifest row ID.'}
  $original[[string]$row.row_id]=$row
 }
 foreach ($file in @($m.source_inventory)) {
  if ($inventory.ContainsKey([string]$file.Path)) {Fail 'Duplicate source manifest path.'}
  $inventory[[string]$file.Path]=$file
 }
 Write-Host 'Rechecking complete source metadata (no hashing)...'
 $now=@(Inventory $source);$seenSource=@{}
 foreach ($file in $now) {
  $seenSource[$file.Path]=$true
  if (-not $inventory.ContainsKey($file.Path)) {AddError '' "New source file: $($file.Path)";continue}
  $old=$inventory[$file.Path]
  if ([long]$old.Bytes -ne $file.Bytes -or [long]$old.Mtime -ne $file.Mtime) {AddError '' "Changed source file: $($file.Path)"}
 }
 foreach ($path in $inventory.Keys) {if (-not $seenSource.ContainsKey($path)) {AddError '' "Missing source file: $path"}}
 $expected=@('row_id','include','source_relative_path','content_type','category','subcategory','target_relative_path','action','match_basis','review_note','duplicate_flag','source_bytes','duplicate_decision')
 $immutable=@('source_relative_path','content_type','match_basis','duplicate_flag','source_bytes')
 $parser=New-Object Microsoft.VisualBasic.FileIO.TextFieldParser($planFull,[Text.Encoding]::UTF8,$true)
 $parser.TextFieldType=[Microsoft.VisualBasic.FileIO.FieldType]::Delimited
 $parser.SetDelimiters(',');$parser.HasFieldsEnclosedInQuotes=$true;$parser.TrimWhiteSpace=$false
 $seen=@{};$destinations=@{}
 try {
  $headers=$parser.ReadFields()
  if ($headers.Count -ne $expected.Count) {Fail 'CSV column count differs from original contract.'}
  $headerSet=@{}
  foreach ($h in $headers) {if ($headerSet.ContainsKey($h) -or $expected -cnotcontains $h) {Fail "Invalid/duplicate CSV header: $h"};$headerSet[$h]=$true}
  while (-not $parser.EndOfData) {
   $values=$parser.ReadFields()
   if ($values.Count -ne $headers.Count) {Fail 'Malformed CSV: wrong number of fields.'}
   $row=@{};for ($i=0;$i -lt $headers.Count;$i++) {$row[$headers[$i]]=[string]$values[$i]}
   $id=$row.row_id
   if (-not $original.ContainsKey($id)) {AddError $id 'Unknown row ID.';continue}
   if ($seen.ContainsKey($id)) {AddError $id 'Duplicate row ID.';continue};$seen[$id]=$true
   $old=$original[$id]
   foreach ($field in $immutable) {if ($row[$field] -cne (EscapeCell ([string]$old.$field))) {AddError $id "Immutable field changed: $field"}}
   if ($row.include -cnotin @('0','1')) {AddError $id 'include must be 0 or 1.'}
   if ($row.action -cnotin @('REVIEW','SKIP','COPY','KEEP_BOTH','HASH_COMPARE')) {AddError $id 'Invalid action.'}
   if ($row.duplicate_decision -cnotin @('REVIEW','SKIP','COPY','KEEP_BOTH','HASH_COMPARE')) {AddError $id 'Invalid duplicate_decision.'}
   $active=$row.include -ceq '1'
   $status='EXCLUDED'
   $target=DecodeCell $row.target_relative_path
   if ($active) {
    $status='SELECTED'
    if ($row.action -cnotin @('COPY','KEEP_BOTH')) {AddError $id 'Included rows require COPY or KEEP_BOTH; unresolved review/hash/skip cannot execute.'}
    if ($old.duplicate_flag -and $row.duplicate_decision -cnotin @('COPY','KEEP_BOTH')) {AddError $id 'Duplicate warning requires explicit COPY or KEEP_BOTH decision.'}
    try {
     $full=Target $target $library
     $parts=$target.Split('/')
     $sub='';if ($parts.Length -ge 3) {$sub=$parts[1]}
     if ((DecodeCell $row.category) -cne $parts[0] -or (DecodeCell $row.subcategory) -cne $sub) {Fail 'Category/subcategory must agree with first/second target directory.'}
     if ([IO.Path]::GetExtension($target) -ine [IO.Path]::GetExtension([string]$old.source_relative_path)) {Fail 'Changing file extension is not supported.'}
     $key=$target.ToLowerInvariant()
     if ($destinations.ContainsKey($key)) {Fail "Planned target collision with row $($destinations[$key])"}
     $destinations[$key]=$id
     $parent=Split-Path -Parent $full
     while ($parent -and -not $parent.Equals($library,[StringComparison]::OrdinalIgnoreCase)) {
      if (-not (Test-Path -LiteralPath $parent)) {[void]$folders.Add($parent)}
      $parent=Split-Path -Parent $parent
     }
    } catch {AddError $id $_.Exception.Message}
   }
   $preview.Add([pscustomobject]@{row_id=$id;selected=$active;action=$row.action;source_relative_path=[string]$old.source_relative_path;target_relative_path=$target;source_bytes=[long]$old.source_bytes;duplicate_decision=$row.duplicate_decision;status=$status})
  }
 } finally {$parser.Close()}
 foreach ($id in $original.Keys) {if (-not $seen.ContainsKey($id)) {AddError $id 'Missing CSV row; exclude it rather than deleting it.'}}
 foreach ($key in $destinations.Keys) {
  $parts=$key.Split('/')
  for ($i=1;$i -lt $parts.Length;$i++) {
   $prefix=$parts[0..($i-1)] -join '/'
   if ($destinations.ContainsKey($prefix)) {AddError $destinations[$key] "Planned file used as parent directory: $prefix"}
  }
 }
 if ($manifestHash -ne (Get-FileHash -LiteralPath $manifestFull -Algorithm SHA256).Hash -or $planHash -ne (Get-FileHash -LiteralPath $planFull -Algorithm SHA256).Hash) {AddError '' 'Manifest or CSV changed during validation.'}
} catch {AddError '' $_.Exception.Message}
$selected=@($preview | Where-Object {$_.selected})
$bytes=0L;foreach ($row in $selected) {$bytes+=$row.source_bytes}
$state='VALIDATION_FAILED'
if ($errors.Count -eq 0) {$state='PREVIEW_VALID_NOT_APPROVED'}
$result=[ordered]@{schema_version=1;state=$state;revision_id=[guid]::NewGuid().ToString('N');validated_utc=[datetime]::UtcNow.ToString('o');manifest_sha256=$manifestHash;csv_sha256=$planHash;source_root=$source;library_root=$library;selected_files=$selected.Count;selected_bytes=$bytes;excluded_files=($preview.Count-$selected.Count);errors=@($errors.ToArray());proposed_folders=@($folders | Sort-Object);rows=@($preview.ToArray());limitations=@('No approval or execution implemented','Manifest is trusted local input, not signed authorization','Source checks use size/time, not content hashes','Destination availability checked now only; executor must recheck','No companion-group consistency or free-space guarantee')}
$result | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath (Join-Path $run 'preview.json') -Encoding UTF8
$errorRows=@($errors | ForEach-Object {[pscustomobject]@{row_id=(EscapeCell $_.row_id);error=(EscapeCell $_.error)}})
if ($errorRows.Count) {$errorRows | Export-Csv -LiteralPath (Join-Path $run 'validation-errors.csv') -NoTypeInformation -Encoding UTF8}
else {'"row_id","error"' | Set-Content -LiteralPath (Join-Path $run 'validation-errors.csv') -Encoding UTF8}
[pscustomobject]@{Status=$state;SelectedFiles=$selected.Count;ExcludedFiles=($preview.Count-$selected.Count);ErrorCount=$errors.Count;ReportDirectory=$run;FilesCopied=0} | Format-List
if ($errors.Count) {$errors | Select-Object -First 15 | Format-Table -Wrap -AutoSize;exit 2}
exit 0
