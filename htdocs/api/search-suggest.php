<?php
/**
 * Search Suggestions / Typeahead API (FRE-40)
 *
 * GET — returns grouped suggestions for typeahead dropdown.
 *
 * Parameters:
 *   q — search term (required, min 2 chars)
 *
 * Returns JSON:
 * {
 *   "categories": [{"name": "...", "type": "category"}],
 *   "content": [{"title": "...", "content_type": "...", "content_id": "...", "type": "content"}]
 * }
 *
 * Max 3 category suggestions + max 5 content suggestions = 8 total.
 * Uses prefix LIKE matching for speed (no fuzzy — typeahead must be fast).
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$query = trim(isset($_GET['q']) ? $_GET['q'] : '');

if (mb_strlen($query) < 2) {
    echo json_encode(['categories' => [], 'content' => []]);
    exit;
}

try {
    $pdo = getDbConnection();

    $likeParam = $query . '%';   // prefix match
    $containsParam = '%' . $query . '%';

    // Category suggestions: distinct category/subcategory matching prefix
    $catSql = "
        (SELECT DISTINCT cm.category AS name, 'category' AS match_field
         FROM content_meta cm
         WHERE cm.category LIKE :cat_like
         LIMIT 3)
        UNION
        (SELECT DISTINCT cm.subcategory AS name, 'subcategory' AS match_field
         FROM content_meta cm
         WHERE cm.subcategory LIKE :subcat_like
           AND cm.subcategory IS NOT NULL
           AND cm.subcategory != ''
         LIMIT 3)
        LIMIT 3
    ";

    $catStmt = $pdo->prepare($catSql);
    $catStmt->execute([
        ':cat_like'    => $containsParam,
        ':subcat_like' => $containsParam,
    ]);
    $catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    $seen = [];
    foreach ($catRows as $row) {
        $name = $row['name'];
        if ($name && !isset($seen[$name])) {
            $categories[] = ['name' => $name, 'type' => 'category'];
            $seen[$name] = true;
        }
        if (count($categories) >= 3) break;
    }

    // Content suggestions: title prefix match, ordered by relevance
    $contentSql = "
        SELECT
            cm.content_id,
            cm.title,
            cm.content_type,
            cm.category,
            cm.subcategory,
            cm.thumbnail_path
        FROM content_meta cm
        WHERE cm.title LIKE :title_like
        ORDER BY
            CASE WHEN cm.title LIKE :title_prefix THEN 0 ELSE 1 END,
            cm.title ASC
        LIMIT 5
    ";

    $contentStmt = $pdo->prepare($contentSql);
    $contentStmt->execute([
        ':title_like'   => $containsParam,
        ':title_prefix' => $likeParam,
    ]);
    $contentRows = $contentStmt->fetchAll(PDO::FETCH_ASSOC);

    $content = [];
    foreach ($contentRows as $row) {
        $content[] = [
            'content_id'     => $row['content_id'],
            'title'          => $row['title'],
            'content_type'   => $row['content_type'],
            'category'       => $row['category'],
            'subcategory'    => $row['subcategory'],
            'thumbnail_path' => $row['thumbnail_path'],
            'type'           => 'content',
        ];
    }

    echo json_encode([
        'categories' => $categories,
        'content'    => $content,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Suggestion lookup failed']);
}
