<?php
/**
 * Enhanced Search API (FRE-13)
 *
 * GET — returns JSON array of content matching a search term.
 *
 * Parameters:
 *   q    — search term (required, min 2 chars for results)
 *   type — optional content_type filter (video, audiobook, pdf, interactive, tool)
 *
 * For terms >= 3 chars: FULLTEXT MATCH...AGAINST in BOOLEAN MODE on
 *   (title, category, subcategory, source)
 * For terms 2 chars: LIKE fallback on all four columns
 * For terms < 2 chars: returns empty array
 *
 * Results ordered by relevance (FULLTEXT score) descending, limit 50.
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$query = trim(isset($_GET['q']) ? $_GET['q'] : '');
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';

// Validate type filter
$allowedTypes = ['video', 'audiobook', 'pdf', 'interactive', 'tool'];
if ($typeFilter !== '' && !in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}

// Return empty for very short queries
if (mb_strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDbConnection();

    $params = [];
    $typeClause = '';

    // Build optional content_type filter
    if ($typeFilter !== '') {
        $typeClause = ' AND cm.content_type = :type_filter';
        $params[':type_filter'] = $typeFilter;
    }

    if (mb_strlen($query) >= 3) {
        // FULLTEXT search in BOOLEAN MODE
        // Append wildcard (*) to each word for prefix matching
        $words = preg_split('/\s+/', $query);
        $booleanTerms = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '') {
                // Strip non-alphanumeric for safety, keep unicode letters
                $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
                if ($clean !== '') {
                    $booleanTerms[] = '+' . $clean . '*';
                }
            }
        }
        $booleanQuery = implode(' ', $booleanTerms);

        $sql = "
            SELECT
                cm.content_id,
                cm.title,
                cm.category,
                cm.subcategory,
                cm.source,
                cm.content_type,
                cm.duration_seconds,
                cm.thumbnail_path,
                MATCH(cm.title, cm.category, cm.subcategory, cm.source) AGAINST(:q_score IN BOOLEAN MODE) AS relevance
            FROM content_meta cm
            WHERE MATCH(cm.title, cm.category, cm.subcategory, cm.source) AGAINST(:q_match IN BOOLEAN MODE)
            {$typeClause}
            ORDER BY relevance DESC
            LIMIT 50
        ";

        $params[':q_score'] = $booleanQuery;
        $params[':q_match'] = $booleanQuery;
    } else {
        // LIKE fallback for 2-char terms
        $likeParam = '%' . $query . '%';

        $sql = "
            SELECT
                cm.content_id,
                cm.title,
                cm.category,
                cm.subcategory,
                cm.source,
                cm.content_type,
                cm.duration_seconds,
                cm.thumbnail_path,
                0 AS relevance
            FROM content_meta cm
            WHERE (
                cm.title LIKE :like_title
                OR cm.category LIKE :like_category
                OR cm.subcategory LIKE :like_subcategory
                OR cm.source LIKE :like_source
            )
            {$typeClause}
            ORDER BY cm.title ASC
            LIMIT 50
        ";

        $params[':like_title']       = $likeParam;
        $params[':like_category']    = $likeParam;
        $params[':like_subcategory'] = $likeParam;
        $params[':like_source']      = $likeParam;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Clean up: remove the relevance score from output, cast numeric fields
    $output = [];
    foreach ($results as $row) {
        $output[] = [
            'content_id'       => $row['content_id'],
            'title'            => $row['title'],
            'category'         => $row['category'],
            'subcategory'      => $row['subcategory'],
            'source'           => $row['source'],
            'content_type'     => $row['content_type'],
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'thumbnail_path'   => $row['thumbnail_path'],
        ];
    }

    echo json_encode($output);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}
