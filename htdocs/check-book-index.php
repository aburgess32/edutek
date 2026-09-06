<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getDbConnection();

    $stmt = $pdo->query(
        "SELECT content_type, category, COUNT(*) AS item_count
         FROM content_meta
         WHERE file_path LIKE '%Books%'
         GROUP BY content_type, category
         ORDER BY item_count DESC
         LIMIT 50"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        print_r($row);
        echo PHP_EOL;
    }
} catch (Throwable $error) {
    echo 'Database check failed: ' . $error->getMessage() . PHP_EOL;
}
