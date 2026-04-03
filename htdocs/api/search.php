<?php
/**
 * Enhanced Search API (FRE-40)
 *
 * GET — returns JSON array of content matching a search term.
 *
 * Parameters:
 *   q    — search term (required, min 2 chars for results)
 *   type — optional content_type filter (video, audiobook, pdf, interactive, tool)
 *
 * Search strategy (waterfall):
 *   1. FULLTEXT MATCH...AGAINST in BOOLEAN MODE (exact/prefix) → match_type 'exact'
 *   2. Alias lookup from search_aliases table → match_type 'alias'
 *   3. SOUNDEX fallback → match_type 'soundex'
 *   4. Levenshtein on LIKE '%first-3-chars%' set → match_type 'fuzzy'
 *
 * Results ordered by relevance (title matches boosted 2x), limit 20.
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

    $results = [];
    $matchType = 'exact';

    if (mb_strlen($query) >= 3) {
        // 1) FULLTEXT search in BOOLEAN MODE
        $words = preg_split('/\s+/', $query);
        $booleanTerms = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '') {
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
                cm.file_path,
                MATCH(cm.title, cm.category, cm.subcategory, cm.source) AGAINST(:q_score IN BOOLEAN MODE) AS relevance,
                MATCH(cm.title) AGAINST(:q_title IN BOOLEAN MODE) AS title_relevance
            FROM content_meta cm
            WHERE MATCH(cm.title, cm.category, cm.subcategory, cm.source) AGAINST(:q_match IN BOOLEAN MODE)
            {$typeClause}
            ORDER BY (relevance + title_relevance * 2) DESC
            LIMIT 20
        ";

        $ftParams = array_merge($params, [
            ':q_score' => $booleanQuery,
            ':q_match' => $booleanQuery,
            ':q_title' => $booleanQuery,
        ]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($ftParams);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matchType = 'exact';

        // 2) Alias fallback if no FULLTEXT results
        if (empty($results)) {
            $aliasResults = searchByAliases($pdo, $query, $typeClause, $params);
            if (!empty($aliasResults)) {
                $results = $aliasResults;
                $matchType = 'alias';
            }
        }

        // 3) SOUNDEX fallback
        if (empty($results)) {
            $soundexResults = searchBySoundex($pdo, $query, $typeClause, $params);
            if (!empty($soundexResults)) {
                $results = $soundexResults;
                $matchType = 'soundex';
            }
        }

        // 4) Levenshtein fallback on LIKE prefix set
        if (empty($results)) {
            $fuzzyResults = searchByLevenshtein($pdo, $query, $typeClause, $params);
            if (!empty($fuzzyResults)) {
                $results = $fuzzyResults;
                $matchType = 'fuzzy';
            }
        }
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
                cm.file_path,
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
            LIMIT 20
        ";

        $likeParams = array_merge($params, [
            ':like_title'       => $likeParam,
            ':like_category'    => $likeParam,
            ':like_subcategory' => $likeParam,
            ':like_source'      => $likeParam,
        ]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($likeParams);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matchType = 'prefix';
    }

    // Format output
    $items = [];
    foreach ($results as $row) {
        $items[] = [
            'content_id'       => $row['content_id'],
            'title'            => $row['title'],
            'category'         => $row['category'],
            'subcategory'      => $row['subcategory'],
            'source'           => $row['source'],
            'content_type'     => $row['content_type'],
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'thumbnail_path'   => $row['thumbnail_path'],
            'file_path'        => $row['file_path'] ?? null,
            'match_type'       => $matchType,
        ];
    }

    // Log the search query for dashboard analytics (FRE-39)
    try {
        // Auto-create search_log table if it doesn't exist (e.g. fresh installs
        // where migration 0008 hasn't run yet)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS search_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                user_type VARCHAR(20) DEFAULT NULL,
                age_range VARCHAR(20) DEFAULT NULL,
                query VARCHAR(255) NOT NULL,
                result_count INT DEFAULT 0,
                searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_searched_at (searched_at),
                INDEX idx_query (query(100))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $logStmt = $pdo->prepare('INSERT INTO search_log (user_id, user_type, age_range, query, result_count) VALUES (?, ?, ?, ?, ?)');
        $logStmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_role'] ?? 'guest',
            $_SESSION['age_range'] ?? null,
            mb_substr($query, 0, 255),
            count($items)
        ]);
    } catch (PDOException $e) {
        error_log('search_log insert failed: ' . $e->getMessage());
        // Fallback for old schema without user_type/age_range columns
        try {
            $logStmt = $pdo->prepare('INSERT INTO search_log (user_id, query, result_count) VALUES (?, ?, ?)');
            $logStmt->execute([
                $_SESSION['user_id'] ?? null,
                mb_substr($query, 0, 255),
                count($items)
            ]);
        } catch (PDOException $e2) {
            error_log('search_log fallback insert failed: ' . $e2->getMessage());
        }
    }

    echo json_encode([
        'results' => $items,
        'total'   => count($items),
        'query'   => $query,
    ]);
} catch (PDOException $e) {
    error_log('Search API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}

/**
 * Search using the search_aliases synonym table.
 * Looks up the query in both the term and aliases columns,
 * then searches content_meta for the canonical term + all aliases.
 */
