<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tiles.php';

$items = getAllContent();

echo "==== getAllContent() ====\n";
print_r($items);
echo "\nTotal items: " . count($items) . "\n";