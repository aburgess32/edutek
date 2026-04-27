<?php
/**
 * Helper: return count of HEVC videos still pending transcoding.
 * Used by the background batch loop.
 */
require_once __DIR__ . '/../includes/auth.php';
$pdo = getDbConnection();
$count = $pdo->query("SELECT COUNT(*) FROM content_meta WHERE codec='hevc' AND (transcoded_path IS NULL OR transcoded_path='')")->fetchColumn();
echo (int)$count;
