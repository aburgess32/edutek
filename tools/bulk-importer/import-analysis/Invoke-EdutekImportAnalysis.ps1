[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$SourceRoot,
    [Parameter(Mandatory=$true)][string]$LibraryRoot,
    [string]$ReportRoot = 'D:\edutek-system\bulk-import-plans'
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
function RootPath([string]$Path) {
    $item = Get-Item -LiteralPath $Path -Force
    if (-not $item.PSIsContainer) { throw "Not a directory: $Path" }
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Root is a link/junction: $Path" }
    return $item.FullName.TrimEnd('\','/')
}
function IsWithin([string]$Child,[string]$Parent) {
    return $Child.Equals($Parent,[StringComparison]::OrdinalIgnoreCase) -or $Child.StartsWith($Parent+'\',[StringComparison]::OrdinalIgnoreCase)
}
function Inventory([string]$Root) {
    $pending = New-Object 'System.Collections.Generic.Stack[string]'
    $pending.Push($Root)
    while ($pending.Count -gt 0) {
        $directory = $pending.Pop()
        foreach ($item in Get-ChildItem -LiteralPath $directory -Force) {
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Link/junction encountered; scan stopped: $($item.FullName)"
            }
            if ($item.PSIsContainer) { $pending.Push($item.FullName); continue }
            $relative = $item.FullName.Substring($Root.Length+1).Replace('\','/')
            [pscustomobject]@{ Path=$relative; Bytes=$item.Length; Mtime=$item.LastWriteTimeUtc.Ticks; Extension=$item.Extension.ToLowerInvariant(); Title=(NormalizeTitle $item.BaseName) }
        }
    }
}
function NormalizeTitle([string]$Title) {
    $text = $Title.Normalize([Text.NormalizationForm]::FormKC).ToLowerInvariant()
    $text = $text -replace '(?i)\s*\(copy(?:\s*\d+)?\)$',''
    return (($text -replace '[_\-]+',' ' -replace '\s+',' ').Trim())
}
function CsvSafe($Value) {
    $text = [string]$Value
    if ($text -match "^[\s]*[=+@-]" -or $text.StartsWith("'")) { return "'"+$text }
    return $text
}
function ExportSafe($Rows,[string]$Path,[string[]]$Columns) {
    $safe = @($Rows | ForEach-Object {
        $record=[ordered]@{}
        foreach ($column in $Columns) { $record[$column]=CsvSafe $_.$column }
        [pscustomobject]$record
    })
    if ($safe.Count) { $safe | Export-Csv -LiteralPath $Path -NoTypeInformation -Encoding UTF8 }
    else { (($Columns | ForEach-Object { '"'+$_+'"' }) -join ',') | Set-Content -LiteralPath $Path -Encoding UTF8 }
}
$source = RootPath $SourceRoot
$library = RootPath $LibraryRoot
$reportBase = [IO.Path]::GetFullPath($ReportRoot).TrimEnd('\','/')
if ((IsWithin $source $library) -or (IsWithin $library $source)) { throw 'Source and library must not overlap.' }
if ((IsWithin $reportBase $source) -or (IsWithin $reportBase $library)) { throw 'Reports must be outside both trees.' }
$runId = (Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8)
$run = Join-Path $reportBase $runId
New-Item -ItemType Directory -Path $run | Out-Null
try {
    Write-Host 'Inventorying library metadata only...'
    $existing = @(Inventory $library)
    Write-Host 'Inventorying source metadata only...'
    $incoming = @(Inventory $source)
    $categories=@{}
    foreach ($directory in Get-ChildItem -LiteralPath $library -Directory -Force) {
        if ($directory.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Library category is a link/junction.' }
        $categories[$directory.Name.ToLowerInvariant()]=$directory.Name
    }
    $byPath=@{}; $byTitle=@{}
    foreach ($file in $existing) {
        $byPath[$file.Path.ToLowerInvariant()]=$file
        $key=$file.Extension+'|'+$file.Title
        if (-not $byTitle.ContainsKey($key)) { $byTitle[$key]=New-Object 'System.Collections.Generic.List[object]' }
        $byTitle[$key].Add([pscustomobject]@{File=$file;RowId=''})
    }
    $types=@{'.pdf'='pdf';'.epub'='epub';'.mp4'='video';'.webm'='video';'.mkv'='video';'.avi'='video';'.mp3'='audio';'.ogg'='audio';'.wav'='audio';'.jpg'='image';'.jpeg'='image';'.png'='image';'.webp'='image';'.srt'='subtitle';'.vtt'='subtitle'}
    $plan=New-Object 'System.Collections.Generic.List[object]'
    $candidates=New-Object 'System.Collections.Generic.List[object]'
    $targets=@{}
    foreach ($file in $incoming) {
        $id=($plan.Count+1).ToString('D6'); $parts=$file.Path.Split('/'); $target=''; $basis='UNKNOWN_REVIEW'
        for ($i=0;$i -lt ($parts.Length-1);$i++) {
            $key=$parts[$i].ToLowerInvariant()
            if ($categories.ContainsKey($key)) {
                $tail=@($parts[$i..($parts.Length-1)]); $tail[0]=$categories[$key]
                $target=$tail -join '/'; $basis='EXACT_CATEGORY_FOLDER_SUGGESTION'; break
            }
        }
        $category='';$subcategory=''
        if ($target) { $tp=$target.Split('/');$category=$tp[0];if ($tp.Length -ge 3) {$subcategory=$tp[1]} }
        $flags=New-Object 'System.Collections.Generic.List[string]'
        if ($target -and $byPath.ContainsKey($target.ToLowerInvariant())) {
            $found=$byPath[$target.ToLowerInvariant()]
            if ($found.Bytes -eq $file.Bytes) {$flags.Add('LIKELY_EXISTING_COPY')} else {$flags.Add('DESTINATION_CONFLICT')}
        }
        $key=$file.Extension+'|'+$file.Title
        if ($types.ContainsKey($file.Extension) -and $byTitle.ContainsKey($key)) {
            foreach ($match in $byTitle[$key]) {
                $flag='POSSIBLE_VERSION_OR_DUPLICATE'
                if ($match.File.Bytes -eq $file.Bytes) {$flag='HIGH_CONFIDENCE_CANDIDATE'}
                $flags.Add($flag)
                $candidates.Add([pscustomobject]@{row_id=$id;matched_existing_path=$(if ($match.RowId -eq '') {$match.File.Path} else {''});matched_incoming_row_id=$match.RowId;duplicate_flag=$flag;source_bytes=$file.Bytes;matched_bytes=$match.File.Bytes;duplicate_reason='Exact normalized title and extension; metadata is not proof of identity'})
            }
        }
        if (-not $byTitle.ContainsKey($key)) {$byTitle[$key]=New-Object 'System.Collections.Generic.List[object]'}
        $byTitle[$key].Add([pscustomobject]@{File=$file;RowId=$id})
        $type='unsupported'
        if ($types.ContainsKey($file.Extension)) {$type=$types[$file.Extension]}
        $row=[pscustomobject]@{row_id=$id;include='0';source_relative_path=$file.Path;content_type=$type;category=$category;subcategory=$subcategory;target_relative_path=$target;action='REVIEW';match_basis=$basis;review_note='Suggestion only. Companion placement and generic titles require review.';duplicate_flag=(@($flags | Select-Object -Unique) -join '|');source_bytes=$file.Bytes;duplicate_decision='REVIEW'}
        if ($target) {
            $targetKey=$target.ToLowerInvariant()
            if ($targets.ContainsKey($targetKey)) {
                $row.duplicate_flag+='|PLANNED_PATH_COLLISION'
                $targets[$targetKey].duplicate_flag+='|PLANNED_PATH_COLLISION'
            } else {$targets[$targetKey]=$row}
        }
        $plan.Add($row)
    }
    $manifest=[ordered]@{schema_version=1;run_id=$runId;state='analysis_only';source_root=$source;library_root=$library;created_utc=[datetime]::UtcNow.ToString('o');source_inventory=$incoming;plan=$plan;limitations=@('No CSV import or execution','No fuzzy matches or alias mapping','No cryptographic identity verification','No companion grouping','No filesystem-complete conflict validation');csv_escape='Leading apostrophe added to formula-like or apostrophe-leading cells; JSON retains exact values'}
    $manifest | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $run 'manifest.json') -Encoding UTF8
    ExportSafe $plan (Join-Path $run 'import-plan.csv') @('row_id','include','source_relative_path','content_type','category','subcategory','target_relative_path','action','match_basis','review_note','duplicate_flag','source_bytes','duplicate_decision')
    ExportSafe $candidates (Join-Path $run 'duplicate-candidates.csv') @('row_id','matched_existing_path','matched_incoming_row_id','duplicate_flag','source_bytes','matched_bytes','duplicate_reason')
    [pscustomobject]@{Status='ANALYSIS ONLY - NO FILES COPIED';SourceFiles=$incoming.Count;LibraryFiles=$existing.Count;CandidatePairs=$candidates.Count;ReportDirectory=$run} | Format-List
} catch {
    $_.Exception.Message | Set-Content -LiteralPath (Join-Path $run 'FAILED.txt') -Encoding UTF8
    throw
}
