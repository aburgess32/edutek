<?php
/* edutek_title v1 - display titles and junk skipping for the app indexer (Patch 7, 2026-10-22).
   Used by includes/content-indexer.php (titleFromFilename, indexContent) and by
   C:\Users\Edutek\Clean-LibraryTitles.ps1 (title updates for existing rows), so both give the same title.
   Junk detection is the shared rule edutek_safe_name v1 (includes/edutek-safe-name.php, sn_junk).
   - edutek_legacy_title(stem)   -> the title rule before Patch 7 (kept for comparison and as a fallback)
   - edutek_display_title(stem)  -> legacy rule plus: leading "._" / "." / "_" removed, a dot standing alone
                                     between spaces removed, ".." -> ".", 4+ dots -> "...", trailing dots removed.
                                     Names without those artifacts get exactly the legacy title.
   - edutek_index_junk(rel, bytes) -> '' or the reason a library file must not be indexed (sn_junk on the file
                                     and on every folder of its path) */
require_once __DIR__ . '/edutek-safe-name.php';

const EDUTEK_TITLE_RULE = 'edutek_title v1';

function edutek_legacy_title(string $filename): string
{
    $clean = preg_replace('/^\d+[\s.\-_]+/', '', $filename);
    $clean = str_replace(['_', '-'], ' ', (string) $clean);
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    return mb_convert_case((string) $clean, MB_CASE_TITLE, 'UTF-8');
}

function edutek_display_title(string $filename): string
{
    $s = (string) preg_replace('/^[\s._]+/', '', $filename);      // "._x", ".x", "_x", " x"
    $s = (string) preg_replace('/^\d+[\s.\-_]+/', '', $s);          // leading numbering (legacy)
    $s = str_replace(['_', '-'], ' ', $s);                           // legacy
    $s = (string) preg_replace('/(?<=\s)\.(?=\s|$)/', '', $s);      // " . " stray dot
    $s = (string) preg_replace('/(?<!\.)\.\.(?!\.)/', '.', $s);     // ".." -> "."
    $s = (string) preg_replace('/\.{4,}/', '...', $s);               // "...." -> "..."
    $s = (string) preg_replace('/[\s.]+$/', '', $s);                 // trailing dots / spaces
    $s = (string) preg_replace('/\s+/', ' ', trim($s));
    if ($s === '') {
        $s = edutek_legacy_title($filename);
        return $s === '' ? 'Untitled' : $s;
    }
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

function edutek_index_junk(string $relativePath, int $bytes = -1): string
{
    $parts = explode('/', str_replace('\\', '/', $relativePath));
    $base = (string) array_pop($parts);
    foreach ($parts as $dir) {
        if ($dir === '') {
            continue;
        }
        $why = sn_junk($dir, true);
        if ($why !== '') {
            return 'inside a ' . $why . ' (' . $dir . ')';
        }
    }
    return sn_junk($base, false, $bytes);
}