function searchByAliases(PDO $pdo, $query, $typeClause, $baseParams) {
    $queryLower = mb_strtolower(trim($query));

    // Find matching alias rows: query matches the term or appears in aliases
    $aliasSql = "
        SELECT term, aliases FROM search_aliases
        WHERE LOWER(term) = :term_exact
           OR LOWER(aliases) LIKE :term_like
        LIMIT 5
    ";
    $aliasStmt = $pdo->prepare($aliasSql);
    $aliasStmt->execute([
        ':term_exact' => $queryLower,
        ':term_like'  => '%' . $queryLower . '%',
    ]);
    $aliasRows = $aliasStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($aliasRows)) {
        return [];
    }

    // Collect all search terms from matched aliases
    $searchTerms = [];
    foreach ($aliasRows as $row) {
        $searchTerms[] = $row['term'];
        $aliases = array_map('trim', explode(',', $row['aliases']));
        foreach ($aliases as $alias) {
            if ($alias !== '') {
                $searchTerms[] = $alias;
            }
        }
    }
    $searchTerms = array_unique($searchTerms);

    // Build LIKE OR conditions for each term
    $conditions = [];
    $params = $baseParams;
    $i = 0;
    foreach ($searchTerms as $term) {
        $paramName = ':alias_' . $i;
        $likeVal = '%' . $term . '%';
        $conditions[] = "(cm.title LIKE {$paramName} OR cm.category LIKE {$paramName} OR cm.subcategory LIKE {$paramName} OR cm.source LIKE {$paramName})";
        $params[$paramName] = $likeVal;
        $i++;
    }

    $whereClause = implode(' OR ', $conditions);
    $sql = "
        SELECT
            cm.content_id, cm.title, cm.category, cm.subcategory, cm.source,
            cm.content_type, cm.duration_seconds, cm.thumbnail_path, cm.file_path,
            0 AS relevance
        FROM content_meta cm
        WHERE ({$whereClause})
        {$typeClause}
        ORDER BY cm.title ASC
        LIMIT 20
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * SOUNDEX-based fallback: matches where the SOUNDEX of title or category
 * matches the SOUNDEX of the query.
 */
function searchBySoundex(PDO $pdo, $query, $typeClause, $baseParams) {
    $sql = "
        SELECT
            cm.content_id, cm.title, cm.category, cm.subcategory, cm.source,
            cm.content_type, cm.duration_seconds, cm.thumbnail_path, cm.file_path,
            0 AS relevance
        FROM content_meta cm
        WHERE SOUNDEX(cm.title) = SOUNDEX(:q_title)
           OR SOUNDEX(cm.category) = SOUNDEX(:q_cat)
           OR SOUNDEX(cm.subcategory) = SOUNDEX(:q_subcat)
        {$typeClause}
        ORDER BY cm.title ASC
        LIMIT 20
    ";

    $params = array_merge($baseParams, [
        ':q_title'  => $query,
        ':q_cat'    => $query,
        ':q_subcat' => $query,
    ]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Levenshtein-based fallback: fetches a broad LIKE set using the first 3 chars,
 * then re-ranks in PHP using levenshtein() distance.
 */
function searchByLevenshtein(PDO $pdo, $query, $typeClause, $baseParams) {
    $prefix = mb_substr($query, 0, 3);
    $likeVal = '%' . $prefix . '%';

    $sql = "
        SELECT
            cm.content_id, cm.title, cm.category, cm.subcategory, cm.source,
            cm.content_type, cm.duration_seconds, cm.thumbnail_path, cm.file_path,
            0 AS relevance
        FROM content_meta cm
        WHERE (
            cm.title LIKE :lev_title
            OR cm.category LIKE :lev_cat
            OR cm.subcategory LIKE :lev_subcat
        )
        {$typeClause}
        LIMIT 200
    ";

    $params = array_merge($baseParams, [
        ':lev_title'  => $likeVal,
        ':lev_cat'    => $likeVal,
        ':lev_subcat' => $likeVal,
    ]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        return [];
    }

    // Score each candidate by Levenshtein distance against the query
    $queryLower = mb_strtolower($query);
    $scored = [];
    foreach ($candidates as $row) {
        $titleDist = levenshtein($queryLower, mb_strtolower(mb_substr($row['title'], 0, 50)));
        $catDist = levenshtein($queryLower, mb_strtolower(mb_substr($row['category'] ?? '', 0, 50)));
        $minDist = min($titleDist, $catDist);

        // Only include results within a reasonable edit distance (max 3)
        if ($minDist <= 3) {
            $row['_lev_distance'] = $minDist;
            $scored[] = $row;
        }
    }

    // Sort by distance ascending
    usort($scored, function ($a, $b) {
        return $a['_lev_distance'] - $b['_lev_distance'];
    });

    // Strip internal scoring field, limit to 20
    $output = [];
    foreach (array_slice($scored, 0, 20) as $row) {
        unset($row['_lev_distance']);
        $output[] = $row;
    }

    return $output;
}
