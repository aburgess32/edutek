<?php

/**
 * Database-driven content queries (FRE-54).
 *
 * Replaces filesystem glob/scandir with fast SQL queries against
 * the content_meta table for the tutorials page.
 *
 * @package EduPak
 */

/**
 * Get all subcategories (folders) for a given category, with video counts.
 * Replaces: glob("videos/CATEGORY/*") + per-folder glob + file counting
 *
 * @param PDO    $pdo      Database connection
 * @param string $category Category name (e.g. "Zimbabwe-Education")
 * @return array [['subcategory' => 'FolderName', 'video_count' => 45, 'first_file' => '...', 'first_thumb' => '...'], ...]
 */
function getSubcategories(PDO $pdo, string $category)
{
    $stmt = $pdo->prepare("
        SELECT subcategory,
               COUNT(*) AS video_count,
               MIN(file_path) AS first_file,
               MIN(thumbnail_path) AS first_thumb
        FROM content_meta
        WHERE category = ?
          AND content_type IN ('video', 'audiobook')
          AND subcategory != ''
        GROUP BY subcategory
        ORDER BY subcategory
    ");
    $stmt->execute([$category]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all videos in a specific subcategory.
 * Replaces: glob("videos/CATEGORY/SUBCATEGORY/*") + extension filtering
 *
 * @param PDO    $pdo         Database connection
 * @param string $category    Category name
 * @param string $subcategory Subcategory name
 * @return array [['title' => '...', 'file_path' => '...', 'thumbnail_path' => '...', 'content_type' => '...'], ...]
 */
function getSubcategoryVideos(PDO $pdo, string $category, string $subcategory)
{
    $stmt = $pdo->prepare("
        SELECT title, file_path, thumbnail_path, content_type
        FROM content_meta
        WHERE category = ? AND subcategory = ?
          AND content_type IN ('video', 'audiobook')
        ORDER BY title
    ");
    $stmt->execute([$category, $subcategory]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get total video count for a category.
 *
 * @param PDO    $pdo      Database connection
 * @param string $category Category name
 * @return int
 */
function getCategoryVideoCount(PDO $pdo, string $category)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM content_meta
        WHERE category = ?
          AND content_type IN ('video', 'audiobook')
    ");
    $stmt->execute([$category]);
    return (int) $stmt->fetchColumn();
}
