<?php
include_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tiles.php';

if (!defined('CONTENT_CIPHER')) {
    define('CONTENT_CIPHER', 'AES-128-CTR');
}
if (!defined('CONTENT_CIPHER_KEY')) {
    define('CONTENT_CIPHER_KEY', 'gEeMmSJT_X9_8Kx2Ru1SeTm/t/HDTx30mlrZIOZ5AcaTJc=');
}
if (!defined('CONTENT_CIPHER_IV')) {
    define('CONTENT_CIPHER_IV', '1234567891011121');
}

$cipher = CONTENT_CIPHER;
$encryption_key = CONTENT_CIPHER_KEY;
$iv = CONTENT_CIPHER_IV;
$options = 0;

function encParam($val, $cipher, $key, $opts, $iv) {
    return str_replace('=', '[equal]', base64_encode(openssl_encrypt($val, $cipher, $key, $opts, $iv)));
}

function normalizeText($value) {
    return mb_strtolower(trim((string)$value));
}

function browsePageForCategory($category, $contentType = '') {
    $categoryNorm = normalizeText($category);
    $typeNorm = normalizeText($contentType);

    if ($typeNorm === 'pdf' || $typeNorm === 'book' || $typeNorm === 'books' || $categoryNorm === 'books' || strpos($categoryNorm, 'book') !== false) {
        return 'tutorials.php';
    }

    if ($typeNorm === 'audiobook') {
        return 'tutorials.php';
    }

    return 'tutorials.php';
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$query = trim(isset($_GET['q']) ? $_GET['q'] : '');
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';

$allowedTypes = ['video', 'audiobook', 'pdf', 'interactive', 'tool', 'book', 'books'];
if ($typeFilter !== '' && !in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}

if (mb_strlen($query) < 2) {
    echo json_encode([
        'results' => [],
        'groups'  => [],
        'total'   => 0,
        'query'   => $query,
    ]);
    exit;
}

try {
    $pdo = getDbConnection();

    $params = [];
    $typeClause = '';

    if ($typeFilter !== '') {
        $typeClause = ' AND cm.content_type = :type_filter';
        $params[':type_filter'] = $typeFilter;
    }

    $results = [];
    $matchType = 'exact';

    if (mb_strlen($query) >= 3) {
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
                MATCH(cm.title, cm.description, cm.category, cm.subcategory, cm.source, cm.transcript_snippet) AGAINST(:q_score IN BOOLEAN MODE) AS relevance,
                MATCH(cm.title) AGAINST(:q_title IN BOOLEAN MODE) AS title_relevance
            FROM content_meta cm
            WHERE MATCH(cm.title, cm.description, cm.category, cm.subcategory, cm.source, cm.transcript_snippet) AGAINST(:q_match IN BOOLEAN MODE)
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

        if (empty($results)) {
            $aliasResults = searchByAliases($pdo, $query, $typeClause, $params);
            if (!empty($aliasResults)) {
                $results = $aliasResults;
                $matchType = 'alias';
            }
        }

        if (empty($results)) {
            $soundexResults = searchBySoundex($pdo, $query, $typeClause, $params);
            if (!empty($soundexResults)) {
                $results = $soundexResults;
                $matchType = 'soundex';
            }
        }

        if (empty($results)) {
            $fuzzyResults = searchByLevenshtein($pdo, $query, $typeClause, $params);
            if (!empty($fuzzyResults)) {
                $results = $fuzzyResults;
                $matchType = 'fuzzy';
            }
        }
    } else {
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
                OR cm.transcript_snippet LIKE :like_transcript
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
            ':like_transcript'  => $likeParam,
        ]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($likeParams);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matchType = 'prefix';
    }

    $courseParamKey = encParam('course', $cipher, $encryption_key, $options, $iv);
    $groups = [];

    if (mb_strlen($query) >= 2) {
        $likeParam = '%' . $query . '%';

        $groupSql = "
            SELECT cm.category AS name, 'category' AS gtype, COUNT(*) AS cnt
            FROM content_meta cm
            WHERE cm.category LIKE :q_cat
            GROUP BY cm.category
            ORDER BY cnt DESC
            LIMIT 3
        ";
        $groupStmt = $pdo->prepare($groupSql);
        $groupStmt->execute([':q_cat' => $likeParam]);
        $catGroups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($catGroups as $g) {
            if (empty($g['name'])) continue;

            $browsePage = browsePageForCategory($g['name']);

            $groups[] = [
                'type'  => 'category',
                'name'  => $g['name'],
                'url'   => $browsePage . '?' . $courseParamKey . '=' . tileEncrypt($g['name']),
                'count' => (int)$g['cnt'],
            ];
        }

        $subSql = "
            SELECT
                cm.subcategory AS name,
                cm.category AS parent,
                cm.content_type,
                COUNT(*) AS cnt
            FROM content_meta cm
            WHERE cm.subcategory LIKE :q_subcat
              AND cm.subcategory IS NOT NULL
              AND cm.subcategory != ''
            GROUP BY cm.subcategory, cm.category, cm.content_type
            ORDER BY cnt DESC
            LIMIT 3
        ";
        $subStmt = $pdo->prepare($subSql);
        $subStmt->execute([':q_subcat' => $likeParam]);
        $subGroups = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subGroups as $g) {
            if (empty($g['name']) || empty($g['parent'])) continue;

            $subcatSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $g['name']));
            $browsePage = browsePageForCategory($g['parent'], $g['content_type']);

            $groups[] = [
                'type'   => 'subcategory',
                'name'   => $g['name'],
                'parent' => $g['parent'],
                'url'    => $browsePage . '?' . $courseParamKey . '=' . tileEncrypt($g['parent']) . '#tut-sec-' . $subcatSlug,
                'count'  => (int)$g['cnt'],
            ];
        }
    }

    $items = [];
    foreach ($results as $row) {
        $categoryUrl = '';
        $subcategoryUrl = '';

        if (!empty($row['category'])) {
            $browsePage = browsePageForCategory($row['category'], $row['content_type']);
            $categoryUrl = $browsePage . '?' . $courseParamKey . '=' . tileEncrypt($row['category']);

            if (!empty($row['subcategory'])) {
                $subcatSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $row['subcategory']));
                $subcategoryUrl = $categoryUrl . '#tut-sec-' . $subcatSlug;
            }
        }

        $items[] = [
            'content_id'       => $row['content_id'],
            'title'            => $row['title'],
            'category'         => $row['category'],
            'subcategory'      => $row['subcategory'],
            'category_url'     => $categoryUrl,
            'subcategory_url'  => $subcategoryUrl,
            'source'           => $row['source'],
            'content_type'     => $row['content_type'],
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'thumbnail_path'   => !empty($row['thumbnail_path']) ? resolveContentUrl($row['thumbnail_path']) : null,
            'file_path'        => $row['file_path'] ?? null,
            'match_type'       => $matchType,
        ];
    }

    $shouldLog = !isset($_GET['log']) || $_GET['log'] !== '0';
    if ($shouldLog) {
        try {
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

            $logUserId = $_SESSION['user_id'] ?? null;
            $logUserType = 'guest';
            $logAgeRange = null;

            if (!empty($logUserId)) {
                $userStmt = $pdo->prepare('SELECT user_type, age_range FROM users WHERE id = ?');
                $userStmt->execute([$logUserId]);
                $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($userRow) {
                    $logUserType = $userRow['user_type'] ?? 'guest';
                    $logAgeRange = $userRow['age_range'] ?? null;
                }
            }

            $logStmt = $pdo->prepare('INSERT INTO search_log (user_id, user_type, age_range, query, result_count) VALUES (?, ?, ?, ?, ?)');
            $logStmt->execute([
                $logUserId,
                $logUserType,
                $logAgeRange,
                mb_substr($query, 0, 255),
                count($items)
            ]);
        } catch (PDOException $e) {
            error_log('search_log insert failed: ' . $e->getMessage());
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
    }

    echo json_encode([
        'results' => $items,
        'groups'  => $groups,
        'total'   => count($items),
        'query'   => $query,
    ]);
} catch (PDOException $e) {
    error_log('Search API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}

function searchByAliases(PDO $pdo, $query, $typeClause, $baseParams) {
    $queryLower = mb_strtolower(trim($query));

    try {
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
    } catch (PDOException $e) {
        error_log('searchByAliases: ' . $e->getMessage());
        return [];
    }

    if (empty($aliasRows)) {
        return [];
    }

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

    $conditions = [];
    $params = $baseParams;
    $i = 0;
    foreach ($searchTerms as $term) {
        $paramName = ':alias_' . $i;
        $likeVal = '%' . $term . '%';
        $conditions[] = "(cm.title LIKE {$paramName} OR cm.category LIKE {$paramName} OR cm.subcategory LIKE {$paramName} OR cm.source LIKE {$paramName} OR cm.transcript_snippet LIKE {$paramName})";
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
            OR cm.transcript_snippet LIKE :lev_transcript
        )
        {$typeClause}
        LIMIT 200
    ";

    $params = array_merge($baseParams, [
        ':lev_title' => $likeVal,
        ':lev_cat' => $likeVal,
        ':lev_subcat' => $likeVal,
        ':lev_transcript' => $likeVal,
    ]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        return [];
    }

    $queryLower = mb_strtolower($query);
    $scored = [];
    foreach ($candidates as $row) {
        $titleDist = levenshtein($queryLower, mb_strtolower(mb_substr($row['title'], 0, 50)));
        $catDist = levenshtein($queryLower, mb_strtolower(mb_substr($row['category'] ?? '', 0, 50)));
        $subDist = levenshtein($queryLower, mb_strtolower(mb_substr($row['subcategory'] ?? '', 0, 50)));
        $minDist = min($titleDist, $catDist, $subDist);

        if ($minDist <= 3) {
            $row['_lev_distance'] = $minDist;
            $scored[] = $row;
        }
    }

    usort($scored, function ($a, $b) {
        return $a['_lev_distance'] - $b['_lev_distance'];
    });

    $output = [];
    foreach (array_slice($scored, 0, 20) as $row) {
        unset($row['_lev_distance']);
        $output[] = $row;
    }

    return $output;
}